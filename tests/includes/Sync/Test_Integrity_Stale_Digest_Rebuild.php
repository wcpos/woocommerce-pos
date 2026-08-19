<?php
/**
 * Tests for the stale-stored-digest rebuild trigger.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * A hookless write leaves the STORED digest stale while the row itself is
 * current. The scan reports it, clients pull, and nothing ever rewrites the
 * stored side — so without a second rebuild trigger the bucket mismatches
 * forever and the till shows a permanent "records need attention" for data
 * that is already correct. Observed on dev-pro 2026-08-19: 138 products
 * drifted by a hookless bulk edit, re-escalated every sweep, local copies
 * byte-identical to the server.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 */
class Test_Integrity_Stale_Digest_Rebuild extends Sync_REST_Store_Test_Case {
	/**
	 * Isolate cron, lock and streak state.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		delete_option( Integrity_Controller::DRIFT_STREAK_OPTION );
	}

	/**
	 * Remove non-transactional option, transient and cron state.
	 */
	public function tearDown(): void {
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		delete_option( Integrity_Controller::DRIFT_STREAK_OPTION );
		parent::tearDown();
	}

	/**
	 * Create a product (its hook stores a digest), then edit the row with raw
	 * SQL so no hook fires — the stored digest is now stale while the product
	 * itself is perfectly current. This is what a CSV import, a migration
	 * plugin, WP-CLI or direct SQL does.
	 *
	 * @return int Product id.
	 */
	private function create_product_with_stale_stored_digest(): int {
		global $wpdb;

		$product = ProductHelper::create_simple_product();
		$id      = $product->get_id();

		// Give the stored side a digest first — a real store gets this from the
		// save hook. Without it the product space has NO stored digests at all
		// and the empty-table guard (a different trigger) short-circuits the
		// drill-down before any drift is reported.
		( new Integrity_Digest() )->rebuild();

		// Raw UPDATE: post_title is folded into the digest formula, and no
		// save_post/woocommerce_* hook runs, so the stored digest is untouched.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_title' => 'Hookless bulk edit ' . $id ),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		return $id;
	}

	/**
	 * Drill into the bucket holding the product.
	 *
	 * @param int $product_id Product id used to select the bucket.
	 * @param int $bucket_size Number of ids covered by the bucket.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch_drill_down( int $product_id, int $bucket_size = 1000 ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'bucket_size' => $bucket_size,
				'bucket'      => (int) floor( $product_id / $bucket_size ),
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * The drill-down must actually see the stale row — if this fails the rest
	 * of the file is testing nothing.
	 */
	public function test_hookless_write_shows_as_a_changed_row(): void {
		$product_id = $this->create_product_with_stale_stored_digest();

		$data = $this->dispatch_drill_down( $product_id );

		$changed = array_values(
			array_filter(
				$data['changes'],
				static fn ( $change ) => (int) $change['id'] === $product_id && 'changed' === $change['status']
			)
		);
		$this->assertCount( 1, $changed );
		$this->assertNotNull( $changed[0]['stored_digest'] );
		$this->assertNotNull( $changed[0]['current_digest'] );
		$this->assertNotSame( $changed[0]['stored_digest'], $changed[0]['current_digest'] );
	}

	/**
	 * The client pulls the drifted ids on every mismatched sweep and escalates
	 * only at 2 consecutive post-pull mismatches. Re-baselining before that
	 * would erase the ONLY signal a hookless write produces (it bypasses the
	 * sequence log too), so the first drill-downs must NOT schedule anything.
	 */
	public function test_drift_below_the_threshold_does_not_schedule_a_rebuild(): void {
		$product_id = $this->create_product_with_stale_stored_digest();

		for ( $i = 1; $i < Integrity_Controller::DRIFT_REBUILD_THRESHOLD; $i++ ) {
			$this->dispatch_drill_down( $product_id );
			$this->assertFalse(
				wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ),
				\sprintf( 'drill-down %d must not schedule a rebuild', $i )
			);
		}

		$streaks = get_option( Integrity_Controller::DRIFT_STREAK_OPTION );
		$this->assertIsArray( $streaks );
		$this->assertSame(
			Integrity_Controller::DRIFT_REBUILD_THRESHOLD - 1,
			(int) reset( $streaks )
		);
	}

	/**
	 * Equal bucket numbers at different sizes cover different id ranges and
	 * must not combine into one rebuild streak.
	 */
	public function test_different_bucket_sizes_do_not_share_a_streak(): void {
		$product_id   = $this->create_product_with_stale_stored_digest();
		$bucket_sizes = array();
		$first_size   = 0;
		$second_size  = 0;

		for ( $size = 10000; $size > 0; --$size ) {
			$bucket = (int) floor( $product_id / $size );
			if ( isset( $bucket_sizes[ $bucket ] ) ) {
				$first_size  = $bucket_sizes[ $bucket ];
				$second_size = $size;
				break;
			}
			$bucket_sizes[ $bucket ] = $size;
		}

		$this->assertNotSame( 0, $second_size, 'test product id must fit two bucket sizes with the same bucket number' );
		$this->dispatch_drill_down( $product_id, $first_size );
		$this->dispatch_drill_down( $product_id, $second_size );
		$this->dispatch_drill_down( $product_id, $first_size );

		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertCount( 2, get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) );
	}

	/**
	 * Equal bucket sizes at different bucket numbers must not combine into one
	 * rebuild streak.
	 */
	public function test_different_buckets_do_not_share_a_streak(): void {
		global $wpdb;

		$first_id  = $this->create_product_with_stale_stored_digest();
		$second_id = $this->create_product_with_stale_stored_digest();
		$wpdb->update(
			$wpdb->posts,
			array( 'post_title' => 'Second hookless bulk edit ' . $first_id ),
			array( 'ID' => $first_id )
		);
		clean_post_cache( $first_id );

		$this->dispatch_drill_down( $first_id, 1 );
		$this->dispatch_drill_down( $second_id, 1 );
		$this->dispatch_drill_down( $first_id, 1 );

		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertCount( 2, get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) );
	}

	/**
	 * The empty-digest trigger supersedes any retained drift streaks.
	 */
	public function test_empty_digest_trigger_clears_retained_streaks(): void {
		global $wpdb;

		$product = ProductHelper::create_simple_product();
		$digest  = new Integrity_Digest();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- internal table name and SQL constant.
		$wpdb->query(
			'DELETE FROM ' . $digest->table_name() . ' WHERE object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		update_option( Integrity_Controller::DRIFT_STREAK_OPTION, array( '1000:0' => 2 ), false );

		$this->dispatch_drill_down( $product->get_id() );

		$this->assertFalse( get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	/**
	 * Once the same bucket has reported stale digests past the threshold, the
	 * drift is provably not a delivery problem — schedule the rebuild.
	 */
	public function test_persistent_drift_schedules_one_rebuild(): void {
		$product_id = $this->create_product_with_stale_stored_digest();

		for ( $i = 0; $i < Integrity_Controller::DRIFT_REBUILD_THRESHOLD; $i++ ) {
			$this->dispatch_drill_down( $product_id );
		}

		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertFalse( get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) );
	}

	/**
	 * The scheduled rebuild reconciles the stored side, and the next drill-down
	 * is clean — the permanent banner clears.
	 */
	public function test_rebuild_clears_the_drift(): void {
		$product_id = $this->create_product_with_stale_stored_digest();

		for ( $i = 0; $i < Integrity_Controller::DRIFT_REBUILD_THRESHOLD; $i++ ) {
			$this->dispatch_drill_down( $product_id );
		}
		do_action( Integrity_Digest::REBUILD_HOOK );

		$data    = $this->dispatch_drill_down( $product_id );
		$changed = array_filter(
			$data['changes'],
			static fn ( $change ) => (int) $change['id'] === $product_id && 'changed' === $change['status']
		);
		$this->assertSame( array(), $changed, 'rebuild must reconcile the stale stored digest' );
	}

	/**
	 * A product drift trigger must not re-baseline unrelated customer or order
	 * digests whose source rows may also have changed without hooks.
	 */
	public function test_scheduled_product_rebuild_preserves_customer_and_order_digests(): void {
		global $wpdb;

		$product_id  = $this->create_product_with_stale_stored_digest();
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$order_id    = OrderHelper::create_order()->get_id();
		$digest      = new Integrity_Digest();
		$digest->upsert_customer_digest( $customer_id );
		$digest->upsert_order_digest( $order_id );

		$customer_digest = $digest->read_customer_digests( array( $customer_id ) )[ $customer_id ];
		$order_digest    = $digest->read_order_digests( array( $order_id ) )[ $order_id ];
		$stale_customer  = '1' === $customer_digest ? '2' : '1';
		$stale_order     = '1' === $order_digest ? '2' : '1';
		$wpdb->update(
			$digest->table_name(),
			array( 'digest' => $stale_customer ),
			array( 'object_type' => 'customer', 'object_id' => $customer_id )
		);
		$wpdb->update(
			$digest->table_name(),
			array( 'digest' => $stale_order ),
			array( 'object_type' => 'order', 'object_id' => $order_id )
		);

		for ( $i = 0; $i < Integrity_Controller::DRIFT_REBUILD_THRESHOLD; $i++ ) {
			$this->dispatch_drill_down( $product_id );
		}
		do_action( Integrity_Digest::REBUILD_HOOK );

		$this->assertSame( $stale_customer, $digest->read_customer_digests( array( $customer_id ) )[ $customer_id ] );
		$this->assertSame( $stale_order, $digest->read_order_digests( array( $order_id ) )[ $order_id ] );

		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/integrity/rebuild' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $customer_digest, $digest->read_customer_digests( array( $customer_id ) )[ $customer_id ] );
		$this->assertSame( $order_digest, $digest->read_order_digests( array( $order_id ) )[ $order_id ] );
	}

	/**
	 * A bucket that reconciles must not carry its streak forward — otherwise
	 * unrelated drift months apart would accumulate into a spurious rebuild.
	 * The streak counts CONSECUTIVE drifted drill-downs, not lifetime drift.
	 */
	public function test_a_clean_drill_down_resets_the_streak(): void {
		$product_id = $this->create_product_with_stale_stored_digest();
		$this->dispatch_drill_down( $product_id );
		$this->assertIsArray( get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) );

		// The bucket reconciles — the stored side catches up with the row.
		( new Integrity_Digest() )->rebuild();

		$this->dispatch_drill_down( $product_id );

		$this->assertFalse( get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}
}
