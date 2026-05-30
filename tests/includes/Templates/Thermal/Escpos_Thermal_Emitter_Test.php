<?php
/**
 * Tests for the ESC/POS thermal emitter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Escpos_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Escpos_Thermal_Emitter_Test class.
 */
class Escpos_Thermal_Emitter_Test extends WP_UnitTestCase {

	/**
	 * Markup parser instance.
	 *
	 * @var Thermal_Markup_Parser
	 */
	private $parser;

	/**
	 * Emitter under test.
	 *
	 * @var Escpos_Thermal_Emitter
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
		$this->emitter = new Escpos_Thermal_Emitter();
	}

	/**
	 * Emit ESC/POS bytes for a markup string.
	 *
	 * @param string $xml The thermal markup.
	 *
	 * @return string Raw ESC/POS bytes.
	 */
	private function render( string $xml ): string {
		return $this->emitter->emit( $this->parser->parse( $xml ) );
	}

	/**
	 * Index of the first occurrence of a byte sequence.
	 *
	 * @param string $bytes  The byte string to search.
	 * @param array  $needle The ordinal byte sequence to find.
	 * @param int    $from   The starting offset.
	 *
	 * @return int The index, or -1 when not found.
	 */
	private function sequence_index( string $bytes, array $needle, int $from = 0 ): int {
		$length      = \strlen( $bytes );
		$needle_size = \count( $needle );
		if ( 0 === $needle_size ) {
			return $from;
		}
		for ( $index = $from; $index <= $length - $needle_size; $index++ ) {
			$matched = true;
			for ( $offset = 0; $offset < $needle_size; $offset++ ) {
				if ( \ord( $bytes[ $index + $offset ] ) !== $needle[ $offset ] ) {
					$matched = false;
					break;
				}
			}
			if ( $matched ) {
				return $index;
			}
		}

		return -1;
	}

	/**
	 * Whether a byte sequence is present.
	 *
	 * @param string $bytes  The byte string to search.
	 * @param array  $needle The ordinal byte sequence to find.
	 *
	 * @return bool True when present.
	 */
	private function includes_sequence( string $bytes, array $needle ): bool {
		return -1 !== $this->sequence_index( $bytes, $needle );
	}

	/**
	 * Convert a literal ASCII string to an ordinal byte array.
	 *
	 * @param string $text The literal text.
	 *
	 * @return array The ordinal bytes.
	 */
	private function ascii_bytes( string $text ): array {
		$bytes = array();
		$length = \strlen( $text );
		for ( $index = 0; $index < $length; $index++ ) {
			$bytes[] = \ord( $text[ $index ] );
		}

		return $bytes;
	}

	/**
	 * Number of extra bytes to skip after a GS command byte.
	 *
	 * @param string $bytes The byte string.
	 * @param int    $index The index of the GS byte (0x1d).
	 *
	 * @return int The number of bytes to skip after the command byte.
	 */
	private function gs_command_skip_length( string $bytes, int $index ): int {
		$command = isset( $bytes[ $index + 1 ] ) ? \ord( $bytes[ $index + 1 ] ) : 0;
		if ( 0x21 === $command ) {
			return 1;
		}
		if ( 0x56 === $command ) {
			$mode = isset( $bytes[ $index + 2 ] ) ? \ord( $bytes[ $index + 2 ] ) : 0;

			return ( 0x41 === $mode || 0x42 === $mode ) ? 2 : 1;
		}

		return 0;
	}

	/**
	 * Decode the printable ASCII content, skipping ESC/GS command bytes.
	 *
	 * @param string $bytes The byte string.
	 *
	 * @return string The decoded printable text with newlines.
	 */
	private function decode_printable_ascii( string $bytes ): string {
		$output = '';
		$length = \strlen( $bytes );
		for ( $index = 0; $index < $length; $index++ ) {
			$byte = \ord( $bytes[ $index ] );
			if ( 0x1b === $byte ) {
				$command = isset( $bytes[ $index + 1 ] ) ? \ord( $bytes[ $index + 1 ] ) : 0;
				$index  += $this->esc_skip_length( $command );
				continue;
			}
			if ( 0x1d === $byte ) {
				$index += 1 + $this->gs_command_skip_length( $bytes, $index );
				continue;
			}
			if ( 0x0d === $byte || 0x0a === $byte ) {
				$output .= "\n";
				continue;
			}
			if ( $byte >= 0x20 && $byte <= 0x7e ) {
				$output .= \chr( $byte );
			}
		}

		return $output;
	}

	/**
	 * Number of extra bytes to skip after an ESC command byte.
	 *
	 * @param int $command The command byte following ESC.
	 *
	 * @return int The number of extra bytes to skip.
	 */
	private function esc_skip_length( int $command ): int {
		$two_byte = array( 0x21, 0x2d, 0x33, 0x45, 0x4d, 0x74 );

		return \in_array( $command, $two_byte, true ) ? 2 : 1;
	}

	/**
	 * Simulate the printed text lines from ESC/POS bytes.
	 *
	 * @param string $bytes   The byte string.
	 * @param int    $columns The paper width in columns.
	 *
	 * @return array List of line descriptors.
	 */
	private function simulate_escpos_lines( string $bytes, int $columns ): array {
		$lines    = array();
		$raw_text = '';
		$length   = \strlen( $bytes );

		for ( $index = 0; $index < $length; $index++ ) {
			$byte = \ord( $bytes[ $index ] );
			if ( 0x1b === $byte ) {
				$command = isset( $bytes[ $index + 1 ] ) ? \ord( $bytes[ $index + 1 ] ) : 0;
				if ( 0x61 === $command ) {
					$index += 2;
					continue;
				}
				$index += $this->esc_skip_length( $command );
				continue;
			}
			if ( 0x1d === $byte ) {
				$index += 1 + $this->gs_command_skip_length( $bytes, $index );
				continue;
			}
			if ( 0x0d === $byte ) {
				continue;
			}
			if ( 0x0a === $byte ) {
				if ( '' !== $raw_text ) {
					$lines[]  = $this->describe_line( $raw_text );
					$raw_text = '';
				}
				continue;
			}
			if ( $byte >= 0x20 && $byte <= 0x7e ) {
				$raw_text .= \chr( $byte );
			}
		}

		if ( '' !== $raw_text ) {
			$lines[] = $this->describe_line( $raw_text );
		}

		return $lines;
	}

	/**
	 * Build a line descriptor from a raw text line.
	 *
	 * @param string $raw_text The raw accumulated line text.
	 *
	 * @return array The line descriptor.
	 */
	private function describe_line( string $raw_text ): array {
		$trimmed         = trim( $raw_text );
		$leading_matches = array();
		preg_match( '/^ */', $raw_text, $leading_matches );

		return array(
			'rawText'    => $raw_text,
			'text'       => $trimmed,
			'xStart'     => \strlen( $leading_matches[0] ),
			'textWidth'  => \strlen( $trimmed ),
			'lineWidth'  => \strlen( $raw_text ),
		);
	}

	/**
	 * Assert that a line of text is visually centered within the columns.
	 *
	 * @param array  $lines   The simulated line descriptors.
	 * @param string $text    The text to locate.
	 * @param int    $columns The paper width in columns.
	 *
	 * @return void
	 */
	private function expect_visually_centered( array $lines, string $text, int $columns ): void {
		$found = null;
		foreach ( $lines as $line ) {
			if ( $line['text'] === $text ) {
				$found = $line;
				break;
			}
		}
		$this->assertNotNull( $found, "Expected to find centered line: {$text}" );
		$actual_center = $found['xStart'] + ( $found['textWidth'] / 2 );
		$this->assertLessThanOrEqual( 0.5, abs( $actual_center - ( $columns / 2 ) ) );
	}

	/**
	 * Emit writes ESC @ at the very start and a partial/full cut command.
	 *
	 * @return void
	 */
	public function test_init_and_cut_emit_expected_command_bytes(): void {
		// Arrange.
		$partial = $this->render( '<receipt paper-width="48"><text>Hi</text><cut/></receipt>' );
		$full    = $this->render( '<receipt paper-width="48"><text>Hi</text><cut type="full"/></receipt>' );

		// Act / Assert.
		$this->assertEquals( 0x1b, \ord( $partial[0] ) );
		$this->assertEquals( 0x40, \ord( $partial[1] ) );
		$this->assertTrue( $this->includes_sequence( $partial, array( 0x1d, 0x56, 0x42 ) ) );
		$this->assertTrue( $this->includes_sequence( $full, array( 0x1d, 0x56, 0x41 ) ) );
	}

	/**
	 * Centered text emits the align byte, physical centering, and restore.
	 *
	 * @return void
	 */
	public function test_center_align_pads_and_restores(): void {
		// Arrange.
		$bytes = $this->render( '<receipt paper-width="48"><align mode="center"><text>Store Name</text></align></receipt>' );

		// Act.
		$lines = $this->simulate_escpos_lines( $bytes, 48 );

		// Assert.
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x61, 0x01 ) ) );
		$this->expect_visually_centered( $lines, 'Store Name', 48 );
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x61, 0x00 ) ) );
	}

	/**
	 * Bold, underline, and invert emit on/off command pairs.
	 *
	 * @return void
	 */
	public function test_inline_styles_emit_on_and_off(): void {
		// Arrange.
		$bytes = $this->render(
			'<receipt paper-width="48"><text><bold>B</bold><underline>U</underline><invert>I</invert></text></receipt>'
		);

		// Act / Assert.
		$bold_on  = $this->sequence_index( $bytes, array( 0x1b, 0x45, 0x01 ) );
		$bold_off = $this->sequence_index( $bytes, array( 0x1b, 0x45, 0x00 ), $bold_on + 1 );
		$this->assertGreaterThan( -1, $bold_on );
		$this->assertGreaterThan( $bold_on, $bold_off );

		$ul_on  = $this->sequence_index( $bytes, array( 0x1b, 0x2d, 0x01 ) );
		$ul_off = $this->sequence_index( $bytes, array( 0x1b, 0x2d, 0x00 ), $ul_on + 1 );
		$this->assertGreaterThan( -1, $ul_on );
		$this->assertGreaterThan( $ul_on, $ul_off );

		$inv_on  = $this->sequence_index( $bytes, array( 0x1d, 0x42, 0x01 ) );
		$inv_off = $this->sequence_index( $bytes, array( 0x1d, 0x42, 0x00 ), $inv_on + 1 );
		$this->assertGreaterThan( -1, $inv_on );
		$this->assertGreaterThan( $inv_on, $inv_off );
	}

	/**
	 * Double-size text emits GS ! and scaled line spacing around the line.
	 *
	 * @return void
	 */
	public function test_size_emits_gs_bang_and_scaled_spacing(): void {
		// Arrange.
		$bytes = $this->render( '<receipt paper-width="48"><text><size width="2" height="2">Big</size></text></receipt>' );

		// Act / Assert.
		$gs_bang = $this->sequence_index( $bytes, array( 0x1d, 0x21, 0x11 ) );
		$this->assertGreaterThan( -1, $gs_bang );

		$spacing_on  = $this->sequence_index( $bytes, array( 0x1b, 0x33 ) );
		$this->assertGreaterThan( -1, $spacing_on );
		$this->assertLessThan( $gs_bang, $spacing_on );

		$big = $this->sequence_index( $bytes, $this->ascii_bytes( 'Big' ) );
		$newline_after = $this->sequence_index( $bytes, array( 0x0a ), $big );
		$spacing_off   = $this->sequence_index( $bytes, array( 0x1b, 0x32 ), $newline_after );
		$this->assertGreaterThan( -1, $spacing_off );
	}

	/**
	 * Rows resolve star widths, flush right cells, and emit one line each.
	 *
	 * @return void
	 */
	public function test_row_layout_and_spacing(): void {
		// Arrange.
		$bytes = $this->render(
			'<receipt paper-width="48"><row><col width="*">Item</col><col width="10" align="right">$9.99</col></row></receipt>'
		);

		// Act.
		$lines = $this->simulate_escpos_lines( $bytes, 48 );

		// Assert.
		$this->assertCount( 1, $lines );
		$this->assertEquals( 'Item', substr( $lines[0]['text'], 0, 4 ) );
		$this->assertEquals( '$9.99', substr( $lines[0]['rawText'], -5 ) );
		$this->assertEquals( 48, $lines[0]['lineWidth'] );

		// Two adjacent rows: exactly one 0x0A between them (no blank line).
		$two = $this->render(
			'<receipt paper-width="48"><row><col width="*">A</col></row><row><col width="*">B</col></row></receipt>'
		);
		$two_lines = $this->simulate_escpos_lines( $two, 48 );
		$this->assertCount( 2, $two_lines );

		// Left cell leading spaces preserved.
		$indented       = $this->render( '<receipt paper-width="48"><row><col width="*">  3 x 5</col></row></receipt>' );
		$indented_lines = $this->simulate_escpos_lines( $indented, 48 );
		$this->assertEquals( '  3 x 5', substr( $indented_lines[0]['rawText'], 0, 7 ) );
	}

	/**
	 * Lines render ASCII rules without CP437 box-drawing bytes.
	 *
	 * @return void
	 */
	public function test_line_styles_render_ascii_rules(): void {
		// Arrange.
		$single = $this->render( '<receipt paper-width="48"><line/></receipt>' );
		$dotted = $this->render( '<receipt paper-width="48"><line style="dotted"/></receipt>' );
		$double = $this->render( '<receipt paper-width="48"><line style="double"/></receipt>' );

		// Act / Assert.
		$this->assertTrue( $this->includes_sequence( $single, $this->ascii_bytes( str_repeat( '-', 48 ) ) ) );
		$this->assertFalse( $this->includes_sequence( $single, array( 0xc4 ) ) );
		$this->assertFalse( $this->includes_sequence( $single, array( 0xcd ) ) );
		$this->assertTrue( $this->includes_sequence( $dotted, $this->ascii_bytes( '. . ' ) ) );
		$this->assertTrue( $this->includes_sequence( $double, $this->ascii_bytes( str_repeat( '=', 48 ) ) ) );
	}

	/**
	 * Feed emits LF per line and drawer emits the pulse command.
	 *
	 * @return void
	 */
	public function test_feed_and_drawer(): void {
		// Arrange.
		$bytes = $this->render( '<receipt paper-width="48"><feed lines="2"/><drawer/></receipt>' );

		// Act / Assert.
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x0a, 0x0a ) ) );
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x70, 0x00, 0x19, 0xfa ) ) );
	}

	/**
	 * Barcode and QR code emit native command sequences with their data.
	 *
	 * @return void
	 */
	public function test_barcode_and_qrcode_native_sequences(): void {
		// Arrange.
		$barcode = $this->render( '<receipt paper-width="48"><barcode type="code128" height="60">ABC-123</barcode></receipt>' );
		$qrcode  = $this->render( '<receipt paper-width="48"><qrcode size="4">XYZ</qrcode></receipt>' );

		// Act / Assert.
		$this->assertTrue( $this->includes_sequence( $barcode, array( 0x1d, 0x6b ) ) );
		$this->assertTrue( $this->includes_sequence( $barcode, array( 0x1d, 0x68 ) ) );
		$this->assertTrue( $this->includes_sequence( $barcode, $this->ascii_bytes( 'ABC-123' ) ) );

		$this->assertTrue( $this->includes_sequence( $qrcode, array( 0x1d, 0x28, 0x6b ) ) );
		$this->assertTrue( $this->includes_sequence( $qrcode, $this->ascii_bytes( 'XYZ' ) ) );
	}

	/**
	 * Images are skipped and emit no raster command.
	 *
	 * @return void
	 */
	public function test_image_is_skipped(): void {
		// Arrange.
		$bytes = $this->render(
			'<receipt paper-width="48"><text>Before</text><image src="x" width="64"/><text>After</text></receipt>'
		);

		// Act.
		$printable = $this->decode_printable_ascii( $bytes );

		// Assert.
		$this->assertFalse( $this->includes_sequence( $bytes, array( 0x1d, 0x76, 0x30 ) ) );
		$this->assertStringContainsString( 'Before', $printable );
		$this->assertStringContainsString( 'After', $printable );
	}
}
