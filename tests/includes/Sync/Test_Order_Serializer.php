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
}
