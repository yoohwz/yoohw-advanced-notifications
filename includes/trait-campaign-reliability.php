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

	/** Cancel confirmed/history work and scheduler-only originals before removing their runnable jobs. */
	private function block_campaigns_on_deactivation() {
		if ( ! class_exists( 'ActionScheduler' ) ) { return; }
		global $wpdb;
		$after = 0; $seen = [];
		try {
			foreach ( $this->get_campaign_history() as $campaign ) {
				if ( ! empty( $campaign['retained'] ) ) { continue; }
				$id = $campaign['campaign_id']; $manifest = $this->read_durable_option( $this->campaign_manifest_key( $id ) );
				if ( ! is_array( $manifest ) && in_array( $campaign['status'], [ 'previewed', 'test', 'test_sent', 'test_failed', 'empty' ], true ) ) { continue; }
				$counts = $this->get_recipient_tracking_counts( $id, ! empty( $campaign['delivery_id'] ) ? $campaign['delivery_id'] : $id );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Campaign cancellation eligibility could not be read.' ); }
				$unstarted = array_intersect_key( $counts, array_flip( [ 'dispatch_pending', 'queued', 'scheduled', 'queue_failed', 'schedule_failed', 'paused_wait', 'rate_wait', 'cancelled' ] ) );
				$unfinished = empty( $campaign['preparation_complete'] ) && is_array( $manifest );
				if ( $unfinished || array_sum( $unstarted ) || in_array( $campaign['status'], [ 'preparing', 'dispatching', 'queueing', 'scheduling', 'queued', 'scheduled', 'processing' ], true ) ) { $this->save_deactivation_gate( $id ); $seen[ $id ] = true; }
			}
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only this plugin's originals authorize cancellation. Keyset batches bound memory without snapshotting a racy marker-ID list.
				$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT action_id,args,extended_args,status FROM %i WHERE hook = %s AND status IN ('pending','in-progress','failed') AND action_id > %d ORDER BY action_id LIMIT 200", $wpdb->prefix . 'actionscheduler_actions', self::ACTION_SEND_SINGLE_EMAIL, $after ), ARRAY_A );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Campaign cancellation jobs could not be read.' ); }
				foreach ( $jobs as $job ) {
					$after = (int) $job['action_id']; $args = json_decode( $job['extended_args'] ?: $job['args'], true ); $args = $args[0] ?? [];
					if ( 'failed' === $job['status'] ) {
						$marker = $this->read_durable_option( 'yoohw_an_privacy_wait_' . $after );
						if ( ! is_array( $marker ) || empty( $marker['args_hash'] ) || ! hash_equals( $marker['args_hash'], hash( 'sha256', wp_json_encode( $args ) ) ) ) { continue; }
					}
					$id = sanitize_text_field( $args['campaign_id'] ?? '' );
					if ( '' === $id || isset( $seen[ $id ] ) ) { continue; }
					$this->save_deactivation_gate( $id );
					$seen[ $id ] = true;
				}
			} while ( count( $jobs ) === 200 );
		} catch ( Throwable $error ) {
			throw new RuntimeException( __( 'Deactivation did not complete. Some campaign cancellations may already be saved; check storage and admission workers, then retry deactivation.', 'yoohw-advanced-notifications' ), 0, $error );
		}
	}

	/** Both deactivation sources use the existing admission/control mutex and the same durable cancellation authority. */
	private function save_deactivation_gate( $id ) {
		if ( ! $this->acquire_campaign_lock( '__admit_' . $id, 2 ) ) { throw new RuntimeException( 'Campaign admission is busy.' ); }
		try {
			$control = $this->campaign_control( $id ); $control['cancelled'] = true; $control['changed_at'] = time();
			if ( false === $this->write_durable_option( $this->control_key( $id ), $control ) || empty( $this->campaign_control( $id )['cancelled'] ) ) { throw new RuntimeException( 'Campaign cancellation could not be verified.' ); }
		} finally { $this->release_campaign_lock( '__admit_' . $id ); }
	}

	/** Resumable import of pending legacy jobs and verified pre-handoff privacy waits. */
	public function maybe_import_legacy_jobs() {
		if ( ! class_exists( 'ActionScheduler' ) ) { return; }
		$import_pending = ! $this->read_durable_option( 'yoohw_an_reliability_import' );
		$privacy_wait = [];
		foreach ( $this->read_durable_options( 'yoohw_an_privacy_wait_' ) as $marker ) {
			if ( is_array( $marker ) && ! empty( $marker['action_id'] ) && ! empty( $marker['args_hash'] ) && ! empty( $marker['email_hash'] ) ) { $privacy_wait[ absint( $marker['action_id'] ) ] = $marker; }
		}
		if ( ! $import_pending && ! $privacy_wait ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$after = 0;
		do {
			$marked = $privacy_wait ? 'action_id IN (' . implode( ',', array_fill( 0, count( $privacy_wait ), '%d' ) ) . ')' : '0 = 1';
			$params = [ $table, self::ACTION_SEND_SINGLE_EMAIL, $import_pending ? 1 : 0 ];
			foreach ( array_keys( $privacy_wait ) as $action_id ) { $params[] = $action_id; }
			$params[] = $after;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The only dynamic SQL is a list of literal %d placeholders, each supplied an integer action ID. Failed jobs are eligible only with a verified pre-handoff privacy-wait checkpoint below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT action_id,args,extended_args,scheduled_date_gmt,status FROM %i WHERE hook = %s AND ((%d = 1 AND status IN ('pending','in-progress')) OR " . $marked . ') AND action_id > %d ORDER BY action_id LIMIT 200',
				$params
			), ARRAY_A );
			if ( $wpdb->last_error ) {
				return;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row['action_id'];
				$args = json_decode( $row['extended_args'] ?: $row['args'], true );
				$args = $args[0] ?? [];
				$marker = $privacy_wait[ $after ] ?? null;
				if ( $marker ) {
					if ( $this->read_durable_option( 'yoohw_an_erased_' . $marker['email_hash'] ) ) { delete_option( 'yoohw_an_privacy_wait_' . $after ); continue; }
					// A checkpoint is written before throwing. An executing/crashed action must first become failed in AS.
					if ( 'failed' !== $row['status'] || ! hash_equals( $marker['args_hash'], hash( 'sha256', wp_json_encode( $args ) ) ) ) { continue; }
				}
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
					if ( ! $this->acquire_campaign_lock( '__admit_' . $id ) ) { return; }
					try {
						$control = $this->campaign_control( $id );
						$record = $this->read_durable_option( $this->campaign_record_key( $id ) );
						if ( ! empty( $control['cancelled'] ) || ! empty( $record['cancelled'] ) || ! empty( $record['retained'] ) ) { if ( $marker ) { delete_option( 'yoohw_an_privacy_wait_' . $after ); } continue; }
						if ( ! empty( $control['paused'] ) ) { continue; }
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
						if ( ! $this->save_campaign_record( $history[ $id ] ) ) { return; }
					}
					$ready = $this->ensure_delivery_row( $id, $delivery, $args['to'] );
					if ( ! $ready || is_wp_error( $ready ) ) {
						return;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomically attach the legacy job only while action_id is unset; cached state could overwrite a worker's newer association.
					if ( false === $wpdb->query( $wpdb->prepare(
						"UPDATE %i SET action_id = %d, scheduled_at_gmt = COALESCE(scheduled_at_gmt,%s), status = IF(status = 'queued' AND scheduled_at_gmt > UTC_TIMESTAMP(), 'scheduled', status) WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND action_id = 0 AND claim_token = '' AND status IN ('dispatch_pending','queued','scheduled','queue_failed','schedule_failed','paused_wait','rate_wait')",
						$this->get_recipient_tracking_table_name(), $after, $row['scheduled_date_gmt'], $id, $delivery, hash( 'sha256', strtolower( $args['to'] ) )
					) ) ) { return; }
					if ( $marker ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify persisted ownership/due time before retiring the only pre-handoff recovery checkpoint.
						$saved = $wpdb->get_row( $wpdb->prepare( 'SELECT action_id,scheduled_at_gmt,status,claim_token FROM %i WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s', $this->get_recipient_tracking_table_name(), $id, $delivery, $marker['email_hash'] ), ARRAY_A );
						$protected = $saved && ( ! empty( $saved['claim_token'] ) || in_array( $saved['status'], [ 'sent', 'unknown', 'cancelled', 'skipped_unsubscribed' ], true ) );
						if ( ! $saved || ( ! $protected && ( (int) $saved['action_id'] !== $after || $saved['scheduled_at_gmt'] !== $row['scheduled_date_gmt'] ) ) || empty( $this->get_campaign_history()[ $id ] ) ) { return; }
						delete_option( 'yoohw_an_privacy_wait_' . $after );
					}
					} finally { $this->release_campaign_lock( '__admit_' . $id ); }
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
		return false !== $this->write_personal_option( $this->campaign_record_key( $campaign['campaign_id'] ), $campaign );
	}

	/** Connection-scoped locks have no lease that can expire while a worker is still running. */
	private function acquire_campaign_lock( $campaign_id, $timeout = 0 ) {
		global $wpdb;
		$key = 'yoohw_an_' . hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $campaign_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK acquires a lock on this database connection; its result must never be cached or replaced with an options read/write lock.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', substr( $key, 0, 64 ), $timeout ) );
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
				if ( 1 !== $this->write_personal_option( $key, $manifest, true ) ) {
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
		if ( empty( $campaign_id ) || empty( $delivery_id ) || ! is_email( $email ) || ! $this->recipient_tracking_table_exists() ) { return false; }
		$hash = hash( 'sha256', strtolower( $email ) );
		// Existing rows need no identity write. Let normal admission defer busy workers without consuming attempts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read current duplicate-protection identity without rewriting erased personal fields.
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s', $this->get_recipient_tracking_table_name(), $campaign_id, $delivery_id, $hash ) ) ) { return true; }
		if ( ! $this->acquire_campaign_lock( '__privacy__' ) ) { return new WP_Error( 'privacy_busy', 'Privacy cleanup busy before recipient initialization.' ); }
		try {
			if ( $this->read_durable_option( 'yoohw_an_erased_' . $hash ) ) { return false; }
			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- INSERT IGNORE preserves existing outcomes; erased identities cannot initialize an unrelated delivery.
			return false !== $wpdb->query( $wpdb->prepare(
				'INSERT IGNORE INTO %i (campaign_id,delivery_id,email_hash,email,status,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s)',
				$this->get_recipient_tracking_table_name(), $campaign_id, $delivery_id, $hash, strtolower( $email ), 'queued', $now, $now
			) );
		} finally { $this->release_campaign_lock( '__privacy__' ); }
	}

	private function finish_delivery( $campaign_id, $delivery_id, $email, $token, $status, $message = '', $error_kind = '' ) {
		$privacy_locked = $this->acquire_campaign_lock( '__privacy__' );
		try {
			// Diagnostic cleanup must never postpone a known mail outcome. A busy lock or failed privacy read drops text only.
			try { $message = $privacy_locked ? $this->scrub_erased_references( $message ) : ''; }
			catch ( Throwable $error ) { $message = ''; }
		global $wpdb;
		// An erased row must not regain personal diagnostic text from a late worker. Finalization itself never waits for privacy cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the worker owning this claim token may atomically finalize processing/unknown; cached updates cannot enforce ownership.
		return 1 === $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET status = %s, last_error = IF(email = '', '', %s), error_kind = %s, updated_at = %s WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND claim_token = %s AND status IN ('processing','unknown')",
			$this->get_recipient_tracking_table_name(), $status, $message, $error_kind, current_time( 'mysql', true ), $campaign_id, $delivery_id, hash( 'sha256', strtolower( $email ) ), $token
		) );
		} finally { if ( $privacy_locked ) { $this->release_campaign_lock( '__privacy__' ); } }
	}
}
