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
		$this->assertEquals( array( 'star-cloudprnt', 'epson-sdp', 'printnode', 'star-online' ), $actual );
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
	 * It returns the StarPRNT content type for star.
	 */
	public function test_content_type_star_returns_starprnt(): void {
		// Act / Assert.
		$this->assertEquals( 'application/vnd.star.starprnt', Provider::content_type( 'star-cloudprnt' ) );
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
	 * It returns starprnt for a thermal star printer.
	 */
	public function test_wire_format_star_thermal_returns_starprnt(): void {
		// Act / Assert.
		$this->assertEquals( 'starprnt', Provider::wire_format( 'star-cloudprnt', 'thermal' ) );
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
	/**
	 * It includes star-online as a valid provider.
	 */
	public function test_valid_includes_star_online(): void {
		$this->assertContains( 'star-online', Provider::valid() );
	}

	/**
	 * It reports push providers as requiring submit.
	 */
	public function test_requires_submit_true_for_push_providers(): void {
		$this->assertTrue( Provider::requires_submit( 'printnode' ) );
		$this->assertTrue( Provider::requires_submit( 'star-online' ) );
	}

	/**
	 * It reports polling providers as not requiring submit.
	 */
	public function test_requires_submit_false_for_polling_providers(): void {
		$this->assertFalse( Provider::requires_submit( 'star-cloudprnt' ) );
		$this->assertFalse( Provider::requires_submit( 'epson-sdp' ) );
	}

	/**
	 * It reports star-online as a push provider with Star markup.
	 */
	public function test_star_online_is_push_with_star_markup(): void {
		$this->assertFalse( Provider::is_polling( 'star-online' ) );
		$this->assertSame( 'text/vnd.star.markup', Provider::content_type( 'star-online' ) );
		$this->assertSame( 'star-markup', Provider::wire_format( 'star-online', 'thermal' ) );
		$this->assertNull( Provider::poll_endpoint( 'star-online' ) );
	}

	/**
	 * It leaves every known provider key untouched.
	 */
	public function test_normalize_known_provider_returns_it_unchanged(): void {
		foreach ( Provider::valid() as $provider ) {
			$this->assertSame( $provider, Provider::normalize( $provider ) );
		}
	}

	/**
	 * It maps a missing provider to the default.
	 */
	public function test_normalize_null_returns_default_provider(): void {
		$this->assertSame( 'star-cloudprnt', Provider::normalize( null ) );
		$this->assertSame( Provider::DEFAULT_PROVIDER, Provider::normalize( null ) );
	}

	/**
	 * It maps an empty provider to the default.
	 */
	public function test_normalize_empty_string_returns_default_provider(): void {
		$this->assertSame( 'star-cloudprnt', Provider::normalize( '' ) );
	}

	/**
	 * It maps an unknown provider key to the default.
	 */
	public function test_normalize_unknown_provider_returns_default_provider(): void {
		$this->assertSame( 'star-cloudprnt', Provider::normalize( 'brother-ql' ) );
	}

	/**
	 * It always returns a key the capability lookups understand.
	 */
	public function test_normalize_output_is_always_a_valid_provider(): void {
		foreach ( array( null, '', 'brother-ql', 'STAR-CLOUDPRNT', 'printnode' ) as $input ) {
			$this->assertContains( Provider::normalize( $input ), Provider::valid() );
		}
	}
}
