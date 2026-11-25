<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\API;

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints of the erasure flow.
 *
 * /request never deletes: it records a pending request and mails a single-use confirmation link. /confirm
 * deletes nothing either: links mailed before 2026-09-15 point at it, so it redirects to the confirmation
 * page, where only the POST of the button erases. What the flow shows is that someone with access to the
 * mailbox opened the link and pressed the button, not who that person is.
 */
final class RestController {
	private const REST_NAMESPACE = 'wm-dsgvo-eraser/v1';

	public function register_routes(): void {
		// Public by design: a data subject has no account. Authorisation happens through the
		// mailed token, while check_public_erasure_access() rate-limits requests per blinded IP.
		register_rest_route( self::REST_NAMESPACE, '/request', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_erasure_request' ],
			'permission_callback' => [ $this, 'check_public_erasure_access' ],
			'args'                => [
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'format'            => 'email',
					'sanitize_callback' => 'sanitize_email',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/confirm', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_confirm' ],
			'permission_callback' => [ $this, 'check_public_erasure_access' ],
			'args'                => [
				'request' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'token'   => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// Not public. The two routes above authorise through a mailed token held by the data
		// subject; this one has no such token and exposes the audit state of the whole install,
		// so it is gated on the capability instead.
		register_rest_route( self::REST_NAMESPACE, '/verify', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_verify_chain' ],
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	/**
	 * Public REST guard for unauthenticated Art. 17 erasure requests and legacy confirmation redirects.
	 *
	 * Uses a keyed HMAC-SHA256 of the client IP (never storing raw IPs) in short-lived transients to
	 * throttle burst abuse while bypassing the transient limit for administrators (`manage_options`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_public_erasure_access( WP_REST_Request $request ) {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$limit = (int) apply_filters( 'wm_eraser_rest_rate_limit', 30, $request );
		if ( $limit <= 0 ) {
			return true;
		}

		$raw_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip_hmac = ErasureRequestManager::blind( $raw_ip );
		$bucket  = 'wm_dsgvo_rl_' . substr( $ip_hmac, 0, 32 );
		$count   = (int) get_transient( $bucket );

		if ( $count >= $limit ) {
			return new WP_Error(
				'wm_eraser_rate_limited',
				__( 'Zu viele Anfragen. Bitte versuchen Sie es in einer Minute erneut.', 'wm-dsgvo-privacy-eraser' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $bucket, $count + 1, defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );

		return true;
	}

	/**
	 * Report whether the certificate chain still verifies.
	 *
	 * Answers 409 on a broken chain so a monitoring probe can act on the status code alone.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_verify_chain(): WP_REST_Response {
		$result = ( new ErasureCertificateGenerator() )->verify_chain_integrity();

		return new WP_REST_Response( $result, 'BROKEN' === $result['status'] ? 409 : 200 );
	}

	/**
	 * Accept an erasure request. Deletes nothing.
	 *
	 * The response is deliberately identical for known addresses, unknown addresses and
	 * rate-limited callers: a differing response would turn this endpoint into an account
	 * enumeration oracle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_erasure_request( WP_REST_Request $request ): WP_REST_Response {
		$email = (string) $request->get_param( 'email' );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_REST_Response( [ 'error' => __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'wm-dsgvo-privacy-eraser' ) ], 400 );
		}

		ErasureRequestManager::create( $email );

		return new WP_REST_Response(
			[
				'success' => true,
				'status'  => 'confirmation_pending',
				'message' => __( 'Der Antrag wurde entgegengenommen. Ist die Adresse gültig, geht an sie ein Bestätigungslink; gelöscht wird erst nach dessen Aufruf.', 'wm-dsgvo-privacy-eraser' ),
			],
			202
		);
	}

	/**
	 * Redirect a mailed link to the confirmation page. Erases nothing.
	 *
	 * Until 2026-09-15 this GET resolved the token and ran the erasure itself, so any client that fetched
	 * the URL — a mail security scanner, a link preview — deleted the account and its posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_confirm( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( null, 302 );
		$response->header(
			'Location',
			ErasureRequestManager::confirmation_url( (string) $request->get_param( 'request' ), (string) $request->get_param( 'token' ) )
		);
		return $response;
	}
}
