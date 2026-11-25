<?php
/**
 * Runs one of the three WordPress tests of this plugin inside a transaction that is rolled back when PHP
 * shuts down, so its rows, the erased test account and any Consent Vault anchor it appends are gone
 * afterwards.
 *
 *   WM_ERASER_TEST=test-erasure-authorization.php wp eval-file tests/wp-run-rolled-back.php --user=1
 *   WM_ERASER_TEST=test-certificate-chain.php wp eval-file tests/wp-run-rolled-back.php scratch --user=1
 *
 * Because every write is rolled back, the run declares the install disposable for the included test. It
 * refuses a persistent object cache (cached options and users would survive the rollback). Statements that
 * would end the outer transaction are rewritten for the duration: START TRANSACTION / BEGIN by other code
 * become SAVEPOINTs, their COMMIT a RELEASE and their ROLLBACK a ROLLBACK TO SAVEPOINT, and schema
 * statements are refused, since DDL commits implicitly. The first version of this file did not rewrite the
 * transaction statements: WM Activity Sentinel logs every user change in its own START TRANSACTION …
 * COMMIT, which committed the outer transaction on a local WordPress install on 2026-09-15 and kept three log entries and
 * one Consent Vault anchor of the authorization test. GET_LOCK and auto-increment counters are not
 * transactional: ids used by the run stay used. The tables involved must be InnoDB.
 *
 * @package WMCyber\DSGVO\Eraser
 */

defined( 'ABSPATH' ) || exit; // the local install serves the plugin folder over HTTP; this file is for wp eval-file only.

$wm_rb_test = basename( (string) getenv( 'WM_ERASER_TEST' ) );
if ( ! in_array( $wm_rb_test, array( 'test-certificate-chain.php', 'test-erasure-authorization.php', 'test-request-address-at-rest.php' ), true ) ) {
	WP_CLI::error( 'WM_ERASER_TEST must name one of the three WordPress tests.' );
}
if ( wp_using_ext_object_cache() ) {
	WP_CLI::error( 'A persistent object cache is active; the rollback would leave cached options and users behind. Not run.' );
}

global $wpdb;
$GLOBALS['wm_rb_depth'] = 0;
$wm_rb_rewrite          = static function ( $query ) {
	$q = ltrim( (string) $query );
	if ( preg_match( '/^(START\s+TRANSACTION|BEGIN)\b/i', $q ) ) {
		++$GLOBALS['wm_rb_depth'];
		return 'SAVEPOINT wm_rb_' . $GLOBALS['wm_rb_depth'];
	}
	if ( preg_match( '/^COMMIT\b/i', $q ) ) {
		return $GLOBALS['wm_rb_depth'] > 0 ? 'RELEASE SAVEPOINT wm_rb_' . $GLOBALS['wm_rb_depth']-- : 'SELECT 1';
	}
	if ( preg_match( '/^ROLLBACK\b/i', $q ) ) {
		return $GLOBALS['wm_rb_depth'] > 0 ? 'ROLLBACK TO SAVEPOINT wm_rb_' . $GLOBALS['wm_rb_depth']-- : 'SELECT 1';
	}
	return preg_match( '/^(ALTER|CREATE|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+autocommit)\b/i', $q ) ? '' : $query;
};
$wpdb->query( 'START TRANSACTION' );
add_filter( 'query', $wm_rb_rewrite );
register_shutdown_function(
	static function () use ( $wpdb, $wm_rb_rewrite ): void {
		remove_filter( 'query', $wm_rb_rewrite );
		$wpdb->query( 'ROLLBACK' );
		fwrite( STDERR, "[wp-run-rolled-back] transaction rolled back\n" );
	}
);

putenv( 'WM_ERASER_INTEGRATION_DISPOSABLE=1' );
include __DIR__ . '/' . $wm_rb_test;
