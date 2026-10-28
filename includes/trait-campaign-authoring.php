<?php
/** Durable drafts, reusable criteria, and explicit schedule previews. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Authoring {
	private function register_operator_features() {
		add_action( 'admin_post_yoohw_an_control', [ $this, 'handle_campaign_control' ] );
		add_action( 'admin_post_yoohw_an_library', [ $this, 'handle_library_action' ] );
		add_action( 'admin_post_yoohw_an_data', [ $this, 'handle_data_action' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'privacy_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'privacy_erasers' ] );
		add_action( self::ACTION_RECONCILE_CAMPAIGNS, [ $this, 'cleanup_retention' ], 30 );
	}
	private function draft_fields() {
		return [ 'internal_name', 'subject', 'heading', 'message', 'recipient_source', 'product_ids', 'user_roles', 'manual_emails', 'manual_emails_raw', 'exclusions', 'exclude_emails_raw', 'schedule_mode', 'scheduled_at_input', 'filters', 'test_email' ];
	}
	private function criteria_fields() { return [ 'recipient_source', 'product_ids', 'user_roles', 'manual_emails', 'manual_emails_raw', 'exclusions', 'exclude_emails_raw', 'filters' ]; }
	private function save_draft( $payload, $id = '' ) {
		$id = $id ?: ( $payload['draft_id'] ?? wp_generate_uuid4() );
		$key = 'yoohw_an_draft_' . sanitize_key( $id );
		$draft = array_intersect_key( $payload, array_flip( $this->draft_fields() ) );
		$draft['draft_id'] = $id;
		$draft['revision'] = wp_generate_uuid4();
		$draft['updated_at'] = time();
		if ( false === $this->write_durable_option( $key, $draft ) ) { throw new RuntimeException( 'Draft could not be saved.' ); }
		return $draft;
	}
	private function raw_authoring_payload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called only after the send nonce is verified.
		$post = wp_unslash( $_POST );
		$p = [];
		foreach ( [ 'internal_name' => 'internal_name', 'email_subject' => 'subject', 'email_heading' => 'heading', 'recipient_source' => 'recipient_source', 'schedule_mode' => 'schedule_mode', 'scheduled_at' => 'scheduled_at_input', 'test_email' => 'test_email' ] as $input => $field ) { $p[ $field ] = sanitize_text_field( $post[ $input ] ?? '' ); }
		$p['message'] = wp_kses_post( $post['email_message'] ?? '' );
		$p['product_ids'] = array_map( 'absint', (array) ( $post['product_ids'] ?? [] ) );
		$p['user_roles'] = array_map( 'sanitize_key', (array) ( $post['user_roles'] ?? [] ) );
		$p['manual_emails_raw'] = sanitize_textarea_field( $post['manual_emails'] ?? '' );
		$p['manual_emails'] = preg_split( '/[\s,;]+/', $p['manual_emails_raw'], -1, PREG_SPLIT_NO_EMPTY );
		$p['exclude_emails_raw'] = sanitize_textarea_field( $post['exclude_emails'] ?? '' );
		$p['exclusions'] = [ 'product_ids' => array_map( 'absint', (array) ( $post['exclude_product_ids'] ?? [] ) ), 'order_statuses' => array_map( 'sanitize_key', (array) ( $post['exclude_order_statuses'] ?? [] ) ), 'emails' => $this->parse_email_list( $p['exclude_emails_raw'] ) ];
		$p['filters'] = $this->filters_from_request();
		return $p;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
	private function handle_authoring_submit() {
		$operation = sanitize_key( wp_unslash( $_POST['authoring_operation'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies nonce.
		if ( ! in_array( $operation, [ 'save_draft', 'save_audience' ], true ) ) { return; }
		$p = $this->raw_authoring_payload();
		$id = sanitize_key( wp_unslash( $_POST['draft_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$draft = $this->save_draft( $p, $id );
		if ( 'save_audience' === $operation ) {
			$valid = $this->validate_recipient_source_options( $p );
			if ( is_wp_error( $valid ) ) { wp_die( esc_html( $valid->get_error_message() ) ); }
			$name = sanitize_text_field( wp_unslash( $_POST['audience_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $name ) { wp_die( esc_html__( 'Enter an audience name.', 'yoohw-advanced-notifications' ) ); }
			$audience_id = sanitize_key( wp_unslash( $_POST['audience_id'] ?? '' ) ) ?: wp_generate_uuid4(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$audience = [ 'id' => $audience_id, 'name' => $name, 'criteria' => array_intersect_key( $p, array_flip( $this->criteria_fields() ) ) ];
			if ( false === $this->write_durable_option( 'yoohw_an_audience_' . $audience_id, $audience ) ) { wp_die( esc_html__( 'Audience could not be saved.', 'yoohw-advanced-notifications' ) ); }
		}
		wp_safe_redirect( $this->get_admin_page_url( [ 'draft_id' => $draft['draft_id'], 'saved' => 1 ] ) ); exit;
	}
	private function extend_authoring_payload( $p ) {
		$p['internal_name'] = sanitize_text_field( wp_unslash( $_POST['internal_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies nonce.
		$p['filters'] = $this->filters_from_request();
		$p['schedule_policy'] = 'strict_v1';
		$p['store_timezone'] = wp_timezone_string();
		$valid = $this->validate_audience_filters( $p );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$id = sanitize_key( wp_unslash( $_POST['draft_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$draft = $this->save_draft( $p, $id );
		$p['draft_id'] = $draft['draft_id'];
		$p['draft_revision'] = $draft['revision'];
		return $p;
	}
	private function validate_preview_revision( $p ) {
		if ( ! empty( $p['draft_id'] ) ) {
			$draft = $this->read_durable_option( 'yoohw_an_draft_' . sanitize_key( $p['draft_id'] ) );
			if ( ! $draft || (string) $draft['revision'] !== (string) $p['draft_revision'] ) { return new WP_Error( 'stale_preview', __( 'This draft changed or was deleted. Open it and create a new preview.', 'yoohw-advanced-notifications' ) ); }
		}
		return $p;
	}
	private function restore_authoring_payload( $p ) {
		$id = sanitize_key( wp_unslash( $_GET['draft_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		if ( $id ) { $p = (array) $this->read_durable_option( 'yoohw_an_draft_' . $id ); }
		$audience_id = sanitize_key( wp_unslash( $_GET['audience_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $audience_id ) {
			$audience = $this->read_durable_option( 'yoohw_an_audience_' . $audience_id );
			if ( $audience ) { $p = array_merge( $p, $audience['criteria'], [ 'audience_id' => $audience_id, 'audience_name' => $audience['name'] ] ); }
		}
		return $p;
	}
	public function handle_library_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) ); }
		check_admin_referer( 'yoohw_an_library' );
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		if ( 'duplicate' === $operation ) {
			$campaign = $this->get_campaign_history()[ $id ] ?? [];
			if ( ! $campaign || ! empty( $campaign['retained'] ) ) { wp_die( esc_html__( 'Campaign content unavailable.', 'yoohw-advanced-notifications' ) ); }
			$draft = $this->save_draft( $campaign, wp_generate_uuid4() );
			wp_safe_redirect( $this->get_admin_page_url( [ 'draft_id' => $draft['draft_id'] ] ) ); exit;
		}
		if ( in_array( $operation, [ 'delete_draft', 'delete_audience' ], true ) ) {
			delete_option( 'yoohw_an_' . ( 'delete_draft' === $operation ? 'draft_' : 'audience_' ) . sanitize_key( $id ) );
		}
		wp_safe_redirect( $this->get_admin_page_url() ); exit;
	}
	private function library_button( $id, $operation, $label ) {
		echo '<form class="an-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'yoohw_an_library' );
		echo '<input type="hidden" name="action" value="yoohw_an_library"><input type="hidden" name="id" value="' . esc_attr( $id ) . '"><button class="button" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button></form>';
	}
	private function render_duplicate_form( $id ) { $this->library_button( $id, 'duplicate', __( 'Duplicate as new draft', 'yoohw-advanced-notifications' ) ); }
	private function render_authoring_library() {
		echo '<details><summary>' . esc_html__( 'Drafts and saved audiences', 'yoohw-advanced-notifications' ) . '</summary>';
		foreach ( $this->read_durable_options( 'yoohw_an_draft_' ) as $draft ) {
			echo '<p><a href="' . esc_url( $this->get_admin_page_url( [ 'draft_id' => $draft['draft_id'] ] ) ) . '">' . esc_html( $draft['internal_name'] ?: ( $draft['subject'] ?: $draft['draft_id'] ) ) . '</a> ';
			$this->library_button( $draft['draft_id'], 'delete_draft', __( 'Delete draft', 'yoohw-advanced-notifications' ) ); echo '</p>';
		}
		foreach ( $this->read_durable_options( 'yoohw_an_audience_' ) as $audience ) {
			echo '<p><a href="' . esc_url( $this->get_admin_page_url( [ 'audience_id' => $audience['id'] ] ) ) . '">' . esc_html( $audience['name'] ) . '</a> ';
			$this->library_button( $audience['id'], 'delete_audience', __( 'Delete audience', 'yoohw-advanced-notifications' ) ); echo '</p>';
		}
		echo '</details>';
	}
	private function render_authoring_fields( $p ) {
		echo '<section class="yoohw-advanced-notifications-panel"><h2>' . esc_html__( 'Save and reuse', 'yoohw-advanced-notifications' ) . '</h2>';
		echo '<input type="hidden" name="draft_id" value="' . esc_attr( $p['draft_id'] ?? '' ) . '"><label>' . esc_html__( 'Internal name', 'yoohw-advanced-notifications' ) . ' <input name="internal_name" value="' . esc_attr( $p['internal_name'] ?? '' ) . '"></label> <button class="button" formnovalidate name="authoring_operation" value="save_draft">' . esc_html__( 'Save draft', 'yoohw-advanced-notifications' ) . '</button><p>' . esc_html__( 'Drafts do not expire and never create delivery jobs. A new preview is required after changes.', 'yoohw-advanced-notifications' ) . '</p>';
		echo '<input type="hidden" name="audience_id" value="' . esc_attr( $p['audience_id'] ?? '' ) . '"><label>' . esc_html__( 'Audience name', 'yoohw-advanced-notifications' ) . ' <input name="audience_name" value="' . esc_attr( $p['audience_name'] ?? '' ) . '"></label> <button class="button" formnovalidate name="authoring_operation" value="save_audience">' . esc_html__( 'Save audience criteria', 'yoohw-advanced-notifications' ) . '</button>';
		echo '<p><label>' . esc_html__( 'Insert editable content template', 'yoohw-advanced-notifications' ) . ' <select id="an-template"><option value="">—</option>';
		$templates = [
			'update' => [ __( 'Product or documentation update', 'yoohw-advanced-notifications' ), __( 'An update for you', 'yoohw-advanced-notifications' ), __( '<p>Hello {customer_name},</p><p>We have updated {product_name}. Add the details and documentation link here.</p>', 'yoohw-advanced-notifications' ) ],
			'guide' => [ __( 'After-purchase guide', 'yoohw-advanced-notifications' ), __( 'Getting started', 'yoohw-advanced-notifications' ), __( '<p>Hello {customer_name},</p><p>Thank you for your purchase. Add setup steps and support details here.</p>', 'yoohw-advanced-notifications' ) ],
			'service' => [ __( 'Service or order change', 'yoohw-advanced-notifications' ), __( 'Service update', 'yoohw-advanced-notifications' ), __( '<p>Hello {customer_name},</p><p>Add the change, effective date and any action needed here.</p>', 'yoohw-advanced-notifications' ) ],
		];
		foreach ( $templates as $key => $template ) { echo '<option value="' . esc_attr( $key ) . '" data-subject="' . esc_attr( $template[1] ) . '" data-body="' . esc_attr( $template[2] ) . '">' . esc_html( $template[0] ) . '</option>'; }
		echo '</select></label></p></section>';
		$this->render_audience_fields( $p );
	}
	private function strict_wall_timestamp( $input, $timezone ) {
		$wall = $this->parse_scheduled_datetime( $input, new DateTimeZone( 'UTC' ) );
		if ( ! $wall ) { return 0; }
		$nominal = $wall->getTimestamp();
		$zone = new DateTimeZone( $timezone );
		$offsets = [ $zone->getOffset( $wall ) ];
		$transitions = $zone->getTransitions( $nominal - 2 * DAY_IN_SECONDS, $nominal + 2 * DAY_IN_SECONDS );
		foreach ( is_array( $transitions ) ? $transitions : [] as $transition ) { $offsets[] = $transition['offset']; }
		$matches = [];
		foreach ( array_unique( $offsets ) as $offset ) {
			$timestamp = $nominal - $offset;
			if ( wp_date( 'Y-m-d\TH:i', $timestamp, $zone ) === substr( $input, 0, 16 ) ) { $matches[] = $timestamp; }
		}
		return count( $matches ) === 1 ? $matches[0] : 0;
	}
	private function operator_schedule( $p, $recipient ) {
		$zone = $p['store_timezone'] ?? wp_timezone_string();
		$fallback = false;
		if ( 'store' !== $p['schedule_mode'] ) {
			$country = $recipient['country'] ?? '';
			$zones = preg_match( '/^[A-Z]{2}$/', $country ) ? DateTimeZone::listIdentifiers( DateTimeZone::PER_COUNTRY, $country ) : [];
			$zone = count( $zones ) === 1 ? $zones[0] : 'UTC';
			$fallback = count( $zones ) !== 1;
		}
		return [ 'timestamp' => $this->strict_wall_timestamp( $p['scheduled_at_input'], $zone ), 'timezone' => $zone, 'fallback' => $fallback, 'country' => $recipient['country'] ?? '', 'state' => $recipient['state'] ?? '', 'city' => $recipient['city'] ?? '', 'rolled_forward_days' => 0 ];
	}
	private function prepare_operator_preview( $p, &$recipients ) {
		$times = []; $fallback = 0;
		foreach ( $recipients as &$recipient ) {
			if ( $this->is_payload_scheduled( $p ) && isset( $p['schedule_policy'] ) ) {
				$schedule = $this->operator_schedule( $p, $recipient );
				if ( ! $schedule['timestamp'] || $schedule['timestamp'] <= time() ) { return new WP_Error( 'schedule_invalid', __( 'At least one recipient has a past, nonexistent or repeated local time. Choose a later, unambiguous time and preview again. No date was shifted.', 'yoohw-advanced-notifications' ) ); }
				$recipient['confirmed_due'] = $schedule['timestamp'];
				$times[] = $schedule['timestamp']; $fallback += (int) $schedule['fallback'];
			}
		} unset( $recipient );
		$p['schedule_summary'] = [ 'fallback' => $fallback, 'earliest' => $times ? min( $times ) : 0, 'latest' => $times ? max( $times ) : 0 ];
		$p['audience_fingerprint'] = $this->audience_fingerprint( $recipients );
		$p['recipient_sample'] = array_slice( $recipients, 0, 20 );
		$p['recipient_count'] = count( $recipients );
		return $p;
	}
	private function audience_fingerprint( $recipients ) {
		$normalized = [];
		foreach ( $recipients as $recipient ) { $normalized[ strtolower( $recipient['email'] ) ] = $this->sanitize_recipient_context( $recipient ); }
		ksort( $normalized ); return hash( 'sha256', wp_json_encode( $normalized ) );
	}
	private function refresh_changed_preview( $p, $recipients ) {
		if ( ! isset( $p['audience_fingerprint'] ) || hash_equals( $p['audience_fingerprint'], $this->audience_fingerprint( $recipients ) ) ) { return ''; }
		$p = $this->prepare_operator_preview( $p, $recipients );
		if ( is_wp_error( $p ) ) { wp_die( esc_html( $p->get_error_message() ) ); }
		return $this->store_preview_payload( $p );
	}
	private function render_operator_preview( $p ) {
		if ( ! empty( $_GET['audience_changed'] ) ) { echo '<p role="alert">' . esc_html__( 'Audience changed since preview. Review this updated preview and confirm again.', 'yoohw-advanced-notifications' ) . '</p>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<p>' . esc_html__( 'Schedule mode / store timezone:', 'yoohw-advanced-notifications' ) . ' ' . esc_html( ( $p['schedule_mode'] ?? 'now' ) . ' / ' . ( $p['store_timezone'] ?? 'UTC' ) ) . '</p>';
		$summary = $p['schedule_summary'] ?? [];
		if ( ! empty( $summary['earliest'] ) ) { echo '<p>' . esc_html__( 'UTC fallback count / earliest and latest planned send (UTC):', 'yoohw-advanced-notifications' ) . ' ' . esc_html( $summary['fallback'] . ' / ' . gmdate( 'Y-m-d H:i', $summary['earliest'] ) . ' — ' . gmdate( 'Y-m-d H:i', $summary['latest'] ) ) . '</p>'; }
		echo '<p>' . esc_html__( 'Selected / excluded:', 'yoohw-advanced-notifications' ) . ' ' . esc_html( ( $p['recipient_count'] ?? 0 ) . ' / ' . ( $p['excluded_count'] ?? 0 ) ) . '</p>';
		$reason_labels = [ 'order_conditions' => __( 'Removed by order conditions', 'yoohw-advanced-notifications' ), 'subscription_or_history' => __( 'Removed by subscription or campaign history', 'yoohw-advanced-notifications' ), 'explicit_exclusions_or_suppression' => __( 'Removed by exclusions or suppression', 'yoohw-advanced-notifications' ) ];
		foreach ( $p['audience_audit'] ?? [] as $reason => $count ) { echo '<p>' . esc_html( ( $reason_labels[ $reason ] ?? $reason ) . ': ' . $count ) . '</p>'; }
		$this->render_audience_summary( $p );
		$this->render_audience_inspector( $p );
		$sample = $p['recipient_sample'][0] ?? null;
		if ( $sample ) {
			$index = absint( $_GET['sample_index'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sample = $p['recipient_sample'][ $index ] ?? $sample;
			echo '<p>' . esc_html__( 'Personalized sample:', 'yoohw-advanced-notifications' ) . ' ';
			foreach ( $p['recipient_sample'] as $i => $recipient ) { echo '<a href="' . esc_url( add_query_arg( 'sample_index', $i ) ) . '">' . esc_html( $recipient['email'] ) . '</a> '; }
			echo '</p><label>' . esc_html__( 'Preview width', 'yoohw-advanced-notifications' ) . ' <select class="an-preview-width"><option value="100%">Desktop</option><option value="375px">Mobile</option></select></label>';
			$message = str_replace( [ '{customer_name}', '{product_name}' ], [ $sample['name'] ?: $sample['email'], $p['products_label'] ?? '' ], $p['message'] );
			$message = $this->append_unsubscribe_footer( $message, $sample['email'], $p['campaign_id'] );
			$html = $this->build_woocommerce_email_body( $p['heading'], $message );
			echo '<iframe class="an-personal-preview" sandbox="" title="' . esc_attr__( 'Personalized email preview', 'yoohw-advanced-notifications' ) . '" srcdoc="' . esc_attr( $html ) . '"></iframe>';
		}
	}
}
