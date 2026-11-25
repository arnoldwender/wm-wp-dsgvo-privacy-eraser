<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public static function get_certificates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_dsgvo_erasure_certificates';
	}

	public static function get_requests_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_dsgvo_requests';
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_certs = self::get_certificates_table();
		$table_reqs  = self::get_requests_table();

		// prev_hash carries the tamper-evident chain: each certificate's seal is an HMAC over the
		// previous certificate's seal plus its own contents. Rows issued before the chain existed
		// keep the empty default and are reported as unchained rather than as tampering.
		$sql_certs = "CREATE TABLE {$table_certs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			certificate_id VARCHAR(64) NOT NULL,
			blinded_email_hmac VARCHAR(64) NOT NULL,
			purged_records_count INT UNSIGNED NOT NULL DEFAULT 0,
			purged_tables_summary TEXT NOT NULL,
			prev_hash VARCHAR(64) NOT NULL DEFAULT '',
			sha256_audit_seal VARCHAR(64) NOT NULL,
			erased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY cert_id_idx (certificate_id),
			KEY hmac_idx (blinded_email_hmac)
		) ENGINE=InnoDB {$charset_collate};";

		// token_hash / expires_at / confirmed_at carry the confirmation step: a request is inert until
		// someone with access to the mailbox opens the single-use link and confirms on the page. email_enc holds the
		// address only until the request is processed or expires — it is required to perform the
		// erasure and is cleared afterwards.
		$sql_reqs = "CREATE TABLE {$table_reqs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_uuid VARCHAR(64) NOT NULL,
			email_hmac VARCHAR(64) NOT NULL,
			email_enc TEXT NULL,
			token_hash VARCHAR(64) NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			ip_hmac VARCHAR(64) NOT NULL,
			requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NULL,
			confirmed_at DATETIME NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid_idx (request_uuid),
			KEY token_idx (token_hash),
			KEY status_idx (status),
			KEY email_hmac_idx (email_hmac)
		) ENGINE=InnoDB {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql_certs );
			dbDelta( $sql_reqs );
		}
	}
}
