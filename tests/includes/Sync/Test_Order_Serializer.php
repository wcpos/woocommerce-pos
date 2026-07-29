<?php
/**
 * Tests for canonical order revisions.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_UnitTestCase;

/**
 * Order serializer tests adapted from the lab.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Serializer
 */
class Test_Order_Serializer extends WP_UnitTestCase {
	/**
	 * Identity metadata does not affect a content revision.
	 */
	public function test_revision_is_invariant_to_uuid_identity_meta(): void {
		$without = array(
			'id' => 5,
			'total' => '9.99',
			'meta_data' => array(
				array(
					'key' => 'other',
					'value' => 'x',
				),
			),
		);
		$with    = array(
			'id'        => 5,
			'total'     => '9.99',
			'meta_data' => array(
				array(
					'key' => 'other',
					'value' => 'x',
				),
				array(
					'key' => '_woocommerce_pos_uuid',
					'value' => '5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c6d',
				),
			),
		);

		$this->assertSame( Order_Serializer::canonical_revision( $without ), Order_Serializer::canonical_revision( $with ) );
		$this->assertNotSame( Order_Serializer::canonical_revision( $with ), Order_Serializer::canonical_revision( array_merge( $with, array( 'total' => '10.99' ) ) ) );
		$this->assertCount( 2, $with['meta_data'] );
	}

	/**
	 * Request-derived payment links do not affect a content revision.
	 */
	public function test_revision_is_invariant_to_payment_links(): void {
		$without = array(
			'id'    => 5,
			'total' => '9.99',
		);
		$with    = array_merge(
			$without,
			array(
				'links' => array(
					'payment' => array(
						array( 'href' => 'https://example.test/checkout/order-pay/5/?pay_for_order=true&key=wc_order_test' ),
					),
				),
			)
		);

		$this->assertSame( Order_Serializer::canonical_revision( $without ), Order_Serializer::canonical_revision( $with ) );
	}

	/**
	 * The pre-cutover grace recipe must hash identically with and without the
	 * read-time links augmentation — a pre-deployment baseRevision on an
	 * unchanged order must still drain (no false 409 across the upgrade).
	 */
	public function test_legacy_revision_is_invariant_to_payment_links(): void {
		$order   = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$without = array(
			'id'    => $order->get_id(),
			'total' => '9.99',
		);
		$with    = Order_Serializer::add_pos_links( $without, $order );

		$this->assertSame( Order_Serializer::legacy_revision( $without ), Order_Serializer::legacy_revision( $with ) );
	}

	/**
	 * Filter-supplied links entries survive the payment augmentation; only the
	 * `payment` member is owned by add_payment_link().
	 */
	public function test_add_payment_link_preserves_existing_links(): void {
		$order   = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$payload = array(
			'id'    => $order->get_id(),
			'links' => array(
				'receipt' => array( array( 'href' => 'https://example.test/receipt' ) ),
			),
		);

		$augmented = Order_Serializer::add_payment_link( $payload, $order );

		$this->assertSame(
			array( array( 'href' => 'https://example.test/receipt' ) ),
			$augmented['links']['receipt']
		);
		$this->assertStringContainsString( 'order-pay/' . $order->get_id(), $augmented['links']['payment'][0]['href'] );
		$this->assertStringContainsString( 'pay_for_order=', $augmented['links']['payment'][0]['href'] );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $augmented['links']['payment'][0]['href'] );
		$this->assertStringNotContainsString( '/checkout/order-pay/', $augmented['links']['payment'][0]['href'] );
	}
}
