<?php
/**
 * Tests for the StarPRNT thermal emitter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Starprnt_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Starprnt_Thermal_Emitter_Test class.
 */
class Starprnt_Thermal_Emitter_Test extends WP_UnitTestCase {

	/**
	 * Markup parser instance.
	 *
	 * @var Thermal_Markup_Parser
	 */
	private $parser;

	/**
	 * Emitter under test.
	 *
	 * @var Starprnt_Thermal_Emitter
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
		$this->emitter = new Starprnt_Thermal_Emitter();
	}

	/**
	 * Emit StarPRNT bytes for a markup string.
	 *
	 * @param string $xml The thermal markup.
	 *
	 * @return string Raw StarPRNT bytes.
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
	 * It starts with the UTF-8 encoding preamble instead of an initialize command.
	 *
	 * CloudPRNT jobs must not re-initialize the device (ESC @ resets the printer
	 * mid-session), and the UTF-8 select sequence is required so non-ASCII text
	 * (e.g. German umlauts) decodes correctly on StarPRNT printers.
	 */
	public function test_emit_starts_with_utf8_preamble_and_no_initialize(): void {
		$bytes = $this->render( '<receipt><text>Hi</text></receipt>' );

		// ESC GS ) U — select UTF-8, then font/width setting (Star reference sequence).
		$this->assertSame( 0, $this->sequence_index( $bytes, array( 0x1b, 0x1d, 0x29, 0x55, 0x02, 0x00, 0x30, 0x01 ) ) );
		$this->assertSame( 8, $this->sequence_index( $bytes, array( 0x1b, 0x1d, 0x29, 0x55, 0x02, 0x00, 0x40, 0x00 ) ) );
		// No ESC @ initialize anywhere in the job.
		$this->assertFalse( $this->includes_sequence( $bytes, array( 0x1b, 0x40 ) ) );
	}

	/**
	 * It emits text lines terminated by a newline.
	 */
	public function test_emit_text_line_appends_newline(): void {
		$bytes = $this->render( '<receipt><text>Hello</text></receipt>' );

		$this->assertStringContainsString( "Hello\x0a", $bytes );
	}

	/**
	 * It wraps bold children in ESC E / ESC F.
	 */
	public function test_emit_bold_uses_esc_e_and_esc_f(): void {
		$bytes = $this->render( '<receipt><text><bold>B</bold></text></receipt>' );

		$on  = $this->sequence_index( $bytes, array( 0x1b, 0x45 ) );
		$off = $this->sequence_index( $bytes, array( 0x1b, 0x46 ) );
		$this->assertGreaterThan( -1, $on );
		$this->assertGreaterThan( $on, $off );
	}

	/**
	 * It wraps underline children in ESC - 1 / ESC - 0.
	 */
	public function test_emit_underline_uses_esc_dash(): void {
		$bytes = $this->render( '<receipt><text><underline>U</underline></text></receipt>' );

		$on  = $this->sequence_index( $bytes, array( 0x1b, 0x2d, 0x01 ) );
		$off = $this->sequence_index( $bytes, array( 0x1b, 0x2d, 0x00 ) );
		$this->assertGreaterThan( -1, $on );
		$this->assertGreaterThan( $on, $off );
	}

	/**
	 * It wraps invert children in ESC 4 / ESC 5.
	 */
	public function test_emit_invert_uses_esc_4_and_esc_5(): void {
		$bytes = $this->render( '<receipt><text><invert>I</invert></text></receipt>' );

		$on  = $this->sequence_index( $bytes, array( 0x1b, 0x34 ) );
		$off = $this->sequence_index( $bytes, array( 0x1b, 0x35 ) );
		$this->assertGreaterThan( -1, $on );
		$this->assertGreaterThan( $on, $off );
	}

	/**
	 * It sets and restores alignment with ESC GS a.
	 */
	public function test_emit_align_center_uses_esc_gs_a(): void {
		$bytes = $this->render( '<receipt><align mode="center"><text>C</text></align></receipt>' );

		$center  = $this->sequence_index( $bytes, array( 0x1b, 0x1d, 0x61, 0x01 ) );
		$restore = $this->sequence_index( $bytes, array( 0x1b, 0x1d, 0x61, 0x00 ), $center + 1 );
		$this->assertGreaterThan( -1, $center );
		$this->assertGreaterThan( $center, $restore );
	}

	/**
	 * It sets magnification with ESC i (height, width) and restores it.
	 */
	public function test_emit_size_uses_esc_i_height_width(): void {
		$bytes = $this->render( '<receipt><size width="2" height="3"><text>S</text></size></receipt>' );

		$set     = $this->sequence_index( $bytes, array( 0x1b, 0x69, 0x02, 0x01 ) );
		$restore = $this->sequence_index( $bytes, array( 0x1b, 0x69, 0x00, 0x00 ), $set + 1 );
		$this->assertGreaterThan( -1, $set );
		$this->assertGreaterThan( $set, $restore );
	}

	/**
	 * It clamps magnification to the StarPRNT maximum of 6x.
	 */
	public function test_emit_size_clamps_magnification_to_six(): void {
		$bytes = $this->render( '<receipt><size width="9" height="9"><text>S</text></size></receipt>' );

		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x69, 0x05, 0x05 ) ) );
	}

	/**
	 * It emits a partial cut by default and a full cut when requested.
	 */
	public function test_emit_cut_uses_esc_d_feed_variants(): void {
		$partial = $this->render( '<receipt><cut/></receipt>' );
		$full    = $this->render( '<receipt><cut type="full"/></receipt>' );

		$this->assertTrue( $this->includes_sequence( $partial, array( 0x1b, 0x64, 0x03 ) ) );
		$this->assertTrue( $this->includes_sequence( $full, array( 0x1b, 0x64, 0x02 ) ) );
	}

	/**
	 * It feeds the requested number of lines.
	 */
	public function test_emit_feed_emits_newlines(): void {
		$bytes = $this->render( '<receipt><feed lines="3"/></receipt>' );

		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x0a, 0x0a, 0x0a ) ) );
	}

	/**
	 * It fires the default drawer connector via ESC BEL + device trigger.
	 */
	public function test_emit_drawer_pulses_device_one_by_default(): void {
		$bytes = $this->render( '<receipt><drawer/></receipt>' );

		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x07, 0x0a, 0x0a, 0x07 ) ) );
	}

	/**
	 * It emits a CODE128 barcode via ESC b terminated by RS.
	 */
	public function test_emit_barcode_uses_esc_b_with_rs_terminator(): void {
		$bytes = $this->render( '<receipt><barcode type="code128" height="40">HELLO</barcode></receipt>' );

		$start = $this->sequence_index( $bytes, array( 0x1b, 0x62, 0x06, 0x01, 0x02, 0x28 ) );
		$this->assertGreaterThan( -1, $start );
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x48, 0x45, 0x4c, 0x4c, 0x4f, 0x1e ) ) );
	}

	/**
	 * It emits a QR code via the ESC GS y command family.
	 */
	public function test_emit_qrcode_uses_esc_gs_y_commands(): void {
		$bytes = $this->render( '<receipt><qrcode size="4">https://wcpos.com</qrcode></receipt>' );

		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x1d, 0x79, 0x53, 0x30, 0x02 ) ) ); // Model 2.
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x1d, 0x79, 0x53, 0x31, 0x01 ) ) ); // EC level M.
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x1d, 0x79, 0x53, 0x32, 0x04 ) ) ); // Cell size 4.
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x1d, 0x79, 0x44, 0x31, 0x00, 17, 0x00 ) ) ); // Data, length 17.
		$this->assertTrue( $this->includes_sequence( $bytes, array( 0x1b, 0x1d, 0x79, 0x50 ) ) ); // Print.
	}

	/**
	 * It renders rows as padded plain text columns.
	 */
	public function test_emit_row_pads_columns(): void {
		$bytes = $this->render(
			'<receipt paper-width="12"><row><col width="6">A</col><col width="6" align="right">B</col></row></receipt>'
		);

		$this->assertStringContainsString( "A          B\x0a", $bytes );
	}

	/**
	 * It computes Unicode display width when mbstring is unavailable.
	 */
	public function test_emit_aligned_unicode_without_mbstring_succeeds(): void {
		$script = <<<'PHP'
<?php
require getcwd() . '/includes/Templates/Thermal/Starprnt_Thermal_Emitter.php';
$emitter = new \WCPOS\WooCommercePOS\Templates\Thermal\Starprnt_Thermal_Emitter();
echo base64_encode(
	$emitter->emit(
		array(
			'paper_width' => 4,
			'children'    => array(
				array(
					'type'     => 'align',
					'mode'     => 'center',
					'children' => array(
						array(
							'type'     => 'text',
							'children' => array( array( 'type' => 'raw-text', 'value' => '漢' ) ),
						),
					),
				),
			),
		)
	)
		);
PHP;
		$process = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=mb_ord,mb_convert_encoding' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( __DIR__, 4 )
		);

		$this->assertIsResource( $process );
		fwrite( $pipes[0], $script );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), $errors );
		$this->assertStringContainsString( '漢', base64_decode( $output ) );
	}

	/**
	 * It renders rule lines from the paper width.
	 */
	public function test_emit_line_repeats_rule_characters(): void {
		$single = $this->render( '<receipt paper-width="8"><line/></receipt>' );
		$double = $this->render( '<receipt paper-width="8"><line style="double"/></receipt>' );

		$this->assertStringContainsString( str_repeat( '-', 8 ) . "\x0a", $single );
		$this->assertStringContainsString( str_repeat( '=', 8 ) . "\x0a", $double );
	}

	/**
	 * It skips image nodes entirely.
	 */
	public function test_emit_image_is_skipped(): void {
		$with    = $this->render( '<receipt><image src="logo.png"/><text>T</text></receipt>' );
		$without = $this->render( '<receipt><text>T</text></receipt>' );

		$this->assertSame( $without, $with );
	}

	/**
	 * It appends an auto-drawer pulse when the option is enabled.
	 */
	public function test_emit_auto_open_drawer_appends_pulse_before_cut(): void {
		$emitter = new Starprnt_Thermal_Emitter(
			array(
				'auto_open_drawer' => true,
				'drawer_connector' => 'pin5',
			)
		);
		$bytes   = $emitter->emit( $this->parser->parse( '<receipt><text>T</text><cut/></receipt>' ) );

		$drawer = $this->sequence_index( $bytes, array( 0x1b, 0x07, 0x0a, 0x0a, 0x1a ) );
		$cut    = $this->sequence_index( $bytes, array( 0x1b, 0x64, 0x03 ) );
		$this->assertGreaterThan( -1, $drawer );
		$this->assertGreaterThan( $drawer, $cut );
	}

	/**
	 * It normalizes typographic characters to ASCII equivalents.
	 */
	public function test_emit_normalizes_typographic_characters(): void {
		$bytes = $this->render( "<receipt><text>a\u{2014}b\u{2019}c</text></receipt>" );

		$this->assertStringContainsString( "a-b'c", $bytes );
	}
}
