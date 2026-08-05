<?php
/**
 * Tests for the canonical variable-product price range.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Variable_Prices;
use WP_UnitTestCase;

/**
 * Canonical variable-price-range tests, exercised through the sync lane adapter.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Services\Variable_Price_Range
 * @covers \WCPOS\WooCommercePOS\Sync\Variable_Prices
 */
class Test_Variable_Price_Range extends WP_UnitTestCase {
	/**
	 * Filters registered by a test, removed after it.
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private $registered_filters = array();

	/**
	 * Remove any WooCommerce price filters a test registered.
	 */
	public function tearDown(): void {
		foreach ( $this->registered_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 10 );
		}
		$this->registered_filters = array();
		parent::tearDown();
	}

	/**
	 * Build a variable product from a list of variation price triples.
	 *
	 * @param array<int, array{regular: string, sale: string, price: string}> $variations Variation prices.
	 *
	 * @return WC_Product_Variable
	 */
	private function make_variable_product( array $variations ): WC_Product_Variable {
		$product = new WC_Product_Variable();
		$product->set_props(
			array(
				'name' => 'Range Fixture',
				'sku'  => uniqid( 'RANGE' ),
			)
		);
		$slugs          = array( 'small', 'medium', 'large' );
		$attribute_data = ProductHelper::create_attribute( 'range' . wp_rand( 1, 100000 ), $slugs );
		$attribute      = new WC_Product_Attribute();
		$attribute->set_id( $attribute_data['attribute_id'] );
		$attribute->set_name( $attribute_data['attribute_taxonomy'] );
		$attribute->set_options( $attribute_data['term_ids'] );
		$attribute->set_position( 1 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		foreach ( $variations as $index => $prices ) {
			$variation = new WC_Product_Variation();
			$variation->set_props(
				array(
					'parent_id'     => $product->get_id(),
					'sku'           => uniqid( 'RANGE VAR' ),
					'regular_price' => $prices['regular'],
					'sale_price'    => $prices['sale'],
					'price'         => $prices['price'],
				)
			);
			$variation->set_attributes( array( $attribute_data['attribute_taxonomy'] => $slugs[ $index ] ) );
			$variation->save();
		}
		wc_delete_product_transients( $product->get_id() );

		return $product;
	}

	/**
	 * Run the sync lane adapter over a variable product and return the injected range.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 *
	 * @return null|array
	 */
	private function sync_range( WC_Product_Variable $product ): ?array {
		$record = Variable_Prices::augment_record(
			array(
				'id'        => $product->get_id(),
				'type'      => 'variable',
				'meta_data' => array(),
			)
		);
		foreach ( $record['meta_data'] as $entry ) {
			if ( Variable_Prices::META_KEY === $entry['key'] ) {
				return $entry['value'];
			}
		}

		return null;
	}

	/**
	 * Register a filter for the duration of the test.
	 *
	 * @param string   $hook     Filter name.
	 * @param callable $callback Callback.
	 * @param int      $args     Accepted args.
	 */
	private function add_temp_filter( string $hook, callable $callback, int $args = 3 ): void {
		add_filter( $hook, $callback, 10, $args );
		$this->registered_filters[] = array( $hook, $callback );
	}

	/**
	 * CHARACTERIZATION: the sync lane keeps the child's own unformatted price strings.
	 */
	public function test_sync_lane_serves_raw_unformatted_price_strings(): void {
		// Arrange.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '9.5',
					'sale'    => '',
					'price'   => '9.5',
				),
				array(
					'regular' => '25',
					'sale'    => '',
					'price'   => '25',
				),
			)
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertSame(
			array(
				'min' => '9.5',
				'max' => '25',
			),
			$range['price']
		);
		$this->assertSame(
			array(
				'min' => '9.5',
				'max' => '25',
			),
			$range['regular_price']
		);
	}

	/**
	 * CHARACTERIZATION: the sync lane omits a sub-range with no values, never emits an empty one.
	 */
	public function test_sync_lane_omits_empty_sub_ranges(): void {
		// Arrange.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '20',
					'sale'    => '',
					'price'   => '20',
				),
			)
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertArrayNotHasKey( 'sale_price', $range );
	}

	/**
	 * CHARACTERIZATION: an active sale still pairs into the sync sale sub-range.
	 */
	public function test_sync_lane_keeps_active_sale_sub_range(): void {
		// Arrange.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '20',
					'sale'    => '15',
					'price'   => '15',
				),
				array(
					'regular' => '30',
					'sale'    => '22',
					'price'   => '22',
				),
			)
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertSame(
			array(
				'min' => '15',
				'max' => '22',
			),
			$range['sale_price']
		);
	}

	/**
	 * CHARACTERIZATION: no visible child carries a price, so nothing is injected.
	 */
	public function test_sync_lane_injects_nothing_when_no_child_is_priced(): void {
		// Arrange.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '',
					'sale'    => '',
					'price'   => '',
				),
			)
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertNull( $range );
	}

	/**
	 * DELIBERATE CHANGE (a): the sync lane now runs WooCommerce's per-variation price filter.
	 */
	public function test_sync_lane_applies_wc_variation_price_filter(): void {
		// Arrange.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '20',
					'sale'    => '',
					'price'   => '20',
				),
			)
		);
		$this->add_temp_filter(
			'woocommerce_variation_prices_price',
			static function ( $price, $variation = null, $parent = null ) {
				return '77';
			}
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertSame( '77', $range['price']['min'] );
		$this->assertSame( '77', $range['price']['max'] );
	}

	/**
	 * DELIBERATE CHANGE (a): the sync lane now runs WooCommerce's min/max range filter.
	 */
	public function test_sync_lane_applies_wc_variation_price_range_filter(): void {
		// Arrange.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '20',
					'sale'    => '',
					'price'   => '20',
				),
				array(
					'regular' => '40',
					'sale'    => '',
					'price'   => '40',
				),
			)
		);
		$this->add_temp_filter(
			'woocommerce_get_variation_price',
			static function ( $price, $parent = null, $min_or_max = 'min', $for_display = false ) {
				return 'min' === $min_or_max ? '1' : '2';
			},
			4
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertSame( '1', $range['price']['min'] );
		$this->assertSame( '2', $range['price']['max'] );
	}

	/**
	 * DELIBERATE CHANGE (b): a stale sale price that is not the active price no longer pairs.
	 *
	 * WooCommerce leaves `_sale_price` on a variation after a scheduled sale ends, so a
	 * non-empty sale price is NOT proof of a sale. V1 only pairs a sale price that equals
	 * the variation's active price; the sync lane now follows the same rule.
	 */
	public function test_sync_lane_drops_inactive_sale_price_from_range(): void {
		// Arrange: sale price left behind, active price is the regular price.
		$product = $this->make_variable_product(
			array(
				array(
					'regular' => '20',
					'sale'    => '15',
					'price'   => '20',
				),
			)
		);

		// Act.
		$range = $this->sync_range( $product );

		// Assert.
		$this->assertArrayNotHasKey( 'sale_price', $range );
	}
}
