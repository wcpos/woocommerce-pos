<?php
/** CPT provenance health tests. @package WCPOS\WooCommercePOS\Tests\Services */
namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

class Test_Provenance_Health extends WCPOS_REST_Unit_Test_Case {
	use Provenance_Health_Tests;

	public function setUp(): void {
		parent::setUp();
		$this->assertFalse( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
