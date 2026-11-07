<?php
/** Purchase history is store-wide and keyed by the same normalized billing email as order audiences. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Purchase_History {
	/** Partial refunds remain purchases; a fully refunded or non-purchased order never counts. */
	private function is_valid_purchase_order( $order ) {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) { return false; }
		$refunded = (float) $order->get_total_refunded();
		return $refunded <= 0 || $refunded < (float) $order->get_total();
	}
	private function purchase_history_for_emails( $emails ) {
		$history = [];
		foreach ( $emails as $email ) {
			$email = strtolower( sanitize_email( $email ) );
			if ( is_email( $email ) ) { $history[ $email ] = [ 'count' => 0, 'last' => 0, 'undated' => false ]; }
		}
		if ( ! $history ) { return []; }
		global $wpdb;
		$page = 1;
		do {
			// Keep source/product/date/category criteria out of this query: only lifecycle criteria cross order history.
			$orders = wc_get_orders( [ 'type' => 'shop_order', 'status' => [ 'processing', 'completed' ], 'limit' => self::ORDER_QUERY_LIMIT, 'paged' => $page, 'return' => 'objects', 'orderby' => 'ID', 'order' => 'ASC' ] );
			if ( ! is_array( $orders ) || $wpdb->last_error ) { throw new RuntimeException( 'Purchase history could not be read.' ); }
			foreach ( $orders as $order ) {
				if ( ! $this->is_valid_purchase_order( $order ) ) { continue; }
				$email = strtolower( sanitize_email( $order->get_billing_email() ) );
				if ( ! isset( $history[ $email ] ) ) { continue; }
				$history[ $email ]['count']++;
				$date = $order->get_date_created();
				if ( ! $date ) { $history[ $email ]['undated'] = true; continue; }
				$history[ $email ]['last'] = max( $history[ $email ]['last'], $date->getTimestamp() );
			}
			$page++;
		} while ( count( $orders ) === self::ORDER_QUERY_LIMIT );
		return $history;
	}
	private function apply_purchase_history_filters( $recipients, $filters ) {
		$frequency = $filters['purchase_frequency'] ?? '';
		$inactive_days = $filters['inactive_days'] ?? '';
		if ( '' === $frequency && '' === $inactive_days ) { return $recipients; }
		$history = $this->purchase_history_for_emails( array_column( $recipients, 'email' ) );
		$cutoff = time() - (int) $inactive_days * DAY_IN_SECONDS;
		return array_values( array_filter( $recipients, static function ( $recipient ) use ( $history, $frequency, $inactive_days, $cutoff ) {
			$stats = $history[ strtolower( $recipient['email'] ) ] ?? [ 'count' => 0, 'last' => 0, 'undated' => false ];
			if ( $stats['undated'] ) { return false; }
			if ( 'first' === $frequency && 1 !== $stats['count'] ) { return false; }
			if ( 'repeat' === $frequency && $stats['count'] < 2 ) { return false; }
			if ( '' !== $inactive_days && ( ! $stats['count'] || ! $stats['last'] || $stats['last'] >= $cutoff ) ) { return false; }
			return true;
		} ) );
	}
	private function render_purchase_history_fields( $filters, $invalid ) {
		echo '<section class="yoohw-advanced-notifications-panel an-purchase-history-filters"><h2>' . esc_html__( 'Purchase history across the store', 'yoohw-advanced-notifications' ) . '</h2><div class="an-form-grid"><label class="an-field">' . esc_html__( 'Purchase frequency', 'yoohw-advanced-notifications' ) . '<select name="audience_filters[purchase_frequency]">';
		$options = [ '' => __( 'Any purchase history', 'yoohw-advanced-notifications' ), 'first' => __( 'First-time buyer (one valid order)', 'yoohw-advanced-notifications' ), 'repeat' => __( 'Repeat buyer (at least two valid orders)', 'yoohw-advanced-notifications' ) ];
		foreach ( $options as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $filters['purchase_frequency'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		$this->render_unavailable_options( [ $filters['purchase_frequency'] ?? '' ], array_keys( $options ) );
		echo '</select></label><label class="an-field">' . esc_html__( 'No repeat purchase in the last X days', 'yoohw-advanced-notifications' ) . '<input type="' . esc_attr( $invalid ? 'text' : 'number' ) . '" min="1" max="36500" name="audience_filters[inactive_days]" value="' . esc_attr( $filters['inactive_days'] ?? '' ) . '"></label></div><p>' . esc_html__( 'These conditions use all valid purchases across the entire store, including orders outside the dates and products selected below. Product/category conditions still match one order; they do not change this into repeat purchases of only that product.', 'yoohw-advanced-notifications' ) . '</p><details><summary>' . esc_html__( 'Which purchases count?', 'yoohw-advanced-notifications' ) . '</summary><p>' . esc_html__( 'Processing and Completed orders count by normalized billing email, including guest orders and orders before an account existed. Cancelled, Failed, Refunded and fully refunded orders do not count. Partial refunds count while an amount remains; free orders without refunds count. Inactivity uses the latest valid order creation time and X times 24 hours. A customer with no valid purchases never qualifies as inactive. Different emails are not merged.', 'yoohw-advanced-notifications' ) . '</p></details></section>';
	}
}
