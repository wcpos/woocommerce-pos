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
	public function test_star_thermal_delegates_to_provider_escpos(): void {
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
				'kind' => 'escpos',
				'content_type' => 'application/octet-stream',
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
}
