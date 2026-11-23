<?php
/**
 * Isolated rate-limit, generation-attempt cap and real multi-process tests.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Request_Budget as Budget;
use OmniThreadAI\Rate_Limiter;
use OmniThreadAI\Smart_Router;
use OmniThreadAI\Chat_Service;
use OmniThreadAI\Admin;
use OmniThreadAI\Usage;
use OmniThreadAI\Rest_Controller;

$errors = 0;
$checks = 0;
function budget_check( $ok, $label ) {
	global $errors, $checks;
	++$checks;
	if ( ! $ok ) {
		++$errors;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_budget_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$wpdb->prefix = $prefix;
$settings = array_merge( Settings::defaults(), array(
	'mode' => 'single', 'primary_provider' => 'openai', 'rag_retrieval' => 'keyword',
	'rag_grounded_only' => 0, 'store_conversations' => 0, 'cache_ttl' => 0,
	'local_first' => 0, 'search_engine' => 'database', 'auto_sync' => 0,
	'rate_limit_minute' => 2, 'rate_limit_day' => 3,
	'providers' => array( 'openai' => array( 'enabled' => 1, 'api_key' => 'synthetic-key', 'daily_limit' => 2 ) ),
) );
$filter = function () use ( &$settings ) { return $settings; };
add_filter( 'pre_option_' . Settings::OPTION, $filter );
Settings::flush();
$saved = array();
foreach ( array( 'otai_db_version', 'otai_fulltext', 'otai_needs_index', 'otai_rag_error', 'cron' ) as $key ) {
	$saved[ $key ] = get_option( $key, null );
}
$calls = 0;
$succeed = false;
$mock = function () use ( &$calls, &$succeed ) {
	++$calls;
	if ( $succeed ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array(
			'choices' => array( array( 'message' => array( 'content' => 'Synthetic successful answer.' ) ) ),
			'model' => 'synthetic-model',
		) ), 'headers' => array(), 'cookies' => array() );
	}
	return new WP_Error( 'fixture_failure', 'Synthetic provider failure after attempt reservation.' );
};
add_filter( 'pre_http_request', $mock );
$user = get_current_user_id();
wp_set_current_user( 0 );
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.23';
$workers = array();
$barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'otai-budget-' . wp_generate_uuid4();
try {
	if ( true !== Installer::install() ) {
		throw new RuntimeException( 'Cannot install isolated quota schema.' );
	}
	budget_check( true === Budget::reserve( 'exact', DAY_IN_SECONDS, 2 ) && true === Budget::reserve( 'exact', DAY_IN_SECONDS, 2 ), 'reservations accept exactly the configured allowance' );
	$limited = Budget::reserve( 'exact', DAY_IN_SECONDS, 2 );
	budget_check( is_wp_error( $limited ) && 429 === $limited->get_error_data()['status'] && $limited->get_error_data()['retry_after'] > 0, 'exhausted window returns 429 and a bounded retry delay' );
	$request = new WP_REST_Request( 'POST', '/omnithread-ai/v1/chat' );
	$response = new WP_REST_Response( array( 'data' => $limited->get_error_data() ), 429 );
	$response = Rest_Controller::response_headers( $response, null, $request );
	budget_check( 'no-store, private' === $response->get_headers()['Cache-Control'] && (int) $response->get_headers()['Retry-After'] > 0, 'quota REST responses prohibit caching and expose Retry-After' );
	$unrelated = new WP_REST_Response( array(), 200 );
	Rest_Controller::response_headers( $unrelated, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
	budget_check( array() === $unrelated->get_headers(), 'plugin response headers do not alter unrelated REST routes' );
	budget_check( true === Budget::reserve( 'other', DAY_IN_SECONDS, 1 ), 'independent scope does not consume another quota' );
	budget_check( is_wp_error( Budget::reserve( 'invalid', 0, 1 ) ), 'invalid quota windows fail explicitly' );
	budget_check( true === Rate_Limiter::check() && true === Rate_Limiter::check() && is_wp_error( Rate_Limiter::check() ), 'visitor minute cap is enforced by persistent reservations' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.24';
	budget_check( true === Rate_Limiter::check(), 'different visitor receives an independent allowance' );
	$settings['rate_limit_minute'] = 10;
	$settings['rate_limit_day'] = 1;
	Settings::flush();
	$daily = Rate_Limiter::check();
	budget_check( is_wp_error( $daily ) && 'otai_rate_limited' === $daily->get_error_code(), 'visitor daily cap still blocks when the minute allowance is available' );
	$settings['providers']['openai']['enabled'] = 0;
	Settings::flush();
	budget_check( array() === Smart_Router::candidates(), 'disabled primary provider is never selected in single-provider mode' );
	$settings['providers']['openai']['enabled'] = 1;
	Settings::flush();
	$context = array( 'query' => 'synthetic fixture', 'messages' => array( array( 'role' => 'user', 'content' => 'Fixture.' ) ), 'skip_local' => true );
	Smart_Router::route( $context );
	Smart_Router::route( $context );
	$third = Smart_Router::route( $context );
	budget_check( 2 === $calls && ! is_wp_error( $third ) && $third['attempts'], 'failed generation attempts consume quota and a third request makes no upstream call' );
	$test = Admin::test_provider( 'openai' );
	budget_check( is_wp_error( $test ) && 'otai_provider_limit' === $test->get_error_code() && 2 === $calls, 'admin connectivity tests cannot bypass the shared provider cap' );
	$wpdb->query( 'DELETE FROM ' . Budget::table() );
	$wpdb->query( 'DELETE FROM ' . Usage::table() );
	Usage::log( array( 'provider' => 'openai', 'answer_source' => 'llm', 'success' => 1 ) );
	Usage::log( array( 'provider' => 'openai', 'answer_source' => 'llm', 'success' => 0 ) );
	budget_check( is_wp_error( Budget::provider( 'openai', 2 ) ), 'initial provider reservation includes pre-upgrade successes and failures' );
	$wpdb->query( 'DELETE FROM ' . Budget::table() );
	$wpdb->query( 'DELETE FROM ' . Usage::table() );
	Admin::test_provider( 'openai' );
	Admin::test_provider( 'openai' );
	$before = $calls;
	Smart_Router::route( $context );
	budget_check( 4 === $calls && $before === $calls, 'failed connection tests consume the same allowance used by chat generation' );
	$wpdb->query( 'DELETE FROM ' . Budget::table() );
	$wpdb->query( 'DELETE FROM ' . Usage::table() );
	$succeed = true;
	$settings['cache_ttl'] = 60;
	$settings['providers']['openai']['daily_limit'] = 1;
	Settings::flush();
	$key = 'otai_rc_budget_' . wp_generate_password( 12, false, false );
	$cached_context = array_merge( $context, array( 'cache_key' => $key ) );
	$before = $calls;
	$success = Smart_Router::route( $cached_context );
	$cached = Smart_Router::route( $cached_context );
	budget_check( 'llm' === $success['source'] && 'cache' === $cached['source'] && $calls === $before + 1, 'successful generation remains functional and cache hits consume no provider attempt' );
	delete_transient( $key );
	$wpdb->query( 'DELETE FROM ' . Budget::table() );
	$wpdb->query( 'DELETE FROM ' . Usage::table() );
	$settings['mode'] = 'smart';
	$settings['provider_order'] = 'openai,groq';
	$settings['providers']['groq'] = array( 'enabled' => 1, 'api_key' => 'synthetic-key', 'daily_limit' => 1 );
	Settings::flush();
	Budget::provider( 'openai', 1 );
	$before = $calls;
	$cascade = Smart_Router::route( $context );
	budget_check( 'groq' === $cascade['provider'] && $calls === $before + 1 && count( $cascade['attempts'] ) === 1, 'smart routing skips exhausted provider and reserves the next enabled provider independently' );
	$wpdb->query( 'DELETE FROM ' . Budget::table() );
	Budget::reserve( 'expired-fixture', DAY_IN_SECONDS, 1 );
	$wpdb->query( 'UPDATE ' . Budget::table() . " SET expires_at='2000-01-01 00:00:00'" );
	budget_check( true === Budget::purge() && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Budget::table() ), 'maintenance removes expired request buckets' );
	mkdir( $barrier );
	$command = array( PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get( 'extension_dir' ), '-d', 'extension=mysqli', '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=curl', __DIR__ . DIRECTORY_SEPARATOR . 'budget-worker.php', $prefix, $barrier );
	for ( $i = 0; $i < 12; ++$i ) {
		$pipes = array();
		$process = proc_open( array_merge( $command, array( (string) $i ) ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Cannot start quota concurrency worker.' );
		}
		fclose( $pipes[0] );
		$workers[] = array( $process, $pipes );
	}
	$deadline = time() + 60;
	do {
		$ready = true;
		for ( $i = 0; $i < 12; ++$i ) {
			$ready = $ready && is_file( $barrier . DIRECTORY_SEPARATOR . $i . '.ready' );
		}
		if ( time() > $deadline ) {
			throw new RuntimeException( 'Workers did not reach the concurrency barrier.' );
		}
		usleep( 10000 );
	} while ( ! $ready );
	file_put_contents( $barrier . DIRECTORY_SEPARATOR . 'go', 'go' );
	$accepted = 0;
	foreach ( $workers as $worker ) {
		$output = stream_get_contents( $worker[1][1] );
		$stderr = stream_get_contents( $worker[1][2] );
		fclose( $worker[1][1] );
		fclose( $worker[1][2] );
		$code = proc_close( $worker[0] );
		if ( 0 !== $code || ! preg_match( '/^ACCEPTED (\d+)$/m', $output, $match ) ) {
			throw new RuntimeException( 'Quota subprocess failed: ' . $stderr . $output );
		}
		$accepted += (int) $match[1];
	}
	$workers = array();
	budget_check( 7 === $accepted, '12 simultaneous PHP workers making 60 attempts accept exactly seven against cap seven' );
	budget_check( 7 === (int) $wpdb->get_var( 'SELECT used FROM ' . Budget::table() . ' LIMIT 1' ), 'durable counter matches accepted concurrent reservations without overshoot' );
	$wpdb->query( 'DROP TABLE `' . Budget::table() . '`' );
	$suppress = $wpdb->suppress_errors( true );
	$before = $calls;
	$failed = Chat_Service::handle( array( 'message' => 'unmatched quota fixture' ) );
	$visitor = Rate_Limiter::check();
	$wpdb->suppress_errors( $suppress );
	budget_check( is_wp_error( $failed ) && 'otai_budget_storage' === $failed->get_error_code() && $before === $calls, 'budget storage outage returns explicit chat error before contacting a provider' );
	budget_check( is_wp_error( $visitor ) && 503 === $visitor->get_error_data()['status'], 'visitor limiter fails closed on unavailable quota storage' );
	update_option( 'otai_db_version', '4', false );
	Installer::maybe_upgrade();
	budget_check( '5' === get_option( 'otai_db_version' ) && true === Budget::reserve( 'upgraded', DAY_IN_SECONDS, 1 ), 'existing version-four installation upgrades to the atomic quota schema' );
} catch ( Throwable $exception ) {
	++$errors;
	echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
	foreach ( $workers as $worker ) {
		if ( is_resource( $worker[0] ) ) {
			proc_terminate( $worker[0] );
			foreach ( $worker[1] as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			proc_close( $worker[0] );
		}
	}
	if ( is_dir( $barrier ) ) {
		foreach ( array_merge( array( 'go' ), array_map( function ( $i ) { return $i . '.ready'; }, range( 0, 11 ) ) ) as $name ) {
			$file = $barrier . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $barrier );
	}
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $filter );
	remove_filter( 'pre_http_request', $mock );
	wp_set_current_user( $user );
	if ( null === $ip ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $ip;
	}
	foreach ( $saved as $key => $value ) {
		null === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	Settings::flush();
}
echo "RESULT budgets: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
