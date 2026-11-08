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
	/** The default modes list contains the primary connection and its capabilities. */
	public function test_descriptor_default_modes_contains_primary_only(): void {
		$capture = Descriptor_Builder::instance()->get( 'pos_cash' )['capture'];
		$this->assertSame(
			array(
				array(
					'mode' => 'manual',
					'provider' => null,
					'hardware' => null,
					'capabilities' => array(
						'amount' => array( 'partial' => true ),
						'change' => true,
						'refunds' => array(
							'via' => 'manual',
							'partial' => true,
						),
						'tips' => 'none',
						'offline' => 'record',
						'void' => true,
					),
					'provider_data' => array(),
				),
			),
			$capture['modes']
		);
		$this->assertSame( array_intersect_key( $capture['modes'][0], array_flip( array( 'mode', 'provider', 'hardware' ) ) ), array_intersect_key( $capture, array_flip( array( 'mode', 'provider', 'hardware' ) ) ) );
	}

	/** Ordered entries keep their own normalized capabilities and provider data. */
	public function test_descriptor_multiple_modes_preserves_order_hardware_and_primary(): void {
		wcpos_register_capture_mode( 'server:cloud', Cloud_Server_Test_Handler::class );
		wcpos_register_capture_mode( 'device:acme', Acme_Device_Test_Handler::class );
		$filter = static fn() => array( 'server:cloud', 'device:acme', 'server:cloud' );
		add_filter( 'wcpos_payment_method_capture_modes', $filter );
		try {
			$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
			$this->assertSame(
				array(
					array(
						'mode' => 'server',
						'provider' => 'cloud',
						'hardware' => array( 'connection' => 'internet' ),
						'capabilities' => array(
							'amount' => array( 'partial' => false ),
							'change' => false,
							'refunds' => array(
								'via' => 'none',
								'partial' => false,
							),
							'tips' => 'none',
							'offline' => 'none',
							'void' => true,
						),
						'provider_data' => array( 'location' => 'shop' ),
					),
					array(
						'mode' => 'device',
						'provider' => 'acme',
						'hardware' => array( 'connection' => 'bluetooth' ),
						'capabilities' => array(
							'amount' => array(
								'partial' => true,
								'min' => '1.20',
								'max' => '99.00',
							),
							'change' => false,
							'refunds' => array(
								'via' => 'provider',
								'partial' => true,
							),
							'tips' => 'on_reader',
							'offline' => 'queue',
							'void' => false,
						),
						'provider_data' => array( 'reader' => 'bluetooth-reader' ),
					),
				),
				$descriptor['capture']['modes']
			);
			$this->assertSame( 'server', $descriptor['capture']['mode'] );
			$this->assertSame( 'cloud', $descriptor['capture']['provider'] );
			$this->assertSame( array( 'connection' => 'internet' ), $descriptor['capture']['hardware'] );
			$this->assertTrue( $descriptor['capture']['webview_available'] );
			$this->assertTrue( $descriptor['capabilities']['void'] );
			$this->assertSame( $descriptor['capture']['modes'][0]['capabilities'], $descriptor['capabilities'] );
			$this->assertSame( array( 'location' => 'shop' ), $descriptor['provider_data'] );
			$this->assertArrayNotHasKey( 'secret', $descriptor['capture'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $filter );
			$this->reset_capture_registry();
		}
	}

	/** A filter that answers a bare string, the singular hook's shape, is read as one key instead of fataling. */
	public function test_descriptor_string_filter_answer_keeps_primary(): void {
		$filter = static fn() => 'manual';
		add_filter( 'wcpos_payment_method_capture_modes', $filter );
		try {
			$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
			$this->assertSame( 'manual', $descriptor['capture']['mode'] );
			$this->assertSame( array( 'manual' ), array_column( $descriptor['capture']['modes'], 'mode' ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $filter );
		}
	}

	/** A bare key and a provider-scoped key for the same mode name one connection. */
	public function test_descriptor_scoped_and_bare_keys_for_one_mode_make_one_entry(): void {
		wcpos_register_capture_mode( 'device:acme', Acme_Device_Test_Handler::class );
		wcpos_register_capture_mode( 'device', Acme_Device_Test_Handler::class );
		$filter = static fn() => array( 'device', 'device:acme' );
		add_filter( 'wcpos_payment_method_capture_modes', $filter );
		try {
			$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
			$this->assertSame( array( 'device' ), array_column( $descriptor['capture']['modes'], 'mode' ) );
			$this->assertSame( 'device', $descriptor['capture']['mode'] );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $filter );
			$this->reset_capture_registry();
		}
	}

	/** Missing handlers cannot replace the first resolved handler or appear on the wire. */
	public function test_descriptor_missing_mode_is_dropped_without_changing_primary(): void {
		$filter = static fn() => array( 'missing_before', 'manual', 'missing_after' );
		add_filter( 'wcpos_payment_method_capture_modes', $filter );
		try {
			$capture = Descriptor_Builder::instance()->get( 'pos_cash' )['capture'];
			$this->assertSame( 'manual', $capture['mode'] );
			$this->assertSame(
				array(
					array(
						'mode' => 'manual',
						'provider' => null,
						'hardware' => null,
						'capabilities' => array(
							'amount' => array( 'partial' => true ),
							'change' => true,
							'refunds' => array(
								'via' => 'manual',
								'partial' => true,
							),
							'tips' => 'none',
							'offline' => 'record',
							'void' => true,
						),
						'provider_data' => array(),
					),
				),
				$capture['modes']
			);
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $filter );
		}
	}

	/** An empty resolved set still advertises the webview fallback. */
	public function test_descriptor_no_resolved_modes_falls_back_to_webview(): void {
		$filter = static fn() => array( 'missing_fallback' );
		add_filter( 'wcpos_payment_method_capture_modes', $filter );
		try {
			$capture = Descriptor_Builder::instance()->get( 'pos_cash' )['capture'];
			$this->assertSame( 'webview', $capture['mode'] );
			$this->assertSame(
				array(
					array(
						'mode' => 'webview',
						'provider' => null,
						'hardware' => null,
						'capabilities' => array(
							'amount' => array( 'partial' => false ),
							'change' => false,
							'refunds' => array(
								'via' => 'provider',
								'partial' => true,
							),
							'tips' => 'none',
							'offline' => 'none',
							'void' => false,
						),
						'provider_data' => array(),
					),
				),
				$capture['modes']
			);
		} finally {
			remove_filter( 'wcpos_payment_method_capture_modes', $filter );
		}
	}

	/** A lookup returns the complete matching entry or null for a disallowed mode. */
	public function test_capture_entry_returns_matching_entry_or_null(): void {
		$descriptor = Descriptor_Builder::instance()->get( 'pos_cash' );
		$this->assertSame( $descriptor['capture']['modes'][0], Descriptor_Builder::capture_entry( $descriptor, 'manual' ) );
		$this->assertNull( Descriptor_Builder::capture_entry( $descriptor, 'device' ) );
	}

	/** Restore the singleton after registering test providers. */
	private function reset_capture_registry(): void {
		$reflection = new \ReflectionClass( \WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry::class );
		$property = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

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
		$this->assertTrue( Descriptor_Builder::instance()->get( 'pos_card' )['capture']['webview_available'] );
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
		$this->assertFalse( $descriptor['capture']['webview_available'] );
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
		$this->assertSame( '1.3', $envelope['contract'] );
		$this->assertCount( count( $gateways ), $envelope['methods'] );
	}

	/** The envelope reports payments contract 1.3 (ADR 0040, spec §6a.1). */
	public function test_descriptor_envelope_reports_contract_1_3(): void {
		// Act.
		$envelope = Descriptor_Builder::instance()->all();

		// Assert.
		$this->assertSame( '1.3', $envelope['contract'] );
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
		add_filter( 'wcpos_payment_gateways_settings', $filter );

		try {
			// Act / Assert.
			$this->assertSame( 'Till', Descriptor_Builder::instance()->get( 'pos_cash' )['title'] );
		} finally {
			remove_filter( 'wcpos_payment_gateways_settings', $filter );
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

/** Device fixture with independent capabilities and provider data. */
class Acme_Device_Test_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler {
	/**
	 * Describe a device-mode gateway for the Acme provider.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array(
			'capture' => array(
				'mode'     => 'device',
				'provider' => 'acme',
				'hardware' => array( 'connection' => 'bluetooth' ),
			),
			'capabilities' => array(
				'amount' => array(
					'partial' => 1,
					'min' => '1.2',
					'max' => 99,
				),
				'refunds' => array(
					'via' => 'provider',
					'partial' => 1,
				),
				'tips' => 'on_reader',
				'offline' => 'queue',
			),
			'provider_data' => array( 'reader' => 'bluetooth-reader' ),
		);
	}
}

/** Cloud reader fixture with a distinct descriptor and primary-only fields. */
class Cloud_Server_Test_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Abstract_Capture_Mode_Handler {
	/**
	 * Describe the cloud reader.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array(
			'capture' => array(
				'mode' => 'server',
				'provider' => 'cloud',
				'hardware' => array( 'connection' => 'internet' ),
				'webview_available' => true,
				'secret' => 'not-on-the-wire',
			),
			'capabilities' => array( 'void' => true ),
			'provider_data' => array( 'location' => 'shop' ),
		);
	}
}
