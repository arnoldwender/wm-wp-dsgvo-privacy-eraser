<?php
/**
 * Tells a tampered chain from a chain copied between installs with different salts.
 *
 *   wp eval-file tests/wp-chain-provenance.php          (run it on both installs and compare the lines)
 *
 * Prints a digest of the certificate rows, a digest of the tip option and a short fingerprint of the key the
 * seals are made with (a hash of an HMAC of a constant, so the salt cannot be derived from it). Same rows,
 * same tip and a different fingerprint mean the data was copied and the seals were made with the other
 * install's salt: verification reports BROKEN at the first row although nothing was edited. Measured on
 * 2026-09-15 between a local WordPress install (INTACT) and the demo Box (BROKEN, seal mismatch at row 1). Read-only.
 *
 * @package WMCyber\DSGVO\Eraser
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT certificate_id, blinded_email_hmac, purged_records_count, purged_tables_summary, prev_hash, sha256_audit_seal, erased_at FROM %i ORDER BY id', $wpdb->prefix . 'wm_dsgvo_erasure_certificates' ), ARRAY_A );
echo 'rows ', count( $rows ), ' | rows sha256 ', hash( 'sha256', wp_json_encode( $rows ) ), ' | tip sha256 ', hash( 'sha256', (string) get_option( 'wm_dsgvo_cert_chain_tip' ) ), ' | key fingerprint ', substr( hash( 'sha256', 'fingerprint|' . hash_hmac( 'sha256', 'probe', wp_salt( 'secure_auth' ) ) ), 0, 8 ), "\n";
