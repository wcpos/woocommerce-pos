<?php
/**
 * Golden-fixture corpus for the PHP thermal emitters.
 *
 * This is a characterisation harness, not a correctness oracle: it pins the
 * exact bytes each emitter produces for a fixed set of markup documents at a
 * fixed set of paper widths, so that a refactor of the shared text-layout
 * helpers can be proven byte-neutral and any behaviour change shows up as a
 * reviewable diff.
 *
 * Goldens are stored one file per lane per case under
 * `tests/fixtures/thermal/golden/{lane}/{case}.txt`, with one base64 token per
 * emitted line so the files stay git-diffable at line granularity.
 *
 * Regenerate with either of:
 *
 *   pnpm run goldens:thermal
 *   WCPOS_UPDATE_GOLDEN=1 <wp-env phpunit invocation for Thermal_Emitter_Golden_Test>
 *
 * Both run inside wp-env, which is the point: these fixtures are committed bytes
 * asserted by a suite that runs in that container, so generating them under a host
 * PHP would make the host's mbstring build an input to a file in git.
 *
 * Never enable WCPOS_UPDATE_GOLDEN in CI — a self-regenerating golden asserts
 * nothing.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use InvalidArgumentException;
use WCPOS\WooCommercePOS\Templates\Thermal\Epos_Xml_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Escpos_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Star_Markup_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Starprnt_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Text_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;

/**
 * Thermal_Golden_Corpus class.
 */
final class Thermal_Golden_Corpus {

	/**
	 * Paper widths every case is rendered at.
	 *
	 * 32 and 42 are the common 58 mm and 76 mm column counts; 48 is the 80 mm
	 * default used by Thermal_Markup_Parser when `paper-width` is absent.
	 */
	const PAPER_WIDTHS = array( 32, 42, 48 );

	/**
	 * Token written in place of an empty emitted line.
	 *
	 * `~` is outside the base64 alphabet, so it can never collide with real
	 * encoded content, and it keeps the file free of ambiguous blank lines.
	 */
	const EMPTY_LINE = '~';

	/**
	 * Emitter lane keys, matching the golden sub-directory names.
	 *
	 * @return array<int, string>
	 */
	public static function lanes(): array {
		return array( 'escpos', 'starprnt', 'epos-xml', 'star-markup', 'text' );
	}

	/**
	 * The markup corpus.
	 *
	 * Deliberately excludes barcode and QR nodes: those are owned by the
	 * barcode-symbology work and pinning them here would collide with it.
	 *
	 * @return array<string, array{markup: string, options: array}>
	 */
	public static function cases(): array {
		return array(
			'plain-text'          => array(
				'markup'  => '
					<text>WCPOS Store</text>
					<text>123 Example Street</text>
					<text>Thank you for shopping with us</text>
				',
				'options' => array(),
			),
			'align'               => array(
				'markup'  => '
					<align mode="center"><text>CENTERED</text></align>
					<align mode="right"><text>RIGHT</text></align>
					<align mode="left"><text>LEFT</text></align>
				',
				'options' => array(),
			),
			'nested-styles'       => array(
				'markup'  => '
					<text><bold>Bold <underline>plus underline <invert>plus invert</invert></underline></bold> plain</text>
					<text><underline><bold>Reordered wrappers</bold></underline></text>
				',
				'options' => array(),
			),
			'size-multipliers'    => array(
				'markup'  => '
					<size width="2" height="2"><text>DOUBLE</text></size>
					<size width="3" height="3"><text>TRIPLE</text></size>
					<size width="2" height="1"><text>WIDE ONLY</text></size>
					<size width="1" height="3"><text>TALL ONLY</text></size>
				',
				'options' => array(),
			),
			'row-star-widths'     => array(
				'markup'  => '
					<row>
						<col width="*">A long product name that will not fit</col>
						<col width="4" align="right">2</col>
						<col width="9" align="right">12.34</col>
					</row>
					<row>
						<col width="*">alpha</col>
						<col width="*">beta</col>
						<col width="*">gamma</col>
					</row>
				',
				'options' => array(),
			),
			'rules'               => array(
				'markup'  => '
					<line style="single"/>
					<line style="double"/>
					<line style="dashed"/>
					<line style="dotted"/>
				',
				'options' => array(),
			),
			'feed-and-cut'        => array(
				'markup'  => '
					<text>Before the cut</text>
					<feed lines="3"/>
					<cut type="partial"/>
					<cut type="full"/>
				',
				'options' => array(),
			),
			'auto-drawer-pin2'    => array(
				'markup'  => '
					<text>Total 10.00</text>
					<feed lines="2"/>
					<cut type="partial"/>
				',
				'options' => array(
					'auto_open_drawer' => true,
					'drawer_connector' => 'pin2',
				),
			),
			'auto-drawer-pin5'    => array(
				'markup'  => '
					<text>Total 10.00</text>
					<feed lines="2"/>
					<cut type="partial"/>
				',
				'options' => array(
					'auto_open_drawer' => true,
					'drawer_connector' => 'pin5',
				),
			),
			'explicit-drawer'     => array(
				'markup'  => '
					<text>Total 10.00</text>
					<drawer/>
					<cut type="partial"/>
				',
				'options' => array(
					'auto_open_drawer' => true,
					'drawer_connector' => 'pin5',
				),
			),
			'cjk-and-typography'  => array(
				// U+FFE5 FULLWIDTH YEN SIGN is the character the Star Markup lane
				// used to measure as one column while every other lane measured two.
				'markup'  => '
					<align mode="center"><text>ご来店ありがとうございます</text></align>
					<text>Total ' . "\u{FFE5}" . '1,234</text>
					<row>
						<col width="*">' . "\u{FFE5}" . ' 日本語テスト 商品名</col>
						<col width="10" align="right">' . "\u{FFE5}" . '1,234</col>
					</row>
					<row>
						<col width="*">한글 상품</col>
						<col width="10" align="right">' . "\u{FFE5}" . '9</col>
					</row>
					<text>' . "\u{201C}" . 'Smart quotes' . "\u{201D}" . ' ' . "\u{2014}" . ' en' . "\u{2013}" . 'dash, non' . "\u{2011}" . 'breaking' . "\u{00A0}" . 'space, ' . "\u{2018}" . 'single' . "\u{2019}" . '</text>
				',
				'options' => array(),
			),
		);
	}

	/**
	 * Absolute path of the golden fixture root.
	 *
	 * @return string
	 */
	public static function fixture_dir(): string {
		return \dirname( __DIR__, 3 ) . '/fixtures/thermal/golden';
	}

	/**
	 * Absolute path of one golden file.
	 *
	 * @param string $lane      The emitter lane key.
	 * @param string $case_name The corpus case name.
	 *
	 * @return string
	 */
	public static function golden_path( string $lane, string $case_name ): string {
		return self::fixture_dir() . '/' . $lane . '/' . $case_name . '.txt';
	}

	/**
	 * Render one case on one lane at one paper width.
	 *
	 * @param string $lane        The emitter lane key.
	 * @param string $case_name   The corpus case name.
	 * @param int    $paper_width The paper width in columns.
	 *
	 * @throws InvalidArgumentException When the lane or case is unknown.
	 *
	 * @return string The raw emitter output.
	 */
	public static function render( string $lane, string $case_name, int $paper_width ): string {
		$cases = self::cases();
		if ( ! isset( $cases[ $case_name ] ) ) {
			throw new InvalidArgumentException( 'Unknown thermal golden case: ' . $case_name );
		}

		$markup  = '<receipt paper-width="' . $paper_width . '">' . $cases[ $case_name ]['markup'] . '</receipt>';
		$ast     = ( new Thermal_Markup_Parser() )->parse( $markup );
		$options = $cases[ $case_name ]['options'];

		switch ( $lane ) {
			case 'escpos':
				return ( new Escpos_Thermal_Emitter( $options ) )->emit( $ast );
			case 'starprnt':
				return ( new Starprnt_Thermal_Emitter( $options ) )->emit( $ast );
			case 'epos-xml':
				return ( new Epos_Xml_Thermal_Emitter( $options ) )->emit( $ast );
			case 'star-markup':
				// The Star Markup emitter has no options constructor; the Star Online
				// provider drives the drawer out of band.
				return ( new Star_Markup_Thermal_Emitter() )->emit( $ast );
			case 'text':
				// Peripherals move to the CloudPRNT response headers on this lane, so
				// the drawer options change cut_type()/drawer() rather than the bytes.
				return ( new Text_Thermal_Emitter( $options ) )->emit( $ast );
		}

		throw new InvalidArgumentException( 'Unknown thermal emitter lane: ' . $lane );
	}

	/**
	 * Build the golden file body for one lane and case.
	 *
	 * @param string $lane      The emitter lane key.
	 * @param string $case_name The corpus case name.
	 *
	 * @return string The golden file body.
	 */
	public static function encode( string $lane, string $case_name ): string {
		$body = '# ' . $case_name . ' :: ' . $lane . "\n";

		foreach ( self::PAPER_WIDTHS as $paper_width ) {
			$body .= '## paper-width ' . $paper_width . "\n";
			foreach ( explode( "\n", self::render( $lane, $case_name, $paper_width ) ) as $segment ) {
				$body .= ( '' === $segment ? self::EMPTY_LINE : base64_encode( $segment ) ) . "\n";
			}
		}

		return $body;
	}

	/**
	 * Decode a golden file body back into raw segments, keyed by paper width.
	 *
	 * Used only to render a human-readable failure message; the assertion itself
	 * compares the encoded bodies.
	 *
	 * @param string $body The golden file body.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function decode( string $body ): array {
		$decoded = array();
		$width   = '';

		foreach ( explode( "\n", $body ) as $line ) {
			if ( '' === $line || 0 === strpos( $line, '# ' ) ) {
				continue;
			}
			if ( 0 === strpos( $line, '## paper-width ' ) ) {
				$width             = substr( $line, \strlen( '## paper-width ' ) );
				$decoded[ $width ] = array();
				continue;
			}
			$decoded[ $width ][] = self::EMPTY_LINE === $line ? '' : (string) base64_decode( $line, true );
		}

		return $decoded;
	}

	/**
	 * Write every golden file for every lane and case.
	 *
	 * @return array<int, string> The paths written.
	 */
	public static function write_all(): array {
		$written = array();

		foreach ( self::lanes() as $lane ) {
			$dir = self::fixture_dir() . '/' . $lane;
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			foreach ( array_keys( self::cases() ) as $case_name ) {
				$path = self::golden_path( $lane, $case_name );
				file_put_contents( $path, self::encode( $lane, $case_name ) );
				$written[] = $path;
			}
		}

		return $written;
	}
}
