<?php
/**
 * Isolated schema, deactivation and opt-in uninstall checks.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Index_Queue;

$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_lifecycle_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$wpdb->prefix = $prefix;
$saved = array( 'cron' => get_option( 'cron' ) );
$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
foreach ( $keys as $key ) {
	$saved[ $key ] = get_option( $key );
}
$delete = false;
$filter = function () use ( &$delete ) { return array_merge( Settings::defaults(), array( 'delete_on_uninstall' => $delete ? 1 : 0 ) ); };
add_filter( 'pre_option_' . Settings::OPTION, $filter );
Settings::flush();
$errors = 0;
function lifecycle_check( $condition, $label ) {
	global $errors;
	if ( ! $condition ) {
		++$errors;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function lifecycle_table_count() {
	global $wpdb, $prefix;
	$count = 0;
	foreach ( Installer::table_names() as $name ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . $name ) ) ) ) {
			++$count;
		}
	}
	return $count;
}
try {
	lifecycle_check( true === Installer::install() && 8 === lifecycle_table_count(), 'schema installation verifies all eight local tables' );
	lifecycle_check( true === Installer::install() && '5' === get_option( 'otai_db_version' ), 'schema installation is idempotent and records version five' );
	Installer::deactivate();
	lifecycle_check( ! wp_next_scheduled( Index_Queue::HOOK ) && ! wp_next_scheduled( Installer::CRON_HOOK ), 'deactivation clears both worker schedules' );
	Installer::install();
	define( 'WP_UNINSTALL_PLUGIN', plugin_basename( OTAI_FILE ) );
	require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'uninstall.php';
	lifecycle_check( 8 === lifecycle_table_count(), 'default uninstall preserves local knowledge, embeddings and conversations' );
	lifecycle_check( ! wp_next_scheduled( Index_Queue::HOOK ), 'uninstall clears schedules even when retaining data' );
	$delete = true;
	Settings::flush();
	otai_uninstall_site();
	lifecycle_check( 0 === lifecycle_table_count(), 'explicit uninstall removes all eight plugin tables' );
	lifecycle_check( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->posts ) ) ), 'uninstall preserves unrelated WordPress tables' );
} catch ( Throwable $error ) {
	++$errors;
	echo 'FAIL ' . $error->getMessage() . "\n";
} finally {
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $filter );
	Settings::flush();
	foreach ( $saved as $key => $value ) {
		update_option( $key, $value );
	}
}
echo "RESULT lifecycle: {$errors} failures\n";
exit( $errors ? 1 : 0 );
