<?php
/**
 * Tests for gallery template tax-display behaviour.
 *
 * Covers two template families:
 *  - Adaptive templates follow the store's woocommerce_tax_display_cart setting
 *    via the neutral money fields (line_total, totals.subtotal, ...).
 *  - The detailed family is a formal tax invoice: it always itemises tax with
 *    tax-exclusive line items, and the tax row collapses when there is no tax.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Template_Tax_Display class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Template_Tax_Display extends WC_REST_Unit_Test_Case {
	/**
	 * Adaptive HTML gallery templates — must follow the store tax-display setting.
	 *
	 * @var string[]
	 */
	private const ADAPTIVE_HTML_TEMPLATES = array(
		'standard-receipt.html',
		'standard-receipt-rtl.html',
		'minimal-receipt.html',
		'narrow-receipt.html',
		'invoice.html',
		'quote.html',
	);

	/**
	 * Adaptive thermal gallery templates — must follow the store tax-display setting.
	 *
	 * @var string[]
	 */
	private const ADAPTIVE_THERMAL_TEMPLATES = array(
		'thermal-simple-58mm.xml',
		'thermal-simple-80mm.xml',
		'thermal-simple-80mm-rtl.xml',
	);

	/**
	 * Detailed thermal gallery templates — formal tax-invoice layout.
	 *
	 * @var string[]
	 */
	private const DETAILED_THERMAL_TEMPLATES = array(
		'thermal-detailed-58mm.xml',
		'thermal-detailed-80mm.xml',
	);

	/**
	 * Create an order with one taxable product.
	 *
	 * A $11.00 product taxed at 10%, so line_total_excl (11.00) and
	 * line_total_incl (12.10) differ and the neutral vs incl/excl distinction
	 * is observable in rendered output.
	 *
	 * @param string $cart_tax_display woocommerce_tax_display_cart value ('incl' or 'excl').
	 *
	 * @return \WC_Order
	 */
	private function create_taxed_order( string $cart_tax_display ) {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_cart', $cart_tax_display );

		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Taxed Product' );
		$product->set_regular_price( '11.00' );
		$product->set_tax_status( 'taxable' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order->save();

		return $order;
	}

	/**
	 * Render a gallery template file through the logicless renderer.
	 *
	 * @param string         $filename     Gallery template filename.
	 * @param \WC_Order|null $order        Order object.
	 * @param array          $receipt_data Canonical receipt payload.
	 *
	 * @return string
	 */
	private function render_gallery_template( string $filename, $order, array $receipt_data ): string {
		$path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/' . $filename;
		$this->assertFileExists( $path );

		$renderer = new Logicless_Renderer();
		ob_start();
		$renderer->render( array( 'content' => file_get_contents( $path ) ), $order, $receipt_data );

		return (string) ob_get_clean();
	}

	/**
	 * Read raw gallery template file contents.
	 *
	 * @param string $filename Gallery template filename.
	 *
	 * @return string
	 */
	private function read_gallery_template( string $filename ): string {
		$path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/' . $filename;
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Test adaptive HTML templates show tax-exclusive line items when the store displays prices excl tax.
	 */
	public function test_adaptive_html_templates_render_excl_line_items_when_store_displays_excl(): void {
		// Arrange.
		$order        = $this->create_taxed_order( 'excl' );
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$formatted    = Receipt_Data_Schema::format_money_fields( $receipt_data, $receipt_data['order']['currency'] );

		// Sanity: the builder resolved the neutral line total to the tax-exclusive value.
		$this->assertEquals(
			$receipt_data['lines'][0]['line_total_excl'],
			$receipt_data['lines'][0]['line_total'],
			'Neutral line_total must equal line_total_excl when the store displays prices excl tax.'
		);

		$line_total_excl = $formatted['lines'][0]['line_total_excl_display'];
		$line_total_incl = $formatted['lines'][0]['line_total_incl_display'];
		$grand_total     = $formatted['totals']['total_incl_display'];
		$this->assertNotEquals( $line_total_excl, $line_total_incl, 'Fixture must produce distinct incl/excl line totals.' );

		foreach ( self::ADAPTIVE_HTML_TEMPLATES as $filename ) {
			// Act.
			$output = $this->render_gallery_template( $filename, $order, $receipt_data );

			// Assert.
			$this->assertStringContainsString(
				$line_total_excl,
				$output,
				sprintf( '%s must show the tax-exclusive line total when the store displays prices excl tax.', $filename )
			);
			$this->assertStringContainsString(
				$grand_total,
				$output,
				sprintf( '%s must always show the gross grand total.', $filename )
			);
		}
	}

	/**
	 * Test adaptive HTML templates show tax-inclusive line items when the store displays prices incl tax.
	 */
	public function test_adaptive_html_templates_render_incl_line_items_when_store_displays_incl(): void {
		// Arrange.
		$order        = $this->create_taxed_order( 'incl' );
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		$formatted    = Receipt_Data_Schema::format_money_fields( $receipt_data, $receipt_data['order']['currency'] );

		// Sanity: the builder resolved the neutral line total to the tax-inclusive value.
		$this->assertEquals(
			$receipt_data['lines'][0]['line_total_incl'],
			$receipt_data['lines'][0]['line_total'],
			'Neutral line_total must equal line_total_incl when the store displays prices incl tax.'
		);

		$line_total_incl = $formatted['lines'][0]['line_total_incl_display'];

		foreach ( self::ADAPTIVE_HTML_TEMPLATES as $filename ) {
			// Act.
			$output = $this->render_gallery_template( $filename, $order, $receipt_data );

			// Assert.
			$this->assertStringContainsString(
				$line_total_incl,
				$output,
				sprintf( '%s must show the tax-inclusive line total when the store displays prices incl tax.', $filename )
			);
		}
	}

	/**
	 * Test adaptive thermal templates reference the neutral money fields, not the hard-coded incl variants.
	 */
	public function test_adaptive_thermal_templates_use_neutral_money_fields(): void {
		foreach ( self::ADAPTIVE_THERMAL_TEMPLATES as $filename ) {
			// Arrange / Act.
			$content = $this->read_gallery_template( $filename );

			// Assert — line items and subtotal follow the store setting via neutral fields.
			$this->assertStringContainsString( '{{line_total_display}}', $content, sprintf( '%s line items must use the neutral line_total field.', $filename ) );
			$this->assertStringContainsString( '{{totals.subtotal_display}}', $content, sprintf( '%s subtotal must use the neutral subtotal field.', $filename ) );
			$this->assertStringNotContainsString( '{{line_total_incl_display}}', $content, sprintf( '%s must not hard-code tax-inclusive line totals.', $filename ) );
			$this->assertStringNotContainsString( '{{totals.subtotal_incl_display}}', $content, sprintf( '%s must not hard-code a tax-inclusive subtotal.', $filename ) );

			// Assert — the grand total stays gross regardless of display mode.
			$this->assertStringContainsString( '{{totals.total_incl_display}}', $content, sprintf( '%s grand total must stay gross (total_incl).', $filename ) );
		}
	}

	/**
	 * Test the detailed thermal templates use tax-exclusive line items consistent with their subtotal-excl totals walk.
	 */
	public function test_detailed_thermal_templates_use_consistent_excl_line_items(): void {
		foreach ( self::DETAILED_THERMAL_TEMPLATES as $filename ) {
			// Arrange / Act.
			$content = $this->read_gallery_template( $filename );

			// Assert — line items show net amounts so they reconcile with subtotal_excl.
			$this->assertStringContainsString( '{{unit_price_excl_display}}', $content, sprintf( '%s line items must show tax-exclusive unit prices.', $filename ) );
			$this->assertStringContainsString( '{{line_total_excl_display}}', $content, sprintf( '%s line items must show tax-exclusive line totals.', $filename ) );
			$this->assertStringNotContainsString( '{{unit_price_incl_display}}', $content, sprintf( '%s must not mix tax-inclusive unit prices into an excl totals walk.', $filename ) );
			$this->assertStringNotContainsString( '{{line_total_incl_display}}', $content, sprintf( '%s must not mix tax-inclusive line totals into an excl totals walk.', $filename ) );

			// Assert — the tax row collapses when the order has no tax.
			$this->assertStringContainsString( '{{#totals.tax_total}}', $content, sprintf( '%s must guard the tax row so it collapses when there is no tax.', $filename ) );
		}
	}

	/**
	 * Test gallery templates branch tax wording for included vs additive tax displays.
	 */
	public function test_gallery_templates_branch_tax_wording_by_display_mode(): void {
		// Arrange.
		$labels = Receipt_I18n_Labels::get_labels();

		$this->assertArrayHasKey( 'included_tax', $labels, 'Receipt i18n labels must expose included_tax for inclusive-price receipts.' );
		$this->assertSame( 'Tax included', $labels['included_tax'], 'Default included-tax label should follow WooCommerce receipt wording.' );

		$total_tax_templates = array(
			'detailed-receipt.html',
			'invoice.html',
			'minimal-receipt.html',
			'quote.html',
			'standard-receipt.html',
			'standard-receipt-rtl.html',
			'thermal-detailed-58mm.xml',
			'thermal-detailed-80mm.xml',
		);

		$tax_summary_templates = array(
			'detailed-receipt.html',
			'narrow-receipt.html',
			'quote.html',
			'thermal-detailed-58mm.xml',
			'thermal-detailed-80mm.xml',
			'thermal-simple-58mm.xml',
			'thermal-simple-80mm.xml',
			'thermal-simple-80mm-rtl.xml',
		);

		foreach ( $total_tax_templates as $filename ) {
			// Act.
			$content = $this->read_gallery_template( $filename );

			// Assert — exclusive receipts use additive wording, inclusive receipts use informational wording.
			$this->assertMatchesRegularExpression(
				'/\{\{\s*#\s*tax\.display_excl\s*\}\}\s*\{\{\s*i18n\.total_tax\s*\}\}\s*\{\{\s*\/\s*tax\.display_excl\s*\}\}/',
				$content,
				sprintf( '%s must show Total Tax only for tax-exclusive display mode.', $filename )
			);
			$this->assertMatchesRegularExpression(
				'/\{\{\s*#\s*tax\.display_incl\s*\}\}\s*\{\{\s*i18n\.included_tax\s*\}\}\s*\{\{\s*\/\s*tax\.display_incl\s*\}\}/',
				$content,
				sprintf( '%s must show Tax included for tax-inclusive display mode.', $filename )
			);
		}

		foreach ( $tax_summary_templates as $filename ) {
			// Act.
			$content = $this->read_gallery_template( $filename );

			// Assert — tax-summary rows/headings need access to both wordings.
			$this->assertMatchesRegularExpression(
				'/\{\{\s*#\s*tax\.display_excl\s*\}\}/',
				$content,
				sprintf( '%s tax summary must branch for tax-exclusive display mode.', $filename )
			);
			$this->assertMatchesRegularExpression(
				'/\{\{\s*#\s*tax\.display_incl\s*\}\}\s*\{\{\s*i18n\.included_tax\s*\}\}/',
				$content,
				sprintf( '%s tax summary must branch to included-tax wording for tax-inclusive display mode.', $filename )
			);
		}
	}

	/**
	 * Test the tax summary never prints the internal WooCommerce tax-rate id.
	 *
	 * The tax_summary[].code field carries the WooCommerce tax-rate database id, which is
	 * meaningless on a customer receipt. Templates must render the label and
	 * rate, never the id.
	 */
	public function test_tax_summary_does_not_print_internal_rate_id(): void {
		$templates = array_merge( self::DETAILED_THERMAL_TEMPLATES, array( 'detailed-receipt.html' ) );

		foreach ( $templates as $filename ) {
			$content = $this->read_gallery_template( $filename );

			preg_match_all(
				'/\{\{\s*#\s*tax_summary\s*\}\}[\s\S]*?\{\{\s*\/\s*tax_summary\s*\}\}/',
				$content,
				$tax_summary_blocks
			);

			$this->assertNotEmpty(
				$tax_summary_blocks[0],
				sprintf( '%s must include a tax_summary block for this assertion.', $filename )
			);

			foreach ( $tax_summary_blocks[0] as $tax_summary_block ) {
				$this->assertDoesNotMatchRegularExpression(
					'/\{\{\s*#\s*code\s*\}\}|\{\{\s*code\s*\}\}/',
					$tax_summary_block,
					sprintf( '%s tax summary must not render tax_summary[].code.', $filename )
				);
			}
		}
	}

	/**
	 * Test detailed-receipt.html omits the Total Tax row when the order has no tax.
	 */
	public function test_detailed_receipt_hides_tax_row_when_order_has_no_tax(): void {
		// Arrange — a plain order with taxes disabled.
		update_option( 'woocommerce_calc_taxes', 'no' );
		$order        = OrderHelper::create_order();
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Sanity: the order carries no tax.
		$this->assertEquals( 0.0, (float) $receipt_data['totals']['tax_total'], 'Fixture order must have no tax.' );

		$labels          = Receipt_I18n_Labels::get_labels();
		$total_tax_label = $labels['total_tax'];

		// Act.
		$output = $this->render_gallery_template( 'detailed-receipt.html', $order, $receipt_data );

		// Assert.
		$this->assertStringNotContainsString(
			$total_tax_label,
			$output,
			'detailed-receipt.html must not render the Total Tax row when the order has no tax.'
		);
	}

	/**
	 * Test detailed-receipt.html keeps the formal tax-invoice fields and is not neutral-swapped.
	 */
	public function test_detailed_receipt_html_uses_tax_invoice_fields(): void {
		// Arrange / Act.
		$content = $this->read_gallery_template( 'detailed-receipt.html' );

		// Assert — the detailed family is a formal tax invoice: tax-exclusive unit
		// price and subtotal, with the tax rows guarded. It must not adopt the
		// adaptive family's neutral fields.
		$this->assertStringContainsString( '{{unit_price_excl_display}}', $content, 'detailed-receipt.html must show tax-exclusive unit prices.' );
		$this->assertStringContainsString( '{{totals.subtotal_excl_display}}', $content, 'detailed-receipt.html must show a tax-exclusive subtotal.' );
		$this->assertStringContainsString( '{{#totals.tax_total}}', $content, 'detailed-receipt.html must guard the tax rows so they collapse when there is no tax.' );
		$this->assertStringNotContainsString( '{{unit_price_display}}', $content, 'detailed-receipt.html must not use the neutral unit_price field.' );
		$this->assertStringNotContainsString( '{{totals.subtotal_display}}', $content, 'detailed-receipt.html must not use the neutral subtotal field.' );
	}

	/**
	 * Test no gallery template renders the neutral totals.total as a grand total.
	 *
	 * The neutral totals.total resolves to a pre-tax figure for tax-exclusive
	 * stores, so it is never a valid headline total — templates must use
	 * totals.total_incl for the grand total.
	 */
	public function test_no_gallery_template_uses_neutral_grand_total(): void {
		// Arrange.
		$gallery_dir = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/';
		$files       = array_merge(
			(array) glob( $gallery_dir . '*.html' ),
			(array) glob( $gallery_dir . '*.xml' )
		);
		$this->assertNotEmpty( $files, 'Gallery should contain template files.' );

		foreach ( $files as $path ) {
			// Act.
			$content = (string) file_get_contents( $path );

			// Assert.
			$this->assertStringNotContainsString(
				'{{totals.total_display}}',
				$content,
				sprintf( '%s must use totals.total_incl for the grand total, not the neutral (pre-tax for excl stores) totals.total.', basename( $path ) )
			);
		}
	}
}
