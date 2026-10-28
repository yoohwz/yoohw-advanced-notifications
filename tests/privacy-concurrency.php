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
	if ( 'erase' === $input['mode'] ) { $out = $n->erase_personal_data( $email ); }
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
