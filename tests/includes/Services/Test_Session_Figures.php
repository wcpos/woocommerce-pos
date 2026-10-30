<?php
/**
 * Session figure contracts.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Session_Figures;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Register_Sale;

/** Shared tender rules and instance-local source reads. */
class Test_Session_Figures extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Tender rows reconcile with expected balances, including both refund sources. */
	public function test_payment_methods_sales_and_refunds_reconcile_with_expected(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$rows = Ledger::instance()->read( $order );
		$rows[] = array_merge(
			$rows[0],
			array(
				'id' => wp_generate_uuid4(),
				'amount' => '-5',
			)
		);
		$rows[] = array_merge(
			$rows[1],
			array(
				'id' => wp_generate_uuid4(),
				'amount' => '-2',
				'kind' => 'refund',
			)
		);
		Ledger_Seed::rows( $order, $rows, false );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '3' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		$figures = Session_Figures::for_session( $session );

		$methods = $figures->payment_methods();

		$this->assertSame( '50.0000', $methods['cash']['sales'] );
		$this->assertSame( '8.0000', $methods['cash']['refunds'] );
		$this->assertSame( '30.0000', $methods['card']['sales'] );
		$this->assertSame( '2.0000', $methods['card']['refunds'] );
		foreach ( $methods as $key => $method ) {
			$this->assertSame( $key, $method['method'] );
			$this->assertSame( $figures->expected()[ $key ], \WCPOS\WooCommercePOS\Services\Closure_Store::sum( array( 'cash' === $key ? '100' : '0', $method['sales'], '-' . $method['refunds'] ) ) );
		}
		$this->assertSame( $methods, $figures->payment_methods() );
	}

	/** Tax lines, coupon lines and quantities are counted once per sale order. */
	public function test_order_breakdowns_sum_two_orders_and_memoise_the_snapshot(): void {
		$session = $this->closure_session();
		$orders = array( $this->closure_ledger( $session ), $this->closure_ledger( $session ) );
		foreach ( $orders as $order ) {
			$item = new \WC_Order_Item_Product();
			$item->set_name( 'Test item' );
			$item->set_quantity( 3 );
			$item->set_total( '10' );
			// A product line keeps its rate ids only when both halves are given (WC_Order_Item_Product::set_taxes).
			$item->set_taxes(
				array(
					'total' => array(
						1 => '2',
						2 => '0.50',
					),
					'subtotal' => array(
						1 => '2',
						2 => '0.50',
					),
				)
			);
			$order->add_item( $item );
			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_total( '5' );
			$shipping->set_taxes( array( 'total' => array( 1 => '1' ) ) );
			$order->add_item( $shipping );
			$fee = new \WC_Order_Item_Fee();
			$fee->set_total( '2' );
			$fee->set_taxes( array( 'total' => array( 1 => '0.40' ) ) );
			$order->add_item( $fee );
			foreach ( array( 'first', 'second' ) as $code ) {
				$coupon = new \WC_Order_Item_Coupon();
				$coupon->set_code( $code );
				$order->add_item( $coupon );
			}
			foreach ( array( array( 1, '20', '2.40', '1' ), array( 2, '5', '0.50', '0' ), array( 3, '0', '0', '0' ) ) as list( $id, $percent, $tax, $shipping ) ) {
				$item = new \WC_Order_Item_Tax();
				$item->set_rate_id( $id );
				$item->set_label( 'VAT ' . $percent . '%' );
				$item->set_rate_percent( $percent );
				$item->set_tax_total( $tax );
				$item->set_shipping_tax_total( $shipping );
				$order->add_item( $item );
			}
			$order->set_discount_total( '5.25' );
			$order->set_discount_tax( '1.05' );
			$order->save();
		}
		$figures = Session_Figures::for_session( $session );

		$this->assertSame( 2, $figures->sales_count() );
		$this->assertSame(
			array(
				'count' => 4,
				'amount' => '12.6000',
			),
			$figures->discounts()
		);
		$this->assertSame( '6', $figures->items_sold() );
		$this->assertSame( '80.0000', $figures->average_sale() );
		$rates = $figures->tax_rates();
		$this->assertSame( '34.0000', $rates[1]['net'] );
		$this->assertSame( '6.8000', $rates[1]['tax'] );
		$this->assertSame( '40.8000', $rates[1]['gross'] );
		$this->assertSame( '20.0000', $rates[2]['net'] );
		$this->assertSame( '1.0000', $rates[2]['tax'] );
		$this->assertSame( '0.0000', $rates[3]['net'] );
		$orders[0]->set_discount_total( '99' );
		$orders[0]->save();
		$this->assertSame( '12.6000', $figures->discounts()['amount'] );
		$this->assertSame( $rates, $figures->tax_rates() );

		$document = ( new \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder() )->build_closure_document( $session, true )['closure'];
		$this->assertCount( 2, $document['breakdowns']['payment_methods'] );
		$this->assertCount( 3, $document['breakdowns']['tax_rates'] );
		$this->assertSame( '160.0000', $document['period_sales_total'] );
		$this->assertSame( '80.0000', $document['breakdowns']['average_sale'] );
		$this->assertSame( '6', $document['breakdowns']['items_sold'] );
		$this->assertSame( 0, $document['breakdowns']['drawer_openings'] );
		$this->assertNotEmpty( $document['breakdowns']['discounts']['amount_display'] );
		$this->assertNotEmpty( $document['breakdowns']['average_sale_display'] );
		$this->assertArrayNotHasKey( 'variance_note', $document['breakdowns'] );
		$this->assertEmpty( $document['breakdowns']['denominations'] ?? array() );
	}

	/**
	 * Fractional quantities retain their sum rather than becoming integer counts.
	 *
	 * @param array  $quantities Sold quantities.
	 * @param string $expected Trimmed decimal sum.
	 * @testWith [[0.5], "0.5"]
	 *           [[0.5, 1.5], "2"]
	 *           [[46, 1.5], "47.5"]
	 *           [[0.1, 0.2], "0.3"]
	 */
	public function test_items_sold_fractional_quantities_return_decimal_string( array $quantities, string $expected ): void {
		$priority = has_filter( 'woocommerce_stock_amount', 'intval' );
		remove_filter( 'woocommerce_stock_amount', 'intval', $priority );
		try {
			$session = $this->closure_session();
			$order = $this->closure_ledger( $session );
			foreach ( $quantities as $quantity ) {
				$item = new \WC_Order_Item_Product();
				$item->set_quantity( $quantity );
				$order->add_item( $item );
			}
			$order->save();

			$figures = Session_Figures::for_session( $session );

			$this->assertSame( $expected, $figures->items_sold() );
		} finally {
			if ( false !== $priority ) {
				add_filter( 'woocommerce_stock_amount', 'intval', $priority );
			}
		}
	}

	/** A zero-rated line retains its sales base alongside a taxed line. */
	public function test_tax_rates_zero_rated_line_keeps_net_and_gross(): void {
		$zero = \WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper::create_tax_rate(
			array(
				'rate' => '0.0000',
				'name' => 'Zero VAT',
				'class' => 'zero-rate',
			)
		);
		$standard = \WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper::create_tax_rate(
			array(
				'rate' => '20.0000',
				'name' => 'VAT',
			)
		);
		try {
			$session = $this->closure_session();
			$order = $this->closure_ledger( $session );
			foreach ( array( array( $zero, '10', '' ), array( $standard, '20', '4' ) ) as list( $id, $net, $tax ) ) {
				$item = new \WC_Order_Item_Product();
				$item->set_total( $net );
				$item->set_subtotal( $net );
				$item->set_taxes(
					array(
						'total' => array( $id => $tax ),
						'subtotal' => array( $id => $tax ),
					)
				);
				$order->add_item( $item );
			}
			$order->update_taxes();
			$order->save();

			$rates = Session_Figures::for_session( $session )->tax_rates();

			$this->assertSame( '10.0000', $rates[ $zero ]['net'] );
			$this->assertSame( '0.0000', $rates[ $zero ]['tax'] );
			$this->assertSame( '10.0000', $rates[ $zero ]['gross'] );
			$this->assertSame( '20.0000', $rates[ $standard ]['net'] );
			$this->assertSame( '4.0000', $rates[ $standard ]['tax'] );
			$this->assertSame( '24.0000', $rates[ $standard ]['gross'] );
		} finally {
			\WC_Tax::_delete_tax_rate( $zero );
			\WC_Tax::_delete_tax_rate( $standard );
		}
	}

	/**
	 * Session refunds reduce the tax table even when the sale belongs to another session.
	 *
	 * @param bool  $same_session Whether the sale and full refund share a session.
	 * @param array $expected Expected net, tax and gross amounts.
	 * @testWith [true, ["0.0000", "0.0000", "0.0000"]]
	 *           [false, ["-50.0000", "-10.0000", "-60.0000"]]
	 */
	public function test_tax_rates_session_refunds_reduce_net_tax_and_gross( bool $same_session, array $expected ): void {
		$sale_session = $this->closure_session();
		$session = $same_session ? $sale_session : $this->closure_session();
		$order = $this->order_with_legs(
			array(
				array(
					'session_id' => $sale_session['id'],
					'amount' => '120',
				),
			)
		);
		$order->set_total( '120' );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( $same_session ? '120' : '60' );
		$refund->set_total( $same_session ? '-120' : '-60' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		foreach ( array(
			array( $order, '100', '20' ),
			array( $refund, $same_session ? '-100' : '-50', $same_session ? '-20' : '-10' ),
		) as list( $document, $net, $tax ) ) {
			$item = new \WC_Order_Item_Product();
			$item->set_total( $net );
			$item->set_subtotal( $net );
			$item->set_taxes(
				array(
					'total' => array( 1 => $tax ),
					'subtotal' => array( 1 => $tax ),
				)
			);
			$document->add_item( $item );
			$line = new \WC_Order_Item_Tax();
			$line->set_rate_id( 1 );
			$line->set_label( 'VAT 20%' );
			$line->set_rate_percent( '20' );
			$line->set_tax_total( $tax );
			$document->add_item( $line );
			$document->save();
		}

		$rates = Session_Figures::for_session( $session )->tax_rates();

		$this->assertCount( 1, $rates );
		$this->assertSame( 'VAT 20%', $rates[1]['name'] );
		$this->assertSame( $expected[0], $rates[1]['net'] );
		$this->assertSame( $expected[1], $rates[1]['tax'] );
		$this->assertSame( $expected[2], $rates[1]['gross'] );
	}

	/** Re-completed sales with no legs in this session still contribute their quantities. */
	public function test_items_sold_includes_completion_history_only_orders(): void {
		$first = $this->closure_session( null, 'open' );
		$second = $this->closure_session( null, 'open' );
		$order = $this->completed_in( $first );
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'Re-completed item' );
		$item->set_quantity( 2 );
		$order->add_item( $item );
		$order->save();
		$this->recomplete_in( $order, $second );
		$figures = Session_Figures::for_session( $second );
		$this->assertSame( array(), $figures->legs() );
		$this->assertSame( 1, $figures->sales_count() );
		$this->assertSame( '2', $figures->items_sold() );
	}

	/** Empty sessions and repeating decimal averages keep four-place strings. */
	public function test_average_sale_decimal_division_and_empty_breakdowns(): void {
		$session = $this->closure_session();
		$empty = Session_Figures::for_session( $session );
		$this->assertSame( '0.0000', $empty->average_sale() );
		$this->assertSame(
			array(
				'count' => 0,
				'amount' => '0.0000',
			),
			$empty->discounts()
		);
		$this->assertSame( '0', $empty->items_sold() );
		$this->assertSame( array(), $empty->tax_rates() );
		foreach ( array( '1', '0', '0' ) as $amount ) {
			$this->order_with_legs(
				array(
					array(
						'session_id' => $session['id'],
						'amount' => $amount,
					),
				)
			);
		}
		$this->assertSame( '0.3333', Session_Figures::for_session( $session )->average_sale() );
	}

	/** A voided no-sale is not a drawer opening in the report. */
	public function test_drawer_openings_ignores_voided_no_sales(): void {
		$session = $this->closure_session( null, 'open' );
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		$fields = array(
			'session_id' => $session['id'],
			'type' => 'no_sale',
			'amount' => '0',
			'reason' => 'Check',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$target = $store->create( $fields + array( 'id' => wp_generate_uuid4() ) );
		$this->assertIsArray( $target );
		$this->assertIsArray( $store->create( $fields + array( 'id' => wp_generate_uuid4() ) ) );
		$this->assertIsArray(
			$store->create(
				array_merge(
					$fields,
					array(
						'id' => wp_generate_uuid4(),
						'type' => 'void',
						'voids' => $target['id'],
					)
				)
			)
		);
		$this->assertSame( 1, Session_Figures::for_session( $session )->drawer_openings() );
	}

	/**
	 * A snapshot digest must not be enumerable without this site's secret.
	 *
	 * @param string $method Snapshot reader.
	 * @param string $key Version key.
	 *
	 * @testWith ["ledger_rows", "version"]
	 *           ["refund_versions", "allocation_version"]
	 */
	public function test_snapshot_versions_differ_between_site_salts( string $method, string $key ): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '3' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		$salt = 'first-site-secret';
		$filter = static function () use ( &$salt ) {
			return $salt;
		};
		add_filter( 'salt', $filter );
		try {
			$before = Session_Figures::for_session( $session )->$method();
			$this->assertSame( $before, Session_Figures::for_session( $session )->$method() );
			$salt = 'second-site-secret';
			$after = Session_Figures::for_session( $session )->$method();
			$this->assertNotSame( $before[0][ $key ], $after[0][ $key ] );
			unset( $before[0][ $key ], $after[0][ $key ] );
			$this->assertSame( $before[0], $after[0] );
		} finally {
			remove_filter( 'salt', $filter );
		}
	}

	/**
	 * Same-second financial edits must change the opaque snapshot version.
	 *
	 * @param int    $index Ledger row index.
	 * @param string $field Changed field.
	 * @param string $value New value.
	 *
	 * @testWith [0, "amount", "55.0000"]
	 *           [1, "method", "voucher"]
	 *           [0, "refunded_amount", "15.0000"]
	 */
	public function test_ledger_rows_same_second_financial_change_changes_version( int $index, string $field, string $value ): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$before = Session_Figures::for_session( $session )->ledger_rows();
		$rows = Ledger::instance()->read( $order );

		// Act. Preserve the timestamp and status while changing a financial input.
		$rows[ $index ][ $field ] = $value;
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();
		$after = Session_Figures::for_session( $session )->ledger_rows();

		// Assert.
		$this->assertSame( $before[ $index ]['updated_at_gmt'], $after[ $index ]['updated_at_gmt'] );
		$this->assertSame( 'captured', $after[ $index ]['status'] );
		$this->assertNotSame( $before[ $index ], $after[ $index ] );
		$this->assertArrayHasKey( 'version', $before[ $index ] );
		$this->assertNotSame( $before[ $index ]['version'], $after[ $index ]['version'] );
	}

	/** Legacy rows need not carry a timestamp; only captured rows enter the snapshot. */
	public function test_ledger_rows_missing_version_returns_null_without_warning(): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$rows = Ledger::instance()->read( $order );
		unset( $rows[0]['updated_at_gmt'], $rows[1]['status'] );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data();

		// Act. PHPUnit converts warnings to exceptions.
		$versions = Session_Figures::for_session( $session )->ledger_rows();

		// Assert.
		$this->assertNotEmpty( $versions[0]['version'] );
		unset( $versions[0]['version'] );
		$this->assertSame(
			array(
				array(
					'id' => $rows[0]['id'],
					'updated_at_gmt' => null,
					'status' => 'captured',
				),
			),
			$versions
		);
	}

	/** All cash-kind gateways map to cash; method takes precedence over method_id. */
	public function test_tender_key_maps_every_cash_kind_to_the_drawer(): void {
		foreach ( array( 'pos_cash', 'cod', 'extension_cash' ) as $method ) {
			$this->assertSame(
				'cash',
				Session_Figures::tender_key(
					array(
						'method_id' => $method,
						'kind' => 'cash',
					)
				)
			);
		}
		$this->assertSame( 'card', Session_Figures::tender_key( array( 'method_id' => 'pos_card' ) ) );
		$this->assertSame( 'voucher', Session_Figures::tender_key( array( 'method_id' => 'voucher' ) ) );
		$this->assertSame(
			'voucher',
			Session_Figures::tender_key(
				array(
					'method' => 'voucher',
					'method_id' => 'pos_card',
				)
			)
		);
	}

	/** Raw legacy refund forms count by either kind or sign, not refunded_amount. */
	public function test_is_refund_tender_by_kind_or_sign(): void {
		$this->assertTrue( Session_Figures::is_refund_tender( array( 'amount' => '-5' ) ) );
		$this->assertTrue(
			Session_Figures::is_refund_tender(
				array(
					'amount' => '7',
					'kind' => 'refund',
				)
			)
		);
		$this->assertFalse(
			Session_Figures::is_refund_tender(
				array(
					'amount' => '5',
					'refunded_amount' => '5',
				)
			)
		);
	}

	/** Cash is seeded even when absent; remaining keys follow first appearance. */
	public function test_tender_totals_seeds_cash_first_and_keeps_appearance_order(): void {
		$this->assertSame( array( 'cash' => '0.0000' ), Session_Figures::tender_totals( array() ) );
		$rows = array();
		foreach ( array( array( 'voucher', '1.0001' ), array( 'pos_card', '2' ), array( 'voucher', '-0.0002' ) ) as list( $method, $amount ) ) {
			$rows[] = array(
				'method_id' => $method,
				'amount' => $amount,
			);
		}
		$this->assertSame(
			array(
				'cash' => '0.0000',
				'voucher' => '0.9999',
				'card' => '2.0000',
			),
			Session_Figures::tender_totals( $rows )
		);
	}

	/** Repeated figures use one lazy ledger read and one lazy movement read. */
	public function test_expected_reads_each_source_once_however_often_asked(): void {
		$session = $this->closure_session();
		$this->closure_ledger( $session );
		$reads = array(
			'orders' => 0,
			'movements' => 0,
		);
		$observe = static function ( $sql ) use ( &$reads ) {
			if ( false !== strpos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, Ledger::SESSION_META_KEY ) ) {
				++$reads['orders'];
			}
			if ( 0 === strpos( $sql, 'SELECT movements FROM ' . ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->table_name() ) ) {
				++$reads['movements'];
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$figures = Session_Figures::for_session( $session );
			$this->assertSame( 0, $reads['orders'] );
			$this->assertSame( 0, $reads['movements'] );
			for ( $i = 0; $i < 2; ++$i ) {
				$this->assertSame(
					array(
						'cash' => '150.0000',
						'card' => '30.0000',
					),
					$figures->expected()
				);
				$this->assertSame( 1, $figures->sales_count() );
				$this->assertSame( 0, $figures->refund_count() );
				$this->assertSame( array(), $figures->movements() );
			}
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame(
			array(
				'orders' => 1,
				'movements' => 1,
			),
			$reads
		);
	}

	/**
	 * A POS order completed with cash recorded in the session, under that session's provenance.
	 *
	 * @param array $session Session row.
	 */
	private function completed_in( array $session ): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '40.00' );
		$order->save();
		Register_Sale::cash(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '40.00',
				'session_id' => $session['id'],
			),
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			)
		);
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Reopen the order and close it unchanged from a session.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $session Session the re-completion happens in.
	 */
	private function recomplete_in( \WC_Order $order, array $session ): void {
		$order->set_status( 'pos-open' );
		$order->save();
		Register_Sale::complete(
			wc_get_order( $order->get_id() ),
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			)
		);
	}

	/** A sale reopened and completed again unchanged in one session is two documents there. */
	public function test_sales_count_counts_a_zero_adjustment_recompletion_in_the_same_session_twice(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$order   = $this->completed_in( $session );

		// Act.
		$this->recomplete_in( $order, $session );

		// Assert.
		$this->assertCount( 2, Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$this->assertSame( 2, Session_Figures::for_session( $session )->sales_count() );
	}

	/** A re-completion in a later session without a new tender counts there once. */
	public function test_a_recompletion_in_a_new_session_counts_once_there(): void {
		// Arrange.
		$first  = $this->closure_session( null, 'open' );
		$second = $this->closure_session( null, 'open' );
		$order  = $this->completed_in( $first );

		// Act.
		$this->recomplete_in( $order, $second );

		// Assert.
		$this->assertSame( 1, Session_Figures::for_session( $first )->sales_count() );
		$this->assertSame( 1, Session_Figures::for_session( $second )->sales_count() );
	}

	/** An order completed before the history existed still counts once in its session. */
	public function test_a_pre_deploy_sale_without_history_still_counts_one(): void {
		// Arrange: captured session legs, no completion history.
		$session = $this->closure_session();
		$order   = $this->closure_ledger( $session );

		// Act.
		$count = Session_Figures::for_session( $session )->sales_count();

		// Assert.
		$this->assertSame( array(), Completion_History::of( wc_get_order( $order->get_id() ) )->entries() );
		$this->assertSame( 1, $count );
	}

	/** A legacy sale row without a session does not blank the session the till tuple carries when the history is synthesised. */
	public function test_a_reopened_legacy_sale_with_a_sessionless_record_still_counts_one(): void {
		global $wpdb;
		// Arrange: captured legs in session A, paid before the history existed, a sale row with no session.
		$session = $this->closure_session();
		$order   = $this->closure_ledger( $session );
		remove_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5 );
		try {
			$paid = wc_get_order( $order->get_id() );
			$paid->set_date_paid( time() );
			$paid->set_status( 'completed' );
			$paid->save();
		} finally {
			add_action( 'woocommerce_order_status_changed', array( Completion_Stamp::class, 'on_status_changed' ), 5, 4 );
		}
		$store = new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store();
		$store->ensure_installed();
		$wpdb->insert(
			$store->table_name(),
			array(
				'type' => 'sale',
				'series' => '',
				'number' => 700,
				'order_id' => $order->get_id(),
				'payload' => '{}',
				'checksum' => str_repeat( 'a', 64 ),
				'received_at_gmt' => '2026-09-11 10:00:00',
			)
		);

		// Act: reopened, which synthesises the legacy entry.
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();

		// Assert.
		$history = Completion_History::of( wc_get_order( $order->get_id() ) )->entries();
		$this->assertCount( 1, $history );
		$this->assertSame( $session['id'], $history[0]['session'] );
		$this->assertSame( 1, Session_Figures::for_session( $session )->sales_count() );
	}

	/** Period totals separate sale legs, both raw refund forms, and bound refunds. */
	public function test_period_amounts_split_legs_and_refund_rows(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$ledger = Ledger::instance();
		$rows = $ledger->read( $order );
		foreach ( array( array( '-5', 'cash' ), array( '7', 'refund' ) ) as list( $amount, $kind ) ) {
			$rows[] = array_merge(
				$rows[0],
				array(
					'id' => wp_generate_uuid4(),
					'amount' => $amount,
					'kind' => $kind,
				)
			);
		}
		Ledger_Seed::rows( $order, $rows, false );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '3' );
		$refund->update_meta_data( '_wcpos_session', $session['id'] );
		$refund->save();
		$figures = Session_Figures::for_session( $session );
		$this->assertSame(
			array(
				'sales' => array( '50.00', '30.00' ),
				'refunds' => array( '5.00', '7.00', '3.00' ),
			),
			$figures->period_amounts()
		);
		$this->assertSame( 3, $figures->refund_count() );
	}
	/** Only a nonempty string matching this closure is counted. */
	public function test_counted_requires_this_closures_id(): void {
		$closure = array( 'id' => wp_generate_uuid4() );
		foreach ( array( null, '', wp_generate_uuid4() ) as $stamp ) {
			$this->assertFalse( Session_Figures::counted( $stamp, $closure ) );
		}
		$this->assertTrue( Session_Figures::counted( $closure['id'], $closure ) );
	}

	/** Late legs are captured, session-bound and not stamped by this closure. */
	public function test_late_legs_are_captured_session_legs_without_this_closures_stamp(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$ledger = Ledger::instance();
		$rows = $ledger->read( $order );
		$stale = clone $order;
		$closure = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
		);
		$ledger->stamp_closure( $order, array( $rows[0]['id'] ), $closure['id'] );
		$this->assertSame( array( $rows[1]['id'] ), array_column( Session_Figures::late_legs( $stale, $closure ), 'id' ) );
		$ledger->stamp_closure( $order, array( $rows[1]['id'] ), wp_generate_uuid4() );
		$this->assertSame( array( $rows[1]['id'] ), array_column( Session_Figures::late_legs( $stale, $closure ), 'id' ) );
	}

	/**
	 * A POS order whose ledger holds these rows, saved through the Ledger.
	 *
	 * @param array $rows Payment rows; each gets a fresh id.
	 */
	private function order_with_legs( array $rows ): \WC_Order {
		$order = new \WC_Order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_total( '100' );
		$order->save();
		$base = array(
			'method_id' => 'pos_cash',
			'kind' => 'cash',
			'amount' => '10',
			'status' => 'captured',
		);
		Ledger_Seed::rows( $order, array_map( static fn( $fields ) => array_merge( $base, $fields, array( 'id' => wp_generate_uuid4() ) ), $rows ), false );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * The session index values stored on an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function indexed_sessions( \WC_Order $order ): array {
		$values = array_map( static fn( $meta ) => $meta->value, wc_get_order( $order->get_id() )->get_meta( Ledger::SESSION_META_KEY, false ) );
		sort( $values );
		return $values;
	}

	/** A captured leg indexes its session, and the session read finds it there. */
	public function test_legs_captured_leg_is_found_through_the_session_index(): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->order_with_legs( array( array( 'session_id' => $session['id'] ) ) );

		// Act.
		$legs = Session_Figures::for_session( $session )->legs();

		// Assert.
		$this->assertSame( array( $session['id'] ), $this->indexed_sessions( $order ) );
		$this->assertSame( array( $order->get_id() ), array_keys( $legs ) );
		$this->assertSame( array_column( Ledger::instance()->read( $order ), 'id' ), array_column( $legs[ $order->get_id() ], 'id' ) );
	}

	/** A partly paid order, not completed and never stamped with the session, is still a session's leg. */
	public function test_legs_partly_paid_order_without_a_completion_stamp_is_found(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '40.00' );
		$order->save();

		// Act.
		$recorded = Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '15.00',
				'session_id' => $session['id'],
			)
		);
		$saved = wc_get_order( $order->get_id() );
		$legs = Session_Figures::for_session( $session )->legs();

		// Assert.
		$this->assertIsArray( $recorded, is_wp_error( $recorded ) ? $recorded->get_error_message() : '' );
		$this->assertSame( 'captured', $recorded['status'] );
		$this->assertFalse( $saved->is_paid() );
		$this->assertSame( '', $saved->get_meta( '_wcpos_session', true ) );
		$this->assertSame( array( $session['id'] ), $this->indexed_sessions( $saved ) );
		$this->assertCount( 1, $legs[ $order->get_id() ] ?? array() );
	}

	/** An order paid again in a second session carries both sessions and is a leg in each. */
	public function test_legs_order_with_captured_legs_in_two_sessions_appears_in_both(): void {
		// Arrange.
		$first = $this->closure_session();
		$second = $this->closure_session();
		$expected = array( $first['id'], $second['id'] );
		sort( $expected );

		// Act.
		$order = $this->order_with_legs( array( array( 'session_id' => $first['id'] ), array( 'session_id' => $second['id'] ) ) );

		// Assert.
		$this->assertSame( $expected, $this->indexed_sessions( $order ) );
		foreach ( array( $first, $second ) as $session ) {
			$legs = Session_Figures::for_session( $session )->legs();
			$this->assertSame( array( $session['id'] ), array_column( $legs[ $order->get_id() ] ?? array(), 'session_id' ) );
		}
	}

	/**
	 * Money in motion is not a session leg until it is captured.
	 *
	 * @param string $status The uncaptured status the row starts in.
	 *
	 * @testWith ["pending"]
	 *           ["authorized"]
	 */
	public function test_legs_uncaptured_row_is_not_indexed_until_captured( string $status ): void {
		// Arrange.
		$session = $this->closure_session();
		$order = $this->order_with_legs(
			array(
				array(
					'session_id' => $session['id'],
					'status' => $status,
				),
			)
		);
		$this->assertSame( array(), $this->indexed_sessions( $order ) );
		$this->assertSame( array(), Session_Figures::for_session( $session )->legs() );
		$rows = Ledger::instance()->read( $order );
		$rows[0]['status'] = 'captured';

		// Act.
		Ledger_Seed::rows( $order, $rows, false );

		// Assert.
		$this->assertSame( array( $session['id'] ), $this->indexed_sessions( $order ) );
		$this->assertSame( array( $rows[0]['id'] ), array_column( Session_Figures::for_session( $session )->legs()[ $order->get_id() ] ?? array(), 'id' ) );
	}
}
