<?php
/** Contact history uses existing recipient state in an isolated reliability database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$notify = new YOOHW_AN_Customer_Email_Notify();
	$reflection = new ReflectionClass( $notify );
	$call = static function ( $method, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $method )->invokeArgs( $notify, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$original_get = $_GET; $original_user = get_current_user_id();
	$tag = 'contact-' . strtolower( wp_generate_password( 8, false ) );
	$email = $tag . '@example.com'; $other = $tag . '-other@example.com'; $hash = hash( 'sha256', $email );
	$table = $wpdb->prefix . 'yoohw_an_recipients';
	$ids = $options = $users = [];
	$seed = static function ( $suffix, $status, $address, $reason = '' ) use ( $tag, $table, $wpdb, &$ids ) {
		$id = $tag . '-' . $suffix; $ids[] = $id;
		$now = '2030-01-01 12:00:00';
		if ( false === $wpdb->insert( $table, [ 'campaign_id' => $id, 'delivery_id' => $id, 'email_hash' => hash( 'sha256', $address ), 'email' => $address, 'recipient_name' => 'Contact fixture', 'status' => $status, 'scheduled_at_gmt' => '2030-01-02 13:00:00', 'created_at' => $now, 'updated_at' => $now, 'last_error' => $reason ] ) ) { throw new RuntimeException( 'Recipient fixture could not be saved.' ); }
		return $id;
	};
	try {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! $admins ) { throw new RuntimeException( 'A disposable administrator is required.' ); }
		wp_set_current_user( $admins[0] ); $notify->maybe_install_recipient_tracking_table();
		$id = $seed( 'handoff', 'sent', $email );
		$record = [ 'campaign_id' => $id, 'delivery_id' => $id, 'subject' => 'Delivery context', 'internal_name' => 'Internal note', 'recipient_source_label' => 'Selected orders', 'status' => 'completed' ];
		$call( 'save_campaign_record', $record ); $options[] = $call( 'campaign_record_key', $id );
		$manifest = [ 'payload' => $record, 'confirmed_at' => time(), 'recipients' => [ [ 'email' => $email, 'order_context' => [ 'number' => 'ORDER-42', 'products' => 'Own saved product', 'count' => 1, 'url' => '' ] ], [ 'email' => $other, 'order_context' => [ 'number' => 'OTHER-88', 'products' => 'Other private product', 'count' => 1, 'url' => '' ] ] ] ];
		$key = $call( 'campaign_manifest_key', $id ); $call( 'write_durable_option', $key, $manifest ); $options[] = $key;
		$seed( 'scheduled', 'scheduled', $email );
		$seed( 'unknown', 'unknown', $email, 'Provider did not confirm the result.' );
		$seed( 'failed', 'failed', $email, 'Sender configuration failed.' );
		$seed( 'skipped', 'skipped_unsubscribed', $email, 'Opt-out recorded before delivery.' );
		$seed( 'other', 'sent', $other, 'Other address private diagnostic' );
		$call( 'record_subscription', $email, 'subscribed', 'Contact fixture consent' ); $options[] = 'yoohw_an_subscription_' . $hash;
		$result = $call( 'get_contact_history', strtoupper( $email ) );
		$assert( 'contact_lookup_matches_one_normalized_email', ! is_wp_error( $result ) && $result['total'] === 5 && count( $result['items'] ) === 5 );
		$assert( 'lookup_preserves_distinct_recorded_outcomes', count( array_intersect( [ 'sent', 'scheduled', 'unknown', 'failed', 'skipped_unsubscribed' ], array_column( $result['items'], 'status' ) ) ) === 5 );
		$assert( 'lookup_shows_current_subscription_without_inference', $result['subscription']['status'] === 'subscribed' && $result['subscription']['source'] === 'Contact fixture consent' );
		$by_campaign = array_column( $result['items'], null, 'campaign_id' ); $saved = $by_campaign[ $id ];
		$assert( 'saved_context_and_campaign_link_are_preserved', $saved['subject'] === 'Delivery context' && $saved['order_context']['number'] === 'ORDER-42' && str_contains( $saved['campaign_url'], 'campaign_id=' . $id ) && str_contains( $saved['campaign_url'], 'tab=campaigns' ) );
		$assert( 'lookup_does_not_include_another_recipients_context', ! str_contains( wp_json_encode( $result ), 'Other private product' ) && ! str_contains( wp_json_encode( $result ), 'Other address private diagnostic' ) );
		$assert( 'recorded_times_are_available', $saved['created_at'] === '2030-01-01 12:00:00' && $saved['scheduled_at_gmt'] === '2030-01-02 13:00:00' );
		$assert( 'malformed_email_is_rejected_without_lookup', is_wp_error( $call( 'get_contact_history', [ $email ] ) ) && is_wp_error( $call( 'get_contact_history', $email . ' OR 1=1' ) ) );
		$_GET = [ 'contact_email' => $email ]; ob_start(); $call( 'render_contact_history' ); $html = ob_get_clean();
		$assert( 'render_explains_handoff_and_unknown_honestly', str_contains( $html, 'Handed to mail system' ) && str_contains( $html, 'Outcome unknown' ) && str_contains( $html, 'does not confirm inbox delivery' ) && str_contains( $html, 'Provider did not confirm' ) );
		$assert( 'missing_campaign_content_is_explained', str_contains( $html, 'Campaign content is no longer available' ) );
		$record['subject'] = '<script>alert(1)</script>'; $call( 'save_campaign_record', $record );
		ob_start(); $call( 'render_contact_history' ); $escaped = ob_get_clean();
		$assert( 'saved_subject_is_escaped_in_lookup', ! str_contains( $escaped, '<script>alert(1)</script>' ) );
		$record['retained'] = true; $record['subject'] = 'Stale retained subject'; $call( 'save_campaign_record', $record );
		$wpdb->update( $table, [ 'last_error' => 'Stale retained diagnostic' ], [ 'campaign_id' => $id ] );
		$retained = array_column( $call( 'get_contact_history', $email )['items'], null, 'campaign_id' )[ $id ];
		$assert( 'retention_never_reconstructs_stale_shared_or_recipient_content', ! $retained['content_available'] && '' === $retained['subject'] && [] === $retained['order_context'] && '' === $retained['last_error'] );
		for ( $index = 0; $index < 48; $index++ ) { $seed( 'page-' . $index, 'queued', $email ); }
		$one = $call( 'get_contact_history', $email, 1 ); $two = $call( 'get_contact_history', $email, 2 );
		$assert( 'contact_history_is_bounded_and_paginated', $one['total'] === 53 && count( $one['items'] ) === 50 && count( $two['items'] ) === 3 && ! array_intersect( array_column( $one['items'], 'campaign_id' ), array_column( $two['items'], 'campaign_id' ) ) );
		$assert( 'out_of_range_page_is_clamped', $call( 'get_contact_history', $email, PHP_INT_MAX )['page'] === 2 );
		$_GET = [ 'contact_email' => $email ]; ob_start(); $call( 'render_contact_history' ); $pages = ob_get_clean();
		preg_match_all( '/href="([^"]+)"/', $pages, $links ); $next_query = [];
		foreach ( $links[1] as $href ) {
			parse_str( wp_parse_url( html_entity_decode( $href, ENT_QUOTES ), PHP_URL_QUERY ) ?: '', $query );
			if ( '2' === ( $query['history_page'] ?? '' ) ) { $next_query = $query; break; }
		}
		$assert( 'pagination_retains_exact_email_lookup', ( $next_query['contact_email'] ?? '' ) === $email && ( $next_query['tab'] ?? '' ) === 'subscribers' );
		$subscriber = wp_create_user( $tag, wp_generate_password(), $tag . '-user@example.com' ); if ( is_wp_error( $subscriber ) ) { throw new RuntimeException( $subscriber->get_error_message() ); } $users[] = $subscriber;
		wp_set_current_user( $subscriber );
		$assert( 'customer_cannot_query_contact_history', is_wp_error( $call( 'get_contact_history', $email ) ) );
		ob_start(); $call( 'render_contact_history' ); $denied = ob_get_clean(); $assert( 'customer_cannot_render_contact_lookup', '' === $denied );
		wp_set_current_user( $admins[0] );
		$storage_fault = static function ( $sql ) use ( $table ) { return str_contains( $sql, $table ) && str_contains( $sql, 'COUNT(*)' ) ? 'SELECT * FROM contact_history_missing_fixture' : $sql; };
		$old_errors = $wpdb->suppress_errors(); add_filter( 'query', $storage_fault );
		try { $storage_result = $call( 'get_contact_history', $email ); }
		finally { remove_filter( 'query', $storage_fault ); $wpdb->suppress_errors( $old_errors ); }
		$assert( 'recipient_storage_failure_is_reported_without_partial_history', is_wp_error( $storage_result ) && $storage_result->get_error_code() === 'contact_history_storage' );
		$protection_fault = static function ( $sql ) use ( $hash ) { return str_contains( $sql, 'yoohw_an_erased_' . $hash ) ? 'SELECT * FROM contact_history_missing_fixture' : $sql; };
		$old_errors = $wpdb->suppress_errors(); add_filter( 'query', $protection_fault );
		try { $protection_result = $call( 'get_contact_history', $email ); }
		finally { remove_filter( 'query', $protection_fault ); $wpdb->suppress_errors( $old_errors ); }
		$assert( 'erasure_authority_read_failure_does_not_expose_history', is_wp_error( $protection_result ) && $protection_result->get_error_code() === 'contact_history_storage' );
		$erased_key = 'yoohw_an_erased_' . $hash; $options[] = $erased_key; $call( 'write_durable_option', $erased_key, [ 'erased_at' => time() ] );
		$erased = $call( 'get_contact_history', $email );
		$assert( 'erased_email_cannot_reveal_old_rows_or_manifest', $erased['erased'] && $erased['items'] === [] && $erased['total'] === 0 && $erased['subscription'] === [] );
		ob_start(); $call( 'render_contact_history' ); $hidden = ob_get_clean();
		$assert( 'erased_lookup_does_not_echo_plaintext_or_history', ! str_contains( $hidden, $email ) && ! str_contains( $hidden, 'Own saved product' ) && str_contains( $hidden, 'protected by erasure' ) );
	} finally {
		$_GET = $original_get; wp_set_current_user( $original_user );
		foreach ( $ids as $id ) { $wpdb->delete( $table, [ 'campaign_id' => $id ] ); }
		foreach ( $options as $key ) { delete_option( $key ); }
		require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	}
	echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
