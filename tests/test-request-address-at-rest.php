<?php
/**
 * Falsifier for the "Zero Cleartext Stored" claim the dashboard used to print.
 *
 * The requests table has a column named `email_enc`. Until 1.2.0 it held the address verbatim —
 * the name promised an encryption nobody had written, and the admin dashboard advertised the
 * promise as a fact. This file fails if the address is ever readable in the column again, and if
 * a request nobody confirms keeps its address past the confirmation window.
 *
 * Mutation check: change RequestCipher::encrypt() back to `return $plain;` and case 1 must fail.
 * Remove the WHERE clause from purge_expired() and case 5 must fail.
 *
 * Run on a disposable install only:
 *   WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-request-address-at-rest.php
 *
 * Without the variable it exits with code 2. It writes request rows (deleted at the end). Exit 1 when an
 * assertion fails.
 *
 * @package WMCyber\DSGVO\Eraser
 */

if ( '1' !== getenv( 'WM_ERASER_INTEGRATION_DISPOSABLE' ) ) {
	fwrite( STDERR, "Refused: this test writes to the WordPress install it runs in. Set WM_ERASER_INTEGRATION_DISPOSABLE=1 on a disposable install.\n" );
	exit( 2 );
}

use WMCyber\DSGVO\Eraser\Crypto\RequestCipher;
use WMCyber\DSGVO\Eraser\Database\Schema;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_r_passed'] = 0;
$GLOBALS['wm_r_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_r_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_r_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_r_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
echo "\n=== REQUEST ADDRESS AT REST TEST ===\n\n";

Schema::create_tables();
$table = Schema::get_requests_table();

if ( ! RequestCipher::is_available() ) {
	echo "  [SKIP] libsodium is not available in this runtime.\n";
	echo "         The plugin stores the address in cleartext here and the dashboard says so.\n\n";
	return;
}

// Swallow the confirmation mail.
add_filter( 'pre_wp_mail', '__return_true', 10, 0 );

$email = 'at-rest-probe@example.test';
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email_hmac = %s", ErasureRequestManager::blind( $email ) ) );

// --- Case 1: the address is not readable in the column --------------------
ErasureRequestManager::create( $email );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hmac = %s ORDER BY id DESC LIMIT 1", ErasureRequestManager::blind( $email ) ) );

wm_r_assert( null !== $row, 'the request was stored' );
wm_r_assert( null !== $row && false === strpos( (string) $row->email_enc, $email ), 'the address does not appear in email_enc' );
wm_r_assert( null !== $row && false === strpos( (string) $row->email_enc, 'at-rest-probe' ), 'not even the local part appears in email_enc' );

// --- Case 2: and it is still recoverable for the erasure ------------------
wm_r_assert( null !== $row && $email === ErasureRequestManager::address_of( $row ), 'address_of() recovers the address' );

// --- Case 3: a pre-1.2.0 cleartext row still opens ------------------------
// Upgrading must not strand a request that was already in flight.
$legacy = (object) array( 'email_enc' => 'legacy-plaintext@example.test' );
wm_r_assert( 'legacy-plaintext@example.test' === ErasureRequestManager::address_of( $legacy ), 'a pre-1.2.0 cleartext row is still readable' );

// --- Case 4: a corrupt envelope yields nothing, never garbage -------------
$broken = (object) array( 'email_enc' => 'wmv1:' . base64_encode( 'not a real envelope at all' ) );
wm_r_assert( '' === ErasureRequestManager::address_of( $broken ), 'a corrupt envelope returns empty, not garbage' );

// --- Case 5: an unconfirmed request loses its address at expiry -----------
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$table} SET expires_at = %s WHERE id = %d",
		gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		(int) $row->id
	)
);

$fresh_email = 'still-valid-probe@example.test';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email_hmac = %s", ErasureRequestManager::blind( $fresh_email ) ) );
ErasureRequestManager::create( $fresh_email );
$fresh_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hmac = %s ORDER BY id DESC LIMIT 1", ErasureRequestManager::blind( $fresh_email ) ) );

ErasureRequestManager::purge_expired();

$expired_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $row->id ) );
$fresh_after   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $fresh_id ) );

wm_r_assert( null !== $expired_after && null === $expired_after->email_enc, 'the expired request no longer holds an address' );
wm_r_assert( null !== $expired_after && 'expired' === $expired_after->status, 'the expired request is marked expired' );
wm_r_assert( null !== $expired_after && null === $expired_after->token_hash, 'the expired request no longer holds a token hash' );
wm_r_assert( null !== $fresh_after && null !== $fresh_after->email_enc, 'a request still inside its window is left alone' );

// --- Teardown -------------------------------------------------------------
foreach ( array( $email, $fresh_email ) as $addr ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email_hmac = %s", ErasureRequestManager::blind( $addr ) ) );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
remove_filter( 'pre_wp_mail', '__return_true', 10 );

echo "\n=== RESULT: {$GLOBALS['wm_r_passed']} passed, {$GLOBALS['wm_r_failed']} failed ===\n\n";
exit( $GLOBALS['wm_r_failed'] > 0 ? 1 : 0 );
