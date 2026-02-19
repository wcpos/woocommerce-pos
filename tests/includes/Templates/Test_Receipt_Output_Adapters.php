<?php
/**
 * Tests for receipt output adapters.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Templates\Adapters\Escpos_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Html_Output_Adapter;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Output_Adapters class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Output_Adapters extends WC_REST_Unit_Test_Case {
	/**
	 * Test HTML adapter output.
	 */
	public function test_html_output_adapter_transforms_payload(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$adapter      = new Html_Output_Adapter();

		$output = $adapter->transform( $receipt_data );

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'wcpos-receipt', $output );
		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
	}

	/**
	 * Test ESC/POS adapter output.
	 */
	public function test_escpos_output_adapter_transforms_payload(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$adapter      = new Escpos_Output_Adapter();

		$output = $adapter->transform( $receipt_data );

		$this->assertIsString( $output );
		$this->assertStringStartsWith( Escpos_Output_Adapter::ESC_INIT, $output );
		$this->assertStringContainsString( 'Order #' . $order->get_order_number(), $output );
		$this->assertStringEndsWith( Escpos_Output_Adapter::CUT_FULL, $output );
	}
}
