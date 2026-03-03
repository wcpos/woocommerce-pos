<?php
/**
 * Tests for receipt renderer dispatch.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Renderer_Factory;
use WCPOS\WooCommercePOS\Templates\Renderers\Legacy_Php_Renderer;
use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Renderers class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Renderers extends WC_REST_Unit_Test_Case {
	/**
	 * Test factory selects logicless renderer.
	 */
	public function test_factory_selects_logicless_renderer(): void {
		$factory  = new Receipt_Renderer_Factory();
		$renderer = $factory->create( 'logicless' );

		$this->assertInstanceOf( Logicless_Renderer::class, $renderer );
	}

	/**
	 * Test factory falls back to legacy renderer.
	 */
	public function test_factory_defaults_to_legacy_renderer(): void {
		$factory  = new Receipt_Renderer_Factory();
		$renderer = $factory->create( 'unknown-engine' );

		$this->assertInstanceOf( Legacy_Php_Renderer::class, $renderer );
	}

	/**
	 * Test logicless renderer can replace simple placeholders.
	 */
	public function test_logicless_renderer_replaces_placeholders(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$template     = array(
			'content' => '<h1>{{meta.order_number}}</h1><p>{{meta.mode}}</p>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
		$this->assertStringContainsString( 'live', $output );
	}
}
