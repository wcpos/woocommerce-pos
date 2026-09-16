<?php
/**
 * Tests for the POS Only / Online Only counts in the products list-table views.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Admin\Products\List_Products;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * The count next to a visibility view must equal the rows that view opens to.
 *
 * WordPress's status views ("All", "Published", …) never count trashed or
 * auto-draft posts, and the list a visibility view opens to is the "All" set
 * narrowed by id. The count used to be by id alone, so a trashed Online Only
 * product was counted but never listed.
 *
 * @covers \WCPOS\WooCommercePOS\Admin\Products\List_Products::pos_visibility_filters
 */
class Test_Product_Visibility_View_Counts extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Empty visibility settings with the feature enabled.
	 */
	public function setUp(): void {
		parent::setUp();
		Settings::instance()->reset_sections_for_testing();
		delete_option( Pos_Visibility::OPTION );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
	}

	/**
	 * Drop the visibility options.
	 */
	public function tearDown(): void {
		unset( $_GET['pos_visibility'] );
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Settings::instance()->reset_sections_for_testing();
		parent::tearDown();
	}

	/**
	 * The Online Only count excludes trashed products, like every WordPress status view.
	 */
	public function test_online_only_count_matches_the_all_statuses_view(): void {
		// Arrange: one published, one private and one trashed product, all Online Only.
		$published = ProductHelper::create_simple_product();
		$private   = ProductHelper::create_simple_product();
		$private->set_status( 'private' );
		$private->save();
		$trashed = ProductHelper::create_simple_product();
		wp_trash_post( $trashed->get_id() );
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'online_only',
				'ids'        => array( $published->get_id(), $private->get_id(), $trashed->get_id() ),
				'scope'      => 'default',
			)
		);

		// Act.
		$views = ( new List_Products() )->pos_visibility_filters(
			array(
				'all'     => '<a href="edit.php?post_type=product" class="current">All <span class="count">(3)</span></a>',
				'publish' => '<a href="edit.php?post_status=publish&#038;post_type=product">Published <span class="count">(1)</span></a>',
			)
		);

		// Assert: published + private, not the trashed one.
		$this->assertSame( 2, $this->count_in_view( $views['online_only'] ) );
		$this->assertSame( 0, $this->count_in_view( $views['pos_only'] ) );
	}

	/**
	 * Read the number inside the view's count span.
	 *
	 * @param string $view The rendered view link.
	 */
	private function count_in_view( string $view ): int {
		$this->assertSame( 1, preg_match( '/<span class="count">\(([\d,]+)\)/', $view, $matches ), $view );

		return (int) str_replace( ',', '', $matches[1] );
	}
}
