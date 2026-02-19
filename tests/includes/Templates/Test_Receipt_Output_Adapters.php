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
use WCPOS\WooCommercePOS\Templates\Adapters\Cpcl_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Escpos_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Html_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Starprnt_Output_Adapter;
use WCPOS\WooCommercePOS\Templates\Adapters\Tspl_Output_Adapter;
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
	 * Get deterministic fixture payload for adapter tests.
	 *
	 * @return array
	 */
	private function get_fixture_payload(): array {
		return array(
			'meta'   => array(
				'order_number'   => '1001',
				'currency'       => 'USD',
				'created_at_gmt' => '2026-02-19 10:00:00',
			),
			'store'  => array(
				'name' => 'WCPOS Fixture Store',
			),
			'lines'  => array(
				array(
					'name'            => 'Coffee Beans',
					'qty'             => 2,
					'line_total_incl' => 19.98,
				),
			),
			'totals' => array(
				'grand_total_incl' => 19.98,
			),
			'fiscal' => array(
				'qr_payload' => 'FISCAL-QR-1001',
			),
		);
	}

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
		$receipt_data = $this->get_fixture_payload();
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
		$this->assertStringContainsString( '[QR] FISCAL-QR-1001', $output );
		$this->assertStringEndsWith( Escpos_Output_Adapter::CUT_PARTIAL, $output );
	}

	/**
	 * Test StarPRNT adapter output scaffold.
	 */
	public function test_starprnt_output_adapter_transforms_payload(): void {
		$receipt_data = $this->get_fixture_payload();
		$adapter      = new Starprnt_Output_Adapter();

		$output = $adapter->transform(
			$receipt_data,
			array(
				'print_qr'    => true,
				'open_drawer' => true,
				'partial_cut' => true,
			)
		);
		$this->assertStringContainsString( Starprnt_Output_Adapter::CMD_INIT, $output );
		$this->assertStringContainsString( Starprnt_Output_Adapter::CMD_ALIGN_CENTER, $output );
		$this->assertStringContainsString( 'Order #1001', $output );
		$this->assertStringContainsString( '[STARPRNT:QR] FISCAL-QR-1001', $output );
		$this->assertStringContainsString( Starprnt_Output_Adapter::CMD_DRAWER, $output );
		$this->assertStringContainsString( Starprnt_Output_Adapter::CMD_CUT_PARTIAL, $output );
	}

	/**
	 * Test ZPL adapter output scaffold.
	 */
	public function test_zpl_output_adapter_transforms_payload(): void {
		$receipt_data = $this->get_fixture_payload();
		$adapter      = new Zpl_Output_Adapter();

		$output = $adapter->transform(
			$receipt_data,
			array(
				'label_width'   => 640,
				'label_length'  => 900,
				'print_qr'      => true,
				'print_barcode' => true,
			)
		);
		$this->assertStringStartsWith( '^XA', $output );
		$this->assertStringContainsString( '^PW640', $output );
		$this->assertStringContainsString( '^LL900', $output );
		$this->assertStringContainsString( '^BCN,60,Y,N,N', $output );
		$this->assertStringContainsString( '^BQN,2,6', $output );
		$this->assertStringContainsString( 'Order #1001', $output );
		$this->assertStringEndsWith( '^XZ', $output );
	}

	/**
	 * Test CPCL adapter output scaffold.
	 */
	public function test_cpcl_output_adapter_transforms_payload(): void {
		$receipt_data = $this->get_fixture_payload();
		$adapter      = new Cpcl_Output_Adapter();

		$output = $adapter->transform(
			$receipt_data,
			array(
				'label_width'   => 640,
				'label_height'  => 720,
				'print_qr'      => true,
				'print_barcode' => true,
			)
		);
		$this->assertStringStartsWith( '! 0 200 200 720 1', $output );
		$this->assertStringContainsString( 'PW 640', $output );
		$this->assertStringContainsString( 'BARCODE 128', $output );
		$this->assertStringContainsString( 'B QR', $output );
		$this->assertStringContainsString( 'Order #1001', $output );
		$this->assertStringEndsWith( "PRINT\n", $output );
	}

	/**
	 * Test TSPL adapter output scaffold.
	 */
	public function test_tspl_output_adapter_transforms_payload(): void {
		$receipt_data = $this->get_fixture_payload();
		$adapter      = new Tspl_Output_Adapter();

		$output = $adapter->transform(
			$receipt_data,
			array(
				'label_width_mm'  => 60,
				'label_height_mm' => 100,
				'print_qr'        => true,
				'print_barcode'   => true,
			)
		);
		$this->assertStringStartsWith( 'SIZE 60 mm,100 mm', $output );
		$this->assertStringContainsString( 'BARCODE 30,180,"128"', $output );
		$this->assertStringContainsString( 'QRCODE 30,290', $output );
		$this->assertStringContainsString( 'Order #1001', $output );
		$this->assertStringEndsWith( "PRINT 1,1\n", $output );
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
		$this->assertInstanceOf( Cpcl_Output_Adapter::class, $factory->create( 'cpcl' ) );
		$this->assertInstanceOf( Tspl_Output_Adapter::class, $factory->create( 'tspl' ) );
	}

	/**
	 * Test output adapter factory rejects unsupported types.
	 */
	public function test_output_adapter_factory_rejects_unknown_adapter_type(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported receipt output type: unknown-printer' );

		$factory = new Receipt_Output_Adapter_Factory();
		$factory->create( 'unknown-printer' );
	}
}
