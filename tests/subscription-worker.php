<?php
/** Real-process subscription race fixture; never run against a store database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$input = json_decode( getenv( 'AN_SUBSCRIPTION_INPUT' ), true );
	$dir = getenv( 'AN_SUBSCRIPTION_EVIDENCE' );
	if ( ! is_array( $input ) || ! is_dir( $dir ) || ! preg_match( '/^subscription-race-[a-f0-9]{10}$/', $input['tag'] ?? '' ) ) { throw new RuntimeException( 'Subscription fixture input is invalid.' ); }
	$n = new YOOHW_AN_Customer_Email_Notify(); $r = new ReflectionClass( $n );
	$f = static function ( $method, ...$args ) use ( $n, $r ) { return $r->getMethod( $method )->invokeArgs( $n, $args ); };
	$email = ( $input['id'] ?? $input['tag'] ) . '@example.com'; $hash = hash( 'sha256', $email );
	$_SERVER['REMOTE_ADDR'] = $input['client'] ?? $input['tag'];
	$wait = static function ( $name ) use ( $dir ) { $end = microtime( true ) + 25; while ( ! file_exists( $dir . '/' . $name ) ) { if ( microtime( true ) > $end ) { throw new RuntimeException( 'Subscription fixture barrier timed out: ' . $name ); } usleep( 10000 ); } };
	$normalize = static function ( $result ) { return is_wp_error( $result ) ? [ 'error' => $result->get_error_code() ] : $result; };
	add_filter( 'pre_wp_mail', static function ( $pre, $attributes ) use ( $dir ) {
		$token = ''; $email = '';
		preg_match_all( '/href="([^"]+)"/', $attributes['message'], $links );
		foreach ( $links[1] as $href ) { parse_str( wp_parse_url( html_entity_decode( $href, ENT_QUOTES ), PHP_URL_QUERY ) ?: '', $query ); if ( 'yoohw_an_confirm_subscription' === ( $query['action'] ?? '' ) ) { $token = $query['token'] ?? ''; $email = $query['email'] ?? ''; break; } }
		file_put_contents( $dir . '/transport.jsonl', wp_json_encode( [ 'to' => $attributes['to'], 'subject' => $attributes['subject'], 'email' => $email, 'token' => $token ] ) . "\n", FILE_APPEND | LOCK_EX );
		return true;
	}, PHP_INT_MAX, 2 );
	$privacy_lock = substr( 'yoohw_an_' . hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|__privacy__' ), 0, 64 );
	if ( ! empty( $input['start'] ) ) { touch( $dir . '/' . $input['start'] . '-ready' ); $wait( $input['start_group'] . '-go' ); }
	$once = false;
	$barrier = static function ( $sql ) use ( $input, $dir, $wait, $privacy_lock, $hash, &$once ) {
		if ( $once ) { return $sql; }
		$mode = $input['barrier'] ?? '';
		$matches = 'before-subscription-write' === $mode && str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'yoohw_an_subscription_' . $hash );
		$matches = $matches || ( 'before-erasure-scrub' === $mode && str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'recipient_name' ) && str_contains( $sql, $hash ) );
		if ( $matches ) { $once = true; touch( $dir . '/' . $mode . '-ready' ); $wait( $mode . '-go' ); }
		if ( 'notify-privacy-lock' === $mode && str_contains( $sql, 'GET_LOCK' ) && str_contains( $sql, $privacy_lock ) ) { $once = true; touch( $dir . '/confirm-at-privacy-lock' ); }
		return $sql;
	};
	if ( ! empty( $input['barrier'] ) ) { add_filter( 'query', $barrier ); }
	$out = null;
	if ( 'quota_snapshot' === $input['mode'] ) {
		$value = $f( 'read_durable_option', 'yoohw_an_signup_rate_site' );
		file_put_contents( $dir . '/site-quota-before.json', wp_json_encode( $value ) );
		delete_option( 'yoohw_an_signup_rate_site' ); $out = true;
	} elseif ( 'request' === $input['mode'] ) {
		$out = $normalize( $f( 'request_subscription', $email, 'signup_form' ) );
	} elseif ( 'confirm' === $input['mode'] ) {
		$out = $normalize( $f( 'confirm_subscription', $email, $input['token'] ) );
	} elseif ( 'unsubscribe' === $input['mode'] ) {
		$out = $f( 'add_unsubscribed_email', $email );
	} elseif ( 'erase' === $input['mode'] ) {
		$out = $n->erase_personal_data( $email );
	} elseif ( 'inspect' === $input['mode'] ) {
		$eligible = array_filter( $f( 'get_subscribed_recipients' ), static function ( $recipient ) use ( $email ) { return ( $recipient['email'] ?? '' ) === $email; } );
		$pending = $f( 'read_durable_option', 'yoohw_an_signup_' . $hash );
		$record = $f( 'read_durable_option', 'yoohw_an_subscription_' . $hash );
		$plaintext = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_value LIKE %s', $wpdb->options, $wpdb->esc_like( 'yoohw_an_' ) . '%', '%' . $wpdb->esc_like( $email ) . '%' ) );
		$out = [ 'pending' => $pending, 'subscription' => $record, 'effective' => $f( 'subscription_record', $email ), 'optout' => $f( 'read_durable_option', 'yoohw_an_optout_' . $hash ), 'erased' => (bool) $f( 'read_durable_option', 'yoohw_an_erased_' . $hash ), 'eligible_count' => count( $eligible ), 'plaintext_options' => $plaintext, 'address_quota' => $f( 'read_durable_option', 'yoohw_an_signup_rate_address_' . $hash ), 'site_quota' => $f( 'read_durable_option', 'yoohw_an_signup_rate_site' ) ];
	} elseif ( 'cleanup' === $input['mode'] ) {
		foreach ( $input['ids'] as $id ) {
			$fixture_hash = hash( 'sha256', $id . '@example.com' );
			foreach ( [ 'yoohw_an_signup_', 'yoohw_an_subscription_', 'yoohw_an_optout_', 'yoohw_an_erased_', 'yoohw_an_signup_rate_address_' ] as $prefix ) { delete_option( $prefix . $fixture_hash ); }
		}
		foreach ( $input['clients'] as $client ) { delete_option( 'yoohw_an_signup_rate_client_' . hash_hmac( 'sha256', $client, wp_salt( 'nonce' ) ) ); }
		$before = json_decode( file_get_contents( $dir . '/site-quota-before.json' ), true );
		if ( null === $before ) { delete_option( 'yoohw_an_signup_rate_site' ); } else { $f( 'write_durable_option', 'yoohw_an_signup_rate_site', $before ); }
		$out = true;
	} else { throw new RuntimeException( 'Unknown subscription fixture mode.' ); }
	remove_filter( 'query', $barrier );
	echo 'AN_SUBSCRIPTION_RESULT=' . wp_json_encode( $out ) . "\n";
})();
