<?php
/**
 * Seed/mutate/verify/clean a backup fixture in the dedicated test database only.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Installer;
use OmniThreadAI\Settings;
use OmniThreadAI\Knowledge_Base;
use OmniThreadAI\Index_Queue;
use OmniThreadAI\Conversations;
use OmniThreadAI\Crypto;

if ( 'otai_rag_test' !== DB_NAME || is_multisite() || empty( $argv[1] ) || ! in_array( $argv[1], array( 'seed', 'mutate', 'verify', 'clean' ), true ) ) {
	throw new RuntimeException( 'Use only the dedicated single-site backup fixture.' );
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_restore_test_';
$wpdb->prefix = $prefix;
$mode = $argv[1];
$snapshot = get_option( 'otai_restore_fixture' );
if ( 'seed' === $mode ) {
	if ( $snapshot ) {
		throw new RuntimeException( 'A backup fixture already exists; refusing to overwrite it.' );
	}
	$options = array();
	foreach ( array( Settings::OPTION, 'otai_db_version', 'otai_fulltext', 'otai_needs_index', 'otai_last_indexed', 'otai_last_reconcile', 'otai_reconcile_cursor', 'cron' ) as $key ) {
		$options[ $key ] = get_option( $key, null );
	}
	$result = Installer::install();
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$settings = array_merge( Settings::defaults(), array( 'mode' => 'local_only', 'auto_sync' => 0, 'search_engine' => 'database', 'bot_name' => 'Backup fixture assistant', 'rag_embed_key' => Crypto::encrypt( 'synthetic-backup-key' ) ) );
	update_option( Settings::OPTION, $settings, false );
	Settings::flush();
	$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Backup fixture', 'post_content' => '<p>Restoremarkertoken identifies the offline backup policy.</p>' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	Index_Queue::enqueue( $post_id );
	if ( 1 !== Index_Queue::run( 1 ) ) {
		throw new RuntimeException( 'Backup fixture indexing did not finish.' );
	}
	$conversation = Conversations::get_or_create( 'restore-synthetic-session' );
	Conversations::add_message( $conversation, 'user', 'Synthetic backup question.' );
	Conversations::add_message( $conversation, 'assistant', 'Synthetic backup answer.', '', 'knowledge' );
	update_option( 'otai_restore_fixture', array( 'post' => $post_id, 'conversation' => $conversation, 'options' => $options ), false );
	echo "RESULT backup fixture seeded\n";
} elseif ( ! is_array( $snapshot ) ) {
	throw new RuntimeException( 'Missing backup fixture snapshot.' );
} elseif ( 'mutate' === $mode ) {
	$wpdb->query( 'DELETE FROM ' . Knowledge_Base::table() );
	$wpdb->query( 'DELETE FROM ' . Conversations::messages_table() );
	$settings = Settings::all();
	$settings['bot_name'] = 'Mutated fixture';
	$settings['rag_embed_key'] = '';
	update_option( Settings::OPTION, $settings, false );
	echo "RESULT backup fixture deliberately mutated\n";
} elseif ( 'verify' === $mode ) {
	if ( 'Backup fixture assistant' !== Settings::get( 'bot_name' )
		|| 'synthetic-backup-key' !== Crypto::decrypt( Settings::get( 'rag_embed_key' ) )
		|| ! Knowledge_Base::search( 'Restoremarkertoken', 2 )
		|| 2 !== count( Conversations::messages( $snapshot['conversation'] ) ) ) {
		throw new RuntimeException( 'Restored settings, encryption, retrieval or conversations differ from the backup.' );
	}
	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Index_Queue::table() . ' WHERE object_id=%d', $snapshot['post'] ), ARRAY_A );
	if ( ! $job || 'done' !== $job['status'] || ! $job['synced_hash'] ) {
		throw new RuntimeException( 'Restored job manifest is incomplete.' );
	}
	echo "PASS actual SQL dump/restore recovers settings, encrypted key, passages, conversation and queue manifest\n";
	echo "RESULT backup restoration: 1 check, 0 failures\n";
} else {
	wp_delete_post( $snapshot['post'], true );
	foreach ( Installer::table_names() as $name ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$name}`" );
	}
	foreach ( $snapshot['options'] as $key => $value ) {
		null === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	delete_option( 'otai_restore_fixture' );
	echo "RESULT backup fixture cleaned\n";
}
$wpdb->prefix = $original_prefix;
