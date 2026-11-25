<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DSGVO\Eraser\Frontend\EcosystemIntegration;
use WMCyber\DSGVO\Eraser\Frontend\ErasureConfirmationPage;
use WMCyber\DSGVO\Eraser\Frontend\ErasureRequestForm;
use WMCyber\DSGVO\Eraser\Admin\AdminDashboard;
use WMCyber\DSGVO\Eraser\API\RestController;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;
use WMCyber\DSGVO\Eraser\CLI\PrivacyEraserCommand;

final class Plugin {
	/** Cron hook that expires unconfirmed requests. */
	public const CRON_HOOK = 'wm_dsgvo_expire_requests';

	/** Bumped whenever Schema::create_tables() changes. */
	public const SCHEMA_VERSION = '3';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', function (): void {
			load_plugin_textdomain(
				'wm-dsgvo-privacy-eraser',
				false,
				dirname( plugin_basename( WM_DSGVO_ERASER_FILE ) ) . '/languages'
			);
		} );

		// The confirmation flow added columns to the requests table. create_tables()
		// only runs on activation, so an already-active install would keep the old schema and
		// every request would fail on a missing email_enc column. Called directly rather than
		// hooked: boot() already runs on plugins_loaded, so hooking it there never fires.
		$this->maybe_upgrade_schema();

		// Frontend shortcodes and blocks, ecosystem bridges, and the page behind the mailed link (only its POST erases).
		( new ErasureRequestForm() )->boot();
		( new ErasureConfirmationPage() )->boot();
		( new EcosystemIntegration() )->boot();

		// Admin Dashboard
		if ( is_admin() ) {
			AdminDashboard::instance()->boot();
		}

		// REST API
		add_action( 'rest_api_init', function (): void {
			( new RestController() )->register_routes();
		} );

		// A request nobody confirms used to keep its address forever, because expiry only ran
		// when somebody happened to click a stale link.
		add_action( self::CRON_HOOK, array( ErasureRequestManager::class, 'purge_expired' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}

		// WP-CLI
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'dsgvo', PrivacyEraserCommand::class );
		}

		/**
		 * Fires after WM DSGVO Privacy Eraser has registered its hooks and upgraded its schema.
		 *
		 * @param Plugin $plugin Active plugin instance.
		 */
		do_action( 'wm_eraser_ready', $this );
	}

	/**
	 * Run dbDelta when the stored schema version is behind the plugin.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema(): void {
		$stored = (string) get_option( 'wm_dsgvo_schema_version', '0' );

		if ( version_compare( $stored, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		\WMCyber\DSGVO\Eraser\Database\Schema::create_tables();
		update_option( 'wm_dsgvo_schema_version', self::SCHEMA_VERSION, false );
	}
}
