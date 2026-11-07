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
		$notices = [ 'saved' => __( 'Rule saved. New rules stay disabled until you choose Enable. Changes to enabled rules apply to new events only.', 'yoohw-advanced-notifications' ), 'enabled' => __( 'Rule enabled for new Completed events. Historical orders were not queued.', 'yoohw-advanced-notifications' ), 'disabled' => __( 'Rule disabled. Unstarted emails are cancelled; work already in flight may finish.', 'yoohw-advanced-notifications' ), 'tested' => __( 'Test mail handed to the mail system. No rule was saved or enabled. Inbox delivery is not verified.', 'yoohw-advanced-notifications' ), 'previewed' => __( 'Sample refreshed. No rule was saved or enabled and no delivery job was created.', 'yoohw-advanced-notifications' ) ];
		$notice = isset( $_GET['automation_notice'] ) && is_string( $_GET['automation_notice'] ) ? sanitize_key( wp_unslash( $_GET['automation_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice.
		if ( isset( $notices[ $notice ] ) ) { echo '<div class="notice notice-success inline" role="status"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>'; }
		$preview = ! empty( $_GET['automation_preview'] ) ? get_transient( $this->automation_ui_preview_key() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only user-owned preview.
		$preview_result = null;
		if ( is_array( $preview ) && ( $preview['editing_id'] ?? '' ) === $id && ! empty( $preview['rule'] ) ) {
			$rule = $preview['rule'];
			// Regenerate personal sample data after checking current erasure protection; never cache rendered PII.
			$preview_result = $this->automation_preview( $rule, (int) $preview['sample_order_id'] );
		} else { $preview = false; }
		$enabled = $id && ! empty( $rules[ $id ]['enabled'] );
		?>
		<section class="yoohw-advanced-notifications-panel an-automation-library">
			<div class="yoohw-advanced-notifications-history-header"><div><h2><?php esc_html_e( 'Automations', 'yoohw-advanced-notifications' ); ?></h2><p><?php esc_html_e( 'Send a helpful guide after an order is completed. Enable uses the saved configuration.', 'yoohw-advanced-notifications' ); ?></p></div><a class="button" href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'automations' ] ) ); ?>"><?php esc_html_e( 'New rule', 'yoohw-advanced-notifications' ); ?></a></div>
			<?php if ( ! $rules ) { ?><p><?php esc_html_e( 'No rules yet. Build your first guide below, then enable it when you are ready.', 'yoohw-advanced-notifications' ); ?></p><?php } ?>
			<?php foreach ( $rules as $saved ) { ?>
				<div class="an-automation-rule">
					<div><strong><?php echo esc_html( $saved['name'] ); ?></strong><span class="an-automation-state"><?php echo esc_html( $saved['enabled'] ? __( 'Enabled', 'yoohw-advanced-notifications' ) : __( 'Disabled', 'yoohw-advanced-notifications' ) ); ?></span><p><?php echo esc_html( $saved['subject'] ); ?><br><?php /* translators: %d: Number of hours after the Completed event. */ echo esc_html( sprintf( __( 'Completed → wait %d hours → send one guide', 'yoohw-advanced-notifications' ), $saved['delay_hours'] ) ); ?></p><details><summary><?php esc_html_e( 'Saved configuration', 'yoohw-advanced-notifications' ); ?></summary><p><strong><?php esc_html_e( 'Products', 'yoohw-advanced-notifications' ); ?>:</strong> <?php echo esc_html( $saved['product_ids'] ? $this->get_selected_products_label( $saved['product_ids'] ) : __( 'All products', 'yoohw-advanced-notifications' ) ); ?><br><strong><?php esc_html_e( 'Categories', 'yoohw-advanced-notifications' ); ?>:</strong> <?php $category_names = array_map( static function ( $term_id ) { $term = get_term( $term_id, 'product_cat' ); return $term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id; }, $saved['category_ids'] ); echo esc_html( $category_names ? implode( ', ', $category_names ) : __( 'All categories', 'yoohw-advanced-notifications' ) ); ?><br><strong><?php echo esc_html( $saved['heading'] ); ?></strong></p><p><?php echo esc_html( wp_strip_all_tags( $saved['message'] ) ); ?></p></details></div>
					<div class="an-automation-rule-actions"><a class="button" href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'automations', 'automation_id' => $saved['id'] ] ) ); ?>"><?php esc_html_e( 'Edit rule', 'yoohw-advanced-notifications' ); ?></a><?php $this->automation_ui_button( $saved, $saved['enabled'] ? 'disable' : 'enable', $saved['enabled'] ? __( 'Disable and cancel waiting emails', 'yoohw-advanced-notifications' ) : __( 'Enable', 'yoohw-advanced-notifications' ) ); ?></div>
				</div>
			<?php } ?>
			<p><a href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'list' ] ) ); ?>"><?php esc_html_e( 'View campaigns and delivery status', 'yoohw-advanced-notifications' ); ?></a> · <a href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'subscribers' ] ) ); ?>"><?php esc_html_e( 'Look up a contact', 'yoohw-advanced-notifications' ); ?></a></p>
		</section>
		<form class="yoohw-advanced-notifications-campaign-form an-automation-form" data-an-initial-step="<?php echo $preview ? 'review' : 'conditions'; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="yoohw_an_automation"><input type="hidden" name="automation_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'yoohw_an_automation' ); ?>
			<section class="yoohw-advanced-notifications-panel an-draftbar"><label for="an-rule-name"><?php esc_html_e( 'Rule name (internal only)', 'yoohw-advanced-notifications' ); ?></label><input id="an-rule-name" name="name" required value="<?php echo esc_attr( $rule['name'] ?? '' ); ?>"><button class="button" name="operation" value="save"><?php echo esc_html( $enabled ? __( 'Save changes for new events', 'yoohw-advanced-notifications' ) : __( 'Save rule', 'yoohw-advanced-notifications' ) ); ?></button><p><?php echo esc_html( $enabled ? __( 'Waiting emails keep their confirmed content, order context and due time.', 'yoohw-advanced-notifications' ) : __( 'Save your rule, then explicitly Enable it above. Saving alone never enables a new rule.', 'yoohw-advanced-notifications' ) ); ?></p></section>
			<nav class="an-compose-steps" aria-label="<?php esc_attr_e( 'Automation steps', 'yoohw-advanced-notifications' ); ?>">
				<?php foreach ( [ 'conditions' => __( '1. Conditions', 'yoohw-advanced-notifications' ), 'content' => __( '2. Content', 'yoohw-advanced-notifications' ), 'timing' => __( '3. Timing', 'yoohw-advanced-notifications' ), 'review' => __( '4. Review', 'yoohw-advanced-notifications' ) ] as $step => $label ) { ?><a href="#an-step-<?php echo esc_attr( $step ); ?>" data-an-step="<?php echo esc_attr( $step ); ?>"><?php echo esc_html( $label ); ?></a><?php } ?>
			</nav>
			<div id="an-step-conditions" class="an-compose-step" data-an-panel="conditions"><section class="yoohw-advanced-notifications-panel">
				<div class="yoohw-advanced-notifications-panel-header"><span class="yoohw-advanced-notifications-step">1</span><div><h2><?php esc_html_e( 'Conditions', 'yoohw-advanced-notifications' ); ?></h2><p><?php esc_html_e( 'Choose which completed orders should receive this guide.', 'yoohw-advanced-notifications' ); ?></p></div></div>
				<p><strong><?php esc_html_e( 'Trigger: order changes to Completed', 'yoohw-advanced-notifications' ); ?></strong><br><?php esc_html_e( 'Only new events after Enable are captured. There is no historical backfill. A customer with several qualifying orders can receive one guide for each order.', 'yoohw-advanced-notifications' ); ?></p>
				<div class="an-form-grid"><label class="an-field"><?php esc_html_e( 'Products or variations (empty means any)', 'yoohw-advanced-notifications' ); ?><select class="wc-product-search" multiple name="product_ids[]" data-action="woocommerce_json_search_products_and_variations" data-width="100%" data-placeholder="<?php esc_attr_e( 'Search products or variations', 'yoohw-advanced-notifications' ); ?>"><?php echo $this->get_selected_product_options_html( (array) ( $rule['product_ids'] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes IDs and product names. ?></select></label>
				<label class="an-field"><?php esc_html_e( 'Categories including descendants (empty means any)', 'yoohw-advanced-notifications' ); ?><select multiple name="category_ids[]">
				<?php $categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ); $known = []; foreach ( is_wp_error( $categories ) ? [] : $categories as $category ) { $known[] = $category->term_id; ?><option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( $category->term_id, array_map( 'intval', (array) ( $rule['category_ids'] ?? [] ) ), true ) ); ?>><?php echo esc_html( $category->name ); ?></option><?php } $this->render_unavailable_options( (array) ( $rule['category_ids'] ?? [] ), $known ); ?>
				</select></label></div><p class="description"><?php esc_html_e( 'Product and category conditions match the same order. Parent products include their variations; a selected variation matches only that variation.', 'yoohw-advanced-notifications' ); ?></p>
				<details><summary><?php esc_html_e( 'Eligibility and subscription', 'yoohw-advanced-notifications' ); ?></summary><p><?php esc_html_e( 'An active subscription is required at sending. Unknown is not consent. Unsubscribe and erasure protection always apply. The order must still be Completed, match its saved conditions and keep the same billing email. Cancelled, failed and fully refunded orders are skipped; partial refunds remain eligible while an amount remains.', 'yoohw-advanced-notifications' ); ?></p></details>
			</section></div>
			<div id="an-step-content" class="an-compose-step" data-an-panel="content"><div class="yoohw-advanced-notifications-campaign-grid"><div class="yoohw-advanced-notifications-campaign-main"><section class="yoohw-advanced-notifications-panel">
				<div class="yoohw-advanced-notifications-panel-header"><span class="yoohw-advanced-notifications-step">2</span><div><h2><?php esc_html_e( 'Content', 'yoohw-advanced-notifications' ); ?></h2><p><?php esc_html_e( 'Write useful setup or care instructions for the purchased items.', 'yoohw-advanced-notifications' ); ?></p></div></div>
				<div class="an-form-grid"><label class="an-field"><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?><input id="email_subject" name="subject" required value="<?php echo esc_attr( $rule['subject'] ?? __( 'A guide for order {order_number}', 'yoohw-advanced-notifications' ) ); ?>"></label><label class="an-field"><?php esc_html_e( 'Email heading', 'yoohw-advanced-notifications' ); ?><input id="email_heading" name="heading" required value="<?php echo esc_attr( $rule['heading'] ?? __( 'Getting started with your purchase', 'yoohw-advanced-notifications' ) ); ?>"></label></div>
				<label for="email_message"><?php esc_html_e( 'Message body', 'yoohw-advanced-notifications' ); ?></label><?php wp_editor( $rule['message'] ?? __( '<p>Hello {customer_name},</p><p>Thank you for purchasing {ordered_products}.</p><p>Before you start, check the setup instructions included with your order. Keep this email handy and contact our store if you need help.</p><p>Order: {order_number}</p>', 'yoohw-advanced-notifications' ), 'email_message', [ 'textarea_name' => 'message', 'textarea_rows' => 10 ] ); ?>
				<p><?php esc_html_e( 'An unsubscribe link is added automatically. The live preview shows template text; Review uses the exact sample order.', 'yoohw-advanced-notifications' ); ?></p><details><summary><?php esc_html_e( 'Personalization variables', 'yoohw-advanced-notifications' ); ?></summary><p><?php esc_html_e( 'Use {customer_name}, {store_name}, {product_name}, {order_number}, {ordered_products} and {order_url} in the subject, heading or body. Order values come from the triggering order and are frozen when captured. Missing names use Customer. Guest order links are empty; account links require the owning account login.', 'yoohw-advanced-notifications' ); ?></p></details>
			</section></div><?php $this->render_automation_preview_panel(); ?></div></div>
			<div id="an-step-timing" class="an-compose-step" data-an-panel="timing"><section class="yoohw-advanced-notifications-panel">
				<div class="yoohw-advanced-notifications-panel-header"><span class="yoohw-advanced-notifications-step">3</span><div><h2><?php esc_html_e( 'Timing', 'yoohw-advanced-notifications' ); ?></h2><p><?php esc_html_e( 'Set how long to wait after the order becomes Completed.', 'yoohw-advanced-notifications' ); ?></p></div></div>
				<label class="an-field"><?php esc_html_e( 'Delay after Completed (hours)', 'yoohw-advanced-notifications' ); ?><input type="number" name="delay_hours" min="0" max="8760" required value="<?php echo esc_attr( $rule['delay_hours'] ?? 24 ); ?>"></label><p><?php esc_html_e( 'Use 0 to queue immediately, or 24 to wait one day. Sending limits and queue delays can make actual delivery later.', 'yoohw-advanced-notifications' ); ?></p><p><?php esc_html_e( 'Editing the delay affects new events only. Waiting emails keep their original due time.', 'yoohw-advanced-notifications' ); ?></p>
			</section></div>
			<div id="an-step-review" class="an-compose-step" data-an-panel="review"><div class="yoohw-advanced-notifications-campaign-grid"><div class="yoohw-advanced-notifications-campaign-main"><section class="yoohw-advanced-notifications-panel">
				<div class="yoohw-advanced-notifications-panel-header"><span class="yoohw-advanced-notifications-step">4</span><div><h2><?php esc_html_e( 'Preview and test', 'yoohw-advanced-notifications' ); ?></h2><p><?php esc_html_e( 'Check the personalized email using an order you can access.', 'yoohw-advanced-notifications' ); ?></p></div></div>
				<label class="an-field"><?php esc_html_e( 'Sample order ID', 'yoohw-advanced-notifications' ); ?><input type="number" name="sample_order_id" min="1" value="<?php echo esc_attr( $preview['sample_order_id'] ?? '' ); ?>"></label><p><button class="button" name="operation" value="preview"><?php esc_html_e( 'Preview guide', 'yoohw-advanced-notifications' ); ?></button></p>
				<label class="an-field"><?php esc_html_e( 'Test email address', 'yoohw-advanced-notifications' ); ?><input type="email" name="test_email" value="<?php echo esc_attr( $preview['test_email'] ?? get_option( 'admin_email' ) ); ?>"></label><p><button class="button" name="operation" value="test"><?php esc_html_e( 'Send test email', 'yoohw-advanced-notifications' ); ?></button></p><p><?php esc_html_e( 'Test mail goes only to this address. Preview and test create no campaign or delivery job, and never save or enable the rule.', 'yoohw-advanced-notifications' ); ?></p>
			</section><section class="yoohw-advanced-notifications-panel"><h2><?php esc_html_e( 'Ready to save?', 'yoohw-advanced-notifications' ); ?></h2><p><?php esc_html_e( 'Save the form, then Enable the saved rule above. If it is already enabled, your changes apply to new events only.', 'yoohw-advanced-notifications' ); ?></p><button class="button button-primary" name="operation" value="save"><?php echo esc_html( $enabled ? __( 'Save changes for new events', 'yoohw-advanced-notifications' ) : __( 'Save rule', 'yoohw-advanced-notifications' ) ); ?></button><details><summary><?php esc_html_e( 'What happens when I disable a rule?', 'yoohw-advanced-notifications' ); ?></summary><p><?php esc_html_e( 'New events stop and unstarted emails are cancelled. Work already in flight may finish. Re-enabling never restores cancelled mail. History is preserved.', 'yoohw-advanced-notifications' ); ?></p></details></section></div>
				<?php $this->render_automation_preview_panel( $preview_result, true ); ?>
			</div></div>
			<div class="an-step-controls" hidden><button type="button" class="button" data-an-previous><?php esc_html_e( 'Back', 'yoohw-advanced-notifications' ); ?></button><button type="button" class="button button-primary" data-an-next><?php esc_html_e( 'Continue', 'yoohw-advanced-notifications' ); ?></button></div>
		</form></div>
		<?php
	}
	private function render_automation_preview_panel( $sample = null, $personalized = false ) {
		?>
		<aside class="yoohw-advanced-notifications-preview-panel" aria-label="<?php echo esc_attr( $personalized ? __( 'Personalized guide preview', 'yoohw-advanced-notifications' ) : __( 'Email preview', 'yoohw-advanced-notifications' ) ); ?>">
			<div class="yoohw-advanced-notifications-preview-header"><div><h2><?php echo esc_html( $personalized ? __( 'Personalized guide preview', 'yoohw-advanced-notifications' ) : __( 'Email preview', 'yoohw-advanced-notifications' ) ); ?></h2><p><?php echo esc_html( $personalized ? __( 'Based on the form at the last refresh.', 'yoohw-advanced-notifications' ) : __( 'Rendered with the active WooCommerce email template.', 'yoohw-advanced-notifications' ) ); ?></p></div><span><?php echo esc_html( $personalized ? __( 'Sample', 'yoohw-advanced-notifications' ) : __( 'Live', 'yoohw-advanced-notifications' ) ); ?></span></div>
			<?php $this->render_preview_device_controls(); ?>
			<?php if ( ! $personalized ) { ?>
				<div class="yoohw-advanced-notifications-preview-subject"><strong><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?></strong><span id="yoohw-advanced-notifications-preview-subject"></span></div><textarea id="yoohw-advanced-notifications-preview-template-source" hidden readonly><?php echo esc_textarea( $this->get_campaign_preview_email_html() ); ?></textarea><iframe id="yoohw-advanced-notifications-preview-frame" class="yoohw-advanced-notifications-preview-frame" title="<?php esc_attr_e( 'WooCommerce email preview', 'yoohw-advanced-notifications' ); ?>"></iframe>
			<?php } elseif ( is_wp_error( $sample ) ) { ?><p role="alert"><?php echo esc_html( $sample->get_error_message() ); ?></p>
			<?php } elseif ( $sample ) { ?>
				<p class="an-sample-stale" role="status" hidden><?php esc_html_e( 'The form has changed. Choose Preview guide to refresh this sample before saving or testing.', 'yoohw-advanced-notifications' ); ?></p><div class="an-automation-sample"><p><?php echo esc_html( $sample['reason'] ); ?></p><p><strong><?php esc_html_e( 'Sample recipient', 'yoohw-advanced-notifications' ); ?>:</strong> <?php echo esc_html( $sample['recipient']['email'] ); ?></p><div class="yoohw-advanced-notifications-preview-subject"><strong><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?></strong><span><?php echo esc_html( $sample['email']['subject'] ); ?></span></div><iframe class="an-personal-preview" sandbox="" title="<?php esc_attr_e( 'Personalized guide preview', 'yoohw-advanced-notifications' ); ?>" srcdoc="<?php echo esc_attr( $sample['html'] ); ?>"></iframe></div>
			<?php } else { ?><p><?php esc_html_e( 'Choose a sample order and select Preview guide to see its customer name, products and order details here.', 'yoohw-advanced-notifications' ); ?></p><?php } ?>
		</aside>
		<?php
	}
}
