<?php
/**
 * Template_Pdf_Service tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Template_Pdf_Service;
use WCPOS\WooCommercePOS\Templates\Thermal\Html_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer;

/**
 * Template_Pdf_Service_Test class.
 */
class Template_Pdf_Service_Test extends \WC_REST_Unit_Test_Case {

	/**
	 * It renders a thermal template for an order into PDF bytes.
	 */
	public function test_render_thermal_template_returns_pdf_bytes(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>',
		);
		$service  = new Template_Pdf_Service();

		// Act.
		$pdf = $service->render( $template, $order );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertGreaterThan( 1000, \strlen( $pdf ) );
	}

	/**
	 * It renders a thermal template containing a barcode without throwing.
	 */
	public function test_render_thermal_template_with_barcode_returns_pdf_bytes(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order {{order.number}}</text><barcode type="code128">12345</barcode></receipt>',
		);
		$service  = new Template_Pdf_Service();

		// Act.
		$pdf = $service->render( $template, $order );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertGreaterThan( 1000, \strlen( $pdf ) );
	}

	/**
	 * It fits a short thermal receipt PDF page to the rendered content height.
	 */
	public function test_render_thermal_fits_page_to_content(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>',
		);
		$service  = new Template_Pdf_Service();

		// Act.
		$pdf = $service->render( $template, $order );
		$box = $this->get_media_box( $pdf );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertEquals( 1, $this->get_pdf_page_count( $pdf ) );
		$this->assertEquals( 226.77, $box['width'], '', 0.01 );
		$this->assertLessThan( 1000.0, $box['height'] );
	}

	/**
	 * It keeps a long thermal receipt on one fitted continuous-roll PDF page.
	 */
	public function test_render_thermal_long_receipt_stays_single_page(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48">' . str_repeat( '<text>Line item {{order.number}}</text>', 80 ) . '<barcode type="code128">12345</barcode></receipt>',
		);
		$service  = new Template_Pdf_Service();

		// Act.
		$pdf = $service->render( $template, $order );
		$box = $this->get_media_box( $pdf );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertEquals( 1, $this->get_pdf_page_count( $pdf ) );
		$this->assertEquals( 226.77, $box['width'], '', 0.01 );
		$this->assertGreaterThan( 1000.0, $box['height'] );
	}

	/**
	 * It renders a logicless template into PDF bytes.
	 */
	public function test_render_logicless_template_returns_pdf_bytes(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'logicless',
			'content' => '<div>Order {{order.number}}</div>',
		);
		$service  = new Template_Pdf_Service();

		// Act.
		$pdf = $service->render( $template, $order );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertGreaterThan( 1000, \strlen( $pdf ) );
	}

	/**
	 * It renders a legacy-php template into PDF bytes.
	 */
	public function test_render_legacy_php_template_returns_pdf_bytes(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'legacy-php',
			'content' => '<div>Order receipt</div>',
		);
		$service  = new Template_Pdf_Service();

		// Act.
		$pdf = $service->render( $template, $order );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertGreaterThan( 1000, \strlen( $pdf ) );
	}

	/**
	 * It flows order data into the intermediate thermal HTML.
	 *
	 * The order number is reliably present in the HTML layer; in the compressed
	 * PDF byte stream it cannot be asserted directly.
	 */
	public function test_render_thermal_template_flows_order_number_into_html(): void {
		// Arrange.
		$order    = OrderHelper::create_order();
		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>',
		);

		// Act.
		$html = ( new Html_Thermal_Emitter() )->emit(
			( new Thermal_Renderer() )->build_ast( $template, $order )
		);

		// Assert.
		$this->assertStringContainsString( (string) $order->get_order_number(), $html );
	}

	/**
	 * Read the first page MediaBox dimensions from PDF bytes.
	 *
	 * @param string $pdf PDF bytes.
	 *
	 * @return array{width: float, height: float}
	 */
	private function get_media_box( string $pdf ): array {
		$this->assertMatchesRegularExpression(
			'/\\/MediaBox\\s*\\[\\s*([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s*\\]/',
			$pdf,
			'PDF should contain a MediaBox.'
		);
		preg_match(
			'/\\/MediaBox\\s*\\[\\s*([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s*\\]/',
			$pdf,
			$matches
		);

		return array(
			'width'  => (float) $matches[3] - (float) $matches[1],
			'height' => (float) $matches[4] - (float) $matches[2],
		);
	}

	/**
	 * Count PDF pages by counting page objects.
	 *
	 * @param string $pdf PDF bytes.
	 *
	 * @return int
	 */
	private function get_pdf_page_count( string $pdf ): int {
		preg_match_all( '/\\/Type\\s*\\/Page\\b/', $pdf, $matches );

		return \count( $matches[0] );
	}
}
