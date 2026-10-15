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
 *
 * Every case builds its own font table instead of reading the one Dompdf ships
 * in vendor_prefixed: a bundled map holding only the cp1252 core fonts, plus a
 * user map (fontDir/installed-fonts.json) supplying DejaVu the way a font pack
 * does. That is the shape a build with trimmed bundled fonts has, and a build
 * with the full DejaVu set resolves the same families the same way, so the
 * tests hold on both. Lookups never open the font files.
 */
class Pdf_Font_Metrics_Test extends \WP_UnitTestCase {

	/**
	 * A font pack's family map: sans and mono faces only, no serif.
	 */
	private const PACK_FAMILIES = array(
		'dejavu sans'      => array(
			'normal'      => 'DejaVuSans',
			'bold'        => 'DejaVuSans-Bold',
			'italic'      => 'DejaVuSans-Oblique',
			'bold_italic' => 'DejaVuSans-Bold',
		),
		'dejavu sans mono' => array(
			'normal'      => 'DejaVuSansMono',
			'bold'        => 'DejaVuSansMono-Bold',
			'italic'      => 'DejaVuSansMono',
			'bold_italic' => 'DejaVuSansMono-Bold',
		),
	);

	/**
	 * Isolated Dompdf root and font directory.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Remove the temporary font tree.
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
	 * Build metrics over a core-only bundled map and the given user families.
	 *
	 * @param array $user_families Families to write as fontDir/installed-fonts.json.
	 *
	 * @return Pdf_Font_Metrics
	 */
	private function make_metrics( array $user_families ): Pdf_Font_Metrics {
		$this->temp_dir = get_temp_dir() . 'wcpos-pdf-fonts-' . uniqid();
		wp_mkdir_p( $this->temp_dir . '/lib/fonts' );

		$real_options = new Options();
		$bundled      = json_decode( (string) file_get_contents( $real_options->getRootDir() . '/lib/fonts/installed-fonts.dist.json' ), true );
		unset( $bundled['dejavu sans'], $bundled['dejavu sans mono'], $bundled['dejavu serif'] );
		file_put_contents( $this->temp_dir . '/lib/fonts/installed-fonts.dist.json', (string) wp_json_encode( $bundled ) );
		file_put_contents( $this->temp_dir . '/installed-fonts.json', (string) wp_json_encode( $user_families ) );

		$options = new Options(
			array(
				'rootDir'   => $this->temp_dir,
				'fontDir'   => $this->temp_dir,
				'fontCache' => $this->temp_dir,
				'tempDir'   => $this->temp_dir,
				'chroot'    => $this->temp_dir,
			)
		);

		return new Pdf_Font_Metrics( ( new Dompdf( $real_options ) )->getCanvas(), $options );
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
		$metrics = $this->make_metrics( self::PACK_FAMILIES );
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
	 * Serif aliases take DejaVu Serif when present and DejaVu Sans otherwise.
	 *
	 * A font pack with sans and mono faces only must still move serif and
	 * times off cp1252 Times; a set that includes a serif face keeps it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serif_alias_falls_back_to_sans_when_no_serif_face_is_present(): void {
		// Arrange.
		$with_serif = self::PACK_FAMILIES + array(
			'dejavu serif' => array(
				'normal'      => 'DejaVuSerif',
				'bold'        => 'DejaVuSerif-Bold',
				'italic'      => 'DejaVuSerif-Italic',
				'bold_italic' => 'DejaVuSerif-BoldItalic',
			),
		);

		// Act.
		$sans_only = $this->make_metrics( self::PACK_FAMILIES )->getFontFamilies();
		$full      = $this->make_metrics( $with_serif )->getFontFamilies();

		// Assert.
		$this->assertStringEndsWith( '/DejaVuSans', $sans_only['serif']['normal'] );
		$this->assertStringEndsWith( '/DejaVuSans-Bold', $sans_only['times']['bold'] );
		$this->assertStringEndsWith( '/DejaVuSansMono', $sans_only['monospace']['normal'] );
		$this->assertStringEndsWith( '/DejaVuSerif', $full['serif']['normal'] );
		$this->assertStringEndsWith( '/DejaVuSerif-Bold', $full['times-roman']['bold'] );
	}
}
