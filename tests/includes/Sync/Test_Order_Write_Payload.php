<?php
/**
 * Tests for the order write payload shaper.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Product;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;
use WP_UnitTestCase;

/**
 * Pins the create and update shaping pipelines applied to a POS order document
 * before it is forwarded to the stock wc/v3 orders controller.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Write_Payload
 */
class Test_Order_Write_Payload extends WP_UnitTestCase {
	private const MISC_SKU_PREFIX = 'wcpos-misc-item-no-sku-lookup';

	private const KEPT_LINE_UUID = 'a1f1a7c0-7c0e-4f17-9cc9-0f2ee4051001';

	private const OMITTED_LINE_UUID = 'a1f1a7c0-7c0e-4f17-9cc9-0f2ee4051002';

	/**
	 * A create payload drops every value the strict wc/v3 order schema rejects.
	 */
	public function test_for_create_with_pos_only_fields_drops_the_values_wc_rejects(): void {
		// Arrange.
		$payload = array(
			'billing'    => array(
				'email'      => '',
				'first_name' => 'Walk-in',
			),
			'line_items' => array(
				array(
					'product_id'  => 12,
					'quantity'    => 1,
					'parent_name' => null,
					'sku'         => 'CATALOG-SKU',
					'image'       => array(
						'id'  => '',
						'src' => '',
					),
					'meta_data'   => array(
						array(
							'key'           => '_pos_line',
							'value'         => '1',
							'display_key'   => 'POS line',
							'display_value' => '1',
						),
					),
				),
			),
			'meta_data'  => array(
				array(
					'key'           => '_pos_store',
					'value'         => '3',
					'display_key'   => 'Store',
					'display_value' => '3',
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_create( $payload );

		// Assert.
		$expected = array(
			'billing'    => array( 'first_name' => 'Walk-in' ),
			'line_items' => array(
				array(
					'product_id' => 12,
					'quantity'   => 1,
					'meta_data'  => array(
						array(
							'key'   => '_pos_line',
							'value' => '1',
						),
					),
				),
			),
			'meta_data'  => array(
				array(
					'key'   => '_pos_store',
					'value' => '3',
				),
			),
		);
		$this->assertEquals( $expected, $forwarded );
	}

	/**
	 * A create recovers taxonomy and custom "any" variation choices from their
	 * display fields before forwarding to wc/v3.
	 */
	public function test_for_create_recovers_any_variation_attributes_from_display_fields(): void {
		// Arrange.
		list( $parent, $variation ) = $this->any_variation_product();
		$payload                    = array(
			'line_items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $variation->get_id(),
					'meta_data'    => array(
						array( 'key' => 'wrong-case', 'value' => '', 'display_key' => 'Size', 'display_value' => 'small' ),
						array( 'key' => 'empty-choice', 'value' => '', 'display_key' => 'Fabric', 'display_value' => '' ),
						array(
							'key'           => 'size',
							'value'         => '',
							'display_key'   => 'size',
							'display_value' => 'large',
						),
						array(
							'key'           => 'Fabric',
							'value'         => '',
							'display_key'   => 'Fabric',
							'display_value' => 'Cotton',
						),
					),
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_create( $payload );

		// Assert. The recovered entries are appended in the variation-attribute
		// iteration order, which WordPress does not guarantee — compare as a
		// key-sorted set, not positionally.
		$actual   = $forwarded['line_items'][0]['meta_data'];
		$expected = array(
			array( 'key' => 'wrong-case', 'value' => '' ),
			array( 'key' => 'empty-choice', 'value' => '' ),
			array( 'key' => 'size', 'value' => '' ),
			array( 'key' => 'Fabric', 'value' => '' ),
			array( 'key' => 'pa_size', 'value' => 'large' ),
			array( 'key' => 'fabric', 'value' => 'Cotton' ),
		);
		$by_key = static function ( array $a, array $b ): int {
			return strcmp( (string) ( $a['key'] ?? '' ), (string) ( $b['key'] ?? '' ) );
		};
		usort( $actual, $by_key );
		usort( $expected, $by_key );
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Recovered attributes do not prevent display-only fields from being stripped.
	 */
	public function test_for_create_with_recovered_attribute_still_strips_display_fields(): void {
		// Arrange.
		list( $parent, $variation ) = $this->any_variation_product();
		$payload                    = array(
			'line_items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $variation->get_id(),
					'meta_data'    => array(
						array(
							'key'           => 'size',
							'value'         => '',
							'display_key'   => 'size',
							'display_value' => 'large',
						),
					),
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_create( $payload );

		// Assert.
		foreach ( $forwarded['line_items'][0]['meta_data'] as $meta ) {
			$this->assertArrayNotHasKey( 'display_key', $meta );
			$this->assertArrayNotHasKey( 'display_value', $meta );
		}
	}

	/**
	 * A real attribute key already present in posted meta remains authoritative.
	 */
	public function test_for_create_with_real_any_variation_key_does_not_add_a_duplicate(): void {
		// Arrange.
		list( $parent, $variation ) = $this->any_variation_product();
		$payload                    = array(
			'line_items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $variation->get_id(),
					'meta_data'    => array(
						array( 'key' => 'pa_size', 'value' => 'small' ),
						array(
							'key'           => 'size',
							'value'         => '',
							'display_key'   => 'size',
							'display_value' => 'large',
						),
					),
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_create( $payload );

		// Assert.
		$this->assertCount(
			1,
			array_filter(
				$forwarded['line_items'][0]['meta_data'],
				static function ( array $meta ): bool {
					return 'pa_size' === ( $meta['key'] ?? null );
				}
			)
		);
		$this->assertEquals( 'small', $forwarded['line_items'][0]['meta_data'][0]['value'] );
	}

	/**
	 * Display fields on a non-variation line are stripped without recovering meta.
	 */
	public function test_for_create_with_non_variation_line_does_not_recover_attributes(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$payload = array(
			'line_items' => array(
				array(
					'product_id' => $product->get_id(),
					'meta_data'  => array(
						array(
							'key'           => 'size',
							'value'         => '',
							'display_key'   => 'size',
							'display_value' => 'large',
						),
					),
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_create( $payload );

		// Assert.
		$this->assertEquals(
			array( array( 'key' => 'size', 'value' => '' ) ),
			$forwarded['line_items'][0]['meta_data']
		);
	}

	/**
	 * A misc line forwards the non-colliding sentinel sku and carries its typed
	 * sku as `_sku` item meta.
	 */
	public function test_for_create_with_a_misc_line_forwards_the_sentinel_sku_and_stamps_sku_meta(): void {
		// Arrange.
		$payload = array(
			'line_items' => array(
				array(
					'product_id' => 0,
					'quantity'   => 1,
					'name'       => 'Miscellaneous',
					'sku'        => 'CUSTOM-1',
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_create( $payload );

		// Assert.
		$line = $forwarded['line_items'][0];
		$this->assertEquals( 0, $line['product_id'] );
		$this->assertEquals(
			array(
				array(
					'key'   => '_sku',
					'value' => 'CUSTOM-1',
				),
			),
			$line['meta_data']
		);
		$this->assertEquals( self::MISC_SKU_PREFIX, substr( $line['sku'], 0, \strlen( self::MISC_SKU_PREFIX ) ) );
	}

	/**
	 * An update restores the posted line's id from its POS UUID and appends a
	 * wc/v3 deletion marker for the stored line the document omitted.
	 */
	public function test_for_update_with_an_omitted_line_restores_ids_and_marks_the_omission_for_removal(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$order   = new WC_Order();
		$kept    = $this->line_item( $product, self::KEPT_LINE_UUID );
		$omitted = $this->line_item( $product, self::OMITTED_LINE_UUID );
		$order->add_item( $kept );
		$order->add_item( $omitted );
		$order->save();

		$payload = array(
			'line_items' => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 3,
					'meta_data'  => array(
						array(
							'key'   => '_woocommerce_pos_uuid',
							'value' => self::KEPT_LINE_UUID,
						),
					),
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_update( $order->get_id(), $payload );

		// Assert.
		$expected = array(
			'line_items' => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 3,
					'meta_data'  => array(
						array(
							'key'   => '_woocommerce_pos_uuid',
							'value' => self::KEPT_LINE_UUID,
						),
					),
					'id'         => $kept->get_id(),
				),
				array(
					'id'         => $omitted->get_id(),
					'product_id' => null,
				),
			),
		);
		$this->assertEquals( $expected, $forwarded );
	}

	/**
	 * An update whose requested coupon codes already match the stored order drops
	 * coupon_lines entirely, so wc/v3 never re-applies them and the line ids stay stable.
	 */
	public function test_for_update_with_unchanged_coupon_codes_drops_coupon_lines_from_the_forward(): void {
		// Arrange.
		$coupon = new WC_Coupon();
		$coupon->set_code( 'save5' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 5.0 );
		$coupon->save();

		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$order   = new WC_Order();
		$item    = $this->line_item( $product, self::KEPT_LINE_UUID );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$order->add_item( $item );
		$order->save();
		$order->apply_coupon( 'save5' );
		$this->assertEquals( 1, \count( $order->get_coupons() ) );

		$payload = array(
			'coupon_lines' => array(
				array(
					'id'   => 4242,
					'code' => 'SAVE5',
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_update( $order->get_id(), $payload );

		// Assert.
		$this->assertEquals( array(), $forwarded );
	}

	/**
	 * Pins the load-bearing step order: the variation-identity dedupe must read the
	 * FORWARDED line shape, i.e. run AFTER the sku has been stripped. Hoisting it
	 * above the sanitization makes the still-present sku look like a re-binding, the
	 * no-op identity survives the forward, and the duplicate `pa_*` meta bug returns.
	 */
	public function test_for_update_with_an_unchanged_variation_line_drops_identity_after_the_sku_is_stripped(): void {
		// Arrange.
		$variable   = ProductHelper::create_variation_product();
		$variations = $variable->get_children();
		$variation  = wc_get_product( $variations[0] );

		$order = new WC_Order();
		$item  = $this->line_item( $variation, self::KEPT_LINE_UUID );
		$order->add_item( $item );
		$order->save();

		$payload = array(
			'line_items' => array(
				array(
					'id'           => $item->get_id(),
					'quantity'     => 1,
					'product_id'   => $variable->get_id(),
					'variation_id' => $variation->get_id(),
					// Present on every acked full-document re-push; wc/v3's get_product_id()
					// ranks it above the ids, so the dedupe must not see it.
					'sku'          => 'ACKED-VARIATION-SKU',
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_update( $order->get_id(), $payload );

		// Assert: sku stripped first, so the unchanged binding is recognised and dropped.
		$expected = array(
			'line_items' => array(
				array(
					'id'       => $item->get_id(),
					'quantity' => 1,
				),
			),
		);
		$this->assertEquals( $expected, $forwarded );
	}

	/**
	 * When the id no longer resolves to an order, every order-reading step no-ops
	 * and the forward carries only the create-side schema sanitization.
	 */
	public function test_for_update_with_an_unresolvable_order_id_applies_only_the_schema_sanitization(): void {
		// Arrange.
		$payload = array(
			'billing'      => array( 'email' => null ),
			'line_items'   => array(
				array(
					'id'          => 7,
					'product_id'  => 3,
					'parent_name' => null,
					'sku'         => 'CATALOG-SKU',
				),
			),
			'coupon_lines' => array(
				array(
					'id'   => 9,
					'code' => 'save5',
				),
			),
		);

		// Act.
		$forwarded = ( new Order_Write_Payload() )->for_update( 999999999, $payload );

		// Assert: no ids reconciled, no removal markers, coupon ids untouched.
		$expected = array(
			'billing'      => array(),
			'line_items'   => array(
				array(
					'id'         => 7,
					'product_id' => 3,
				),
			),
			'coupon_lines' => array(
				array(
					'id'   => 9,
					'code' => 'save5',
				),
			),
		);
		$this->assertEquals( $expected, $forwarded );
	}

	/**
	 * Build an unsaved product line item carrying a stable POS UUID.
	 *
	 * @param \WC_Product $product Product to bind the line to.
	 * @param string      $uuid    Stable POS line UUID.
	 *
	 * @return WC_Order_Item_Product
	 */
	private function line_item( $product, string $uuid ): WC_Order_Item_Product {
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->add_meta_data( '_woocommerce_pos_uuid', $uuid, true );

		return $item;
	}

	/**
	 * Build a variation whose taxonomy and custom attributes both accept any value.
	 *
	 * @return array{0: \WC_Product_Variable, 1: \WC_Product_Variation}
	 */
	private function any_variation_product(): array {
		$parent = ProductHelper::create_variation_product();

		$fabric = new \WC_Product_Attribute();
		$fabric->set_name( 'Fabric' );
		$fabric->set_options( array( 'Cotton', 'Wool' ) );
		$fabric->set_visible( true );
		$fabric->set_variation( true );

		$attributes   = array_values( $parent->get_attributes() );
		$attributes[] = $fabric;
		$parent->set_attributes( $attributes );
		$parent->save();

		$variation = wc_get_product( $parent->get_children()[0] );
		$variation->set_attributes(
			array(
				'pa_size' => '',
				'fabric'  => '',
			)
		);
		$variation->save();

		return array( $parent, $variation );
	}
}
