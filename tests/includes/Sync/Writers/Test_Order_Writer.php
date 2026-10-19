<?php
/** Order writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer;
use WP_UnitTestCase;

/** Pins server-authoritative order shaping. */
class Test_Order_Writer extends WP_UnitTestCase {
	public function test_after_update_preserves_supplied_context_without_in_request_preparation(): void {
		$old = self::factory()->user->create();
		$current = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $current );
		$order = wc_create_order();
		$order->update_meta_data( '_pos_user', (string) $old );
		$order->set_billing_email( 'old@example.com' );
		$order->save();
		$context = array(
			'prepare_under_lock' => true,
			'reassignment' => array( '_pos_user' => (string) $current ),
			'store_authorized' => false,
			'pre_store' => null,
			'clear_email' => true,
		);
		( new Order_Writer( new \stdClass() ) )->after_update( $order->get_id(), array(), array(), array(), $context );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( (string) $current, $saved->get_meta( '_pos_user' ) );
		$this->assertSame( '', $saved->get_billing_email() );
	}

	public function test_create_transition_locks_then_reads_the_new_orders_money(): void {
		$order = wc_create_order( array( 'status' => 'pos-open' ) );
		$order->set_total( 10 );
		$order->save();
		$writer = new class( new \stdClass() ) extends Order_Writer {
			public function lock( int $id ): bool {
				$result = parent::lock( $id );
				$order = wc_get_order( $id );
				$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( array( 'id' => 'arrived', 'amount' => '10', 'status' => 'captured' ) ) ) );
				$order->save();
				return $result;
			}
		};
		$prepared = $writer->prepare_create( array( 'route' => '/wc/v3/orders' ), array( 'meta_data' => array( array( 'key' => '_wcpos_completed_at', 'value' => '2026-10-18T12:00:00Z' ) ) ), static fn() => null );
		$writer->forward( $prepared, static fn() => new \WP_REST_Response( array( 'id' => $order->get_id() ), 201 ) );
		$this->assertSame( '2026-10-18T12:00:00Z', wc_get_order( $order->get_id() )->get_meta( '_wcpos_completed_at' ) );
		$lock = new \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			$response = ( new Order_Writer( new \stdClass() ) )->forward( $prepared, static fn() => new \WP_REST_Response( array( 'id' => $order->get_id() ), 201 ) );
			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 'woo_rxdb_sync_record_locked', $response->get_error_code() );
			$this->assertSame( 409, $response->get_error_data()['status'] );
			$this->assertSame( $order->get_id(), $response->get_error_data()['order_id'] );
		} finally { $lock->release( $order->get_id() ); }
	}

	/** Strip client-owned copies of server fields. */
	public function test_prepare_create_shapes_order_audit_fields(): void {
		$prepared = ( new Order_Writer( new \stdClass() ) )->prepare_create(
			array( 'route' => '/wc/v3/orders' ),
			array( 'tax_ids' => array(), 'meta_data' => array( array( 'key' => '_pos_user', 'value' => '999' ), array( 'key' => 'public', 'value' => 'kept' ) ) ),
			static function () { return null; }
		);
		$this->assertSame( 'woocommerce-pos', $prepared['payload']['created_via'] );
		$this->assertArrayNotHasKey( 'tax_ids', $prepared['payload'] );
		$this->assertSame( array( array( 'key' => 'public', 'value' => 'kept' ) ), $prepared['payload']['meta_data'] );
	}

	public function test_legacy_owner_strips_coupon_aggregate_but_keeps_intent(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_wcpos_money_owner', 'server' );
		\WCPOS\WooCommercePOS\Payments\Contract\Money_Meta_Guard::authorised( $order->get_id(), static fn() => $order->save() );
		$writer = new Order_Writer( new \stdClass() );
		$prepared = $writer->prepare_update( array( 'route' => '/wc/v3/orders' ), $order->get_id(), array( 'coupon_lines' => array( array( 'code' => 'discount', 'discount' => '10', 'discount_tax' => '2' ) ) ), static fn() => null );
		$forwarded = array();
		$writer->forward( $prepared, static function ( $method, $route, $payload ) use ( &$forwarded ) { $forwarded = $payload; return null; } );
		$this->assertSame( array( array( 'code' => 'discount' ) ), $forwarded['coupon_lines'] );
	}

	/** Require a non-null mutation store dependency (pinned in review, 0ae23b11). */
	public function test_constructor_requires_mutation_store(): void {
		$constructor = new \ReflectionMethod( Order_Writer::class, '__construct' );

		$this->assertSame( 1, $constructor->getNumberOfRequiredParameters() );
		$this->assertFalse( $constructor->getParameters()[0]->allowsNull() );
	}

	/** Ignore an invalid value returned by the public WooCommerce pre-insert filter. */
	public function test_create_forward_ignores_non_order_filter_value(): void {
		$prepared = array(
			'method' => 'POST',
			'route' => '/wc/v3/orders',
			'payload' => array(),
			'context' => array(
				'operation' => 'create',
				'created_gmt' => '2026-08-15T12:00:00',
				'fill_meta' => array(),
			),
		);
		$result   = ( new Order_Writer( new \stdClass() ) )->forward(
			$prepared,
			static function () {
				return apply_filters( 'woocommerce_rest_pre_insert_shop_order_object', null, new \WP_REST_Request(), true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercising the WooCommerce filter.
			}
		);

		$this->assertNull( $result );
	}
	/** @dataProvider unsuccessful_forwards */
	public function test_money_transition_ignores_non_successful_forward( $response ): void {
		$writer = new Order_Writer( new \stdClass() );
		$prepared = $writer->prepare_create( array( 'route' => '/wc/v3/orders' ), array(), static fn() => null );
		$this->assertSame( $response, $writer->forward( $prepared, static fn() => $response ) );
	}
	public function unsuccessful_forwards(): array {
		return array( array( null ), array( new \WP_Error( 'test' ) ), array( new \WP_REST_Response( array(), 199 ) ), array( new \WP_REST_Response( array(), 400 ) ) );
	}

}
