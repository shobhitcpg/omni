<?php
/**
 * Site knowledge base: indexes content into chunks and retrieves the most relevant ones.
 * Uses MySQL FULLTEXT when available, falling back to keyword LIKE search – works on any host, zero cost.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Knowledge_Base {

	const CHUNK_SIZE = 1200;

	private static $stopwords = array( 'a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'am', 'do', 'does', 'did', 'to', 'of', 'in', 'on', 'at', 'for', 'with', 'by', 'from', 'as', 'it', 'its', 'this', 'that', 'these', 'those', 'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she', 'they', 'them', 'their', 'what', 'which', 'who', 'whom', 'how', 'when', 'where', 'why', 'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'must', 'have', 'has', 'had', 'there', 'here', 'about', 'please', 'tell', 'know', 'want', 'need', 'any', 'some', 'so', 'if', 'then', 'than', 'too', 'very', 'just', 'also', 'not', 'no', 'yes', 'hi', 'hello', 'hey', 'thanks', 'thank', 'get', 'give', 'show', 'find', 'us', 'much', 'many', 'more', 'most', 'into', 'up', 'out', 'over', 'all', 'each', 'other', 'such', 'only', 'own', 'same', 'like' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'otai_knowledge';
	}

	public static function register_hooks() {
		add_action( 'wp_after_insert_post', array( 'OmniThreadAI\\Index_Queue', 'on_post' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'remove_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'remove_post' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( 'OmniThreadAI\\Index_Queue', 'on_meta' ), 20, 2 );
		}
		add_action( 'set_object_terms', array( 'OmniThreadAI\\Index_Queue', 'on_terms' ), 20 );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'on_settings_update' ), 10, 2 );
	}

	public static function on_settings_update( $old, $new ) {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		foreach ( array( 'post_types', 'rag_retrieval', 'rag_embed_provider', 'rag_embed_model', 'rag_embed_url', 'rag_embed_dimensions', 'rag_chunk_chars', 'redact_pii' ) as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				update_option( 'otai_needs_index', 1, false );
				break;
			}
		}
		foreach ( array( 'search_engine', 'es_url', 'es_index', 'es_auth', 'es_username', 'es_password', 'es_api_key', 'es_verify_ssl' ) as $key ) {
			if ( ( isset( $old[ $key ] ) ? $old[ $key ] : null ) !== ( isset( $new[ $key ] ) ? $new[ $key ] : null ) ) {
				Settings::flush();
				Search_Engine::reset_health();
				if ( Search_Engine::syncing() ) {
					update_option( 'otai_needs_index', 1, false );
				}
				break;
			}
		}
		foreach ( array( 'faq', 'custom_knowledge' ) as $key ) {
			$before = isset( $old[ $key ] ) ? $old[ $key ] : '';
			$after  = isset( $new[ $key ] ) ? $new[ $key ] : '';
			if ( $before !== $after ) {
				Settings::flush();
				self::index_manual_entries();
				return;
			}
		}
	}

	public static function indexable_post_types() {
		$types = array_filter( (array) Settings::get( 'post_types', array() ), 'post_type_exists' );
		return apply_filters( 'otai_indexable_post_types', array_values( $types ) );
	}

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::indexable_post_types(), true ) ) {
			return;
		}
		Index_Queue::on_post( $post_id, $post );
	}

	public static function eligible( $post ) {
		if ( ! $post ) {
			return false;
		}
		$post = get_post( $post );
		return $post && 'publish' === $post->post_status && '' === $post->post_password
			&& in_array( $post->post_type, self::indexable_post_types(), true )
			&& is_post_publicly_viewable( $post )
			&& (bool) apply_filters( 'otai_index_post_allowed', Access_Policy::default_allowed(), $post );
	}

	/**
	 * @param int|\WP_Post $post Post.
	 * @param bool         $sync Also push to the Elasticsearch index (batched separately during full rebuilds).
	 */
	public static function index_post( $post, $sync = true ) {
		global $wpdb;
		$post_id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		$post = get_post( $post );
		if ( ! $post ) {
			return self::delete_post_rows( $post_id );
		}
		if ( ! self::eligible( $post ) ) {
			return self::delete_post_rows( $post->ID );
		}
		$text = self::post_text( $post );
		if ( mb_strlen( $text ) > (int) Settings::get( 'rag_document_chars' ) ) {
			return new \WP_Error( 'otai_document_size', __( 'Document exceeds the configured indexing size limit. Increase the limit or split the document.', 'omnithread-ai' ) );
		}
		$rows = array();
		foreach ( self::stable_chunks( $text ) as $index => $chunk ) {
			$rows[] = array(
				'object_type' => $post->post_type, 'object_id' => $post->ID,
				'chunk_index' => $index, 'title' => self::plain_title( $post ),
				'url' => (string) get_permalink( $post ), 'content' => $chunk,
				'source_modified' => $post->post_modified_gmt,
			);
		}
		$result = self::replace_rows( $post->post_type, $post->ID, $rows );
		if ( $sync && ! is_wp_error( $result ) ) {
			Index_Queue::enqueue( $post->ID );
		}
		return $result;
	}

	public static function remove_post( $post_id ) {
		self::delete_post_rows( $post_id );
		Index_Queue::enqueue( $post_id );
	}

	private static function delete_post_rows( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE object_id = %d AND object_type NOT IN ('faq','custom')", $post_id ) );
		if ( false === $result ) {
			$error = new \WP_Error( 'otai_index_delete', __( 'Cannot remove obsolete knowledge passages.', 'omnithread-ai' ) );
			RAG::error( $error );
			return $error;
		}
		return $result;
	}

	/**
	 * Paragraph boundaries make unchanged sections independent of earlier edits.
	 */
	public static function stable_chunks( $text ) {
		$out = array();
		foreach ( preg_split( "/\n{2,}/", trim( $text ) ) as $paragraph ) {
			if ( '' !== trim( $paragraph ) ) {
				$out = array_merge( $out, self::chunk( $paragraph, (int) Settings::get( 'rag_chunk_chars', self::CHUNK_SIZE ) ) );
			}
		}
		return $out;
	}

	private static function replace_rows( $type, $object_id, array $rows ) {
		global $wpdb;
		$table = self::table();
		$old = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_type=%s AND object_id=%d ORDER BY id", $type, $object_id ), ARRAY_A );
		if ( $wpdb->last_error || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'otai_index_storage', __( 'Cannot read or transact the knowledge index.', 'omnithread-ai' ) );
		}
		$pool = array();
		foreach ( $old as $row ) {
			$hash = hash( 'sha256', $row['title'] . "\n" . $row['content'] );
			$pool[ $hash ][] = $row;
		}
		foreach ( $rows as $row ) {
			$hash = hash( 'sha256', $row['title'] . "\n" . $row['content'] );
			$row['content_hash'] = $hash;
			$row['updated_at'] = current_time( 'mysql', true );
			if ( ! empty( $pool[ $hash ] ) ) {
				$existing = array_shift( $pool[ $hash ] );
				$changed = false;
				foreach ( $row as $key => $value ) {
					if ( 'updated_at' !== $key && (string) $existing[ $key ] !== (string) $value ) {
						$changed = true;
					}
				}
				$result = $changed ? $wpdb->update( $table, $row, array( 'id' => $existing['id'] ) ) : 0;
			} else {
				$result = $wpdb->insert( $table, $row );
			}
			if ( false === $result ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'otai_index_storage', __( 'Cannot persist knowledge passages. The previous index was retained.', 'omnithread-ai' ) );
			}
		}
		foreach ( $pool as $remaining ) {
			foreach ( $remaining as $row ) {
				if ( false === $wpdb->delete( $table, array( 'id' => $row['id'] ), array( '%d' ) ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'otai_index_storage', __( 'Cannot delete replaced knowledge passages.', 'omnithread-ai' ) );
				}
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new \WP_Error( 'otai_index_storage', __( 'Cannot commit the knowledge index.', 'omnithread-ai' ) );
		}
		return count( $rows );
	}

	/**
	 * Plain-text title: get_the_title() returns texturized HTML entities (e.g. "&#8211;").
	 */
	public static function plain_title( $post ) {
		return self::decode( wp_strip_all_tags( get_the_title( $post ) ) );
	}

	public static function decode( $text ) {
		return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	public static function post_text( \WP_Post $post ) {
		// Block comments and markup are stripped by clean(); dynamic blocks render nothing and are skipped.
		$content = strip_shortcodes( $post->post_content );
		$parts   = array( self::plain_title( $post ) );
		if ( '' !== $post->post_excerpt ) {
			$parts[] = $post->post_excerpt;
		}
		$parts[] = $content;

		$terms = wp_get_post_terms( $post->ID, get_object_taxonomies( $post->post_type ), array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			$parts[] = 'Topics: ' . implode( ', ', $terms );
		}

		$text = implode( "\n\n", $parts );
		/**
		 * Filter the text indexed for a post (WooCommerce integration appends price, SKU, stock…).
		 */
		$text = apply_filters( 'otai_index_post_text', $text, $post );
		return self::clean( $text );
	}

	public static function clean( $text ) {
		$text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $text );
		$text = preg_replace_callback( '#<table\b[^>]*>(.*?)</table>#is', array( __CLASS__, 'table_to_text' ), $text );
		$text = preg_replace( '#<li\b[^>]*>#i', "\n- ", $text );
		$text = preg_replace( '#</(p|h[1-6]|section|article)\s*>#i', "\n\n", $text );
		$text = preg_replace( '#</(p|div|li|ul|ol|dl|dt|dd|h[1-6]|tr|section|article|aside|header|footer|nav|main|figure|figcaption|blockquote|details|summary|button|label|option|pre)\s*>|<br\s*/?>|<hr\b[^>]*>#i', "\n", $text );
		$text = preg_replace( '#</t[dh]\s*>#i', ' ', $text );
		// Any other tag boundary becomes a space so adjacent words never merge ("Title?Answer").
		$text = preg_replace( '#<(?!/?(a|abbr|b|code|em|i|mark|small|span|strong|sub|sup|u)\b)[^>]*>#i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = preg_replace( '/ +([,.;:!?])/u', '$1', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n\s*\n+/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Turn an HTML table into one "- Header: value; Header: value" line per row, so each row stays
	 * meaningful on its own (for retrieval, extractive answers and the LLM context).
	 */
	private static function table_to_text( $match ) {
		preg_match_all( '#<tr\b[^>]*>(.*?)</tr>#is', $match[1], $rows );
		$headers = array();
		$lines   = array();
		foreach ( $rows[1] as $i => $row ) {
			preg_match_all( '#<(t[hd])\b[^>]*>(.*?)</t[hd]>#is', $row, $cells, PREG_SET_ORDER );
			if ( ! $cells ) {
				continue;
			}
			$values = array();
			$all_th = true;
			foreach ( $cells as $cell ) {
				$all_th   = $all_th && 'th' === strtolower( $cell[1] );
				$value    = html_entity_decode( wp_strip_all_tags( $cell[2] ), ENT_QUOTES, 'UTF-8' );
				$values[] = trim( preg_replace( '/\s+/u', ' ', $value ) );
			}
			if ( ! $headers && ( $all_th || ( 0 === $i && count( $rows[1] ) > 2 && count( $values ) > 1 && max( array_map( 'mb_strlen', $values ) ) < 40 ) ) ) {
				$headers = $values;
				continue;
			}
			$parts = array();
			foreach ( $values as $c => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$parts[] = isset( $headers[ $c ] ) && '' !== $headers[ $c ] ? $headers[ $c ] . ': ' . $value : $value;
			}
			if ( $parts ) {
				$lines[] = '- ' . implode( '; ', $parts );
			}
		}
		return $lines ? "\n\n" . implode( "\n", $lines ) . "\n\n" : ' ';
	}

	public static function chunk( $text, $size = self::CHUNK_SIZE ) {
		$paragraphs = preg_split( "/\n{2,}/", $text );
		$chunks     = array();
		$current    = '';
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			while ( mb_strlen( $paragraph ) > $size ) {
				$head = mb_substr( $paragraph, 0, $size );
				// Prefer line boundaries (table rows, list items) over word boundaries.
				$cut = mb_strrpos( $head, "\n" );
				if ( ! $cut || $cut < $size / 3 ) {
					$cut = mb_strrpos( $head, ' ' );
				}
				$cut = $cut ? $cut : $size;
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}
				$chunks[]  = trim( mb_substr( $paragraph, 0, $cut ) );
				$paragraph = trim( mb_substr( $paragraph, $cut ) );
			}
			if ( mb_strlen( $current ) + mb_strlen( $paragraph ) + 2 > $size && '' !== $current ) {
				$chunks[] = $current;
				$current  = $paragraph;
			} else {
				$current = '' === $current ? $paragraph : $current . "\n\n" . $paragraph;
			}
		}
		if ( '' !== $current ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * Index FAQ pairs and custom knowledge from settings.
	 *
	 * @param bool $sync Also push to the Elasticsearch index.
	 */
	public static function index_manual_entries( $sync = true ) {
		$faqs = array();
		foreach ( self::parse_faq( (string) Settings::get( 'faq', '' ) ) as $i => $faq ) {
			$faqs[] = array(
				'object_type' => 'faq', 'object_id' => 0, 'chunk_index' => $i,
				'title' => self::decode( $faq['q'] ), 'url' => $faq['url'],
				'content' => self::clean( $faq['a'] ), 'source_modified' => '',
			);
		}
		$result = self::replace_rows( 'faq', 0, $faqs );
		if ( is_wp_error( $result ) ) {
			RAG::error( $result );
			return $result;
		}
		$rows = array();
		$custom = self::clean( (string) Settings::get( 'custom_knowledge', '' ) );
		if ( '' !== $custom ) {
			foreach ( self::stable_chunks( $custom ) as $i => $chunk ) {
				$rows[] = array(
					'object_type' => 'custom', 'object_id' => 0, 'chunk_index' => $i,
					'title' => self::decode( get_bloginfo( 'name' ) ), 'url' => '',
					'content' => $chunk, 'source_modified' => '',
				);
			}
		}
		$result = self::replace_rows( 'custom', 0, $rows );
		if ( is_wp_error( $result ) ) {
			RAG::error( $result );
			return $result;
		}
		if ( $sync && ! Index_Queue::enqueue( 0 ) ) {
			return new \WP_Error( 'otai_queue_storage', __( 'Manual knowledge could not be queued. Check database permissions and retry.', 'omnithread-ai' ) );
		}
		return count( $faqs ) + count( $rows );
	}

	/**
	 * Parse "Q: … / A: … / URL: …" blocks separated by blank lines.
	 */
	public static function parse_faq( $raw ) {
		$faqs  = array();
		$items = preg_split( "/\r?\n\s*\r?\n/", trim( $raw ) );
		foreach ( $items as $item ) {
			$q   = '';
			$a   = array();
			$url = '';
			foreach ( preg_split( "/\r?\n/", $item ) as $line ) {
				$line = trim( $line );
				if ( preg_match( '/^Q\s*[:\-]\s*(.+)$/i', $line, $m ) ) {
					$q = $m[1];
				} elseif ( preg_match( '/^A\s*[:\-]\s*(.*)$/i', $line, $m ) ) {
					$a[] = $m[1];
				} elseif ( preg_match( '/^URL\s*[:\-]\s*(\S+)$/i', $line, $m ) ) {
					$url = esc_url_raw( $m[1] );
				} elseif ( $a && '' !== $line ) {
					$a[] = $line;
				}
			}
			if ( '' !== $q && $a ) {
				$faqs[] = array(
					'q'   => $q,
					'a'   => trim( implode( "\n", $a ) ),
					'url' => $url,
				);
			}
		}
		return $faqs;
	}

	/**
	 * Batch (re)index for the admin progress bar / WP-CLI.
	 *
	 * @return array|\WP_Error [ processed, total, done ] or an explicit queue/storage failure.
	 */
	public static function reindex_batch( $offset = 0, $batch = 25 ) {
		global $wpdb;
		$types = self::indexable_post_types();
		if ( 0 === (int) $offset ) {
			$manual = self::index_manual_entries();
			if ( is_wp_error( $manual ) ) {
				return $manual;
			}
			if ( false === Index_Queue::retry() ) {
				return new \WP_Error( 'otai_queue_storage', __( 'Cannot reset failed jobs for this rebuild.', 'omnithread-ai' ) );
			}
		}
		if ( ! $types ) {
			delete_option( 'otai_needs_index' );
			update_option( 'otai_last_queued', time(), false );
			return array(
				'processed' => 0,
				'total'     => 0,
				'done'      => true,
			);
		}
		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => $batch,
				'offset'                 => (int) $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
				'suppress_filters'       => true,
			)
		);
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'otai_index_storage', __( 'Cannot read source posts for the rebuild.', 'omnithread-ai' ) );
		}
		foreach ( $query->posts as $post ) {
			if ( ! Index_Queue::enqueue( $post->ID ) ) {
				return new \WP_Error( 'otai_queue_storage', __( 'Not all source posts could be queued. Correct the database problem and restart the rebuild.', 'omnithread-ai' ) );
			}
		}
		$processed = (int) $offset + count( $query->posts );
		$total     = (int) $query->found_posts;
		if ( $processed >= $total ) {
			delete_option( 'otai_needs_index' );
			update_option( 'otai_last_queued', time(), false );
		}
		return array(
			'processed' => $processed,
			'total'     => $total,
			'done'      => $processed >= $total,
		);
	}

	public static function stats() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_type, COUNT(*) AS chunks, COUNT(DISTINCT object_id) AS objects FROM {$table} GROUP BY object_type", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function tokenize( $text ) {
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text ) : strtolower( (string) $text );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $words as $word ) {
			if ( mb_strlen( $word ) < 2 || in_array( $word, self::$stopwords, true ) ) {
				continue;
			}
			$out[] = self::stem( $word );
		}
		return array_values( array_unique( $out ) );
	}

	private static function stem( $word ) {
		if ( mb_strlen( $word ) > 4 && ! preg_match( '/\d/u', $word ) ) {
			$word = preg_replace( '/(ies|y)$/u', 'i', $word );
			$word = preg_replace( '/(ing|ed|es|s)$/u', '', $word );
			// shipp -> ship, runn -> run.
			$word = preg_replace( '/([b-df-hj-np-tv-z])\1$/u', '$1', $word );
		}
		return $word;
	}

	/**
	 * Stems end in "i" for y/ies words ("polici"); trim it so LIKE matches both "policy" and "policies".
	 */
	private static function like_term( $stem ) {
		return mb_strlen( $stem ) > 4 ? preg_replace( '/i$/u', '', $stem ) : $stem;
	}

	/**
	 * Retrieve relevant chunks.
	 *
	 * @return array[] Each: id, type, object_id, title, url, content, score (0..1).
	 */
	public static function search( $query, $limit = 4, $types = null ) {
		$terms                      = self::tokenize( $query );
		Search_Engine::$last_engine = 'database';
		if ( ! $terms || $limit < 1 ) {
			return array();
		}

		// Elasticsearch (ElasticPress/VIP or own server) first; the database fills gaps and takes over if it is unavailable.
		$es         = Search_Engine::candidates( $query, $types );
		$candidates = array();
		if ( $es ) {
			foreach ( $es['rows'] as $row ) {
				$candidates[ (int) $row['id'] ] = $row;
			}
		}
		if ( ! $es || ! $es['complete'] || ! $candidates ) {
			foreach ( self::db_candidates( $query, $terms, $types ) as $row ) {
				$id = (int) $row['id'];
				if ( isset( $candidates[ $id ] ) ) {
					$candidates[ $id ]['ft'] = isset( $row['ft'] ) ? $row['ft'] : 0;
				} else {
					$candidates[ $id ] = $row;
				}
			}
		}
		return RAG::hydrate( self::rank( $query, $terms, array_values( $candidates ), $limit ) );
	}

	/**
	 * MySQL FULLTEXT candidates, falling back to LIKE.
	 */
	private static function db_candidates( $query, array $terms, $types ) {
		global $wpdb;
		$table      = self::table();
		$type_sql   = '';
		if ( is_array( $types ) && $types ) {
			$type_sql = ' AND object_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
		}
		$candidates = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( get_option( 'otai_fulltext' ) ) {
			$ft_query   = implode( ' ', array_map( array( __CLASS__, 'raw_word' ), self::raw_terms( $query ) ) );
			$args       = array_merge( array( $ft_query, $ft_query ), is_array( $types ) ? $types : array() );
			$suppress   = $wpdb->suppress_errors( true );
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, object_type, object_id, title, url, content, MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS ft
					FROM {$table} WHERE MATCH(title, content) AGAINST (%s IN NATURAL LANGUAGE MODE){$type_sql} ORDER BY ft DESC LIMIT 40",
					$args
				),
				ARRAY_A
			);
			$wpdb->suppress_errors( $suppress );
		}

		if ( empty( $candidates ) ) {
			$like_terms = $terms;
			usort(
				$like_terms,
				function ( $a, $b ) {
					return mb_strlen( $b ) - mb_strlen( $a );
				}
			);
			$like_terms = array_slice( $like_terms, 0, 6 );
			$where      = array();
			$args       = array();
			foreach ( $like_terms as $term ) {
				$like    = '%' . $wpdb->esc_like( self::like_term( $term ) ) . '%';
				$where[] = '(title LIKE %s OR content LIKE %s)';
				$args[]  = $like;
				$args[]  = $like;
			}
			$args       = array_merge( $args, is_array( $types ) ? $types : array() );
			$candidates = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, object_type, object_id, title, url, content FROM {$table} WHERE (" . implode( ' OR ', $where ) . "){$type_sql} LIMIT 80", $args ),
				ARRAY_A
			);
		}
		// phpcs:enable
		return (array) $candidates;
	}

	/**
	 * Score candidates by keyword coverage (plus search-engine relevance) and keep the best, diverse passages.
	 */
	private static function rank( $query, array $terms, array $candidates, $limit ) {
		$phrase  = trim( mb_strtolower( (string) $query ) );
		$results = array();
		foreach ( $candidates as $row ) {
			$content_terms = array_flip( self::tokenize( $row['content'] ) );
			$title_terms   = array_flip( self::tokenize( $row['title'] ) );
			$hits          = 0;
			$title_hits    = 0;
			foreach ( $terms as $term ) {
				if ( isset( $content_terms[ $term ] ) || isset( $title_terms[ $term ] ) ) {
					++$hits;
				}
				if ( isset( $title_terms[ $term ] ) ) {
					++$title_hits;
				}
			}
			$es = isset( $row['es'] ) ? (float) $row['es'] : 0.0;
			if ( ! $hits ) {
				// Search-engine-only matches (fuzzy spelling, stemming) ground AI answers but stay below the direct-answer threshold.
				if ( $es <= 0 ) {
					continue;
				}
				$score = 0.45 * $es;
			} else {
				$total = count( $terms );
				$score = 0.8 * ( $hits / $total ) + 0.2 * ( $title_hits / $total ) + 0.15 * $es;
				if ( mb_strlen( $phrase ) > 8 && false !== mb_strpos( mb_strtolower( $row['content'] ), $phrase ) ) {
					$score += 0.1;
				}
			}
			$results[] = array(
				'id'        => (int) $row['id'],
				'type'      => $row['object_type'],
				'object_id' => (int) $row['object_id'],
				'title'     => self::decode( $row['title'] ),
				'url'       => $row['url'],
				'content'   => $row['content'],
				'score'     => round( min( 1, $score ), 3 ),
				'ft'        => isset( $row['ft'] ) ? (float) $row['ft'] : 0,
				'es'        => $es,
			);
		}
		usort(
			$results,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $b['es'] === $a['es'] ? $b['ft'] <=> $a['ft'] : $b['es'] <=> $a['es'];
				}
				return $b['score'] <=> $a['score'];
			}
		);

		// Keep at most two chunks per source for diversity.
		$per_source = array();
		$final      = array();
		foreach ( $results as $result ) {
			$key                = $result['type'] . ':' . $result['object_id'] . ':' . ( $result['object_id'] ? '' : $result['id'] );
			$per_source[ $key ] = isset( $per_source[ $key ] ) ? $per_source[ $key ] + 1 : 1;
			if ( $per_source[ $key ] > 2 ) {
				continue;
			}
			$final[] = $result;
			if ( count( $final ) >= $limit ) {
				break;
			}
		}
		return $final;
	}

	private static function raw_terms( $query ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $query ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values(
			array_filter(
				$words,
				function ( $w ) {
					return mb_strlen( $w ) >= 2 && ! in_array( $w, self::$stopwords, true );
				}
			)
		);
	}

	private static function raw_word( $word ) {
		return preg_replace( '/[+\-><()~*"@]/', '', $word );
	}

	/**
	 * Best FAQ match using token overlap (Dice coefficient) against the FAQ question.
	 *
	 * @return array|null [ question, answer, url, score ].
	 */
	public static function match_faq( $query ) {
		$q_terms = self::tokenize( $query );
		if ( ! $q_terms ) {
			return null;
		}
		$faqs = self::search( $query, 5, array( 'faq' ) );
		$best = null;
		foreach ( $faqs as $faq ) {
			$f_terms = self::tokenize( $faq['title'] );
			if ( ! $f_terms ) {
				continue;
			}
			$overlap = count( array_intersect( $q_terms, $f_terms ) );
			$score   = ( 2 * $overlap ) / ( count( $q_terms ) + count( $f_terms ) );
			if ( ! $best || $score > $best['score'] ) {
				$best = array(
					'question' => $faq['title'],
					'answer'   => $faq['content'],
					'url'      => $faq['url'],
					'score'    => round( $score, 3 ),
				);
			}
		}
		return $best;
	}

	/**
	 * Build a readable extractive answer from the best chunk (used when no LLM is needed or available).
	 *
	 * - Table rows / list items ("- …" lines) matching the query are returned as bullets.
	 * - Otherwise the single best-matching sentence is expanded into a short, contiguous passage
	 *   (so the reply reads as one coherent paragraph instead of scattered fragments).
	 */
	public static function extractive_answer( $query, array $chunk, $max_units = 5 ) {
		$result = self::extract( $query, $chunk, $max_units );
		return $result['text'];
	}

	/**
	 * @return array { text: string, rich: bool } "rich" is false when only a page pointer could be produced.
	 */
	public static function extract( $query, array $chunk, $max_units = 5 ) {
		$terms = self::tokenize( $query );
		$title = isset( $chunk['title'] ) ? self::decode( $chunk['title'] ) : '';
		$units = array();
		foreach ( preg_split( '/\n+/u', (string) $chunk['content'], -1, PREG_SPLIT_NO_EMPTY ) as $line_no => $line ) {
			$line = trim( $line );
			if ( '' === $line || ( '' !== $title && 0 === strcasecmp( $line, $title ) ) ) {
				continue;
			}
			if ( 0 === strpos( $line, '- ' ) ) {
				$units[] = self::unit( $line, $line_no, true, $terms );
				continue;
			}
			foreach ( preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}\p{N}"“(])/u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $sentence ) {
				$units[] = self::unit( trim( $sentence ), $line_no, false, $terms );
			}
		}

		// 1) Structured rows (tables, lists) that match the query.
		$rows = array_values(
			array_filter(
				$units,
				function ( $u ) {
					return $u['row'] && $u['hits'] > 0;
				}
			)
		);
		if ( $rows ) {
			usort( $rows, array( __CLASS__, 'by_hits' ) );
			$rows = array_slice( $rows, 0, $max_units );
			usort( $rows, array( __CLASS__, 'by_pos' ) );
			return array(
				'text' => self::frame_answer( $title, implode( "\n", wp_list_pluck( $rows, 'text' ) ) ),
				'rich' => true,
			);
		}

		// 2) Prose: best "real" sentence (not a heading/name/question), then read on from there.
		$prose = array();
		foreach ( $units as $pos => $u ) {
			if ( ! $u['row'] && $u['sentence'] ) {
				$u['pos'] = $pos;
				$prose[]  = $u;
			}
		}
		if ( ! $prose ) {
			return array(
				/* translators: %s: page title */
				'text' => '' !== $title ? sprintf( __( 'This page looks relevant: **%s**', 'omnithread-ai' ), $title ) : wp_html_excerpt( (string) $chunk['content'], 300, '…' ),
				'rich' => false,
			);
		}
		$best = $prose;
		usort( $best, array( __CLASS__, 'by_hits' ) );
		$best = $best[0];

		$picked = array( $best );
		$length = mb_strlen( $best['text'] );
		for ( $i = $best['pos'] + 1; $i < count( $units ) && $length < 420 && count( $picked ) < 4; $i++ ) {
			$u = $units[ $i ];
			if ( $u['row'] || ! $u['sentence'] ) {
				if ( $u['line'] !== $best['line'] ) {
					break; // A heading or list starts a new section.
				}
				continue;
			}
			$picked[] = $u;
			$length  += mb_strlen( $u['text'] );
		}

		$out  = '';
		$prev = null;
		foreach ( $picked as $u ) {
			if ( null !== $prev ) {
				$out .= $u['line'] === $prev['line'] ? ' ' : "\n\n";
			}
			$out .= $u['text'];
			$prev = $u;
		}
		return array(
			'text' => self::frame_answer( $title, $out ),
			'rich' => true,
		);
	}

	private static function unit( $text, $line_no, $row, array $terms ) {
		$tokens = array_flip( self::tokenize( $text ) );
		$hits   = 0;
		foreach ( $terms as $term ) {
			if ( isset( $tokens[ $term ] ) ) {
				++$hits;
			}
		}
		// A "sentence" is real prose: long enough, ends like a sentence and is not a question heading.
		$len      = mb_strlen( $text );
		$last     = mb_substr( $text, -1 );
		$sentence = ! $row && $len >= 30 && '?' !== $last && ( in_array( $last, array( '.', '!', '"', '”', ')', ':' ), true ) || $len >= 80 );
		return array(
			'text'     => $text,
			'line'     => $line_no,
			'row'      => $row,
			'hits'     => $hits,
			'sentence' => $sentence,
		);
	}

	private static function by_hits( $a, $b ) {
		return $b['hits'] === $a['hits'] ? 0 : ( $b['hits'] > $a['hits'] ? 1 : -1 );
	}

	private static function by_pos( $a, $b ) {
		return $a['line'] - $b['line'];
	}

	private static function frame_answer( $title, $body ) {
		if ( '' === $title ) {
			return $body;
		}
		/* translators: %s: page title */
		return sprintf( __( 'Here is what I found on **%s**:', 'omnithread-ai' ), $title ) . "\n\n" . $body;
	}
}