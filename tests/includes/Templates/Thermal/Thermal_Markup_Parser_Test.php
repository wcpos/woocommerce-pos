<?php
/**
 * Tests for the thermal markup XML parser.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Thermal_Markup_Parser_Test class.
 */
class Thermal_Markup_Parser_Test extends WP_UnitTestCase {

	/**
	 * Parser under test.
	 *
	 * @var Thermal_Markup_Parser
	 */
	private $parser;

	/**
	 * Set up the parser instance.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->parser = new Thermal_Markup_Parser();
	}

	/**
	 * A representative document parses into the expected nested AST.
	 *
	 * @return void
	 */
	public function test_parse_representative_document_returns_expected_ast(): void {
		// Arrange.
		$xml = '<receipt paper-width="32"><align mode="center"><bold><text>Hi</text></bold></align>'
			. '<row><col width="*">A</col><col width="10" align="right">B</col></row>'
			. '<line style="double"/><cut/></receipt>';

		// Act.
		$ast = $this->parser->parse( $xml );

		// Assert.
		$this->assertEquals( 'receipt', $ast['type'] );
		$this->assertEquals( 32, $ast['paper_width'] );

		$align = $ast['children'][0];
		$this->assertEquals( 'align', $align['type'] );
		$this->assertEquals( 'center', $align['mode'] );

		$bold = $align['children'][0];
		$this->assertEquals( 'bold', $bold['type'] );

		$text = $bold['children'][0];
		$this->assertEquals( 'text', $text['type'] );

		$raw = $text['children'][0];
		$this->assertEquals( 'raw-text', $raw['type'] );
		$this->assertEquals( 'Hi', $raw['value'] );

		$row = $ast['children'][1];
		$this->assertEquals( 'row', $row['type'] );
		$this->assertCount( 2, $row['children'] );

		$col_one = $row['children'][0];
		$this->assertEquals( 'col', $col_one['type'] );
		$this->assertEquals( '*', $col_one['width'] );

		$col_two = $row['children'][1];
		$this->assertEquals( 10, $col_two['width'] );
		$this->assertEquals( 'right', $col_two['align'] );

		$line = $ast['children'][2];
		$this->assertEquals( 'line', $line['type'] );
		$this->assertEquals( 'double', $line['style'] );

		$cut = $ast['children'][3];
		$this->assertEquals( 'cut', $cut['type'] );
		$this->assertEquals( 'partial', $cut['cut_type'] );
	}

	/**
	 * Invalid numeric attributes fall back to their defaults.
	 *
	 * @return void
	 */
	public function test_parse_invalid_numeric_attributes_use_fallbacks(): void {
		// Arrange.
		$xml = '<receipt paper-width="12px"><feed lines="3.5"/><col-ignored/></receipt>';

		// Act.
		$ast = $this->parser->parse( $xml );

		// Assert.
		$this->assertEquals( 48, $ast['paper_width'] );
		$feed = $ast['children'][0];
		$this->assertEquals( 'feed', $feed['type'] );
		$this->assertEquals( 1, $feed['lines'] );
	}

	/**
	 * A non-numeric col width falls back to 12.
	 *
	 * @return void
	 */
	public function test_parse_invalid_col_width_falls_back_to_twelve(): void {
		// Arrange.
		$xml = '<receipt><row><col width="abc">A</col></row></receipt>';

		// Act.
		$ast = $this->parser->parse( $xml );

		// Assert.
		$col = $ast['children'][0]['children'][0];
		$this->assertEquals( 12, $col['width'] );
	}

	/**
	 * Barcode elements with a QR type normalize into qrcode nodes.
	 *
	 * @return void
	 */
	public function test_parse_qr_barcode_normalizes_to_qrcode_node(): void {
		// Arrange.
		$xml_qr      = '<receipt><barcode type="qr" height="40">X</barcode></receipt>';
		$xml_qrcode  = '<receipt><barcode type="qrcode" height="40">X</barcode></receipt>';

		// Act.
		$node_qr     = $this->parser->parse( $xml_qr )['children'][0];
		$node_qrcode = $this->parser->parse( $xml_qrcode )['children'][0];

		// Assert.
		$this->assertEquals( 'qrcode', $node_qr['type'] );
		$this->assertEquals( 4, $node_qr['size'] );
		$this->assertEquals( 'X', $node_qr['value'] );

		$this->assertEquals( 'qrcode', $node_qrcode['type'] );
		$this->assertEquals( 4, $node_qrcode['size'] );
		$this->assertEquals( 'X', $node_qrcode['value'] );
	}

	/**
	 * Whitespace-only text is skipped while leading spaces are preserved.
	 *
	 * @return void
	 */
	public function test_parse_preserves_verbatim_text_and_skips_whitespace(): void {
		// Arrange.
		$xml = '<receipt><text>  SKU: 1</text></receipt>';

		// Act.
		$ast = $this->parser->parse( $xml );

		// Assert.
		$text = $ast['children'][0];
		$this->assertEquals( 'text', $text['type'] );
		$this->assertCount( 1, $text['children'] );

		$raw = $text['children'][0];
		$this->assertEquals( 'raw-text', $raw['type'] );
		$this->assertEquals( '  SKU: 1', $raw['value'] );
	}

	/**
	 * Native qrcode and barcode elements parse with their own defaults.
	 *
	 * @return void
	 */
	public function test_parse_native_qrcode_and_barcode_nodes(): void {
		// Arrange.
		$xml = '<receipt><qrcode size="4">Q</qrcode>'
			. '<barcode type="code128" height="60">ABC</barcode></receipt>';

		// Act.
		$ast = $this->parser->parse( $xml );

		// Assert.
		$qrcode = $ast['children'][0];
		$this->assertEquals( 'qrcode', $qrcode['type'] );
		$this->assertEquals( 4, $qrcode['size'] );
		$this->assertEquals( 'Q', $qrcode['value'] );

		$barcode = $ast['children'][1];
		$this->assertEquals( 'barcode', $barcode['type'] );
		$this->assertEquals( 'code128', $barcode['barcode_type'] );
		$this->assertEquals( 60, $barcode['height'] );
		$this->assertEquals( 'ABC', $barcode['value'] );
	}

	/**
	 * An explicit full cut type is honoured.
	 *
	 * @return void
	 */
	public function test_parse_cut_full_type(): void {
		// Arrange.
		$xml = '<receipt><cut type="full"/></receipt>';

		// Act.
		$ast = $this->parser->parse( $xml );

		// Assert.
		$cut = $ast['children'][0];
		$this->assertEquals( 'cut', $cut['type'] );
		$this->assertEquals( 'full', $cut['cut_type'] );
	}
}
