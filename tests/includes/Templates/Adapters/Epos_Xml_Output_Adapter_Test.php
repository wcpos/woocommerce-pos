<?php
/**
 * Epson ePOS-Print XML adapter tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Adapters
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Adapters;

use WCPOS\WooCommercePOS\Services\Receipt_Output_Adapter_Factory;
use WCPOS\WooCommercePOS\Templates\Adapters\Epos_Xml_Output_Adapter;

/**
 * Epos_Xml_Output_Adapter_Test class.
 */
class Epos_Xml_Output_Adapter_Test extends \WP_UnitTestCase {
	/**
	 * It renders canonical receipt data as Epson ePOS-Print XML.
	 */
	public function test_transform_outputs_epos_print_xml(): void {
		$xml = ( new Epos_Xml_Output_Adapter() )->transform( $this->receipt_data() );

		$this->assertStringContainsString( '<epos-print', $xml );
		$this->assertStringNotContainsString( '<?xml', $xml );
		$this->assertStringContainsString( 'Acme Store', $xml );
		$this->assertStringContainsString( 'Widget', $xml );
		$this->assertStringContainsString( '<cut', $xml );
	}

	/**
	 * It escapes XML special characters.
	 */
	public function test_transform_escapes_xml_text(): void {
		$data                         = $this->receipt_data();
		$data['line_items'][0]['name'] = 'Fish & Chips <Large>';

		$xml = ( new Epos_Xml_Output_Adapter() )->transform( $data );

		$this->assertStringContainsString( 'Fish &amp; Chips &lt;Large&gt;', $xml );
		$this->assertStringNotContainsString( 'Fish & Chips <Large>', $xml );
	}

	/**
	 * It is available from the receipt output factory.
	 */
	public function test_factory_creates_epos_xml_adapter(): void {
		$this->assertInstanceOf(
			Epos_Xml_Output_Adapter::class,
			( new Receipt_Output_Adapter_Factory() )->create( 'epos-xml' )
		);
	}

	/**
	 * Receipt fixture.
	 *
	 * @return array
	 */
	private function receipt_data(): array {
		return array(
			'store'      => array(
				'name' => 'Acme Store',
			),
			'order'      => array(
				'number' => '1001',
			),
			'line_items' => array(
				array(
					'name'     => 'Widget',
					'quantity' => 2,
					'total'    => 12.5,
				),
			),
			'totals'     => array(
				'total_incl' => 12.5,
			),
		);
	}
}
