<?php
/**
 * Thermal renderer orchestrator tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer;

/**
 * Thermal_Renderer_Test class.
 */
class Thermal_Renderer_Test extends \WC_REST_Unit_Test_Case {

	/**
	 * Build a minimal thermal template array.
	 *
	 * @return array The template metadata/content.
	 */
	private function template(): array {
		return array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><align mode="center"><bold><text>Order #{{order.number}}</text></bold></align>{{#lines}}<row><col width="*">{{name}}</col><col width="10" align="right">{{qty}}</col></row>{{/lines}}<cut /></receipt>',
		);
	}

	/**
	 * It renders ESC/POS bytes containing the init sequence, order number, and item name.
	 */
	public function test_render_escpos_returns_initialized_bytes_with_order_data(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$items    = array_values( $order->get_items( 'line_item' ) );
		$name     = $items[0]->get_name();
		$number   = (string) $order->get_order_number();
		$renderer = new Thermal_Renderer();

		// Act.
		$output = $renderer->render( $this->template(), $order, 'escpos' );

		// Assert.
		$this->assertNotEmpty( $output );
		$this->assertEquals( "\x1b\x40", substr( $output, 0, 2 ) );
		$this->assertStringContainsString( $number, $output );
		$this->assertStringContainsString( $name, $output );
	}

	/**
	 * It renders valid ePOS-Print XML containing the order number and item name.
	 */
	public function test_render_epos_xml_returns_valid_xml_with_order_data(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$items    = array_values( $order->get_items( 'line_item' ) );
		$name     = $items[0]->get_name();
		$number   = (string) $order->get_order_number();
		$renderer = new Thermal_Renderer();

		// Act.
		$output = $renderer->render( $this->template(), $order, 'epos-xml' );

		// Assert.
		$this->assertStringContainsString( '<epos-print', $output );
		$this->assertStringContainsString( $number, $output );
		$this->assertStringContainsString( $name, $output );
		$this->assertNotFalse( simplexml_load_string( $output ) );
	}

	/**
	 * It expands the {{#lines}} loop once per line item.
	 */
	public function test_render_escpos_expands_loop_for_each_line_item(): void {
		// Arrange.
		$order   = OrderHelper::create_order();
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 9,
				'price'         => 9,
				'name'          => 'Second Loop Product',
			)
		);
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$names = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$names[] = $item->get_name();
		}
		$this->assertGreaterThanOrEqual( 2, \count( $names ) );

		$renderer = new Thermal_Renderer();

		// Act.
		$output = $renderer->render( $this->template(), $order, 'escpos' );

		// Assert.
		foreach ( $names as $name ) {
			$this->assertStringContainsString( $name, $output );
		}
	}

	/**
	 * It throws for an unknown wire format.
	 */
	public function test_render_throws_for_unknown_wire_format(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$renderer = new Thermal_Renderer();

		// Assert.
		$this->expectException( \InvalidArgumentException::class );

		// Act.
		$renderer->render( $this->template(), $order, 'pdf' );
	}
}
