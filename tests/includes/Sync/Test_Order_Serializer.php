<?php
/**
 * Tests for canonical order revisions.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_REST_Request;
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
	}

	/**
	 * Internal trash metadata removed after HPOS restore does not affect a revision.
	 */
	public function test_revision_is_invariant_to_transient_trash_meta(): void {
		$without = array(
			'id'        => 5,
			'total'     => '9.99',
			'meta_data' => array(),
		);

		foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status' ) as $key ) {
			$with = $without;
			$with['meta_data'][] = array(
				'key'   => $key,
				'value' => 'transient',
			);

			$this->assertSame( Order_Serializer::canonical_revision( $without ), Order_Serializer::canonical_revision( $with ), $key . ' must not move the restored order revision.' );
		}
	}

	/** Only allow-listed, non-unsaved order meta contributes to the revision. */
	public function test_revision_hashes_only_allow_listed_stored_order_meta(): void {
		// Arrange.
		$without = array(
			'id' => 5,
			'meta_data' => array(),
		);
		$revision = Order_Serializer::canonical_revision( $without );
		foreach ( array( 'gift_message', 'is_vat_exempt' ) as $key ) {
			foreach ( array( 0, '0', -1 ) as $id ) {
				$entry = array(
					'id' => $id,
					'key' => $key,
					'value' => 'yes',
				);
				foreach ( array( $entry, (object) $entry ) as $shape ) {
					// Act and assert: explicit unsaved rows stay out, even for an owned key.
					$with = array_merge( $without, array( 'meta_data' => array( $shape ) ) );
					$this->assertSame( $revision, Order_Serializer::canonical_revision( $with ) );
				}
			}
			foreach ( array(
				array(
					'key' => $key,
					'value' => 'yes',
				),
				array(
					'id' => 9,
					'key' => $key,
					'value' => 'yes',
				),
			) as $entry ) {
				$with = array_merge( $without, array( 'meta_data' => array( $entry ) ) );
				if ( 'is_vat_exempt' === $key ) {
					$this->assertNotSame( $revision, Order_Serializer::canonical_revision( $with ) );
				} else {
					$this->assertSame( $revision, Order_Serializer::canonical_revision( $with ) );
				}
			}
		}
	}

	/** An unsaved line decoration is ignored; visible line meta without an id still hashes. */
	public function test_revision_ignores_unsaved_visible_line_meta(): void {
		foreach ( array( 'line_items', 'fee_lines', 'shipping_lines', 'coupon_lines' ) as $type ) {
			// Arrange.
			$without = array(
				$type => array(
					array(
						'id' => 7,
						'meta_data' => array(),
					),
				),
			);
			$with = $without;
			$with[ $type ][0]['meta_data'][] = array(
				'id'    => 0,
				'key'   => 'melhor_integrador_order_data',
				'value' => array( 'date_quotation' => '2026-11-20 10:00:00' ),
			);
			// Act and assert.
			$this->assertSame( Order_Serializer::canonical_revision( $without ), Order_Serializer::canonical_revision( $with ), $type );
			$with[ $type ][0]['meta_data'][0]['value']['date_quotation'] = '2026-11-20 10:00:01';
			$this->assertSame( Order_Serializer::canonical_revision( $without ), Order_Serializer::canonical_revision( $with ), $type );
			unset( $with[ $type ][0]['meta_data'][0]['id'] );
			$this->assertNotSame( Order_Serializer::canonical_revision( $without ), Order_Serializer::canonical_revision( $with ), $type );
		}
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

	/**
	 * Served coupon lines must carry a POS uuid, like every other line type
	 * (v1 parity — V1/Orders_Controller stamped ALL item types). The client
	 * pairs pushed vs acked lines strictly by uuid with no positional
	 * fallback: an unstamped coupon line can never pair, so a server-side
	 * change to its discount would silently evade the money-divergence alarm.
	 */
	public function test_served_coupon_lines_carry_pos_uuid(): void {
		$order  = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$coupon = CouponHelper::create_coupon( 'serializer-uuid-' . wp_generate_password( 6, false ) );
		$order->apply_coupon( $coupon->get_code() );
		$order->save();

		$payload = ( new Order_Serializer() )->serialize_order( $order->get_id(), new WP_REST_Request() );

		$this->assertNotEmpty( $payload['coupon_lines'] ?? array(), 'fixture must serve a coupon line' );
		$uuid = null;
		foreach ( $payload['coupon_lines'][0]['meta_data'] ?? array() as $entry ) {
			$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? $entry->key : null );
			if ( Pos_Uuid::META_KEY === $key ) {
				$uuid = is_array( $entry ) ? $entry['value'] : $entry->value;
			}
		}
		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ), 'served coupon line must carry a valid POS uuid, got: ' . wp_json_encode( $uuid ) );
	}

	/** A field only one side carries is still named. */
	public function test_canonical_diff_names_a_field_only_the_server_carries(): void {
		$server = array(
			'status'        => 'processing',
			'customer_note' => 'same',
		);
		$client = array( 'customer_note' => 'same' );

		$this->assertSame( array( 'status' ), Order_Serializer::canonical_diff( $client, $server ) );
		$this->assertSame( array( 'status' ), Order_Serializer::canonical_diff( $server, $client ) );
	}

	/** Two lists holding the same items in another order are still named. */
	public function test_canonical_diff_names_line_items_that_differ_only_in_order(): void {
		$first  = array(
			'id'       => 11,
			'name'     => 'First',
			'quantity' => 1,
		);
		$second = array(
			'id'       => 12,
			'name'     => 'Second',
			'quantity' => 2,
		);
		$client = array( 'line_items' => array( $first, $second ) );
		$server = array( 'line_items' => array( $second, $first ) );

		$this->assertSame( array( 'line_items' ), Order_Serializer::canonical_diff( $client, $server ) );
	}
}
