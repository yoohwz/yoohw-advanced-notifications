<?php
/** Context shared by drafts, recipient previews and immutable deliveries. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Personalization {
	private function parse_selected_order_ids( $raw ) {
		$invalid = new WP_Error( 'invalid_orders', __( 'Choose valid orders. No order selection has been ignored.', 'yoohw-advanced-notifications' ) );
		if ( ! is_array( $raw ) && ! is_string( $raw ) ) { return $invalid; }
		$parts = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $parts as $id ) { if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return $invalid; } }
		$ids = array_values( array_unique( array_map( 'intval', $parts ) ) ); sort( $ids, SORT_NUMERIC ); return $ids;
	}
	private function validate_selected_orders( $p ) {
		$ids = $this->parse_selected_order_ids( $p['order_ids'] ?? [] );
		if ( is_wp_error( $ids ) ) { return $ids; }
		if ( self::SOURCE_SELECTED_ORDERS !== ( $p['recipient_source'] ?? '' ) ) {
			return $ids ? new WP_Error( 'incompatible_orders', __( 'Clear selected orders or choose Selected orders before previewing.', 'yoohw-advanced-notifications' ) ) : true;
		}
		if ( ! $ids ) { return new WP_Error( 'missing_orders', __( 'Select at least one order.', 'yoohw-advanced-notifications' ) ); }
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || in_array( $order->get_status(), [ 'trash', 'auto-draft' ], true ) || ! $this->contextual_can_edit_order( $id ) ) { return new WP_Error( 'unavailable_order', __( 'A selected order is unavailable or cannot be accessed. Review the selection.', 'yoohw-advanced-notifications' ) ); }
		}
		return true;
	}
	private function render_selected_orders_field( $p ) {
		echo '<section class="yoohw-advanced-notifications-panel an-selected-orders"><label class="an-field">' . esc_html__( 'Selected order IDs (exact scope)', 'yoohw-advanced-notifications' ) . '<input name="order_ids" value="' . esc_attr( implode( ', ', (array) ( $p['order_ids'] ?? [] ) ) ) . '"></label><p class="description">' . esc_html__( 'Used only with Selected orders. These orders do not expand into other purchases. All selected order statuses are included unless filtered below; invalid emails and suppression are excluded. Product customers includes purchases of the selected parent and its variations; a selected variation matches only that variation.', 'yoohw-advanced-notifications' ) . '</p></section>';
	}
	private function context_from_order( $order ) {
		$names = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) { $names[] = $item->get_name(); }
		$user = $order->get_user_id() ? get_userdata( $order->get_user_id() ) : false;
		// No order keys or guest access tokens are saved. WooCommerce checks ownership after login.
		$url = $user && strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) ? $order->get_view_order_url() : '';
		return $this->sanitize_order_context( [ 'number' => $order->get_order_number(), 'products' => implode( ', ', array_unique( $names ) ), 'url' => $url, 'count' => 1 ] );
	}
	private function sanitize_order_context( $context ) {
		if ( ! is_array( $context ) || ! $context ) { return []; }
		return [ 'number' => sanitize_text_field( $context['number'] ?? '' ), 'products' => sanitize_text_field( $context['products'] ?? '' ), 'url' => esc_url_raw( $context['url'] ?? '' ), 'count' => absint( $context['count'] ?? 0 ) ];
	}
	private function render_personalized_email( $p, $recipient ) {
		// Existing confirmed content keeps its original subject/heading and body semantics.
		if ( empty( $p['personalization_version'] ) ) {
			return [ 'subject' => $p['subject'], 'heading' => $p['heading'], 'message' => str_replace( [ '{customer_name}', '{product_name}' ], [ ( $recipient['name'] ?? '' ) ?: ( $recipient['email'] ?? '' ), $p['products_label'] ?? '' ], $p['message'] ) ];
		}
		$c = $this->sanitize_order_context( $recipient['order_context'] ?? [] );
		$name = trim( $recipient['name'] ?? '' );
		if ( ! $name || strtolower( $name ) === strtolower( $recipient['email'] ?? '' ) ) { $name = __( 'Customer', 'yoohw-advanced-notifications' ); }
		$values = [ '{customer_name}' => $name, '{store_name}' => $p['store_name'] ?? wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), '{product_name}' => $p['products_label'] ?? '', '{order_number}' => $c['number'] ?? '', '{ordered_products}' => $c['products'] ?? '', '{order_url}' => $c['url'] ?? '' ];
		$result = [];
		foreach ( [ 'subject', 'heading', 'message' ] as $field ) {
			// Escape replacement values, including quotes in href attributes. Never re-expand replacement text.
			$replacements = 'message' === $field ? array_map( 'esc_attr', $values ) : array_map( 'sanitize_text_field', $values );
			$result[ $field ] = strtr( $p[ $field ] ?? '', $replacements );
		}
		return $result;
	}
	private function render_order_context_summary( $recipient ) {
		$c = $this->sanitize_order_context( $recipient['order_context'] ?? [] );
		if ( ! $c ) { return; }
		echo '<p><strong>' . esc_html__( 'Order context: highest matching order ID', 'yoohw-advanced-notifications' ) . '</strong>: ' . esc_html( $c['number'] ) . ' · ' . esc_html__( 'Matching orders', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $c['count'] ) . '</p>';
	}
}
