<?php
/** Checkout intent integration. Run only on a disposable reliability database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
require_once dirname( __DIR__ ) . '/includes/trait-campaign-checkout.php';
class YOOHW_AN_Checkout_Subscription_Fixture {
	use YOOHW_AN_Campaign_Checkout;
	public $requests = [];
	public $fail = false;
	public function register() { $this->register_subscription_checkout(); }
	private function request_subscription( $email, $source ) {
		$this->requests[] = [ 'email' => $email, 'source' => $source ];
		return $this->fail ? new WP_Error( 'fixture_failure', 'Storage unavailable.' ) : true;
	}
}
(function () {
	global $wp_filter;
	$checks = [];
	$assert = static function ( $name, $passed ) use ( &$checks ) { $checks[ $name ] = (bool) $passed; if ( ! $passed ) { throw new RuntimeException( 'FAILED: ' . $name ); } };
	$original_post = $_POST;
	$hooks = [ 'woocommerce_after_order_notes', 'woocommerce_checkout_order_processed', 'woocommerce_init', 'woocommerce_store_api_checkout_order_processed' ];
	$original_hooks = [];
	foreach ( $hooks as $hook ) { $original_hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null; remove_all_actions( $hook ); }
	$fixture = new YOOHW_AN_Checkout_Subscription_Fixture();
	$email = 'checkout-intent-' . strtolower( wp_generate_password( 8, false ) ) . '@example.com';
	$order = null;
	try {
		$fixture->register();
		$assert( 'classic_and_blocks_checkout_hooks_registered', 20 === has_action( 'woocommerce_checkout_order_processed', [ $fixture, 'request_classic_checkout_subscription' ] ) && 20 === has_action( 'woocommerce_store_api_checkout_order_processed', [ $fixture, 'request_block_checkout_subscription' ] ) );
		ob_start(); do_action( 'woocommerce_after_order_notes', WC()->checkout() ); $html = ob_get_clean();
		$assert( 'classic_checkbox_is_optional_and_unchecked', str_contains( $html, 'name="yoohw_an_subscribe"' ) && ! str_contains( $html, 'checked="checked"' ) && ! str_contains( $html, 'required="required"' ) );
		$assert( 'classic_copy_requires_email_confirmation', str_contains( $html, 'confirm my email address before subscribing' ) );
		$order = wc_create_order(); $order->set_billing_email( $email ); $order->save();
		$_POST = [];
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );
		$assert( 'classic_without_checkbox_does_not_request_subscription', [] === $fixture->requests );
		foreach ( [ '0', 'yes', [ '1' ], 'true', '' ] as $value ) {
			$_POST['yoohw_an_subscribe'] = $value;
			do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );
		}
		$assert( 'classic_only_accepts_explicit_scalar_checkbox_one', [] === $fixture->requests );
		$_POST['yoohw_an_subscribe'] = '1';
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );
		$assert( 'classic_selected_intent_starts_confirmation_for_order_email', [ [ 'email' => $email, 'source' => 'checkout_classic' ] ] === $fixture->requests );
		$assert( 'classic_does_not_save_optin_on_order_or_customer', '' === $order->get_meta( '_wc_other/yoohw-an/subscribe', true ) && ! get_option( 'yoohw_an_subscription_' . hash( 'sha256', $email ) ) );
		$fixture->requests = [];
		$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
		if ( ! isset( $fields->get_additional_fields()['yoohw-an/subscribe'] ) ) { $fixture->register_block_subscription_field(); }
		$field = $fields->get_additional_fields()['yoohw-an/subscribe'] ?? [];
		$assert( 'blocks_registers_optional_order_checkbox', 'checkbox' === ( $field['type'] ?? '' ) && 'order' === ( $field['location'] ?? '' ) && false === ( $field['required'] ?? true ) );
		$assert( 'blocks_field_is_not_customer_profile_data', ! $fields->is_customer_field( 'yoohw-an/subscribe' ) );
		$assert( 'blocks_default_has_no_optin', ! $fields->get_field_from_object( 'yoohw-an/subscribe', $order, 'other' ) );
		$schema = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\SchemaController::class )->get( 'checkout' );
		$schema_fields = $schema->get_properties()['additional_fields']['properties'] ?? [];
		$assert( 'store_api_exposes_boolean_optional_field', 'boolean' === ( $schema_fields['yoohw-an/subscribe']['type'] ?? '' ) && false === ( $schema_fields['yoohw-an/subscribe']['required'] ?? true ) );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$assert( 'blocks_unselected_order_does_not_request_subscription', [] === $fixture->requests );
		$fields->persist_field_for_order( 'yoohw-an/subscribe', false, $order, 'other', false ); $order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$assert( 'blocks_false_intent_never_starts_confirmation', [] === $fixture->requests );
		$fields->persist_field_for_order( 'yoohw-an/subscribe', true, $order, 'other', false ); $order->save();
		$order = wc_get_order( $order->get_id() );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$assert( 'blocks_true_persisted_intent_starts_confirmation', [ [ 'email' => $email, 'source' => 'checkout_blocks' ] ] === $fixture->requests );
		$assert( 'blocks_order_intent_is_not_subscription_evidence', ! get_option( 'yoohw_an_subscription_' . hash( 'sha256', $email ) ) );
		$fixture->requests = [];
		$order->update_meta_data( '_wc_other/yoohw-an/subscribe', 'yes' );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$assert( 'blocks_invalid_raw_intent_is_not_truthy_optin', [] === $fixture->requests );
		$fixture->fail = true; $order->update_meta_data( '_wc_other/yoohw-an/subscribe', '1' );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$assert( 'subscription_request_failure_does_not_fail_order_checkout', 1 === count( $fixture->requests ) );
		$fixture->requests = [];
		$fixture->request_block_checkout_subscription( null );
		$fixture->request_classic_checkout_subscription( 0, [], null );
		$assert( 'missing_order_cannot_request_subscription', [] === $fixture->requests );
	} finally {
		if ( $order ) { $order->delete( true ); }
		$_POST = $original_post;
		foreach ( $hooks as $hook ) { if ( null === $original_hooks[ $hook ] ) { unset( $wp_filter[ $hook ] ); } else { $wp_filter[ $hook ] = $original_hooks[ $hook ]; } }
	}
	echo wp_json_encode( [ 'passed' => true, 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
})();
