<?php
/**
 * Anonymous usage analytics (tokens, latency, answer source) and daily provider caps.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Usage {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'otai_usage';
	}

	public static function log( array $row ) {
		global $wpdb;
		$row = wp_parse_args(
			$row,
			array(
				'provider'      => '',
				'model'         => '',
				'answer_source' => '',
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'latency_ms'    => 0,
				'success'       => 1,
				'error'         => '',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'provider'      => substr( (string) $row['provider'], 0, 40 ),
				'model'         => substr( (string) $row['model'], 0, 191 ),
				'answer_source' => substr( (string) $row['answer_source'], 0, 20 ),
				'input_tokens'  => absint( $row['input_tokens'] ),
				'output_tokens' => absint( $row['output_tokens'] ),
				'latency_ms'    => absint( $row['latency_ms'] ),
				'success'       => $row['success'] ? 1 : 0,
				'error'         => substr( (string) $row['error'], 0, 255 ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
		wp_cache_delete( 'otai_today_' . $row['provider'], 'otai' );
	}

	public static function count_today( $provider ) {
		global $wpdb;
		$cache_key = 'otai_today_' . $provider;
		$count     = wp_cache_get( $cache_key, 'otai' );
		if ( false === $count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE provider = %s AND success = 1 AND created_at >= %s', $provider, gmdate( 'Y-m-d 00:00:00' ) ) );
			wp_cache_set( $cache_key, $count, 'otai', 60 );
		}
		return (int) $count;
	}

	/**
	 * Aggregated stats for the dashboard.
	 */
	public static function summary( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) );
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS requests, SUM(success) AS ok, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, AVG(latency_ms) AS latency FROM {$table} WHERE created_at >= %s", $since ), ARRAY_A );
		$by_source = $wpdb->get_results( $wpdb->prepare( "SELECT answer_source, COUNT(*) AS c FROM {$table} WHERE created_at >= %s AND success = 1 GROUP BY answer_source", $since ), ARRAY_A );
		$by_prov   = $wpdb->get_results( $wpdb->prepare( "SELECT provider, model, COUNT(*) AS requests, SUM(success) AS ok, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, AVG(latency_ms) AS latency FROM {$table} WHERE created_at >= %s AND provider <> '' GROUP BY provider, model ORDER BY requests DESC", $since ), ARRAY_A );
		$errors    = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, provider, error FROM {$table} WHERE created_at >= %s AND success = 0 ORDER BY id DESC LIMIT 10", $since ), ARRAY_A );
		// phpcs:enable
		$sources = array();
		foreach ( (array) $by_source as $row ) {
			$sources[ $row['answer_source'] ] = (int) $row['c'];
		}
		return array(
			'totals'      => $totals ? $totals : array(),
			'sources'     => $sources,
			'providers'   => (array) $by_prov,
			'last_errors' => (array) $errors,
		);
	}

	public static function purge( $days = 365 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $days ) ) );
	}
}
