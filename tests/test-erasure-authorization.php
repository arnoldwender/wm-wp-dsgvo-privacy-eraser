<?php
/**
 * Authorization test for the erasure flow, against a real WordPress and MySQL.
 *
 * Before a2d634d, POST /wm-dsgvo-eraser/v1/request carried permission_callback => '__return_true' and
 * called execute_erasure() straight from the request body: an anonymous POST with someone else's address
 * deleted their account, rewrote their comments and wiped their order billing data. Until 2026-09-15 the
 * mailed link erased on a plain GET of /confirm, so a mail scanner that fetched it did the same; Case 3
 * pins that opening the link erases nothing.
 *
 * Run on a disposable install only:
 *   WM_ERASER_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-privacy-eraser/tests/test-erasure-authorization.php
 *
 * Without the variable it exits with code 2 before touching anything. The run creates the user
 * `erasurevictim`, erases it (its posts with it), issues one certificate that the teardown removes, and —
 * with the WM Sovereign Consent Vault active — appends one permanent anchor to the vault's chain. Exit 1
 * when an assertion fails (until 2026-09-15 it always exited 0).
 *
 * @package WMCyber\DSGVO\Eraser
 */

if ( '1' !== getenv( 'WM_ERASER_INTEGRATION_DISPOSABLE' ) ) {
	fwrite( STDERR, "Refused: this test writes to the WordPress install it runs in. Set WM_ERASER_INTEGRATION_DISPOSABLE=1 on a disposable install.\n" );
	exit( 2 );
}

use WMCyber\DSGVO\Eraser\Request\ErasureConfirmation;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;
use WMCyber\DSGVO\Eraser\Database\Schema;
use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;

$GLOBALS['wm_e_passed'] = 0;
$GLOBALS['wm_e_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_e_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_e_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_e_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
echo "\n=== ERASURE AUTHORIZATION TEST ===\n\n";

// Capture the confirmation mail instead of sending it.
$GLOBALS['wm_e_mail'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['wm_e_mail'][] = $atts;
		return true;
	},
	10,
	2
);

// --- Fixture -------------------------------------------------------------
// Case 4 performs a real erasure and therefore issues a certificate. It is the newest chained row, so the
// teardown removes it and puts the chain tip back exactly.
$wm_e_certs      = Schema::get_certificates_table();
$wm_e_cert_max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $wm_e_certs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wm_e_tip_before = get_option( ErasureCertificateGenerator::TIP_OPTION, null );

$email = 'erasure-victim@example.test';
wp_delete_user( (int) ( get_user_by( 'email', $email )->ID ?? 0 ) );
$victim_id = wp_insert_user(
	array(
		'user_login' => 'erasurevictim',
		'user_email' => $email,
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'editor',
	)
);

try {
	// --- Case 1: the original attack must no longer delete anything ----------
	echo "Case 1 — anonymous POST to /request (the original attack)\n";
	$GLOBALS['wm_e_mail'] = array();
	$req                  = new WP_REST_Request( 'POST', '/wm-dsgvo-eraser/v1/request' );
	$req->set_param( 'email', $email );
	$res  = rest_do_request( $req );
	$body = $res->get_data();

	wm_e_assert( 202 === $res->get_status(), 'HTTP 202: request accepted, nothing executed' );
	wm_e_assert( ! isset( $body['data']['total_purged'] ) && ! isset( $body['data']['certificate'] ), 'the answer carries no erasure result and no record' );
	wm_e_assert( false !== get_user_by( 'email', $email ), 'the account still exists' );
	wm_e_assert( 1 === count( $GLOBALS['wm_e_mail'] ), 'exactly one confirmation mail' );

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email_hmac = %s ORDER BY id DESC LIMIT 1', Schema::get_requests_table(), ErasureRequestManager::blind( $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	wm_e_assert( null !== $row && 'pending' === $row->status && ! empty( $row->token_hash ), 'the request is pending with a token hash' );
	preg_match( '/wm_erasure_token=([a-f0-9]{64})/', (string) ( $GLOBALS['wm_e_mail'][0]['message'] ?? '' ), $m );
	$raw_token = $m[1] ?? '';
	$uuid      = (string) ( $row->request_uuid ?? '' );
	wm_e_assert( 64 === strlen( $raw_token ), 'the mail links the confirmation page with a 64-hex token' );

	// --- Case 2: a wrong token must not confirm ------------------------------
	echo "\nCase 2 — confirmation with a wrong token\n";
	$bad = ErasureConfirmation::run( $uuid, bin2hex( random_bytes( 32 ) ) );
	wm_e_assert( 'invalid' === $bad['state'] && 403 === $bad['http'], 'a wrong token is refused' );
	wm_e_assert( false !== get_user_by( 'email', $email ), 'the account is unchanged' );

	// --- Case 3: opening the link erases nothing ------------------------------
	echo "\nCase 3 — opening the link (what a mail scanner does)\n";
	$get = new WP_REST_Request( 'GET', '/wm-dsgvo-eraser/v1/confirm' );
	$get->set_param( 'request', $uuid );
	$get->set_param( 'token', $raw_token );
	$get_res = rest_do_request( $get );
	wm_e_assert( 302 === $get_res->get_status() && str_contains( (string) ( $get_res->get_headers()['Location'] ?? '' ), 'wm_dsgvo_erasure=confirm' ), 'a GET of the REST link redirects to the confirmation page' );
	wm_e_assert( true === ErasureConfirmation::pending( $uuid, $raw_token ), 'the confirmation page finds the request' );
	wm_e_assert( false !== get_user_by( 'email', $email ), 'the account still exists after the link was opened' );

	// --- Case 4: the button erases -------------------------------------------
	echo "\nCase 4 — confirmation on the page\n";
	$ok = ErasureConfirmation::run( $uuid, $raw_token );
	wm_e_assert( 'done' === $ok['state'] && 200 === $ok['http'], 'the POST of the button erases' );
	wm_e_assert( false === get_user_by( 'email', $email ), 'the account is deleted' );

	// --- Case 5: the token is single use -------------------------------------
	echo "\nCase 5 — reusing the token\n";
	wm_e_assert( 'invalid' === ErasureConfirmation::run( $uuid, $raw_token )['state'], 'a second confirmation is refused' );
	$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_uuid = %s', Schema::get_requests_table(), $uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	wm_e_assert( null !== $after && 'processed' === $after->status, 'the request is processed' );
	wm_e_assert( null !== $after && null === $after->email_enc && null === $after->token_hash, 'address and token hash are gone from the request' );

	// --- Case 6: no enumeration oracle ---------------------------------------
	echo "\nCase 6 — no account enumeration\n";
	$unknown = new WP_REST_Request( 'POST', '/wm-dsgvo-eraser/v1/request' );
	$unknown->set_param( 'email', 'definitely-not-a-user@example.test' );
	$u_res = rest_do_request( $unknown );
	wm_e_assert( 202 === $u_res->get_status() && ( $u_res->get_data()['message'] ?? '' ) === ( $body['message'] ?? '' ), 'an unknown address gets the same status and message' );

	// --- Case 7: WooCommerce billing is left alone by default -----------------
	echo "\nCase 7 — WooCommerce switch\n";
	wm_e_assert( false === (bool) get_option( 'wm_dsgvo_anonymize_wc_orders', false ), 'order anonymisation is off by default' );
} catch ( Throwable $e ) {
	wm_e_assert( false, 'no exception: ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	// --- Teardown --------------------------------------------------------
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email_hmac IN (%s, %s)', Schema::get_requests_table(), ErasureRequestManager::blind( $email ), ErasureRequestManager::blind( 'definitely-not-a-user@example.test' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wm_e_issued = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $wm_e_certs, $wm_e_cert_max ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( null === $wm_e_tip_before ) {
		delete_option( ErasureCertificateGenerator::TIP_OPTION );
	} else {
		update_option( ErasureCertificateGenerator::TIP_OPTION, $wm_e_tip_before, false );
	}
	// Until 2026-09-15 the user stayed behind when the erasure case failed.
	$leftover = get_user_by( 'email', $email );
	if ( $leftover ) {
		wp_delete_user( (int) $leftover->ID );
	}
}
wm_e_assert( 1 === $wm_e_issued, 'the certificate issued by case 4 was removed again (' . $wm_e_issued . ')' );

echo "\n----------------------------------------------------\n";
$p = (int) $GLOBALS['wm_e_passed'];
$f = (int) $GLOBALS['wm_e_failed'];
echo "Tests passed: {$p} / " . ( $p + $f ) . "\n";
echo ( 0 === $f && $p > 0 ) ? "VERDICT: PASS\n" : "VERDICT: FAIL\n";
echo "----------------------------------------------------\n";
exit( $GLOBALS['wm_e_failed'] > 0 ? 1 : 0 );
