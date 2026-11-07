<?php
/** Optional checkout intent starts email ownership confirmation; an order is never opt-in proof. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Checkout {
	private function register_subscription_checkout() {
		add_action( 'woocommerce_after_order_notes', [ $this, 'render_classic_subscription_field' ] );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'request_classic_checkout_subscription' ], 20, 3 );
		add_action( 'woocommerce_init', [ $this, 'register_block_subscription_field' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'request_block_checkout_subscription' ], 20 );
	}

	public function render_classic_subscription_field() {
		woocommerce_form_field( 'yoohw_an_subscribe', [
			'type' => 'checkbox',
			'class' => [ 'form-row-wide' ],
			'label' => __( 'Email me store news and offers. I will confirm my email address before subscribing.', 'yoohw-advanced-notifications' ),
			'required' => false,
		], 0 );
	}

	public function register_block_subscription_field() {
		static $registered = false;
		if ( $registered || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) { return; }
		woocommerce_register_additional_checkout_field( [
			'id' => 'yoohw-an/subscribe',
			'label' => __( 'Email me store news and offers. I will confirm my email address before subscribing.', 'yoohw-advanced-notifications' ),
			'location' => 'order',
			'type' => 'checkbox',
			'required' => false,
		] );
		$registered = true;
		add_filter( 'woocommerce_get_default_value_for_yoohw-an/subscribe', '__return_false' );
	}

	public function request_classic_checkout_subscription( $order_id, $posted_data, $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce has verified its checkout nonce and validated the order before this hook.
		$intent = isset( $_POST['yoohw_an_subscribe'] ) && is_string( $_POST['yoohw_an_subscribe'] ) ? wp_unslash( $_POST['yoohw_an_subscribe'] ) : '';
		if ( '1' !== $intent || ! $order instanceof WC_Order ) { return; }
		$this->request_subscription( $order->get_billing_email(), 'checkout_classic' );
	}

	public function request_block_checkout_subscription( $order ) {
		if ( ! $order instanceof WC_Order ) { return; }
		// Store API validates registered additional fields and persists booleans as 1/0 before this hook.
		$intent = $order->get_meta( '_wc_other/yoohw-an/subscribe', true );
		if ( true !== $intent && '1' !== $intent && 1 !== $intent ) { return; }
		$this->request_subscription( $order->get_billing_email(), 'checkout_blocks' );
	}
}
