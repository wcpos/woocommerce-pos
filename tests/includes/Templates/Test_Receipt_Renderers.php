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
			'content' => '<h1>{{meta.order_number}}</h1><p>{{receipt.mode}}</p>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
		$this->assertStringContainsString( 'live', $output );
	}

	/**
	 * Test logicless renderer can replace semantic order and receipt placeholders.
	 */
	public function test_logicless_renderer_replaces_semantic_date_placeholders(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$template     = array(
			'content' => '<h1>{{order.number}}</h1><p>{{order.created.datetime_full}}</p><p>{{receipt.mode}}</p>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
		$this->assertStringContainsString( 'live', $output );

		$datetime_full = $receipt_data['order']['created']['datetime_full'];
		$this->assertNotSame( '', $datetime_full );
		$this->assertStringContainsString( (string) $order->get_date_created()->format( 'Y' ), $datetime_full );
		$this->assertStringContainsString( $datetime_full, $output );
		$this->assertStringNotContainsString( '{{order.created.datetime_full}}', $output );
	}

	/**
	 * Test logicless renderer does not crash when template references an array value.
	 */
	public function test_logicless_renderer_handles_array_value_without_error(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = array(
			'meta'     => array(
				'currency' => 'USD',
			),
			'customer' => array(
				'name'            => 'John Doe',
				'billing_address' => array(
					'city'  => 'New York',
					'state' => 'NY',
				),
			),
		);

		$template = array(
			'content' => '<p>{{customer.name}}</p><p>{{customer.billing_address}}</p>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'John Doe', $output );
		$this->assertStringNotContainsString( 'Array', $output );
	}

	/**
	 * Test logicless renderer iterates array sections correctly.
	 */
	public function test_logicless_renderer_iterates_array_sections(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = array(
			'meta'  => array(
				'currency' => 'USD',
			),
			'lines' => array(
				array(
					'name' => 'Widget',
					'qty'  => 2,
				),
				array(
					'name' => 'Gadget',
					'qty'  => 1,
				),
			),
		);

		$template = array(
			'content' => '{{#lines}}<div>{{name}} x{{qty}}</div>{{/lines}}',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Widget x2', $output );
		$this->assertStringContainsString( 'Gadget x1', $output );
	}

	/**
	 * Test logicless renderer formats money fields as currency without raw HTML entities.
	 */
	public function test_logicless_renderer_formats_money_fields(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = array(
			'meta'   => array(
				'currency' => 'USD',
			),
			'totals' => array(
				'grand_total_incl' => 19.99,
				'subtotal_incl'    => 19.99,
			),
		);

		$template = array(
			'content' => '<span>{{totals.grand_total_incl}}</span>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( '19.99', $output );
		$this->assertStringNotContainsString( '{{', $output );
		// Entities from wc_price() should be decoded, not double-escaped.
		$this->assertStringNotContainsString( '&amp;', $output );
		$this->assertStringNotContainsString( '&#36;', $output );
	}

	/**
	 * Test logicless renderer renders empty comment for empty template.
	 */
	public function test_logicless_renderer_handles_empty_template(): void {
		$order    = OrderHelper::create_order();
		$template = array( 'content' => '' );

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<!-- Empty logicless receipt template -->', $output );
	}

	/**
	 * Test logicless renderer handles nested arrays in line items without error.
	 */
	public function test_logicless_renderer_handles_nested_arrays_in_lines(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = array(
			'meta'  => array(
				'currency' => 'USD',
			),
			'lines' => array(
				array(
					'name'            => 'Widget',
					'qty'             => 2,
					'line_total_incl' => 10.00,
					'taxes'           => array(
						array(
							'code'   => '1',
							'label'  => 'Tax',
							'amount' => 1.00,
						),
					),
				),
			),
		);

		$template = array(
			'content' => '{{#lines}}<div>{{name}} {{line_total_incl}}</div>{{/lines}}',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Widget', $output );
		$this->assertStringNotContainsString( 'Array', $output );
	}

	/**
	 * Test logicless renderer strips HTML comments from template.
	 */
	public function test_logicless_renderer_strips_html_comments(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = array(
			'meta' => array(
				'currency' => 'USD',
			),
		);

		$template = array(
			'content' => "<!-- This is a comment\nspanning multiple lines --><p>Hello</p>",
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Hello', $output );
		$this->assertStringNotContainsString( 'This is a comment', $output );
	}

	/**
	 * Test store address formats country/state display names.
	 */
	public function test_receipt_data_builder_formats_country_state(): void {
		$previous_default_country = get_option( 'woocommerce_default_country', '' );
		update_option( 'woocommerce_default_country', 'US:AL' );

		try {
			$order   = OrderHelper::create_order();
			$builder = new Receipt_Data_Builder();
			$data    = $builder->build( $order, 'live' );

			$address_lines = $data['store']['address_lines'];
			$last_line     = end( $address_lines );

			$this->assertStringContainsString( 'Alabama', $last_line );
			$this->assertStringNotContainsString( 'US:AL', $last_line );
		} finally {
			update_option( 'woocommerce_default_country', $previous_default_country );
		}
	}

	/**
	 * Test receipt data builder sets Guest name for anonymous orders.
	 */
	public function test_receipt_data_builder_guest_customer_name(): void {
		$order = OrderHelper::create_order();
		$order->set_customer_id( 0 );
		$order->set_billing_first_name( '' );
		$order->set_billing_last_name( '' );
		$order->save();

		$builder = new Receipt_Data_Builder();
		$data    = $builder->build( $order, 'live' );

		$this->assertSame( __( 'Guest', 'woocommerce-pos' ), $data['customer']['name'] );
		$this->assertNull( $data['customer']['id'] );
	}

	/**
	 * Test logicless renderer with the full simple receipt template and real order data.
	 */
	public function test_logicless_renderer_full_receipt_no_error(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		$template_path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/simple-receipt.html';
		$this->assertFileExists( $template_path );

		$template = array(
			'content' => file_get_contents( $template_path ),
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
		$this->assertStringNotContainsString( '{{', $output );
	}
}
