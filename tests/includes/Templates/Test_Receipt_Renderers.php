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
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order );
		$template     = array(
			'content' => '<h1>{{order.number}}</h1><p>{{order.currency}}</p>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $order->get_order_number(), $output );
		$this->assertStringContainsString( (string) $order->get_currency(), $output );
	}

	/**
	 * Test logicless renderer can replace semantic order placeholders.
	 */
	public function test_logicless_renderer_replaces_semantic_date_placeholders(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order );
		$template     = array(
			'content' => '<h1>{{order.number}}</h1><p>{{order.created.datetime_full}}</p>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $order->get_order_number(), $output );

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
			'order'     => array( 'currency' => 'USD' ),
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
			'order'  => array( 'currency' => 'USD' ),
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
			'order'   => array( 'currency' => 'USD' ),
			'totals' => array(
				'total_incl' => 19.99,
				'subtotal_incl'    => 19.99,
			),
		);

		// Templates use the `_display` companion for currency-formatted output.
		// The bare key ({{totals.total_incl}}) renders the raw numeric value.
		$template = array(
			'content' => '<span>{{totals.total_incl_display}}</span>|<span>{{totals.total_incl}}</span>',
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		// The _display variant renders the formatted currency string (e.g. "$19.99").
		$this->assertStringContainsString( '19.99', $output );
		$this->assertStringContainsString( '$', $output );
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
			'order'  => array( 'currency' => 'USD' ),
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
			'order' => array( 'currency' => 'USD' ),
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
	 * Test store address composes country/state via WC_Countries.
	 */
	public function test_receipt_data_builder_composes_country_aware_address(): void {
		$previous_default_country = get_option( 'woocommerce_default_country', '' );
		$country_code             = 'US';
		update_option( 'woocommerce_default_country', $country_code . ':AL' );

		// WC strips the country line when address country matches the base — force
		// it on so the test can verify country-name resolution end-to-end.
		add_filter( 'woocommerce_formatted_address_force_country_display', '__return_true' );

		try {
			$order   = OrderHelper::create_order();
			$builder = new Receipt_Data_Builder();
			$data    = $builder->build( $order );

			$address_lines = $data['store']['address_lines'];
			$last_line     = end( $address_lines );

			// WC_Countries::get_formatted_address ends US-format addresses with
			// the resolved country name; never the raw "US:AL" combined token.
			$expected_country = WC()->countries->countries[ $country_code ];
			$this->assertStringContainsStringIgnoringCase( (string) $expected_country, (string) $last_line );
			$this->assertStringNotContainsString( 'US:AL', implode( "\n", $address_lines ) );
		} finally {
			remove_filter( 'woocommerce_formatted_address_force_country_display', '__return_true' );
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

	/**
	 * Test detailed receipt renders opening hours notes without opening hours.
	 */
	public function test_detailed_receipt_renders_opening_hours_notes_without_opening_hours(): void {
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		$receipt_data['store']['opening_hours']       = null;
		$receipt_data['store']['opening_hours_notes'] = 'Closed on public holidays';

		$template_path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/detailed-receipt.html';
		$this->assertFileExists( $template_path );

		$template = array(
			'content' => file_get_contents( $template_path ),
		);

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( $template, $order, $receipt_data );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Closed on public holidays', $output );
		$this->assertStringNotContainsString( '{{store.opening_hours_notes}}', $output );
	}
}
