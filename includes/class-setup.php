<?php
/**
 * Customer onboarding, explicit publication and allowlisted support diagnostics.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

use OmniThreadAI\Providers\Registry;

defined( 'ABSPATH' ) || exit;

class Setup {

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_otai_setup', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_otai_support', array( __CLASS__, 'download' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'health_tests' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=omnithread-ai-setup' );
	}

	public static function published() {
		return Settings::get( 'enabled' ) && ! get_option( 'otai_setup_required' );
	}

	public static function menu() {
		add_submenu_page( Admin::SLUG, __( 'Setup & Readiness', 'omnithread-ai' ), __( 'Setup & Readiness', 'omnithread-ai' ), Admin::capability(), 'omnithread-ai-setup', array( __CLASS__, 'page' ) );
	}

	public static function notice() {
		if ( current_user_can( Admin::capability() ) && Access_Policy::systems() && Knowledge_Base::indexable_post_types() && ! has_filter( 'otai_index_post_allowed' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'OmniThread AI has blocked post sources because a recognised membership/LMS system is active without an access connector. Curated FAQs remain available. Review Setup & Readiness before using post-based answers.', 'omnithread-ai' ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Review access policy', 'omnithread-ai' ) . '</a></p></div>';
		}
		if ( current_user_can( Admin::capability() ) && get_option( 'otai_setup_required' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'OmniThread AI is not published. Complete setup, test your configuration and launch when ready.', 'omnithread-ai' ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open setup', 'omnithread-ai' ) . '</a></p></div>';
		}
	}

	private static function fingerprint( $service ) {
		if ( 'provider' === $service ) {
			$id = Settings::get( 'primary_provider' );
			$config = array( $id, Settings::provider_config( $id ) );
		} elseif ( 'embedding' === $service ) {
			$config = array( Embeddings::config(), Settings::get( 'redact_pii' ), Settings::get( 'mode' ), Settings::get( 'rag_cloud_consent' ) );
		} else {
			$config = array( Search_Engine::config(), Embeddings::profile() );
		}
		return hash_hmac( 'sha256', wp_json_encode( $config ), wp_salt( 'auth' ) );
	}

	public static function record_test( $service, $result ) {
		if ( ! in_array( $service, array( 'provider', 'embedding', 'backend' ), true ) ) {
			return;
		}
		$tests = get_option( 'otai_setup_tests', array() );
		$tests = is_array( $tests ) ? $tests : array();
		$tests[ $service ] = array(
			'fingerprint' => self::fingerprint( $service ), 'time' => time(),
			'ok' => ! is_wp_error( $result ) && false !== $result,
		);
		update_option( 'otai_setup_tests', $tests, false );
	}

	public static function tested( $service, $require_recent = true ) {
		$tests = get_option( 'otai_setup_tests', array() );
		$test = $tests[ $service ] ?? array();
		return ! empty( $test['ok'] ) && ! empty( $test['time'] )
			&& ( ! $require_recent || $test['time'] > time() - DAY_IN_SECONDS )
			&& isset( $test['fingerprint'] ) && hash_equals( self::fingerprint( $service ), $test['fingerprint'] );
	}

	/**
	 * Configuration/storage checks only. Never probes remote services on page load.
	 */
	public static function checks( $for_launch = false ) {
		global $wpdb;
		$checks = array();
		$recent_test = $for_launch || ! self::published();
		$add = function ( $id, $ok, $label, $remedy, $warning = false ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'status' => $ok ? 'good' : ( $warning ? 'warning' : 'blocked' ), 'label' => $label, 'remedy' => $ok ? '' : $remedy );
		};
		$add( 'runtime', function_exists( 'mb_strtolower' ) && Crypto::available(), __( 'Required PHP extensions', 'omnithread-ai' ), __( 'Ask your host to enable mbstring and OpenSSL.', 'omnithread-ai' ) );
		$add( 'supported_php', version_compare( PHP_VERSION, '8.3', '>=' ), __( 'Maintained PHP baseline', 'omnithread-ai' ), __( 'Use a currently supported PHP release; PHP 8.3+ is recommended.', 'omnithread-ai' ), true );
		$schema = get_option( 'otai_db_version' ) === OTAI_DB_VERSION;
		foreach ( Installer::table_names() as $name ) {
			$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $name ) ) );
			if ( $wpdb->last_error || ! $table ) {
				$schema = false;
				break;
			}
		}
		$add( 'schema', $schema, __( 'Plugin database schema', 'omnithread-ai' ), __( 'Database installation is incomplete. Check CREATE/ALTER permissions and reactivate the plugin.', 'omnithread-ai' ) );
		$chunks = $schema ? $wpdb->get_var( 'SELECT id FROM ' . Knowledge_Base::table() . ' LIMIT 1' ) : null;
		$storage_ok = ! $wpdb->last_error;
		$add( 'knowledge', $storage_ok && $chunks && ! get_option( 'otai_needs_index' ), __( 'Knowledge base built', 'omnithread-ai' ), __( 'Choose public sources, queue Rebuild knowledge base and process the jobs before launch.', 'omnithread-ai' ) );
		$jobs = $schema ? Index_Queue::stats() : array();
		$queue_ok = $schema && ! $wpdb->last_error;
		$pending = 0;
		foreach ( $jobs as $job ) {
			if ( in_array( $job['status'], array( 'pending', 'running', 'failed' ), true ) ) {
				$pending += (int) $job['jobs'];
			}
		}
		$add( 'queue', $queue_ok && 0 === $pending && ! Settings::get( 'rag_queue_paused' ), __( 'Initial indexing completed', 'omnithread-ai' ), __( 'Resume/process pending jobs. Inspect failures, correct the service configuration and retry failed jobs.', 'omnithread-ai' ) );
		$add( 'scheduler', wp_next_scheduled( Index_Queue::HOOK ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), __( 'Background scheduling', 'omnithread-ai' ), __( 'WordPress cron is disabled or unscheduled. Configure a real server scheduler for wp omnithread-ai work; verify it runs for every site.', 'omnithread-ai' ), true );
		$add( 'sync', (bool) Settings::get( 'auto_sync' ), __( 'Automatic content updates', 'omnithread-ai' ), __( 'Enable auto-sync or operate a documented manual refresh process.', 'omnithread-ai' ), true );
		$add( 'https', is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ), __( 'HTTPS deployment', 'omnithread-ai' ), __( 'Enable HTTPS before collecting customer messages in production.', 'omnithread-ai' ), true );
		$add( 'privacy', (bool) get_option( 'otai_setup_privacy_reviewed' ), __( 'Owner privacy/access review', 'omnithread-ai' ), __( 'Review outbound services, public sources, membership restrictions and transcript retention, then acknowledge below.', 'omnithread-ai' ) );
		$add( 'access_policy', ! Access_Policy::systems() || ! Knowledge_Base::indexable_post_types() || has_filter( 'otai_index_post_allowed' ), __( 'External content-access policy', 'omnithread-ai' ), __( 'A recognised membership/LMS system is active. Post indexing/retrieval is denied by default. Install an access-aware otai_index_post_allowed connector that allows only anonymously public content, or use curated FAQs/custom knowledge with no post types. A registered callback is not proof of correct access rules; test it.', 'omnithread-ai' ) );
		if ( 'local_only' !== Settings::get( 'mode' ) ) {
			$provider = Registry::make( Settings::get( 'primary_provider' ) );
			$add( 'provider', $provider && $provider->is_enabled() && $provider->is_configured(), __( 'Primary answer provider', 'omnithread-ai' ), __( 'Enable/configure your chosen primary provider under AI Providers.', 'omnithread-ai' ) );
			$uncapped = false;
			foreach ( Smart_Router::candidates() as $candidate ) {
				if ( ! $candidate->is_local() && ! $candidate->daily_limit() ) {
					$uncapped = true;
				}
			}
			$add( 'provider_budget', ! $uncapped, __( 'Answer-provider attempt caps', 'omnithread-ai' ), __( 'At least one eligible cloud provider has no daily attempt cap. Set a cap under AI Providers and a currency spending limit with each upstream provider.', 'omnithread-ai' ), true );
			$add( 'provider_test', self::tested( 'provider', $recent_test ), __( 'Answer model tested', 'omnithread-ai' ), __( 'Run the primary-provider test after saving configuration. Launch requires a test within 24 hours; changed credentials/model invalidate the saved result.', 'omnithread-ai' ) );
		}
		if ( 'keyword' !== Settings::get( 'rag_retrieval' ) ) {
			$add( 'embedding_policy', ! is_wp_error( Embeddings::policy() ), __( 'Embedding consent/configuration', 'omnithread-ai' ), __( 'Review embedding model, dimensions, endpoint, credentials and outbound approval in RAG Pipeline.', 'omnithread-ai' ) );
			$add( 'embedding_test', self::tested( 'embedding', $recent_test ), __( 'Embedding model tested', 'omnithread-ai' ), __( 'Run the embedding test after saving configuration.', 'omnithread-ai' ) );
			$add( 'backend_test', self::tested( 'backend', $recent_test ), __( 'Vector index tested', 'omnithread-ai' ), __( 'Run the vector-backend test after saving configuration.', 'omnithread-ai' ) );
		}
		$add( 'grounding', (bool) Settings::get( 'rag_grounded_only' ), __( 'No-evidence abstention', 'omnithread-ai' ), __( 'Enable grounded-only answers for a site-grounded assistant.', 'omnithread-ai' ), true );
		return $checks;
	}

	public static function blockers() {
		return array_values( array_filter( self::checks( true ), function ( $check ) { return 'blocked' === $check['status']; } ) );
	}

	public static function launch() {
		$blockers = self::blockers();
		if ( $blockers ) {
			return new \WP_Error( 'otai_not_ready', __( 'Resolve all blocked readiness checks before publishing.', 'omnithread-ai' ) );
		}
		$settings = Settings::all();
		$settings['enabled'] = 1;
		update_option( Settings::OPTION, $settings, false );
		Settings::flush();
		if ( ! Settings::get( 'enabled' ) ) {
			return new \WP_Error( 'otai_publish_storage', __( 'Cannot persist the publication setting.', 'omnithread-ai' ) );
		}
		delete_option( 'otai_setup_required' );
		if ( get_option( 'otai_setup_required' ) ) {
			return new \WP_Error( 'otai_publish_storage', __( 'Cannot clear the unpublished setup state.', 'omnithread-ai' ) );
		}
		update_option( 'otai_setup_completed', time(), false );
		return true;
	}

	public static function save( array $input ) {
		$profile = $input['profile'] ?? '';
		$display = $input['display'] ?? '';
		$vertical = $input['vertical'] ?? '';
		if ( ! is_string( $vertical ) || ! in_array( $profile, array( 'local', 'byok' ), true ) || ! in_array( $display, array( 'floating', 'shortcode_only', 'selected' ), true ) || ! isset( Verticals::all()[ $vertical ] ) ) {
			return new \WP_Error( 'otai_setup_input', __( 'Choose a valid setup profile, industry and display mode.', 'omnithread-ai' ) );
		}
		$settings = array( 'vertical' => $vertical, 'display' => $display, 'store_conversations' => empty( $input['transcripts'] ) ? 0 : 1 );
		if ( 'local' === $profile ) {
			$settings['mode'] = 'local_only';
			$settings['rag_retrieval'] = 'keyword';
			$settings['local_first'] = 1;
			$settings['local_answer_content'] = 1;
		} else {
			$id = $input['primary_provider'] ?? '';
			if ( ! in_array( $id, Registry::ids(), true ) ) {
				return new \WP_Error( 'otai_setup_input', __( 'Choose a valid primary provider.', 'omnithread-ai' ) );
			}
			$settings['mode'] = 'single';
			$settings['primary_provider'] = $id;
		}
		if ( ! empty( $input['apply_preset'] ) ) {
			$settings = array_merge( Verticals::recommended_settings( $vertical ), $settings );
		}
		$expected = Settings::sanitize( $settings );
		update_option( Settings::OPTION, $expected, false );
		Settings::flush();
		foreach ( $settings as $key => $value ) {
			if ( Settings::get( $key ) !== $expected[ $key ] ) {
				return new \WP_Error( 'otai_setup_storage', __( 'Setup settings could not be saved.', 'omnithread-ai' ) );
			}
		}
		update_option( 'otai_setup_privacy_reviewed', empty( $input['privacy_reviewed'] ) ? 0 : time(), false );
		$reviewed = ! empty( $input['privacy_reviewed'] );
		if ( $reviewed !== (bool) get_option( 'otai_setup_privacy_reviewed' ) ) {
			return new \WP_Error( 'otai_setup_storage', __( 'The privacy review acknowledgment could not be saved.', 'omnithread-ai' ) );
		}
		return true;
	}

	public static function run_test( $service ) {
		if ( 'provider' === $service ) {
			$result = Admin::test_provider( Settings::get( 'primary_provider' ) );
		} elseif ( 'embedding' === $service ) {
			$result = Embeddings::embed( array( 'Connectivity test for site search.' ) );
		} elseif ( 'backend' === $service ) {
			$result = RAG::ensure_index();
		} else {
			return new \WP_Error( 'otai_setup_test', __( 'Unknown connection test.', 'omnithread-ai' ) );
		}
		self::record_test( $service, $result );
		return $result;
	}

	public static function handle() {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'omnithread-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'otai_setup' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( 'save' === $operation ) {
			$input = array();
			foreach ( array( 'profile', 'display', 'vertical', 'transcripts', 'primary_provider', 'apply_preset', 'privacy_reviewed' ) as $key ) {
				if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
					$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				}
			}
			$result = self::save( $input );
		} elseif ( 'launch' === $operation ) {
			$result = self::launch();
		} else {
			$result = self::run_test( $operation );
		}
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url() ) );
		exit;
	}

	/**
	 * Explicit allowlist: no settings dump, URLs, paths, errors, prompts or keys.
	 */
	public static function report() {
		global $wp_version;
		$checks = array_map( function ( $check ) { return array( 'id' => $check['id'], 'status' => $check['status'] ); }, self::checks() );
		return array(
			'plugin_version' => OTAI_VERSION, 'wordpress_version' => $wp_version, 'php_version' => PHP_VERSION,
			'multisite' => is_multisite(), 'published' => (bool) self::published(),
			'retrieval' => Settings::get( 'rag_retrieval' ), 'routing' => Settings::get( 'mode' ),
			'checks' => $checks, 'generated_at_utc' => gmdate( 'c' ),
		);
	}

	public static function download() {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'omnithread-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'otai_support' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="omnithread-ai-support.json"' );
		echo wp_json_encode( self::report(), JSON_PRETTY_PRINT );
		exit;
	}

	public static function health_tests( $tests ) {
		$tests['direct']['omnithread_ai'] = array( 'label' => __( 'OmniThread AI readiness', 'omnithread-ai' ), 'test' => array( __CLASS__, 'health' ) );
		return $tests;
	}

	public static function health() {
		$checks = self::checks();
		$issues = array_filter( $checks, function ( $check ) { return 'good' !== $check['status']; } );
		$blocked = array_filter( $checks, function ( $check ) { return 'blocked' === $check['status']; } );
		$description = '<ul>';
		foreach ( $issues as $check ) {
			$description .= '<li>' . esc_html( $check['label'] . ': ' . $check['remedy'] ) . '</li>';
		}
		$description .= '</ul>';
		return array(
			'label' => $issues ? __( 'OmniThread AI needs configuration review', 'omnithread-ai' ) : __( 'OmniThread AI configuration checks passed', 'omnithread-ai' ),
			'status' => $blocked && self::published() ? 'critical' : ( $issues ? 'recommended' : 'good' ),
			'badge' => array( 'label' => 'OmniThread AI', 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'These local checks do not certify service availability, answer quality or enterprise readiness.', 'omnithread-ai' ) . '</p>' . $description,
			'actions' => '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Review setup', 'omnithread-ai' ) . '</a>',
			'test' => 'omnithread_ai',
		);
	}

	private static function button( $operation, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( 'otai_setup' );
		echo '<input type="hidden" name="action" value="otai_setup"><button class="button" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function page() {
		if ( ! current_user_can( Admin::capability() ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Setup & Readiness', 'omnithread-ai' ) . '</h1>';
		if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Operation completed. Review the refreshed readiness checks below.', 'omnithread-ai' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Standalone BYOK: no seller account, telemetry or mandatory cloud subscription. Basic site-search answers need no AI key. Generative answers require a service you configure and pay for separately.', 'omnithread-ai' ) . '</p>';
		echo '<p><strong>' . esc_html( self::published() ? __( 'Published', 'omnithread-ai' ) : __( 'Not published', 'omnithread-ai' ) ) . '</strong></p>';
		echo '<h2>' . esc_html__( '1. Choose your experience', 'omnithread-ai' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'otai_setup' );
		echo '<input type="hidden" name="action" value="otai_setup"><input type="hidden" name="operation" value="save">';
		$fields = array(
			'profile' => array( __( 'Experience', 'omnithread-ai' ), array( 'local' => __( 'Local site search (no model needed)', 'omnithread-ai' ), 'byok' => __( 'My own AI provider (single provider, no automatic paid failover)', 'omnithread-ai' ) ), 'local_only' === Settings::get( 'mode' ) ? 'local' : 'byok' ),
			'vertical' => array( __( 'Site industry', 'omnithread-ai' ), wp_list_pluck( Verticals::all(), 'label' ), Settings::get( 'vertical' ) ),
			'primary_provider' => array( __( 'Primary answer provider', 'omnithread-ai' ), array_combine( Registry::ids(), array_map( function ( $def ) { return $def[1]; }, Registry::definitions() ) ), Settings::get( 'primary_provider' ) ),
			'display' => array( __( 'Display', 'omnithread-ai' ), array( 'shortcode_only' => __( 'Only where I add the shortcode', 'omnithread-ai' ), 'floating' => __( 'Floating bubble across the site', 'omnithread-ai' ), 'selected' => __( 'Selected pages (configure targeting in General settings)', 'omnithread-ai' ) ), Settings::get( 'display' ) ),
		);
		foreach ( $fields as $name => $field ) {
			echo '<p><label for="otai-setup-' . esc_attr( $name ) . '">' . esc_html( $field[0] ) . '</label><br><select id="otai-setup-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $field[1] as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $field[2], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		}
		foreach ( array(
			'apply_preset' => array( false, __( 'Apply industry prompt/model recommendations (replaces current preset-controlled settings)', 'omnithread-ai' ) ),
			'transcripts' => array( Settings::get( 'store_conversations' ), __( 'Store conversation transcripts under the configured retention policy', 'omnithread-ai' ) ),
			'privacy_reviewed' => array( get_option( 'otai_setup_privacy_reviewed' ), __( 'I reviewed public content/access rules, external data processing and retention. Membership-protected sources require an access connector.', 'omnithread-ai' ) ),
		) as $name => $field ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $field[0], true, false ) . '> ' . esc_html( $field[1] ) . '</label></p>';
		}
		submit_button( __( 'Save setup choices', 'omnithread-ai' ) );
		echo '</form><h2>' . esc_html__( '2. Connect, build and test', 'omnithread-ai' ) . '</h2><p>';
		foreach ( array( 'providers' => __( 'AI Providers', 'omnithread-ai' ), 'knowledge' => __( 'Content sources', 'omnithread-ai' ), 'search' => __( 'Search Engine', 'omnithread-ai' ), 'rag' => __( 'RAG Pipeline', 'omnithread-ai' ), 'privacy' => __( 'Privacy', 'omnithread-ai' ) ) as $tab => $label ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=omnithread-ai-settings&tab=' . $tab ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=omnithread-ai-knowledge' ) ) . '">' . esc_html__( 'Build knowledge base', 'omnithread-ai' ) . '</a></p>';
		echo '<p>' . esc_html__( 'Save settings before testing. These buttons contact your configured services: the answer test may incur a charge, the embedding test reserves one input, and the backend test may create the plugin vector index. No site content is sent by these connectivity tests.', 'omnithread-ai' ) . '</p>';
		self::button( 'provider', __( 'Test primary answer provider', 'omnithread-ai' ) );
		self::button( 'embedding', __( 'Test embedding model', 'omnithread-ai' ) );
		self::button( 'backend', __( 'Test vector index', 'omnithread-ai' ) );
		echo '<h2>' . esc_html__( '3. Review and launch', 'omnithread-ai' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'omnithread-ai' ) . '</th><th>' . esc_html__( 'Status', 'omnithread-ai' ) . '</th><th>' . esc_html__( 'Next action', 'omnithread-ai' ) . '</th></tr></thead><tbody>';
		foreach ( self::checks( true ) as $check ) {
			echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td>' . esc_html( $check['status'] ) . '</td><td>' . esc_html( $check['remedy'] ) . '</td></tr>';
		}
		echo '</tbody></table><p>' . esc_html__( 'These checks gate a new launch. Expired tests do not automatically unpublish an existing assistant or require daily paid tests. Launch requires no blocked checks; review warnings. This is a configuration gate, not a production or compliance certificate. Verify real questions and source accuracy before launch.', 'omnithread-ai' ) . '</p>';
		self::button( 'launch', __( 'Publish assistant', 'omnithread-ai' ) );
		echo '<p><code>[omnithread_ai_chat]</code> ' . esc_html__( 'Use this shortcode in your chosen page when using shortcode-only display. Use the Enabled switch in General settings to stop the assistant.', 'omnithread-ai' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'otai_support' );
		echo '<input type="hidden" name="action" value="otai_support">';
		submit_button( __( 'Download safe support report', 'omnithread-ai' ), 'secondary' );
		echo '<p>' . esc_html__( 'Includes versions, mode and check statuses only. Excludes keys, endpoints, site identity, content, conversations and raw error messages. Review before sharing. Nothing is uploaded automatically.', 'omnithread-ai' ) . '</p></form></div>';
	}
}
