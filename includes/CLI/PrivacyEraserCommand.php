<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Eraser\TransversalDataEraser;
use WP_CLI;

final class PrivacyEraserCommand {
	/**
	 * Erase or anonymise what WordPress and (with the switch on) WooCommerce hold for an address, without a
	 * request or confirmation link, and record it in the certificate chain.
	 *
	 * Exits non-zero when an area failed or the record was not written. Check the identity of the person
	 * yourself before running it; the plugin does not decide whether a request is justified.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address to erase.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dsgvo erase user@example.com
	 *
	 * @when after_wp_load
	 */
	public function erase( array $args, array $assoc_args ): void {
		$email = $args[0] ?? '';
		if ( empty( $email ) ) {
			WP_CLI::error( "Email address is required." );
		}

		WP_CLI::line( "Erasing what WordPress holds for: {$email}" );

		$eraser = new TransversalDataEraser();
		$result = $eraser->execute_erasure( $email );

		// Until 2026-09-15 this printed "Data purged" also when nothing was changed and "Chained To" could never
		// read "(genesis)", because the first record carries the genesis constant, not null.
		foreach ( (array) ( $result['tables'] ?? [] ) as $area => $state ) {
			WP_CLI::line( "- {$area}: {$state}" );
		}
		$certificate = $result['certificate'] ?? null;
		if ( is_array( $certificate ) ) {
			$prev = (string) ( $certificate['prev_hash'] ?? '' );
			WP_CLI::line( 'Record:          ' . $certificate['certificate_id'] );
			WP_CLI::line( 'Address HMAC:    ' . $result['email_hmac'] );
			WP_CLI::line( 'Seal:            ' . $certificate['sha256_audit_seal'] );
			WP_CLI::line( 'Chained to:      ' . ( ErasureCertificateGenerator::GENESIS_HASH === $prev ? $prev . ' (genesis)' : $prev ) );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Unknown error' ) );
		}
		WP_CLI::success( sprintf( '%d entries erased or anonymised; the erasure is recorded.', (int) $result['total_purged'] ) );
	}

	/**
	 * Verify the erasure certificate hash chain and report the first row that does not check out.
	 *
	 * Exits non-zero when the chain is broken, so it can be used as a gate in a cron or a
	 * monitoring job. A chain with no certificates yet is not a failure.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dsgvo verify
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function verify( array $args, array $assoc_args ): void {
		$result = ( new ErasureCertificateGenerator() )->verify_chain_integrity();

		if ( 'EMPTY_CHAIN' === $result['status'] ) {
			WP_CLI::warning( 'No certificates have been issued yet — nothing to verify.' );
			return;
		}

		if ( 'BROKEN' === $result['status'] ) {
			$where = null === $result['broken_node'] ? 'the chain tip' : 'certificate row #' . $result['broken_node'];
			WP_CLI::error( "Certificate chain BROKEN at {$where}: " . (string) $result['error'] );
		}

		WP_CLI::success( "Certificate chain intact: {$result['checked']} sealed rows verified." );

		if ( $result['unchained_legacy'] > 0 ) {
			WP_CLI::warning(
				"{$result['unchained_legacy']} certificate(s) predate the hash chain and carry no seal that can be verified. " .
				'They are reported as unchained, not as intact.'
			);
		}
	}
}
