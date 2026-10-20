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

	/** A public meta entry the strip must keep. */
	private function public_meta(): array {
		return array(
			'key'   => 'public',
			'value' => 'kept',
		);
	}

	/** Every server-owned top-level field and meta key, with values a till might send. */
	private function money_payload(): array {
		$meta = array( $this->public_meta() );
		foreach ( array( '_wcpos_payments', '_wcpos_payment_method', '_wcpos_payment_id', '_wcpos_payment_live', '_wcpos_commands', '_wcpos_completed_at', '_wcpos_completions', '_wcpos_fiscal_pending', '_wcpos_gateway_attempts', '_wcpos_awaiting_customer', '_payment_method', '_payment_method_title', '_transaction_id', '_date_paid', '_paid_date' ) as $key ) {
			$meta[] = array(
				'key'   => $key,
				'value' => 'x',
			);
		}
		return array(
			'set_paid'             => true,
			'date_paid'            => '2026-05-01T10:00:00',
			'date_paid_gmt'        => '2026-05-01T10:00:00',
			'transaction_id'       => 'txn',
			'payment_method'       => 'pos_cash',
			'payment_method_title' => 'Cash',
			'customer_note'        => 'kept',
			'meta_data'            => $meta,
		);
	}

	/**
	 * Assert a forwarded order body carries no money (ADR 0040 rule 2).
	 *
	 * @param array $forwarded The forwarded order body.
	 */
	private function assert_no_money( array $forwarded ): void {
		foreach ( array( 'set_paid', 'date_paid', 'date_paid_gmt', 'transaction_id', 'payment_method', 'payment_method_title' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $forwarded );
		}
		$this->assertSame( 'kept', $forwarded['customer_note'] );
		$this->assertSame( array( $this->public_meta() ), $forwarded['meta_data'] );
	}

	/** A create forwards no top-level money field and no server-owned meta key. */
	public function test_prepare_create_drops_money_fields_and_meta(): void {
		$prepared = ( new Order_Writer( new \stdClass() ) )->prepare_create(
			array( 'route' => '/wc/v3/orders' ),
			$this->money_payload(),
			static function () {
				return null;
			}
		);

		$this->assert_no_money( $prepared['payload'] );
	}

	/** An update forwards no top-level money field and no server-owned meta key. */
	public function test_prepare_update_drops_money_fields_and_meta(): void {
		$prepared = ( new Order_Writer( new \stdClass() ) )->prepare_update(
			array( 'route' => '/wc/v3/orders' ),
			PHP_INT_MAX,
			$this->money_payload(),
			static function () {
				return null;
			}
		);
		$late     = $prepared['context_factory']();

		$this->assert_no_money( $late['payload'] );
	}

	/** A line's meta cannot carry the item's own storage keys or the stock baseline (ADR 0040 rules 2, 4). */
	public function test_prepare_create_drops_internal_item_meta_from_lines(): void {
		$line     = static function ( array $keys ): array {
			$meta = array(
				array(
					'key'   => 'note',
					'value' => 'kept',
				),
			);
			foreach ( $keys as $key ) {
				$meta[] = array(
					'key'   => $key,
					'value' => '9',
				);
			}
			return array( 'meta_data' => $meta );
		};
		$payload  = array(
			'line_items'     => array( $line( array( '_qty', '_line_total', '_product_id', '_reduced_stock', '_restock_refunded_items' ) ) ),
			'fee_lines'      => array( $line( array( '_fee_amount', '_tax_status', '_line_total' ) ) ),
			'shipping_lines' => array( $line( array( 'cost', 'method_id', 'taxes' ) ) ),
			'coupon_lines'   => array( $line( array( 'discount_amount', 'discount_amount_tax' ) ) ),
		);
		$prepared = ( new Order_Writer( new \stdClass() ) )->prepare_create(
			array( 'route' => '/wc/v3/orders' ),
			$payload,
			static function () {
				return null;
			}
		);

		foreach ( array( 'line_items', 'fee_lines', 'shipping_lines', 'coupon_lines' ) as $type ) {
			$this->assertSame( array( 'note' ), array_column( $prepared['payload'][ $type ][0]['meta_data'], 'key' ), $type );
		}
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
}
