<?php
/**
 * Run with wp --skip-plugins --skip-themes eval-file in a local test installation.
 * Uses a temporary French MO file and a request-only locale filter. No site/user language is changed.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'Run using WP-CLI.' );
}

(function () {
$plugin_root = dirname( __DIR__ );
$domain = 'yoohw-advanced-notifications';
$mofile = $plugin_root . '/languages/' . $domain . '-fr_FR.mo';
foreach ( [ $mofile, str_replace( '.mo', '.po', $mofile ), str_replace( '.mo', '.l10n.php', $mofile ), WP_LANG_DIR . '/plugins/' . $domain . '-fr_FR.mo', WP_LANG_DIR . '/plugins/' . $domain . '-fr_FR.l10n.php' ] as $existing ) {
	if ( file_exists( $existing ) ) {
		WP_CLI::error( 'French translations already exist. Use a disposable installation to run this test.' );
	}
}
require_once ABSPATH . 'wp-includes/pomo/mo.php';
$mo = new MO();
$mo->set_headers( [ 'Content-Type' => 'text/plain; charset=UTF-8', 'Language' => 'fr_FR', 'Plural-Forms' => 'nplurals=2; plural=(n > 1);' ] );
$translations = [
	'Email campaigns' => 'Campagnes e-mail',
	'Manual email list' => 'Liste d’adresses e-mail',
	'Recipient source' => 'Source des destinataires',
	'Email subject' => 'Objet de l’e-mail',
	'Email heading' => 'En-tête de l’e-mail',
	'Message body' => 'Corps du message',
	'Cancelled' => 'Annulé',
	'Unresolved' => 'Non résolu',
	'Unsubscribe' => 'Se désabonner',
	'Please enter at least one recipient email address.' => 'Veuillez saisir au moins une adresse e-mail.',
];
foreach ( $translations as $original => $translated ) {
	$mo->add_entry( new Translation_Entry( [ 'singular' => $original, 'translations' => [ $translated ] ] ) );
}
$mo->add_entry( new Translation_Entry( [ 'singular' => '%d recipient', 'plural' => '%d recipients', 'translations' => [ '%d destinataire', '%d destinataires' ] ] ) );
$locale_filter = static function () { return 'fr_FR'; };
$original_user = get_current_user_id();
$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Snapshot CLI fixture state; this does not process a browser request.
$checks = [];
try {
	if ( ! $mo->export_to_file( $mofile ) ) { throw new RuntimeException( 'Could not write the temporary translation fixture.' ); }
	wp_cache_delete( md5( trailingslashit( $plugin_root . '/languages' ) ), 'translation_files' );
	wp_register_plugin_realpath( WP_PLUGIN_DIR . '/yoohw-advanced-notifications/yoohw-advanced-notifications.php' );
	require_once $plugin_root . '/yoohw-advanced-notifications.php';
	unload_textdomain( $domain, true );
	add_filter( 'determine_locale', $locale_filter, PHP_INT_MAX );
	yoohw_an_load_textdomain();
	$checks['loader_registered_on_init'] = 0 === has_action( 'init', 'yoohw_an_load_textdomain' );
	$checks['bundled_mo_loads_automatically'] = 'Campagnes e-mail' === __( 'Email campaigns', 'yoohw-advanced-notifications' ) && is_textdomain_loaded( $domain );
	$checks['singular_translation'] = '1 destinataire' === sprintf( /* translators: %d: Number of recipients. */ _n( '%d recipient', '%d recipients', 1, 'yoohw-advanced-notifications' ), 1 );
	$checks['plural_translation'] = '2 destinataires' === sprintf( /* translators: %d: Number of recipients. */ _n( '%d recipient', '%d recipients', 2, 'yoohw-advanced-notifications' ), 2 );
	$notify = new YOOHW_AN_Customer_Email_Notify();
	$class = new ReflectionClass( $notify );
	$sources = $class->getMethod( 'get_recipient_sources' )->invoke( $notify );
	$checks['recipient_source_translation'] = 'Liste d’adresses e-mail' === $sources['manual_emails'];
	$checks['cancelled_status_translation'] = 'Annulé' === $class->getMethod( 'get_status_label_from_key' )->invoke( $notify, 'cancelled' );
	$checks['unresolved_status_translation'] = 'Non résolu' === $class->getMethod( 'get_status_label_from_key' )->invoke( $notify, 'unresolved' );
	$validation = $class->getMethod( 'validate_recipient_source_options' )->invoke( $notify, [ 'recipient_source' => 'manual_emails', 'manual_emails' => [] ] );
	$checks['validation_message_translation'] = is_wp_error( $validation ) && 'Veuillez saisir au moins une adresse e-mail.' === $validation->get_error_message();
	$checks['unsubscribe_translation'] = false !== strpos( $class->getMethod( 'get_unsubscribe_footer_html' )->invoke( $notify ), 'Se désabonner' );
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	if ( empty( $admins ) ) { throw new RuntimeException( 'This test needs a local administrator account.' ); }
	wp_set_current_user( $admins[0] );
	$_GET = [];
	ob_start();
	$notify->render_page();
	$html = ob_get_clean();
	$checks['translated_form_renders'] = false !== strpos( $html, 'Campagnes e-mail' ) && false !== strpos( $html, 'Source des destinataires' );
	$notify->enqueue_scripts( 'woocommerce_page_yoohw-advanced-notifications' );
	$scripts = implode( "\n", (array) wp_scripts()->get_data( 'yoohw-advanced-notifications-admin', 'before' ) );
	preg_match( '/var yoohwAN = (.+);/', $scripts, $matches );
	$defaults = isset( $matches[1] ) ? json_decode( $matches[1], true )['previewDefaults'] : [];
	$checks['live_preview_translations'] = 'Objet de l’e-mail' === ( $defaults['subject'] ?? '' ) && 'Corps du message' === ( $defaults['message'] ?? '' );
	remove_filter( 'determine_locale', $locale_filter, PHP_INT_MAX );
	unload_textdomain( $domain, true );
	yoohw_an_load_textdomain();
	$checks['english_fallback_without_language_pack'] = 'Email campaigns' === __( 'Email campaigns', 'yoohw-advanced-notifications' );
} catch ( Throwable $error ) {
	$checks['unexpected_exception'] = false;
	WP_CLI::warning( $error->getMessage() );
} finally {
	remove_filter( 'determine_locale', $locale_filter, PHP_INT_MAX );
	unload_textdomain( $domain, true );
	if ( file_exists( $mofile ) ) { wp_delete_file( $mofile ); }
	wp_cache_delete( md5( trailingslashit( $plugin_root . '/languages' ) ), 'translation_files' );
	$_GET = $original_get;
	wp_set_current_user( $original_user );
}
echo wp_json_encode( [ 'passed' => ! in_array( false, $checks, true ), 'checks' => $checks ], JSON_PRETTY_PRINT ) . PHP_EOL;
if ( in_array( false, $checks, true ) ) { WP_CLI::error( 'Internationalization checks failed.' ); }

})();
