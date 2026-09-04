<?php
/**
 * Tax-inclusive order discount characterization tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact characterization scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Tax;

/**
 * @covers \WCPOS\WooCommercePOS\Orders::fee_after_calculate_taxes
 */
class Test_Negative_Fee_Tax_Inclusive extends WCPOS_REST_Unit_Test_Case {
	/** @var array<string, mixed> */
	private $original_options = array();

	/** @var \WC_Product_Simple */
	private $product;

	/** @var int */
	private $tax_rate_id = 0;

	public function setUp(): void {
		parent::setUp();

		$options = array(
			'woocommerce_calc_taxes'           => 'yes',
			'woocommerce_prices_include_tax'    => 'yes',
			'woocommerce_tax_round_at_subtotal' => 'no',
			'woocommerce_tax_based_on'           => 'base',
			'woocommerce_default_country'        => 'GB',
		);
		foreach ( $options as $name => $value ) {
			$this->original_options[ $name ] = get_option( $name );
			update_option( $name, $value );
		}

		$this->tax_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'GB',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		$this->product     = ProductHelper::create_simple_product(
			array(
				'regular_price' => 120,
				'price'         => 120,
			)
		);
	}

	public function tearDown(): void {
		WC_Tax::_delete_tax_rate( $this->tax_rate_id );
		ProductHelper::delete_product( $this->product->get_id() );

		foreach ( $this->original_options as $name => $value ) {
			if ( false === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}

		parent::tearDown();
	}

	public function test_stock_negative_fee_on_non_pos_order_takes_twelve_off_for_ten(): void {
		$order = $this->create_order();
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Discount' );
		$fee->set_total( -10 );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );

		$order->calculate_totals();

		$this->assertSame( '-2.00', wc_format_decimal( $fee->get_total_tax(), 2 ) );
		$this->assertSame( '108.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '18.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
	}

	public function test_pos_override_takes_ten_off_but_leaves_vat_at_twenty(): void {
		$order = $this->create_order();
		$order->set_created_via( 'woocommerce-pos' );
		// The hook resolves the order from the fee item by id, so the marker must be persisted.
		$order->save();
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Discount' );
		$fee->set_total( -10 );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );

		$order->calculate_totals();

		$this->assertSame( '0.00', wc_format_decimal( $fee->get_total_tax(), 2 ) );
		$this->assertSame( '110.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '20.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
	}

	public function test_fixed_cart_virtual_coupon_gets_both_numbers_right(): void {
		$order  = $this->create_order();
		$coupon = $this->create_virtual_coupon( 'fixed_cart', 10 );

		$this->assertTrue( $order->apply_coupon( $coupon ) );

		$this->assertSame( '110.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '18.33', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( '8.33', wc_format_decimal( $order->get_discount_total(), 2 ) );
		$this->assertSame( '1.67', wc_format_decimal( $order->get_discount_tax(), 2 ) );
		$coupon_items = array_values( $order->get_items( 'coupon' ) );
		$this->assertCount( 1, $coupon_items );
		$this->assertSame( 'pos-discount', $coupon_items[0]->get_code() );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'pos-discount' ) );
	}

	public function test_virtual_percent_coupon_is_rebuilt_as_fixed_cart_on_recalculation(): void {
		$order       = $this->create_order();
		$coupon      = $this->create_virtual_coupon( 'percent', 8.333333 );
		$this->assertTrue( $order->apply_coupon( $coupon ) );
		$coupon_item = $this->get_coupon_item( $order );
		$coupon_info = json_decode( $coupon_item->get_meta( 'coupon_info', true ), true );
		$this->assertSame( 0.0, (float) $coupon_info[3] );

		$rebuilt_coupon = $this->capture_recalculated_coupon( $order );

		$this->assertSame( '110.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '18.33', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( 'fixed_cart', $rebuilt_coupon->get_discount_type() );
		$this->assertSame( 10.0, (float) $rebuilt_coupon->get_amount() );
	}

	public function test_writing_coupon_info_keeps_a_percent_coupon_percent_on_recalculation(): void {
		$order       = $this->create_order();
		$coupon      = $this->create_virtual_coupon( 'percent', 8.333333 );
		$this->assertTrue( $order->apply_coupon( $coupon ) );
		$coupon_item = $this->get_coupon_item( $order );
		$coupon_item->update_meta_data( 'coupon_info', $coupon->get_short_info() );
		$coupon_item->save();

		$rebuilt_coupon = $this->capture_recalculated_coupon( $order );

		$this->assertSame( 'percent', $rebuilt_coupon->get_discount_type() );
		$this->assertEqualsWithDelta( 8.333333, (float) $rebuilt_coupon->get_amount(), 0.000001 );
		$this->assertSame( '110.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '18.33', wc_format_decimal( $order->get_total_tax(), 2 ) );
	}

	/**
	 * A line's subtotal_tax only exists once totals have been calculated, and WC_Discounts prices an
	 * item at subtotal + subtotal_tax on a tax-inclusive order. Every real order has been through
	 * calculate_totals() before a coupon touches it; the one test that skips this pins the hazard.
	 */
	public function test_percent_coupon_applied_before_totals_are_calculated_discounts_the_net_subtotal(): void {
		// Hazard for the v1.11 write path: apply the virtual coupon only AFTER calculate_totals().
		// With subtotal_tax still 0, WC_Discounts prices the 120-incl line at 100, so 8.333333%
		// yields 8.33 gross → 6.94 net, and the customer pays 111.67 instead of 110.00.
		$order  = $this->create_order( false );
		$coupon = $this->create_virtual_coupon( 'percent', 8.333333 );

		$this->assertTrue( $order->apply_coupon( $coupon ) );

		$this->assertSame( '6.94', wc_format_decimal( $this->get_coupon_item( $order )->get_discount(), 2 ) );
		$this->assertSame( '111.67', wc_format_decimal( $order->get_total(), 2 ) );
	}

	private function create_order( bool $calculate_totals = true ): WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		if ( $calculate_totals ) {
			$order->calculate_totals();
		}

		return $order;
	}

	private function create_virtual_coupon( string $discount_type, float $amount ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'pos-discount' );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );
		$coupon->set_virtual( true );

		return $coupon;
	}

	private function get_coupon_item( WC_Order $order ): WC_Order_Item_Coupon {
		$coupon_items = array_values( $order->get_items( 'coupon' ) );
		$this->assertCount( 1, $coupon_items );

		return $coupon_items[0];
	}

	private function capture_recalculated_coupon( WC_Order $order ): WC_Coupon {
		$rebuilt_coupon = null;
		$capture         = static function ( WC_Coupon $coupon ) use ( &$rebuilt_coupon ): WC_Coupon {
			$rebuilt_coupon = $coupon;

			return $coupon;
		};
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', $capture );

		try {
			$order->recalculate_coupons();
		} finally {
			remove_filter( 'woocommerce_order_recalculate_coupons_coupon_object', $capture );
		}

		$this->assertInstanceOf( WC_Coupon::class, $rebuilt_coupon );

		return $rebuilt_coupon;
	}
}
