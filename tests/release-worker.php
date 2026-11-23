<?php
/**
 * Isolated queue/chat concurrency fixture. Never contacts a model service.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

if ( empty( $argv[1] ) || ! preg_match( '/^wp_otai_release_test_[a-z0-9]+_$/', $argv[1] )
	|| empty( $argv[2] ) || ! is_dir( $argv[2] ) || ! isset( $argv[3], $argv[4] )
	|| ! ctype_digit( $argv[3] ) || ! in_array( $argv[4], array( 'queue', 'chat' ), true ) ) {
	throw new RuntimeException( 'Invalid isolated release worker.' );
}
$wpdb->prefix = $argv[1];
add_filter( 'pre_option_otai_settings', function () {
	return array_merge( \OmniThreadAI\Settings::defaults(), array(
		'auto_sync' => 0, 'mode' => 'local_only', 'search_engine' => 'database',
		'rag_retrieval' => 'keyword', 'store_conversations' => 0,
		'faq' => "Q: When is support available?\nA: Synthetic support hours are 09:00 to 17:00.",
	) );
} );
\OmniThreadAI\Settings::flush();
add_filter( 'pre_http_request', function () { return new WP_Error( 'offline_fixture', 'External traffic forbidden.' ); } );
$go = $argv[2] . DIRECTORY_SEPARATOR . 'go';
file_put_contents( $argv[2] . DIRECTORY_SEPARATOR . $argv[3] . '.ready', 'ready' );
$deadline = time() + 60;
while ( ! is_file( $go ) ) {
	if ( time() > $deadline ) {
		throw new RuntimeException( 'Release worker start barrier timed out.' );
	}
	usleep( 10000 );
}
if ( 'queue' === $argv[4] ) {
	for ( $i = 0; $i < 10; ++$i ) {
		\OmniThreadAI\Index_Queue::run( 20 );
	}
	echo "WORKER queue completed\n";
} else {
	$times = array();
	for ( $i = 0; $i < 10; ++$i ) {
		$start = microtime( true );
		$result = \OmniThreadAI\Chat_Service::handle( array( 'message' => 'When is support available?' ) );
		if ( is_wp_error( $result ) || 'faq' !== $result['source'] || false === strpos( $result['reply'], '09:00' ) ) {
			throw new RuntimeException( 'Concurrent offline chat did not return the expected FAQ.' );
		}
		$times[] = ( microtime( true ) - $start ) * 1000;
	}
	echo 'WORKER ' . wp_json_encode( $times ) . "\n";
}
