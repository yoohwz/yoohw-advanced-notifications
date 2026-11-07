<?php
/** Separate-process capture races preserve each immutable event through preparation contention. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$n = new YOOHW_AN_Customer_Email_Notify(); $reflection = new ReflectionClass( $n );
	$call = static function ( $method, ...$args ) use ( $n, $reflection ) { return $reflection->getMethod( $method )->invokeArgs( $n, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$tag = 'guide-' . strtolower( wp_generate_password( 9, false ) );
	$products = $orders = $refunds = $rules = $emails = $campaign_ids = [];
	$original_user = get_current_user_id(); $table = $wpdb->prefix . 'yoohw_an_recipients';
	$mail = []; $fail_transport = false;
	$transport = static function ( $pre, $args ) use ( &$mail, &$fail_transport, $tag ) {
		if ( str_contains( $args['subject'] ?? '', $tag ) ) { $mail[] = $args; return ! $fail_transport; }
		return true;
	};
	add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX, 2 );
	$old_limits = $call( 'read_durable_option', 'yoohw_an_limits' ); $old_rate = $call( 'read_durable_option', 'yoohw_an_rate' );
	$make_order = static function ( $suffix, $product, $subscribed = true, $address = '' ) use ( &$orders, &$emails, $tag, $call ) {
		$email = $address ?: $tag . '-' . $suffix . '@example.com'; $emails[ $email ] = true;
		if ( $subscribed ) { $call( 'record_subscription', $email, 'subscribed', 'Automation fixture evidence' ); }
		$order = wc_create_order(); $orders[] = $order; $order->set_billing_email( $email ); $order->set_billing_first_name( 'Guide Buyer' ); $order->set_billing_country( 'VN' ); $order->add_product( $product ); $order->set_total( 20 ); $order->set_status( 'pending' ); $order->save(); return $order;
	};
	$complete = static function ( $order ) { $order->set_status( 'completed' ); $order->save(); return wc_get_order( $order->get_id() ); };
	$rule_data = static function ( $product, $delay = 0, $version = 'v1' ) use ( $tag ) { return [ 'name' => $tag . ' ' . $version, 'product_ids' => [ $product->get_id() ], 'category_ids' => [], 'delay_hours' => (string) $delay, 'subject' => $tag . ' ' . $version . ' order {order_number}', 'heading' => 'Hello {customer_name}', 'message' => '<p>' . $tag . ' ' . $version . ': {ordered_products} / {order_number} / {store_name}</p>' ]; };
	$save = static function ( $data ) use ( $call, &$rules ) { $rule = $call( 'save_automation_rule', $data ); if ( is_wp_error( $rule ) ) { throw new RuntimeException( $rule->get_error_message() ); } $rules[ $rule['id'] ] = $rule; return $rule; };
	$event = static function ( $rule, $order ) use ( $call, &$campaign_ids ) {
		foreach ( $call( 'read_durable_options', 'yoohw_an_manifest_' ) as $manifest ) {
			$a = $manifest['payload']['automation'] ?? [];
			if ( ( $a['rule_id'] ?? '' ) === $rule['id'] && (int) ( $a['order_id'] ?? 0 ) === $order->get_id() ) { $campaign_ids[ $manifest['payload']['campaign_id'] ] = true; return $manifest; }
		}
		return null;
	};
	$row = static function ( $manifest ) use ( $wpdb, $table ) { return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id = %s', $table, $manifest['payload']['campaign_id'] ), ARRAY_A ); };
	$args = static function ( $manifest ) use ( $n, $row ) {
		$n->dispatch_campaign_batch( $manifest['payload']['campaign_id'] ); $record = $row( $manifest );
		if ( empty( $record['action_id'] ) ) { throw new RuntimeException( 'No real delivery action was stored: ' . wp_json_encode( $record ) ); }
		return ActionScheduler::store()->fetch_action( (int) $record['action_id'] )->get_args()[0] ?? [];
	};
	$run = static function ( $manifest ) use ( $args, $row, $wpdb, $table ) {
		$job_args = $args( $manifest ); $record = $row( $manifest );
		// Move only this fixture's admission due-time so a real delayed action can be exercised without waiting hours.
		$wpdb->update( $table, [ 'scheduled_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ], [ 'id' => $record['id'] ] );
		ActionScheduler_QueueRunner::instance()->process_action( (int) $record['action_id'], 'Automation fixture' ); return $job_args;
	};
	try {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); if ( ! $admins ) { throw new RuntimeException( 'A disposable administrator is required.' ); } wp_set_current_user( $admins[0] );
		$call( 'write_durable_option', 'yoohw_an_limits', [] ); $call( 'write_durable_option', 'yoohw_an_rate', [] );
		foreach ( [ 'A', 'B' ] as $label ) { $p = new WC_Product_Simple(); $p->set_name( $tag . ' Product ' . $label ); $p->set_regular_price( '20' ); $p->save(); $products[] = $p; }
		// Three separate PHP processes race the same hook callback against the real durable manifest lock.
		$race_order = $complete( $make_order( 'race', $products[0] ) ); $race_other = $complete( $make_order( 'race-other', $products[0] ) ); $race_rule = $save( $rule_data( $products[0], 2, 'race' ) ); $call( 'set_automation_enabled', $race_rule['id'], true );

		$processes = [];
		if ( ! $call( 'acquire_campaign_lock', '__automation_rules__', 2 ) ) { throw new RuntimeException( 'Cannot hold the rule edit fixture lock.' ); }
		for ( $i = 0; $i < 3; $i++ ) {
			$pipes = []; $process = proc_open( [ getenv( 'AN_AUTOMATION_WP' ), 'eval-file', __DIR__ . '/automation-worker.php', (string) ( 2 === $i ? $race_other->get_id() : $race_order->get_id() ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start independent automation callback fixture.' ); } fclose( $pipes[0] ); $processes[] = [ $process, $pipes ];
		}
		$diagnostics = [];
		foreach ( $processes as [ $process, $pipes ] ) { $output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $diagnostics[] = [ 'status' => proc_close( $process ), 'output' => $output, 'error' => $error ]; }
		foreach ( $diagnostics as $result ) { if ( 0 !== $result['status'] ) { throw new RuntimeException( 'Automation callback process failed: ' . wp_json_encode( $result ) ); } }
		usleep( 3000000 );
		$captured_while_rule_edit_locked = $event( $race_rule, $race_order ) && $event( $race_rule, $race_other );
		$call( 'release_campaign_lock', '__automation_rules__' );
		if ( ! $captured_while_rule_edit_locked ) { throw new RuntimeException( 'Concurrent trigger capture lost an event: ' . wp_json_encode( $diagnostics ) ); }
		$assert( 'distinct_completed_events_are_captured_while_rule_edit_lock_is_held_over_two_seconds', $captured_while_rule_edit_locked );
		$race = $event( $race_rule, $race_order ); $call( 'register_automation_manifests' ); $n->reconcile_open_campaigns(); $n->dispatch_campaign_batch( $race['payload']['campaign_id'] );
		if ( ! $row( $race ) ) { throw new RuntimeException( 'Race missing tracking row: ' . wp_json_encode( $race ) ); }
		$assert( 'independent_concurrent_completed_callbacks_capture_one_delivery', is_array( $race ) && 1 === count( $race['recipients'] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id=%s', $table, $race['payload']['campaign_id'] ) ) );
		$before_race_mail = count( $mail ); $run( $race ); $run( $event( $race_rule, $race_other ) );
		$assert( 'distinct_order_captures_both_recover_to_exactly_one_guide', count( $mail ) === $before_race_mail + 2 );
		$call( 'set_automation_enabled', $race_rule['id'], false );
	} finally {
		$call( 'release_campaign_lock', '__automation_rules__' );
		remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX ); wp_set_current_user( $original_user );
		foreach ( $call( 'read_durable_options', 'yoohw_an_manifest_' ) as $manifest ) { if ( isset( $rules[ $manifest['payload']['automation']['rule_id'] ?? '' ] ) ) { $campaign_ids[ $manifest['payload']['campaign_id'] ] = true; } }
		foreach ( array_keys( $campaign_ids ) as $id ) {
			foreach ( [ 'campaign_record_key', 'campaign_manifest_key', 'control_key' ] as $method ) { delete_option( $call( $method, $id ) ); } delete_option( $call( 'get_campaign_payload_option_name', $id, $id ) );
			$wpdb->delete( $table, [ 'campaign_id' => $id ] );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s,%s) AND (args LIKE %s OR extended_args LIKE %s)', $wpdb->prefix . 'actionscheduler_actions', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN, '%' . $wpdb->esc_like( $id ) . '%', '%' . $wpdb->esc_like( $id ) . '%' ) );
		}
		foreach ( array_keys( $rules ) as $id ) { delete_option( $call( 'automation_key', $id ) ); }
		foreach ( array_keys( $emails ) as $email ) { foreach ( [ 'yoohw_an_subscription_', 'yoohw_an_optout_', 'yoohw_an_erased_' ] as $prefix ) { delete_option( $prefix . hash( 'sha256', $email ) ); } }
		foreach ( $refunds as $refund ) { $refund->delete( true ); } foreach ( $orders as $order ) { $order->delete( true ); } foreach ( array_reverse( $products ) as $product ) { $product->delete( true ); }
		$call( 'write_durable_option', 'yoohw_an_limits', $old_limits ); $call( 'write_durable_option', 'yoohw_an_rate', $old_rate ); $n->maybe_schedule_campaign_reconciliation();
	}
	echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts', 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
