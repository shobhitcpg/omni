<?php
/**
 * Allowlisted public site metadata for the optional agent bridge.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Site_Context {

	public static function profile() {
		return array(
			'url'         => home_url( '/' ),
			'name'        => mb_substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 200 ),
			'description' => mb_substr( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ), 0, 1000 ),
			'language'    => mb_substr( get_bloginfo( 'language' ), 0, 35 ),
			'industry'    => mb_substr( (string) Settings::get( 'vertical', 'general' ), 0, 60 ),
		);
	}
}
