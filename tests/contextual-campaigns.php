<?php
/** Run in an isolated reliability database. All mail handoffs are intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$notify = new YOOHW_AN_Customer_Email_Notify();
	$reflection = new ReflectionClass( $notify );
	$call = static function ( $method, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $method )->invokeArgs( $notify, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$tag = 'context-' . strtolower( wp_generate_password( 8, false ) );
	$products = $orders = $users = $drafts = $campaigns = $options = $tokens = $mail = [];
	$table = $wpdb->prefix . 'yoohw_an_recipients';
	$old_limits = $call( 'read_durable_option', 'yoohw_an_limits' );
	$old_rate = $call( 'read_durable_option', 'yoohw_an_rate' );
	$original_get = $_GET; $original_post = $_POST; $original_user = get_current_user_id();
	$transport = static function ( $pre, $attributes ) use ( &$mail ) { $mail[] = $attributes; return true; };
	add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX, 2 );
	try {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! $admins ) { throw new RuntimeException( 'A disposable administrator is required.' ); }
		wp_set_current_user( $admins[0] );
		$call( 'write_durable_option', 'yoohw_an_limits', [] ); $call( 'write_durable_option', 'yoohw_an_rate', [] );
		$notify->maybe_install_recipient_tracking_table();
		$product = new WC_Product_Simple(); $product->set_name( 'Private purchase ' . $tag ); $product->set_regular_price( '10' ); $product->save(); $products[] = $product;
		$variable = new WC_Product_Variable(); $variable->set_name( 'Variable ' . $tag ); $variable->save(); $products[] = $variable;
		$variation = new WC_Product_Variation(); $variation->set_parent_id( $variable->get_id() ); $variation->set_regular_price( '12' ); $variation->save(); $products[] = $variation;
		$sibling = new WC_Product_Variation(); $sibling->set_parent_id( $variable->get_id() ); $sibling->set_regular_price( '13' ); $sibling->save(); $products[] = $sibling;
		$email = $tag . '-guest@example.com'; $account_email = $tag . '-account@example.com';
		$user = wp_create_user( $tag, wp_generate_password(), $account_email );
		if ( is_wp_error( $user ) ) { throw new RuntimeException( $user->get_error_message() ); } $users[] = $user;
		$make_order = static function ( $address, $item, $name, $customer = 0 ) use ( &$orders ) {
			$order = wc_create_order(); $orders[] = $order;
			$order->set_billing_email( $address ); $order->set_billing_first_name( $name ); $order->set_customer_id( $customer ); $order->set_billing_country( 'VN' );
			$order->add_product( $item ); $order->set_status( 'completed' ); $order->save(); return $order;
		};
		$first = $make_order( $email, $product, 'Earlier Guest' );
		$second = $make_order( strtoupper( $email ), $product, 'Selected Guest' );
		$outside = $make_order( $email, $sibling, 'Outside selection' );
		$other = $make_order( $tag . '-other@example.com', $product, 'Other customer' );
		$registered = $make_order( $account_email, $variation, 'Registered Buyer', $user );
		$mismatched = $make_order( $tag . '-different@example.com', $product, 'Different billing', $user );
		$mail = []; // Exclude WooCommerce fixture order-status notifications from campaign assertions.
		$source = YOOHW_AN_Customer_Email_Notify::SOURCE_SELECTED_ORDERS;
		$criteria = [ 'recipient_source' => $source, 'order_ids' => [ $first->get_id(), $second->get_id(), $registered->get_id() ], 'product_ids' => [], 'user_roles' => [], 'filters' => [] ];
		$entry_draft = $call( 'contextual_orders_draft', [ $second->get_id(), $first->get_id(), $second->get_id() ] ); $drafts[] = $entry_draft['draft_id'];
		$assert( 'order_entrypoint_opens_exact_draft_without_delivery', $entry_draft['order_ids'] === [ $first->get_id(), $second->get_id() ] && $entry_draft['recipient_source'] === $source && [] === $mail && ! $call( 'read_durable_option', $call( 'campaign_manifest_key', $entry_draft['draft_id'] ) ) );
		$row_actions = $notify->contextual_product_row_action( [], get_post( $product->get_id() ) );
		$assert( 'product_entrypoint_has_nonce_and_exact_product', isset( $row_actions['yoohw_an_draft'] ) && str_contains( $row_actions['yoohw_an_draft'], '_wpnonce=' ) && str_contains( $row_actions['yoohw_an_draft'], 'object_id=' . $product->get_id() ) );
		wp_set_current_user( $user );
		$assert( 'unprivileged_customer_has_no_product_or_order_entrypoint', [] === $notify->contextual_product_row_action( [], get_post( $product->get_id() ) ) && [] === $notify->contextual_order_bulk_action( [] ) && is_wp_error( $call( 'validate_selected_orders', $criteria ) ) );
		wp_set_current_user( $admins[0] );
		$_POST = [ 'recipient_source' => $source, 'order_ids' => implode( ', ', $criteria['order_ids'] ), 'email_subject' => 'Selected {order_number}', 'email_heading' => 'Hello {customer_name}', 'email_message' => '<p>{ordered_products}</p>', 'schedule_mode' => 'now' ];
		$request_payload = $call( 'get_payload_from_request' );
		$assert( 'selected_order_request_builds_valid_preview_payload', ! is_wp_error( $request_payload ) && $request_payload['order_ids'] === $criteria['order_ids'] && (int) $request_payload['personalization_version'] === 1 );
		$drafts[] = $request_payload['draft_id']; $_POST = $original_post;
		$selected = $call( 'get_recipients_by_payload', $criteria );
		$by_email = array_column( $selected, null, 'email' );
		$assert( 'selected_orders_deduplicate_guest_and_account', count( $selected ) === 2 && isset( $by_email[ $email ], $by_email[ $account_email ] ) );
		$assert( 'selected_orders_do_not_expand_to_other_product_buyers', ! isset( $by_email[ $other->get_billing_email() ] ) );
		$guest = $by_email[ $email ]; $account = $by_email[ $account_email ];
		$assert( 'context_uses_highest_matching_order_not_newer_outside_order', $guest['order_context']['number'] === (string) $second->get_order_number() && $guest['name'] === 'Selected Guest' );
		$assert( 'context_reports_matching_order_count', (int) $guest['order_context']['count'] === 2 && (int) $account['order_context']['count'] === 1 );
		$assert( 'context_products_come_from_selected_order', str_contains( $guest['order_context']['products'], $product->get_name() ) && ! str_contains( $guest['order_context']['products'], 'Variable ' . $tag ) );
		$assert( 'guest_has_no_order_access_link', empty( $guest['order_context']['url'] ) );
		$assert( 'registered_order_link_contains_no_order_key', ! empty( $account['order_context']['url'] ) && ! str_contains( $account['order_context']['url'], 'key=' ) && ! str_contains( $account['order_context']['url'], $registered->get_order_key() ) );
		$mismatch = $call( 'get_recipients_by_payload', array_merge( $criteria, [ 'order_ids' => [ $mismatched->get_id() ] ] ) );
		$assert( 'mismatched_account_email_has_no_access_link', count( $mismatch ) === 1 && empty( $mismatch[0]['order_context']['url'] ) );
		$assert( 'empty_selection_fails_validation_and_never_expands', is_wp_error( $call( 'validate_recipient_source_options', array_merge( $criteria, [ 'order_ids' => [] ] ) ) ) && [] === $call( 'get_recipients_by_payload', array_merge( $criteria, [ 'order_ids' => [] ] ) ) );
		foreach ( [ '-1', '1abc', [ [ '1' ] ], '1 OR 1=1' ] as $index => $invalid ) { $assert( 'invalid_order_selection_' . $index, is_wp_error( $call( 'parse_selected_order_ids', $invalid ) ) ); }
		$assert( 'selected_ids_are_normalized_without_duplicates', count( $call( 'parse_selected_order_ids', [ (string) $first->get_id(), (string) $first->get_id() ] ) ) === 1 );
		$assert( 'unknown_order_selection_fails_closed', [] === $call( 'get_recipients_by_payload', array_merge( $criteria, [ 'order_ids' => [ PHP_INT_MAX ] ] ) ) );
		$scoped = array_merge( $criteria, [ 'filters' => [ 'country' => 'US' ] ] );
		$assert( 'selected_orders_keep_same_order_filters', [] === $call( 'get_recipients_by_payload', $scoped ) );
		$parent = $call( 'get_recipients_by_payload', [ 'recipient_source' => 'product_customers', 'product_ids' => [ $variable->get_id() ], 'filters' => [] ] );
		$child = $call( 'get_recipients_by_payload', [ 'recipient_source' => 'product_customers', 'product_ids' => [ $variation->get_id() ], 'filters' => [] ] );
		$assert( 'parent_includes_all_variations', in_array( $email, array_column( $parent, 'email' ), true ) && in_array( $account_email, array_column( $parent, 'email' ), true ) );
		$assert( 'variation_excludes_sibling_purchases', ! in_array( $email, array_column( $child, 'email' ), true ) && in_array( $account_email, array_column( $child, 'email' ), true ) );

		$id = $tag . '-campaign'; $campaigns[] = $id;
		$p = array_merge( $criteria, [ 'campaign_id' => $id, 'delivery_id' => $id . '-delivery', 'campaign_hash' => hash( 'sha256', $id ), 'personalization_version' => 1, 'store_name' => $request_payload['store_name'], 'subject' => 'Order {order_number} for {customer_name}', 'heading' => '{store_name}: {customer_name}', 'message' => '<p>Hello {customer_name}: {ordered_products}; {order_url}; {product_name}</p>', 'products_label' => 'Shared campaign label', 'from_email' => 'store@example.com', 'from_name' => 'Store', 'reply_to_email' => 'reply@example.com', 'schedule_mode' => 'now', 'internal_name' => $tag ] );
		$rendered = $call( 'render_personalized_email', $p, $guest );
		$assert( 'subject_heading_and_body_use_same_recipient', str_contains( $rendered['subject'], $second->get_order_number() ) && str_contains( $rendered['subject'], 'Selected Guest' ) && str_contains( $rendered['heading'], 'Selected Guest' ) && str_contains( $rendered['message'], $product->get_name() ) );
		$assert( 'shared_product_name_keeps_original_meaning', str_contains( $rendered['message'], 'Shared campaign label' ) );
		$missing = $call( 'render_personalized_email', array_merge( $p, [ 'subject' => '{customer_name}|{order_number}|{order_url}', 'message' => '{store_name}|{ordered_products}' ] ), [ 'email' => $tag . '-manual@example.com', 'name' => '' ] );
		$assert( 'missing_order_data_uses_explicit_fallback_without_unexpanded_tokens', ! preg_match( '/\{(?:customer_name|store_name|order_number|ordered_products|order_url)\}/', wp_json_encode( $missing ) ) );
		$hostile = $call( 'render_personalized_email', $p, array_merge( $guest, [ 'name' => '<img src=x onerror=alert(1)>' ] ) );
		$assert( 'recipient_content_cannot_inject_html', ! str_contains( $hostile['message'], '<img src=x' ) );
		$legacy = $p; unset( $legacy['personalization_version'] );
		$legacy_render = $call( 'render_personalized_email', $legacy, $guest );
		$assert( 'legacy_subject_and_heading_remain_literal', $legacy_render['subject'] === $legacy['subject'] && $legacy_render['heading'] === $legacy['heading'] );
		$draft = $call( 'save_draft', $p ); $drafts[] = $draft['draft_id'];
		$assert( 'draft_preserves_selected_orders_and_personalization_version', $draft['order_ids'] === $criteria['order_ids'] && (int) $draft['personalization_version'] === 1 );
		$_GET = [ 'draft_id' => $draft['draft_id'] ];
		$restored = $call( 'restore_authoring_payload', [] );
		$assert( 'reopened_draft_keeps_exact_selection', $restored['order_ids'] === $criteria['order_ids'] );
		$_GET = $original_get;
		$call( 'confirm_campaign', $p, $selected );
		$manifest_key = $call( 'campaign_manifest_key', $id );
		$manifest = $call( 'read_durable_option', $manifest_key );
		$assert( 'confirmation_preserves_recipient_context', $manifest['recipients'][0]['order_context'] === $selected[0]['order_context'] );
		$second->set_billing_first_name( 'Changed after confirmation' ); $second->save();
		$assert( 'confirmed_context_is_frozen', $call( 'read_durable_option', $manifest_key )['recipients'] === $manifest['recipients'] );
		$duplicate = $call( 'save_draft', $call( 'get_campaign_history' )[ $id ], wp_generate_uuid4() ); $drafts[] = $duplicate['draft_id'];
		$assert( 'duplicate_preserves_criteria_without_runtime_identity', $duplicate['order_ids'] === $criteria['order_ids'] && ! isset( $duplicate['campaign_id'], $duplicate['delivery_id'] ) );
		$notify->dispatch_campaign_batch( $id );
		$reference = $call( 'store_campaign_email_payload', $p );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT action_id FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $id, hash( 'sha256', $email ) ), ARRAY_A );
		$action = ! empty( $row['action_id'] ) ? ActionScheduler::store()->fetch_action( (int) $row['action_id'] ) : null;
		$args = $action ? ( $action->get_args()[0] ?? [] ) : [];
		$assert( 'dispatcher_enqueues_compact_contextual_action', ! isset( $args['order_context'] ) && strlen( wp_json_encode( $args ) ) < 7000 && $args['to'] === $email );
		$hydrated = $call( 'hydrate_email_action_args', $args );
		$assert( 'queued_payload_preserves_version_and_context', (int) $hydrated['personalization_version'] === 1 && $hydrated['order_context'] === $guest['order_context'] );
		$shop_change = static function () { return 'Changed shop name after confirmation'; };
		add_filter( 'option_blogname', $shop_change );
		try { $frozen_render = $call( 'render_personalized_email', $hydrated, array_merge( $guest, [ 'order_context' => $hydrated['order_context'] ] ) ); }
		finally { remove_filter( 'option_blogname', $shop_change ); }
		$assert( 'queued_shop_name_is_frozen_at_authoring', $hydrated['store_name'] === $request_payload['store_name'] && $frozen_render['heading'] === $rendered['heading'] );
		$assert( 'missing_manifest_recipient_fails_before_handoff', is_wp_error( $call( 'hydrate_email_action_args', array_merge( $args, [ 'to' => $tag . '-not-confirmed@example.com' ] ) ) ) );
		$action->execute();
		$notify->process_single_email( $args );
		$assert( 'actual_handoff_matches_preview_and_deduplicates', count( $mail ) === 1 && $mail[0]['subject'] === $rendered['subject'] && str_contains( $mail[0]['message'], $product->get_name() ) && str_contains( $mail[0]['message'], 'Shared campaign label' ) );
		$blocked = $account_email; $hash = hash( 'sha256', $blocked ); $options[] = 'yoohw_an_optout_' . $hash;
		$call( 'add_unsubscribed_email', $blocked, $id );
		$notify->process_single_email( $call( 'build_email_action_args', $p, $account, $reference ) );
		$assert( 'unsubscribe_after_preview_blocks_contextual_delivery', count( $mail ) === 1 );
		$export = $notify->export_personal_data( $email );
		$assert( 'privacy_export_includes_own_order_context', str_contains( wp_json_encode( $export ), $product->get_name() ) && ! str_contains( wp_json_encode( $export ), $account_email ) );
		$preview = array_merge( $p, [ 'recipient_sample' => [ $guest ], 'recipient_count' => 1 ] );
		$token = $call( 'store_preview_payload', $preview ); $tokens[] = $token;
		$options[] = 'yoohw_an_erased_' . hash( 'sha256', $email );
		$notify->erase_personal_data( $email );
		$after_manifest = $call( 'read_durable_option', $manifest_key );
		$after_preview = $call( 'get_preview_payload', $token );
		$assert( 'erasure_removes_context_from_manifest_and_preview', ! str_contains( wp_json_encode( $after_manifest['recipients'] ), $product->get_name() ) && ! str_contains( wp_json_encode( $after_preview['recipient_sample'] ?? [] ), $product->get_name() ) );
		$stale = $call( 'scrub_erased_references', [ 'to' => $email, 'order_context' => $guest['order_context'] ] );
		$assert( 'erasure_blocks_stale_context_publication', empty( $stale['order_context'] ) );
		$notify->process_single_email( $args );
		$assert( 'erasure_does_not_replay_already_handed_off_mail', count( $mail ) === 1 );

		// A large multilingual order context stays in the confirmed manifest, not scheduler JSON.
		$large_id = $tag . '-large'; $campaigns[] = $large_id;
		$large_email = $tag . '-large@example.com';
		$large_products = trim( str_repeat( '東京の購入商品 ', 1000 ) );
		$large_recipient = [ 'email' => $large_email, 'name' => 'Large Buyer', 'order_context' => [ 'number' => 'LARGE-ORDER', 'products' => $large_products, 'url' => '', 'count' => 1 ] ];
		$large_p = array_merge( $p, [ 'campaign_id' => $large_id, 'delivery_id' => $large_id . '-delivery', 'campaign_hash' => hash( 'sha256', $large_id ), 'recipient_source' => 'manual_emails', 'manual_emails' => [ $large_email ], 'order_ids' => [], 'subject' => 'Large {order_number}', 'heading' => '{store_name}', 'message' => '<p>{ordered_products}</p>' ] );
		$call( 'confirm_campaign', $large_p, [ $large_recipient ] );
		$notify->dispatch_campaign_batch( $large_id );
		$large_row = $wpdb->get_row( $wpdb->prepare( 'SELECT action_id FROM %i WHERE campaign_id = %s AND email_hash = %s', $table, $large_id, hash( 'sha256', $large_email ) ), ARRAY_A );
		$large_action = ! empty( $large_row['action_id'] ) ? ActionScheduler::store()->fetch_action( (int) $large_row['action_id'] ) : null;
		$large_args = $large_action ? ( $large_action->get_args()[0] ?? [] ) : [];
		$assert( 'large_multilingual_context_uses_compact_scheduled_args', mb_strlen( $large_products ) > 7000 && ! isset( $large_args['order_context'] ) && strlen( wp_json_encode( $large_args ) ) < 7000 && ( $large_args['to'] ?? '' ) === $large_email );
		$large_hydrated = $call( 'hydrate_email_action_args', $large_args );
		$assert( 'large_context_roundtrips_without_truncation', ! is_wp_error( $large_hydrated ) && $large_hydrated['order_context']['products'] === $large_products );
		$large_action->execute();
		$assert( 'large_context_handoff_keeps_full_products', count( $mail ) === 2 && $mail[1]['subject'] === 'Large LARGE-ORDER' && str_contains( $mail[1]['message'], $large_products ) );
	} finally {
		remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
		$_GET = $original_get; $_POST = $original_post; wp_set_current_user( $original_user );
		foreach ( $tokens as $token ) { $call( 'delete_preview_payload', $token ); }
		foreach ( $drafts as $draft ) { delete_option( 'yoohw_an_draft_' . $draft ); }
		foreach ( $campaigns as $id ) {
			foreach ( [ 'campaign_record_key', 'campaign_manifest_key', 'control_key' ] as $method ) { delete_option( $call( $method, $id ) ); }
			delete_option( $call( 'get_campaign_payload_option_name', $id, $id . '-delivery' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE campaign_id = %s', $table, $id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s,%s) AND (args LIKE %s OR extended_args LIKE %s)', $wpdb->prefix . 'actionscheduler_actions', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN, '%' . $wpdb->esc_like( $id ) . '%', '%' . $wpdb->esc_like( $id ) . '%' ) );
		}
		foreach ( $options as $option ) { delete_option( $option ); }
		foreach ( $orders as $order ) { $order->delete( true ); }
		foreach ( array_reverse( $products ) as $product ) { $product->delete( true ); }
		foreach ( $users as $user ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); }
		$call( 'write_durable_option', 'yoohw_an_limits', $old_limits ); $call( 'write_durable_option', 'yoohw_an_rate', $old_rate );
	}
	echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
