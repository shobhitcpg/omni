<?php
/**
 * Isolated privacy pagination, data isolation, retention and storage failures.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Plugin;
use OmniThreadAI\Conversations;

$errors = 0;
$checks = 0;
function privacy_check( $ok, $label ) {
	global $errors, $checks;
	++$checks;
	$errors += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_privacy_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$saved = array( 'cron' => get_option( 'cron' ) );
$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
foreach ( $keys as $key ) {
	$saved[ $key ] = get_option( $key );
}
$wpdb->prefix = $prefix;
$user_id = 0;
$renamed = false;
$messages = Conversations::messages_table();
$filter = function () { return array_merge( Settings::defaults(), array( 'store_conversations' => 1 ) ); };
add_filter( 'pre_option_' . Settings::OPTION, $filter );
Settings::flush();
try {
	if ( is_wp_error( Installer::install() ) ) {
		throw new RuntimeException( 'Cannot install privacy test tables.' );
	}
	$email = 'privacy-' . wp_generate_uuid4() . '@example.invalid';
	$user_id = wp_insert_user( array( 'user_login' => 'privacy-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'user_email' => $email ) );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( $user_id->get_error_message() );
	}
	wp_set_current_user( $user_id );
	for ( $i = 0; $i < 101; ++$i ) {
		$id = Conversations::get_or_create( 'privacy-' . $i );
		Conversations::add_message( $id, 'user', 'Synthetic privacy message ' . $i );
	}
	wp_set_current_user( 0 );
	$anonymous = Conversations::get_or_create( 'privacy-anonymous' );
	Conversations::add_message( $anonymous, 'user', 'Unrelated anonymous fixture.' );
	$page_one = Plugin::export_personal_data( $email, 1 );
	$page_two = Plugin::export_personal_data( $email, 2 );
	privacy_check( 100 === count( $page_one['data'] ) && ! $page_one['done'] && 1 === count( $page_two['data'] ) && $page_two['done'], 'personal-data export is bounded and paginates 101 messages without truncation' );
	$ids = array_column( array_merge( $page_one['data'], $page_two['data'] ), 'item_id' );
	privacy_check( 101 === count( array_unique( $ids ) ), 'export pages contain no duplicate message identifiers' );
	privacy_check( false === strpos( wp_json_encode( $page_one ) . wp_json_encode( $page_two ), 'Unrelated anonymous fixture' ), 'export excludes anonymous/unrelated conversations' );
	$empty = Plugin::export_personal_data( 'missing@example.invalid' );
	privacy_check( array() === $empty['data'] && $empty['done'], 'unknown email has an empty completed export' );
	if ( false === $wpdb->query( "RENAME TABLE `{$messages}` TO `{$messages}_offline`" ) ) {
		throw new RuntimeException( 'Cannot simulate privacy storage outage.' );
	}
	$renamed = true;
	$previous_suppression = $wpdb->suppress_errors();
	$failed_export = Plugin::export_personal_data( $email );
	$failed_erase = Plugin::erase_personal_data( $email );
	$wpdb->suppress_errors( $previous_suppression );
	privacy_check( is_wp_error( $failed_export ) && 'otai_privacy_storage' === $failed_export->get_error_code(), 'export surfaces database failure instead of claiming an empty success' );
	privacy_check( is_wp_error( $failed_erase ) && 101 === count( Conversations::for_user( $user_id ) ), 'failed message deletion reports error and retains conversation manifests for retry' );
	if ( false === $wpdb->query( "RENAME TABLE `{$messages}_offline` TO `{$messages}`" ) ) {
		throw new RuntimeException( 'Cannot restore privacy storage.' );
	}
	$renamed = false;
	$first = Plugin::erase_personal_data( $email );
	privacy_check( $first['items_removed'] && ! $first['done'] && 1 === count( Conversations::for_user( $user_id ) ), 'eraser removes at most 100 conversations per call without skipping remaining rows' );
	$second = Plugin::erase_personal_data( $email );
	$third = Plugin::erase_personal_data( $email );
	privacy_check( $second['items_removed'] && $second['done'] && ! $third['items_removed'] && $third['done'], 'eraser finishes remaining batch and repeated erasure is idempotent' );
	privacy_check( 1 === Conversations::count() && 1 === count( Conversations::messages( $anonymous ) ), 'erasure preserves unrelated conversation and messages' );
	$stale = Conversations::get_or_create( 'privacy-stale' );
	Conversations::add_message( $stale, 'user', 'Synthetic expired message.' );
	$wpdb->update( Conversations::conversations_table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => $stale ) );
	Conversations::purge( 1 );
	privacy_check( 1 === Conversations::count() && ! Conversations::messages( $stale ) && Conversations::messages( $anonymous ), 'retention removes old conversations with their messages while keeping fresh data' );
} catch ( Throwable $error ) {
	++$errors;
	echo 'FAIL ' . $error->getMessage() . "\n";
} finally {
	if ( $renamed ) {
		$wpdb->query( "RENAME TABLE `{$messages}_offline` TO `{$messages}`" );
	}
	wp_set_current_user( 0 );
	if ( is_int( $user_id ) && $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $filter );
	Settings::flush();
	$current_keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'otai_' ) . '%' ) );
	foreach ( array_diff( $current_keys, array_keys( $saved ) ) as $key ) {
		delete_option( $key );
	}
	foreach ( $saved as $key => $value ) {
		update_option( $key, $value );
	}
}
echo "RESULT privacy: {$checks} checks, {$errors} failures\n";
exit( $errors ? 1 : 0 );
