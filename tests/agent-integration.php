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
use OmniThreadAI\Crypto;
use OmniThreadAI\Smart_Router;

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
	agent_check( ! isset( $call['body']['agent_id'] ), 'blank agent selection preserves the server tenant default' );
	$provider->chat( $messages, array_merge( $params, array( 'diagnostic' => true ) ) );
	agent_check( true === $calls[1]['body']['diagnostic'], 'explicit provider test can authenticate without inference or connector calls' );
	$before = count( $calls );
	foreach ( array(
		array( 'consent' => 0 ), array( 'base_url' => 'http://remote.example' ),
		array( 'base_url' => 'https://user:password@agents.example' ),
		array( 'base_url' => 'https://agents.example?token=secret' ),
		array( 'site_key' => "bad\r\nkey" ),
		array( 'agent_id' => '../../staff' ),
	) as $invalid ) {
		$bad = Registry::make( 'agent_service', array_merge( $config, $invalid ) );
		agent_check( is_wp_error( $bad->chat( $messages, $params ) ), 'invalid/unapproved bridge configuration is explicitly rejected' );
	}
	agent_check( $before === count( $calls ), 'rejected configurations make no outbound requests' );
	$selected = Registry::make( 'agent_service', array_merge( $config, array( 'agent_id' => 'public-support' ) ) );
	$selected->chat( $messages, $params );
	agent_check( 'public-support' === $calls[ count( $calls ) - 1 ]['body']['agent_id'], 'configured agent ID is sent server-side for tenant/published/audience validation' );
	agent_check( is_wp_error( $provider->chat( $messages, array( 'context' => str_repeat( 'x', 24001 ) ) ) ), 'oversized evidence fails explicitly rather than silently truncating' );
	$response = array( 'text' => array( 'invalid' ), 'model' => 'bad' );
	agent_check( is_wp_error( $provider->chat( $messages, $params ) ), 'invalid upstream answer types are rejected' );
	agent_check( array() === \OmniThreadAI\Smart_Router::candidates( 'local_only' ), 'optional service never changes local-only routing' );
	$response = array( 'text' => 'Synthetic current answer.', 'model' => 'synthetic-agent' );
	$settings['local_first'] = 0;
	$settings['providers']['agent_service'] = array_merge( $config, array( 'site_key' => Crypto::encrypt( $config['site_key'] ) ) );
	Settings::flush();
	$cache_key = 'otai_agent_cache_test_' . wp_generate_uuid4();
	set_transient( $cache_key, array( 'reply' => 'Stale published answer.' ), 60 );
	$before = count( $calls );
	$ctx = array( 'query' => 'Synthetic agent policy', 'messages' => $messages, 'params' => $params,
		'cache_key' => $cache_key, 'chunks' => array( array( 'title' => 'Synthetic evidence', 'url' => '', 'content' => 'Synthetic evidence', 'score' => 1 ) ) );
	$first = Smart_Router::route( $ctx );
	$second = Smart_Router::route( $ctx );
	agent_check( count( $calls ) === $before + 2 && ! is_wp_error( $first ) && ! is_wp_error( $second )
		&& 'llm' === $first['source'] && 'llm' === $second['source'],
		'agent routing bypasses old/new WordPress response cache so central changes are checked on each remote turn' );
	delete_transient( $cache_key );
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
		$selected_id = getenv( 'OTAI_TEST_AGENT_ID' );
		if ( $selected_id ) {
			$provider = Registry::make( 'agent_service', array_merge( $config, array( 'base_url' => $live, 'agent_id' => $selected_id ) ) );
			$result = $provider->chat( $messages, $params );
			agent_check( ! is_wp_error( $result ) && 'evidence-extract' === $result['model'], 'actual WordPress bridge selects a centrally published public agent' );
		}
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
