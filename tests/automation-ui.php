<?php
/** Authorized automation authoring, preview and test mail without customer delivery. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$notify = new YOOHW_AN_Customer_Email_Notify(); $reflection = new ReflectionClass( $notify );
	$call = static function ( $method, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $method )->invokeArgs( $notify, $args ); };
	$checks = []; $assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$tag = 'automation-ui-' . strtolower( wp_generate_password( 8, false ) );
	$original_user = get_current_user_id(); $original_post = $_POST; $original_get = $_GET; $original_request = $_REQUEST; $original_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$users = $products = $orders = $rule_ids = []; $mail = []; $erased_key = ''; $race_erased_key = '';
	$transport = static function ( $pre, $args ) use ( &$mail ) { $mail[] = $args; return true; };
	$die = static function () { return static function ( $message ) { throw new RuntimeException( 'automation-ui-die: ' . $message ); }; };
	$redirect = static function ( $url ) { throw new RuntimeException( 'automation-ui-redirect: ' . $url ); };
	add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX, 2 ); add_filter( 'wp_die_handler', $die, PHP_INT_MAX ); add_filter( 'wp_redirect', $redirect, 0 );
	$invoke = static function ( $data, $operation, $id = '', $extra = [] ) use ( $notify ) {
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( $data, [ 'automation_id' => $id, 'operation' => $operation, '_wpnonce' => wp_create_nonce( 'yoohw_an_automation' ) ], $extra ); $_REQUEST = $_POST;
		try { $notify->handle_automation_action(); } catch ( RuntimeException $error ) {
			if ( ! str_starts_with( $error->getMessage(), 'automation-ui-redirect: ' ) ) { throw $error; }
			parse_str( wp_parse_url( substr( $error->getMessage(), strlen( 'automation-ui-redirect: ' ) ), PHP_URL_QUERY ), $args ); return $args;
		}
		throw new RuntimeException( 'Expected redirect to automation editor.' );
	};
	$expect_die = static function ( $operation ) use ( $assert ) {
		try { $operation(); } catch ( RuntimeException $error ) { if ( str_starts_with( $error->getMessage(), 'automation-ui-die: ' ) ) { return; } throw $error; }
		$assert( 'request_should_have_been_refused', false );
	};
	try {
		$admin = wp_insert_user( [ 'user_login' => $tag . '-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] ); $users[] = $admin;
		$other_admin = wp_insert_user( [ 'user_login' => $tag . '-other-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] ); $users[] = $other_admin;
		$subscriber = wp_insert_user( [ 'user_login' => $tag . '-customer', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] ); $users[] = $subscriber;
		wp_set_current_user( $admin );
		$product = new WC_Product_Simple(); $product->set_name( 'Guide product ' . $tag ); $product->set_regular_price( '10' ); $product->save(); $products[] = $product;
		$order = wc_create_order(); $order->set_billing_email( $tag . '-buyer@example.com' ); $order->set_billing_first_name( 'Guide Buyer' ); $order->add_product( $product ); $order->set_status( 'completed' ); $order->calculate_totals(); $order->save(); $orders[] = $order; $mail = [];
		$data = [ 'name' => 'After purchase ' . $tag, 'product_ids' => [ (string) $product->get_id() ], 'category_ids' => [], 'delay_hours' => '24', 'subject' => 'Guide for {order_number}', 'heading' => 'Hello {customer_name}', 'message' => '<p>Care for {ordered_products}.</p>' ];
		$before_jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'yoohw_an_%'" ); $before_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}yoohw_an_recipients" );
		$before_rules = count( $call( 'automation_rules' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_REQUEST = [];
		$expect_die( static function () use ( $notify ) { $notify->handle_automation_action(); } );
		$expect_die( static function () use ( $invoke, $data ) { $invoke( $data, 'save', '', [ '_wpnonce' => 'invalid' ] ); } );
		$assert( 'get_and_invalid_nonce_do_not_save_rule', count( $call( 'automation_rules' ) ) === $before_rules );
		$redirect_args = $invoke( $data, 'save' ); $id = $redirect_args['automation_id']; $rule_ids[] = $id;
		$saved = $call( 'automation_rules' )[ $id ];
		$assert( 'save_redirects_to_selected_automation_without_enabling', 'automations' === $redirect_args['tab'] && ! $saved['enabled'] );
		$assert( 'save_preserves_explicit_product_and_delay', [ $product->get_id() ] === $saved['product_ids'] && 24 === (int) $saved['delay_hours'] );
		$unsaved = array_merge( $data, [ 'subject' => 'Unsaved {order_number}', 'message' => '<p>Preview only for {customer_name}.</p>' ] );
		$preview_args = $invoke( $unsaved, 'preview', $id, [ 'sample_order_id' => (string) $order->get_id(), 'test_email' => $tag . '-test@example.com' ] );
		$preview_key = $call( 'automation_ui_preview_key' ); $preview = get_transient( $preview_key );
		$assert( 'preview_keeps_unsaved_form_without_changing_active_rule', $preview['rule']['subject'] === $unsaved['subject'] && $call( 'automation_rules' )[ $id ]['subject'] === $data['subject'] );
		$rendered_preview = $call( 'automation_preview', $preview['rule'], $order->get_id() );
		$assert( 'preview_uses_the_exact_sample_order_context', str_contains( $rendered_preview['email']['subject'], $order->get_order_number() ) && str_contains( $rendered_preview['html'], 'Guide Buyer' ) );
		$assert( 'preview_cache_contains_no_rendered_recipient_or_html', ! isset( $preview['recipient'], $preview['email'], $preview['html'] ) && ! str_contains( wp_json_encode( $preview ), 'Guide Buyer' ) );
		$_GET = $preview_args; ob_start(); $call( 'render_automations_page' ); $html = ob_get_clean();
		$assert( 'preview_restores_form_and_personalized_sample', str_contains( $html, 'name="subject" required value="Unsaved {order_number}"' ) && str_contains( $html, 'Personalized guide preview' ) && str_contains( $html, 'Guide Buyer' ) );
		$assert( 'authoring_shares_composer_steps_and_returns_sample_to_review', 4 === substr_count( $html, 'data-an-panel=' ) && str_contains( $html, 'data-an-initial-step="review"' ) && str_contains( $html, 'an-step-controls' ) );
		$assert( 'content_reuses_live_woocommerce_preview_and_device_controls', str_contains( $html, 'id="email_message"' ) && str_contains( $html, 'name="message"' ) && str_contains( $html, 'id="yoohw-advanced-notifications-preview-frame"' ) && 2 === substr_count( $html, 'aria-label="Mobile"' ) );
		$assert( 'rule_controls_use_existing_post_and_nonce_authority', str_contains( $html, 'name="action" value="yoohw_an_automation"' ) && str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'name="operation" value="enable"' ) );
		$assert( 'ui_explains_no_backfill_and_per_order_delivery', str_contains( $html, 'no historical backfill' ) && str_contains( $html, 'one guide for each order' ) );
		$erased_key = 'yoohw_an_erased_' . hash( 'sha256', strtolower( $order->get_billing_email() ) ); update_option( $erased_key, [ 'erased' => true ], false );
		ob_start(); $call( 'render_automations_page' ); $erased_html = ob_get_clean();
		$assert( 'cached_sample_cannot_restore_personal_context_after_erasure', ! str_contains( $erased_html, 'Guide Buyer' ) && str_contains( $erased_html, 'protected by erasure' ) ); delete_option( $erased_key );
		$expect_die( static function () use ( $invoke, $data, $id ) { $invoke( $data, 'preview', $id, [ 'sample_order_id' => 'bad' ] ); } );
		$test_email = $tag . '-test@example.com'; $tested = $invoke( $unsaved, 'test', $id, [ 'sample_order_id' => (string) $order->get_id(), 'test_email' => $test_email ] );
		$assert( 'test_mail_targets_only_explicit_test_address', count( $mail ) === 1 && $mail[0]['to'] === $test_email && str_contains( $mail[0]['subject'], $order->get_order_number() ) );
		$assert( 'test_does_not_enable_or_save_unsaved_content', 'tested' === $tested['automation_notice'] && ! $call( 'automation_rules' )[ $id ]['enabled'] && $call( 'automation_rules' )[ $id ]['subject'] === $data['subject'] );
		$enabled = $invoke( [ 'subject' => 'Injected unsaved subject' ], 'enable', $id );
		$assert( 'enable_uses_saved_rule_only', 'enabled' === $enabled['automation_notice'] && $call( 'automation_rules' )[ $id ]['enabled'] && $call( 'automation_rules' )[ $id ]['subject'] === $data['subject'] && ! get_transient( $preview_key ) );
		$_GET = [ 'automation_id' => $id ]; ob_start(); $call( 'render_automations_page' ); $enabled_html = ob_get_clean();
		$assert( 'enabled_editor_explains_future_only_save_and_disable', str_contains( $enabled_html, 'Save changes for new events' ) && str_contains( $enabled_html, 'Disable and cancel waiting emails' ) && str_contains( $enabled_html, 'Waiting emails keep their confirmed content' ) );
		$edited = array_merge( $data, [ 'subject' => 'New events {order_number}', 'delay_hours' => '48' ] ); $invoke( $edited, 'save', $id );
		$assert( 'save_enabled_rule_keeps_enabled_and_applies_new_config', $call( 'automation_rules' )[ $id ]['enabled'] && 48 === (int) $call( 'automation_rules' )[ $id ]['delay_hours'] && $call( 'automation_rules' )[ $id ]['subject'] === $edited['subject'] );
		$invoke( [], 'disable', $id ); $assert( 'disable_is_explicit_and_keeps_rule_history', ! $call( 'automation_rules' )[ $id ]['enabled'] );
		$assert( 'save_preview_test_enable_create_no_campaign_jobs_or_rows', $before_jobs === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'yoohw_an_%'" ) && $before_rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}yoohw_an_recipients" ) );
		$race_order = wc_create_order(); $race_order->set_billing_email( $tag . '-race-sample@example.com' ); $race_order->set_billing_first_name( 'Race Sample' ); $race_order->add_product( $product ); $race_order->calculate_totals(); $race_order->save(); $orders[] = $race_order;
		$race_erased_key = 'yoohw_an_erased_' . hash( 'sha256', $race_order->get_billing_email() ); $body_renders = 0; $erase_result = null;
		$erase_during_render = static function ( $body ) use ( &$body_renders, &$erase_result, $notify, $race_order ) { if ( 2 === ++$body_renders ) { $erase_result = $notify->erase_personal_data( $race_order->get_billing_email() ); } return $body; };
		$before_race_mail = count( $mail ); add_filter( 'woocommerce_mail_content', $erase_during_render, PHP_INT_MAX );
		try { $race_result = $call( 'automation_test', $data, $race_order->get_id(), $tag . '-different-test@example.com' ); } finally { remove_filter( 'woocommerce_mail_content', $erase_during_render, PHP_INT_MAX ); }
		$assert( 'sample_erased_during_test_body_render_blocks_other_test_address_handoff', 2 === $body_renders && ! empty( $erase_result['done'] ) && get_option( $race_erased_key ) && false === $race_result && $before_race_mail === count( $mail ) );

		$preview_args = $invoke( $unsaved, 'preview', $id, [ 'sample_order_id' => (string) $order->get_id() ] );
		wp_set_current_user( $other_admin ); $_GET = $preview_args; ob_start(); $call( 'render_automations_page' ); $other = ob_get_clean();
		$assert( 'preview_is_bound_to_the_authoring_user', ! str_contains( $other, 'Guide Buyer' ) && ! str_contains( $other, 'class="an-automation-sample"' ) );
		wp_set_current_user( $subscriber ); $_GET = []; ob_start(); $call( 'render_automations_page' ); $denied = ob_get_clean();
		$expect_die( static function () use ( $invoke, $data ) { $invoke( $data, 'save' ); } );
		$assert( 'customer_cannot_view_or_modify_automation_rules', '' === $denied && count( $call( 'automation_rules' ) ) === $before_rules + 1 );
		echo wp_json_encode( [ 'checks' => $checks, 'passed' => ! in_array( false, $checks, true ) ], JSON_PRETTY_PRINT ) . "\n";
	} finally {
		remove_filter( 'wp_redirect', $redirect, 0 ); remove_filter( 'wp_die_handler', $die, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
		if ( $erased_key ) { delete_option( $erased_key ); } if ( $race_erased_key ) { delete_option( $race_erased_key ); }
		foreach ( $rule_ids as $id ) { delete_option( 'yoohw_an_automation_' . $id ); }
		foreach ( $users as $user ) { if ( ! is_wp_error( $user ) ) { delete_transient( 'yoohw_an_automation_preview_' . $user ); } }
		foreach ( $orders as $order ) { $order->delete( true ); } foreach ( $products as $product ) { $product->delete( true ); }
		require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $user ) { if ( ! is_wp_error( $user ) ) { wp_delete_user( $user ); } }
		wp_set_current_user( $original_user ); $_POST = $original_post; $_GET = $original_get; $_REQUEST = $original_request;
		if ( null === $original_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $original_method; }
	}
})();
