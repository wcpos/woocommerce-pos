<?php
/**
 * Tests the offline checksum integrity check.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Error_Reporter;
use WCPOS\WooCommercePOS\Services\File_Integrity;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * File integrity test case.
 *
 * @covers \WCPOS\WooCommercePOS\Services\File_Integrity
 */
class Test_File_Integrity extends WP_UnitTestCase {
	/**
	 * Fake plugin root.
	 *
	 * @var string
	 */
	private $root = '';

	/**
	 * Remote requests seen by the HTTP stub.
	 *
	 * @var string[]
	 */
	private $requests = array();

	/**
	 * Build a fake plugin root with two shipped files.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/wcpos-fi-' . str_replace( '.', '', uniqid( '', true ) ) . '/';
		mkdir( $this->root . 'includes', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		file_put_contents( $this->root . 'main.php', 'main' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->root . 'includes/A.php', 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->requests = array();
		delete_site_option( File_Integrity::RESULT_OPTION );
	}

	/**
	 * Remove the fake root and any stubs.
	 */
	public function tearDown(): void {
		foreach ( array( 'checksums.json', 'includes/A.php', 'main.php' ) as $file ) {
			if ( is_file( $this->root . $file ) ) {
				unlink( $this->root . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
		}
		rmdir( $this->root . 'includes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		rmdir( $this->root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		delete_site_option( File_Integrity::RESULT_OPTION );
		remove_all_filters( 'pre_http_request' );
		Error_Reporter::set_transport_factory_for_testing( null );
		Error_Reporter::set_dev_override_for_testing( null );
		Error_Reporter::reset_instance();
		parent::tearDown();
	}

	/**
	 * Matching files are clean.
	 */
	public function test_compare_reports_nothing_when_every_file_matches(): void {
		$this->assertSame(
			array(
				'missing'  => array(),
				'modified' => array(),
			),
			File_Integrity::compare( $this->root, $this->manifest() )
		);
	}

	/**
	 * A deleted file is missing; an edited file is modified; extra local files are ignored.
	 */
	public function test_compare_finds_missing_and_modified_files(): void {
		unlink( $this->root . 'main.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		file_put_contents( $this->root . 'includes/A.php', 'tampered' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertSame(
			array(
				'missing'  => array( 'main.php' ),
				'modified' => array( 'includes/A.php' ),
			),
			File_Integrity::compare( $this->root, $this->manifest() )
		);
	}

	/**
	 * WordPress.org lists several accepted hashes for some files; any one matches.
	 */
	public function test_compare_accepts_any_listed_hash(): void {
		$manifest                               = $this->manifest();
		$manifest['main.php']['sha256']         = array( hash( 'sha256', 'other' ), hash( 'sha256', 'main' ) );
		$manifest['includes/A.php']['sha256'][] = 'ignored';

		$this->assertSame( array(), File_Integrity::compare( $this->root, $manifest )['modified'] );
	}

	/**
	 * Composer's build-stamped files differ between the GitHub and WordPress.org
	 * builds, so their content is not compared; their absence still counts.
	 */
	public function test_compare_skips_content_of_build_stamped_files(): void {
		mkdir( $this->root . 'vendor' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		file_put_contents( $this->root . 'vendor/autoload.php', 'local build' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$manifest = array(
			'vendor/autoload.php'                => array( 'sha256' => hash( 'sha256', 'other build' ) ),
			'vendor/composer/autoload_static.php' => array( 'sha256' => hash( 'sha256', 'other build' ) ),
		);

		$result = File_Integrity::compare( $this->root, $manifest );
		unlink( $this->root . 'vendor/autoload.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		rmdir( $this->root . 'vendor' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.

		$this->assertSame( array(), $result['modified'] );
		$this->assertSame( array( 'vendor/composer/autoload_static.php' ), $result['missing'] );
	}

	/**
	 * A shipped checksums.json (Pro, non-WordPress.org builds) is read locally, with no request.
	 */
	public function test_check_reads_a_local_manifest(): void {
		// Arrange.
		$this->stub_http( 200, array() );
		file_put_contents( $this->root . 'checksums.json', wp_json_encode( array( 'files' => $this->manifest() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->root . 'includes/A.php', 'tampered' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		// Act.
		$result = File_Integrity::check( $this->target( null ) );

		// Assert.
		$this->assertSame( 'files_modified', $result['status'] );
		$this->assertSame( array( 'includes/A.php' ), $result['modified'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * The WordPress.org manifest is fetched once and cached.
	 */
	public function test_check_fetches_and_caches_the_wordpress_org_manifest(): void {
		// Arrange.
		$this->stub_http( 200, array( 'files' => $this->manifest() ) );

		// Act.
		$first  = File_Integrity::check( $this->target( 'woocommerce-pos' ) );
		$second = File_Integrity::check( $this->target( 'woocommerce-pos' ) );

		// Assert.
		$this->assertSame( 'ok', $first['status'] );
		$this->assertSame( 'ok', $second['status'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://downloads.wordpress.org/plugin-checksums/woocommerce-pos/1.2.3.json', $this->requests[0] );
	}

	/**
	 * No checksums (404, timeout, or no manifest at all) is unknown, never a failure.
	 *
	 * @dataProvider unavailable_provider
	 *
	 * @param int|null $status HTTP status, or null for a transport error.
	 */
	public function test_check_is_unknown_when_checksums_are_unavailable( ?int $status ): void {
		$this->stub_http( $status, array( 'error' => 'Not found' ) );

		$this->assertSame( 'unknown', File_Integrity::check( $this->target( 'woocommerce-pos' ) )['status'] );
		$this->assertSame( 'unknown', File_Integrity::check( $this->target( null ) )['status'] );
	}

	/**
	 * Unavailable shapes.
	 *
	 * @return array<string, array{int|null}>
	 */
	public function unavailable_provider(): array {
		return array(
			'404'       => array( 404 ),
			'transport' => array( null ),
		);
	}

	/**
	 * A git checkout is never compared.
	 */
	public function test_check_skips_a_git_checkout(): void {
		$this->stub_http( 200, array( 'files' => array( 'gone.php' => array( 'sha256' => 'x' ) ) ) );
		mkdir( $this->root . '.git' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		$checkout = File_Integrity::check( $this->target( 'woocommerce-pos' ) );
		rmdir( $this->root . '.git' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.

		// A worktree's .git is a file.
		file_put_contents( $this->root . '.git', 'gitdir: /elsewhere' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$worktree = File_Integrity::check( $this->target( 'woocommerce-pos' ) );
		unlink( $this->root . '.git' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.

		$this->assertSame( 'unknown', $checkout['status'] );
		$this->assertSame( 'unknown', $worktree['status'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A failed run reports once, as an error tagged with what it found.
	 */
	public function test_run_reports_missing_files_once(): void {
		// Arrange.
		$transport = new Stub_Transport();
		Error_Reporter::reset_instance();
		Error_Reporter::set_transport_factory_for_testing(
			static function () use ( $transport ): Stub_Transport {
				return $transport;
			}
		);
		Error_Reporter::set_dev_override_for_testing( false );
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );
		$this->stub_http( 200, array( 'files' => $this->manifest() ) );
		unlink( $this->root . 'main.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.

		// Act: the second run finds the same thing and stays quiet.
		$result = File_Integrity::run( 'daily', $this->target( 'woocommerce-pos' ) );
		Error_Reporter::reset_instance();
		File_Integrity::run( 'daily', $this->target( 'woocommerce-pos' ) );
		$settings['tracking_consent'] = 'undecided';
		SettingsService::instance()->save_settings( 'general', $settings );

		// Assert.
		$this->assertSame( 'files_missing', $result['status'] );
		$this->assertSame( $result, File_Integrity::stored( $this->target( 'woocommerce-pos' ) ) );
		$this->assertCount( 1, $transport->events );
		$event = $transport->events[0];
		$this->assertSame( 'error', (string) $event->getLevel() );
		$this->assertSame( 'files_missing', $event->getTags()['wcpos_integrity'] ?? null );
		$this->assertSame( 'main.php', $event->getTags()['integrity_path'] ?? null );
	}

	/**
	 * Site Health shows the stored result: unknown passes, a mismatch is critical with the list.
	 */
	public function test_site_health_reflects_the_stored_result(): void {
		File_Integrity::store(
			array(
				'status'   => 'unknown',
				'missing'  => array(),
				'modified' => array(),
				'version'  => \WCPOS\WooCommercePOS\VERSION,
				'checked'  => time(),
			)
		);
		$this->assertSame( 'good', File_Integrity::site_health_test()['status'] );

		File_Integrity::store(
			array(
				'status'   => 'files_missing',
				'missing'  => array( 'includes/Services/Auth.php' ),
				'modified' => array(),
				'version'  => \WCPOS\WooCommercePOS\VERSION,
				'checked'  => time(),
			)
		);
		$test = File_Integrity::site_health_test();
		$this->assertSame( 'critical', $test['status'] );
		$this->assertStringContainsString( 'includes/Services/Auth.php', $test['description'] );

		File_Integrity::store(
			array(
				'status'   => 'files_modified',
				'missing'  => array(),
				'modified' => array( 'includes/API.php' ),
				'version'  => \WCPOS\WooCommercePOS\VERSION,
				'checked'  => time(),
			)
		);
		$this->assertSame( 'recommended', File_Integrity::site_health_test()['status'] );

		$this->assertArrayHasKey( 'wcpos_file_integrity', File_Integrity::site_status_tests( array( 'direct' => array() ) )['direct'] );
	}

	/**
	 * Results are kept per plugin folder and version: a free result never stands in for Pro's.
	 */
	public function test_stored_results_are_separate_per_folder_and_version(): void {
		$free   = $this->target( 'woocommerce-pos' );
		$pro    = array_merge( $free, array( 'root' => $this->root . 'includes/' ) );
		$result = array(
			'status'   => 'ok',
			'missing'  => array(),
			'modified' => array(),
			'version'  => '1.2.3',
			'checked'  => time(),
		);

		File_Integrity::store( $result, $free );

		$this->assertSame( $result, File_Integrity::stored( $free ) );
		$this->assertNull( File_Integrity::stored( $pro ) );
		$this->assertNull( File_Integrity::stored( array_merge( $free, array( 'version' => '1.2.4' ) ) ) );
	}

	/**
	 * With no stored result, Site Health queues a check instead of running one in the page.
	 */
	public function test_site_health_without_a_result_queues_a_check(): void {
		$this->stub_http( 200, array( 'files' => $this->manifest() ) );

		$test = File_Integrity::site_health_test();
		$next = wp_next_scheduled( File_Integrity::CRON_HOOK, array( 'site_health' ) );
		wp_clear_scheduled_hook( File_Integrity::CRON_HOOK, array( 'site_health' ) );

		$this->assertSame( 'good', $test['status'] );
		$this->assertIsInt( $next );
		$this->assertSame( array(), $this->requests );
		$this->assertNull( File_Integrity::stored() );
	}

	/**
	 * Updating this plugin schedules a check a minute later; other plugins do not.
	 */
	public function test_upgrade_of_this_plugin_schedules_a_delayed_check(): void {
		File_Integrity::on_upgrade(
			null,
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'plugins' => array( 'akismet/akismet.php' ),
			)
		);
		$this->assertFalse( wp_next_scheduled( File_Integrity::CRON_HOOK, array( 'post_update' ) ) );

		File_Integrity::on_upgrade(
			null,
			array(
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => \WCPOS\WooCommercePOS\PLUGIN_FILE,
			)
		);
		$next = wp_next_scheduled( File_Integrity::CRON_HOOK, array( 'post_update' ) );
		wp_clear_scheduled_hook( File_Integrity::CRON_HOOK, array( 'post_update' ) );

		$this->assertIsInt( $next );
		$this->assertGreaterThan( time() + 30, $next );
	}

	/**
	 * Manifest for the fake root.
	 *
	 * @return array
	 */
	private function manifest(): array {
		return array(
			'main.php'       => array(
				'md5'    => md5( 'main' ),
				'sha256' => hash( 'sha256', 'main' ),
			),
			'includes/A.php' => array(
				'md5'    => md5( 'a' ),
				'sha256' => array( hash( 'sha256', 'a' ) ),
			),
		);
	}

	/**
	 * A check target for the fake root.
	 *
	 * @param string|null $slug WordPress.org slug, or null for a non-WordPress.org build.
	 *
	 * @return array
	 */
	private function target( ?string $slug ): array {
		return array(
			'root'    => $this->root,
			'version' => '1.2.3',
			'slug'    => $slug,
		);
	}

	/**
	 * Answer every remote request with a fixed response.
	 *
	 * @param int|null $status HTTP status, or null for a transport error.
	 * @param array    $body   JSON body.
	 */
	private function stub_http( ?int $status, array $body ): void {
		delete_transient( 'wcpos_checksums_' . md5( 'woocommerce-pos1.2.3' ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $status, $body ) {
				$this->requests[] = $url;
				if ( null === $status ) {
					return new \WP_Error( 'http_request_failed', 'timed out' );
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}
}
