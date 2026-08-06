<?php
/**
 * Tests for sync record identity.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\FunctionsMockerHack;
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
}
