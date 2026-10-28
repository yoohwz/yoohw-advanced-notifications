<?php
/** Run only in the disposable reliability database; all mail is intercepted. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
(function () {
	global $wpdb;
	$notify = new YOOHW_AN_Customer_Email_Notify();
	$reflection = new ReflectionClass( $notify );
	$call = static function ( $name, ...$args ) use ( $notify, $reflection ) { return $reflection->getMethod( $name )->invokeArgs( $notify, $args ); };
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$original_post = $_POST;
	$tag = 'operator-' . strtolower( wp_generate_password( 8, false ) );
	$campaigns = $orders = $products = $terms = $drafts = $options = [];
	$table = $wpdb->prefix . 'yoohw_an_recipients';
	$mail = [];
	$transport = static function ( $pre, $attributes ) use ( &$mail ) { $mail[] = $attributes; return true; };
	add_filter( 'pre_wp_mail', $transport, PHP_INT_MAX, 2 );
	$old_limits = $call( 'read_durable_option', 'yoohw_an_limits' ); $old_rate = $call( 'read_durable_option', 'yoohw_an_rate' );
	$call( 'write_durable_option', 'yoohw_an_limits', [] );
	$call( 'write_durable_option', 'yoohw_an_rate', [] );
	$make = static function ( $suffix, $emails ) use ( &$campaigns, $tag, $call ) {
		$id = $tag . '-' . $suffix; $campaigns[] = $id;
		$p = [ 'campaign_id' => $id, 'delivery_id' => $id . '-delivery', 'campaign_hash' => hash( 'sha256', $id ), 'subject' => 'Subject', 'heading' => 'Heading', 'message' => '<p>Hello {customer_name}: {product_name}</p>', 'products_label' => 'Shared label', 'from_email' => 'store@example.com', 'from_name' => 'Store', 'reply_to_email' => 'reply@example.com', 'recipient_source' => 'manual_emails', 'manual_emails' => $emails, 'schedule_mode' => 'now', 'product_ids' => [], 'user_roles' => [], 'filters' => [] ];
		$r = array_map( static function ( $email ) { return [ 'email' => $email, 'name' => 'Sample Name', 'country' => 'VN' ]; }, $emails );
		$call( 'confirm_campaign', $p, $r ); return [ $p, $r ];
	};
	$state = static function ( $id ) use ( $wpdb, $table ) { return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id = %s ORDER BY id', $table, $id ), ARRAY_A ); };
	try {
		$draft = $call( 'save_draft', [ 'internal_name' => 'Unfinished', 'subject' => '', 'message' => '', 'recipient_source' => 'manual_emails', 'manual_emails_raw' => 'unfinished@', 'schedule_mode' => 'store', 'scheduled_at_input' => '2030-06-01T12:00', 'filters' => [ 'subscription' => 'unknown' ] ] ); $drafts[] = $draft['draft_id'];
		$assert( 'incomplete_draft_is_durable', $call( 'read_durable_option', 'yoohw_an_draft_' . $draft['draft_id'] )['manual_emails_raw'] === 'unfinished@' );
		$assert( 'saving_draft_creates_no_manifest', ! $call( 'read_durable_option', $call( 'campaign_manifest_key', $draft['draft_id'] ) ) );
		$token = $call( 'store_preview_payload', [ 'draft_id' => $draft['draft_id'], 'draft_revision' => $draft['revision'] ] );
		$call( 'delete_preview_payload', $token );
		$assert( 'preview_expiry_does_not_expire_draft', (bool) $call( 'read_durable_option', 'yoohw_an_draft_' . $draft['draft_id'] ) );
		$old_revision = [ 'draft_id' => $draft['draft_id'], 'draft_revision' => $draft['revision'] ];
		$draft = $call( 'save_draft', array_merge( $draft, [ 'subject' => 'Changed' ] ) );
		$assert( 'saving_invalidates_old_preview', is_wp_error( $call( 'validate_preview_revision', $old_revision ) ) );
		[ $p, $r ] = $make( 'control', [ $tag . '-a@example.com', $tag . '-b@example.com' ] );
		$id = $p['campaign_id']; $notify->dispatch_campaign_batch( $id );
		$before = $state( $id );
		$assert( 'pause_saved', true === $call( 'control_campaign', $id, 'pause' ) );
		foreach ( $r as $recipient ) { $notify->process_single_email( $call( 'build_email_action_args', $p, $recipient, $call( 'store_campaign_email_payload', $p ) ) ); }
		$assert( 'pause_blocks_unstarted_workers', count( $mail ) === 0 && array_column( $state( $id ), 'status' ) === [ 'paused_wait', 'paused_wait' ] );
		$notify->reconcile_open_campaigns( $id );
		$assert( 'reconciliation_respects_pause', $call( 'get_campaign_history' )[ $id ]['status'] === 'paused' );
		$assert( 'resume_same_campaign', true === $call( 'control_campaign', $id, 'resume' ) );
		$notify->dispatch_campaign_batch( $id );
		$assert( 'resume_does_not_consume_retry_or_change_due', array_column( $before, 'attempts' ) === array_column( $state( $id ), 'attempts' ) && array_column( $before, 'scheduled_at_gmt' ) === array_column( $state( $id ), 'scheduled_at_gmt' ) );
		$notify->process_single_email( $call( 'build_email_action_args', $p, $r[0], $call( 'store_campaign_email_payload', $p ) ) );
		$call( 'control_campaign', $id, 'cancel' );
		$notify->process_single_email( $call( 'build_email_action_args', $p, $r[1], $call( 'store_campaign_email_payload', $p ) ) );
		$call( 'retry_campaign', $id ); $notify->reconcile_open_campaigns( $id ); $notify->dispatch_campaign_batch( $id );
		$assert( 'cancel_preserves_handoff_and_blocks_recovery', array_column( $state( $id ), 'status' ) === [ 'sent', 'cancelled' ] && count( $mail ) === 1 );
		[ $partial, $partial_recipients ] = $make( 'partial-cancel', [ $tag . '-partial@example.com' ] );
		$call( 'write_durable_option', $call( 'control_key', $partial['campaign_id'] ), [ 'cancelled' => true ] );
		$notify->reconcile_open_campaigns( $partial['campaign_id'] );
		$assert( 'interrupted_cancel_finishes_recipient_cancellation', $state( $partial['campaign_id'] )[0]['status'] === 'cancelled' );
		[ $cancel_fault, $cancel_fault_recipients ] = $make( 'cancel-storage', [ $tag . '-cancel-storage@example.com' ] );
		$fault_cancel = static function ( $sql ) { return str_contains( $sql, "SET status = 'cancelled'" ) && str_contains( $sql, 'UPDATE' ) ? 'UPDATE an_missing_cancel_fixture SET status = 1' : $sql; };
		$previous_errors = $wpdb->suppress_errors(); add_filter( 'query', $fault_cancel );
		$result = $call( 'control_campaign', $cancel_fault['campaign_id'], 'cancel' );
		remove_filter( 'query', $fault_cancel ); $wpdb->suppress_errors( $previous_errors );
		$assert( 'cancel_write_failure_reports_saved_protection', is_wp_error( $result ) && $call( 'campaign_blocked', $cancel_fault['campaign_id'] ) );
		$notify->process_single_email( $call( 'build_email_action_args', $cancel_fault, $cancel_fault_recipients[0], $call( 'store_campaign_email_payload', $cancel_fault ) ) );
		$assert( 'cancelled_worker_does_not_become_paused_wait', $state( $cancel_fault['campaign_id'] )[0]['status'] === 'cancelled' );
		$notify->reconcile_open_campaigns( $cancel_fault['campaign_id'] );
		$assert( 'cancel_is_irreversible_for_same_campaign', is_wp_error( $call( 'control_campaign', $id, 'resume' ) ) );
		$copy = $call( 'save_draft', $call( 'get_campaign_history' )[ $id ], wp_generate_uuid4() ); $drafts[] = $copy['draft_id'];
		$assert( 'duplicate_has_no_runtime_identity', ! isset( $copy['campaign_id'], $copy['delivery_id'], $copy['status'], $copy['claim_token'] ) );
		$copy['message'] = 'Edited duplicate'; $call( 'save_draft', $copy );
		$assert( 'draft_edits_cannot_change_confirmed_manifest', $call( 'read_durable_option', $call( 'campaign_manifest_key', $id ) )['payload']['message'] === $p['message'] );
		$call( 'write_durable_option', 'yoohw_an_limits', [ 'minute' => 1, 'day' => 1 ] ); $call( 'write_durable_option', 'yoohw_an_rate', [] );
		[ $first, $fr ] = $make( 'rate1', [ $tag . '-rate1@example.com' ] );
		[ $second, $sr ] = $make( 'rate2', [ $tag . '-rate2@example.com' ] );
		foreach ( [ [ $first, $fr ], [ $second, $sr ] ] as [ $payload, $recipients ] ) { $notify->dispatch_campaign_batch( $payload['campaign_id'] ); $notify->process_single_email( $call( 'build_email_action_args', $payload, $recipients[0], $call( 'store_campaign_email_payload', $payload ) ) ); }
		$assert( 'limits_apply_across_campaigns', $state( $first['campaign_id'] )[0]['status'] === 'sent' && $state( $second['campaign_id'] )[0]['status'] === 'rate_wait' );
		$waiting = $state( $second['campaign_id'] )[0];
		$assert( 'rate_wait_keeps_original_attempts', (int) $waiting['attempts'] === 1 && '' === $waiting['claim_token'] );
		$assert( 'daily_limit_reports_next_utc_boundary', $call( 'campaign_control', $second['campaign_id'] )['rate_resume_at'] === (int) ( floor( time() / DAY_IN_SECONDS ) * DAY_IN_SECONDS + DAY_IN_SECONDS ) );
		$call( 'write_durable_option', 'yoohw_an_rate', [] );
		$call( 'write_durable_option', $call( 'control_key', $second['campaign_id'] ), [] );
		$notify->dispatch_campaign_batch( $second['campaign_id'] );
		$notify->process_single_email( $call( 'build_email_action_args', $second, $sr[0], $call( 'store_campaign_email_payload', $second ) ) );
		$assert( 'rate_wait_resumes_without_retry_consumption', $state( $second['campaign_id'] )[0]['status'] === 'sent' && (int) $state( $second['campaign_id'] )[0]['attempts'] === 1 );
		$call( 'write_durable_option', 'yoohw_an_limits', [] );
		$assert( 'dst_nonexistent_rejected', 0 === $call( 'strict_wall_timestamp', '2030-03-10T02:30', 'America/New_York' ) );
		$assert( 'dst_repeated_rejected', 0 === $call( 'strict_wall_timestamp', '2030-11-03T01:30', 'America/New_York' ) );
		$assert( 'fixed_offset_timezone_supported', $call( 'strict_wall_timestamp', '2030-06-01T12:00', '+00:00' ) > 0 && $call( 'strict_wall_timestamp', '2030-06-01T12:00', '+07:00' ) > 0 );
		$assert( 'valid_wall_time_accepted', $call( 'strict_wall_timestamp', '2030-11-03T03:30', 'America/New_York' ) > 0 );
		$scheduled = array_merge( $p, [ 'schedule_mode' => 'local', 'schedule_policy' => 'strict_v1', 'scheduled_at_input' => '2030-06-01T12:00', 'store_timezone' => 'Asia/Ho_Chi_Minh' ] );
		$vn = $call( 'operator_schedule', $scheduled, [ 'country' => 'VN' ] ); $us = $call( 'operator_schedule', $scheduled, [ 'country' => 'US' ] );
		$assert( 'single_zone_country_is_estimate', ! $vn['fallback'] && $vn['timezone'] === 'Asia/Ho_Chi_Minh' );
		$assert( 'ambiguous_country_uses_utc', $us['fallback'] && $us['timezone'] === 'UTC' );
		$scheduled['schedule_mode'] = 'store';
		$assert( 'store_schedule_is_one_shared_instant', $call( 'operator_schedule', $scheduled, [ 'country' => 'US' ] )['timestamp'] === $call( 'operator_schedule', $scheduled, [ 'country' => 'VN' ] )['timestamp'] );
		$scheduled['scheduled_at_input'] = '2020-01-01T12:00'; $recipients = $r;
		$assert( 'past_time_does_not_roll_forward', is_wp_error( $reflection->getMethod( 'prepare_operator_preview' )->invokeArgs( $notify, [ $scheduled, &$recipients ] ) ) );
		$product = new WC_Product_Simple(); $product->set_name( 'Operator fixture' ); $product->save(); $products[] = $product;
		$category = wp_insert_term( $tag, 'product_cat' ); $terms[] = $category['term_id']; wp_set_object_terms( $product->get_id(), $category['term_id'], 'product_cat' );
		foreach ( [ [ 'completed', 'VN', '-20 days', true ], [ 'processing', 'US', 'now', true ], [ 'completed', 'VN', 'now', false ] ] as [ $status, $country, $date, $has_product ] ) {
			$order = wc_create_order(); $orders[] = $order; $order->set_billing_email( $tag . '-orders@example.com' ); $order->set_billing_country( $country ); $order->set_status( $status ); $order->set_date_created( strtotime( $date ) ); if ( $has_product ) { $order->add_product( $product ); } $order->save();
		}
		$audience = [ 'recipient_source' => 'product_customers', 'product_ids' => [ $product->get_id() ], 'filters' => [ 'last_days' => '7', 'country' => 'VN', 'order_statuses' => [ 'wc-completed' ], 'category_ids' => [ (string) $category['term_id'] ] ] ];
		$assert( 'order_conditions_never_mix_across_orders', [] === $call( 'get_recipients_by_payload', $audience ) );
		$orders[2]->add_product( $product ); $orders[2]->save();
		$assert( 'single_matching_order_qualifies', [ $tag . '-orders@example.com' ] === array_column( $call( 'get_recipients_by_payload', $audience ), 'email' ) );
		$audience['filters']['date_from'] = 'not-a-date';
		$assert( 'invalid_filter_fails_closed', is_wp_error( $call( 'validate_audience_filters', $audience ) ) && [] === $call( 'get_recipients_by_payload', $audience ) );
		$assert( 'manual_order_filters_rejected', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'manual_emails', 'filters' => [ 'last_days' => '7' ] ] ) ) );
		$assert( 'legacy_address_is_unknown', $call( 'subscription_record', $tag . '-new@example.com' )['status'] === 'unknown' );
		$import = $call( 'import_subscription_preview', $tag . '-consent@example.com' . "\n" . strtoupper( $tag ) . '-consent@example.com' . "\ninvalid", 'unsubscribed', 'legacy opt-outs' );
		$assert( 'import_counts_valid_duplicate_rejected', count( $import['valid'] ) === 1 && $import['duplicates'] === 1 && count( $import['rejected'] ) === 1 );
		$email = $import['valid'][0]; foreach ( [ 'yoohw_an_subscription_', 'yoohw_an_optout_', 'yoohw_an_erased_' ] as $prefix ) { $options[] = $prefix . hash( 'sha256', $email ); }
		$assert( 'suppression_import_is_durable', $call( 'record_subscription', $email, 'unsubscribed', 'legacy' ) );
		$assert( 'consent_import_never_removes_optout', ! $call( 'record_subscription', $email, 'subscribed', 'new list' ) && $call( 'is_email_unsubscribed', $email ) );
		$assert( 'repeated_import_is_duplicate', $call( 'import_subscription_preview', $email, 'unsubscribed', 'legacy' )['duplicates'] === 1 );
		$unseen = array_merge( $p, [ 'manual_emails' => [ $tag . '-unseen@example.com' ], 'filters' => [ 'history_campaign' => $id, 'history_mode' => 'not_handoff' ] ] );
		$assert( 'missing_history_is_not_known_unsent', [] === $call( 'get_recipients_by_payload', $unseen ) );
		$known = array_merge( $p, [ 'filters' => [ 'history_campaign' => $id, 'history_mode' => 'not_handoff' ] ] );
		$assert( 'known_cancelled_is_unsent_but_sent_is_not', array_column( $call( 'get_recipients_by_payload', $known ), 'email' ) === [ $r[1]['email'] ] );
		$erased = $r[0]['email']; $options[] = 'yoohw_an_erased_' . hash( 'sha256', $erased );
		$export = $notify->export_personal_data( $erased );
		$assert( 'guest_privacy_export_contains_own_recipient', str_contains( wp_json_encode( $export ), $erased ) && ! str_contains( wp_json_encode( $export ), $r[1]['email'] ) );
		$notify->erase_personal_data( $erased );
		$assert( 'erasure_preserves_sent_hash_and_removes_identity', $state( $id )[0]['email'] === '' && $state( $id )[0]['status'] === 'sent' && $state( $id )[0]['claim_token'] !== '' );
		$assert( 'erasure_cannot_rebuild_audience', [] === $call( 'get_recipients_by_payload', array_merge( $p, [ 'manual_emails' => [ $erased ] ] ) ) );
		$manifest = $call( 'read_durable_option', $call( 'campaign_manifest_key', $id ) );
		$assert( 'manifest_snapshot_is_scrubbed', ! str_contains( wp_json_encode( $manifest ), $erased ) );
		$call( 'resume_campaign_preparation', $id );
		$assert( 'recovery_does_not_restore_erased_row', $state( $id )[0]['email'] === '' );
		$notify->erase_personal_data( $email );
		$assert( 'erasure_retains_suppression_hash', $call( 'is_email_unsubscribed', $email ) && ! isset( $call( 'read_durable_option', 'yoohw_an_optout_' . hash( 'sha256', $email ) )['email'] ) );
		$closed = $call( 'get_campaign_history' )[ $first['campaign_id'] ]; $closed['completed_at'] = time() - 3 * DAY_IN_SECONDS; $closed['status'] = 'completed'; $call( 'save_campaign_record', $closed );
		$active_manifest = $call( 'read_durable_option', $call( 'campaign_manifest_key', $second['campaign_id'] ) );
		$assert( 'manifest_contains_original_recipients', $active_manifest['recipients'][0]['email'] === $sr[0]['email'] );
		$changed = $call( 'refresh_changed_preview', array_merge( $p, [ 'audience_fingerprint' => $call( 'audience_fingerprint', $r ) ] ), [ $r[1] ] );
		$assert( 'audience_change_requires_fresh_preview', (bool) $changed && $call( 'get_preview_payload', $changed )['recipient_count'] === 1 ); $call( 'delete_preview_payload', $changed );
		$assert( 'filter_changes_change_campaign_hash', $call( 'generate_campaign_hash', $p ) !== $call( 'generate_campaign_hash', array_merge( $p, [ 'filters' => [ 'subscription' => 'subscribed' ] ] ) ) );
		$assert( 'timezone_policy_changes_campaign_hash', $call( 'generate_campaign_hash', $p ) !== $call( 'generate_campaign_hash', array_merge( $p, [ 'schedule_policy' => 'strict_v1', 'store_timezone' => 'Asia/Ho_Chi_Minh' ] ) ) );
		$assert( 'zero_day_filter_rejected', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'woocommerce_customers', 'filters' => [ 'last_days' => '0' ] ] ) ) );
		$assert( 'malformed_status_list_rejected', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'woocommerce_customers', 'filters' => [ 'order_statuses' => 'completed' ] ] ) ) );
		$_POST = [ 'audience_filters' => [ 'last_days' => [ '7' ] ] ];
		$assert( 'malformed_request_cannot_drop_filter', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'woocommerce_customers', 'filters' => $call( 'filters_from_request' ) ] ) ) );
		$_POST = [ 'audience_filters' => [ 'unexpected' => 'value' ] ];
		$assert( 'unknown_request_filter_rejected', is_wp_error( $call( 'validate_audience_filters', [ 'recipient_source' => 'woocommerce_customers', 'filters' => $call( 'filters_from_request' ) ] ) ) );
		$_POST = [ 'recipient_source' => 'manual_emails', 'manual_emails' => 'valid@example.com', 'email_subject' => 'Fixture', 'email_heading' => 'Fixture', 'email_message' => '<p>Fixture</p>', 'exclude_emails' => 'not-an-email' ];
		$assert( 'malformed_exclusion_email_rejected', is_wp_error( $call( 'get_payload_from_request' ) ) );
		$_POST = [];
		$audience_option = 'yoohw_an_audience_' . wp_generate_uuid4(); $options[] = $audience_option;
		$call( 'write_durable_option', $audience_option, [ 'id' => 'fixture', 'criteria' => [ 'recipient_source' => 'manual_emails', 'manual_emails' => [ $erased ] ] ] );
		$export = $notify->export_personal_data( $erased );
		$assert( 'privacy_exports_saved_audience_reference', str_contains( wp_json_encode( $export ), 'Saved list references' ) );
		$notify->erase_personal_data( $erased );
		$assert( 'privacy_scrubs_saved_audience_and_is_repeatable', ! str_contains( wp_json_encode( $call( 'read_durable_option', $audience_option ) ), $erased ) );
		$assert( 'erased_address_cannot_create_new_tracking_row', ! $call( 'ensure_delivery_row', 'erased-new-campaign', 'erased-new-delivery', $erased ) );
		$assert( 'unsubscribing_after_erasure_keeps_only_hash', $call( 'record_subscription', $erased, 'unsubscribed', 'fixture' ) && ! isset( $call( 'read_durable_option', 'yoohw_an_optout_' . hash( 'sha256', $erased ) )['email'] ) );
		$options[] = 'yoohw_an_optout_' . hash( 'sha256', $erased );
		$failure_email = $tag . '-storage@example.com';
		$fault = static function ( $sql ) { return str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, 'yoohw_an_erased_' ) ? 'INSERT INTO an_missing_erasure_fixture VALUES (1)' : $sql; };
		$previous_errors = $wpdb->suppress_errors(); add_filter( 'query', $fault );
		$result = $notify->erase_personal_data( $failure_email );
		remove_filter( 'query', $fault ); $wpdb->suppress_errors( $previous_errors );
		$assert( 'failed_erasure_does_not_report_success', ! $result['items_removed'] && $result['items_retained'] );
		$call( 'write_durable_option', 'yoohw_an_limits', [ 'retention_days' => 1 ] ); $notify->cleanup_retention();

		$assert( 'retention_removes_content_and_keeps_outcomes', ! empty( $call( 'get_campaign_history' )[ $first['campaign_id'] ]['retained'] ) && $state( $first['campaign_id'] )[0]['email'] === '' && $state( $first['campaign_id'] )[0]['status'] === 'sent' );
		$pruned = $call( 'get_campaign_history' )[ $first['campaign_id'] ]; unset( $pruned['retention_complete'] ); $call( 'save_campaign_record', $pruned );
		$call( 'write_durable_option', $call( 'campaign_manifest_key', $first['campaign_id'] ), [ 'payload' => $first, 'recipients' => $fr, 'confirmed_at' => time() ] );
		$notify->cleanup_retention();
		$assert( 'interrupted_retention_can_finish_from_tombstone', ! $call( 'read_durable_option', $call( 'campaign_manifest_key', $first['campaign_id'] ) ) && ! empty( $call( 'get_campaign_history' )[ $first['campaign_id'] ]['retention_complete'] ) );
		$assert( 'retention_removes_reconstruction_sources', ! $call( 'read_durable_option', $call( 'campaign_manifest_key', $first['campaign_id'] ) ) && ! $call( 'read_durable_option', $call( 'get_campaign_payload_option_name', $first['campaign_id'], $first['delivery_id'] ) ) );
	} finally {
		$_POST = $original_post; remove_filter( 'pre_wp_mail', $transport, PHP_INT_MAX );
		foreach ( $orders as $order ) { $order->delete( true ); } foreach ( $products as $product ) { $product->delete( true ); } foreach ( $terms as $term ) { wp_delete_term( $term, 'product_cat' ); }
		foreach ( $campaigns as $id ) {
			foreach ( [ 'campaign_record_key', 'campaign_manifest_key', 'control_key' ] as $method ) { delete_option( $call( $method, $id ) ); }
			delete_option( $call( 'get_campaign_payload_option_name', $id, $id . '-delivery' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE campaign_id = %s', $table, $id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s,%s) AND (args LIKE %s OR extended_args LIKE %s)', $wpdb->prefix . 'actionscheduler_actions', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN, '%' . $wpdb->esc_like( $id ) . '%', '%' . $wpdb->esc_like( $id ) . '%' ) );
		}
		foreach ( $drafts as $id ) { delete_option( 'yoohw_an_draft_' . $id ); } foreach ( $options as $option ) { delete_option( $option ); }
		$call( 'write_durable_option', 'yoohw_an_limits', $old_limits ); $call( 'write_durable_option', 'yoohw_an_rate', $old_rate );
	}
	echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
