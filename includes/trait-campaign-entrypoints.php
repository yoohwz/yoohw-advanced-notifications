<?php
/** Open the existing composer from a specific product or exact set of orders. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait YOOHW_AN_Campaign_Entrypoints {
	private function register_contextual_entrypoints() {
		add_filter( 'post_row_actions', [ $this, 'contextual_product_row_action' ], 10, 2 );
		add_action( 'post_submitbox_misc_actions', [ $this, 'contextual_product_edit_action' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'contextual_variation_action' ], 10, 3 );
		add_action( 'woocommerce_order_actions_end', [ $this, 'contextual_order_edit_action' ] );
		add_action( 'admin_post_yoohw_an_contextual_draft', [ $this, 'handle_contextual_draft' ] );
		foreach ( [ 'edit-shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
			add_filter( 'bulk_actions-' . $screen, [ $this, 'contextual_order_bulk_action' ] );
			add_filter( 'handle_bulk_actions-' . $screen, [ $this, 'handle_contextual_order_bulk_action' ], 10, 3 );
		}
	}

	private function contextual_draft_url( $kind, $id ) {
		return wp_nonce_url( add_query_arg( [ 'action' => 'yoohw_an_contextual_draft', 'kind' => $kind, 'object_id' => $id ], admin_url( 'admin-post.php' ) ), 'yoohw_an_contextual_draft_' . $kind . '_' . $id );
	}

	public function contextual_product_row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['yoohw_an_draft'] = '<a href="' . esc_url( $this->contextual_draft_url( 'product', $post->ID ) ) . '">' . esc_html__( 'Email purchasers (draft)', 'yoohw-advanced-notifications' ) . '</a>';
		}
		return $actions;
	}

	public function contextual_product_edit_action() {
		global $post;
		if ( $post instanceof WP_Post && 'product' === $post->post_type && current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_post', $post->ID ) ) {
			echo '<div class="misc-pub-section"><a href="' . esc_url( $this->contextual_draft_url( 'product', $post->ID ) ) . '">' . esc_html__( 'Email purchasers (draft)', 'yoohw-advanced-notifications' ) . '</a><p class="description">' . esc_html__( 'A parent product includes all its variations. Opening a draft sends no email.', 'yoohw-advanced-notifications' ) . '</p></div>';
		}
	}

	public function contextual_variation_action( $loop, $variation_data, $variation ) {
		if ( current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_post', $variation->ID ) ) {
			echo '<p class="form-row form-row-full"><a href="' . esc_url( $this->contextual_draft_url( 'product', $variation->ID ) ) . '">' . esc_html__( 'Email purchasers of this variation (draft)', 'yoohw-advanced-notifications' ) . '</a></p>';
		}
	}

	public function contextual_order_edit_action( $order_id ) {
		if ( current_user_can( 'manage_woocommerce' ) && $this->contextual_can_edit_order( $order_id ) ) {
			echo '<li class="wide"><a href="' . esc_url( $this->contextual_draft_url( 'order', $order_id ) ) . '">' . esc_html__( 'Email this customer (draft)', 'yoohw-advanced-notifications' ) . '</a></li>';
		}
	}

	public function contextual_order_bulk_action( $actions ) {
		if ( current_user_can( 'manage_woocommerce' ) ) { $actions['yoohw_an_draft'] = __( 'Email selected orders (draft)', 'yoohw-advanced-notifications' ); }
		return $actions;
	}

	private function contextual_can_edit_order( $id ) {
		// HPOS does not require an editable order post; use its Orders screen capability.
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { return current_user_can( 'edit_others_shop_orders' ); }
		return current_user_can( 'edit_shop_order', $id );
	}

	/** Validate every selected object before saving anything; never widen a broken selection. */
	private function contextual_orders_draft( $ids ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) ); }
		if ( ! is_array( $ids ) || ! $ids ) { wp_die( esc_html__( 'Select at least one available order.', 'yoohw-advanced-notifications' ) ); }
		$order_ids = [];
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || ! (int) $id || ! $this->contextual_can_edit_order( (int) $id ) ) { wp_die( esc_html__( 'A selected order is invalid or unavailable.', 'yoohw-advanced-notifications' ) ); }
			$order = wc_get_order( (int) $id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || in_array( $order->get_status(), [ 'trash', 'auto-draft' ], true ) ) { wp_die( esc_html__( 'A selected order is invalid or unavailable.', 'yoohw-advanced-notifications' ) ); }
			$order_ids[] = (int) $id;
		}
		$order_ids = array_values( array_unique( $order_ids ) );
		sort( $order_ids, SORT_NUMERIC );
		return $this->save_draft( [ 'internal_name' => __( 'Selected orders', 'yoohw-advanced-notifications' ), 'recipient_source' => 'selected_orders', 'order_ids' => $order_ids, 'product_ids' => [], 'schedule_mode' => 'now', 'filters' => [] ] );
	}

	public function handle_contextual_order_bulk_action( $redirect_to, $action, $ids ) {
		if ( 'yoohw_an_draft' !== $action ) { return $redirect_to; }
		check_admin_referer( 'handle_bulk_actions-woocommerce_page_wc-orders' === current_filter() ? 'bulk-orders' : 'bulk-posts' );
		$draft = $this->contextual_orders_draft( $ids );
		return $this->get_admin_page_url( [ 'draft_id' => $draft['draft_id'] ] );
	}

	public function handle_contextual_draft() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) ); }
		$kind = isset( $_GET['kind'] ) && is_string( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$id = isset( $_GET['object_id'] ) && is_string( $_GET['object_id'] ) && ctype_digit( $_GET['object_id'] ) ? (int) $_GET['object_id'] : 0;
		check_admin_referer( 'yoohw_an_contextual_draft_' . $kind . '_' . $id );
		if ( 'order' === $kind ) {
			$draft = $this->contextual_orders_draft( [ $id ] );
		} elseif ( 'product' === $kind && $id && current_user_can( 'edit_post', $id ) ) {
			$product = wc_get_product( $id );
			if ( ! $product || in_array( $product->get_status(), [ 'trash', 'auto-draft' ], true ) ) { wp_die( esc_html__( 'A selected product is invalid or unavailable.', 'yoohw-advanced-notifications' ) ); }
			$draft = $this->save_draft( [ 'internal_name' => $product->get_name(), 'recipient_source' => self::SOURCE_PRODUCT_CUSTOMERS, 'product_ids' => [ $id ], 'schedule_mode' => 'now', 'filters' => [] ] );
		} else {
			wp_die( esc_html__( 'Invalid draft source.', 'yoohw-advanced-notifications' ) );
		}
		wp_safe_redirect( $this->get_admin_page_url( [ 'draft_id' => $draft['draft_id'] ] ) );
		exit;
	}
}
