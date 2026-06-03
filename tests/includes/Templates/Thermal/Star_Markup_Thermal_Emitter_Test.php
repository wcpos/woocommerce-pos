<?php
/**
 * Star Document Markup emitter tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

use WCPOS\WooCommercePOS\Templates\Thermal\Star_Markup_Thermal_Emitter;

/**
 * @internal
 *
 * @coversNothing
 */
class Star_Markup_Thermal_Emitter_Test extends WP_UnitTestCase {
	private function emit( array $children ): string {
		$ast = array( 'type' => 'receipt', 'paper_width' => 48, 'children' => $children );

		return ( new Star_Markup_Thermal_Emitter() )->emit( $ast );
	}

	public function test_centered_bold_text(): void {
		$out = $this->emit( array(
			array( 'type' => 'align', 'mode' => 'center', 'children' => array(
				array( 'type' => 'bold', 'children' => array(
					array( 'type' => 'text', 'children' => array(
						array( 'type' => 'raw-text', 'value' => 'WCPOS' ),
					) ),
				) ),
			) ),
		) );

		$this->assertStringContainsString( '[align: middle]', $out );
		$this->assertStringContainsString( '[bold: on]WCPOS[bold: off]', $out );
		$this->assertStringContainsString( '[align: left]', $out );
	}

	public function test_barcode_and_qr(): void {
		$out = $this->emit( array(
			array( 'type' => 'barcode', 'barcode_type' => 'code128', 'height' => 40, 'value' => 'ABC123' ),
			array( 'type' => 'qrcode', 'size' => 4, 'value' => 'https://x.test' ),
		) );

		$this->assertStringContainsString( '[barcode: type code128; data "ABC123"', $out );
		$this->assertStringContainsString( '[barcode: type qr; data "https://x.test"', $out );
	}

	public function test_cut_and_feed(): void {
		$out = $this->emit( array(
			array( 'type' => 'feed', 'lines' => 2 ),
			array( 'type' => 'cut', 'cut_type' => 'partial' ),
		) );
		$this->assertStringContainsString( '[feed]', $out );
		$this->assertStringContainsString( '[cut]', $out );
	}

	public function test_logo_public_url_emitted(): void {
		$out = $this->emit( array(
			array( 'type' => 'image', 'src' => 'https://store.test/logo.png', 'width' => 300 ),
		) );
		$this->assertStringContainsString( '[image: url https://store.test/logo.png;', $out );
	}

	public function test_logo_data_uri_skipped(): void {
		$out = $this->emit( array(
			array( 'type' => 'image', 'src' => 'data:image/png;base64,AAAA', 'width' => 300 ),
		) );
		$this->assertStringNotContainsString( '[image', $out );
	}

	public function test_relative_logo_resolved_to_absolute(): void {
		$out = $this->emit( array(
			array( 'type' => 'image', 'src' => '/wp-content/logo.png', 'width' => 300 ),
		) );
		$this->assertStringContainsString( '[image: url ' . home_url( '/wp-content/logo.png' ) . ';', $out );
	}
}
