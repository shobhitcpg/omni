<?php
/**
 * Provision only the dedicated disposable multisite fixture.
 */

if ( 'prepare' !== getenv( 'OTAI_TEST_NETWORK' ) ) {
	throw new RuntimeException( 'Use OTAI_TEST_NETWORK=prepare for this fixture.' );
}
require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/schema.php';
require_once ABSPATH . 'wp-admin/includes/network.php';
foreach ( $wpdb->tables( 'ms_global' ) as $name => $table ) {
	$wpdb->$name = $table;
}
install_network();
$result = populate_network( 1, 'otai-tests.invalid', 'test@example.invalid', 'Isolated release network', '/', false );
if ( is_wp_error( $result ) || $wpdb->last_error ) {
	throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'Network fixture database error.' );
}
echo "RESULT network fixture prepared\n";
