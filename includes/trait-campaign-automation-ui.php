<?php
/** Admin authoring for a single Completed-order email per order and rule. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Automation_UI {
	private function register_automation_ui() {
		add_action( 'admin_post_yoohw_an_automation', [ $this, 'handle_automation_action' ] );
	}
	private function automation_ui_preview_key() { return 'yoohw_an_automation_preview_' . get_current_user_id(); }
	private function automation_ui_request_data() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_automation_action() verifies the nonce before parsing.
		$data = wp_unslash( $_POST );
		return array_intersect_key( $data, array_flip( [ 'name', 'product_ids', 'category_ids', 'delay_hours', 'subject', 'heading', 'message' ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
	public function handle_automation_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ), '', [ 'response' => 403 ] ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Use the automation form.', 'yoohw-advanced-notifications' ), '', [ 'response' => 405 ] ); }
		check_admin_referer( 'yoohw_an_automation' );
		if ( isset( $_POST['automation_id'] ) && ! is_string( $_POST['automation_id'] ) ) { wp_die( esc_html__( 'Choose a valid automation rule.', 'yoohw-advanced-notifications' ), '', [ 'response' => 400 ] ); }
		$id = isset( $_POST['automation_id'] ) && is_string( $_POST['automation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['automation_id'] ) ) : '';
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$args = [ 'tab' => 'automations' ];
		if ( in_array( $operation, [ 'enable', 'disable' ], true ) ) {
			$result = $this->set_automation_enabled( $id, 'enable' === $operation );
			$args['automation_notice'] = 'enable' === $operation ? 'enabled' : 'disabled';
		} elseif ( 'save' === $operation ) {
			$result = $this->save_automation_rule( $this->automation_ui_request_data(), $id );
			$args['automation_notice'] = 'saved';
		} elseif ( in_array( $operation, [ 'preview', 'test' ], true ) ) {
			$data = $this->automation_ui_request_data();
			$order_id = isset( $_POST['sample_order_id'] ) && is_string( $_POST['sample_order_id'] ) ? wp_unslash( $_POST['sample_order_id'] ) : '';
			if ( ! ctype_digit( $order_id ) || (int) $order_id < 1 ) { wp_die( esc_html__( 'Choose an available order ID for the sample.', 'yoohw-advanced-notifications' ), '', [ 'response' => 400 ] ); }
			$result = $this->automation_preview( $data, (int) $order_id );
			if ( ! is_wp_error( $result ) ) {
				$test_email = isset( $_POST['test_email'] ) && is_string( $_POST['test_email'] ) ? wp_unslash( $_POST['test_email'] ) : '';
				if ( 'test' === $operation ) {
					$sent = $this->automation_test( $data, (int) $order_id, $test_email );
					if ( is_wp_error( $sent ) || ! $sent ) { $result = is_wp_error( $sent ) ? $sent : new WP_Error( 'automation_test_failed', __( 'The test email could not be handed off. Check mail settings and try again.', 'yoohw-advanced-notifications' ) ); }
				}
				if ( ! is_wp_error( $result ) ) {
					$this->set_personal_transient( $this->automation_ui_preview_key(), [ 'rule' => $result['rule'], 'editing_id' => $id, 'sample_order_id' => (int) $order_id, 'test_email' => sanitize_email( $test_email ) ], 30 * MINUTE_IN_SECONDS );
					$args['automation_preview'] = 1;
					$args['automation_notice'] = 'test' === $operation ? 'tested' : 'previewed';
					if ( $id ) { $args['automation_id'] = $id; }
				}
			}
		} else { $result = new WP_Error( 'automation_operation', __( 'Choose a valid automation action.', 'yoohw-advanced-notifications' ) ); }
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 400 ] ); }
		if ( in_array( $operation, [ 'save', 'enable', 'disable' ], true ) ) {
			delete_transient( $this->automation_ui_preview_key() );
			$args['automation_id'] = $result['id'];
		}
		wp_safe_redirect( $this->get_admin_page_url( $args ) ); exit;
	}
	private function automation_ui_button( $rule, $operation, $label ) {
		echo '<form class="an-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_automation"><input type="hidden" name="automation_id" value="' . esc_attr( $rule['id'] ) . '">'; wp_nonce_field( 'yoohw_an_automation' );
		echo '<button class="button" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button></form>';
	}
	private function render_automations_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$rules = $this->automation_rules();
		$id = isset( $_GET['automation_id'] ) && is_string( $_GET['automation_id'] ) ? sanitize_text_field( wp_unslash( $_GET['automation_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor selection.
		$rule = $id ? ( $rules[ $id ] ?? null ) : [];
		echo '<div class="wrap yoohw-an-workspace"><h1>' . esc_html__( 'Email campaigns', 'yoohw-advanced-notifications' ) . '</h1>';
		$this->render_page_tabs( 'automations' );
		if ( null === $rule ) { echo '<p role="alert">' . esc_html__( 'This automation rule is unavailable.', 'yoohw-advanced-notifications' ) . '</p></div>'; return; }
		$notices = [ 'saved' => __( 'Rule saved. A new rule remains disabled until you choose Enable. Changes to enabled rules apply to new events only.', 'yoohw-advanced-notifications' ), 'enabled' => __( 'Rule enabled for new Completed events. Historical orders were not queued.', 'yoohw-advanced-notifications' ), 'disabled' => __( 'Rule disabled. New events stop and unstarted emails are cancelled. Work already in flight may finish.', 'yoohw-advanced-notifications' ), 'tested' => __( 'Test mail handed to the mail system. Inbox delivery is not verified; no rule was saved or enabled.', 'yoohw-advanced-notifications' ), 'previewed' => __( 'Preview only. No rule was saved or enabled and no delivery job was created.', 'yoohw-advanced-notifications' ) ];
		$notice = isset( $_GET['automation_notice'] ) && is_string( $_GET['automation_notice'] ) ? sanitize_key( wp_unslash( $_GET['automation_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice.
		if ( isset( $notices[ $notice ] ) ) { echo '<p role="status">' . esc_html( $notices[ $notice ] ) . '</p>'; }
		$preview = ! empty( $_GET['automation_preview'] ) ? get_transient( $this->automation_ui_preview_key() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only user-owned preview.
		$preview_result = null;
		if ( is_array( $preview ) && ( $preview['editing_id'] ?? '' ) === $id && ! empty( $preview['rule'] ) ) {
			$rule = $preview['rule'];
			// Regenerate personal sample data after checking current erasure protection; never cache rendered PII.
			$preview_result = $this->automation_preview( $rule, (int) $preview['sample_order_id'] );
		} else { $preview = false; }
		echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html__( 'Completed-order guides', 'yoohw-advanced-notifications' ) . '</h2><p>' . esc_html__( 'One event, one delay and one email per order and rule. A customer with several qualifying orders can receive one guide for each order. Only new Completed events after Enable are captured; there is no historical backfill.', 'yoohw-advanced-notifications' ) . '</p><p>' . esc_html__( 'An active subscription is required at sending. Unknown is not consent. Unsubscribe and erasure protection always apply. Orders must still be Completed and match the saved conditions; cancelled, failed and fully refunded orders are skipped. Partial refunds remain eligible while an amount remains. Started work may finish when a rule is disabled.', 'yoohw-advanced-notifications' ) . '</p>';
		if ( ! $rules ) { echo '<p>' . esc_html__( 'No saved automation rules yet.', 'yoohw-advanced-notifications' ) . '</p>'; }
		foreach ( $rules as $saved ) {
			echo '<div class="an-library-item"><strong>' . esc_html( $saved['name'] ) . '</strong> — ' . esc_html( ! empty( $saved['enabled'] ) ? __( 'Enabled', 'yoohw-advanced-notifications' ) : __( 'Disabled', 'yoohw-advanced-notifications' ) ) . '<p>' . esc_html__( 'Delay after Completed (hours)', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $saved['delay_hours'] ) . '<br>' . esc_html( $saved['subject'] ) . '</p><a class="button" href="' . esc_url( $this->get_admin_page_url( [ 'tab' => 'automations', 'automation_id' => $saved['id'] ] ) ) . '">' . esc_html__( 'Edit rule', 'yoohw-advanced-notifications' ) . '</a> ';
			echo '<details><summary>' . esc_html__( 'Saved conditions and content', 'yoohw-advanced-notifications' ) . '</summary><p><strong>' . esc_html__( 'Products', 'yoohw-advanced-notifications' ) . ':</strong> ' . esc_html( empty( $saved['product_ids'] ) ? __( 'All products', 'yoohw-advanced-notifications' ) : $this->get_selected_products_label( $saved['product_ids'] ) ) . '<br><strong>' . esc_html__( 'Category IDs including descendants', 'yoohw-advanced-notifications' ) . ':</strong> ' . esc_html( empty( $saved['category_ids'] ) ? __( 'All categories', 'yoohw-advanced-notifications' ) : implode( ', ', $saved['category_ids'] ) ) . '<br><strong>' . esc_html__( 'Email heading', 'yoohw-advanced-notifications' ) . ':</strong> ' . esc_html( $saved['heading'] ) . '</p><p>' . esc_html( wp_strip_all_tags( $saved['message'] ) ) . '</p></details>';
			$this->automation_ui_button( $saved, ! empty( $saved['enabled'] ) ? 'disable' : 'enable', ! empty( $saved['enabled'] ) ? __( 'Disable and cancel waiting emails', 'yoohw-advanced-notifications' ) : __( 'Enable', 'yoohw-advanced-notifications' ) );
			echo '</div>';
		}
		echo '<p><a class="button" href="' . esc_url( $this->get_admin_page_url( [ 'tab' => 'automations' ] ) ) . '">' . esc_html__( 'New rule', 'yoohw-advanced-notifications' ) . '</a> <a href="' . esc_url( $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'logs' ] ) ) . '">' . esc_html__( 'Open campaign history', 'yoohw-advanced-notifications' ) . '</a> · <a href="' . esc_url( $this->get_admin_page_url( [ 'tab' => 'subscribers' ] ) ) . '">' . esc_html__( 'Look up contacts by email', 'yoohw-advanced-notifications' ) . '</a></p></section>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_automation"><input type="hidden" name="automation_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'yoohw_an_automation' );
		echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html( $id ? __( 'Edit completed-order guide', 'yoohw-advanced-notifications' ) : __( 'New completed-order guide', 'yoohw-advanced-notifications' ) ) . '</h2><p>' . esc_html__( 'Saving changes to an enabled rule affects new events only. Waiting emails keep their confirmed content, order context and due time. Preview and test use the form below without changing any saved rule.', 'yoohw-advanced-notifications' ) . '</p><div class="an-form-grid"><label class="an-field">' . esc_html__( 'Rule name', 'yoohw-advanced-notifications' ) . '<input name="name" required value="' . esc_attr( $rule['name'] ?? '' ) . '"></label><label class="an-field">' . esc_html__( 'Delay after Completed (hours)', 'yoohw-advanced-notifications' ) . '<input type="number" name="delay_hours" min="0" max="8760" required value="' . esc_attr( $rule['delay_hours'] ?? 24 ) . '"></label></div>';
		echo '<div class="an-form-grid"><label class="an-field">' . esc_html__( 'Products or variations (empty means any)', 'yoohw-advanced-notifications' ) . '<select class="wc-product-search" multiple name="product_ids[]" data-action="woocommerce_json_search_products_and_variations" data-width="100%">' . $this->get_selected_product_options_html( (array) ( $rule['product_ids'] ?? [] ) ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Existing product-option helper escapes IDs/names.
		echo '<label class="an-field">' . esc_html__( 'Categories including descendants (empty means any)', 'yoohw-advanced-notifications' ) . '<select multiple name="category_ids[]">';
		$categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ); $known = [];
		foreach ( is_wp_error( $categories ) ? [] : $categories as $category ) { $known[] = $category->term_id; echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( in_array( $category->term_id, array_map( 'intval', (array) ( $rule['category_ids'] ?? [] ) ), true ), true, false ) . '>' . esc_html( $category->name ) . '</option>'; }
		$this->render_unavailable_options( (array) ( $rule['category_ids'] ?? [] ), $known );
		echo '</select></label></div><p class="description">' . esc_html__( 'Product and category conditions must match the same order. Parent products include their variations; a selected variation matches only that variation.', 'yoohw-advanced-notifications' ) . '</p><label class="an-field">' . esc_html__( 'Email subject', 'yoohw-advanced-notifications' ) . '<input name="subject" required value="' . esc_attr( $rule['subject'] ?? __( 'A guide for order {order_number}', 'yoohw-advanced-notifications' ) ) . '"></label><label class="an-field">' . esc_html__( 'Email heading', 'yoohw-advanced-notifications' ) . '<input name="heading" required value="' . esc_attr( $rule['heading'] ?? __( 'Getting started with your purchase', 'yoohw-advanced-notifications' ) ) . '"></label><label for="an-automation-message">' . esc_html__( 'Guide content', 'yoohw-advanced-notifications' ) . '</label>';
		wp_editor( $rule['message'] ?? __( '<p>Hello {customer_name},</p><p>Thank you for purchasing {ordered_products} (order {order_number}). Add setup or care steps, when to follow them and a support contact here.</p>', 'yoohw-advanced-notifications' ), 'an-automation-message', [ 'textarea_name' => 'message', 'textarea_rows' => 10 ] );
		echo '<p>' . esc_html__( 'Variables in subject, heading and body: {customer_name}, {store_name}, {product_name}, {order_number}, {ordered_products}, {order_url}. Order values come from the exact triggering order and are frozen when its event is captured. Missing names use Customer. Guest order links are empty; account links require the owning account login.', 'yoohw-advanced-notifications' ) . '</p><button class="button button-primary" name="operation" value="save">' . esc_html( $id && ! empty( $rules[ $id ]['enabled'] ) ? __( 'Save changes for new events', 'yoohw-advanced-notifications' ) : __( 'Save rule', 'yoohw-advanced-notifications' ) ) . '</button><p>' . esc_html__( 'New rules are saved disabled. Enable the saved rule explicitly when its conditions, content and delay are ready.', 'yoohw-advanced-notifications' ) . '</p></section>';
		echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html__( 'Preview and test with an order', 'yoohw-advanced-notifications' ) . '</h2><div class="an-form-grid"><label class="an-field">' . esc_html__( 'Sample order ID', 'yoohw-advanced-notifications' ) . '<input type="number" name="sample_order_id" min="1" value="' . esc_attr( $preview['sample_order_id'] ?? '' ) . '"></label><label class="an-field">' . esc_html__( 'Test email address', 'yoohw-advanced-notifications' ) . '<input type="email" name="test_email" value="' . esc_attr( $preview['test_email'] ?? get_option( 'admin_email' ) ) . '"></label></div><button class="button" name="operation" value="preview">' . esc_html__( 'Preview guide', 'yoohw-advanced-notifications' ) . '</button> <button class="button" name="operation" value="test">' . esc_html__( 'Send test email', 'yoohw-advanced-notifications' ) . '</button><p>' . esc_html__( 'These actions create no campaign or delivery job. Test mail goes only to the test address entered above. A sample can be previewed even when its order is not currently eligible.', 'yoohw-advanced-notifications' ) . '</p></section></form>';
		if ( is_wp_error( $preview_result ) ) { echo '<p role="alert">' . esc_html( $preview_result->get_error_message() ) . '</p>'; }
		if ( $preview && ! is_wp_error( $preview_result ) ) {
			$p = $preview_result;
			echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html__( 'Personalized guide preview', 'yoohw-advanced-notifications' ) . '</h2><p>' . esc_html( $p['reason'] ?? '' ) . '</p><p><strong>' . esc_html__( 'Sample recipient', 'yoohw-advanced-notifications' ) . ':</strong> ' . esc_html( $p['recipient']['email'] ?? '' ) . '</p><p><strong>' . esc_html__( 'Subject', 'yoohw-advanced-notifications' ) . ':</strong> ' . esc_html( $p['email']['subject'] ?? '' ) . '</p><iframe class="an-personal-preview" sandbox="" title="' . esc_attr__( 'Personalized guide preview', 'yoohw-advanced-notifications' ) . '" srcdoc="' . esc_attr( $p['html'] ?? '' ) . '"></iframe></section>';
		}
		echo '</div>';
	}
}
