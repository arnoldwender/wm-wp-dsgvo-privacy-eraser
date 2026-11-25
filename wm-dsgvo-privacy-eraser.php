<?php
/**
 * Plugin Name:       WM DSGVO Art. 17 Cryptographic Privacy Eraser
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-dsgvo-privacy-eraser/
 * Description:       Löschanträge nach Art. 17 DSGVO mit Bestätigungslink an die Adresse; danach werden Kommentare anonymisiert, das Benutzerkonto (außer Administratoren) gelöscht und optional WooCommerce-Rechnungsfelder anonymisiert. Jede Löschung erhält einen HMAC-verketteten Nachweis. Keine rechtliche Prüfung des Antrags.
 * Version:           1.4.2
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-dsgvo-privacy-eraser
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WM_DSGVO_ERASER_VERSION', '1.4.2' );
define( 'WM_DSGVO_ERASER_FILE', __FILE__ );
define( 'WM_DSGVO_ERASER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WM_DSGVO_ERASER_URL', plugin_dir_url( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix   = 'WMCyber\\DSGVO\\Eraser\\';
	$base_dir = WM_DSGVO_ERASER_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

if ( ! function_exists( __NAMESPACE__ . '\\activate_site' ) ) {
	/**
	 * Create database tables and record the schema version for the current site.
	 *
	 * @return void
	 */
	function activate_site(): void {
		Database\Schema::create_tables();
		update_option( 'wm_dsgvo_schema_version', Core\Plugin::SCHEMA_VERSION, false );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\activate_site_on_blog' ) ) {
	/**
	 * Switch to a blog, initialize schema and options, and always restore the caller's blog.
	 *
	 * @param int $blog_id Target blog ID.
	 * @return void
	 */
	function activate_site_on_blog( int $blog_id ): void {
		switch_to_blog( $blog_id );
		try {
			activate_site();
		} finally {
			restore_current_blog();
		}
	}
}

// DB Activation
register_activation_hook( __FILE__, function ( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
		$site_ids = get_sites( [
			'fields' => 'ids',
			'number' => 0,
		] );
		foreach ( $site_ids as $site_id ) {
			activate_site_on_blog( (int) $site_id );
		}
		return;
	}

	activate_site();
} );

// Initialize tables and schema version when a new site joins a network-activated Multisite install.
add_action( 'wp_initialize_site', function ( $new_site ): void {
	if ( ! is_multisite() ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}
	if ( ! is_object( $new_site ) || ! isset( $new_site->blog_id ) ) {
		return;
	}
	$blog_id = (int) $new_site->blog_id;
	if ( $blog_id <= 0 ) {
		return;
	}

	activate_site_on_blog( $blog_id );
} );

// Leaving the sweep scheduled after deactivation would fire a hook nothing handles.
register_deactivation_hook( __FILE__, function (): void {
	$next = wp_next_scheduled( Core\Plugin::CRON_HOOK );
	if ( $next ) {
		wp_unschedule_event( $next, Core\Plugin::CRON_HOOK );
	}
} );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WMCyber\DSGVO\Eraser\Adapters\Privacy_Eraser_Spoke_Adapter' ) && file_exists( WM_DSGVO_ERASER_PATH . 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php' ) ) {
		require_once WM_DSGVO_ERASER_PATH . 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php';
	}
	if ( class_exists( 'WMCyber\DSGVO\Eraser\Adapters\Privacy_Eraser_Spoke_Adapter' ) ) {
		$modules[] = new Adapters\Privacy_Eraser_Spoke_Adapter();
	}
	return $modules;
} );

// Bootstrap
add_action( 'plugins_loaded', function (): void {
	Core\Plugin::instance()->boot();
} );

