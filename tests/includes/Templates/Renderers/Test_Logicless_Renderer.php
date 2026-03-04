<?php
/**
 * Tests for Logicless_Renderer section support.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Renderers
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Renderers;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;
use WC_REST_Unit_Test_Case;

/**
 * Test_Logicless_Renderer class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Logicless_Renderer extends WC_REST_Unit_Test_Case {

	/**
	 * Renderer instance.
	 *
	 * @var Logicless_Renderer
	 */
	private $renderer;

	/**
	 * Dummy order for render() signature.
	 *
	 * @var \WC_Abstract_Order
	 */
	private $order;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->renderer = new Logicless_Renderer();
		$this->order    = OrderHelper::create_order();
	}

	/**
	 * Helper: render template with given receipt data and return output.
	 *
	 * @param string $content      Template content.
	 * @param array  $receipt_data Receipt data.
	 *
	 * @return string
	 */
	private function render( string $content, array $receipt_data ): string {
		ob_start();
		$this->renderer->render( array( 'content' => $content ), $this->order, $receipt_data );
		return ob_get_clean();
	}

	// ─── Task 2: Basic section iteration ───

	/**
	 * Test iterating over an array of objects.
	 */
	public function test_section_iterates_over_array(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
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

		$output = $this->render(
			'{{#lines}}<div>{{name}} x{{qty}}</div>{{/lines}}',
			$data
		);

		$this->assertStringContainsString( 'Widget x2', $output );
		$this->assertStringContainsString( 'Gadget x1', $output );
	}

	/**
	 * Test empty array produces no output from section.
	 */
	public function test_section_empty_array_produces_no_output(): void {
		$data = array(
			'meta' => array( 'currency' => 'USD' ),
			'fees' => array(),
		);

		$output = $this->render(
			'before{{#fees}}<div>{{label}}</div>{{/fees}}after',
			$data
		);

		$this->assertSame( 'beforeafter', $output );
	}

	// ─── Task 3: Inverted sections and truthy conditionals ───

	/**
	 * Test inverted section shows when array is empty.
	 */
	public function test_inverted_section_shows_for_empty_array(): void {
		$data = array(
			'meta' => array( 'currency' => 'USD' ),
			'fees' => array(),
		);

		$output = $this->render(
			'{{^fees}}No fees{{/fees}}',
			$data
		);

		$this->assertStringContainsString( 'No fees', $output );
	}

	/**
	 * Test inverted section hidden when array has items.
	 */
	public function test_inverted_section_hidden_for_non_empty_array(): void {
		$data = array(
			'meta' => array( 'currency' => 'USD' ),
			'fees' => array(
				array( 'label' => 'Service Fee' ),
			),
		);

		$output = $this->render(
			'{{^fees}}No fees{{/fees}}',
			$data
		);

		$this->assertStringNotContainsString( 'No fees', $output );
	}

	/**
	 * Test truthy section shows for non-empty string.
	 */
	public function test_truthy_section_shows_for_non_empty_string(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'store' => array( 'phone' => '555-1234' ),
		);

		$output = $this->render(
			'{{#store.phone}}Phone: {{store.phone}}{{/store.phone}}',
			$data
		);

		$this->assertStringContainsString( 'Phone: 555-1234', $output );
	}

	/**
	 * Test truthy section hidden for empty string.
	 */
	public function test_truthy_section_hidden_for_empty_string(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'store' => array( 'phone' => '' ),
		);

		$output = $this->render(
			'{{#store.phone}}Phone: {{store.phone}}{{/store.phone}}',
			$data
		);

		$this->assertStringNotContainsString( 'Phone:', $output );
	}

	/**
	 * Test inverted section shows for missing key.
	 */
	public function test_inverted_section_shows_for_missing_key(): void {
		$data = array(
			'meta' => array( 'currency' => 'USD' ),
		);

		$output = $this->render(
			'{{^customer.name}}Walk-in customer{{/customer.name}}',
			$data
		);

		$this->assertStringContainsString( 'Walk-in customer', $output );
	}

	// ─── Task 4: Dot-path sections, {{.}} reference, context fallback ───

	/**
	 * Test {{.}} reference for array of strings.
	 */
	public function test_dot_reference_for_scalar_array(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'store' => array(
				'address_lines' => array( '123 Main St', 'Suite 100', 'Anytown 12345' ),
			),
		);

		$output = $this->render(
			'{{#store.address_lines}}<div>{{.}}</div>{{/store.address_lines}}',
			$data
		);

		$this->assertStringContainsString( '<div>123 Main St</div>', $output );
		$this->assertStringContainsString( '<div>Suite 100</div>', $output );
		$this->assertStringContainsString( '<div>Anytown 12345</div>', $output );
	}

	/**
	 * Test context fallback to parent scope.
	 */
	public function test_context_fallback_to_parent(): void {
		$data = array(
			'meta'  => array(
				'currency'     => 'USD',
				'order_number' => '1042',
			),
			'lines' => array(
				array( 'name' => 'Widget' ),
			),
		);

		$output = $this->render(
			'{{#lines}}<div>{{name}} (Order #{{meta.order_number}})</div>{{/lines}}',
			$data
		);

		$this->assertStringContainsString( 'Widget (Order #1042)', $output );
	}

	/**
	 * Test dot-path section name resolves through nested data.
	 */
	public function test_dot_path_section_name(): void {
		$data = array(
			'meta'     => array( 'currency' => 'USD' ),
			'customer' => array(
				'billing_address' => array(
					'first_name' => 'John',
					'last_name'  => 'Doe',
					'city'       => 'Portland',
				),
			),
		);

		$output = $this->render(
			'{{#customer.billing_address}}{{first_name}} {{last_name}}, {{city}}{{/customer.billing_address}}',
			$data
		);

		$this->assertStringContainsString( 'John Doe, Portland', $output );
	}

	// ─── Task 5: Nested sections ───

	/**
	 * Test nested sections iterate correctly.
	 */
	public function test_nested_section_iteration(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'lines' => array(
				array(
					'name'  => 'Widget',
					'taxes' => array(
						array(
							'label'  => 'VAT 20%',
							'amount' => 2.00,
						),
					),
				),
				array(
					'name'  => 'Gadget',
					'taxes' => array(
						array(
							'label'  => 'VAT 20%',
							'amount' => 1.50,
						),
						array(
							'label'  => 'State Tax',
							'amount' => 0.50,
						),
					),
				),
			),
		);

		$output = $this->render(
			'{{#lines}}<div>{{name}}{{#taxes}} [{{label}}]{{/taxes}}</div>{{/lines}}',
			$data
		);

		$this->assertStringContainsString( 'Widget [VAT 20%]', $output );
		$this->assertStringContainsString( 'Gadget [VAT 20%] [State Tax]', $output );
	}

	/**
	 * Test deeply nested sections render correctly.
	 */
	public function test_deep_nesting_renders(): void {
		$data = array(
			'meta' => array( 'currency' => 'USD' ),
			'a'    => array(
				array(
					'b' => array(
						array(
							'c' => array(
								array( 'val' => 'deep' ),
							),
						),
					),
				),
			),
		);

		$output = $this->render(
			'{{#a}}{{#b}}{{#c}}{{val}}{{/c}}{{/b}}{{/a}}',
			$data
		);

		$this->assertStringContainsString( 'deep', $output );
	}

	// ─── Task 6: Money formatting ───

	/**
	 * Test money fields are auto-formatted.
	 */
	public function test_money_fields_auto_formatted(): void {
		$data = array(
			'meta'   => array( 'currency' => 'USD' ),
			'totals' => array(
				'grand_total_incl' => 12.5,
			),
		);

		$output = $this->render(
			'<span>{{totals.grand_total_incl}}</span>',
			$data
		);

		$this->assertStringNotContainsString( '>12.5<', $output );
		$this->assertMatchesRegularExpression( '/12\\.50/', $output );
	}

	/**
	 * Test money fields inside sections are formatted.
	 */
	public function test_money_fields_formatted_inside_sections(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'lines' => array(
				array(
					'name'           => 'Widget',
					'line_total_incl' => 25.0,
				),
			),
		);

		$output = $this->render(
			'{{#lines}}{{name}}: {{line_total_incl}}{{/lines}}',
			$data
		);

		$this->assertStringContainsString( 'Widget:', $output );
		$this->assertMatchesRegularExpression( '/25\\.00/', $output );
	}

	/**
	 * Test non-money fields are NOT formatted.
	 */
	public function test_non_money_fields_not_formatted(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'lines' => array(
				array(
					'name' => 'Widget',
					'qty'  => 3,
				),
			),
		);

		$output = $this->render(
			'{{#lines}}{{qty}}{{/lines}}',
			$data
		);

		$this->assertStringContainsString( '3', $output );
		$this->assertStringNotContainsString( '$', $output );
	}

	// ─── Task 7: Standalone line stripping and edge cases ───

	/**
	 * Test standalone section tags do not produce blank lines.
	 */
	public function test_standalone_tags_no_blank_lines(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'lines' => array(
				array( 'name' => 'Widget' ),
			),
		);

		$template = "before\n{{#lines}}\n<div>{{name}}</div>\n{{/lines}}\nafter";
		$output   = $this->render( $template, $data );

		$this->assertSame( "before\n<div>Widget</div>\nafter", $output );
	}

	/**
	 * Test empty template outputs comment.
	 */
	public function test_empty_template_renders_comment(): void {
		$output = $this->render( '', array( 'meta' => array( 'currency' => 'USD' ) ) );
		$this->assertStringContainsString( 'Empty logicless receipt template', $output );
	}

	/**
	 * Test unclosed section tag throws a syntax exception.
	 *
	 * @throws \Mustache\Exception\SyntaxException Expected exception.
	 */
	public function test_unclosed_section_throws_exception(): void {
		$data = array(
			'meta'  => array( 'currency' => 'USD' ),
			'lines' => array(),
		);

		$this->expectException( \Mustache\Exception\SyntaxException::class );

		try {
			$this->render( '{{#lines}}no closing tag', $data );
		} catch ( \Mustache\Exception\SyntaxException $e ) {
			// Clean up the output buffer opened by render() helper.
			ob_end_clean();
			throw $e;
		}
	}

	/**
	 * Test missing placeholder key produces empty string.
	 */
	public function test_missing_key_produces_empty_string(): void {
		$data   = array( 'meta' => array( 'currency' => 'USD' ) );
		$output = $this->render( 'Hello {{nonexistent.key}}!', $data );

		$this->assertSame( 'Hello !', $output );
	}

	/**
	 * Test existing placeholder substitution still works.
	 */
	public function test_basic_placeholder_substitution(): void {
		$data = array(
			'meta'  => array(
				'currency'     => 'USD',
				'order_number' => '999',
				'mode'         => 'live',
			),
		);

		$output = $this->render(
			'<h1>{{meta.order_number}}</h1><p>{{meta.mode}}</p>',
			$data
		);

		$this->assertStringContainsString( '999', $output );
		$this->assertStringContainsString( 'live', $output );
	}
}
