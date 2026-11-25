<?php
/**
 * The page behind the mailed confirmation link.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Frontend;

defined( 'ABSPATH' ) || exit;

use WMCyber\DSGVO\Eraser\Request\ErasureConfirmation;

/**
 * `/?wm_dsgvo_erasure=confirm&wm_erasure_request=…&wm_erasure_token=…`.
 *
 * Opening the link (GET) shows what will happen and a button; only the POST of that button erases. Until
 * 2026-09-15 the link erased on the GET itself, so a mail scanner or link preview that fetched it
 * completed the erasure, and the person saw a JSON answer.
 */
final class ErasureConfirmationPage {
	public const FLAG = 'wm_dsgvo_erasure';

	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the single-use token is the credential; a logged-out data subject has no nonce.
		$is_post = 'POST' === strtoupper( sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) );
		$source  = $is_post ? $_POST : $_GET;
		if ( 'confirm' !== sanitize_key( wp_unslash( (string) ( $source[ self::FLAG ] ?? '' ) ) ) ) {
			return;
		}
		$uuid  = sanitize_text_field( wp_unslash( (string) ( $source['wm_erasure_request'] ?? '' ) ) );
		$token = sanitize_text_field( wp_unslash( (string) ( $source['wm_erasure_token'] ?? '' ) ) );
		// phpcs:enable

		$result = null;
		if ( $is_post ) {
			$outcome = ErasureConfirmation::run( $uuid, $token );
			$view    = $outcome['state'];
			$http    = $outcome['http'];
			$result  = $outcome['result'] ?? null;
		} else {
			$view = ErasureConfirmation::pending( $uuid, $token ) ? 'ask' : 'invalid';
			$http = 'ask' === $view ? 200 : 403;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		status_header( $http );
		get_header();
		echo self::markup( $view, $uuid, $token, $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside markup().
		get_footer();
		exit;
	}

	/**
	 * Markup of one view (`ask`, `invalid`, `undecryptable`, `incomplete`, `done`), escaped.
	 *
	 * @param array|null $result Erasure result for `done` and `incomplete`.
	 */
	public static function markup( string $view, string $uuid, string $token, ?array $result ): string {
		$certificate = (string) ( $result['certificate']['certificate_id'] ?? '' );
		$total       = (int) ( $result['total_purged'] ?? 0 );

		switch ( $view ) {
			case 'ask':
				$title = __( 'Löschung bestätigen', 'wm-dsgvo-privacy-eraser' );
				$body  = '<p>' . esc_html__( 'Das Öffnen dieses Links hat noch nichts gelöscht. Mit der Schaltfläche unten werden zu der Adresse, für die der Antrag gestellt wurde, die Kommentare auf dieser Website anonymisiert und ein Benutzerkonto gelöscht (außer Administratorkonten); ist es eingeschaltet, werden auch Rechnungsfelder von WooCommerce-Bestellungen anonymisiert. Die Löschung ist unwiderruflich.', 'wm-dsgvo-privacy-eraser' ) . '</p>'
					. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">'
					. '<input type="hidden" name="' . esc_attr( self::FLAG ) . '" value="confirm" />'
					. '<input type="hidden" name="wm_erasure_request" value="' . esc_attr( $uuid ) . '" />'
					. '<input type="hidden" name="wm_erasure_token" value="' . esc_attr( $token ) . '" />'
					. '<p><button type="submit" class="button">' . esc_html__( 'Löschung jetzt ausführen', 'wm-dsgvo-privacy-eraser' ) . '</button></p>'
					. '</form>';
				break;

			case 'done':
				$title = __( 'Löschung ausgeführt', 'wm-dsgvo-privacy-eraser' );
				$body  = '<p>' . esc_html(
					sprintf(
						/* translators: 1: number of entries, 2: record id */
						__( '%1$d Einträge wurden gelöscht oder anonymisiert. Kennung des Löschnachweises: %2$s', 'wm-dsgvo-privacy-eraser' ),
						$total,
						$certificate
					)
				) . '</p>';
				break;

			case 'incomplete':
				$title = __( 'Löschung nicht vollständig', 'wm-dsgvo-privacy-eraser' );
				$body  = '<p>' . esc_html__( 'Nicht alle Daten konnten geändert werden, oder der Löschnachweis wurde nicht gespeichert. Der Antrag ist als fehlgeschlagen vermerkt; bitte wenden Sie sich an den Betreiber dieser Website.', 'wm-dsgvo-privacy-eraser' ) . '</p>';
				if ( '' !== $certificate ) {
					/* translators: %s: record id */
					$body .= '<p>' . esc_html( sprintf( __( 'Kennung des Löschnachweises: %s', 'wm-dsgvo-privacy-eraser' ), $certificate ) ) . '</p>';
				}
				break;

			case 'undecryptable':
				$title = __( 'Antrag nicht verarbeitet', 'wm-dsgvo-privacy-eraser' );
				$body  = '<p>' . esc_html__( 'Der Antrag konnte nicht verarbeitet werden. Bitte stellen Sie ihn erneut.', 'wm-dsgvo-privacy-eraser' ) . '</p>';
				break;

			default:
				$title = __( 'Link nicht gültig', 'wm-dsgvo-privacy-eraser' );
				$body  = '<p>' . esc_html__( 'Der Bestätigungslink ist ungültig, abgelaufen oder wurde bereits verwendet.', 'wm-dsgvo-privacy-eraser' ) . '</p>';
		}

		return '<main id="wm-dsgvo-erasure-confirmation" class="wm-dsgvo-erasure-confirmation">'
			. '<section aria-labelledby="wm-dsgvo-erasure-title">'
			. '<h1 id="wm-dsgvo-erasure-title">' . esc_html( $title ) . '</h1>'
			. $body
			. '</section></main>';
	}
}
