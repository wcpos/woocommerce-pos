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
		$this->assertStringContainsString( '<epos-print', base64_decode( $diagnostic['payload'], true ) );
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
		$this->assertStringContainsString( 'Till [[cut]]', base64_decode( $diagnostic['payload'], true ) );
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
}
