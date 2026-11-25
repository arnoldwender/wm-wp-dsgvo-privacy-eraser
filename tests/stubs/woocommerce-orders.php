<?php
/**
 * Minimal WooCommerce order API for the standalone suite: wc_get_orders() with `billing_email` and order
 * objects with the billing setters and save(). WooCommerce 11.0.1 supports `billing_email` in both order
 * stores (includes/class-wc-order-query.php, src/Internal/DataStores/Orders/OrdersTableQuery.php).
 * Loaded late by the suite: before that, wc_get_orders() does not exist, as on a site without WooCommerce.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

final class Mock_WC_Order {
	public string $billing_first_name = '';
	public string $billing_last_name  = '';
	public string $billing_address_1  = '';
	public string $billing_phone      = '';
	public string $billing_email      = '';
	public int $saves                 = 0;

	public function __construct( public int $id, string $email, public int $save_result = 1 ) {
		$this->billing_first_name = 'Erika';
		$this->billing_last_name  = 'Musterfrau';
		$this->billing_address_1  = 'Musterweg 1';
		$this->billing_phone      = '0345 000000';
		$this->billing_email      = $email;
	}

	public function set_billing_first_name( string $v ): void {
		$this->billing_first_name = $v;
	}
	public function set_billing_last_name( string $v ): void {
		$this->billing_last_name = $v;
	}
	public function set_billing_address_1( string $v ): void {
		$this->billing_address_1 = $v;
	}
	public function set_billing_phone( string $v ): void {
		$this->billing_phone = $v;
	}
	public function set_billing_email( string $v ): void {
		$this->billing_email = $v;
	}
	public function save(): int {
		++$this->saves;
		return $this->save_result > 0 ? $this->id : 0;
	}
}

function wc_get_orders( array $args ): array {
	$GLOBALS['mock_wc_queries'][] = $args;
	return array_values( array_filter( $GLOBALS['mock_wc_orders'] ?? [], static fn( Mock_WC_Order $o ): bool => $o->billing_email === ( $args['billing_email'] ?? null ) ) );
}
