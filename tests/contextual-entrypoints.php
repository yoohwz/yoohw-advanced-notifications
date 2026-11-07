<?php
/** Run on the disposable reliability database; exercise admin draft entry points without delivery. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb, $wp_filter, $post;
	$notify = ( new ReflectionClass( YOOHW_AN_Customer_Email_Notify::class ) )->newInstanceWithoutConstructor();
	$reflection = new ReflectionClass( $notify );
	$call = static function ( $name, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $name )->invokeArgs( $notify, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$original_user = get_current_user_id(); $original_get = $_GET; $original_request = $_REQUEST; $original_post = $post ?? null;
	$tag = 'entrypoint-' . strtolower( wp_generate_password( 8, false ) );
	$users = $orders = $products = $drafts = [];
	$mail = [];
	$transport = static function ( $pre, $args ) use ( &$mail ) { $mail[] = $args; return true; };
	$die = static function () { return static function ( $message ) { throw new RuntimeException( 'entrypoint-die: ' . $message ); }; };
	$redirect = static function ( $url ) { throw new RuntimeException( 'entrypoint-redirect: ' . $url ); };
	add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX, 2 );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	add_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
	$read_drafts = static function () use ( $call ) { return $call( 'read_durable_options', 'yoohw_an_draft_' ); };
	$original_drafts = array_column( $read_drafts(), 'draft_id' );
	$expect_die = static function ( $operation ) use ( $assert ) {
		try { $operation(); } catch ( RuntimeException $error ) { if ( str_starts_with( $error->getMessage(), 'entrypoint-die:' ) ) { return; } throw $error; }
		$assert( 'request_should_have_been_refused', false );
	};
	$open = static function ( $kind, $id, $nonce = null ) use ( $notify, $call ) {
		$_GET = [ 'kind' => $kind, 'object_id' => (string) $id, '_wpnonce' => $nonce ?? wp_create_nonce( 'yoohw_an_contextual_draft_' . $kind . '_' . $id ) ]; $_REQUEST = $_GET;
		try { $notify->handle_contextual_draft(); } catch ( RuntimeException $error ) {
			if ( ! str_starts_with( $error->getMessage(), 'entrypoint-redirect:' ) ) { throw $error; }
			parse_str( wp_parse_url( substr( $error->getMessage(), strlen( 'entrypoint-redirect: ' ) ), PHP_URL_QUERY ), $args );
			if ( empty( $args['draft_id'] ) || 'yoohw-advanced-notifications' !== ( $args['page'] ?? '' ) ) { throw new RuntimeException( 'Unexpected composer redirect.' ); }
			return $call( 'read_durable_option', 'yoohw_an_draft_' . $args['draft_id'] );
		}
		throw new RuntimeException( 'Expected composer redirect.' );
	};
	try {
		$admin = wp_insert_user( [ 'user_login' => $tag . '-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] ); $users[] = $admin;
		$subscriber = wp_insert_user( [ 'user_login' => $tag . '-subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] ); $users[] = $subscriber;
		wp_set_current_user( $admin );
		$parent = new WC_Product_Variable(); $parent->set_name( $tag ); $parent->save(); $products[] = $parent;
		$variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_regular_price( '10' ); $variation->save(); $products[] = $variation;
		foreach ( [ 'one', 'two' ] as $suffix ) { $order = wc_create_order(); $order->set_billing_email( $tag . '-' . $suffix . '@example.com' ); $order->set_status( 'completed' ); $order->add_product( $variation ); $order->save(); $orders[] = $order; }
		$mail = [];
		$before_jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'yoohw_an_%'" );
		$before_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}yoohw_an_recipients" );
		$assert( 'product_row_action_available', isset( $notify->contextual_product_row_action( [], get_post( $parent->get_id() ) )['yoohw_an_draft'] ) );
		$post = get_post( $parent->get_id() ); ob_start(); $notify->contextual_product_edit_action(); $product_html = ob_get_clean();
		$assert( 'product_editor_explains_parent_scope', str_contains( $product_html, 'includes all its variations' ) );
		ob_start(); $notify->contextual_variation_action( 0, [], get_post( $variation->get_id() ) ); $variation_html = ob_get_clean();
		$assert( 'variation_link_has_exact_id_and_nonce', str_contains( $variation_html, 'object_id=' . $variation->get_id() ) && str_contains( $variation_html, '_wpnonce=' ) );
		ob_start(); $notify->contextual_order_edit_action( $orders[0]->get_id() ); $order_html = ob_get_clean();
		$assert( 'order_detail_has_draft_link', str_contains( $order_html, 'object_id=' . $orders[0]->get_id() ) && str_contains( $order_html, '_wpnonce=' ) );
		foreach ( [ $parent, $variation ] as $product ) {
			$draft = $open( 'product', $product->get_id() ); $drafts[] = $draft['draft_id'];
			$assert( 'product_draft_keeps_exact_' . $product->get_type(), [ $product->get_id() ] === $draft['product_ids'] && 'product_customers' === $draft['recipient_source'] );
		}
		$draft = $open( 'order', $orders[0]->get_id() ); $drafts[] = $draft['draft_id'];
		$assert( 'order_detail_keeps_one_order', [ $orders[0]->get_id() ] === $draft['order_ids'] && 'selected_orders' === $draft['recipient_source'] );
		foreach ( [ 'edit-shop_order' => 'bulk-posts', 'woocommerce_page_wc-orders' => 'bulk-orders' ] as $screen => $nonce_action ) {
			$hook = 'handle_bulk_actions-' . $screen; $original_hook = $wp_filter[ $hook ] ?? null;
			remove_all_filters( $hook ); add_filter( $hook, [ $notify, 'handle_contextual_order_bulk_action' ], 10, 3 );
			try {
				$_REQUEST = [ '_wpnonce' => wp_create_nonce( $nonce_action ) ];
				$url = apply_filters( $hook, 'http://example.com/unchanged', 'yoohw_an_draft', [ $orders[1]->get_id(), $orders[0]->get_id(), $orders[1]->get_id() ] );
				parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $args ); $draft = $call( 'read_durable_option', 'yoohw_an_draft_' . $args['draft_id'] ); $drafts[] = $draft['draft_id'];
				$ids = array_map( static function ( $order ) { return $order->get_id(); }, $orders ); sort( $ids, SORT_NUMERIC );
				$assert( $screen . '_bulk_retains_exact_order_set', $ids === $draft['order_ids'] );
				$_REQUEST['_wpnonce'] = 'invalid'; $expect_die( static function () use ( $hook, $orders ) { apply_filters( $hook, 'http://example.com/', 'yoohw_an_draft', [ $orders[0]->get_id() ] ); } );
				$assert( $screen . '_unrelated_bulk_action_is_untouched', 'unchanged' === apply_filters( $hook, 'unchanged', 'unrelated', [] ) );
			} finally { if ( null === $original_hook ) { unset( $wp_filter[ $hook ] ); } else { $wp_filter[ $hook ] = $original_hook; } }
		}
		$before_invalid = count( $read_drafts() );
		$expect_die( static function () use ( $open, $parent ) { $open( 'product', $parent->get_id(), 'invalid' ); } );
		$expect_die( static function () use ( $call, $orders ) { $call( 'contextual_orders_draft', [ $orders[0]->get_id(), 999999999 ] ); } );
		$expect_die( static function () use ( $call, $orders ) { $call( 'contextual_orders_draft', [ $orders[0]->get_id(), 'bad' ] ); } );
		$expect_die( static function () use ( $call ) { $call( 'contextual_orders_draft', [] ); } );
		$orders[1]->delete( false );
		$expect_die( static function () use ( $call, $orders ) { $call( 'contextual_orders_draft', [ $orders[0]->get_id(), $orders[1]->get_id() ] ); } );
		wp_set_current_user( $subscriber );
		$assert( 'unauthorized_product_row_action_hidden', [] === $notify->contextual_product_row_action( [], get_post( $parent->get_id() ) ) );
		$assert( 'unauthorized_order_bulk_action_hidden', [] === $notify->contextual_order_bulk_action( [] ) );
		$expect_die( static function () use ( $open, $parent ) { $open( 'product', $parent->get_id() ); } );
		$expect_die( static function () use ( $call, $orders ) { $call( 'contextual_orders_draft', [ $orders[0]->get_id() ] ); } );
		$assert( 'refused_requests_never_save_partial_drafts', count( $read_drafts() ) === $before_invalid );
		$assert( 'entrypoints_create_no_delivery_jobs_or_rows', $before_jobs === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'yoohw_an_%'" ) && $before_rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}yoohw_an_recipients" ) && [] === $mail );
		echo wp_json_encode( [ 'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts', 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
	} finally {
		remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX ); remove_filter( 'wp_die_handler', $die, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
		wp_set_current_user( $original_user ); $_GET = $original_get; $_REQUEST = $original_request; $post = $original_post;
		foreach ( $read_drafts() as $draft ) { if ( ! in_array( $draft['draft_id'], $original_drafts, true ) ) { delete_option( 'yoohw_an_draft_' . $draft['draft_id'] ); } }
		foreach ( $orders as $order ) { $order->delete( true ); }
		foreach ( array_reverse( $products ) as $product ) { $product->delete( true ); }
		require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { if ( ! is_wp_error( $id ) ) { wp_delete_user( $id ); } }
	}
})();
