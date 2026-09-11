<?php
/** HPOS provenance health tests. @package WCPOS\WooCommercePOS\Tests\API */
namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Tests\Services\Provenance_Health_Tests;

class Test_HPOS_Provenance_Health extends WCPOS_REST_HPOS_Unit_Test_Case {
	use HPOSToggleTrait;
	use Provenance_Health_Tests;

	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}
}
