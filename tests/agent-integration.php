<?php
/**
 * Opt-in public site-context bridge contracts; optional actual loopback service.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Site_Context;
use OmniThreadAI\Providers\Registry;
use OmniThreadAI\Providers\Agent_Service;

$errors = 0;
$checks = 0;
function agent_check( $ok, $label ) {
	global $errors, $checks;
	++$checks;
	$errors += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_agent_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$saved = array( 'cron' => get_option( 'cron' ) );
$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
foreach ( $keys as $key ) {
	$saved[ $key ] = get_option( $key );
}
$wpdb->prefix = $prefix;
$config = array( 'enabled' => 1, 'base_url' => 'https://agents.example', 'site_key' => str_repeat( 'a', 48 ), 'consent' => 1 );
$settings = array_merge( Settings::defaults(), array( 'vertical' => 'education', 'mode' => 'single', 'primary_provider' => 'agent_service' ) );
$filter = function () use ( &$settings ) { return $settings; };
add_filter( 'pre_option_' . Settings::OPTION, $filter );
$home_filter = function () { return 'https://otai-tests.invalid'; };
add_filter( 'pre_option_home', $home_filter );
Settings::flush();
$calls = array();
$response = array( 'text' => 'Synthetic site-specific answer [1].', 'model' => 'synthetic-agent' );
$http = function ( $preempt, $args, $url ) use ( &$calls, &$response ) {
	$calls[] = array( 'url' => $url, 'args' => $args, 'body' => json_decode( $args['body'], true ) );
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $response ) );
};
add_filter( 'pre_http_request', $http, 10, 3 );
$messages = array( array( 'role' => 'system', 'content' => 'Internal instructions must not be sent as site data.' ), array( 'role' => 'user', 'content' => 'What are the support hours?' ) );
$params = array( 'context' => "[1] Support policy\nSynthetic support hours are 09:00 to 17:00." );
try {
	if ( is_wp_error( Installer::install() ) ) {
		throw new RuntimeException( 'Cannot install isolated bridge test schema.' );
	}
	agent_check( Registry::blueprint( 'agent_service' ) instanceof Agent_Service, 'separate agent service is registered without replacing existing backend' );
	$provider = Registry::make( 'agent_service', $config );
	$result = $provider->chat( $messages, $params );
	agent_check( ! is_wp_error( $result ) && 'synthetic-agent' === $result['model'], 'bridge maps valid service response into standard provider contract' );
	$call = $calls[0];
	agent_check( 'https://agents.example/v1/chat' === $call['url'] && 0 === $call['args']['redirection'] && true === $call['args']['sslverify'], 'bridge uses fixed chat route, verifies TLS and never follows redirects' );
	agent_check( 'Bearer ' . $config['site_key'] === $call['args']['headers']['Authorization'] && false === strpos( $call['args']['body'], $config['site_key'] ), 'site credential is a server-side header, never part of site context' );
	agent_check( array( 'url', 'name', 'description', 'language', 'industry' ) === array_keys( $call['body']['site'] ) && home_url( '/' ) === $call['body']['site']['url'], 'site profile contains only allowlisted public metadata from installed WordPress site' );
	agent_check( $params['context'] === $call['body']['context'] && 'education' === $call['body']['site']['industry']
		&& false === strpos( $call['args']['body'], 'Internal instructions' ), 'bridge sends approved context and current question, not internal prompts or account identity' );
	agent_check( ! $call['body']['diagnostic'], 'normal chat is not mislabeled as a connectivity test' );
	$provider->chat( $messages, array_merge( $params, array( 'diagnostic' => true ) ) );
	agent_check( true === $calls[1]['body']['diagnostic'], 'explicit provider test can authenticate without inference or connector calls' );
	$before = count( $calls );
	foreach ( array(
		array( 'consent' => 0 ), array( 'base_url' => 'http://remote.example' ),
		array( 'base_url' => 'https://user:password@agents.example' ),
		array( 'base_url' => 'https://agents.example?token=secret' ),
		array( 'site_key' => "bad\r\nkey" ),
	) as $invalid ) {
		$bad = Registry::make( 'agent_service', array_merge( $config, $invalid ) );
		agent_check( is_wp_error( $bad->chat( $messages, $params ) ), 'invalid/unapproved bridge configuration is explicitly rejected' );
	}
	agent_check( $before === count( $calls ), 'rejected configurations make no outbound requests' );
	agent_check( is_wp_error( $provider->chat( $messages, array( 'context' => str_repeat( 'x', 24001 ) ) ) ), 'oversized evidence fails explicitly rather than silently truncating' );
	$response = array( 'text' => array( 'invalid' ), 'model' => 'bad' );
	agent_check( is_wp_error( $provider->chat( $messages, $params ) ), 'invalid upstream answer types are rejected' );
	agent_check( array() === \OmniThreadAI\Smart_Router::candidates( 'local_only' ), 'optional service never changes local-only routing' );
	$live = getenv( 'OTAI_TEST_AGENT_URL' );
	if ( $live ) {
		if ( 'http://127.0.0.1:19308' !== $live ) {
			throw new RuntimeException( 'Actual agent test allows only the dedicated loopback port.' );
		}
		remove_filter( 'pre_http_request', $http, 10 );
		$provider = Registry::make( 'agent_service', array_merge( $config, array( 'base_url' => $live ) ) );
		$result = $provider->chat( $messages, $params );
		agent_check( ! is_wp_error( $result ) && 'evidence-extract' === $result['model'] && false !== strpos( $result['text'], '09:00' ), 'actual PHP-to-FastAPI-to-LangGraph HTTP roundtrip returns supplied site evidence' );
		$result = $provider->chat( $messages, array( 'diagnostic' => true ) );
		agent_check( ! is_wp_error( $result ) && 'bridge-diagnostic' === $result['model'], 'actual service diagnostic authenticates without model credentials' );
	}
} catch ( Throwable $error ) {
	++$errors;
	echo 'FAIL ' . $error->getMessage() . "\n";
} finally {
	remove_filter( 'pre_http_request', $http, 10 );
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $filter );
	remove_filter( 'pre_option_home', $home_filter );
	Settings::flush();
	$current_keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
	foreach ( array_diff( $current_keys, array_keys( $saved ) ) as $key ) {
		delete_option( $key );
	}
	foreach ( $saved as $key => $value ) {
		update_option( $key, $value );
	}
}
echo "RESULT agent bridge: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
