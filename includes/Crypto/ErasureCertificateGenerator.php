<?php
/**
 * Erasure certificates and the tamper-evident chain that links them.
 *
 * The first version of this file computed the seal as sha256() over three values that were all
 * stored in the same row. Anyone able to write to the table could edit the purged count and
 * recompute a matching seal without holding any secret, so the word "immutable" on the admin
 * dashboard described a property the code did not have.
 *
 * What replaced it:
 *
 * - the seal is an HMAC keyed with wp_salt( 'secure_auth' ), which lives in wp-config.php and
 *   not in the database, so database write access alone can no longer forge one;
 * - every seal binds the previous row's seal, so editing, deleting or reordering any row breaks
 *   verification from that row onwards;
 * - the current tip is mirrored into an option, so removing the newest rows, or all of them, is caught
 *   while that option is left as it was; a new record chains onto the option, not onto whatever row
 *   is newest in the table (until 2026-09-15 the next erasure chained onto the truncated tail and the
 *   chain verified again);
 * - the certificate id is re-derived from the row contents during verification.
 *
 * What this still does NOT give, and must therefore not be claimed: it is tamper-EVIDENT, not
 * immutable. Whoever holds wp-config.php holds the key and can re-seal the whole chain. Whoever can
 * write the database can also rewrite the tip option to an older seal (the seals are in the table),
 * or delete every row together with the option, which then looks like an install that never erased
 * anything. Nothing here proves a certificate existed at a given wall-clock time — that needs an
 * external anchor (a timestamping authority or a published digest), which this plugin does not do.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Crypto;

use WMCyber\DSGVO\Eraser\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Issues Art. 17 erasure certificates and verifies their hash chain.
 */
final class ErasureCertificateGenerator {

	/** Predecessor of the first chained certificate. */
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/** Domain separator. Bump only together with a re-seal migration. */
	private const SEAL_DOMAIN = 'WM-DSGVO-ART17-CERT-V2';

	/** Option mirroring the newest seal, so tail truncation is detectable. */
	public const TIP_OPTION = 'wm_dsgvo_cert_chain_tip';

	/** Seconds to wait for the append lock before giving up. */
	private const LOCK_TIMEOUT = 5;

	/**
	 * Issue a certificate and append it to the chain.
	 *
	 * Returns the payload, or `[ 'error' => …, 'blinded_hmac' => … ]` when nothing was stored: the append
	 * lock was not obtained, or the insert failed. Until 2026-09-15 both cases went ahead anyway — the lock
	 * result was ignored, and a refused insert (a duplicate certificate id from two identical erasures in
	 * the same second) still moved the tip and returned a certificate that was not in the table.
	 *
	 * @param string $email         Address that was erased.
	 * @param int    $purged_count  Entries changed.
	 * @param array  $tables_purged area => count or state.
	 * @return array Certificate payload, or an error array.
	 */
	public function issue_certificate( string $email, int $purged_count, array $tables_purged ): array {
		global $wpdb;
		Schema::create_tables();

		$table_certs = Schema::get_certificates_table();
		$blinded_hmac = hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'secure_auth' ) );
		$timestamp = current_time( 'mysql' );

		$tables_summary = implode(
			', ',
			array_map(
				static function ( $tbl, $cnt ) {
					return "{$tbl}: {$cnt}";
				},
				array_keys( $tables_purged ),
				$tables_purged
			)
		);

		$record_hash = self::record_hash( $blinded_hmac, $purged_count, $tables_summary, $timestamp );
		$cert_id     = 'ERASE-' . substr( $record_hash, 0, 16 );

		// Two concurrent erasures reading the same tip would both write the same prev_hash and
		// fork the chain, which verification reports as tampering. Serialise the append, and do not
		// append without the lock: GET_LOCK answers 1 when obtained, 0 on timeout, NULL on error.
		$lock = 'wm_dsgvo_cert_chain';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::LOCK_TIMEOUT ) );
		if ( '1' !== (string) $locked ) {
			return array(
				'error'        => 'record_not_written: the chain lock was not obtained',
				'blinded_hmac' => $blinded_hmac,
			);
		}

		try {
			$prev_hash = self::chain_head();
			$seal      = self::seal( $prev_hash, $record_hash );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table_certs,
				array(
					'certificate_id'        => $cert_id,
					'blinded_email_hmac'    => $blinded_hmac,
					'purged_records_count'  => $purged_count,
					'purged_tables_summary' => $tables_summary,
					'prev_hash'             => $prev_hash,
					'sha256_audit_seal'     => $seal,
					'erased_at'             => $timestamp,
				)
			);

			if ( false === $inserted ) {
				return array(
					'error'        => 'record_not_written: the certificate row was refused (' . $cert_id . ')',
					'blinded_hmac' => $blinded_hmac,
				);
			}

			update_option( self::TIP_OPTION, $seal, false );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}

		if ( class_exists( '\WMCyber\Consent\Vault\Crypto\MerkleTreeEngine' ) ) {
			try {
				$engine = new \WMCyber\Consent\Vault\Crypto\MerkleTreeEngine();
				$engine->append_anchor( $cert_id, $seal );
			} catch ( \Throwable $e ) {
				// The erasure and its record stand when the cross-plugin anchor fails. Throwable, not
				// Exception: until 2026-09-15 a TypeError from the anchor escaped after the row was written
				// and left the request stuck in `processing`.
			}
		}

		return array(
			'certificate_id'    => $cert_id,
			'blinded_hmac'      => $blinded_hmac,
			'purged_count'      => $purged_count,
			'tables_purged'     => $tables_purged,
			'prev_hash'         => $prev_hash,
			'sha256_audit_seal' => $seal,
			'timestamp'         => $timestamp,
			// What happened, not a legal verdict (was 'legal_status' => 'PURGED_AND_CERTIFIED_DSGVO_ART_17').
			'record_status'     => 'ERASURE_EXECUTED_AND_CHAINED',
		);
	}

	/**
	 * Content digest of a certificate row.
	 *
	 * Every field a reader would rely on is bound here, so editing any of them invalidates the
	 * seal. certificate_id is derived from this digest and therefore excluded — including it
	 * would be circular.
	 *
	 * @param string $blinded_hmac    Blinded address.
	 * @param int    $purged_count    Rows purged.
	 * @param string $tables_summary  Per-table summary.
	 * @param string $timestamp       Erasure timestamp as stored.
	 * @return string Hex sha256.
	 */
	public static function record_hash( string $blinded_hmac, int $purged_count, string $tables_summary, string $timestamp ): string {
		return hash(
			'sha256',
			self::SEAL_DOMAIN . '|' . $blinded_hmac . '|COUNT:' . $purged_count . '|' . $tables_summary . '|' . $timestamp
		);
	}

	/**
	 * Chain link for a row.
	 *
	 * Keyed with a salt that lives in wp-config.php, not in the database — that separation is
	 * the whole reason this is stronger than the plain hash it replaced.
	 *
	 * @param string $prev_hash   Predecessor seal.
	 * @param string $record_hash Content digest.
	 * @return string Hex HMAC.
	 */
	public static function seal( string $prev_hash, string $record_hash ): string {
		return hash_hmac( 'sha256', $prev_hash . '|' . $record_hash, wp_salt( 'secure_auth' ) );
	}

	/**
	 * The seal a new record chains onto: the recorded tip option, else the newest chained row, else genesis.
	 *
	 * The option comes first. When the newest rows were removed, the option still holds the seal of the
	 * last row that was written, so the new record keeps pointing at the removed row and verification
	 * keeps reporting the gap.
	 *
	 * @return string
	 */
	private static function chain_head(): string {
		global $wpdb;

		$recorded = (string) get_option( self::TIP_OPTION, '' );
		if ( '' !== $recorded ) {
			return $recorded;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tip = $wpdb->get_var( $wpdb->prepare( "SELECT sha256_audit_seal FROM %i WHERE prev_hash <> '' ORDER BY id DESC LIMIT 1", Schema::get_certificates_table() ) );

		return $tip ? (string) $tip : self::GENESIS_HASH;
	}

	/** Whether the certificates table exists (verification must not read a missing table as an empty chain). */
	private static function table_present(): bool {
		global $wpdb;
		$table = Schema::get_certificates_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Walk the chain and report the first row that does not verify.
	 *
	 * Rows written before the chain existed carry an empty prev_hash. They are counted as
	 * unchained rather than reported as tampering: they were never sealed with a key, so there
	 * is nothing to verify and saying otherwise would be the same overclaim this replaced. A
	 * legacy row appearing AFTER a chained one is not legacy — it is an insertion, and it is
	 * reported.
	 *
	 * @return array{status:string, checked:int, unchained_legacy:int, broken_node:?int, error:?string}
	 */
	public function verify_chain_integrity(): array {
		global $wpdb;
		$table = Schema::get_certificates_table();

		$result = array(
			'status'           => 'INTACT',
			'checked'          => 0,
			'unchained_legacy' => 0,
			'broken_node'      => null,
			'error'            => null,
		);

		if ( ! self::table_present() ) {
			$result['status'] = 'BROKEN';
			$result['error']  = 'the certificate table is missing';
			return $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ), ARRAY_A );
		$stored_tip = (string) get_option( self::TIP_OPTION, '' );

		if ( empty( $rows ) ) {
			// Until 2026-09-15 a table emptied after the first erasure read as "no records yet" and showed green.
			if ( '' !== $stored_tip ) {
				$result['status'] = 'BROKEN';
				$result['error']  = 'no certificate rows, but a chain tip is recorded (the certificates were removed)';
				return $result;
			}
			$result['status'] = 'EMPTY_CHAIN';
			return $result;
		}

		$expected_prev = self::GENESIS_HASH;
		$seen_chained  = false;

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			if ( '' === (string) $row['prev_hash'] ) {
				if ( $seen_chained ) {
					$result['status']      = 'BROKEN';
					$result['broken_node'] = $id;
					$result['error']       = 'unsealed row inserted after the chain started';
					return $result;
				}
				++$result['unchained_legacy'];
				continue;
			}

			$seen_chained = true;
			++$result['checked'];

			if ( ! hash_equals( $expected_prev, (string) $row['prev_hash'] ) ) {
				$result['status']      = 'BROKEN';
				$result['broken_node'] = $id;
				$result['error']       = 'prev_hash does not match the preceding seal (row inserted, deleted or reordered)';
				return $result;
			}

			$record_hash = self::record_hash(
				(string) $row['blinded_email_hmac'],
				(int) $row['purged_records_count'],
				(string) $row['purged_tables_summary'],
				(string) $row['erased_at']
			);

			$expected_seal = self::seal( (string) $row['prev_hash'], $record_hash );

			if ( ! hash_equals( $expected_seal, (string) $row['sha256_audit_seal'] ) ) {
				$result['status']      = 'BROKEN';
				$result['broken_node'] = $id;
				$result['error']       = 'seal does not match the row contents (a field was edited)';
				return $result;
			}

			// The id is what the CLI prints, the REST answer returns and the Consent Vault anchors; it is
			// derived from the digest, so it is checked against it (it was never checked until 2026-09-15).
			if ( ! hash_equals( 'ERASE-' . substr( $record_hash, 0, 16 ), (string) $row['certificate_id'] ) ) {
				$result['status']      = 'BROKEN';
				$result['broken_node'] = $id;
				$result['error']       = 'certificate_id does not match the row contents (the id was edited)';
				return $result;
			}

			$expected_prev = (string) $row['sha256_audit_seal'];
		}

		// The rows on their own cannot show that the newest ones were removed: a truncated chain
		// verifies perfectly. The mirrored tip is what catches that. Every install that chains rows has
		// written the option since the chain exists (ea2519c), so sealed rows without it mean it was removed.
		if ( $result['checked'] > 0 && '' === $stored_tip ) {
			$result['status'] = 'BROKEN';
			$result['error']  = 'sealed certificates exist, but the recorded chain tip is missing';
			return $result;
		}
		if ( '' !== $stored_tip && ! hash_equals( $stored_tip, $expected_prev ) ) {
			$result['status'] = 'BROKEN';
			$result['error']  = 'chain tip does not match the recorded tip (newest certificates were removed)';
			return $result;
		}

		return $result;
	}
}
