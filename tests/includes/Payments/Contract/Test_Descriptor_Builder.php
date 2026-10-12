<?php
/**
 * Payment method descriptor tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WC_Payment_Gateway;
use WC_Payment_Gateways;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Payment method descriptor tests. */
class Test_Descriptor_Builder extends WCPOS_REST_Unit_Test_Case {
	/** Cash exposes the configured increment without losing decimal precision. */
	public function test_descriptor_cash_with_rounding_returns_nearest_increment(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array( 'gateways' => array( 'pos_cash' => array( 'rounding_increment' => '0.05' ) ) )
		);
		$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
		$this->assertSame(
			array(
				'increment' => '0.05',
				'mode'      => 'nearest',
			),
			$descriptor['defaults']['rounding']
		);
	}

	/** Unset, empty, and unsupported values do not enable rounding. */
	public function test_descriptor_cash_without_valid_rounding_returns_null(): void {
		foreach ( array( array(), array( 'rounding_increment' => '' ), array( 'rounding_increment' => '0.2' ) ) as $settings ) {
			update_option( 'woocommerce_pos_settings_payment_gateways', array( 'gateways' => array( 'pos_cash' => $settings ) ) );
			$this->assertNull( Descriptor_Builder::instance()->get( 'pos_cash' )['defaults']['rounding'] );
		}
	}

	/** Card descriptors ignore even a supported rounding value. */
	public function test_descriptor_card_with_rounding_returns_null(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array( 'gateways' => array( 'pos_card' => array( 'rounding_increment' => '0.05' ) ) )
		);
		$this->assertNull( Descriptor_Builder::instance()->get( 'pos_card' )['defaults']['rounding'] );
	}

	/** Cash uses the Free manual-mode defaults. */
	public function test_descriptor_cash_gateway_has_manual_capture_and_cash_defaults(): void {
		// Arrange / Act.
		$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );

		// Assert.
		$this->assertSame( 1, $descriptor['schema'] );
		$this->assertSame( 'cash', $descriptor['kind'] );
		$this->assertSame( 'manual', $descriptor['capture']['mode'] );
		$this->assertNull( $descriptor['capture']['provider'] );
		$this->assertTrue( $descriptor['capture']['webview_available'] );
		$this->assertTrue( $descriptor['capabilities']['change'] );
		$this->assertSame( 'record', $descriptor['capabilities']['offline'] );
		$this->assertSame( 'manual', $descriptor['capabilities']['refunds']['via'] );
		$this->assertSame( 'completed', $descriptor['defaults']['order_status'] );
		$this->assertTrue( $descriptor['defaults']['open_drawer'] );
		$this->assertTrue( $descriptor['pos_enabled'] );
		$this->assertSame( 0, $descriptor['order'] );
	}

	/** Unconfigured gateways use the webview single-tender defaults. */
	public function test_descriptor_webview_gateway_defaults_to_webview_mode_single_tender(): void {
		// Arrange.
		$filter = static function ( array $gateways ): array {
			$gateways[] = Webview_Test_Gateway::class;
			return $gateways;
		};
		add_filter( 'woocommerce_payment_gateways', $filter );
		WC_Payment_Gateways::instance()->init();

		try {
			// Act.
			$descriptor = Descriptor_Builder::instance()->get( 'wcpos_webview_test' );

			// Assert.
			$this->assertSame( 'webview', $descriptor['capture']['mode'] );
			$this->assertFalse( $descriptor['capabilities']['amount']['partial'] );
			$this->assertSame( 'none', $descriptor['capabilities']['offline'] );
			$this->assertFalse( $descriptor['capabilities']['void'] );
			$this->assertSame( 'other', $descriptor['kind'] );
			$this->assertFalse( $descriptor['pos_enabled'] );
			$this->assertSame( 999, $descriptor['order'] );
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $filter );
			WC_Payment_Gateways::instance()->init();
		}
	}

	/** The envelope describes every real registered gateway. */
	public function test_descriptor_envelope_carries_schema_contract_and_every_gateway(): void {
		// Arrange.
		WC()->payment_gateways();
		$gateways = array_filter(
			WC()->payment_gateways->payment_gateways(),
			static function ( $gateway, $id ): bool {
				return $gateway instanceof WC_Payment_Gateway && 'pre_install_woocommerce_payments_promotion' !== $id;
			},
			ARRAY_FILTER_USE_BOTH
		);

		// Act.
		$envelope = Descriptor_Builder::instance()->all();

		// Assert.
		$this->assertSame( array( 'schema', 'contract', 'methods' ), array_keys( $envelope ) );
		$this->assertSame( 1, $envelope['schema'] );
		$this->assertSame( '1.2', $envelope['contract'] );
		$this->assertCount( count( $gateways ), $envelope['methods'] );
	}

	/** Unknown kind filters are rejected. */
	/** A provider-scoped key from the mode filter steers the handler; the wire mode stays bare. */
	public function test_descriptor_provider_scoped_mode_key_uses_scoped_handler_and_bare_wire_mode(): void {
		// Arrange.
		wcpos_register_capture_mode( 'device:acme', Acme_Device_Test_Handler::class );
		$filter = static function ( string $mode, \WC_Payment_Gateway $gateway ): string {
			return 'pos_cash' === $gateway->id ? 'device:acme' : $mode;
		};
		add_filter( 'wcpos_payment_method_capture_mode', $filter, 10, 2 );

		try {
			// Act.
			$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );

			// Assert.
			$this->assertSame( 'device', $descriptor['capture']['mode'] );
			$this->assertSame( 'acme', $descriptor['capture']['provider'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter, 10 );
			$reflection = new \ReflectionClass( \WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::class );
			$property   = $reflection->getProperty( 'instance' );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	public function test_descriptor_kind_filter_unknown_value_falls_back_to_other(): void {
		// Arrange.
		$filter = static function (): string {
			return 'unknown';
		};
		add_filter( 'wcpos_payment_method_kind', $filter );

		try {
			// Act / Assert.
			$this->assertSame( 'other', Descriptor_Builder::instance()->get( 'pos_cash' )['kind'] );
		} finally {
			remove_filter( 'wcpos_payment_method_kind', $filter );
		}
	}

	/** POS settings override the gateway title. */
	public function test_descriptor_title_uses_pos_settings_override(): void {
		// Arrange.
		$filter = static function ( array $settings ): array {
			$settings['gateways']['pos_cash']['title'] = 'Till';
			return $settings;
		};
		add_filter( 'woocommerce_pos_payment_gateways_settings', $filter );

		try {
			// Act / Assert.
			$this->assertSame( 'Till', Descriptor_Builder::instance()->get( 'pos_cash' )['title'] );
		} finally {
			remove_filter( 'woocommerce_pos_payment_gateways_settings', $filter );
		}
	}
}

/** Gateway used to prove the default webview descriptor. */
class Webview_Test_Gateway extends WC_Payment_Gateway {
	/** Constructor. */
	public function __construct() {
		$this->id       = 'wcpos_webview_test';
		$this->title    = 'Webview Test';
		$this->enabled  = 'yes';
		$this->supports = array( 'products' );
	}
}

class Acme_Device_Test_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler {
	/** Describe a device-mode gateway for the Acme provider. */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array(
			'capture' => array(
				'mode'     => 'device',
				'provider' => 'acme',
			),
		);
	}
}
