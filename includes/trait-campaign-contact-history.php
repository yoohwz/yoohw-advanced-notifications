<?php
/** Read-only contact lookup over existing campaign recipient outcomes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Contact_History {
	private function get_contact_history( $email, $page = 1 ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'contact_history_forbidden', __( 'Permission denied.', 'yoohw-advanced-notifications' ) ); }
		if ( ! is_string( $email ) || ! is_email( trim( $email ) ) ) { return new WP_Error( 'contact_history_email', __( 'Enter one valid email address to look up its contact history.', 'yoohw-advanced-notifications' ) ); }
		$email = strtolower( trim( $email ) );
		$hash = hash( 'sha256', $email );
		$page = max( 1, min( 1000000, (int) $page ) );
		$result = [ 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => 50, 'erased' => false, 'subscription' => [] ];
		try {
		// An erasure marker remains authoritative even if old rows or snapshots still exist.
		if ( $this->read_durable_option( 'yoohw_an_erased_' . $hash ) ) { $result['erased'] = true; return $result; }
		global $wpdb;
		$table = $this->get_recipient_tracking_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact recipient hash lookup; no audience or order reconstruction.
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email_hash = %s', $table, $hash ) );
		if ( $wpdb->last_error ) { return new WP_Error( 'contact_history_storage', __( 'Contact history could not be read. Please try again after checking storage.', 'yoohw-advanced-notifications' ) ); }
		$result['total'] = (int) $total;
		$result['page'] = min( $page, max( 1, (int) ceil( $result['total'] / $result['per_page'] ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded read of plugin-owned recipient outcomes for one email only.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT campaign_id,status,scheduled_at_gmt,created_at,updated_at,last_error FROM %i WHERE email_hash = %s ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d', $table, $hash, $result['per_page'], ( $result['page'] - 1 ) * $result['per_page'] ), ARRAY_A );
		if ( $wpdb->last_error ) { return new WP_Error( 'contact_history_storage', __( 'Contact history could not be read. Please try again after checking storage.', 'yoohw-advanced-notifications' ) ); }
		$campaigns = $rows ? $this->get_campaign_history() : [];
		foreach ( (array) $rows as $row ) {
			$campaign = $campaigns[ $row['campaign_id'] ] ?? [];
			$available = $campaign && empty( $campaign['retained'] ) && ( ! empty( $campaign['subject'] ) || ! empty( $campaign['internal_name'] ) || ! empty( $campaign['recipient_source_label'] ) );
			$context = [];
			if ( $available ) {
				$manifest = $this->read_durable_option( $this->campaign_manifest_key( $row['campaign_id'] ) );
				foreach ( (array) ( $manifest['recipients'] ?? [] ) as $recipient ) {
					if ( strtolower( $recipient['email'] ?? '' ) === $email ) { $context = $this->sanitize_order_context( $recipient['order_context'] ?? [] ); break; }
				}
			}
			$result['items'][] = array_merge( $row, [
				'subject' => $available ? ( $campaign['subject'] ?? '' ) : '',
				'internal_name' => $available ? ( $campaign['internal_name'] ?? '' ) : '',
				'source_label' => $available ? ( $campaign['recipient_source_label'] ?? '' ) : '',
				'content_available' => (bool) $available,
				'last_error' => ! empty( $campaign['retained'] ) ? '' : $row['last_error'],
				'order_context' => $context,
				'campaign_url' => $this->get_admin_page_url( [ 'tab' => 'campaigns', 'campaign_id' => $row['campaign_id'] ] ),
			] );
		}
		$result['subscription'] = $this->subscription_record( $email );
		// Erasure may have started during these reads; never return its older plaintext snapshot.
		if ( $this->read_durable_option( 'yoohw_an_erased_' . $hash ) ) { $result['items'] = []; $result['total'] = 0; $result['subscription'] = []; $result['erased'] = true; }
		return $result;
		} catch ( Throwable $error ) {
			return new WP_Error( 'contact_history_storage', __( 'Contact history could not be read. Please try again after checking storage.', 'yoohw-advanced-notifications' ) );
		}
	}
	private function render_contact_history() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$raw = $_GET['contact_email'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only authorized lookup; no data is changed.
		$email = is_string( $raw ) ? strtolower( trim( wp_unslash( $raw ) ) ) : '';
		$raw_page = $_GET['history_page'] ?? 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page = is_scalar( $raw_page ) ? max( 1, (int) $raw_page ) : 1;
		$result = '' !== $email ? $this->get_contact_history( $email, $page ) : null;
		$erased = is_array( $result ) && $result['erased'];
		echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html__( 'Contact history by email', 'yoohw-advanced-notifications' ) . '</h2><p>' . esc_html__( 'Only contacts recorded by Advanced Notifications are shown. A mail-system handoff does not confirm inbox delivery or that an email was read.', 'yoohw-advanced-notifications' ) . '</p><form method="get" class="an-filter-form"><input type="hidden" name="page" value="yoohw-advanced-notifications"><input type="hidden" name="tab" value="subscribers"><label class="an-field">' . esc_html__( 'Email for contact history', 'yoohw-advanced-notifications' ) . '<input type="email" name="contact_email" required value="' . esc_attr( $erased ? '' : $email ) . '"></label><button class="button">' . esc_html__( 'Look up contacts', 'yoohw-advanced-notifications' ) . '</button></form>';
		if ( is_wp_error( $result ) ) { echo '<p role="alert">' . esc_html( $result->get_error_message() ) . '</p></section>'; return; }
		if ( ! $result ) { echo '</section>'; return; }
		if ( $erased ) { echo '<p role="status">' . esc_html__( 'This address is protected by erasure. Its previous contact details are not shown or reconstructed.', 'yoohw-advanced-notifications' ) . '</p></section>'; return; }
		$states = [ 'subscribed' => __( 'Subscribed', 'yoohw-advanced-notifications' ), 'unsubscribed' => __( 'Unsubscribed', 'yoohw-advanced-notifications' ), 'unknown' => __( 'Unknown', 'yoohw-advanced-notifications' ) ];
		$subscription = $result['subscription'];
		echo '<p><strong>' . esc_html__( 'Current subscription', 'yoohw-advanced-notifications' ) . ':</strong> ' . esc_html( $states[ $subscription['status'] ?? 'unknown' ] ?? $states['unknown'] ) . '</p>';
		if ( ! empty( $subscription['source'] ) ) { echo '<p>' . esc_html__( 'Recorded source', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $subscription['source'] ) . '</p>'; }
		if ( ! $result['total'] ) { echo '<p role="status">' . esc_html__( 'No campaign contacts are currently retained for this email.', 'yoohw-advanced-notifications' ) . '</p></section>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Campaign / saved context', 'yoohw-advanced-notifications' ) . '</th><th>' . esc_html__( 'Recorded times (UTC)', 'yoohw-advanced-notifications' ) . '</th><th>' . esc_html__( 'Outcome / reason', 'yoohw-advanced-notifications' ) . '</th></tr></thead><tbody>';
		foreach ( $result['items'] as $item ) {
			echo '<tr><td><a href="' . esc_url( $item['campaign_url'] ) . '">' . esc_html( $item['campaign_id'] ) . '</a><br>';
			if ( $item['content_available'] ) {
				echo esc_html( $item['subject'] );
				if ( $item['internal_name'] ) { echo '<br>' . esc_html( $item['internal_name'] ); }
				if ( $item['source_label'] ) { echo '<br>' . esc_html( $item['source_label'] ); }
				$c = $item['order_context'];
				if ( $c ) { echo '<br>' . esc_html__( 'Saved matching order', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $c['number'] ) . '<br>' . esc_html( $c['products'] ); }
			} else { echo esc_html__( 'Campaign content is no longer available. Only the retained outcome is shown.', 'yoohw-advanced-notifications' ); }
			echo '</td><td>' . esc_html__( 'Created', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $item['created_at'] ) . '<br>' . esc_html__( 'Due', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $item['scheduled_at_gmt'] ?: '—' ) . '<br>' . esc_html__( 'Updated', 'yoohw-advanced-notifications' ) . ': ' . esc_html( $item['updated_at'] ) . '</td><td>' . esc_html( $this->get_status_label_from_key( $item['status'] ) );
			if ( $item['last_error'] ) { echo '<br>' . esc_html( $item['last_error'] ); }
			echo '</td></tr>';
		}
		echo '</tbody></table><p>';
		$pages = max( 1, (int) ceil( $result['total'] / $result['per_page'] ) );
		if ( $result['page'] > 1 ) { echo '<a class="button" href="' . esc_url( $this->get_admin_page_url( [ 'tab' => 'subscribers', 'contact_email' => $email, 'history_page' => $result['page'] - 1 ] ) ) . '">' . esc_html__( 'Previous contacts', 'yoohw-advanced-notifications' ) . '</a> '; }
		/* translators: 1: Current page, 2: Total pages. */
		echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'yoohw-advanced-notifications' ), $result['page'], $pages ) );
		if ( $result['page'] < $pages ) { echo ' <a class="button" href="' . esc_url( $this->get_admin_page_url( [ 'tab' => 'subscribers', 'contact_email' => $email, 'history_page' => $result['page'] + 1 ] ) ) . '">' . esc_html__( 'Next contacts', 'yoohw-advanced-notifications' ) . '</a>'; }
		echo '</p></section>';
	}
}
