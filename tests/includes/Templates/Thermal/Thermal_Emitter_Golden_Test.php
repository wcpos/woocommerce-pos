<?php
/**
 * Golden (characterisation) tests for the PHP thermal emitters.
 *
 * These do not assert that the output is correct — they assert that it has not
 * changed. Their job is to make a text-layout refactor provably byte-neutral on
 * the lanes it must not touch, and to force any behaviour change onto the
 * reviewable diff of tests/fixtures/thermal/golden/.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Epos_Xml_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Escpos_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Star_Markup_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Starprnt_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Text_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

require_once __DIR__ . '/Thermal_Golden_Corpus.php';

/**
 * Thermal_Emitter_Golden_Test class.
 */
class Thermal_Emitter_Golden_Test extends WP_UnitTestCase {

	/**
	 * Every (lane, case) pair in the corpus.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function lane_case_provider(): array {
		$rows = array();
		foreach ( Thermal_Golden_Corpus::lanes() as $lane ) {
			foreach ( array_keys( Thermal_Golden_Corpus::cases() ) as $case_name ) {
				$rows[ $lane . ' / ' . $case_name ] = array( $lane, $case_name );
			}
		}

		return $rows;
	}

	/**
	 * It emits byte-identical output to the committed golden fixture.
	 *
	 * @dataProvider lane_case_provider
	 *
	 * @param string $lane      The emitter lane key.
	 * @param string $case_name The corpus case name.
	 *
	 * @return void
	 */
	public function test_emitter_output_matches_committed_golden( string $lane, string $case_name ): void {
		// Arrange.
		$path   = Thermal_Golden_Corpus::golden_path( $lane, $case_name );
		$update = '1' === (string) getenv( 'WCPOS_UPDATE_GOLDEN' );

		// Act.
		$actual = Thermal_Golden_Corpus::encode( $lane, $case_name );

		if ( $update ) {
			file_put_contents( $path, $actual );
			$this->markTestSkipped( 'WCPOS_UPDATE_GOLDEN=1 — rewrote ' . $path . ' instead of asserting.' );
		}

		// Assert.
		$this->assertFileExists( $path, 'Missing golden fixture; run pnpm run goldens:thermal' );

		$expected = (string) file_get_contents( $path );
		$this->assertSame(
			$expected,
			$actual,
			sprintf(
				"Thermal output drifted for %s / %s.\nExpected:\n%s\nActual:\n%s\nIf the change is intended, run pnpm run goldens:thermal and review the diff.",
				$lane,
				$case_name,
				$this->readable( $expected ),
				$this->readable( $actual )
			)
		);
	}

	/**
	 * It never tells anyone to regenerate the goldens under a host PHP.
	 *
	 * The fixtures under tests/fixtures/thermal/golden/ are committed bytes, and this
	 * class asserts them byte-for-byte from inside wp-env. Generating them anywhere
	 * else makes the generating machine's PHP an input to a file in git — the text
	 * metrics reach mbstring through mb_str_split() and mb_ord() — so the two must
	 * share one runtime. AGENTS.md states the rule for the repo: PHP tests run through
	 * Docker/wp-env, with no local fallback.
	 *
	 * The regression this guards is a documentation one, which is exactly why it needs
	 * a test: the generator ran happily under a bare `php`, so nothing except the
	 * instructions ever pointed at the container.
	 *
	 * @return void
	 */
	public function test_golden_regeneration_is_only_ever_documented_as_a_wp_env_run(): void {
		// Arrange: assembled from parts so this file cannot match its own needle.
		$generator   = 'tests/bin/regenerate-thermal-goldens.php';
		$bare_run    = 'php ' . $generator;
		$wp_env_run  = 'tests-cli -- ' . $bare_run;
		$plugin_root = \dirname( __DIR__, 4 );
		$documents   = array(
			'package.json',
			$generator,
			'tests/includes/Templates/Thermal/Thermal_Golden_Corpus.php',
			'tests/includes/Templates/Thermal/Thermal_Emitter_Golden_Test.php',
		);

		// Act: collect every invocation that is not handed to the wp-env container.
		$bare_invocations = array();
		foreach ( $documents as $document ) {
			$path = $plugin_root . '/' . $document;
			$this->assertFileExists( $path );

			$lines = explode( "\n", (string) file_get_contents( $path ) );
			foreach ( $lines as $offset => $line ) {
				if ( false === strpos( $line, $bare_run ) ) {
					continue;
				}
				if ( false !== strpos( $line, $wp_env_run ) ) {
					continue;
				}
				$bare_invocations[] = $document . ':' . ( $offset + 1 );
			}
		}

		// Assert.
		$this->assertSame(
			array(),
			$bare_invocations,
			'The golden generator is documented as a host-PHP run, which would let the '
				. 'generating machine bake its own PHP build into committed fixtures. Route it '
				. 'through wp-env (pnpm run goldens:thermal) at: ' . implode( ', ', $bare_invocations )
		);
	}

	/**
	 * It measures U+FFE5 FULLWIDTH YEN SIGN as two columns on every emitter.
	 *
	 * A Japanese receipt total is the realistic case: the Star Markup lane used
	 * to count the sign as one column, so a row padded around it landed one
	 * character to the right of every other lane.
	 *
	 * @return void
	 */
	public function test_display_width_fullwidth_yen_sign_is_two_columns_on_every_emitter(): void {
		// Arrange: a six-column cell holding one full-width yen sign. At two
		// columns wide it is followed by exactly four spaces before the next cell.
		$markup = '<receipt paper-width="32"><row>'
			. '<col width="6">' . "\u{FFE5}" . '</col>'
			. '<col width="6">END</col>'
			. '</row></receipt>';

		foreach ( Thermal_Golden_Corpus::lanes() as $lane ) {
			// Act.
			$output = $this->render_markup( $lane, $markup );

			// Assert.
			$this->assertStringContainsString(
				"\u{FFE5}" . '    END',
				$output,
				$lane . ' measured the full-width yen sign as one column.'
			);
		}
	}

	/**
	 * It packs a starred row into identical columns on every emitter.
	 *
	 * The star split itself is arithmetic and already agreed; what differed was
	 * the display width fed into it, so the row content is full-width text long
	 * enough to be truncated as well as padded.
	 *
	 * @return void
	 */
	public function test_row_column_widths_are_identical_across_every_emitter(): void {
		// Arrange: the starred cell resolves to 22 columns and its content is 32
		// columns wide, so the packed line exposes the truncation point, the pad
		// width, and the right-aligned cell's own measurement.
		$markup = '<receipt paper-width="32"><row>'
			. '<col width="*">日本語テスト商品名ですこれは長い</col>'
			. '<col width="10" align="right">' . "\u{FFE5}" . '1,234</col>'
			. '</row></receipt>';

		$expected = '日本語テスト商品名です   ' . "\u{FFE5}" . '1,234';

		foreach ( Thermal_Golden_Corpus::lanes() as $lane ) {
			// Act.
			$output = $this->render_markup( $lane, $markup );

			// Assert.
			$this->assertStringContainsString(
				$expected,
				$output,
				$lane . ' packed the starred row differently from the other emitters.'
			);
		}
	}

	/**
	 * Render a markup document on one lane.
	 *
	 * @param string $lane   The emitter lane key.
	 * @param string $markup The full thermal markup document.
	 *
	 * @return string The raw emitter output.
	 */
	private function render_markup( string $lane, string $markup ): string {
		$parser = new Thermal_Markup_Parser();
		$ast    = $parser->parse( $markup );

		switch ( $lane ) {
			case 'escpos':
				return ( new Escpos_Thermal_Emitter() )->emit( $ast );
			case 'starprnt':
				return ( new Starprnt_Thermal_Emitter() )->emit( $ast );
			case 'epos-xml':
				return ( new Epos_Xml_Thermal_Emitter() )->emit( $ast );
			case 'text':
				return ( new Text_Thermal_Emitter() )->emit( $ast );
		}

		return ( new Star_Markup_Thermal_Emitter() )->emit( $ast );
	}

	/**
	 * Decode a golden body into a printable form for failure messages.
	 *
	 * @param string $body The golden file body.
	 *
	 * @return string
	 */
	private function readable( string $body ): string {
		$out = '';
		foreach ( Thermal_Golden_Corpus::decode( $body ) as $width => $segments ) {
			$out .= '  [' . $width . '] ' . implode( "\n  |   ", array_map( array( $this, 'printable' ), $segments ) ) . "\n";
		}

		return $out;
	}

	/**
	 * Replace control bytes with visible escapes.
	 *
	 * @param string $segment One emitted line.
	 *
	 * @return string
	 */
	private function printable( string $segment ): string {
		return (string) preg_replace_callback(
			'/[\x00-\x1f\x7f]/',
			static function ( array $match ): string {
				return sprintf( '\x%02x', \ord( $match[0] ) );
			},
			$segment
		);
	}
}
