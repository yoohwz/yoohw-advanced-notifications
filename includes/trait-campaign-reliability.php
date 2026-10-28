<?php
/** Durable campaign operations. Operational reads deliberately bypass the options cache. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait YOOHW_AN_Campaign_Reliability {
	public function capture_action_id( $id ) {
		$this->current_action_id = absint( $id );
	}

	public function clear_action_id() {
		$this->current_action_id = 0;
	}

	/** Resumable, non-destructive import of inline pending jobs whose history may have been trimmed. */
	public function maybe_import_legacy_jobs() {
		if ( $this->read_durable_option( 'yoohw_an_reliability_import' ) || ! class_exists( 'ActionScheduler' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read pending/in-progress legacy inline arguments directly so resumable import sees jobs stored by other workers.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT action_id,args,extended_args,scheduled_date_gmt FROM %i WHERE hook = %s AND status IN ('pending','in-progress') AND action_id > %d ORDER BY action_id LIMIT 200",
				$table, self::ACTION_SEND_SINGLE_EMAIL, $after
			), ARRAY_A );
			if ( $wpdb->last_error ) {
				return;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['action_id'];
				$args = json_decode( $row['extended_args'] ?: $row['args'], true );
				$args = $args[0] ?? [];
				if ( empty( $args['campaign_id'] ) || empty( $args['to'] ) ) {
					continue;
				}
				if ( $this->read_durable_option( 'yoohw_an_erased_' . hash( 'sha256', strtolower( $args['to'] ) ) ) ) { continue; }
				$id = $args['campaign_id'];
				$delivery = ! empty( $args['delivery_id'] ) ? $args['delivery_id'] : $id;
				if ( ! $this->acquire_campaign_lock( $id ) ) {
					return;
				}
				try {
					$history = $this->get_campaign_history();
					if ( empty( $history[ $id ] ) ) {
						$payload = $this->hydrate_email_action_args( $args );
						if ( is_wp_error( $payload ) ) {
							$payload = $args;
						}
						if ( ! $this->record_campaign_snapshot( array_merge( $payload, [ 'delivery_id' => $delivery ] ), 'needs_attention', [ 'recipient_count' => 0 ] ) ) {
							return;
						}
						$history = $this->get_campaign_history();
						$history[ $id ]['audience_incomplete'] = true;
						$this->save_campaign_record( $history[ $id ] );
					}
					if ( ! $this->ensure_delivery_row( $id, $delivery, $args['to'] ) ) {
						return;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically attach the legacy job only while action_id is unset; cached state could overwrite a worker's newer association.
					if ( false === $wpdb->query( $wpdb->prepare(
						"UPDATE %i SET action_id = %d, scheduled_at_gmt = %s, status = IF(status = 'queued' AND %s > UTC_TIMESTAMP(), 'scheduled', status) WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND action_id = 0",
						$this->get_recipient_tracking_table_name(), $after, $row['scheduled_date_gmt'], $row['scheduled_date_gmt'], $id, $delivery, hash( 'sha256', strtolower( $args['to'] ) )
					) ) ) { return; }
				} finally {
					$this->release_campaign_lock( $id );
				}
			}
		} while ( count( $rows ) === 200 );
		$this->write_durable_option( 'yoohw_an_reliability_import', 1 );
	}

	private function read_durable_option( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Opt-outs, claims and recovery checkpoints must reflect committed writes from other processes, bypassing persistent options caches.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name = %s", $wpdb->options, $name ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Campaign storage read failed.' );
		}
		return maybe_unserialize( $value );
	}

	private function write_durable_option( $name, $value, $insert_only = false ) {
		global $wpdb;
		$sql = $insert_only
			? "INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,'no')"
			: "INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Atomic INSERT IGNORE/upsert is required for durable checkpoints; $sql selects two fixed statements with prepared identifiers/values. Options caches are invalidated below.
		$result = $wpdb->query( $wpdb->prepare( $sql, $wpdb->options, $name, maybe_serialize( $value ) ) );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return $result;
	}

	private function read_durable_options( $prefix ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rebuild campaign/opt-out collections from current durable records; a cached collection can omit another worker's committed record.
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name LIKE %s", $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Campaign storage read failed.' );
		}
		return array_map( 'maybe_unserialize', $values );
	}

	private function campaign_record_key( $campaign_id ) {
		return 'yoohw_an_record_' . hash( 'sha256', $campaign_id );
	}

	private function campaign_manifest_key( $campaign_id ) {
		return 'yoohw_an_manifest_' . hash( 'sha256', $campaign_id );
	}

	private function save_campaign_record( $campaign ) {
		return false !== $this->write_durable_option( $this->campaign_record_key( $campaign['campaign_id'] ), $campaign );
	}

	/** Connection-scoped locks have no lease that can expire while a worker is still running. */
	private function acquire_campaign_lock( $campaign_id ) {
		global $wpdb;
		$key = 'yoohw_an_' . hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $campaign_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK acquires a lock on this database connection; its result must never be cached or replaced with an options read/write lock.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', substr( $key, 0, 64 ) ) );
	}

	private function release_campaign_lock( $campaign_id ) {
		global $wpdb;
		$key = 'yoohw_an_' . hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $campaign_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK must execute on the owning connection; returning a cached result would leave the lock held.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( $key, 0, 64 ) ) );
	}

	/** First confirmation wins. The immutable manifest is also the preparation checkpoint. */
	private function confirm_campaign( $payload, $recipients ) {
		$id = $payload['campaign_id'];
		if ( ! $this->acquire_campaign_lock( $id ) ) {
			return new WP_Error( 'campaign_busy', __( 'Campaign is being prepared. Open Campaigns & Logs to follow its progress.', 'yoohw-advanced-notifications' ) );
		}
		try {
			$key = $this->campaign_manifest_key( $id );
			$manifest = $this->read_durable_option( $key );
			if ( ! is_array( $manifest ) ) {
				$history = $this->get_campaign_history();
				$existing = $history[ $id ] ?? [];
				// A pre-upgrade confirmation must never reset its recipient state.
				if ( ! empty( $existing ) && ! in_array( $existing['status'], [ 'previewed', 'test', 'test_sent', 'test_failed', 'empty' ], true ) ) {
					return 0;
				}
				if ( empty( $recipients ) ) {
					$this->record_campaign_snapshot( $payload, 'empty', [ 'recipient_count' => 0 ] );
					return 0;
				}
				$manifest = [ 'payload' => $payload, 'recipients' => $recipients, 'confirmed_at' => time() ];
				if ( 1 !== $this->write_durable_option( $key, $manifest, true ) ) {
					return new WP_Error( 'confirmation_store_failed', __( 'Confirmation could not be saved. Please try again.', 'yoohw-advanced-notifications' ) );
				}
			}
			$this->resume_campaign_preparation( $id, $manifest );
			return $this->enqueue_campaign_dispatch( $id );
		} finally {
			$this->release_campaign_lock( $id );
		}
	}

	private function resume_campaign_preparation( $id, $manifest = null ) {
		$manifest = $manifest ?? $this->read_durable_option( $this->campaign_manifest_key( $id ) );
		if ( ! is_array( $manifest ) || empty( $manifest['payload'] ) ) {
			return false; // Never reconstruct a historical audience from today's customers.
		}
		$history = $this->get_campaign_history();
		if ( ! $this->recovery_enabled || $this->campaign_blocked( $id ) || ! empty( $history[ $id ]['cancelled'] ) ) {
			return false;
		}
		if ( ! empty( $history[ $id ]['preparation_complete'] ) ) {
			return true;
		}
		$payload = $manifest['payload'];
		$this->record_campaign_snapshot( $payload, 'preparing', [ 'recipient_count' => count( $manifest['recipients'] ) ] );
		$reference = $this->store_campaign_email_payload( $payload );
		// Resume against the original confirmation time, even when recovery crosses a due date.
		$result = is_wp_error( $reference ) ? $reference : $this->prepare_campaign_recipients_for_dispatch( $payload, $manifest['recipients'], ! empty( $manifest['confirmed_at'] ) ? absint( $manifest['confirmed_at'] ) : null );
		$history = $this->get_campaign_history();
		$campaign = $history[ $id ];
		$campaign['preparation_complete'] = ! is_wp_error( $result );
		$campaign['recovery_message'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$campaign['status'] = is_wp_error( $result ) ? 'needs_attention' : 'dispatching';
		$this->save_campaign_record( $campaign );
		return ! is_wp_error( $result );
	}

	/** Reconcile actual action state per recipient; queue duplication is safe, transport duplication is not. */
	private function recover_campaign_recipients( $campaign, $manual = false ) {
		global $wpdb;
		$id = $campaign['campaign_id'];
		if ( $this->campaign_blocked( $id ) ) { return; }
		$delivery = ! empty( $campaign['delivery_id'] ) ? $campaign['delivery_id'] : $id;
		$table = $this->get_recipient_tracking_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery must inspect current recipient state before comparing it with the live Action Scheduler job state.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE campaign_id = %s AND delivery_id = %s AND status IN ('processing','queued','scheduled','queue_failed','schedule_failed','failed')",
			$table, $id, $delivery
		), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$age = time() - strtotime( $row['updated_at'] . ' UTC' );
			$action_status = '';
			if ( $row['action_id'] && class_exists( 'ActionScheduler' ) ) {
				try {
					$action_status = ActionScheduler::store()->get_status( (int) $row['action_id'] );
				} catch ( Exception $error ) {
					$action_status = 'missing';
				}
			}
			if ( 'processing' === $row['status'] ) {
				if ( $age >= 15 * MINUTE_IN_SECONDS ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare status and claim token in one UPDATE so a stale worker snapshot cannot overwrite a completed handoff.
					$wpdb->query( $wpdb->prepare(
						"UPDATE %i SET status = 'unknown', last_error = %s WHERE id = %d AND status = 'processing' AND claim_token = %s",
						$table, __( 'Worker stopped around mail handoff. Check the mail provider before creating a new campaign; this recipient will not be retried automatically.', 'yoohw-advanced-notifications' ), $row['id'], $row['claim_token']
					) );
				}
				continue;
			}
			if ( in_array( $action_status, [ 'pending', 'in-progress' ], true ) ) {
				continue;
			}
			$queue_failure = in_array( $row['status'], [ 'queue_failed', 'schedule_failed' ], true );
			$missing_job = in_array( $row['status'], [ 'queued', 'scheduled' ], true ) && ( $manual || $age >= 5 * MINUTE_IN_SECONDS );
			$known_failure = $manual && 'failed' === $row['status'] && 'configuration' === $row['error_kind'];
			if ( ( $queue_failure || $missing_job || $known_failure ) && (int) $row['attempts'] < 3 && ( $manual || $age >= 5 * MINUTE_IN_SECONDS ) ) {
				if ( $this->is_email_unsubscribed( $row['email'] ) ) {
					// Configuration failures retain their claim token. Match the exact snapshot so
					// recovery can skip them without touching a worker that started meanwhile.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The status/token comparison must be atomic with the opt-out skip; cached state cannot protect a concurrently running worker.
					$wpdb->query( $wpdb->prepare(
						"UPDATE %i SET status = 'skipped_unsubscribed', last_error = %s, updated_at = %s WHERE id = %d AND status = %s AND claim_token = %s",
						$table, __( 'Recipient unsubscribed before recovery.', 'yoohw-advanced-notifications' ), current_time( 'mysql', true ), $row['id'], $row['status'], $row['claim_token']
					) );
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically requeue only the inspected state within the retry limit; never overwrite a claim acquired after the read.
				$wpdb->query( $wpdb->prepare(
					"UPDATE %i SET status = 'dispatch_pending', action_id = 0, claim_token = '', updated_at = %s WHERE id = %d AND status = %s AND attempts < 3",
					$table, current_time( 'mysql', true ), $row['id'], $row['status']
				) );
			}
		}
	}

	private function retry_campaign( $id ) {
		if ( ! $this->acquire_campaign_lock( $id ) ) {
			return 0;
		}
		try {
			$this->resume_campaign_preparation( $id );
			$history = $this->get_campaign_history();
			if ( empty( $history[ $id ] ) ) {
				return 0;
			}
			if ( $this->campaign_blocked( $id ) || ! empty( $history[ $id ]['cancelled'] ) ) { return 0; }
			$history[ $id ]['dispatch_error'] = '';
			$this->save_campaign_record( $history[ $id ] );
			$this->recover_campaign_recipients( $history[ $id ], true );
			$this->enqueue_campaign_dispatch( $id );
			return 1;
		} finally {
			$this->release_campaign_lock( $id );
		}
	}

	private function ensure_delivery_row( $campaign_id, $delivery_id, $email ) {
		global $wpdb;
		if ( $this->read_durable_option( 'yoohw_an_erased_' . hash( 'sha256', strtolower( $email ) ) ) || empty( $campaign_id ) || empty( $delivery_id ) || ! is_email( $email ) || ! $this->recipient_tracking_table_exists() ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE uses the unique delivery/recipient key to initialize a row without resetting another worker's outcome.
		return false !== $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO %i (campaign_id,delivery_id,email_hash,email,status,created_at,updated_at) VALUES (%s,%s,%s,%s,'queued',%s,%s)",
			$this->get_recipient_tracking_table_name(), $campaign_id, $delivery_id, hash( 'sha256', strtolower( $email ) ), strtolower( $email ), $now, $now
		) );
	}

	private function finish_delivery( $campaign_id, $delivery_id, $email, $token, $status, $message = '', $error_kind = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the worker owning this claim token may atomically finalize processing/unknown; cached updates cannot enforce ownership.
		return 1 === $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET status = %s, last_error = %s, error_kind = %s, updated_at = %s WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND claim_token = %s AND status IN ('processing','unknown')",
			$this->get_recipient_tracking_table_name(), $status, $message, $error_kind, current_time( 'mysql', true ), $campaign_id, $delivery_id, hash( 'sha256', strtolower( $email ) ), $token
		) );
	}
}
