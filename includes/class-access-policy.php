<?php
/**
 * Fail-closed defaults for recognised external content-access systems.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Access_Policy {

	public static function systems() {
		$known = array(
			'memberpress' => 'MemberPress',
			'paid-memberships-pro' => 'Paid Memberships Pro',
			'restrict-content-pro' => 'Restrict Content Pro',
			'restrict-content' => 'Restrict Content',
			'woocommerce-memberships' => 'WooCommerce Memberships',
			'simple-membership' => 'Simple Membership',
			'ultimate-member' => 'Ultimate Member',
			'groups' => 'Groups',
			'sfwd-lms' => 'LearnDash',
			'learndash' => 'LearnDash',
			'learnpress' => 'LearnPress',
			'tutor' => 'Tutor LMS',
			'lifterlms' => 'LifterLMS',
			'sensei-lms' => 'Sensei LMS',
			'wishlist-member' => 'Wishlist Member',
			'wishlist-member-x' => 'Wishlist Member',
			's2member' => 's2Member',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$systems = array();
		foreach ( $active as $plugin ) {
			$directory = strtok( (string) $plugin, '/' );
			if ( isset( $known[ $directory ] ) ) {
				$systems[ $directory ] = $known[ $directory ];
			}
		}
		/**
		 * MU/custom access systems must register here (id => label).
		 * Recognition blocks posts by default; it does not grant access.
		 */
		return (array) apply_filters( 'otai_access_systems', $systems );
	}

	public static function default_allowed() {
		return ! self::systems();
	}
}
