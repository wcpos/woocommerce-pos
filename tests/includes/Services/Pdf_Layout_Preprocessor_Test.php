<?php
/**
 * Pdf_Layout_Preprocessor tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Pdf_Layout_Preprocessor;

/**
 * Pdf_Layout_Preprocessor_Test class.
 */
class Pdf_Layout_Preprocessor_Test extends \WP_UnitTestCase {
	/**
	 * Preprocessor under test.
	 *
	 * @var Pdf_Layout_Preprocessor
	 */
	private $preprocessor;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->preprocessor = new Pdf_Layout_Preprocessor();
	}

	/**
	 * Space-between label/value rows become a table with a right-aligned value.
	 *
	 * The previous CSS shim floated the value right; Dompdf stacks consecutive
	 * floats leftward, drifting the lower values (tendered/change) off the edge.
	 */
	public function test_space_between_row_becomes_table_with_right_aligned_value(): void {
		// Arrange.
		$html = '<div><div style="display: flex; justify-content: space-between; padding: 2px 0;">'
			. '<span>Change</span><span>7,16</span></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( '<table', $out );
		$this->assertStringContainsString( 'text-align: right', $out );
		$this->assertStringNotContainsString( 'float', $out );
		$this->assertStringNotContainsString( 'display: flex', $out );
	}

	/**
	 * A fixed flex basis is carried onto the table cell as a width.
	 */
	public function test_fixed_flex_basis_becomes_cell_width(): void {
		// Arrange.
		$html = '<div><div style="display: flex;">'
			. '<div style="flex: 1 1 auto;">note</div><div style="flex: 0 0 280px;">totals</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'width: 280px', $out );
	}

	/**
	 * Flex 0 0 auto columns shrink to content via width:1% + nowrap.
	 */
	public function test_shrink_flex_child_becomes_narrow_nowrap_cell(): void {
		// Arrange.
		$html = '<div><div style="display: flex;">'
			. '<div style="flex: 0 0 auto;">logo</div><div style="flex: 1 1 auto;">name</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'width: 1%', $out );
		$this->assertStringContainsString( 'white-space: nowrap', $out );
	}

	/**
	 * Equal flex:1 columns split the width evenly via a fixed-layout table.
	 *
	 * The packing-slip sign-off (Name / Date / Signature) collapsed to short
	 * content-width lines without this.
	 */
	public function test_equal_grow_columns_use_fixed_table_layout(): void {
		// Arrange.
		$html = '<div><div style="display: flex; gap: 24px;">'
			. '<div style="flex: 1;">a</div><div style="flex: 1;">b</div><div style="flex: 1;">c</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'table-layout: fixed', $out );
		$this->assertStringContainsString( 'padding-left: 24px', $out );
		$this->assertEquals( 3, substr_count( $out, '<td' ) );
	}

	/**
	 * Grid fr/px column specs map to proportional and fixed cell widths.
	 */
	public function test_grid_fr_columns_become_percentage_widths(): void {
		// Arrange.
		$html = '<div><div style="display: grid; grid-template-columns: 2fr 1fr;">'
			. '<div>wide</div><div>narrow</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'width: 66.667%', $out );
		$this->assertStringContainsString( 'width: 33.333%', $out );
	}

	/**
	 * Grids with more children than columns chunk into multiple table rows.
	 */
	public function test_grid_chunks_children_into_rows(): void {
		// Arrange.
		$html = '<div><div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px 20px;">'
			. '<div>1</div><div>2</div><div>3</div><div>4</div><div>5</div><div>6</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertEquals( 2, substr_count( $out, '<tr>' ) );
		$this->assertEquals( 6, substr_count( $out, '<td' ) );
		$this->assertStringContainsString( 'padding-left: 20px', $out );
		$this->assertStringContainsString( 'padding-top: 10px', $out );
	}

	/**
	 * Flex-end wrappers become right-aligned blocks, not tables.
	 */
	public function test_flex_end_wrapper_becomes_right_aligned_block(): void {
		// Arrange.
		$html = '<div><div style="display: flex; justify-content: flex-end;">'
			. '<div style="width: 220px;">barcode</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'text-align: right', $out );
		$this->assertStringContainsString( 'display: inline-block', $out );
		$this->assertStringNotContainsString( '<table', $out );
	}

	/**
	 * Inline-flex chips become inline-blocks with non-breaking inner whitespace.
	 *
	 * The status pill's fixed-size dot collapses as plain inline, and Dompdf's
	 * word-based minimum width wrapped the chip inside shrink-to-content cells,
	 * pushing the dot onto its own line.
	 */
	public function test_inline_flex_chip_becomes_unbreakable_inline_block(): void {
		// Arrange.
		$html = '<div><div style="display: inline-flex; align-items: center; gap: 6px;">'
			. "\n\t<span style=\"width: 6px; height: 6px; border-radius: 50%; background: #6b7280;\"></span> Completed\n</div></div>";

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'display: inline-block', $out );
		$this->assertStringContainsString( 'vertical-align: middle', $out );
		$this->assertStringContainsString( "\u{00A0}Completed", $out );
		$this->assertStringNotContainsString( 'inline-flex', $out );
	}

	/**
	 * Chip whitespace trimming must not corrupt adjacent multibyte characters.
	 *
	 * A byte-wise ltrim of the NBSP would also strip the leading 0xC2 byte of
	 * £/©/° and produce invalid UTF-8.
	 */
	public function test_inline_flex_chip_preserves_multibyte_edges(): void {
		// Arrange.
		$html = '<div><div style="display: inline-flex;"> £10 <b>paid</b> </div></div>';

		// Act.
		$out = html_entity_decode( $this->preprocessor->process( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Assert.
		$this->assertStringContainsString( '£10', $out );
	}

	/**
	 * Column flex containers become block stacks, not sideways row tables.
	 */
	public function test_column_flex_becomes_block_stack(): void {
		// Arrange.
		$html = '<div><div style="display: flex; flex-direction: column; gap: 8px;">'
			. '<div>first</div><div>second</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringNotContainsString( '<table', $out );
		$this->assertStringNotContainsString( 'display: flex', $out );
		$this->assertStringContainsString( 'margin-top: 8px', $out );
	}

	/**
	 * The `flex: auto` shorthand (1 1 auto) is a growing column, not shrink.
	 */
	public function test_flex_auto_child_grows(): void {
		// Arrange.
		$html = '<div><div style="display: flex;">'
			. '<div style="flex: auto;">name</div><div style="flex: 0 0 auto;">meta</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert: only the shrink cell gets the 1% width.
		$this->assertEquals( 1, substr_count( $out, 'width: 1%' ) );
	}

	/**
	 * The root element's padding is lifted and reported as @page margins in pt.
	 */
	public function test_root_padding_is_lifted_into_page_margins(): void {
		// Arrange.
		$html = '<div style="color: #111; padding: 32px 36px;"><p>Receipt</p></div>';

		// Act.
		$out     = $this->preprocessor->process( $html );
		$margins = $this->preprocessor->get_page_margins_pt();

		// Assert.
		$this->assertEquals( array( 24.0, 27.0, 24.0, 27.0 ), $margins );
		$this->assertStringNotContainsString( 'padding: 32px 36px', $out );
		$this->assertStringContainsString( 'color: #111', $out );
	}

	/**
	 * A root without padding reports zero margins (preview shows none either).
	 */
	public function test_root_without_padding_reports_zero_margins(): void {
		// Act.
		$this->preprocessor->process( '<div><p>Receipt</p></div>' );

		// Assert.
		$this->assertEquals( array( 0.0, 0.0, 0.0, 0.0 ), $this->preprocessor->get_page_margins_pt() );
	}

	/**
	 * Converted children keep their own decorations (borders, backgrounds).
	 */
	public function test_converted_children_keep_decorations(): void {
		// Arrange.
		$html = '<div><div style="display: grid; grid-template-columns: 2fr 1fr;">'
			. '<div style="border: 2px solid #111827; border-radius: 10px; padding: 18px 20px;">ship to</div>'
			. '<div>order</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'border: 2px solid #111827', $out );
		$this->assertStringContainsString( 'border-radius: 10px', $out );
		$this->assertStringContainsString( 'padding: 18px 20px', $out );
	}

	/**
	 * Style parsing keeps semicolons inside CSS function values.
	 */
	public function test_css_function_values_keep_inner_semicolons(): void {
		// Arrange.
		$html = '<div><div style="display: grid; grid-template-columns: 1fr 1fr;">'
			. '<div style="background-image: url(data:image/svg+xml;utf8,%3Csvg%3E%3C/svg%3E); border: 1px solid #111;">logo</div>'
			. '<div>order</div></div></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( 'background-image: url(data:image/svg+xml;utf8,%3Csvg%3E%3C/svg%3E)', $out );
		$this->assertStringContainsString( 'border: 1px solid #111', $out );
	}

	/**
	 * Multibyte content survives the DOM round-trip.
	 */
	public function test_multibyte_content_survives_round_trip(): void {
		// Arrange.
		$html = '<div><div style="display: flex; justify-content: space-between;">'
			. '<span>ARTÍCULO</span><span>42,84 £ — €5</span></div></div>';

		// Act.
		$out = html_entity_decode( $this->preprocessor->process( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Assert.
		$this->assertStringContainsString( 'ARTÍCULO', $out );
		$this->assertStringContainsString( '42,84 £ — €5', $out );
	}

	/**
	 * Existing tables and plain markup pass through untouched.
	 */
	public function test_plain_markup_passes_through(): void {
		// Arrange.
		$html = '<div><table style="width: 100%;"><tr><td>Item</td><td>Total</td></tr></table><p>Done</p></div>';

		// Act.
		$out = $this->preprocessor->process( $html );

		// Assert.
		$this->assertStringContainsString( '<td>Item</td>', $out );
		$this->assertStringContainsString( '<p>Done</p>', $out );
	}
}
