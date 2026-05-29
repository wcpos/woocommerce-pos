<?php
/**
 * Tests for the ePOS-Print XML thermal emitter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Epos_Xml_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Epos_Xml_Thermal_Emitter_Test class.
 */
class Epos_Xml_Thermal_Emitter_Test extends WP_UnitTestCase {

	/**
	 * Markup parser instance.
	 *
	 * @var Thermal_Markup_Parser
	 */
	private $parser;

	/**
	 * Emitter under test.
	 *
	 * @var Epos_Xml_Thermal_Emitter
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
		$this->emitter = new Epos_Xml_Thermal_Emitter();
	}

	/**
	 * Emit ePOS-Print XML for a markup string.
	 *
	 * @param string $xml The thermal markup.
	 *
	 * @return string The ePOS-Print XML.
	 */
	private function render( string $xml ): string {
		return $this->emitter->emit( $this->parser->parse( $xml ) );
	}

	/**
	 * Root element wraps the document and parses as valid XML.
	 *
	 * @return void
	 */
	public function test_root_wrapper_is_well_formed_epos_print(): void {
		// Arrange / Act.
		$xml = $this->render( '<receipt><text>Hi</text></receipt>' );

		// Assert.
		$this->assertStringStartsWith( '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">', $xml );
		$this->assertStringEndsWith( '</epos-print>', $xml );
		$this->assertNotFalse( simplexml_load_string( $xml ) );
	}

	/**
	 * Centered bold scaled heading carries all the style attributes.
	 *
	 * @return void
	 */
	public function test_centered_bold_scaled_heading_emits_all_style_attrs(): void {
		// Arrange.
		$markup = '<receipt><align mode="center"><bold><size width="2" height="2"><text>Store</text></size></bold></align></receipt>';

		// Act.
		$xml = $this->render( $markup );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $xml ) );
		$this->assertStringContainsString( '<text', $xml );
		$this->assertStringContainsString( 'align="center"', $xml );
		$this->assertStringContainsString( 'em="true"', $xml );
		$this->assertStringContainsString( 'dw="true"', $xml );
		$this->assertStringContainsString( 'dh="true"', $xml );
		$this->assertStringContainsString( 'Store', $xml );
	}

	/**
	 * Plain left text has no alignment or styling attributes.
	 *
	 * @return void
	 */
	public function test_plain_left_text_has_no_style_attributes(): void {
		// Arrange / Act.
		$xml = $this->render( '<receipt><text>Hello</text></receipt>' );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $xml ) );
		$this->assertStringContainsString( 'Hello', $xml );
		$this->assertStringNotContainsString( 'align=', $xml );
		$this->assertStringNotContainsString( 'em="true"', $xml );
		$this->assertStringNotContainsString( 'dw="true"', $xml );
		$this->assertStringNotContainsString( 'dh="true"', $xml );
	}

	/**
	 * A row emits one left-aligned text element of full paper width.
	 *
	 * @return void
	 */
	public function test_row_emits_single_full_width_text_line(): void {
		// Arrange.
		$markup = '<receipt paper-width="48"><row><col width="*">Item</col><col width="10" align="right">$9.99</col></row></receipt>';

		// Act.
		$xml = $this->render( $markup );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $xml ) );
		$this->assertEquals( 1, substr_count( $xml, '<text' ) );
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc );
		$line = rtrim( (string) $doc->text, "\n" );
		$this->assertEquals( 48, strlen( $line ) );
		$this->assertStringStartsWith( 'Item', $line );
		$this->assertStringEndsWith( '$9.99', $line );
	}

	/**
	 * Horizontal rules render to repeated characters per style.
	 *
	 * @return void
	 */
	public function test_lines_render_expected_characters(): void {
		// Arrange / Act.
		$single = $this->render( '<receipt paper-width="48"><line/></receipt>' );
		$double = $this->render( '<receipt paper-width="48"><line style="double"/></receipt>' );
		$dotted = $this->render( '<receipt paper-width="48"><line style="dotted"/></receipt>' );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $single ) );
		$this->assertNotFalse( simplexml_load_string( $double ) );
		$this->assertNotFalse( simplexml_load_string( $dotted ) );
		$this->assertStringContainsString( str_repeat( '-', 48 ), $single );
		$this->assertStringContainsString( str_repeat( '=', 48 ), $double );
		$this->assertStringContainsString( '. ', $dotted );
	}

	/**
	 * Feed, cut and drawer map to their ePOS-Print elements.
	 *
	 * @return void
	 */
	public function test_feed_cut_drawer_map_to_elements(): void {
		// Arrange / Act.
		$feed   = $this->render( '<receipt><feed lines="3"/></receipt>' );
		$cut    = $this->render( '<receipt><cut/></receipt>' );
		$drawer = $this->render( '<receipt><drawer/></receipt>' );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $feed ) );
		$this->assertNotFalse( simplexml_load_string( $cut ) );
		$this->assertNotFalse( simplexml_load_string( $drawer ) );
		$this->assertStringContainsString( '<feed line="3"/>', $feed );
		$this->assertStringContainsString( '<cut type="feed"/>', $cut );
		$this->assertStringContainsString( '<pulse/>', $drawer );
	}

	/**
	 * Barcode and QR code map to their native ePOS-Print elements.
	 *
	 * @return void
	 */
	public function test_barcode_and_qrcode_map_to_native_elements(): void {
		// Arrange / Act.
		$barcode = $this->render( '<receipt><barcode type="code128" height="60">ABC-123</barcode></receipt>' );
		$qrcode  = $this->render( '<receipt><qrcode size="5">XYZ</qrcode></receipt>' );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $barcode ) );
		$this->assertNotFalse( simplexml_load_string( $qrcode ) );
		$this->assertStringContainsString( '<barcode', $barcode );
		$this->assertStringContainsString( 'type="code128"', $barcode );
		$this->assertStringContainsString( 'ABC-123', $barcode );
		$this->assertStringContainsString( '<symbol', $qrcode );
		$this->assertStringContainsString( 'type="qrcode_model_2"', $qrcode );
		$this->assertStringContainsString( 'XYZ', $qrcode );
	}

	/**
	 * Text content with XML-significant characters is escaped and round-trips.
	 *
	 * @return void
	 */
	public function test_text_content_is_xml_escaped(): void {
		// Arrange.
		$markup = '<receipt><text>Tom &amp; Jerry &lt;x&gt;</text></receipt>';

		// Act.
		$xml = $this->render( $markup );

		// Assert.
		$this->assertStringContainsString( 'Tom &amp; Jerry &lt;x&gt;', $xml );
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc );
		$this->assertEquals( 'Tom & Jerry <x>', rtrim( (string) $doc->text, "\n" ) );
	}

	/**
	 * Image nodes are skipped but surrounding text is preserved.
	 *
	 * @return void
	 */
	public function test_image_node_is_skipped(): void {
		// Arrange.
		$markup = '<receipt><text>before</text><image src="x" width="64"/><text>after</text></receipt>';

		// Act.
		$xml = $this->render( $markup );

		// Assert.
		$this->assertNotFalse( simplexml_load_string( $xml ) );
		$this->assertStringNotContainsString( '<image', $xml );
		$this->assertStringContainsString( 'before', $xml );
		$this->assertStringContainsString( 'after', $xml );
	}
}
