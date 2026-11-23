<?php
/**
 * Offline access-boundary, concurrent queue/chat and local retrieval evaluation.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Access_Policy;
use OmniThreadAI\Knowledge_Base as KB;
use OmniThreadAI\Index_Queue;
use OmniThreadAI\RAG;
use OmniThreadAI\Chat_Service;

$errors = 0;
$checks = 0;
function release_check( $ok, $label ) {
	global $errors, $checks;
	++$checks;
	if ( ! $ok ) {
		++$errors;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function release_workers( $mode, $prefix ) {
	$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'otai-release-' . wp_generate_uuid4();
	mkdir( $directory );
	$workers = array();
	$times = array();
	$started = microtime( true );
	try {
		$command = array( PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get( 'extension_dir' ), '-d', 'extension=mysqli', '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=curl', __DIR__ . DIRECTORY_SEPARATOR . 'release-worker.php', $prefix, $directory );
		for ( $i = 0; $i < 6; ++$i ) {
			$pipes = array();
			$process = proc_open( array_merge( $command, array( (string) $i, $mode ) ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Cannot start release concurrency worker.' );
			}
			fclose( $pipes[0] );
			$workers[] = array( $process, $pipes );
		}
		$deadline = time() + 60;
		do {
			$ready = true;
			for ( $i = 0; $i < 6; ++$i ) {
				$ready = $ready && is_file( $directory . DIRECTORY_SEPARATOR . $i . '.ready' );
			}
			if ( time() > $deadline ) {
				throw new RuntimeException( 'Release workers did not reach the barrier.' );
			}
			usleep( 10000 );
		} while ( ! $ready );
		file_put_contents( $directory . DIRECTORY_SEPARATOR . 'go', 'go' );
		foreach ( $workers as $worker ) {
			$output = stream_get_contents( $worker[1][1] );
			$stderr = stream_get_contents( $worker[1][2] );
			fclose( $worker[1][1] );
			fclose( $worker[1][2] );
			$code = proc_close( $worker[0] );
			if ( $code || ! preg_match( '/^WORKER (.+)$/m', $output, $match ) || $stderr ) {
				throw new RuntimeException( 'Release worker failed: ' . $stderr . $output );
			}
			if ( 'chat' === $mode ) {
				$sample = json_decode( $match[1], true );
				if ( ! is_array( $sample ) || 10 !== count( $sample ) ) {
					throw new RuntimeException( 'Incomplete chat timing sample.' );
				}
				$times = array_merge( $times, $sample );
			}
		}
		$workers = array();
		return array( 'wall_seconds' => round( microtime( true ) - $started, 3 ), 'times' => $times );
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
		foreach ( array_merge( array( 'go' ), array_map( function ( $i ) { return $i . '.ready'; }, range( 0, 5 ) ) ) as $file ) {
			$path = $directory . DIRECTORY_SEPARATOR . $file;
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		rmdir( $directory );
	}
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_release_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$wpdb->prefix = $prefix;
$settings = array_merge( Settings::defaults(), array(
	'auto_sync' => 0, 'mode' => 'local_only', 'search_engine' => 'database',
	'rag_retrieval' => 'keyword', 'store_conversations' => 0,
	'faq' => "Q: When is support available?\nA: Synthetic support hours are 09:00 to 17:00.",
) );
$filter = function () use ( &$settings ) { return $settings; };
add_filter( 'pre_option_' . Settings::OPTION, $filter );
Settings::flush();
$network = function () { return new WP_Error( 'offline_fixture', 'External traffic forbidden.' ); };
add_filter( 'pre_http_request', $network );
$saved = array();
foreach ( array( 'otai_db_version', 'otai_needs_index', 'otai_fulltext', 'otai_rag_error', 'otai_last_indexed', 'otai_last_reconcile', 'otai_reconcile_cursor', 'cron' ) as $key ) {
	$saved[ $key ] = get_option( $key, null );
}
$posts = array();
$membership = function () { return array( 'memberpress/memberpress.php' ); };
$custom = function ( $systems ) { return array( 'custom-fixture' => 'Custom access fixture' ); };
$connector = function ( $allowed, $post ) { return 'yes' === get_post_meta( $post->ID, '_release_anonymous_allowed', true ); };
try {
	if ( true !== Installer::install() ) {
		throw new RuntimeException( 'Cannot create isolated release schema.' );
	}
	for ( $i = 0; $i < 60; ++$i ) {
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
			'post_title' => 'Release fixture ' . $i,
			'post_content' => '<p>Topicmarker' . $i . ' policy applies to synthetic customer group ' . $i . '.</p><p>Caseidentifier' . $i . ' support is available on weekdays.</p>',
		), true );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$posts[] = $id;
		Index_Queue::enqueue( $id );
	}
	Index_Queue::enqueue( 0 );
	$processed = release_workers( 'queue', $prefix );
	$jobs = $wpdb->get_results( 'SELECT status,COUNT(*) AS n FROM ' . Index_Queue::table() . ' GROUP BY status', OBJECT_K );
	release_check( 1 === count( $jobs ) && isset( $jobs['done'] ) && 61 === (int) $jobs['done']->n, 'six simultaneous queue workers finish all 61 document jobs without stuck/failed leases' );
	$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . KB::table() . ' WHERE object_id>0' );
	release_check( 180 === $count, 'concurrent indexing retains exactly three stable passages per synthetic document' );
	echo 'METRIC queue ' . wp_json_encode( array( 'documents' => 61, 'workers' => 6, 'wall_seconds' => $processed['wall_seconds'], 'passages' => $count ) ) . "\n";
	$correct = 0;
	$times = array();
	for ( $i = 0; $i < 20; ++$i ) {
		$start = microtime( true );
		$found = RAG::retrieve( 'Topicmarker' . $i, 2 );
		$times[] = ( microtime( true ) - $start ) * 1000;
		if ( $found && (int) $posts[ $i ] === $found[0]['object_id'] ) {
			++$correct;
		}
	}
	release_check( 20 === $correct, 'offline lexical evaluation returns the expected source first for all 20 exact-topic questions' );
	$unknown = Chat_Service::handle( array( 'message' => 'zzunanswerablefixturetoken' ) );
	release_check( ! is_wp_error( $unknown ) && 'fallback' === $unknown['source'] && ! $unknown['sources'], 'unanswerable offline question abstains without fabricated sources' );
	$rows = RAG::retrieve( 'Topicmarker0', 2 );
	add_filter( 'pre_option_active_plugins', $membership );
	release_check( isset( Access_Policy::systems()['memberpress'] ) && ! KB::eligible( $posts[0] ), 'recognised membership activation denies post eligibility by default' );
	release_check( array() === RAG::hydrate( $rows ) && array() === RAG::retrieve( 'Topicmarker0', 2 ), 'membership activation revokes cached/stale passage retrieval immediately' );
	$blocked = array_column( \OmniThreadAI\Setup::checks(), 'status', 'id' );
	release_check( 'blocked' === $blocked['access_policy'], 'setup blocks post-based launch without a membership access connector' );
	$faq = Chat_Service::handle( array( 'message' => 'When is support available?' ) );
	release_check( 'faq' === $faq['source'], 'owner-curated manual FAQ remains available while post sources fail closed' );
	add_filter( 'otai_index_post_allowed', $connector, 10, 2 );
	update_post_meta( $posts[0], '_release_anonymous_allowed', 'yes' );
	release_check( KB::eligible( $posts[0] ) && ! KB::eligible( $posts[1] ), 'explicit connector can allow one anonymously public post without allowing other posts' );
	delete_post_meta( $posts[0], '_release_anonymous_allowed' );
	release_check( array() === RAG::hydrate( $rows ), 'changing access metadata revokes existing passage output before reindexing' );
	remove_filter( 'otai_index_post_allowed', $connector, 10 );
	remove_filter( 'pre_option_active_plugins', $membership );
	add_filter( 'otai_access_systems', $custom );
	release_check( ! KB::eligible( $posts[0] ), 'custom and MU access systems can register the same deny-by-default boundary' );
	remove_filter( 'otai_access_systems', $custom );
	$sample = release_workers( 'chat', $prefix );
	sort( $sample['times'], SORT_NUMERIC );
	release_check( 60 === count( $sample['times'] ), 'six concurrent workers complete 60 real offline chat pipeline turns correctly' );
	echo 'METRIC chat ' . wp_json_encode( array( 'turns' => 60, 'workers' => 6,
		'p50_ms' => round( $sample['times'][29], 2 ), 'p95_ms' => round( $sample['times'][56], 2 ),
		'max_ms' => round( max( $sample['times'] ), 2 ), 'wall_seconds' => $sample['wall_seconds'],
		'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ),
	) ) . "\n";
} catch ( Throwable $exception ) {
	++$errors;
	echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
	remove_filter( 'pre_option_active_plugins', $membership );
	remove_filter( 'otai_access_systems', $custom );
	remove_filter( 'otai_index_post_allowed', $connector, 10 );
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $filter );
	remove_filter( 'pre_http_request', $network );
	foreach ( $saved as $key => $value ) {
		null === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	Settings::flush();
}
echo "RESULT offline release: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
