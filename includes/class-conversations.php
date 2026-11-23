<?php
/**
 * Conversation transcripts and feedback.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Conversations {

	public static function conversations_table() {
		global $wpdb;
		return $wpdb->prefix . 'otai_conversations';
	}

	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'otai_messages';
	}

	public static function get_or_create( $session_key, $page_url = '' ) {
		global $wpdb;
		$table = self::conversations_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_key = %s", $session_key ) );
		if ( $id ) {
			return $id;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'session_key'   => $session_key,
				'user_id'       => get_current_user_id(),
				'ip_hash'       => Rate_Limiter::ip_hash(),
				'page_url'      => esc_url_raw( $page_url ),
				'message_count' => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function add_message( $conversation_id, $role, $content, $provider = '', $source = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::messages_table(),
			array(
				'conversation_id' => (int) $conversation_id,
				'role'            => $role,
				'content'         => $content,
				'provider'        => $provider,
				'answer_source'   => $source,
				'created_at'      => $now,
			)
		);
		$message_id = (int) $wpdb->insert_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::conversations_table() . ' SET message_count = message_count + 1, updated_at = %s WHERE id = %d', $now, $conversation_id ) );
		return $message_id;
	}

	/**
	 * Record 👍 (1) / 👎 (-1) on an assistant message that belongs to the given session.
	 */
	public static function feedback( $session_key, $message_id, $rating ) {
		global $wpdb;
		$c = self::conversations_table();
		$m = self::messages_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$m} msg INNER JOIN {$c} conv ON conv.id = msg.conversation_id SET msg.feedback = %d WHERE msg.id = %d AND conv.session_key = %s AND msg.role = 'assistant'", $rating > 0 ? 1 : -1, $message_id, $session_key ) );
		return false !== $updated;
	}

	public static function recent( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$c = self::conversations_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
	}

	public static function count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::conversations_table() );
	}

	public static function messages( $conversation_id ) {
		global $wpdb;
		$m = self::messages_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$m} WHERE conversation_id = %d ORDER BY id ASC", $conversation_id ), ARRAY_A );
	}

	public static function feedback_totals() {
		global $wpdb;
		$m = self::messages_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT SUM(feedback = 1) AS up, SUM(feedback = -1) AS down FROM {$m}", ARRAY_A );
		return array(
			'up'   => $row ? (int) $row['up'] : 0,
			'down' => $row ? (int) $row['down'] : 0,
		);
	}

	/**
	 * Delete conversations older than $days (0 = everything).
	 */
	public static function purge( $days ) {
		global $wpdb;
		$c = self::conversations_table();
		$m = self::messages_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $days <= 0 ) {
			$wpdb->query( "DELETE FROM {$m}" );
			$wpdb->query( "DELETE FROM {$c}" );
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $days );
		$wpdb->query( $wpdb->prepare( "DELETE msg FROM {$m} msg INNER JOIN {$c} conv ON conv.id = msg.conversation_id WHERE conv.updated_at < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$c} WHERE updated_at < %s", $cutoff ) );
		// phpcs:enable
	}

	/**
	 * Personal data eraser/exporter support: conversations by user ID.
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$c = self::conversations_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$c} WHERE user_id = %d", $user_id ) ) );
	}

	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . self::messages_table() . " WHERE conversation_id IN ({$in})" );
		$wpdb->query( 'DELETE FROM ' . self::conversations_table() . " WHERE id IN ({$in})" );
		// phpcs:enable
	}
}
