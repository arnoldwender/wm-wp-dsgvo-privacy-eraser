<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;

/**
 * `[wm_dsgvo_erasure_portal]` and the block `wm/dsgvo-erasure-portal`: an address field that posts to
 * `wm-dsgvo-eraser/v1/request`. Nothing is erased from here; the page behind the mailed link does that, after its button.
 */
final class ErasureRequestForm {
	public const BLOCK_EDITOR_HANDLE = 'wm-dsgvo-eraser-blocks-editor';

	public function boot(): void {
		add_shortcode( 'wm_dsgvo_erasure_portal', [ $this, 'render_shortcode' ] );

		add_action( 'init', [ $this, 'register_block' ] );
	}

	public function register_block(): void {
		$asset_path = WM_DSGVO_ERASER_PATH . 'build/index.asset.php';
		$asset      = is_file( $asset_path )
			? require $asset_path
			: [
				'dependencies' => [ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ],
				'version'      => WM_DSGVO_ERASER_VERSION,
			];

		if ( function_exists( 'wp_register_script' ) ) {
			wp_register_script(
				self::BLOCK_EDITOR_HANDLE,
				WM_DSGVO_ERASER_URL . 'build/index.js',
				(array) ( $asset['dependencies'] ?? [] ),
				(string) ( $asset['version'] ?? WM_DSGVO_ERASER_VERSION ),
				true
			);
		}

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::BLOCK_EDITOR_HANDLE,
				'wm-dsgvo-privacy-eraser',
				WM_DSGVO_ERASER_PATH . 'languages'
			);
		}

		if ( function_exists( 'register_block_type' ) ) {
			$block_dir = WM_DSGVO_ERASER_PATH . 'blocks/dsgvo-erasure-portal';
			register_block_type(
				is_dir( $block_dir ) ? $block_dir : 'wm/dsgvo-erasure-portal',
				[
					'editor_script'   => self::BLOCK_EDITOR_HANDLE,
					'render_callback' => [ $this, 'render_shortcode' ],
				]
			);
		}
	}

	/**
	 * Render the public Art. 17 erasure request form for the shortcode or Gutenberg block.
	 *
	 * @param array<string, mixed>|string $atts Block or shortcode attributes.
	 */
	public function render_shortcode( $atts = [] ): string {
		$atts             = is_array( $atts ) ? $atts : [];
		$show_description = ! array_key_exists( 'showDescription', $atts )
			|| filter_var( $atts['showDescription'], FILTER_VALIDATE_BOOLEAN );
		$api_url = rest_url( 'wm-dsgvo-eraser/v1/request' );
		// Texts from gettext and the request limits from the code (until 2026-09-15 typed into the script).
		$text = array(
			'empty'   => __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'wm-dsgvo-privacy-eraser' ),
			'sending' => __( 'Antrag wird übermittelt …', 'wm-dsgvo-privacy-eraser' ),
			'title'   => __( 'Bestätigung erforderlich', 'wm-dsgvo-privacy-eraser' ),
			'sent'    => sprintf(
				/* translators: %d: hours the confirmation link stays valid */
				__( 'Ist die Adresse gültig, geht an sie ein Bestätigungslink. Gelöscht wird erst, wenn die Löschung auf der verlinkten Seite bestätigt wird. Der Link ist %d Stunden gültig und nur einmal verwendbar.', 'wm-dsgvo-privacy-eraser' ),
				ErasureRequestManager::TOKEN_TTL_HOURS
			),
			'failed'  => __( 'Der Antrag konnte nicht übermittelt werden:', 'wm-dsgvo-privacy-eraser' ),
			'unknown' => __( 'unbekannter Fehler', 'wm-dsgvo-privacy-eraser' ),
			'network' => __( 'Der Antrag konnte nicht übermittelt werden: Verbindungsfehler.', 'wm-dsgvo-privacy-eraser' ),
		);

		add_action( 'wp_footer', function() use ( $api_url, $text ): void {
			?>
			<script>
			function wmSubmitErasureRequest() {
				var email = document.getElementById('wm-erasure-email').value;
				var statusBox = document.getElementById('wm-erasure-status');
				// Text only: server messages are written with textContent, never as markup.
				var show = function (text, strong) {
					statusBox.replaceChildren();
					if (strong) {
						var s = document.createElement('strong');
						s.textContent = strong;
						statusBox.appendChild(s);
						statusBox.appendChild(document.createElement('br'));
					}
					statusBox.appendChild(document.createTextNode(text));
				};
				var text = <?php echo wp_json_encode( $text ); ?>;
				if (!email) {
					show(text.empty);
					return;
				}

				show(text.sending);

				// No X-WP-Nonce: /request is public, and a nonce from a page cached longer than its lifetime made
				// WordPress answer 403 "Cookie check failed" to logged-out visitors (until 2026-09-15).
				fetch(<?php echo wp_json_encode( esc_url_raw( $api_url ) ); ?>, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({ email: email })
				})
				.then(function(r) { return r.json(); })
				.then(function(d) {
					if (d.success) {
						// Deliberately identical for known and unknown addresses: a differing
						// message would turn this form into an account enumeration oracle.
						show(text.sent, text.title);
					} else {
						show(text.failed + ' ' + (d.message || d.error || text.unknown));
					}
				})
				.catch(function() {
					show(text.network);
				});
			}
			</script>
			<?php
		}, 99 );

		ob_start();
		?>
		<section class="wm-dsgvo-erasure-box" aria-labelledby="wm-erasure-heading" style="background: rgba(15, 23, 42, 0.95); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 28px; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; max-width: 600px; margin: 24px auto;">
			<h3 id="wm-erasure-heading" style="color: #7dd3fc; font-size: 20px; margin-top: 0; margin-bottom: 8px;"><?php esc_html_e( 'Antrag auf Datenlöschung (Art. 17 DSGVO)', 'wm-dsgvo-privacy-eraser' ); ?></h3>
			<?php if ( $show_description ) : ?>
			<p style="color: #cbd5e1; font-size: 14px; line-height: 1.5; margin-bottom: 20px;">
				<?php esc_html_e( 'Gemäß Art. 17 DSGVO ("Recht auf Vergessenwerden") haben Sie das Recht auf unverzügliche Löschung Ihrer personenbezogenen Daten. Zur Bestätigung Ihrer Identität nach Art. 12 Abs. 6 DSGVO senden wir Ihnen zunächst einen einmaligen Bestätigungslink. Erst nach dessen Aufruf wird gelöscht und ein kryptografisches Löschzertifikat ausgestellt.', 'wm-dsgvo-privacy-eraser' ); ?>
			</p>
			<?php endif; ?>

			<div style="margin-bottom: 16px;">
				<label for="wm-erasure-email" style="display: block; font-size: 14px; color: #cbd5e1; margin-bottom: 4px;"><?php esc_html_e( 'Ihre hinterlegte E-Mail-Adresse:', 'wm-dsgvo-privacy-eraser' ); ?></label>
				<input type="email" id="wm-erasure-email" autocomplete="email" required placeholder="<?php echo esc_attr__( 'ihre-email@example.com', 'wm-dsgvo-privacy-eraser' ); ?>" style="width: 100%; background: #030712; border: 1px solid rgba(255,255,255,0.35); border-radius: 6px; padding: 10px 14px; color: #ffffff; font-size: 16px; box-sizing: border-box;" />
			</div>

			<button type="button" onclick="wmSubmitErasureRequest()" style="background: #be123c; color: #ffffff; font-weight: 600; padding: 12px 20px; min-height: 44px; border-radius: 6px; border: none; cursor: pointer; font-size: 15px;">
				<?php esc_html_e( 'Löschantrag übermitteln', 'wm-dsgvo-privacy-eraser' ); ?>
			</button>

			<div id="wm-erasure-status" role="status" aria-live="polite" style="margin-top: 16px; font-size: 14px;"></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
