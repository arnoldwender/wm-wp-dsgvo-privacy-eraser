<?php
/**
 * The confirmation step of an erasure request, shared by the confirmation page.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Request;

defined( 'ABSPATH' ) || exit;

use WMCyber\DSGVO\Eraser\Eraser\TransversalDataEraser;

/**
 * Resolve the token, take the request, recover the address, erase, close the request.
 *
 * States: `invalid` (unknown, expired, used, or taken by a concurrent confirmation), `undecryptable`
 * (the stored address does not open), `incomplete` (an area failed or the record was not written; the
 * request is marked `failed`), `done`.
 */
final class ErasureConfirmation {

	/**
	 * @return array{state: string, http: int, result?: array}
	 */
	public static function run( string $uuid, string $token, ?TransversalDataEraser $eraser = null ): array {
		$row = ErasureRequestManager::resolve( $uuid, $token );
		if ( null === $row ) {
			return array( 'state' => 'invalid', 'http' => 403 );
		}

		// Only the caller whose conditional UPDATE changed the row goes on (see claim()).
		if ( ! ErasureRequestManager::claim( (string) $row->request_uuid ) ) {
			return array( 'state' => 'invalid', 'http' => 403 );
		}

		$address = ErasureRequestManager::address_of( $row );
		if ( '' === $address ) {
			// The envelope did not open: wrong salt, or a row written by a runtime that had sodium when a
			// later one does not. Erasing nothing is correct; claiming success would not be.
			ErasureRequestManager::mark( (string) $row->request_uuid, 'failed' );
			return array( 'state' => 'undecryptable', 'http' => 500 );
		}

		try {
			$result = ( $eraser ?? new TransversalDataEraser() )->execute_erasure( $address );
		} catch ( \Throwable $e ) {
			// A fatal half-way used to leave the request in `processing` with its address.
			ErasureRequestManager::mark( (string) $row->request_uuid, 'failed' );
			return array(
				'state'  => 'incomplete',
				'http'   => 500,
				'result' => array( 'success' => false, 'error' => 'The erasure stopped with an error.' ),
			);
		}

		$done = ! empty( $result['success'] );
		ErasureRequestManager::mark( (string) $row->request_uuid, $done ? 'processed' : 'failed' );

		return array(
			'state'  => $done ? 'done' : 'incomplete',
			'http'   => $done ? 200 : 500,
			'result' => $result,
		);
	}

	/** Whether opening the link should show the confirmation button. Changes nothing except marking an expired request. */
	public static function pending( string $uuid, string $token ): bool {
		return null !== ErasureRequestManager::resolve( $uuid, $token );
	}
}
