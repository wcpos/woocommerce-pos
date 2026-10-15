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
		if ( $this->temp_dir && is_dir( $this->temp_dir ) ) {
			$entries = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->temp_dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $entries as $entry ) {
				if ( $entry->isDir() ) {
					rmdir( $entry->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				} else {
					wp_delete_file( $entry->getPathname() );
				}
			}
			rmdir( $this->temp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		}
		parent::tearDown();
	}

	/**
	 * Merchant fonts override aliases, while unregistered families stay Unicode.
	 *
	 * The family is resolved once before the merchant registers it, which is
	 * what an earlier receipt in the same request does: Dompdf memoises that
	 * answer in a process-wide static, and without the getFont() override the
	 * registration would never be seen. Runs in its own process so the cache
	 * state is exactly what this test creates.
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
		$this->assertStringEndsWith( '/DejaVuSans', $metrics->getFont( 'helvetica', 'normal' ) ); // Warms Dompdf's static cache with the alias.

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

	/**
	 * Serif falls back to DejaVu Sans when the build ships no serif face.
	 *
	 * The `next` lane trims the bundled DejaVu files and installs a font pack
	 * with sans and mono faces only, so `dejavu serif` is absent from the
	 * lookup table there. The alias must then land on a Unicode face rather
	 * than cp1252 Times. The bundled map is simulated by pointing Dompdf's
	 * rootDir at a copy of installed-fonts.dist.json with the serif entry
	 * removed; FontMetrics reads nothing else from rootDir for a lookup.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serif_alias_falls_back_to_sans_when_no_serif_face_is_bundled(): void {
		// Arrange.
		$this->temp_dir = get_temp_dir() . 'wcpos-pdf-fonts-' . uniqid();
		wp_mkdir_p( $this->temp_dir . '/lib/fonts' );
		$real_options = new Options();
		$dist_map     = json_decode( (string) file_get_contents( $real_options->getRootDir() . '/lib/fonts/installed-fonts.dist.json' ), true );
		unset( $dist_map['dejavu serif'] );
		file_put_contents( $this->temp_dir . '/lib/fonts/installed-fonts.dist.json', (string) wp_json_encode( $dist_map ) );
		$trimmed_options = new Options(
			array(
				'rootDir'   => $this->temp_dir,
				'fontDir'   => $this->temp_dir,
				'fontCache' => $this->temp_dir,
				'tempDir'   => $this->temp_dir,
				'chroot'    => $this->temp_dir,
			)
		);
		$canvas = ( new Dompdf( $real_options ) )->getCanvas();

		// Act.
		$bundled = new Pdf_Font_Metrics( $canvas, $real_options );
		$trimmed = new Pdf_Font_Metrics( $canvas, $trimmed_options );

		// Assert.
		$this->assertStringEndsWith( '/DejaVuSerif', $bundled->getFont( 'serif', 'normal' ) );
		$this->assertStringEndsWith( '/DejaVuSans', $trimmed->getFontFamilies()['serif']['normal'] );
		$this->assertStringEndsWith( '/DejaVuSans-Bold', $trimmed->getFontFamilies()['times']['bold'] );
		$this->assertStringEndsWith( '/DejaVuSansMono', $trimmed->getFontFamilies()['monospace']['normal'] );
	}
}
