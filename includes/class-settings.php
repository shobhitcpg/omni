<?php
/**
 * Settings storage, defaults, sanitisation and wp-config overrides.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

use OmniThreadAI\Providers\Registry;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'otai_settings';
	const GROUP  = 'otai_settings_group';

	private static $cache = null;
	private static $cache_blog = null;

	public static function defaults() {
		return array(
			// General.
			'enabled'              => 1,
			'vertical'             => 'general',
			'bot_name'             => 'AI Assistant',
			'welcome_message'      => '',
			'persona'              => '',
			'contact_info'         => '',
			'display'              => 'floating',
			'show_ids'             => '',
			'show_post_types'      => array(),
			'show_paths'           => '',
			'visibility'           => 'all',
			'exclude_ids'          => '',
			'exclude_paths'        => '',
			// Routing.
			'mode'                 => 'smart',
			'primary_provider'     => 'openai',
			'provider_order'       => 'ollama,openai,gemini,anthropic,azure_openai,bedrock,mistral,groq,deepseek,openrouter,custom,omnithread',
			'local_first'          => 1,
			'free_first'           => 1,
			'local_answer_content' => 1,
			'local_threshold'      => 0.8,
			'faq_threshold'        => 0.6,
			'cache_ttl'            => 3600,
			'timeout'              => 30,
			'fallback_message'     => '',
			// Model parameters.
			'temperature'          => 0.4,
			'top_p'                => 0.9,
			'top_k'                => 40,
			'max_tokens'           => 600,
			'presence_penalty'     => 0,
			'frequency_penalty'    => 0,
			'history_turns'        => 6,
			'context_chunks'       => 4,
			// Providers (per provider sub-arrays).
			'providers'            => array(),
			// Knowledge.
			'post_types'           => array( 'post', 'page' ),
			'faq'                  => '',
			'custom_knowledge'     => '',
			'auto_sync'            => 1,
			'woo_products'         => 1,
			'woo_orders'           => 1,
			// Search engine.
			'search_engine'        => 'auto',
			'es_url'               => '',
			'es_index'             => '',
			'es_auth'              => 'none',
			'es_username'          => '',
			'es_password'          => '',
			'es_api_key'           => '',
			'es_verify_ssl'        => 1,
			'rag_retrieval'        => 'keyword',
			'rag_cloud_consent'    => 0,
			'rag_embed_provider'   => 'openai',
			'rag_embed_model'      => 'text-embedding-3-small',
			'rag_embed_url'        => '',
			'rag_embed_key'        => '',
			'rag_embed_dimensions' => 1536,
			'rag_embed_daily'      => 1000,
			'rag_candidates'       => 40,
			'rag_rrf_constant'     => 60,
			'rag_parent_chars'     => 0,
			'rag_context_chars'    => 12000,
			'rag_chunk_chars'      => 1200,
			'rag_document_chars'   => 100000,
			'rag_grounded_only'    => 1,
			'rag_queue_paused'     => 0,
			'primary_color'        => '#2563eb',
			'position'             => 'right',
			'launcher_label'       => '',
			'starters'             => '',
			'show_sources'         => 1,
			// Privacy & security.
			'store_conversations'  => 1,
			'retention_days'       => 30,
			'redact_pii'           => 1,
			'rate_limit_minute'    => 12,
			'rate_limit_day'       => 200,
			'max_message_length'   => 1000,
			'disclaimer'           => '',
			'consent_notice'       => '',
			'strict_origin'        => 1,
			'delete_on_uninstall'  => 0,
		);
	}

	/**
	 * Field type map used by the sanitiser.
	 */
	private static function schema() {
		return array(
			'enabled'              => array( 'bool' ),
			'vertical'             => array( 'enum', array_keys( Verticals::all() ) ),
			'bot_name'             => array( 'text' ),
			'welcome_message'      => array( 'textarea' ),
			'persona'              => array( 'textarea' ),
			'contact_info'         => array( 'textarea' ),
			'display'              => array( 'enum', array( 'floating', 'selected', 'shortcode_only' ) ),
			'show_ids'             => array( 'id_csv' ),
			'show_post_types'      => array( 'slug_array' ),
			'show_paths'           => array( 'paths' ),
			'visibility'           => array( 'enum', array( 'all', 'logged_in', 'guests' ) ),
			'exclude_ids'          => array( 'id_csv' ),
			'exclude_paths'        => array( 'paths' ),
			'mode'                 => array( 'enum', array( 'smart', 'single', 'local_only' ) ),
			'primary_provider'     => array( 'enum', Registry::ids() ),
			'provider_order'       => array( 'provider_csv' ),
			'local_first'          => array( 'bool' ),
			'free_first'           => array( 'bool' ),
			'local_answer_content' => array( 'bool' ),
			'local_threshold'      => array( 'float', 0.3, 1 ),
			'faq_threshold'        => array( 'float', 0.3, 1 ),
			'cache_ttl'            => array( 'int', 0, WEEK_IN_SECONDS ),
			'timeout'              => array( 'int', 5, 120 ),
			'fallback_message'     => array( 'textarea' ),
			'temperature'          => array( 'float', 0, 2 ),
			'top_p'                => array( 'float', 0, 1 ),
			'top_k'                => array( 'int', 1, 200 ),
			'max_tokens'           => array( 'int', 32, 8192 ),
			'presence_penalty'     => array( 'float', -2, 2 ),
			'frequency_penalty'    => array( 'float', -2, 2 ),
			'history_turns'        => array( 'int', 0, 30 ),
			'context_chunks'       => array( 'int', 0, 12 ),
			'post_types'           => array( 'slug_array' ),
			'faq'                  => array( 'textarea' ),
			'custom_knowledge'     => array( 'textarea' ),
			'auto_sync'            => array( 'bool' ),
			'woo_products'         => array( 'bool' ),
			'woo_orders'           => array( 'bool' ),
			'search_engine'        => array( 'enum', array( 'auto', 'elasticpress', 'elasticsearch', 'database' ) ),
			'es_url'               => array( 'url' ),
			'es_index'             => array( 'es_index' ),
			'es_auth'              => array( 'enum', array( 'none', 'basic', 'api_key' ) ),
			'es_username'          => array( 'text' ),
			'es_password'          => array( 'secret' ),
			'es_api_key'           => array( 'secret' ),
			'es_verify_ssl'        => array( 'bool' ),
			'rag_retrieval'        => array( 'enum', array( 'keyword', 'semantic', 'hybrid' ) ),
			'rag_cloud_consent'    => array( 'bool' ),
			'rag_embed_provider'   => array( 'enum', array( 'openai', 'azure', 'ollama' ) ),
			'rag_embed_model'      => array( 'text' ),
			'rag_embed_url'        => array( 'url' ),
			'rag_embed_key'        => array( 'secret' ),
			'rag_embed_dimensions' => array( 'int', 1, 4096 ),
			'rag_embed_daily'      => array( 'int', 1, 100000 ),
			'rag_candidates'       => array( 'int', 4, 100 ),
			'rag_rrf_constant'     => array( 'int', 1, 200 ),
			'rag_parent_chars'     => array( 'int', 0, 12000 ),
			'rag_context_chars'    => array( 'int', 1000, 60000 ),
			'rag_chunk_chars'      => array( 'int', 200, 4000 ),
			'rag_document_chars'   => array( 'int', 4000, 500000 ),
			'rag_grounded_only'    => array( 'bool' ),
			'rag_queue_paused'     => array( 'bool' ),
			'primary_color'        => array( 'color' ),
			'position'             => array( 'enum', array( 'right', 'left' ) ),
			'launcher_label'       => array( 'text' ),
			'starters'             => array( 'textarea' ),
			'show_sources'         => array( 'bool' ),
			'store_conversations'  => array( 'bool' ),
			'retention_days'       => array( 'int', 1, 3650 ),
			'redact_pii'           => array( 'bool' ),
			'rate_limit_minute'    => array( 'int', 1, 1000 ),
			'rate_limit_day'       => array( 'int', 1, 100000 ),
			'max_message_length'   => array( 'int', 50, 8000 ),
			'disclaimer'           => array( 'textarea' ),
			'consent_notice'       => array( 'textarea' ),
			'strict_origin'        => array( 'bool' ),
			'delete_on_uninstall'  => array( 'bool' ),
		);
	}

	public static function all() {
		if ( null === self::$cache || self::$cache_blog !== get_current_blog_id() ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
			self::$cache_blog = get_current_blog_id();
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Resolved provider config: stored values + defaults + wp-config constants, secrets decrypted.
	 */
	public static function provider_config( $id ) {
		$provider = Registry::blueprint( $id );
		if ( ! $provider ) {
			return array();
		}
		$providers = self::get( 'providers', array() );
		$stored    = isset( $providers[ $id ] ) && is_array( $providers[ $id ] ) ? $providers[ $id ] : array();
		$config    = array(
			'enabled'     => ! empty( $stored['enabled'] ),
			'daily_limit' => isset( $stored['daily_limit'] ) ? absint( $stored['daily_limit'] ) : 0,
		);
		foreach ( $provider->fields() as $key => $field ) {
			$value = array_key_exists( $key, $stored ) ? $stored[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
			if ( 'secret' === $field['type'] ) {
				$value = Crypto::decrypt( $value );
			}
			$const = self::constant_name( $id, $key );
			if ( defined( $const ) ) {
				$value = constant( $const );
			}
			$config[ $key ] = $value;
		}
		$const = self::constant_name( $id, 'enabled' );
		if ( defined( $const ) ) {
			$config['enabled'] = (bool) constant( $const );
		}
		return $config;
	}

	public static function constant_name( $provider_id, $field ) {
		return strtoupper( 'OTAI_' . $provider_id . '_' . $field );
	}

	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return self::all();
		}
		$stored = get_option( self::OPTION, array() );
		$out    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		$apply  = ! empty( $input['_apply_recommended'] );
		unset( $input['_apply_recommended'] );

		foreach ( self::schema() as $key => $rule ) {
			if ( 'secret' === $rule[0] && ! empty( $input[ 'clear_' . $key ] ) ) {
				$out[ $key ] = '';
				continue;
			}
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = self::sanitize_value( $rule, $input[ $key ], $out[ $key ] );
			}
		}

		if ( isset( $input['providers'] ) && is_array( $input['providers'] ) ) {
			$out['providers'] = self::sanitize_providers( $input['providers'], is_array( $out['providers'] ) ? $out['providers'] : array() );
		}

		if ( $apply ) {
			$out = array_merge( $out, Verticals::recommended_settings( $out['vertical'] ) );
		}

		self::$cache = null;
		return $out;
	}

	private static function sanitize_value( $rule, $value, $previous ) {
		switch ( $rule[0] ) {
			case 'bool':
				return empty( $value ) ? 0 : 1;
			case 'int':
				return max( $rule[1], min( $rule[2], (int) $value ) );
			case 'float':
				return round( max( $rule[1], min( $rule[2], (float) $value ) ), 2 );
			case 'enum':
				return in_array( $value, $rule[1], true ) ? $value : $previous;
			case 'text':
				return sanitize_text_field( $value );
			case 'url':
				$value = trim( (string) $value );
				return '' === $value ? '' : untrailingslashit( esc_url_raw( $value, array( 'http', 'https' ) ) );
			case 'es_index':
				// Elasticsearch index names: lowercase, no spaces or special characters.
				return substr( trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $value ) ) ), '-_' ), 0, 200 );
			case 'secret':
				// Blank keeps the stored value, so the saved secret never has to be echoed back into the form.
				$value = preg_replace( '/[\x00-\x20\x7f]/', '', (string) $value );
				return '' === $value ? $previous : Crypto::encrypt( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'color':
				$color = sanitize_hex_color( $value );
				return $color ? $color : $previous;
			case 'id_csv':
				return implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) );
			case 'provider_csv':
				$ids   = Registry::ids();
				$order = array_values( array_unique( array_intersect( array_map( 'sanitize_key', explode( ',', (string) $value ) ), $ids ) ) );
				foreach ( $ids as $id ) {
					if ( ! in_array( $id, $order, true ) ) {
						$order[] = $id;
					}
				}
				return implode( ',', $order );
			case 'slug_array':
				return array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
			case 'paths':
				$lines = array();
				foreach ( preg_split( '/[\r\n,]+/', (string) $value ) as $line ) {
					$line = trim( sanitize_text_field( $line ) );
					if ( '' === $line ) {
						continue;
					}
					if ( preg_match( '#^https?://#i', $line ) ) {
						$path = wp_parse_url( $line, PHP_URL_PATH );
						$line = $path ? $path : '/';
					}
					$lines[] = '/' . ltrim( $line, '/' );
				}
				return implode( "\n", array_unique( $lines ) );
		}
		return $previous;
	}

	private static function sanitize_providers( array $input, array $existing ) {
		foreach ( Registry::ids() as $id ) {
			if ( ! isset( $input[ $id ] ) || ! is_array( $input[ $id ] ) ) {
				continue;
			}
			$in       = $input[ $id ];
			$p        = isset( $existing[ $id ] ) && is_array( $existing[ $id ] ) ? $existing[ $id ] : array();
			$provider = Registry::blueprint( $id );

			if ( array_key_exists( 'enabled', $in ) ) {
				$p['enabled'] = empty( $in['enabled'] ) ? 0 : 1;
			}
			if ( array_key_exists( 'daily_limit', $in ) ) {
				$p['daily_limit'] = absint( $in['daily_limit'] );
			}

			foreach ( $provider->fields() as $key => $field ) {
				if ( 'secret' === $field['type'] ) {
					if ( ! empty( $in[ 'clear_' . $key ] ) ) {
						$p[ $key ] = '';
					} elseif ( isset( $in[ $key ] ) && '' !== trim( (string) $in[ $key ] ) ) {
						$p[ $key ] = Crypto::encrypt( preg_replace( '/[\x00-\x20\x7f]/', '', (string) $in[ $key ] ) );
					}
					continue;
				}
				if ( ! array_key_exists( $key, $in ) ) {
					continue;
				}
				$value = $in[ $key ];
				switch ( $field['type'] ) {
					case 'url':
						$p[ $key ] = untrailingslashit( esc_url_raw( trim( (string) $value ) ) );
						break;
					case 'number':
						$p[ $key ] = is_numeric( $value ) ? $value + 0 : ( isset( $field['default'] ) ? $field['default'] : 0 );
						break;
					case 'checkbox':
						$p[ $key ] = empty( $value ) ? 0 : 1;
						break;
					case 'select':
						$p[ $key ] = array_key_exists( $value, $field['options'] ) ? $value : $field['default'];
						break;
					default:
						$p[ $key ] = sanitize_text_field( $value );
				}
			}
			$existing[ $id ] = $p;
		}
		return $existing;
	}
}
