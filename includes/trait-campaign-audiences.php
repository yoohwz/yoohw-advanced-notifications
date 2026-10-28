<?php
/** Source-aware audience criteria. Order conditions always apply to the same order. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Audiences {
	private function filters_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authoring callers verify the send nonce.
		$raw = wp_unslash( $_POST['audience_filters'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each scalar/list is type checked, sanitized below and strictly validated by validate_audience_filters().
		$filters = [];
		$scalar_keys = [ 'date_from', 'date_to', 'last_days', 'country', 'subscription', 'history_campaign', 'history_mode' ];
		$list_keys = [ 'category_ids', 'order_statuses' ];
		if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), array_merge( $scalar_keys, $list_keys ) ) ) { return [ 'invalid_input' => true ]; }
		foreach ( $scalar_keys as $key ) { if ( isset( $raw[ $key ] ) && ! is_string( $raw[ $key ] ) ) { return [ 'invalid_input' => true ]; } }
		foreach ( $list_keys as $key ) { if ( isset( $raw[ $key ] ) && ( ! is_array( $raw[ $key ] ) || array_filter( $raw[ $key ], static function ( $value ) { return ! is_string( $value ); } ) ) ) { return [ 'invalid_input' => true ]; } }
		foreach ( [ 'date_from', 'date_to', 'last_days', 'country', 'subscription', 'history_campaign', 'history_mode' ] as $key ) { $filters[ $key ] = sanitize_text_field( $raw[ $key ] ?? '' ); }
		foreach ( [ 'category_ids', 'order_statuses' ] as $key ) { $filters[ $key ] = array_map( 'sanitize_text_field', (array) ( $raw[ $key ] ?? [] ) ); }
		return $filters;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
	private function validate_audience_filters( $p ) {
		$f = $p['filters'] ?? [];
		$invalid = new WP_Error( 'invalid_filters', __( 'Invalid or inapplicable audience conditions. Review source, dates, statuses, category, country and campaign history; no condition has been ignored.', 'yoohw-advanced-notifications' ) );
		if ( ! is_array( $f ) || array_diff( array_keys( $f ), [ 'date_from', 'date_to', 'last_days', 'country', 'subscription', 'history_campaign', 'history_mode', 'category_ids', 'order_statuses' ] ) ) { return $invalid; }
		foreach ( [ 'category_ids', 'order_statuses' ] as $key ) { if ( isset( $f[ $key ] ) && ! is_array( $f[ $key ] ) ) { return $invalid; } }
		$source = $p['recipient_source'] ?? '';
		$order_source = in_array( $source, [ self::SOURCE_PRODUCT_CUSTOMERS, self::SOURCE_WOOCOMMERCE_CUSTOMERS ], true );
		foreach ( [ 'date_from', 'date_to', 'last_days', 'category_ids', 'order_statuses' ] as $field ) { if ( ! $order_source && ! empty( $f[ $field ] ) ) { return $invalid; } }
		if ( self::SOURCE_MANUAL_EMAILS === $source && ! empty( $f['country'] ) ) { return $invalid; }
		foreach ( [ 'date_from', 'date_to' ] as $field ) {
			if ( isset( $f[ $field ] ) && '' !== $f[ $field ] ) {
				$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $f[ $field ], wp_timezone() );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $f[ $field ] ) { return $invalid; }
			}
		}
		if ( isset( $f['last_days'] ) && '' !== $f['last_days'] && ( ! ctype_digit( (string) $f['last_days'] ) || (int) $f['last_days'] < 1 || (int) $f['last_days'] > 36500 || ! empty( $f['date_from'] ) || ! empty( $f['date_to'] ) ) ) { return $invalid; }
		if ( ! empty( $f['date_from'] ) && ! empty( $f['date_to'] ) && $f['date_from'] > $f['date_to'] ) { return $invalid; }
		if ( ! empty( $f['country'] ) && ! isset( WC()->countries->get_countries()[ $f['country'] ] ) ) { return $invalid; }
		foreach ( (array) ( $f['order_statuses'] ?? [] ) as $status ) { if ( ! isset( $this->get_order_status_options()[ $status ] ) ) { return $invalid; } }
		foreach ( (array) ( $f['category_ids'] ?? [] ) as $id ) { if ( ! ctype_digit( (string) $id ) || ! term_exists( (int) $id, 'product_cat' ) ) { return $invalid; } }
		if ( ! in_array( $f['subscription'] ?? '', [ '', 'subscribed', 'unsubscribed', 'unknown' ], true ) ) { return $invalid; }
		if ( ! in_array( $f['history_mode'] ?? '', [ '', 'handoff', 'not_handoff' ], true ) ) { return $invalid; }
		if ( ! empty( $f['history_mode'] ) ) {
			$history = $this->get_campaign_history();
			if ( empty( $f['history_campaign'] ) || empty( $history[ $f['history_campaign'] ] ) ) { return $invalid; }
		} elseif ( ! empty( $f['history_campaign'] ) ) { return $invalid; }
		foreach ( (array) ( $p['product_ids'] ?? [] ) as $id ) { if ( ! wc_get_product( $id ) ) { return $invalid; } }
		return true;
	}
	private function order_matches_filters( $order, $f ) {
		$date = $order->get_date_created();
		if ( ( ! empty( $f['date_from'] ) || ! empty( $f['date_to'] ) || ! empty( $f['last_days'] ) ) && ! $date ) { return false; }
		if ( ! empty( $f['date_from'] ) && $date->getTimestamp() < ( new DateTimeImmutable( $f['date_from'], wp_timezone() ) )->getTimestamp() ) { return false; }
		if ( ! empty( $f['date_to'] ) && $date->getTimestamp() >= ( new DateTimeImmutable( $f['date_to'], wp_timezone() ) )->modify( '+1 day' )->getTimestamp() ) { return false; }
		if ( ! empty( $f['last_days'] ) && ( $date->getTimestamp() < time() - (int) $f['last_days'] * DAY_IN_SECONDS || $date->getTimestamp() > time() ) ) { return false; }
		if ( ! empty( $f['country'] ) && $order->get_billing_country() !== $f['country'] ) { return false; }
		if ( ! empty( $f['category_ids'] ) ) {
			$categories = array_map( 'intval', $f['category_ids'] );
			foreach ( $f['category_ids'] as $id ) { $children = get_term_children( (int) $id, 'product_cat' ); if ( ! is_wp_error( $children ) ) { $categories = array_merge( $categories, array_map( 'intval', $children ) ); } }
			$matched = false;
			foreach ( $order->get_items() as $item ) {
				$terms = wp_get_post_terms( $item->get_product_id(), 'product_cat', [ 'fields' => 'ids' ] );
				if ( ! is_wp_error( $terms ) && array_intersect( $terms, $categories ) ) { $matched = true; break; }
			}
			if ( ! $matched ) { return false; }
		}
		return true;
	}
	private function subscription_record( $email ) {
		if ( $this->is_email_unsubscribed( $email ) ) { return [ 'status' => 'unsubscribed', 'source' => 'suppression' ]; }
		return $this->read_durable_option( 'yoohw_an_subscription_' . hash( 'sha256', strtolower( $email ) ) ) ?: [ 'status' => 'unknown', 'source' => '', 'recorded_at' => 0 ];
	}
	private function apply_audience_filters( $recipients, $p ) {
		if ( is_wp_error( $this->validate_audience_filters( $p ) ) ) { return []; }
		$f = $p['filters'] ?? [];
		$result = [];
		$history = [];
		if ( ! empty( $f['history_mode'] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Handoff filters use durable recipient outcomes, including minimal retained hashes.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email_hash,status FROM %i WHERE campaign_id = %s', $this->get_recipient_tracking_table_name(), $f['history_campaign'] ), ARRAY_A );
			foreach ( $rows as $row ) { $history[ $row['email_hash'] ][] = $row['status']; }
		}
		foreach ( $recipients as $recipient ) {
			$email = strtolower( $recipient['email'] );
			if ( ! empty( $f['subscription'] ) && $this->subscription_record( $email )['status'] !== $f['subscription'] ) { continue; }
			if ( self::SOURCE_REGISTERED_USERS === $p['recipient_source'] && ! empty( $f['country'] ) && get_user_meta( $recipient['user_id'], 'billing_country', true ) !== $f['country'] ) { continue; }
			if ( ! empty( $f['history_mode'] ) ) {
				$states = $history[ hash( 'sha256', $email ) ] ?? [];
				if ( 'handoff' === $f['history_mode'] && ! in_array( 'sent', $states, true ) ) { continue; }
				// Only explicit, known unsent terminal records qualify. Missing, pending and unknown never mean "not sent".
				if ( 'not_handoff' === $f['history_mode'] && ( ! $states || array_diff( $states, [ 'failed', 'queue_failed', 'schedule_failed', 'cancelled', 'skipped', 'skipped_unsubscribed' ] ) ) ) { continue; }
			}
			$result[] = $recipient;
		}
		return $result;
	}
	private function render_audience_fields( $p ) {
		$f = $p['filters'] ?? [];
		echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html__( 'Audience conditions', 'yoohw-advanced-notifications' ) . '</h2><div class="an-order-filters">';
		foreach ( [ 'date_from' => [ 'date', __( 'Order created from (store timezone)', 'yoohw-advanced-notifications' ) ], 'date_to' => [ 'date', __( 'Through date (inclusive)', 'yoohw-advanced-notifications' ) ], 'last_days' => [ 'number', __( 'Or last N × 24 hours', 'yoohw-advanced-notifications' ) ] ] as $key => $field ) { echo '<p><label>' . esc_html( $field[1] ) . ' <input type="' . esc_attr( $field[0] ) . '" name="audience_filters[' . esc_attr( $key ) . ']" value="' . esc_attr( $f[ $key ] ?? '' ) . '"></label></p>'; }
		echo '<label>' . esc_html__( 'Matching order statuses (default processing/completed)', 'yoohw-advanced-notifications' ) . '<select multiple name="audience_filters[order_statuses][]">';
		foreach ( $this->get_order_status_options() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( in_array( $key, $f['order_statuses'] ?? [], true ), true, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><p><label>' . esc_html__( 'Categories (includes all descendants)', 'yoohw-advanced-notifications' ) . '<select multiple name="audience_filters[category_ids][]">';
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) { echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( in_array( (string) $term->term_id, array_map( 'strval', $f['category_ids'] ?? [] ), true ), true, false ) . '>' . esc_html( $term->name ) . '</option>'; }
		echo '</select></label></p></div><div class="an-country-filter"><label>' . esc_html__( 'Billing country', 'yoohw-advanced-notifications' ) . ' <select name="audience_filters[country]"><option value="">—</option>';
		foreach ( function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [] as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $f['country'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label></div><p><label>' . esc_html__( 'Subscription status', 'yoohw-advanced-notifications' ) . ' <select name="audience_filters[subscription]">';
		foreach ( [ '' => __( 'Any (suppression still applies)', 'yoohw-advanced-notifications' ), 'subscribed' => __( 'Subscribed', 'yoohw-advanced-notifications' ), 'unknown' => __( 'No confirmation recorded', 'yoohw-advanced-notifications' ), 'unsubscribed' => __( 'Unsubscribed (excluded from sending)', 'yoohw-advanced-notifications' ) ] as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $f['subscription'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label></p><label>' . esc_html__( 'Campaign history', 'yoohw-advanced-notifications' ) . ' <select name="audience_filters[history_campaign]"><option value="">—</option>';
		foreach ( $this->get_campaign_history() as $campaign ) { echo '<option value="' . esc_attr( $campaign['campaign_id'] ) . '" ' . selected( $f['history_campaign'] ?? '', $campaign['campaign_id'], false ) . '>' . esc_html( $campaign['campaign_id'] . ' — ' . ( $campaign['subject'] ?? '' ) ) . '</option>'; }
		echo '</select><select name="audience_filters[history_mode]">';
		foreach ( [ '' => '—', 'handoff' => __( 'Handed to mail system', 'yoohw-advanced-notifications' ), 'not_handoff' => __( 'Known unsent terminal outcome', 'yoohw-advanced-notifications' ) ] as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $f['history_mode'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><p>' . esc_html__( 'Order filters use order creation time in the store timezone and match on one order, including product/variation, status and billing country. Categories include descendants and use current product categories. Registered users use profile billing country; manual lists have no country. Missing data fails the condition. History uses handoff only: unknown, pending and missing history never qualify as known unsent. Existing exclusions and durable opt-outs apply last.', 'yoohw-advanced-notifications' ) . ' ' . esc_html( wp_timezone_string() ) . '</p></section>';
	}
	private function render_audience_summary( $p ) {
		$f = array_filter( $p['filters'] ?? [], static function ( $value ) { return '' !== $value && [] !== $value; } );
		if ( ! $f ) { return; }
		$labels = [ 'date_from' => __( 'Order created from', 'yoohw-advanced-notifications' ), 'date_to' => __( 'Through date', 'yoohw-advanced-notifications' ), 'last_days' => __( 'Last N days', 'yoohw-advanced-notifications' ), 'country' => __( 'Billing country', 'yoohw-advanced-notifications' ), 'subscription' => __( 'Subscription status', 'yoohw-advanced-notifications' ), 'history_campaign' => __( 'History campaign', 'yoohw-advanced-notifications' ), 'history_mode' => __( 'Handoff condition', 'yoohw-advanced-notifications' ), 'category_ids' => __( 'Categories including descendants', 'yoohw-advanced-notifications' ), 'order_statuses' => __( 'Matching order statuses', 'yoohw-advanced-notifications' ) ];
		echo '<h3>' . esc_html__( 'Saved audience conditions', 'yoohw-advanced-notifications' ) . '</h3><table class="widefat striped">';
		foreach ( $f as $key => $value ) {
			$values = (array) $value;
			if ( 'order_statuses' === $key ) { $options = $this->get_order_status_options(); $values = array_map( static function ( $status ) use ( $options ) { return $options[ $status ] ?? $status; }, $values ); }
			if ( 'category_ids' === $key ) { $values = array_map( static function ( $id ) { $term = get_term( (int) $id, 'product_cat' ); return $term && ! is_wp_error( $term ) ? $term->name . ' (#' . $id . ')' : '#' . $id; }, $values ); }
			echo '<tr><th>' . esc_html( $labels[ $key ] ?? $key ) . '</th><td>' . esc_html( implode( ', ', $values ) ) . '</td></tr>';
		}
		echo '</table>';
	}

	private function render_audience_inspector( $p ) {
		$email = strtolower( sanitize_email( wp_unslash( $_GET['inspect_email'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only authorized preview.
		echo '<form method="get"><input type="hidden" name="page" value="yoohw-advanced-notifications"><input type="hidden" name="preview_token" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['preview_token'] ?? '' ) ) ) . '"><label>' . esc_html__( 'Inspect an address against current criteria', 'yoohw-advanced-notifications' ) . ' <input type="email" name="inspect_email" value="' . esc_attr( $email ) . '"></label><button class="button">' . esc_html__( 'Inspect', 'yoohw-advanced-notifications' ) . '</button></form>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_email( $email ) ) { return; }
		$base = $p; $base['filters'] = [];
		$baseline = $this->get_base_recipients_by_payload( $base );
		$filtered = $this->get_base_recipients_by_payload( $p );
		$filtered_common = $this->apply_audience_filters( $filtered, $p );
		$reason = __( 'Not in the selected source or product purchasers.', 'yoohw-advanced-notifications' );
		if ( in_array( $email, array_column( $baseline, 'email' ), true ) ) { $reason = __( 'No single order matches all order conditions, or billing country is missing/different.', 'yoohw-advanced-notifications' ); }
		if ( in_array( $email, array_column( $filtered, 'email' ), true ) ) { $reason = __( 'Subscription status or known campaign handoff condition does not match.', 'yoohw-advanced-notifications' ); }
		if ( in_array( $email, array_column( $filtered_common, 'email' ), true ) ) { $reason = __( 'Matched source and all conditions; removed by an explicit exclusion.', 'yoohw-advanced-notifications' ); }
		if ( in_array( $email, array_column( $this->get_recipients_by_payload( $p ), 'email' ), true ) ) { $reason = __( 'Included: matches source and every applicable condition; no exclusion matched.', 'yoohw-advanced-notifications' ); }
		if ( $this->is_email_unsubscribed( $email ) ) { $reason = __( 'Excluded: durable unsubscribe/suppression.', 'yoohw-advanced-notifications' ); }
		echo '<p role="status">' . esc_html( $email . ': ' . $reason ) . '</p>';
	}
}
