<?php
/**
 * Tests for the POS payment gateways controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WC_Payment_Gateway;
use WC_Payment_Gateways;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Payment_Gateways_Controller extends WCPOS_REST_Unit_Test_Case {
	public function test_payment_gateways_returns_pos_contract_fields(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );

		$match = wp_list_filter(
			$data,
			array(
				'id' => 'pos_cash',
			)
		);

		$this->assertNotEmpty( $match );

		$gateway = array_shift( $match );

		$this->assertArrayHasKey( 'id', $gateway );
		$this->assertArrayHasKey( 'title', $gateway );
		$this->assertArrayHasKey( 'enabled', $gateway );
		$this->assertArrayHasKey( 'provider', $gateway );
		$this->assertArrayHasKey( 'pos_type', $gateway );
		$this->assertArrayHasKey( 'capabilities', $gateway );
		$this->assertArrayHasKey( 'provider_data', $gateway );
	}

	/**
	 * The POS catalog must not expose WooCommerce's admin settings schema.
	 *
	 * Serializing it forces init_form_fields() on every gateway, which the POS
	 * does not need and which fatals on poorly-behaved third-party gateways.
	 */
	public function test_payment_gateways_omit_admin_settings_schema(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data );

		foreach ( $data as $gateway ) {
			$this->assertArrayNotHasKey( 'settings', $gateway, 'POS catalog must not include the admin settings schema.' );
			$this->assertArrayNotHasKey( 'method_supports', $gateway );
		}
	}

	/**
	 * A gateway that fatals in init_form_fields() must not break the catalog.
	 *
	 * Mirrors real-world gateways (e.g. ToyyibPay) that gate their settings code
	 * behind is_admin() and therefore throw when init_form_fields() runs in a
	 * non-admin REST context.
	 */
	public function test_payment_gateways_do_not_invoke_init_form_fields(): void {
		$gateway = new class() extends WC_Payment_Gateway {
			/**
			 * Set up a minimal gateway without building form fields.
			 */
			public function __construct() {
				$this->id          = 'wcpos_throwing_gateway';
				$this->title       = 'Throwing Gateway';
				$this->description = 'Gateway that explodes on init_form_fields().';
				$this->enabled     = 'yes';
				$this->supports    = array( 'products' );
			}

			/**
			 * Explode, simulating a call to an undefined settings helper.
			 *
			 * @throws \Error Always.
			 */
			public function init_form_fields(): void {
				throw new \Error( 'Call to undefined function tfw_get_settings()' );
			}
		};

		$add_gateway = static function ( $gateways ) use ( $gateway ) {
			$gateways[] = $gateway;

			return $gateways;
		};

		add_filter( 'woocommerce_payment_gateways', $add_gateway );

		$registry                   = WC_Payment_Gateways::instance();
		$registry->payment_gateways = array();
		$registry->init();

		try {
			$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status() );

			$match = wp_list_filter( $data, array( 'id' => 'wcpos_throwing_gateway' ) );
			$this->assertNotEmpty( $match, 'Throwing gateway should still appear in the POS catalog.' );

			$found = array_shift( $match );
			$this->assertSame( 'Throwing Gateway', $found['title'] );
			$this->assertArrayHasKey( 'capabilities', $found );
			$this->assertArrayNotHasKey( 'settings', $found );
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $add_gateway );
			$registry->payment_gateways = array();
			$registry->init();
		}
	}

	public function test_payment_gateways_default_checkout_support_matches_registered_handler(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$cash = array_shift(
			wp_list_filter(
				$data,
				array(
					'id' => 'pos_cash',
				)
			)
		);
		$bacs = array_shift(
			wp_list_filter(
				$data,
				array(
					'id' => 'bacs',
				)
			)
		);

		$this->assertNotEmpty( $cash );
		$this->assertNotEmpty( $bacs );
		$this->assertTrue( $cash['capabilities']['supports_checkout'] );
		$this->assertFalse( $bacs['capabilities']['supports_checkout'] );
	}

	/**
	 * It exposes refund capability flags for built-in and Woo gateways.
	 */
	public function test_payment_gateways_expose_refund_capabilities_for_built_in_and_woo_gateways(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/payment-gateways' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$cash = array_shift( wp_list_filter( $data, array( 'id' => 'pos_cash' ) ) );
		$bacs = array_shift( wp_list_filter( $data, array( 'id' => 'bacs' ) ) );

		$this->assertSame( 'wcpos', $cash['provider'] );
		$this->assertSame( 'manual', $cash['pos_type'] );
		$this->assertFalse( $cash['capabilities']['supports_provider_refunds'] );
		$this->assertFalse( $cash['capabilities']['supports_automatic_refunds'] );
		$this->assertArrayHasKey( 'provider_data', $cash );

		$this->assertArrayHasKey( 'supports_provider_refunds', $bacs['capabilities'] );
		$this->assertArrayHasKey( 'supports_automatic_refunds', $bacs['capabilities'] );
	}

	/**
	 * The catalog uses admin display values when the public title is null.
	 */
	public function test_payment_gateways_null_title_falls_back_to_method_title(): void {
		$this->with_stub_gateway(
			array(
				'id'                 => 'wcpos_catalog_untitled',
				'method_title'       => 'Catalog Method',
				'method_description' => 'Catalog description',
				'enabled'            => 'yes',
				'supports'           => array( 'products' ),
			),
			function () {
				$request  = $this->wp_rest_get_request( '/wcpos/v2/payment-gateways' );
				$response = $this->server->dispatch( $request );
				$this->assertSame( 200, $response->get_status() );

				$match = wp_list_filter( $response->get_data(), array( 'id' => 'wcpos_catalog_untitled' ) );
				$this->assertNotEmpty( $match );
				$found = array_shift( $match );

				$this->assertSame( 'Catalog Method', $found['title'] );
				$this->assertSame( 'Catalog description', $found['description'] );
			}
		);
	}

	/**
	 * The catalog uses the gateway id when both titles are empty.
	 */
	public function test_payment_gateways_empty_titles_fall_back_to_id(): void {
		$this->with_stub_gateway(
			array(
				'id'           => 'wcpos_catalog_nameless',
				'title'        => '',
				'method_title' => '',
			),
			function () {
				$request  = $this->wp_rest_get_request( '/wcpos/v2/payment-gateways' );
				$response = $this->server->dispatch( $request );
				$this->assertSame( 200, $response->get_status() );

				$match = wp_list_filter( $response->get_data(), array( 'id' => 'wcpos_catalog_nameless' ) );
				$this->assertNotEmpty( $match );
				$found = array_shift( $match );

				$this->assertSame( 'wcpos_catalog_nameless', $found['title'] );
			}
		);
	}

	/**
	 * Register a stub gateway with the given public properties for the duration
	 * of the callback, then restore the gateway registry.
	 *
	 * @param array    $props    Property name => value, assigned on the stub (e.g. 'id', 'title', 'method_title').
	 * @param callable $callback Runs while the stub is registered.
	 */
	private function with_stub_gateway( array $props, callable $callback ): void {
		$gateway = new class( $props ) extends WC_Payment_Gateway {
			/**
			 * Assign the given properties without building form fields.
			 *
			 * @param array $props Property name => value.
			 */
			public function __construct( array $props ) {
				foreach ( $props as $name => $value ) {
					$this->$name = $value;
				}
			}
		};

		$add_gateway = static function ( $gateways ) use ( $gateway ) {
			return array_merge( $gateways, array( $gateway ) );
		};

		add_filter( 'woocommerce_payment_gateways', $add_gateway );

		$registry                   = WC_Payment_Gateways::instance();
		$registry->payment_gateways = array();
		$registry->init();

		try {
			$callback();
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $add_gateway );
			$registry->payment_gateways = array();
			$registry->init();
		}
	}
}
