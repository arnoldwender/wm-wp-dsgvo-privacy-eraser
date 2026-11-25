<?php
/**
 * Envelope encryption for the address held while an erasure request awaits confirmation.
 *
 * The requests table has always had a column called `email_enc`, and it has always held the
 * address in cleartext — the name promised an encryption that was never written. Meanwhile the
 * admin dashboard advertised "Zero Cleartext Stored". Both were wrong in the same direction.
 *
 * The address genuinely has to be kept: Art. 12(6) DSGVO means nothing is erased until the
 * mailbox holder follows a link, and the erasure needs the address to run. What can be fixed is
 * the shape it is kept in. The key is derived from wp_salt( 'secure_auth' ), which lives in
 * wp-config.php, so a stolen database dump on its own no longer reveals who asked to be
 * forgotten — which is exactly the population that most wants not to be on a list.
 *
 * Same trust root, and therefore the same honest limit, as the certificate chain: whoever reads
 * wp-config.php can decrypt. This defends against database-only exposure, nothing more.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts the pending-request address.
 */
final class RequestCipher {

	/** Marks a value written by this class. Anything else is a pre-1.2.0 cleartext row. */
	private const PREFIX = 'wmv1:';

	/**
	 * Whether libsodium is actually present in this runtime.
	 *
	 * PHP 8.2 bundles sodium but a host can still build without it. The dashboard reads this
	 * rather than assuming, because the whole point of this change is to stop printing claims
	 * the runtime does not support.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * Derive the symmetric key from the site salt.
	 *
	 * @return string Raw key bytes.
	 */
	private static function key(): string {
		return sodium_crypto_generichash(
			wp_salt( 'secure_auth' ) . '|wm-dsgvo-request-address',
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Encrypt an address for storage.
	 *
	 * Returns the plain value unchanged when sodium is unavailable — storing it is still
	 * required for the flow to work, and silently returning something undecryptable would break
	 * erasure for every request on that host.
	 *
	 * @param string $plain Address.
	 * @return string Stored representation.
	 */
	public static function encrypt( string $plain ): string {
		if ( ! self::is_available() ) {
			return $plain;
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Rows written before 1.2.0 carry no prefix and are returned as they are, so upgrading does
	 * not strand a request that is mid-flight.
	 *
	 * @param string $stored Stored representation.
	 * @return string Address, or an empty string when the value cannot be recovered.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? '' : $plain;
	}
}
