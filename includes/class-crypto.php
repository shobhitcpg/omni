<?php
/**
 * Symmetric encryption for secrets (API keys) stored in the database.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Crypto {

	const PREFIX       = 'otai1:';
	const PLAIN_PREFIX = 'otai0:';

	private static function master_key() {
		if ( defined( 'OTAI_ENCRYPTION_KEY' ) && OTAI_ENCRYPTION_KEY ) {
			$secret = OTAI_ENCRYPTION_KEY;
		} elseif ( function_exists( 'wp_salt' ) ) {
			$secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		} else {
			$secret = 'omnithread-ai';
		}
		return hash( 'sha256', $secret, true );
	}

	private static function subkey( $purpose ) {
		return hash_hmac( 'sha256', $purpose, self::master_key(), true );
	}

	public static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' );
	}

	public static function is_encrypted( $value ) {
		return is_string( $value ) && ( 0 === strpos( $value, self::PREFIX ) || 0 === strpos( $value, self::PLAIN_PREFIX ) );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || self::is_encrypted( $plain ) ) {
			return $plain;
		}
		if ( ! self::available() ) {
			return self::PLAIN_PREFIX . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::subkey( 'enc' ), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, self::subkey( 'mac' ), true );
		return self::PREFIX . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Returns the plain value, or '' if the value cannot be decrypted (e.g. salts rotated).
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, self::PLAIN_PREFIX ) ) {
			return (string) base64_decode( substr( $value, strlen( self::PLAIN_PREFIX ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value; // Legacy/plain value.
		}
		if ( ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		$calc   = hash_hmac( 'sha256', $iv . $cipher, self::subkey( 'mac' ), true );
		if ( ! hash_equals( $mac, $calc ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', self::subkey( 'enc' ), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	public static function mask( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$len = strlen( $plain );
		return $len <= 8 ? str_repeat( '•', 8 ) : substr( $plain, 0, 3 ) . str_repeat( '•', 8 ) . substr( $plain, -4 );
	}
}
