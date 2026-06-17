<?php
/**
 * Tests for the POS payment gateway contract helper.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments
 */

namespace WCPOS\WooCommercePOS\Tests\Payments;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\Payments\Abstract_POS_Gateway;
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * POS gateway contract tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Gateway_Contract extends WCPOS_REST_Unit_Test_Case {
	/**
	 * It exposes a PHP interface/base for POS gateway adapters.
	 */
	public function test_pos_gateway_contract_types_are_available(): void {
		$this->assertTrue( interface_exists( 'WCPOS\\WooCommercePOS\\Payments\\Gateway_Adapter_Interface' ) );
		$this->assertTrue( class_exists( 'WCPOS\\WooCommercePOS\\Payments\\Abstract_POS_Gateway' ) );
		$this->assertTrue( class_exists( 'WCPOS\\WooCommercePOS\\Payments\\Filter_Gateway_Adapter' ) );
	}

	/**
	 * It reads direct PHP interface adapters before falling back to filters.
	 */
	public function test_interface_gateway_supplies_contract_without_legacy_filters(): void {
		$this->ensure_interface_only_gateway_class();

		$class_name = __NAMESPACE__ . '\\Interface_Only_Test_Gateway';
		$gateway    = new $class_name();
		$request    = new WP_REST_Request( 'GET', '/wcpos/v1/payment-gateways' );
		$contract   = new Gateway_Contract();

		$this->assertFalse( has_action( 'wcpos_process_checkout_action_wcpos_interface_only' ) );
		$this->assertSame( 'interface_provider', $contract->get_provider( $gateway, $request ) );
		$this->assertSame( 'terminal', $contract->infer_pos_type( $gateway, $request ) );
		$this->assertSame( array( 'reader_group' => 'front-counter' ), $contract->get_provider_data( $gateway, $request ) );
		$this->assertTrue( $contract->supports_checkout( $gateway, $request ) );
		$this->assertSame(
			array(
				'supports_checkout'           => true,
				'supports_automatic_refunds'  => true,
				'supports_provider_refunds'   => true,
				'requires_hardware'           => true,
			),
			$contract->get_capabilities( $gateway, $request )
		);
		$this->assertSame(
			array(
				'gateway_id'    => 'wcpos_interface_only',
				'status'        => 'ready',
				'expires_at'    => '2030-01-01T00:00:00Z',
				'provider_data' => array( 'reader_group' => 'front-counter' ),
			),
			$contract->get_bootstrap_response( 'wcpos_interface_only', array(), $request, $gateway )
		);

		if ( ! method_exists( $contract, 'process_checkout_action' ) ) {
			$this->fail( 'Gateway_Contract must dispatch checkout through the POS gateway adapter interface.' );
		}

		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'wcpos_interface_only',
				'total'          => '12.00',
			)
		);
		$state = $contract->process_checkout_action( $gateway, $order->get_id(), 'start', array( 'reader_id' => 'rdr_1' ), $order, $request );

		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 'wcpos_interface_only', $state['gateway_id'] );
		$this->assertSame( array( 'reader_id' => 'rdr_1', 'source' => 'interface' ), $state['provider_data'] );
	}

	/**
	 * It applies passive legacy checkout filters after the direct adapter runs.
	 */
	public function test_interface_gateway_checkout_is_not_shadowed_by_passive_legacy_filter(): void {
		$this->ensure_interface_only_gateway_class();

		$class_name     = __NAMESPACE__ . '\\Interface_Only_Test_Gateway';
		$gateway        = new $class_name();
		$request        = new WP_REST_Request( 'GET', '/wcpos/v1/payment-gateways' );
		$contract       = new Gateway_Contract();
		$passive_filter = static function ( $state ) {
			$state['provider_data']['observed_status'] = $state['status'] ?? '';

			return $state;
		};

		add_filter( 'wcpos_process_checkout_action_wcpos_interface_only', $passive_filter, 10, 5 );

		try {
			$this->assertNotFalse( has_action( 'wcpos_process_checkout_action_wcpos_interface_only' ) );

			$order = OrderHelper::create_order(
				array(
					'payment_method' => 'wcpos_interface_only',
					'total'          => '12.00',
				)
			);
			$state = $contract->process_checkout_action( $gateway, $order->get_id(), 'start', array( 'reader_id' => 'rdr_1' ), $order, $request );

			$this->assertSame( 'completed', $state['status'] );
			$this->assertSame( 'interface', $state['provider_data']['source'] );
			$this->assertSame( 'completed', $state['provider_data']['observed_status'] );
		} finally {
			remove_filter( 'wcpos_process_checkout_action_wcpos_interface_only', $passive_filter, 10 );
		}
	}

	/**
	 * It honors a direct adapter that disables checkout while keeping legacy hooks.
	 */
	public function test_direct_adapter_can_disable_checkout_with_legacy_hook_registered(): void {
		$gateway = new class() extends Abstract_POS_Gateway {
			public function __construct() {
				$this->id          = 'wcpos_disabled_adapter';
				$this->title       = 'Disabled Adapter';
				$this->description = '';
				$this->enabled     = 'yes';
				$this->supports    = array( 'products' );

				$this->register_pos_gateway_contract_hooks();
			}

			public function supports_pos_checkout( ?WP_REST_Request $request = null ): bool {
				return false;
			}

			public function process_pos_checkout_action( array $state, string $action, array $payment_data, \WC_Order $order, ?WP_REST_Request $request = null ) {
				return $state;
			}
		};

		try {
			$this->assertNotFalse( has_action( 'wcpos_process_checkout_action_wcpos_disabled_adapter' ) );
			$this->assertFalse( ( new Gateway_Contract() )->supports_checkout( $gateway, new WP_REST_Request( 'GET', '/wcpos/v1/payment-gateways' ) ) );
		} finally {
			remove_filter( 'wcpos_payment_gateway_provider', array( $gateway, 'wcpos_provider' ), 10 );
			remove_filter( 'wcpos_payment_gateway_pos_type', array( $gateway, 'wcpos_pos_type' ), 10 );
			remove_filter( 'wcpos_payment_gateway_provider_data', array( $gateway, 'wcpos_provider_data' ), 10 );
			remove_filter( 'wcpos_payment_gateway_bootstrap', array( $gateway, 'wcpos_bootstrap' ), 10 );
			remove_filter( 'wcpos_process_checkout_action_wcpos_disabled_adapter', array( $gateway, 'wcpos_process_checkout_action' ), 10 );
		}
	}

	/**
	 * It preserves the public WordPress filter contract as the compatibility shim.
	 */
	public function test_filter_gateway_adapter_preserves_legacy_hook_contract(): void {
		$gateway = new class() extends WC_Payment_Gateway {
			public function __construct() {
				$this->id          = 'wcpos_legacy_filter';
				$this->title       = 'Legacy Filter';
				$this->description = '';
				$this->enabled     = 'yes';
				$this->supports    = array( 'products' );
			}
		};
		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/payment-gateways' );
		$contract = new Gateway_Contract();

		$provider = static function ( $value, $candidate ) {
			return $candidate instanceof WC_Payment_Gateway && 'wcpos_legacy_filter' === $candidate->id ? 'legacy_provider' : $value;
		};
		$pos_type = static function ( $value, $candidate ) {
			return $candidate instanceof WC_Payment_Gateway && 'wcpos_legacy_filter' === $candidate->id ? 'terminal' : $value;
		};
		$provider_data = static function ( $value, $candidate ) {
			return $candidate instanceof WC_Payment_Gateway && 'wcpos_legacy_filter' === $candidate->id ? array( 'legacy' => true ) : $value;
		};
		$supports_checkout = static function ( $value, $candidate ) {
			return $candidate instanceof WC_Payment_Gateway && 'wcpos_legacy_filter' === $candidate->id ? true : $value;
		};
		$supports_automatic_refunds = static function ( $value, $candidate ) {
			return $candidate instanceof WC_Payment_Gateway && 'wcpos_legacy_filter' === $candidate->id ? true : $value;
		};
		$supports_provider_refunds = static function ( $value, $candidate ) {
			return $candidate instanceof WC_Payment_Gateway && 'wcpos_legacy_filter' === $candidate->id ? true : $value;
		};
		$bootstrap = static function ( $value, $gateway_id ) {
			return 'wcpos_legacy_filter' === $gateway_id
				? array(
					'gateway_id'    => 'wcpos_legacy_filter',
					'status'        => 'requires_action',
					'expires_at'    => '2031-01-01T00:00:00Z',
					'provider_data' => array( 'legacy' => true ),
				)
				: $value;
		};
		$process_checkout = static function ( $state, $action, $payment_data ) {
			$state['status']        = 'completed';
			$state['provider_data'] = array(
				'action'       => $action,
				'payment_data' => $payment_data,
				'source'       => 'legacy_filter',
			);

			return $state;
		};

		add_filter( 'wcpos_payment_gateway_provider', $provider, 10, 3 );
		add_filter( 'wcpos_payment_gateway_pos_type', $pos_type, 10, 3 );
		add_filter( 'wcpos_payment_gateway_provider_data', $provider_data, 10, 3 );
		add_filter( 'wcpos_payment_gateway_supports_checkout', $supports_checkout, 10, 3 );
		add_filter( 'wcpos_payment_gateway_supports_automatic_refunds', $supports_automatic_refunds, 10, 3 );
		add_filter( 'wcpos_payment_gateway_supports_provider_refunds', $supports_provider_refunds, 10, 3 );
		add_filter( 'wcpos_payment_gateway_bootstrap', $bootstrap, 10, 4 );
		add_filter( 'wcpos_process_checkout_action_wcpos_legacy_filter', $process_checkout, 10, 5 );

		try {
			$this->assertSame( 'legacy_provider', $contract->get_provider( $gateway, $request ) );
			$this->assertSame( 'terminal', $contract->infer_pos_type( $gateway, $request ) );
			$this->assertSame( array( 'legacy' => true ), $contract->get_provider_data( $gateway, $request ) );
			$this->assertTrue( $contract->supports_checkout( $gateway, $request ) );
			$this->assertTrue( $contract->get_capabilities( $gateway, $request )['supports_automatic_refunds'] );
			$this->assertTrue( $contract->get_capabilities( $gateway, $request )['supports_provider_refunds'] );
			$this->assertSame( 'requires_action', $contract->get_bootstrap_response( 'wcpos_legacy_filter', array(), $request )['status'] );

			if ( ! method_exists( $contract, 'process_checkout_action' ) ) {
				$this->fail( 'Gateway_Contract must preserve checkout processing through the legacy filter shim.' );
			}

			$order = OrderHelper::create_order(
				array(
					'payment_method' => 'wcpos_legacy_filter',
					'total'          => '12.00',
				)
			);
			$state = $contract->process_checkout_action( $gateway, $order->get_id(), 'start', array( 'reader_id' => 'rdr_2' ), $order, $request );

			$this->assertSame( 'completed', $state['status'] );
			$this->assertSame( 'legacy_filter', $state['provider_data']['source'] );
			$this->assertSame( array( 'reader_id' => 'rdr_2' ), $state['provider_data']['payment_data'] );
		} finally {
			remove_filter( 'wcpos_payment_gateway_provider', $provider, 10 );
			remove_filter( 'wcpos_payment_gateway_pos_type', $pos_type, 10 );
			remove_filter( 'wcpos_payment_gateway_provider_data', $provider_data, 10 );
			remove_filter( 'wcpos_payment_gateway_supports_checkout', $supports_checkout, 10 );
			remove_filter( 'wcpos_payment_gateway_supports_automatic_refunds', $supports_automatic_refunds, 10 );
			remove_filter( 'wcpos_payment_gateway_supports_provider_refunds', $supports_provider_refunds, 10 );
			remove_filter( 'wcpos_payment_gateway_bootstrap', $bootstrap, 10 );
			remove_filter( 'wcpos_process_checkout_action_wcpos_legacy_filter', $process_checkout, 10 );
		}
	}

	/**
	 * Ensure the interface-only test gateway exists after the production interface is loaded.
	 */
	private function ensure_interface_only_gateway_class(): void {
		if ( ! interface_exists( 'WCPOS\\WooCommercePOS\\Payments\\Gateway_Adapter_Interface' ) ) {
			$this->fail( 'Gateway_Adapter_Interface must exist for direct POS gateway adapters.' );
		}

		if ( class_exists( __NAMESPACE__ . '\\Interface_Only_Test_Gateway', false ) ) {
			return;
		}

		eval(
			'namespace ' . __NAMESPACE__ . ';' .
			'class Interface_Only_Test_Gateway extends \\WC_Payment_Gateway implements \\WCPOS\\WooCommercePOS\\Payments\\Gateway_Adapter_Interface {' .
			'public function __construct() { $this->id = "wcpos_interface_only"; $this->title = "Interface Only"; $this->description = ""; $this->enabled = "yes"; $this->supports = array( "products", "refunds" ); }' .
			'public function get_pos_provider( ?\\WP_REST_Request $request = null ): string { return "interface_provider"; }' .
			'public function get_pos_type( ?\\WP_REST_Request $request = null ): string { return "terminal"; }' .
			'public function get_pos_provider_data( ?\\WP_REST_Request $request = null ): array { return array( "reader_group" => "front-counter" ); }' .
			'public function supports_pos_checkout( ?\\WP_REST_Request $request = null ): bool { return true; }' .
			'public function supports_pos_automatic_refunds( ?\\WP_REST_Request $request = null ): bool { return true; }' .
			'public function supports_pos_provider_refunds( ?\\WP_REST_Request $request = null ): bool { return true; }' .
			'public function get_pos_bootstrap_response( array $context, ?\\WP_REST_Request $request = null ): array { return array( "gateway_id" => $this->id, "status" => "ready", "expires_at" => "2030-01-01T00:00:00Z", "provider_data" => $this->get_pos_provider_data( $request ) ); }' .
			'public function process_pos_checkout_action( array $state, string $action, array $payment_data, \\WC_Order $order, ?\\WP_REST_Request $request = null ) { $state["status"] = "completed"; $state["provider_data"] = array( "reader_id" => $payment_data["reader_id"] ?? "", "source" => "interface" ); return $state; }' .
			'}'
		);
	}
}
