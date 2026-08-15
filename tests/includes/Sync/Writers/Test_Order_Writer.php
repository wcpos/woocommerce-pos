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

	/** Require a non-null mutation store dependency. */
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
}
