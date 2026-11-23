<?php
/**
 * Subprocess fixture; only used with the isolated budget integration suite.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

if ( empty( $argv[1] ) || ! preg_match( '/^wp_otai_budget_test_[a-z0-9]+_$/', $argv[1] )
	|| empty( $argv[2] ) || ! is_dir( $argv[2] ) || ! isset( $argv[3] ) || ! ctype_digit( $argv[3] ) ) {
	throw new RuntimeException( 'Invalid isolated worker fixture.' );
}
$wpdb->prefix = $argv[1];
$ready = $argv[2] . DIRECTORY_SEPARATOR . $argv[3] . '.ready';
$go = $argv[2] . DIRECTORY_SEPARATOR . 'go';
file_put_contents( $ready, 'ready' );
$deadline = time() + 60;
while ( ! is_file( $go ) ) {
	if ( time() > $deadline ) {
		throw new RuntimeException( 'Timed out waiting for the concurrency barrier.' );
	}
	usleep( 10000 );
}
$accepted = 0;
for ( $i = 0; $i < 5; ++$i ) {
	$result = \OmniThreadAI\Request_Budget::reserve( 'concurrent', DAY_IN_SECONDS, 7 );
	if ( true === $result ) {
		++$accepted;
	} elseif ( 'otai_budget_exhausted' !== $result->get_error_code() ) {
		throw new RuntimeException( $result->get_error_message() );
	}
}
echo "ACCEPTED {$accepted}\n";
