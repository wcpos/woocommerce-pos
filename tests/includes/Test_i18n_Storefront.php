<?php
/**
 * Storefront requests load an existing translation file and nothing more.
 *
 * Measured 2026-09-03 on dev-next (see
 * .claude/research/2026-09-03-online-store-footprint.md): the loader read
 * three transients per plugin on every request — the active path, the locale
 * version and a "missing locale" marker whose delete is a guaranteed miss —
 * six queries per storefront page on Pro. A shopper's page only needs the
 * file that is already on disk; keeping it fresh (version checks, downloads,
 * format conversion, corrupt-file repair) is admin/POS/REST/cron work.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Admin\Permalink;
use WCPOS\WooCommercePOS\i18n;
use WC_Unit_Test_Case;

/**
 * @covers \WCPOS\WooCommercePOS\i18n
 */
class Test_I18n_Storefront extends WC_Unit_Test_Case {
	private string $temp_lang_dir;

	/** @var string[] URLs the loader tried to fetch. */
	private array $fetched_urls = array();

	/** @var string[] SQL touching the loader's markers. */
	private array $marker_queries = array();

	/** @var callable */
	private $http_spy;

	/** @var callable */
	private $query_spy;

	public function setUp(): void {
		parent::setUp();
		// WordPress parses loaded translation files lazily; drop any file an
		// earlier test class loaded from a temp dir it has since deleted. The
		// unload_textdomain() wrapper skips the controller when the $l10n global
		// was already reset by the test framework, so go to the controller.
		unload_textdomain( 'woocommerce-pos', true );
		\WP_Translation_Controller::get_instance()->unload_textdomain( 'woocommerce-pos' );
		$this->temp_lang_dir = sys_get_temp_dir() . '/wcpos-i18n-storefront-' . uniqid() . '/';
		wp_mkdir_p( $this->temp_lang_dir );
		add_filter(
			'locale',
			static function () {
				return 'de_DE';
			}
		);
		add_filter( 'woocommerce_pos_i18n_maintain', '__return_false' );

		$this->fetched_urls = array();
		$this->http_spy     = function ( $pre, $args, $url ) {
			$this->fetched_urls[] = (string) $url;
			return new \WP_Error( 'test_blocked', 'no network in tests' );
		};
		add_filter( 'pre_http_request', $this->http_spy, 10, 3 );

		$this->marker_queries = array();
		$this->query_spy      = function ( $query ) {
			if ( false !== strpos( (string) $query, 'wcpos_i18n' ) ) {
				$this->marker_queries[] = (string) $query;
			}
			return $query;
		};
		add_filter( 'query', $this->query_spy );
	}

	public function tearDown(): void {
		remove_filter( 'query', $this->query_spy );
		remove_filter( 'pre_http_request', $this->http_spy, 10 );
		remove_all_filters( 'woocommerce_pos_i18n_maintain' );
		remove_all_filters( 'locale' );
		$this->remove_dir( $this->temp_lang_dir );
		$this->remove_dir( $this->fallback_dir() );
		unload_textdomain( 'woocommerce-pos' );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the test environment.
		parent::tearDown();
	}

	public function test_storefront_request_loads_an_existing_file_with_no_marker_reads_and_no_download(): void {
		file_put_contents( $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php', "<?php\nreturn array('messages' => array('Receipt' => 'Beleg'));" );

		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertSame( 'Beleg', __( 'Receipt', 'woocommerce-pos' ) );
		$this->assertSame( array(), $this->fetched_urls, 'A storefront request never downloads.' );
		$this->assertSame( array(), $this->marker_queries, 'A storefront request never reads or writes the loader markers.' );
	}

	public function test_storefront_request_falls_back_to_the_base_language_file(): void {
		file_put_contents( $this->temp_lang_dir . 'woocommerce-pos-de.l10n.php', "<?php\nreturn array('messages' => array('Receipt' => 'Beleg (de)'));" );

		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertSame( 'Beleg (de)', __( 'Receipt', 'woocommerce-pos' ) );
		$this->assertSame( array(), $this->fetched_urls );
		$this->assertSame( array(), $this->marker_queries );
	}

	public function test_storefront_request_loads_a_file_from_the_uploads_fallback_directory(): void {
		// Stores whose WP_LANG_DIR is unwritable keep their translations under
		// uploads. The .mo path handed to WordPress must point at THAT directory.
		wp_mkdir_p( $this->fallback_dir() );
		file_put_contents( $this->fallback_dir() . 'woocommerce-pos-de_DE.l10n.php', "<?php\nreturn array('messages' => array('Receipt' => 'Beleg (uploads)'));" );

		new i18n( 'woocommerce-pos', '1.8.7' );

		$this->assertSame( 'Beleg (uploads)', __( 'Receipt', 'woocommerce-pos' ) );
		$this->assertSame( array(), $this->fetched_urls );
		$this->assertSame( array(), $this->marker_queries );
	}

	public function test_storefront_request_prefers_the_active_uploads_copy_over_a_stale_primary_copy(): void {
		$text_domain = 'wcpos-i18n-active-path-test';
		$name        = $text_domain . '-de_DE.l10n.php';
		$primary     = WP_LANG_DIR . '/plugins/';
		wp_mkdir_p( $primary );
		wp_mkdir_p( $this->fallback_dir() );
		file_put_contents( $primary . $name, "<?php\nreturn array('messages' => array('Receipt' => 'Beleg (stale)'));" );
		file_put_contents( $this->fallback_dir() . $name, "<?php\nreturn array('messages' => array('Receipt' => 'Beleg (uploads)'));" );
		set_transient( 'wcpos_i18n_' . $text_domain . '_active_path', 'uploads', MONTH_IN_SECONDS );

		try {
			new i18n( $text_domain, '1.8.7' );

			$this->assertSame( 'Beleg (uploads)', __( 'Receipt', $text_domain ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- isolated test domain prevents overwriting a real translation.
		} finally {
			wp_delete_file( $primary . $name );
			delete_transient( 'wcpos_i18n_' . $text_domain . '_active_path' );
			unload_textdomain( $text_domain, true );
		}
	}

	public function test_storefront_request_skips_a_corrupt_primary_file_for_the_uploads_copy(): void {
		$text_domain = 'wcpos-i18n-candidate-test';
		$name        = $text_domain . '-de_DE.l10n.php';
		$primary     = WP_LANG_DIR . '/plugins/';
		wp_mkdir_p( $primary );
		wp_mkdir_p( $this->fallback_dir() );
		file_put_contents( $primary . $name, "<?php\nreturn array('messages' => array(" );
		file_put_contents( $this->fallback_dir() . $name, "<?php\nreturn array('messages' => array('Receipt' => 'Beleg (uploads)'));" );

		try {
			new i18n( $text_domain, '1.8.7' );

			$this->assertSame( 'Beleg (uploads)', __( 'Receipt', $text_domain ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- isolated test domain prevents overwriting a real translation.
		} finally {
			wp_delete_file( $primary . $name );
			unload_textdomain( $text_domain, true );
		}
	}

	public function test_storefront_request_skips_an_old_format_regional_file_for_the_base_locale(): void {
		file_put_contents( $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php', "<?php\nreturn array('Receipt' => 'Beleg (flat)');" );
		file_put_contents( $this->temp_lang_dir . 'woocommerce-pos-de.l10n.php', "<?php\nreturn array('messages' => array('Receipt' => 'Beleg (de)'));" );

		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertSame( 'Beleg (de)', __( 'Receipt', 'woocommerce-pos' ) );
	}

	public function test_storefront_request_leaves_an_old_format_file_alone(): void {
		// The flat pre-6.5 format needs converting; that is a write, so it is
		// maintenance work. The storefront path neither converts nor loads it.
		$file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		$body = "<?php\nreturn array('Receipt' => 'Beleg (flat)');";
		file_put_contents( $file, $body );

		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertSame( 'Receipt', __( 'Receipt', 'woocommerce-pos' ) );
		$this->assertSame( $body, file_get_contents( $file ), 'A storefront request must not rewrite the file.' );
		$this->assertSame( array(), $this->marker_queries );
	}

	public function test_storefront_request_leaves_a_corrupt_file_alone(): void {
		$file = $this->temp_lang_dir . 'woocommerce-pos-de_DE.l10n.php';
		file_put_contents( $file, "<?php\nreturn array('messages' => array(" );

		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertFileExists( $file, 'Deleting a corrupt file is maintenance work, not storefront work.' );
		$this->assertSame( array(), $this->marker_queries, 'No version marker is cleared on the storefront path.' );
	}

	public function test_storefront_request_without_a_file_does_nothing(): void {
		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertSame( 'Receipt', __( 'Receipt', 'woocommerce-pos' ) );
		$this->assertSame( array(), $this->fetched_urls, 'Fetching the file is maintenance work, not storefront work.' );
		$this->assertSame( array(), $this->marker_queries );
	}

	public function test_maintenance_request_still_downloads_when_the_file_is_missing(): void {
		remove_all_filters( 'woocommerce_pos_i18n_maintain' );
		add_filter( 'woocommerce_pos_i18n_maintain', '__return_true' );

		new i18n( 'woocommerce-pos', '1.8.7', $this->temp_lang_dir );

		$this->assertNotEmpty( $this->fetched_urls, 'Admin/POS/REST/cron requests keep the translations fresh.' );
	}

	/**
	 * @dataProvider maintenance_lanes
	 */
	public function test_request_lanes_are_classified( string $label, callable $arrange, bool $expected ): void {
		remove_all_filters( 'woocommerce_pos_i18n_maintain' );
		$previous_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored only to restore the test environment's value.
		$previous_get = $_GET;
		set_current_screen( 'front' );

		$restore = $arrange();
		$actual  = i18n::is_maintenance_request();
		if ( \is_callable( $restore ) ) {
			$restore();
		}

		$_GET = $previous_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- restoring the test environment.
		if ( null === $previous_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $previous_uri;
		}
		$this->assertSame( $expected, $actual, $label );
	}

	public function maintenance_lanes(): array {
		$uri = static function ( string $path ): callable {
			return static function () use ( $path ) {
				$_SERVER['REQUEST_URI'] = $path;
				return null;
			};
		};
		return array(
			'plain storefront GET'          => array( 'A shop page is not maintenance.', $uri( '/shop/' ), false ),
			'wp-admin'                      => array(
				'wp-admin is.',
				static function () {
					set_current_screen( 'dashboard' );
					return static function () {
						set_current_screen( 'front' );
					};
				},
				true,
			),
			'REST incl. Store API'          => array( 'REST (the Store API included) is.', $uri( '/wp-json/wc/store/v1/cart' ), true ),
			'REST via rest_route'           => array( 'Plain-permalink REST is.', $uri( '/?rest_route=/wcpos/v2/products' ), true ),
			'raw POS query marker'           => array( 'The pre-rewrite wcpos query marker is.', $uri( '/?wcpos=1' ), true ),
			'cron'                          => array(
				'Cron is.',
				static function () {
					add_filter( 'wp_doing_cron', '__return_true' );
					return static function () {
						remove_filter( 'wp_doing_cron', '__return_true' );
					};
				},
				true,
			),
			'wc-ajax shopper traffic'       => array(
				'WooCommerce wc-ajax (cart fragments, add to cart, classic checkout) is shopper traffic even though WooCommerce marks it DOING_AJAX.',
				static function () {
					$_SERVER['REQUEST_URI'] = '/?wc-ajax=get_refreshed_fragments';
					$_GET['wc-ajax']        = 'get_refreshed_fragments';
					add_filter( 'wp_doing_ajax', '__return_true' );
					return static function () {
						remove_filter( 'wp_doing_ajax', '__return_true' );
					};
				},
				false,
			),
			'browser-loaded POS route'      => array( 'The POS app page (rewrite rules not yet parsed at init) is.', $uri( '/' . Permalink::get_slug() . '/' ), true ),
			'browser-loaded POS route under a subdirectory home' => array(
				'The POS app page under a subdirectory home is.',
				static function () {
					$_SERVER['REQUEST_URI'] = '/shop/' . Permalink::get_slug() . '/';
					$home_url_filter        = static function ( string $url ): string {
						return rtrim( $url, '/' ) . '/shop';
					};
					add_filter( 'home_url', $home_url_filter );
					return static function () use ( $home_url_filter ) {
						remove_filter( 'home_url', $home_url_filter );
					};
				},
				true,
			),
			'browser-loaded wcpos-auth'     => array( 'The POS login route is.', $uri( '/wcpos-auth/?redirect_uri=x' ), true ),
			'browser-loaded wcpos-checkout' => array( 'The POS checkout route is.', $uri( '/wcpos-checkout/order-pay/12/' ), true ),
			'a product that starts with the slug' => array( 'A storefront path that merely starts with the letters is not.', $uri( '/' . Permalink::get_slug() . 'tcards/' ), false ),
		);
	}

	private function fallback_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'wcpos-languages/';
	}

	private function remove_dir( string $dir ): void {
		$files = glob( $dir . '*' );
		foreach ( \is_array( $files ) ? $files : array() as $file ) {
			unlink( $file );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
}
