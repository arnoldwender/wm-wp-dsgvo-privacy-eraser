<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Crypto\RequestCipher;
use WMCyber\DSGVO\Eraser\Database\Schema;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;

/**
 * Admin screen (menu "Privacy Eraser") and dashboard widget.
 *
 * Until 2026-09-14 the screen was Spanish and: its "Asistente de Borrado" button promised an immediate
 * erasure and certificate but posted to /request, which only mails a confirmation link, then showed
 * "Error" although the mail had gone out; the certificate table read the columns email_hmac and
 * created_at, which do not exist; the chain card printed "100% INTACTA" next to an unused verification
 * result; badges said "CERTIFICADOS INMUTABLES" (the chain's own docblock says it is not immutable);
 * the widget counted a table that does not exist and advertised PDF certificates nobody generates.
 */
final class AdminDashboard {
	private static ?AdminDashboard $instance = null;

	public static function instance(): AdminDashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/**
	 * Sanitize checkbox setting to 0 or 1.
	 *
	 * @param mixed $value Submitted option value.
	 */
	public static function sanitize_checkbox( $value ): int {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
	}

	public function register_settings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			'wm_dsgvo_eraser_settings',
			'wm_dsgvo_anonymize_wc_orders',
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ self::class, 'sanitize_checkbox' ],
				'default'           => 0,
			]
		);

		register_setting(
			'wm_dsgvo_eraser_settings',
			'wm_dsgvo_eraser_delete_data_on_uninstall',
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ self::class, 'sanitize_checkbox' ],
				'default'           => 0,
			]
		);
	}

	/**
	 * Measured state of the install, read on every render.
	 *
	 * @return array{certificates:int, purged:int, holding:int, chain:array, cipher:bool, wc:bool, delete_on_uninstall:bool}
	 */
	public static function stats(): array {
		global $wpdb;
		$certs = Schema::get_certificates_table();
		$reqs  = Schema::get_requests_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts for the admin screen.
		$certificates = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $certs ) );
		$purged       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(purged_records_count) FROM %i', $certs ) );
		$holding      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE email_enc IS NOT NULL AND email_enc <> ''", $reqs ) );
		// phpcs:enable

		return [
			'certificates'        => $certificates,
			'purged'              => $purged,
			'holding'             => $holding,
			'chain'               => ( new ErasureCertificateGenerator() )->verify_chain_integrity(),
			'cipher'              => RequestCipher::is_available(),
			'wc'                  => (bool) get_option( 'wm_dsgvo_anonymize_wc_orders', false ),
			'delete_on_uninstall' => (bool) get_option( 'wm_dsgvo_eraser_delete_data_on_uninstall', false ),
		];
	}

	/** German label for a chain status. */
	private static function chain_label( array $chain ): string {
		return match ( $chain['status'] ) {
			'INTACT'      => sprintf(
				/* translators: %d: number of verified certificates */
				__( 'Kette geprüft: %d versiegelte Nachweise stimmen', 'wm-dsgvo-privacy-eraser' ),
				(int) $chain['checked']
			),
			'EMPTY_CHAIN' => __( 'Noch keine Löschnachweise', 'wm-dsgvo-privacy-eraser' ),
			default       => __( 'Kette gebrochen', 'wm-dsgvo-privacy-eraser' ),
		};
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_dsgvo_eraser_widget',
			__( 'WM DSGVO Privacy Eraser', 'wm-dsgvo-privacy-eraser' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		$stats  = self::stats();
		$broken = 'BROKEN' === $stats['chain']['status'];
		?>
		<div class="wm-dash-widget-box">
			<div class="wm-dash-banner <?php echo $broken ? 'is-warning' : 'is-valid'; ?>">
				<span class="dashicons <?php echo $broken ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
				<div>
					<div class="wm-dash-banner-title"><?php echo esc_html( self::chain_label( $stats['chain'] ) ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( $stats['certificates'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Löschnachweise', 'wm-dsgvo-privacy-eraser' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( $stats['holding'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Offene Anträge', 'wm-dsgvo-privacy-eraser' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-dsgvo-eraser' ) ); ?>" class="wm-dash-btn">
					<?php esc_html_e( 'Privacy Eraser öffnen', 'wm-dsgvo-privacy-eraser' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WM Privacy Eraser', 'wm-dsgvo-privacy-eraser' ),
			__( 'Privacy Eraser', 'wm-dsgvo-privacy-eraser' ),
			'manage_options',
			'wm-dsgvo-eraser',
			[ $this, 'render_dashboard' ],
			self::family_menu_icon(),
			86
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_wm-dsgvo-eraser' ) {
			return;
		}

		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		wp_enqueue_style(
			'wm-admin-tokens',
			WM_DSGVO_ERASER_URL . 'assets/css/wm-admin-tokens.css',
			[],
			(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_DSGVO_ERASER_VERSION )
		);
		wp_enqueue_style(
			'wm-admin-ui',
			WM_DSGVO_ERASER_URL . 'assets/css/wm-admin-ui.css',
			[ 'wm-admin-tokens' ],
			(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_DSGVO_ERASER_VERSION )
		);
	}

	public function render_dashboard(): void {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
		if ( ! in_array( $active_tab, [ 'overview', 'request', 'certificates', 'limits', 'settings' ], true ) ) {
			$active_tab = 'overview';
		}
		$tabs = [
			'overview'     => __( 'Übersicht', 'wm-dsgvo-privacy-eraser' ),
			'request'      => __( 'Antrag stellen', 'wm-dsgvo-privacy-eraser' ),
			'certificates' => __( 'Löschnachweise', 'wm-dsgvo-privacy-eraser' ),
			'limits'       => __( 'Was gelöscht wird', 'wm-dsgvo-privacy-eraser' ),
			'settings'     => __( 'Einstellungen', 'wm-dsgvo-privacy-eraser' ),
		];

		$stats = self::stats();
		$chain = $stats['chain'];
		?>
		<div class="wrap wm-admin">
			<header class="wm-admin-header">
				<div class="wm-header-title">
					<h1><?php esc_html_e( 'WM DSGVO Privacy Eraser', 'wm-dsgvo-privacy-eraser' ); ?></h1>
					<p class="wm-subtitle"><?php esc_html_e( 'Löschanträge mit Bestätigungslink, Löschung bzw. Anonymisierung der Daten, die WordPress selbst zu einer E-Mail-Adresse hält, und ein verketteter Nachweis je Löschung. Es ersetzt keine rechtliche Prüfung des Antrags.', 'wm-dsgvo-privacy-eraser' ); ?></p>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-dsgvo-privacy-eraser' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-dsgvo-privacy-eraser' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-dsgvo-privacy-eraser' ) : esc_html__( 'Standalone', 'wm-dsgvo-privacy-eraser' ); ?></span>
					</span>
				</div>
			</header>

			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Bereiche', 'wm-dsgvo-privacy-eraser' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wm-dsgvo-eraser', 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>" class="wm-tab-link <?php echo $active_tab === $slug ? 'active' : ''; ?>"<?php echo $active_tab === $slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'overview' === $active_tab ) : ?>
			<section class="wm-card" aria-labelledby="wm-eraser-overview">
				<h2 id="wm-eraser-overview"><?php esc_html_e( 'Stand dieser Website', 'wm-dsgvo-privacy-eraser' ); ?></h2>
				<p class="wm-card-caption"><?php esc_html_e( 'Beim Aufruf dieser Seite aus der Datenbank gelesen.', 'wm-dsgvo-privacy-eraser' ); ?></p>
				<table class="wm-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Löschnachweise', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $stats['certificates'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gelöschte oder anonymisierte Einträge laut Nachweisen', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $stats['purged'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Offene Anträge (Adresse noch gespeichert)', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $stats['holding'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Nachweiskette', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td>
								<span class="wm-status-pill <?php echo 'BROKEN' === $chain['status'] ? 'wm-status-error' : 'wm-status-healthy'; ?>"><?php echo esc_html( self::chain_label( $chain ) ); ?></span>
								<?php if ( ! empty( $chain['error'] ) ) : ?>
									<br /><code><?php echo esc_html( (string) $chain['error'] ); ?></code>
								<?php endif; ?>
								<?php if ( (int) $chain['unchained_legacy'] > 0 ) : ?>
									<br />
									<?php
									/* translators: %d: number of certificates issued before the chain existed */
									echo esc_html( sprintf( __( '%d ältere Nachweise ohne Siegel, nicht prüfbar.', 'wm-dsgvo-privacy-eraser' ), (int) $chain['unchained_legacy'] ) );
									?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Verschlüsselung offener Adressen (libsodium)', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td><span class="wm-status-pill <?php echo $stats['cipher'] ? 'wm-status-healthy' : 'wm-status-degraded'; ?>"><?php echo $stats['cipher'] ? esc_html__( 'verfügbar', 'wm-dsgvo-privacy-eraser' ) : esc_html__( 'nicht verfügbar: Adressen werden im Klartext gespeichert', 'wm-dsgvo-privacy-eraser' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'WooCommerce-Rechnungsfelder anonymisieren (wm_dsgvo_anonymize_wc_orders)', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td><?php echo $stats['wc'] ? esc_html__( 'an', 'wm-dsgvo-privacy-eraser' ) : esc_html__( 'aus (Standard)', 'wm-dsgvo-privacy-eraser' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Daten bei Deinstallation löschen (wm_dsgvo_eraser_delete_data_on_uninstall)', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<td><?php echo $stats['delete_on_uninstall'] ? esc_html__( 'an', 'wm-dsgvo-privacy-eraser' ) : esc_html__( 'aus (Standard: Nachweise bleiben erhalten)', 'wm-dsgvo-privacy-eraser' ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<?php elseif ( 'request' === $active_tab ) : ?>
			<section class="wm-card" aria-labelledby="wm-eraser-request">
				<h2 id="wm-eraser-request"><?php esc_html_e( 'Löschantrag für eine Adresse stellen', 'wm-dsgvo-privacy-eraser' ); ?></h2>
				<p class="wm-card-caption">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: hours the confirmation link stays valid */
							__( 'Legt denselben Antrag an wie das Formular auf der Website: An die Adresse geht ein Bestätigungslink, %d Stunden gültig. Gelöscht wird erst, wenn die Löschung auf der verlinkten Seite bestätigt wird. Eine sofortige Löschung ohne Bestätigung gibt es nur über WP-CLI (wp dsgvo erase), nachdem Sie die Identität selbst geprüft haben.', 'wm-dsgvo-privacy-eraser' ),
							ErasureRequestManager::TOKEN_TTL_HOURS
						)
					);
					?>
				</p>
				<p>
					<label for="wm-admin-purge-email"><strong><?php esc_html_e( 'E-Mail-Adresse der betroffenen Person', 'wm-dsgvo-privacy-eraser' ); ?></strong></label><br />
					<input type="email" id="wm-admin-purge-email" class="regular-text" autocomplete="off" />
				</p>
				<p><button type="button" class="button button-primary" id="wm-admin-purge-run"><?php esc_html_e( 'Bestätigungslink senden', 'wm-dsgvo-privacy-eraser' ); ?></button></p>
				<div id="wm-admin-purge-status" role="status" aria-live="polite"></div>
				<script>
				( function () {
					const run    = document.getElementById( 'wm-admin-purge-run' );
					const input  = document.getElementById( 'wm-admin-purge-email' );
					const status = document.getElementById( 'wm-admin-purge-status' );
					if ( ! run ) { return; }
					run.addEventListener( 'click', function () {
						if ( ! input.value ) {
							status.textContent = <?php echo wp_json_encode( __( 'Bitte eine E-Mail-Adresse eingeben.', 'wm-dsgvo-privacy-eraser' ) ); ?>;
							return;
						}
						status.textContent = <?php echo wp_json_encode( __( 'Wird übermittelt …', 'wm-dsgvo-privacy-eraser' ) ); ?>;
						fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'wm-dsgvo-eraser/v1/request' ) ) ); ?>, {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
							body: JSON.stringify( { email: input.value } )
						} )
							.then( function ( r ) { return r.json(); } )
							.then( function ( d ) {
								status.textContent = d && d.success
									? <?php
								echo wp_json_encode(
									sprintf(
										/* translators: 1: requests per address, 2: hours, 3: requests per IP per hour */
										__( 'Antrag übermittelt. Ein Bestätigungslink geht nur hinaus, wenn die Adresse gültig ist, keines der Limits greift (%1$d Anträge je Adresse in %2$d Stunden, %3$d je IP pro Stunde, Ihre eigene IP zählt mit) und der Mailversand gelingt; diese Seite erfährt das nicht. Noch ist nichts gelöscht.', 'wm-dsgvo-privacy-eraser' ),
										ErasureRequestManager::MAX_PER_EMAIL,
										ErasureRequestManager::TOKEN_TTL_HOURS,
										ErasureRequestManager::MAX_PER_IP_HOUR
									)
								);
								?>
									: ( ( d && ( d.message || d.error ) ) || <?php echo wp_json_encode( __( 'Der Antrag konnte nicht angelegt werden.', 'wm-dsgvo-privacy-eraser' ) ); ?> );
							} )
							.catch( function () {
								status.textContent = <?php echo wp_json_encode( __( 'Verbindungsfehler.', 'wm-dsgvo-privacy-eraser' ) ); ?>;
							} );
					} );
				} )();
				</script>
			</section>

			<?php elseif ( 'certificates' === $active_tab ) : ?>
			<?php
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live list for the admin screen.
			$recent_certs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 10', Schema::get_certificates_table() ), ARRAY_A );
			?>
			<section class="wm-card" aria-labelledby="wm-eraser-certificates">
				<h2 id="wm-eraser-certificates"><?php esc_html_e( 'Letzte zehn Löschnachweise', 'wm-dsgvo-privacy-eraser' ); ?></h2>
				<p class="wm-card-caption"><?php esc_html_e( 'Jeder Nachweis speichert statt der Adresse einen geschlüsselten Hash, die Anzahl der Einträge je Bereich und ein Siegel, das den vorherigen Nachweis einschließt. Bearbeitete, eingefügte, umsortierte oder gelöschte Nachweise fallen bei der Prüfung auf, solange die Option mit dem Kettenende unverändert ist; wer die Datenbank schreiben kann, kann auch diese Option ändern, und wer den Schlüssel aus wp-config.php hat, kann die Kette neu versiegeln.', 'wm-dsgvo-privacy-eraser' ); ?></p>
				<table class="wm-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Nachweis', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hash der Adresse', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Einträge', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Bereiche', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Siegel', 'wm-dsgvo-privacy-eraser' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Zeitpunkt', 'wm-dsgvo-privacy-eraser' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $recent_certs ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'Noch keine Löschnachweise.', 'wm-dsgvo-privacy-eraser' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $recent_certs as $c ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $c['certificate_id'] ); ?></strong></td>
									<td><code><?php echo esc_html( substr( (string) $c['blinded_email_hmac'], 0, 16 ) ); ?>…</code></td>
									<td><?php echo esc_html( number_format_i18n( (int) $c['purged_records_count'] ) ); ?></td>
									<td><?php echo esc_html( (string) $c['purged_tables_summary'] ); ?></td>
									<td><code><?php echo esc_html( '' === (string) $c['prev_hash'] ? __( 'ohne Siegelkette', 'wm-dsgvo-privacy-eraser' ) : substr( (string) $c['sha256_audit_seal'], 0, 16 ) . '…' ); ?></code></td>
									<td><?php echo esc_html( (string) $c['erased_at'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<?php elseif ( 'settings' === $active_tab ) : ?>
			<section class="wm-card" aria-labelledby="wm-eraser-settings">
				<h2 id="wm-eraser-settings"><?php esc_html_e( 'Einstellungen', 'wm-dsgvo-privacy-eraser' ); ?></h2>
				<p class="wm-card-caption"><?php esc_html_e( 'Beide Schalter sind standardmäßig ausgeschaltet, damit weder steuerrechtlich relevante Rechnungsdaten noch Löschnachweise unbeabsichtigt entfernt werden.', 'wm-dsgvo-privacy-eraser' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php
					if ( function_exists( 'settings_fields' ) ) {
						settings_fields( 'wm_dsgvo_eraser_settings' );
					}
					?>
					<table class="wm-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wm_dsgvo_anonymize_wc_orders"><?php esc_html_e( 'WooCommerce-Rechnungsfelder anonymisieren', 'wm-dsgvo-privacy-eraser' ); ?></label>
								</th>
								<td>
									<input type="checkbox" id="wm_dsgvo_anonymize_wc_orders" name="wm_dsgvo_anonymize_wc_orders" value="1"<?php if ( $stats['wc'] ) : ?> checked="checked"<?php endif; ?> />
									<p class="description"><?php esc_html_e( 'Überschreibt Vor- und Nachname, erste Adresszeile, Telefon und E-Mail in WooCommerce-Bestellungen der betroffenen Adresse. Erst nach Prüfung gesetzlicher Aufbewahrungsfristen (§ 147 AO, § 257 HGB) einschalten.', 'wm-dsgvo-privacy-eraser' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wm_dsgvo_eraser_delete_data_on_uninstall"><?php esc_html_e( 'Alle Plugin-Daten bei Deinstallation löschen', 'wm-dsgvo-privacy-eraser' ); ?></label>
								</th>
								<td>
									<input type="checkbox" id="wm_dsgvo_eraser_delete_data_on_uninstall" name="wm_dsgvo_eraser_delete_data_on_uninstall" value="1"<?php if ( $stats['delete_on_uninstall'] ) : ?> checked="checked"<?php endif; ?> />
									<p class="description"><?php esc_html_e( 'Wenn aktiviert, entfernt die Deinstallation alle Plugin-Optionen sowie die Tabellen für Anträge und Löschnachweise unwiderruflich. Standardmäßig bleiben Nachweise und Optionen erhalten.', 'wm-dsgvo-privacy-eraser' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Einstellungen speichern', 'wm-dsgvo-privacy-eraser' ); ?></button></p>
				</form>
			</section>

			<?php else : ?>
			<section class="wm-card" aria-labelledby="wm-eraser-limits">
				<h2 id="wm-eraser-limits"><?php esc_html_e( 'Was bei einer Löschung passiert', 'wm-dsgvo-privacy-eraser' ); ?></h2>
				<ul class="wm-card-body">
					<li><?php esc_html_e( 'Kommentare mit dieser Adresse: Name, Adresse, URL, IP, Browserkennung und Benutzerzuordnung werden überschrieben, der Text durch „[Inhalt auf Antrag nach Art. 17 DSGVO gelöscht]“ ersetzt. Die Kommentare selbst bleiben stehen.', 'wm-dsgvo-privacy-eraser' ); ?></li>
					<li><?php esc_html_e( 'Benutzerkonto mit dieser Adresse: wird mit wp_delete_user() gelöscht, dabei auch die Beiträge, die dieses Konto verfasst hat. Konten mit der Rolle Administrator werden nicht gelöscht; der Nachweis vermerkt das. In einer Multisite entfernt wp_delete_user() das Konto nur aus dieser Website; der Nachweis vermerkt removed_from_this_site.', 'wm-dsgvo-privacy-eraser' ); ?></li>
					<li><?php esc_html_e( 'WooCommerce-Bestellungen: nur wenn die Option wm_dsgvo_anonymize_wc_orders an ist, und nur Vor- und Nachname, erste Adresszeile, E-Mail und Telefon der Rechnungsadresse, über die Bestellobjekte von WooCommerce (HPOS und Beitragsspeicher). Standard ist aus, weil Rechnungsdaten Aufbewahrungsfristen unterliegen können (§ 147 AO, § 257 HGB); ob eine greift, entscheidet das Plugin nicht.', 'wm-dsgvo-privacy-eraser' ); ?></li>
					<li><?php esc_html_e( 'Nicht erfasst: Tabellen anderer Plugins (Formulare, Newsletter, Buchungen), Benutzer-Metadaten verbleibender Konten, Kommentar-Metadaten, Lieferadressen, Dateien, Logs, Caches und Backups. Die Datenschutz-Löschwerkzeuge von WordPress ruft das Plugin nicht auf.', 'wm-dsgvo-privacy-eraser' ); ?></li>
					<li><?php esc_html_e( 'Ob ein Antrag berechtigt ist, ob Ausnahmen nach Art. 17 Abs. 3 DSGVO greifen und welche Frist gilt, entscheidet das Plugin nicht.', 'wm-dsgvo-privacy-eraser' ); ?></li>
				</ul>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
