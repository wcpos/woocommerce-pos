<?php
/**
 * Tests for receipt output adapters.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Output_Adapter_Factory;
use WCPOS\WooCommercePOS\Templates\Adapters\Escpos_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Html_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Starprnt_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Zpl_Output_Adapter;
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

	/**
	 * Test ESC/POS adapter options for cut, drawer and QR handling.
	 */
	public function test_escpos_output_adapter_respects_context_options(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'fiscal' );
		$receipt_data['fiscal']['qr_payload'] = 'WCPOS-QR-CODE';
		$adapter      = new Escpos_Output_Adapter();

		$output = $adapter->transform(
			$receipt_data,
			array(
				'paper_width_chars' => 32,
				'cut'               => true,
				'partial_cut'       => true,
				'open_drawer'       => true,
				'print_qr'          => true,
				'codepage'          => 16,
			)
		);

		$this->assertStringContainsString( Escpos_Output_Adapter::CODEPAGE_PREFIX . chr( 16 ), $output );
		$this->assertStringContainsString( Escpos_Output_Adapter::DRAWER_KICK, $output );
		$this->assertStringContainsString( '[QR] WCPOS-QR-CODE', $output );
		$this->assertStringEndsWith( Escpos_Output_Adapter::CUT_PARTIAL, $output );
	}

	/**
	 * Test StarPRNT adapter output scaffold.
	 */
	public function test_starprnt_output_adapter_transforms_payload(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$adapter      = new Starprnt_Output_Adapter();

		$output = $adapter->transform( $receipt_data );
		$this->assertStringContainsString( '[STARPRNT] RECEIPT', $output );
		$this->assertStringContainsString( 'Order #' . $order->get_order_number(), $output );
		$this->assertStringContainsString( '[STARPRNT] CUT', $output );
	}

	/**
	 * Test ZPL adapter output scaffold.
	 */
	public function test_zpl_output_adapter_transforms_payload(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$adapter      = new Zpl_Output_Adapter();

		$output = $adapter->transform( $receipt_data );
		$this->assertStringStartsWith( '^XA', $output );
		$this->assertStringContainsString( 'Order #' . $order->get_order_number(), $output );
		$this->assertStringEndsWith( '^XZ', $output );
	}

	/**
	 * Test output adapter factory resolves supported output types.
	 */
	public function test_output_adapter_factory_resolves_adapters(): void {
		$factory = new Receipt_Output_Adapter_Factory();

		$this->assertInstanceOf( Html_Output_Adapter::class, $factory->create( 'html' ) );
		$this->assertInstanceOf( Escpos_Output_Adapter::class, $factory->create( 'escpos' ) );
		$this->assertInstanceOf( Starprnt_Output_Adapter::class, $factory->create( 'starprnt' ) );
		$this->assertInstanceOf( Zpl_Output_Adapter::class, $factory->create( 'zpl' ) );
	}

	/**
	 * Test output adapter factory rejects unsupported types.
	 */
	public function test_output_adapter_factory_rejects_unknown_adapter_type(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported receipt output type: tspl' );

		$factory = new Receipt_Output_Adapter_Factory();
		$factory->create( 'tspl' );
	}
}
