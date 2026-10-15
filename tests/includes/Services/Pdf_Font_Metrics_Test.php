<?php
/**
 * Pdf_Font_Metrics tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\Vendor\Dompdf\Dompdf;
use WCPOS\Vendor\Dompdf\Options;
use WCPOS\WooCommercePOS\Services\Pdf_Font_Metrics;

/**
 * Pdf_Font_Metrics_Test class.
 */
class Pdf_Font_Metrics_Test extends \WP_UnitTestCase {

	/**
	 * Isolated font directory.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Remove the font registration and temporary directory.
	 */
	public function tearDown(): void {
		wp_delete_file( $this->temp_dir . '/installed-fonts.json' );
		rmdir( $this->temp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		parent::tearDown();
	}

	/**
	 * Merchant fonts override aliases, while unregistered families stay Unicode.
	 *
	 * Isolate Dompdf's static getFont() cache from other renderer tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_font_alias_preserves_merchant_registration(): void {
		// Arrange.
		$this->temp_dir = get_temp_dir() . 'wcpos-pdf-fonts-' . uniqid();
		wp_mkdir_p( $this->temp_dir );
		$options = new Options(
			array(
				'fontDir'   => $this->temp_dir,
				'fontCache' => $this->temp_dir,
				'tempDir'   => $this->temp_dir,
				'chroot'    => $this->temp_dir,
			)
		);
		$dompdf  = new Dompdf( $options );
		$metrics = new Pdf_Font_Metrics( $dompdf->getCanvas(), $options );

		// Act.
		$metrics->setFontFamily(
			'helvetica',
			array(
				'normal'      => 'MerchantFont',
				'bold'        => 'MerchantFont-Bold',
				'italic'      => 'MerchantFont',
				'bold_italic' => 'MerchantFont-Bold',
			)
		);

		// Assert.
		$this->assertStringEndsWith( '/MerchantFont', $metrics->getFont( 'helvetica', 'normal' ) );
		$this->assertStringEndsWith( '/DejaVuSans', $metrics->getFont( 'sans-serif', 'normal' ) );
	}
}
