<?php
/**
 * Provider value object tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Provider;
use WP_UnitTestCase;

/**
 * Provider_Test class.
 */
class Provider_Test extends WP_UnitTestCase {
	/**
	 * It returns the canonical list of valid providers.
	 */
	public function test_valid_returns_canonical_provider_list(): void {
		// Act.
		$actual = Provider::valid();

		// Assert.
		$this->assertEquals( array( 'star-cloudprnt', 'epson-sdp', 'printnode' ), $actual );
	}

	/**
	 * It reports printnode as non-polling.
	 */
	public function test_is_polling_printnode_returns_false(): void {
		// Act / Assert.
		$this->assertFalse( Provider::is_polling( 'printnode' ) );
	}

	/**
	 * It reports star-cloudprnt as polling.
	 */
	public function test_is_polling_star_returns_true(): void {
		// Act / Assert.
		$this->assertTrue( Provider::is_polling( 'star-cloudprnt' ) );
	}

	/**
	 * It reports epson-sdp as polling.
	 */
	public function test_is_polling_epson_returns_true(): void {
		// Act / Assert.
		$this->assertTrue( Provider::is_polling( 'epson-sdp' ) );
	}

	/**
	 * It returns the octet-stream content type for star.
	 */
	public function test_content_type_star_returns_octet_stream(): void {
		// Act / Assert.
		$this->assertEquals( 'application/octet-stream', Provider::content_type( 'star-cloudprnt' ) );
	}

	/**
	 * It returns the xml content type for epson.
	 */
	public function test_content_type_epson_returns_xml(): void {
		// Act / Assert.
		$this->assertEquals( 'application/xml', Provider::content_type( 'epson-sdp' ) );
	}

	/**
	 * It returns the epson-sdp poll endpoint slug.
	 */
	public function test_poll_endpoint_epson_returns_epson_sdp(): void {
		// Act / Assert.
		$this->assertEquals( 'epson-sdp', Provider::poll_endpoint( 'epson-sdp' ) );
	}

	/**
	 * It returns the cloudprnt poll endpoint slug for star.
	 */
	public function test_poll_endpoint_star_returns_cloudprnt(): void {
		// Act / Assert.
		$this->assertEquals( 'cloudprnt', Provider::poll_endpoint( 'star-cloudprnt' ) );
	}

	/**
	 * It returns null poll endpoint for printnode.
	 */
	public function test_poll_endpoint_printnode_returns_null(): void {
		// Act / Assert.
		$this->assertNull( Provider::poll_endpoint( 'printnode' ) );
	}

	/**
	 * It reports printnode as having no server diagnostic.
	 */
	public function test_supports_server_diagnostic_printnode_returns_false(): void {
		// Act / Assert.
		$this->assertFalse( Provider::supports_server_diagnostic( 'printnode' ) );
	}

	/**
	 * It reports star as supporting a server diagnostic.
	 */
	public function test_supports_server_diagnostic_star_returns_true(): void {
		// Act / Assert.
		$this->assertTrue( Provider::supports_server_diagnostic( 'star-cloudprnt' ) );
	}

	/**
	 * It returns escpos for a thermal star printer.
	 */
	public function test_wire_format_star_thermal_returns_escpos(): void {
		// Act / Assert.
		$this->assertEquals( 'escpos', Provider::wire_format( 'star-cloudprnt', 'thermal' ) );
	}

	/**
	 * It returns epos-xml for a thermal epson printer.
	 */
	public function test_wire_format_epson_thermal_returns_epos_xml(): void {
		// Act / Assert.
		$this->assertEquals( 'epos-xml', Provider::wire_format( 'epson-sdp', 'thermal' ) );
	}

	/**
	 * It returns null for a non-thermal star printer.
	 */
	public function test_wire_format_star_logicless_returns_null(): void {
		// Act / Assert.
		$this->assertNull( Provider::wire_format( 'star-cloudprnt', 'logicless' ) );
	}

	/**
	 * It returns null for printnode regardless of engine.
	 */
	public function test_wire_format_printnode_thermal_returns_null(): void {
		// Act / Assert.
		$this->assertNull( Provider::wire_format( 'printnode', 'thermal' ) );
	}
}
