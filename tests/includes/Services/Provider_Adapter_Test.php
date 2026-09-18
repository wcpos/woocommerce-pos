<?php
/**
 * Cloud-print provider adapter tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Provider;
use WCPOS\WooCommercePOS\Services\Providers\Epson_Sdp_Adapter;
use WCPOS\WooCommercePOS\Services\Providers\Printnode_Adapter;
use WCPOS\WooCommercePOS\Services\Providers\Star_Cloudprnt_Adapter;
use WCPOS\WooCommercePOS\Services\Providers\Star_Online_Adapter;
use WP_UnitTestCase;

/**
 * Provider_Adapter_Test class.
 */
class Provider_Adapter_Test extends WP_UnitTestCase {
	/**
	 * A completed CloudPRNT result acknowledges the token, not another job offer.
	 */
	public function test_cloudprnt_result_returns_exact_acknowledgement(): void {
		// Arrange.
		$adapter = new Star_Cloudprnt_Adapter();
		$poll    = $adapter->parse(
			array(
				'params' => array(
					'token' => '42',
					'code'  => '200 OK',
				),
				'method' => 'DELETE',
				'route'  => '/wcpos/v2/print-jobs/cloudprnt',
			)
		);

		// Act.
		$response = $adapter->advertise( array( 'id' => 'p1' ), $poll, null );

		// Assert.
		$this->assertSame(
			array(
				'status'  => 200,
				'headers' => array(),
				'body'    => array( 'ok' => true ),
			),
			$response
		);
	}

	/**
	 * Both SDP responses retain the XML media type and UTF-8 charset.
	 */
	public function test_epson_poll_and_delivery_keep_xml_content_type(): void {
		// Arrange.
		$adapter = new Epson_Sdp_Adapter();
		$poll    = $adapter->parse(
			array(
				'params' => array( 'ConnectionType' => 'GetRequest' ),
				'body'   => '',
				'route'  => '/wcpos/v2/print-jobs/epson-sdp',
			)
		);

		// Act.
		$idle     = $adapter->advertise( array( 'id' => 'p1' ), $poll, null );
		$delivery = $adapter->deliver( array(), array( 'body' => '<epos-print/>' ), $poll );

		// Assert: the controller tests separately pin the SDP envelope bytes.
		$this->assertSame( array( 'Content-Type' => 'text/xml; charset=utf-8' ), $idle['headers'] );
		$this->assertSame( array( 'Content-Type' => 'text/xml; charset=utf-8' ), $delivery['headers'] );
	}

	/**
	 * It resolves every canonical provider to its adapter.
	 */
	public function test_adapter_resolves_all_provider_keys(): void {
		$this->assertInstanceOf( Star_Cloudprnt_Adapter::class, Provider::adapter( 'star-cloudprnt' ) );
		$this->assertInstanceOf( Epson_Sdp_Adapter::class, Provider::adapter( 'epson-sdp' ) );
		$this->assertInstanceOf( Printnode_Adapter::class, Provider::adapter( 'printnode' ) );
		$this->assertInstanceOf( Star_Online_Adapter::class, Provider::adapter( 'star-online' ) );
	}

	/**
	 * It normalizes a missing legacy-row provider to Star CloudPRNT.
	 */
	public function test_adapter_resolves_legacy_row_to_star_cloudprnt(): void {
		$provider = Provider::normalize( null );

		$this->assertSame( 'star-cloudprnt', $provider );
		$this->assertInstanceOf( Star_Cloudprnt_Adapter::class, Provider::adapter( $provider ) );
	}

	/**
	 * It returns null for an unknown provider.
	 */
	public function test_adapter_returns_null_for_unknown_provider(): void {
		$this->assertNull( Provider::adapter( 'unknown-provider' ) );
	}

	/**
	 * It keeps Star CloudPRNT's native format, diagnostic, and polling status.
	 */
	public function test_star_cloudprnt_adapter_owns_star_behaviour(): void {
		$adapter = new Star_Cloudprnt_Adapter();

		$this->assertSame(
			array(
				'kind'         => 'starprnt',
				'content_type' => 'application/vnd.star.starprnt',
			),
			$adapter->format( array(), array( 'engine' => 'thermal' ) )
		);

		$diagnostic = $adapter->diagnostic( 'Kitchen' );
		$this->assertSame( 'application/vnd.star.starprnt', $diagnostic['content_type'] );
		$this->assertStringContainsString( "\x1B\x64\x03", base64_decode( $diagnostic['payload'], true ) );
		$this->assertSame(
			'connected',
			$adapter->status(
				array(),
				array(
					'now'          => 200,
					'seen'         => 100,
					'seen_ttl'     => 150,
					'relay_status' => null,
				)
			)
		);
	}

	/**
	 * It keeps Epson's XML format, diagnostic, and polling status.
	 */
	public function test_epson_adapter_owns_epson_behaviour(): void {
		$adapter = new Epson_Sdp_Adapter();

		$this->assertSame(
			array(
				'kind'         => 'epos-xml',
				'content_type' => 'application/xml',
			),
			$adapter->format( array(), array( 'engine' => 'thermal' ) )
		);

		$diagnostic = $adapter->diagnostic( 'Counter' );
		$this->assertSame( 'application/xml', $diagnostic['content_type'] );
		$xml = base64_decode( $diagnostic['payload'], true );
		$this->assertStringContainsString( '<epos-print', $xml );
		// Three blank lines before the feed cut so the last line clears the blade.
		$this->assertStringContainsString( '</text><feed line="3"/><cut type="feed"/>', $xml );
		$this->assertSame(
			'blocked',
			$adapter->status(
				array(),
				array(
					'now'          => 200,
					'seen'         => 0,
					'seen_ttl'     => 150,
					'relay_status' => array( 'origin_status' => 'blocked' ),
				)
			)
		);
	}

	/**
	 * It keeps PrintNode's format, diagnostic, listing status, and submit errors.
	 */
	public function test_printnode_adapter_owns_printnode_behaviour(): void {
		$adapter = new Printnode_Adapter();
		$printer = array( 'id' => 'printnode-adapter-test' );

		$this->assertSame(
			array(
				'kind'         => 'escpos',
				'content_type' => 'application/octet-stream',
			),
			$adapter->format(
				array( 'printnode_format' => 'raw' ),
				array( 'engine' => 'thermal' )
			)
		);
		$this->assertSame( 'application/pdf', $adapter->diagnostic( 'Bar' )['content_type'] );
		$this->assertSame( 'unknown', $adapter->status( $printer, array( 'cache_ttl' => 60 ) ) );

		$result = $adapter->submit( array(), array(), '', 'WCPOS Test' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Cloud print: PrintNode printer is missing an API key or printer id.', $result['error'] );

		$result = $adapter->submit(
			array(
				'printnode_api_key'    => 'key',
				'printnode_printer_id' => 1,
			),
			array(),
			'',
			'WCPOS Test'
		);
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Cloud print: PrintNode job produced no printable content.', $result['error'] );

		delete_transient( 'wcpos_cloud_print_pn_status_' . md5( $printer['id'] ) );
	}

	/**
	 * It keeps Star Online's format, diagnostic, listing status, and submit errors.
	 */
	public function test_star_online_adapter_owns_star_online_behaviour(): void {
		$adapter = new Star_Online_Adapter();
		$printer = array( 'id' => 'star-online-adapter-test' );

		$this->assertSame(
			array(
				'kind'         => 'star-markup',
				'content_type' => 'text/vnd.star.markup',
			),
			$adapter->format( array(), array( 'engine' => 'thermal' ) )
		);

		$diagnostic = $adapter->diagnostic( 'Till [cut]' );
		$this->assertSame( 'text/vnd.star.markup', $diagnostic['content_type'] );
		$markup = base64_decode( $diagnostic['payload'], true );
		$this->assertStringContainsString( 'Till [[cut]]', $markup );
		$this->assertStringEndsWith( "printing works!\n[feed][feed][feed][cut]", $markup );
		$this->assertSame( 'unknown', $adapter->status( $printer, array( 'cache_ttl' => 60 ) ) );

		$result = $adapter->submit( array(), array(), '', 'WCPOS Test' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Cloud print: Star Online printer is misconfigured.', $result['error'] );

		$result = $adapter->submit(
			array(
				'star_api_key'       => 'key',
				'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/test',
				'star_device_id'     => 'device',
			),
			array(),
			'',
			'WCPOS Test'
		);
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Cloud print: Star Online job produced no printable content.', $result['error'] );

		delete_transient( 'wcpos_cloud_print_star_status_' . md5( $printer['id'] ) );
	}

	/**
	 * It builds a Star diagnostic as native StarPRNT bytes.
	 *
	 * StarPRNT-native printers (the whole TSP100 line) cannot decode ESC/POS,
	 * so the diagnostic must carry StarPRNT commands under the vnd.star type.
	 */
	public function test_build_star_diagnostic_is_starprnt_bytes(): void {
		// Act.
		$diag = Provider::adapter( 'star-cloudprnt' )->diagnostic( 'Kitchen' );

		// Assert.
		$this->assertEquals( 'application/vnd.star.starprnt', $diag['content_type'] );
		$bytes = base64_decode( $diag['payload'], true );
		$this->assertStringContainsString( 'WCPOS', $bytes );
		$this->assertStringContainsString( 'Kitchen', $bytes );
		$this->assertStringNotContainsString( "\x1B@", $bytes ); // No ESC/POS init.
		$this->assertStringNotContainsString( "\x1DV", $bytes ); // No ESC/POS cut.
		$this->assertStringContainsString( "\x1B\x1D\x61\x01", $bytes ); // StarPRNT center align.
		$this->assertStringContainsString( "\x1B\x64\x03", $bytes ); // StarPRNT partial cut.
	}

	/**
	 * It strips control bytes from the printer name before emitting commands.
	 */
	public function test_build_strips_control_bytes_from_printer_name(): void {
		// Act.
		$diag  = Provider::adapter( 'star-cloudprnt' )->diagnostic( "Kit\x1b\x64\x02chen" );
		$bytes = base64_decode( $diag['payload'], true );

		// Assert.
		$this->assertStringContainsString( 'Kitd', $bytes ); // ESC + STX stripped, printable 'd' kept.
		$this->assertStringNotContainsString( "\x1b\x64\x02", $bytes ); // No injected full cut.
	}

	/**
	 * It builds an Epson diagnostic as ePOS-Print XML.
	 */
	public function test_build_epson_diagnostic_is_epos_xml(): void {
		// Act.
		$diag = Provider::adapter( 'epson-sdp' )->diagnostic( 'Counter' );

		// Assert.
		$this->assertEquals( 'application/xml', $diag['content_type'] );
		$xml = base64_decode( $diag['payload'], true );
		$this->assertStringContainsString( '<epos-print', $xml );
		$this->assertStringContainsString( 'WCPOS', $xml );
	}

	/**
	 * It reports PrintNode as having no server-side diagnostic.
	 */
	public function test_printnode_does_not_support_server_diagnostic(): void {
		// Act / Assert.
		$this->assertFalse( Provider::supports_server_diagnostic( 'printnode' ) );
	}

	/**
	 * It builds a PrintNode diagnostic as PDF bytes.
	 */
	public function test_build_pdf_returns_pdf_bytes(): void {
		// Act.
		$pdf = base64_decode( Provider::adapter( 'printnode' )->diagnostic( 'Bar' )['payload'], true );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
	}

	/**
	 * It builds the Star Online diagnostic as Star Document Markup.
	 */
	public function test_star_markup_returns_star_document_markup(): void {
		// Arrange: the payload stamps gmdate('Y-m-d H:i'), so accept either side
		// of a minute boundary crossed during the call.
		$before = gmdate( 'Y-m-d H:i' );

		// Act.
		$markup = base64_decode( Provider::adapter( 'star-online' )->diagnostic( 'Counter' )['payload'], true );

		// Assert.
		$after    = gmdate( 'Y-m-d H:i' );
		$template = static function ( string $date ): string {
			$expected  = '[align: middle][bold: on]WCPOS[bold: off]' . "\n";
			$expected .= 'Cloud Print Test' . "\n" . '[align: left]';
			$expected .= 'Printer: Counter' . "\n";
			$expected .= 'Date: ' . $date . "\n";
			$expected .= 'If you can read this, printing works!' . "\n";
			// Three feeds before the cut: the same bottom margin as receipts.
			$expected .= '[feed][feed][feed][cut]';

			return $expected;
		};
		$this->assertContains( $markup, array( $template( $before ), $template( $after ) ) );
	}

	/**
	 * It escapes markup brackets in the printer name.
	 */
	public function test_star_markup_escapes_brackets_in_printer_name(): void {
		// Act.
		$markup = base64_decode( Provider::adapter( 'star-online' )->diagnostic( 'Till [cut]' )['payload'], true );

		// Assert.
		$this->assertStringContainsString( 'Printer: Till [[cut]]', $markup );
	}
}
