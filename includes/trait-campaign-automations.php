<?php
/** One completed-order event, one immutable campaign, one guide per rule/order. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Automations {
	private function register_automations() {
		$this->register_automation_ui();
		add_action( 'woocommerce_order_status_changed', [ $this, 'trigger_completed_order_guides' ], 30, 4 );
	}
	private function automation_key( $id ) { return 'yoohw_an_automation_' . sanitize_key( $id ); }
	private function automation_rules() {
		$rules = [];
		foreach ( $this->read_durable_options( 'yoohw_an_automation_' ) as $rule ) { if ( is_array( $rule ) && ! empty( $rule['id'] ) ) { $rules[ $rule['id'] ] = $rule; } }
		return $rules;
	}
	private function normalize_automation_rule( $data ) {
		$error = new WP_Error( 'invalid_automation', __( 'Review the rule name, products, categories, delay and required email content.', 'yoohw-advanced-notifications' ) );
		if ( ! is_array( $data ) ) { return $error; }
		foreach ( [ 'name', 'subject', 'heading', 'message' ] as $field ) { if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === trim( $data[ $field ] ) ) { return $error; } }
		$hours = $data['delay_hours'] ?? '';
		if ( ( ! is_string( $hours ) && ! is_int( $hours ) ) || ! ctype_digit( (string) $hours ) || (int) $hours > 8760 ) { return $error; }
		$result = [ 'name' => sanitize_text_field( $data['name'] ), 'subject' => sanitize_text_field( $data['subject'] ), 'heading' => sanitize_text_field( $data['heading'] ), 'message' => wp_kses_post( $data['message'] ), 'delay_hours' => (int) $hours ];
		foreach ( [ 'product_ids', 'category_ids' ] as $field ) {
			if ( isset( $data[ $field ] ) && ! is_array( $data[ $field ] ) ) { return $error; }
			$ids = [];
			foreach ( $data[ $field ] ?? [] as $id ) {
				if ( ( ! is_string( $id ) && ! is_int( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return $error; }
				if ( 'product_ids' === $field ? ! wc_get_product( (int) $id ) : ! term_exists( (int) $id, 'product_cat' ) ) { return $error; }
				$ids[] = (int) $id;
			}
			$result[ $field ] = array_values( array_unique( $ids ) ); sort( $result[ $field ], SORT_NUMERIC );
		}
		if ( ! $result['name'] || ! $result['subject'] || ! $result['heading'] || ! $result['message'] ) { return $error; }
		return $result;
	}
	private function save_automation_rule( $data, $id = '' ) {
		$normalized = $this->normalize_automation_rule( $data ); if ( is_wp_error( $normalized ) ) { return $normalized; }
		if ( ! $this->acquire_campaign_lock( '__automation_rules__', 2 ) ) { return new WP_Error( 'automation_busy', __( 'Another rule change is in progress. Please try again.', 'yoohw-advanced-notifications' ) ); }
		try {
			$existing = $id ? $this->read_durable_option( $this->automation_key( $id ) ) : null;
			if ( $id && ( ! is_array( $existing ) || $id !== ( $existing['id'] ?? '' ) ) ) { return new WP_Error( 'missing_automation', __( 'Rule not found. Open Automations again.', 'yoohw-advanced-notifications' ) ); }
			$rule = array_merge( $normalized, [ 'id' => $id ?: wp_generate_uuid4(), 'revision' => wp_generate_uuid4(), 'generation' => $existing['generation'] ?? wp_generate_uuid4(), 'enabled' => ! empty( $existing['enabled'] ), 'updated_at' => time(), 'enabled_at' => $existing['enabled_at'] ?? 0 ] );
			if ( false === $this->write_personal_option( $this->automation_key( $rule['id'] ), $rule ) || $rule !== $this->read_durable_option( $this->automation_key( $rule['id'] ) ) ) { throw new RuntimeException( 'Rule could not be saved.' ); }
			return $rule;
		} catch ( Throwable $error ) { return new WP_Error( 'automation_storage', __( 'The rule could not be saved. Please try again after checking storage.', 'yoohw-advanced-notifications' ) ); }
		finally { $this->release_campaign_lock( '__automation_rules__' ); }
	}
	private function set_automation_enabled( $id, $enabled ) {
		if ( ! $this->acquire_campaign_lock( '__automation_rules__', 2 ) ) { return new WP_Error( 'automation_busy', __( 'Another rule change is in progress. Please try again.', 'yoohw-advanced-notifications' ) ); }
		try {
			$rule = $this->read_durable_option( $this->automation_key( $id ) );
			if ( ! is_array( $rule ) || $id !== ( $rule['id'] ?? '' ) ) { return new WP_Error( 'missing_automation', __( 'Rule not found.', 'yoohw-advanced-notifications' ) ); }
			$valid = $this->normalize_automation_rule( $rule ); if ( $enabled && is_wp_error( $valid ) ) { return $valid; }
			if ( ! $enabled && ! empty( $rule['enabled'] ) ) { $rule['generation'] = wp_generate_uuid4(); }
			$rule['enabled'] = (bool) $enabled; $rule['enabled_at'] = $enabled ? time() : 0; $rule['updated_at'] = time();
			if ( false === $this->write_personal_option( $this->automation_key( $id ), $rule ) || $rule !== $this->read_durable_option( $this->automation_key( $id ) ) ) { throw new RuntimeException( 'Rule change could not be verified.' ); }
		} catch ( Throwable $error ) { return new WP_Error( 'automation_storage', __( 'The rule change could not be verified. Please check its current state and try again.', 'yoohw-advanced-notifications' ) ); }
		finally { $this->release_campaign_lock( '__automation_rules__' ); }
		if ( ! $enabled ) {
			try { $this->reconcile_automation_campaigns(); }
			catch ( Throwable $error ) { return new WP_Error( 'automation_cancel_pending', __( 'The rule is disabled and its previous generation cannot send. Some campaign cancellations still need cleanup; open Campaigns again after checking storage.', 'yoohw-advanced-notifications' ) ); }
		}
		return $rule;
	}
	private function automation_order_matches( $order, $rule ) {
		if ( ! $this->is_valid_purchase_order( $order ) || 'completed' !== $order->get_status() ) { return false; }
		if ( ! $this->order_matches_filters( $order, [ 'category_ids' => $rule['category_ids'] ?? [] ] ) ) { return false; }
		if ( empty( $rule['product_ids'] ) ) { return true; }
		foreach ( $order->get_items( 'line_item' ) as $item ) { if ( in_array( (int) $item->get_product_id(), $rule['product_ids'], true ) || in_array( (int) $item->get_variation_id(), $rule['product_ids'], true ) ) { return true; } }
		return false;
	}
	private function automation_recipient( $order ) {
		return [ 'email' => strtolower( sanitize_email( $order->get_billing_email() ) ), 'name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 'user_id' => (int) $order->get_user_id(), 'country' => $order->get_billing_country(), 'state' => $order->get_billing_state(), 'city' => $order->get_billing_city(), 'order_context' => $this->context_from_order( $order ) ];
	}
	private function automation_campaign_payload( $rule, $order, $event_time ) {
		$id = 'guide-' . substr( hash( 'sha256', $rule['id'] . '|' . $order->get_id() ), 0, 32 );
		$due = $event_time + $rule['delay_hours'] * HOUR_IN_SECONDS;
		return [ 'campaign_id' => $id, 'delivery_id' => $id, 'campaign_hash' => hash( 'sha256', $id ), 'internal_name' => $rule['name'] . ' / #' . $order->get_order_number(), 'subject' => $rule['subject'], 'heading' => $rule['heading'], 'message' => $rule['message'], 'products_label' => $rule['product_ids'] ? $this->get_selected_products_label( $rule['product_ids'] ) : __( 'Completed-order guide', 'yoohw-advanced-notifications' ), 'personalization_version' => 1, 'store_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'recipient_source' => self::SOURCE_SELECTED_ORDERS, 'recipient_source_label' => __( 'Completed-order guide', 'yoohw-advanced-notifications' ), 'order_ids' => [ $order->get_id() ], 'product_ids' => [], 'user_roles' => [], 'manual_emails' => [], 'filters' => [], 'schedule_mode' => $rule['delay_hours'] ? 'store' : 'now', 'schedule_policy' => 'automation_v1', 'store_timezone' => 'UTC', 'scheduled_at_input' => gmdate( 'Y-m-d\TH:i', $due ), 'scheduled_at_timestamp' => $due, 'from_email' => $this->get_sender_email(), 'from_name' => $this->get_sender_name(), 'reply_to_email' => $this->get_reply_to_email(), 'automation' => [ 'rule_id' => $rule['id'], 'revision' => $rule['revision'], 'generation' => $rule['generation'], 'order_id' => $order->get_id(), 'event_at' => $event_time, 'due' => $due, 'product_ids' => $rule['product_ids'], 'category_ids' => $rule['category_ids'] ] ];
	}
	public function trigger_completed_order_guides( $order_id, $from, $to, $order ) {
		if ( 'completed' !== $to || $from === $to || ! $order instanceof WC_Order ) { return; }
		// A rule read is the event's configuration snapshot. Distinct orders never wait behind rule edits or preparation.
		try {
			foreach ( $this->automation_rules() as $rule ) {
				if ( empty( $rule['enabled'] ) || ! $this->automation_order_matches( $order, $rule ) ) { continue; }
				$payload = $this->automation_campaign_payload( $rule, $order, time() ); $id = $payload['campaign_id'];
				if ( $this->read_durable_option( $this->campaign_manifest_key( $id ) ) || $this->read_durable_option( $this->campaign_record_key( $id ) ) ) { continue; }
				$recipient = $this->automation_recipient( $order ); $recipient['confirmed_due'] = $payload['automation']['due'];
				if ( ! is_email( $recipient['email'] ) ) {
					$this->record_campaign_snapshot( $payload, 'empty', [ 'recipient_count' => 0 ] );
					$record = $this->read_durable_option( $this->campaign_record_key( $id ) ); $record['recovery_message'] = __( 'The triggering order has no valid billing email. No guide was queued.', 'yoohw-advanced-notifications' ); $this->save_campaign_record( $record ); continue;
				}
				// Capture only the immutable manifest under short locks. Preparation can resume independently.
				if ( ! $this->acquire_campaign_lock( $id, 2 ) ) { continue; }
				try {
					if ( $this->read_durable_option( $this->campaign_manifest_key( $id ) ) || $this->read_durable_option( $this->campaign_record_key( $id ) ) ) { continue; }
					if ( ! $this->acquire_campaign_lock( '__privacy__', 2 ) ) { throw new RuntimeException( 'Guide capture privacy check busy.' ); }
					try {
						$manifest = [ 'payload' => $payload, 'recipients' => [ $recipient ], 'confirmed_at' => time() ];
						if ( 1 !== $this->write_personal_option( $this->campaign_manifest_key( $id ), $manifest, true ) ) { throw new RuntimeException( 'Guide capture could not be saved.' ); }
					} finally { $this->release_campaign_lock( '__privacy__' ); }
				} finally { $this->release_campaign_lock( $id ); }
				$result = $this->confirm_campaign( $payload, [ $recipient ] );
				if ( is_wp_error( $result ) ) { $this->automation_event_error( $order_id ); }
			}
		} catch ( Throwable $error ) { $this->automation_event_error( $order_id ); }
	}
	private function automation_event_error( $order_id ) {
		if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( 'Completed-order guide capture could not finish for order #' . absint( $order_id ) . '. Check campaign recovery and storage before manually changing the order status.', [ 'source' => self::LOG_SOURCE ] ); }
	}
	/** Existing manifests remain the sole captured-event authority, including a crash before the history record. */
	private function register_automation_manifests() {
		foreach ( $this->read_durable_options( 'yoohw_an_manifest_' ) as $manifest ) {
			$p = $manifest['payload'] ?? []; $id = $p['campaign_id'] ?? '';
			if ( empty( $p['automation'] ) || ! $id || ! $this->acquire_campaign_lock( $id ) ) { continue; }
			try {
				if ( $this->read_durable_option( $this->campaign_record_key( $id ) ) ) { continue; }
				if ( ! $this->record_campaign_snapshot( $p, 'preparing', [ 'recipient_count' => count( $manifest['recipients'] ?? [] ) ] ) ) { throw new RuntimeException( 'Automation history could not be recovered.' ); }
			} finally { $this->release_campaign_lock( $id ); }
		}
	}
	private function reconcile_automation_campaigns() {
		$this->register_automation_manifests();
		foreach ( $this->get_campaign_history() as $campaign ) {
			$a = $campaign['automation'] ?? [];
			if ( ! $a || in_array( $campaign['status'] ?? '', [ 'completed', 'cancelled', 'empty', 'test_sent', 'test_failed', 'previewed' ], true ) || ! empty( $campaign['retained'] ) ) { continue; }
			$rule = $this->read_durable_option( $this->automation_key( $a['rule_id'] ) );
			if ( ! empty( $rule['enabled'] ) && ( $rule['generation'] ?? '' ) === $a['generation'] ) { continue; }
			$id = $campaign['campaign_id'];
			$this->save_deactivation_gate( $id );
			if ( ! $this->acquire_campaign_lock( $id, 2 ) ) { throw new RuntimeException( 'Automation preparation is busy; cancellation gate remains saved.' ); }
			try {
				if ( ! $this->cancel_unstarted_recipients( $id ) ) { throw new RuntimeException( 'Automation cancellation needs cleanup.' ); }
				$campaign = $this->read_durable_option( $this->campaign_record_key( $id ) ) ?: $campaign;
				$campaign['cancelled'] = true; $campaign['status'] = 'cancelled';
				if ( ! $this->save_campaign_record( $campaign ) ) { throw new RuntimeException( 'Automation cancellation history could not be saved.' ); }
			} finally { $this->release_campaign_lock( $id ); }
		}
	}
	private function automation_delivery_check( $args ) {
		$a = $args['automation'] ?? [];
		if ( ! $a ) { return true; }
		try {
			$rule = $this->read_durable_option( $this->automation_key( $a['rule_id'] ?? '' ) );
			if ( empty( $rule['enabled'] ) || empty( $a['generation'] ) || ( $rule['generation'] ?? '' ) !== $a['generation'] ) { return new WP_Error( 'automation_cancelled', __( 'Guide rule disabled or this pending email belongs to an earlier enabled generation.', 'yoohw-advanced-notifications' ) ); }
			$order = wc_get_order( absint( $a['order_id'] ?? 0 ) );
			if ( ! $order instanceof WC_Order || strtolower( sanitize_email( $order->get_billing_email() ) ) !== strtolower( $args['to'] ?? '' ) || ! $this->automation_order_matches( $order, $a ) ) { return new WP_Error( 'automation_ineligible', __( 'The triggering order is no longer Completed, has been fully refunded, changed billing email or no longer matches its saved product/category conditions.', 'yoohw-advanced-notifications' ) ); }
			return true;
		} catch ( Throwable $error ) { return new WP_Error( 'automation_storage', __( 'The triggering order or rule could not be checked. No guide was handed off.', 'yoohw-advanced-notifications' ) ); }
	}
	private function automation_preview( $data, $order_id ) {
		$rule = $this->normalize_automation_rule( $data ); if ( is_wp_error( $rule ) ) { return $rule; }
		if ( ( ! is_int( $order_id ) && ! is_string( $order_id ) ) || ! ctype_digit( (string) $order_id ) || (int) $order_id < 1 ) { return new WP_Error( 'automation_sample', __( 'Choose a valid sample order ID.', 'yoohw-advanced-notifications' ) ); }
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || ! $this->contextual_can_edit_order( $order->get_id() ) ) { return new WP_Error( 'automation_sample', __( 'Choose an available order that you can access for preview.', 'yoohw-advanced-notifications' ) ); }
		if ( $this->read_durable_option( 'yoohw_an_erased_' . hash( 'sha256', strtolower( sanitize_email( $order->get_billing_email() ) ) ) ) ) { return new WP_Error( 'automation_sample_erased', __( 'This sample is protected by erasure. Its previous personal context cannot be previewed or tested.', 'yoohw-advanced-notifications' ) ); }
		$recipient = $this->automation_recipient( $order );
		$p = array_merge( $rule, [ 'personalization_version' => 1, 'store_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'products_label' => $rule['product_ids'] ? $this->get_selected_products_label( $rule['product_ids'] ) : __( 'Completed-order guide', 'yoohw-advanced-notifications' ) ] );
		$email = $this->render_personalized_email( $p, $recipient );
		$reason = ! $this->automation_order_matches( $order, $rule ) ? __( 'This sample order does not currently match the rule. Preview only; it will not trigger a guide in this state.', 'yoohw-advanced-notifications' ) : ( 'subscribed' !== $this->subscription_record( $recipient['email'] )['status'] ? __( 'This sample has no active subscription. A real guide would be skipped unless subscribed at send time.', 'yoohw-advanced-notifications' ) : __( 'This sample matches now. Enabling never sends to historical orders; only a new Completed event can trigger a guide.', 'yoohw-advanced-notifications' ) );
		return [ 'rule' => $rule, 'recipient' => $recipient, 'email' => $email, 'html' => $this->build_woocommerce_email_body( $email['heading'], $this->append_unsubscribe_footer( $email['message'], $recipient['email'] ) ), 'reason' => $reason ];
	}
	private function automation_test( $data, $order_id, $test_email ) {
		if ( ! is_string( $test_email ) || ! is_email( $test_email ) ) { return new WP_Error( 'automation_test_email', __( 'Enter a valid test email address.', 'yoohw-advanced-notifications' ) ); }
		$preview = $this->automation_preview( $data, $order_id ); if ( is_wp_error( $preview ) ) { return $preview; }
		return $this->send_woocommerce_email( $test_email, '[TEST] ' . $preview['email']['subject'], $preview['email']['heading'], $this->append_unsubscribe_footer( $preview['email']['message'], $test_email ), $this->get_sender_email(), $this->get_sender_name(), $this->get_reply_to_email(), false, [], hash( 'sha256', $preview['recipient']['email'] ) );
	}
}
