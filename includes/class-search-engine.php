<?php
/**
 * Optional Elasticsearch / OpenSearch retrieval.
 *
 * Engines, in "auto" order:
 *  1. ElasticPress / WP VIP Enterprise Search already running on the site – reused, nothing to index.
 *  2. Your own (free, open-source) Elasticsearch or OpenSearch server – the plugin keeps its own index in sync.
 *  3. The built-in MySQL search (always available, also used as a fallback when a search server is down).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Search_Engine {

	const DOWN_KEY  = 'otai_es_down';
	const ERROR_KEY = 'otai_es_last_error';

	/**
	 * Name of the engine used by the last search() call (for diagnostics).
	 *
	 * @var string
	 */
	public static $last_engine = 'database';

	/**
	 * Which engine should answer searches right now: elasticpress | elasticsearch | database.
	 */
	public static function engine() {
		$choice = (string) Settings::get( 'search_engine', 'auto' );
		if ( 'database' === $choice ) {
			return 'database';
		}
		if ( in_array( $choice, array( 'auto', 'elasticpress' ), true ) && self::elasticpress()['available'] ) {
			return 'elasticpress';
		}
		if ( in_array( $choice, array( 'auto', 'elasticsearch' ), true ) && self::custom_configured() ) {
			return 'elasticsearch';
		}
		return 'database';
	}

	public static function label( $engine ) {
		switch ( $engine ) {
			case 'elasticpress':
				return self::elasticpress()['label'];
			case 'elasticsearch':
				return __( 'Elasticsearch / OpenSearch (own server)', 'omnithread-ai' );
		}
		return get_option( 'otai_fulltext' ) ? __( 'Database (MySQL FULLTEXT)', 'omnithread-ai' ) : __( 'Database (keyword search)', 'omnithread-ai' );
	}

	/* ---------------------------------------------------------------------
	 * ElasticPress / VIP Enterprise Search detection
	 * ------------------------------------------------------------------- */

	/**
	 * @return array available (bool), label, message.
	 */
	public static function elasticpress() {
		static $status = null;
		if ( null !== $status ) {
			return $status;
		}
		$vip   = class_exists( '\\Automattic\\VIP\\Search\\Search' ) || ( defined( 'VIP_ENABLE_VIP_SEARCH' ) && VIP_ENABLE_VIP_SEARCH );
		$label = $vip ? __( 'WP VIP Enterprise Search (ElasticPress)', 'omnithread-ai' ) : __( 'ElasticPress', 'omnithread-ai' );

		if ( ! function_exists( '\\ElasticPress\\Utils\\get_host' ) || ! class_exists( '\\ElasticPress\\Indexables' ) ) {
			$status = array(
				'available' => false,
				'label'     => $label,
				'message'   => $vip
					? __( 'Enterprise Search is enabled in the site config but is not running in this environment (normal on local/dev copies). It will be used automatically where it runs.', 'omnithread-ai' )
					: __( 'Not detected.', 'omnithread-ai' ),
				'detected'  => $vip,
			);
			return $status;
		}
		$host    = (string) \ElasticPress\Utils\get_host();
		$indexed = (bool) \ElasticPress\Indexables::factory()->get( 'post' );
		$ok      = '' !== $host && $indexed;
		$status  = array(
			'available' => (bool) apply_filters( 'otai_use_elasticpress', $ok ),
			'label'     => $label,
			'message'   => $ok
				? __( 'Detected and connected. Its index of your posts is reused – nothing extra to set up.', 'omnithread-ai' )
				: __( 'Installed, but not connected to an Elasticsearch host or the posts index is disabled.', 'omnithread-ai' ),
			'detected'  => true,
		);
		return $status;
	}

	/**
	 * Rank posts with ElasticPress, then map them to our passages.
	 *
	 * @return array[]|null Rows (id, object_type, object_id, title, url, content, es) or null if ES was not used.
	 */
	private static function search_elasticpress( $query, $types ) {
		global $wpdb;
		$post_types = self::post_types_filter( $types );
		if ( ! $post_types ) {
			return null;
		}
		$q = new \WP_Query(
			array(
				's'                      => $query,
				'ep_integrate'           => true,
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => (int) apply_filters( 'otai_elasticpress_limit', 10 ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		// ElasticPress sets this flag only when Elasticsearch actually served the query.
		if ( empty( $q->elasticsearch_success ) ) {
			return null;
		}
		$ids = array_map( 'intval', (array) $q->posts );
		if ( ! $ids ) {
			return array();
		}
		$rank = array();
		$n    = count( $ids );
		foreach ( $ids as $i => $id ) {
			$rank[ $id ] = 1 - ( $i / $n );
		}
		$table = Knowledge_Base::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, title, url, content FROM {$table} WHERE object_type NOT IN ('faq','custom') AND object_id IN (" . implode( ',', array_fill( 0, $n, '%d' ) ) . ')', $ids ), ARRAY_A );
		foreach ( (array) $rows as $k => $row ) {
			$rows[ $k ]['es'] = $rank[ (int) $row['object_id'] ];
		}
		return (array) $rows;
	}

	private static function post_types_filter( $types ) {
		$post_types = Knowledge_Base::indexable_post_types();
		if ( is_array( $types ) && $types ) {
			$post_types = array_values( array_intersect( $post_types, $types ) );
		}
		return $post_types;
	}

	/* ---------------------------------------------------------------------
	 * Own Elasticsearch / OpenSearch server
	 * ------------------------------------------------------------------- */

	/**
	 * Resolved connection settings; wp-config constants win (OTAI_ES_URL, OTAI_ES_INDEX, OTAI_ES_USERNAME, OTAI_ES_PASSWORD, OTAI_ES_API_KEY).
	 */
	public static function config() {
		$config = array(
			'url'        => (string) Settings::get( 'es_url', '' ),
			'index'      => (string) Settings::get( 'es_index', '' ),
			'auth'       => (string) Settings::get( 'es_auth', 'none' ),
			'username'   => (string) Settings::get( 'es_username', '' ),
			'password'   => Crypto::decrypt( (string) Settings::get( 'es_password', '' ) ),
			'api_key'    => Crypto::decrypt( (string) Settings::get( 'es_api_key', '' ) ),
			'verify_ssl' => (bool) Settings::get( 'es_verify_ssl', 1 ),
		);
		foreach ( array( 'url', 'index', 'username', 'password', 'api_key' ) as $key ) {
			$const = 'OTAI_ES_' . strtoupper( $key );
			if ( defined( $const ) ) {
				$config[ $key ] = (string) constant( $const );
			}
		}
		if ( defined( 'OTAI_ES_API_KEY' ) ) {
			$config['auth'] = 'api_key';
		} elseif ( defined( 'OTAI_ES_USERNAME' ) ) {
			$config['auth'] = 'basic';
		}
		$config['url'] = untrailingslashit( $config['url'] );
		if ( '' === $config['index'] ) {
			$config['index'] = self::default_index();
		}
		return $config;
	}

	public static function default_index() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name = 'otai-' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ) . '-' . get_current_blog_id();
		return substr( trim( $name, '-' ), 0, 200 );
	}

	public static function custom_configured() {
		return '' !== self::config()['url'];
	}

	/**
	 * Whether the plugin should push its passages to the own-server index.
	 */
	public static function syncing() {
		return 'elasticsearch' === self::engine();
	}

	/**
	 * @return array|\WP_Error Decoded JSON (or [] for empty bodies).
	 */
	public static function request( $method, $path, $body = null, $timeout = 5, $ndjson = false ) {
		$config = self::config();
		if ( '' === $config['url'] ) {
			return new \WP_Error( 'otai_es_config', __( 'Elasticsearch URL is not set.', 'omnithread-ai' ) );
		}
		$headers = array( 'Content-Type' => $ndjson ? 'application/x-ndjson' : 'application/json' );
		if ( 'api_key' === $config['auth'] && '' !== $config['api_key'] ) {
			$headers['Authorization'] = 'ApiKey ' . $config['api_key'];
		} elseif ( 'basic' === $config['auth'] && '' !== $config['username'] ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
			$headers['Authorization'] = 'Basic ' . base64_encode( $config['username'] . ':' . $config['password'] );
		}
		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'headers'    => $headers,
			'sslverify'  => $config['verify_ssl'],
			'user-agent' => 'OmniThreadAI/' . OTAI_VERSION,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}
		$response = wp_remote_request( $config['url'] . '/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'otai_es_http', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$reason = is_array( $data ) && isset( $data['error']['reason'] ) ? $data['error']['reason'] : wp_html_excerpt( wp_strip_all_tags( $raw ), 200, '…' );
			return new \WP_Error( 'otai_es_' . $code, sprintf( 'HTTP %d: %s', $code, $reason ), array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'otai_es_json', __( 'Search server returned invalid JSON.', 'omnithread-ai' ) );
		}
		return $data;
	}

	/**
	 * Connection test.
	 *
	 * @return array|\WP_Error distribution, version, index_exists, docs.
	 */
	public static function ping() {
		$info = self::request( 'GET', '/', null, 8 );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( empty( $info['version']['number'] ) ) {
			return new \WP_Error( 'otai_es_unknown', __( 'The server responded, but it does not look like Elasticsearch or OpenSearch.', 'omnithread-ai' ) );
		}
		$index = rawurlencode( self::config()['index'] );
		$count = self::request( 'GET', $index . '/_count', null, 8 );
		if ( is_wp_error( $count ) && 'otai_es_404' !== $count->get_error_code() ) {
			return $count;
		}
		return array(
			'distribution' => isset( $info['version']['distribution'] ) ? $info['version']['distribution'] : 'elasticsearch',
			'version'      => $info['version']['number'],
			'index'        => self::config()['index'],
			'index_exists' => ! is_wp_error( $count ),
			'docs'         => is_wp_error( $count ) ? 0 : (int) $count['count'],
		);
	}

	private static function mapping() {
		$text = array(
			'type'     => 'text',
			'analyzer' => 'otai_text',
		);
		return apply_filters(
			'otai_es_index_body',
			array(
				'settings' => array(
					'number_of_shards'   => 1,
					'number_of_replicas' => 0,
					'analysis'           => array(
						'analyzer' => array(
							'otai_text' => array(
								'type'      => 'custom',
								'tokenizer' => 'standard',
								'filter'    => array( 'lowercase', 'asciifolding', 'otai_stop', 'otai_stem' ),
							),
						),
						'filter'   => array(
							'otai_stop' => array(
								'type'      => 'stop',
								'stopwords' => '_english_',
							),
							'otai_stem' => array(
								'type'     => 'stemmer',
								'language' => 'light_english',
							),
						),
					),
				),
				'mappings' => array(
					'properties' => array(
						'chunk_id'    => array( 'type' => 'long' ),
						'object_type' => array( 'type' => 'keyword' ),
						'object_id'   => array( 'type' => 'long' ),
						'title'       => $text,
						'content'     => $text,
						'url'         => array(
							'type'  => 'keyword',
							'index' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Drop and recreate the index (start of a full rebuild).
	 */
	public static function recreate_index() {
		$index = rawurlencode( self::config()['index'] );
		self::request( 'DELETE', $index, null, 15 );
		$result = self::request( 'PUT', $index, self::mapping(), 15 );
		return self::track( $result, true );
	}

	/**
	 * Push the current DB passages of the given posts (or 'manual' FAQ/custom entries) to the index.
	 *
	 * @param int[]|string $objects Post IDs, or 'manual'.
	 * @param bool         $replace Delete existing docs for these objects first.
	 */
	public static function push( $objects, $replace = true ) {
		global $wpdb;
		if ( ! self::ready_to_sync() ) {
			return false;
		}
		$table = Knowledge_Base::table();
		if ( 'manual' === $objects ) {
			$filter = array( 'terms' => array( 'object_type' => array( 'faq', 'custom' ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT id, object_type, object_id, title, url, content FROM {$table} WHERE object_type IN ('faq','custom')", ARRAY_A );
		} else {
			$ids = array_values( array_filter( array_map( 'intval', (array) $objects ) ) );
			if ( ! $ids ) {
				return true;
			}
			$filter = array(
				'bool' => array(
					'filter'   => array( array( 'terms' => array( 'object_id' => $ids ) ) ),
					'must_not' => array( array( 'terms' => array( 'object_type' => array( 'faq', 'custom' ) ) ) ),
				),
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, title, url, content FROM {$table} WHERE object_type NOT IN ('faq','custom') AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), ARRAY_A );
		}
		$index = self::config()['index'];
		if ( $replace && ! self::track( self::delete_where( $filter ), true ) ) {
			return false;
		}
		if ( ! $rows ) {
			return true;
		}
		$ndjson = '';
		foreach ( $rows as $row ) {
			$ndjson .= wp_json_encode(
				array(
					'index' => array(
						'_index' => $index,
						'_id'    => (string) $row['id'],
					),
				)
			) . "\n";
			$ndjson .= wp_json_encode(
				array(
					'chunk_id'    => (int) $row['id'],
					'object_type' => $row['object_type'],
					'object_id'   => (int) $row['object_id'],
					'title'       => $row['title'],
					'url'         => $row['url'],
					'content'     => $row['content'],
				)
			) . "\n";
		}
		$result = self::request( 'POST', '_bulk', $ndjson, 30, true );
		if ( ! is_wp_error( $result ) && ( ! isset( $result['errors'], $result['items'] ) || count( $result['items'] ) !== count( $rows ) || ! empty( $result['errors'] ) ) ) {
			$reason = __( 'Some passages could not be indexed.', 'omnithread-ai' );
			foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
				if ( isset( $item['index']['error']['reason'] ) ) {
					$reason = $item['index']['error']['reason'];
					break;
				}
			}
			$result = new \WP_Error( 'otai_es_bulk', $reason );
		}
		return self::track( $result, true );
	}

	public static function remove( $post_id ) {
		if ( ! self::ready_to_sync() ) {
			return;
		}
		self::track(
			self::delete_where(
				array(
					'bool' => array(
						'filter'   => array( array( 'term' => array( 'object_id' => (int) $post_id ) ) ),
						'must_not' => array( array( 'terms' => array( 'object_type' => array( 'faq', 'custom' ) ) ) ),
					),
				)
			),
			true
		);
	}

	/**
	 * Own-server engine active and reachable; if it is paused after an error, flag the index as needing a rebuild.
	 */
	private static function ready_to_sync() {
		if ( ! self::syncing() ) {
			return false;
		}
		if ( self::is_down() ) {
			update_option( 'otai_es_stale', 1, false );
			return false;
		}
		return true;
	}

	public static function refresh() {
		if ( self::syncing() && ! self::is_down() ) {
			self::track( self::request( 'POST', rawurlencode( self::config()['index'] ) . '/_refresh', null, 15 ) );
		}
	}

	private static function delete_where( array $filter ) {
		$result = self::request( 'POST', rawurlencode( self::config()['index'] ) . '/_delete_by_query?refresh=true&conflicts=proceed', array( 'query' => $filter ), 15 );
		// A missing index simply has nothing to delete.
		if ( is_wp_error( $result ) && 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			$result = self::request( 'PUT', rawurlencode( self::config()['index'] ), self::mapping(), 15 );
		}
		if ( ! is_wp_error( $result ) && ( ! empty( $result['failures'] ) || ! empty( $result['timed_out'] ) ) ) {
			return new \WP_Error( 'otai_es_delete', __( 'Keyword index cleanup did not complete.', 'omnithread-ai' ) );
		}
		return $result;
	}

	/**
	 * @return array[]|null Rows or null if the server could not be used.
	 */
	private static function search_custom( $query, $types ) {
		$must = array(
			'multi_match' => array(
				'query'     => $query,
				'fields'    => array( 'title^2', 'content' ),
				'type'      => 'best_fields',
				'fuzziness' => 'AUTO',
				'operator'  => 'or',
			),
		);
		$bool = array(
			'must'   => array( $must ),
			'should' => array(
				array(
					'match_phrase' => array(
						'content' => array(
							'query' => $query,
							'slop'  => 2,
							'boost' => 2,
						),
					),
				),
			),
		);
		if ( is_array( $types ) && $types ) {
			$bool['filter'] = array( array( 'terms' => array( 'object_type' => array_values( $types ) ) ) );
		}
		$result = self::request(
			'POST',
			rawurlencode( self::config()['index'] ) . '/_search',
			array(
				'size'    => 40,
				'query'   => array( 'bool' => $bool ),
				'_source' => array( 'chunk_id', 'object_type', 'object_id', 'title', 'url', 'content' ),
			),
			(int) apply_filters( 'otai_es_search_timeout', 4 )
		);
		if ( ! self::track( $result ) || ! isset( $result['hits']['hits'] ) ) {
			return null;
		}
		$hits = $result['hits']['hits'];
		$max  = $hits ? max( 0.0001, (float) $hits[0]['_score'] ) : 1;
		$rows = array();
		foreach ( $hits as $hit ) {
			$src    = $hit['_source'];
			$rows[] = array(
				'id'          => (int) $src['chunk_id'],
				'object_type' => $src['object_type'],
				'object_id'   => (int) $src['object_id'],
				'title'       => $src['title'],
				'url'         => $src['url'],
				'content'     => $src['content'],
				'es'          => (float) $hit['_score'] / $max,
			);
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Shared
	 * ------------------------------------------------------------------- */

	/**
	 * Candidate rows from the active search engine.
	 *
	 * @return array{rows: array[], complete: bool}|null complete = rows cover every passage type (no DB search needed).
	 */
	public static function candidates( $query, $types = null ) {
		$engine = self::engine();
		if ( 'database' === $engine || self::is_down() ) {
			return null;
		}
		$rows = 'elasticpress' === $engine ? self::search_elasticpress( $query, $types ) : self::search_custom( $query, $types );
		if ( null === $rows ) {
			return null;
		}
		self::$last_engine = $engine;
		return array(
			'rows'     => $rows,
			'complete' => 'elasticsearch' === $engine,
		);
	}

	/**
	 * Remember failures and pause calls for a few minutes so a dead server never slows the site down.
	 *
	 * @param mixed $result Request result.
	 * @param bool  $write  A failed write leaves the index out of date.
	 * @return bool True on success.
	 */
	private static function track( $result, $write = false ) {
		if ( is_wp_error( $result ) ) {
			if ( $write ) {
				update_option( 'otai_es_stale', 1, false );
			}
			update_option(
				self::ERROR_KEY,
				array(
					'time'    => time(),
					'message' => $result->get_error_message(),
				),
				false
			);
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			// Only network errors / 5xx mean the server is unavailable; 4xx are request problems.
			if ( 0 === $status || $status >= 500 ) {
				set_transient( self::DOWN_KEY, 1, (int) apply_filters( 'otai_es_retry_after', 5 * MINUTE_IN_SECONDS ) );
			}
			return false;
		}
		return true;
	}

	public static function is_down() {
		return (bool) get_transient( self::DOWN_KEY );
	}

	public static function reset_health() {
		delete_transient( self::DOWN_KEY );
		delete_option( self::ERROR_KEY );
		delete_option( 'otai_es_stale' );
	}

	public static function is_stale() {
		return self::syncing() && (bool) get_option( 'otai_es_stale' );
	}

	public static function last_error() {
		$error = get_option( self::ERROR_KEY );
		return is_array( $error ) ? $error : null;
	}
}
