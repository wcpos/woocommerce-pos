<?php
/**
 * Cloud print diagnostic tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Diagnostic;
use WP_UnitTestCase;

/**
 * Cloud_Print_Diagnostic_Test class.
 */
class Cloud_Print_Diagnostic_Test extends WP_UnitTestCase {
	/**
	 * It builds a Star diagnostic as ESC/POS bytes.
	 */
	public function test_build_star_diagnostic_is_escpos_bytes(): void {
		$diag = ( new Cloud_Print_Diagnostic() )->build( 'star-cloudprnt', 'Kitchen' );
		$this->assertEquals( 'application/octet-stream', $diag['content_type'] );
		$bytes = base64_decode( $diag['payload'], true );
		$this->assertStringContainsString( 'WCPOS', $bytes );
		$this->assertStringContainsString( 'Kitchen', $bytes );
		$this->assertStringContainsString( "\x1B@", $bytes ); // ESC @ init.
	}

	/**
	 * It builds an Epson diagnostic as ePOS-Print XML.
	 */
	public function test_build_epson_diagnostic_is_epos_xml(): void {
		$diag = ( new Cloud_Print_Diagnostic() )->build( 'epson-sdp', 'Counter' );
		$this->assertEquals( 'application/xml', $diag['content_type'] );
		$xml = base64_decode( $diag['payload'], true );
		$this->assertStringContainsString( '<epos-print', $xml );
		$this->assertStringContainsString( 'WCPOS', $xml );
	}

	/**
	 * It throws for providers without a server-side diagnostic.
	 */
	public function test_build_printnode_throws(): void {
		$this->expectException( \RuntimeException::class );
		( new Cloud_Print_Diagnostic() )->build( 'printnode', 'Bar' );
	}

	/**
	 * It builds a PrintNode diagnostic as PDF bytes.
	 */
	public function test_build_pdf_returns_pdf_bytes(): void {
		$pdf = ( new Cloud_Print_Diagnostic() )->build_pdf( 'Bar' );
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
	}
}
