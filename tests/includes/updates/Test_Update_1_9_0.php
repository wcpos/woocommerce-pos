<?php
/**
 * Tests for the 1.9.0 update script that cleans up bloated log files.
 *
 * @package WCPOS\WooCommercePOS\Tests\Updates
 */

namespace WCPOS\WooCommercePOS\Tests\Updates;

use WP_UnitTestCase;

/**
 * Tests for update-1.9.0.php log cleanup.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Update_1_9_0 extends WP_UnitTestCase {

	/**
	 * WC logs directory path.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		if ( ! is_dir( $this->log_dir ) ) {
			mkdir( $this->log_dir, 0755, true );
		}

		// Remove any pre-existing POS log files so tests start clean.
		$existing = glob( $this->log_dir . 'woocommerce-pos-*.log' );
		if ( $existing ) {
			array_map( 'unlink', $existing );
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up any test log files.
		$files = glob( $this->log_dir . 'woocommerce-pos-*.log' );
		if ( $files ) {
			array_map( 'unlink', $files );
		}

		// Clean up non-POS files too.
		$other_files = glob( $this->log_dir . 'woocommerce-2026-*.log' );
		if ( $other_files ) {
			array_map( 'unlink', $other_files );
		}

		parent::tearDown();
	}

	/**
	 * Test that POS log files are deleted by the update script.
	 */
	public function test_pos_log_files_are_deleted(): void {
		// Create fake POS log files.
		$file_a = $this->log_dir . 'woocommerce-pos-0-2026-03-25.log';
		$file_b = $this->log_dir . 'woocommerce-pos-1-2026-03-25.log';
		$file_c = $this->log_dir . 'woocommerce-pos-2-2026-03-25.log';
		file_put_contents( $file_a, str_repeat( 'x', 100 ) );
		file_put_contents( $file_b, str_repeat( 'x', 100 ) );
		file_put_contents( $file_c, str_repeat( 'x', 100 ) );

		$this->assertFileExists( $file_a );
		$this->assertFileExists( $file_b );
		$this->assertFileExists( $file_c );

		// Run the update script.
		include __DIR__ . '/../../../includes/updates/update-1.9.0.php';

		// The script's own log message may create a new POS log file,
		// so verify the specific files we created are gone.
		$this->assertFileDoesNotExist( $file_a );
		$this->assertFileDoesNotExist( $file_b );
		$this->assertFileDoesNotExist( $file_c );
	}

	/**
	 * Test that non-POS log files are not deleted.
	 */
	public function test_non_pos_log_files_are_preserved(): void {
		// Create a POS log file and a non-POS log file.
		file_put_contents( $this->log_dir . 'woocommerce-pos-0-2026-03-25.log', 'pos log' );
		file_put_contents( $this->log_dir . 'woocommerce-2026-03-25-abc123.log', 'other log' );

		include __DIR__ . '/../../../includes/updates/update-1.9.0.php';

		$this->assertFileDoesNotExist( $this->log_dir . 'woocommerce-pos-0-2026-03-25.log' );
		$this->assertFileExists( $this->log_dir . 'woocommerce-2026-03-25-abc123.log' );
	}

	/**
	 * Test that the update script handles missing log directory gracefully.
	 */
	public function test_handles_missing_log_directory(): void {
		// Override uploads dir to a non-existent location for this test.
		$filter = function ( $dirs ) {
			$dirs['basedir'] = sys_get_temp_dir() . '/wcpos-test-missing-' . uniqid();
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );

		// Should not throw or error.
		include __DIR__ . '/../../../includes/updates/update-1.9.0.php';

		remove_filter( 'upload_dir', $filter );

		// If we get here without error, the test passes.
		$this->assertTrue( true );
	}
}
