<?php
/**
 * HPOS coverage for Tax_Id_Detector.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Run the Tax_Id_Detector tests with orders stored in the HPOS tables and
 * compatibility sync off, the WooCommerce default for new stores.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Tax_Id_Detector_HPOS extends Test_Tax_Id_Detector {
	use HPOSToggleTrait;

	/**
	 * Enable HPOS without syncing to the posts tables, before creating orders.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->disable_cot_sync();
	}

	/**
	 * Restore posts storage.
	 */
	public function tearDown(): void {
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/**
	 * Guard for the inherited inference test: in this class, order meta is only
	 * in the HPOS tables, so get_post_meta() cannot see it.
	 */
	public function test_hpos_without_sync_order_meta_is_not_in_postmeta(): void {
		// Arrange.
		$order = wc_create_order();
		$order->update_meta_data( '_billing_eu_vat_number', 'DE123456789' );
		$order->save();

		// Act.
		$post_meta = get_post_meta( $order->get_id(), '_billing_eu_vat_number', true );

		// Assert.
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assertSame( '', $post_meta );
	}
}
