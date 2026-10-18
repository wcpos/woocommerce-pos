<?php
/** Order writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer;
use WP_UnitTestCase;

/** Pins server-authoritative order shaping. */
class Test_Order_Writer extends WP_UnitTestCase {
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
