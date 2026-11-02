<?php
/**
 * Tests for gallery templates with and without an order number.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Mustache\Engine as Mustache_Engine;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Money_Formatter;
use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;
use WC_REST_Unit_Test_Case;

/**
 * Test_Gallery_Templates_Without_Order_Number class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Gallery_Templates_Without_Order_Number extends WC_REST_Unit_Test_Case {
	/**
	 * Gallery templates that display the order number.
	 *
	 * @var string[]
	 */
	private const TEMPLATES = array(
		'standard-receipt.html',
		'standard-receipt-rtl.html',
		'minimal-receipt.html',
		'narrow-receipt.html',
		'detailed-receipt.html',
		'gift-receipt.html',
		'invoice.html',
		'quote.html',
		'packing-slip.html',
		'thermal-kitchen-ticket.xml',
		'thermal-simple-58mm.xml',
		'thermal-simple-80mm.xml',
		'thermal-simple-80mm-rtl.xml',
		'thermal-detailed-58mm.xml',
		'thermal-detailed-80mm.xml',
	);

	/**
	 * Test missing order numbers leave no bare hash or empty barcode.
	 */
	public function test_gallery_templates_without_order_number_omit_number_markup(): void {
		// Arrange.
		$order                          = OrderHelper::create_order();
		$receipt_data                   = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$receipt_data['order']['number'] = '';
		$order_label_templates          = array(
			'narrow-receipt.html',
			'minimal-receipt.html',
			'gift-receipt.html',
			'thermal-simple-58mm.xml',
			'thermal-simple-80mm.xml',
			'thermal-simple-80mm-rtl.xml',
			'thermal-detailed-58mm.xml',
			'thermal-detailed-80mm.xml',
		);

		foreach ( self::TEMPLATES as $filename ) {
			// Act.
			$output = $this->render_gallery_template( $filename, $order, $receipt_data );

			// Assert.
			$this->assertDoesNotMatchRegularExpression( '/#(\s|<|&middot;|$)/', $output, $filename . ' must not render a bare hash.' );
			$this->assertDoesNotMatchRegularExpression( '/<barcode[^>]*>\s*<\/barcode>/', $output, $filename . ' must not render an empty barcode.' );
			if ( in_array( $filename, $order_label_templates, true ) ) {
				$this->assertDoesNotMatchRegularExpression(
					'/>\s*:?\s*' . preg_quote( esc_html( $receipt_data['i18n']['order'] ), '/' ) . '\s*:?\s*</u',
					$output,
					$filename . ' must not render an order label without a number.'
				);
			}
		}
	}

	/**
	 * Test saved orders retain their number in every gallery template.
	 */
	public function test_gallery_templates_with_order_number_render_number(): void {
		// Arrange.
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$this->assertNotEmpty( $receipt_data['order']['number'] );

		foreach ( self::TEMPLATES as $filename ) {
			// Act.
			$output = $this->render_gallery_template( $filename, $order, $receipt_data );

			// Assert.
			$prefix = 'thermal-kitchen-ticket.xml' === $filename ? '' : '#';
			$this->assertStringContainsString( $prefix . $order->get_order_number(), $output, $filename . ' must render the order number.' );
		}
	}

	/**
	 * Render HTML through the logicless renderer and XML through raw Mustache.
	 *
	 * @param string    $filename     Gallery template filename.
	 * @param \WC_Order $order        Order object.
	 * @param array     $receipt_data Canonical receipt payload.
	 *
	 * @return string
	 */
	private function render_gallery_template( string $filename, $order, array $receipt_data ): string {
		$path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/' . $filename;
		$this->assertFileExists( $path );

		if ( 'xml' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			// Keep empty barcodes visible: kses and thermal emitters would discard them.
			$data      = Receipt_Money_Formatter::format( $receipt_data, $receipt_data['order']['currency'] ?? 'USD' );
			$data['t'] = true;
			$flags     = ENT_QUOTES | ENT_SUBSTITUTE;
			$mustache  = new Mustache_Engine(
				array(
					'entity_flags' => $flags,
					'escape'       => function ( $value ) use ( $flags ) {
						return \is_array( $value ) ? '' : htmlspecialchars( (string) $value, $flags, 'UTF-8' );
					},
				)
			);

			return $mustache->render( file_get_contents( $path ), $data );
		}

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( array( 'content' => file_get_contents( $path ) ), $order, $receipt_data );

		return (string) ob_get_clean();
	}
}
