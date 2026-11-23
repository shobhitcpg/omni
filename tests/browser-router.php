<?php
/**
 * Dedicated synthetic loopback UI/REST fixture, never a production router.
 */

if ( 'cli-server' !== PHP_SAPI || '1' !== getenv( 'OTAI_TEST_BROWSER' )
	|| '127.0.0.1:19306' !== getenv( 'OTAI_TEST_DB_HOST' )
	|| '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' )
	|| 19307 !== (int) ( $_SERVER['SERVER_PORT'] ?? 0 ) ) {
	http_response_code( 403 );
	exit( 'Dedicated test server required.' );
}
$request_server = $_SERVER;
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( 0 === strpos( $path, '/test-content/' ) || 0 === strpos( $path, '/wp-includes/' ) ) {
	$root = realpath( dirname( __DIR__, 3 ) );
	$relative = rawurldecode( substr( $path, strlen( '/test-content/' ) ) );
	if ( 0 === strpos( $path, '/wp-includes/' ) ) {
		$root = realpath( getenv( 'OTAI_TEST_WP_CORE' ) . DIRECTORY_SEPARATOR . 'wp-includes' );
		$relative = rawurldecode( substr( $path, strlen( '/wp-includes/' ) ) );
	} elseif ( 0 !== strpos( $relative, 'plugins/omnithread-ai/assets/' ) && 0 !== strpos( $relative, 'themes/twentytwentyone/' ) ) {
		http_response_code( 404 );
		exit;
	}
	$file = realpath( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	$type = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
	$types = array( 'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'png' => 'image/png' );
	if ( ! $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! isset( $types[ $type ] ) || ! is_file( $file ) ) {
		http_response_code( 404 );
		exit;
	}
	header( 'Content-Type: ' . $types[ $type ] );
	readfile( $file );
	exit;
}
require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
$_SERVER = $request_server;
$snapshot = get_option( 'otai_browser_fixture' );
if ( ! is_array( $snapshot ) ) {
	http_response_code( 503 );
	exit( 'Seed the dedicated browser fixture first.' );
}
$wpdb->prefix .= 'otai_browser_test_';
add_filter( 'pre_http_request', function () { return new WP_Error( 'otai_offline', 'External HTTP is prohibited by the browser fixture.' ); } );
add_filter( 'pre_option_home', function () { return 'http://127.0.0.1:19307'; } );
add_filter( 'pre_option_siteurl', function () { return 'http://127.0.0.1:19307'; } );
add_filter( 'pre_option_otai_setup_required', function () { return 'unpublished' === ( $_GET['view'] ?? '' ) ? 1 : 0; } );
add_filter( 'pre_option_' . \OmniThreadAI\Settings::OPTION, function () {
	return array_merge( \OmniThreadAI\Settings::defaults(), array(
		'enabled' => 1, 'mode' => 'local_only', 'display' => 'floating' === ( $_GET['view'] ?? '' ) ? 'floating' : 'shortcode_only',
		'store_conversations' => 0, 'auto_sync' => 0, 'search_engine' => 'database', 'post_types' => array(),
		'bot_name' => 'Offline fixture assistant', 'starters' => 'When is support available?',
		'faq' => "Q: When is support available?\nA: Synthetic support hours are 09:00 to 17:00.",
	) );
} );
\OmniThreadAI\Settings::flush();
$rest_route = $_GET['rest_route'] ?? ( 0 === strpos( $path, '/wp-json/' ) ? substr( $path, strlen( '/wp-json' ) ) : '' );
if ( in_array( $rest_route, array( '/omnithread-ai/v1/chat', '/omnithread-ai/v1/feedback' ), true ) ) {
	$GLOBALS['wp']->query_vars['rest_route'] = $rest_route;
	rest_api_loaded();
	exit;
}
if ( 'twentytwentyone' !== get_stylesheet() ) {
	http_response_code( 503 );
	exit( 'Use only the prepared standard-theme fixture.' );
}
require_once get_template_directory() . DIRECTORY_SEPARATOR . 'functions.php';
do_action( 'after_setup_theme' );
$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $snapshot['post'] ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['post'] = get_post( $snapshot['post'] );
if ( 'none' === ( $_GET['view'] ?? '' ) || 'floating' === ( $_GET['view'] ?? '' ) ) {
	$GLOBALS['post']->post_content = 'Synthetic page without a shortcode.';
}
get_header();
echo '<main style="max-width:900px;margin:2rem auto"><h1>Offline browser fixture</h1>';
echo apply_filters( 'the_content', $GLOBALS['post']->post_content );
echo '</main>';
get_footer();
