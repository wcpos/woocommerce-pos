<?php
/**
 * Tests for the plain text thermal emitter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Text_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Text_Thermal_Emitter_Test class.
 */
class Text_Thermal_Emitter_Test extends WP_UnitTestCase {

	/**
	 * Markup parser instance.
	 *
	 * @var Thermal_Markup_Parser
	 */
	private $parser;

	/**
	 * Emitter under test.
	 *
	 * @var Text_Thermal_Emitter
	 */
	private $emitter;

	/**
	 * Set up the parser and emitter instances.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->parser  = new Thermal_Markup_Parser();
		$this->emitter = new Text_Thermal_Emitter();
	}

	/**
	 * Emit plain text for a markup string.
	 *
	 * @param string $xml The thermal markup.
	 *
	 * @return string The rendered text.
	 */
	private function render( string $xml ): string {
		return $this->emitter->emit( $this->parser->parse( $xml ) );
	}

	/**
	 * It emits text lines with no command bytes.
	 */
	public function test_emit_text_lines_carry_no_control_bytes(): void {
		$out = $this->render( '<receipt paper-width="20"><text>Hello</text><text>World</text></receipt>' );

		$this->assertSame( "Hello\nWorld\n", $out );
	}

	/**
	 * It centres and right-aligns text within the paper width.
	 */
	public function test_emit_aligns_text_within_paper_width(): void {
		$out = $this->render(
			'<receipt paper-width="10"><align mode="center"><text>abcd</text></align><align mode="right"><text>xy</text></align></receipt>'
		);

		$this->assertSame( "   abcd\n        xy\n", $out );
	}

	/**
	 * It lays a row out in fixed columns, splitting the star column.
	 */
	public function test_emit_row_pads_columns_to_the_paper_width(): void {
		$out = $this->render(
			'<receipt paper-width="20"><row><col width="*">Coffee</col><col width="6" align="right">4.50</col></row></receipt>'
		);

		// The star column takes 14 cells; the fixed column right-aligns in 6.
		$this->assertSame( 'Coffee' . str_repeat( ' ', 10 ) . "4.50\n", $out );
		$this->assertSame( 20, strlen( rtrim( $out, "\n" ) ) );
	}

	/**
	 * It walks styling wrappers through without emitting markers.
	 */
	public function test_emit_ignores_styling_but_keeps_the_text(): void {
		$out = $this->render( '<receipt paper-width="20"><text><bold>TOTAL</bold></text></receipt>' );

		$this->assertSame( "TOTAL\n", $out );
	}

	/**
	 * It draws horizontal rules across the paper width.
	 */
	public function test_emit_line_fills_the_paper_width(): void {
		$out = $this->render( '<receipt paper-width="8"><line/><line style="double"/></receipt>' );

		$this->assertSame( "--------\n========\n", $out );
	}

	/**
	 * It prints a QR code's value as text rather than dropping the node.
	 */
	public function test_emit_qrcode_falls_back_to_its_value(): void {
		$out = $this->render( '<receipt paper-width="20"><qrcode>https://x.test</qrcode></receipt>' );

		$this->assertStringContainsString( 'https://x.test', $out );
	}

	/**
	 * It drops image nodes, which plain text cannot carry.
	 */
	public function test_emit_drops_images(): void {
		$out = $this->render( '<receipt paper-width="20"><image src="data:image/png;base64,AAA"/><text>Hi</text></receipt>' );

		$this->assertSame( "Hi\n", $out );
	}

	/**
	 * It reports the AST's cut instead of printing it.
	 */
	public function test_emit_reports_cut_out_of_band(): void {
		$out = $this->render( '<receipt paper-width="20"><text>Hi</text><cut type="full"/></receipt>' );

		$this->assertSame( "Hi\n", $out );
		$this->assertSame( 'full', $this->emitter->cut_type() );
	}

	/**
	 * It reports an explicit drawer node instead of printing it.
	 */
	public function test_emit_reports_drawer_out_of_band(): void {
		$out = $this->render( '<receipt paper-width="20"><text>Hi</text><drawer/></receipt>' );

		$this->assertSame( "Hi\n", $out );
		$this->assertSame( 'end', $this->emitter->drawer() );
	}

	/**
	 * It reports a drawer for a job that asked for one without an AST node.
	 */
	public function test_emit_reports_drawer_from_render_options(): void {
		$emitter = new Text_Thermal_Emitter( array( 'auto_open_drawer' => true ) );
		$emitter->emit( $this->parser->parse( '<receipt paper-width="20"><text>Hi</text></receipt>' ) );

		$this->assertSame( 'end', $emitter->drawer() );
	}

	/**
	 * It reports no cut or drawer when the receipt asked for neither.
	 */
	public function test_emit_reports_nothing_when_the_receipt_asks_for_nothing(): void {
		$this->render( '<receipt paper-width="20"><text>Hi</text></receipt>' );

		$this->assertNull( $this->emitter->cut_type() );
		$this->assertNull( $this->emitter->drawer() );
	}

	/**
	 * It counts full-width characters as two cells when truncating a column.
	 */
	public function test_emit_row_truncates_full_width_text_by_display_width(): void {
		$out = $this->render(
			'<receipt paper-width="10"><row><col width="4">日本語</col><col width="6" align="right">9</col></row></receipt>'
		);

		// Two kanji fill the 4-cell column; the third is dropped.
		$this->assertSame( "日本     9\n", $out );
	}

	/**
	 * It resets state between emissions so a reused emitter does not leak.
	 */
	public function test_emit_resets_state_between_calls(): void {
		$this->render( '<receipt paper-width="20"><text>Hi</text><cut/></receipt>' );
		$out = $this->render( '<receipt paper-width="20"><text>Bye</text></receipt>' );

		$this->assertSame( "Bye\n", $out );
		$this->assertNull( $this->emitter->cut_type() );
	}
}
