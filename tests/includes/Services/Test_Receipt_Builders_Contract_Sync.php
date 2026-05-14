<?php
/**
 * Tests for receipt builder contract parity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Builders_Contract_Sync class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Builders_Contract_Sync extends WC_REST_Unit_Test_Case {
	/**
	 * Test live and preview builders emit the same contract key sets.
	 */
	public function test_builders_emit_matching_contract_keys(): void {
		$order   = OrderHelper::create_order();
		$live    = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$preview = ( new Preview_Receipt_Builder() )->build();

		$this->assertSame( array_keys( $live ), array_keys( $preview ) );
		$this->assertSame( array_keys( $live['tax'] ), array_keys( $preview['tax'] ) );
		$this->assertSame( array_keys( $live['presentation_hints'] ), array_keys( $preview['presentation_hints'] ) );
		$this->assertArrayNotHasKey( 'display_tax', $live['presentation_hints'] );
		$this->assertArrayNotHasKey( 'display_tax', $preview['presentation_hints'] );
		$this->assertArrayNotHasKey( 'order_barcode_type', $live['presentation_hints'] );
		$this->assertArrayNotHasKey( 'order_barcode_type', $preview['presentation_hints'] );
	}

	/**
	 * Test redundant branchable tax booleans stay consistent with enums.
	 */
	public function test_tax_booleans_match_tax_enums(): void {
		$order   = OrderHelper::create_order();
		$payload = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$tax     = $payload['tax'];

		$this->assertSame( 'incl' === $tax['display'], $tax['display_incl'] );
		$this->assertSame( 'excl' === $tax['display'], $tax['display_excl'] );
		$this->assertSame( 1, count( array_filter( array( $tax['breakdown_hidden'], $tax['breakdown_single'], $tax['breakdown_itemized'] ) ) ) );
		$this->assertSame( 'hidden' === $tax['breakdown'], $tax['breakdown_hidden'] );
		$this->assertSame( 'single' === $tax['breakdown'], $tax['breakdown_single'] );
		$this->assertSame( 'itemized' === $tax['breakdown'], $tax['breakdown_itemized'] );
	}
}
