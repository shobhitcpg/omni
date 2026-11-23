<?php
/**
 * Per-IP rate limiting backed by transients (uses the persistent object cache when available).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Rate_Limiter {

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the client IP (e.g. to trust X-Forwarded-For behind a known proxy/CDN).
		 */
		return (string) apply_filters( 'otai_client_ip', $ip );
	}

	public static function ip_hash() {
		return hash_hmac( 'sha256', self::client_ip(), wp_salt( 'nonce' ) );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$hash    = substr( self::ip_hash(), 0, 32 );
		$windows = array(
			'm' => array( MINUTE_IN_SECONDS, (int) Settings::get( 'rate_limit_minute', 12 ) ),
			'd' => array( DAY_IN_SECONDS, (int) Settings::get( 'rate_limit_day', 200 ) ),
		);
		foreach ( $windows as $suffix => $window ) {
			list( $ttl, $limit ) = $window;
			$bucket              = (int) floor( time() / $ttl );
			$key                 = 'otai_rl_' . $suffix . '_' . $hash . '_' . $bucket;
			$count               = (int) get_transient( $key );
			if ( $count >= $limit ) {
				return new \WP_Error(
					'otai_rate_limited',
					'm' === $suffix ? __( 'You are sending messages too quickly. Please wait a moment.', 'omnithread-ai' ) : __( 'Daily message limit reached. Please try again tomorrow.', 'omnithread-ai' ),
					array( 'status' => 429 )
				);
			}
			set_transient( $key, $count + 1, $ttl );
		}
		return true;
	}
}
