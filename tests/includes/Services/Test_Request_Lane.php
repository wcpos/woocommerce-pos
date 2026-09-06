<?php
/**
 * The request lane classifier decides which POS services a request constructs.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Admin\Permalink;
use WCPOS\WooCommercePOS\Services\Request_Lane;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Services\Request_Lane
 */
class Test_Request_Lane extends WP_UnitTestCase {
	/** @var string|null */
	private $previous_uri;

	/** @var array */
	private $previous_get;

	public function setUp(): void {
		parent::setUp();
		$this->previous_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored only to restore the test environment's value.
		$this->previous_get = $_GET;
		set_current_screen( 'front' );
		// The suite pins the REST lane (tests/bootstrap.php); this file tests detection.
		remove_filter( 'woocommerce_pos_request_lane', array( \WCPOS\WooCommercePOS\Tests\Bootstrap::class, 'default_request_lane' ) );
		Request_Lane::reset();
	}

	public function tearDown(): void {
		$_GET = $this->previous_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- restoring the test environment.
		if ( null === $this->previous_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->previous_uri;
		}
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the test environment.
		remove_all_filters( 'woocommerce_pos_request_lane' );
		remove_all_filters( 'wp_doing_cron' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'home_url' );
		add_filter( 'woocommerce_pos_request_lane', array( \WCPOS\WooCommercePOS\Tests\Bootstrap::class, 'default_request_lane' ) );
		Request_Lane::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider lanes
	 */
	public function test_requests_are_classified_by_lane( string $label, callable $arrange, string $expected ): void {
		$arrange();
		Request_Lane::reset();

		$this->assertSame( $expected, Request_Lane::current(), $label );
		$this->assertSame( Request_Lane::STOREFRONT === $expected, Request_Lane::is_storefront(), $label );
	}

	public function lanes(): array {
		$uri = static function ( string $path ): callable {
			return static function () use ( $path ): void {
				$_SERVER['REQUEST_URI'] = $path;
			};
		};
		return array(
			'shop page'                    => array( 'A shop page is storefront.', $uri( '/shop/' ), Request_Lane::STOREFRONT ),
			'product page'                 => array( 'A product page is storefront.', $uri( '/product/hat/' ), Request_Lane::STOREFRONT ),
			'wc-ajax'                      => array(
				'wc-ajax is shopper traffic even though WooCommerce marks it DOING_AJAX.',
				static function (): void {
					$_SERVER['REQUEST_URI'] = '/?wc-ajax=add_to_cart';
					$_GET['wc-ajax']        = 'add_to_cart';
					add_filter( 'wp_doing_ajax', '__return_true' );
				},
				Request_Lane::STOREFRONT,
			),
			'wc-api webhook'               => array(
				'A gateway webhook / IPN is storefront by design: the order write itself constructs the order services.',
				$uri( '/wc-api/WC_Gateway_Paypal/?ipn=1' ),
				Request_Lane::STOREFRONT,
			),
			'wp-admin'                     => array(
				'wp-admin is the admin lane.',
				static function (): void {
					set_current_screen( 'dashboard' );
				},
				Request_Lane::ADMIN,
			),
			'cron'                         => array(
				'Cron is its own lane.',
				static function (): void {
					add_filter( 'wp_doing_cron', '__return_true' );
				},
				Request_Lane::CRON,
			),
			'Store API'                    => array( 'REST, the Store API included.', $uri( '/wp-json/wc/store/v1/checkout' ), Request_Lane::REST ),
			'plain-permalink REST'         => array( 'REST via rest_route.', $uri( '/?rest_route=/wcpos/v2/products' ), Request_Lane::REST ),
			'raw POS marker'               => array( 'The pre-rewrite ?wcpos=1 marker.', $uri( '/?wcpos=1' ), Request_Lane::POS ),
			'POS app route'                => array( 'The browser-loaded POS page.', $uri( '/' . Permalink::get_slug() . '/' ), Request_Lane::POS ),
			'POS login route'              => array( 'The POS login route.', $uri( '/wcpos-auth/?redirect_uri=x' ), Request_Lane::POS ),
			'POS checkout route'           => array( 'The POS checkout route.', $uri( '/wcpos-checkout/order-pay/12/' ), Request_Lane::POS ),
			'POS route under a subdir home' => array(
				'A subdirectory install still recognises the POS page.',
				static function (): void {
					$_SERVER['REQUEST_URI'] = '/shop/' . Permalink::get_slug() . '/';
					add_filter(
						'home_url',
						static function ( string $url ): string {
							return rtrim( $url, '/' ) . '/shop';
						}
					);
				},
				Request_Lane::POS,
			),
			'slug prefix collision'        => array( 'A product whose path merely starts with the slug letters is storefront.', $uri( '/' . Permalink::get_slug() . 'tcards/' ), Request_Lane::STOREFRONT ),
		);
	}

	public function test_the_lane_is_memoised_and_filterable(): void {
		$_SERVER['REQUEST_URI'] = '/shop/';
		Request_Lane::reset();
		$this->assertSame( Request_Lane::STOREFRONT, Request_Lane::current() );

		// Changing the environment after the first read does not change the lane...
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
		$this->assertSame( Request_Lane::STOREFRONT, Request_Lane::current(), 'One lane per request.' );

		// ...and a host can force one.
		Request_Lane::reset();
		add_filter(
			'woocommerce_pos_request_lane',
			static function (): string {
				return Request_Lane::POS;
			}
		);
		$this->assertSame( Request_Lane::POS, Request_Lane::current() );
	}
}
