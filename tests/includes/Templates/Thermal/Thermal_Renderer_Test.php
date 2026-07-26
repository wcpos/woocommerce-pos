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
	 * It strips XML-1.0-illegal control characters from order data before parsing.
	 *
	 * A form-feed (0x0C) in the customer note would otherwise make DOMDocument::loadXML
	 * fail and the parser throw. The renderer must strip such characters and still
	 * produce a non-empty, parseable payload.
	 */
	public function test_render_strips_xml_illegal_control_characters_from_data(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_customer_note( "Line1\x0CLine2" );
		$order->save();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>{{order.customer_note}}</text><cut /></receipt>',
		);
		$renderer = new Thermal_Renderer();

		// Act.
		$escpos = $renderer->render( $template, $order, 'escpos' );

		// Assert.
		$this->assertNotEmpty( $escpos );
		$this->assertStringContainsString( 'Line1', $escpos );
		$this->assertStringContainsString( 'Line2', $escpos );
		$this->assertStringNotContainsString( "\x0C", $escpos );

		// Act (epos-xml) — must produce XML that simplexml can parse.
		$xml = $renderer->render( $template, $order, 'epos-xml' );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $xml ) );
	}

	/**
	 * Order text carrying printer command sequences cannot operate the printer.
	 *
	 * A customer note is attacker-controllable on a public checkout. If its
	 * ESC bytes survived into the job, they would be executed as commands —
	 * cutting paper or kicking the cash drawer mid-receipt. The control-byte
	 * strip in render() is what prevents that, so assert on the emitted bytes
	 * for both wire formats rather than on the strip itself.
	 */
	public function test_render_does_not_execute_printer_commands_from_order_text(): void {
		// Arrange: ESC/POS drawer kick (ESC p) and full cut (GS V), plus the
		// StarPRNT cut (ESC d) and drawer pulse (ESC BEL).
		$order = OrderHelper::create_order();
		$order->set_customer_note( "A\x1b\x70\x00\x37\x79B\x1d\x56\x41C\x1b\x64\x02D\x1b\x07E" );
		$order->save();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>{{order.customer_note}}</text></receipt>',
		);
		$renderer = new Thermal_Renderer();

		// Act.
		$escpos   = $renderer->render( $template, $order, 'escpos' );
		$starprnt = $renderer->render( $template, $order, 'starprnt' );

		// Assert: no command sequence from the note reaches either job. The
		// escape byte is stripped, so what remains of each sequence is inert
		// printable text (e.g. ESC p 0 55 121 prints as "p7y") — ugly on the
		// receipt, but it cannot operate the printer, and the surrounding
		// characters still render.
		foreach ( array( $escpos, $starprnt ) as $bytes ) {
			$this->assertStringNotContainsString( "\x1b\x70", $bytes );
			$this->assertStringNotContainsString( "\x1d\x56\x41", $bytes );
			$this->assertStringNotContainsString( "\x1b\x64\x02", $bytes );
			$this->assertStringNotContainsString( "\x1b\x07", $bytes );
			$this->assertMatchesRegularExpression( '/A.*B.*C.*D.*E/', $bytes );
		}
	}

	/**
	 * It builds an AST whose root is a receipt node with children.
	 */
	public function test_build_ast_returns_receipt_root_with_children(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order #{{order.number}}</text><cut /></receipt>',
		);
		$renderer = new Thermal_Renderer();

		// Act.
		$ast = $renderer->build_ast( $template, $order );

		// Assert.
		$this->assertEquals( 'receipt', $ast['type'] );
		$this->assertNotEmpty( $ast['children'] );
	}

	/**
	 * It renders StarPRNT bytes with order data for the starprnt wire format.
	 */
	public function test_render_starprnt_returns_bytes_with_order_data(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$renderer = new Thermal_Renderer();

		// Act.
		$output = $renderer->render( $this->template(), $order, 'starprnt' );

		// Assert.
		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
		$this->assertStringNotContainsString( "\x1b\x40", $output ); // Never ESC/POS init.
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
