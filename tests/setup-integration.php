<?php
/**
 * Isolated customer onboarding and publication regression tests.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Setup;
use OmniThreadAI\Knowledge_Base as KB;
use OmniThreadAI\Index_Queue;
use OmniThreadAI\Rest_Controller;
use OmniThreadAI\Frontend;
use OmniThreadAI\Providers\Registry;

$errors = 0;
$checks = 0;
function setup_check( $ok, $message ) {
	global $checks, $errors;
	++$checks;
	if ( ! $ok ) {
		++$errors;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $message . "\n";
}
function setup_settings( array $changes ) {
	update_option( Settings::OPTION, Settings::sanitize( $changes ), false );
	Settings::flush();
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_setup_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$saved = array( 'cron' => get_option( 'cron' ) );
$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
foreach ( $names as $key ) {
	$saved[ $key ] = get_option( $key );
}
$user_id = get_current_user_id();
$posts = array();
$wpdb->prefix = $prefix;
$calls = 0;
$network = function () use ( &$calls ) {
	++$calls;
	return new WP_Error( 'test_external', 'No outbound network permitted in setup tests.' );
};
add_filter( 'pre_http_request', $network );
try {
	\OmniThreadAI\Admin::register_setting();
	delete_option( Settings::OPTION );
	Settings::flush();
	setup_check( true === Installer::install(), 'new customer schema installs successfully' );
	setup_check( ! Settings::get( 'enabled' ) && 'local_only' === Settings::get( 'mode' ) && 'shortcode_only' === Settings::get( 'display' ) && ! Settings::get( 'store_conversations' ), 'new installs are unpublished, local-only, shortcode-only and transcript storage off' );
	setup_check( ! Setup::published() && get_option( 'otai_setup_required' ), 'fresh installation requires explicit publication' );
	setup_settings( array( 'enabled' => 1 ) );
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', '/omnithread-ai/v1/chat' );
	setup_check( is_wp_error( Rest_Controller::public_permission( $request ) ), 'a settings checkbox cannot bypass first-run REST publication gate' );
	setup_check( ! Frontend::is_allowed_here(), 'unpublished installation cannot render the frontend widget' );
	setup_check( is_wp_error( Setup::launch() ), 'launch refuses an empty unreviewed deployment' );
	$initial = Settings::all();
	setup_check( is_wp_error( Setup::save( array( 'profile' => 'invalid', 'display' => 'floating', 'vertical' => 'general' ) ) ) && $initial === Settings::all(), 'invalid onboarding input fails without changing settings' );
	setup_check( is_wp_error( Setup::save( array( 'profile' => 'local', 'display' => 'floating', 'vertical' => array() ) ) ), 'non-scalar industry input fails explicitly' );
	setup_settings( array( 'persona' => 'Customer custom instructions', 'faq' => "Hours | Open weekdays", 'providers' => array( 'openai' => array( 'enabled' => 1, 'api_key' => 'private-test-provider-key' ) ) ) );
	$providers = Settings::get( 'providers' );
	$result = Setup::save( array( 'profile' => 'local', 'vertical' => 'general', 'display' => 'shortcode_only', 'privacy_reviewed' => 1 ) );
	setup_check( true === $result && 'Customer custom instructions' === Settings::get( 'persona' ) && $providers === Settings::get( 'providers' ), 'guided setup preserves custom instructions and saved encrypted credentials' );
	$id = wp_insert_post( array( 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 'Customer setup fixture', 'post_content' => '<p>Our customer support is available on weekdays.</p>' ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$posts[] = $id;
	$batch = KB::reindex_batch( 0, 100 );
	setup_check( $batch['done'] && is_wp_error( Setup::launch() ), 'queued work does not count as a completed initial knowledge base' );
	Index_Queue::run( 20 );
	setup_check( array() === Setup::blockers(), 'completed local deployment needs no external model or search server' );
	setup_check( true === Setup::launch() && Setup::published() && get_option( 'otai_setup_completed' ), 'explicit launch publishes only after readiness passes' );
	setup_check( true === Installer::install() && Setup::published(), 'reactivation/schema upgrade does not reset existing customer publication' );
	setup_settings( array( 'post_types' => array(), 'faq' => "Q: When is support available?\nA: Support is available on weekdays." ) );
	$batch = KB::reindex_batch( 0, 100 );
	Index_Queue::run( 20 );
	setup_check( ! is_wp_error( $batch ) && $batch['done'] && ! get_option( 'otai_needs_index' ) && array() === Setup::blockers(), 'FAQ-only sites can finish setup without selecting any post types' );
	update_option( 'otai_needs_index', 1, false );
	$wpdb->query( 'DROP TABLE `' . Index_Queue::table() . '`' );
	$suppressed = $wpdb->suppress_errors( true );
	$batch = KB::reindex_batch( 0, 100 );
	$wpdb->suppress_errors( $suppressed );
	setup_check( is_wp_error( $batch ) && get_option( 'otai_needs_index' ), 'queue storage failure cannot be reported as a successful rebuild' );
	Installer::install();
	KB::reindex_batch( 0, 100 );
	Index_Queue::run( 20 );
	$before = $calls;
	Setup::checks();
	Setup::report();
	Setup::health();
	setup_check( $before === $calls, 'readiness, Site Health and support export make zero outbound calls' );
	setup_settings( array( 'rag_embed_key' => 'embedding-private-sentinel', 'custom_knowledge' => 'private-content-sentinel', 'es_password' => 'search-private-sentinel', 'consent_notice' => 'private-notice-sentinel' ) );
	update_option( 'otai_rag_error', array( 'message' => 'private-error-sentinel' ), false );
	$report = wp_json_encode( Setup::report() );
	$leaks = false;
	foreach ( array( 'private-sentinel', 'private-content-sentinel', 'private-notice-sentinel', 'private-error-sentinel', home_url(), ABSPATH, 'api_key', 'fingerprint' ) as $index => $sentinel ) {
		if ( false !== strpos( $report, $sentinel ) ) {
			$leaks = true;
			echo "FAIL report sentinel {$index} matched\n";
		}
	}
	setup_check( ! $leaks, 'support report excludes secrets, content, raw errors, identity and internal fingerprints' );
	$before = Settings::get( 'providers' );
	setup_check( true === Setup::save( array( 'profile' => 'byok', 'vertical' => 'education', 'display' => 'selected', 'primary_provider' => 'openai', 'privacy_reviewed' => 1 ) ) && 'single' === Settings::get( 'mode' ) && 'selected' === Settings::get( 'display' ) && $before === Settings::get( 'providers' ), 'BYOK setup selects one explicit provider without paid cascade or credential replacement' );
	Setup::record_test( 'provider', array( 'text' => 'Test reply' ) );
	setup_check( Setup::tested( 'provider' ), 'successful service test is tied to current provider configuration' );
	setup_settings( array( 'providers' => array( 'openai' => array( 'model' => 'another-test-model' ) ) ) );
	setup_check( ! Setup::tested( 'provider' ), 'changing model invalidates previous service readiness' );
	Setup::record_test( 'provider', new WP_Error( 'fixture', 'failure' ) );
	setup_check( ! Setup::tested( 'provider' ), 'failed connection test cannot pass a publication gate' );
	Setup::record_test( 'provider', true );
	$tests = get_option( 'otai_setup_tests' );
	$tests['provider']['time'] = time() - DAY_IN_SECONDS - 1;
	update_option( 'otai_setup_tests', $tests, false );
	setup_check( ! Setup::tested( 'provider' ), 'service readiness expires after 24 hours' );
	$health_checks = array_column( Setup::checks(), 'status', 'id' );
	$launch_checks = array_column( Setup::blockers(), 'status', 'id' );
	setup_check( 'good' === $health_checks['provider_test'] && isset( $launch_checks['provider_test'] ), 'unchanged published deployments do not require daily paid tests, but a new launch requires recent validation' );
	$contracts = array();
	$provider_mock = function ( $pre, $args, $url ) use ( &$contracts ) {
		if ( 'https://api.openai.com/v1/chat/completions' === $url ) {
			$body = array( 'choices' => array( array( 'message' => array( 'content' => 'Connectivity verified.' ) ) ), 'model' => 'another-test-model' );
		} elseif ( 0 === strpos( $url, 'https://bedrock-runtime.us-east-1.amazonaws.com/model/' ) ) {
			$body = array( 'output' => array( 'message' => array( 'content' => array( array( 'text' => 'Connectivity verified.' ) ) ) ) );
		} else {
			return $pre;
		}
		$contracts[] = $args;
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
	};
	add_filter( 'pre_http_request', $provider_mock, 20, 3 );
	setup_check( ! is_wp_error( Setup::run_test( 'provider' ) ) && Setup::tested( 'provider' ), 'explicit provider connectivity test records successful contract validation' );
	$bedrock = Registry::make( 'bedrock', array( 'enabled' => 1, 'access_key' => 'synthetic-access', 'secret_key' => 'synthetic-secret' ) );
	$bedrock_result = $bedrock->chat( array( array( 'role' => 'user', 'content' => 'Connectivity test.' ) ), array() );
	setup_check( ! is_wp_error( $bedrock_result ) && 2 === count( $contracts ) && 0 === $contracts[0]['redirection'] && 0 === $contracts[1]['redirection'] && $contracts[0]['sslverify'] && $contracts[1]['sslverify'], 'both shared and Bedrock generation requests verify TLS and refuse credential redirects' );
	remove_filter( 'pre_http_request', $provider_mock, 20 );
	Setup::record_test( 'embedding', true );
	setup_settings( array( 'rag_embed_dimensions' => 768 ) );
	setup_check( ! Setup::tested( 'embedding' ), 'changing embedding dimensions invalidates embedding test' );
	Setup::record_test( 'backend', true );
	setup_settings( array( 'es_url' => 'http://localhost:19999' ) );
	setup_check( ! Setup::tested( 'backend' ), 'changing search endpoint invalidates vector-backend test' );
	$admin = get_user_by( 'login', 'rag-test-admin' );
	wp_set_current_user( $admin->ID );
	ob_start();
	Setup::page();
	$html = ob_get_clean();
	setup_check( false !== strpos( $html, 'Publish assistant' ) && false !== strpos( $html, 'otai_support' ) && false !== strpos( $html, '_wpnonce' ), 'customer setup renders launch, safe support and nonce-protected forms' );
	add_option( 'otai_setup_required', 1, '', false );
	setup_check( true === Rest_Controller::public_permission( $request ), 'authenticated administrator can preview an unpublished assistant' );
	wp_set_current_user( 0 );
	setup_check( is_wp_error( Rest_Controller::public_permission( $request ) ), 'unpublished admin preview remains unavailable to anonymous visitors' );
	$die_filter = function () {
		return function ( $message ) { throw new RuntimeException( wp_strip_all_tags( $message ) ); };
	};
	add_filter( 'wp_die_handler', $die_filter );
	foreach ( array( 'handle', 'download' ) as $method ) {
		$denied = false;
		try {
			Setup::$method();
		} catch ( RuntimeException $exception ) {
			$denied = 'Permission denied.' === $exception->getMessage();
		}
		setup_check( $denied, 'anonymous users cannot access setup action: ' . $method );
	}
	wp_set_current_user( $admin->ID );
	$rejected = false;
	$_REQUEST['_wpnonce'] = 'invalid-fixture-nonce';
	try {
		Setup::handle();
	} catch ( RuntimeException $exception ) {
		$rejected = true;
	}
	unset( $_REQUEST['_wpnonce'] );
	setup_check( $rejected, 'administrator setup writes require a valid nonce' );
	remove_filter( 'wp_die_handler', $die_filter );
	setup_check( isset( Setup::health_tests( array() )['direct']['omnithread_ai'] ) && isset( Setup::health()['test'] ), 'WordPress Site Health registration returns the expected shape' );
} catch ( Throwable $exception ) {
	++$errors;
	echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$wpdb->prefix = $original_prefix;
	wp_set_current_user( $user_id );
	remove_filter( 'pre_http_request', $network );
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
	foreach ( $names as $key ) {
		if ( ! array_key_exists( $key, $saved ) ) {
			delete_option( $key );
		}
	}
	foreach ( $saved as $key => $value ) {
		update_option( $key, $value );
	}
	Settings::flush();
}
echo "RESULT setup: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
