<?php
/** Targeted real-database privacy race worker. Only disposable databases and intercepted mail. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$input = json_decode( getenv( 'AN_PRIVACY_INPUT' ), true );
	$dir = getenv( 'AN_PRIVACY_EVIDENCE' );
	$n = new YOOHW_AN_Customer_Email_Notify(); $r = new ReflectionClass( $n );
	$f = static function ( $m, ...$a ) use ( $n, $r ) { return $r->getMethod( $m )->invokeArgs( $n, $a ); };
	$id = $input['id']; $email = $id . '@example.com'; $hash = hash( 'sha256', $email );
	$table = $wpdb->prefix . 'yoohw_an_recipients';
	$p = [ 'campaign_id' => $id, 'delivery_id' => $id . '-delivery', 'recipient_source' => 'manual_emails', 'manual_emails' => [ $email ], 'subject' => 'Fixture', 'heading' => 'Fixture', 'message' => '<p>Fixture</p>', 'from_email' => 'store@example.com', 'from_name' => 'Store', 'reply_to_email' => 'reply@example.com', 'products_label' => 'Fixtures', 'product_ids' => [], 'schedule_mode' => 'now' ];
	$recipient = [ 'email' => $email, 'name' => 'Private fixture', 'country' => 'VN', 'confirmed_due' => time() - 120 ];
	$wait = static function ( $name ) use ( $dir ) { $end = microtime( true ) + 25; while ( ! file_exists( $dir . '/' . $name ) ) { if ( microtime( true ) > $end ) { throw new RuntimeException( 'Barrier timed out.' ); } usleep( 10000 ); } };
	add_filter( 'pre_wp_mail', static function ( $pre, $atts ) use ( $dir ) { file_put_contents( $dir . '/transport.jsonl', wp_json_encode( [ 'subject' => $atts['subject'] ] ) . "\n", FILE_APPEND | LOCK_EX ); return true; }, PHP_INT_MAX, 2 );
	$out = null;
	if ( 'legacy_seed' === $input['mode'] ) {
		$args = array_merge( $p, [ 'to' => $email, 'name' => 'Legacy fixture' ] );
		$action_id = as_schedule_single_action( time() - 300, YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, [ $args ], YOOHW_AN_Customer_Email_Notify::ACTION_GROUP );
		delete_option( 'yoohw_an_reliability_import' );
		$out = [ 'action_id' => $action_id, 'due' => ActionScheduler::store()->get_date( $action_id )->format( 'Y-m-d H:i:s' ) ];
	} elseif ( 'legacy_run' === $input['mode'] ) {
		$barrier = static function ( $sql ) use ( $dir, $wait ) { if ( str_starts_with( $sql, 'SELECT option_value' ) && str_contains( $sql, 'yoohw_an_privacy_wait_' ) ) { touch( $dir . '/checkpoint-written' ); $wait( 'release-checkpoint' ); } return $sql; };
		$failure = static function ( $sql ) { return str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'yoohw_an_privacy_wait_' ) ? 'INSERT INTO missing_privacy_wait_fixture VALUES (1)' : $sql; };
		$before = static function ( $sql ) use ( $dir, $wait ) { if ( str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'yoohw_an_privacy_wait_' ) ) { touch( $dir . '/before-checkpoint' ); $wait( 'publish-checkpoint' ); } return $sql; };
		if ( ! empty( $input['hold_before_checkpoint'] ) ) { add_filter( 'query', $before ); }
		if ( ! empty( $input['hold_checkpoint'] ) ) { add_filter( 'query', $barrier ); }
		if ( ! empty( $input['fail_checkpoint'] ) ) { $wpdb->suppress_errors(); add_filter( 'query', $failure ); }
		ActionScheduler_QueueRunner::instance()->process_action( $input['action_id'], 'Privacy concurrency fixture' );
		remove_filter( 'query', $before ); remove_filter( 'query', $barrier ); remove_filter( 'query', $failure );
		$out = ActionScheduler::store()->get_status( $input['action_id'] );
	} elseif ( 'legacy_inspect' === $input['mode'] ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT email,status,attempts,action_id,scheduled_at_gmt FROM %i WHERE campaign_id = %s', $table, $id ), ARRAY_A );
		$action = ActionScheduler::store()->fetch_action( $input['action_id'] );
		$out = [ 'action_status' => ActionScheduler::store()->get_status( $input['action_id'] ), 'marker' => $f( 'read_durable_option', 'yoohw_an_privacy_wait_' . $input['action_id'] ), 'row' => $row, 'history' => isset( $f( 'get_campaign_history' )[ $id ] ), 'cancelled' => ! empty( $f( 'campaign_control', $id )['cancelled'] ), 'args' => $action->get_args() ];
	} elseif ( 'legacy_deactivate' === $input['mode'] ) {
		$key = $f( 'control_key', $id ); $marker_key = 'yoohw_an_privacy_wait_' . $input['action_id']; $armed = false;
		$fault = static function ( $sql ) use ( $key, $marker_key, &$armed, $input ) {
			if ( 'marker-read' === ( $input['fault'] ?? '' ) && str_starts_with( $sql, 'SELECT option_value' ) && str_contains( $sql, $marker_key ) ) { return 'SELECT option_value FROM missing_deactivation_gate_fixture'; }
			if ( ! str_contains( $sql, $key ) ) { return $sql; }
			if ( str_starts_with( $sql, 'INSERT INTO' ) ) { $armed = true; if ( 'write' === ( $input['fault'] ?? '' ) ) { return 'INSERT INTO missing_deactivation_gate_fixture VALUES (1)'; } }
			if ( $armed && 'readback' === ( $input['fault'] ?? '' ) && str_starts_with( $sql, 'SELECT option_value' ) ) { return 'SELECT option_value FROM missing_deactivation_gate_fixture'; }
			return $sql;
		};
		$pending = 0;
		if ( ! empty( $input['fault'] ) ) {
			$pending = as_schedule_single_action( time() + 600, YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, [ array_merge( $p, [ 'campaign_id' => $id . '-extra', 'to' => $email ] ) ], YOOHW_AN_Customer_Email_Notify::ACTION_GROUP );
			$wpdb->suppress_errors(); add_filter( 'query', $fault );
		}
		$failure = false;
		try { $n->deactivate(); } catch ( RuntimeException $error ) { $failure = str_contains( $error->getMessage(), 'Deactivation did not complete' ); }
		remove_filter( 'query', $fault );
		$out = [ 'failed' => $failure, 'cancelled' => ! empty( $f( 'campaign_control', $id )['cancelled'] ), 'marker' => (bool) $f( 'read_durable_option', 'yoohw_an_privacy_wait_' . $input['action_id'] ), 'pending_preserved' => ! $pending || 'pending' === ActionScheduler::store()->get_status( $pending ) ];
	} elseif ( 'stale_resume_seed' === $input['mode'] ) {
		$f( 'confirm_campaign', $p, [ $recipient ] ); $n->dispatch_campaign_batch( $id ); $f( 'control_campaign', $id, 'pause' ); $out = true;
	} elseif ( 'stale_resume' === $input['mode'] ) {
		$key = substr( 'yoohw_an_' . hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|__admit_' . $id ), 0, 64 );
		$once = false;
		$barrier = static function ( $sql ) use ( $key, $dir, $wait, &$once ) { if ( ! $once && str_contains( $sql, 'GET_LOCK' ) && str_contains( $sql, $key ) ) { $once = true; touch( $dir . '/resume-before-admit' ); $wait( 'resume-admit' ); } return $sql; };
		add_filter( 'query', $barrier ); $result = $f( 'control_campaign', $id, 'resume' ); remove_filter( 'query', $barrier );
		$out = [ 'error' => is_wp_error( $result ) ? $result->get_error_code() : '', 'cancelled' => ! empty( $f( 'campaign_control', $id )['cancelled'] ) ];
	} elseif ( 'control_read_fault' === $input['mode'] ) {
		$before = $f( 'campaign_control', $id ); $key = $f( 'control_key', $id );
		$fault = static function ( $sql ) use ( $key ) { return str_starts_with( $sql, 'SELECT option_value' ) && str_contains( $sql, $key ) ? 'SELECT option_value FROM missing_control_read_fixture' : $sql; };
		$wpdb->suppress_errors(); add_filter( 'query', $fault ); $caught = false;
		try { $f( 'control_campaign', $id, 'resume' ); } catch ( RuntimeException $error ) { $caught = true; } remove_filter( 'query', $fault );
		$out = $caught && $before === $f( 'campaign_control', $id );
	} elseif ( 'legacy_tamper' === $input['mode'] ) {
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET args = '[]', extended_args = NULL WHERE action_id = %d", $wpdb->prefix . 'actionscheduler_actions', $input['action_id'] ) ); $out = true;
	} elseif ( 'legacy_fail' === $input['mode'] ) {
		ActionScheduler::store()->mark_failure( $input['action_id'] ); $out = true;
	} elseif ( 'legacy_import_fault' === $input['mode'] ) {
		$key = 'control-read' === $input['case'] ? $f( 'control_key', $id ) : $f( 'campaign_record_key', $id );
		$fault = static function ( $sql ) use ( $input, $key ) {
			if ( in_array( $input['case'], [ 'control-read', 'record-read' ], true ) ) { return str_starts_with( $sql, 'SELECT option_value' ) && str_contains( $sql, $key ) ? 'SELECT option_value FROM missing_privacy_import_fixture' : $sql; }
			$matches = 'snapshot-failure' === $input['case'] ? str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'yoohw_an_record_' ) : str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'SET action_id =' );
			return $matches ? 'UPDATE missing_privacy_import_fixture SET missing = 1' : $sql;
		};
		$wpdb->suppress_errors(); add_filter( 'query', $fault ); touch( $dir . '/fault-ready' ); $wait( 'fault-go' );
		$caught = false; try { $f( 'maybe_import_legacy_jobs' ); } catch ( RuntimeException $error ) { $caught = true; } remove_filter( 'query', $fault );
		$out = (bool) $f( 'read_durable_option', 'yoohw_an_privacy_wait_' . $input['action_id'] );
		if ( in_array( $input['case'], [ 'control-read', 'record-read' ], true ) ) { $out = $out && $caught && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE campaign_id = %s', $table, $id ) ); }
	} elseif ( 'legacy_import' === $input['mode'] ) {
		$f( 'maybe_import_legacy_jobs' ); $out = true;
	} elseif ( 'legacy_recover' === $input['mode'] ) {
		// Exercise existing automatic recovery after the normal missing/failed-job grace interval.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET updated_at = %s WHERE campaign_id = %s AND status IN ('queued','scheduled')", $table, gmdate( 'Y-m-d H:i:s', time() - 301 ), $id ) );
		$n->reconcile_open_campaigns( $id ); $n->dispatch_campaign_batch( $id );
		$actions = as_get_scheduled_actions( [ 'hook' => YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, 'status' => 'pending', 'per_page' => 100 ], 'ids' );
		foreach ( $actions as $action_id ) { $action = ActionScheduler::store()->fetch_action( $action_id ); if ( ( $action->get_args()[0]['campaign_id'] ?? '' ) === $id ) { ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'Privacy recovery fixture' ); } }
		$out = true;
	} elseif ( 'posthandoff_privacy_fault' === $input['mode'] ) {
		$f( 'confirm_campaign', $p, [ $recipient ] );
		$ref = $f( 'store_campaign_email_payload', $p );
		$fault = static function ( $sql ) { return str_starts_with( $sql, 'SELECT option_value' ) && str_contains( $sql, 'yoohw_an_erased_' ) ? 'SELECT option_value FROM missing_privacy_diagnostic_fixture' : $sql; };
		$translate = static function ( $translated, $text ) { return 'Mail system accepted the request; inbox delivery is not verified.' === $text ? 'Accepted for diagnostic-reference@example.com' : $translated; };
		$arm = static function ( $pre ) use ( $fault ) { add_filter( 'query', $fault ); return true; };
		add_filter( 'gettext', $translate, 10, 2 ); add_filter( 'pre_wp_mail', $arm, PHP_INT_MAX ); $wpdb->suppress_errors();
		$n->process_single_email( $f( 'build_email_action_args', $p, $recipient, $ref ) );
		remove_filter( 'query', $fault ); remove_filter( 'gettext', $translate ); remove_filter( 'pre_wp_mail', $arm );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,claim_token,last_error FROM %i WHERE campaign_id = %s', $table, $id ), ARRAY_A );
		$out = 'sent' === $row['status'] && ! empty( $row['claim_token'] ) && '' === $row['last_error'];
	} elseif ( 'subscription_reference' === $input['mode'] ) {
		$n->erase_personal_data( $email );
		$other = $id . '-other@example.com';
		$saved = $f( 'record_subscription', $other, 'subscribed', 'Opt-in evidence from ' . $email );
		$record = $f( 'read_durable_option', 'yoohw_an_subscription_' . hash( 'sha256', $other ) );
		$out = [ 'evidence_source' => $saved && false === stripos( wp_json_encode( $record ), $email ) && $record['email'] === $other ];
		$optout = $id . '-optout@example.com'; $f( 'add_unsubscribed_email', $optout, $email );
		$out['optout_reference'] = false === stripos( wp_json_encode( $f( 'read_durable_option', 'yoohw_an_optout_' . hash( 'sha256', $optout ) ) ), $email );
		$f( 'track_recipient_status', $id, $other, 'queued', 0, 'Diagnostic for ' . $email );
		$diagnostic = $wpdb->get_var( $wpdb->prepare( 'SELECT last_error FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $id, hash( 'sha256', $other ) ) );
		$out['tracking_diagnostic'] = false === stripos( $diagnostic, $email );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET email = %s,status = 'processing',claim_token = 'source-fixture' WHERE campaign_id = %s", $table, $other, $id ) );
		$f( 'finish_delivery', $id, $id, $other, 'source-fixture', 'unknown', 'Late diagnostic for ' . $email );
		$diagnostic = $wpdb->get_var( $wpdb->prepare( 'SELECT last_error FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $id, hash( 'sha256', $other ) ) );
		$out['final_diagnostic'] = false === stripos( $diagnostic, $email );
	} elseif ( 'erase' === $input['mode'] ) { $out = $n->erase_personal_data( $email ); }
	elseif ( 'lock' === $input['mode'] ) {
		foreach ( $input['locks'] as $lock ) { if ( ! $f( 'acquire_campaign_lock', $lock ) ) { throw new RuntimeException( 'Fixture lock unavailable.' ); } }
		touch( $dir . '/ready' ); $wait( 'go' );
		foreach ( array_reverse( $input['locks'] ) as $lock ) { $f( 'release_campaign_lock', $lock ); } $out = true;
	} elseif ( 'worker' === $input['mode'] ) {
		$args = $f( 'build_email_action_args', $p, $recipient, $f( 'store_campaign_email_payload', $p ) );
		$n->process_single_email( $args ); $out = true;
	} elseif ( 'seed' === $input['mode'] ) { $f( 'confirm_campaign', $p, [ $recipient ] ); $n->dispatch_campaign_batch( $id ); $out = true; }
	elseif ( 'inspect' === $input['mode'] ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email,status,attempts,claim_token,scheduled_at_gmt,last_error FROM %i WHERE campaign_id = %s', $table, $id ), ARRAY_A );
		$options = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'yoohw_an_' ) . '%', $wpdb->esc_like( '_transient_yoohw_an_' ) . '%' ), ARRAY_A );
		$out = [ 'rows' => $rows, 'plaintext_options' => count( array_filter( $options, static function ( $v ) use ( $email ) { return false !== stripos( $v['option_value'], $email ); } ) ), 'subscription' => $f( 'read_durable_option', 'yoohw_an_subscription_' . $hash ), 'optout' => $f( 'read_durable_option', 'yoohw_an_optout_' . $hash ) ];
	} elseif ( 'preparation' === $input['mode'] ) {
		$kept = [ 'email' => $id . '-kept@example.com', 'name' => 'Kept' ];
		$recipients = [ $kept, $recipient ]; $p['manual_emails'][] = $kept['email'];
		$f( 'write_durable_option', $f( 'campaign_manifest_key', $id ), [ 'payload' => $p, 'recipients' => $recipients, 'confirmed_at' => time() ] );
		$f( 'record_campaign_snapshot', $p, 'preparing', [ 'recipient_count' => 2 ] );
		$f( 'prepare_campaign_recipients_for_dispatch', $p, [ $kept ] );
		$failure = static function ( $sql ) { return str_contains( $sql, 'INSERT IGNORE INTO' ) && str_contains( $sql, "'cancelled'" ) ? 'INSERT INTO missing_privacy_fixture VALUES (1)' : $sql; };
		$old = $wpdb->suppress_errors(); add_filter( 'query', $failure ); $failed = $n->erase_personal_data( $email ); remove_filter( 'query', $failure ); $wpdb->suppress_errors( $old );
		$erased = $n->erase_personal_data( $email );
		$manifest = $f( 'read_durable_option', $f( 'campaign_manifest_key', $id ) );
		$f( 'resume_campaign_preparation', $id ); $n->dispatch_campaign_batch( $id );
		$n->process_single_email( $f( 'build_email_action_args', $p, $kept, $f( 'store_campaign_email_payload', $p ) ) );
		$n->reconcile_open_campaigns( $id );
		$history = $f( 'get_campaign_history' )[ $id ];
		$erased_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $id, $hash ), ARRAY_A );
		$out = [ 'failure_reported' => ! $failed['items_removed'] && $failed['items_retained'], 'erased' => $erased['items_removed'], 'manifest_count' => count( $manifest['recipients'] ), 'placeholder' => $manifest['recipients'][1], 'status' => $history['status'], 'unresolved' => $history['unresolved_count'], 'row_status' => $erased_row['status'], 'row_email' => $erased_row['email'], 'due_preserved' => $erased_row['scheduled_at_gmt'] === gmdate( 'Y-m-d H:i:s', $recipient['confirmed_due'] ) ];
	} elseif ( 'ordinary_identity' === $input['mode'] ) {
		$f( 'save_campaign_record', array_merge( $p, [ 'status' => 'preparing' ] ) );
		$f( 'track_recipient_status', $id, $email, 'queued' );
		$f( 'prepare_campaign_recipients_for_dispatch', $p, [ $recipient ] );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT email,recipient_name,status,claim_token FROM %i WHERE campaign_id = %s', $table, $id ), ARRAY_A );
		$out = $row['email'] === $email && $row['recipient_name'] === $recipient['name'] && 'queued' === $row['status'] && '' === $row['claim_token'];
	} elseif ( 'shared_policy' === $input['mode'] ) {
		$p['message'] = '<p>Confirmed personal text ' . $email . '</p>';
		$f( 'confirm_campaign', $p, [ $recipient ] );
		$ref = $f( 'store_campaign_email_payload', $p );
		$before = $f( 'read_durable_option', $ref['payload_key'] );
		$n->erase_personal_data( $email );
		$after = $f( 'read_durable_option', $ref['payload_key'] );
		$f( 'save_campaign_record', array_merge( $p, [ 'status' => 'completed_with_errors', 'preparation_complete' => true ] ) );
		$n->erase_personal_data( $email );
		$terminal = $f( 'read_durable_option', $ref['payload_key'] );
		$out = [ 'active_preserved' => $before === $after, 'terminal_scrubbed' => false === stripos( wp_json_encode( $terminal ), $email ) ];
	} elseif ( 'preservation' === $input['mode'] ) {
		$f( 'confirm_campaign', $p, [ $recipient ] );
		// Existing sent/unknown claims must survive both erase and a stale preparation retry.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s, claim_token = 'preserved-token' WHERE campaign_id = %s", $table, $input['status'], $id ) );
		$erased = $n->erase_personal_data( $email ); $f( 'prepare_campaign_recipients_for_dispatch', $p, [ $recipient ] );
		if ( 'unknown' === $input['status'] ) { $f( 'finish_delivery', $id, $p['delivery_id'], $email, 'preserved-token', 'unknown', 'Late diagnostic for ' . $email ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT email,recipient_name,status,claim_token,last_error FROM %i WHERE campaign_id = %s', $table, $id ), ARRAY_A );
		$out = [ 'erased' => $erased['items_removed'], 'row' => $row ];
	} elseif ( 'publish' === $input['mode'] ) {
		$kind = $input['kind'];
		if ( ! empty( $input['pause'] ) ) {
			$needle = [ 'subscription' => 'yoohw_an_subscription_', 'optout' => 'yoohw_an_optout_', 'ensure' => 'yoohw_an_recipients', 'prepare' => 'yoohw_an_recipients', 'draft' => 'yoohw_an_draft_', 'audience' => 'yoohw_an_audience_', 'preview' => '_transient_yoohw_an_preview_', 'import' => '_transient_yoohw_an_import_', 'snapshot' => 'yoohw_an_record_', 'manifest' => 'yoohw_an_manifest_', 'shared' => 'yoohw_an_payload_', 'log' => 'yoohw_an_recipients' ][ $kind ];
			add_filter( 'query', static function ( $sql ) use ( $needle, $dir, $wait ) { if ( str_contains( $sql, 'INSERT' ) && str_contains( $sql, $needle ) ) { touch( $dir . '/ready' ); $wait( 'go' ); } return $sql; } );
		}
		if ( 'subscription' === $kind ) { $out = $f( 'record_subscription', $email, 'subscribed', 'Fixture evidence' ); }
		elseif ( 'optout' === $kind ) { $out = $f( 'record_subscription', $email, 'unsubscribed', 'Fixture evidence' ); }
		elseif ( 'log' === $kind ) { $out = $f( 'store_campaign_log_event', $id, $email, 'failed', 'Diagnostic for ' . $email ); }
		elseif ( 'ensure' === $kind ) { $out = $f( 'ensure_delivery_row', $id, $p['delivery_id'], $email ); }
		elseif ( 'prepare' === $kind ) { $out = $f( 'prepare_campaign_recipients_for_dispatch', $p, [ $recipient ] ); }
		elseif ( 'draft' === $kind ) { $out = $f( 'save_draft', $p, $id ); }
		elseif ( 'audience' === $kind ) { $out = $f( 'write_personal_option', 'yoohw_an_audience_' . $id, [ 'id' => $id, 'criteria' => $p ] ); }
		elseif ( 'preview' === $kind ) { $out = $f( 'store_preview_payload', $p ); }
		elseif ( 'import' === $kind ) { $out = $f( 'set_personal_transient', 'yoohw_an_import_' . $id, [ 'valid' => [ $email ], 'source' => $email ], 3600 ); }
		elseif ( 'snapshot' === $kind ) { $out = $f( 'save_campaign_record', array_merge( $p, [ 'status' => 'preparing' ] ) ); }
		elseif ( 'manifest' === $kind ) { $out = $f( 'confirm_campaign', $p, [ $recipient ] ); }
		elseif ( 'shared' === $kind ) { $p['message'] = '<p>Shared ' . $email . '</p>'; $out = $f( 'store_campaign_email_payload', $p ); }
	}
	echo 'AN_PRIVACY_RESULT=' . wp_json_encode( $out ) . "\n";
})();
