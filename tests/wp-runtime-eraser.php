<?php
/**
 * Runtime check inside a real WordPress and MySQL for the reader pass of 2026-09-15.
 *
 *   wp eval-file tests/wp-runtime-eraser.php --user=1
 *
 * Leaves no row behind. The part that needs a pending request (opening the mailed link, the claim race)
 * runs inside a transaction that is rolled back; the row counts before and after are compared. That is
 * only safe with a non-persistent object cache, so the file refuses to run under a persistent one.
 * Written to also run against the code before the fix, where it measures the defects instead of failing
 * on a missing class. Exit 1 (WP_CLI::error) on failure.
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS, and there is no strict_types
 * declaration because one after the docblock is fatal there.
 *
 * @package WMCyber\DSGVO\Eraser
 */

defined( 'ABSPATH' ) || exit; // the local install serves the plugin folder over HTTP; this file is for wp eval-file only.

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Crypto\RequestCipher;
use WMCyber\DSGVO\Eraser\Database\Schema;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;

$GLOBALS['wm_er_rt'] = [ 'pass' => 0, 'fail' => 0 ];

function wm_er_rt_check( string $name, bool $ok, string $details = '' ): void {
	$GLOBALS['wm_er_rt'][ $ok ? 'pass' : 'fail' ]++;
	WP_CLI::log( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ( $ok || '' === $details ? '' : ': ' . $details ) );
}

if ( ! class_exists( ErasureCertificateGenerator::class ) ) {
	WP_CLI::error( 'wm-dsgvo-privacy-eraser is not loaded.' );
}
if ( ! current_user_can( 'manage_options' ) ) {
	WP_CLI::error( 'Run with --user=<an administrator>.' );
}
if ( wp_using_ext_object_cache() ) {
	WP_CLI::error( 'A persistent object cache is active; the rolled-back part of this check could leave cache entries behind. Not run.' );
}

global $wpdb;
$certs    = Schema::get_certificates_table();
$requests = Schema::get_requests_table();
$count    = static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

wm_er_rt_check( 'both tables exist', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $certs ) ) ) === $certs && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $requests ) ) ) === $requests );
WP_CLI::log( 'WooCommerce loaded: ' . ( function_exists( 'wc_get_orders' ) ? 'yes' : 'no (the order branch is not measured here)' ) . ' | multisite: ' . ( is_multisite() ? 'yes' : 'no' ) );

// The chain as it stands, read through the real SQL.
$wpdb->last_error = '';
$chain            = ( new ErasureCertificateGenerator() )->verify_chain_integrity();
WP_CLI::log( 'chain ' . wp_json_encode( $chain ) . ' | certificates ' . $count( $certs ) . ' | requests ' . $count( $requests ) );
// A chain copied from an install with other salts verifies BROKEN at row 1 without any edit (the demo Box,
// measured 2026-09-15 with tests/wp-chain-provenance.php); this check therefore records the status and
// requires the rest of the plugin to report it consistently, not a green chain.
wm_er_rt_check( 'the chain verification runs without a database error and names a status', '' === $wpdb->last_error && in_array( $chain['status'], [ 'INTACT', 'EMPTY_CHAIN', 'BROKEN' ], true ), (string) $wpdb->last_error );

// D4 and D5 inside a rolled-back transaction: a pending request with a known token.
$before  = [ $count( $certs ), $count( $requests ), (string) get_option( ErasureCertificateGenerator::TIP_OPTION, '' ) ];
$uuid    = bin2hex( random_bytes( 16 ) );
$token   = bin2hex( random_bytes( 32 ) );
$address = 'runtime-' . bin2hex( random_bytes( 4 ) ) . '@example.invalid';

// Anything that would end the transaction early is rewritten for the duration: DDL commits implicitly (the code
// before the fix ran dbDelta() on its way to issuing a certificate), and another plugin's START TRANSACTION …
// COMMIT (WM Activity Sentinel logs in one) would commit this one; those become savepoints.
$GLOBALS['wm_er_rt_depth'] = 0;
$no_ddl                    = static function ( $query ) {
	$q = ltrim( (string) $query );
	if ( preg_match( '/^(START\s+TRANSACTION|BEGIN)\b/i', $q ) ) {
		++$GLOBALS['wm_er_rt_depth'];
		return 'SAVEPOINT wm_er_rt_' . $GLOBALS['wm_er_rt_depth'];
	}
	if ( preg_match( '/^COMMIT\b/i', $q ) ) {
		return $GLOBALS['wm_er_rt_depth'] > 0 ? 'RELEASE SAVEPOINT wm_er_rt_' . $GLOBALS['wm_er_rt_depth']-- : 'SELECT 1';
	}
	if ( preg_match( '/^ROLLBACK\b/i', $q ) ) {
		return $GLOBALS['wm_er_rt_depth'] > 0 ? 'ROLLBACK TO SAVEPOINT wm_er_rt_' . $GLOBALS['wm_er_rt_depth']-- : 'SELECT 1';
	}
	return preg_match( '/^(ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', $q ) ? '' : $query;
};
$wpdb->query( 'START TRANSACTION' );
add_filter( 'query', $no_ddl );
$wpdb->insert(
	$requests,
	[
		'request_uuid' => $uuid,
		'email_hmac'   => ErasureRequestManager::blind( $address ),
		'email_enc'    => RequestCipher::encrypt( $address ),
		'token_hash'   => hash( 'sha256', $token ),
		'status'       => 'pending',
		'ip_hmac'      => ErasureRequestManager::blind( '198.51.100.1' ),
		'requested_at' => gmdate( 'Y-m-d H:i:s' ),
		'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
	]
);
$get = new WP_REST_Request( 'GET', '/wm-dsgvo-eraser/v1/confirm' );
$get->set_param( 'request', $uuid );
$get->set_param( 'token', $token );
$get_res   = rest_do_request( $get );
$location  = (string) ( $get_res->get_headers()['Location'] ?? '' );
$status    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE request_uuid = %s', $requests, $uuid ) );
$new_certs = $count( $certs ) - $before[0];
WP_CLI::log( "GET /confirm with the mailed token: HTTP {$get_res->get_status()} | request status {$status} | certificates added {$new_certs}" );
wm_er_rt_check( 'D4: opening the link erases nothing and redirects to the confirmation page (erased on GET until 2026-09-15)', 302 === $get_res->get_status() && str_contains( $location, 'wm_dsgvo_erasure=confirm' ) && 'pending' === $status && 0 === $new_certs );

if ( method_exists( ErasureRequestManager::class, 'claim' ) ) {
	$first  = ErasureRequestManager::claim( $uuid );
	$second = ErasureRequestManager::claim( $uuid );
	wm_er_rt_check( 'D5: of two claims on the same pending request only the first wins (real conditional UPDATE)', true === $first && false === $second );
} else {
	wm_er_rt_check( 'D5: of two claims on the same pending request only the first wins', false, 'ErasureRequestManager::claim() does not exist' );
}
remove_filter( 'query', $no_ddl );
$wpdb->query( 'ROLLBACK' );
$after = [ $count( $certs ), $count( $requests ), (string) get_option( ErasureCertificateGenerator::TIP_OPTION, '' ) ];
wm_er_rt_check( 'the rollback leaves certificates, requests and the chain tip as they were', $before === $after, wp_json_encode( [ $before, $after ] ) );

// Confirmation page and service, read-only paths.
$page_class = 'WMCyber\\DSGVO\\Eraser\\Frontend\\ErasureConfirmationPage';
$run_class  = 'WMCyber\\DSGVO\\Eraser\\Request\\ErasureConfirmation';
if ( class_exists( $page_class ) && class_exists( $run_class ) ) {
	$ask = $page_class::markup( 'ask', 'u', 't', null );
	wm_er_rt_check( 'D4: the confirmation page offers a POST button', str_contains( $ask, '<form method="post"' ) && str_contains( $ask, 'name="wm_erasure_token"' ) );
	wm_er_rt_check( 'an unknown request is invalid and changes nothing', 'invalid' === $run_class::run( bin2hex( random_bytes( 16 ) ), bin2hex( random_bytes( 32 ) ) )['state'] && $before[1] === $count( $requests ) );
} else {
	wm_er_rt_check( 'D4: the confirmation page exists', false, 'ErasureConfirmationPage / ErasureConfirmation missing' );
}

// Public form script: no REST nonce.
global $wp_filter;
$form_class = 'WMCyber\\DSGVO\\Eraser\\Frontend\\ErasureRequestForm';
( new $form_class() )->render_shortcode();
$footer_cbs = $wp_filter['wp_footer']->callbacks[99] ?? [];
$last_cb    = end( $footer_cbs );
ob_start();
if ( is_array( $last_cb ) && is_callable( $last_cb['function'] ) ) {
	( $last_cb['function'] )();
}
$footer = (string) ob_get_clean();
wm_er_rt_check( 'D13: the public form script sends no X-WP-Nonce', str_contains( $footer, 'wm-dsgvo-eraser' ) && ! preg_match( "/'X-WP-Nonce'\s*:/", $footer ) );

// REST routes, verify route and hub adapter.
$routes = array_keys( rest_get_server()->get_routes() );
wm_er_rt_check( 'REST routes registered', [] === array_diff( [ '/wm-dsgvo-eraser/v1/request', '/wm-dsgvo-eraser/v1/confirm', '/wm-dsgvo-eraser/v1/verify' ], $routes ) );
$verify = rest_do_request( new WP_REST_Request( 'GET', '/wm-dsgvo-eraser/v1/verify' ) );
wm_er_rt_check( 'GET /verify answers 409 for a broken chain and 200 otherwise', ( 'BROKEN' === $chain['status'] ? 409 : 200 ) === $verify->get_status(), (string) $verify->get_status() );

$adapter_class = 'WMCyber\\DSGVO\\Eraser\\Adapters\\Privacy_Eraser_Spoke_Adapter';
if ( ! class_exists( $adapter_class ) ) {
	WP_CLI::log( '[SKIP] hub adapter: WM Suite Hub interface not loaded' );
} else {
	$adapter = new $adapter_class();
	$health  = $adapter->get_health_status();
	$self    = $adapter->execute_action( 'self_test' );
	$licence = $adapter->get_sbom_data()['licenses'][0]['license'] ?? [];
	WP_CLI::log( 'hub health ' . $health . ' | self_test ' . wp_json_encode( $self ) );
	$expected = 'BROKEN' === $chain['status'] ? 'ERROR' : ( RequestCipher::is_available() ? 'HEALTHY' : 'DEGRADED' );
	wm_er_rt_check( 'hub health and self test follow the tables, the chain and libsodium', $expected === $health && ( 'BROKEN' !== $chain['status'] ) === $self['success'], $health . ' expected ' . $expected );
	wm_er_rt_check( 'D18: the SBOM licence has a name and no invalid SPDX id', isset( $licence['name'] ) && ! isset( $licence['id'] ), wp_json_encode( $licence ) );
}

WP_CLI::log( sprintf( '=== RUNTIME: %d passed, %d failed ===', $GLOBALS['wm_er_rt']['pass'], $GLOBALS['wm_er_rt']['fail'] ) );
if ( $GLOBALS['wm_er_rt']['fail'] > 0 ) {
	WP_CLI::error( 'runtime check failed' );
}
