<?php
/** Mailbox-owned subscription and suppression lifecycle on a disposable reliability database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$notify = new YOOHW_AN_Customer_Email_Notify();
	$reflection = new ReflectionClass( $notify );
	$call = static function ( $method, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $method )->invokeArgs( $notify, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$tag = 'signup-' . strtolower( wp_generate_password( 10, false ) );
	$emails = $clients = $campaigns = $users = $mail = [];
	$original_post = $_POST; $original_get = $_GET; $original_request = $_REQUEST; $original_server = $_SERVER; $original_user = get_current_user_id();
	$site_quota_key = 'yoohw_an_signup_rate_site'; $site_quota = $call( 'read_durable_option', $site_quota_key );
	$transport = static function ( $pre, $attributes ) use ( &$mail ) { $mail[] = $attributes; return true; };
	add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX, 2 );
	$address = static function ( $suffix ) use ( $tag, &$emails ) { $email = $tag . '-' . $suffix . '@example.com'; $emails[ $email ] = true; return $email; };
	$set_client = static function () use ( &$clients ) {
		$ip = '198.18.' . random_int( 1, 250 ) . '.' . random_int( 1, 250 ); $_SERVER['REMOTE_ADDR'] = $ip;
		$clients[ 'yoohw_an_signup_rate_client_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) ] = true; return $ip;
	};
	$request = static function ( $email, $source = 'signup_form' ) use ( $call, $set_client ) { $set_client(); return $call( 'request_subscription', $email, $source ); };
	$pending = static function ( $email ) use ( $call ) { return $call( 'read_durable_option', 'yoohw_an_signup_' . hash( 'sha256', $email ) ); };
	$cooldown = static function ( $email ) use ( $call ) {
		$key = 'yoohw_an_signup_rate_address_' . hash( 'sha256', $email ); $state = (array) $call( 'read_durable_option', $key ); $state['expires'] = time() - 1; $call( 'write_durable_option', $key, $state );
	};
	$token_from_last_mail = static function () use ( &$mail ) {
		$last = end( $mail );
		if ( ! preg_match( '/href="([^"]*action=yoohw_an_confirm_subscription[^"]*)"/', $last['message'] ?? '', $match ) ) { throw new RuntimeException( 'No confirmation link was captured.' ); }
		parse_str( wp_parse_url( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $query );
		return $query['token'] ?? '';
	};
	$die_message = '';
	$die_handler = static function () use ( &$die_message ) { return static function ( $message ) use ( &$die_message ) { $die_message = $message; throw new RuntimeException( 'subscription_fixture_die' ); }; };
	$invoke_page = static function ( $callback ) use ( $die_handler, &$die_message ) {
		$die_message = ''; add_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
		try { $callback(); } catch ( RuntimeException $error ) { if ( 'subscription_fixture_die' !== $error->getMessage() ) { throw $error; } }
		finally { remove_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX ); }
		return $die_message;
	};
	$table = $wpdb->prefix . 'yoohw_an_recipients';
	try {
		wp_set_current_user( 0 );
		$email = $address( 'owner' );
		$assert( 'unseen_address_has_no_implied_subscription', 'unknown' === $call( 'subscription_record', $email )['status'] );
		$assert( 'invalid_address_and_source_are_rejected', is_wp_error( $request( 'not-an-email' ) ) && is_wp_error( $request( $email, 'arbitrary_source' ) ) );
		$assert( 'request_is_accepted_without_implying_optin', true === $request( strtoupper( $email ) ) && 'unknown' === $call( 'subscription_record', $email )['status'] );
		$first_pending = $pending( $email ); $token = $token_from_last_mail();
		$assert( 'confirmation_token_is_random_hex_and_stored_only_as_hash', 64 === strlen( $token ) && ctype_xdigit( $token ) && hash( 'sha256', $token ) === $first_pending['token_hash'] && ! str_contains( wp_json_encode( $first_pending ), $token ) );
		$assert( 'request_records_source_and_bounded_confirmation_expiry', 'signup_form' === $first_pending['source'] && $first_pending['requested_at'] <= time() && $first_pending['expires'] > time() && $first_pending['expires'] <= time() + DAY_IN_SECONDS );
		$export = $notify->export_personal_data( $email );
		$assert( 'privacy_export_includes_pending_evidence_without_token', str_contains( wp_json_encode( $export ), 'pending_request' ) && str_contains( wp_json_encode( $export ), 'signup_form' ) && ! str_contains( wp_json_encode( $export ), $token ) && ! str_contains( wp_json_encode( $export ), $first_pending['token_hash'] ) );
		$before = count( $mail ); $request( $email );
		$assert( 'repeated_address_request_is_throttled_without_changing_token', count( $mail ) === $before && $pending( $email ) === $first_pending );
		$assert( 'token_cannot_confirm_another_address_or_wrong_secret', is_wp_error( $call( 'confirm_subscription', $address( 'wrong' ), $token ) ) && is_wp_error( $call( 'confirm_subscription', $email, str_repeat( '0', 64 ) ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [ 'email' => $email, 'token' => $token ]; $_REQUEST = $_GET;
		$get_html = $invoke_page( [ $notify, 'handle_subscription_confirmation' ] );
		$assert( 'confirmation_get_is_read_only_for_link_scanners', str_contains( $get_html, 'method="post"' ) && str_contains( $get_html, 'Confirm subscription' ) && $pending( $email ) === $first_pending && 'unknown' === $call( 'subscription_record', $email )['status'] );
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = [ 'email' => $email, 'token' => $token, '_wpnonce' => wp_create_nonce( 'yoohw_an_confirm_subscription' ) ]; $_REQUEST = $_POST;
		$confirmed_page = $invoke_page( [ $notify, 'handle_subscription_confirmation' ] );
		$record = $call( 'subscription_record', $email );
		$assert( 'confirmation_post_establishes_mailbox_owned_subscription', str_contains( $confirmed_page, 'subscription is confirmed' ) && 'subscribed' === $record['status'] && 'signup_form' === $record['source'] && ! empty( $record['confirmed_at'] ) );
		$assert( 'successful_confirmation_consumes_token_authority', '' === $pending( $email )['token_hash'] && ! empty( $pending( $email )['consumed_at'] ) && is_wp_error( $call( 'confirm_subscription', $email, $token ) ) );
		$assert( 'subscribed_guest_needs_no_account_or_orders', ! get_user_by( 'email', $email ) && in_array( $email, array_column( $call( 'get_recipients_by_payload', [ 'recipient_source' => YOOHW_AN_Customer_Email_Notify::SOURCE_SUBSCRIBED, 'filters' => [] ] ), 'email' ), true ) );
		$expired_email = $address( 'expired' ); $request( $expired_email ); $expired_token = $token_from_last_mail(); $expired = $pending( $expired_email ); $expired['expires'] = time() - 1; $call( 'write_durable_option', 'yoohw_an_signup_' . hash( 'sha256', $expired_email ), $expired );
		$assert( 'expired_confirmation_cannot_subscribe', is_wp_error( $call( 'confirm_subscription', $expired_email, $expired_token ) ) && 'unknown' === $call( 'subscription_record', $expired_email )['status'] );
		$notify->cleanup_subscription_requests();
		$assert( 'expired_request_records_are_removed_by_existing_cleanup', null === $pending( $expired_email ) );

		$assert( 'unsubscribe_applies_to_previously_confirmed_owner', $call( 'add_unsubscribed_email', $email ) && $call( 'is_email_unsubscribed', $email ) );
		$optout_key = 'yoohw_an_optout_' . hash( 'sha256', $email ); $optout = $call( 'read_durable_option', $optout_key );
		$assert( 'import_cannot_remove_durable_optout', false === $call( 'record_subscription', $email, 'subscribed', 'Import fixture' ) && $call( 'is_email_unsubscribed', $email ) && $optout === $call( 'read_durable_option', $optout_key ) );
		$cooldown( $email ); $request( $email ); $stale_token = $token_from_last_mail();
		$call( 'add_unsubscribed_email', $email );
		$assert( 'later_unsubscribe_invalidates_pending_confirmation_generation', is_wp_error( $call( 'confirm_subscription', $email, $stale_token ) ) && $call( 'is_email_unsubscribed', $email ) );
		$cooldown( $email ); $request( $email ); $resubscribe_token = $token_from_last_mail();
		$before_resubscribe = $call( 'read_durable_option', $optout_key );
		$assert( 'resubscribe_request_never_clears_optout_before_confirmation', $call( 'is_email_unsubscribed', $email ) && $before_resubscribe === $call( 'read_durable_option', $optout_key ) );
		$assert( 'only_new_confirmed_owner_request_can_override_prior_optout', true === $call( 'confirm_subscription', $email, $resubscribe_token ) && ! $call( 'is_email_unsubscribed', $email ) && $before_resubscribe === $call( 'read_durable_option', $optout_key ) );
		$fault = static function ( $sql ) use ( $optout_key ) { return str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, "'" . $optout_key . "'" ) ? 'INSERT INTO an6_missing_optout_fixture VALUES (1)' : $sql; };
		$errors = $wpdb->suppress_errors(); add_filter( 'query', $fault );
		try { $failed_unsubscribe = $call( 'add_unsubscribed_email', $email ); }
		finally { remove_filter( 'query', $fault ); $wpdb->suppress_errors( $errors ); }
		$assert( 'unsubscribe_storage_failure_cannot_report_false_success_after_resubscribe', false === $failed_unsubscribe && ! $call( 'is_email_unsubscribed', $email ) && $before_resubscribe === $call( 'read_durable_option', $optout_key ) );
		$assert( 'unsubscribe_retry_creates_new_generation_and_suppresses', true === $call( 'add_unsubscribed_email', $email ) && $call( 'is_email_unsubscribed', $email ) && $before_resubscribe !== $call( 'read_durable_option', $optout_key ) );

		$criteria = [ 'recipient_source' => YOOHW_AN_Customer_Email_Notify::SOURCE_SUBSCRIBED, 'filters' => [] ];
		$unknown_email = $address( 'unknown-import' ); $call( 'record_subscription', $unknown_email, 'unknown', 'Unknown fixture' );
		$pending_email = $address( 'pending-only' ); $request( $pending_email );
		$selected = array_column( $call( 'get_recipients_by_payload', $criteria ), 'email' );
		$assert( 'subscriber_source_excludes_unknown_pending_and_unsubscribed', ! in_array( $email, $selected, true ) && ! in_array( $unknown_email, $selected, true ) && ! in_array( $pending_email, $selected, true ) );
		$assert( 'subscriber_source_rejects_order_country_and_profile_scope', is_wp_error( $call( 'validate_recipient_source_options', array_merge( $criteria, [ 'filters' => [ 'country' => 'VN' ] ] ) ) ) && is_wp_error( $call( 'validate_recipient_source_options', array_merge( $criteria, [ 'filters' => [ 'last_days' => '7' ] ] ) ) ) && is_wp_error( $call( 'validate_recipient_source_options', array_merge( $criteria, [ 'user_roles' => [ 'customer' ] ] ) ) ) );
		$campaign_email = $address( 'campaign' ); $request( $campaign_email ); $call( 'confirm_subscription', $campaign_email, $token_from_last_mail() );
		$campaign_id = $tag . '-campaign'; $campaigns[] = $campaign_id;
		$p = array_merge( $criteria, [ 'campaign_id' => $campaign_id, 'delivery_id' => $campaign_id . '-delivery', 'campaign_hash' => hash( 'sha256', $campaign_id ), 'personalization_version' => 1, 'store_name' => get_bloginfo( 'name' ), 'subject' => 'Subscription fixture', 'heading' => 'Hello {customer_name}', 'message' => '<p>Subscribed email campaign.</p>', 'products_label' => 'Subscribed email addresses', 'from_email' => 'store@example.com', 'from_name' => 'Store', 'reply_to_email' => 'reply@example.com', 'schedule_mode' => 'now', 'product_ids' => [], 'user_roles' => [] ] );
		$r = array_values( array_filter( $call( 'get_recipients_by_payload', $p ), static function ( $recipient ) use ( $campaign_email ) { return $recipient['email'] === $campaign_email; } ) );
		$assert( 'confirmed_subscriber_is_selectable_without_purchasing', count( $r ) === 1 );
		$call( 'confirm_campaign', $p, $r ); $notify->dispatch_campaign_batch( $campaign_id );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT action_id FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $campaign_id, hash( 'sha256', $campaign_email ) ), ARRAY_A );
		$action = ActionScheduler::store()->fetch_action( (int) $row['action_id'] );
		$call( 'add_unsubscribed_email', $campaign_email ); $before_campaign = count( $mail ); $action->execute();
		$assert( 'unsubscribe_after_confirmation_blocks_actual_queued_handoff', count( $mail ) === $before_campaign && 'skipped_unsubscribed' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $campaign_id, hash( 'sha256', $campaign_email ) ) ) );


		$unknown_send = $address( 'scheduled-unknown' ); $request( $unknown_send ); $call( 'confirm_subscription', $unknown_send, $token_from_last_mail() );
		$unknown_id = $tag . '-unknown-campaign'; $campaigns[] = $unknown_id;
		$unknown_p = array_merge( $p, [ 'campaign_id' => $unknown_id, 'delivery_id' => $unknown_id . '-delivery', 'campaign_hash' => hash( 'sha256', $unknown_id ) ] );
		$unknown_r = array_values( array_filter( $call( 'get_recipients_by_payload', $unknown_p ), static function ( $recipient ) use ( $unknown_send ) { return $recipient['email'] === $unknown_send; } ) );
		$call( 'confirm_campaign', $unknown_p, $unknown_r ); $notify->dispatch_campaign_batch( $unknown_id );
		$unknown_row = $wpdb->get_row( $wpdb->prepare( 'SELECT action_id FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $unknown_id, hash( 'sha256', $unknown_send ) ), ARRAY_A );
		$unknown_action = ActionScheduler::store()->fetch_action( (int) $unknown_row['action_id'] );
		$unknown_args = $call( 'hydrate_email_action_args', $unknown_action->get_args()[0] );
		$assert( 'subscribed_source_policy_survives_shared_payload_hydration', ! empty( $unknown_args['subscription_required'] ) );
		$call( 'record_subscription', $unknown_send, 'unknown', 'Verification no longer available' ); $before_unknown = count( $mail ); $unknown_action->execute();
		$unknown_state = $wpdb->get_row( $wpdb->prepare( 'SELECT status,last_error FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $unknown_id, hash( 'sha256', $unknown_send ) ), ARRAY_A );
		$assert( 'subscriber_changed_to_unknown_is_skipped_before_handoff', count( $mail ) === $before_unknown && 'skipped_unsubscribed' === $unknown_state['status'] && str_contains( $unknown_state['last_error'], 'No active subscription' ) );


		$call( 'record_subscription', $unknown_send, 'subscribed', 'Fresh evidence fixture' );
		$body_id = $tag . '-body-race'; $campaigns[] = $body_id;
		$body_p = array_merge( $p, [ 'campaign_id' => $body_id, 'delivery_id' => $body_id . '-delivery', 'campaign_hash' => hash( 'sha256', $body_id ) ] );
		$body_r = array_values( array_filter( $call( 'get_recipients_by_payload', $body_p ), static function ( $recipient ) use ( $unknown_send ) { return $recipient['email'] === $unknown_send; } ) );
		$call( 'confirm_campaign', $body_p, $body_r ); $notify->dispatch_campaign_batch( $body_id );
		$body_row = $wpdb->get_row( $wpdb->prepare( 'SELECT action_id FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $body_id, hash( 'sha256', $unknown_send ) ), ARRAY_A );
		$body_action = ActionScheduler::store()->fetch_action( (int) $body_row['action_id'] );
		$body_transition = static function ( $body ) use ( $call, $unknown_send ) { $call( 'record_subscription', $unknown_send, 'unknown', 'Changed while rendering' ); return $body; };
		$before_body_race = count( $mail ); add_filter( 'woocommerce_mail_content', $body_transition, PHP_INT_MAX );
		try { $body_action->execute(); } finally { remove_filter( 'woocommerce_mail_content', $body_transition, PHP_INT_MAX ); }
		$body_state = $wpdb->get_row( $wpdb->prepare( 'SELECT status,last_error FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $body_id, hash( 'sha256', $unknown_send ) ), ARRAY_A );
		$assert( 'subscription_changed_during_body_render_is_rechecked_before_wp_mail', count( $mail ) === $before_body_race && 'skipped_unsubscribed' === $body_state['status'] && str_contains( $body_state['last_error'], 'No active subscription' ) );

		$storage_email = $address( 'storage-confirm' ); $request( $storage_email ); $storage_token = $token_from_last_mail();
		$record_key = 'yoohw_an_subscription_' . hash( 'sha256', $storage_email );
		$record_fault = static function ( $sql ) use ( $record_key ) { return str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, "'" . $record_key . "'" ) ? 'INSERT INTO an6_missing_subscription_fixture VALUES (1)' : $sql; };
		$errors = $wpdb->suppress_errors(); add_filter( 'query', $record_fault );
		try { $failed_confirmation = $call( 'confirm_subscription', $storage_email, $storage_token ); }
		finally { remove_filter( 'query', $record_fault ); $wpdb->suppress_errors( $errors ); }
		$assert( 'confirmation_publication_failure_reports_error_and_consumes_token', is_wp_error( $failed_confirmation ) && 'unknown' === $call( 'subscription_record', $storage_email )['status'] && is_wp_error( $call( 'confirm_subscription', $storage_email, $storage_token ) ) );
		$storage_request = $address( 'storage-request' ); $request_key = 'yoohw_an_signup_' . hash( 'sha256', $storage_request );
		$request_fault = static function ( $sql ) use ( $request_key ) { return str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, "'" . $request_key . "'" ) ? 'INSERT INTO an6_missing_request_fixture VALUES (1)' : $sql; };
		$errors = $wpdb->suppress_errors(); add_filter( 'query', $request_fault ); $before_fault = count( $mail );
		try { $failed_request = $request( $storage_request ); }
		finally { remove_filter( 'query', $request_fault ); $wpdb->suppress_errors( $errors ); }
		$assert( 'pending_request_storage_failure_sends_no_confirmation', is_wp_error( $failed_request ) && count( $mail ) === $before_fault && ! $pending( $storage_request ) );

		$erased_email = $address( 'erased' ); $request( $erased_email ); $erased_token = $token_from_last_mail();
		$notify->erase_personal_data( $erased_email ); $before_erased = count( $mail );
		$assert( 'erasure_invalidates_pending_confirmation_and_blocks_new_requests', is_wp_error( $call( 'confirm_subscription', $erased_email, $erased_token ) ) && true === $request( $erased_email ) && count( $mail ) === $before_erased && 'unsubscribed' === $call( 'subscription_record', $erased_email )['status'] );
		$assert( 'erasure_never_reconstructs_subscription_or_pending_identity', ! $call( 'read_durable_option', 'yoohw_an_subscription_' . hash( 'sha256', $erased_email ) ) && ! str_contains( wp_json_encode( $pending( $erased_email ) ), $erased_email ) && false === $call( 'record_subscription', $erased_email, 'subscribed', 'Stale import fixture' ) );
		$shortcode = $notify->subscription_shortcode();
		$assert( 'public_shortcode_requires_explicit_unchecked_consent', str_contains( $shortcode, 'name="consent"' ) && ! str_contains( $shortcode, 'checked="checked"' ) && str_contains( $shortcode, '_wpnonce' ) && str_contains( $shortcode, 'does not subscribe you' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = []; $_REQUEST = [];
		$page = $invoke_page( [ $notify, 'handle_subscription_request' ] );
		$assert( 'public_signup_get_does_not_request_mail', str_contains( $page, 'Use the subscription form' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = [ 'email' => $address( 'no-consent' ), '_wpnonce' => wp_create_nonce( 'yoohw_an_signup' ) ]; $_REQUEST = $_POST; $before_form = count( $mail );
		$page = $invoke_page( [ $notify, 'handle_subscription_request' ] );
		$assert( 'public_signup_without_consent_is_rejected', str_contains( $page, 'Choose whether' ) && count( $mail ) === $before_form );
		$_POST['consent'] = '1'; $_POST['signup_website'] = 'bot'; $_REQUEST = $_POST;
		$invoke_page( [ $notify, 'handle_subscription_request' ] );
		$assert( 'public_honeypot_never_sends_confirmation', count( $mail ) === $before_form );


		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = [ 'operation' => 'request' ]; $_REQUEST = $_POST;
		$assert( 'account_handler_requires_authentication', str_contains( $invoke_page( [ $notify, 'handle_account_subscription' ] ), 'Permission denied' ) );
		$account_email = $address( 'account' ); $account_id = wp_create_user( $tag . '-account', wp_generate_password(), $account_email );
		if ( is_wp_error( $account_id ) ) { throw new RuntimeException( $account_id->get_error_message() ); } $users[] = $account_id;
		wp_set_current_user( $account_id ); $set_client();
		$_POST = [ 'operation' => 'request', 'email' => $pending_email, '_wpnonce' => wp_create_nonce( 'yoohw_an_account_subscription' ) ]; $_REQUEST = $_POST;
		$redirected = false;
		$redirect = static function ( $url ) use ( &$redirected ) { $redirected = true; throw new RuntimeException( 'subscription_fixture_redirect' ); };
		add_filter( 'wp_redirect', $redirect, PHP_INT_MIN );
		try { $notify->handle_account_subscription(); } catch ( RuntimeException $error ) { if ( 'subscription_fixture_redirect' !== $error->getMessage() ) { throw $error; } }
		finally { remove_filter( 'wp_redirect', $redirect, PHP_INT_MIN ); }
		$assert( 'account_request_targets_authenticated_email_not_posted_email', $redirected && ( $pending( $account_email )['source'] ?? '' ) === 'my_account' && ( end( $mail )['to'] ?? '' ) === $account_email && 'unknown' === $call( 'subscription_record', $account_email )['status'] );
		$call( 'confirm_subscription', $account_email, $token_from_last_mail() );
		ob_start(); $notify->render_account_subscription(); $account_html = ob_get_clean();
		$assert( 'account_view_displays_effective_preference_and_unsubscribe', str_contains( $account_html, 'subscribed' ) && str_contains( $account_html, 'value="unsubscribe"' ) && str_contains( $account_html, 'email confirmation' ) );
		$_POST = [ 'operation' => 'unsubscribe', 'email' => $pending_email, '_wpnonce' => wp_create_nonce( 'yoohw_an_account_subscription' ) ]; $_REQUEST = $_POST;
		add_filter( 'wp_redirect', $redirect, PHP_INT_MIN );
		try { $notify->handle_account_subscription(); } catch ( RuntimeException $error ) { if ( 'subscription_fixture_redirect' !== $error->getMessage() ) { throw $error; } }
		finally { remove_filter( 'wp_redirect', $redirect, PHP_INT_MIN ); }
		$assert( 'account_unsubscribe_cannot_change_another_posted_address', $call( 'is_email_unsubscribed', $account_email ) && ! $call( 'is_email_unsubscribed', $pending_email ) );
		wp_set_current_user( 0 );

		$client_email = $address( 'quota-client' ); $set_client(); $client_count = count( $mail );
		for ( $i = 0; $i < 6; $i++ ) { $call( 'request_subscription', $address( 'ip-' . $i ), 'signup_form' ); }
		$assert( 'client_request_quota_bounds_six_distinct_addresses_to_five_emails', count( $mail ) === $client_count + 5 );
		$site_before_limit = $call( 'read_durable_option', $site_quota_key );
		$call( 'write_durable_option', $site_quota_key, [ 'used' => 100, 'expires' => time() + HOUR_IN_SECONDS ] );
		$before_site = count( $mail ); $request( $address( 'site-quota' ) );
		$assert( 'site_request_quota_prevents_another_confirmation', count( $mail ) === $before_site );
		$call( 'write_durable_option', $site_quota_key, $site_before_limit );
	} finally {
		remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
		$_POST = $original_post; $_GET = $original_get; $_REQUEST = $original_request; $_SERVER = $original_server; wp_set_current_user( $original_user );
		foreach ( $emails as $email => $unused ) {
			$hash = hash( 'sha256', $email );
			foreach ( [ 'yoohw_an_signup_', 'yoohw_an_subscription_', 'yoohw_an_optout_', 'yoohw_an_erased_', 'yoohw_an_signup_rate_address_' ] as $prefix ) { delete_option( $prefix . $hash ); }
		}
		foreach ( $clients as $key => $unused ) { delete_option( $key ); }
		require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
		if ( null === $site_quota ) { delete_option( $site_quota_key ); } else { $call( 'write_durable_option', $site_quota_key, $site_quota ); }
		foreach ( $campaigns as $id ) {
			foreach ( [ 'campaign_record_key', 'campaign_manifest_key', 'control_key' ] as $method ) { delete_option( $call( $method, $id ) ); }
			delete_option( $call( 'get_campaign_payload_option_name', $id, $id . '-delivery' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE campaign_id = %s', $table, $id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s,%s) AND (args LIKE %s OR extended_args LIKE %s)', $wpdb->prefix . 'actionscheduler_actions', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN, '%' . $wpdb->esc_like( $id ) . '%', '%' . $wpdb->esc_like( $id ) . '%' ) );
		}
	}
	echo wp_json_encode( [ 'passed' => true, 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
