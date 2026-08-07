<?php
/**
 * Query double for Collection Rules clause goldens.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

/**
 * Stands in for both query objects the clause bodies receive.
 *
 * The HPOS dialect only ever asks a query for `get_table_name()`, and the legacy dialect
 * only ever reads `query_vars['post_type']`. Providing both here is what makes the clause
 * goldens pure: no orders table, no WP_Query, no dispatch.
 */
class Collection_Rules_Query_Stub {
	/**
	 * WP_Query-shaped vars the legacy dialect inspects.
	 *
	 * @var array<string, mixed>
	 */
	public $query_vars;

	/**
	 * Build the stub.
	 *
	 * @param array<string, mixed> $query_vars WP_Query-shaped vars.
	 */
	public function __construct( array $query_vars = array( 'post_type' => 'shop_order' ) ) {
		$this->query_vars = $query_vars;
	}

	/**
	 * Resolve an HPOS table alias to a stable, assertable name.
	 *
	 * @param string $table Table alias, e.g. `orders`.
	 *
	 * @return string
	 */
	public function get_table_name( string $table ): string {
		$names = array(
			'orders'           => 'stub_wc_orders',
			'meta'             => 'stub_wc_orders_meta',
			'operational_data' => 'stub_wc_order_operational_data',
		);

		return $names[ $table ] ?? ( 'stub_wc_' . $table );
	}
}
