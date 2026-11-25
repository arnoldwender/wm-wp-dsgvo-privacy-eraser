<?php
/**
 * Falsifier for the tamper-evidence claim on the erasure certificates.
 *
 * The admin dashboard used to call the certificates "immutable" while the seal was a plain
 * sha256() over three values that were all stored in the same row — recomputable by anyone with
 * database write access and no secret at all. This file is the test that would have caught that:
 * every case below edits, removes or inserts a row and requires verification to go RED.
 *
 * Mutation check: remove the hash_equals() comparison in
 * ErasureCertificateGenerator::verify_chain_integrity() and cases 2-6 must fail. If they still
 * pass, the test is decorative and the claim is unguarded again.
 *
 * Run on a disposable install only:
 *   WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-certificate-chain.php [scratch]
 *
 * Without the variable it exits with code 2 before touching anything. It truncates the certificate table
 * (refused on a populated table unless "scratch" is passed), and with the WM Sovereign Consent Vault active
 * every certificate it issues also appends a permanent anchor to the vault's chain, which no teardown can
 * remove. Exit 1 when an assertion fails (until 2026-09-15 it always exited 0).
 *
 * @package WMCyber\DSGVO\Eraser
 */

if ( '1' !== getenv( 'WM_ERASER_INTEGRATION_DISPOSABLE' ) ) {
	fwrite( STDERR, "Refused: this test writes to the WordPress install it runs in. Set WM_ERASER_INTEGRATION_DISPOSABLE=1 on a disposable install.\n" );
	exit( 2 );
}

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Database\Schema;

// eval-file runs inside a function, so a plain `global` would not bind for helpers below.
$GLOBALS['wm_c_passed'] = 0;
$GLOBALS['wm_c_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_c_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_c_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_c_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
echo "\n=== CERTIFICATE CHAIN TAMPER-EVIDENCE TEST ===\n\n";

Schema::create_tables();
$table = Schema::get_certificates_table();

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

// A populated table is refused by default: this test truncates it. On a disposable database the
// caller says so with the positional argument "scratch" (wp eval-file <file> scratch); the
// existing rows and the chain tip are then snapshotted and put back at teardown, ids included.
$wm_c_scratch = isset( $args ) && is_array( $args ) && in_array( 'scratch', $args, true );
$wm_c_snapshot = array();
if ( $existing > 0 && ! $wm_c_scratch ) {
	echo "  [SKIP] {$table} already holds {$existing} certificate(s).\n";
	echo "         This test rewrites and truncates that table. Run it on a scratch install,\n";
	echo "         or pass the argument \"scratch\" to snapshot and restore the rows.\n\n";
	return;
}
if ( $existing > 0 ) {
	$wm_c_snapshot = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	echo "  [INFO] scratch mode: {$existing} certificate(s) snapshotted, restored at teardown.\n";
}

$gen           = new ErasureCertificateGenerator();
$saved_tip     = get_option( ErasureCertificateGenerator::TIP_OPTION, '' );
$reset_fixture = static function () use ( $wpdb, $table, $gen ) {
	$wpdb->query( "DELETE FROM {$table}" );
	delete_option( ErasureCertificateGenerator::TIP_OPTION );
	foreach ( array( 'a@example.test', 'b@example.test', 'c@example.test' ) as $i => $addr ) {
		$gen->issue_certificate( $addr, ( $i + 1 ) * 10, array( 'wp_comments' => ( $i + 1 ) * 10 ) );
	}
	return $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" );
};

// --- Case 1: a chain nobody touched verifies ------------------------------
$ids    = $reset_fixture();
$result = $gen->verify_chain_integrity();
wm_c_assert( 'INTACT' === $result['status'], 'untouched chain verifies as INTACT' );
wm_c_assert( 3 === $result['checked'], 'all three certificates were checked (got ' . $result['checked'] . ')' );

// --- Case 2: editing a sealed field is detected ---------------------------
$ids = $reset_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET purged_records_count = 999 WHERE id = %d", $ids[1] ) );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'edited purged_records_count breaks the chain' );
wm_c_assert( (int) $ids[1] === (int) $result['broken_node'], 'the edited row is the one reported' );

// --- Case 3: recomputing the old-style seal no longer forges anything -----
// This is the exact attack the previous implementation allowed: rewrite the row, then rewrite
// its seal with a hash that needs no key. It must not produce a chain that verifies.
$ids = $reset_fixture();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ids[1] ), ARRAY_A );
$forged_payload = 'GDPR-ART17-ERASURE|' . $row['blinded_email_hmac'] . '|COUNT:999|' . $row['erased_at'];
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$table} SET purged_records_count = 999, sha256_audit_seal = %s WHERE id = %d",
		hash( 'sha256', $forged_payload ),
		$ids[1]
	)
);
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'unkeyed seal recomputation does not forge a valid chain' );

// --- Case 3b: tampering with the NEWEST row is detected --------------------
// The tail is the attacker's natural target: no successor row exists whose prev_hash would break.
// Measured under mutation, the guard that actually catches this one is the mirrored tip, not the
// seal comparison — remove the tip check and this case goes green again. Case 2 is the one that
// isolates the seal itself, because it edits a field without touching the seal at all.
$ids = $reset_fixture();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", end( $ids ) ), ARRAY_A );
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$table} SET purged_records_count = 0, sha256_audit_seal = %s WHERE id = %d",
		hash( 'sha256', 'GDPR-ART17-ERASURE|' . $row['blinded_email_hmac'] . '|COUNT:0|' . $row['erased_at'] ),
		end( $ids )
	)
);
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'tampering with the newest row is detected' );

// --- Case 4: deleting a row in the middle is detected ---------------------
$ids = $reset_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $ids[1] ) );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'deleting a middle row breaks the chain' );

// --- Case 5: deleting the newest row is detected via the mirrored tip -----
// Without the tip option this case passes silently: a truncated chain verifies perfectly.
$ids = $reset_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", end( $ids ) ) );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'deleting the newest row is caught by the recorded tip' );

// --- Case 6: pre-chain rows are reported as unchained, not as intact ------
$ids = $reset_fixture();
$wpdb->query( "DELETE FROM {$table}" );
delete_option( ErasureCertificateGenerator::TIP_OPTION );
$wpdb->insert(
	$table,
	array(
		'certificate_id'        => 'ERASE-legacyrow000000',
		'blinded_email_hmac'    => str_repeat( 'a', 64 ),
		'purged_records_count'  => 5,
		'purged_tables_summary' => 'wp_comments: 5',
		'prev_hash'             => '',
		'sha256_audit_seal'     => str_repeat( 'b', 64 ),
		'erased_at'             => current_time( 'mysql' ),
	)
);
$gen->issue_certificate( 'd@example.test', 7, array( 'wp_comments' => 7 ) );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'INTACT' === $result['status'], 'a pre-chain row before the chain is not called tampering' );
wm_c_assert( 1 === $result['unchained_legacy'], 'the pre-chain row is counted as unchained, not as verified' );
wm_c_assert( 1 === $result['checked'], 'only the sealed row counts as checked' );

// --- Case 7: an unsealed row inserted AFTER the chain is tampering --------
$wpdb->insert(
	$table,
	array(
		'certificate_id'        => 'ERASE-injected000000',
		'blinded_email_hmac'    => str_repeat( 'c', 64 ),
		'purged_records_count'  => 4242,
		'purged_tables_summary' => 'wp_users: 4242',
		'prev_hash'             => '',
		'sha256_audit_seal'     => str_repeat( 'd', 64 ),
		'erased_at'             => current_time( 'mysql' ),
	)
);
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'an unsealed row appended after the chain is reported' );

// --- Case 8: removing the newest row stays visible after the next erasure --------
// Until 2026-09-15 the next certificate chained onto the newest remaining row and overwrote the tip.
$ids = $reset_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", end( $ids ) ) );
$gen->issue_certificate( 'e@example.test', 5, array( 'wp_comments' => 5 ) );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'a removed newest row is still reported after the next certificate' );

// --- Case 9: every row removed while the tip is recorded -------------------
$ids = $reset_fixture();
$wpdb->query( "DELETE FROM {$table}" );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'an emptied table with a recorded tip is BROKEN, not EMPTY_CHAIN' );

// --- Case 10: an edited certificate id is detected --------------------------
$ids = $reset_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET certificate_id = %s WHERE id = %d", 'ERASE-forged00000000', $ids[0] ) );
$result = $gen->verify_chain_integrity();
wm_c_assert( 'BROKEN' === $result['status'], 'an edited certificate_id breaks the chain' );

// --- Teardown -------------------------------------------------------------
$wpdb->query( "DELETE FROM {$table}" );
foreach ( $wm_c_snapshot as $wm_c_row ) {
	$wpdb->insert( $table, $wm_c_row );
}
if ( '' === $saved_tip ) {
	delete_option( ErasureCertificateGenerator::TIP_OPTION );
} else {
	update_option( ErasureCertificateGenerator::TIP_OPTION, $saved_tip, false );
}
if ( count( $wm_c_snapshot ) > 0 ) {
	$wm_c_restored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	wm_c_assert( $wm_c_restored === count( $wm_c_snapshot ), 'scratch mode: the ' . count( $wm_c_snapshot ) . ' pre-existing certificate(s) are back (' . $wm_c_restored . ')' );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

echo "\n=== RESULT: {$GLOBALS['wm_c_passed']} passed, {$GLOBALS['wm_c_failed']} failed ===\n\n";
exit( $GLOBALS['wm_c_failed'] > 0 ? 1 : 0 );
