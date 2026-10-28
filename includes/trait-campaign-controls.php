<?php
/** Campaign controls and site-wide admission limits. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait YOOHW_AN_Campaign_Controls {
	private function control_key( $id ) { return 'yoohw_an_control_' . hash( 'sha256', $id ); }
	private function campaign_control( $id ) {
		return (array) $this->read_durable_option( $this->control_key( $id ) );
	}
	private function campaign_blocked( $id ) {
		$control = $this->campaign_control( $id );
		$record = $this->read_durable_option( $this->campaign_record_key( $id ) );
		return ! empty( $control['paused'] ) || ! empty( $control['cancelled'] ) || ! empty( $record['cancelled'] ) || ! empty( $record['retained'] );
	}
	private function control_campaign( $id, $operation ) {
		if ( ! in_array( $operation, [ 'pause', 'resume', 'cancel' ], true ) || ! $this->acquire_campaign_lock( $id ) ) {
			return new WP_Error( 'busy', __( 'Campaign is busy. Try again after the current preparation step.', 'yoohw-advanced-notifications' ) );
		}
		try {
			$record = $this->get_campaign_history()[ $id ] ?? [];
			if ( ! $record || ! empty( $record['retained'] ) ) { return new WP_Error( 'missing', __( 'Campaign unavailable.', 'yoohw-advanced-notifications' ) ); }
			$control = $this->campaign_control( $id );
			if ( ! empty( $control['cancelled'] ) || ! empty( $record['cancelled'] ) ) { return new WP_Error( 'cancelled', __( 'Cancelled campaigns cannot resume. Duplicate into a new draft instead.', 'yoohw-advanced-notifications' ) ); }
			if ( ! $this->acquire_campaign_lock( '__admit_' . $id ) ) { return new WP_Error( 'busy', __( 'A recipient is being admitted. Try the control again.', 'yoohw-advanced-notifications' ) ); }
			try {
			$control['paused'] = 'pause' === $operation;
			$control['cancelled'] = 'cancel' === $operation;
			$control['changed_at'] = time();
			if ( false === $this->write_durable_option( $this->control_key( $id ), $control ) ) { throw new RuntimeException( 'Control could not be saved.' ); }
			if ( 'cancel' === $operation ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional cancellation must not overwrite an admitted worker.
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', last_error = %s, updated_at = %s WHERE campaign_id = %s AND status NOT IN ('processing','unknown','sent','skipped','skipped_unsubscribed','skipped_duplicate')", $this->get_recipient_tracking_table_name(), __( 'Cancelled before send began.', 'yoohw-advanced-notifications' ), current_time( 'mysql', true ), $id ) );
				$record['cancelled'] = true;
				$record['status'] = 'cancelled';
				$this->save_campaign_record( $record );
			}
			} finally { $this->release_campaign_lock( '__admit_' . $id ); }
			if ( 'resume' === $operation ) {
				$this->resume_campaign_preparation( $id );
				$this->recover_campaign_recipients( $record, true );
				$this->enqueue_campaign_dispatch( $id );
			}
			return true;
		} finally { $this->release_campaign_lock( $id ); }
	}

	/** A slot is reserved only after a unique recipient claim succeeds. Crashed slots expire conservatively. */
	private function admit_delivery( $id, $delivery, $email, $action_id ) {
		if ( ! $this->acquire_campaign_lock( '__admit_' . $id ) ) { $this->defer_admission( $id, $delivery, $email ); return false; }
		try {
			if ( $this->campaign_blocked( $id ) ) {
				$this->mark_delivery_wait( $id, $delivery, $email, 'paused_wait', __( 'Paused; use Resume to continue the original schedule.', 'yoohw-advanced-notifications' ) );
				return false;
			}
			if ( ! $this->acquire_campaign_lock( '__site_rate__' ) ) { $this->defer_admission( $id, $delivery, $email ); return false; }
			try {
				if ( $this->read_durable_option( 'yoohw_an_erased_' . hash( 'sha256', strtolower( $email ) ) ) ) { return false; }
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A final/claimed duplicate must not impose a new campaign rate wait.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,claim_token,scheduled_at_gmt FROM %i WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s', $this->get_recipient_tracking_table_name(), $id, $delivery, hash( 'sha256', strtolower( $email ) ) ), ARRAY_A );
				if ( ! $row || $row['claim_token'] || ! in_array( $row['status'], [ 'dispatch_pending', 'queued', 'scheduled', 'paused_wait', 'rate_wait' ], true ) ) { return false; }
				if ( ! empty( $row['scheduled_at_gmt'] ) && strtotime( $row['scheduled_at_gmt'] . ' UTC' ) > time() ) { return false; }
				$limits = (array) $this->read_durable_option( 'yoohw_an_limits' );
				$state = (array) $this->read_durable_option( 'yoohw_an_rate' );
				$now = time();
				$resume = 0;
				foreach ( [ 'minute' => 60, 'day' => DAY_IN_SECONDS ] as $period => $seconds ) {
					$start = (int) ( floor( $now / $seconds ) * $seconds );
					if ( ( $state[ $period ]['start'] ?? 0 ) !== $start ) { $state[ $period ] = [ 'start' => $start, 'used' => 0 ]; }
					if ( ! empty( $limits[ $period ] ) && $state[ $period ]['used'] >= $limits[ $period ] ) { $resume = max( $resume, $start + $seconds ); }
				}
				if ( $resume ) {
					$control = $this->campaign_control( $id );
					$control['rate_resume_at'] = $resume;
					if ( false === $this->write_durable_option( $this->control_key( $id ), $control ) ) { throw new RuntimeException( 'Rate wait could not be saved.' ); }
					$this->mark_delivery_wait( $id, $delivery, $email, 'rate_wait', __( 'Site sending limit reached. Original due time is preserved; admission will be checked again.', 'yoohw-advanced-notifications' ) );
					$this->enqueue_campaign_dispatch( $id );
					return false;
				}
				$claim = $this->claim_recipient_delivery( $id, $delivery, $email, $action_id );
				if ( $claim ) {
					$state['minute']['used']++;
					$state['day']['used']++;
					if ( false === $this->write_durable_option( 'yoohw_an_rate', $state ) ) {
						$this->finish_delivery( $id, $delivery, $email, $claim, 'failed', __( 'Rate reservation storage failed before transport.', 'yoohw-advanced-notifications' ), 'configuration' );
						return false;
					}
				}
				return $claim;
			} finally { $this->release_campaign_lock( '__site_rate__' ); }
		} finally { $this->release_campaign_lock( '__admit_' . $id ); }
	}
	private function defer_admission( $id, $delivery, $email ) {
		$this->mark_delivery_wait( $id, $delivery, $email, 'rate_wait', __( 'Another worker is reserving a site slot. Waiting does not consume a retry.', 'yoohw-advanced-notifications' ) );
		if ( ! $this->campaign_blocked( $id ) ) { as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::ACTION_DISPATCH_CAMPAIGN, [ $id ], self::ACTION_GROUP . '-dispatch', true ); }
	}
	private function mark_delivery_wait( $id, $delivery, $email, $status, $message ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Admission can change only unclaimed work; attempts and original due time are untouched.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s, action_id = 0, last_error = %s, updated_at = %s WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND claim_token = '' AND status IN ('dispatch_pending','queued','scheduled','paused_wait','rate_wait')", $this->get_recipient_tracking_table_name(), $status, $message, current_time( 'mysql', true ), $id, $delivery, hash( 'sha256', strtolower( $email ) ) ) );
	}
	public function handle_campaign_control() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) ); }
		check_admin_referer( 'yoohw_an_control' );
		$id = sanitize_text_field( wp_unslash( $_POST['campaign_id'] ?? '' ) );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		$result = $this->control_campaign( $id, $operation );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
		wp_safe_redirect( $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'logs', 'campaign_id' => $id, 'control_saved' => 1 ] ) );
		exit;
	}
	private function render_campaign_controls( $campaign ) {
		$id = $campaign['campaign_id'];
		$control = $this->campaign_control( $id );
		if ( ! empty( $campaign['internal_name'] ) ) { echo '<h2>' . esc_html( $campaign['internal_name'] ) . '</h2>'; }
		echo '<p>' . esc_html__( 'Pause/Cancel cannot recall mail already handed to the mail system. Workers admitted before the change may finish.', 'yoohw-advanced-notifications' ) . '</p>';
		if ( ! empty( $_GET['control_saved'] ) ) { echo '<p role="status">' . esc_html__( 'Campaign control saved.', 'yoohw-advanced-notifications' ) . '</p>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( [ 'pause' => __( 'Pause', 'yoohw-advanced-notifications' ), 'resume' => __( 'Resume', 'yoohw-advanced-notifications' ), 'cancel' => __( 'Cancel', 'yoohw-advanced-notifications' ) ] as $operation => $label ) {
			if ( ! empty( $control['cancelled'] ) || ! empty( $campaign['cancelled'] ) || ( 'resume' === $operation && empty( $control['paused'] ) ) || ( 'pause' === $operation && ! empty( $control['paused'] ) ) ) { continue; }
			echo '<form class="an-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'yoohw_an_control' );
			echo '<input type="hidden" name="action" value="yoohw_an_control"><input type="hidden" name="campaign_id" value="' . esc_attr( $id ) . '"><button class="button" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button></form> ';
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live diagnostic timestamps must reflect other workers.
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(updated_at) FROM %i WHERE campaign_id = %s AND status IN ('sent','failed','unknown','processing','skipped_unsubscribed')", $this->get_recipient_tracking_table_name(), $id ) );
		echo '<p>' . esc_html__( 'Latest recipient progress (UTC):', 'yoohw-advanced-notifications' ) . ' ' . esc_html( $last ?: '—' ) . '</p>';
		if ( ! empty( $control['paused'] ) ) { echo '<p>' . esc_html__( 'Paused. Resume continues the same recipients, attempts and original due times.', 'yoohw-advanced-notifications' ) . '</p>'; }
		if ( ( $control['rate_resume_at'] ?? 0 ) > time() ) { echo '<p>' . esc_html__( 'Site sending limit: next admission check (UTC):', 'yoohw-advanced-notifications' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', $control['rate_resume_at'] ) ) . '</p>'; }
		echo '<p>' . esc_html__( 'Pending delivery jobs:', 'yoohw-advanced-notifications' ) . ' ' . esc_html( (string) ( $campaign['pending_action_count'] ?? 0 ) ) . ' — ' . esc_html__( 'Last reconciliation:', 'yoohw-advanced-notifications' ) . ' ' . esc_html( ! empty( $campaign['reconciled_at'] ) ? gmdate( 'Y-m-d H:i:s', $campaign['reconciled_at'] ) . ' UTC' : '—' ) . '</p>';
		echo '<a class="button" href="' . esc_url( admin_url( 'tools.php?page=action-scheduler&s=' . rawurlencode( $id ) ) ) . '">' . esc_html__( 'Inspect Scheduled Actions', 'yoohw-advanced-notifications' ) . '</a>';
		$this->render_recipient_diagnostics( $id );
	}
	private function render_recipient_diagnostics( $id ) {
		$search = sanitize_text_field( wp_unslash( $_GET['recipient_search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_GET['recipient_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<form method="get"><input type="hidden" name="page" value="yoohw-advanced-notifications"><input type="hidden" name="tab" value="campaigns"><input type="hidden" name="view" value="logs"><input type="hidden" name="campaign_id" value="' . esc_attr( $id ) . '"><input name="recipient_search" aria-label="' . esc_attr__( 'Find email or reason', 'yoohw-advanced-notifications' ) . '" value="' . esc_attr( $search ) . '"><select name="recipient_status">';
		foreach ( [ '' => __( 'All recipient states', 'yoohw-advanced-notifications' ), 'dispatch_pending' => __( 'Waiting', 'yoohw-advanced-notifications' ), 'paused_wait' => __( 'Paused', 'yoohw-advanced-notifications' ), 'rate_wait' => __( 'Rate limited', 'yoohw-advanced-notifications' ), 'processing' => __( 'Processing', 'yoohw-advanced-notifications' ), 'sent' => __( 'Handed to mail system', 'yoohw-advanced-notifications' ), 'failed' => __( 'Failed', 'yoohw-advanced-notifications' ), 'queue_failed' => __( 'Queue failed', 'yoohw-advanced-notifications' ), 'unknown' => __( 'Unknown', 'yoohw-advanced-notifications' ), 'skipped_unsubscribed' => __( 'Unsubscribed', 'yoohw-advanced-notifications' ), 'cancelled' => __( 'Cancelled', 'yoohw-advanced-notifications' ) ] as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><button class="button">' . esc_html__( 'Filter recipients', 'yoohw-advanced-notifications' ) . '</button></form>';
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded, searchable live operational diagnostics.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT email,status,attempts,last_error,scheduled_at_gmt FROM %i WHERE campaign_id = %s AND (%s = '' OR status = %s) AND (email LIKE %s OR last_error LIKE %s) ORDER BY id LIMIT 200", $this->get_recipient_tracking_table_name(), $id, $status, $status, $like, $like ), ARRAY_A );
		echo '<p>' . esc_html__( 'Up to 200 matching recipients. Original due times below are UTC; pauses, queue delays and limits may delay actual handoff.', 'yoohw-advanced-notifications' ) . '</p><table class="widefat striped"><tr><th>' . esc_html__( 'Email', 'yoohw-advanced-notifications' ) . '</th><th>' . esc_html__( 'State / attempts', 'yoohw-advanced-notifications' ) . '</th><th>' . esc_html__( 'Original due / reason', 'yoohw-advanced-notifications' ) . '</th></tr>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['email'] ) . '</td><td>' . esc_html( $row['status'] . ' / ' . $row['attempts'] ) . '</td><td>' . esc_html( $row['scheduled_at_gmt'] . ' — ' . $row['last_error'] ) . '</td></tr>'; }
		echo '</table>';
	}
}
