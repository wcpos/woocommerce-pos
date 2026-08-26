<?php
/**
 * Tests for sync record identity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\FunctionsMockerHack;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Customer;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_UnitTestCase;
use wpdb;

/**
 * Pos UUID tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid
 */
class Test_Pos_Uuid extends WP_UnitTestCase {
	/**
	 * Tear down: unregister function mocks (multisite simulation).
	 */
	public function tearDown(): void {
		FunctionsMockerHack::get_hack_instance()->reset();
		parent::tearDown();
	}

	/**
	 * Simulate a multisite install inside plugin code (CodeHacker only rewrites
	 * calls in includes/, so test code still sees the real single-site functions).
	 *
	 * @param int $blog_id Blog id reported to plugin code.
	 */
	private function mock_multisite( int $blog_id = 2 ): void {
		FunctionsMockerHack::add_function_mocks(
			array(
				'is_multisite'        => function () {
					return true;
				},
				'get_current_blog_id' => function () use ( $blog_id ) {
					return $blog_id;
				},
			)
		);
	}

	/**
	 * The identity brain uses the shared production meta-key constant.
	 */
	public function test_meta_key_uses_sync_api_constant(): void {
		$this->assertSame( Api::UUID_META_KEY, Pos_Uuid::META_KEY );
	}

	/**
	 * On multisite, a legacy per-blog cashier uuid is adopted as the network-wide
	 * identity by ANY caller of ensure_user_uuid — not just /cashier — so the
	 * adopted identity is the same whichever endpoint reads the user first.
	 */
	public function test_ensure_user_uuid_multisite_adopts_legacy_per_blog_uuid(): void {
		$user_id     = $this->factory->user->create();
		$legacy_uuid = wp_generate_uuid4();
		update_user_meta( $user_id, Pos_Uuid::META_KEY . '_2', $legacy_uuid );
		$this->mock_multisite( 2 );

		$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

		$this->assertSame( $legacy_uuid, $uuid );
		$this->assertSame( $legacy_uuid, get_user_meta( $user_id, Pos_Uuid::META_KEY, true ) );
	}

	/**
	 * A legacy per-blog uuid already owned by ANOTHER user's network identity is
	 * not served as a duplicate RxDB key — the authority re-mints instead.
	 */
	public function test_ensure_user_uuid_multisite_rejects_legacy_uuid_owned_by_other_user(): void {
		$owner_id  = $this->factory->user->create();
		$victim_id = $this->factory->user->create();
		$uuid      = wp_generate_uuid4();
		update_user_meta( $owner_id, Pos_Uuid::META_KEY, $uuid );
		update_user_meta( $victim_id, Pos_Uuid::META_KEY . '_2', $uuid );
		$this->mock_multisite( 2 );

		$victim_uuid = Pos_Uuid::ensure_user_uuid( $victim_id );

		$this->assertTrue( Pos_Uuid::is_uuid( $victim_uuid ) );
		$this->assertNotSame( $uuid, $victim_uuid );
	}

	/**
	 * A valid canonical duplicate wins even when an invalid row precedes it.
	 */
	public function test_ensure_user_uuid_multisite_preserves_later_valid_canonical_uuid(): void {
		$user_id        = $this->factory->user->create();
		$canonical_uuid = wp_generate_uuid4();
		$legacy_uuid    = wp_generate_uuid4();
		add_user_meta( $user_id, Pos_Uuid::META_KEY, 'invalid' );
		add_user_meta( $user_id, Pos_Uuid::META_KEY, $canonical_uuid );
		update_user_meta( $user_id, Pos_Uuid::META_KEY . '_2', $legacy_uuid );
		$this->mock_multisite( 2 );

		$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

		$this->assertSame( $canonical_uuid, $uuid );
		$this->assertSame( array( $canonical_uuid ), get_user_meta( $user_id, Pos_Uuid::META_KEY, false ) );
	}

	/**
	 * Direct customer stamping uses the same multisite legacy adoption path.
	 */
	public function test_ensure_uuid_multisite_customer_adopts_legacy_per_blog_uuid(): void {
		$user_id     = $this->factory->user->create();
		$legacy_uuid = wp_generate_uuid4();
		update_user_meta( $user_id, Pos_Uuid::META_KEY . '_2', $legacy_uuid );
		$this->mock_multisite( 2 );

		$uuid = Pos_Uuid::ensure_uuid(
			new WC_Customer( $user_id ),
			array( 'collides' => array( Pos_Uuid::class, 'uuid_owned_by_other_user' ) )
		);

		$this->assertSame( $legacy_uuid, $uuid );
		$this->assertSame( $legacy_uuid, get_user_meta( $user_id, Pos_Uuid::META_KEY, true ) );
	}

	/**
	 * A request cannot promote its legacy UUID while another connection owns the
	 * same user's adoption lock — but it must still serve the adoptable legacy
	 * value (never ''), because the uuid is the client's RxDB primary key.
	 */
	public function test_ensure_user_uuid_multisite_does_not_overwrite_while_adoption_is_locked(): void {
		$user_id     = $this->factory->user->create();
		$legacy_uuid = wp_generate_uuid4();
		$lock_name   = 'wcpos_user_uuid_' . $user_id;
		$locker      = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		update_user_meta( $user_id, Pos_Uuid::META_KEY . '_2', $legacy_uuid );
		$this->mock_multisite( 2 );
		$this->assertSame( '1', (string) $locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) );

		try {
			$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

			$this->assertSame( $legacy_uuid, $uuid, 'The adoptable legacy uuid should be served read-only.' );
			$this->assertSame( array(), get_user_meta( $user_id, Pos_Uuid::META_KEY, false ), 'Nothing may be written while the adoption lock is held elsewhere.' );
		} finally {
			$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$locker->close();
		}
	}

	/**
	 * A first stamp under lock contention (no canonical, no legacy) mints a
	 * fallback rather than serving '' — an empty uuid is a client-side identity
	 * failure on first login.
	 */
	public function test_ensure_user_uuid_multisite_first_stamp_under_contention_mints_fallback(): void {
		$user_id   = $this->factory->user->create();
		$lock_name = 'wcpos_user_uuid_' . $user_id;
		$locker    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->mock_multisite( 2 );
		$this->assertSame( '1', (string) $locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) );

		try {
			$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

			$this->assertTrue( Pos_Uuid::is_uuid( $uuid ), 'A valid uuid must be served even under lock contention.' );
			$this->assertSame( $uuid, get_user_meta( $user_id, Pos_Uuid::META_KEY, true ), 'The fallback must be persisted so the next read serves the same identity.' );
		} finally {
			$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$locker->close();
		}
	}

	/**
	 * A lock-contended read serves a concurrently persisted winner instead of
	 * minting its own uuid.
	 */
	public function test_ensure_user_uuid_multisite_contention_serves_concurrent_winner(): void {
		$user_id     = $this->factory->user->create();
		$winner_uuid = wp_generate_uuid4();
		$lock_name   = 'wcpos_user_uuid_' . $user_id;
		$locker      = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->mock_multisite( 2 );
		$this->assertSame( '1', (string) $locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) );
		add_user_meta( $user_id, Pos_Uuid::META_KEY, $winner_uuid, true );

		try {
			$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

			$this->assertSame( $winner_uuid, $uuid );
			$this->assertSame( array( $winner_uuid ), get_user_meta( $user_id, Pos_Uuid::META_KEY, false ) );
		} finally {
			$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$locker->close();
		}
	}

	/**
	 * A legacy per-blog uuid owned by ANOTHER user under a LEGACY key (not the
	 * network key) is never adopted: the first reader must not claim a shared
	 * legacy value, or the rightful owner's identity forks when they read later.
	 */
	public function test_ensure_user_uuid_multisite_rejects_legacy_uuid_owned_by_other_user_legacy_key(): void {
		$owner_id  = $this->factory->user->create();
		$victim_id = $this->factory->user->create();
		$uuid      = wp_generate_uuid4();
		update_user_meta( $owner_id, Pos_Uuid::META_KEY . '_2', $uuid );
		update_user_meta( $victim_id, Pos_Uuid::META_KEY . '_2', $uuid );
		$this->mock_multisite( 2 );

		$victim_uuid = Pos_Uuid::ensure_user_uuid( $victim_id );

		$this->assertTrue( Pos_Uuid::is_uuid( $victim_uuid ) );
		$this->assertNotSame( $uuid, $victim_uuid, 'A shared legacy value must not be claimed as the victim\'s identity.' );
		$this->assertSame(
			$uuid,
			get_user_meta( $owner_id, Pos_Uuid::META_KEY . '_2', true ),
			'The rightful owner\'s legacy row must be left in place.'
		);
	}

	/**
	 * A live network identity is NEVER discarded because a stale duplicated
	 * legacy row on another user carries the same value — the legacy-key
	 * ownership check is an adoption gate, not a live-collision predicate.
	 */
	public function test_ensure_user_uuid_multisite_keeps_live_identity_despite_stale_legacy_duplicate(): void {
		$user_id  = $this->factory->user->create();
		$other_id = $this->factory->user->create();
		$live     = wp_generate_uuid4();
		update_user_meta( $user_id, Pos_Uuid::META_KEY, $live );
		update_user_meta( $other_id, Pos_Uuid::META_KEY . '_2', $live );
		$this->mock_multisite( 2 );

		$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

		$this->assertSame( $live, $uuid, 'The already-served identity must be kept.' );
		$this->assertSame( array( $live ), get_user_meta( $user_id, Pos_Uuid::META_KEY, false ) );
	}

	/**
	 * A lock-contended first stamp with a corrupt stored row persists its
	 * fallback (compare-and-swap on the invalid row) instead of returning a uuid
	 * that was never written.
	 */
	public function test_ensure_user_uuid_multisite_contention_replaces_corrupt_row_with_persisted_fallback(): void {
		$user_id   = $this->factory->user->create();
		$lock_name = 'wcpos_user_uuid_' . $user_id;
		$locker    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		update_user_meta( $user_id, Pos_Uuid::META_KEY, 'garbage' );
		$this->mock_multisite( 2 );
		$this->assertSame( '1', (string) $locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) );

		try {
			$uuid = Pos_Uuid::ensure_user_uuid( $user_id );

			$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );
			$this->assertSame( $uuid, get_user_meta( $user_id, Pos_Uuid::META_KEY, true ), 'The served uuid must be the persisted one.' );
		} finally {
			$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$locker->close();
		}
	}

	/**
	 * A missing UUID is minted and persisted through the WC data API.
	 */
	public function test_ensure_uuid_mints_and_persists_product_identity(): void {
		$product = ProductHelper::create_simple_product();
		$uuid    = Pos_Uuid::ensure_uuid( $product );

		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );
		$this->assertSame( $uuid, wc_get_product( $product->get_id() )->get_meta( Api::UUID_META_KEY ) );
		$this->assertSame( $uuid, Pos_Uuid::ensure_uuid( wc_get_product( $product->get_id() ) ) );
	}

	/**
	 * Payload stamping removes conflicting copies and preserves unrelated meta.
	 */
	public function test_ensure_in_payload_keeps_one_canonical_uuid(): void {
		$uuid    = wp_generate_uuid4();
		$payload = Pos_Uuid::ensure_in_payload(
			array(
				'meta_data' => array(
					array(
						'key' => Api::UUID_META_KEY,
						'value' => '',
					),
					array(
						'key' => 'other',
						'value' => 'kept',
					),
					array(
						'key' => Api::UUID_META_KEY,
						'value' => wp_generate_uuid4(),
					),
				),
			),
			$uuid
		);

		$this->assertSame(
			array(
				array(
					'key' => 'other',
					'value' => 'kept',
				),
				array(
					'key' => Api::UUID_META_KEY,
					'value' => $uuid,
				),
			),
			$payload['meta_data']
		);
	}

	/**
	 * A copied UUID on another active post is a collision.
	 */
	public function test_uuid_owned_by_other_detects_active_product_collision(): void {
		$uuid    = wp_generate_uuid4();
		$first   = ProductHelper::create_simple_product();
		$second  = ProductHelper::create_simple_product();
		$first->update_meta_data( Api::UUID_META_KEY, $uuid );
		$first->save_meta_data();
		$second->update_meta_data( Api::UUID_META_KEY, $uuid );
		$second->save_meta_data();

		$this->assertTrue( Pos_Uuid::uuid_owned_by_other( $uuid, $second ) );
	}

	/**
	 * The shared identity brain repairs a copied UUID without touching its owner.
	 */
	public function test_ensure_uuid_repairs_active_product_collision(): void {
		$uuid   = wp_generate_uuid4();
		$owner  = ProductHelper::create_simple_product();
		$cloned = ProductHelper::create_simple_product();
		update_post_meta( $owner->get_id(), Api::UUID_META_KEY, $uuid );
		update_post_meta( $cloned->get_id(), Api::UUID_META_KEY, $uuid );

		$repaired = Pos_Uuid::ensure_uuid(
			wc_get_product( $cloned->get_id() ),
			array( 'collides' => array( Pos_Uuid::class, 'uuid_owned_by_other' ) )
		);

		$this->assertTrue( Pos_Uuid::is_uuid( $repaired ) );
		$this->assertNotSame( $uuid, $repaired );
		$this->assertSame( $uuid, get_post_meta( $owner->get_id(), Api::UUID_META_KEY, true ) );
		$this->assertSame( $repaired, get_post_meta( $cloned->get_id(), Api::UUID_META_KEY, true ) );
	}

	/**
	 * Before-save stamping attaches a UUID without recursively saving the object.
	 */
	public function test_stamp_on_save_adds_uuid_to_in_progress_product(): void {
		$product = ProductHelper::create_simple_product();
		$product->delete_meta_data( Api::UUID_META_KEY );

		Pos_Uuid::stamp_on_save( $product );

		$this->assertTrue( Pos_Uuid::is_uuid( $product->get_meta( Api::UUID_META_KEY ) ) );
	}

	/**
	 * A term collision re-key on duplicate uuid rows collapses to ONE row
	 * (update_term_meta alone rewrites all rows without pruning — codex P2).
	 */
	public function test_term_collision_rekey_collapses_duplicate_rows(): void {
		$term = wp_insert_term( 'Dup Uuid Term ' . wp_generate_uuid4(), 'product_cat' );
		$term_id = (int) $term['term_id'];
		$other = wp_insert_term( 'Owner Term ' . wp_generate_uuid4(), 'product_cat' );
		$owned = wp_generate_uuid4();
		add_term_meta( (int) $other['term_id'], Api::UUID_META_KEY, $owned );
		// Two duplicate rows, the first colliding with the other term's uuid.
		add_term_meta( $term_id, Api::UUID_META_KEY, $owned );
		add_term_meta( $term_id, Api::UUID_META_KEY, wp_generate_uuid4() );

		$resolved = Pos_Uuid::ensure_term_uuid( $term_id );

		$rows = get_term_meta( $term_id, Api::UUID_META_KEY, false );
		$this->assertCount( 1, $rows, 'duplicate uuid rows must collapse to one' );
		$this->assertSame( $resolved, $rows[0] );
		$this->assertNotSame( $owned, $resolved );
	}

	/**
	 * The order-item UUID mint holds its lock on the DATABASE, not in the object
	 * cache.
	 *
	 * The original lock was a `wp_cache_add` sentinel, which is silently
	 * ineffective on the many installs with no persistent object cache: two
	 * concurrent requests both "won" and both minted. This pins the replacement
	 * by observing the live mint from a SECOND database connection — the lock is
	 * unavailable there for as long as the mint holds it.
	 *
	 * Probing from inside the critical section keeps the test instant: contending
	 * from the outside would sit out the production 10-second GET_LOCK timeout.
	 */
	public function test_ensure_order_item_uuid_holds_the_lock_on_the_datastore(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$items = $order->get_items();
		$item  = reset( $items );
		$item->delete_meta_data( Pos_Uuid::META_KEY );
		$item->save_meta_data();

		$lock_key  = 'wc_pos_uuid_order_item_' . $item->get_id();
		$observer  = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$contended = null;
		// wc_get_order_item_meta() runs inside the critical section, so its
		// short-circuit filter is a probe point reached only while the lock is held.
		$probe = function ( $value, $object_id, $meta_key ) use ( $observer, $lock_key, &$contended ) {
			if ( null === $contended && Pos_Uuid::META_KEY === $meta_key ) {
				$contended = (string) $observer->get_var( $observer->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, 0 ) );
			}

			return $value;
		};
		add_filter( 'get_order_item_metadata', $probe, 10, 3 );

		// Act.
		try {
			Pos_Uuid::ensure_order_item_uuid( $item );
		} finally {
			remove_filter( 'get_order_item_metadata', $probe, 10 );
			$observer->get_var( $observer->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
			$observer->close();
		}

		// Assert.
		$this->assertSame( '0', $contended, 'A second connection must not acquire the lock while the mint holds it.' );

		// Read the uuid back out of the datastore rather than off the in-memory
		// item: $item->get_meta() would return what ensure_order_item_uuid() set
		// on the object, so a regression that dropped save_meta_data() would still
		// pass. This test exists because the ORIGINAL lock was ineffective without
		// a persistent object cache — persistence is the property under test.
		$this->assertTrue( Pos_Uuid::is_uuid( wc_get_order_item_meta( $item->get_id(), Pos_Uuid::META_KEY, true ) ) );
	}

	/**
	 * CPT lookup returns the order that owns the uuid.
	 *
	 * COVERAGE NOTE (#1725): `get_order_ids_by_uuid()` used to be tested only in
	 * Test_Pos_Uuid_HPOS — the datastore where its `wp_postmeta` full-scan
	 * regression could not appear, because `wc_orders_meta` carries a composite
	 * `(meta_key, meta_value)` index and `wp_postmeta` does not. The CPT branch,
	 * which is the suite default AND the branch that broke, had no coverage at all.
	 * These tests are that branch.
	 */
	public function test_get_order_ids_by_uuid_returns_the_owning_cpt_order(): void {
		// Arrange.
		$this->assert_running_on_cpt_storage();
		$uuid  = wp_generate_uuid4();
		$order = OrderHelper::create_order();
		$order->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$order->save_meta_data();

		// Act.
		$ids = Pos_Uuid::get_order_ids_by_uuid( $uuid );

		// Assert.
		$this->assertSame( array( (string) $order->get_id() ), $ids );
	}

	/**
	 * An unknown uuid owns nothing.
	 *
	 * This is the SHAPE of the call the hot path actually makes: the collision
	 * detector runs while stamping, so the predicate usually matches at most the
	 * record being stamped. A `LIMIT 2` that never reaches two is precisely what
	 * let the bad plan walk the whole meta table.
	 */
	public function test_get_order_ids_by_uuid_returns_empty_for_an_unknown_uuid(): void {
		// Arrange.
		$this->assert_running_on_cpt_storage();
		OrderHelper::create_order();

		// Act.
		$ids = Pos_Uuid::get_order_ids_by_uuid( wp_generate_uuid4() );

		// Assert.
		$this->assertSame( array(), $ids );
	}

	/**
	 * A trashed CPT order retaining the uuid is not a live owner.
	 */
	public function test_get_order_ids_by_uuid_ignores_trashed_cpt_order_when_live_owner_exists(): void {
		// Arrange.
		global $wpdb;
		$this->assert_running_on_cpt_storage();
		$uuid    = wp_generate_uuid4();
		$trashed = OrderHelper::create_order();
		$trashed->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$trashed->save_meta_data();
		$trashed_id = $trashed->get_id();
		$trashed->delete( false );

		$live = OrderHelper::create_order();
		$live->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$live->save_meta_data();

		$retained = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$trashed_id,
				Pos_Uuid::META_KEY
			)
		);
		$this->assertSame( $uuid, $retained, 'The trashed order must retain its uuid for this regression.' );

		// Act.
		$ids = Pos_Uuid::get_order_ids_by_uuid( $uuid );

		// Assert.
		$this->assertSame( array( (string) $live->get_id() ), $ids );
	}

	/**
	 * A CPT refund sharing a uuid does not count as a second order owner.
	 */
	public function test_get_order_ids_by_uuid_ignores_cpt_refund_when_live_order_exists(): void {
		// Arrange.
		$this->assert_running_on_cpt_storage();
		$uuid    = wp_generate_uuid4();
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$order->save();

		$refund = wc_create_refund(
			array(
				'amount'   => '10.00',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertNotWPError( $refund );
		$refund->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$refund->save();

		// Act.
		$ids = Pos_Uuid::get_order_ids_by_uuid( $uuid );

		// Assert.
		$this->assertSame( array( (string) $order->get_id() ), $ids );
	}

	/**
	 * Two live CPT orders sharing a uuid are both reported, so callers can fail
	 * closed on an ambiguous identity.
	 *
	 * Deliberately order-INDEPENDENT: the query carries no `ORDER BY` (#1725), and
	 * every caller decides on the COUNT rather than on which ids came back.
	 */
	public function test_get_order_ids_by_uuid_reports_both_owners_when_two_live_cpt_orders_share_a_uuid(): void {
		// Arrange.
		$this->assert_running_on_cpt_storage();
		$uuid  = wp_generate_uuid4();
		$first = OrderHelper::create_order();
		$first->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$first->save_meta_data();
		$second = OrderHelper::create_order();
		$second->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$second->save_meta_data();

		// Act.
		$ids = array_map( 'intval', Pos_Uuid::get_order_ids_by_uuid( $uuid ) );
		sort( $ids );

		// Assert.
		$expected = array( $first->get_id(), $second->get_id() );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * A collision on a DIFFERENT live CPT order is reported; the record's own uuid
	 * is not a collision with itself.
	 */
	public function test_uuid_owned_by_other_order_distinguishes_self_from_a_cpt_clone(): void {
		// Arrange.
		$this->assert_running_on_cpt_storage();
		$uuid  = wp_generate_uuid4();
		$owner = OrderHelper::create_order();
		$owner->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$owner->save_meta_data();

		// Act + Assert: the owner does not collide with itself.
		$this->assertFalse( Pos_Uuid::uuid_owned_by_other_order( $uuid, $owner ) );

		// Arrange: a clone copies the meta onto a second live order.
		$clone = OrderHelper::create_order();
		$clone->update_meta_data( Pos_Uuid::META_KEY, $uuid );
		$clone->save_meta_data();

		// Act + Assert: each now sees the other as a live owner.
		$this->assertTrue( Pos_Uuid::uuid_owned_by_other_order( $uuid, $owner ) );
		$this->assertTrue( Pos_Uuid::uuid_owned_by_other_order( $uuid, $clone ) );
	}

	/**
	 * Guard: these tests only mean anything on the legacy CPT order datastore.
	 */
	private function assert_running_on_cpt_storage(): void {
		$this->assertFalse(
			class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled(),
			'This class is the CPT sibling of Test_Pos_Uuid_HPOS; HPOS must be off here.'
		);
	}
}
