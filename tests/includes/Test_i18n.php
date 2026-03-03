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
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\i18n
 */
class Test_i18n extends WC_Unit_Test_Case {

	/**
	 * Temp directory for language files during tests.
	 *
	 * @var string
	 */
	private string $temp_lang_dir;

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
		delete_transient( 'wcpos_i18n_woocommerce-pos_missing_fr_FR' );
	}

	public function tearDown(): void {
		// Clean up temp language files.
		$files = glob( $this->temp_lang_dir . '*' );
		if ( $files ) {
			array_map( 'unlink', $files );
		}
		if ( is_dir( $this->temp_lang_dir ) ) {
			rmdir( $this->temp_lang_dir );
		}

		// Remove locale filter if set.
		remove_all_filters( 'locale' );

		parent::tearDown();
	}

	/**
	 * @covers ::__construct
	 * @covers ::load_translations
	 */
	public function test_english_locale_skips_loading(): void {
		// en_US should not trigger any HTTP requests.
		add_filter( 'locale', function () {
			return 'en_US';
		});

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No HTTP requests should be made for en_US locale' );
	}

	/**
	 * @covers ::__construct
	 * @covers ::load_translations
	 */
	public function test_empty_locale_skips_loading(): void {
		add_filter( 'locale', function () {
			return '';
		});

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertEmpty( $this->http_requests, 'No HTTP requests should be made for empty locale' );
	}

	/**
	 * @covers ::__construct
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_non_english_locale_triggers_download(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::download_translation
	 */
	public function test_cdn_url_format_is_correct(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::download_translation
	 */
	public function test_successful_download_saves_file(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::load_translations
	 */
	public function test_successful_download_sets_version_transient(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::load_translations
	 */
	public function test_cached_version_skips_download(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::load_translations
	 */
	public function test_version_change_triggers_redownload(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::download_translation
	 */
	public function test_failed_http_request_returns_gracefully(): void {
		add_filter( 'locale', function () {
			return 'fr_FR';
		});

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
	 * @covers ::download_translation
	 */
	public function test_non_200_response_does_not_save_file(): void {
		add_filter( 'locale', function () {
			return 'fr_FR';
		});

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
	 * @covers ::download_translation
	 */
	public function test_empty_response_body_does_not_save_file(): void {
		add_filter( 'locale', function () {
			return 'fr_FR';
		});

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
	 * @covers ::__construct
	 */
	public function test_custom_text_domain_uses_correct_transient_key(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::__construct
	 */
	public function test_custom_text_domain_builds_correct_cdn_url(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::load_translations
	 */
	public function test_missing_file_with_no_transient_triggers_download(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::load_translations
	 */
	public function test_expired_transient_triggers_redownload(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::download_translation
	 */
	public function test_download_creates_languages_directory_if_missing(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::download_translation
	 */
	public function test_download_uses_10_second_timeout(): void {
		add_filter( 'locale', function () {
			return 'de_DE';
		});

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
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_regional_locale_falls_back_to_base_language(): void {
		add_filter( 'locale', function () {
			return 'da_DK';
		});

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
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_missing_locale_is_cached_to_avoid_repeated_download_attempts(): void {
		add_filter( 'locale', function () {
			return 'fr_FR';
		});

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
	 * @covers ::load_translations
	 * @covers ::download_translation
	 */
	public function test_non_404_failures_do_not_cache_missing_locale(): void {
		add_filter( 'locale', function () {
			return 'fr_FR';
		});

		$this->http_responder = function () {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		};

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 2, $this->http_requests, 'Should try exact locale and base locale on first attempt' );
		$this->assertFalse( get_transient( 'wcpos_i18n_woocommerce-pos_missing_fr_FR' ), 'Transient should not cache missing locale for transport errors' );

		$i18n = new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertCount( 4, $this->http_requests, 'Should retry downloads on subsequent attempts when failure was not 404' );
	}
}
