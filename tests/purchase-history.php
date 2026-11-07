<?php
/** Store-wide purchase history on a disposable reliability database; no real mail. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$notify = new YOOHW_AN_Customer_Email_Notify(); $reflection = new ReflectionClass( $notify );
	$call = static function ( $name, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $name )->invokeArgs( $notify, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$tag = 'purchase-' . strtolower( wp_generate_password( 10, false ) );
	$products = $orders = $refunds = $users = $terms = $options = $drafts = $campaigns = $tokens = [];
	$original_user = get_current_user_id(); $original_get = $_GET; $original_post = $_POST;
	$transport = static function () { return true; }; add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
	$emails = [];
	$address = static function ( $suffix ) use ( $tag, &$emails ) { $email = $tag . '-' . $suffix . '@example.com'; $emails[ $suffix ] = $email; return $email; };
	$make = static function ( $email, $days, $product, $status = 'completed', $user_id = 0, $country = 'VN', $total = 20 ) use ( &$orders ) {
		$order = wc_create_order(); $orders[] = $order; $order->set_billing_email( $email ); $order->set_customer_id( $user_id ); $order->set_billing_country( $country );
		if ( $product ) { $order->add_product( $product, 1, [ 'subtotal' => $total, 'total' => $total ] ); }
		$order->set_total( $total ); $order->set_date_created( time() - $days * DAY_IN_SECONDS ); $order->set_status( $status ); $order->save(); return $order;
	};
	$matches = static function ( $source_emails, $filters ) use ( $call ) { return array_column( $call( 'get_recipients_by_payload', [ 'recipient_source' => 'manual_emails', 'manual_emails' => $source_emails, 'filters' => $filters ] ), 'email' ); };
	try {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); if ( ! $admins ) { throw new RuntimeException( 'A disposable administrator is required.' ); } wp_set_current_user( $admins[0] );
		foreach ( [ 'A', 'B' ] as $name ) { $p = new WC_Product_Simple(); $p->set_name( $tag . '-' . $name ); $p->set_regular_price( '20' ); $p->save(); $products[] = $p; }
		$term = wp_insert_term( $tag, 'product_cat' ); if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); } $terms[] = $term['term_id']; wp_set_object_terms( $products[1]->get_id(), [ $term['term_id'] ], 'product_cat' );
		$first = $address( 'first' ); $first_order = $make( $first, 180, $products[0] );
		$repeat = $address( 'repeat' ); $make( $repeat, 365, $products[0], 'completed', 0, 'US' ); $make( $repeat, 1, $products[1] );
		$inactive = $address( 'inactive' ); $make( $inactive, 300, $products[0] ); $make( $inactive, 120, $products[1] );
		$never = $address( 'never' );
		$cancelled = $address( 'cancelled' ); $cancelled_order = $make( $cancelled, 200, $products[0], 'cancelled' );
		$failed = $address( 'failed' ); $failed_order = $make( $failed, 200, $products[0], 'failed' );
		$refunded = $address( 'refunded' ); $refunded_order = $make( $refunded, 200, $products[0], 'refunded' );
		$full = $address( 'full' ); $full_order = $make( $full, 200, $products[0] ); $refund = wc_create_refund( [ 'order_id' => $full_order->get_id(), 'amount' => 20, 'reason' => 'Fixture full refund', 'refund_payment' => false ] ); if ( is_wp_error( $refund ) ) { throw new RuntimeException( $refund->get_error_message() ); } $refunds[] = $refund;
		$full_order = wc_get_order( $full_order->get_id() ); $full_order->set_status( 'completed' ); $full_order->save();
		$partial = $address( 'partial' ); $partial_order = $make( $partial, 200, $products[0] ); $refund = wc_create_refund( [ 'order_id' => $partial_order->get_id(), 'amount' => 5, 'reason' => 'Fixture partial refund', 'refund_payment' => false ] ); if ( is_wp_error( $refund ) ) { throw new RuntimeException( $refund->get_error_message() ); } $refunds[] = $refund; $partial_order = wc_get_order( $partial_order->get_id() );
		$free = $address( 'free' ); $free_order = $make( $free, 200, null, 'completed', 0, 'VN', 0 );
		$shared = $address( 'shared' ); $user_id = wp_create_user( $tag, wp_generate_password(), $shared ); if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); } $users[] = $user_id; ( new WP_User( $user_id ) )->set_role( 'customer' );
		$make( strtoupper( $shared ), 365, $products[0] ); $make( $shared, 1, $products[1], 'processing', $user_id );
		$different = $address( 'different' ); $make( $different, 200, $products[0], 'completed', $user_id );
		$all = array_values( $emails );
		$stats = $call( 'purchase_history_for_emails', $all );
		$assert( 'old_and_new_orders_count_across_full_history', 2 === $stats[ $repeat ]['count'] && 2 === $stats[ $inactive ]['count'] );
		$assert( 'first_and_repeat_filters_select_exact_counts', in_array( $first, $matches( $all, [ 'purchase_frequency' => 'first' ] ), true ) && ! in_array( $repeat, $matches( $all, [ 'purchase_frequency' => 'first' ] ), true ) && in_array( $repeat, $matches( $all, [ 'purchase_frequency' => 'repeat' ] ), true ) );
		$assert( 'recent_purchase_excludes_old_customer_from_inactivity', ! in_array( $repeat, $matches( $all, [ 'inactive_days' => '90' ] ), true ) && in_array( $inactive, $matches( $all, [ 'inactive_days' => '90' ] ), true ) );
		$assert( 'never_buyers_are_not_inactive_or_first_time_buyers', [] === $matches( [ $never ], [ 'inactive_days' => '90' ] ) && [] === $matches( [ $never ], [ 'purchase_frequency' => 'first' ] ) && [] === $matches( [ $never ], [ 'purchase_frequency' => 'repeat' ] ) );
		$assert( 'no_lifecycle_criteria_keeps_never_buyer_in_source', [ $never ] === $matches( [ $never ], [] ) );
		$assert( 'cancelled_failed_and_refunded_statuses_do_not_count', ! $call( 'is_valid_purchase_order', $cancelled_order ) && ! $call( 'is_valid_purchase_order', $failed_order ) && ! $call( 'is_valid_purchase_order', $refunded_order ) );
		$assert( 'full_refund_is_excluded_even_if_status_is_completed', ! $call( 'is_valid_purchase_order', $full_order ) && 0 === $stats[ $full ]['count'] && [] === $matches( [ $full ], [ 'inactive_days' => '90' ] ) );
		$assert( 'partial_refund_remains_one_valid_purchase', $call( 'is_valid_purchase_order', $partial_order ) && 1 === $stats[ $partial ]['count'] && [ $partial ] === $matches( [ $partial ], [ 'purchase_frequency' => 'first', 'inactive_days' => '90' ] ) );
		$assert( 'free_completed_order_without_refund_counts', $call( 'is_valid_purchase_order', $free_order ) && 1 === $stats[ $free ]['count'] );
		$assert( 'guest_and_account_orders_share_normalized_billing_email', 2 === $stats[ $shared ]['count'] && 1 === $stats[ $different ]['count'] );
		$registered = $call( 'get_recipients_by_payload', [ 'recipient_source' => 'registered_users', 'user_roles' => [ 'customer' ], 'filters' => [ 'purchase_frequency' => 'repeat' ] ] );
		$assert( 'registered_source_uses_same_billing_email_history', in_array( $shared, array_column( $registered, 'email' ), true ) );
		$base = [ 'recipient_source' => 'product_customers', 'product_ids' => [ $products[1]->get_id() ], 'filters' => [ 'last_days' => '7', 'country' => 'VN', 'category_ids' => [ (string) $term['term_id'] ], 'purchase_frequency' => 'repeat' ] ];
		$recent = $call( 'get_recipients_by_payload', $base );
		$assert( 'campaign_dates_and_products_do_not_shrink_purchase_history', in_array( $repeat, array_column( $recent, 'email' ), true ) && in_array( $shared, array_column( $recent, 'email' ), true ) );
		$mismatch = $base; $mismatch['product_ids'] = [ $products[0]->get_id() ];
		$assert( 'same_order_product_country_date_and_category_semantics_are_preserved', ! in_array( $repeat, array_column( $call( 'get_recipients_by_payload', $mismatch ), 'email' ), true ) );
		$assert( 'frequency_and_inactivity_conditions_intersect', [ $inactive ] === $matches( [ $repeat, $inactive ], [ 'purchase_frequency' => 'repeat', 'inactive_days' => '90' ] ) );
		$sub_key = 'yoohw_an_subscription_' . hash( 'sha256', $first ); $options[] = $sub_key; $call( 'record_subscription', $first, 'subscribed', 'Purchase history fixture' );
		$subscribers = $call( 'get_recipients_by_payload', [ 'recipient_source' => YOOHW_AN_Customer_Email_Notify::SOURCE_SUBSCRIBED, 'filters' => [ 'purchase_frequency' => 'first', 'inactive_days' => '90' ] ] );
		$assert( 'subscriber_source_can_use_store_wide_lifecycle_conditions', in_array( $first, array_column( $subscribers, 'email' ), true ) );
		foreach ( [ '0', '-1', '36501', '1.5', true, [ '1' ] ] as $index => $invalid ) { $assert( 'invalid_inactivity_' . $index . '_is_rejected', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'manual_emails', 'filters' => [ 'inactive_days' => $invalid ] ] ) ) ); }
		$assert( 'invalid_frequency_is_rejected', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'manual_emails', 'filters' => [ 'purchase_frequency' => 'all_buyers' ] ] ) ) );
		$_POST = [ 'audience_filters' => [ 'purchase_frequency' => [ 'first' ] ] ]; $assert( 'request_rejects_malformed_lifecycle_scalar', ! empty( $call( 'filters_from_request' )['invalid_input'] ) );
		$_POST = [ 'audience_filters' => [ 'purchase_frequency' => 'repeat', 'inactive_days' => '90' ] ]; $parsed = $call( 'filters_from_request' );
		$assert( 'request_preserves_both_lifecycle_fields', 'repeat' === $parsed['purchase_frequency'] && '90' === $parsed['inactive_days'] ); $_POST = $original_post;
		$p = [ 'recipient_source' => 'manual_emails', 'manual_emails' => [ $first ], 'manual_emails_raw' => $first, 'filters' => [ 'purchase_frequency' => 'first', 'inactive_days' => '90' ], 'subject' => 'Purchase history fixture', 'heading' => 'Hello', 'message' => '<p>Hello {customer_name}</p>', 'products_label' => 'Lifecycle fixture', 'from_email' => 'store@example.com', 'from_name' => 'Store', 'reply_to_email' => 'reply@example.com', 'schedule_mode' => 'now', 'internal_name' => $tag ];
		$draft = $call( 'save_draft', $p ); $drafts[] = $draft['draft_id']; $_GET = [ 'draft_id' => $draft['draft_id'] ];
		$assert( 'draft_reopen_preserves_lifecycle_criteria', $call( 'restore_authoring_payload', [] )['filters'] === $p['filters'] );
		$audience_id = $tag . '-audience'; $audience_key = 'yoohw_an_audience_' . $audience_id; $options[] = $audience_key;
		$criteria = array_intersect_key( $p, array_flip( $call( 'criteria_fields' ) ) ); $call( 'write_personal_option', $audience_key, [ 'id' => $audience_id, 'name' => 'Lifecycle audience', 'criteria' => $criteria ] ); $_GET = [ 'audience_id' => $audience_id ];
		$restored = $call( 'restore_authoring_payload', [] ); $assert( 'saved_audience_preserves_lifecycle_criteria', $restored['filters'] === $p['filters'] && [ $first ] === array_column( $call( 'get_recipients_by_payload', $restored ), 'email' ) );
		$id = $tag . '-frozen'; $campaigns[] = $id; $p = array_merge( $p, [ 'campaign_id' => $id, 'delivery_id' => $id . '-delivery', 'campaign_hash' => hash( 'sha256', $id ) ] );
		$recipients = $call( 'get_recipients_by_payload', $p ); $p['audience_fingerprint'] = $call( 'audience_fingerprint', $recipients ); $call( 'confirm_campaign', $p, $recipients );
		$manifest = $call( 'read_durable_option', $call( 'campaign_manifest_key', $id ) );
		$make( $first, 1, $products[1] );
		$assert( 'saved_audience_reuse_evaluates_current_history', [] === $call( 'get_recipients_by_payload', $restored ) );
		$assert( 'confirmed_manifest_never_rebuilds_after_later_purchase', $call( 'read_durable_option', $call( 'campaign_manifest_key', $id ) )['recipients'] === $manifest['recipients'] && $manifest['payload']['filters'] === $p['filters'] );
		$token = $call( 'refresh_changed_preview', $p, [] ); if ( $token ) { $tokens[] = $token; }
		$assert( 'changed_purchase_history_requires_fresh_preview', '' !== $token );
		ob_start(); $call( 'render_purchase_history_fields', $p['filters'], false ); $html = ob_get_clean();
		$assert( 'ui_explains_whole_store_dates_refunds_and_identity', str_contains( $html, 'entire store' ) && str_contains( $html, 'outside the dates and products' ) && str_contains( $html, 'Partial refunds' ) && str_contains( $html, 'Different emails are not merged' ) );
	} finally {
		$_POST = $original_post; $_GET = $original_get; wp_set_current_user( $original_user ); remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
		foreach ( $tokens as $token ) { $call( 'delete_preview_payload', $token ); }
		foreach ( $drafts as $id ) { delete_option( 'yoohw_an_draft_' . $id ); }
		foreach ( $campaigns as $id ) {
			foreach ( [ 'campaign_record_key', 'campaign_manifest_key', 'control_key' ] as $method ) { delete_option( $call( $method, $id ) ); }
			delete_option( $call( 'get_campaign_payload_option_name', $id, $id . '-delivery' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE campaign_id = %s', $wpdb->prefix . 'yoohw_an_recipients', $id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s,%s) AND (args LIKE %s OR extended_args LIKE %s)', $wpdb->prefix . 'actionscheduler_actions', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN, '%' . $wpdb->esc_like( $id ) . '%', '%' . $wpdb->esc_like( $id ) . '%' ) );
		}
		foreach ( $options as $option ) { delete_option( $option ); }
		foreach ( $refunds as $refund ) { $refund->delete( true ); }
		foreach ( $orders as $order ) { $order->delete( true ); }
		foreach ( array_reverse( $products ) as $product ) { $product->delete( true ); }
		foreach ( $terms as $term ) { wp_delete_term( $term, 'product_cat' ); }
		require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $user ) { wp_delete_user( $user ); }
	}
	echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts', 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
