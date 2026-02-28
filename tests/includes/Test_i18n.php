<?php
/**
 * Tests for the i18n class.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\i18n;
use WC_Unit_Test_Case;

/**
 * Tests for the i18n translation loader.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\i18n
 */
class Test_I18n extends WC_Unit_Test_Case { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

	/**
	 * Temp directory for language files during tests.
	 *
	 * @var string
	 */
	private string $temp_lang_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->temp_lang_dir = sys_get_temp_dir() . '/wcpos-i18n-test-' . uniqid() . '/';
		wp_mkdir_p( $this->temp_lang_dir );

		// Clear any cached transients from previous tests.
		delete_transient( 'wcpos_i18n_woocommerce-pos_de_DE' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_da' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_da_DK' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_fr' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_fr_FR' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_missing_da_DK' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_missing_de_DE' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_missing_fr_FR' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_write_failed' );
		delete_transient( 'wcpos_i18n_woocommerce-pos_active_path' );
		delete_transient( 'wcpos_i18n_woocommerce-pos-pro_write_failed' );
		delete_transient( 'wcpos_i18n_woocommerce-pos-pro_active_path' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up temp language files.
		$files = glob( $this->temp_lang_dir . '*' );
		if ( $files ) {
			array_map( 'unlink', $files );
		}
		if ( is_dir( $this->temp_lang_dir ) ) {
			rmdir( $this->temp_lang_dir );
		}

		// Clean up fallback languages dir if created.
		$upload_dir    = wp_upload_dir();
		$fallback_dir  = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
		$fallback_files = glob( $fallback_dir . '*' );
		if ( $fallback_files ) {
			array_map( 'unlink', $fallback_files );
		}
		if ( is_dir( $fallback_dir ) ) {
			rmdir( $fallback_dir );
		}

		// Remove locale and upload_dir filters if set.
		remove_all_filters( 'locale' );
		remove_all_filters( 'upload_dir' );

		parent::tearDown();
	}

	/**
	 * Verify that en_US locale does not trigger downloads.
	 *
	 * @covers ::__construct
	 * @covers ::load_translations
	 */
	public function test_english_locale_skips_loading(): void {
		// en_US should not trigger any HTTP requests.
		add_filter(
			'locale',
			function () {
				return 'en_US';
			}
		);

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No HTTP requests should be made for en_US locale' );
	}

	/**
	 * Verify that empty locale does not trigger downloads.
	 *
	 * @covers ::__construct
	 * @covers ::load_translations
	 */
	public function test_empty_locale_skips_loading(): void {
		add_filter(
			'locale',
			function () {
				return '';
			}
		);

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No HTTP requests should be made for empty locale' );
	}

	/**
	 * Verify that non-English locale triggers a CDN download.
	 *
	 * @covers ::__construct
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_non_english_locale_triggers_download(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function ( $request, $url ) {
			if ( false !== strpos( $url, 'cdn.jsdelivr.net' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => "<?php\nreturn array('messages' => array());",
				);
			}

			return false;
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertNotEmpty( $this->http_requests, 'An HTTP request should be made for de_DE locale' );
	}

	/**
	 * Verify CDN URL includes correct version, locale, and text domain.
	 *
	 * @covers ::download_translation
	 */
	public function test_cdn_url_format_is_correct(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$expected_url = 'https://cdn.jsdelivr.net/gh/wcpos/translations@1.8.7/translations/php/de_DE/woocommerce-pos-de_DE.l10n.php';
		$this->assertEquals( $expected_url, $this->http_requests[0]['url'] );
	}

	/**
	 * Verify successful download writes the translation file to disk.
	 *
	 * @covers ::download_translation
	 */
	public function test_successful_download_saves_file(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$file_content = "<?php\nreturn array('messages' => array('Hello' => 'Hallo'));";
		$this->http_responder = function ( $request, $url ) use ( $file_content ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $file_content,
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		$this->assertFileExists( $file, 'Translation file should be saved to languages directory' );
	}

	/**
	 * Verify successful download caches the version in a transient.
	 *
	 * @covers ::load_translations
	 */
	public function test_successful_download_sets_version_transient(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$cached = get_transient( 'wcpos_i18n_woocommerce-pos_de_DE' );
		$this->assertEquals( '1.8.7', $cached, 'Transient should store the current version after successful download' );
	}

	/**
	 * Verify cached version transient prevents re-download.
	 *
	 * @covers ::load_translations
	 */
	public function test_cached_version_skips_download(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Pre-create the file and set the transient to simulate a cached translation.
		$file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		file_put_contents( $file, "<?php\nreturn array('messages' => array());" );
		set_transient( 'wcpos_i18n_woocommerce-pos_de_DE', '1.8.7', WEEK_IN_SECONDS );

		$this->http_responder = function ( $request, $url ) {
			$this->fail( 'No HTTP request should be made when translation is cached' );
			return false;
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No download should occur when file exists and version matches' );
	}

	/**
	 * Verify version bump triggers a fresh download.
	 *
	 * @covers ::load_translations
	 */
	public function test_version_change_triggers_redownload(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Pre-create the file with an old version transient.
		$file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		file_put_contents( $file, "<?php\nreturn array('messages' => array());" );
		set_transient( 'wcpos_i18n_woocommerce-pos_de_DE', '1.8.6', WEEK_IN_SECONDS );

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array('Updated' => 'Aktualisiert'));",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertNotEmpty( $this->http_requests, 'Version change should trigger a new download' );

		$cached = get_transient( 'wcpos_i18n_woocommerce-pos_de_DE' );
		$this->assertEquals( '1.8.7', $cached, 'Transient should be updated to the new version' );
	}

	/**
	 * Verify HTTP transport errors are handled gracefully.
	 *
	 * @covers ::download_translation
	 */
	public function test_failed_http_request_returns_gracefully(): void {
		add_filter(
			'locale',
			function () {
				return 'fr_FR';
			}
		);

		delete_transient( 'wcpos_i18n_woocommerce-pos_fr_FR' );

		$this->http_responder = function ( $request, $url ) {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		};

		// Should not throw.
		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$file = $this->temp_lang_dir . 'woocommerce-pos-fr_FR.l10n.php';
		$this->assertFileDoesNotExist( $file, 'No file should be saved when HTTP request fails' );

		$cached = get_transient( 'wcpos_i18n_woocommerce-pos_fr_FR' );
		$this->assertFalse( $cached, 'No transient should be set when download fails' );
	}

	/**
	 * Verify non-200 HTTP response does not save a file.
	 *
	 * @covers ::download_translation
	 */
	public function test_non_200_response_does_not_save_file(): void {
		add_filter(
			'locale',
			function () {
				return 'fr_FR';
			}
		);

		delete_transient( 'wcpos_i18n_woocommerce-pos_fr_FR' );

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => 'Not Found',
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$file = $this->temp_lang_dir . 'woocommerce-pos-fr_FR.l10n.php';
		$this->assertFileDoesNotExist( $file, 'No file should be saved for a 404 response' );
	}

	/**
	 * Verify empty response body does not save a file.
	 *
	 * @covers ::download_translation
	 */
	public function test_empty_response_body_does_not_save_file(): void {
		add_filter(
			'locale',
			function () {
				return 'fr_FR';
			}
		);

		delete_transient( 'wcpos_i18n_woocommerce-pos_fr_FR' );

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$file = $this->temp_lang_dir . 'woocommerce-pos-fr_FR.l10n.php';
		$this->assertFileDoesNotExist( $file, 'No file should be saved when response body is empty' );
	}

	/**
	 * Verify custom text domain stores version under its own transient key.
	 *
	 * @covers ::__construct
	 */
	public function test_custom_text_domain_uses_correct_transient_key(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos-pro', '1.8.7', $this->temp_lang_dir );

		$cached = get_transient( 'wcpos_i18n_woocommerce-pos-pro_de_DE' );
		$this->assertEquals( '1.8.7', $cached, 'Custom text domain should use its own transient key' );
	}

	/**
	 * Verify custom text domain builds CDN URL with its own slug.
	 *
	 * @covers ::__construct
	 */
	public function test_custom_text_domain_builds_correct_cdn_url(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos-pro', '2.0.0', $this->temp_lang_dir );

		$expected_url = 'https://cdn.jsdelivr.net/gh/wcpos/translations@2.0.0/translations/php/de_DE/woocommerce-pos-pro-de_DE.l10n.php';
		$this->assertEquals( $expected_url, $this->http_requests[0]['url'] );
	}

	/**
	 * Verify missing file with no transient triggers a download.
	 *
	 * @covers ::load_translations
	 */
	public function test_missing_file_with_no_transient_triggers_download(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// No file exists, no transient set — should download.
		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 1, $this->http_requests, 'Exactly one download request should be made' );
	}

	/**
	 * Verify expired transient triggers a re-download even when file exists.
	 *
	 * @covers ::load_translations
	 */
	public function test_expired_transient_triggers_redownload(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Create the file but let the transient be absent (simulating expiry).
		$file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		file_put_contents( $file, "<?php\nreturn array('messages' => array());" );
		// Don't set a transient — simulates expiration.

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array('New' => 'Neu'));",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertNotEmpty( $this->http_requests, 'Expired transient should trigger a re-download even if file exists' );
	}

	/**
	 * Verify download creates the languages directory when missing.
	 *
	 * @covers ::download_translation
	 */
	public function test_download_creates_languages_directory_if_missing(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Use a nested path that doesn't exist yet.
		$nested_dir = $this->temp_lang_dir . 'nested/subdir/';

		$this->http_responder = function ( $request, $url ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $nested_dir );

		$this->assertDirectoryExists( $nested_dir, 'Languages directory should be created if it does not exist' );

		// Clean up nested dirs.
		$file = $nested_dir . 'woocommerce-pos-de_DE.l10n.php';
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $nested_dir ) ) {
			rmdir( $nested_dir );
		}
		$parent = $this->temp_lang_dir . 'nested/';
		if ( is_dir( $parent ) ) {
			rmdir( $parent );
		}
	}

	/**
	 * Verify HTTP request uses a 10-second timeout.
	 *
	 * @covers ::download_translation
	 */
	public function test_download_uses_10_second_timeout(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$captured_timeout = null;
		$this->http_responder = function ( $request, $url ) use ( &$captured_timeout ) {
			$captured_timeout = $request['timeout'] ?? null;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEquals( 10, $captured_timeout, 'HTTP request should use a 10-second timeout' );
	}

	/**
	 * Verify regional locale falls back to base language on 404.
	 *
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_regional_locale_falls_back_to_base_language(): void {
		add_filter(
			'locale',
			function () {
				return 'da_DK';
			}
		);

		$this->http_responder = function ( $request, $url ) {
			if ( false !== strpos( $url, '/da_DK/' ) ) {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => 'Not Found',
				);
			}

			if ( false !== strpos( $url, '/da/' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => "<?php\nreturn array('messages' => array('Hello' => 'Hej'));",
				);
			}

			return false;
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 2, $this->http_requests, 'Regional locale should try exact locale then base language' );
		$this->assertStringContainsString( '/da_DK/woocommerce-pos-da_DK.l10n.php', $this->http_requests[0]['url'] );
		$this->assertStringContainsString( '/da/woocommerce-pos-da.l10n.php', $this->http_requests[1]['url'] );

		$fallback_file = $this->temp_lang_dir . 'woocommerce-pos-da.l10n.php';
		$this->assertFileExists( $fallback_file, 'Fallback base language file should be downloaded' );
	}

	/**
	 * Verify 404 for all candidates caches missing locale to avoid retries.
	 *
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_missing_locale_is_cached_to_avoid_repeated_download_attempts(): void {
		add_filter(
			'locale',
			function () {
				return 'fr_FR';
			}
		);

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => 'Not Found',
			);
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 2, $this->http_requests, 'Missing locale should attempt exact locale then base language once' );
		$this->assertEquals( '1.8.7', get_transient( 'wcpos_i18n_woocommerce-pos_missing_fr_FR' ), 'Missing locale should be cached at current version' );

		$this->http_requests  = array();
		$this->http_responder = function ( $request, $url ) {
			$this->fail( 'No HTTP request should be made while missing locale cache is valid' );
			return false;
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No requests should be made while missing locale is cached' );
	}

	/**
	 * Verify non-404 failures do not cache missing locale.
	 *
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_non_404_failures_do_not_cache_missing_locale(): void {
		add_filter(
			'locale',
			function () {
				return 'fr_FR';
			}
		);

		$this->http_responder = function () {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 2, $this->http_requests, 'Should try exact locale and base locale on first attempt' );
		$this->assertFalse( get_transient( 'wcpos_i18n_woocommerce-pos_missing_fr_FR' ), 'Transient should not cache missing locale for transport errors' );

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 4, $this->http_requests, 'Should retry downloads on subsequent attempts when failure was not 404' );
	}

	/**
	 * Verify write falls back to uploads directory when primary path fails.
	 *
	 * @covers ::download_translation
	 */
	public function test_write_falls_back_to_uploads_dir(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array('Hello' => 'Hallo'));",
			);
		};

		// Block primary path by placing a file where a directory is expected.
		$blocker        = $this->temp_lang_dir . 'blocked';
		file_put_contents( $blocker, 'x' );
		$unwritable_dir = $blocker . '/lang/';

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

		// File should exist at the uploads fallback path.
		$upload_dir   = wp_upload_dir();
		$fallback_dir = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
		$fallback_file = $fallback_dir . 'woocommerce-pos-de_DE.l10n.php';
		$this->assertFileExists( $fallback_file, 'Translation file should be written to uploads fallback path' );

		// Primary unwritable path should not have the file.
		$primary_file = $unwritable_dir . 'woocommerce-pos-de_DE.l10n.php';
		$this->assertFileDoesNotExist( $primary_file, 'Translation file should not exist at unwritable primary path' );

		// Clean up blocker file.
		unlink( $blocker );
	}

	/**
	 * Verify fallback path sets the active path transient to uploads.
	 *
	 * @covers ::download_translation
	 */
	public function test_fallback_path_sets_active_path_transient(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		$blocker        = $this->temp_lang_dir . 'blocked';
		file_put_contents( $blocker, 'x' );
		$unwritable_dir = $blocker . '/lang/';

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

		$this->assertEquals(
			'uploads',
			get_transient( 'wcpos_i18n_woocommerce-pos_active_path' ),
			'Active path transient should be set to "uploads" after fallback write succeeds'
		);

		unlink( $blocker );
	}

	/**
	 * Verify primary write success does not set the fallback path transient.
	 *
	 * @covers ::download_translation
	 */
	public function test_primary_write_success_does_not_set_fallback_transient(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		// temp_lang_dir is writable — primary write should succeed.
		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertFalse(
			get_transient( 'wcpos_i18n_woocommerce-pos_active_path' ),
			'Active path transient should not be set when primary write succeeds'
		);

		// Uploads fallback dir should not exist.
		$upload_dir   = wp_upload_dir();
		$fallback_dir = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
		$this->assertDirectoryDoesNotExist( $fallback_dir, 'Fallback directory should not be created when primary write succeeds' );
	}

	/**
	 * Verify write failure at both locations sets the write-failed transient.
	 *
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_write_failure_at_both_locations_is_cached(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		// Block both primary and fallback paths.
		$blocker = $this->temp_lang_dir . 'blocked';
		file_put_contents( $blocker, 'x' );
		$unwritable_dir = $blocker . '/lang/';

		add_filter(
			'upload_dir',
			function ( $dirs ) use ( $blocker ) {
				$dirs['basedir'] = $blocker . '/uploads';
				return $dirs;
			}
		);

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

		$this->assertEquals(
			'1.8.7',
			get_transient( 'wcpos_i18n_woocommerce-pos_write_failed' ),
			'Write failure transient should be set when both locations fail'
		);

		unlink( $blocker );
	}

	/**
	 * Verify cached write failure skips download attempts.
	 *
	 * @covers ::load_translations
	 */
	public function test_cached_write_failure_skips_download(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Pre-set the write-failure transient.
		set_transient( 'wcpos_i18n_woocommerce-pos_write_failed', '1.8.7' );

		$this->http_responder = function () {
			$this->fail( 'No HTTP request should be made when write failure is cached' );
			return false;
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No downloads should occur when write failure is cached' );
	}

	/**
	 * Verify cached write failure still loads stale translation file.
	 *
	 * @covers ::load_translations
	 */
	public function test_cached_write_failure_still_loads_stale_file(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Create a stale file (no matching version transient).
		$stale_file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		file_put_contents( $stale_file, "<?php\nreturn array('messages' => array('Hello' => 'Hallo'));" );

		// Pre-set the write-failure transient.
		set_transient( 'wcpos_i18n_woocommerce-pos_write_failed', '1.8.7' );

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No downloads should occur' );
		// Verify the text domain was loaded (stale file used).
		$this->assertTrue( is_textdomain_loaded( 'woocommerce-pos' ), 'Stale translation file should be loaded when write failure is cached' );
	}

	/**
	 * Verify successful download clears the write-failed transient.
	 *
	 * @covers ::load_translations
	 */
	public function test_successful_download_clears_write_failure_cache(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		// Pre-set the write-failure transient from a previous version.
		set_transient( 'wcpos_i18n_woocommerce-pos_write_failed', '1.8.6' );

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		// Version 1.8.7 won't match the 1.8.6 transient, so download proceeds.
		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertFalse(
			get_transient( 'wcpos_i18n_woocommerce-pos_write_failed' ),
			'Write failure transient should be cleared after successful download'
		);
	}

	/**
	 * Verify write failure sets write-failed transient, not missing-locale.
	 *
	 * @covers ::load_translations
	 */
	public function test_write_failure_does_not_set_missing_locale_cache(): void {
		add_filter(
			'locale',
			function () {
				return 'de_DE';
			}
		);

		$this->http_responder = function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => "<?php\nreturn array('messages' => array());",
			);
		};

		// Block both paths.
		$blocker = $this->temp_lang_dir . 'blocked';
		file_put_contents( $blocker, 'x' );
		$unwritable_dir = $blocker . '/lang/';

		add_filter(
			'upload_dir',
			function ( $dirs ) use ( $blocker ) {
				$dirs['basedir'] = $blocker . '/uploads';
				return $dirs;
			}
		);

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $unwritable_dir );

		$this->assertFalse(
			get_transient( 'wcpos_i18n_woocommerce-pos_missing_de_DE' ),
			'Missing-locale transient should NOT be set when failure is a write error, not a 404'
		);
		$this->assertEquals(
			'1.8.7',
			get_transient( 'wcpos_i18n_woocommerce-pos_write_failed' ),
			'Write failure transient should be set instead'
		);

		unlink( $blocker );
	}

	/**
	 * Verify constructor uses WP_LANG_DIR/plugins/ as default languages path.
	 *
	 * @covers ::__construct
	 */
	public function test_default_languages_path_is_wp_lang_dir_plugins(): void {
		// Use en_US to skip translation loading (avoids HTTP requests).
		add_filter(
			'locale',
			function () {
				return 'en_US';
			}
		);

		$i18n = new i18n( 'woocommerce-pos', '1.8.7' );

		$reflection = new \ReflectionProperty( i18n::class, 'languages_path' );
		$reflection->setAccessible( true );

		$this->assertEquals(
			WP_LANG_DIR . '/plugins/',
			$reflection->getValue( $i18n ),
			'Default languages path should be WP_LANG_DIR/plugins/'
		);
	}

	/**
	 * Verify constructor uses uploads fallback when active_path transient is set.
	 *
	 * @covers ::__construct
	 */
	public function test_remembered_fallback_path_used_on_construction(): void {
		add_filter(
			'locale',
			function () {
				return 'en_US';
			}
		);

		// Simulate a previous session that fell back to uploads.
		set_transient( 'wcpos_i18n_woocommerce-pos_active_path', 'uploads' );

		$i18n = new i18n( 'woocommerce-pos', '1.8.7' );

		$reflection = new \ReflectionProperty( i18n::class, 'languages_path' );
		$reflection->setAccessible( true );

		$upload_dir    = wp_upload_dir();
		$expected_path = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';

		$this->assertEquals(
			$expected_path,
			$reflection->getValue( $i18n ),
			'Constructor should use uploads fallback when active_path transient is set'
		);
	}
}
