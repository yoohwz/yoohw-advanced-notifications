<?php
/** Completed-order guides use the real manifest/queue/worker lifecycle on a disposable database. */
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
		$data = $rule_data( $products[0], 2 );
		foreach ( [ [ 'delay_hours' => '-1' ], [ 'delay_hours' => true ], [ 'delay_hours' => '8761' ], [ 'subject' => '' ], [ 'product_ids' => '1' ], [ 'product_ids' => [ 999999999 ] ] ] as $index => $invalid ) { $assert( 'invalid_rule_' . $index . '_is_rejected', is_wp_error( $call( 'save_automation_rule', array_merge( $data, $invalid ) ) ) ); }
		$historical = $complete( $make_order( 'historical', $products[0] ) );
		$rule = $save( $data ); $assert( 'new_rule_is_disabled_until_explicit_enable', ! $rule['enabled'] );
		$enabled = $call( 'set_automation_enabled', $rule['id'], true );
		$assert( 'enabling_does_not_backfill_historical_completed_orders', ! is_wp_error( $enabled ) && null === $event( $rule, $historical ) );
		$order = $make_order( 'first', $products[0] ); $order->add_product( $products[0] ); $order->save();
		$before = time(); $order = $complete( $order ); $after = time(); $manifest = $event( $rule, $order );
		$assert( 'completed_event_captures_one_recipient_despite_multiple_matching_items', is_array( $manifest ) && count( $manifest['recipients'] ) === 1 && $manifest['recipients'][0]['email'] === $order->get_billing_email() );
		$a = $manifest['payload']['automation'];
		$assert( 'delay_uses_exact_event_time_and_immutable_recipient_due', $a['event_at'] >= $before && $a['event_at'] <= $after && $a['due'] === $a['event_at'] + 2 * HOUR_IN_SECONDS && $manifest['recipients'][0]['confirmed_due'] === $a['due'] );
		$preview = $call( 'automation_preview', $data, $order->get_id() );
		$assert( 'preview_uses_triggering_order_context', ! is_wp_error( $preview ) && str_contains( $preview['email']['subject'], $order->get_order_number() ) && str_contains( $preview['email']['message'], $products[0]->get_name() ) && str_contains( $preview['email']['heading'], 'Guide Buyer' ) );
		$test_email = $tag . '-test@example.com'; $emails[ $test_email ] = true; $before_test = count( $mail );
		$assert( 'test_email_reuses_exact_preview_personalization', true === $call( 'automation_test', $data, $order->get_id(), $test_email ) && count( $mail ) === $before_test + 1 && end( $mail )['subject'] === '[TEST] ' . $preview['email']['subject'] && str_contains( end( $mail )['message'], $order->get_order_number() ) ); $mail = [];
		$order->set_status( 'processing' ); $order->save(); $order = $complete( $order );
		$n->trigger_completed_order_guides( $order->get_id(), 'processing', 'completed', $order );
		$assert( 'repeated_completed_event_keeps_original_manifest_identity_and_due', $event( $rule, $order ) === $manifest );
		$edited_data = $rule_data( $products[1], 4, 'v2' ); $edited = $call( 'save_automation_rule', $edited_data, $rule['id'] );
		$assert( 'editing_enabled_rule_preserves_generation_but_changes_revision', $edited['enabled'] && $edited['generation'] === $enabled['generation'] && $edited['revision'] !== $rule['revision'] );
		$assert( 'editing_rule_does_not_change_waiting_content_conditions_or_due', $event( $rule, $order ) === $manifest && $manifest['payload']['subject'] === $data['subject'] && $manifest['payload']['automation']['product_ids'] === [ $products[0]->get_id() ] );
		$second = $complete( $make_order( 'second', $products[1], true, $order->get_billing_email() ) ); $second_manifest = $event( $edited, $second );
		$assert( 'same_email_different_orders_get_separate_rule_campaigns', $second_manifest && $second_manifest['payload']['campaign_id'] !== $manifest['payload']['campaign_id'] && $second_manifest['recipients'][0]['email'] === $manifest['recipients'][0]['email'] );
		$assert( 'new_events_use_new_content_and_delay_only', $second_manifest['payload']['subject'] === $edited_data['subject'] && $second_manifest['payload']['automation']['due'] === $second_manifest['payload']['automation']['event_at'] + 4 * HOUR_IN_SECONDS );
		$job = $run( $manifest ); $n->process_single_email( $job );
		$assert( 'original_pending_order_uses_saved_conditions_after_rule_edit', count( $mail ) === 1 && $mail[0]['subject'] === $preview['email']['subject'] && str_contains( $mail[0]['message'], $products[0]->get_name() ) && 'sent' === $row( $manifest )['status'] );
		$assert( 'duplicate_worker_cannot_handoff_twice', 1 === count( $mail ) );
		$call( 'set_automation_enabled', $rule['id'], false );
		$assert( 'disable_cancels_unstarted_and_preserves_handed_off_outcome', 'cancelled' === $row( $second_manifest )['status'] && 'sent' === $row( $manifest )['status'] );
		$generation_before_disable = $second_manifest['payload']['automation']['generation']; $reenabled = $call( 'set_automation_enabled', $rule['id'], true );
		$assert( 'reenable_never_reauthorizes_previous_generation', $reenabled['generation'] !== $generation_before_disable && is_wp_error( $call( 'automation_delivery_check', array_merge( $second_manifest['payload'], [ 'to' => $second_manifest['recipients'][0]['email'] ] ) ) ) );
		$n->reconcile_open_campaigns( $second_manifest['payload']['campaign_id'] ); $n->dispatch_campaign_batch( $second_manifest['payload']['campaign_id'] );
		$assert( 'disabled_campaign_cannot_resume_through_existing_recovery', 'cancelled' === $row( $second_manifest )['status'] );
		$order->set_status( 'processing' ); $order->save(); $order = $complete( $order );
		$assert( 'reenable_and_completed_reentry_do_not_create_second_delivery_for_order_rule', $event( $rule, $order ) === $manifest );
		$call( 'set_automation_enabled', $rule['id'], false );

		// Isolate each admission mutation with a fresh rule so unrelated fixtures never widen the event set.
		foreach ( [ 'cancelled', 'processing', 'full-refund', 'partial-refund', 'email-change', 'product-change', 'unknown-subscription', 'unsubscribe', 'erasure', 'unknown-transport' ] as $case ) {
			$case_rule = $save( $rule_data( $products[0], 1, $case ) ); $case_order = $make_order( $case, $products[0], 'unknown-subscription' !== $case );
			$call( 'set_automation_enabled', $case_rule['id'], true ); $case_order = $complete( $case_order ); $m = $event( $case_rule, $case_order );
			$assert( $case . '_captures_only_after_new_completed_event', is_array( $m ) );
			$captured_args = $args( $m );
			if ( in_array( $case, [ 'cancelled', 'processing' ], true ) ) { $case_order->set_status( $case ); $case_order->save(); }
			if ( 'full-refund' === $case || 'partial-refund' === $case ) { $refund = wc_create_refund( [ 'order_id' => $case_order->get_id(), 'amount' => 'full-refund' === $case ? 20 : 5, 'reason' => 'Guide fixture', 'refund_payment' => false ] ); if ( is_wp_error( $refund ) ) { throw new RuntimeException( $refund->get_error_message() ); } $refunds[] = $refund; }
			if ( 'email-change' === $case ) { $changed = $tag . '-changed@example.com'; $emails[ $changed ] = true; $case_order->set_billing_email( $changed ); $case_order->save(); }
			if ( 'product-change' === $case ) { foreach ( $case_order->get_items() as $item ) { $case_order->remove_item( $item->get_id() ); } $case_order->add_product( $products[1] ); $case_order->save(); }
			if ( 'unsubscribe' === $case ) { $call( 'add_unsubscribed_email', $case_order->get_billing_email() ); }
			if ( 'erasure' === $case ) { $n->erase_personal_data( $case_order->get_billing_email() ); }
			$before_mail = count( $mail ); $fail_transport = 'unknown-transport' === $case;
			$case_args = $captured_args;
			if ( 'erasure' === $case ) { $n->process_single_email( $captured_args ); } else { $run( $m ); }
			$state = $row( $m )['status']; $fail_transport = false;
			if ( 'partial-refund' === $case ) { $assert( 'partial_refund_remains_eligible_for_one_guide', 'sent' === $state && count( $mail ) === $before_mail + 1 ); }
			elseif ( 'unknown-transport' === $case ) {
				$assert( 'ambiguous_mail_outcome_is_unknown', 'unknown' === $state && count( $mail ) === $before_mail + 1 );
				$call( 'retry_campaign', $m['payload']['campaign_id'] ); $n->reconcile_open_campaigns( $m['payload']['campaign_id'] ); $n->dispatch_campaign_batch( $m['payload']['campaign_id'] ); $n->process_single_email( $case_args );
				$assert( 'unknown_guide_is_never_automatically_retried', 'unknown' === $row( $m )['status'] && count( $mail ) === $before_mail + 1 );
			} else { $assert( $case . '_is_rechecked_and_never_handed_off', count( $mail ) === $before_mail && in_array( $state, [ 'skipped', 'skipped_unsubscribed', 'cancelled' ], true ) ); }
			if ( 'unknown-subscription' === $case ) { $assert( 'buying_never_establishes_subscription', 'unknown' === $call( 'subscription_record', $case_order->get_billing_email() )['status'] ); }
			$call( 'set_automation_enabled', $case_rule['id'], false );
			if ( 'unknown-transport' === $case ) { $assert( 'disable_preserves_unknown_outcome_and_claim', 'unknown' === $row( $m )['status'] && '' !== $row( $m )['claim_token'] ); }
		}

		$orphan_rule = $save( $rule_data( $products[0], 2, 'orphan' ) ); $orphan_order = $make_order( 'orphan', $products[0] ); $call( 'set_automation_enabled', $orphan_rule['id'], true ); $orphan_order = $complete( $orphan_order ); $orphan = $event( $orphan_rule, $orphan_order );
		delete_option( $call( 'campaign_record_key', $orphan['payload']['campaign_id'] ) ); $wpdb->delete( $table, [ 'campaign_id' => $orphan['payload']['campaign_id'] ] );
		$call( 'register_automation_manifests' ); $n->reconcile_open_campaigns( $orphan['payload']['campaign_id'] ); $n->dispatch_campaign_batch( $orphan['payload']['campaign_id'] );
		$assert( 'orphan_captured_manifest_recovers_original_due_and_recipient', $row( $orphan ) && $row( $orphan )['email'] === $orphan['recipients'][0]['email'] && strtotime( $row( $orphan )['scheduled_at_gmt'] . ' UTC' ) === $orphan['payload']['automation']['due'] );
		$call( 'set_automation_enabled', $orphan_rule['id'], false );
		$active_rule = $save( $rule_data( $products[0], 0, 'admitted' ) ); $active_order = $make_order( 'admitted', $products[0] ); $call( 'set_automation_enabled', $active_rule['id'], true ); $active_order = $complete( $active_order ); $active = $event( $active_rule, $active_order ); $args( $active );
		$claim = $call( 'admit_delivery', $active['payload']['campaign_id'], $active['payload']['delivery_id'], $active['recipients'][0]['email'], 0 ); $call( 'set_automation_enabled', $active_rule['id'], false );
		$assert( 'disable_does_not_rewrite_an_already_admitted_claim', $claim && 'processing' === $row( $active )['status'] && $claim === $row( $active )['claim_token'] );
		$call( 'finish_delivery', $active['payload']['campaign_id'], $active['payload']['delivery_id'], $active['recipients'][0]['email'], $claim, 'unknown', 'Fixture admitted worker has no transport confirmation.', 'transport' );
		$deactivate_rule = $save( $rule_data( $products[0], 2, 'deactivate' ) ); $deactivate_order = $make_order( 'deactivate', $products[0] ); $call( 'set_automation_enabled', $deactivate_rule['id'], true ); $deactivate_order = $complete( $deactivate_order ); $deactivate = $event( $deactivate_rule, $deactivate_order );
		$n->deactivate();
		$assert( 'deactivation_durably_cancels_waiting_guides', 'cancelled' === $row( $deactivate )['status'] && $call( 'campaign_blocked', $deactivate['payload']['campaign_id'] ) );
		$fresh = new YOOHW_AN_Customer_Email_Notify(); $fresh->reconcile_open_campaigns( $deactivate['payload']['campaign_id'] ); $fresh->dispatch_campaign_batch( $deactivate['payload']['campaign_id'] );
		$assert( 'reactivation_recovery_does_not_recreate_cancelled_guide', 'cancelled' === $row( $deactivate )['status'] );
	} finally {
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
