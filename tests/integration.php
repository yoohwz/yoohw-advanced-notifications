<?php
/** Run with wp eval-file in a disposable WordPress + WooCommerce installation. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'YOOHW_AN_Customer_Email_Notify' ) ) {
	exit( 'Run using WP-CLI with WooCommerce and this plugin active.' );
}

(function () {
global $wpdb;
$notify = new YOOHW_AN_Customer_Email_Notify();
$class = new ReflectionClass( $notify );
$call = static function ( $method, ...$args ) use ( $class, $notify ) {
	return $class->getMethod( $method )->invokeArgs( $notify, $args );
};
$checks = [];
$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; };
$original_options = [];
foreach ( [ 'yoohw_an_campaigns', 'yoohw_an_campaign_logs', 'yoohw_an_unsubscribed_emails', 'yoohw_an_from_email', 'yoohw_an_from_name', 'yoohw_an_reply_to_email', 'yoohw_an_suppress_automated_replies', 'yoohw_an_no_reply_email', 'woocommerce_email_from_address', 'woocommerce_email_from_name' ] as $name ) {
	$original_options[ $name ] = get_option( $name, null );
}
$original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Snapshot CLI fixture state; this does not process a browser request.
$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Snapshot CLI fixture state; this does not process a browser request.
$original_user = get_current_user_id();
$products = $orders = $users = $cleanup_options = $captured_mail = $queued = [];
$preview_token = '';
$campaign_id = 'an-test-' . wp_generate_uuid4();
$mail_filter = static function ( $pre, $attributes ) use ( &$captured_mail ) { $captured_mail[] = $attributes; return true; };
// Older Action Scheduler versions do not pass $unique to the pre-scheduling filters.
$queue_filter = static function ( $pre, $hook, $args, $group, $priority, $unique = false ) use ( &$queued ) {
	$queued[] = compact( 'hook', 'args', 'group', 'unique' );
	return 900000 + count( $queued );
};
$schedule_filter = static function ( $pre, $timestamp, $hook, $args, $group, $priority, $unique = false ) use ( &$queued ) {
	$queued[] = compact( 'timestamp', 'hook', 'args', 'group', 'unique' );
	return 900000 + count( $queued );
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
add_filter( 'pre_as_enqueue_async_action', $queue_filter, 10, 6 );
add_filter( 'pre_as_schedule_single_action', $schedule_filter, 10, 7 );
try {
	$notify->maybe_install_recipient_tracking_table();
	$table = $call( 'get_recipient_tracking_table_name' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the custom-table schema directly in the disposable test database.
	$assert( 'tracking_table_installed', $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
	$assert( 'four_local_recipient_sources', [ 'product_customers', 'woocommerce_customers', 'registered_users', 'manual_emails' ] === array_keys( $call( 'get_recipient_sources' ) ) );
	$assert( 'unsupported_stored_source_is_empty', [] === $call( 'get_base_recipients_by_payload', [ 'recipient_source' => 'current_subscribers' ] ) );
	update_option( 'woocommerce_email_from_address', 'store@example.com' );
	update_option( 'woocommerce_email_from_name', 'Test Store' );
	delete_option( 'yoohw_an_from_email' );
	delete_option( 'yoohw_an_from_name' );
	$assert( 'sender_defaults_to_current_store', 'store@example.com' === $call( 'get_sender_email' ) && 'Test Store' === $call( 'get_sender_name' ) );
	$tag = strtolower( wp_generate_password( 8, false ) );
	$user_id = wp_create_user( 'an-' . $tag, wp_generate_password(), $tag . '@example.com' );
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
	$users[] = $user_id;
	$customer_user = new WP_User( $user_id );
	$customer_user->set_role( 'customer' );
	$customer_user->add_role( 'shop_manager' );
	$subscriber_id = wp_create_user( 'an-sub-' . $tag, wp_generate_password(), 'subscriber-' . $tag . '@example.com' );
	if ( is_wp_error( $subscriber_id ) ) { throw new RuntimeException( $subscriber_id->get_error_message() ); }
	$users[] = $subscriber_id;
	( new WP_User( $subscriber_id ) )->set_role( 'subscriber' );
	$role_recipients = $call( 'get_base_recipients_by_payload', [ 'recipient_source' => 'registered_users', 'user_roles' => [ 'customer' ] ] );
	$assert( 'role_filter_selects_customers_only', [ $tag . '@example.com' ] === array_column( $role_recipients, 'email' ) );
	$role_recipients = $call( 'get_base_recipients_by_payload', [ 'recipient_source' => 'registered_users', 'user_roles' => [ 'shop_manager', 'subscriber' ] ] );
	$assert( 'roles_match_any_and_deduplicate_users', 2 === count( $role_recipients ) );
	$assert( 'invalid_role_does_not_expand_audience', [] === $call( 'get_base_recipients_by_payload', [ 'recipient_source' => 'registered_users', 'user_roles' => [ 'missing-role' ] ] ) );
	$assert( 'empty_roles_preserve_all_users', count( get_users( [ 'fields' => 'ID' ] ) ) === count( $call( 'get_base_recipients_by_payload', [ 'recipient_source' => 'registered_users' ] ) ) );
	$assert( 'registered_users_are_selectable', in_array( $tag . '@example.com', array_column( $call( 'get_registered_user_recipients' ), 'email' ), true ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'Notification test product' );
	$product->set_regular_price( '10' );
	$product->save();
	$products[] = $product;
	$variable = new WC_Product_Variable();
	$variable->set_name( 'Notification test variable product' );
	$variable->save();
	$products[] = $variable;
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_regular_price( '12' );
	$variation->save();
	$products[] = $variation;
	foreach ( [ 'completed', 'processing', 'pending', 'refunded' ] as $status ) {
		$order = wc_create_order();
		$orders[] = $order;
		$order->set_billing_email( $status . '-' . $tag . '@example.com' );
		$order->set_billing_first_name( 'Fixture' );
		$order->set_billing_country( 'VN' );
		if ( 'processing' === $status ) { $order->set_customer_id( $user_id ); }
		$order->add_product( $product, 1 );
		if ( 'completed' === $status ) { $order->add_product( $variation, 1 ); }
		$order->set_status( $status );
		$order->save();
	}
	$duplicate = wc_create_order();
	$orders[] = $duplicate;
	$duplicate->set_billing_email( strtoupper( 'completed-' . $tag . '@example.com' ) );
	$duplicate->add_product( $product, 1 );
	$duplicate->set_status( 'completed' );
	$duplicate->save();
	$all_customers = $call( 'get_base_recipients_by_payload', [ 'recipient_source' => 'woocommerce_customers' ] );
	$assert( 'woocommerce_customers_include_guests_and_accounts', 2 === count( $all_customers ) && in_array( 0, array_column( $all_customers, 'user_id' ), true ) && in_array( $user_id, array_column( $all_customers, 'user_id' ), true ) );
	$assert( 'woocommerce_customers_deduplicate_billing_emails', 2 === count( $all_customers ) );
	$assert( 'product_source_still_requires_product_scope', [] === $call( 'get_customers_by_products', [] ) );
	$recipients = $call( 'get_customers_by_products', [ $product->get_id() ] );
	$assert( 'only_paid_order_statuses_selected', 2 === count( $recipients ) && ! in_array( 'pending-' . $tag . '@example.com', array_column( $recipients, 'email' ), true ) );
	$assert( 'variable_parent_matches_variation_purchase', 1 === count( $call( 'get_customers_by_products', [ $variable->get_id() ] ) ) );
	$assert( 'variation_can_be_selected_directly', 1 === count( $call( 'get_customers_by_products', [ $variation->get_id() ] ) ) );
	$assert( 'manual_email_exclusion', 1 === count( $call( 'apply_recipient_exclusions', $recipients, [ 'exclusions' => [ 'emails' => [ 'processing-' . $tag . '@example.com' ] ] ] ) ) );
	$assert( 'order_status_exclusion', 1 === count( $call( 'apply_recipient_exclusions', $recipients, [ 'exclusions' => [ 'order_statuses' => [ 'wc-completed' ] ] ] ) ) );
	$assert( 'product_purchase_exclusion', 1 === count( $call( 'apply_recipient_exclusions', $recipients, [ 'exclusions' => [ 'product_ids' => [ $variation->get_id() ] ] ] ) ) );
	$_POST = [ 'recipient_source' => 'registered_users', 'email_subject' => 'Campaign test', 'email_heading' => 'Hello', 'email_message' => '<p>Hello {customer_name}, {product_name}.</p>' ];
	$payload = $call( 'get_payload_from_request' );
	$assert( 'campaign_request_validates', ! is_wp_error( $payload ) );
	$_POST['user_roles'] = [ 'customer' ];
	$role_payload = $call( 'get_payload_from_request' );
	$assert( 'role_selection_is_in_campaign_hash', ! is_wp_error( $role_payload ) && $role_payload['campaign_hash'] !== $payload['campaign_hash'] );
	$_POST['user_roles'] = [ 'not-a-role' ];
	$assert( 'invalid_role_request_rejected', is_wp_error( $call( 'get_payload_from_request' ) ) );
	$_POST['user_roles'] = 'customer';
	$assert( 'malformed_role_request_rejected', is_wp_error( $call( 'get_payload_from_request' ) ) );
	$_POST['recipient_source'] = 'woocommerce_customers';
	$assert( 'woocommerce_customer_request_needs_no_products', ! is_wp_error( $call( 'get_payload_from_request' ) ) );
	$_POST['recipient_source'] = 'manual_emails';
	$_POST['manual_emails'] = "One@example.com, one@example.com;\ntwo@example.com three@example.com";
	$manual_payload = $call( 'get_payload_from_request' );
	$assert( 'manual_list_parses_and_deduplicates', ! is_wp_error( $manual_payload ) && [ 'one@example.com', 'two@example.com', 'three@example.com' ] === $manual_payload['manual_emails'] );
	$assert( 'manual_list_builds_recipients', 3 === count( $call( 'get_base_recipients_by_payload', $manual_payload ) ) );
	$manual_reordered = $manual_payload;
	$manual_reordered['manual_emails'] = array_reverse( $manual_payload['manual_emails'] );
	$assert( 'manual_hash_ignores_list_order', $manual_payload['campaign_hash'] === $call( 'generate_campaign_hash', $manual_reordered ) );
	$manual_reordered['manual_emails'][] = 'four@example.com';
	$assert( 'manual_hash_changes_with_recipients', $manual_payload['campaign_hash'] !== $call( 'generate_campaign_hash', $manual_reordered ) );
	foreach ( [ '', 'bad@@example.com', 'bad<one@example.com>', [ 'one@example.com' ] ] as $bad_list ) {
		$_POST['manual_emails'] = $bad_list;
		$assert( 'invalid_manual_list_' . count( $checks ), is_wp_error( $call( 'get_payload_from_request' ) ) );
	}
	foreach ( [ $role_payload, $manual_payload ] as $scope_payload ) {
		$scope_payload['campaign_id'] = $campaign_id;
		$call( 'record_campaign_snapshot', $scope_payload, 'previewed' );
		$stored = $call( 'get_campaign_history' )[ $campaign_id ];
		$restored = $call( 'get_campaign_reschedule_payload', $stored );
		$assert( $scope_payload['recipient_source'] . '_scope_survives_history_and_retry', ! is_wp_error( $restored ) && $scope_payload['user_roles'] === $restored['user_roles'] && $scope_payload['manual_emails'] === $restored['manual_emails'] );
		$scope_token = $call( 'store_preview_payload', $scope_payload );
		$restored = $call( 'get_restore_payload', $scope_token );
		$assert( $scope_payload['recipient_source'] . '_scope_survives_draft_restore', $scope_payload['user_roles'] === $restored['user_roles'] && $scope_payload['manual_emails'] === $restored['manual_emails'] );
		$call( 'delete_preview_payload', $scope_token );
	}
	unset( $_POST['user_roles'], $_POST['manual_emails'] );
	$_POST['recipient_source'] = 'active_subscribers';
	$invalid = $call( 'get_payload_from_request' );
	$assert( 'subscription_request_rejected', is_wp_error( $invalid ) && 'yoohw_an_invalid_source' === $invalid->get_error_code() );
	$_POST['recipient_source'] = 'registered_users';
	$_POST['schedule_mode'] = 'scheduled';
	$_POST['scheduled_at'] = '2027-02-30T10:00';
	$assert( 'invalid_calendar_date_rejected', is_wp_error( $call( 'get_payload_from_request' ) ) );
	$payload['campaign_id'] = $campaign_id;
	$payload['message'] .= str_repeat( '<p>Shared rich email content.</p>', 2000 );
	$recipient = [ 'email' => 'delivery-' . $tag . '@example.com', 'name' => 'Fixture Recipient', 'country' => 'VN' ];
	$payload['recipient_source'] = 'manual_emails';
	$payload['recipient_source_label'] = 'Manual email list';
	$payload['manual_emails'] = [ $recipient['email'] ];
	$reference = $call( 'store_campaign_email_payload', $payload );
	if ( is_wp_error( $reference ) ) { throw new RuntimeException( $reference->get_error_message() ); }
	$args = $call( 'build_email_action_args', $payload, $recipient, $reference );
	$cleanup_options[] = $call( 'get_campaign_payload_option_name', $campaign_id, $payload['delivery_id'] );
	$cleanup_options[] = $call( 'get_recipient_delivery_option_name', $campaign_id, $payload['delivery_id'], $recipient['email'] );
	$hydrated = $call( 'hydrate_email_action_args', $args );
	$assert( 'large_payload_hydrates_correctly', ! is_wp_error( $hydrated ) && $payload['message'] === $hydrated['message'] );
	$assert( 'scheduler_payload_remains_compact', strlen( wp_json_encode( [ $args ] ) ) < 2000 && ! isset( $args['message'] ) && ! is_wp_error( $call( 'validate_email_action_args_size', $args ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert stored database state directly in this disposable CLI fixture.
	$assert( 'shared_content_is_not_autoloaded', in_array( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $cleanup_options[0] ) ), [ 'off', 'no', 'auto-off' ], true ) );
	$tampered = $args;
	$tampered['payload_hash'] = str_repeat( '0', 64 );
	$assert( 'tampered_payload_rejected', is_wp_error( $call( 'hydrate_email_action_args', $tampered ) ) );
	$call( 'record_campaign_snapshot', $payload, 'dispatching', [ 'recipient_count' => 1 ] );
	$prepared = $call( 'prepare_campaign_recipients_for_dispatch', $payload, [ $recipient, $recipient ] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert stored database state directly in this disposable CLI fixture.
	$assert( 'recipient_snapshot_deduplicated', ! is_wp_error( $prepared ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE campaign_id = %s", $table, $campaign_id ) ) );
	$notify->dispatch_campaign_batch( $campaign_id );
	$job = end( $queued );
	$assert( 'batch_dispatch_queues_compact_job', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL === $job['hook'] && false === $job['unique'] && ! isset( $job['args'][0]['message'] ) );
	$captured_mail = [];
	$notify->process_single_email( $args );
	$notify->process_single_email( $args );
	$assert( 'duplicate_delivery_blocked', 1 === count( $captured_mail ) );
	$assert( 'email_personalized_and_unsubscribe_included', false !== strpos( $captured_mail[0]['message'], 'Fixture Recipient' ) && false !== strpos( $captured_mail[0]['message'], 'yoohw_an_unsubscribe' ) );
	$call( 'track_recipient_status', $campaign_id, $recipient['email'], 'scheduled', 0 );
	$status = $call( 'reconcile_campaign_status', $campaign_id );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert stored database state directly in this disposable CLI fixture.
	$assert( 'successful_delivery_status_does_not_regress', 'sent' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE campaign_id = %s", $table, $campaign_id ) ) );
	$assert( 'campaign_reconciles_to_completed', 'completed' === $status['status'] && 0 === (int) $status['unresolved_count'] );
	$call( 'add_unsubscribed_email', $recipient['email'], $campaign_id );
	$token = $call( 'get_unsubscribe_token', $recipient['email'] );
	$assert( 'unsubscribe_token_requires_matching_email', $call( 'is_valid_unsubscribe_token', $recipient['email'], $token ) && ! $call( 'is_valid_unsubscribe_token', 'other@example.com', $token ) );
	$before_mail = count( $captured_mail );
	$notify->process_single_email( $args );
	$assert( 'unsubscribe_rechecked_before_delivery', count( $captured_mail ) === $before_mail );
	$assert( 'unsubscribed_recipient_excluded', [] === $call( 'apply_recipient_exclusions', [ $recipient ], [] ) );
	$assert( 'manual_source_honors_unsubscribe', [] === $call( 'get_recipients_by_payload', $payload ) );
	$assert( 'new_sources_honor_manual_exclusions', [] === $call( 'get_recipients_by_payload', array_merge( $manual_payload, [ 'exclusions' => [ 'emails' => $manual_payload['manual_emails'] ] ] ) ) );
	$opted_out = $args;
	$opted_out['to'] = 'opted-out-' . $tag . '@example.com';
	$call( 'add_unsubscribed_email', $opted_out['to'], $campaign_id );
	$notify->process_single_email( $opted_out );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert stored database state directly in this disposable CLI fixture.
	$assert( 'unsent_opted_out_recipient_is_skipped', count( $captured_mail ) === $before_mail && 'skipped_unsubscribed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE campaign_id = %s AND email_hash = %s", $table, $campaign_id, hash( 'sha256', $opted_out['to'] ) ) ) );
	$retry_args = $args;
	$retry_args['to'] = 'retry-' . $tag . '@example.com';
	$retry_key = $call( 'get_recipient_delivery_option_name', $campaign_id, $payload['delivery_id'], $retry_args['to'] );
	$cleanup_options[] = $retry_key;
	$fail_mail = static function () { return false; };
	add_filter( 'pre_wp_mail', $fail_mail, 20 );
	$notify->process_single_email( $retry_args );
	$retry_row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM %i WHERE campaign_id = %s AND email_hash = %s", $table, $campaign_id, hash( 'sha256', $retry_args['to'] ) ), ARRAY_A );
	$assert( 'unconfirmed_transport_result_is_unknown', 'unknown' === $retry_row['status'] );
	remove_filter( 'pre_wp_mail', $fail_mail, 20 );
	$notify->process_single_email( $retry_args );
	$assert( 'unconfirmed_transport_result_is_not_resent', 'unknown' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE campaign_id = %s AND email_hash = %s", $table, $campaign_id, hash( 'sha256', $retry_args['to'] ) ) ) );
	update_option( 'yoohw_an_suppress_automated_replies', 'yes' );
	update_option( 'yoohw_an_no_reply_email', 'no-reply@example.com' );
	$headers = $call( 'build_email_headers', 'support@example.com' );
	$assert( 'automatic_reply_suppression_headers', in_array( 'Auto-Submitted: auto-generated', $headers, true ) && in_array( 'X-Auto-Response-Suppress: OOF, AutoReply', $headers, true ) && in_array( 'Reply-To: no-reply@example.com', $headers, true ) );
	$assert( 'delivery_failures_not_suppressed', ! in_array( 'X-Auto-Response-Suppress: All', $headers, true ) );
	update_option( 'yoohw_an_suppress_automated_replies', 'no' );
	$assert( 'normal_reply_to_supported', in_array( 'Reply-To: support@example.com', $call( 'build_email_headers', 'support@example.com' ), true ) );
	$scheduled = $payload;
	$scheduled['schedule_mode'] = 'scheduled';
	$scheduled['scheduled_at_input'] = gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) . 'T10:00';
	$scheduled['scheduled_at_timestamp'] = strtotime( $scheduled['scheduled_at_input'] . ' UTC' );
	$vn = $call( 'get_recipient_scheduled_delivery', $scheduled, [ 'country' => 'VN' ] );
	$unknown = $call( 'get_recipient_scheduled_delivery', $scheduled, [] );
	$assert( 'recipient_timezone_and_utc_fallback', 'Asia/Ho_Chi_Minh' === $vn['timezone'] && 'UTC' === $unknown['timezone'] && 7 * HOUR_IN_SECONDS === $unknown['timestamp'] - $vn['timestamp'] );
	$call( 'schedule_email_action', $args, time() + DAY_IN_SECONDS );
	$job = end( $queued );
	$assert( 'scheduled_jobs_allow_many_recipients', false === $job['unique'] && isset( $job['timestamp'] ) );
	wp_set_current_user( 0 );
	ob_start();
	$notify->render_page();
	$assert( 'admin_page_requires_capability', '' === ob_get_clean() );
	wp_set_current_user( 1 );
	$preview_token = $call( 'store_preview_payload', $payload );
	$assert( 'preview_payload_can_be_restored', ! is_wp_error( $call( 'get_preview_payload', $preview_token ) ) );
	wp_set_current_user( $subscriber_id );
	$_GET['preview_token'] = $preview_token;
	ob_start();
	$notify->render_page();
	$assert( 'customer_cannot_view_campaign_preview', '' === ob_get_clean() );
	wp_set_current_user( 1 );
	$_GET = [];
	ob_start();
	$notify->render_page();
	$html = ob_get_clean();
	$assert( 'campaign_form_omits_removed_features', false !== strpos( $html, 'Campaigns &amp; Logs' ) && false !== strpos( $html, 'Subscription status' ) && false === stripos( $html, 'audience-sync' ) );
	foreach ( [ 'campaigns', 'settings' ] as $tab ) {
		$_GET['tab'] = $tab;
		ob_start();
		$notify->render_page();
		$assert( $tab . '_tab_renders', strlen( ob_get_clean() ) > 500 );
	}
} catch ( Throwable $error ) {
	$checks['unexpected_exception'] = false;
	WP_CLI::warning( $error->getMessage() );
} finally {
	remove_filter( 'pre_as_enqueue_async_action', $queue_filter, 10 );
	remove_filter( 'pre_as_schedule_single_action', $schedule_filter, 10 );
	foreach ( $orders as $order ) { $order->delete( true ); }
	foreach ( array_reverse( $products ) as $product ) { $product->delete( true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove the synthetic recipient rows created by this CLI fixture.
	$wpdb->delete( $wpdb->prefix . 'yoohw_an_recipients', [ 'campaign_id' => $campaign_id ] );
	foreach ( [ $call( 'campaign_record_key', $campaign_id ), $call( 'campaign_manifest_key', $campaign_id ), 'yoohw_an_optout_' . hash( 'sha256', $recipient['email'] ?? '' ), 'yoohw_an_optout_' . hash( 'sha256', $opted_out['to'] ?? '' ) ] as $name ) { $cleanup_options[] = $name; }
	foreach ( $cleanup_options as $name ) { delete_option( $name ); }
	foreach ( $original_options as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value ); }
	$call( 'delete_preview_payload', $preview_token );
	wp_clear_scheduled_hook( YOOHW_AN_Customer_Email_Notify::ACTION_RECONCILE_CAMPAIGNS, [ $campaign_id ] );
	$_POST = $original_post;
	$_GET = $original_get;
	wp_set_current_user( $original_user );
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
}
echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'checks' => $checks ], JSON_PRETTY_PRINT ) . PHP_EOL;
if ( in_array( false, $checks, true ) ) { WP_CLI::error( 'Integration checks failed.' ); }

})();
