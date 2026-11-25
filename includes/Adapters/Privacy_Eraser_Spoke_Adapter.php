<?php
/**
 * WM Suite DSGVO Privacy Eraser — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface): health, telemetry,
 * certificate chain verification and a self test.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Crypto\RequestCipher;
use WMCyber\DSGVO\Eraser\Database\Schema;

final class Privacy_Eraser_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-dsgvo-privacy-eraser';
	}

	public function get_name(): string {
		return 'WM DSGVO Art. 17 Privacy Eraser';
	}

	public function get_version(): string {
		return defined( 'WM_DSGVO_ERASER_VERSION' ) ? WM_DSGVO_ERASER_VERSION : '1.4.2';
	}

	public function get_description(): string {
		return 'Löschanträge mit Bestätigungslink, Löschung bzw. Anonymisierung von Kommentaren, Benutzerkonto und optional WooCommerce-Rechnungsfeldern, verkettete Löschnachweise.';
	}

	/**
	 * Both tables exist or nothing can be requested or recorded.
	 */
	private static function tables_present(): bool {
		global $wpdb;
		foreach ( array( Schema::get_certificates_table(), Schema::get_requests_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * ERROR when a table is missing or the certificate chain does not verify; DEGRADED when libsodium
	 * is missing (pending addresses are then stored in clear text); HEALTHY otherwise. Until
	 * 2026-09-14 missing tables read as an empty, therefore healthy, chain.
	 */
	public function get_health_status(): string {
		if ( ! self::tables_present() ) {
			return 'ERROR';
		}
		$audit = ( new ErasureCertificateGenerator() )->verify_chain_integrity();
		if ( 'BROKEN' === $audit['status'] ) {
			return 'ERROR';
		}
		return RequestCipher::is_available() ? 'HEALTHY' : 'DEGRADED';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-dsgvo-privacy-eraser@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_DSGVO_ERASER_FILE ),
				],
			],
			'licenses'    => [
				[
					// CycloneDX 1.6 allows id XOR name, and an `id` must come from the SPDX list.
					// Until 2026-09-15 this declared 'Proprietary' as an `id`, which is not one, and
					// the fix then was to move the string to `name`. On 2026-09-19 the licence itself
					// was corrected: the plugin is GPLv2 or later in its header, readme.txt, composer
					// and LICENSE, and GPL-2.0-or-later IS an SPDX identifier — so `id` is now both
					// valid and the better field, because it is the machine-readable one.
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			// Features, not legal labels: the plugin makes no assessment of DSGVO or retention duties.
			'properties'  => [
				[
					'name'  => 'wm:features:requests',
					'value' => 'public request route, single-use confirmation link (24 h), address encrypted with libsodium while pending',
				],
				[
					'name'  => 'wm:features:erasure',
					'value' => 'comments anonymised, non-administrator account deleted, WooCommerce HPOS billing fields optional',
				],
				[
					'name'  => 'wm:features:certificates',
					'value' => 'HMAC-SHA256 chained records with a tip option, verification via REST, WP-CLI and hub',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-dsgvo-eraser' );
	}

	public function get_telemetry_metrics(): array {
		global $wpdb;
		$table_certs = Schema::get_certificates_table();
		$table_reqs  = Schema::get_requests_table();

		$total_certificates = 0;
		$total_purged       = 0;
		$pending_requests   = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live telemetry.
		if ( self::tables_present() ) {
			$total_certificates = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_certs ) );
			$total_purged       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(purged_records_count) FROM %i', $table_certs ) );
			$pending_requests   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending'", $table_reqs ) );
		}
		// phpcs:enable

		$generator = new ErasureCertificateGenerator();
		$audit     = $generator->verify_chain_integrity();

		return [
			'tables_present'        => self::tables_present(),
			'issued_certificates'   => $total_certificates,
			'total_purged_records'  => $total_purged,
			'pending_erasure_reqs'  => $pending_requests,
			'certificate_chain'     => $audit['status'],
			'chain_error'           => $audit['error'],
			'verified_certificates' => $audit['checked'] ?? 0,
			'unchained_legacy'      => $audit['unchained_legacy'] ?? 0,
			'address_encryption'    => RequestCipher::is_available() ? 'libsodium' : 'unavailable',
			'wc_orders_anonymised'  => (bool) get_option( 'wm_dsgvo_anonymize_wc_orders', false ),
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		$generator = new ErasureCertificateGenerator();

		switch ( $action ) {
			case 'self_test':
			case 'verify_chain':
				// Until 2026-09-15 self_test succeeded with both tables missing, and a mismatch at the chain tip
				// was reported as "Eintrag #0".
				if ( 'self_test' === $action && ! self::tables_present() ) {
					return [
						'success' => false,
						'message' => 'Selbsttest fehlgeschlagen: Tabellen des Plugins fehlen.',
					];
				}
				$audit = $generator->verify_chain_integrity();
				$valid = 'BROKEN' !== $audit['status'];
				$where = null === $audit['broken_node'] ? 'am Ende der Kette' : sprintf( 'bei Eintrag #%d', (int) $audit['broken_node'] );
				return [
					'success' => $valid,
					'message' => $valid
						? sprintf( 'Nachweiskette geprüft: Status %s (%d versiegelte Nachweise verifiziert, %d ohne Siegel).', (string) $audit['status'], (int) $audit['checked'], (int) $audit['unchained_legacy'] )
						: sprintf( 'Nachweiskette gebrochen %s: %s.', $where, (string) ( $audit['error'] ?? 'Kettenbruch' ) ),
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}
