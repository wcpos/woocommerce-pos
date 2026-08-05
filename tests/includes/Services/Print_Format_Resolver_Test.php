<?php
/**
 * Print format resolver tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Print_Format_Resolver;
use WP_UnitTestCase;

/**
 * Print_Format_Resolver_Test class.
 */
class Print_Format_Resolver_Test extends WP_UnitTestCase {
	/**
	 * It resolves printnode non-thermal templates to PDF.
	 */
	public function test_printnode_non_thermal_resolves_to_pdf(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array( 'provider' => 'printnode' ),
			array( 'engine' => 'logicless' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => 'pdf',
				'content_type' => 'application/pdf',
			),
			$actual
		);
	}

	/**
	 * It resolves printnode thermal raw to ESC/POS.
	 */
	public function test_printnode_thermal_raw_resolves_to_escpos(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array(
				'provider' => 'printnode',
				'printnode_format' => 'raw',
			),
			array( 'engine' => 'thermal' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => 'escpos',
				'content_type' => 'application/octet-stream',
			),
			$actual
		);
	}

	/**
	 * It resolves printnode thermal with explicit pdf format to PDF.
	 */
	public function test_printnode_thermal_pdf_resolves_to_pdf(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array(
				'provider' => 'printnode',
				'printnode_format' => 'pdf',
			),
			array( 'engine' => 'thermal' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => 'pdf',
				'content_type' => 'application/pdf',
			),
			$actual
		);
	}

	/**
	 * It defaults printnode thermal with no format to PDF.
	 */
	public function test_printnode_thermal_missing_format_defaults_to_pdf(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array( 'provider' => 'printnode' ),
			array( 'engine' => 'thermal' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => 'pdf',
				'content_type' => 'application/pdf',
			),
			$actual
		);
	}

	/**
	 * It delegates star thermal to the provider wire format.
	 */
	public function test_star_thermal_delegates_to_provider_starprnt(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array( 'provider' => 'star-cloudprnt' ),
			array( 'engine' => 'thermal' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => 'starprnt',
				'content_type' => 'application/vnd.star.starprnt',
			),
			$actual
		);
	}

	/**
	 * It delegates epson thermal to the provider wire format.
	 */
	public function test_epson_thermal_delegates_to_provider_epos_xml(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array( 'provider' => 'epson-sdp' ),
			array( 'engine' => 'thermal' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => 'epos-xml',
				'content_type' => 'application/xml',
			),
			$actual
		);
	}

	/**
	 * It marks a non-printnode non-thermal template as not printable.
	 */
	public function test_star_non_thermal_is_not_printable(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act.
		$actual = $resolver->resolve(
			array( 'provider' => 'star-cloudprnt' ),
			array( 'engine' => 'logicless' )
		);

		// Assert.
		$this->assertEquals(
			array(
				'kind' => '',
				'content_type' => '',
			),
			$actual
		);
	}

	/**
	 * It returns each provider's declared content type when no template is in hand.
	 */
	public function test_content_type_for_printer_matches_provider_declaration(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();
		$expected = array(
			'star-cloudprnt' => 'application/vnd.star.starprnt',
			'epson-sdp'      => 'application/xml',
			'printnode'      => 'application/pdf',
			'star-online'    => 'text/vnd.star.markup',
		);

		foreach ( $expected as $provider => $content_type ) {
			// Act / Assert.
			$this->assertEquals(
				$content_type,
				$resolver->content_type_for_printer( array( 'provider' => $provider ) ),
				$provider
			);
		}
	}

	/**
	 * It falls back to octet-stream for a printer with no usable provider.
	 */
	public function test_content_type_for_printer_unknown_provider_returns_octet_stream(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();

		// Act / Assert.
		$this->assertEquals( 'application/octet-stream', $resolver->content_type_for_printer( array() ) );
		$this->assertEquals(
			'application/octet-stream',
			$resolver->content_type_for_printer( array( 'provider' => 'brother-ql' ) )
		);
	}

	/**
	 * It deliberately ignores the PrintNode raw format without a template.
	 *
	 * The template-aware path answers octet-stream for the same printer; the
	 * template-agnostic path keeps the provider default. Locking both in keeps
	 * the divergence visible if either side is changed (see issue #1351).
	 */
	public function test_content_type_for_printer_printnode_raw_keeps_pdf_default(): void {
		// Arrange.
		$resolver = new Print_Format_Resolver();
		$printer  = array(
			'provider' => 'printnode',
			'printnode_format' => 'raw',
		);

		// Act / Assert.
		$this->assertEquals( 'application/pdf', $resolver->content_type_for_printer( $printer ) );
		$this->assertEquals(
			'application/octet-stream',
			$resolver->resolve( $printer, array( 'engine' => 'thermal' ) )['content_type']
		);
	}
}
