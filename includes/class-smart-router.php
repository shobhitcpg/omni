<?php
/**
 * Smart Query Mode router: FAQ → knowledge base (Elasticsearch + database) → cache → self-hosted models → paid providers (in order, with failover and daily caps) → graceful fallback.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

use OmniThreadAI\Providers\Registry;

defined( 'ABSPATH' ) || exit;

class Smart_Router {

	/**
	 * @param array $ctx {
	 *     @type string $query      Visitor question (original, used for local matching only).
	 *     @type array  $messages   Chat messages to send to an LLM (already redacted).
	 *     @type array  $params     Generation params.
	 *     @type array  $chunks     Retrieved knowledge chunks.
	 *     @type string $cache_key  Optional response cache key.
	 *     @type bool   $skip_local Skip FAQ / extractive answers (e.g. personal order questions).
	 * }
	 * @return array|\WP_Error reply, provider, model, source, sources, attempts; or a request-budget storage error.
	 */
	public static function route( array $ctx ) {
		$ctx = wp_parse_args(
			$ctx,
			array(
				'query'      => '',
				'messages'   => array(),
				'params'     => array(),
				'chunks'     => array(),
				'cache_key'  => '',
				'skip_local' => false,
			)
		);

		$mode     = Settings::get( 'mode', 'smart' );
		$attempts = array();
		$chunks   = $ctx['chunks'];

		// 1–2. Free local answers.
		if ( ! $ctx['skip_local'] && ( 'local_only' === $mode || Settings::get( 'local_first' ) ) ) {
			$local = self::local_answer( $ctx['query'], $chunks, 'local_only' === $mode );
			if ( $local ) {
				return $local;
			}
		}

		if ( 'local_only' === $mode ) {
			if ( $chunks && ! empty( $chunks[0]['personal'] ) ) {
				Usage::log( array( 'answer_source' => 'knowledge' ) );
				return array(
					'reply'    => $chunks[0]['content'],
					'provider' => '',
					'model'    => '',
					'source'   => 'knowledge',
					'sources'  => self::source_links( array( $chunks[0] ) ),
					'attempts' => array(),
				);
			}
			return self::fallback( $chunks, $attempts );
		}
		if ( ! $chunks && Settings::get( 'rag_grounded_only' ) ) {
			return self::fallback( $chunks, $attempts );
		}

		// 3. Response cache.
		$ttl = (int) Settings::get( 'cache_ttl', 0 );
		if ( $ctx['cache_key'] && $ttl > 0 ) {
			$cached = get_transient( $ctx['cache_key'] );
			if ( is_array( $cached ) && ! empty( $cached['reply'] ) ) {
				$cached['source'] = 'cache';
				Usage::log(
					array(
						'provider'      => '',
						'answer_source' => 'cache',
					)
				);
				return $cached;
			}
		}

		// 4. Providers.
		foreach ( self::candidates( $mode ) as $provider ) {
			$reservation = Request_Budget::provider( $provider->id(), $provider->daily_limit() );
			if ( is_wp_error( $reservation ) ) {
				$attempts[] = array(
					'provider' => $provider->id(),
					'error'    => $reservation->get_error_message(),
				);
				if ( 'otai_provider_limit' !== $reservation->get_error_code() ) {
					return $reservation;
				}
				continue;
			}
			$start  = microtime( true );
			$result = $provider->chat( $ctx['messages'], $ctx['params'] );
			$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$attempts[] = array(
					'provider' => $provider->id(),
					'error'    => $result->get_error_message(),
				);
				Usage::log(
					array(
						'provider'      => $provider->id(),
						'answer_source' => 'llm',
						'latency_ms'    => $ms,
						'success'       => 0,
						'error'         => $result->get_error_message(),
					)
				);
				continue;
			}

			Usage::log(
				array(
					'provider'      => $provider->id(),
					'model'         => $result['model'],
					'answer_source' => 'llm',
					'input_tokens'  => $result['input_tokens'],
					'output_tokens' => $result['output_tokens'],
					'latency_ms'    => $ms,
				)
			);
			$answer = array(
				'reply'    => RAG::guard_answer( $result['text'], $chunks ),
				'provider' => $provider->id(),
				'model'    => $result['model'],
				'source'   => 'llm',
				'sources'  => self::source_links( $chunks, 12 ),
				'attempts' => $attempts,
			);
			if ( $ctx['cache_key'] && $ttl > 0 ) {
				$cache = $answer;
				unset( $cache['attempts'] );
				set_transient( $ctx['cache_key'], $cache, $ttl );
			}
			return $answer;
		}

		// 5. Nothing worked: best effort from the knowledge base.
		if ( ! $ctx['skip_local'] && $chunks ) {
			$local = self::local_answer( $ctx['query'], $chunks, true );
			if ( $local ) {
				$local['attempts'] = $attempts;
				return $local;
			}
		}
		return self::fallback( $chunks, $attempts );
	}

	/**
	 * Providers to try, in order.
	 *
	 * @return \OmniThreadAI\Providers\Base_Provider[]
	 */
	public static function candidates( $mode = null ) {
		$mode = $mode ? $mode : Settings::get( 'mode', 'smart' );
		if ( 'local_only' === $mode ) {
			return array();
		}
		if ( 'single' === $mode ) {
			$primary = Registry::make( Settings::get( 'primary_provider', 'openai' ) );
			return $primary && $primary->is_enabled() && $primary->is_configured() ? array( $primary ) : array();
		}
		$out = array();
		foreach ( Registry::ordered() as $provider ) {
			if ( $provider->is_enabled() && $provider->is_configured() ) {
				$out[] = $provider;
			}
		}
		if ( Settings::get( 'free_first' ) ) {
			// Self-hosted (free) models before paid clouds, keeping the admin's order inside each group.
			$local = array();
			$cloud = array();
			foreach ( $out as $provider ) {
				if ( $provider->is_local() ) {
					$local[] = $provider;
				} else {
					$cloud[] = $provider;
				}
			}
			$out = array_merge( $local, $cloud );
		}
		return $out;
	}

	/**
	 * FAQ match or high-confidence extractive answer.
	 *
	 * @param bool $lenient Accept a lower KB confidence (local-only mode or after all providers failed).
	 */
	private static function local_answer( $query, array $chunks, $lenient ) {
		$faq = Knowledge_Base::match_faq( $query );
		if ( $faq && $faq['score'] >= (float) Settings::get( 'faq_threshold', 0.6 ) ) {
			Usage::log( array( 'answer_source' => 'faq' ) );
			return array(
				'reply'    => $faq['answer'],
				'provider' => '',
				'model'    => '',
				'source'   => 'faq',
				'sources'  => $faq['url'] ? array(
					array(
						'title' => $faq['question'],
						'url'   => $faq['url'],
					),
				) : array(),
				'attempts' => array(),
			);
		}

		if ( ! $lenient && ! Settings::get( 'local_answer_content' ) ) {
			return null;
		}
		$threshold = (float) Settings::get( 'local_threshold', 0.8 );
		if ( $lenient ) {
			$threshold = min( $threshold, 0.5 );
		}
		$first = null;
		foreach ( $chunks as $chunk ) {
			if ( 'faq' === $chunk['type'] || $chunk['score'] < $threshold ) {
				continue;
			}
			// Prefer the first qualifying chunk that yields a real passage over a bare page pointer.
			$extract = Knowledge_Base::extract( $query, $chunk );
			$answer  = array(
				'reply'    => $extract['text'],
				'provider' => '',
				'model'    => '',
				'source'   => 'knowledge',
				'sources'  => self::source_links( array( $chunk ) ),
				'attempts' => array(),
			);
			if ( $extract['rich'] ) {
				$first = $answer;
				break;
			}
			if ( null === $first ) {
				$first = $answer;
			}
		}
		if ( $first ) {
			Usage::log( array( 'answer_source' => 'knowledge' ) );
		}
		return $first;
	}

	private static function fallback( array $chunks, array $attempts ) {
		$message = trim( (string) Settings::get( 'fallback_message', '' ) );
		if ( '' === $message ) {
			$message = $chunks
				? __( 'I could not generate a full answer right now, but these pages look relevant:', 'omnithread-ai' )
				: __( 'Sorry, I don’t have an answer for that yet.', 'omnithread-ai' );
		}
		$contact = trim( (string) Settings::get( 'contact_info', '' ) );
		if ( '' !== $contact ) {
			/* translators: %s: contact information */
			$message .= "\n\n" . sprintf( __( 'You can also reach us here: %s', 'omnithread-ai' ), $contact );
		}
		Usage::log(
			array(
				'answer_source' => 'fallback',
				'success'       => $attempts ? 0 : 1,
				'error'         => $attempts ? 'all providers failed' : '',
			)
		);
		return array(
			'reply'    => $message,
			'provider' => '',
			'model'    => '',
			'source'   => 'fallback',
			'sources'  => self::source_links( $chunks ),
			'attempts' => $attempts,
		);
	}

	public static function source_links( array $chunks, $max = 3 ) {
		$links = array();
		foreach ( $chunks as $chunk ) {
			if ( empty( $chunk['url'] ) || isset( $links[ $chunk['url'] ] ) ) {
				continue;
			}
			$links[ $chunk['url'] ] = array(
				'title' => $chunk['title'],
				'url'   => $chunk['url'],
			);
			if ( count( $links ) >= $max ) {
				break;
			}
		}
		return array_values( $links );
	}
}
