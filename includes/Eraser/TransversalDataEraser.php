<?php
declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Eraser;

defined( 'ABSPATH' ) || exit;

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;

/**
 * Erases or anonymises what WordPress core and WooCommerce hold under one address, then records it in the
 * certificate chain.
 *
 * Covered: comments by that address (anonymised, content replaced), the user account unless it is an
 * administrator, and — only with `wm_dsgvo_anonymize_wc_orders` on — billing first and last name, first
 * address line, phone and email of that address's WooCommerce orders. Not covered: other plugins' tables,
 * form entries, user meta of kept accounts, shipping fields, files, backups, logs and caches.
 *
 * Every area reports what the database actually did. Until 2026-09-15 the counts came from the SELECT
 * before each UPDATE, the UPDATE results were thrown away, `wp_users: 1` was written whether or not
 * wp_delete_user() worked, and `success` was always true; the WooCommerce UPDATE named columns
 * (billing_first_name, billing_last_name, billing_address_1, billing_phone) that the HPOS table `wc_orders`
 * does not have, so MySQL refused it while the record counted the orders as anonymised.
 */
final class TransversalDataEraser {
	/** Address written into anonymised comments and orders. */
	public const ANON_EMAIL = 'anonymized@gdpr.local';

	/**
	 * @return array{success: bool, email_hmac?: string, total_purged?: int, tables?: array, failed_areas?: string[], certificate?: ?array, error?: ?string}
	 */
	public function execute_erasure( string $email ): array {
		global $wpdb;
		$clean_email = sanitize_email( strtolower( trim( $email ) ) );
		if ( '' === $clean_email || ! is_email( $clean_email ) ) {
			return [ 'success' => false, 'error' => 'Invalid email address.' ];
		}

		$areas  = [];
		$failed = [];
		$total  = 0;

		// 1. Comments: the same fields WordPress core's own comment eraser clears (author, address, URL, IP,
		// user agent, user id; the agent and user id were left until 2026-09-15), plus the text.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$comment_ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT comment_ID FROM %i WHERE comment_author_email = %s', $wpdb->comments, $clean_email ) );
		if ( [] !== $comment_ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET comment_author = %s, comment_author_email = %s, comment_author_url = '', comment_author_IP = %s, comment_agent = '', user_id = 0, comment_content = %s WHERE comment_author_email = %s",
					$wpdb->comments,
					'Anonymisiert',
					self::ANON_EMAIL,
					'0.0.0.0',
					'[Inhalt auf Antrag nach Art. 17 DSGVO gelöscht]',
					$clean_email
				)
			);
			if ( false === $changed ) {
				$areas['wp_comments'] = 'failed';
				$failed[]             = 'wp_comments';
			} else {
				$areas['wp_comments'] = (int) $changed;
				$total               += (int) $changed;
				if ( function_exists( 'clean_comment_cache' ) ) {
					clean_comment_cache( array_map( 'intval', $comment_ids ) );
				}
			}
		}

		// 2. WooCommerce orders. OFF by default: order billing data can be subject to commercial and tax
		// retention periods (§ 147 AO, § 257 HGB); whether one applies is not decided here. The orders go
		// through WooCommerce's own order objects, so HPOS, the legacy posts storage and their sync all get
		// the change.
		if ( ! get_option( 'wm_dsgvo_anonymize_wc_orders', false ) ) {
			$areas['woocommerce_orders'] = 'skipped_switch_off';
		} elseif ( ! function_exists( 'wc_get_orders' ) ) {
			$areas['woocommerce_orders'] = 'skipped_woocommerce_inactive';
		} else {
			[ $orders_changed, $orders_failed ] = self::anonymize_orders( $clean_email );
			$total += $orders_changed;
			if ( $orders_failed > 0 ) {
				$areas['woocommerce_orders'] = sprintf( 'failed %d of %d', $orders_failed, $orders_changed + $orders_failed );
				$failed[]                    = 'woocommerce_orders';
			} else {
				$areas['woocommerce_orders'] = $orders_changed;
			}
		}

		// 3. User account (non-administrators only). wp_delete_user() without a reassign target also deletes
		// the posts that user authored. On multisite it only removes the user from this site: the account
		// stays in the network, and the record says so instead of claiming a deletion.
		$user = get_user_by( 'email', $clean_email );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			$areas['wp_users'] = 'skipped_administrator';
		} elseif ( $user ) {
			if ( ! function_exists( 'wp_delete_user' ) && file_exists( ABSPATH . 'wp-admin/includes/user.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$deleted = function_exists( 'wp_delete_user' ) && wp_delete_user( (int) $user->ID );
			if ( ! $deleted ) {
				$areas['wp_users'] = 'failed';
				$failed[]          = 'wp_users';
			} elseif ( is_multisite() ) {
				$areas['wp_users'] = 'removed_from_this_site';
				$total            += 1;
			} else {
				$areas['wp_users'] = 1;
				$total            += 1;
			}
		}

		// 3b. Extensibility: allow companion plugins to anonymise or erase custom tables for this address
		// and fold their reported counts or failures into the sealed certificate summary.
		$extra_areas = apply_filters( 'wm_eraser_anonymize_personal_data', [], $clean_email );
		if ( is_array( $extra_areas ) ) {
			foreach ( $extra_areas as $area_key => $area_state ) {
				$safe_key = sanitize_key( (string) $area_key );
				if ( '' === $safe_key || isset( $areas[ $safe_key ] ) ) {
					continue;
				}
				if ( 'failed' === $area_state || ( is_string( $area_state ) && str_starts_with( $area_state, 'failed' ) ) ) {
					$areas[ $safe_key ] = sanitize_text_field( (string) $area_state );
					$failed[]           = $safe_key;
				} elseif ( is_int( $area_state ) && $area_state >= 0 ) {
					$areas[ $safe_key ] = $area_state;
					$total             += $area_state;
				} else {
					$areas[ $safe_key ] = sanitize_text_field( (string) $area_state );
				}
			}
		}

		// 4. Record.
		$record = ( new ErasureCertificateGenerator() )->issue_certificate( $clean_email, $total, $areas );
		$error  = null;
		if ( isset( $record['error'] ) ) {
			$error = 'The erasure ran, but its record was not written: ' . $record['error'];
		} elseif ( [] !== $failed ) {
			$record['record_status'] = 'ERASURE_INCOMPLETE_AND_CHAINED';
			$error                   = 'Not every area could be changed: ' . implode( ', ', $failed );
		}

		$outcome = [
			'success'      => null === $error,
			'email_hmac'   => (string) ( $record['blinded_hmac'] ?? '' ),
			'total_purged' => $total,
			'tables'       => $areas,
			'failed_areas' => $failed,
			'certificate'  => isset( $record['error'] ) ? null : $record,
			'error'        => $error,
		];

		do_action( 'wm_eraser_completed', $outcome );

		return $outcome;
	}

	/**
	 * Billing first and last name, first address line, phone and email of every order of that address.
	 *
	 * @return array{0: int, 1: int} Orders saved, orders that failed.
	 */
	private static function anonymize_orders( string $email ): array {
		$saved  = 0;
		$failed = 0;
		foreach ( (array) wc_get_orders( [ 'billing_email' => $email, 'limit' => -1 ] ) as $order ) {
			try {
				$order->set_billing_first_name( 'Anonym' );
				$order->set_billing_last_name( 'Kunde' );
				$order->set_billing_address_1( 'Gelöscht' );
				$order->set_billing_phone( '' );
				$order->set_billing_email( self::ANON_EMAIL );
				if ( (int) $order->save() > 0 ) {
					++$saved;
				} else {
					++$failed;
				}
			} catch ( \Throwable $e ) {
				++$failed;
			}
		}
		return [ $saved, $failed ];
	}
}
