<?php
/**
 * Declared order search carriers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_UnitTestCase;

/** Pins the human-readable order search declaration for future client exposure. */
class Test_Collection_Rules_Order_Search extends WP_UnitTestCase {
	/** Every searched field is represented in the carrier list. */
	public function test_order_search_declares_all_carriers(): void {
		$this->assertSame(
			array(
				'id',
				'billing_email',
				'first_name',
				'last_name',
				'company',
				'email',
				'phone',
				'shipping_first_name',
				'shipping_last_name',
				'shipping_company',
				'shipping_phone',
				'line_item_name',
				'line_item_sku',
				'number_meta',
			),
			Collection_Rules::rules( 'orders' )['search']['carriers']
		);
	}
}
