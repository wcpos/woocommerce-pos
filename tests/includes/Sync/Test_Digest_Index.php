<?php
/**
 * Tests for the digest store's read half.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;

/**
 * The integrity read surface answers bucket questions without exposing SQL.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index
 */
class Test_Digest_Index extends Sync_Store_Test_Case {
	/**
	 * The read half under test.
	 *
	 * @var Digest_Index
	 */
	private $index;

	/**
	 * The write half, used to arrange stored digests.
	 *
	 * @var Integrity_Digest
	 */
	private $digests;

	/**
	 * Build the collaborators for each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->index   = new Digest_Index();
		$this->digests = new Integrity_Digest();
	}

	/**
	 * Remove visibility settings written by a test.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * One range wide enough to hold every id a test creates, in a single bucket.
	 */
	private function whole_space( int $bucket_size = 1000000 ): array {
		return array(
			'bucket_size' => $bucket_size,
			'start' => 0,
			'end' => $bucket_size,
		);
	}

	/**
	 * Drop every stored product-space digest so the stored side is known-empty.
	 */
	private function clear_stored_product_digests(): void {
		global $wpdb;

		$wpdb->query(
			'DELETE FROM ' . $this->index->table_name() . ' WHERE object_type IN ' . Digest_Index::OBJECT_TYPES_SQL // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		);
	}

	/**
	 * Hide a product-space id from the POS through the visibility contract.
	 */
	private function hide_from_pos( string $post_type_key, int $id ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				$post_type_key => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $id ) ),
					),
				),
			)
		);
	}

	/**
	 * One numeric id can be live in EVERY digest id-space at once. Reading a
	 * collection must answer from that collection's own object types and never
	 * another's — the separation the three hand-written readers existed to keep,
	 * and the one thing folding them into a registry-driven reader could break.
	 */
	public function test_read_digests_customer_ids_do_not_leak_into_product_id_space_result(): void {
		global $wpdb;

		// Arrange: ONE id stored in three id-spaces with three different digests,
		// plus a variation id proving the products space still spans both types.
		$shared_id    = 4242;
		$variation_id = 4243;
		$stored       = array(
			array( 'product', $shared_id, '111111111111111111' ),
			array( 'customer', $shared_id, '222222222222222222' ),
			array( 'order', $shared_id, '333333333333333333' ),
			array( 'variation', $variation_id, '444444444444444444' ),
		);
		foreach ( $stored as $row ) {
			$wpdb->replace(
				$this->index->table_name(),
				array(
					'object_type' => $row[0],
					'object_id' => $row[1],
					'digest' => $row[2],
					'updated_gmt' => current_time( 'mysql', true ),
				)
			);
		}

		// Act.
		$products  = $this->index->read_digests( 'products', array( $shared_id, $variation_id ) );
		$customers = $this->index->read_digests( 'customers', array( $shared_id, $variation_id ) );
		$orders    = $this->index->read_digests( 'orders', array( $shared_id, $variation_id ) );

		// Assert.
		$this->assertSame(
			array(
				$shared_id => '111111111111111111',
				$variation_id => '444444444444444444',
			),
			$products
		);
		$this->assertSame( array( $shared_id => '222222222222222222' ), $customers );
		$this->assertSame( array( $shared_id => '333333333333333333' ), $orders );
		// A collection with no digest id-space reads nothing rather than falling
		// through to the products digests.
		$this->assertSame( array(), $this->index->read_digests( 'categories', array( $shared_id ) ) );
	}

	/**
	 * The listing carries the live digest and the object_type that routes a pull.
	 */
	public function test_bucket_listing_products_returns_live_rows_with_typed_digests(): void {
		$product = ProductHelper::create_simple_product();

		$rows = $this->index->bucket_listing( 'products', $this->whole_space() );
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $product->get_id() === $candidate['id'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'product', $row['object_type'] );
		$this->assertIsString( $row['digest'] );
		$this->assertNotSame( '', $row['digest'] );
	}

	/**
	 * A range that excludes the id excludes its row.
	 */
	public function test_bucket_listing_products_outside_range_returns_no_row(): void {
		$product = ProductHelper::create_simple_product();

		$rows = $this->index->bucket_listing(
			'products',
			array(
				'start' => $product->get_id() + 1,
				'end' => $product->get_id() + 2,
			)
		);

		$this->assertNotContains( $product->get_id(), array_column( $rows, 'id' ) );
	}

	/**
	 * status=publish scopes the listing to the readable catalog.
	 */
	public function test_bucket_listing_products_publish_status_excludes_draft_product(): void {
		$published = ProductHelper::create_simple_product();
		$draft     = ProductHelper::create_simple_product();
		$draft->set_status( 'draft' );
		$draft->save();

		$ids = array_column( $this->index->bucket_listing( 'products', $this->whole_space(), array( 'status' => 'publish' ) ), 'id' );

		$this->assertContains( $published->get_id(), $ids );
		$this->assertNotContains( $draft->get_id(), $ids );
	}

	/**
	 * A variation is readable only while its parent product is published — the
	 * variation post itself stays 'publish' when the parent is drafted.
	 */
	public function test_bucket_listing_products_publish_status_scopes_variations_by_parent(): void {
		$parent = ProductHelper::create_variation_product();
		$parent->set_status( 'draft' );
		$parent->save();
		$variation = (int) $parent->get_children()[0];

		$ids = array_column( $this->index->bucket_listing( 'products', $this->whole_space(), array( 'status' => 'publish' ) ), 'id' );

		$this->assertSame( 'publish', get_post_status( $variation ) );
		$this->assertNotContains( $variation, $ids );
	}

	/**
	 * The authoritative served set drops POS-hidden ids, so the client's reconcile
	 * prunes anything toggled online_only after it was pulled.
	 */
	public function test_bucket_listing_products_excludes_pos_hidden_ids(): void {
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		$this->hide_from_pos( 'products', $hidden->get_id() );

		$ids = array_column( $this->index->bucket_listing( 'products', $this->whole_space() ), 'id' );

		$this->assertContains( $visible->get_id(), $ids );
		$this->assertNotContains( $hidden->get_id(), $ids );
	}

	/**
	 * Hidden variations share the wp_posts id-space and are dropped the same way.
	 */
	public function test_bucket_listing_products_excludes_pos_hidden_variation_ids(): void {
		$parent    = ProductHelper::create_variation_product();
		$variation = (int) $parent->get_children()[0];
		$this->hide_from_pos( 'variations', $variation );

		$ids = array_column( $this->index->bucket_listing( 'products', $this->whole_space() ), 'id' );

		$this->assertContains( $parent->get_id(), $ids );
		$this->assertNotContains( $variation, $ids );
	}

	/**
	 * Customers walk their own id-space and digest as 'customer'.
	 */
	public function test_bucket_listing_customers_walks_the_user_id_space(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$rows = $this->index->bucket_listing( 'customers', $this->whole_space() );
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $user_id === $candidate['id'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'customer', $row['object_type'] );
	}

	/**
	 * A freshly stored digest leaves the bucket in agreement with the live rows.
	 */
	public function test_bucket_aggregates_fresh_stored_digest_reports_match(): void {
		$this->clear_stored_product_digests();
		$product = ProductHelper::create_simple_product();
		$this->clear_stored_product_digests();
		$this->digests->upsert_digest( $product->get_id() );

		$buckets = $this->index->bucket_aggregates( $this->whole_space() )['buckets'];

		$this->assertCount( 1, $buckets );
		$this->assertTrue( $buckets[0]['match'] );
		$this->assertSame( 1, $buckets[0]['stored_count'] );
		$this->assertSame( 1, $buckets[0]['current_count'] );
		$this->assertSame( $buckets[0]['stored_digest'], $buckets[0]['current_digest'] );
	}

	/**
	 * A hook-bypassing row edit drifts the bucket — the whole point of the scan.
	 */
	public function test_bucket_aggregates_hookless_edit_reports_mismatch(): void {
		global $wpdb;
		$this->clear_stored_product_digests();
		$product = ProductHelper::create_simple_product();
		$this->clear_stored_product_digests();
		$this->digests->upsert_digest( $product->get_id() );

		// A direct row write fires no hook, so the stored digest is not refreshed.
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'sql bypass' ), array( 'ID' => $product->get_id() ) );

		$buckets = $this->index->bucket_aggregates( $this->whole_space() )['buckets'];

		$this->assertFalse( $buckets[0]['match'] );
		$this->assertNotSame( $buckets[0]['stored_digest'], $buckets[0]['current_digest'] );
	}

	/**
	 * Digests stay strings: an unsigned 64-bit value cannot survive an int cast.
	 */
	public function test_bucket_aggregates_reports_digests_as_strings(): void {
		$this->clear_stored_product_digests();
		$product = ProductHelper::create_simple_product();
		$this->digests->upsert_digest( $product->get_id() );

		$bucket = $this->index->bucket_aggregates( $this->whole_space() )['buckets'][0];

		$this->assertIsString( $bucket['stored_digest'] );
		$this->assertIsString( $bucket['current_digest'] );
	}

	/**
	 * max_id covers the stored side too, so orphan digests past the last live post
	 * are still inside the walk.
	 */
	public function test_bucket_aggregates_max_id_covers_an_orphan_stored_digest(): void {
		global $wpdb;
		$orphan_id = 987654;
		$wpdb->insert(
			$this->index->table_name(),
			array(
				'object_type' => 'product',
				'object_id' => $orphan_id,
				'digest' => 42,
				'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s' )
		);

		$max_id = $this->index->bucket_aggregates( $this->whole_space() )['max_id'];

		$this->assertSame( $orphan_id, $max_id );
	}

	/**
	 * A live row with no stored digest is missing_stored, not drift.
	 */
	public function test_bucket_drift_reports_missing_stored_for_an_undigested_row(): void {
		$product = ProductHelper::create_simple_product();
		$this->clear_stored_product_digests();

		$rows = $this->index->bucket_drift( $this->whole_space() );
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $product->get_id() === $candidate['id'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'missing_stored', $row['status'] );
		$this->assertNull( $row['stored_digest'] );
		$this->assertIsString( $row['current_digest'] );
	}

	/**
	 * A stored digest whose row is gone is a hook-bypassing delete.
	 */
	public function test_bucket_drift_reports_deleted_for_an_orphan_stored_digest(): void {
		global $wpdb;
		$orphan_id = 987654;
		$wpdb->insert(
			$this->index->table_name(),
			array(
				'object_type' => 'product',
				'object_id' => $orphan_id,
				'digest' => 42,
				'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s' )
		);

		$rows = $this->index->bucket_drift( $this->whole_space() );
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $orphan_id === $candidate['id'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'deleted', $row['status'] );
		$this->assertSame( '42', $row['stored_digest'] );
		$this->assertNull( $row['current_digest'] );
	}

	/**
	 * A refreshed stored digest leaves nothing to report for that id.
	 */
	public function test_bucket_drift_omits_a_row_whose_stored_digest_is_fresh(): void {
		$product = ProductHelper::create_simple_product();
		$this->digests->upsert_digest( $product->get_id() );

		$rows = $this->index->bucket_drift( $this->whole_space() );

		$this->assertNotContains( $product->get_id(), array_column( $rows, 'id' ) );
	}

	/**
	 * The readable-catalog filter keeps published products and drops drafts, in
	 * the caller's own id order.
	 */
	public function test_published_product_ids_keeps_published_and_drops_draft(): void {
		$published = ProductHelper::create_simple_product();
		$draft     = ProductHelper::create_simple_product();
		$draft->set_status( 'draft' );
		$draft->save();

		$kept = $this->index->published_product_ids( array( $draft->get_id(), $published->get_id() ) );

		$this->assertSame( array( $published->get_id() ), $kept );
	}

	/**
	 * Variations follow their parent, exactly as the bucket listing scopes them.
	 */
	public function test_published_product_ids_scopes_variations_by_parent(): void {
		$published_parent = ProductHelper::create_variation_product();
		$draft_parent     = ProductHelper::create_variation_product();
		$draft_parent->set_status( 'draft' );
		$draft_parent->save();
		$published_variation = (int) $published_parent->get_children()[0];
		$draft_variation     = (int) $draft_parent->get_children()[0];

		$kept = $this->index->published_product_ids( array( $published_variation, $draft_variation ) );

		$this->assertSame( array( $published_variation ), $kept );
	}

	/**
	 * No ids in, no query out.
	 */
	public function test_published_product_ids_empty_input_returns_empty(): void {
		$this->assertSame( array(), $this->index->published_product_ids( array() ) );
	}

	/**
	 * Live products with an empty stored side is the "never backfilled" signal.
	 */
	public function test_needs_product_rebuild_true_when_stored_digests_are_empty(): void {
		ProductHelper::create_simple_product();
		$this->clear_stored_product_digests();

		$this->assertTrue( $this->index->needs_product_rebuild() );
	}

	/**
	 * One stored digest is enough to say the store was backfilled.
	 */
	public function test_needs_product_rebuild_false_once_a_digest_is_stored(): void {
		$product = ProductHelper::create_simple_product();
		$this->digests->upsert_digest( $product->get_id() );

		$this->assertFalse( $this->index->needs_product_rebuild() );
	}
}
