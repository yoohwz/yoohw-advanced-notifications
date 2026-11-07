<?php
/** Independent Completed-hook/worker process for the disposable automation race fixture. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( DB_NAME, 'reliability' ) ) { exit( 'Disposable reliability database required.' ); }
add_filter( 'woocommerce_logger_log_message', static function ( $message ) { fwrite( STDERR, $message . PHP_EOL ); return $message; } );
add_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );
$order = wc_get_order( absint( $args[0] ?? 0 ) );
if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'Automation race fixture order missing.' ); }
(new YOOHW_AN_Customer_Email_Notify())->trigger_completed_order_guides( $order->get_id(), 'processing', 'completed', $order );
