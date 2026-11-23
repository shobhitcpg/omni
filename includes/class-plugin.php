<?php
/**
 * Service wiring.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'OmniThread AI requires the PHP mbstring extension. Please ask your host to enable it.', 'omnithread-ai' ) . '</p></div>';
				}
			);
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'OmniThreadAI\\Installer', 'maybe_upgrade' ), 5 );
		add_action( 'rest_api_init', array( 'OmniThreadAI\\Rest_Controller', 'register_routes' ) );
		add_action( Installer::CRON_HOOK, array( __CLASS__, 'maintenance' ) );
		add_action( 'wp_initialize_site', array( 'OmniThreadAI\\Installer', 'on_new_site' ), 20 );
		add_filter( 'wpmu_drop_tables', array( 'OmniThreadAI\\Installer', 'on_delete_site' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );

		Index_Queue::register();
		add_action( Installer::CRON_HOOK, array( 'OmniThreadAI\\Index_Queue', 'reconcile' ) );
		Knowledge_Base::register_hooks();
		Integrations\WooCommerce::register();
		Frontend::register();
		if ( is_admin() ) {
			Admin::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'omnithread-ai', 'OmniThreadAI\\CLI' );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'omnithread-ai', false, dirname( plugin_basename( OTAI_FILE ) ) . '/languages' );
	}

	public static function maintenance() {
		Conversations::purge( max( 1, (int) Settings::get( 'retention_days', 30 ) ) );
		Usage::purge( 365 );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}otai_embedding_budget WHERE day < DATE_SUB(UTC_DATE(), INTERVAL 90 DAY)" );
	}

	public static function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . __( 'This site uses an AI chat assistant. Messages you type are processed to generate answers and may be sent to a third-party AI provider configured by the site owner. Obvious personal data (emails, phone and card numbers) is automatically removed before messages are sent. Conversation transcripts may be stored for quality purposes and are automatically deleted after the configured retention period.', 'omnithread-ai' ) . '</p>';
		wp_add_privacy_policy_content( 'OmniThread AI', wp_kses_post( $content ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['omnithread-ai'] = array(
			'exporter_friendly_name' => __( 'AI chat conversations', 'omnithread-ai' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['omnithread-ai'] = array(
			'eraser_friendly_name' => __( 'AI chat conversations', 'omnithread-ai' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public static function export_personal_data( $email ) {
		$user  = get_user_by( 'email', $email );
		$items = array();
		if ( $user ) {
			foreach ( Conversations::for_user( $user->ID ) as $conversation_id ) {
				foreach ( Conversations::messages( $conversation_id ) as $message ) {
					$items[] = array(
						'group_id'    => 'otai-conversations',
						'group_label' => __( 'AI chat conversations', 'omnithread-ai' ),
						'item_id'     => 'otai-message-' . $message['id'],
						'data'        => array(
							array(
								'name'  => __( 'Date', 'omnithread-ai' ),
								'value' => $message['created_at'],
							),
							array(
								'name'  => __( 'Role', 'omnithread-ai' ),
								'value' => $message['role'],
							),
							array(
								'name'  => __( 'Message', 'omnithread-ai' ),
								'value' => $message['content'],
							),
						),
					);
				}
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase_personal_data( $email ) {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			$ids = Conversations::for_user( $user->ID );
			if ( $ids ) {
				Conversations::delete( $ids );
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
