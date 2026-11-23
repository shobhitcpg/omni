<?php
/**
 * Actual WordPress multisite isolation and lifecycle checks.
 */

if ( 'run' !== getenv( 'OTAI_TEST_NETWORK' ) ) {
	throw new RuntimeException( 'Use OTAI_TEST_NETWORK=run on the prepared fixture.' );
}
require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Index_Queue;
use OmniThreadAI\Request_Budget;
use OmniThreadAI\Knowledge_Base;
use OmniThreadAI\Setup;

$checks = 0;
$errors = 0;
function network_check( $ok, $label ) {
	global $checks, $errors;
	++$checks;
	if ( ! $ok ) {
		++$errors;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
$site_id = 0;
$scope = 'isolation-' . wp_generate_uuid4();
$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
try {
	if ( ! is_multisite() ) {
		throw new RuntimeException( 'The fixture is not a real multisite installation.' );
	}
	update_site_option( 'active_sitewide_plugins', array( plugin_basename( OTAI_FILE ) => time() ) );
	Installer::activate( true );
	$main_settings = Settings::all();
	$main_settings['bot_name'] = 'Network main assistant';
	update_option( Settings::OPTION, $main_settings, false );
	Settings::flush();
	$site_id = wp_insert_site( array( 'domain' => 'otai-tests.invalid', 'path' => '/release-child/', 'title' => 'Release child', 'user_id' => 1 ) );
	if ( is_wp_error( $site_id ) ) {
		throw new RuntimeException( $site_id->get_error_message() );
	}
	network_check( 1 === get_current_blog_id() && ! ms_is_switched(), 'new-site provisioning restores the original blog context' );
	switch_to_blog( $site_id );
	network_check( OTAI_DB_VERSION === get_option( 'otai_db_version' ), 'network-active plugin provisions newly created sites' );
	$child_settings = get_option( Settings::OPTION );
	$child_settings['bot_name'] = 'Network child assistant';
	update_option( Settings::OPTION, $child_settings, false );
	network_check( 'Network child assistant' === Settings::get( 'bot_name' ), 'switching blogs reads child settings rather than cached main settings' );
	network_check( ! Setup::published(), 'new network child remains unpublished' );
	network_check( true === Request_Budget::reserve( $scope, DAY_IN_SECONDS, 1 ) && is_wp_error( Request_Budget::reserve( $scope, DAY_IN_SECONDS, 1 ) ), 'child quota uses child tables' );
	$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Child knowledge', 'post_content' => '<p>Childonlyidentifier is private to this site corpus.</p>' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	Index_Queue::enqueue( $post_id );
	Index_Queue::run( 20 );
	network_check( (bool) Knowledge_Base::search( 'Childonlyidentifier', 2 ), 'child content is retrievable in its own knowledge base' );
	restore_current_blog();
	network_check( 'Network main assistant' === Settings::get( 'bot_name' ), 'restoring blog restores main settings without a manual cache flush' );
	network_check( true === Request_Budget::reserve( $scope, DAY_IN_SECONDS, 1 ), 'child exhausted quota does not consume main-site quota' );
	network_check( array() === Knowledge_Base::search( 'Childonlyidentifier', 2 ), 'child content cannot appear in main-site retrieval' );
	Installer::deactivate( true );
	$unscheduled = true;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
		switch_to_blog( $id );
		$unscheduled = $unscheduled && ! wp_next_scheduled( Index_Queue::HOOK ) && ! wp_next_scheduled( Installer::CRON_HOOK );
		restore_current_blog();
	}
	network_check( $unscheduled, 'network deactivation clears schedules on every site' );
	Installer::activate( true );
	switch_to_blog( $site_id );
	network_check( 'Network child assistant' === Settings::get( 'bot_name' ) && wp_next_scheduled( Index_Queue::HOOK ), 'network reactivation retains child settings and restores worker schedule' );
	$tables = Installer::on_delete_site( array() );
	network_check( count( Installer::table_names() ) === count( $tables ) && in_array( Request_Budget::table(), $tables, true ), 'site-deletion hook includes all plugin-owned tables' );
	restore_current_blog();
	// This fixture intentionally contains only disposable sites.
	define( 'WP_UNINSTALL_PLUGIN', plugin_basename( OTAI_FILE ) );
	require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'uninstall.php';
	switch_to_blog( $site_id );
	network_check( (bool) get_option( Settings::OPTION ), 'network uninstall retains child data by default' );
	$child_settings['delete_on_uninstall'] = 1;
	update_option( Settings::OPTION, $child_settings, false );
	otai_uninstall_site();
	$count = 0;
	foreach ( Installer::table_names() as $name ) {
		$count += (int) (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $name ) ) );
	}
	network_check( 0 === $count && false === get_option( Settings::OPTION ), 'child opt-in uninstall removes child tables and options' );
	restore_current_blog();
	network_check( 'Network main assistant' === get_option( Settings::OPTION )['bot_name'], 'child deletion preference does not delete main-site data' );
} catch ( Throwable $exception ) {
	++$errors;
	echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
	while ( ms_is_switched() ) {
		restore_current_blog();
	}
	update_site_option( 'active_sitewide_plugins', $network_plugins );
	if ( $site_id && ! is_wp_error( $site_id ) ) {
		wp_delete_site( $site_id );
	}
}
echo "RESULT network: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
