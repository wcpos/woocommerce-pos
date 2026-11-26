<?php
/**
 * Revisions hash what the user is sent (roadmap 623).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

require_once __DIR__ . '/../../Helpers/Withheld_Fields_Walk.php';

use WCPOS\WooCommercePOS\Services\Withheld_Fields;
use WCPOS\WooCommercePOS\Tests\Helpers\Withheld_Fields_Walk;

/**
 * A cost the user cannot see neither moves their revision nor makes their writes stale.
 */
class Test_Withheld_Cost_Revisions extends Sync_REST_Store_Test_Case {
	use Withheld_Fields_Walk;

	/**
	 * Restricted cashier id.
	 *
	 * @var int
	 */
	private $cashier = 0;

	/** Build the COGS fixtures. */
	public function setUp(): void {
		parent::setUp();
		$this->arrange_storage();
		$this->install_sync_pipeline();
		$this->enable_cogs();
		$this->create_cost_products();
		$this->create_cost_order();
		$this->cashier = $this->restricted_cashier();
	}

	/** Restore options, auth and roles. */
	public function tearDown(): void {
		$this->restore_withheld_state();
		parent::tearDown();
	}

	/** Storage seam for the HPOS subclass. */
	protected function arrange_storage(): void {
	}

	/**
	 * Change only the cost of a product or variation.
	 *
	 * Product revisions hash `date_modified`, so the stored dates are pinned back: a real
	 * cost-only save moves the date and costs the restricted user one re-pull, not a loop.
	 *
	 * @param int $id Product or variation id.
	 */
	private function change_cost_only( int $id ): void {
		global $wpdb;
		$dates   = $wpdb->get_row( $wpdb->prepare( "SELECT post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
		$product = wc_get_product( $id );
		$product->set_cogs_value( (float) $product->get_cogs_value() + 10 );
		$product->save();
		$wpdb->update( $wpdb->posts, $dates, array( 'ID' => $id ) );
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
	}

	/**
	 * Revision the given user pulls for a record.
	 *
	 * @param int    $user_id    User.
	 * @param string $collection products|variations.
	 * @param int    $id         Record id.
	 */
	private function revision_as( int $user_id, string $collection, int $id ): string {
		$this->sign_in_with_token( $user_id );
		return $this->pulled_record( $collection, $id )['_rxdb_revision'];
	}

	/** A cost-only product change leaves the cashier's revision alone and moves the admin's. */
	public function test_product_cost_change_moves_only_the_admin_revision(): void {
		// Arrange.
		$cashier_before = $this->revision_as( $this->cashier, 'products', $this->ids['simple'] );
		$admin_before   = $this->revision_as( $this->user, 'products', $this->ids['simple'] );
		// Act.
		$this->change_cost_only( $this->ids['simple'] );
		// Assert.
		$this->assertSame( $cashier_before, $this->revision_as( $this->cashier, 'products', $this->ids['simple'] ) );
		$this->assertNotSame( $admin_before, $this->revision_as( $this->user, 'products', $this->ids['simple'] ) );
	}

	/** The cashier's pulled product revision is accepted as the base of their push. */
	public function test_product_pull_then_push_with_pulled_base_is_accepted(): void {
		// Arrange.
		$base = $this->revision_as( $this->cashier, 'products', $this->ids['simple'] );
		// Act.
		list( $status, $body ) = $this->serve( $this->push_request( 'products', 'update', $this->uuid_of( 'simple' ), $base, array( 'name' => 'Pushed' ) ) );
		// Assert.
		$this->assertSame( 200, $status, wp_json_encode( $body ) );
		$this->assertSame( 'Pushed', wc_get_product( $this->ids['simple'] )->get_name() );
	}

	/** A cost-only variation change leaves the cashier's revision alone and moves the admin's. */
	public function test_variation_cost_change_moves_only_the_admin_revision(): void {
		// Arrange.
		$cashier_before = $this->revision_as( $this->cashier, 'variations', $this->ids['variation'] );
		$admin_before   = $this->revision_as( $this->user, 'variations', $this->ids['variation'] );
		// Act.
		$this->change_cost_only( $this->ids['variation'] );
		// Assert.
		$this->assertSame( $cashier_before, $this->revision_as( $this->cashier, 'variations', $this->ids['variation'] ) );
		$this->assertNotSame( $admin_before, $this->revision_as( $this->user, 'variations', $this->ids['variation'] ) );
	}

	/** The cashier's pulled variation revision is accepted as the base of their push. */
	public function test_variation_pull_then_push_with_pulled_base_is_accepted(): void {
		// Arrange.
		$base = $this->revision_as( $this->cashier, 'variations', $this->ids['variation'] );
		// Act.
		list( $status, $body ) = $this->serve( $this->push_request( 'variations', 'update', $this->uuid_of( 'variation' ), $base, array( 'sku' => 'pushed-sku' ) ) );
		// Assert.
		$this->assertSame( 200, $status, wp_json_encode( $body ) );
		$this->assertSame( 'pushed-sku', wc_get_product( $this->ids['variation'] )->get_sku() );
	}

	/** The cashier's order ack revision is the next pull's revision and is accepted again: no loop. */
	public function test_order_push_ack_matches_next_pull_and_is_accepted(): void {
		// Arrange.
		$this->sign_in_with_token( $this->cashier );
		$base = $this->pulled_order( $this->ids['order'] )['sync']['revision'];
		// Act.
		list( $status, $ack ) = $this->serve( $this->push_request( 'orders', 'update', $this->uuid_of( 'order' ), $base, array( 'customer_note' => 'First' ) ) );
		$pulled               = $this->pulled_order( $this->ids['order'] )['sync']['revision'];
		list( $again, $body ) = $this->serve( $this->push_request( 'orders', 'update', $this->uuid_of( 'order' ), $ack['currentRevision'], array( 'customer_note' => 'Second' ) ) );
		// Assert.
		$this->assertSame( 200, $status, wp_json_encode( $ack ) );
		$this->assertSame( $ack['currentRevision'], $pulled );
		$this->assertSame( 200, $again, wp_json_encode( $body ) );
		$this->assertSame( 'Second', wc_get_order( $this->ids['order'] )->get_customer_note() );
	}

	/**
	 * What the current user is served for a record, with and without the withheld-fields echo filter.
	 *
	 * @param callable $read Reads the record as served.
	 * @return array{0: array, 1: array} Served, and served without the filter.
	 */
	private function served_with_and_without_filter( callable $read ): array {
		$served = $read();
		remove_filter( 'rest_pre_echo_response', array( Withheld_Fields::class, 'filter_response' ), 999 );
		try {
			$unfiltered = $read();
		} finally {
			add_filter( 'rest_pre_echo_response', array( Withheld_Fields::class, 'filter_response' ), 999, 3 );
		}
		// WooCommerce shuffles related_ids on every read; it is outside every revision too.
		unset( $served['related_ids'], $unfiltered['related_ids'] );
		return array( $served, $unfiltered );
	}

	/** A role that keeps the permission is sent cost, in the same bytes as before, with the admin's revisions. */
	public function test_role_with_permission_sees_cost_and_same_revision(): void {
		// Arrange.
		get_role( 'cashier' )->add_cap( 'view_woocommerce_pos_costs' );
		$this->sign_in_with_token( $this->user );
		$admin_product = $this->pulled_record( 'products', $this->ids['simple'] );
		$admin_order   = $this->pulled_order( $this->ids['order'] );
		$this->sign_in_with_token( $this->cashier );
		// Act.
		list( $product, $product_unfiltered ) = $this->served_with_and_without_filter( fn() => $this->pulled_record( 'products', $this->ids['simple'] ) );
		list( $order, $order_unfiltered )     = $this->served_with_and_without_filter( fn() => $this->pulled_order( $this->ids['order'] ) );
		// Assert.
		$this->assertArrayHasKey( 'cost_of_goods_sold', $product );
		$this->assertArrayHasKey( 'cost_of_goods_sold', $order['payload'] );
		$this->assertSame( $product_unfiltered, $product );
		$this->assertSame( $order_unfiltered, $order );
		$this->assertSame( $admin_product['_rxdb_revision'], $product['_rxdb_revision'] );
		$this->assertSame( $admin_order['sync']['revision'], $order['sync']['revision'] );
	}

	/** On a store that never had COGS there is nothing to withhold: same bytes, and the admin's revisions. */
	public function test_cogs_disabled_strips_nothing_and_changes_no_revision(): void {
		// Arrange.
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' );
		$this->ids = array();
		$this->create_cost_products( false );
		$this->create_cost_order();
		$this->sign_in_with_token( $this->user );
		$admin = array(
			'product'   => $this->pulled_record( 'products', $this->ids['simple'] )['_rxdb_revision'],
			'variation' => $this->pulled_record( 'variations', $this->ids['variation'] )['_rxdb_revision'],
			'order'     => $this->pulled_order( $this->ids['order'] )['sync']['revision'],
		);
		$this->sign_in_with_token( $this->cashier );
		// Act.
		list( $product, $product_unfiltered )     = $this->served_with_and_without_filter( fn() => $this->pulled_record( 'products', $this->ids['simple'] ) );
		list( $variation, $variation_unfiltered ) = $this->served_with_and_without_filter( fn() => $this->pulled_record( 'variations', $this->ids['variation'] ) );
		list( $order, $order_unfiltered )         = $this->served_with_and_without_filter( fn() => $this->pulled_order( $this->ids['order'] ) );
		// Assert.
		$this->assertSame( $product_unfiltered, $product );
		$this->assertSame( $variation_unfiltered, $variation );
		$this->assertSame( $order_unfiltered, $order );
		$this->assertSame( $admin['product'], $product['_rxdb_revision'] );
		$this->assertSame( $admin['variation'], $variation['_rxdb_revision'] );
		$this->assertSame( $admin['order'], $order['sync']['revision'] );
	}

	/** COGS turned off after use: WooCommerce lists the line `_cogs_value` meta, and it is still withheld. */
	public function test_cogs_disabled_after_use_still_withholds_line_cost_meta(): void {
		// Arrange.
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' );
		$this->sign_in_with_token( $this->user );
		$admin = $this->pulled_order( $this->ids['order'] );
		$this->sign_in_with_token( $this->cashier );
		// Act.
		$cashier = $this->pulled_order( $this->ids['order'] );
		// Assert.
		$this->assertContains( '/payload/line_items/0/meta_data/0[key=_cogs_value]', $this->cost_paths( $admin ) );
		$this->assert_no_cost( $cashier );
	}
}
