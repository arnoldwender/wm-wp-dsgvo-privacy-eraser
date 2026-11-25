<?php
/**
 * WM Suite DSGVO Art. 17 Privacy Eraser — uninstaller.
 *
 * Clears the scheduled expiry sweep unconditionally; preserves plugin options and both database
 * tables by default unless the administrator explicitly opted into data deletion via
 * `wm_dsgvo_eraser_delete_data_on_uninstall`.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Expiry sweep (Core\Plugin::CRON_HOOK) — always unscheduled so no orphan cron hook stays behind.
wp_clear_scheduled_hook( 'wm_dsgvo_expire_requests' );

// 2. Opt-in gate (D-UNINSTALL): preserve erasure certificates, request hashes, and options by default.
if ( ! filter_var( get_option( 'wm_dsgvo_eraser_delete_data_on_uninstall', false ), FILTER_VALIDATE_BOOLEAN ) ) {
	return;
}

global $wpdb;

// 3. Options (prefixed names: this file runs in the global scope).
$wm_dsgvo_eraser_options = [
	'wm_dsgvo_cert_chain_tip',
	'wm_dsgvo_schema_version',
	'wm_dsgvo_anonymize_wc_orders',
	'wm_dsgvo_eraser_delete_data_on_uninstall',
];

foreach ( $wm_dsgvo_eraser_options as $wm_dsgvo_eraser_option ) {
	delete_option( $wm_dsgvo_eraser_option );
	delete_site_option( $wm_dsgvo_eraser_option );
}

// 4. Rate-limit transients (`wm_dsgvo_rl_*`).
if ( isset( $wpdb->options ) && is_string( $wpdb->options ) && '' !== $wpdb->options ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_wm_dsgvo_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wm_dsgvo_rl_' ) . '%'
		)
	);
}

// 5. Tables.
$wm_dsgvo_eraser_certs = $wpdb->prefix . 'wm_dsgvo_erasure_certificates';
$wm_dsgvo_eraser_reqs  = $wpdb->prefix . 'wm_dsgvo_requests';

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wm_dsgvo_eraser_certs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes the plugin's own tables when opted in.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wm_dsgvo_eraser_reqs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes the plugin's own tables when opted in.
