<?php
/**
 * Atomic, persistent fixed-window request reservations shared across workers.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Request_Budget {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'otai_request_budget';
	}

	/**
	 * No refunds: a failed request may already have reached the upstream service.
	 */
	public static function reserve( $scope, $window, $limit, $initial = 0 ) {
		global $wpdb;
		if ( ! is_string( $scope ) || '' === $scope || ! is_int( $window ) || $window < 1 || ! is_int( $limit ) || $limit < 1 || ! is_int( $initial ) || $initial < 0 ) {
			return new \WP_Error( 'otai_budget_input', __( 'Invalid request-budget configuration.', 'omnithread-ai' ), array( 'status' => 503 ) );
		}
		$now = time();
		$bucket = (int) floor( $now / $window );
		$key = hash( 'sha256', $scope . '|' . $window . '|' . $bucket );
		$table = self::table();
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (bucket_key,used,expires_at) VALUES (%s,%d,%s)",
			$key, $initial, gmdate( 'Y-m-d H:i:s', ( $bucket + 1 ) * $window )
		) );
		if ( false === $inserted ) {
			return self::storage_error();
		}
		$reserved = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET used=used+1 WHERE bucket_key=%s AND used<%d", $key, $limit
		) );
		if ( false === $reserved ) {
			return self::storage_error();
		}
		if ( 1 !== $reserved ) {
			return new \WP_Error( 'otai_budget_exhausted', __( 'Request limit reached. Try again after the current window resets.', 'omnithread-ai' ), array( 'status' => 429, 'retry_after' => max( 1, ( $bucket + 1 ) * $window - $now ) ) );
		}
		return true;
	}

	private static function storage_error() {
		$error = new \WP_Error( 'otai_budget_storage', __( 'Request limits cannot be verified right now. No model request was started. Please try again later.', 'omnithread-ai' ), array( 'status' => 503 ) );
		RAG::error( $error );
		return $error;
	}

	public static function provider( $id, $limit ) {
		global $wpdb;
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true;
		}
		// Seed from today's pre-upgrade calls, so enabling the table cannot reset usage.
		$initial = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Usage::table() . ' WHERE provider=%s AND created_at >= %s',
			$id, gmdate( 'Y-m-d 00:00:00' )
		) );
		if ( $wpdb->last_error ) {
			return self::storage_error();
		}
		$result = self::reserve( 'provider:' . $id, DAY_IN_SECONDS, $limit, (int) $initial );
		if ( is_wp_error( $result ) && 'otai_budget_exhausted' === $result->get_error_code() ) {
			return new \WP_Error( 'otai_provider_limit', __( 'This provider daily request-attempt limit has been reached. Failed requests and connection tests also consume the limit.', 'omnithread-ai' ), $result->get_error_data() );
		}
		return $result;
	}

	public static function purge() {
		global $wpdb;
		if ( false === $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY' ) ) {
			return self::storage_error();
		}
		return true;
	}
}
