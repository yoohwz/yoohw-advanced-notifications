<?php
/** Internal worker for reliability.py. Run only against a disposable database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! getenv( 'AN_RELIABILITY_FIXTURES' ) ) {
	exit( 'Use tests/reliability.py with a disposable WordPress database.' );
}
(function () {
	global $wpdb;
	if ( ! str_contains( DB_NAME, 'reliability' ) ) { throw new RuntimeException( 'Use a disposable database whose name contains reliability.' ); }
	$input = json_decode( getenv( 'AN_INPUT' ), true );
	$callbacks = $GLOBALS['wp_filter'][ YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL ]->callbacks[10];
	$notify = reset( $callbacks )['function'][0];
	$reflection = new ReflectionClass( $notify );
	$call = static function ( $method, ...$args ) use ( $reflection, $notify ) { return $reflection->getMethod( $method )->invokeArgs( $notify, $args ); };
	$table = $wpdb->prefix . 'yoohw_an_recipients';
	$id = $input['id'] ?? 'fixture';
	$directory = getenv( 'AN_RELIABILITY_FIXTURES' );
	$signal = static function ( $name ) use ( $directory ) { touch( $directory . '/' . $name ); };
	$wait = static function ( $name ) use ( $directory ) {
		$deadline = microtime( true ) + 25;
		while ( ! file_exists( $directory . '/' . $name ) ) {
			if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Fixture barrier timed out: ' . $name ); }
			usleep( 10000 );
		}
	};
	$fault = $input['fault'] ?? '';
	add_filter( 'pre_wp_mail', static function ( $pre, $attributes ) use ( $directory, $fault, $signal, $wait ) {
		if ( 'crash_before' === $fault ) { $signal( 'transport-before' ); $wait( 'never' ); }
		file_put_contents( $directory . '/transport.jsonl', wp_json_encode( [ 'pid' => getmypid(), 'to' => $attributes['to'], 'subject' => $attributes['subject'] ] ) . "\n", FILE_APPEND | LOCK_EX );
		if ( 'crash_after' === $fault ) { $signal( 'transport-after' ); $wait( 'never' ); }
		if ( 'hold_transport' === $fault ) { $signal( 'transport-held' ); $wait( 'release-transport' ); }
		if ( 'transport_false' === $fault ) { return false; }
		if ( 'transport_exception' === $fault ) { throw new RuntimeException( 'Synthetic transport disconnect after submission.' ); }
		usleep( 20000 );
		return true;
	}, PHP_INT_MAX, 2 );
	if ( ! empty( $input['barrier'] ) ) {
		$barrier = $input['barrier'];
		$signal( $barrier . '-ready' );
		$wait( 'go' );
	}
	if ( 'schedule_fail' === $fault ) {
		add_filter( 'pre_as_schedule_single_action', static function () { return 0; } );
	}
	if ( in_array( $fault, [ 'queue_fail', 'next_batch_fail' ], true ) ) {
		add_filter( 'pre_as_enqueue_async_action', static function ( $pre, $hook ) use ( $fault ) {
			$target = 'queue_fail' === $fault ? YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL : YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN;
			return $hook === $target ? 0 : $pre;
		}, 10, 2 );
	}
	if ( 'dispatch_before_job' === $fault ) {
		add_filter( 'pre_as_enqueue_async_action', static function ( $pre, $hook ) use ( $signal, $wait ) {
			if ( YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL === $hook ) {
				$signal( 'before-job' ); $wait( 'never' );
			}
			return $pre;
		}, 10, 2 );
	}
	if ( in_array( $fault, [ 'late_dispatch', 'dispatch_crash' ], true ) ) {
		add_action( 'action_scheduler_stored_action', static function ( $action_id ) use ( $signal, $wait, $fault ) {
			if ( YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL === ActionScheduler::store()->fetch_action( $action_id )->get_hook() ) {
				$signal( 'dispatch_crash' === $fault ? 'dispatch-partial' : 'job-stored' );
				$wait( 'dispatch_crash' === $fault ? 'never' : 'handoff-complete' );
			}
		} );
	}
	if ( 'preparation_crash' === $fault ) {
		$inserts = 0;
		add_filter( 'query', static function ( $query ) use ( &$inserts, $signal, $wait ) {
			if ( str_contains( $query, '(campaign_id,delivery_id,email_hash,email,recipient_name' ) && str_contains( $query, 'INSERT INTO' ) && ++$inserts === 2 ) {
				$signal( 'preparation-partial' ); $wait( 'never' );
			}
			return $query;
		} );
	}
	$result = null;
	switch ( $input['mode'] ) {
		case 'control':
			$result = $call( 'control_campaign', $id, $input['operation'] );
			if ( is_wp_error( $result ) ) { $result = [ 'error' => $result->get_error_code() ]; }
			break;
		case 'limits':
			$call( 'write_durable_option', 'yoohw_an_limits', $input['limits'] ?? [] );
			if ( ! empty( $input['reset_rate'] ) ) { $call( 'write_durable_option', 'yoohw_an_rate', [] ); }
			break;
		case 'clear_wait':
			$control = $call( 'campaign_control', $id ); unset( $control['rate_resume_at'] );
			$call( 'write_durable_option', $call( 'control_key', $id ), $control );
			break;
		case 'erase': $result = $notify->erase_personal_data( $input['email'] ); break;
		case 'direct':
			$campaign = $call( 'get_campaign_history' )[ $id ];
			$payload = $call( 'get_campaign_reschedule_payload', $campaign );
			$reference = $call( 'store_campaign_email_payload', $payload );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email,recipient_name AS name FROM %i WHERE campaign_id = %s AND email <> %s ORDER BY id', $table, $id, '' ), ARRAY_A );
			foreach ( $rows as $recipient ) { $notify->process_single_email( $call( 'build_email_action_args', $payload, $recipient, $reference ) ); }
			break;
		case 'reset':
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM %i WHERE option_name LIKE %s", $wpdb->options, $wpdb->esc_like( 'yoohw_an_' ) . '%' ) );
			foreach ( $names as $name ) { delete_option( $name ); }
			$wpdb->query( "DELETE FROM {$table}" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE hook IN (%s,%s)", $wpdb->prefix . 'actionscheduler_actions', YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN ) );
			break;
		case 'create':
		case 'manifest':
		case 'confirm':
			$recipients = [];
			for ( $i = 0; $i < ( $input['count'] ?? 1 ); $i++ ) {
				$recipients[] = [ 'email' => $input['email'] ?? $id . '-' . $i . '@example.com', 'name' => 'Fixture ' . $i, 'country' => 'VN' ];
			}
			$payload = [ 'campaign_id' => $id, 'delivery_id' => $id . '-delivery', 'campaign_hash' => hash( 'sha256', $id ), 'recipient_source' => 'manual_emails', 'recipient_source_label' => 'Manual email list', 'user_roles' => [], 'manual_emails' => array_column( $recipients, 'email' ), 'product_ids' => [], 'products_label' => 'Fixtures', 'subject' => 'Reliability ' . $id, 'heading' => 'Fixture', 'message' => '<p>Hello {customer_name}</p>', 'from_email' => 'store@example.com', 'from_name' => 'Fixture Store', 'reply_to_email' => 'reply@example.com', 'schedule_mode' => 'now' ];
			if ( ! empty( $input['future'] ) ) {
				$payload['schedule_mode'] = 'scheduled';
				$payload['scheduled_at_input'] = gmdate( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );
				$payload['scheduled_at_timestamp'] = time() + DAY_IN_SECONDS;
			}
			if ( 'manifest' === $input['mode'] ) {
				// Simulate elapsed wall time after an interrupted confirmation; do not wait an hour.
				$confirmed_at = time() - 2 * HOUR_IN_SECONDS;
				$payload['schedule_mode'] = 'scheduled';
				$payload['scheduled_at_input'] = gmdate( 'Y-m-d\TH:i', time() - HOUR_IN_SECONDS );
				$payload['scheduled_at_timestamp'] = strtotime( $payload['scheduled_at_input'] . ' UTC' );
				foreach ( $recipients as &$recipient ) { $recipient['country'] = ''; }
				unset( $recipient );
				$call( 'write_durable_option', $call( 'campaign_manifest_key', $id ), [ 'payload' => $payload, 'recipients' => $recipients, 'confirmed_at' => $confirmed_at ], true );
				$call( 'record_campaign_snapshot', $payload, ! empty( $input['failed'] ) ? 'needs_attention' : 'preparing', [ 'recipient_count' => count( $recipients ) ] );
				$call( 'prepare_campaign_recipients_for_dispatch', $payload, array_slice( $recipients, 0, 1 ) );
				$due = gmdate( 'Y-m-d H:i:s', $payload['scheduled_at_timestamp'] );
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET scheduled_at_gmt = %s WHERE campaign_id = %s", $table, $due, $id ) );
				$result = $due;
			} elseif ( 'create' === $input['mode'] ) {
				$call( 'record_campaign_snapshot', $payload, 'previewed', [ 'recipient_count' => count( $recipients ) ] );
			} else {
				$result = $call( 'confirm_campaign', $payload, $recipients );
				if ( is_wp_error( $result ) ) { $result = [ 'code' => $result->get_error_code() ]; }
			}
			break;
		case 'queue':
			$hooks = [ ! empty( $input['dispatch'] ) ? YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN : YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL ];
			$store = ActionScheduler::store();
			$claim = $store->stake_claim( $input['batch'] ?? 1000, null, $hooks );
			if ( ! empty( $input['race'] ) ) {
				$signal( $input['race'] . '-claimed' ); $wait( 'execute' );
			}
			foreach ( $claim->get_actions() as $action_id ) { ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'Reliability fixtures' ); }
			$result = $claim->get_actions();
			$store->release_claim( $claim );
			break;
		case 'duplicate':
			$campaign = $call( 'get_campaign_history' )[ $id ];
			$payload = $call( 'get_campaign_reschedule_payload', $campaign );
			$reference = $call( 'store_campaign_email_payload', $payload );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT email,recipient_name AS name FROM %i WHERE campaign_id = %s LIMIT 1", $table, $id ), ARRAY_A );
			$result = $call( 'enqueue_email_action', $call( 'build_email_action_args', $payload, $row, $reference ) );
			break;
		case 'reconcile': $notify->reconcile_open_campaigns( $id ); break;
		case 'retry': $result = $call( 'retry_campaign', $id ); break;
		case 'age':
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET updated_at = '2020-01-01 00:00:00', scheduled_at_gmt = '2020-01-01 00:00:00' WHERE campaign_id = %s", $table, $id ) );
			break;
		case 'age_updated_only':
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET updated_at = '2020-01-01 00:00:00' WHERE campaign_id = %s", $table, $id ) ); break;
		case 'cancel_delivery':
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM %i WHERE campaign_id = %s", $table, $id ) );
			foreach ( $ids as $action_id ) { if ( $action_id ) { ActionScheduler::store()->cancel_action( (int) $action_id ); } }
			break;
		case 'deactivate': $notify->deactivate(); break;
		case 'cancel_dispatch':
			as_unschedule_all_actions( YOOHW_AN_Customer_Email_Notify::ACTION_DISPATCH_CAMPAIGN, [ $id ], YOOHW_AN_Customer_Email_Notify::ACTION_GROUP . '-dispatch' );
			break;
		case 'unsubscribe':
			$_GET = [ 'email' => $input['email'], 'campaign_id' => $id, 'token' => $call( 'get_unsubscribe_token', $input['email'] ) ];
			if ( 'storage_fail' === $fault ) {
				$wpdb->suppress_errors();
				add_filter( 'query', static function ( $query ) { return str_contains( $query, 'yoohw_an_optout_' ) && str_contains( $query, 'INSERT' ) ? 'INSERT INTO missing_fixture_table VALUES (1)' : $query; } );
			}
			add_filter( 'wp_die_handler', static function () { return static function ( $message, $title, $args ) { throw new RuntimeException( 'unsubscribe_response_' . $args['response'] ); }; } );
			add_filter( 'wp_die_ajax_handler', static function () { return static function ( $message, $title, $args ) { throw new RuntimeException( 'unsubscribe_response_' . $args['response'] ); }; } );
			try { $notify->handle_unsubscribe(); } catch ( RuntimeException $error ) { $result = $error->getMessage(); }
			break;
		case 'legacy_optouts':
			update_option( 'yoohw_an_unsubscribed_emails', [ 'OLD-A@example.com' => [ 'email' => 'OLD-A@example.com', 'unsubscribed_at' => 12 ], 'old-b@example.com', [ 'email' => 'old-c@example.com', 'campaign_id' => 'old' ] ], false );
			break;
		case 'legacy_claim':
			$email = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM %i WHERE campaign_id = %s LIMIT 1", $table, $id ) );
			$key = $call( 'get_recipient_delivery_option_name', $id, $id . '-delivery', $email );
			update_option( $key, [ 'state' => $input['state'] ?? 'sent', 'token' => 'legacy-fixture' ], false );
			break;
		case 'legacy_inline':
			delete_option( 'yoohw_an_reliability_import' );
			$args = [ 'campaign_id' => $id, 'to' => $input['email'], 'subject' => 'Legacy ' . $id, 'heading' => 'Legacy fixture', 'message' => '<p>Legacy fixture</p>', 'from_email' => 'store@example.com', 'from_name' => 'Fixture Store', 'reply_to_email' => 'reply@example.com' ];
			$result = as_enqueue_async_action( YOOHW_AN_Customer_Email_Notify::ACTION_SEND_SINGLE_EMAIL, [ $args ], YOOHW_AN_Customer_Email_Notify::ACTION_GROUP );
			break;
		case 'truncate_schema':
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN claim_token, DROP COLUMN attempts, DROP COLUMN error_kind" );
			update_option( 'yoohw_an_tracking_schema', 2 );
			break;
		case 'legacy_history':
			$history = $call( 'get_campaign_history' );
			$old = $history[ $id ];
			update_option( 'yoohw_an_campaigns', [ $id => $old ], false );
			delete_option( $call( 'campaign_record_key', $id ) );
			delete_option( $call( 'campaign_manifest_key', $id ) );
			break;
		case 'orphan': delete_option( $call( 'campaign_record_key', $id ) ); delete_option( $call( 'campaign_manifest_key', $id ) ); break;
		case 'missing_payload':
			$history = $call( 'get_campaign_history' );
			delete_option( $call( 'get_campaign_payload_option_name', $id, $history[ $id ]['delivery_id'] ) );
			break;
		case 'history':
			for ( $i = 0; $i < 110; $i++ ) { $call( 'record_campaign_snapshot', [ 'campaign_id' => 'history-' . $i, 'subject' => 'History fixture' ], 'completed' ); }
			break;
		case 'inspect':
			$history = $call( 'get_campaign_history' );
			$result = [ 'campaign' => $history[ $id ] ?? null, 'history_count' => count( $history ), 'first_campaign' => array_key_first( $history ), 'recipients' => $wpdb->get_results( $wpdb->prepare( "SELECT email,status,attempts,action_id,last_error,error_kind,claim_token,scheduled_at_gmt FROM %i WHERE campaign_id = %s ORDER BY id", $table, $id ), ARRAY_A ), 'optouts' => $call( 'get_unsubscribed_emails' ), 'jobs' => $wpdb->get_results( $wpdb->prepare( "SELECT hook,status,COUNT(*) AS total FROM %i WHERE args LIKE %s OR extended_args LIKE %s GROUP BY hook,status", $wpdb->prefix . 'actionscheduler_actions', '%' . $wpdb->esc_like( $id ) . '%', '%' . $wpdb->esc_like( $id ) . '%' ), ARRAY_A ) ];
			break;
		case 'render':
			wp_set_current_user( 1 );
			$_GET = [ 'tab' => 'campaigns', 'view' => $input['view'] ?? 'logs', 'campaign_id' => $id ];
			ob_start(); $notify->render_page(); $html = ob_get_clean();
			file_put_contents( $directory . '/campaign.html', $html );
			$result = [ 'handoff_label' => str_contains( $html, 'Handed to mail system' ), 'unknown_label' => str_contains( $html, 'Outcome unknown' ), 'recovery' => str_contains( $html, 'Recover / retry eligible recipients' ) ];
			break;
		default: throw new RuntimeException( 'Unknown fixture mode' );
	}
	echo "AN_RESULT=" . wp_json_encode( $result ) . "\n";
})();
