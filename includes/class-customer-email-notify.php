<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-campaign-reliability.php';

class YOOHW_AN_Customer_Email_Notify {
	use YOOHW_AN_Campaign_Reliability;

	const ACTION_SEND_SINGLE_EMAIL = 'yoohw_an_send_single_email';
	const ACTION_UNSUBSCRIBE       = 'yoohw_an_unsubscribe';
	const ACTION_RESCHEDULE_FAILED = 'yoohw_an_reschedule_failed';
	const ACTION_RECONCILE_CAMPAIGNS = 'yoohw_an_reconcile_campaigns';
	const ACTION_DISPATCH_CAMPAIGN = 'yoohw_an_dispatch_campaign';
	const ACTION_GROUP             = 'yoohw-advanced-notifications';
	const LOG_SOURCE               = 'yoohw-advanced-notifications';
	const PREVIEW_TRANSIENT_PREFIX = 'yoohw_an_preview_';
	const DELIVERY_CLAIM_PREFIX    = 'yoohw_an_delivery_';
	const CAMPAIGN_PAYLOAD_PREFIX  = 'yoohw_an_payload_';
	const CAMPAIGN_PAYLOAD_SCHEMA  = 1;
	const ACTION_ARGS_SCHEMA       = 2;
	const RECIPIENT_TRACKING_SCHEMA = 3;
	const OPTION_FROM_EMAIL        = 'yoohw_an_from_email';
	const OPTION_FROM_NAME         = 'yoohw_an_from_name';
	const OPTION_REPLY_TO_EMAIL    = 'yoohw_an_reply_to_email';
	const OPTION_SUPPRESS_AUTOMATED_REPLIES = 'yoohw_an_suppress_automated_replies';
	const OPTION_NO_REPLY_EMAIL    = 'yoohw_an_no_reply_email';
	const OPTION_UNSUBSCRIBED_EMAILS = 'yoohw_an_unsubscribed_emails';
	const OPTION_CAMPAIGNS         = 'yoohw_an_campaigns';
	const OPTION_CAMPAIGN_LOGS     = 'yoohw_an_campaign_logs';
	const OPTION_TRACKING_SCHEMA   = 'yoohw_an_tracking_schema';
	const ORDER_QUERY_LIMIT        = 200;
	const USER_QUERY_LIMIT         = 500;
	const CAMPAIGN_LOG_LIMIT       = 1000;
	const CAMPAIGN_HISTORY_PER_PAGE = 20;
	const CAMPAIGN_LOG_PER_PAGE     = 50;
	const DISPATCH_BATCH_SIZE       = 100;
	const RECIPIENT_INSERT_BATCH_SIZE = 250;
	const SOURCE_PRODUCT_CUSTOMERS   = 'product_customers';
	const SOURCE_REGISTERED_USERS    = 'registered_users';
	const SOURCE_WOOCOMMERCE_CUSTOMERS = 'woocommerce_customers';
	const SOURCE_MANUAL_EMAILS        = 'manual_emails';

	private $current_from_email = '';
	private $current_from_name  = '';
	private $last_mail_error    = '';
	private $mail_handoff_started = false;
	private $current_action_id = 0;
	private $recovery_enabled = true;
	private $campaign_payload_cache = [];
	private $recipient_tracking_table_ready = null;

	public function __construct() {
		// Register after WooCommerce sorts Marketing at priority 99 to preserve our position.
		add_action( 'admin_menu', [ $this, 'add_submenu' ], 100 );
		add_action( 'admin_post_yoohw_an_send_product_customer_email', [ $this, 'handle_send_email' ] );
		add_action( 'admin_post_yoohw_an_save_customer_email_notify_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_' . self::ACTION_UNSUBSCRIBE, [ $this, 'handle_unsubscribe' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION_UNSUBSCRIBE, [ $this, 'handle_unsubscribe' ] );
		add_action( 'admin_post_' . self::ACTION_RESCHEDULE_FAILED, [ $this, 'handle_reschedule_failed_campaign' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		add_action( self::ACTION_SEND_SINGLE_EMAIL, [ $this, 'process_single_email' ], 10, 1 );
		add_action( self::ACTION_DISPATCH_CAMPAIGN, [ $this, 'dispatch_campaign_batch' ], 10, 1 );
		add_action( self::ACTION_RECONCILE_CAMPAIGNS, [ $this, 'reconcile_open_campaigns' ], 10, 1 );
		add_action( 'init', [ $this, 'maybe_install_recipient_tracking_table' ], 5 );
		add_action( 'init', [ $this, 'maybe_schedule_campaign_reconciliation' ], 20 );
		add_action( 'init', [ $this, 'maybe_import_legacy_jobs' ], 10 );
		add_action( 'action_scheduler_before_execute', [ $this, 'capture_action_id' ], 1 );
		add_action( 'action_scheduler_after_execute', [ $this, 'clear_action_id' ], 100 );
		add_action( 'action_scheduler_failed_execution', [ $this, 'clear_action_id' ], 100 );
	}

	public function maybe_install_recipient_tracking_table() {
		if (
			self::RECIPIENT_TRACKING_SCHEMA === absint( get_option( self::OPTION_TRACKING_SCHEMA, 0 ) ) &&
			$this->recipient_tracking_table_exists()
		) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->get_recipient_tracking_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id varchar(64) NOT NULL,
				delivery_id varchar(64) NOT NULL,
				email_hash char(64) NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				recipient_name varchar(190) NOT NULL DEFAULT '',
				country varchar(8) NOT NULL DEFAULT '',
				state varchar(100) NOT NULL DEFAULT '',
				city varchar(190) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL,
				claim_token varchar(36) NOT NULL DEFAULT '',
				attempts smallint unsigned NOT NULL DEFAULT 0,
				error_kind varchar(32) NOT NULL DEFAULT '',
				action_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scheduled_at_gmt datetime NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY delivery_recipient (delivery_id,email_hash),
				KEY campaign_delivery (campaign_id,delivery_id),
				KEY campaign_status (campaign_id,status),
				KEY action_id (action_id)
			) {$charset_collate};"
		);

		$this->recipient_tracking_table_ready = null;

		if ( $this->recipient_tracking_table_exists() && count( array_intersect( [ 'claim_token', 'attempts', 'error_kind' ], $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ) ) ) ) === 3 ) {
			$this->recipient_tracking_table_ready = true;
			update_option( self::OPTION_TRACKING_SCHEMA, self::RECIPIENT_TRACKING_SCHEMA, false );
		}
	}

	public function maybe_schedule_campaign_reconciliation() {
		if ( ! wp_next_scheduled( self::ACTION_RECONCILE_CAMPAIGNS ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', self::ACTION_RECONCILE_CAMPAIGNS );
		}
	}

	public function deactivate() {
		$this->recovery_enabled = false;
		wp_unschedule_hook( self::ACTION_RECONCILE_CAMPAIGNS );
		wp_unschedule_hook( self::ACTION_SEND_SINGLE_EMAIL );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_SEND_SINGLE_EMAIL, null, self::ACTION_GROUP );
			as_unschedule_all_actions( self::ACTION_DISPATCH_CAMPAIGN, null, self::ACTION_GROUP . '-dispatch' );
		}

		if ( $this->recipient_tracking_table_exists() ) {
			global $wpdb;
			$table = $this->get_recipient_tracking_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
			$wpdb->query( $wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', last_error = %s, updated_at = %s WHERE status IN ('dispatch_pending', 'queued', 'scheduled')",
				$table,
				__( 'Plugin deactivated before delivery.', 'yoohw-advanced-notifications' ),
				current_time( 'mysql', true )
			) );
			foreach ( $this->get_campaign_history() as $campaign ) {
				if ( in_array( $campaign['status'], [ 'preparing', 'dispatching', 'queueing', 'scheduling', 'queued', 'scheduled', 'processing' ], true ) ) {
					$campaign['status'] = 'cancelled';
					$campaign['cancelled'] = true;
					$this->save_campaign_record( $campaign );
				}
			}
		}
	}

	public function reconcile_open_campaigns( $campaign_id = '' ) {
		$campaigns = $this->get_campaign_history();
		$campaign_id = sanitize_text_field( $campaign_id );

		foreach ( $campaigns as $stored_campaign_id => $campaign ) {
			if ( '' !== $campaign_id && $campaign_id !== $stored_campaign_id ) {
				continue;
			}

			$status = isset( $campaign['status'] ) ? sanitize_key( $campaign['status'] ) : '';

			if ( ! in_array( $status, [ 'preparing', 'needs_attention', 'dispatching', 'queue_failed', 'schedule_failed', 'queueing', 'queued', 'scheduling', 'scheduled', 'processing', 'completed_with_errors' ], true ) ) {
				continue;
			}

			$this->reconcile_campaign_status( $stored_campaign_id );
		}
	}

	private function get_recipient_tracking_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'yoohw_an_recipients';
	}

	private function recipient_tracking_table_exists() {
		if ( null !== $this->recipient_tracking_table_ready ) {
			return (bool) $this->recipient_tracking_table_ready;
		}

		global $wpdb;

		$table_name = $this->get_recipient_tracking_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
		$this->recipient_tracking_table_ready = $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );

		return $this->recipient_tracking_table_ready;
	}

	private function track_recipient_status( $campaign_id, $email, $status, $action_id = 0, $message = '' ) {
		$campaign_id = sanitize_text_field( $campaign_id );
		$email       = strtolower( sanitize_email( $email ) );
		$status      = sanitize_key( $status );

		$trackable_statuses = [
			'dispatch_pending',
			'queued',
			'scheduled',
			'processing',
			'sent',
			'failed',
			'queue_failed',
			'schedule_failed',
			'skipped',
			'skipped_unsubscribed',
			'skipped_duplicate',
			'cancelled',
			'unknown',
		];

		if ( empty( $campaign_id ) || empty( $email ) || ! is_email( $email ) || ! in_array( $status, $trackable_statuses, true ) ) {
			return false;
		}

		if ( ! $this->recipient_tracking_table_exists() ) {
			$this->maybe_install_recipient_tracking_table();
		}

		if ( ! $this->recipient_tracking_table_exists() ) {
			return false;
		}

		$campaigns  = $this->get_campaign_history();
		$campaign   = isset( $campaigns[ $campaign_id ] ) && is_array( $campaigns[ $campaign_id ] ) ? $campaigns[ $campaign_id ] : [];
		$delivery_id = ! empty( $campaign['delivery_id'] ) ? sanitize_text_field( $campaign['delivery_id'] ) : $campaign_id;

		global $wpdb;

		$table_name = $this->get_recipient_tracking_table_name();
		$now        = current_time( 'mysql', true );
		$email_hash = hash( 'sha256', $email );
		$message    = sanitize_text_field( $message );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
		return false !== $wpdb->query( $wpdb->prepare(
			"INSERT INTO %i
				(campaign_id, delivery_id, email_hash, status, action_id, last_error, created_at, updated_at)
			VALUES (%s, %s, %s, %s, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				status = CASE
					WHEN status IN ('sent','processing','unknown','failed','skipped','skipped_unsubscribed','cancelled') THEN status
					ELSE VALUES(status)
				END,
				action_id = CASE WHEN VALUES(action_id) > 0 THEN VALUES(action_id) ELSE action_id END,
				last_error = CASE
					WHEN status IN ('sent','processing','unknown','failed','skipped','skipped_unsubscribed','cancelled') THEN last_error
					ELSE VALUES(last_error)
				END,
				updated_at = VALUES(updated_at)",
			$table_name,
			$campaign_id,
			$delivery_id,
			$email_hash,
			$status,
			absint( $action_id ),
			$message,
			$now,
			$now
		) );
	}

	private function get_recipient_tracking_counts( $campaign_id, $delivery_id ) {
		global $wpdb;

		if ( ! $this->recipient_tracking_table_exists() ) {
			return [];
		}

		$table_name = $this->get_recipient_tracking_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total
				FROM %i
				WHERE campaign_id = %s AND delivery_id = %s
				GROUP BY status",
				$table_name,
				sanitize_text_field( $campaign_id ),
				sanitize_text_field( $delivery_id )
			),
			ARRAY_A
		);
		$counts = [];

		foreach ( (array) $rows as $row ) {
			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';

			if ( '' !== $status ) {
				$counts[ $status ] = absint( isset( $row['total'] ) ? $row['total'] : 0 );
			}
		}

		return $counts;
	}

	private function get_recipient_tracking_status_map( $campaign_id, $delivery_id ) {
		global $wpdb;

		if ( ! $this->recipient_tracking_table_exists() ) {
			return [];
		}

		$table_name = $this->get_recipient_tracking_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT email_hash, status
				FROM %i
				WHERE campaign_id = %s AND delivery_id = %s",
				$table_name,
				sanitize_text_field( $campaign_id ),
				sanitize_text_field( $delivery_id )
			),
			ARRAY_A
		);
		$statuses = [];

		foreach ( (array) $rows as $row ) {
			$email_hash = isset( $row['email_hash'] ) ? sanitize_text_field( $row['email_hash'] ) : '';
			$status     = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';

			if ( '' !== $email_hash && '' !== $status ) {
				$statuses[ $email_hash ] = $status;
			}
		}

		return $statuses;
	}

	private function prepare_campaign_recipients_for_dispatch( $payload, $recipients ) {
		$payload      = is_array( $payload ) ? $payload : [];
		$campaign_id = isset( $payload['campaign_id'] ) ? sanitize_text_field( $payload['campaign_id'] ) : '';
		$delivery_id = ! empty( $payload['delivery_id'] ) ? sanitize_text_field( $payload['delivery_id'] ) : $campaign_id;

		if ( empty( $campaign_id ) || empty( $delivery_id ) || empty( $recipients ) || ! is_array( $recipients ) ) {
			return new WP_Error( 'yoohw_an_invalid_dispatch_recipients', __( 'Campaign recipients could not be prepared for background dispatch.', 'yoohw-advanced-notifications' ) );
		}

		$this->maybe_install_recipient_tracking_table();

		if ( ! $this->recipient_tracking_table_exists() ) {
			return new WP_Error( 'yoohw_an_missing_tracking_table', __( 'Recipient tracking storage is unavailable.', 'yoohw-advanced-notifications' ) );
		}

		global $wpdb;

		$table_name = $this->get_recipient_tracking_table_name();
		$now        = current_time( 'mysql', true );
		$rows       = [];
		$invalid    = 0;

		foreach ( $recipients as $recipient ) {
			$recipient = $this->sanitize_recipient_context( $recipient );
			$email     = isset( $recipient['email'] ) ? strtolower( sanitize_email( $recipient['email'] ) ) : '';

			if ( empty( $email ) || ! is_email( $email ) ) {
				continue;
			}

			$status           = 'dispatch_pending';
			$scheduled_at_gmt = $now;
			$last_error       = '';

			if ( $this->is_payload_scheduled( $payload ) ) {
				$schedule = $this->get_recipient_scheduled_delivery( $payload, $recipient );

				if ( empty( $schedule['timestamp'] ) ) {
					$status           = 'failed';
					$scheduled_at_gmt = $now;
					$last_error       = $this->format_schedule_log_message( $schedule, __( 'Invalid scheduled date/time. Create a new campaign with a valid time.', 'yoohw-advanced-notifications' ) );
					$invalid++;
				} else {
					$scheduled_at_gmt = gmdate( 'Y-m-d H:i:s', absint( $schedule['timestamp'] ) );
				}
			}

			$rows[] = [
				'campaign_id'     => $campaign_id,
				'delivery_id'     => $delivery_id,
				'email_hash'      => hash( 'sha256', $email ),
				'email'           => $email,
				'recipient_name'  => isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '',
				'country'         => isset( $recipient['country'] ) ? $this->normalize_country_code( $recipient['country'] ) : '',
				'state'           => isset( $recipient['state'] ) ? $this->normalize_location_part( $recipient['state'] ) : '',
				'city'            => isset( $recipient['city'] ) ? $this->normalize_location_part( $recipient['city'] ) : '',
				'status'          => $status,
				'scheduled_at_gmt' => $scheduled_at_gmt,
				'last_error'      => sanitize_text_field( $last_error ),
			];
		}

		foreach ( array_chunk( $rows, self::RECIPIENT_INSERT_BATCH_SIZE ) as $chunk ) {
			$placeholders = [];
			$values       = [ $table_name ];

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,0,%s,%s,%s,%s)';
				array_push(
					$values,
					$row['campaign_id'],
					$row['delivery_id'],
					$row['email_hash'],
					$row['email'],
					$row['recipient_name'],
					$row['country'],
					$row['state'],
					$row['city'],
					$row['status'],
					$row['scheduled_at_gmt'],
					$row['last_error'],
					$now,
					$now
				);
			}

			$sql = "INSERT INTO %i
				(campaign_id,delivery_id,email_hash,email,recipient_name,country,state,city,status,action_id,scheduled_at_gmt,last_error,created_at,updated_at)
				VALUES " . implode( ',', $placeholders ) . "
				ON DUPLICATE KEY UPDATE
					email = IF(email = '', VALUES(email), email),
					recipient_name = IF(recipient_name = '', VALUES(recipient_name), recipient_name)";

			// The SQL consists only of the fixed statement and repeated literal placeholders above. All values, including the table identifier, are prepared.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared bulk placeholders in the custom delivery table; uncached writes keep worker status current.
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( false === $result ) {
				return new WP_Error( 'yoohw_an_dispatch_store_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Recipient dispatch storage failed.', 'yoohw-advanced-notifications' ) );
			}
		}

		$counts = $this->get_recipient_tracking_counts( $campaign_id, $delivery_id );

		return [
			'total'            => count( $rows ),
			'dispatch_pending' => isset( $counts['dispatch_pending'] ) ? absint( $counts['dispatch_pending'] ) : 0,
			'schedule_failed'  => isset( $counts['schedule_failed'] ) ? absint( $counts['schedule_failed'] ) : $invalid,
		];
	}

	private function enqueue_campaign_dispatch( $campaign_id ) {
		$campaign_id = sanitize_text_field( $campaign_id );

		if ( empty( $campaign_id ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		return absint(
			as_enqueue_async_action(
				self::ACTION_DISPATCH_CAMPAIGN,
				[ $campaign_id ],
				self::ACTION_GROUP . '-dispatch',
				false
			)
		);
	}

	private function update_tracking_row_state( $campaign_id, $delivery_id, $email_hash, $status, $action_id = 0, $message = '' ) {
		global $wpdb;
		// A late scheduler result must never overwrite a worker or a final outcome.
		return false !== $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET status = %s, action_id = %d, last_error = %s, updated_at = %s WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND status IN ('dispatch_pending','queued','scheduled','queue_failed','schedule_failed') AND claim_token = ''",
			$this->get_recipient_tracking_table_name(), $status, $action_id, $message, current_time( 'mysql', true ), $campaign_id, $delivery_id, $email_hash
		) );
	}

	public function dispatch_campaign_batch( $campaign_id ) {
		$campaign_id = sanitize_text_field( $campaign_id );
		if ( empty( $campaign_id ) || ! $this->acquire_campaign_lock( $campaign_id ) ) {
			return;
		}

		try {
			$manifest = $this->read_durable_option( $this->campaign_manifest_key( $campaign_id ) );
			if ( is_array( $manifest ) && ! $this->resume_campaign_preparation( $campaign_id, $manifest ) ) {
				return;
			}
			$campaigns = $this->get_campaign_history();

			if ( empty( $campaigns[ $campaign_id ] ) ) {
				return;
			}

			$campaign    = $campaigns[ $campaign_id ];
			$delivery_id = ! empty( $campaign['delivery_id'] ) ? sanitize_text_field( $campaign['delivery_id'] ) : $campaign_id;
			$payload     = $this->get_campaign_reschedule_payload( $campaign );

			if ( is_wp_error( $payload ) ) {
				$campaign['dispatch_error'] = $payload->get_error_message();
				$campaign['status'] = 'needs_attention';
				$this->save_campaign_record( $campaign );
				return;
			}

			$payload_reference = $this->store_campaign_email_payload( $payload );

			if ( is_wp_error( $payload_reference ) ) {
				$campaign['dispatch_error'] = $payload_reference->get_error_message();
				$campaign['status'] = 'needs_attention';
				$this->save_campaign_record( $campaign );
				return;
			}

			global $wpdb;

			$table_name = $this->get_recipient_tracking_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
			$rows       = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT email_hash,email,recipient_name,country,state,city,scheduled_at_gmt
					FROM %i
					WHERE campaign_id = %s AND delivery_id = %s AND status = 'dispatch_pending'
					ORDER BY id ASC
					LIMIT %d",
					$table_name,
					$campaign_id,
					$delivery_id,
					self::DISPATCH_BATCH_SIZE
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$recipient = [
					'email'   => isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
					'name'    => isset( $row['recipient_name'] ) ? sanitize_text_field( $row['recipient_name'] ) : '',
					'country' => isset( $row['country'] ) ? $this->normalize_country_code( $row['country'] ) : '',
					'state'   => isset( $row['state'] ) ? $this->normalize_location_part( $row['state'] ) : '',
					'city'    => isset( $row['city'] ) ? $this->normalize_location_part( $row['city'] ) : '',
				];
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts = attempts + 1 WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND status = 'dispatch_pending'", $table_name, $campaign_id, $delivery_id, $row['email_hash'] ) );
				$args = $this->build_email_action_args( $payload, $recipient, $payload_reference );

				if ( ! empty( $row['scheduled_at_gmt'] ) && strtotime( $row['scheduled_at_gmt'] . ' UTC' ) > time() ) {
					$scheduled_at = ! empty( $row['scheduled_at_gmt'] ) ? strtotime( $row['scheduled_at_gmt'] . ' UTC' ) : 0;
					$result       = $this->schedule_email_action( $args, $scheduled_at );
					$status       = is_wp_error( $result ) ? 'schedule_failed' : 'scheduled';
				} else {
					$result = $this->enqueue_email_action( $args );
					$status = is_wp_error( $result ) ? 'queue_failed' : 'queued';
				}

				$this->update_tracking_row_state(
					$campaign_id,
					$delivery_id,
					isset( $row['email_hash'] ) ? $row['email_hash'] : '',
					$status,
					is_wp_error( $result ) ? 0 : absint( $result ),
					is_wp_error( $result ) ? $result->get_error_message() : ''
				);
			}

			$this->reconcile_campaign_status( $campaign_id );

		} finally {
			$this->release_campaign_lock( $campaign_id );
		}
	}

	private function get_campaign_action_status_counts( $campaign_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status,args,extended_args FROM %i WHERE hook = %s AND (args LIKE %s OR extended_args LIKE %s)",
			$table_name, self::ACTION_SEND_SINGLE_EMAIL,
			'%' . $wpdb->esc_like( $campaign_id ) . '%', '%' . $wpdb->esc_like( $campaign_id ) . '%'
		), ARRAY_A );
		$counts = [];
		foreach ( (array) $rows as $row ) {
			$args = json_decode( $row['extended_args'] ?: $row['args'], true );
			if ( $campaign_id !== ( $args[0]['campaign_id'] ?? '' ) ) { continue; }
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
		}

		return $counts;
	}

	private function reconcile_campaign_status( $campaign_id ) {
		if ( ! $this->acquire_campaign_lock( $campaign_id ) ) {
			return [];
		}
		try {
			$this->resume_campaign_preparation( $campaign_id );
			return $this->reconcile_campaign_status_locked( $campaign_id );
		} finally {
			$this->release_campaign_lock( $campaign_id );
		}
	}

	private function reconcile_campaign_status_locked( $campaign_id ) {
		$campaign_id = sanitize_text_field( $campaign_id );
		$campaigns   = $this->get_campaign_history();

		if ( empty( $campaigns[ $campaign_id ] ) ) {
			return [];
		}

		$campaign      = $campaigns[ $campaign_id ];
		$delivery_id   = ! empty( $campaign['delivery_id'] ) ? sanitize_text_field( $campaign['delivery_id'] ) : $campaign_id;
		$recipient_count = isset( $campaign['recipient_count'] ) ? absint( $campaign['recipient_count'] ) : 0;
		$this->recover_campaign_recipients( $campaign );
		$tracking_counts = $this->get_recipient_tracking_counts( $campaign_id, $delivery_id );
		$action_counts = $this->get_campaign_action_status_counts( $campaign_id );
		$pending_actions = isset( $action_counts['pending'] ) ? absint( $action_counts['pending'] ) : 0;
		$dispatch_pending = isset( $tracking_counts['dispatch_pending'] ) ? absint( $tracking_counts['dispatch_pending'] ) : 0;
		$tracked_total = array_sum( $tracking_counts );
		$recipient_count = max( $recipient_count, $tracked_total );
		$terminal_statuses = [
			'sent',
			'failed',
			'queue_failed',
			'schedule_failed',
			'skipped',
			'skipped_unsubscribed',
			'skipped_duplicate',
			'cancelled',
			'unknown',
		];
		$error_statuses = [ 'failed', 'queue_failed', 'schedule_failed', 'skipped_duplicate', 'cancelled', 'unknown' ];
		$terminal_total = 0;
		$error_total    = 0;

		foreach ( $terminal_statuses as $terminal_status ) {
			$terminal_total += isset( $tracking_counts[ $terminal_status ] ) ? absint( $tracking_counts[ $terminal_status ] ) : 0;
		}

		foreach ( $error_statuses as $error_status ) {
			$error_total += isset( $tracking_counts[ $error_status ] ) ? absint( $tracking_counts[ $error_status ] ) : 0;
		}

		global $wpdb;
		$overdue = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE campaign_id = %s AND status IN ('queued','scheduled') AND updated_at < %s AND (scheduled_at_gmt IS NULL OR scheduled_at_gmt < %s)",
			$this->get_recipient_tracking_table_name(), $campaign_id, gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
		) );
		$pending_recipients = ( $tracking_counts['processing'] ?? 0 ) + ( $tracking_counts['queued'] ?? 0 ) + ( $tracking_counts['scheduled'] ?? 0 );
		$unresolved = max( 0, $recipient_count - $tracked_total );
		$campaign['recovery_message'] = '';
		if ( ! empty( $campaign['dispatch_error'] ) ) {
			$status = 'needs_attention';
			$campaign['recovery_message'] = $campaign['dispatch_error'];
		} elseif ( $dispatch_pending > 0 ) {
			$has_dispatch = function_exists( 'as_get_scheduled_actions' ) && as_get_scheduled_actions( [ 'hook' => self::ACTION_DISPATCH_CAMPAIGN, 'args' => [ $campaign_id ], 'status' => 'pending', 'per_page' => 1 ], 'ids' );
			$dispatch_id = $has_dispatch ? 1 : $this->enqueue_campaign_dispatch( $campaign_id );
			$status = $dispatch_id ? 'dispatching' : 'needs_attention';
			if ( ! $dispatch_id ) {
				$campaign['recovery_message'] = __( 'Dispatcher could not be queued. Check Action Scheduler, then use Recover / retry eligible recipients.', 'yoohw-advanced-notifications' );
			}
		} elseif ( $pending_recipients > 0 || $pending_actions > 0 && 0 === $tracked_total ) {
			$status = ! empty( $tracking_counts['processing'] ) ? 'processing' : ( ! empty( $tracking_counts['scheduled'] ) ? 'scheduled' : 'queued' );
		} else {
			$status = $error_total > 0 ? 'completed_with_errors' : 'completed';
		}
		if ( ! empty( $campaign['audience_incomplete'] ) || $unresolved > 0 || ( 0 === $tracked_total && empty( $campaign['preparation_complete'] ) ) ) {
			$status = 'needs_attention';
			$campaign['recovery_message'] = __( 'Recipient snapshot is incomplete. Recovery uses only saved recipients; it cannot recreate a lost historical audience. Review the stored records before creating a new campaign.', 'yoohw-advanced-notifications' );
		}
		if ( $overdue > 0 && $pending_recipients > 0 ) {
			$status = 'needs_attention';
			$campaign['recovery_message'] = __( 'Delivery jobs are overdue. Check WordPress cron and WooCommerce Scheduled Actions. Existing pending jobs are retained; recovery will not create another send while they remain pending.', 'yoohw-advanced-notifications' );
		}
		if ( ! empty( $tracking_counts['unknown'] ) ) {
			$status = 'needs_attention';
			$campaign['recovery_message'] = __( 'Some outcomes are unknown. Check the mail provider logs before creating another campaign for those addresses. Recovery never resends unknown outcomes.', 'yoohw-advanced-notifications' );
		} elseif ( $error_total > 0 ) {
			$campaign['recovery_message'] = __( 'Review recipient reasons below. Queue failures retry at least 5 minutes apart, up to 3 queue attempts. Correct configuration failures before recovery. Cancelled recipients require a new campaign.', 'yoohw-advanced-notifications' );
		}

		$campaign['recipient_count']         = $recipient_count;
		$campaign['status']                  = $status;
		$campaign['tracking_counts']         = $tracking_counts;
		$campaign['tracked_recipient_count'] = $tracked_total;
		$campaign['pending_action_count']    = $pending_actions;
		$campaign['dispatch_pending_count']  = $dispatch_pending;
		$campaign['unresolved_count']        = $unresolved;
		$campaign['reconciled_at']           = time();
		$campaigns[ $campaign_id ]           = $campaign;

		$this->save_campaign_record( $campaign );

		return $campaign;
	}

	public function add_submenu() {
		add_submenu_page(
			'woocommerce-marketing',
			__( 'Email campaigns', 'yoohw-advanced-notifications' ),
			__( 'Email campaigns', 'yoohw-advanced-notifications' ),
			'manage_woocommerce',
			'yoohw-advanced-notifications',
			[ $this, 'render_page' ],
			1
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['preview_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
			$this->render_preview_page( sanitize_key( wp_unslash( $_GET['preview_token'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'campaign'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.

		if ( 'settings' === $active_tab ) {
			$this->render_settings_page();
			return;
		}

		if ( 'campaigns' === $active_tab ) {
			$this->render_campaigns_page();
			return;
		}

		$edit_token      = isset( $_GET['edit_token'] ) ? sanitize_key( wp_unslash( $_GET['edit_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
		$restore_payload = $this->get_restore_payload( $edit_token );
		$restore_failed  = ! empty( $edit_token ) && empty( $restore_payload );

		$recipient_sources = $this->get_recipient_sources();
		$selected_recipient_source = isset( $restore_payload['recipient_source'] )
			? sanitize_key( $restore_payload['recipient_source'] )
			: self::SOURCE_PRODUCT_CUSTOMERS;

		if ( ! isset( $recipient_sources[ $selected_recipient_source ] ) ) {
			$selected_recipient_source = self::SOURCE_PRODUCT_CUSTOMERS;
		}

		$selected_product_ids = isset( $restore_payload['product_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) $restore_payload['product_ids'] ) ) ) )
			: [];
		$selected_user_roles = isset( $restore_payload['user_roles'] ) ? (array) $restore_payload['user_roles'] : [];
		$selected_manual_emails = isset( $restore_payload['manual_emails'] ) ? implode( "\n", (array) $restore_payload['manual_emails'] ) : '';

		$selected_subject = isset( $restore_payload['subject'] ) ? sanitize_text_field( $restore_payload['subject'] ) : '';
		$selected_heading = isset( $restore_payload['heading'] ) ? sanitize_text_field( $restore_payload['heading'] ) : '';
		$selected_message = isset( $restore_payload['message'] ) ? wp_kses_post( $restore_payload['message'] ) : '';
		$selected_exclusions = isset( $restore_payload['exclusions'] ) && is_array( $restore_payload['exclusions'] )
			? $restore_payload['exclusions']
			: [];
		$selected_exclude_order_statuses = $this->sanitize_order_statuses( isset( $selected_exclusions['order_statuses'] ) ? $selected_exclusions['order_statuses'] : [] );
		$selected_exclude_product_ids = isset( $selected_exclusions['product_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_exclusions['product_ids'] ) ) ) )
			: [];
		$selected_exclude_emails = isset( $restore_payload['exclude_emails_raw'] )
			? sanitize_textarea_field( $restore_payload['exclude_emails_raw'] )
			: ( isset( $selected_exclusions['emails'] ) ? implode( "\n", array_filter( array_map( 'sanitize_email', (array) $selected_exclusions['emails'] ) ) ) : '' );
		$selected_schedule_mode = isset( $restore_payload['schedule_mode'] ) ? sanitize_key( $restore_payload['schedule_mode'] ) : 'now';
		$selected_schedule_mode = in_array( $selected_schedule_mode, [ 'now', 'scheduled' ], true ) ? $selected_schedule_mode : 'now';
		$selected_scheduled_at  = ! empty( $restore_payload['scheduled_at_input'] )
			? sanitize_text_field( $restore_payload['scheduled_at_input'] )
			: $this->get_default_schedule_datetime_value();
		$selected_test_mode  = ! empty( $restore_payload['test_mode'] );
		$selected_test_email = ! empty( $restore_payload['test_email'] )
			? sanitize_email( $restore_payload['test_email'] )
			: get_option( 'admin_email' );
		?>

		<div class="wrap">
			<h1><?php esc_html_e( 'Email campaigns', 'yoohw-advanced-notifications' ); ?></h1>

			<?php $this->render_page_tabs( 'campaign' ); ?>

			<?php if ( isset( $_GET['preview_expired'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'The email preview expired. Please review the form and try again.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['campaign_payload_failed'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'The shared campaign content could not be stored safely, so no email jobs were created. Review the campaign log or server error log before retrying.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $restore_payload ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'Draft restored from the preview. You can edit it and preview recipients again.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php elseif ( $restore_failed ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'The draft preview expired, so the form could not be restored.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['campaign_dispatch_failed'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Recipients could not be prepared for background delivery, so no new email jobs were created.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['dispatching'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: Number of recipients. */ __( '%d recipient(s) are being prepared in the background. You can leave this page; campaign status will update automatically.', 'yoohw-advanced-notifications' ),
								absint( $_GET['dispatching'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['queued'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: Number of recipients. */ __( 'Email queued for %d recipient(s). Emails will be sent in the background.', 'yoohw-advanced-notifications' ),
								absint( $_GET['queued'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['scheduled'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						$scheduled_local = isset( $_GET['scheduled_local'] ) ? sanitize_text_field( wp_unslash( $_GET['scheduled_local'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
						echo esc_html(
							sprintf(
								/* translators: 1: Number of recipients. 2: Scheduled local date and time. */ __( 'Email scheduled for %1$d recipient(s). Local send time: %2$s. Timezone is derived from each recipient billing country; missing country uses UTC.', 'yoohw-advanced-notifications' ),
								absint( $_GET['scheduled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
								! empty( $scheduled_local ) ? $this->format_schedule_input_for_display( $scheduled_local ) : __( 'the selected time', 'yoohw-advanced-notifications' )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['duplicate'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: Number of recipients. */ __( 'This campaign was already queued recently for %d recipient(s), so the duplicate submission was blocked.', 'yoohw-advanced-notifications' ),
								absint( $_GET['duplicate'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_sent'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice <?php echo absint( $_GET['test_sent'] ) ? 'notice-success' : 'notice-error'; /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?> is-dismissible">
					<p>
						<?php
						echo absint( $_GET['test_sent'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
							? esc_html__( 'Test email handed to the mail system. Inbox delivery is not verified.', 'yoohw-advanced-notifications' )
							: esc_html__( 'Test email could not be sent.', 'yoohw-advanced-notifications' );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['sent'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: Number of recipients. */ __( 'Email sent to %d recipient(s).', 'yoohw-advanced-notifications' ),
								absint( $_GET['sent'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="yoohw-advanced-notifications-sender-summary">
				<div>
					<strong><?php esc_html_e( 'Sender', 'yoohw-advanced-notifications' ); ?></strong>
					<span><?php echo esc_html( $this->get_sender_name() . ' <' . $this->get_sender_email() . '>' ); ?></span>
				</div>
				<div>
					<strong><?php esc_html_e( 'Reply-To', 'yoohw-advanced-notifications' ); ?></strong>
					<span>
						<?php
						echo esc_html(
							$this->should_suppress_automated_replies()
								? sprintf(
									/* translators: %s: effective no-reply address. */
									__( '%s (automatic replies suppressed)', 'yoohw-advanced-notifications' ),
									$this->get_effective_reply_to_email()
								)
								: $this->get_reply_to_email()
						);
						?>
					</span>
				</div>
				<a class="button" href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'settings' ] ) ); ?>">
					<?php esc_html_e( 'Edit Settings', 'yoohw-advanced-notifications' ); ?>
				</a>
			</div>

			<form class="yoohw-advanced-notifications-campaign-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="yoohw_an_send_product_customer_email">

				<?php wp_nonce_field( 'yoohw_an_send_product_customer_email_action', 'yoohw_an_send_product_customer_email_nonce' ); ?>

				<div class="yoohw-advanced-notifications-campaign-grid">
					<div class="yoohw-advanced-notifications-campaign-main">
						<section class="yoohw-advanced-notifications-panel yoohw-advanced-notifications-panel-wide">
						<div class="yoohw-advanced-notifications-panel-header">
							<span class="yoohw-advanced-notifications-step">1</span>
							<div>
								<h2><?php esc_html_e( 'Audience', 'yoohw-advanced-notifications' ); ?></h2>
								<p><?php esc_html_e( 'Choose who should receive this campaign.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
						</div>

						<div class="yoohw-advanced-notifications-fields yoohw-advanced-notifications-fields-2">
							<div class="yoohw-advanced-notifications-field">
								<label for="recipient_source"><?php esc_html_e( 'Recipient source', 'yoohw-advanced-notifications' ); ?></label>
								<select name="recipient_source" id="recipient_source" class="yoohw-advanced-notifications-control">
									<?php foreach ( $recipient_sources as $source_key => $source_label ) : ?>
										<option value="<?php echo esc_attr( $source_key ); ?>" <?php selected( $selected_recipient_source, $source_key ); ?>>
											<?php echo esc_html( $source_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p><?php esc_html_e( 'Product selection is only required for product customers.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
							<div id="yoohw-advanced-notifications-roles-row" class="yoohw-advanced-notifications-field">
								<label for="user_roles"><?php esc_html_e( 'User roles', 'yoohw-advanced-notifications' ); ?></label>
								<select name="user_roles[]" id="user_roles" class="wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'All roles', 'yoohw-advanced-notifications' ); ?>" data-allow_clear="true">
									<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
										<option value="<?php echo esc_attr( $role ); ?>" <?php selected( in_array( $role, $selected_user_roles, true ) ); ?>><?php echo esc_html( translate_user_role( $label ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p><?php esc_html_e( 'Match any selected role. Leave empty for all registered users, including administrators.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
							<div id="yoohw-advanced-notifications-manual-row" class="yoohw-advanced-notifications-field" style="grid-column: 1 / -1;">
								<label for="manual_emails"><?php esc_html_e( 'Recipient email addresses', 'yoohw-advanced-notifications' ); ?></label>
								<textarea name="manual_emails" id="manual_emails" rows="5" class="large-text" placeholder="one@example.com&#10;two@example.com"><?php echo esc_textarea( $selected_manual_emails ); ?></textarea>
								<p><?php esc_html_e( 'Enter email addresses separated by commas, semicolons, spaces, or line breaks. Duplicates are removed. Invalid addresses must be corrected. Scheduled delivery uses UTC for this list.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
							<p id="yoohw-advanced-notifications-customers-row" class="description" style="grid-column: 1 / -1;"><?php esc_html_e( 'Includes billing email addresses from processing and completed orders, including guest purchases. Each email address is included once.', 'yoohw-advanced-notifications' ); ?></p>

							<div id="yoohw-advanced-notifications-products-row" class="yoohw-advanced-notifications-field">
								<label for="product_ids"><?php esc_html_e( 'Products', 'yoohw-advanced-notifications' ); ?></label>
								<select
									name="product_ids[]"
									id="product_ids"
									class="wc-product-search"
									multiple="multiple"
									style="width: 100%;"
									data-placeholder="<?php esc_attr_e( 'Search products or variations', 'yoohw-advanced-notifications' ); ?>"
									data-action="woocommerce_json_search_products_and_variations"
									data-allow_clear="true">
									<?php echo $this->get_selected_product_options_html( $selected_product_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</select>
								<p><?php esc_html_e( 'Include matching purchases of selected products or variations.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
						</div>
					</section>

					<section class="yoohw-advanced-notifications-panel yoohw-advanced-notifications-panel-wide">
						<div class="yoohw-advanced-notifications-panel-header">
							<span class="yoohw-advanced-notifications-step">2</span>
							<div>
								<h2><?php esc_html_e( 'Message', 'yoohw-advanced-notifications' ); ?></h2>
								<p><?php esc_html_e( 'Compose the email shown inside the WooCommerce template.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
						</div>

						<div class="yoohw-advanced-notifications-fields yoohw-advanced-notifications-fields-2">
							<div class="yoohw-advanced-notifications-field">
								<label for="email_subject"><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?></label>
								<input type="text" name="email_subject" id="email_subject" class="regular-text" value="<?php echo esc_attr( $selected_subject ); ?>" required>
							</div>

							<div class="yoohw-advanced-notifications-field">
								<label for="email_heading"><?php esc_html_e( 'Email heading', 'yoohw-advanced-notifications' ); ?></label>
								<input type="text" name="email_heading" id="email_heading" class="regular-text" value="<?php echo esc_attr( $selected_heading ); ?>" required>
							</div>

							<div class="yoohw-advanced-notifications-field yoohw-advanced-notifications-field-full">
								<label for="email_message"><?php esc_html_e( 'Message body', 'yoohw-advanced-notifications' ); ?></label>
								<?php
								wp_editor(
									$selected_message,
									'email_message',
									[
										'textarea_name' => 'email_message',
										'textarea_rows' => 10,
										'media_buttons' => true,
									]
								);
								?>
								<p><?php /* translators: Keep {customer_name} and {product_name} unchanged. They are email placeholders. */ esc_html_e( 'Available placeholders: {customer_name}, {product_name}', 'yoohw-advanced-notifications' ); ?></p>
								<p><?php esc_html_e( 'An unsubscribe link is added automatically to campaign emails.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
						</div>
					</section>

					<section class="yoohw-advanced-notifications-panel yoohw-advanced-notifications-panel-wide">
						<details class="yoohw-advanced-notifications-details">
							<summary>
								<span><?php esc_html_e( 'Exclusions', 'yoohw-advanced-notifications' ); ?></span>
								<small><?php esc_html_e( 'Remove matched recipients from the final list.', 'yoohw-advanced-notifications' ); ?></small>
							</summary>

							<div class="yoohw-advanced-notifications-fields yoohw-advanced-notifications-fields-2">
								<div class="yoohw-advanced-notifications-field">
									<label for="exclude_order_statuses"><?php esc_html_e( 'Order statuses', 'yoohw-advanced-notifications' ); ?></label>
									<select
										name="exclude_order_statuses[]"
										id="exclude_order_statuses"
										class="wc-enhanced-select"
										multiple="multiple"
										style="width: 100%;"
										data-placeholder="<?php esc_attr_e( 'No order statuses excluded', 'yoohw-advanced-notifications' ); ?>"
										data-allow_clear="true">
										<?php foreach ( $this->get_order_status_options() as $status_key => $status_label ) : ?>
											<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( in_array( $status_key, $selected_exclude_order_statuses, true ) ); ?>>
												<?php echo esc_html( $status_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p><?php esc_html_e( 'Example: exclude users with refunded orders.', 'yoohw-advanced-notifications' ); ?></p>
								</div>

								<div class="yoohw-advanced-notifications-field">
									<label for="exclude_product_ids"><?php esc_html_e( 'Product purchasers', 'yoohw-advanced-notifications' ); ?></label>
									<select
										name="exclude_product_ids[]"
										id="exclude_product_ids"
										class="wc-product-search"
										multiple="multiple"
										style="width: 100%;"
										data-placeholder="<?php esc_attr_e( 'Search products or variations', 'yoohw-advanced-notifications' ); ?>"
										data-action="woocommerce_json_search_products_and_variations"
										data-allow_clear="true">
										<?php echo $this->get_selected_product_options_html( $selected_exclude_product_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</select>
									<p><?php esc_html_e( 'Exclude people who already bought selected products.', 'yoohw-advanced-notifications' ); ?></p>
								</div>

								<div class="yoohw-advanced-notifications-field">
									<label for="exclude_emails"><?php esc_html_e( 'Manual emails', 'yoohw-advanced-notifications' ); ?></label>
									<textarea
										name="exclude_emails"
										id="exclude_emails"
										class="large-text"
										rows="4"
										placeholder="<?php esc_attr_e( 'one@example.com, two@example.com', 'yoohw-advanced-notifications' ); ?>"><?php echo esc_textarea( $selected_exclude_emails ); ?></textarea>
									<p><?php esc_html_e( 'Comma, semicolon, space, or line break separated.', 'yoohw-advanced-notifications' ); ?></p>
								</div>
							</div>
						</details>
					</section>

					<section class="yoohw-advanced-notifications-panel">
						<div class="yoohw-advanced-notifications-panel-header">
							<span class="yoohw-advanced-notifications-step">3</span>
							<div>
								<h2><?php esc_html_e( 'Delivery', 'yoohw-advanced-notifications' ); ?></h2>
								<p><?php esc_html_e( 'Choose when the campaign should be sent.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
						</div>

						<div class="yoohw-advanced-notifications-choice-group">
							<label class="yoohw-advanced-notifications-choice">
								<input type="radio" name="schedule_mode" value="now" <?php checked( 'now', $selected_schedule_mode ); ?>>
								<span>
									<strong><?php esc_html_e( 'Send after confirmation', 'yoohw-advanced-notifications' ); ?></strong>
									<small><?php esc_html_e( 'Queue emails as soon as the preview is confirmed.', 'yoohw-advanced-notifications' ); ?></small>
								</span>
							</label>
							<label class="yoohw-advanced-notifications-choice">
								<input type="radio" name="schedule_mode" value="scheduled" <?php checked( 'scheduled', $selected_schedule_mode ); ?>>
								<span>
									<strong><?php esc_html_e( 'Schedule for later', 'yoohw-advanced-notifications' ); ?></strong>
									<small><?php esc_html_e( 'Use each recipient billing timezone when available.', 'yoohw-advanced-notifications' ); ?></small>
								</span>
							</label>
						</div>

						<div id="yoohw-advanced-notifications-schedule-row" class="yoohw-advanced-notifications-field yoohw-advanced-notifications-schedule-field">
							<label for="scheduled_at"><?php esc_html_e( 'Scheduled date/time', 'yoohw-advanced-notifications' ); ?></label>
							<input
								type="datetime-local"
								name="scheduled_at"
								id="scheduled_at"
								value="<?php echo esc_attr( $selected_scheduled_at ); ?>"
							>
							<p><?php esc_html_e( 'Recipients without billing location use UTC.', 'yoohw-advanced-notifications' ); ?></p>
						</div>
					</section>

					<section class="yoohw-advanced-notifications-panel">
						<div class="yoohw-advanced-notifications-panel-header">
							<span class="yoohw-advanced-notifications-step">4</span>
							<div>
								<h2><?php esc_html_e( 'Test', 'yoohw-advanced-notifications' ); ?></h2>
								<p><?php esc_html_e( 'Send a single email without building the campaign queue.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
						</div>

						<label class="yoohw-advanced-notifications-choice">
							<input type="checkbox" name="test_mode" id="test_mode" value="1" <?php checked( $selected_test_mode ); ?>>
							<span>
								<strong><?php esc_html_e( 'Enable test mode', 'yoohw-advanced-notifications' ); ?></strong>
								<small><?php esc_html_e( 'Only the test recipient will receive this email.', 'yoohw-advanced-notifications' ); ?></small>
							</span>
						</label>

						<div id="yoohw-advanced-notifications-test-email-row" class="yoohw-advanced-notifications-field yoohw-advanced-notifications-test-email-field">
							<label for="test_email"><?php esc_html_e( 'Test email address', 'yoohw-advanced-notifications' ); ?></label>
							<input
								type="email"
								name="test_email"
								id="test_email"
								class="regular-text"
								value="<?php echo esc_attr( $selected_test_email ); ?>"
							>
							</div>
						</section>
					</div>

					<aside class="yoohw-advanced-notifications-preview-panel" aria-label="<?php esc_attr_e( 'Email preview', 'yoohw-advanced-notifications' ); ?>">
						<div class="yoohw-advanced-notifications-preview-header">
							<div>
								<h2><?php esc_html_e( 'Email preview', 'yoohw-advanced-notifications' ); ?></h2>
								<p><?php esc_html_e( 'Rendered with the active WooCommerce email template.', 'yoohw-advanced-notifications' ); ?></p>
							</div>
							<span><?php esc_html_e( 'Live', 'yoohw-advanced-notifications' ); ?></span>
						</div>

						<div class="yoohw-advanced-notifications-preview-subject">
							<strong><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?></strong>
							<span id="yoohw-advanced-notifications-preview-subject"><?php esc_html_e( 'Email subject', 'yoohw-advanced-notifications' ); ?></span>
						</div>

						<textarea id="yoohw-advanced-notifications-preview-template-source" hidden readonly><?php echo esc_textarea( $this->get_campaign_preview_email_html() ); ?></textarea>
						<iframe
							id="yoohw-advanced-notifications-preview-frame"
							class="yoohw-advanced-notifications-preview-frame"
							title="<?php esc_attr_e( 'WooCommerce email preview', 'yoohw-advanced-notifications' ); ?>">
						</iframe>
					</aside>
				</div>

				<div class="yoohw-advanced-notifications-actions">
					<span
						id="yoohw-advanced-notifications-action-help"
						data-campaign-text="<?php esc_attr_e( 'Next step: preview recipients and confirm before sending.', 'yoohw-advanced-notifications' ); ?>"
						data-test-text="<?php esc_attr_e( 'Test mode sends one email to the test recipient and keeps this draft for further edits.', 'yoohw-advanced-notifications' ); ?>">
						<?php esc_html_e( 'Next step: preview recipients and confirm before sending.', 'yoohw-advanced-notifications' ); ?>
					</span>
					<?php
					submit_button(
						__( 'Preview Recipients', 'yoohw-advanced-notifications' ),
						'primary large',
						'submit',
						false,
						[
							'id'                  => 'yoohw-advanced-notifications-primary-action',
							'data-campaign-label' => __( 'Preview Recipients', 'yoohw-advanced-notifications' ),
							'data-test-label'     => __( 'Send Test Email', 'yoohw-advanced-notifications' ),
						]
					);
					?>
				</div>
			</form>
		</div>

		<?php
	}

	private function render_page_tabs( $active_tab ) {
		$tabs = [
			'campaign' => [
				'label' => __( 'Campaign', 'yoohw-advanced-notifications' ),
				'url'   => $this->get_admin_page_url(),
			],
			'campaigns' => [
				'label' => __( 'Campaigns & Logs', 'yoohw-advanced-notifications' ),
				'url'   => $this->get_admin_page_url( [ 'tab' => 'campaigns' ] ),
			],
			'settings' => [
				'label' => __( 'Settings', 'yoohw-advanced-notifications' ),
				'url'   => $this->get_admin_page_url( [ 'tab' => 'settings' ] ),
			],
		];
		?>
		<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
			<?php foreach ( $tabs as $tab_key => $tab ) : ?>
				<a
					class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $tab['url'] ); ?>">
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private function render_campaigns_page() {
		$this->reconcile_open_campaigns();

		$campaigns            = $this->get_campaign_history();
		$selected_campaign_id = isset( $_GET['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
		$selected_campaign    = ! empty( $selected_campaign_id ) && isset( $campaigns[ $selected_campaign_id ] )
			? $campaigns[ $selected_campaign_id ]
			: [];
		$requested_view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
		$active_view          = in_array( $requested_view, [ 'list', 'logs' ], true )
			? $requested_view
			: ( ! empty( $selected_campaign_id ) ? 'logs' : 'list' );
		$all_logs_count       = count( $this->get_campaign_log_entries( '', 0 ) );
		$campaigns_total      = count( $campaigns );
		$campaigns_page       = $this->get_current_pagination_page( 'campaigns_paged' );
		$campaigns_total_pages = max( 1, (int) ceil( $campaigns_total / self::CAMPAIGN_HISTORY_PER_PAGE ) );
		$campaigns_page       = min( $campaigns_page, $campaigns_total_pages );
		$campaigns_offset     = ( $campaigns_page - 1 ) * self::CAMPAIGN_HISTORY_PER_PAGE;
		$campaigns_page_items = array_slice( $campaigns, $campaigns_offset, self::CAMPAIGN_HISTORY_PER_PAGE, true );
		$filtered_logs        = 'logs' === $active_view ? $this->get_campaign_log_entries( $selected_campaign_id, 0 ) : [];
		$logs_total           = count( $filtered_logs );
		$logs_page            = $this->get_current_pagination_page( 'logs_paged' );
		$logs_total_pages     = max( 1, (int) ceil( $logs_total / self::CAMPAIGN_LOG_PER_PAGE ) );
		$logs_page            = min( $logs_page, $logs_total_pages );
		$logs_offset          = ( $logs_page - 1 ) * self::CAMPAIGN_LOG_PER_PAGE;
		$logs                 = 'logs' === $active_view ? array_slice( $filtered_logs, $logs_offset, self::CAMPAIGN_LOG_PER_PAGE ) : [];
		$subtabs              = [
			'list' => [
				'label' => __( 'Campaign list', 'yoohw-advanced-notifications' ),
				'url'   => $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'list' ] ),
				'count' => $campaigns_total,
			],
			'logs' => [
				'label' => __( 'Recent campaign log', 'yoohw-advanced-notifications' ),
				'url'   => $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'logs' ] ),
				'count' => $all_logs_count,
			],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email campaigns', 'yoohw-advanced-notifications' ); ?></h1>

			<?php $this->render_page_tabs( 'campaigns' ); ?>
			<?php $this->render_campaign_reschedule_notices(); ?>
			<p><?php esc_html_e( 'Handed to mail system means the sending request was accepted, not that the email reached an inbox. Queue failures retry automatically up to 3 attempts, at least 5 minutes apart. Known pre-transport failures require correcting the reported data or configuration, then recovery. Unknown outcomes require checking the mail provider before deciding to create a new campaign.', 'yoohw-advanced-notifications' ); ?></p>

			<ul class="subsubsub yoohw-advanced-notifications-subtabs">
				<?php foreach ( $subtabs as $view_key => $view ) : ?>
					<li>
						<a
							class="<?php echo $active_view === $view_key ? 'current' : ''; ?>"
							href="<?php echo esc_url( $view['url'] ); ?>">
							<?php echo esc_html( $view['label'] ); ?>
							<span class="count">(<?php echo esc_html( absint( $view['count'] ) ); ?>)</span>
						</a>
						<?php echo 'logs' === $view_key ? '' : ' | '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear">

			<?php if ( 'list' === $active_view ) : ?>
				<div class="yoohw-advanced-notifications-history-header">
					<div>
						<h2><?php esc_html_e( 'Campaign list', 'yoohw-advanced-notifications' ); ?></h2>
						<p><?php esc_html_e( 'Recent campaign previews, tests, queues, schedules, and send results are grouped by campaign.', 'yoohw-advanced-notifications' ); ?></p>
					</div>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: Number of campaigns. */ __( '%d campaign(s) stored', 'yoohw-advanced-notifications' ),
								count( $campaigns )
							)
						);
						?>
					</span>
				</div>

				<?php if ( empty( $campaigns ) ) : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'No campaign history has been created yet. Preview or send a campaign to start tracking it here.', 'yoohw-advanced-notifications' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					$this->render_table_pagination(
						$campaigns_total,
						self::CAMPAIGN_HISTORY_PER_PAGE,
						$campaigns_page,
						[
							'tab'  => 'campaigns',
							'view' => 'list',
						],
						'campaigns_paged',
						'top'
					);
					?>
					<table class="wp-list-table widefat fixed striped table-view-list yoohw-advanced-notifications-history-table">
						<thead>
							<tr>
								<th scope="col" class="manage-column column-campaign"><?php esc_html_e( 'Campaign', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-message"><?php esc_html_e( 'Message', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-audience"><?php esc_html_e( 'Audience', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-delivery"><?php esc_html_e( 'Delivery', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-activity"><?php esc_html_e( 'Activity', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-updated"><?php esc_html_e( 'Updated', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'yoohw-advanced-notifications' ); ?></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<th scope="col" class="manage-column column-campaign"><?php esc_html_e( 'Campaign', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-message"><?php esc_html_e( 'Message', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-audience"><?php esc_html_e( 'Audience', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-delivery"><?php esc_html_e( 'Delivery', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-activity"><?php esc_html_e( 'Activity', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-updated"><?php esc_html_e( 'Updated', 'yoohw-advanced-notifications' ); ?></th>
								<th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'yoohw-advanced-notifications' ); ?></th>
							</tr>
						</tfoot>
						<tbody>
							<?php foreach ( $campaigns_page_items as $campaign ) : ?>
								<?php
								$campaign_id = isset( $campaign['campaign_id'] ) ? sanitize_text_field( $campaign['campaign_id'] ) : '';

								if ( empty( $campaign_id ) ) {
									continue;
								}

								$view_url = $this->get_admin_page_url(
									[
										'tab'         => 'campaigns',
										'view'        => 'logs',
										'campaign_id' => $campaign_id,
									]
								);
								?>
								<tr>
									<td class="column-campaign has-row-actions">
										<a href="<?php echo esc_url( $view_url ); ?>"><code><?php echo esc_html( $campaign_id ); ?></code></a>
										<?php if ( ! empty( $campaign['test_mode'] ) ) : ?>
											<small><?php esc_html_e( 'Test campaign', 'yoohw-advanced-notifications' ); ?></small>
										<?php endif; ?>
										<div class="row-actions">
											<span class="view">
												<a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View log', 'yoohw-advanced-notifications' ); ?></a>
											</span>
										</div>
									</td>
									<td class="column-status">
										<span class="yoohw-advanced-notifications-status-badge <?php echo esc_attr( $this->get_campaign_status_class( $campaign ) ); ?>">
											<?php echo esc_html( $this->get_campaign_status_label( $campaign ) ); ?>
										</span>
									</td>
									<td class="column-message">
										<strong><?php echo esc_html( ! empty( $campaign['subject'] ) ? $campaign['subject'] : __( '(No subject)', 'yoohw-advanced-notifications' ) ); ?></strong>
										<?php if ( ! empty( $campaign['heading'] ) ) : ?>
											<small><?php echo esc_html( $campaign['heading'] ); ?></small>
										<?php endif; ?>
									</td>
									<td class="column-audience">
										<?php echo esc_html( $this->format_campaign_recipient_summary( $campaign ) ); ?>
										<?php if ( ! empty( $campaign['recipient_source_label'] ) ) : ?>
											<small><?php echo esc_html( $campaign['recipient_source_label'] ); ?></small>
										<?php if ( self::SOURCE_REGISTERED_USERS === $campaign['recipient_source'] ) : ?>
											<small><?php echo esc_html( $this->get_user_roles_label( isset( $campaign['user_roles'] ) ? $campaign['user_roles'] : [] ) ); ?></small>
										<?php endif; ?>
										<?php endif; ?>
									</td>
									<td class="column-delivery"><?php echo esc_html( $this->get_campaign_delivery_label( $campaign ) ); ?></td>
									<td class="column-activity"><?php echo esc_html( $this->format_campaign_status_counts( $campaign ) ); ?></td>
									<td class="column-updated">
										<?php echo esc_html( $this->format_campaign_history_time( isset( $campaign['updated_at'] ) ? $campaign['updated_at'] : 0 ) ); ?>
										<?php if ( ! empty( $campaign['created_at'] ) ) : ?>
											<small>
												<?php
												echo esc_html(
													sprintf(
														/* translators: %s: Formatted creation date and time. */ __( 'Created %s', 'yoohw-advanced-notifications' ),
														$this->format_campaign_history_time( $campaign['created_at'] )
													)
												);
												?>
											</small>
										<?php endif; ?>
									</td>
									<td class="column-actions">
										<a class="button button-small" href="<?php echo esc_url( $view_url ); ?>">
											<?php esc_html_e( 'View log', 'yoohw-advanced-notifications' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					$this->render_table_pagination(
						$campaigns_total,
						self::CAMPAIGN_HISTORY_PER_PAGE,
						$campaigns_page,
						[
							'tab'  => 'campaigns',
							'view' => 'list',
						],
						'campaigns_paged',
						'bottom'
					);
					?>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( ! empty( $selected_campaign_id ) && empty( $selected_campaign ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'The selected campaign could not be found in stored history.', 'yoohw-advanced-notifications' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $selected_campaign ) ) : ?>
					<div class="yoohw-advanced-notifications-campaign-detail">
						<h2><?php esc_html_e( 'Campaign details', 'yoohw-advanced-notifications' ); ?></h2>
						<table class="wp-list-table widefat striped table-view-list yoohw-advanced-notifications-detail-table">
							<tbody>
								<tr>
									<th><?php esc_html_e( 'Campaign ID', 'yoohw-advanced-notifications' ); ?></th>
									<td><code><?php echo esc_html( $selected_campaign_id ); ?></code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?></th>
									<td><?php echo esc_html( isset( $selected_campaign['subject'] ) ? $selected_campaign['subject'] : '' ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Audience', 'yoohw-advanced-notifications' ); ?></th>
									<td>
										<?php echo esc_html( $this->format_campaign_recipient_summary( $selected_campaign ) ); ?>
										<?php if ( ! empty( $selected_campaign['recipient_source_label'] ) ) : ?>
											<br><span><?php echo esc_html( $selected_campaign['recipient_source_label'] ); ?></span>
											<?php if ( self::SOURCE_REGISTERED_USERS === $selected_campaign['recipient_source'] ) : ?>
												<br><span><?php echo esc_html( $this->get_user_roles_label( isset( $selected_campaign['user_roles'] ) ? $selected_campaign['user_roles'] : [] ) ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Scope', 'yoohw-advanced-notifications' ); ?></th>
									<td><?php echo esc_html( isset( $selected_campaign['products_label'] ) ? $selected_campaign['products_label'] : '' ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Exclusions', 'yoohw-advanced-notifications' ); ?></th>
									<td>
										<?php
										echo esc_html(
											! empty( $selected_campaign['exclusion_labels'] ) && is_array( $selected_campaign['exclusion_labels'] )
												? implode( '; ', $selected_campaign['exclusion_labels'] )
												: __( 'None', 'yoohw-advanced-notifications' )
										);
										?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Delivery', 'yoohw-advanced-notifications' ); ?></th>
									<td><?php echo esc_html( $this->get_campaign_delivery_label( $selected_campaign ) ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Sender', 'yoohw-advanced-notifications' ); ?></th>
									<td>
										<?php
										echo esc_html(
											sprintf(
												'%1$s <%2$s>',
												isset( $selected_campaign['from_name'] ) ? $selected_campaign['from_name'] : '',
												isset( $selected_campaign['from_email'] ) ? $selected_campaign['from_email'] : ''
											)
										);
										?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Reply-To', 'yoohw-advanced-notifications' ); ?></th>
									<td>
										<?php
										$campaign_reply_to = isset( $selected_campaign['reply_to_email'] ) ? $selected_campaign['reply_to_email'] : '';
										echo esc_html(
											$this->should_suppress_automated_replies()
												? sprintf(
													/* translators: %s: effective no-reply address. */
													__( '%s (automatic replies suppressed)', 'yoohw-advanced-notifications' ),
													$this->get_effective_reply_to_email( $campaign_reply_to )
												)
												: $campaign_reply_to
										);
										?>
									</td>
								</tr>
							</tbody>
						</table>
						<?php
						if ( ! empty( $selected_campaign['recovery_message'] ) ) {
							echo '<p class="notice notice-warning inline">' . esc_html( $selected_campaign['recovery_message'] ) . '</p>';
						}
						$selected_counts        = isset( $selected_campaign['status_counts'] ) && is_array( $selected_campaign['status_counts'] )
							? $this->sanitize_count_map( $selected_campaign['status_counts'] )
							: [];
						$schedule_failed_count = isset( $selected_counts['schedule_failed'] ) ? absint( $selected_counts['schedule_failed'] ) : 0;
						$retry_availability    = $this->get_schedule_retry_availability( $selected_campaign );
						if ( 'tracking' === $retry_availability['source'] ) {
							$schedule_failed_count = absint( $retry_availability['count'] );
						}
						?>
						<?php if ( $schedule_failed_count > 0 && ! empty( $retry_availability['available'] ) ) : ?>
							<div class="yoohw-advanced-notifications-reschedule-box">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESCHEDULE_FAILED ); ?>">
									<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $selected_campaign_id ); ?>">
									<?php wp_nonce_field( self::ACTION_RESCHEDULE_FAILED . '_' . $selected_campaign_id, 'yoohw_an_reschedule_nonce' ); ?>
									<?php
									submit_button(
										__( 'Recover / retry eligible recipients', 'yoohw-advanced-notifications' ),
										'secondary',
										'submit',
										false
									);
									?>
									<p class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: Number of scheduling failures. */ __( '%d eligible recipient(s). Recovery continues preparation and retries queue failures or known pre-transport failures, up to 3 attempts. Unknown outcomes are never retried. Unsubscribes are checked again.', 'yoohw-advanced-notifications' ),
												$schedule_failed_count
											)
										);
										?>
									</p>
								</form>
							</div>
						<?php elseif ( $schedule_failed_count > 0 ) : ?>
							<div class="notice notice-warning inline">
								<p>
									<?php esc_html_e( 'Historical schedule failures are recorded, but their recipient-level records are no longer complete. Automatic retry is disabled to prevent sending to the wrong or already handled recipients.', 'yoohw-advanced-notifications' ); ?>
								</p>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="yoohw-advanced-notifications-log-section">
					<div class="yoohw-advanced-notifications-history-header">
						<div>
							<h2>
								<?php
								echo esc_html(
									! empty( $selected_campaign_id )
										? sprintf( /* translators: %s: Campaign ID. */ __( 'Log for campaign %s', 'yoohw-advanced-notifications' ), $selected_campaign_id )
										: __( 'Recent campaign log', 'yoohw-advanced-notifications' )
								);
								?>
							</h2>
							<p><?php esc_html_e( 'Latest stored send, queue, schedule, failure, skipped, and unsubscribe events.', 'yoohw-advanced-notifications' ); ?></p>
						</div>
						<?php if ( ! empty( $selected_campaign_id ) ) : ?>
							<a class="button" href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'logs' ] ) ); ?>">
								<?php esc_html_e( 'View all logs', 'yoohw-advanced-notifications' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php if ( empty( $logs ) ) : ?>
						<div class="notice notice-info inline">
							<p><?php esc_html_e( 'No log entries found yet.', 'yoohw-advanced-notifications' ); ?></p>
						</div>
					<?php else : ?>
						<?php
						$log_pagination_args = [
							'tab'  => 'campaigns',
							'view' => 'logs',
						];

						if ( ! empty( $selected_campaign_id ) ) {
							$log_pagination_args['campaign_id'] = $selected_campaign_id;
						}

						$this->render_table_pagination(
							$logs_total,
							self::CAMPAIGN_LOG_PER_PAGE,
							$logs_page,
							$log_pagination_args,
							'logs_paged',
							'top'
						);
						?>
						<table class="wp-list-table widefat fixed striped table-view-list yoohw-advanced-notifications-log-table">
							<thead>
								<tr>
									<th scope="col" class="manage-column column-time"><?php esc_html_e( 'Time', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-campaign"><?php esc_html_e( 'Campaign', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-email"><?php esc_html_e( 'Email', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-action-id"><?php esc_html_e( 'Action ID', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-message"><?php esc_html_e( 'Message', 'yoohw-advanced-notifications' ); ?></th>
								</tr>
							</thead>
							<tfoot>
								<tr>
									<th scope="col" class="manage-column column-time"><?php esc_html_e( 'Time', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-campaign"><?php esc_html_e( 'Campaign', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-email"><?php esc_html_e( 'Email', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-action-id"><?php esc_html_e( 'Action ID', 'yoohw-advanced-notifications' ); ?></th>
									<th scope="col" class="manage-column column-message"><?php esc_html_e( 'Message', 'yoohw-advanced-notifications' ); ?></th>
								</tr>
							</tfoot>
							<tbody>
								<?php foreach ( $logs as $log ) : ?>
									<?php
									$log_campaign_id = isset( $log['campaign_id'] ) ? sanitize_text_field( $log['campaign_id'] ) : '';
									?>
									<tr>
										<td class="column-time"><?php echo esc_html( $this->format_campaign_history_time( isset( $log['time'] ) ? $log['time'] : 0 ) ); ?></td>
										<td class="column-campaign">
											<?php if ( ! empty( $log_campaign_id ) && 'unknown' !== $log_campaign_id ) : ?>
												<a href="<?php echo esc_url( $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'logs', 'campaign_id' => $log_campaign_id ] ) ); ?>">
													<code><?php echo esc_html( $log_campaign_id ); ?></code>
												</a>
											<?php else : ?>
												<code><?php esc_html_e( 'unknown', 'yoohw-advanced-notifications' ); ?></code>
											<?php endif; ?>
										</td>
										<td class="column-email"><?php echo esc_html( isset( $log['email'] ) ? $log['email'] : '' ); ?></td>
										<td class="column-status">
											<span class="yoohw-advanced-notifications-status-badge <?php echo esc_attr( $this->get_status_class_from_key( isset( $log['status'] ) ? $log['status'] : '' ) ); ?>">
												<?php echo esc_html( $this->get_status_label_from_key( isset( $log['status'] ) ? $log['status'] : '' ) ); ?>
											</span>
										</td>
										<td class="column-action-id"><?php echo esc_html( ! empty( $log['action_id'] ) ? absint( $log['action_id'] ) : '-' ); ?></td>
										<td class="column-message"><?php echo esc_html( isset( $log['message'] ) ? $log['message'] : '' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php
						$this->render_table_pagination(
							$logs_total,
							self::CAMPAIGN_LOG_PER_PAGE,
							$logs_page,
							$log_pagination_args,
							'logs_paged',
							'bottom'
						);
						?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_campaign_reschedule_notices() {
		$reschedule_error = isset( $_GET['reschedule_error'] ) ? sanitize_key( wp_unslash( $_GET['reschedule_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.

		if ( ! empty( $reschedule_error ) ) {
			$messages = [
				'missing_campaign' => __( 'The selected campaign could not be found.', 'yoohw-advanced-notifications' ),
				'missing_payload'  => __( 'This campaign does not have enough stored email content to reschedule safely. For older campaigns, keep at least one pending scheduled action so Email campaigns can recover the message payload.', 'yoohw-advanced-notifications' ),
				'missing_recipient_state' => __( 'Recipient-level failure records are incomplete, so automatic retry was blocked to prevent duplicate or incorrect delivery.', 'yoohw-advanced-notifications' ),
				'payload_store_failed' => __( 'The campaign content could not be stored safely, so no retry jobs were created.', 'yoohw-advanced-notifications' ),
				'dispatch_store_failed' => __( 'Failed recipients could not be prepared for background retry.', 'yoohw-advanced-notifications' ),
				'dispatch_enqueue_failed' => __( 'The background retry dispatcher could not be queued.', 'yoohw-advanced-notifications' ),
				'no_recipients'    => __( 'No stuck schedule-failed recipients were found for this campaign.', 'yoohw-advanced-notifications' ),
			];
			$message  = isset( $messages[ $reschedule_error ] )
				? $messages[ $reschedule_error ]
				: __( 'The failed schedule retry could not be completed.', 'yoohw-advanced-notifications' );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
			return;
		}

		if ( isset( $_GET['rescheduled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
			$rescheduled = absint( wp_unslash( $_GET['rescheduled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
			$failed      = isset( $_GET['reschedule_failed'] ) ? absint( wp_unslash( $_GET['reschedule_failed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
			$notice_type = $failed > 0 ? 'notice-warning' : 'notice-success';
			?>
			<div class="notice <?php echo esc_attr( $notice_type ); ?> is-dismissible">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Number of emails successfully rescheduled. 2: Number of failed retries. */ __( 'Rescheduled %1$d failed email(s). %2$d retry attempt(s) failed.', 'yoohw-advanced-notifications' ),
							$rescheduled,
							$failed
						)
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['dispatching_retry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: Number of recipients. */ __( '%d failed recipient(s) are being prepared for retry in the background.', 'yoohw-advanced-notifications' ),
							absint( wp_unslash( $_GET['dispatching_retry'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	private function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email campaigns', 'yoohw-advanced-notifications' ); ?></h1>

			<?php $this->render_page_tabs( 'settings' ); ?>

			<?php if ( isset( $_GET['settings_saved'] ) ) : /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed. */ ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Email campaigns settings saved.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="yoohw_an_save_customer_email_notify_settings">

				<?php wp_nonce_field( 'yoohw_an_save_customer_email_notify_settings_action', 'yoohw_an_save_customer_email_notify_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Sender', 'yoohw-advanced-notifications' ); ?></h2>

				<table class="form-table">
					<tr>
						<th>
							<label for="from_email"><?php esc_html_e( 'From email', 'yoohw-advanced-notifications' ); ?></label>
						</th>
						<td>
							<input
								type="email"
								name="from_email"
								id="from_email"
								class="regular-text"
								value="<?php echo esc_attr( $this->get_sender_email() ); ?>"
								required
							>
							<p class="description">
								<?php esc_html_e( 'Used as the sender email for every campaign.', 'yoohw-advanced-notifications' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th>
							<label for="from_name"><?php esc_html_e( 'From name', 'yoohw-advanced-notifications' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								name="from_name"
								id="from_name"
								class="regular-text"
								value="<?php echo esc_attr( $this->get_sender_name() ); ?>"
								required
							>
						</td>
					</tr>

					<tr>
						<th>
							<label for="reply_to_email"><?php esc_html_e( 'Reply-To email', 'yoohw-advanced-notifications' ); ?></label>
						</th>
						<td>
							<input
								type="email"
								name="reply_to_email"
								id="reply_to_email"
								class="regular-text"
								value="<?php echo esc_attr( $this->get_reply_to_email() ); ?>"
								required
							>
							<p class="description">
								<?php esc_html_e( 'Used when automated-reply suppression is disabled.', 'yoohw-advanced-notifications' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Automated replies', 'yoohw-advanced-notifications' ); ?></th>
						<td>
							<label for="suppress_automated_replies">
								<input
									type="checkbox"
									name="suppress_automated_replies"
									id="suppress_automated_replies"
									value="1"
									<?php checked( $this->should_suppress_automated_replies() ); ?>
								>
								<?php esc_html_e( 'Suppress out-of-office and other automatic replies', 'yoohw-advanced-notifications' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Adds standards-based bulk/automatic message headers while preserving delivery failure notifications.', 'yoohw-advanced-notifications' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th>
							<label for="no_reply_email"><?php esc_html_e( 'No-reply email', 'yoohw-advanced-notifications' ); ?></label>
						</th>
						<td>
							<input
								type="email"
								name="no_reply_email"
								id="no_reply_email"
								class="regular-text"
								value="<?php echo esc_attr( $this->get_no_reply_email() ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'Used as Reply-To while suppression is enabled, so non-compliant automatic responders do not reach the support inbox.', 'yoohw-advanced-notifications' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'yoohw-advanced-notifications' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_preview_page( $preview_token ) {
		$payload = $this->get_preview_payload( $preview_token );

		if ( is_wp_error( $payload ) ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Preview email campaign', 'yoohw-advanced-notifications' ); ?></h1>
				<div class="notice notice-error">
					<p><?php echo esc_html( $payload->get_error_message() ); ?></p>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( $this->get_admin_page_url() ); ?>">
						<?php esc_html_e( 'Back to email form', 'yoohw-advanced-notifications' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		$recipient_count  = isset( $payload['recipient_count'] ) ? absint( $payload['recipient_count'] ) : 0;
		$recipient_sample = isset( $payload['recipient_sample'] ) && is_array( $payload['recipient_sample'] )
			? $payload['recipient_sample']
			: [];
		$source_label = isset( $payload['recipient_source_label'] )
			? $payload['recipient_source_label']
			: __( 'Product customers', 'yoohw-advanced-notifications' );
		$products_label = isset( $payload['products_label'] )
			? $payload['products_label']
			: __( 'selected product(s)', 'yoohw-advanced-notifications' );
		$exclusion_labels = isset( $payload['exclusion_labels'] ) && is_array( $payload['exclusion_labels'] )
			? array_filter( array_map( 'sanitize_text_field', $payload['exclusion_labels'] ) )
			: [];
		$recipient_count_before_exclusions = isset( $payload['recipient_count_before_exclusions'] )
			? absint( $payload['recipient_count_before_exclusions'] )
			: $recipient_count;
		$excluded_count = isset( $payload['excluded_count'] ) ? absint( $payload['excluded_count'] ) : 0;
		$is_scheduled = $this->is_payload_scheduled( $payload );
		?>

		<div class="wrap">
			<h1><?php esc_html_e( 'Preview email campaign', 'yoohw-advanced-notifications' ); ?></h1>

			<table class="widefat striped" style="max-width: 920px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Campaign ID', 'yoohw-advanced-notifications' ); ?></th>
						<td><code><?php echo esc_html( $payload['campaign_id'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recipient source', 'yoohw-advanced-notifications' ); ?></th>
						<td><?php echo esc_html( $source_label ); ?></td>
					</tr>
					<?php if ( self::SOURCE_REGISTERED_USERS === $payload['recipient_source'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'User roles', 'yoohw-advanced-notifications' ); ?></th>
							<td><?php echo esc_html( $this->get_user_roles_label( isset( $payload['user_roles'] ) ? $payload['user_roles'] : [] ) ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $payload['product_ids'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Products', 'yoohw-advanced-notifications' ); ?></th>
							<td><?php echo esc_html( $products_label ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recipients', 'yoohw-advanced-notifications' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: Number of recipients. */ _n( '%d recipient', '%d recipients', $recipient_count, 'yoohw-advanced-notifications' ),
									$recipient_count
								)
							);
							?>
						</td>
					</tr>
					<?php if ( ! empty( $exclusion_labels ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclusions', 'yoohw-advanced-notifications' ); ?></th>
							<td><?php echo esc_html( implode( '; ', $exclusion_labels ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Excluded', 'yoohw-advanced-notifications' ); ?></th>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: Number of excluded recipients. 2: Initial number of recipients. */ __( '%1$d removed from %2$d initial recipient(s).', 'yoohw-advanced-notifications' ),
										$excluded_count,
										$recipient_count_before_exclusions
									)
								);
								?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'From', 'yoohw-advanced-notifications' ); ?></th>
						<td><?php echo esc_html( $payload['from_name'] . ' <' . $payload['from_email'] . '>' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reply-To', 'yoohw-advanced-notifications' ); ?></th>
						<td>
							<?php
							$preview_reply_to = isset( $payload['reply_to_email'] ) ? $payload['reply_to_email'] : $this->get_reply_to_email();
							echo esc_html(
								$this->should_suppress_automated_replies()
									? sprintf(
										/* translators: %s: effective no-reply address. */
										__( '%s (automatic replies suppressed)', 'yoohw-advanced-notifications' ),
										$this->get_effective_reply_to_email( $preview_reply_to )
									)
									: $preview_reply_to
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subject', 'yoohw-advanced-notifications' ); ?></th>
						<td><?php echo esc_html( $payload['subject'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Heading', 'yoohw-advanced-notifications' ); ?></th>
						<td><?php echo esc_html( $payload['heading'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery', 'yoohw-advanced-notifications' ); ?></th>
						<td>
							<?php
							echo esc_html(
								$is_scheduled
									? sprintf(
										/* translators: %s: Scheduled local date and time. */ __( 'Scheduled for %s in each recipient billing country timezone; missing country uses UTC.', 'yoohw-advanced-notifications' ),
										$this->format_schedule_input_for_display( isset( $payload['scheduled_at_input'] ) ? $payload['scheduled_at_input'] : '' )
									)
									: __( 'Send as soon as possible after confirmation', 'yoohw-advanced-notifications' )
							);
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $recipient_sample ) ) : ?>
				<h2><?php esc_html_e( 'Recipient sample', 'yoohw-advanced-notifications' ); ?></h2>
				<table class="widefat striped" style="max-width: 920px;">
					<thead>
						<tr>
								<th><?php esc_html_e( 'Name', 'yoohw-advanced-notifications' ); ?></th>
								<th><?php esc_html_e( 'Email', 'yoohw-advanced-notifications' ); ?></th>
								<th><?php esc_html_e( 'Billing country', 'yoohw-advanced-notifications' ); ?></th>
								<th><?php esc_html_e( 'Billing state/city', 'yoohw-advanced-notifications' ); ?></th>
								<?php if ( $is_scheduled ) : ?>
									<th><?php esc_html_e( 'Scheduled timezone', 'yoohw-advanced-notifications' ); ?></th>
									<th><?php esc_html_e( 'Scheduled send time', 'yoohw-advanced-notifications' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recipient_sample as $recipient ) : ?>
							<?php
							$sample_schedule = $is_scheduled
								? $this->get_recipient_scheduled_delivery( $payload, $recipient )
								: [];
							?>
							<tr>
									<td><?php echo esc_html( isset( $recipient['name'] ) ? $recipient['name'] : '' ); ?></td>
									<td><?php echo esc_html( isset( $recipient['email'] ) ? $recipient['email'] : '' ); ?></td>
									<td><?php echo esc_html( ! empty( $recipient['country'] ) ? $recipient['country'] : __( 'UTC fallback', 'yoohw-advanced-notifications' ) ); ?></td>
									<td>
										<?php
										echo esc_html(
											trim(
												( ! empty( $recipient['state'] ) ? $recipient['state'] : '' ) .
												( ! empty( $recipient['city'] ) ? ' / ' . $recipient['city'] : '' )
											) ?: __( 'Not set', 'yoohw-advanced-notifications' )
										);
										?>
									</td>
									<?php if ( $is_scheduled ) : ?>
										<td><?php echo esc_html( isset( $sample_schedule['timezone'] ) ? $sample_schedule['timezone'] : 'UTC' ); ?></td>
									<td>
										<?php
										echo esc_html(
											! empty( $sample_schedule['timestamp'] )
												? $this->format_schedule_timestamp( $sample_schedule['timestamp'], $sample_schedule['timezone'] )
												: __( 'Invalid scheduled time', 'yoohw-advanced-notifications' )
										);
										?>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Message preview', 'yoohw-advanced-notifications' ); ?></h2>
			<div style="max-width: 860px; padding: 16px; background: #fff; border: 1px solid #dcdcde;">
				<?php echo wpautop( wp_kses_post( $payload['message'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->get_unsubscribe_footer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<p>
				<a class="button" href="<?php echo esc_url( $this->get_admin_page_url( [ 'edit_token' => $preview_token ] ) ); ?>">
					<?php esc_html_e( 'Back to email form', 'yoohw-advanced-notifications' ); ?>
				</a>
			</p>

			<?php if ( $recipient_count > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="yoohw_an_send_product_customer_email">
					<input type="hidden" name="confirmed" value="1">
					<input type="hidden" name="preview_token" value="<?php echo esc_attr( $preview_token ); ?>">
					<?php wp_nonce_field( 'yoohw_an_send_product_customer_email_action', 'yoohw_an_send_product_customer_email_nonce' ); ?>
					<?php
					submit_button(
						sprintf(
							$is_scheduled
								? /* translators: %d: Number of recipients. */ __( 'Schedule Email for %d Recipient(s)', 'yoohw-advanced-notifications' )
								: /* translators: %d: Number of recipients. */ __( 'Queue Email for %d Recipient(s)', 'yoohw-advanced-notifications' ),
							$recipient_count
						)
					);
					?>
				</form>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No matching customers were found. Nothing will be queued.', 'yoohw-advanced-notifications' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<?php
	}

	public function handle_unsubscribe() {
		$email       = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public unsubscribe links are authenticated by is_valid_unsubscribe_token() below.
		$token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public unsubscribe links are authenticated by is_valid_unsubscribe_token() below.
		$campaign_id = isset( $_GET['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public unsubscribe links are authenticated by is_valid_unsubscribe_token() below.

		if ( empty( $email ) || ! is_email( $email ) || ! $this->is_valid_unsubscribe_token( $email, $token ) ) {
			wp_die(
				esc_html__( 'This unsubscribe link is invalid or expired.', 'yoohw-advanced-notifications' ),
				esc_html__( 'Invalid unsubscribe link', 'yoohw-advanced-notifications' ),
				[ 'response' => 400 ]
			);
		}

		if ( ! $this->add_unsubscribed_email( $email, $campaign_id ) ) {
			wp_die( esc_html__( 'Your unsubscribe could not be saved. Please try this link again.', 'yoohw-advanced-notifications' ), '', [ 'response' => 503 ] );
		}
		do_action( 'yoohw_an_unsubscribed', $email, $campaign_id );
		$this->log_campaign_event( $campaign_id, $email, 'unsubscribed' );

		wp_die(
			wp_kses_post(
				sprintf(
					'<h1>%1$s</h1><p>%2$s</p>',
					esc_html__( 'You have been unsubscribed.', 'yoohw-advanced-notifications' ),
					esc_html__( 'This email address will be excluded from future email campaigns.', 'yoohw-advanced-notifications' )
				)
			),
			esc_html__( 'Unsubscribed', 'yoohw-advanced-notifications' ),
			[ 'response' => 200 ]
		);
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) );
		}

		check_admin_referer(
			'yoohw_an_save_customer_email_notify_settings_action',
			'yoohw_an_save_customer_email_notify_settings_nonce'
		);

		$from_email                 = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
		$from_name                  = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '';
		$reply_to_email             = isset( $_POST['reply_to_email'] ) ? sanitize_email( wp_unslash( $_POST['reply_to_email'] ) ) : '';
		$suppress_automated_replies = isset( $_POST['suppress_automated_replies'] );
		$no_reply_email             = isset( $_POST['no_reply_email'] ) ? sanitize_email( wp_unslash( $_POST['no_reply_email'] ) ) : '';

		if ( empty( $from_email ) || ! is_email( $from_email ) ) {
			wp_die( esc_html__( 'Please enter a valid sender email address.', 'yoohw-advanced-notifications' ) );
		}

		if ( empty( $from_name ) ) {
			wp_die( esc_html__( 'Please enter a sender name.', 'yoohw-advanced-notifications' ) );
		}

		if ( empty( $reply_to_email ) || ! is_email( $reply_to_email ) ) {
			wp_die( esc_html__( 'Please enter a valid Reply-To email address.', 'yoohw-advanced-notifications' ) );
		}

		if ( $suppress_automated_replies && ( empty( $no_reply_email ) || ! is_email( $no_reply_email ) ) ) {
			wp_die( esc_html__( 'Please enter a valid no-reply email address when automated replies are suppressed.', 'yoohw-advanced-notifications' ) );
		}

		$this->save_sender_settings(
			$from_email,
			$from_name,
			$reply_to_email,
			$suppress_automated_replies,
			$no_reply_email
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => 'yoohw-advanced-notifications',
					'tab'            => 'settings',
					'settings_saved' => 1,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_reschedule_failed_campaign() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';

		check_admin_referer(
			self::ACTION_RESCHEDULE_FAILED . '_' . $campaign_id,
			'yoohw_an_reschedule_nonce'
		);

		$this->retry_campaign( $campaign_id );
		wp_safe_redirect( $this->get_admin_page_url( [ 'tab' => 'campaigns', 'view' => 'logs', 'campaign_id' => $campaign_id ] ) );
		exit;
	}

	public function handle_send_email() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'yoohw-advanced-notifications' ) );
		}

		check_admin_referer(
			'yoohw_an_send_product_customer_email_action',
			'yoohw_an_send_product_customer_email_nonce'
		);

		$is_confirmed = isset( $_POST['confirmed'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirmed'] ) );

		if ( $is_confirmed ) {
			$preview_token = isset( $_POST['preview_token'] ) ? sanitize_key( wp_unslash( $_POST['preview_token'] ) ) : '';
			$payload       = $this->get_preview_payload( $preview_token );

			if ( is_wp_error( $payload ) ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'            => 'yoohw-advanced-notifications',
							'preview_expired' => 1,
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		} else {
			$payload = $this->get_payload_from_request();

			if ( is_wp_error( $payload ) ) {
				wp_die( esc_html( $payload->get_error_message() ) );
			}
		}

		$options_valid = $this->validate_recipient_source_options( $payload );
		if ( is_wp_error( $options_valid ) ) {
			wp_die( esc_html( $options_valid->get_error_message() ) );
		}

		if ( ! empty( $payload['test_mode'] ) ) {
			$test_email = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';

			if ( empty( $test_email ) || ! is_email( $test_email ) ) {
				wp_die( esc_html__( 'Please enter a valid test email address.', 'yoohw-advanced-notifications' ) );
			}

			$payload['test_email'] = $test_email;
			$this->record_campaign_snapshot(
				$payload,
				'test',
				[
					'recipient_count' => 1,
				]
			);

			$test_message = str_replace(
				[
					'{customer_name}',
					'{product_name}',
				],
				[
					__( 'Test Customer', 'yoohw-advanced-notifications' ),
					$payload['products_label'],
				],
				$payload['message']
			);
			$test_message = $this->append_unsubscribe_footer( $test_message, $test_email, $payload['campaign_id'] );

			$sent = $this->send_woocommerce_email(
				$test_email,
				'[TEST] ' . $payload['subject'],
				$payload['heading'],
				$test_message,
				$payload['from_email'],
				$payload['from_name'],
				isset( $payload['reply_to_email'] ) ? $payload['reply_to_email'] : $this->get_reply_to_email()
			);

			$this->log_campaign_event(
				$payload['campaign_id'],
				$test_email,
				$sent ? 'test_sent' : 'test_failed',
				$sent ? '' : $this->last_mail_error
			);

			$this->record_campaign_snapshot(
				$payload,
				$sent ? 'test_sent' : 'test_failed',
				[
					'recipient_count' => 1,
				]
			);

			$edit_token = $this->store_preview_payload( $payload );

			wp_safe_redirect(
				add_query_arg(
					[
						'page'       => 'yoohw-advanced-notifications',
						'test_sent'  => $sent ? 1 : 0,
						'edit_token' => $edit_token,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( ! $is_confirmed ) {
			$exclusion_summary = [];
			$customers         = $this->get_recipients_by_payload( $payload, $exclusion_summary );

			$payload['recipient_count']                   = count( $customers );
			$payload['recipient_count_before_exclusions'] = isset( $exclusion_summary['base_count'] ) ? absint( $exclusion_summary['base_count'] ) : count( $customers );
			$payload['excluded_count']                    = isset( $exclusion_summary['excluded_count'] ) ? absint( $exclusion_summary['excluded_count'] ) : 0;
			$payload['exclusion_labels']                  = isset( $exclusion_summary['labels'] ) && is_array( $exclusion_summary['labels'] ) ? $exclusion_summary['labels'] : [];
			$payload['recipient_sample']                  = array_slice( $customers, 0, 20 );

			$this->record_campaign_snapshot( $payload, 'previewed' );

			$preview_token = $this->store_preview_payload( $payload );

			wp_safe_redirect(
				add_query_arg(
					[
						'page'          => 'yoohw-advanced-notifications',
						'preview_token' => $preview_token,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$customers = $this->get_recipients_by_payload( $payload );

		$result = $this->confirm_campaign( $payload, $customers );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 503 ] );
		}
		$is_scheduled_campaign = $this->is_payload_scheduled( $payload );
		$prepared = [ 'dispatch_pending' => count( $customers ) ];
		$this->delete_preview_payload( isset( $preview_token ) ? $preview_token : '' );

		$query_args = [
			'page'        => 'yoohw-advanced-notifications',
			'dispatching' => isset( $prepared['dispatch_pending'] ) ? absint( $prepared['dispatch_pending'] ) : 0,
		];

		if ( $is_scheduled_campaign ) {
			$query_args['scheduled_local'] = isset( $payload['scheduled_at_input'] ) ? $payload['scheduled_at_input'] : '';
		}

		wp_safe_redirect(
			add_query_arg(
				$query_args,
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function build_email_action_args( $payload, $recipient, $payload_reference = [] ) {
		$payload_reference = is_array( $payload_reference ) ? $payload_reference : [];

		return [
			'action_schema'  => self::ACTION_ARGS_SCHEMA,
			'to'             => isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '',
			'name'           => isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '',
			'campaign_id'    => isset( $payload['campaign_id'] ) ? sanitize_text_field( $payload['campaign_id'] ) : '',
			'delivery_id'    => isset( $payload['delivery_id'] ) ? sanitize_text_field( $payload['delivery_id'] ) : '',
			'payload_key'    => isset( $payload_reference['payload_key'] ) ? sanitize_key( $payload_reference['payload_key'] ) : '',
			'payload_hash'   => isset( $payload_reference['payload_hash'] ) ? sanitize_text_field( $payload_reference['payload_hash'] ) : '',
			'country'        => isset( $recipient['country'] ) ? $this->normalize_country_code( $recipient['country'] ) : '',
			'state'          => isset( $recipient['state'] ) ? $this->normalize_location_part( $recipient['state'] ) : '',
			'city'           => isset( $recipient['city'] ) ? $this->normalize_location_part( $recipient['city'] ) : '',
		];
	}

	private function store_campaign_email_payload( $payload ) {
		$payload     = is_array( $payload ) ? $payload : [];
		$campaign_id = isset( $payload['campaign_id'] ) ? sanitize_text_field( $payload['campaign_id'] ) : '';
		$delivery_id = isset( $payload['delivery_id'] ) ? sanitize_text_field( $payload['delivery_id'] ) : $campaign_id;

		if ( empty( $campaign_id ) || empty( $delivery_id ) ) {
			return new WP_Error( 'yoohw_an_missing_payload_identity', __( 'Missing campaign payload identity.', 'yoohw-advanced-notifications' ) );
		}

		$email_payload = [
			'subject'        => isset( $payload['subject'] ) ? sanitize_text_field( $payload['subject'] ) : '',
			'heading'        => isset( $payload['heading'] ) ? sanitize_text_field( $payload['heading'] ) : '',
			'message'        => isset( $payload['message'] ) ? wp_kses_post( $payload['message'] ) : '',
			'products_label' => isset( $payload['products_label'] ) ? sanitize_text_field( $payload['products_label'] ) : '',
			'from_email'     => isset( $payload['from_email'] ) ? sanitize_email( $payload['from_email'] ) : $this->get_sender_email(),
			'from_name'      => isset( $payload['from_name'] ) ? sanitize_text_field( $payload['from_name'] ) : $this->get_sender_name(),
			'reply_to_email' => isset( $payload['reply_to_email'] ) ? sanitize_email( $payload['reply_to_email'] ) : $this->get_reply_to_email(),
		];

		if (
			empty( $email_payload['subject'] ) ||
			empty( $email_payload['heading'] ) ||
			empty( $email_payload['message'] ) ||
			empty( $email_payload['from_email'] ) ||
			! is_email( $email_payload['from_email'] ) ||
			empty( $email_payload['from_name'] ) ||
			empty( $email_payload['reply_to_email'] ) ||
			! is_email( $email_payload['reply_to_email'] )
		) {
			return new WP_Error( 'yoohw_an_invalid_shared_payload', __( 'Campaign content or sender details are incomplete.', 'yoohw-advanced-notifications' ) );
		}

		$payload_hash = hash( 'sha256', wp_json_encode( $email_payload ) );
		$payload_key  = $this->get_campaign_payload_option_name( $campaign_id, $delivery_id );
		$record       = [
			'schema'       => self::CAMPAIGN_PAYLOAD_SCHEMA,
			'campaign_id'  => $campaign_id,
			'delivery_id'  => $delivery_id,
			'payload_hash' => $payload_hash,
			'payload'      => $email_payload,
			'created_at'   => time(),
		];
		$existing     = $this->read_durable_option( $payload_key );

		if ( null === $existing ) {
			$stored   = $this->write_durable_option( $payload_key, $record, true );
			$existing = $stored ? $record : $this->read_durable_option( $payload_key );
		}

		if (
			! is_array( $existing ) ||
			empty( $existing['payload_hash'] ) ||
			! hash_equals( $payload_hash, (string) $existing['payload_hash'] )
		) {
			return new WP_Error( 'yoohw_an_payload_collision', __( 'Stored campaign content does not match this campaign delivery.', 'yoohw-advanced-notifications' ) );
		}

		return [
			'payload_key'  => $payload_key,
			'payload_hash' => $payload_hash,
		];
	}

	private function hydrate_email_action_args( $args ) {
		$args = is_array( $args ) ? $args : [];

		// Actions created before shared payload storage keep their complete inline payload.
		if ( empty( $args['payload_key'] ) && empty( $args['payload_hash'] ) ) {
			return $args;
		}

		$campaign_id = isset( $args['campaign_id'] ) ? sanitize_text_field( $args['campaign_id'] ) : '';
		$delivery_id = isset( $args['delivery_id'] ) ? sanitize_text_field( $args['delivery_id'] ) : $campaign_id;
		$payload_key = isset( $args['payload_key'] ) ? sanitize_key( $args['payload_key'] ) : '';
		$payload_hash = isset( $args['payload_hash'] ) ? sanitize_text_field( $args['payload_hash'] ) : '';
		$expected_key = $this->get_campaign_payload_option_name( $campaign_id, $delivery_id );

		if ( empty( $campaign_id ) || empty( $delivery_id ) || empty( $payload_key ) || $payload_key !== $expected_key || empty( $payload_hash ) ) {
			return new WP_Error( 'yoohw_an_invalid_payload_reference', __( 'Invalid shared campaign payload reference.', 'yoohw-advanced-notifications' ) );
		}

		$record = isset( $this->campaign_payload_cache[ $payload_key ] )
			? $this->campaign_payload_cache[ $payload_key ]
			: get_option( $payload_key, false );

		if (
			! is_array( $record ) ||
			self::CAMPAIGN_PAYLOAD_SCHEMA !== absint( isset( $record['schema'] ) ? $record['schema'] : 0 ) ||
			$campaign_id !== sanitize_text_field( isset( $record['campaign_id'] ) ? $record['campaign_id'] : '' ) ||
			$delivery_id !== sanitize_text_field( isset( $record['delivery_id'] ) ? $record['delivery_id'] : '' ) ||
			empty( $record['payload'] ) ||
			! is_array( $record['payload'] )
		) {
			return new WP_Error( 'yoohw_an_missing_shared_payload', __( 'Shared campaign content is missing or invalid.', 'yoohw-advanced-notifications' ) );
		}

		$stored_hash    = isset( $record['payload_hash'] ) ? sanitize_text_field( $record['payload_hash'] ) : '';
		$computed_hash  = hash( 'sha256', wp_json_encode( $record['payload'] ) );

		if (
			empty( $stored_hash ) ||
			! hash_equals( $stored_hash, $payload_hash ) ||
			! hash_equals( $stored_hash, $computed_hash )
		) {
			return new WP_Error( 'yoohw_an_shared_payload_mismatch', __( 'Shared campaign content failed its integrity check.', 'yoohw-advanced-notifications' ) );
		}

		$this->campaign_payload_cache[ $payload_key ] = $record;

		foreach ( [ 'subject', 'heading', 'message', 'products_label', 'from_email', 'from_name', 'reply_to_email' ] as $field ) {
			$args[ $field ] = isset( $record['payload'][ $field ] ) ? $record['payload'][ $field ] : '';
		}

		return $args;
	}

	private function get_campaign_payload_option_name( $campaign_id, $delivery_id ) {
		$campaign_id = sanitize_text_field( $campaign_id );
		$delivery_id = sanitize_text_field( $delivery_id );

		if ( empty( $campaign_id ) || empty( $delivery_id ) ) {
			return '';
		}

		return self::CAMPAIGN_PAYLOAD_PREFIX . hash( 'sha256', $campaign_id . '|' . $delivery_id );
	}

	private function validate_email_action_args_size( $args ) {
		$encoded = wp_json_encode( [ $args ] );

		if ( false === $encoded || strlen( $encoded ) > 7000 ) {
			return new WP_Error(
				'yoohw_an_action_args_too_large',
				__( 'Email job metadata is too large for Action Scheduler. Campaign content must use shared payload storage.', 'yoohw-advanced-notifications' )
			);
		}

		return true;
	}

	private function schedule_email_action( $args, $scheduled_at ) {
		$scheduled_at = absint( $scheduled_at );

		if ( ! $scheduled_at || $scheduled_at <= time() ) {
			return new WP_Error( 'yoohw_an_past_schedule', __( 'Scheduled timestamp is not in the future.', 'yoohw-advanced-notifications' ) );
		}

		$args_size_valid = $this->validate_email_action_args_size( $args );

		if ( is_wp_error( $args_size_valid ) ) {
			return $args_size_valid;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action(
				$scheduled_at,
				self::ACTION_SEND_SINGLE_EMAIL,
				[ $args ],
				self::ACTION_GROUP,
				false
			);

			return $action_id
				? absint( $action_id )
				: new WP_Error( 'yoohw_an_empty_action_id', __( 'as_schedule_single_action returned an empty action id', 'yoohw-advanced-notifications' ) );
		}

		$scheduled = wp_schedule_single_event(
			$scheduled_at,
			self::ACTION_SEND_SINGLE_EMAIL,
			[ $args ]
		);

		return $scheduled
			? 0
			: new WP_Error( 'yoohw_an_wp_cron_failed', __( 'wp_schedule_single_event returned false', 'yoohw-advanced-notifications' ) );
	}

	private function enqueue_email_action( $args ) {
		$args_size_valid = $this->validate_email_action_args_size( $args );

		if ( is_wp_error( $args_size_valid ) ) {
			return $args_size_valid;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'yoohw_an_action_scheduler_unavailable', __( 'Action Scheduler is unavailable.', 'yoohw-advanced-notifications' ) );
		}

		$action_id = as_enqueue_async_action(
			self::ACTION_SEND_SINGLE_EMAIL,
			[ $args ],
			self::ACTION_GROUP,
			false
		);

		return $action_id
			? absint( $action_id )
			: new WP_Error( 'yoohw_an_empty_action_id', __( 'as_enqueue_async_action returned an empty action id', 'yoohw-advanced-notifications' ) );
	}

	private function format_schedule_log_message( $recipient_schedule, $prefix = '' ) {
		$recipient_schedule = is_array( $recipient_schedule ) ? $recipient_schedule : [];
		$timestamp          = isset( $recipient_schedule['timestamp'] ) ? absint( $recipient_schedule['timestamp'] ) : 0;
		$timezone           = isset( $recipient_schedule['timezone'] ) ? sanitize_text_field( $recipient_schedule['timezone'] ) : 'UTC';
		$parts              = [];

		if ( ! empty( $prefix ) ) {
			$parts[] = sanitize_text_field( $prefix );
		}

		$parts[] = sprintf(
			'scheduled_at=%s',
			$timestamp ? $this->format_schedule_timestamp( $timestamp, $timezone ) : ''
		);
		$parts[] = sprintf( 'country=%s', isset( $recipient_schedule['country'] ) ? sanitize_text_field( $recipient_schedule['country'] ) : 'UTC' );
		$parts[] = sprintf( 'state=%s', isset( $recipient_schedule['state'] ) ? sanitize_text_field( $recipient_schedule['state'] ) : '' );
		$parts[] = sprintf( 'city=%s', isset( $recipient_schedule['city'] ) ? sanitize_text_field( $recipient_schedule['city'] ) : '' );
		$parts[] = sprintf( 'timezone=%s', $timezone );

		if ( ! empty( $recipient_schedule['rolled_forward_days'] ) ) {
			$parts[] = sprintf( 'rolled_forward_days=%d', absint( $recipient_schedule['rolled_forward_days'] ) );
		}

		return implode( ' ', array_filter( $parts ) );
	}

	private function get_payload_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_send_email() verifies the send nonce before calling this private parser.
		$product_ids = isset( $_POST['product_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) )
			: [];
		$exclude_product_ids = isset( $_POST['exclude_product_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['exclude_product_ids'] ) )
			: [];
		$exclude_order_statuses = isset( $_POST['exclude_order_statuses'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['exclude_order_statuses'] ) )
			: [];
		$exclude_emails_raw = isset( $_POST['exclude_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_emails'] ) ) : '';
		$exclude_emails     = ! empty( $exclude_emails_raw ) ? $this->parse_email_list( $exclude_emails_raw ) : [];

		$product_ids   = array_values( array_unique( array_filter( $product_ids ) ) );
		$exclude_product_ids = array_values( array_unique( array_filter( $exclude_product_ids ) ) );
		$subject       = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$email_heading = isset( $_POST['email_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['email_heading'] ) ) : '';
		$message       = isset( $_POST['email_message'] ) ? wp_kses_post( wp_unslash( $_POST['email_message'] ) ) : '';
		$from_email    = $this->get_sender_email();
		$from_name     = $this->get_sender_name();
		$reply_to_email = $this->get_reply_to_email();
		$test_mode     = isset( $_POST['test_mode'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['test_mode'] ) );
		$test_email    = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$schedule_mode = isset( $_POST['schedule_mode'] ) ? sanitize_key( wp_unslash( $_POST['schedule_mode'] ) ) : 'now';
		$scheduled_at  = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
		$recipient_source = isset( $_POST['recipient_source'] )
			? sanitize_key( wp_unslash( $_POST['recipient_source'] ) )
			: self::SOURCE_PRODUCT_CUSTOMERS;
		$recipient_sources = $this->get_recipient_sources();

		if ( ! isset( $recipient_sources[ $recipient_source ] ) ) {
			return new WP_Error( 'yoohw_an_invalid_source', __( 'Please choose a valid recipient source.', 'yoohw-advanced-notifications' ) );
		}

		if ( ! in_array( $schedule_mode, [ 'now', 'scheduled' ], true ) ) {
			return new WP_Error( 'yoohw_an_invalid_schedule_mode', __( 'Please choose a valid send time option.', 'yoohw-advanced-notifications' ) );
		}

		if ( self::SOURCE_PRODUCT_CUSTOMERS !== $recipient_source ) {
			$product_ids = [];
		}

		$scheduled_at_timestamp = 0;

		if ( $test_mode ) {
			$schedule_mode = 'now';
			$scheduled_at  = '';
		} elseif ( 'scheduled' === $schedule_mode ) {
			$scheduled_at_timestamp = $this->parse_scheduled_timestamp( $scheduled_at, new DateTimeZone( 'UTC' ) );

			if ( ! $scheduled_at_timestamp ) {
				return new WP_Error( 'yoohw_an_invalid_schedule_time', __( 'Please enter a valid scheduled date/time.', 'yoohw-advanced-notifications' ) );
			}
		}

		if ( empty( $subject ) || empty( $email_heading ) || empty( $message ) ) {
			return new WP_Error( 'yoohw_an_missing_fields', __( 'Missing required fields.', 'yoohw-advanced-notifications' ) );
		}

		if ( empty( $from_email ) || ! is_email( $from_email ) ) {
			return new WP_Error( 'yoohw_an_invalid_from_email', __( 'Please enter a valid sender email address.', 'yoohw-advanced-notifications' ) );
		}

		if ( empty( $from_name ) ) {
			return new WP_Error( 'yoohw_an_invalid_from_name', __( 'Please enter a sender name.', 'yoohw-advanced-notifications' ) );
		}

		if ( empty( $reply_to_email ) || ! is_email( $reply_to_email ) ) {
			return new WP_Error( 'yoohw_an_invalid_reply_to_email', __( 'Please enter a valid Reply-To email address.', 'yoohw-advanced-notifications' ) );
		}

		if ( ! $test_mode && self::SOURCE_PRODUCT_CUSTOMERS === $recipient_source && empty( $product_ids ) ) {
			return new WP_Error( 'yoohw_an_missing_products', __( 'Please select at least one product.', 'yoohw-advanced-notifications' ) );
		}

		$user_roles = self::SOURCE_REGISTERED_USERS === $recipient_source && isset( $_POST['user_roles'] ) ? wp_unslash( $_POST['user_roles'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validate_recipient_source_options() strictly rejects unknown or malformed roles below.
		$manual_emails = self::SOURCE_MANUAL_EMAILS === $recipient_source
			? $this->parse_manual_email_list( isset( $_POST['manual_emails'] ) ? wp_unslash( $_POST['manual_emails'] ) : '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict parser rejects malformed input and validates each address before normalization.
			: [];
		if ( is_wp_error( $manual_emails ) ) {
			return $manual_emails;
		}
		$options_valid = $this->validate_recipient_source_options( [
			'recipient_source' => $recipient_source, 'user_roles' => $user_roles,
			'manual_emails' => $manual_emails, 'test_mode' => $test_mode,
		] );
		if ( is_wp_error( $options_valid ) ) {
			return $options_valid;
		}
		$user_roles = array_values( array_unique( $user_roles ) );
		sort( $user_roles );

		$source_label = $recipient_sources[ $recipient_source ];
		$exclusions   = [
			'order_statuses'        => $this->sanitize_order_statuses( $exclude_order_statuses ),
			'product_ids'           => $exclude_product_ids,
			'emails'                => $exclude_emails,
		];

		$payload = [
			'recipient_source'       => $recipient_source,
			'recipient_source_label' => $source_label,
			'product_ids'            => $product_ids,
			'user_roles'             => $user_roles,
			'manual_emails'          => $manual_emails,
			'subject'                => $subject,
			'heading'                => $email_heading,
			'message'                => $message,
			'products_label'         => ! empty( $product_ids )
				? $this->get_selected_products_label( $product_ids )
				: $source_label,
			'schedule_mode'          => $schedule_mode,
			'scheduled_at_input'     => $scheduled_at,
			'scheduled_at_timestamp' => $scheduled_at_timestamp,
			'test_mode'              => $test_mode,
			'test_email'             => $test_email,
			'from_email'             => $from_email,
			'from_name'              => $from_name,
			'reply_to_email'         => $reply_to_email,
			'exclusions'             => $exclusions,
			'exclude_emails_raw'     => sanitize_textarea_field( $exclude_emails_raw ),
			'exclusion_labels'       => $this->get_exclusion_labels( $exclusions ),
		];

		$payload['campaign_hash'] = $this->generate_campaign_hash( $payload );
		$payload['delivery_id']   = wp_generate_uuid4();
		$payload['campaign_id']   = substr( $payload['campaign_hash'], 0, 8 ) . '-' . substr( str_replace( '-', '', $payload['delivery_id'] ), 0, 8 );

		return $payload;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	private function generate_campaign_hash( $payload ) {
		$hash_payload = [
			'recipient_source' => isset( $payload['recipient_source'] ) ? (string) $payload['recipient_source'] : self::SOURCE_PRODUCT_CUSTOMERS,
			'product_ids'      => isset( $payload['product_ids'] ) ? array_map( 'absint', (array) $payload['product_ids'] ) : [],
			'subject'          => isset( $payload['subject'] ) ? (string) $payload['subject'] : '',
			'heading'          => isset( $payload['heading'] ) ? (string) $payload['heading'] : '',
			'message'          => isset( $payload['message'] ) ? (string) $payload['message'] : '',
			'from_email'       => isset( $payload['from_email'] ) ? (string) $payload['from_email'] : '',
			'from_name'        => isset( $payload['from_name'] ) ? (string) $payload['from_name'] : '',
			'reply_to_email'   => isset( $payload['reply_to_email'] ) ? (string) $payload['reply_to_email'] : '',
			'exclusions'       => $this->normalize_exclusions_for_hash( isset( $payload['exclusions'] ) && is_array( $payload['exclusions'] ) ? $payload['exclusions'] : [] ),
			'schedule_mode'    => isset( $payload['schedule_mode'] ) ? (string) $payload['schedule_mode'] : 'now',
			'scheduled_at'     => isset( $payload['scheduled_at_timestamp'] ) ? absint( $payload['scheduled_at_timestamp'] ) : 0,
			'scheduled_input'  => isset( $payload['scheduled_at_input'] ) ? (string) $payload['scheduled_at_input'] : '',
		];

		if ( self::SOURCE_REGISTERED_USERS === $hash_payload['recipient_source'] && ! empty( $payload['user_roles'] ) ) {
			$hash_payload['user_roles'] = array_values( array_unique( $payload['user_roles'] ) );
			sort( $hash_payload['user_roles'] );
		}
		if ( self::SOURCE_MANUAL_EMAILS === $hash_payload['recipient_source'] ) {
			$hash_payload['manual_emails'] = array_values( array_unique( array_map( 'strtolower', $payload['manual_emails'] ) ) );
			sort( $hash_payload['manual_emails'] );
		}
		sort( $hash_payload['product_ids'], SORT_NUMERIC );

		return hash( 'sha256', wp_json_encode( $hash_payload ) );
	}

	private function store_preview_payload( $payload ) {
		$preview_token = wp_generate_uuid4();

		set_transient(
			$this->get_preview_transient_key( $preview_token ),
			$payload,
			30 * MINUTE_IN_SECONDS
		);

		return $preview_token;
	}

	private function get_preview_payload( $preview_token ) {
		if ( empty( $preview_token ) ) {
			return new WP_Error( 'yoohw_an_missing_preview', __( 'Missing email preview token.', 'yoohw-advanced-notifications' ) );
		}

		$payload = get_transient( $this->get_preview_transient_key( $preview_token ) );

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return new WP_Error( 'yoohw_an_expired_preview', __( 'The email preview expired. Please review the form and try again.', 'yoohw-advanced-notifications' ) );
		}

		return $payload;
	}

	private function get_restore_payload( $preview_token ) {
		if ( empty( $preview_token ) ) {
			return [];
		}

		$payload = $this->get_preview_payload( $preview_token );

		return is_wp_error( $payload ) ? [] : $payload;
	}

	private function delete_preview_payload( $preview_token ) {
		if ( empty( $preview_token ) ) {
			return;
		}

		delete_transient( $this->get_preview_transient_key( $preview_token ) );
	}

	private function get_preview_transient_key( $preview_token ) {
		return self::PREVIEW_TRANSIENT_PREFIX . md5( $preview_token );
	}

	private function get_admin_page_url( $args = [] ) {
		return add_query_arg(
			array_merge(
				[
					'page' => 'yoohw-advanced-notifications',
				],
				(array) $args
			),
			admin_url( 'admin.php' )
		);
	}

	private function get_current_pagination_page( $query_arg ) {
		$query_arg = sanitize_key( $query_arg );
		$page      = isset( $_GET[ $query_arg ] ) ? absint( wp_unslash( $_GET[ $query_arg ] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.

		return max( 1, $page );
	}

	private function render_table_pagination( $total_items, $per_page, $current_page, $base_args, $page_arg, $position = 'top' ) {
		$total_items  = absint( $total_items );
		$per_page     = max( 1, absint( $per_page ) );
		$total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page = min( max( 1, absint( $current_page ) ), $total_pages );
		$page_arg     = sanitize_key( $page_arg );
		$position     = sanitize_key( $position );
		$input_id     = 'current-page-selector-' . sanitize_html_class( $page_arg . '-' . $position );
		$paging_id    = 'table-paging-' . sanitize_html_class( $page_arg . '-' . $position );

		if ( $total_items < 1 ) {
			return;
		}
		?>
		<div class="tablenav <?php echo esc_attr( $position ); ?> yoohw-advanced-notifications-tablenav">
			<div class="tablenav-pages <?php echo $total_pages < 2 ? 'one-page' : ''; ?>">
				<span class="displaying-num">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Formatted number of items. */ _n( '%s item', '%s items', $total_items, 'yoohw-advanced-notifications' ),
							number_format_i18n( $total_items )
						)
					);
					?>
				</span>

				<?php if ( $total_pages > 1 ) : ?>
					<span class="pagination-links">
						<?php
						echo $this->get_table_pagination_link( 1, $current_page <= 1, $base_args, $page_arg, 'first-page', __( 'First page', 'yoohw-advanced-notifications' ), '&laquo;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $this->get_table_pagination_link( max( 1, $current_page - 1 ), $current_page <= 1, $base_args, $page_arg, 'prev-page', __( 'Previous page', 'yoohw-advanced-notifications' ), '&lsaquo;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<span class="paging-input">
							<label for="<?php echo esc_attr( $input_id ); ?>" class="screen-reader-text">
								<?php esc_html_e( 'Current page', 'yoohw-advanced-notifications' ); ?>
							</label>
							<input
								class="current-page"
								id="<?php echo esc_attr( $input_id ); ?>"
								type="text"
								name="<?php echo esc_attr( $page_arg ); ?>"
								value="<?php echo esc_attr( $current_page ); ?>"
								size="<?php echo esc_attr( strlen( (string) $total_pages ) ); ?>"
								aria-describedby="<?php echo esc_attr( $paging_id ); ?>"
							>
							<span class="tablenav-paging-text" id="<?php echo esc_attr( $paging_id ); ?>">
								<?php esc_html_e( 'of', 'yoohw-advanced-notifications' ); ?>
								<span class="total-pages"><?php echo esc_html( number_format_i18n( $total_pages ) ); ?></span>
							</span>
						</span>
						<?php
						echo $this->get_table_pagination_link( min( $total_pages, $current_page + 1 ), $current_page >= $total_pages, $base_args, $page_arg, 'next-page', __( 'Next page', 'yoohw-advanced-notifications' ), '&rsaquo;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $this->get_table_pagination_link( $total_pages, $current_page >= $total_pages, $base_args, $page_arg, 'last-page', __( 'Last page', 'yoohw-advanced-notifications' ), '&raquo;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</span>
				<?php endif; ?>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	private function get_table_pagination_link( $target_page, $disabled, $base_args, $page_arg, $class, $screen_reader_text, $symbol ) {
		$page_arg = sanitize_key( $page_arg );
		$class    = sanitize_html_class( $class );
		$symbol = wp_kses_post( $symbol );

		if ( $disabled ) {
			return sprintf(
				'<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%s</span>',
				$symbol
			);
		}

		$args              = (array) $base_args;
		$args[ $page_arg ] = max( 1, absint( $target_page ) );

		return sprintf(
			'<a class="%1$s button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a>',
			esc_attr( $class ),
			esc_url( $this->get_admin_page_url( $args ) ),
			esc_html( $screen_reader_text ),
			$symbol
		);
	}

	private function record_campaign_snapshot( $payload, $status = 'previewed', $overrides = [] ) {
		if ( empty( $payload['campaign_id'] ) ) {
			return false;
		}

		$campaign_id = sanitize_text_field( $payload['campaign_id'] );
		$campaigns   = $this->get_campaign_history();
		$existing    = isset( $campaigns[ $campaign_id ] ) && is_array( $campaigns[ $campaign_id ] )
			? $campaigns[ $campaign_id ]
			: [];
		$created_at  = ! empty( $existing['created_at'] ) ? absint( $existing['created_at'] ) : time();

		$recipient_count = isset( $overrides['recipient_count'] )
			? absint( $overrides['recipient_count'] )
			: ( isset( $payload['recipient_count'] ) ? absint( $payload['recipient_count'] ) : ( isset( $existing['recipient_count'] ) ? absint( $existing['recipient_count'] ) : 0 ) );
		$recipient_count_before_exclusions = isset( $overrides['recipient_count_before_exclusions'] )
			? absint( $overrides['recipient_count_before_exclusions'] )
			: ( isset( $payload['recipient_count_before_exclusions'] ) ? absint( $payload['recipient_count_before_exclusions'] ) : ( isset( $existing['recipient_count_before_exclusions'] ) ? absint( $existing['recipient_count_before_exclusions'] ) : $recipient_count ) );
		$excluded_count = isset( $overrides['excluded_count'] )
			? absint( $overrides['excluded_count'] )
			: ( isset( $payload['excluded_count'] ) ? absint( $payload['excluded_count'] ) : ( isset( $existing['excluded_count'] ) ? absint( $existing['excluded_count'] ) : 0 ) );

		$entry = array_merge(
			$existing,
			[
				'campaign_id'                       => $campaign_id,
				'campaign_hash'                     => isset( $payload['campaign_hash'] ) ? sanitize_text_field( $payload['campaign_hash'] ) : ( isset( $existing['campaign_hash'] ) ? sanitize_text_field( $existing['campaign_hash'] ) : '' ),
				'delivery_id'                       => isset( $payload['delivery_id'] ) ? sanitize_text_field( $payload['delivery_id'] ) : ( isset( $existing['delivery_id'] ) ? sanitize_text_field( $existing['delivery_id'] ) : '' ),
				'payload_key'                       => isset( $payload['payload_key'] ) ? sanitize_key( $payload['payload_key'] ) : ( isset( $existing['payload_key'] ) ? sanitize_key( $existing['payload_key'] ) : '' ),
				'payload_hash'                      => isset( $payload['payload_hash'] ) ? sanitize_text_field( $payload['payload_hash'] ) : ( isset( $existing['payload_hash'] ) ? sanitize_text_field( $existing['payload_hash'] ) : '' ),
				'subject'                           => isset( $payload['subject'] ) ? sanitize_text_field( $payload['subject'] ) : ( isset( $existing['subject'] ) ? sanitize_text_field( $existing['subject'] ) : '' ),
				'heading'                           => isset( $payload['heading'] ) ? sanitize_text_field( $payload['heading'] ) : ( isset( $existing['heading'] ) ? sanitize_text_field( $existing['heading'] ) : '' ),
				'message'                           => isset( $payload['message'] ) ? wp_kses_post( $payload['message'] ) : ( isset( $existing['message'] ) ? wp_kses_post( $existing['message'] ) : '' ),
				'recipient_source'                  => isset( $payload['recipient_source'] ) ? sanitize_key( $payload['recipient_source'] ) : ( isset( $existing['recipient_source'] ) ? sanitize_key( $existing['recipient_source'] ) : '' ),
				'recipient_source_label'            => isset( $payload['recipient_source_label'] ) ? sanitize_text_field( $payload['recipient_source_label'] ) : ( isset( $existing['recipient_source_label'] ) ? sanitize_text_field( $existing['recipient_source_label'] ) : '' ),
				'product_ids'                       => isset( $payload['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $payload['product_ids'] ) ) ) ) : ( isset( $existing['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $existing['product_ids'] ) ) ) ) : [] ),
				'products_label'                    => isset( $payload['products_label'] ) ? sanitize_text_field( $payload['products_label'] ) : ( isset( $existing['products_label'] ) ? sanitize_text_field( $existing['products_label'] ) : '' ),
				'user_roles'                        => isset( $payload['user_roles'] ) ? $payload['user_roles'] : ( isset( $existing['user_roles'] ) ? $existing['user_roles'] : [] ),
				'manual_emails'                     => isset( $payload['manual_emails'] ) ? $payload['manual_emails'] : ( isset( $existing['manual_emails'] ) ? $existing['manual_emails'] : [] ),
				'recipient_count'                   => $recipient_count,
				'recipient_count_before_exclusions' => $recipient_count_before_exclusions,
				'excluded_count'                    => $excluded_count,
				'exclusions'                        => $this->sanitize_campaign_exclusions( isset( $payload['exclusions'] ) && is_array( $payload['exclusions'] ) ? $payload['exclusions'] : ( isset( $existing['exclusions'] ) && is_array( $existing['exclusions'] ) ? $existing['exclusions'] : [] ) ),
				'exclude_emails_raw'                => isset( $payload['exclude_emails_raw'] ) ? sanitize_textarea_field( $payload['exclude_emails_raw'] ) : ( isset( $existing['exclude_emails_raw'] ) ? sanitize_textarea_field( $existing['exclude_emails_raw'] ) : '' ),
				'exclusion_labels'                  => $this->sanitize_text_list( isset( $payload['exclusion_labels'] ) ? $payload['exclusion_labels'] : ( isset( $existing['exclusion_labels'] ) ? $existing['exclusion_labels'] : [] ) ),
				'schedule_mode'                     => isset( $payload['schedule_mode'] ) ? sanitize_key( $payload['schedule_mode'] ) : ( isset( $existing['schedule_mode'] ) ? sanitize_key( $existing['schedule_mode'] ) : 'now' ),
				'scheduled_at_input'                => isset( $payload['scheduled_at_input'] ) ? sanitize_text_field( $payload['scheduled_at_input'] ) : ( isset( $existing['scheduled_at_input'] ) ? sanitize_text_field( $existing['scheduled_at_input'] ) : '' ),
				'scheduled_at_timestamp'            => isset( $payload['scheduled_at_timestamp'] ) ? absint( $payload['scheduled_at_timestamp'] ) : ( isset( $existing['scheduled_at_timestamp'] ) ? absint( $existing['scheduled_at_timestamp'] ) : 0 ),
				'test_mode'                         => ! empty( $payload['test_mode'] ) || ! empty( $existing['test_mode'] ),
				'test_email'                        => isset( $payload['test_email'] ) ? sanitize_email( $payload['test_email'] ) : ( isset( $existing['test_email'] ) ? sanitize_email( $existing['test_email'] ) : '' ),
				'from_email'                        => isset( $payload['from_email'] ) ? sanitize_email( $payload['from_email'] ) : ( isset( $existing['from_email'] ) ? sanitize_email( $existing['from_email'] ) : '' ),
				'from_name'                         => isset( $payload['from_name'] ) ? sanitize_text_field( $payload['from_name'] ) : ( isset( $existing['from_name'] ) ? sanitize_text_field( $existing['from_name'] ) : '' ),
				'reply_to_email'                    => isset( $payload['reply_to_email'] ) ? sanitize_email( $payload['reply_to_email'] ) : ( isset( $existing['reply_to_email'] ) ? sanitize_email( $existing['reply_to_email'] ) : '' ),
				'queued_count'                      => isset( $overrides['queued_count'] ) ? absint( $overrides['queued_count'] ) : ( isset( $existing['queued_count'] ) ? absint( $existing['queued_count'] ) : 0 ),
				'scheduled_count'                   => isset( $overrides['scheduled_count'] ) ? absint( $overrides['scheduled_count'] ) : ( isset( $existing['scheduled_count'] ) ? absint( $existing['scheduled_count'] ) : 0 ),
				'sent_count'                        => isset( $overrides['sent_count'] ) ? absint( $overrides['sent_count'] ) : ( isset( $existing['sent_count'] ) ? absint( $existing['sent_count'] ) : 0 ),
				'dispatch_action_id'                 => isset( $overrides['dispatch_action_id'] ) ? absint( $overrides['dispatch_action_id'] ) : ( isset( $existing['dispatch_action_id'] ) ? absint( $existing['dispatch_action_id'] ) : 0 ),
				'dispatch_pending_count'             => isset( $overrides['dispatch_pending_count'] ) ? absint( $overrides['dispatch_pending_count'] ) : ( isset( $existing['dispatch_pending_count'] ) ? absint( $existing['dispatch_pending_count'] ) : 0 ),
				'status'                            => sanitize_key( $status ),
				'created_at'                        => $created_at,
				'updated_at'                        => time(),
			]
		);

		$entry['status_counts'] = isset( $existing['status_counts'] ) && is_array( $existing['status_counts'] )
			? $this->sanitize_count_map( $existing['status_counts'] )
			: [];

		$campaigns[ $campaign_id ] = $entry;

		return $this->save_campaign_record( $entry );
	}

	private function get_campaign_history() {
		$stored = $this->read_durable_option( self::OPTION_CAMPAIGNS );
		$stored = is_array( $stored ) ? $stored : [];
		// Import by overlay, without deleting the old data or requiring a one-shot migration.
		foreach ( $this->read_durable_options( self::CAMPAIGN_PAYLOAD_PREFIX ) as $record ) {
			if ( ! is_array( $record ) || empty( $record['campaign_id'] ) || isset( $stored[ $record['campaign_id'] ] ) ) {
				continue;
			}
			$stored[ $record['campaign_id'] ] = array_merge( $record['payload'], [
				'campaign_id' => $record['campaign_id'], 'delivery_id' => $record['delivery_id'],
				'status' => 'needs_attention', 'created_at' => $record['created_at'], 'audience_incomplete' => true, 'schedule_mode' => 'now',
				'recovery_message' => __( 'Recovered campaign content. Only stored recipients and existing jobs can continue. The original audience may be incomplete; review recipient records before creating another campaign.', 'yoohw-advanced-notifications' ),
			] );
		}
		foreach ( $this->read_durable_options( 'yoohw_an_manifest_' ) as $manifest ) {
			if ( is_array( $manifest ) && ! empty( $manifest['payload']['campaign_id'] ) ) {
				$id = $manifest['payload']['campaign_id'];
				$stored[ $id ] = array_merge( $stored[ $id ] ?? [], $manifest['payload'], [ 'status' => 'preparing', 'recipient_count' => count( $manifest['recipients'] ), 'created_at' => $manifest['confirmed_at'] ] );
			}
		}
		foreach ( $this->read_durable_options( 'yoohw_an_record_' ) as $record ) {
			if ( is_array( $record ) && ! empty( $record['campaign_id'] ) ) {
				$stored[ $record['campaign_id'] ] = $record;
			}
		}
		$campaigns = [];

		foreach ( $stored as $key => $campaign ) {
			if ( ! is_array( $campaign ) ) {
				continue;
			}

			$campaign_id = ! empty( $campaign['campaign_id'] )
				? sanitize_text_field( $campaign['campaign_id'] )
				: sanitize_text_field( $key );

			if ( empty( $campaign_id ) ) {
				continue;
			}

			$campaign['campaign_id']     = $campaign_id;
			$campaign['status']          = ! empty( $campaign['status'] ) ? sanitize_key( $campaign['status'] ) : '';
			$campaign['last_status']     = ! empty( $campaign['last_status'] ) ? sanitize_key( $campaign['last_status'] ) : '';
			$campaign['status_counts']   = isset( $campaign['status_counts'] ) && is_array( $campaign['status_counts'] ) ? $this->sanitize_count_map( $campaign['status_counts'] ) : [];
			$campaign['payload_key']     = isset( $campaign['payload_key'] ) ? sanitize_key( $campaign['payload_key'] ) : '';
			$campaign['payload_hash']    = isset( $campaign['payload_hash'] ) ? sanitize_text_field( $campaign['payload_hash'] ) : '';
			$campaign['exclusion_labels'] = isset( $campaign['exclusion_labels'] ) ? $this->sanitize_text_list( $campaign['exclusion_labels'] ) : [];
			$campaign['message']         = isset( $campaign['message'] ) ? wp_kses_post( $campaign['message'] ) : '';
			$campaign['product_ids']     = isset( $campaign['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $campaign['product_ids'] ) ) ) ) : [];
			$campaign['exclusions']      = isset( $campaign['exclusions'] ) && is_array( $campaign['exclusions'] ) ? $this->sanitize_campaign_exclusions( $campaign['exclusions'] ) : [];
			$campaign['exclude_emails_raw'] = isset( $campaign['exclude_emails_raw'] ) ? sanitize_textarea_field( $campaign['exclude_emails_raw'] ) : '';
			$campaign['created_at']      = ! empty( $campaign['created_at'] ) ? absint( $campaign['created_at'] ) : 0;
			$campaign['updated_at']      = ! empty( $campaign['updated_at'] ) ? absint( $campaign['updated_at'] ) : $campaign['created_at'];

			$campaigns[ $campaign_id ] = $campaign;
		}

		uasort(
			$campaigns,
			function ( $a, $b ) {
				$closed = [ 'completed', 'empty', 'test_sent', 'test_failed', 'previewed' ];
				$active_a = ! in_array( $a['status'] ?? '', $closed, true );
				$active_b = ! in_array( $b['status'] ?? '', $closed, true );
				return ( $active_b <=> $active_a ) ?: ( absint( $b['updated_at'] ?? 0 ) <=> absint( $a['updated_at'] ?? 0 ) );
			}
		);

		return $campaigns;
	}

	private function store_campaign_log_event( $campaign_id, $email, $status, $message = '', $action_id = 0 ) {
		$event = [
			'time'        => time(),
			'campaign_id' => ! empty( $campaign_id ) ? sanitize_text_field( $campaign_id ) : 'unknown',
			'email'       => ! empty( $email ) ? sanitize_email( $email ) : 'unknown',
			'status'      => sanitize_key( $status ),
			'message'     => sanitize_text_field( $message ),
			'action_id'   => absint( $action_id ),
		];

		$tracked = $this->track_recipient_status( $event['campaign_id'], $event['email'], $event['status'], $event['action_id'], $event['message'] );

		if ( $tracked ) {
			if (
				in_array(
					$event['status'],
					[ 'sent', 'failed', 'queue_failed', 'schedule_failed', 'skipped', 'skipped_unsubscribed', 'skipped_duplicate' ],
					true
				) &&
				! wp_next_scheduled( self::ACTION_RECONCILE_CAMPAIGNS, [ $event['campaign_id'] ] )
			) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ACTION_RECONCILE_CAMPAIGNS, [ $event['campaign_id'] ] );
			}

			return true;
		}

		$logs = $this->get_campaign_log_entries( '', self::CAMPAIGN_LOG_LIMIT );
		array_unshift( $logs, $event );
		$logs = array_slice( $logs, 0, self::CAMPAIGN_LOG_LIMIT );

		$this->update_nonautoload_option( self::OPTION_CAMPAIGN_LOGS, $logs );
		$this->increment_campaign_status_count( $event );

		if (
			in_array(
				$event['status'],
				[ 'sent', 'failed', 'queue_failed', 'schedule_failed', 'skipped', 'skipped_unsubscribed', 'skipped_duplicate' ],
				true
			) &&
			'unknown' !== $event['campaign_id'] &&
			! wp_next_scheduled( self::ACTION_RECONCILE_CAMPAIGNS, [ $event['campaign_id'] ] )
		) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ACTION_RECONCILE_CAMPAIGNS, [ $event['campaign_id'] ] );
		}

		return false;
	}

	private function get_campaign_log_entries( $campaign_id = '', $limit = 50 ) {
		$stored      = get_option( self::OPTION_CAMPAIGN_LOGS, [] );
		$campaign_id = sanitize_text_field( $campaign_id );
		$logs        = [];

		if ( ! is_array( $stored ) ) {
			return $logs;
		}

		foreach ( $stored as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$entry = [
				'time'        => isset( $log['time'] ) ? absint( $log['time'] ) : 0,
				'campaign_id' => isset( $log['campaign_id'] ) ? sanitize_text_field( $log['campaign_id'] ) : 'unknown',
				'email'       => isset( $log['email'] ) ? sanitize_email( $log['email'] ) : 'unknown',
				'status'      => isset( $log['status'] ) ? sanitize_key( $log['status'] ) : '',
				'message'     => isset( $log['message'] ) ? sanitize_text_field( $log['message'] ) : '',
				'action_id'   => isset( $log['action_id'] ) ? absint( $log['action_id'] ) : 0,
			];

			if ( ! empty( $campaign_id ) && $campaign_id !== $entry['campaign_id'] ) {
				continue;
			}

			$logs[] = $entry;
		}

		if ( ! empty( $campaign_id ) && $this->recipient_tracking_table_exists() ) {
			global $wpdb;

			$table_name = $this->get_recipient_tracking_table_name();
			$row_limit  = $limit > 0 ? min( self::CAMPAIGN_LOG_LIMIT, max( absint( $limit ), self::CAMPAIGN_LOG_PER_PAGE ) ) : self::CAMPAIGN_LOG_LIMIT;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom delivery state must be read/written directly so concurrent workers and reconciliation see current status.
			$tracked_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT email,status,action_id,last_error,updated_at
					FROM %i
					WHERE campaign_id = %s AND status <> 'dispatch_pending'
					ORDER BY updated_at DESC
					LIMIT %d",
					$table_name,
					$campaign_id,
					$row_limit
				),
				ARRAY_A
			);

			foreach ( (array) $tracked_rows as $row ) {
				$email = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';

				if ( empty( $email ) ) {
					continue;
				}

				$logs[] = [
					'time'        => ! empty( $row['updated_at'] ) ? absint( strtotime( $row['updated_at'] . ' UTC' ) ) : 0,
					'campaign_id' => $campaign_id,
					'email'       => $email,
					'status'      => isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '',
					'message'     => isset( $row['last_error'] ) ? sanitize_text_field( $row['last_error'] ) : '',
					'action_id'   => isset( $row['action_id'] ) ? absint( $row['action_id'] ) : 0,
				];
			}
		}

		usort(
			$logs,
			function ( $a, $b ) {
				return absint( isset( $b['time'] ) ? $b['time'] : 0 ) <=> absint( isset( $a['time'] ) ? $a['time'] : 0 );
			}
		);

		return $limit > 0 ? array_slice( $logs, 0, absint( $limit ) ) : $logs;
	}

	private function get_campaign_reschedule_payload( $campaign ) {
		$campaign    = is_array( $campaign ) ? $campaign : [];
		$campaign_id = isset( $campaign['campaign_id'] ) ? sanitize_text_field( $campaign['campaign_id'] ) : '';

		if ( empty( $campaign_id ) ) {
			return new WP_Error( 'yoohw_an_missing_campaign_id', __( 'Missing campaign ID.', 'yoohw-advanced-notifications' ) );
		}

		$needs_sample = empty( $campaign['subject'] ) || empty( $campaign['heading'] ) || empty( $campaign['message'] ) || empty( $campaign['products_label'] );
		$sample_args  = $needs_sample ? $this->get_campaign_action_args_sample( $campaign_id ) : [];
		$sources      = $this->get_recipient_sources();

		$recipient_source = isset( $campaign['recipient_source'] ) ? sanitize_key( $campaign['recipient_source'] ) : self::SOURCE_PRODUCT_CUSTOMERS;

		if ( ! isset( $sources[ $recipient_source ] ) ) {
			$recipient_source = self::SOURCE_PRODUCT_CUSTOMERS;
		}

		$payload = [
			'campaign_id'            => $campaign_id,
			'campaign_hash'          => isset( $campaign['campaign_hash'] ) ? sanitize_text_field( $campaign['campaign_hash'] ) : '',
			'delivery_id'            => isset( $campaign['delivery_id'] ) ? sanitize_text_field( $campaign['delivery_id'] ) : $campaign_id,
			'recipient_source'       => $recipient_source,
			'recipient_source_label' => isset( $campaign['recipient_source_label'] ) && '' !== $campaign['recipient_source_label']
				? sanitize_text_field( $campaign['recipient_source_label'] )
				: $sources[ $recipient_source ],
			'product_ids'            => isset( $campaign['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $campaign['product_ids'] ) ) ) ) : [],
			'user_roles'             => isset( $campaign['user_roles'] ) ? $campaign['user_roles'] : [],
			'manual_emails'          => isset( $campaign['manual_emails'] ) ? $campaign['manual_emails'] : [],
			'subject'                => isset( $campaign['subject'] ) && '' !== $campaign['subject'] ? sanitize_text_field( $campaign['subject'] ) : ( isset( $sample_args['subject'] ) ? sanitize_text_field( $sample_args['subject'] ) : '' ),
			'heading'                => isset( $campaign['heading'] ) && '' !== $campaign['heading'] ? sanitize_text_field( $campaign['heading'] ) : ( isset( $sample_args['heading'] ) ? sanitize_text_field( $sample_args['heading'] ) : '' ),
			'message'                => isset( $campaign['message'] ) && '' !== $campaign['message'] ? wp_kses_post( $campaign['message'] ) : ( isset( $sample_args['message'] ) ? wp_kses_post( $sample_args['message'] ) : '' ),
			'products_label'         => isset( $campaign['products_label'] ) && '' !== $campaign['products_label'] ? sanitize_text_field( $campaign['products_label'] ) : ( isset( $sample_args['products_label'] ) ? sanitize_text_field( $sample_args['products_label'] ) : '' ),
			'schedule_mode'          => isset( $campaign['schedule_mode'] ) ? sanitize_key( $campaign['schedule_mode'] ) : 'scheduled',
			'scheduled_at_input'     => isset( $campaign['scheduled_at_input'] ) ? sanitize_text_field( $campaign['scheduled_at_input'] ) : '',
			'scheduled_at_timestamp' => isset( $campaign['scheduled_at_timestamp'] ) ? absint( $campaign['scheduled_at_timestamp'] ) : 0,
			'test_mode'              => false,
			'from_email'             => isset( $campaign['from_email'] ) && is_email( $campaign['from_email'] ) ? sanitize_email( $campaign['from_email'] ) : ( isset( $sample_args['from_email'] ) ? sanitize_email( $sample_args['from_email'] ) : $this->get_sender_email() ),
			'from_name'              => isset( $campaign['from_name'] ) && '' !== $campaign['from_name'] ? sanitize_text_field( $campaign['from_name'] ) : ( isset( $sample_args['from_name'] ) ? sanitize_text_field( $sample_args['from_name'] ) : $this->get_sender_name() ),
			'reply_to_email'         => isset( $campaign['reply_to_email'] ) && is_email( $campaign['reply_to_email'] ) ? sanitize_email( $campaign['reply_to_email'] ) : ( isset( $sample_args['reply_to_email'] ) ? sanitize_email( $sample_args['reply_to_email'] ) : $this->get_reply_to_email() ),
			'exclusions'             => isset( $campaign['exclusions'] ) && is_array( $campaign['exclusions'] ) ? $this->sanitize_campaign_exclusions( $campaign['exclusions'] ) : [],
			'exclude_emails_raw'     => isset( $campaign['exclude_emails_raw'] ) ? sanitize_textarea_field( $campaign['exclude_emails_raw'] ) : '',
			'exclusion_labels'       => isset( $campaign['exclusion_labels'] ) && is_array( $campaign['exclusion_labels'] ) ? $this->sanitize_text_list( $campaign['exclusion_labels'] ) : [],
		];

		if ( empty( $payload['products_label'] ) ) {
			$payload['products_label'] = ! empty( $payload['product_ids'] )
				? $this->get_selected_products_label( $payload['product_ids'] )
				: $payload['recipient_source_label'];
		}

		if ( 'scheduled' === $payload['schedule_mode'] && empty( $payload['scheduled_at_timestamp'] ) ) {
			$payload['scheduled_at_timestamp'] = $this->parse_scheduled_timestamp( $payload['scheduled_at_input'], new DateTimeZone( 'UTC' ) );
		}

		if ( empty( $payload['from_email'] ) || ! is_email( $payload['from_email'] ) ) {
			$payload['from_email'] = $this->get_sender_email();
		}

		if ( empty( $payload['from_name'] ) ) {
			$payload['from_name'] = $this->get_sender_name();
		}

		if ( empty( $payload['reply_to_email'] ) || ! is_email( $payload['reply_to_email'] ) ) {
			$payload['reply_to_email'] = $this->get_reply_to_email();
		}

		if (
			( 'scheduled' === $payload['schedule_mode'] && empty( $payload['scheduled_at_input'] ) ) ||
			empty( $payload['subject'] ) ||
			empty( $payload['heading'] ) ||
			empty( $payload['message'] )
		) {
			return new WP_Error( 'yoohw_an_missing_reschedule_payload', __( 'Missing campaign content needed for rescheduling.', 'yoohw-advanced-notifications' ) );
		}

		if ( empty( $payload['campaign_hash'] ) ) {
			$payload['campaign_hash'] = $this->generate_campaign_hash( $payload );
		}

		return $payload;
	}

	private function get_campaign_action_args_sample( $campaign_id ) {
		$campaign_id = sanitize_text_field( $campaign_id );

		if ( empty( $campaign_id ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}

		$statuses = class_exists( 'ActionScheduler_Store' )
			? [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_COMPLETE ]
			: [ 'pending', 'complete' ];

		foreach ( $statuses as $status ) {
			$actions = as_get_scheduled_actions(
				[
					'hook'     => self::ACTION_SEND_SINGLE_EMAIL,
					'group'    => self::ACTION_GROUP,
					'status'   => $status,
					'per_page' => 5000,
					'orderby'  => 'date',
					'order'    => 'ASC',
				],
				OBJECT
			);

			foreach ( (array) $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}

				$action_args = $action->get_args();
				$email_args  = isset( $action_args[0] ) && is_array( $action_args[0] ) ? $action_args[0] : [];

				if ( empty( $email_args['campaign_id'] ) || $campaign_id !== sanitize_text_field( $email_args['campaign_id'] ) ) {
					continue;
				}

				return $email_args;
			}
		}

		return [];
	}

	private function get_schedule_retry_availability( $campaign ) {
		global $wpdb;
		$id = $campaign['campaign_id'];
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE campaign_id = %s AND attempts < 3 AND (status IN ('dispatch_pending','queue_failed','schedule_failed') OR (status = 'failed' AND error_kind = 'configuration'))",
			$this->get_recipient_tracking_table_name(), $id
		) );
		if ( empty( $campaign['preparation_complete'] ) && is_array( $this->read_durable_option( $this->campaign_manifest_key( $id ) ) ) ) {
			$count = max( 1, $count );
		}
		return [ 'available' => $count > 0, 'count' => $count, 'source' => 'tracking' ];
	}


	private function sanitize_recipient_context( $recipient ) {
		return [
			'user_id'  => isset( $recipient['user_id'] ) ? absint( $recipient['user_id'] ) : 0,
			'email'    => isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '',
			'name'     => isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '',
			'country'  => isset( $recipient['country'] ) ? $this->normalize_country_code( $recipient['country'] ) : '',
			'state'    => isset( $recipient['state'] ) ? $this->normalize_location_part( $recipient['state'] ) : '',
			'city'     => isset( $recipient['city'] ) ? $this->normalize_location_part( $recipient['city'] ) : '',
			'timezone' => isset( $recipient['timezone'] ) ? sanitize_text_field( $recipient['timezone'] ) : '',
		];
	}

	private function increment_campaign_status_count( $event ) {
		$campaign_id = isset( $event['campaign_id'] ) ? sanitize_text_field( $event['campaign_id'] ) : '';

		if ( empty( $campaign_id ) ) {
			return;
		}

		$campaigns = $this->get_campaign_history();

		if ( empty( $campaigns[ $campaign_id ] ) ) {
			$campaigns[ $campaign_id ] = [
				'campaign_id'   => $campaign_id,
				'status'        => '',
				'status_counts' => [],
				'created_at'    => time(),
			];
		}

		$status = isset( $event['status'] ) ? sanitize_key( $event['status'] ) : '';

		if ( empty( $status ) ) {
			return;
		}

		$campaigns[ $campaign_id ]['status_counts'] = isset( $campaigns[ $campaign_id ]['status_counts'] ) && is_array( $campaigns[ $campaign_id ]['status_counts'] )
			? $this->sanitize_count_map( $campaigns[ $campaign_id ]['status_counts'] )
			: [];
		$campaigns[ $campaign_id ]['status_counts'][ $status ] = isset( $campaigns[ $campaign_id ]['status_counts'][ $status ] )
			? absint( $campaigns[ $campaign_id ]['status_counts'][ $status ] ) + 1
			: 1;
		$campaigns[ $campaign_id ]['last_status']  = $status;
		$campaigns[ $campaign_id ]['last_message'] = isset( $event['message'] ) ? sanitize_text_field( $event['message'] ) : '';
		$campaigns[ $campaign_id ]['last_action_id'] = isset( $event['action_id'] ) ? absint( $event['action_id'] ) : 0;
		$campaigns[ $campaign_id ]['updated_at']   = time();

		if ( empty( $campaigns[ $campaign_id ]['status'] ) ) {
			$campaigns[ $campaign_id ]['status'] = $status;
		}

		$this->save_campaign_record( $campaigns[ $campaign_id ] );
	}

	private function update_nonautoload_option( $option_name, $value ) {
		if ( false === get_option( $option_name, false ) ) {
			return add_option( $option_name, $value, '', 'no' );
		}

		return update_option( $option_name, $value );
	}

	private function sanitize_text_list( $items ) {
		$clean = [];

		foreach ( (array) $items as $item ) {
			$item = sanitize_text_field( $item );

			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function sanitize_campaign_exclusions( $exclusions ) {
		$exclusions = is_array( $exclusions ) ? $exclusions : [];

		return [
			'order_statuses'        => $this->sanitize_order_statuses( isset( $exclusions['order_statuses'] ) ? $exclusions['order_statuses'] : [] ),
			'product_ids'           => isset( $exclusions['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $exclusions['product_ids'] ) ) ) ) : [],
			'emails'                => isset( $exclusions['emails'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_email', (array) $exclusions['emails'] ) ) ) ) : [],
		];
	}

	private function sanitize_count_map( $counts ) {
		$clean = [];

		foreach ( (array) $counts as $status => $count ) {
			$status = sanitize_key( $status );

			if ( empty( $status ) ) {
				continue;
			}

			$clean[ $status ] = absint( $count );
		}

		return $clean;
	}

	private function get_campaign_status_label( $campaign ) {
		return $this->get_status_label_from_key( $this->get_campaign_display_status( $campaign ) );
	}

	private function get_campaign_status_class( $campaign ) {
		return $this->get_status_class_from_key( $this->get_campaign_display_status( $campaign ) );
	}

	private function get_campaign_display_status( $campaign ) {
		$status = isset( $campaign['status'] ) ? sanitize_key( $campaign['status'] ) : '';
		if ( ! empty( $campaign['tracking_counts'] ) ) {
			return $status;
		}
		$counts = isset( $campaign['status_counts'] ) && is_array( $campaign['status_counts'] )
			? $this->sanitize_count_map( $campaign['status_counts'] )
			: [];

		$recipient_count = isset( $campaign['recipient_count'] ) ? absint( $campaign['recipient_count'] ) : 0;
		$sent            = isset( $counts['sent'] ) ? absint( $counts['sent'] ) : 0;
		$failed          = isset( $counts['failed'] ) ? absint( $counts['failed'] ) : 0;
		$skipped         = ( isset( $counts['skipped'] ) ? absint( $counts['skipped'] ) : 0 ) + ( isset( $counts['skipped_unsubscribed'] ) ? absint( $counts['skipped_unsubscribed'] ) : 0 ) + ( isset( $counts['skipped_duplicate'] ) ? absint( $counts['skipped_duplicate'] ) : 0 );
		$processed       = $sent + $failed + $skipped;

		if ( in_array( $status, [ 'queued', 'scheduled' ], true ) && $recipient_count > 0 ) {
			if ( $processed >= $recipient_count ) {
				return 'completed';
			}

			if ( $processed > 0 ) {
				return 'processing';
			}
		}

		if ( empty( $status ) && ! empty( $campaign['last_status'] ) ) {
			return sanitize_key( $campaign['last_status'] );
		}

		return ! empty( $status ) ? $status : 'unknown';
	}

	private function get_status_label_from_key( $status ) {
		$status = sanitize_key( $status );

		$labels = [
			'preparing'             => __( 'Saving recipient snapshot', 'yoohw-advanced-notifications' ),
			'needs_attention'       => __( 'Needs attention', 'yoohw-advanced-notifications' ),
			'previewed'             => __( 'Previewed', 'yoohw-advanced-notifications' ),
			'test'                  => __( 'Testing', 'yoohw-advanced-notifications' ),
			'test_sent'             => __( 'Test handed to mail system', 'yoohw-advanced-notifications' ),
			'test_failed'           => __( 'Test failed', 'yoohw-advanced-notifications' ),
			'queueing'              => __( 'Queueing', 'yoohw-advanced-notifications' ),
			'dispatching'           => __( 'Preparing in background', 'yoohw-advanced-notifications' ),
			'dispatch_pending'      => __( 'Preparing', 'yoohw-advanced-notifications' ),
			'queued'                => __( 'Queued', 'yoohw-advanced-notifications' ),
			'queue_failed'          => __( 'Queue failed', 'yoohw-advanced-notifications' ),
			'scheduling'            => __( 'Scheduling', 'yoohw-advanced-notifications' ),
			'scheduled'             => __( 'Scheduled', 'yoohw-advanced-notifications' ),
			'schedule_failed'       => __( 'Schedule failed', 'yoohw-advanced-notifications' ),
			'processing'            => __( 'Processing', 'yoohw-advanced-notifications' ),
			'completed'             => __( 'Completed', 'yoohw-advanced-notifications' ),
			'completed_with_errors' => __( 'Completed with issues', 'yoohw-advanced-notifications' ),
			'sent'                  => __( 'Handed to mail system', 'yoohw-advanced-notifications' ),
			'failed'                => __( 'Failed', 'yoohw-advanced-notifications' ),
			'skipped'               => __( 'Skipped', 'yoohw-advanced-notifications' ),
			'skipped_unsubscribed'  => __( 'Skipped unsubscribed', 'yoohw-advanced-notifications' ),
			'skipped_duplicate'     => __( 'Skipped duplicate', 'yoohw-advanced-notifications' ),
			'unsubscribed'          => __( 'Unsubscribed', 'yoohw-advanced-notifications' ),
			'duplicate_blocked'     => __( 'Duplicate blocked', 'yoohw-advanced-notifications' ),
			'empty'                 => __( 'No recipients', 'yoohw-advanced-notifications' ),
			'cancelled'             => __( 'Cancelled', 'yoohw-advanced-notifications' ),
			'unresolved'            => __( 'Unresolved', 'yoohw-advanced-notifications' ),
			'unknown'               => __( 'Outcome unknown', 'yoohw-advanced-notifications' ),
		];

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
	}

	private function get_status_class_from_key( $status ) {
		$status = sanitize_key( $status );

		if ( in_array( $status, [ 'failed', 'test_failed', 'schedule_failed', 'queue_failed', 'completed_with_errors' ], true ) ) {
			return 'is-error';
		}

		if ( in_array( $status, [ 'sent', 'test_sent', 'completed' ], true ) ) {
			return 'is-success';
		}

		if ( in_array( $status, [ 'preparing', 'dispatching', 'queued', 'scheduled', 'processing', 'queueing', 'scheduling' ], true ) ) {
			return 'is-info';
		}

		if ( in_array( $status, [ 'needs_attention', 'unknown', 'skipped', 'skipped_unsubscribed', 'skipped_duplicate', 'duplicate_blocked', 'empty', 'unsubscribed' ], true ) ) {
			return 'is-warning';
		}

		return 'is-neutral';
	}

	private function format_campaign_recipient_summary( $campaign ) {
		$recipient_count = isset( $campaign['recipient_count'] ) ? absint( $campaign['recipient_count'] ) : 0;
		$base_count      = isset( $campaign['recipient_count_before_exclusions'] ) ? absint( $campaign['recipient_count_before_exclusions'] ) : $recipient_count;
		$excluded_count  = isset( $campaign['excluded_count'] ) ? absint( $campaign['excluded_count'] ) : 0;

		if ( $excluded_count > 0 && $base_count > $recipient_count ) {
			return sprintf(
				/* translators: 1: Final number of recipients. 2: Excluded recipients. 3: Initial recipients. */ __( '%1$d recipient(s), %2$d excluded from %3$d', 'yoohw-advanced-notifications' ),
				$recipient_count,
				$excluded_count,
				$base_count
			);
		}

		return sprintf(
			/* translators: %d: Number of recipients. */ __( '%d recipient(s)', 'yoohw-advanced-notifications' ),
			$recipient_count
		);
	}

	private function get_campaign_delivery_label( $campaign ) {
		if ( ! empty( $campaign['test_mode'] ) ) {
			return __( 'Test email only', 'yoohw-advanced-notifications' );
		}

		$schedule_mode = isset( $campaign['schedule_mode'] ) ? sanitize_key( $campaign['schedule_mode'] ) : 'now';

		if ( 'scheduled' === $schedule_mode ) {
			$scheduled_at = isset( $campaign['scheduled_at_input'] ) ? sanitize_text_field( $campaign['scheduled_at_input'] ) : '';

			return sprintf(
				/* translators: %s: Scheduled local date and time. */ __( 'Scheduled: %s by recipient timezone', 'yoohw-advanced-notifications' ),
				! empty( $scheduled_at ) ? $this->format_schedule_input_for_display( $scheduled_at ) : __( 'selected time', 'yoohw-advanced-notifications' )
			);
		}

		return __( 'After confirmation', 'yoohw-advanced-notifications' );
	}

	private function format_campaign_status_counts( $campaign ) {
		$has_tracking = isset( $campaign['tracking_counts'] ) && is_array( $campaign['tracking_counts'] ) && ! empty( $campaign['tracking_counts'] );
		$counts       = $has_tracking
			? $this->sanitize_count_map( $campaign['tracking_counts'] )
			: ( isset( $campaign['status_counts'] ) && is_array( $campaign['status_counts'] ) ? $this->sanitize_count_map( $campaign['status_counts'] ) : [] );

		if ( ! $has_tracking ) {
			foreach ( [ 'queued', 'scheduled', 'sent' ] as $status ) {
				$count_key = $status . '_count';

				if ( ! empty( $campaign[ $count_key ] ) ) {
					$counts[ $status ] = max( isset( $counts[ $status ] ) ? absint( $counts[ $status ] ) : 0, absint( $campaign[ $count_key ] ) );
				}
			}
		}

		$order = [
			'dispatch_pending',
			'queued',
			'scheduled',
			'processing',
			'unknown',
			'queue_failed',
			'cancelled',
			'sent',
			'failed',
			'schedule_failed',
			'skipped',
			'skipped_unsubscribed',
			'skipped_duplicate',
			'test_sent',
			'test_failed',
			'unsubscribed',
			'duplicate_blocked',
			'unresolved',
		];

		if ( ! empty( $campaign['unresolved_count'] ) ) {
			$counts['unresolved'] = absint( $campaign['unresolved_count'] );
		}
		$parts = [];

		foreach ( $order as $status ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}

			$parts[] = sprintf(
				'%1$s: %2$d',
				$this->get_status_label_from_key( $status ),
				absint( $counts[ $status ] )
			);
		}

		return ! empty( $parts ) ? implode( ' | ', $parts ) : __( 'No activity yet', 'yoohw-advanced-notifications' );
	}

	private function format_campaign_history_time( $timestamp ) {
		$timestamp = absint( $timestamp );

		if ( ! $timestamp ) {
			return '-';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	private function get_sender_email() {
		$default = sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
		$from_email = sanitize_email( get_option( self::OPTION_FROM_EMAIL, $default ) );

		return is_email( $from_email ) ? $from_email : $default;
	}

	private function get_sender_name() {
		$default = sanitize_text_field( get_option( 'woocommerce_email_from_name', get_option( 'blogname' ) ) );
		$from_name = sanitize_text_field( get_option( self::OPTION_FROM_NAME, $default ) );

		return ! empty( $from_name ) ? $from_name : $default;
	}

	private function get_reply_to_email() {
		$reply_to_email = sanitize_email( get_option( self::OPTION_REPLY_TO_EMAIL, '' ) );

		return is_email( $reply_to_email ) ? $reply_to_email : $this->get_sender_email();
	}

	private function should_suppress_automated_replies() {
		return 'yes' === get_option( self::OPTION_SUPPRESS_AUTOMATED_REPLIES, 'no' );
	}

	private function get_no_reply_email() {
		$no_reply_email = sanitize_email( get_option( self::OPTION_NO_REPLY_EMAIL, '' ) );

		return is_email( $no_reply_email ) ? $no_reply_email : $this->get_sender_email();
	}

	private function get_effective_reply_to_email( $reply_to_email = '' ) {
		if ( $this->should_suppress_automated_replies() ) {
			return $this->get_no_reply_email();
		}

		$reply_to_email = sanitize_email( $reply_to_email );

		return is_email( $reply_to_email ) ? $reply_to_email : $this->get_reply_to_email();
	}

	private function save_sender_settings( $from_email, $from_name, $reply_to_email, $suppress_automated_replies = false, $no_reply_email = '' ) {
		$from_email     = sanitize_email( $from_email );
		$from_name      = sanitize_text_field( $from_name );
		$reply_to_email = sanitize_email( $reply_to_email );
		$no_reply_email = sanitize_email( $no_reply_email );

		if ( is_email( $from_email ) ) {
			update_option( self::OPTION_FROM_EMAIL, $from_email );
		}

		if ( ! empty( $from_name ) ) {
			update_option( self::OPTION_FROM_NAME, $from_name );
		}

		if ( is_email( $reply_to_email ) ) {
			update_option( self::OPTION_REPLY_TO_EMAIL, $reply_to_email );
		}

		update_option(
			self::OPTION_SUPPRESS_AUTOMATED_REPLIES,
			$suppress_automated_replies ? 'yes' : 'no',
			false
		);

		if ( is_email( $no_reply_email ) ) {
			update_option( self::OPTION_NO_REPLY_EMAIL, $no_reply_email, false );
		}
	}

	private function parse_scheduled_timestamp( $value, $timezone = null ) {
		$date = $this->parse_scheduled_datetime( $value, $timezone );

		return $date instanceof DateTimeImmutable ? $date->getTimestamp() : 0;
	}

	private function parse_scheduled_datetime( $value, $timezone = null ) {
		$value = trim( (string) $value );

		if ( empty( $value ) ) {
			return null;
		}

		if ( ! $timezone instanceof DateTimeZone ) {
			$timezone = new DateTimeZone( 'UTC' );
		}

		foreach ( [ '!Y-m-d\TH:i:s', '!Y-m-d\TH:i' ] as $format ) {
			$date   = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
			$errors = DateTimeImmutable::getLastErrors();

			if (
				$date instanceof DateTimeImmutable &&
				(
					false === $errors ||
					(
						empty( $errors['warning_count'] ) &&
						empty( $errors['error_count'] )
					)
				)
			) {
				return $date;
			}
		}

		return null;
	}

	private function format_schedule_timestamp( $timestamp, $timezone = null ) {
		$timestamp = absint( $timestamp );

		if ( ! $timestamp ) {
			return '';
		}

		if ( ! $timezone instanceof DateTimeZone ) {
			if ( is_string( $timezone ) && ! empty( $timezone ) ) {
				try {
					$timezone = new DateTimeZone( $timezone );
				} catch ( Exception $e ) {
					$timezone = new DateTimeZone( 'UTC' );
				}
			} else {
				$timezone = new DateTimeZone( 'UTC' );
			}
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp,
			$timezone
		);
	}

	private function format_schedule_input_for_display( $value ) {
		$timestamp = $this->parse_scheduled_timestamp( $value, new DateTimeZone( 'UTC' ) );

		if ( ! $timestamp ) {
			return sanitize_text_field( $value );
		}

		return $this->format_schedule_timestamp( $timestamp, new DateTimeZone( 'UTC' ) );
	}

	private function get_default_schedule_datetime_value() {
		return wp_date( 'Y-m-d\TH:i', time() + HOUR_IN_SECONDS, new DateTimeZone( 'UTC' ) );
	}

	private function is_payload_scheduled( $payload ) {
		return (
			isset( $payload['schedule_mode'], $payload['scheduled_at_input'] ) &&
			'scheduled' === $payload['schedule_mode'] &&
			'' !== trim( (string) $payload['scheduled_at_input'] )
		);
	}

	private function get_recipient_scheduled_delivery( $payload, $recipient, $roll_forward = true ) {
		$country       = isset( $recipient['country'] ) ? $this->normalize_country_code( $recipient['country'] ) : '';
		$state         = isset( $recipient['state'] ) ? $this->normalize_location_part( $recipient['state'] ) : '';
		$city          = isset( $recipient['city'] ) ? $this->normalize_location_part( $recipient['city'] ) : '';
		$timezone_name = isset( $recipient['timezone'] ) ? sanitize_text_field( $recipient['timezone'] ) : '';

		if ( empty( $timezone_name ) ) {
			$timezone_name = $this->get_timezone_name_from_location( $country, $state, $city );
		}

		try {
			$timezone = new DateTimeZone( $timezone_name );
		} catch ( Exception $e ) {
			$timezone_name = 'UTC';
			$timezone      = new DateTimeZone( 'UTC' );
		}

		$scheduled_date = $this->parse_scheduled_datetime(
			isset( $payload['scheduled_at_input'] ) ? $payload['scheduled_at_input'] : '',
			$timezone
		);
		$rolled_forward_days = 0;

		while ( $roll_forward && $scheduled_date instanceof DateTimeImmutable && $scheduled_date->getTimestamp() <= time() && $rolled_forward_days < 366 ) {
			$scheduled_date = $scheduled_date->modify( '+1 day' );
			$rolled_forward_days++;
		}

		$timestamp = $scheduled_date instanceof DateTimeImmutable ? $scheduled_date->getTimestamp() : 0;

		return [
			'timestamp'           => $timestamp,
			'country'             => ! empty( $country ) ? $country : 'UTC',
			'state'               => $state,
			'city'                => $city,
			'timezone'            => $timezone_name,
			'rolled_forward_days' => $rolled_forward_days,
		];
	}

	private function normalize_country_code( $country ) {
		$country = strtoupper( sanitize_text_field( (string) $country ) );

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	private function normalize_location_part( $value ) {
		return sanitize_text_field( trim( (string) $value ) );
	}

	private function normalize_timezone_lookup_value( $value ) {
		$value = remove_accents( (string) $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '', $value );

		return (string) $value;
	}

	private function get_timezone_name_from_location( $country, $state = '', $city = '' ) {
		$country = $this->normalize_country_code( $country );
		$state   = $this->normalize_location_part( $state );
		$city    = $this->normalize_location_part( $city );

		if ( empty( $country ) ) {
			return 'UTC';
		}

		$timezones = DateTimeZone::listIdentifiers( DateTimeZone::PER_COUNTRY, $country );

		if ( empty( $timezones ) ) {
			return 'UTC';
		}

		$city_timezone = $this->get_timezone_name_from_city( $timezones, $city );

		if ( $city_timezone ) {
			return $city_timezone;
		}

		$state_timezone = $this->get_timezone_name_from_state( $country, $state );

		if ( $state_timezone ) {
			return $state_timezone;
		}

		return reset( $timezones );
	}

	private function get_timezone_name_from_city( $timezones, $city ) {
		$city_key = $this->normalize_timezone_lookup_value( $city );

		if ( empty( $city_key ) ) {
			return '';
		}

		foreach ( (array) $timezones as $timezone_name ) {
			$parts         = explode( '/', $timezone_name );
			$timezone_city = end( $parts );

			if ( $city_key === $this->normalize_timezone_lookup_value( str_replace( '_', ' ', $timezone_city ) ) ) {
				return $timezone_name;
			}
		}

		return '';
	}

	private function get_timezone_name_from_state( $country, $state ) {
		$country   = $this->normalize_country_code( $country );
		$state_key = strtoupper( preg_replace( '/[^A-Z0-9]/', '', remove_accents( (string) $state ) ) );

		if ( empty( $country ) || empty( $state_key ) ) {
			return '';
		}

		$state_aliases = [
			'US' => [
				'ALABAMA'              => 'AL',
				'ALASKA'               => 'AK',
				'ARIZONA'              => 'AZ',
				'ARKANSAS'             => 'AR',
				'CALIFORNIA'           => 'CA',
				'COLORADO'             => 'CO',
				'CONNECTICUT'          => 'CT',
				'DELAWARE'             => 'DE',
				'DISTRICTOFCOLUMBIA'   => 'DC',
				'FLORIDA'              => 'FL',
				'GEORGIA'              => 'GA',
				'HAWAII'               => 'HI',
				'IDAHO'                => 'ID',
				'ILLINOIS'             => 'IL',
				'INDIANA'              => 'IN',
				'IOWA'                 => 'IA',
				'KANSAS'               => 'KS',
				'KENTUCKY'             => 'KY',
				'LOUISIANA'            => 'LA',
				'MAINE'                => 'ME',
				'MARYLAND'             => 'MD',
				'MASSACHUSETTS'        => 'MA',
				'MICHIGAN'             => 'MI',
				'MINNESOTA'            => 'MN',
				'MISSISSIPPI'          => 'MS',
				'MISSOURI'             => 'MO',
				'MONTANA'              => 'MT',
				'NEBRASKA'             => 'NE',
				'NEVADA'               => 'NV',
				'NEWHAMPSHIRE'         => 'NH',
				'NEWJERSEY'            => 'NJ',
				'NEWMEXICO'            => 'NM',
				'NEWYORK'              => 'NY',
				'NORTHCAROLINA'        => 'NC',
				'NORTHDAKOTA'          => 'ND',
				'OHIO'                 => 'OH',
				'OKLAHOMA'             => 'OK',
				'OREGON'               => 'OR',
				'PENNSYLVANIA'         => 'PA',
				'RHODEISLAND'          => 'RI',
				'SOUTHCAROLINA'        => 'SC',
				'SOUTHDAKOTA'          => 'SD',
				'TENNESSEE'            => 'TN',
				'TEXAS'                => 'TX',
				'UTAH'                 => 'UT',
				'VERMONT'              => 'VT',
				'VIRGINIA'             => 'VA',
				'WASHINGTON'           => 'WA',
				'WESTVIRGINIA'         => 'WV',
				'WISCONSIN'            => 'WI',
				'WYOMING'              => 'WY',
				'PUERTORICO'           => 'PR',
			],
			'CA' => [
				'ALBERTA'                  => 'AB',
				'BRITISHCOLUMBIA'          => 'BC',
				'MANITOBA'                 => 'MB',
				'NEWBRUNSWICK'             => 'NB',
				'NEWFOUNDLANDANDLABRADOR'  => 'NL',
				'NOVASCOTIA'               => 'NS',
				'NORTHWESTTERRITORIES'     => 'NT',
				'NUNAVUT'                  => 'NU',
				'ONTARIO'                  => 'ON',
				'PRINCEEDWARDISLAND'       => 'PE',
				'QUEBEC'                   => 'QC',
				'SASKATCHEWAN'             => 'SK',
				'YUKON'                    => 'YT',
			],
			'AU' => [
				'AUSTRALIANCAPITALTERRITORY' => 'ACT',
				'NEWSOUTHWALES'              => 'NSW',
				'NORTHERNTERRITORY'          => 'NT',
				'QUEENSLAND'                 => 'QLD',
				'SOUTHAUSTRALIA'             => 'SA',
				'TASMANIA'                   => 'TAS',
				'VICTORIA'                   => 'VIC',
				'WESTERNAUSTRALIA'           => 'WA',
			],
		];

		if ( isset( $state_aliases[ $country ][ $state_key ] ) ) {
			$state_key = $state_aliases[ $country ][ $state_key ];
		}

		$maps = [
			'US' => [
				'AL' => 'America/Chicago',
				'AK' => 'America/Anchorage',
				'AZ' => 'America/Phoenix',
				'AR' => 'America/Chicago',
				'CA' => 'America/Los_Angeles',
				'CO' => 'America/Denver',
				'CT' => 'America/New_York',
				'DE' => 'America/New_York',
				'DC' => 'America/New_York',
				'FL' => 'America/New_York',
				'GA' => 'America/New_York',
				'HI' => 'Pacific/Honolulu',
				'ID' => 'America/Boise',
				'IL' => 'America/Chicago',
				'IN' => 'America/Indiana/Indianapolis',
				'IA' => 'America/Chicago',
				'KS' => 'America/Chicago',
				'KY' => 'America/New_York',
				'LA' => 'America/Chicago',
				'ME' => 'America/New_York',
				'MD' => 'America/New_York',
				'MA' => 'America/New_York',
				'MI' => 'America/Detroit',
				'MN' => 'America/Chicago',
				'MS' => 'America/Chicago',
				'MO' => 'America/Chicago',
				'MT' => 'America/Denver',
				'NE' => 'America/Chicago',
				'NV' => 'America/Los_Angeles',
				'NH' => 'America/New_York',
				'NJ' => 'America/New_York',
				'NM' => 'America/Denver',
				'NY' => 'America/New_York',
				'NC' => 'America/New_York',
				'ND' => 'America/Chicago',
				'OH' => 'America/New_York',
				'OK' => 'America/Chicago',
				'OR' => 'America/Los_Angeles',
				'PA' => 'America/New_York',
				'RI' => 'America/New_York',
				'SC' => 'America/New_York',
				'SD' => 'America/Chicago',
				'TN' => 'America/Chicago',
				'TX' => 'America/Chicago',
				'UT' => 'America/Denver',
				'VT' => 'America/New_York',
				'VA' => 'America/New_York',
				'WA' => 'America/Los_Angeles',
				'WV' => 'America/New_York',
				'WI' => 'America/Chicago',
				'WY' => 'America/Denver',
				'PR' => 'America/Puerto_Rico',
			],
			'CA' => [
				'AB' => 'America/Edmonton',
				'BC' => 'America/Vancouver',
				'MB' => 'America/Winnipeg',
				'NB' => 'America/Moncton',
				'NL' => 'America/St_Johns',
				'NS' => 'America/Halifax',
				'NT' => 'America/Yellowknife',
				'NU' => 'America/Iqaluit',
				'ON' => 'America/Toronto',
				'PE' => 'America/Halifax',
				'QC' => 'America/Toronto',
				'SK' => 'America/Regina',
				'YT' => 'America/Whitehorse',
			],
			'AU' => [
				'ACT' => 'Australia/Sydney',
				'NSW' => 'Australia/Sydney',
				'NT'  => 'Australia/Darwin',
				'QLD' => 'Australia/Brisbane',
				'SA'  => 'Australia/Adelaide',
				'TAS' => 'Australia/Hobart',
				'VIC' => 'Australia/Melbourne',
				'WA'  => 'Australia/Perth',
			],
			'BR' => [
				'AC' => 'America/Rio_Branco',
				'AL' => 'America/Maceio',
				'AP' => 'America/Belem',
				'AM' => 'America/Manaus',
				'BA' => 'America/Bahia',
				'CE' => 'America/Fortaleza',
				'DF' => 'America/Sao_Paulo',
				'ES' => 'America/Sao_Paulo',
				'GO' => 'America/Sao_Paulo',
				'MA' => 'America/Fortaleza',
				'MT' => 'America/Cuiaba',
				'MS' => 'America/Campo_Grande',
				'MG' => 'America/Sao_Paulo',
				'PA' => 'America/Belem',
				'PB' => 'America/Fortaleza',
				'PR' => 'America/Sao_Paulo',
				'PE' => 'America/Recife',
				'PI' => 'America/Fortaleza',
				'RJ' => 'America/Sao_Paulo',
				'RN' => 'America/Fortaleza',
				'RS' => 'America/Sao_Paulo',
				'RO' => 'America/Porto_Velho',
				'RR' => 'America/Boa_Vista',
				'SC' => 'America/Sao_Paulo',
				'SP' => 'America/Sao_Paulo',
				'SE' => 'America/Maceio',
				'TO' => 'America/Araguaina',
			],
			'MX' => [
				'AG' => 'America/Mexico_City',
				'BC' => 'America/Tijuana',
				'BS' => 'America/Mazatlan',
				'CH' => 'America/Chihuahua',
				'CL' => 'America/Mexico_City',
				'CM' => 'America/Merida',
				'CO' => 'America/Monterrey',
				'CS' => 'America/Mexico_City',
				'DF' => 'America/Mexico_City',
				'DG' => 'America/Monterrey',
				'GT' => 'America/Mexico_City',
				'GR' => 'America/Mexico_City',
				'HG' => 'America/Mexico_City',
				'JA' => 'America/Mexico_City',
				'MX' => 'America/Mexico_City',
				'MI' => 'America/Mexico_City',
				'MO' => 'America/Mexico_City',
				'NA' => 'America/Mazatlan',
				'NL' => 'America/Monterrey',
				'OA' => 'America/Mexico_City',
				'PU' => 'America/Mexico_City',
				'QT' => 'America/Mexico_City',
				'QR' => 'America/Cancun',
				'SL' => 'America/Mexico_City',
				'SI' => 'America/Mazatlan',
				'SO' => 'America/Hermosillo',
				'TB' => 'America/Mexico_City',
				'TM' => 'America/Matamoros',
				'TL' => 'America/Mexico_City',
				'VE' => 'America/Mexico_City',
				'YU' => 'America/Merida',
				'ZA' => 'America/Mexico_City',
			],
		];

		return isset( $maps[ $country ][ $state_key ] ) ? $maps[ $country ][ $state_key ] : '';
	}

	private function get_recipient_sources() {
		$sources = [
			self::SOURCE_PRODUCT_CUSTOMERS   => __( 'Product customers', 'yoohw-advanced-notifications' ),
			self::SOURCE_WOOCOMMERCE_CUSTOMERS => __( 'WooCommerce customers', 'yoohw-advanced-notifications' ),
			self::SOURCE_REGISTERED_USERS    => __( 'All registered users', 'yoohw-advanced-notifications' ),
			self::SOURCE_MANUAL_EMAILS        => __( 'Manual email list', 'yoohw-advanced-notifications' ),
		];

		return $sources;
	}

	private function get_user_roles_label( $roles ) {
		if ( empty( $roles ) ) {
			return __( 'All roles', 'yoohw-advanced-notifications' );
		}
		return implode( ', ', $this->get_labels_for_keys( (array) $roles, array_map( 'translate_user_role', wp_roles()->get_names() ) ) );
	}

	private function parse_manual_email_list( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'yoohw_an_invalid_manual_emails', __( 'Please enter a list of valid email addresses.', 'yoohw-advanced-notifications' ) );
		}
		$emails = [];
		foreach ( preg_split( '/[\s,;]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			if ( ! is_email( $part ) ) {
				return new WP_Error( 'yoohw_an_invalid_manual_emails', __( 'The recipient list contains an invalid email address. Correct the list before continuing.', 'yoohw-advanced-notifications' ) );
			}
			$email = strtolower( $part );
			$emails[ $email ] = $email;
		}
		return array_values( $emails );
	}

	private function validate_recipient_source_options( $payload ) {
		$source = isset( $payload['recipient_source'] ) ? $payload['recipient_source'] : '';
		if ( ! is_string( $source ) || ! isset( $this->get_recipient_sources()[ $source ] ) ) {
			return new WP_Error( 'yoohw_an_invalid_source', __( 'Please choose a valid recipient source.', 'yoohw-advanced-notifications' ) );
		}
		if ( self::SOURCE_REGISTERED_USERS === $source ) {
			$roles = isset( $payload['user_roles'] ) ? $payload['user_roles'] : [];
			if ( ! is_array( $roles ) ) {
				return new WP_Error( 'yoohw_an_invalid_roles', __( 'Please choose valid user roles.', 'yoohw-advanced-notifications' ) );
			}
			$known_roles = wp_roles()->get_names();
			foreach ( $roles as $role ) {
				if ( ! is_string( $role ) || ! isset( $known_roles[ $role ] ) ) {
					return new WP_Error( 'yoohw_an_invalid_roles', __( 'A selected user role is unavailable. Review the recipient selection before continuing.', 'yoohw-advanced-notifications' ) );
				}
			}
		}
		if ( self::SOURCE_MANUAL_EMAILS === $source ) {
			$emails = isset( $payload['manual_emails'] ) ? $payload['manual_emails'] : [];
			if ( ! is_array( $emails ) || ( empty( $emails ) && empty( $payload['test_mode'] ) ) ) {
				return new WP_Error( 'yoohw_an_missing_manual_emails', __( 'Please enter at least one recipient email address.', 'yoohw-advanced-notifications' ) );
			}
			foreach ( $emails as $email ) {
				if ( ! is_string( $email ) || ! is_email( $email ) ) {
					return new WP_Error( 'yoohw_an_invalid_manual_emails', __( 'Please enter a list of valid email addresses.', 'yoohw-advanced-notifications' ) );
				}
			}
		}
		return true;
	}

	private function get_recipients_by_payload( $payload, &$exclusion_summary = null ) {
		$recipients     = $this->get_base_recipients_by_payload( $payload );
		$base_count     = count( $recipients );
		$excluded_count = 0;
		$recipients     = $this->apply_recipient_exclusions( $recipients, $payload, $excluded_count );
		$labels         = $this->get_exclusion_labels( isset( $payload['exclusions'] ) && is_array( $payload['exclusions'] ) ? $payload['exclusions'] : [] );

		if ( $this->get_unsubscribed_email_count() > 0 ) {
			$labels[] = __( 'Previously unsubscribed email addresses', 'yoohw-advanced-notifications' );
		}

		$exclusion_summary = [
			'base_count'     => $base_count,
			'excluded_count' => $excluded_count,
			'labels'         => $labels,
		];

		return $recipients;
	}

	private function get_base_recipients_by_payload( $payload ) {
		if ( is_wp_error( $this->validate_recipient_source_options( $payload ) ) ) {
			return [];
		}
		$recipient_source = isset( $payload['recipient_source'] )
			? sanitize_key( $payload['recipient_source'] )
			: self::SOURCE_PRODUCT_CUSTOMERS;

		switch ( $recipient_source ) {
			case self::SOURCE_REGISTERED_USERS:
				return $this->get_registered_user_recipients( [], isset( $payload['user_roles'] ) ? $payload['user_roles'] : [] );

			case self::SOURCE_WOOCOMMERCE_CUSTOMERS:
				return $this->get_order_customer_recipients();

			case self::SOURCE_MANUAL_EMAILS:
				return array_map( static function ( $email ) {
					$email = strtolower( $email );
					return [ 'email' => $email, 'name' => $email, 'country' => '', 'state' => '', 'city' => '' ];
				}, array_values( array_unique( array_map( 'strtolower', isset( $payload['manual_emails'] ) ? $payload['manual_emails'] : [] ) ) ) );

			case self::SOURCE_PRODUCT_CUSTOMERS:
				$product_ids = isset( $payload['product_ids'] ) ? $payload['product_ids'] : [];
				return $this->get_customers_by_products( $product_ids );

			default:
				return [];
		}
	}

	private function apply_recipient_exclusions( $recipients, $payload, &$excluded_count = 0 ) {
		$excluded_count = 0;
		$exclusions     = isset( $payload['exclusions'] ) && is_array( $payload['exclusions'] ) ? $payload['exclusions'] : [];
		$lookup         = $this->get_recipient_exclusion_lookup( $exclusions );

		if ( empty( $lookup['emails'] ) && empty( $lookup['user_ids'] ) ) {
			return $recipients;
		}

		$filtered = [];

		foreach ( (array) $recipients as $recipient ) {
			$email   = isset( $recipient['email'] ) ? strtolower( sanitize_email( $recipient['email'] ) ) : '';
			$user_id = isset( $recipient['user_id'] ) ? absint( $recipient['user_id'] ) : 0;

			if (
				( ! empty( $email ) && isset( $lookup['emails'][ $email ] ) ) ||
				( $user_id && isset( $lookup['user_ids'][ $user_id ] ) )
			) {
				$excluded_count++;
				continue;
			}

			$filtered[] = $recipient;
		}

		return $filtered;
	}

	private function get_recipient_exclusion_lookup( $exclusions ) {
		$lookup = [
			'emails'   => [],
			'user_ids' => [],
		];

		$manual_emails = isset( $exclusions['emails'] ) ? (array) $exclusions['emails'] : [];

		foreach ( $manual_emails as $email ) {
			$email = strtolower( sanitize_email( $email ) );

			if ( is_email( $email ) ) {
				$lookup['emails'][ $email ] = true;
			}
		}

		$order_statuses = isset( $exclusions['order_statuses'] ) ? (array) $exclusions['order_statuses'] : [];

		if ( ! empty( $order_statuses ) ) {
			$this->merge_exclusion_lookup( $lookup, $this->get_customer_lookup_by_order_statuses( $order_statuses ) );
		}

		$product_ids = isset( $exclusions['product_ids'] ) ? array_filter( array_map( 'absint', (array) $exclusions['product_ids'] ) ) : [];

		if ( ! empty( $product_ids ) ) {
			$this->merge_exclusion_lookup( $lookup, $this->get_customer_lookup_from_recipients( $this->get_customers_by_products( $product_ids ) ) );
		}

		$this->merge_exclusion_lookup( $lookup, $this->get_unsubscribed_email_lookup() );

		return $lookup;
	}

	private function merge_exclusion_lookup( &$target, $source ) {
		foreach ( [ 'emails', 'user_ids' ] as $key ) {
			if ( empty( $source[ $key ] ) || ! is_array( $source[ $key ] ) ) {
				continue;
			}

			foreach ( $source[ $key ] as $value => $enabled ) {
				if ( ! $enabled ) {
					continue;
				}

				$target[ $key ][ $value ] = true;
			}
		}
	}

	private function get_customer_lookup_from_recipients( $recipients ) {
		$lookup = [
			'emails'   => [],
			'user_ids' => [],
		];

		foreach ( (array) $recipients as $recipient ) {
			$email = isset( $recipient['email'] ) ? strtolower( sanitize_email( $recipient['email'] ) ) : '';

			if ( is_email( $email ) ) {
				$lookup['emails'][ $email ] = true;
			}

			$user_id = isset( $recipient['user_id'] ) ? absint( $recipient['user_id'] ) : 0;

			if ( $user_id ) {
				$lookup['user_ids'][ $user_id ] = true;
			}
		}

		return $lookup;
	}

	private function get_unsubscribed_email_lookup() {
		$lookup = [
			'emails'   => [],
			'user_ids' => [],
		];

		foreach ( $this->get_unsubscribed_emails() as $email => $data ) {
			unset( $data );

			$email = strtolower( sanitize_email( $email ) );

			if ( is_email( $email ) ) {
				$lookup['emails'][ $email ] = true;
			}
		}

		return $lookup;
	}

	private function get_unsubscribed_email_count() {
		return count( $this->get_unsubscribed_emails() );
	}

	private function is_email_unsubscribed( $email ) {
		$email = strtolower( sanitize_email( $email ) );

		if ( ! is_email( $email ) ) {
			return false;
		}

		$record = $this->read_durable_option( 'yoohw_an_optout_' . hash( 'sha256', $email ) );
		if ( is_array( $record ) && $email === ( $record['email'] ?? '' ) ) { return true; }
		$legacy = $this->normalize_unsubscribed_emails( $this->read_durable_option( self::OPTION_UNSUBSCRIBED_EMAILS ) );
		return isset( $legacy[ $email ] );
	}

	private function get_unsubscribed_emails() {
		$stored = $this->read_durable_option( self::OPTION_UNSUBSCRIBED_EMAILS );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( $this->read_durable_options( 'yoohw_an_optout_' ) as $optout ) {
			if ( is_array( $optout ) && ! empty( $optout['email'] ) ) {
				$stored[ $optout['email'] ] = $optout;
			}
		}
		return $this->normalize_unsubscribed_emails( $stored );
	}

	private function normalize_unsubscribed_emails( $stored ) {
		$emails = [];
		foreach ( (array) $stored as $key => $value ) {
			$email = is_string( $key ) && is_email( $key )
				? strtolower( sanitize_email( $key ) )
				: '';

			if ( empty( $email ) && is_array( $value ) && ! empty( $value['email'] ) ) {
				$email = strtolower( sanitize_email( $value['email'] ) );
			}

			if ( empty( $email ) && is_string( $value ) ) {
				$email = strtolower( sanitize_email( $value ) );
			}

			if ( ! is_email( $email ) ) {
				continue;
			}

			$emails[ $email ] = is_array( $value )
				? $value
				: [
					'email'           => $email,
					'unsubscribed_at' => 0,
					'campaign_id'     => '',
				];
		}

		return $emails;
	}

	private function add_unsubscribed_email( $email, $campaign_id = '' ) {
		$email = strtolower( sanitize_email( $email ) );

		if ( ! is_email( $email ) ) {
			return false;
		}

		$key = 'yoohw_an_optout_' . hash( 'sha256', $email );
		$data = [ 'email' => $email, 'unsubscribed_at' => time(), 'campaign_id' => sanitize_text_field( $campaign_id ) ];
		$this->write_durable_option( $key, $data, true );
		$saved = $this->read_durable_option( $key );
		return is_array( $saved ) && $email === ( $saved['email'] ?? '' );
	}

	private function get_unsubscribe_token( $email ) {
		$email = strtolower( sanitize_email( $email ) );

		return hash_hmac( 'sha256', $email, wp_salt( 'auth' ) . self::ACTION_UNSUBSCRIBE );
	}

	private function is_valid_unsubscribe_token( $email, $token ) {
		$email = strtolower( sanitize_email( $email ) );
		$token = sanitize_text_field( $token );

		if ( ! is_email( $email ) || empty( $token ) ) {
			return false;
		}

		return hash_equals( $this->get_unsubscribe_token( $email ), $token );
	}

	private function get_unsubscribe_url( $email, $campaign_id = '' ) {
		$email = strtolower( sanitize_email( $email ) );

		if ( ! is_email( $email ) ) {
			return '';
		}

		return add_query_arg(
			[
				'action'      => self::ACTION_UNSUBSCRIBE,
				'email'       => $email,
				'token'       => $this->get_unsubscribe_token( $email ),
				'campaign_id' => sanitize_text_field( $campaign_id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	private function get_unsubscribe_footer_html( $email = '', $campaign_id = '' ) {
		$unsubscribe_url = $this->get_unsubscribe_url( $email, $campaign_id );
		$link_url        = ! empty( $unsubscribe_url ) ? $unsubscribe_url : '#';

		return sprintf(
			'<p style="border-top:1px solid #e5e5e5;color:#777;font-size:12px;line-height:1.5;margin-top:24px;padding-top:14px;">%1$s <a href="%2$s" style="color:#777;text-decoration:underline;">%3$s</a>.</p>',
			esc_html__( 'Do not want to receive these campaign emails?', 'yoohw-advanced-notifications' ),
			esc_url( $link_url ),
			esc_html__( 'Unsubscribe', 'yoohw-advanced-notifications' )
		);
	}

	private function append_unsubscribe_footer( $message, $email, $campaign_id = '' ) {
		return $message . "\n\n" . $this->get_unsubscribe_footer_html( $email, $campaign_id );
	}

	private function get_order_status_options() {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return wc_get_order_statuses();
		}

		return [
			'wc-pending'    => __( 'Pending payment', 'yoohw-advanced-notifications' ),
			'wc-processing' => __( 'Processing', 'yoohw-advanced-notifications' ),
			'wc-on-hold'    => __( 'On hold', 'yoohw-advanced-notifications' ),
			'wc-completed'  => __( 'Completed', 'yoohw-advanced-notifications' ),
			'wc-cancelled'  => __( 'Cancelled', 'yoohw-advanced-notifications' ),
			'wc-refunded'   => __( 'Refunded', 'yoohw-advanced-notifications' ),
			'wc-failed'     => __( 'Failed', 'yoohw-advanced-notifications' ),
		];
	}

	private function sanitize_order_statuses( $statuses ) {
		$valid_statuses = $this->get_order_status_options();
		$clean_statuses = [];

		foreach ( (array) $statuses as $status ) {
			$status = sanitize_key( $status );

			if ( isset( $valid_statuses[ $status ] ) ) {
				$clean_statuses[ $status ] = $status;
			}
		}

		return array_values( $clean_statuses );
	}

	private function normalize_order_status_for_query( $status ) {
		return preg_replace( '/^wc-/', '', sanitize_key( $status ) );
	}

	private function parse_email_list( $value ) {
		$value  = sanitize_textarea_field( (string) $value );
		$parts  = preg_split( '/[\s,;]+/', $value );
		$emails = [];

		foreach ( (array) $parts as $part ) {
			$email = strtolower( sanitize_email( $part ) );

			if ( is_email( $email ) ) {
				$emails[ $email ] = $email;
			}
		}

		return array_values( $emails );
	}

	private function normalize_exclusions_for_hash( $exclusions ) {
		$normalized = [
			'order_statuses'        => isset( $exclusions['order_statuses'] ) ? $this->sanitize_order_statuses( $exclusions['order_statuses'] ) : [],
			'product_ids'           => isset( $exclusions['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $exclusions['product_ids'] ) ) ) ) : [],
			'emails'                => isset( $exclusions['emails'] ) ? $this->parse_email_list( implode( "\n", (array) $exclusions['emails'] ) ) : [],
		];

		sort( $normalized['order_statuses'] );
		sort( $normalized['product_ids'], SORT_NUMERIC );
		sort( $normalized['emails'] );

		return $normalized;
	}

	private function get_exclusion_labels( $exclusions ) {
		$exclusions = is_array( $exclusions ) ? $exclusions : [];
		$labels     = [];

		$order_statuses = isset( $exclusions['order_statuses'] ) ? $this->sanitize_order_statuses( $exclusions['order_statuses'] ) : [];

		if ( ! empty( $order_statuses ) ) {
			$labels[] = sprintf(
				/* translators: %s: Comma-separated order status labels. */ __( 'Order statuses: %s', 'yoohw-advanced-notifications' ),
				implode( ', ', $this->get_labels_for_keys( $order_statuses, $this->get_order_status_options() ) )
			);
		}

		$product_ids = isset( $exclusions['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $exclusions['product_ids'] ) ) ) ) : [];

		if ( ! empty( $product_ids ) ) {
			$labels[] = sprintf(
				/* translators: %s: Comma-separated product names. */ __( 'Purchased products: %s', 'yoohw-advanced-notifications' ),
				$this->get_selected_products_label( $product_ids )
			);
		}

		$emails = isset( $exclusions['emails'] ) ? $this->parse_email_list( implode( "\n", (array) $exclusions['emails'] ) ) : [];

		if ( ! empty( $emails ) ) {
			$labels[] = sprintf(
				/* translators: %d: Number of excluded email addresses. */ _n( 'Manual emails: %d address', 'Manual emails: %d addresses', count( $emails ), 'yoohw-advanced-notifications' ),
				count( $emails )
			);
		}

		return $labels;
	}

	private function get_labels_for_keys( $keys, $options ) {
		$labels = [];

		foreach ( (array) $keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$labels[] = $options[ $key ];
			}
		}

		return array_values( array_unique( array_filter( $labels ) ) );
	}

	private function get_customer_lookup_by_order_statuses( $statuses ) {
		$statuses       = $this->sanitize_order_statuses( $statuses );
		$query_statuses = array_values( array_filter( array_map( [ $this, 'normalize_order_status_for_query' ], $statuses ) ) );

		if ( empty( $query_statuses ) || ! function_exists( 'wc_get_orders' ) ) {
			return [
				'emails'   => [],
				'user_ids' => [],
			];
		}

		$lookup = [
			'emails'   => [],
			'user_ids' => [],
		];
		$page   = 1;

		do {
			$orders = wc_get_orders(
				[
					'limit'   => self::ORDER_QUERY_LIMIT,
					'paged'   => $page,
					'status'  => $query_statuses,
					'return'  => 'objects',
					'orderby' => 'ID',
					'order'   => 'DESC',
				]
			);

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$email = strtolower( sanitize_email( $order->get_billing_email() ) );

				if ( is_email( $email ) ) {
					$lookup['emails'][ $email ] = true;
				}

				$user_id = absint( $order->get_user_id() );

				if ( $user_id ) {
					$lookup['user_ids'][ $user_id ] = true;
				}
			}

			$page++;
		} while ( count( $orders ) === self::ORDER_QUERY_LIMIT );

		return $lookup;
	}

	private function get_registered_user_recipients( $exclude_user_ids = [], $roles = [] ) {
		if ( is_wp_error( $this->validate_recipient_source_options( [ 'recipient_source' => self::SOURCE_REGISTERED_USERS, 'user_roles' => $roles ] ) ) ) {
			return [];
		}
		$exclude_lookup = [];

		foreach ( (array) $exclude_user_ids as $user_id ) {
			$user_id = absint( $user_id );

			if ( $user_id ) {
				$exclude_lookup[ $user_id ] = true;
			}
		}

		$recipients = [];
		$page       = 1;

		do {
			$users = get_users(
				[
					'number'  => self::USER_QUERY_LIMIT,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'fields'  => [ 'ID', 'user_email', 'display_name' ],
					'role__in' => $roles,
				]
			);

			if ( empty( $users ) ) {
				break;
			}

			foreach ( $users as $user ) {
				$user_id = isset( $user->ID ) ? absint( $user->ID ) : 0;

				if ( $user_id && isset( $exclude_lookup[ $user_id ] ) ) {
					continue;
				}

				$email = isset( $user->user_email ) ? strtolower( sanitize_email( $user->user_email ) ) : '';

				if ( empty( $email ) || ! is_email( $email ) || isset( $recipients[ $email ] ) ) {
					continue;
				}

				$name = isset( $user->display_name ) ? sanitize_text_field( $user->display_name ) : '';

				$recipients[ $email ] = [
					'user_id' => $user_id,
					'email'   => $email,
					'name'    => ! empty( $name ) ? $name : $email,
					'country' => $this->normalize_country_code( get_user_meta( $user_id, 'billing_country', true ) ),
					'state'   => $this->normalize_location_part( get_user_meta( $user_id, 'billing_state', true ) ),
					'city'    => $this->normalize_location_part( get_user_meta( $user_id, 'billing_city', true ) ),
				];
			}

			$page++;
		} while ( count( $users ) === self::USER_QUERY_LIMIT );

		return array_values( $recipients );
	}

	public function process_single_email( $raw_args ) {
		$raw_args = is_array( $raw_args ) ? $raw_args : [];
		$campaign_id = sanitize_text_field( $raw_args['campaign_id'] ?? '' );
		$delivery_id = ! empty( $raw_args['delivery_id'] ) ? sanitize_text_field( $raw_args['delivery_id'] ) : $campaign_id;
		$to = strtolower( sanitize_email( $raw_args['to'] ?? '' ) );
		$action_id = $this->get_current_action_id();
		if ( ! $this->ensure_delivery_row( $campaign_id, $delivery_id, $to ) ) {
			throw new RuntimeException( 'Recipient storage unavailable; no mail was handed off.' );
		}
		$claim = $this->claim_recipient_delivery( $campaign_id, $delivery_id, $to, $action_id );
		if ( false === $claim ) {
			return; // A duplicate job is not another recipient outcome.
		}
		$args = $this->hydrate_email_action_args( $raw_args );
		if ( is_wp_error( $args ) ) {
			$this->finish_delivery( $campaign_id, $delivery_id, $to, $claim, 'failed', $args->get_error_message(), 'configuration' );
			return;
		}
		$history = $this->get_campaign_history();
		if ( empty( $history[ $campaign_id ] ) ) {
			// Inline jobs from before shared payloads were introduced remain observable.
			$this->record_campaign_snapshot( array_merge( $args, [ 'delivery_id' => $delivery_id ] ), 'processing' );
		}
		if ( empty( $args['subject'] ) || empty( $args['heading'] ) || empty( $args['message'] ) ) {
			$this->finish_delivery( $campaign_id, $delivery_id, $to, $claim, 'failed', __( 'Missing required email content. Repair campaign data before retrying.', 'yoohw-advanced-notifications' ), 'configuration' );
			return;
		}
		try {
			$message = str_replace( [ '{customer_name}', '{product_name}' ], [ $args['name'] ?? $to, $args['products_label'] ?? '' ], $args['message'] );
			$message = $this->append_unsubscribe_footer( $message, $to, $campaign_id );
			// Read the durable opt-out immediately before entering the sending path.
			if ( $this->is_email_unsubscribed( $to ) ) {
				$this->finish_delivery( $campaign_id, $delivery_id, $to, $claim, 'skipped_unsubscribed', __( 'Recipient unsubscribed before send.', 'yoohw-advanced-notifications' ) );
				return;
			}
			$this->mail_handoff_started = false;
			$sent = $this->send_woocommerce_email( $to, $args['subject'], $args['heading'], $message, $args['from_email'] ?? '', $args['from_name'] ?? '', $args['reply_to_email'] ?? '' );
			$status = $sent ? 'sent' : ( $this->mail_handoff_started ? 'unknown' : ( $this->is_email_unsubscribed( $to ) ? 'skipped_unsubscribed' : 'failed' ) );
			$this->finish_delivery( $campaign_id, $delivery_id, $to, $claim, $status,
				$sent ? __( 'Mail system accepted the request; inbox delivery is not verified.', 'yoohw-advanced-notifications' ) : ( $this->last_mail_error ?: __( 'Mail transport returned no confirmation. Check the mail provider before sending again.', 'yoohw-advanced-notifications' ) ),
				$sent ? '' : ( $this->mail_handoff_started ? 'transport' : 'configuration' )
			);
		} catch ( Throwable $error ) {
			$this->finish_delivery( $campaign_id, $delivery_id, $to, $claim, 'unknown', $error->getMessage(), 'transport' );
		}
	}

	/** Compare-and-set in the recipient table; never use add_option() as a lock. */
	private function claim_recipient_delivery( $campaign_id, $delivery_id, $email, $action_id = 0 ) {
		global $wpdb;
		$key = $this->get_recipient_delivery_option_name( $campaign_id, $delivery_id, $email );
		$legacy = $this->read_durable_option( $key );
		$table = $this->get_recipient_tracking_table_name();
		$hash = hash( 'sha256', strtolower( $email ) );
		if ( is_array( $legacy ) || ( $delivery_id === $campaign_id && $this->was_recipient_already_sent( $campaign_id, $email ) ) ) {
			$status = ( ! is_array( $legacy ) || 'sent' === ( $legacy['state'] ?? '' ) ) ? 'sent' : 'unknown';
			$wpdb->query( $wpdb->prepare(
				"UPDATE %i SET status = %s, last_error = %s WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND status IN ('dispatch_pending','queued','scheduled','skipped_duplicate')",
				$table, $status, __( 'Preserved a pre-upgrade delivery claim. Check the mail provider for unknown outcomes.', 'yoohw-advanced-notifications' ), $campaign_id, $delivery_id, $hash
			) );
			return false;
		}
		$token = wp_generate_uuid4();
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET status = 'processing', claim_token = %s, action_id = %d, attempts = GREATEST(attempts,1), updated_at = %s WHERE campaign_id = %s AND delivery_id = %s AND email_hash = %s AND status IN ('dispatch_pending','queued','scheduled') AND claim_token = ''",
			$table, $token, $action_id, current_time( 'mysql', true ), $campaign_id, $delivery_id, $hash
		) );
		return 1 === $result ? $token : false;
	}

	private function was_recipient_already_sent( $campaign_id, $email ) {
		$campaign_id = sanitize_text_field( $campaign_id );
		$email       = strtolower( sanitize_email( $email ) );

		if ( empty( $campaign_id ) || empty( $email ) ) {
			return false;
		}

		foreach ( $this->get_campaign_log_entries( $campaign_id, 0 ) as $event ) {
			$event_email  = isset( $event['email'] ) ? strtolower( sanitize_email( $event['email'] ) ) : '';
			$event_status = isset( $event['status'] ) ? sanitize_key( $event['status'] ) : '';

			if ( $email === $event_email && 'sent' === $event_status ) {
				return true;
			}
		}

		return false;
	}


	private function get_recipient_delivery_option_name( $campaign_id, $delivery_id, $email ) {
		$campaign_id = sanitize_text_field( $campaign_id );
		$delivery_id = sanitize_text_field( $delivery_id );
		$email       = strtolower( sanitize_email( $email ) );

		if ( empty( $campaign_id ) || empty( $delivery_id ) || empty( $email ) || ! is_email( $email ) ) {
			return '';
		}

		return self::DELIVERY_CLAIM_PREFIX . hash( 'sha256', $campaign_id . '|' . $delivery_id . '|' . $email );
	}

	private function get_customers_by_products( $product_ids ) {
		return $this->get_order_customer_recipients( (array) $product_ids );
	}

	private function get_order_customer_recipients( $product_ids = null ) {
		$all_products = null === $product_ids;
		$product_ids = $all_products ? [] : $product_ids;
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );

		if ( ! $all_products && empty( $product_ids ) ) {
			return [];
		}

		$matching_product_ids   = [];
		$matching_variation_ids = [];

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$product_type = $product->get_type();

			if ( 'variable' === $product_type ) {
				$matching_product_ids[] = $product_id;

				$children = $product->get_children();

				if ( ! empty( $children ) ) {
					$matching_variation_ids = array_merge(
						$matching_variation_ids,
						array_map( 'absint', $children )
					);
				}

				continue;
			}

			if ( 'variation' === $product_type ) {
				$matching_variation_ids[] = $product_id;
				continue;
			}

			$matching_product_ids[] = $product_id;
		}

		$matching_product_ids   = array_values( array_unique( array_filter( array_map( 'absint', $matching_product_ids ) ) ) );
		$matching_variation_ids = array_values( array_unique( array_filter( array_map( 'absint', $matching_variation_ids ) ) ) );

		if ( ! $all_products && empty( $matching_product_ids ) && empty( $matching_variation_ids ) ) {
			return [];
		}

		$customers = [];
		$page      = 1;

		do {
			$orders = wc_get_orders(
				[
					'limit'   => self::ORDER_QUERY_LIMIT,
					'paged'   => $page,
					'status'  => [ 'processing', 'completed' ],
					'return'  => 'objects',
					'orderby' => 'ID',
					'order'   => 'DESC',
				]
			);

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$has_product = $all_products;

				foreach ( ( $all_products ? [] : $order->get_items( 'line_item' ) ) as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}

					$item_product_id   = absint( $item->get_product_id() );
					$item_variation_id = absint( $item->get_variation_id() );

					if (
						$item_product_id &&
						in_array( $item_product_id, $matching_product_ids, true )
					) {
						$has_product = true;
						break;
					}

					if (
						$item_variation_id &&
						in_array( $item_variation_id, $matching_variation_ids, true )
					) {
						$has_product = true;
						break;
					}
				}

				if ( ! $has_product ) {
					continue;
				}

				$email = strtolower( sanitize_email( $order->get_billing_email() ) );

				if ( ! is_email( $email ) || isset( $customers[ $email ] ) ) {
					continue;
				}

				$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

				$customers[ $email ] = [
					'user_id' => absint( $order->get_user_id() ),
					'email'   => $email,
					'name'    => $name ?: $email,
					'country' => $this->normalize_country_code( $order->get_billing_country() ),
					'state'   => $this->normalize_location_part( $order->get_billing_state() ),
					'city'    => $this->normalize_location_part( $order->get_billing_city() ),
				];
			}

			$page++;
		} while ( count( $orders ) === self::ORDER_QUERY_LIMIT );

		return array_values( $customers );
	}

	private function get_selected_products_label( $product_ids ) {
		$names = [];

		foreach ( (array) $product_ids as $product_id ) {
			$product = wc_get_product( absint( $product_id ) );

			if ( $product ) {
				$names[] = $product->get_name();
			}
		}

		$names = array_unique( array_filter( $names ) );

		return ! empty( $names )
			? implode( ', ', $names )
			: __( 'selected product(s)', 'yoohw-advanced-notifications' );
	}

	private function get_selected_product_options_html( $product_ids ) {
		$options = '';

		foreach ( (array) $product_ids as $product_id ) {
			$product_id = absint( $product_id );

			if ( ! $product_id ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$label   = $product ? $product->get_name() : sprintf(
				/* translators: %d: Product ID. */
				__( 'Product #%d', 'yoohw-advanced-notifications' ),
				$product_id
			);

			$options .= sprintf(
				'<option value="%1$d" selected="selected">%2$s</option>',
				$product_id,
				esc_html( $label )
			);
		}

		return $options;
	}

	private function get_campaign_preview_email_html() {
		$heading_token   = 'YOOHW_AN_PREVIEW_HEADING';
		$preview_message = '<div id="yoohw-advanced-notifications-preview-message"><p>' . esc_html__( 'Message body', 'yoohw-advanced-notifications' ) . '</p></div>' . $this->get_unsubscribe_footer_html();
		$email_body      = $this->build_woocommerce_email_body(
			$heading_token,
			$preview_message,
			false
		);

		if ( false === $email_body ) {
			return '<!doctype html><html><body><p>' . esc_html__( 'WooCommerce email template is unavailable.', 'yoohw-advanced-notifications' ) . '</p></body></html>';
		}

		$email_body = preg_replace(
			'/' . preg_quote( $heading_token, '/' ) . '/',
			'<span id="yoohw-advanced-notifications-preview-heading">' . esc_html__( 'Email heading', 'yoohw-advanced-notifications' ) . '</span>',
			$email_body,
			1
		);

		return $email_body;
	}

	private function build_woocommerce_email_body( $heading, $message, $sanitize_message = true ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		$email_context = $this->get_woocommerce_email_context();

		$message_content = $sanitize_message ? wpautop( wp_kses_post( $message ) ) : $message;

		ob_start();

		wc_get_template(
			'emails/email-header.php',
			[
				'email_heading' => $heading,
				'email'         => $email_context,
			]
		);

		echo $message_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		wc_get_template(
			'emails/email-footer.php',
			[
				'email' => $email_context,
			]
		);

		$email_body = ob_get_clean();

		if ( $email_context && method_exists( $email_context, 'style_inline' ) ) {
			$previous_email_type     = isset( $email_context->email_type ) ? $email_context->email_type : null;
			$email_context->email_type = 'html';
			$email_body             = apply_filters( 'woocommerce_mail_content', $email_context->style_inline( $email_body ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke the existing WooCommerce email content hook for template compatibility.
			$email_context->email_type = $previous_email_type;
		} else {
			$styles = wc_get_template_html( 'emails/email-styles.php' );

			if ( ! empty( $styles ) ) {
				$email_body = '<style type="text/css">' . $styles . '</style>' . $email_body;
			}
		}

		return $email_body;
	}

	private function get_woocommerce_email_context() {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$mailer = WC()->mailer();
		$emails = method_exists( $mailer, 'get_emails' ) ? $mailer->get_emails() : [];

		foreach ( [ 'WC_Email_Customer_Completed_Order', 'WC_Email_Customer_Processing_Order', 'WC_Email_Customer_Note' ] as $email_key ) {
			if ( ! empty( $emails[ $email_key ] ) && is_a( $emails[ $email_key ], 'WC_Email' ) ) {
				return $emails[ $email_key ];
			}
		}

		foreach ( (array) $emails as $email ) {
			if ( is_a( $email, 'WC_Email' ) ) {
				return $email;
			}
		}

		return class_exists( 'WC_Email' ) ? new WC_Email() : null;
	}

	private function send_woocommerce_email( $to, $subject, $heading, $message, $from_email = '', $from_name = '', $reply_to_email = '' ) {
		$email_body = $this->build_woocommerce_email_body( $heading, $message );

		if ( false === $email_body ) {
			$this->last_mail_error = __( 'WooCommerce mailer is unavailable.', 'yoohw-advanced-notifications' );
			return false;
		}

		if ( $this->is_email_unsubscribed( $to ) ) {
			$this->last_mail_error = __( 'Recipient unsubscribed before mail handoff.', 'yoohw-advanced-notifications' );
			return false;
		}

		$this->current_from_email = is_email( $from_email ) ? sanitize_email( $from_email ) : $this->get_sender_email();
		$this->current_from_name  = ! empty( $from_name ) ? sanitize_text_field( $from_name ) : $this->get_sender_name();
		$headers                  = $this->build_email_headers( $reply_to_email );
		$this->last_mail_error    = '';

		add_filter( 'wp_mail_from', [ $this, 'override_from_email' ] );
		add_filter( 'wp_mail_from_name', [ $this, 'override_from_name' ] );
		add_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ], 10, 1 );

		$this->mail_handoff_started = true;
		try {
			$result = wp_mail( $to, $subject, $email_body, $headers );
		} finally {
			remove_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ], 10 );
			remove_filter( 'wp_mail_from', [ $this, 'override_from_email' ] );
			remove_filter( 'wp_mail_from_name', [ $this, 'override_from_name' ] );

			$this->current_from_email = '';
			$this->current_from_name  = '';
		}

		return $result;
	}

	private function build_email_headers( $reply_to_email = '' ) {
		$headers        = [ 'Content-Type: text/html; charset=UTF-8' ];
		$reply_to_email = $this->get_effective_reply_to_email( $reply_to_email );

		if ( is_email( $reply_to_email ) ) {
			$headers[] = 'Reply-To: ' . $reply_to_email;
		}

		if ( $this->should_suppress_automated_replies() ) {
			$headers[] = 'Auto-Submitted: auto-generated';
			$headers[] = 'Precedence: bulk';
			// Preserve DSN/NDR processing; suppress only OOF and other automatic replies.
			$headers[] = 'X-Auto-Response-Suppress: OOF, AutoReply';
		}

		return $headers;
	}

	public function override_from_email() {
		return ! empty( $this->current_from_email ) ? $this->current_from_email : $this->get_sender_email();
	}

	public function override_from_name() {
		return ! empty( $this->current_from_name ) ? $this->current_from_name : $this->get_sender_name();
	}

	public function capture_mail_error( $error ) {
		if ( is_wp_error( $error ) ) {
			$this->last_mail_error = $error->get_error_message();
		}
	}

	private function get_current_action_id() {
		if ( $this->current_action_id ) {
			return $this->current_action_id;
		}
		if ( function_exists( 'as_get_current_action_id' ) ) {
			return absint( as_get_current_action_id() );
		}

		return 0;
	}

	private function log_campaign_event( $campaign_id, $email, $status, $message = '', $action_id = 0 ) {
		$campaign_id = ! empty( $campaign_id ) ? sanitize_text_field( $campaign_id ) : 'unknown';
		$email       = ! empty( $email ) ? sanitize_email( $email ) : 'unknown';
		$status      = sanitize_key( $status );
		$message     = sanitize_text_field( $message );
		$action_id   = absint( $action_id );

		$log_message = sprintf(
			'campaign=%1$s email=%2$s status=%3$s action_id=%4$d',
			$campaign_id,
			$email,
			$status,
			$action_id
		);

		if ( ! empty( $message ) ) {
			$log_message .= ' message=' . $message;
		}

		$stored_in_tracking = $this->store_campaign_log_event( $campaign_id, $email, $status, $message, $action_id );

		if ( $stored_in_tracking ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$level  = in_array( $status, [ 'failed', 'test_failed', 'schedule_failed' ], true ) ? 'error' : 'info';

			$logger->log(
				$level,
				$log_message,
				[ 'source' => self::LOG_SOURCE ]
			);
			return;
		}

		error_log( '[yoohw-advanced-notifications] ' . $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation, filters and notices; no state is changed.

		if ( 'woocommerce_page_yoohw-advanced-notifications' !== $hook && 'yoohw-advanced-notifications' !== $page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		wp_enqueue_style( 'yoohw-advanced-notifications-admin', plugins_url( 'assets/admin.css', dirname( __FILE__ ) ), [ 'woocommerce_admin_styles' ], YOOHW_AN_VERSION );

		wp_enqueue_script( 'yoohw-advanced-notifications-admin', plugins_url( 'assets/admin.js', dirname( __FILE__ ) ), [ 'jquery', 'wc-enhanced-select' ], YOOHW_AN_VERSION, true );
		wp_add_inline_script(
			'yoohw-advanced-notifications-admin',
			'var yoohwAN = ' . wp_json_encode( [
				'productSource'    => self::SOURCE_PRODUCT_CUSTOMERS,
				'registeredSource' => self::SOURCE_REGISTERED_USERS,
				'manualSource'     => self::SOURCE_MANUAL_EMAILS,
				'customersSource'  => self::SOURCE_WOOCOMMERCE_CUSTOMERS,
				'previewDefaults'  => [
					'subject' => __( 'Email subject', 'yoohw-advanced-notifications' ),
					'heading' => __( 'Email heading', 'yoohw-advanced-notifications' ),
					'message' => __( 'Message body', 'yoohw-advanced-notifications' ),
				],
			] ) . ';',
			'before'
		);
	}
}
