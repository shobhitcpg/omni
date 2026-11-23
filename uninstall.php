<?php
/**
 * Remove only plugin-owned local data when explicitly enabled by the owner.
 *
 * @package OmniThreadAI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-installer.php';

function otai_uninstall_site() {
	global $wpdb;
	wp_clear_scheduled_hook( 'otai_daily_maintenance' );
	wp_clear_scheduled_hook( 'otai_index_worker' );
	$settings = get_option( 'otai_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}
	foreach ( \OmniThreadAI\Installer::table_names() as $name ) {
		if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $name . '`' ) ) {
			wp_die( esc_html__( 'OmniThread AI could not remove a local table. Check database permissions before retrying uninstall.', 'omnithread-ai' ) );
		}
	}
	delete_post_meta_by_key( '_otai_widget' );
	$keys = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'otai_' ) . '%', $wpdb->esc_like( '_transient_otai_' ) . '%', $wpdb->esc_like( '_transient_timeout_otai_' ) . '%'
	) );
	if ( $wpdb->last_error ) {
		wp_die( esc_html__( 'OmniThread AI could not list its local options for removal.', 'omnithread-ai' ) );
	}
	foreach ( $keys as $key ) {
		if ( 0 === strpos( $key, '_transient_timeout_' ) ) {
			delete_transient( substr( $key, strlen( '_transient_timeout_' ) ) );
		} elseif ( 0 === strpos( $key, '_transient_' ) ) {
			delete_transient( substr( $key, strlen( '_transient_' ) ) );
		} else {
			delete_option( $key );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $otai_blog_id ) {
		switch_to_blog( $otai_blog_id );
		otai_uninstall_site();
		restore_current_blog();
	}
} else {
	otai_uninstall_site();
}
