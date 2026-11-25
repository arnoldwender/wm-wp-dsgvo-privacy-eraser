<?php
/**
 * Erasure request lifecycle with a mailed confirmation link.
 *
 * Acting on a request without any check hands anyone on the internet a delete button for any address
 * they can guess (that was the plugin before a2d634d). The flow is: request -> single-use token mailed to
 * the address -> the holder opens the link and confirms on the page -> erasure. Nothing is deleted before
 * that confirmation, and every response from the public endpoint is identical regardless of whether the
 * address exists. Whether a controller may require this step for a given request is a legal question the
 * plugin does not decide: Art. 12 Abs. 6 DSGVO speaks of reasonable doubts about the requester's identity.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Request;

use WMCyber\DSGVO\Eraser\Crypto\RequestCipher;
use WMCyber\DSGVO\Eraser\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, confirms and expires erasure requests.
 */
final class ErasureRequestManager {

	/** Confirmation window in hours. */
	public const TOKEN_TTL_HOURS = 24;

	/** Max requests accepted per address inside the TTL window. */
	public const MAX_PER_EMAIL = 3;

	/** Max requests accepted per client IP inside one hour. */
	public const MAX_PER_IP_HOUR = 10;

	/**
	 * Blind an identifier so the request log never stores the raw value.
	 *
	 * @param string $value Raw value.
	 * @return string Hex HMAC.
	 */
	public static function blind( string $value ): string {
		return hash_hmac( 'sha256', strtolower( trim( $value ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Client IP, blinded.
	 *
	 * @return string
	 */
	private static function client_ip_hmac(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return self::blind( $ip );
	}

	/**
	 * Whether this address or IP has exceeded its request budget.
	 *
	 * @param string $email_hmac Blinded address.
	 * @return bool True when the request must be dropped.
	 */
	public static function is_rate_limited( string $email_hmac ): bool {
		global $wpdb;
		$table = Schema::get_requests_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$per_email = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE email_hmac = %s AND requested_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d HOUR )',
				$table,
				$email_hmac,
				self::TOKEN_TTL_HOURS
			)
		);

		$per_ip = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE ip_hmac = %s AND requested_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 HOUR )',
				$table,
				self::client_ip_hmac()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $per_email >= self::MAX_PER_EMAIL || $per_ip >= self::MAX_PER_IP_HOUR;
	}

	/**
	 * Create a pending request and mail its confirmation link.
	 *
	 * Deletes nothing. Returns true when a mail was dispatched, false when the request was
	 * dropped. Callers MUST NOT surface that distinction to the client.
	 *
	 * @param string $email Raw address as submitted.
	 * @return bool
	 */
	public static function create( string $email ): bool {
		global $wpdb;

		$clean = sanitize_email( strtolower( trim( $email ) ) );
		if ( empty( $clean ) || ! is_email( $clean ) ) {
			return false;
		}

		$email_hmac = self::blind( $clean );
		if ( self::is_rate_limited( $email_hmac ) ) {
			return false;
		}

		$raw_token = bin2hex( random_bytes( 32 ) );
		$uuid      = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			Schema::get_requests_table(),
			array(
				'request_uuid' => $uuid,
				'email_hmac'   => $email_hmac,
				'email_enc'    => RequestCipher::encrypt( $clean ),
				'token_hash'   => hash( 'sha256', $raw_token ),
				'status'       => 'pending',
				'ip_hmac'      => self::client_ip_hmac(),
				'requested_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL_HOURS * HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return self::send_confirmation_mail( $clean, $raw_token, $uuid );
	}

	/**
	 * Mail the single-use confirmation link.
	 *
	 * @param string $email     Recipient.
	 * @param string $raw_token Raw token (never stored).
	 * @param string $uuid      Request UUID.
	 * @return bool
	 */
	private static function send_confirmation_mail( string $email, string $raw_token, string $uuid ): bool {
		$link = self::confirmation_url( $uuid, $raw_token );

		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Löschantrag bestätigen (Art. 17 DSGVO)', 'wm-dsgvo-privacy-eraser' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = sprintf(
			/* translators: 1: Confirmation URL, 2: Validity in hours */
			__(
				"Für diese E-Mail-Adresse wurde ein Antrag auf Löschung personenbezogener Daten nach Art. 17 DSGVO gestellt.\n\nDie Löschung wird erst ausgeführt, wenn Sie diesen Link öffnen und sie auf der Seite bestätigen:\n\n%1\$s\n\nDer Link ist %2\$d Stunden gültig und kann nur einmal verwendet werden.\n\nWenn Sie diesen Antrag nicht gestellt haben, ignorieren Sie diese E-Mail. Es wird nichts gelöscht.\n\nAchtung: Die Löschung ist unwiderruflich.",
				'wm-dsgvo-privacy-eraser'
			),
			esc_url_raw( $link ),
			self::TOKEN_TTL_HOURS
		);

		return (bool) wp_mail( $email, $subject, $body );
	}

	/**
	 * Page that shows the confirmation step for a request: opening it erases nothing.
	 *
	 * Until 2026-09-15 the mail linked the REST route /confirm, whose GET erased at once, so any client
	 * that fetched the link (a mail security scanner, a link preview) completed the erasure. That route
	 * now only redirects here.
	 */
	public static function confirmation_url( string $uuid, string $raw_token ): string {
		return add_query_arg(
			array(
				'wm_dsgvo_erasure'   => 'confirm',
				'wm_erasure_request' => rawurlencode( $uuid ),
				'wm_erasure_token'   => rawurlencode( $raw_token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Resolve a confirmation token to its pending request.
	 *
	 * @param string $uuid      Request UUID.
	 * @param string $raw_token Raw token from the link.
	 * @return object|null Request row, or null when absent, expired, spent or mismatched.
	 */
	public static function resolve( string $uuid, string $raw_token ): ?object {
		global $wpdb;

		if ( empty( $uuid ) || empty( $raw_token ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE request_uuid = %s LIMIT 1', Schema::get_requests_table(), $uuid )
		);

		if ( ! $row || 'pending' !== $row->status ) {
			return null;
		}

		if ( empty( $row->expires_at ) || strtotime( $row->expires_at . ' UTC' ) < time() ) {
			self::mark( $row->request_uuid, 'expired' );
			return null;
		}

		// Constant-time comparison — the token is a credential.
		if ( ! hash_equals( (string) $row->token_hash, hash( 'sha256', $raw_token ) ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Take a pending request for processing; true only for the one caller that got it.
	 *
	 * The UPDATE is conditional on `pending`, so of two confirmations that both passed resolve() only one
	 * changes the row. Until 2026-09-15 the status was set without that condition and without reading the
	 * result, and both erased. The token is burnt here; the address stays until mark() ends the request,
	 * because the erasure still needs it.
	 */
	public static function claim( string $uuid ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'processing', token_hash = NULL WHERE request_uuid = %s AND status = 'pending'",
				Schema::get_requests_table(),
				$uuid
			)
		);

		return 1 === (int) $changed;
	}

	/**
	 * Move a request to a terminal state and drop the stored address and token.
	 *
	 * @param string $uuid   Request UUID.
	 * @param string $status New status.
	 * @return void
	 */
	public static function mark( string $uuid, string $status ): void {
		global $wpdb;

		$data = array(
			'status'     => $status,
			'email_enc'  => null,
			'token_hash' => null,
		);

		if ( 'processed' === $status ) {
			$data['confirmed_at'] = gmdate( 'Y-m-d H:i:s' );
			$data['processed_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			Schema::get_requests_table(),
			$data,
			array( 'request_uuid' => $uuid )
		);
	}

	/**
	 * Recover the address a pending request was filed for.
	 *
	 * Callers must go through this rather than reading email_enc: since 1.2.0 the column holds
	 * an encrypted envelope, and reading it raw would hand the erasure engine ciphertext.
	 *
	 * @param object $row Request row.
	 * @return string Address, or empty when it cannot be recovered.
	 */
	public static function address_of( object $row ): string {
		return RequestCipher::decrypt( (string) ( $row->email_enc ?? '' ) );
	}

	/**
	 * Drop the address and token of every request that ran out its confirmation window.
	 *
	 * Before this existed, expiry only happened when somebody clicked a stale link, so a request nobody
	 * confirmed kept its address for good. A request still `processing` after its window (an erasure that
	 * died half-way) is marked `failed` and loses its address too. The row itself stays with its keyed
	 * hashes of address and IP: the rate limiter counts it, and how long such rows are kept is an open
	 * decision.
	 *
	 * @return int Rows swept.
	 */
	public static function purge_expired(): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				 SET status = 'expired', email_enc = NULL, token_hash = NULL
				 WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < %s",
				Schema::get_requests_table(),
				$now
			)
		);
		$stuck   = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				 SET status = 'failed', email_enc = NULL, token_hash = NULL
				 WHERE status = 'processing' AND expires_at IS NOT NULL AND expires_at < %s",
				Schema::get_requests_table(),
				$now
			)
		);
		// phpcs:enable

		return $expired + $stuck;
	}
}
