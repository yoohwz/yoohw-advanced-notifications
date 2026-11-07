<?php
/** Explicit subscription requests and single-use, mailbox-owned confirmations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait YOOHW_AN_Campaign_Subscriptions {
	private function register_subscriptions() {
		add_shortcode( 'yoohw_an_subscribe', [ $this, 'subscription_shortcode' ] );
		foreach ( [ 'admin_post_', 'admin_post_nopriv_' ] as $prefix ) {
			add_action( $prefix . 'yoohw_an_signup', [ $this, 'handle_subscription_request' ] );
			add_action( $prefix . 'yoohw_an_confirm_subscription', [ $this, 'handle_subscription_confirmation' ] );
		}
		add_action( 'woocommerce_account_dashboard', [ $this, 'render_account_subscription' ] );
		add_action( 'admin_post_yoohw_an_account_subscription', [ $this, 'handle_account_subscription' ] );
		add_action( self::ACTION_RECONCILE_CAMPAIGNS, [ $this, 'cleanup_subscription_requests' ], 35 );
		$this->register_subscription_checkout();
	}
	private function suppression_fingerprint( $email ) {
		$legacy = $this->normalize_unsubscribed_emails( $this->read_durable_option( self::OPTION_UNSUBSCRIBED_EMAILS ) );
		return hash( 'sha256', wp_json_encode( [ $this->read_durable_option( 'yoohw_an_optout_' . hash( 'sha256', $email ) ), $legacy[ $email ] ?? null ] ) );
	}
	/** Only mailbox confirmation can acknowledge the exact suppression generation it follows. */
	private function confirmed_suppression_override( $email ) {
		$record = $this->read_durable_option( 'yoohw_an_subscription_' . hash( 'sha256', $email ) );
		return is_array( $record ) && 'subscribed' === ( $record['status'] ?? '' ) && ! empty( $record['confirmed_at'] ) && ! empty( $record['suppression_fingerprint'] ) && hash_equals( $record['suppression_fingerprint'], $this->suppression_fingerprint( $email ) );
	}
	private function reserve_signup_quota( $email ) {
		$now = time();
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Never output/stored; used only as HMAC input, not trusted as identity.
		$quotas = [ 'address_' . hash( 'sha256', $email ) => [ 1, 10 * MINUTE_IN_SECONDS ], 'client_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) => [ 5, 30 * MINUTE_IN_SECONDS ], 'site' => [ 100, HOUR_IN_SECONDS ] ];
		$states = [];
		foreach ( $quotas as $key => $quota ) {
			$name = 'yoohw_an_signup_rate_' . $key;
			$state = (array) $this->read_durable_option( $name );
			if ( ( $state['expires'] ?? 0 ) <= $now ) { $state = [ 'used' => 0, 'expires' => $now + $quota[1] ]; }
			if ( ( $state['used'] ?? 0 ) >= $quota[0] ) { return false; }
			$state['used']++; $states[ $name ] = $state;
		}
		foreach ( $states as $name => $state ) { if ( false === $this->write_durable_option( $name, $state ) ) { throw new RuntimeException( 'Subscription request limits unavailable.' ); } }
		return true;
	}
	private function request_subscription( $email, $source ) {
		$email = is_string( $email ) ? strtolower( trim( $email ) ) : '';
		if ( ! is_email( $email ) || ! in_array( $source, [ 'signup_form', 'my_account', 'checkout_classic', 'checkout_blocks' ], true ) ) { return new WP_Error( 'invalid_signup', __( 'Enter a valid email address and try again.', 'yoohw-advanced-notifications' ) ); }
		if ( ! $this->acquire_campaign_lock( '__privacy__', 2 ) ) { return new WP_Error( 'signup_busy', __( 'Subscription storage is busy. Please try again.', 'yoohw-advanced-notifications' ) ); }
		try {
			$hash = hash( 'sha256', $email );
			if ( $this->read_durable_option( 'yoohw_an_erased_' . $hash ) || 'subscribed' === $this->subscription_record( $email )['status'] ) { return true; }
			if ( ! $this->reserve_signup_quota( $email ) ) { return true; }
			$token = bin2hex( random_bytes( 32 ) );
			$pending = [ 'email' => $email, 'source' => $source, 'requested_at' => time(), 'expires' => time() + DAY_IN_SECONDS, 'token_hash' => hash( 'sha256', $token ), 'suppression_fingerprint' => $this->suppression_fingerprint( $email ) ];
			$key = 'yoohw_an_signup_' . $hash;
			if ( false === $this->write_durable_option( $key, $pending ) || $pending !== $this->read_durable_option( $key ) ) { throw new RuntimeException( 'Subscription request could not be verified.' ); }
			if ( $this->read_durable_option( 'yoohw_an_erased_' . $hash ) ) { delete_option( $key ); return true; }
			$url = add_query_arg( [ 'action' => 'yoohw_an_confirm_subscription', 'email' => $email, 'token' => $token ], admin_url( 'admin-post.php' ) );
			// This requested confirmation is not marketing. Campaign suppression is never bypassed by this path.
			$this->send_subscription_confirmation( $email, $url );
			return true; // A request is not proof of transport or consent. No automatic resend after an ambiguous outcome.
		} catch ( Throwable $error ) { return new WP_Error( 'signup_storage', __( 'The request could not be completed. Please try again later.', 'yoohw-advanced-notifications' ) ); }
		finally { $this->release_campaign_lock( '__privacy__' ); }
	}
	private function send_subscription_confirmation( $email, $url ) {
		$heading = __( 'Confirm your email subscription', 'yoohw-advanced-notifications' );
		$message = '<p>' . esc_html__( 'Someone requested campaign emails from this store for this address. To subscribe, open the link below and confirm. It expires in 24 hours. If you did not request this, ignore this email; your preference has not changed.', 'yoohw-advanced-notifications' ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html( $heading ) . '</a></p>';
		$body = $this->build_woocommerce_email_body( $heading, $message );
		if ( false === $body ) { return false; }
		$this->current_from_email = $this->get_sender_email(); $this->current_from_name = $this->get_sender_name();
		add_filter( 'wp_mail_from', [ $this, 'override_from_email' ] ); add_filter( 'wp_mail_from_name', [ $this, 'override_from_name' ] );
		try { return wp_mail( $email, $heading, $body, $this->build_email_headers( $this->get_reply_to_email() ) ); }
		finally { remove_filter( 'wp_mail_from', [ $this, 'override_from_email' ] ); remove_filter( 'wp_mail_from_name', [ $this, 'override_from_name' ] ); $this->current_from_email = ''; $this->current_from_name = ''; }
	}
	private function confirm_subscription( $email, $token ) {
		$email = is_string( $email ) ? strtolower( trim( $email ) ) : '';
		$invalid = new WP_Error( 'invalid_confirmation', __( 'This confirmation is invalid, expired or already used. Submit a new subscription request.', 'yoohw-advanced-notifications' ) );
		if ( ! is_email( $email ) || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return $invalid; }
		if ( ! $this->acquire_campaign_lock( '__privacy__', 2 ) ) { return new WP_Error( 'signup_busy', __( 'Subscription storage is busy. Please try again.', 'yoohw-advanced-notifications' ) ); }
		try {
			$hash = hash( 'sha256', $email ); $key = 'yoohw_an_signup_' . $hash; $pending = $this->read_durable_option( $key );
			if ( $this->read_durable_option( 'yoohw_an_erased_' . $hash ) || ! is_array( $pending ) || ( $pending['email'] ?? '' ) !== $email || ( $pending['expires'] ?? 0 ) <= time() || empty( $pending['token_hash'] ) || ! hash_equals( $pending['token_hash'], hash( 'sha256', $token ) ) || ! hash_equals( $pending['suppression_fingerprint'] ?? '', $this->suppression_fingerprint( $email ) ) ) { return $invalid; }
			// Consume authority before publishing opt-in. Storage failure may require a fresh request; it must never enable replay.
			$pending['token_hash'] = ''; $pending['consumed_at'] = time();
			if ( false === $this->write_durable_option( $key, $pending ) || '' !== ( $this->read_durable_option( $key )['token_hash'] ?? null ) ) { throw new RuntimeException( 'Confirmation could not be consumed.' ); }
			$record = [ 'email' => $email, 'status' => 'subscribed', 'source' => $pending['source'], 'requested_at' => $pending['requested_at'], 'recorded_at' => time(), 'confirmed_at' => time(), 'recorded_by' => 0, 'suppression_fingerprint' => $pending['suppression_fingerprint'] ];
			$record_key = 'yoohw_an_subscription_' . $hash;
			if ( false === $this->write_durable_option( $record_key, $record ) || $record !== $this->read_durable_option( $record_key ) || $this->read_durable_option( 'yoohw_an_erased_' . $hash ) ) { throw new RuntimeException( 'Confirmed subscription could not be verified.' ); }
			return true;
		} catch ( Throwable $error ) { return new WP_Error( 'signup_storage', __( 'The subscription could not be saved. Submit a new request and try again.', 'yoohw-advanced-notifications' ) ); }
		finally { $this->release_campaign_lock( '__privacy__' ); }
	}
	public function subscription_shortcode() {
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_signup">';
		wp_nonce_field( 'yoohw_an_signup' );
		echo '<p><label>' . esc_html__( 'Email address', 'yoohw-advanced-notifications' ) . '<input type="email" name="email" required autocomplete="email"></label></p><p><label><input type="checkbox" name="consent" value="1" required> ' . esc_html__( 'I would like to receive campaign emails from this store. I can unsubscribe at any time.', 'yoohw-advanced-notifications' ) . '</label></p><div style="display:none" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'yoohw-advanced-notifications' ) . '<input name="signup_website" tabindex="-1" autocomplete="off"></label></div><button type="submit">' . esc_html__( 'Request subscription', 'yoohw-advanced-notifications' ) . '</button><p>' . esc_html__( 'Check your email to confirm. Submitting this form does not subscribe you.', 'yoohw-advanced-notifications' ) . '</p></form>';
		return ob_get_clean();
	}
	public function handle_subscription_request() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Use the subscription form.', 'yoohw-advanced-notifications' ), '', [ 'response' => 405 ] ); }
		check_admin_referer( 'yoohw_an_signup' );
		$consent = isset( $_POST['consent'] ) && is_string( $_POST['consent'] ) && '1' === $_POST['consent'];
		if ( ! $consent ) { wp_die( esc_html__( 'Choose whether you want to request campaign emails.', 'yoohw-advanced-notifications' ), '', [ 'response' => 400 ] ); }
		$result = true;
		if ( empty( $_POST['signup_website'] ) ) { $result = $this->request_subscription( isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '', 'signup_form' ); }
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 400 ] ); }
		wp_die( esc_html__( 'If this address is eligible, a confirmation email has been requested. Check your inbox and spam folder. Requests are limited; you can try again later.', 'yoohw-advanced-notifications' ), esc_html__( 'Check your email', 'yoohw-advanced-notifications' ), [ 'response' => 200 ] );
	}
	public function handle_subscription_confirmation() {
		nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow' );
		$posting = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		$input = $posting ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- GET is read-only; POST verifies nonce below. The token proves mailbox ownership.
		$email = isset( $input['email'] ) && is_string( $input['email'] ) ? strtolower( trim( wp_unslash( $input['email'] ) ) ) : '';
		$token = isset( $input['token'] ) && is_string( $input['token'] ) ? wp_unslash( $input['token'] ) : '';
		if ( ! $posting ) {
			if ( ! is_email( $email ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { wp_die( esc_html__( 'Invalid confirmation link.', 'yoohw-advanced-notifications' ), '', [ 'response' => 400 ] ); }
			ob_start(); echo '<h1>' . esc_html__( 'Confirm your email subscription', 'yoohw-advanced-notifications' ) . '</h1><p>' . esc_html__( 'Confirm that you want campaign emails from this store. This also replaces an earlier unsubscribe for this address. You can unsubscribe again at any time.', 'yoohw-advanced-notifications' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_confirm_subscription"><input type="hidden" name="email" value="' . esc_attr( $email ) . '"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">'; wp_nonce_field( 'yoohw_an_confirm_subscription' ); echo '<button type="submit">' . esc_html__( 'Confirm subscription', 'yoohw-advanced-notifications' ) . '</button></form>';
			wp_die( ob_get_clean(), esc_html__( 'Confirm subscription', 'yoohw-advanced-notifications' ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup and all values escaped above.
		}
		check_admin_referer( 'yoohw_an_confirm_subscription' ); $result = $this->confirm_subscription( $email, $token );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 400 ] ); }
		wp_die( esc_html__( 'Your email subscription is confirmed. You can unsubscribe from any campaign email.', 'yoohw-advanced-notifications' ), esc_html__( 'Subscribed', 'yoohw-advanced-notifications' ), [ 'response' => 200 ] );
	}
	public function render_account_subscription() {
		$user = wp_get_current_user(); if ( ! $user->exists() ) { return; }
		$state = $this->subscription_record( strtolower( $user->user_email ) );
		if ( ! empty( $state['confirmation_pending'] ) ) { echo '<p>' . esc_html__( 'Email confirmation is pending. Your current preference remains in effect until you confirm.', 'yoohw-advanced-notifications' ) . '</p>'; }
		echo '<section><h2>' . esc_html__( 'Campaign email preference', 'yoohw-advanced-notifications' ) . '</h2><p>' . esc_html__( 'Preference for your current account email:', 'yoohw-advanced-notifications' ) . ' ' . esc_html( $state['status'] ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yoohw_an_account_subscription">'; wp_nonce_field( 'yoohw_an_account_subscription' );
		echo '<p><button type="submit" name="operation" value="request">' . esc_html__( 'Request subscription by email', 'yoohw-advanced-notifications' ) . '</button> <button type="submit" name="operation" value="unsubscribe">' . esc_html__( 'Unsubscribe', 'yoohw-advanced-notifications' ) . '</button></p><p>' . esc_html__( 'A subscription request requires email confirmation. Unsubscribe applies immediately.', 'yoohw-advanced-notifications' ) . '</p></form></section>';
	}
	public function handle_account_subscription() {
		if ( ! is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ), '', [ 'response' => 403 ] ); }
		check_admin_referer( 'yoohw_an_account_subscription' );
		$email = strtolower( wp_get_current_user()->user_email );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? $_POST['operation'] : '';
		$result = 'unsubscribe' === $operation ? $this->add_unsubscribed_email( $email ) : ( 'request' === $operation ? $this->request_subscription( $email, 'my_account' ) : false );
		if ( ! $result || is_wp_error( $result ) ) { wp_die( esc_html__( 'The preference could not be saved. Try again later.', 'yoohw-advanced-notifications' ), '', [ 'response' => 503 ] ); }
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) ); exit;
	}
	public function cleanup_subscription_requests() {
		if ( ! $this->acquire_campaign_lock( '__privacy__' ) ) { return; }
		try {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Expiring request/rate records are plugin-owned and never used after their expiry.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'yoohw_an_signup_' ) . '%' ), ARRAY_A );
			foreach ( $rows as $row ) { $value = maybe_unserialize( $row['option_value'] ); if ( is_array( $value ) && ( $value['expires'] ?? 0 ) <= time() ) { delete_option( $row['option_name'] ); } }
		} finally { $this->release_campaign_lock( '__privacy__' ); }
	}
	private function get_subscribed_recipients() {
		$recipients = [];
		foreach ( $this->read_durable_options( 'yoohw_an_subscription_' ) as $record ) {
			$email = strtolower( $record['email'] ?? '' );
			if ( ! is_email( $email ) || 'subscribed' !== ( $record['status'] ?? '' ) || $this->is_email_unsubscribed( $email ) ) { continue; }
			$recipients[ $email ] = [ 'email' => $email, 'name' => '', 'country' => '', 'state' => '', 'city' => '' ];
		}
		return array_values( $recipients );
	}
}
