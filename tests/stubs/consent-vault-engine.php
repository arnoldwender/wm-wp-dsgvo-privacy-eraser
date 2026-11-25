<?php
/**
 * Stand-in for the WM Sovereign Consent Vault chain engine (append_anchor has this signature in the vault,
 * includes/Crypto/MerkleTreeEngine.php). Loaded late by the suite: before that, the class does not exist.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\Consent\Vault\Crypto;

final class MerkleTreeEngine {
	public function append_anchor( string $foreign_id, string $foreign_hash ): array {
		if ( ! empty( $GLOBALS['mock_vault_throw'] ) ) {
			throw new \TypeError( 'append_anchor(): Argument #2 must be of type string' );
		}
		$GLOBALS['mock_vault_anchors'][] = [ $foreign_id, $foreign_hash ];
		return [];
	}
}
