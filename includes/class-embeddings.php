<?php
/**
 * Independent embedding adapters, validation, per-site cache and atomic budgets.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Embeddings {

	public static function config() {
		$config = array(
			'provider'   => Settings::get( 'rag_embed_provider' ),
			'model'      => Settings::get( 'rag_embed_model' ),
			'url'        => Settings::get( 'rag_embed_url' ),
			'key'        => Crypto::decrypt( (string) Settings::get( 'rag_embed_key' ) ),
			'dimensions' => (int) Settings::get( 'rag_embed_dimensions' ),
		);
		foreach ( array( 'provider', 'model', 'url', 'key', 'dimensions' ) as $field ) {
			$constant = 'OTAI_EMBED_' . strtoupper( $field );
			if ( defined( $constant ) ) {
				$config[ $field ] = constant( $constant );
			}
		}
		if ( ! $config['url'] && 'openai' === $config['provider'] ) {
			$config['url'] = 'https://api.openai.com/v1';
		}
		if ( ! $config['url'] && 'ollama' === $config['provider'] ) {
			$config['url'] = 'http://localhost:11434';
		}
		return $config;
	}

	public static function profile() {
		$c = self::config();
		return hash( 'sha256', wp_json_encode( array( $c['provider'], $c['model'], $c['url'], (int) $c['dimensions'], (bool) Settings::get( 'redact_pii' ), 'plain-v1', get_current_blog_id() ) ) );
	}

	public static function input( $text ) {
		return Settings::get( 'redact_pii' ) ? Chat_Service::redact( $text ) : $text;
	}

	public static function cache_key( $text ) {
		return hash( 'sha256', self::profile() . '|' . self::input( $text ) );
	}

	public static function policy() {
		$c    = self::config();
		$host = strtolower( (string) wp_parse_url( $c['url'], PHP_URL_HOST ) );
		$loopback = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		if ( ! in_array( $c['provider'], array( 'openai', 'azure', 'ollama' ), true ) || ! $c['model'] || ! $host ) {
			return new \WP_Error( 'otai_embedding_config', __( 'Set a supported embedding provider, model and endpoint.', 'omnithread-ai' ) );
		}
		if ( 'local_only' === Settings::get( 'mode' ) && ( 'ollama' !== $c['provider'] || ! $loopback ) ) {
			return new \WP_Error( 'otai_embedding_local', __( 'Local-only mode permits only loopback Ollama embeddings. Cloud embeddings were not called.', 'omnithread-ai' ) );
		}
		if ( ! $loopback && ! Settings::get( 'rag_cloud_consent' ) ) {
			return new \WP_Error( 'otai_embedding_consent', __( 'Approve outbound embedding processing in RAG settings before sending text to this endpoint.', 'omnithread-ai' ) );
		}
		$scheme = wp_parse_url( $c['url'], PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ( 'ollama' !== $c['provider'] && 'https' !== $scheme ) ) {
			return new \WP_Error( 'otai_embedding_tls', __( 'Cloud embedding endpoints require HTTPS.', 'omnithread-ai' ) );
		}
		if ( wp_parse_url( $c['url'], PHP_URL_USER ) || ( 'ollama' !== $c['provider'] && ! $c['key'] ) ) {
			return new \WP_Error( 'otai_embedding_key', __( 'Configure the embedding API key separately; URL credentials are not supported.', 'omnithread-ai' ) );
		}
		return true;
	}

	public static function validate( $vector, $dimensions ) {
		if ( ! is_array( $vector ) || count( $vector ) !== (int) $dimensions ) {
			return new \WP_Error( 'otai_embedding_dimensions', __( 'Embedding dimensions do not match the configured index. Check the model and dimensions.', 'omnithread-ai' ) );
		}
		$norm = 0.0;
		foreach ( $vector as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				return new \WP_Error( 'otai_embedding_vector', __( 'Embedding response contains non-numeric values.', 'omnithread-ai' ) );
			}
			if ( ! is_finite( (float) $value ) ) {
				return new \WP_Error( 'otai_embedding_vector', __( 'Embedding response contains non-finite values.', 'omnithread-ai' ) );
			}
			$norm += $value * $value;
		}
		if ( $norm <= 0 || ! is_finite( $norm ) ) {
			return new \WP_Error( 'otai_embedding_vector', __( 'Embedding response contains an invalid zero vector.', 'omnithread-ai' ) );
		}
		return array_values( $vector );
	}

	/**
	 * Reserve text inputs, including retries and queries. Failed requests retain
	 * their reservation because the provider may have processed them.
	 */
	private static function reserve( $count ) {
		global $wpdb;
		$table = $wpdb->prefix . 'otai_embedding_budget';
		$day   = gmdate( 'Y-m-d' );
		$limit = (int) Settings::get( 'rag_embed_daily' );
		if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (day,used) VALUES (%s,0)", $day ) ) ) {
			return new \WP_Error( 'otai_embedding_budget_db', __( 'Cannot initialise the embedding budget.', 'omnithread-ai' ) );
		}
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used=used+%d WHERE day=%s AND used+%d<=%d", $count, $day, $count, $limit ) );
		if ( 1 !== $result ) {
			return new \WP_Error( 'otai_embedding_budget', __( 'Embedding daily text-input limit reached, or budget storage failed. Jobs remain retryable.', 'omnithread-ai' ) );
		}
		return true;
	}

	public static function embed( array $texts ) {
		$policy = self::policy();
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		if ( ! $texts || count( $texts ) > 8 ) {
			return new \WP_Error( 'otai_embedding_batch', __( 'Embedding batches must contain 1 to 8 texts.', 'omnithread-ai' ) );
		}
		$texts = array_map( array( __CLASS__, 'input' ), $texts );
		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > 16000 ) {
				return new \WP_Error( 'otai_embedding_input', __( 'Embedding input is empty or exceeds the 16 KB safety limit.', 'omnithread-ai' ) );
			}
		}
		$reserved = self::reserve( count( $texts ) );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		$c       = self::config();
		$headers = array( 'Content-Type' => 'application/json' );
		$body    = array( 'input' => array_values( $texts ) );
		if ( 'ollama' === $c['provider'] ) {
			$url = untrailingslashit( $c['url'] ) . '/api/embed';
			$body['model']    = $c['model'];
			$body['truncate'] = false;
		} elseif ( 'azure' === $c['provider'] ) {
			$url = $c['url'];
			$headers['api-key'] = $c['key'];
		} else {
			$url = untrailingslashit( $c['url'] ) . '/embeddings';
			$headers['Authorization'] = 'Bearer ' . $c['key'];
			$body['model'] = $c['model'];
			if ( 0 === strpos( $c['model'], 'text-embedding-3-' ) ) {
				$body['dimensions'] = (int) $c['dimensions'];
			}
		}
		$response = wp_remote_post( $url, array(
			'headers' => $headers, 'body' => wp_json_encode( $body ),
			'timeout' => min( 60, (int) Settings::get( 'timeout', 30 ) ),
			'redirection' => 0, 'sslverify' => true,
		) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'otai_embedding_http', $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'otai_embedding_http', sprintf( __( 'Embedding endpoint returned HTTP %d. Check credentials, model, quota and endpoint.', 'omnithread-ai' ), $code ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$vectors = array();
		if ( 'ollama' === $c['provider'] && isset( $data['embeddings'] ) ) {
			$vectors = $data['embeddings'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $item ) {
				if ( ! isset( $item['index'], $item['embedding'] ) || ! is_int( $item['index'] ) || isset( $vectors[ $item['index'] ] ) ) {
					return new \WP_Error( 'otai_embedding_batch', __( 'Embedding response contains invalid or duplicate batch indices.', 'omnithread-ai' ) );
				}
				$vectors[ $item['index'] ] = $item['embedding'];
			}
			ksort( $vectors );
		}
		if ( ! is_array( $vectors ) || array_keys( $vectors ) !== range( 0, count( $texts ) - 1 ) ) {
			return new \WP_Error( 'otai_embedding_batch', __( 'Embedding endpoint returned an incomplete batch.', 'omnithread-ai' ) );
		}
		foreach ( $vectors as $i => $vector ) {
			$vectors[ $i ] = self::validate( $vector, $c['dimensions'] );
			if ( is_wp_error( $vectors[ $i ] ) ) {
				return $vectors[ $i ];
			}
		}
		return $vectors;
	}

	public static function cached( $text ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT vector FROM {$wpdb->prefix}otai_embeddings WHERE cache_key=%s", self::cache_key( $text ) ) );
		if ( null === $raw ) {
			return null;
		}
		return self::validate( json_decode( $raw, true ), self::config()['dimensions'] );
	}

	public static function store( $text, array $vector ) {
		global $wpdb;
		$result = $wpdb->replace( $wpdb->prefix . 'otai_embeddings', array(
			'cache_key' => self::cache_key( $text ), 'profile' => self::profile(),
			'vector' => wp_json_encode( $vector ), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s' ) );
		return false === $result ? new \WP_Error( 'otai_embedding_cache', __( 'Cannot persist the embedding cache.', 'omnithread-ai' ) ) : true;
	}
}
