<?php
/**
 * Prepare/clean synthetic data for the loopback-only browser router.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Knowledge_Base;

if ( empty( $argv[1] ) || ! in_array( $argv[1], array( 'seed', 'clean' ), true ) || is_multisite() ) {
	throw new RuntimeException( 'Use seed or clean in the dedicated single-site fixture.' );
}
$snapshot = get_option( 'otai_browser_fixture' );
$prefix = $wpdb->prefix . 'otai_browser_test_';
if ( 'seed' === $argv[1] ) {
	if ( $snapshot ) {
		throw new RuntimeException( 'Browser fixture already exists.' );
	}
	$options = array( 'cron' => get_option( 'cron' ), 'template' => get_option( 'template' ), 'stylesheet' => get_option( 'stylesheet' ) );
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
	foreach ( $keys as $key ) {
		$options[ $key ] = get_option( $key );
	}
	$wpdb->prefix = $prefix;
	if ( is_wp_error( Installer::install() ) ) {
		throw new RuntimeException( 'Cannot install browser fixture tables.' );
	}
	update_option( Settings::OPTION, array_merge( Settings::defaults(), array(
		'auto_sync' => 0, 'store_conversations' => 0, 'mode' => 'local_only',
		'faq' => "Q: When is support available?\nA: Synthetic support hours are 09:00 to 17:00.",
	) ) );
	Settings::flush();
	$result = Knowledge_Base::index_manual_entries( false );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	update_option( 'template', 'twentytwentyone' );
	update_option( 'stylesheet', 'twentytwentyone' );
	$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Offline browser fixture', 'post_content' => '[omnithread_ai_chat height="580px"]' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	update_option( 'otai_browser_fixture', array( 'post' => $post_id, 'options' => $options ), false );
	echo "RESULT browser fixture seeded\n";
} elseif ( ! is_array( $snapshot ) ) {
	throw new RuntimeException( 'Browser fixture is missing.' );
} else {
	$wpdb->prefix = $prefix;
	wp_delete_post( $snapshot['post'], true );
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
	foreach ( array_diff( $keys, array_keys( $snapshot['options'] ) ) as $key ) {
		delete_option( $key );
	}
	foreach ( $snapshot['options'] as $key => $value ) {
		update_option( $key, $value );
	}
	echo "RESULT browser fixture cleaned\n";
}
