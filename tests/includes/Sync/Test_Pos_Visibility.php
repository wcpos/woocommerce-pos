<?php
/**
 * Tests for the POS visibility authority.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_UnitTestCase;

/**
 * Pos_Visibility interface tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Visibility
 */
class Test_Pos_Visibility extends WP_UnitTestCase {
	/**
	 * Filters added by a test, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private $added_filters = array();

	/**
	 * Remove options and filters written by the tests.
	 */
	public function tearDown(): void {
		foreach ( $this->added_filters as $entry ) {
			remove_filter( $entry[0], $entry[1] );
		}
		$this->added_filters = array();

		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * The feature toggle gates every method in the module.
	 */
	public function test_hidden_ids_with_feature_disabled_returns_empty_set(): void {
		$this->enable_feature( false );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$visibility = new Pos_Visibility();

		$this->assertSame( array(), $visibility->hidden_ids( Pos_Visibility::PRODUCTS ) );
		$this->assertSame( array(), $visibility->hidden_ids( Pos_Visibility::VARIATIONS ) );
		$this->assertSame( array(), $visibility->hidden_ids( Pos_Visibility::CATALOG ) );
		$this->assertSame( array( 'post__not_in' => array() ), $visibility->apply_to_wp_query_args( array( 'post__not_in' => array() ), 'products' ) );
		$this->assertSame( 'WHERE 1=1', $visibility->apply_to_sql_where( 'WHERE 1=1', 'wp_posts.ID', Pos_Visibility::PRODUCTS ) );
		$this->assertSame( array( 4, 8 ), $visibility->filter_visible_children( array( 4, 8 ) ) );
	}

	/**
	 * Stored ids are sanitized to unique positive ints, per type.
	 */
	public function test_hidden_ids_returns_unique_positive_ids_per_type(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( '4', 4, 0, -1, 8 ), array( 12 ) );

		$visibility = new Pos_Visibility();

		$this->assertSame( array( 4, 8 ), $visibility->hidden_ids( Pos_Visibility::PRODUCTS ) );
		$this->assertSame( array( 12 ), $visibility->hidden_ids( Pos_Visibility::VARIATIONS ) );
	}

	/**
	 * The catalog type unions products and variations.
	 */
	public function test_hidden_ids_catalog_unions_products_and_variations(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4, 8 ), array( 8, 12 ) );

		$this->assertSame( array( 4, 8, 12 ), ( new Pos_Visibility() )->hidden_ids( Pos_Visibility::CATALOG ) );
	}

	/**
	 * Singular type aliases resolve to the stored post-type keys.
	 */
	public function test_hidden_ids_accepts_singular_type_aliases(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$visibility = new Pos_Visibility();

		$this->assertSame( array( 4 ), $visibility->hidden_ids( 'product' ) );
		$this->assertSame( array( 8 ), $visibility->hidden_ids( 'variation' ) );
	}

	/**
	 * A scope that was never configured hides nothing (and raises no warning).
	 */
	public function test_hidden_ids_for_unconfigured_scope_returns_empty_set(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$this->assertSame( array(), ( new Pos_Visibility() )->hidden_ids( Pos_Visibility::PRODUCTS, 'store_7' ) );
	}

	/**
	 * An extension filter that hides a product is honoured by the authority.
	 *
	 * This is the contract the v1 lanes always had (they read through
	 * Services\Settings) and the v2 lanes did not.
	 */
	public function test_hidden_ids_honours_the_online_only_product_filter(): void {
		$this->enable_feature( true );
		$this->store_hidden( array(), array() );
		$this->add_filter(
			'woocommerce_pos_online_only_product_visibility_settings',
			static function ( $settings ) {
				$settings['ids'] = array( 4242 );

				return $settings;
			}
		);

		$this->assertSame( array( 4242 ), ( new Pos_Visibility() )->hidden_ids( Pos_Visibility::PRODUCTS ) );
	}

	/**
	 * An extension filter that hides a variation is honoured by the authority.
	 */
	public function test_hidden_ids_honours_the_online_only_variations_filter(): void {
		$this->enable_feature( true );
		$this->store_hidden( array(), array() );
		$this->add_filter(
			'woocommerce_pos_online_only_variations_visibility_settings',
			static function ( $settings ) {
				$settings['ids'] = array( 5252 );

				return $settings;
			}
		);

		$visibility = new Pos_Visibility();

		$this->assertSame( array( 5252 ), $visibility->hidden_ids( Pos_Visibility::VARIATIONS ) );
		$this->assertSame( array( 11 ), $visibility->filter_visible_children( array( 11, 5252 ) ) );
	}

	/**
	 * With no include list the exclusion rides post__not_in, merged with any existing one.
	 */
	public function test_apply_to_wp_query_args_merges_post_not_in(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$args = ( new Pos_Visibility() )->apply_to_wp_query_args( array( 'post__not_in' => array( 99 ) ), 'products' );

		$this->assertSame( array( 99, 4, 8 ), $args['post__not_in'] );
	}

	/**
	 * A targeted pull must intersect post__in, because WP_Query ignores post__not_in beside it.
	 */
	public function test_apply_to_wp_query_args_intersects_post_in(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array() );

		$args = ( new Pos_Visibility() )->apply_to_wp_query_args( array( 'post__in' => array( 4, 5 ) ), 'products' );

		$this->assertSame( array( 5 ), $args['post__in'] );
		$this->assertArrayNotHasKey( 'post__not_in', $args );
	}

	/**
	 * An include list of only hidden ids must return nothing, not everything.
	 */
	public function test_apply_to_wp_query_args_pins_empty_intersection_to_no_results(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array() );

		$args = ( new Pos_Visibility() )->apply_to_wp_query_args( array( 'post__in' => array( 4 ) ), 'products' );

		$this->assertSame( array( 0 ), $args['post__in'] );
	}

	/**
	 * The products collection also excludes hidden variations — WooCommerce widens post_type
	 * to product_variation on SKU-ish params, so variation rows can ride a /products response.
	 */
	public function test_apply_to_wp_query_args_products_collection_excludes_hidden_variations(): void {
		$this->enable_feature( true );
		$this->store_hidden( array(), array( 8 ) );

		$args = ( new Pos_Visibility() )->apply_to_wp_query_args( array(), 'products' );

		$this->assertSame( array( 8 ), $args['post__not_in'] );
	}

	/**
	 * The variations collection excludes hidden variations only.
	 */
	public function test_apply_to_wp_query_args_variations_collection_excludes_variations_only(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$args = ( new Pos_Visibility() )->apply_to_wp_query_args( array(), 'variations' );

		$this->assertSame( array( 8 ), $args['post__not_in'] );
	}

	/**
	 * A collection with no POS visibility rule is left untouched.
	 */
	public function test_apply_to_wp_query_args_leaves_unrelated_collections_untouched(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$args = ( new Pos_Visibility() )->apply_to_wp_query_args( array( 'foo' => 'bar' ), 'orders' );

		$this->assertSame( array( 'foo' => 'bar' ), $args );
	}

	/**
	 * The SQL lane appends a prepared NOT IN for the requested id column.
	 */
	public function test_apply_to_sql_where_appends_prepared_not_in(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4, 8 ), array() );

		$where = ( new Pos_Visibility() )->apply_to_sql_where( 'WHERE 1=1', 'wp_posts.ID', Pos_Visibility::PRODUCTS );

		$this->assertSame( 'WHERE 1=1 AND wp_posts.ID NOT IN (4,8) ', $where );
	}

	/**
	 * Nothing hidden means the clause is returned byte-identical.
	 */
	public function test_apply_to_sql_where_without_hidden_ids_returns_input(): void {
		$this->enable_feature( true );
		$this->store_hidden( array(), array() );

		$this->assertSame(
			'WHERE 1=1',
			( new Pos_Visibility() )->apply_to_sql_where( 'WHERE 1=1', 'ID', Pos_Visibility::PRODUCTS )
		);
	}

	/**
	 * Hidden children are dropped from a variation id list; the rest keep their order.
	 */
	public function test_filter_visible_children_drops_hidden_variations(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 11 ), array( 8 ) );

		$this->assertSame(
			array( 7, 9 ),
			( new Pos_Visibility() )->filter_visible_children( array( '7', 8, 9 ) )
		);
	}

	/**
	 * The legacy accessors still answer, delegating to the new interface.
	 */
	public function test_legacy_accessors_delegate_to_hidden_ids(): void {
		$this->enable_feature( true );
		$this->store_hidden( array( 4 ), array( 8 ) );

		$visibility = new Pos_Visibility();

		$this->assertSame( array( 4 ), $visibility->online_only_product_ids() );
		$this->assertSame( array( 8 ), $visibility->online_only_variation_ids() );
	}

	/**
	 * Toggle the pos_only_products feature.
	 *
	 * @param bool $enabled Whether the feature is on.
	 */
	private function enable_feature( bool $enabled ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => $enabled ) );
	}

	/**
	 * Store the online_only id lists for the default scope.
	 *
	 * @param array $product_ids   Hidden product ids.
	 * @param array $variation_ids Hidden variation ids.
	 */
	private function store_hidden( array $product_ids, array $variation_ids ): void {
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array() ),
						'online_only' => array( 'ids' => $product_ids ),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array() ),
						'online_only' => array( 'ids' => $variation_ids ),
					),
				),
			)
		);
	}

	/**
	 * Add a filter and remember it for teardown.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 */
	private function add_filter( string $hook, callable $callback ): void {
		add_filter( $hook, $callback );
		$this->added_filters[] = array( $hook, $callback );
	}
}
