<?php
/**
 * Declared customer search carriers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_UnitTestCase;

/** Pins the human-readable customer search declaration for future client exposure. */
class Test_Collection_Rules_Customer_Search extends WP_UnitTestCase {
	/** Every searched field is represented in the carrier list. */
	public function test_customer_search_declares_all_carriers(): void {
		$this->assertSame(
			array(
				'user_email',
				'user_login',
				'display_name',
				'first_name',
				'last_name',
				'billing_first_name',
				'billing_last_name',
				'billing_email',
				'billing_company',
				'billing_phone',
				'shipping_first_name',
				'shipping_last_name',
				'shipping_company',
				'shipping_phone',
				'tax_ids',
			),
			Collection_Rules::rules( 'customers' )['search']['carriers']
		);
	}
}
