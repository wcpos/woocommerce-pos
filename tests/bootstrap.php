<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\CodeHacker;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\BypassFinalsHack;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\FunctionsMockerHack;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\StaticMockerHack;
use Automattic\WooCommerce\Testing\Tools\DependencyManagement\MockableLegacyProxy;
use ReflectionException;
use ReflectionProperty;

error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

class Bootstrap {
	public $tests_dir;
	public $plugin_dir;
	protected static $instance = null;

	public function __construct() {
		ini_set( 'display_errors', 'on' );
		error_reporting( E_ALL );
		$this->tests_dir  = $this->get_test_dir();
		$this->plugin_dir = \dirname( __DIR__, 1 );

		// Require composer dependencies.
		require_once $this->plugin_dir . '/vendor/autoload.php';
		// $this->initialize_code_hacker();

		// Bootstrap WP_Mock to initialize built-in features
		// NOTE: CodeHacker and WP_Mock are not compatible :(
		// WP_Mock::bootstrap();

		// Give access to tests_add_filter() function.
		require_once $this->tests_dir . '/includes/functions.php';

		// Do not try to load JavaScript files from an external URL - this takes a
		// while.
		\define( 'GUTENBERG_LOAD_VENDOR_SCRIPTS', false );

		tests_add_filter( 'muplugins_loaded', array( $this, 'manually_load_plugin' ) );
		tests_add_filter( 'muplugins_loaded', array( $this, 'install_woocommerce' ) );

		// Start up the WP testing environment.
		tests_add_filter( 'wp_die_handler', array( $this, 'fail_if_died' ) ); // handle bootstrap errors
		require $this->tests_dir . '/includes/bootstrap.php';
		$this->includes();

		// re-initialize dependency injection, this needs to be the last operation after everything else is in place.
		// $this->initialize_dependency_injection();

		// Use existing behavior for wp_die during actual test execution.
		remove_filter( 'wp_die_handler', array( $this, 'fail_if_died' ) );
	}

	/**
	 * Determine the tests directory (from a WP dev checkout).
	 */
	public function get_test_dir(): string {
		// Try the WP_TESTS_DIR environment variable first.
		$_tests_dir = getenv( 'WP_TESTS_DIR' );

		// Next, try the WP_PHPUNIT composer package.
		if ( ! $_tests_dir ) {
			$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
		}

		// See if we're installed inside an existing WP dev instance.
		if ( ! $_tests_dir ) {
			$_try_tests_dir = __DIR__ . '/../../../../../tests/phpunit';
			if ( file_exists( $_try_tests_dir . '/includes/functions.php' ) ) {
				$_tests_dir = $_try_tests_dir;
			}
		}
		// Fallback.
		if ( ! $_tests_dir ) {
			$_tests_dir = '/tmp/wordpress-tests-lib';
		}

		return $_tests_dir;
	}

	/**
	 * Manually load the plugin being tested.
	 */
	public function manually_load_plugin(): void {
		require $this->plugin_dir . '/woocommerce-pos.php';
		require_once $this->plugin_dir . '/includes/wcpos-functions.php';
	}

	/**
	 * Install WooCommerce.
	 */
	public function install_woocommerce(): void {
		require $this->plugin_dir . '/../woocommerce/woocommerce.php';
		// Clean existing install first.
		// define( 'WP_UNINSTALL_PLUGIN', true );
		// define( 'WC_REMOVE_ALL_DATA', true );
		// require dirname( dirname( __FILE__ ) ) . '/../woocommerce/uninstall.php';
		// WC_Install::install();
		// echo esc_html( 'Installing WooCommerce...' . PHP_EOL );
	}

	/**
	 * Load the WooCommerce test framework so we can reuse some of its functionality.
	 */
	public function includes(): void {
		require_once $this->plugin_dir . '/tests/framework/wp-http-testcase.php';
		require_once $this->plugin_dir . '/tests/framework/class-wc-unit-test-case.php';
		require_once $this->plugin_dir . '/tests/framework/class-wc-rest-unit-test-case.php';
		require_once $this->plugin_dir . '/tests/framework/class-wc-unit-test-factory.php';
		require_once $this->plugin_dir . '/tests/framework/class-wp-test-spy-rest-server.php';

		// Helpers
		require_once $this->plugin_dir . '/tests/Helpers/ProductHelper.php';
		require_once $this->plugin_dir . '/tests/Helpers/OrderHelper.php';
		require_once $this->plugin_dir . '/tests/Helpers/CustomerHelper.php';
		require_once $this->plugin_dir . '/tests/Helpers/CouponHelper.php';
		require_once $this->plugin_dir . '/tests/Helpers/ShippingHelper.php';
		require_once $this->plugin_dir . '/tests/Helpers/HPOSToggleTrait.php';
	}

	/**
	 * Adds a wp_die handler for use during tests.
	 *
	 * If bootstrap.php triggers wp_die, it will not cause the script to fail. This
	 * means that tests will look like they passed even though they should have
	 * failed. So we throw an exception if WordPress dies during test setup. This
	 * way the failure is observable.
	 *
	 * @param string|WP_Error $message The error message.
	 *
	 * @throws Exception When a `wp_die()` occurs.
	 */
	public function fail_if_died( $message ): void {
		if ( is_wp_error( $message ) ) {
			$message = $message->get_error_message();
		}

		throw new \Exception( 'WordPress died: ' . $message );
	}

	public static function instance() {
		if ( \is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the code hacker.
	 *
	 * @throws Exception Error when initializing one of the hacks.
	 */
	private function initialize_code_hacker(): void {
		require_once $this->plugin_dir . '/tests/Tools/CodeHacking/Hacks/CodeHack.php';
		require_once $this->plugin_dir . '/tests/Tools/CodeHacking/Hacks/BypassFinalsHack.php';
		require_once $this->plugin_dir . '/tests/Tools/CodeHacking/Hacks/FunctionsMockerHack.php';
		require_once $this->plugin_dir . '/tests/Tools/CodeHacking/Hacks/StaticMockerHack.php';
		require_once $this->plugin_dir . '/tests/Tools/CodeHacking/CodeHacker.php';

		/*
		 * I can't get CodeHacker to work with my includes
		 * But it needs to be here otherwise I can't use the WC TEST Helpers
		 *
		 * Why is everything in WordPress such a fucking nightmare to work with?
		 */
		CodeHacker::initialize( array( __DIR__ . '/../includes/' ) );

		$replaceable_functions = include_once __DIR__ . '/mockable-functions.php';
		if ( ! empty( $replaceable_functions ) ) {
			FunctionsMockerHack::initialize( $replaceable_functions );
			CodeHacker::add_hack( FunctionsMockerHack::get_hack_instance() );
		}

		$mockable_static_classes = include_once __DIR__ . '/classes-with-mockable-static-methods.php';
		if ( ! empty( $mockable_static_classes ) ) {
			StaticMockerHack::initialize( $mockable_static_classes );
			CodeHacker::add_hack( StaticMockerHack::get_hack_instance() );
		}

		CodeHacker::add_hack( new BypassFinalsHack() );

		CodeHacker::enable();
	}

	/**
	 * Re-initialize the dependency injection engine.
	 *
	 * This adjusts the container for testing, enabling the use of mockable proxies and other test-specific overrides.
	 */
	// private function initialize_dependency_injection(): void {
	// Check if WooCommerce provides a testing container.
	// if ( ! class_exists( \Automattic\WooCommerce\Internal\DependencyManagement\TestingContainer::class ) ) {
	// throw new \Exception( 'TestingContainer class is not available in the current WooCommerce version.' );
	// }

	// Create a new TestingContainer instance.
	// $testing_container = new \Automattic\WooCommerce\Internal\DependencyManagement\TestingContainer();

	// Replace the legacy proxy with a mockable version for testing.
	// $testing_container->register(
	// LegacyProxy::class,
	// function () {
	// return new MockableLegacyProxy();
	// }
	// );

	// Replace the global WooCommerce container with the testing container.
	// \Automattic\WooCommerce\Container::set( $testing_container );

	// Store the container globally for test access if necessary.
	// $GLOBALS['wc_container'] = $testing_container;
	// }
}

Bootstrap::instance();
