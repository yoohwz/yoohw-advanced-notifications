<?php
/** Subscription evidence, durable suppression, WordPress privacy and terminal retention. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Privacy {
	private function import_subscription_preview( $text, $status, $source ) {
		$preview = [ 'valid' => [], 'duplicates' => 0, 'rejected' => [], 'status' => $status, 'source' => $source ];
		$seen = [];
		foreach ( preg_split( '/\r\n|\r|\n/', trim( $text ) ) as $index => $raw ) {
			$email = strtolower( trim( $raw ) );
			if ( ! is_email( $email ) ) { $preview['rejected'][] = [ 'line' => $index + 1, 'reason' => __( 'Invalid email; use one plain email address per line.', 'yoohw-advanced-notifications' ) ]; continue; }
			if ( isset( $seen[ $email ] ) ) { $preview['duplicates']++; continue; }
			$seen[ $email ] = true;
			$current = $this->subscription_record( $email );
			if ( $this->is_email_unsubscribed( $email ) || $current['status'] === $status ) { $preview['duplicates']++; continue; }
			$preview['valid'][] = $email;
		}
		return $preview;
	}
	private function record_subscription( $email, $status, $source ) {
		$email = strtolower( $email );
		if ( ! is_email( $email ) || ! in_array( $status, [ 'subscribed', 'unsubscribed', 'unknown' ], true ) || '' === trim( $source ) ) { return false; }
		if ( $this->read_durable_option( 'yoohw_an_erased_' . hash( 'sha256', $email ) ) ) { return 'unsubscribed' === $status && $this->add_unsubscribed_email( $email ); }
		if ( 'unsubscribed' === $status ) {
			// The existing durable suppression write is the authority, never the editable evidence record.
			if ( ! $this->add_unsubscribed_email( $email ) ) { return false; }
		} elseif ( $this->is_email_unsubscribed( $email ) ) { return false; }
		return false !== $this->write_durable_option( 'yoohw_an_subscription_' . hash( 'sha256', $email ), [ 'email' => $email, 'status' => $status, 'source' => $source, 'recorded_at' => time(), 'recorded_by' => get_current_user_id() ] );
	}
	public function handle_data_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) ); }
		check_admin_referer( 'yoohw_an_data' );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		if ( 'settings' === $operation ) {
			$limits = [];
			foreach ( [ 'minute', 'day', 'retention_days' ] as $field ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '0' ) );
				if ( ! ctype_digit( $value ) || (int) $value > 1000000 ) { wp_die( esc_html__( 'Enter nonnegative whole limits.', 'yoohw-advanced-notifications' ) ); }
				$limits[ $field ] = (int) $value;
			}
			$this->write_durable_option( 'yoohw_an_limits', $limits );
		} elseif ( 'preview_import' === $operation ) {
			$status = sanitize_key( wp_unslash( $_POST['subscription_status'] ?? '' ) );
			$source = sanitize_text_field( wp_unslash( $_POST['evidence_source'] ?? '' ) );
			if ( ! in_array( $status, [ 'subscribed', 'unsubscribed', 'unknown' ], true ) || '' === $source ) { wp_die( esc_html__( 'Choose a status and record its source. Subscribed requires opt-in evidence.', 'yoohw-advanced-notifications' ) ); }
			$preview = $this->import_subscription_preview( sanitize_textarea_field( wp_unslash( $_POST['import_emails'] ?? '' ) ), $status, $source );
			set_transient( 'yoohw_an_import_' . get_current_user_id(), $preview, 30 * MINUTE_IN_SECONDS );
		} elseif ( 'apply_import' === $operation ) {
			$preview = get_transient( 'yoohw_an_import_' . get_current_user_id() );
			if ( ! $preview ) { wp_die( esc_html__( 'Import preview expired. Preview again.', 'yoohw-advanced-notifications' ) ); }
			$applied = 0;
			foreach ( $preview['valid'] as $email ) { if ( $this->record_subscription( $email, $preview['status'], $preview['source'] ) ) { $applied++; } }
			delete_transient( 'yoohw_an_import_' . get_current_user_id() );
			set_transient( 'yoohw_an_import_result_' . get_current_user_id(), [ 'applied' => $applied, 'not_applied' => count( $preview['valid'] ) - $applied ], 30 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( $this->get_admin_page_url( [ 'tab' => 'settings', 'data_saved' => 1 ] ) ); exit;
	}
	private function render_data_settings() {
		$limits = (array) $this->read_durable_option( 'yoohw_an_limits' );
		echo '<h2>' . esc_html__( 'Sending limits and retention', 'yoohw-advanced-notifications' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_data"><input type="hidden" name="operation" value="settings">'; wp_nonce_field( 'yoohw_an_data' );
		foreach ( [ 'minute' => __( 'Admissions per UTC minute', 'yoohw-advanced-notifications' ), 'day' => __( 'Admissions per UTC day', 'yoohw-advanced-notifications' ), 'retention_days' => __( 'Days after campaign completion before content and personal logs are removed', 'yoohw-advanced-notifications' ) ] as $key => $label ) { echo '<p><label>' . esc_html( $label ) . ' <input type="number" min="0" name="' . esc_attr( $key ) . '" value="' . esc_attr( $limits[ $key ] ?? 0 ) . '"></label></p>'; }
		echo '<p>' . esc_html__( '0 disables that limit/cleanup. Limits cover campaign workers across this site, reserve a slot when a unique send starts, and conservatively keep the slot after a crash or pre-transport failure. UTC fixed windows reset each minute/day. Test mail is not a campaign admission. Retention runs on the hourly reconciliation cron; active campaigns and unfinished preparation are excluded. It removes content, recipient identity/location, snapshots and plugin queue arguments/logs, retaining campaign IDs, recipient hashes, outcomes/claims and durable suppression to prevent repeats. Fallback WooCommerce logs for this plugin are cleared together during erasure or retention; operational outcomes stay in the recipient table. Drafts and saved audiences stay until explicitly deleted or erased through WordPress privacy tools.', 'yoohw-advanced-notifications' ) . '</p><button class="button">' . esc_html__( 'Save limits and retention', 'yoohw-advanced-notifications' ) . '</button></form>';
		echo '<h2>' . esc_html__( 'Subscriptions and suppression', 'yoohw-advanced-notifications' ) . '</h2><p>' . esc_html__( 'An order, account or pasted email is not opt-in evidence. Unknown remains unknown. Imports never remove an unsubscribe. For subscribed records, enter the actual evidence source; the recorded time is the import time, not an inferred original consent time.', 'yoohw-advanced-notifications' ) . '</p>';
		$search = strtolower( sanitize_text_field( wp_unslash( $_GET['subscription_search'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<form method="get"><input type="hidden" name="page" value="yoohw-advanced-notifications"><input type="hidden" name="tab" value="settings"><label>' . esc_html__( 'Find subscription or suppression', 'yoohw-advanced-notifications' ) . ' <input name="subscription_search" value="' . esc_attr( $search ) . '"></label><button class="button">' . esc_html__( 'Search', 'yoohw-advanced-notifications' ) . '</button></form><table class="widefat striped"><tr><th>' . esc_html__( 'Email / state', 'yoohw-advanced-notifications' ) . '</th><th>' . esc_html__( 'Source / recorded time (UTC)', 'yoohw-advanced-notifications' ) . '</th></tr>';
		$records = [];
		foreach ( $this->read_durable_options( 'yoohw_an_subscription_' ) as $record ) { if ( ! empty( $record['email'] ) ) { $records[ $record['email'] ] = $record; } }
		foreach ( $this->get_unsubscribed_emails() as $email => $record ) { $records[ $email ] = array_merge( (array) $record, $records[ $email ] ?? [], [ 'status' => 'unsubscribed' ] ); }
		if ( is_email( $search ) && ! isset( $records[ $search ] ) ) { $records[ $search ] = $this->subscription_record( $search ); }
		$count = 0;
		foreach ( $records as $email => $record ) {
			if ( $search && false === strpos( $email, $search ) ) { continue; }
			if ( ++$count > 200 ) { break; }
			$timestamp = $record['recorded_at'] ?? ( $record['unsubscribed_at'] ?? 0 );
			echo '<tr><td>' . esc_html( $email . ' / ' . $record['status'] ) . '</td><td>' . esc_html( ( $record['source'] ?? 'legacy/unsubscribe' ) . ' / ' . ( $timestamp ? gmdate( 'Y-m-d H:i:s', is_numeric( $timestamp ) ? (int) $timestamp : strtotime( $timestamp ) ) : '—' ) ) . '</td></tr>';
		} echo '</table><p>' . esc_html__( 'Up to 200 matches. Erased addresses are represented only by protective hashes and are not listed as plaintext.', 'yoohw-advanced-notifications' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_data"><input type="hidden" name="operation" value="preview_import">'; wp_nonce_field( 'yoohw_an_data' );
		echo '<p><label>' . esc_html__( 'One email per line', 'yoohw-advanced-notifications' ) . '<br><textarea name="import_emails" rows="5" cols="55"></textarea></label></p><label>' . esc_html__( 'Status', 'yoohw-advanced-notifications' ) . ' <select name="subscription_status">';
		foreach ( [ 'unsubscribed' => __( 'Unsubscribed', 'yoohw-advanced-notifications' ), 'subscribed' => __( 'Subscribed (evidence required)', 'yoohw-advanced-notifications' ), 'unknown' => __( 'No confirmation', 'yoohw-advanced-notifications' ) ] as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'Evidence/source', 'yoohw-advanced-notifications' ) . ' <input name="evidence_source" required></label> <button class="button">' . esc_html__( 'Preview import', 'yoohw-advanced-notifications' ) . '</button></form>';
		$preview = get_transient( 'yoohw_an_import_' . get_current_user_id() );
		if ( $preview ) {
			echo '<p>' . esc_html__( 'Valid / duplicate or already protected / rejected:', 'yoohw-advanced-notifications' ) . ' ' . esc_html( count( $preview['valid'] ) . ' / ' . $preview['duplicates'] . ' / ' . count( $preview['rejected'] ) ) . '</p>';
			foreach ( $preview['rejected'] as $rejection ) { echo '<p>' . esc_html( $rejection['line'] . ': ' . $rejection['reason'] ) . '</p>'; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_data"><input type="hidden" name="operation" value="apply_import">'; wp_nonce_field( 'yoohw_an_data' ); echo '<button class="button">' . esc_html__( 'Apply previewed import', 'yoohw-advanced-notifications' ) . '</button></form>';
		}
		$result = get_transient( 'yoohw_an_import_result_' . get_current_user_id() );
		if ( $result ) { echo '<p role="status">' . esc_html__( 'Applied / not applied (protected or storage failure):', 'yoohw-advanced-notifications' ) . ' ' . esc_html( $result['applied'] . ' / ' . $result['not_applied'] ) . '</p>'; }
		echo '<p>' . esc_html__( 'Use Tools → Export/Erase Personal Data for registered or guest email addresses. Erasure suppresses future reconstruction with a minimal address hash; it keeps opt-out and duplicate-protection hashes. In-flight workers may finish, and their records require another erase pass after completion. Backups outside this plugin and mail-provider logs are managed separately.', 'yoohw-advanced-notifications' ) . '</p>';
	}
	private function clear_plugin_fallback_logs() {
		if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->clear( self::LOG_SOURCE, true ); }
		// Legacy WooCommerce file handlers clear only today's source file; remove older files of this source too.
		if ( class_exists( 'WC_Log_Handler_File' ) ) {
			$handler = new WC_Log_Handler_File();
			foreach ( WC_Log_Handler_File::get_log_files() as $handle => $file ) {
				if ( str_starts_with( $file, self::LOG_SOURCE . '-' ) ) { $handler->remove( $handle ); }
			}
		}
	}
	public function privacy_exporters( $exporters ) {
		$exporters['yoohw-advanced-notifications'] = [ 'exporter_friendly_name' => __( 'YoOhw campaign data', 'yoohw-advanced-notifications' ), 'callback' => [ $this, 'export_personal_data' ] ]; return $exporters;
	}
	public function privacy_erasers( $erasers ) {
		$erasers['yoohw-advanced-notifications'] = [ 'eraser_friendly_name' => __( 'YoOhw campaign data', 'yoohw-advanced-notifications' ), 'callback' => [ $this, 'erase_personal_data' ] ]; return $erasers;
	}
	public function export_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$email = strtolower( $email ); $hash = hash( 'sha256', $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WordPress privacy exporter reads plugin-owned records for exactly one email hash.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT campaign_id,status,scheduled_at_gmt,updated_at,email,recipient_name,country,state,city,last_error FROM %i WHERE email_hash = %s ORDER BY id LIMIT 100 OFFSET %d', $this->get_recipient_tracking_table_name(), $hash, ( max( 1, (int) $page ) - 1 ) * 100 ), ARRAY_A );
		$data = [];
		foreach ( $rows as $index => $row ) {
			$fields = [];
			foreach ( $row as $name => $value ) { $fields[] = [ 'name' => $name, 'value' => $value ]; }
			$data[] = [ 'group_id' => 'yoohw-an', 'group_label' => __( 'Email campaigns', 'yoohw-advanced-notifications' ), 'item_id' => 'yoohw-an-' . $row['campaign_id'] . '-' . $page . '-' . $index, 'data' => $fields ];
		}
		if ( 1 === (int) $page ) {
			$record = $this->subscription_record( $email );
			$record['suppression_evidence'] = $this->read_durable_option( 'yoohw_an_optout_' . $hash ) ?: ( $this->get_unsubscribed_emails()[ $email ] ?? [] );
			$record['protective_erasure_hash'] = (bool) $this->read_durable_option( 'yoohw_an_erased_' . $hash );
			$data[] = [ 'group_id' => 'yoohw-an', 'group_label' => __( 'Email campaigns', 'yoohw-advanced-notifications' ), 'item_id' => 'yoohw-an-subscription', 'data' => [ [ 'name' => __( 'Subscription and protection', 'yoohw-advanced-notifications' ), 'value' => wp_json_encode( $record ) ] ] ];
		}
		if ( 1 === (int) $page ) {
			$references = [];
			foreach ( [ 'yoohw_an_draft_', 'yoohw_an_audience_' ] as $prefix ) {
				foreach ( $this->read_durable_options( $prefix ) as $record ) {
					$fields = $record['criteria'] ?? $record;
					if ( in_array( $email, array_map( 'strtolower', $fields['manual_emails'] ?? [] ), true ) || in_array( $email, array_map( 'strtolower', $fields['exclusions']['emails'] ?? [] ), true ) ) { $references[] = [ 'kind' => $prefix, 'id' => $record['draft_id'] ?? $record['id'] ?? '', 'email' => $email ]; }
				}
			}
			foreach ( (array) $this->read_durable_option( self::OPTION_CAMPAIGN_LOGS ) as $log ) { if ( strtolower( $log['email'] ?? '' ) === $email ) { $references[] = $log; } }
			if ( $references ) { $data[] = [ 'group_id' => 'yoohw-an', 'group_label' => __( 'Email campaigns', 'yoohw-advanced-notifications' ), 'item_id' => 'yoohw-an-references', 'data' => [ [ 'name' => __( 'Saved list references and legacy logs', 'yoohw-advanced-notifications' ), 'value' => wp_json_encode( $references ) ] ] ]; }
		}
		return [ 'data' => $data, 'done' => count( $rows ) < 100 ];
	}
	private function scrub_personal_value( $value, $email ) {
		if ( is_array( $value ) ) {
			if ( strtolower( $value['email'] ?? $value['to'] ?? '' ) === $email ) {
				foreach ( [ 'email', 'to', 'name', 'recipient_name', 'country', 'state', 'city', 'timezone', 'user_id' ] as $field ) { if ( isset( $value[ $field ] ) ) { $value[ $field ] = ''; } }
			}
			$result = [];
			foreach ( $value as $key => $item ) { if ( is_string( $key ) && strtolower( $key ) === $email ) { continue; } $result[ $key ] = $this->scrub_personal_value( $item, $email ); }
			return $result;
		}
		return is_string( $value ) ? str_ireplace( $email, '[erased]', $value ) : $value;
	}
	public function erase_personal_data( $email, $page = 1 ) {
		try { return $this->erase_personal_data_internal( $email ); }
		catch ( Throwable $error ) { return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ __( 'Campaign storage is busy or unavailable. Personal data was not fully erased; run erasure again after checking storage and workers.', 'yoohw-advanced-notifications' ) ], 'done' => true ]; }
	}
	private function erase_personal_data_internal( $email ) {
		global $wpdb;
		$email = strtolower( $email ); $hash = hash( 'sha256', $email );
		if ( ! is_email( $email ) ) { return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ]; }
		// Tombstone first: all send/preparation paths consult it, even after partial erasure or interruption.
		if ( false === $this->write_durable_option( 'yoohw_an_erased_' . $hash, [ 'erased_at' => time() ] ) ) { throw new RuntimeException( 'Privacy protection could not be saved.' ); }
		if ( ! $this->acquire_campaign_lock( '__site_rate__' ) ) { throw new RuntimeException( 'Send admission busy. Run erasure again.' ); }
		try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- In-flight claims must not be erased underneath an executing worker.
		$processing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE email_hash = %s AND status = 'processing'", $this->get_recipient_tracking_table_name(), $hash ) );
		if ( $processing ) { return [ 'items_removed' => false, 'items_retained' => true, 'messages' => [ __( 'A send is in flight. Run erasure again after the worker finishes. A protective hash already blocks future admission.', 'yoohw-advanced-notifications' ) ], 'done' => true ]; }
		if ( ! $this->acquire_campaign_lock( '__privacy__' ) ) { throw new RuntimeException( 'Privacy cleanup busy.' ); }
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove identity but preserve delivery key, token and terminal outcomes for duplicate protection.
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET email = '', recipient_name = '', country = '', state = '', city = '', last_error = '', status = IF(status IN ('sent','unknown'),status,'cancelled') WHERE email_hash = %s AND status <> 'processing'", $this->get_recipient_tracking_table_name(), $hash ) ) ) { throw new RuntimeException( 'Recipient erasure failed.' ); }
			delete_option( 'yoohw_an_subscription_' . $hash );
			if ( $this->read_durable_option( 'yoohw_an_optout_' . $hash ) ) { $this->write_durable_option( 'yoohw_an_optout_' . $hash, [ 'suppressed' => true ] ); }
			// Scrub every plugin source, including the legacy fallback and expiring previews, so deleted identities cannot reappear.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Privacy examines plugin option snapshots directly, including expired transients.
			$options = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'yoohw_an_' ) . '%', $wpdb->esc_like( '_transient_yoohw_an_' ) . '%' ), ARRAY_A );
			foreach ( $options as $option ) {
				if ( false === stripos( $option['option_value'], $email ) ) { continue; }

				$value = maybe_unserialize( $option['option_value'] );
				$id = is_array( $value ) ? ( $value['campaign_id'] ?? $value['payload']['campaign_id'] ?? '' ) : '';
				if ( $id && ! $this->acquire_campaign_lock( $id ) ) { throw new RuntimeException( 'Campaign snapshot busy. Run erasure again.' ); }
				try {
					$value = $this->read_durable_option( $option['option_name'] );
					$record = $id ? ( $this->get_campaign_history()[ $id ] ?? [] ) : [];
					$active = $id && ! in_array( $record['status'] ?? '', [ 'completed', 'completed_with_errors', 'cancelled', 'empty', 'test_sent', 'test_failed', 'previewed' ], true );
					// Preserve active shared content/checksums. Recipient identities are still scrubbed.
					$content = [];
					if ( $active ) {
						foreach ( [ 'subject', 'heading', 'message', 'products_label', 'from_email', 'from_name', 'reply_to_email', 'payload_hash' ] as $field ) {
							if ( isset( $value[ $field ] ) ) { $content[ $field ] = $value[ $field ]; }
						}
						if ( isset( $value['payload']['message'] ) ) { $content['payload_content'] = array_intersect_key( $value['payload'], array_flip( [ 'subject', 'heading', 'message', 'products_label', 'from_email', 'from_name', 'reply_to_email' ] ) ); }
					}
					$value = $this->scrub_personal_value( $value, $email );
					if ( isset( $content['payload_content'] ) ) { $value['payload'] = array_merge( $value['payload'], $content['payload_content'] ); unset( $content['payload_content'] ); }
					if ( is_array( $value ) ) { $value = array_merge( $value, $content ); }
					if ( false === $this->write_durable_option( $option['option_name'], $value ) ) { throw new RuntimeException( 'Snapshot erasure failed.' ); }
				} finally { if ( $id ) { $this->release_campaign_lock( $id ); } }
			}
			$this->scrub_action_data( '', $email );
			$this->clear_plugin_fallback_logs();
			$this->campaign_payload_cache = [];
		} finally { $this->release_campaign_lock( '__privacy__' ); }
		} finally { $this->release_campaign_lock( '__site_rate__' ); }
		return [ 'items_removed' => true, 'items_retained' => true, 'messages' => [ __( 'Personal fields removed. Active campaign shared content is retained until completion; run erasure again if it contains personal text. Minimal recipient/campaign hashes, outcomes and suppression/erasure protection remain to prevent duplicate sends and data reconstruction.', 'yoohw-advanced-notifications' ) ], 'done' => true ];
	}
	private function scrub_action_data( $campaign_id = '', $email = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Action Scheduler retains plugin-owned args after completion; erase those copies as well.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT action_id,args,extended_args,status FROM %i WHERE hook = %s', $table, self::ACTION_SEND_SINGLE_EMAIL ), ARRAY_A );
		foreach ( $rows as $row ) {
			$args = json_decode( $row['extended_args'] ?: $row['args'], true ); $arg = $args[0] ?? [];
			if ( 'in-progress' === $row['status'] || ( $campaign_id && ( $arg['campaign_id'] ?? '' ) !== $campaign_id ) || ( $email && strtolower( $arg['to'] ?? '' ) !== $email ) ) { continue; }
			$replacement = wp_json_encode( [ [ 'campaign_id' => $arg['campaign_id'] ?? '', 'delivery_id' => $arg['delivery_id'] ?? '' ] ] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Update only non-running plugin actions. Never change another WooCommerce hook.
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET args = %s, extended_args = NULL, status = IF(status = 'pending','canceled',status) WHERE action_id = %d AND status <> 'in-progress'", $table, $replacement, $row['action_id'] ) ) ) { throw new RuntimeException( 'Action arguments erasure failed.' ); }
		}
	}
	public function cleanup_retention() {
		$limits = (array) $this->read_durable_option( 'yoohw_an_limits' );
		if ( empty( $limits['retention_days'] ) ) { return; }
		foreach ( $this->get_campaign_history() as $id => $campaign ) {
			if ( ! empty( $campaign['retention_complete'] ) || ! in_array( $campaign['status'], [ 'completed', 'completed_with_errors', 'cancelled', 'empty', 'test_sent', 'test_failed' ], true ) ) { continue; }
			if ( empty( $campaign['completed_at'] ) ) { $campaign['completed_at'] = time(); $this->save_campaign_record( $campaign ); continue; }
			if ( $campaign['completed_at'] > time() - (int) $limits['retention_days'] * DAY_IN_SECONDS || ! $this->acquire_campaign_lock( $id ) ) { continue; }
			try {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Never prune a campaign with recoverable or in-flight recipients.
				$active = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE campaign_id = %s AND (status IN ('dispatch_pending','queued','scheduled','processing','paused_wait','rate_wait') OR status IN ('queue_failed','schedule_failed') AND attempts < 3 OR status = 'failed' AND error_kind = 'configuration' AND attempts < 3)", $this->get_recipient_tracking_table_name(), $id ) );
				if ( $active || ( empty( $campaign['preparation_complete'] ) && empty( $campaign['cancelled'] ) && $this->read_durable_option( $this->campaign_manifest_key( $id ) ) ) ) { continue; }
				$minimal = array_intersect_key( $campaign, array_flip( [ 'campaign_id', 'delivery_id', 'status', 'created_at', 'completed_at', 'cancelled', 'tracking_counts', 'recipient_count' ] ) );
				$minimal['retained'] = true; $minimal['preparation_complete'] = true; $minimal['updated_at'] = time();
				if ( ! $this->save_campaign_record( $minimal ) ) { continue; } // Tombstone before deleting any fallback.
				delete_option( $this->campaign_manifest_key( $id ) );
				delete_option( $this->get_campaign_payload_option_name( $id, ( $campaign['delivery_id'] ?? '' ) ?: $id ) );
				$legacy = (array) $this->read_durable_option( self::OPTION_CAMPAIGNS ); unset( $legacy[ $id ] ); if ( false === $this->write_durable_option( self::OPTION_CAMPAIGNS, $legacy ) ) { throw new RuntimeException( 'Retention fallback cleanup failed.' ); }
				$logs = (array) $this->read_durable_option( self::OPTION_CAMPAIGN_LOGS );
				$logs = array_filter( $logs, static function ( $log ) use ( $id ) { return ( $log['campaign_id'] ?? '' ) !== $id; } ); if ( false === $this->write_durable_option( self::OPTION_CAMPAIGN_LOGS, $logs ) ) { throw new RuntimeException( 'Retention log cleanup failed.' ); }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keep only minimal identity hashes, outcomes and claims after terminal retention.
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET email = '',recipient_name = '',country = '',state = '',city = '',last_error = '' WHERE campaign_id = %s", $this->get_recipient_tracking_table_name(), $id ) ) ) { throw new RuntimeException( 'Retention identity cleanup failed.' ); }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove expired/live preview copies of this terminal campaign as well as its durable snapshots.
				$previews = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_' . self::PREVIEW_TRANSIENT_PREFIX ) . '%' ), ARRAY_A );
				foreach ( $previews as $preview ) { $data = maybe_unserialize( $preview['option_value'] ); if ( ( $data['campaign_id'] ?? '' ) === $id ) { delete_transient( substr( $preview['option_name'], strlen( '_transient_' ) ) ); } }
				$this->scrub_action_data( $id );
				$this->clear_plugin_fallback_logs();
				if ( $this->read_durable_option( $this->campaign_manifest_key( $id ) ) || $this->read_durable_option( $this->get_campaign_payload_option_name( $id, ( $campaign['delivery_id'] ?? '' ) ?: $id ) ) ) { throw new RuntimeException( 'Retention snapshot cleanup incomplete.' ); }
				$minimal['retention_complete'] = true;
				$this->save_campaign_record( $minimal );
			} finally { $this->release_campaign_lock( $id ); }
		}
	}
}
