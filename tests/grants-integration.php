<?php
/**
 * Actual denied CREATE/ALTER/write grants on the dedicated disposable server.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Request_Budget;

$errors = 0;
$checks = 0;
function grants_check( $ok, $label ) {
	global $errors, $checks;
	++$checks;
	$errors += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
$root = $wpdb;
$original_prefix = $root->prefix;
$prefix = $original_prefix . 'otai_grants_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$saved = array( 'cron' => get_option( 'cron' ) );
$keys = $root->get_col( $root->prepare( "SELECT option_name FROM {$root->options} WHERE option_name LIKE %s", $root->esc_like( 'otai_' ) . '%' ) );
foreach ( $keys as $key ) {
	$saved[ $key ] = get_option( $key );
}
$account = "'otai_release_readonly'@'127.0.0.1'";
$created = false;
try {
	if ( $root->get_var( "SELECT User FROM mysql.user WHERE User='otai_release_readonly' AND Host='127.0.0.1'" ) ) {
		throw new RuntimeException( 'Refusing to overwrite an existing restricted test account.' );
	}
	$password = wp_generate_password( 40, false, false );
	if ( false === $root->query( $root->prepare( "CREATE USER {$account} IDENTIFIED BY %s", $password ) ) ) {
		throw new RuntimeException( 'Cannot create disposable SELECT-only test account.' );
	}
	$created = true;
	if ( false === $root->query( "GRANT SELECT ON `otai_rag_test`.* TO {$account}" ) ) {
		throw new RuntimeException( 'Cannot grant disposable test account read access.' );
	}
	$root->prefix = $prefix;
	if ( is_wp_error( Installer::install() ) ) {
		throw new RuntimeException( 'Cannot create schema failure fixture.' );
	}
	update_option( 'otai_db_version', '4' );
	if ( false === $root->query( "ALTER TABLE `{$prefix}otai_knowledge` DROP COLUMN content_hash" ) ) {
		throw new RuntimeException( 'Cannot prepare prior-schema fixture.' );
	}
	$wpdb = new wpdb( 'otai_release_readonly', $password, DB_NAME, DB_HOST );
	$wpdb->set_prefix( $original_prefix );
	$wpdb->prefix = $prefix . 'new_';
	$wpdb->suppress_errors();
	wp_cache_flush();
	$result = Installer::install();
	grants_check( is_wp_error( $result ) && 'otai_schema' === $result->get_error_code(), 'denied CREATE produces an explicit incomplete-schema error' );
	grants_check( '4' === get_option( 'otai_db_version' ), 'failed CREATE does not mark schema upgrade as completed' );
	$wpdb->prefix = $prefix;
	$result = Installer::install();
	grants_check( is_wp_error( $result ) && 'otai_schema' === $result->get_error_code(), 'denied ALTER on a previous schema fails explicitly' );
	grants_check( '4' === get_option( 'otai_db_version' ), 'failed ALTER preserves the previous schema version' );
	$result = Request_Budget::reserve( 'grants-fixture', DAY_IN_SECONDS, 1 );
	grants_check( is_wp_error( $result ), 'denied quota writes fail closed rather than allowing an unmetered request' );
	$wpdb->close();
	$wpdb = $root;
	wp_cache_flush();
	$result = Installer::install();
	grants_check( true === $result && '5' === get_option( 'otai_db_version' )
		&& in_array( 'content_hash', $root->get_col( "SHOW COLUMNS FROM `{$prefix}otai_knowledge`" ), true ), 'restored grants allow retry to repair schema and complete upgrade' );
} catch ( Throwable $error ) {
	++$errors;
	echo 'FAIL ' . $error->getMessage() . "\n";
} finally {
	$wpdb = $root;
	if ( $created ) {
		$root->query( "DROP USER {$account}" );
	}
	foreach ( Installer::table_names() as $name ) {
		$root->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$root->prefix = $original_prefix;
	wp_cache_flush();
	Settings::flush();
	$current_keys = $root->get_col( $root->prepare( "SELECT option_name FROM {$root->options} WHERE option_name LIKE %s", $root->esc_like( 'otai_' ) . '%' ) );
	foreach ( array_diff( $current_keys, array_keys( $saved ) ) as $key ) {
		delete_option( $key );
	}
	foreach ( $saved as $key => $value ) {
		update_option( $key, $value );
	}
}
echo "RESULT database grants: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
