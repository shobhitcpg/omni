<?php
/**
 * Plugin-owned vector indexes and permission-checked hybrid retrieval.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class RAG {

	public static $trace = array();

	public static function error( \WP_Error $error ) {
		update_option( 'otai_rag_error', array( 'time' => time(), 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ), false );
		self::$trace['degraded'] = $error->get_error_message();
	}

	public static function index() {
		return substr( Search_Engine::config()['index'], 0, 180 ) . '-rag-' . substr( Embeddings::profile(), 0, 16 );
	}

	public static function backend() {
		$key = 'otai_vector_backend_' . md5( Search_Engine::config()['url'] );
		$cached = get_transient( $key );
		if ( $cached ) {
			return $cached;
		}
		$info = Search_Engine::request( 'GET', '/' );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$distribution = $info['version']['distribution'] ?? 'elasticsearch';
		$version      = $info['version']['number'] ?? '';
		$minimum      = 'opensearch' === $distribution ? '2.4.0' : '8.0.0';
		if ( ! in_array( $distribution, array( 'opensearch', 'elasticsearch' ), true ) || ! $version || version_compare( $version, $minimum, '<' ) ) {
			return new \WP_Error( 'otai_vector_backend', __( 'Vector retrieval requires Elasticsearch 8+ or OpenSearch 2.4+ with Lucene k-NN support.', 'omnithread-ai' ) );
		}
		$result = array( 'distribution' => $distribution, 'version' => $version );
		set_transient( $key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	public static function mapping( $distribution, $dimensions ) {
		$vector = 'opensearch' === $distribution
			? array( 'type' => 'knn_vector', 'dimension' => (int) $dimensions, 'method' => array( 'name' => 'hnsw', 'engine' => 'lucene', 'space_type' => 'cosinesimil' ) )
			: array( 'type' => 'dense_vector', 'dims' => (int) $dimensions, 'index' => true, 'similarity' => 'cosine' );
		$mapping = array(
			'settings' => array( 'number_of_shards' => 1 ),
			'mappings' => array( 'properties' => array(
				'vector' => $vector, 'chunk_id' => array( 'type' => 'long' ),
				'object_id' => array( 'type' => 'long' ), 'object_type' => array( 'type' => 'keyword' ),
				'content_hash' => array( 'type' => 'keyword' ),
			) ),
		);
		if ( 'opensearch' === $distribution ) {
			$mapping['settings']['index.knn'] = true;
		}
		return $mapping;
	}

	public static function ensure_index() {
		$backend = self::backend();
		if ( is_wp_error( $backend ) ) {
			return $backend;
		}
		$index = rawurlencode( self::index() );
		$found = Search_Engine::request( 'GET', $index . '/_mapping' );
		if ( is_wp_error( $found ) ) {
			if ( 'otai_es_404' !== $found->get_error_code() ) {
				return $found;
			}
			$created = Search_Engine::request( 'PUT', $index, self::mapping( $backend['distribution'], Embeddings::config()['dimensions'] ), 15 );
			if ( is_wp_error( $created ) ) {
				// Concurrent creators may race; verify the actual mapping rather than assume success.
				$found = Search_Engine::request( 'GET', $index . '/_mapping' );
				if ( is_wp_error( $found ) ) {
					return $created;
				}
			} else {
				$found = Search_Engine::request( 'GET', $index . '/_mapping' );
			}
		}
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$vector = $found[ self::index() ]['mappings']['properties']['vector'] ?? array();
		$dimensions = $vector['dims'] ?? ( $vector['dimension'] ?? 0 );
		$type = 'opensearch' === $backend['distribution'] ? 'knn_vector' : 'dense_vector';
		if ( ( $vector['type'] ?? '' ) !== $type || (int) $dimensions !== (int) Embeddings::config()['dimensions'] ) {
			return new \WP_Error( 'otai_vector_mapping', __( 'The vector index has an incompatible type or dimensions. Do not reuse an index built with another embedding model.', 'omnithread-ai' ) );
		}
		return $backend;
	}

	private static function rows( $object_id ) {
		global $wpdb;
		$table = Knowledge_Base::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id=%d ORDER BY chunk_index,id", $object_id ), ARRAY_A );
		return $wpdb->last_error ? new \WP_Error( 'otai_vector_storage', __( 'Cannot read current passages for vector indexing.', 'omnithread-ai' ) ) : $rows;
	}

	/**
	 * Returns false for useful partial progress, true on completion, WP_Error on failure.
	 */
	public static function sync( $object_id ) {
		global $wpdb;
		$rows = self::rows( $object_id );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$fingerprint = hash( 'sha256', wp_json_encode( array( $rows ? array_map( function ( $row ) {
			return array( $row['id'], $row['content_hash'], $row['url'] );
		}, $rows ) : array(), Embeddings::profile(), Search_Engine::config()['url'], Search_Engine::config()['index'], Search_Engine::engine(), Settings::get( 'rag_retrieval' ) ) ) );
		$previous = $wpdb->get_var( $wpdb->prepare( 'SELECT synced_hash FROM ' . Index_Queue::table() . ' WHERE object_id=%d', $object_id ) );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'otai_index_manifest', __( 'Cannot read the document synchronisation manifest.', 'omnithread-ai' ) );
		}
		if ( $previous === $fingerprint ) {
			return true;
		}
		if ( 'keyword' !== Settings::get( 'rag_retrieval' ) ) {
			$policy = Embeddings::policy();
			if ( is_wp_error( $policy ) ) {
				return $policy;
			}
			$backend = self::ensure_index();
			if ( is_wp_error( $backend ) ) {
				return $backend;
			}
			$missing = array();
			foreach ( $rows as $row ) {
				$text   = $row['title'] . "\n" . $row['content'];
				$cached = Embeddings::cached( $text );
				if ( is_wp_error( $cached ) ) {
					return $cached;
				}
				if ( null === $cached ) {
					$missing[ Embeddings::cache_key( $text ) ] = $text;
				}
			}
			if ( $missing ) {
				$batch = array_slice( array_values( $missing ), 0, 8 );
				$vectors = Embeddings::embed( $batch );
				if ( is_wp_error( $vectors ) ) {
					return $vectors;
				}
				foreach ( $batch as $i => $text ) {
					$stored = Embeddings::store( $text, $vectors[ $i ] );
					if ( is_wp_error( $stored ) ) {
						return $stored;
					}
				}
				if ( count( $missing ) > 8 ) {
					return false;
				}
			}
			$ids = array();
			foreach ( array_chunk( $rows, 100 ) as $batch ) {
				$body = '';
				foreach ( $batch as $row ) {
					$vector = Embeddings::cached( $row['title'] . "\n" . $row['content'] );
					if ( ! is_array( $vector ) ) {
						return is_wp_error( $vector ) ? $vector : new \WP_Error( 'otai_vector_cache', __( 'An embedding was not persisted; retry the indexing job.', 'omnithread-ai' ) );
					}
					$ids[] = (string) $row['id'];
					$body .= wp_json_encode( array( 'index' => array( '_id' => (string) $row['id'] ) ) ) . "\n";
					$body .= wp_json_encode( array(
						'vector' => $vector, 'chunk_id' => (int) $row['id'], 'object_id' => (int) $row['object_id'],
						'object_type' => $row['object_type'], 'content_hash' => $row['content_hash'],
					) ) . "\n";
				}
				$response = Search_Engine::request( 'POST', rawurlencode( self::index() ) . '/_bulk?refresh=wait_for', $body, 30, true );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				if ( ! isset( $response['items'], $response['errors'] ) || count( $response['items'] ) !== count( $batch ) || $response['errors'] ) {
					return new \WP_Error( 'otai_vector_bulk', __( 'One or more vector writes failed. Check the backend and retry the job.', 'omnithread-ai' ) );
				}
			}
			$bool = array( 'filter' => array( array( 'term' => array( 'object_id' => (int) $object_id ) ) ) );
			if ( $ids ) {
				$bool['must_not'] = array( array( 'ids' => array( 'values' => $ids ) ) );
			}
			$deleted = Search_Engine::request( 'POST', rawurlencode( self::index() ) . '/_delete_by_query?conflicts=proceed&refresh=true', array( 'query' => array( 'bool' => $bool ) ), 15 );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			if ( ! isset( $deleted['deleted'] ) || ! empty( $deleted['failures'] ) || ! empty( $deleted['timed_out'] ) ) {
				return new \WP_Error( 'otai_vector_delete', __( 'Obsolete vector cleanup did not complete.', 'omnithread-ai' ) );
			}
		}
		if ( Search_Engine::syncing() ) {
			$written = Search_Engine::push( 0 === $object_id ? 'manual' : array( $object_id ) );
			if ( ! $written ) {
				return new \WP_Error( 'otai_keyword_sync', __( 'Keyword index update failed. See Search Engine diagnostics and retry.', 'omnithread-ai' ) );
			}
		}
		update_option( 'otai_last_indexed', time(), false );
		if ( false === $wpdb->update( Index_Queue::table(), array( 'synced_hash' => $fingerprint ), array( 'object_id' => $object_id ), array( '%s' ), array( '%d' ) ) ) {
			return new \WP_Error( 'otai_index_manifest', __( 'Cannot persist the document synchronisation manifest.', 'omnithread-ai' ) );
		}
		return true;
	}

	public static function vector_query( $distribution, array $vector, $count, array $types ) {
		$filter = array( 'terms' => array( 'object_type' => $types ) );
		if ( 'opensearch' === $distribution ) {
			return array( 'size' => $count, '_source' => array( 'chunk_id', 'content_hash' ),
				'query' => array( 'knn' => array( 'vector' => array( 'vector' => $vector, 'k' => $count, 'filter' => $filter ) ) ) );
		}
		return array( 'size' => $count, '_source' => array( 'chunk_id', 'content_hash' ),
			'knn' => array( 'field' => 'vector', 'query_vector' => $vector, 'k' => $count, 'num_candidates' => max( 100, $count * 2 ), 'filter' => $filter ) );
	}

	/**
	 * Never trust remote text, titles or URLs. Hydrate matching hashes from WordPress.
	 */
	public static function hydrate( array $candidates ) {
		global $wpdb;
		if ( ! $candidates ) {
			return array();
		}
		$ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $candidates, 'id' ) ) ) );
		$table = Knowledge_Base::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), ARRAY_A );
		if ( $wpdb->last_error ) {
			self::error( new \WP_Error( 'otai_retrieval_storage', __( 'Cannot verify retrieved passages against the current index.', 'omnithread-ai' ) ) );
			return array();
		}
		$by_id = array_column( $rows, null, 'id' );
		$out = array();
		foreach ( $candidates as $candidate ) {
			$row = $by_id[ $candidate['id'] ] ?? null;
			if ( ! $row || ( isset( $candidate['content_hash'] ) && $candidate['content_hash'] !== $row['content_hash'] ) ) {
				continue;
			}
			if ( $row['object_id'] ) {
				$post = get_post( $row['object_id'] );
				if ( ! Knowledge_Base::eligible( $post ) || ( $row['source_modified'] && $row['source_modified'] !== $post->post_modified_gmt ) ) {
					continue;
				}
			}
			$out[] = array_merge( $candidate, array(
				'id' => (int) $row['id'], 'type' => $row['object_type'], 'object_id' => (int) $row['object_id'],
				'title' => Knowledge_Base::decode( $row['title'] ), 'url' => $row['url'],
				'content' => $row['content'], 'content_hash' => $row['content_hash'],
			) );
		}
		return $out;
	}

	public static function fuse( array $lists, $constant = 60 ) {
		$rows = array();
		$scores = array();
		foreach ( $lists as $list ) {
			$seen = array();
			foreach ( array_values( $list ) as $rank => $row ) {
				$id = $row['id'];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$rows[ $id ] = isset( $rows[ $id ] ) ? array_merge( $row, $rows[ $id ] ) : $row;
				$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 1 / ( max( 1, $constant ) + $rank + 1 );
			}
		}
		arsort( $scores, SORT_NUMERIC );
		$out = array();
		foreach ( $scores as $id => $score ) {
			$rows[ $id ]['rrf'] = $score;
			$out[] = $rows[ $id ];
		}
		return $out;
	}

	public static function retrieve( $query, $limit = 4 ) {
		self::$trace = array( 'mode' => Settings::get( 'rag_retrieval' ), 'profile' => substr( Embeddings::profile(), 0, 16 ) );
		if ( $limit < 1 ) {
			return array();
		}
		$start = microtime( true );
		$count = (int) Settings::get( 'rag_candidates' );
		$keyword = self::hydrate( Knowledge_Base::search( $query, $count ) );
		$mode = Settings::get( 'rag_retrieval' );
		$rows = $keyword;
		if ( 'keyword' !== $mode ) {
			$semantic = self::semantic( $query, $count );
			if ( is_wp_error( $semantic ) ) {
				self::error( $semantic );
				if ( 'semantic' === $mode ) {
					return $semantic;
				}
			} else {
				$rows = 'hybrid' === $mode ? self::fuse( array( $keyword, $semantic ), (int) Settings::get( 'rag_rrf_constant' ) ) : $semantic;
			}
		}
		$selected = array();
		$per_source = array();
		foreach ( $rows as $row ) {
			$source = $row['object_id'] ? $row['type'] . ':' . $row['object_id'] : $row['type'] . ':' . $row['id'];
			$per_source[ $source ] = ( $per_source[ $source ] ?? 0 ) + 1;
			if ( $per_source[ $source ] <= 2 ) {
				$selected[] = $row;
			}
			if ( count( $selected ) >= $limit ) {
				break;
			}
		}
		self::$trace['keyword_candidates'] = count( $keyword );
		self::$trace['retrieval_ms'] = (int) round( 1000 * ( microtime( true ) - $start ) );
		return $selected;
	}

	private static function semantic( $query, $count ) {
		$policy = Embeddings::policy();
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		$backend = self::backend();
		if ( is_wp_error( $backend ) ) {
			return $backend;
		}
		$key = 'otai_qv_' . hash( 'sha256', Embeddings::profile() . '|' . Embeddings::input( $query ) );
		$vector = get_transient( $key );
		if ( ! $vector ) {
			$result = Embeddings::embed( array( $query ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$vector = $result[0];
			set_transient( $key, $vector, 10 * MINUTE_IN_SECONDS );
		}
		$valid = Embeddings::validate( $vector, Embeddings::config()['dimensions'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$types = array_merge( Knowledge_Base::indexable_post_types(), array( 'faq', 'custom' ) );
		$response = Search_Engine::request( 'POST', rawurlencode( self::index() ) . '/_search', self::vector_query( $backend['distribution'], $vector, $count, $types ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response['hits']['hits'] ) || ! empty( $response['timed_out'] ) || ! empty( $response['_shards']['failed'] ) ) {
			return new \WP_Error( 'otai_vector_response', __( 'Vector search returned incomplete results.', 'omnithread-ai' ) );
		}
		$hits = array();
		foreach ( $response['hits']['hits'] as $hit ) {
			if ( isset( $hit['_source']['chunk_id'], $hit['_source']['content_hash'] ) ) {
				// Similarity is not a probability or an extractive-answer confidence.
				$hits[] = array( 'id' => (int) $hit['_source']['chunk_id'], 'content_hash' => $hit['_source']['content_hash'], 'score' => 0.45, 'similarity' => $hit['_score'] );
			}
		}
		$rows = self::hydrate( $hits );
		self::$trace['semantic_candidates'] = count( $rows );
		return $rows;
	}

	public static function context( array $chunks ) {
		global $wpdb;
		$budget = (int) Settings::get( 'rag_context_chars' );
		$parent = (int) Settings::get( 'rag_parent_chars' );
		$out = array();
		$seen = array();
		foreach ( self::hydrate( array_filter( $chunks, function ( $c ) { return empty( $c['personal'] ); } ) ) as $chunk ) {
			if ( isset( $seen[ $chunk['id'] ] ) || $budget < 1 ) {
				continue;
			}
			$text = $chunk['content'];
			$seen[ $chunk['id'] ] = true;
			if ( $parent > 0 && $chunk['object_id'] ) {
				$table = Knowledge_Base::table();
				$nearby = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_id=%d AND object_type=%s AND chunk_index BETWEEN (SELECT chunk_index-1 FROM {$table} WHERE id=%d) AND (SELECT chunk_index+1 FROM {$table} WHERE id=%d) ORDER BY chunk_index",
					$chunk['object_id'], $chunk['type'], $chunk['id'], $chunk['id']
				), ARRAY_A );
				if ( $wpdb->last_error ) {
					self::error( new \WP_Error( 'otai_parent_storage', __( 'Cannot expand neighbouring passages.', 'omnithread-ai' ) ) );
				} else {
					$text = $chunk['content'];
					foreach ( self::hydrate( $nearby ) as $neighbour ) {
						if ( $neighbour['id'] !== $chunk['id'] && ! isset( $seen[ $neighbour['id'] ] ) && mb_strlen( $text . "\n\n" . $neighbour['content'] ) <= $parent ) {
							$text .= "\n\n" . $neighbour['content'];
							$seen[ $neighbour['id'] ] = true;
						}
					}
				}
			}
			$chunk['content'] = mb_substr( $text, 0, $budget );
			$budget -= mb_strlen( $chunk['content'] );
			$out[] = $chunk;
		}
		// Authenticated live data is supplied by trusted integrations, not vector search.
		foreach ( $chunks as $chunk ) {
			if ( ! empty( $chunk['personal'] ) && $budget > 0 ) {
				$chunk['content'] = mb_substr( $chunk['content'], 0, $budget );
				$budget -= mb_strlen( $chunk['content'] );
				$out[] = $chunk;
			}
		}
		return $out;
	}

	public static function guard_answer( $text, array $chunks ) {
		$allowed = array();
		foreach ( array_values( $chunks ) as $i => $chunk ) {
			$allowed[ $i + 1 ] = $chunk['url'] ?? '';
		}
		$invalid = false;
		$text = preg_replace_callback( '/\[([^\]]+)\]\(([^)\s]+)\)/u', function ( $m ) use ( $allowed, &$invalid ) {
			if ( ! in_array( $m[2], $allowed, true ) || ! preg_match( '#^https?://#i', $m[2] ) ) {
				$invalid = true;
				return $m[1];
			}
			return $m[0];
		}, $text );
		$text = preg_replace_callback( '/\[(\d+)\](?!\()/u', function ( $m ) use ( $allowed, &$invalid ) {
			$id = (int) $m[1];
			if ( ! array_key_exists( $id, $allowed ) ) {
				$invalid = true;
				return '';
			}
			return $allowed[ $id ] ? '[' . $id . '](' . $allowed[ $id ] . ')' : $m[0];
		}, $text );
		if ( $invalid ) {
			self::error( new \WP_Error( 'otai_citation', __( 'The answer contained an unsupported citation or link; it was removed.', 'omnithread-ai' ) ) );
		}
		return Settings::get( 'redact_pii' ) ? Chat_Service::redact( $text ) : $text;
	}
}
