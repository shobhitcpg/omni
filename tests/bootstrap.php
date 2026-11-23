<?php
/**
 * Isolated WordPress bootstrap. Never uses a site's wp-config.php.
 *
 * Set OTAI_TEST_WP_CORE to a WordPress core directory and OTAI_TEST_DB_HOST to
 * 127.0.0.1:19306, on a dedicated disposable MySQL instance (root, no password).
 */

$core = getenv( 'OTAI_TEST_WP_CORE' );
$host = getenv( 'OTAI_TEST_DB_HOST' );
if ( 'cli' !== PHP_SAPI || '127.0.0.1:19306' !== $host || ! $core || ! is_file( $core . DIRECTORY_SEPARATOR . 'wp-settings.php' ) ) {
	throw new RuntimeException( 'Use the dedicated loopback test database and a valid OTAI_TEST_WP_CORE directory.' );
}
$mysqli = new mysqli( '127.0.0.1', 'root', '', '', 19306 );
$mysqli->query( 'CREATE DATABASE IF NOT EXISTS otai_rag_test CHARACTER SET utf8mb4' );
$mysqli->close();
define( 'ABSPATH', rtrim( $core, '\\/' ) . DIRECTORY_SEPARATOR );
define( 'DB_NAME', 'otai_rag_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', $host );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_INSTALLING', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WPMU_PLUGIN_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'no-mu-plugins' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'WP_CONTENT_DIR', dirname( __DIR__, 3 ) );
$table_prefix = 'wp_';
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	wp_install( 'RAG integration tests', 'rag-test-admin', 'test@example.invalid', false, '', wp_generate_password( 32 ) );
}
wp_installing( false );
require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'omnithread-ai.php';
\OmniThreadAI\Plugin::instance();
