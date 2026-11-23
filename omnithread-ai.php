<?php
/**
 * Plugin Name:       OmniThread AI – Smart Site Assistant
 * Plugin URI:        https://github.com/wpcomvip/my-capgemini
 * Description:       Make any WordPress site AI-enabled. Industry-ready chat assistant with OpenAI, Azure OpenAI, Gemini, Claude, Amazon Bedrock, Ollama and more, plus a cost-saving Smart Query Mode that answers from your own content first.
 * Version:           1.4.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            OmniThread AI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omnithread-ai
 * Domain Path:       /languages
 *
 * @package OmniThreadAI
 */

defined( 'ABSPATH' ) || exit;

define( 'OTAI_VERSION', '1.4.2' );
define( 'OTAI_DB_VERSION', '5' );
define( 'OTAI_FILE', __FILE__ );
define( 'OTAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'OTAI_URL', plugin_dir_url( __FILE__ ) );

$otai_includes = array(
	'class-crypto.php',
	'class-settings.php',
	'class-verticals.php',
	'providers/class-base-provider.php',
	'providers/class-openai-compatible.php',
	'providers/class-openai.php',
	'providers/class-azure-openai.php',
	'providers/class-gemini.php',
	'providers/class-anthropic.php',
	'providers/class-ollama.php',
	'providers/class-bedrock.php',
	'providers/class-omnithread.php',
	'providers/class-registry.php',
	'class-installer.php',
	'class-search-engine.php',
	'class-embeddings.php',
	'class-rag.php',
	'class-index-queue.php',
	'class-setup.php',
	'class-access-policy.php',
	'class-knowledge-base.php',
	'class-usage.php',
	'class-conversations.php',
	'class-rate-limiter.php',
	'class-request-budget.php',
	'class-smart-router.php',
	'integrations/class-woocommerce.php',
	'class-chat-service.php',
	'class-rest-controller.php',
	'class-frontend.php',
	'class-admin.php',
	'class-plugin.php',
);
foreach ( $otai_includes as $otai_file ) {
	require_once OTAI_DIR . 'includes/' . $otai_file;
}
unset( $otai_includes, $otai_file );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OTAI_DIR . 'includes/class-cli.php';
}

register_activation_hook( __FILE__, array( 'OmniThreadAI\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OmniThreadAI\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'OmniThreadAI\\Plugin', 'instance' ) );
