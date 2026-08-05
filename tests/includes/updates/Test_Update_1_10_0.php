<?php
/**
 * Tests for the 1.10.0 database migration that promotes legacy multisite
 * per-blog user uuids (`_woocommerce_pos_uuid_{blog_id}`) into the plain
 * network-wide `_woocommerce_pos_uuid` key and deletes the legacy rows.
 *
 * @see https://github.com/wcpos/woocommerce-pos/pull/1450
 *
 * @package WCPOS\WooCommercePOS\Tests\Updates
 */

namespace WCPOS\WooCommercePOS\Tests\Updates;

use WP_UnitTestCase;

/**
 * Tests for update-1.10.0.php legacy uuid promotion.
 *
 * The migration operates purely on usermeta key names (the legacy key encodes
 * the blog id), so it is exercisable on a single-site test install by seeding
 * the legacy keys directly.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Update_1_10_0 extends WP_UnitTestCase {
	/**
	 * Count usermeta rows for a given user + meta_key (uncached, direct query).
	 *
	 * @param int    $user_id  The user ID.
	 * @param string $meta_key The meta key.
	 *
	 * @return int
	 */
	private function count_meta_rows( int $user_id, string $meta_key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
				$user_id,
				$meta_key
			)
		);
	}

	/**
	 * Run the migration script.
	 */
	private function run_migration(): void {
		include __DIR__ . '/../../../includes/updates/update-1.10.0.php';
	}

	/**
	 * Test a legacy per-blog uuid is promoted to the plain key and the legacy
	 * row is deleted when the user has no plain uuid yet.
	 */
	public function test_migration_promotes_legacy_uuid_when_plain_key_absent(): void {
		$user_id     = $this->factory()->user->create();
		$legacy_uuid = wp_generate_uuid4();
		update_user_meta( $user_id, '_woocommerce_pos_uuid_2', $legacy_uuid );

		$this->run_migration();

		$this->assertSame( $legacy_uuid, get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 0, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid_2' ) );
	}

	/**
	 * Test an existing valid plain uuid wins over legacy values — it is what
	 * the /customers endpoint has already served to clients.
	 */
	public function test_migration_preserves_existing_plain_uuid(): void {
		$user_id    = $this->factory()->user->create();
		$plain_uuid = wp_generate_uuid4();
		update_user_meta( $user_id, '_woocommerce_pos_uuid', $plain_uuid );
		update_user_meta( $user_id, '_woocommerce_pos_uuid_2', wp_generate_uuid4() );

		$this->run_migration();

		$this->assertSame( $plain_uuid, get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 0, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid_2' ) );
	}

	/**
	 * Test the lowest blog id's value wins when a user carries legacy uuids
	 * for multiple blogs (deterministic, numeric ordering — blog 2 beats 10).
	 */
	public function test_migration_promotes_lowest_blog_id_value(): void {
		$user_id = $this->factory()->user->create();
		$winner  = wp_generate_uuid4();
		$loser   = wp_generate_uuid4();
		update_user_meta( $user_id, '_woocommerce_pos_uuid_10', $loser );
		update_user_meta( $user_id, '_woocommerce_pos_uuid_2', $winner );

		$this->run_migration();

		$this->assertSame( $winner, get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 0, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid_2' ) );
		$this->assertEquals( 0, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid_10' ) );
	}

	/**
	 * Test an INVALID plain value (non-uuid junk) is overwritten by a valid
	 * legacy uuid — mirrors Pos_Uuid's adoption, which treats junk as absent.
	 */
	public function test_migration_replaces_invalid_plain_value_with_legacy_uuid(): void {
		$user_id     = $this->factory()->user->create();
		$legacy_uuid = wp_generate_uuid4();
		update_user_meta( $user_id, '_woocommerce_pos_uuid', 'not-a-valid-uuid' );
		update_user_meta( $user_id, '_woocommerce_pos_uuid_3', $legacy_uuid );

		$this->run_migration();

		$this->assertSame( $legacy_uuid, get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * Test an invalid legacy value is never promoted, but its row is still
	 * cleaned up. The plain key stays absent so Pos_Uuid mints a fresh uuid on
	 * the next read.
	 */
	public function test_migration_skips_invalid_legacy_value_but_deletes_row(): void {
		$user_id = $this->factory()->user->create();
		update_user_meta( $user_id, '_woocommerce_pos_uuid_2', 'junk-value' );

		$this->run_migration();

		$this->assertSame( '', get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 0, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid_2' ) );
	}

	/**
	 * Test a legacy value already owned by ANOTHER user's plain key (a cloned /
	 * imported row) is not promoted — serving it would fork one RxDB primary key
	 * across two users. The legacy row is still deleted.
	 */
	public function test_migration_skips_promotion_when_uuid_owned_by_other_user(): void {
		$owner_id  = $this->factory()->user->create();
		$clone_id  = $this->factory()->user->create();
		$dupe_uuid = wp_generate_uuid4();
		update_user_meta( $owner_id, '_woocommerce_pos_uuid', $dupe_uuid );
		update_user_meta( $clone_id, '_woocommerce_pos_uuid_2', $dupe_uuid );

		$this->run_migration();

		$this->assertSame( $dupe_uuid, get_user_meta( $owner_id, '_woocommerce_pos_uuid', true ) );
		$this->assertSame( '', get_user_meta( $clone_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 0, $this->count_meta_rows( $clone_id, '_woocommerce_pos_uuid_2' ) );
	}

	/**
	 * Test two users sharing the SAME cloned legacy value converge
	 * deterministically: the lower user id promotes it, the higher user id is
	 * skipped as a collision.
	 */
	public function test_migration_resolves_shared_legacy_value_to_lower_user_id(): void {
		$user_a    = $this->factory()->user->create();
		$user_b    = $this->factory()->user->create();
		$low_id    = min( $user_a, $user_b );
		$high_id   = max( $user_a, $user_b );
		$dupe_uuid = wp_generate_uuid4();
		update_user_meta( $low_id, '_woocommerce_pos_uuid_2', $dupe_uuid );
		update_user_meta( $high_id, '_woocommerce_pos_uuid_2', $dupe_uuid );

		$this->run_migration();

		$this->assertSame( $dupe_uuid, get_user_meta( $low_id, '_woocommerce_pos_uuid', true ) );
		$this->assertSame( '', get_user_meta( $high_id, '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * Test keys with a non-numeric suffix are not ours and are left untouched.
	 */
	public function test_migration_ignores_non_numeric_suffix_keys(): void {
		$user_id = $this->factory()->user->create();
		update_user_meta( $user_id, '_woocommerce_pos_uuid_backup', wp_generate_uuid4() );

		$this->run_migration();

		$this->assertSame( '', get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 1, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid_backup' ) );
	}

	/**
	 * Test running the migration twice is safe (idempotent) — the second run
	 * finds no legacy rows and changes nothing.
	 */
	public function test_migration_is_idempotent(): void {
		$user_id     = $this->factory()->user->create();
		$legacy_uuid = wp_generate_uuid4();
		update_user_meta( $user_id, '_woocommerce_pos_uuid_2', $legacy_uuid );

		$this->run_migration();
		$this->run_migration();

		$this->assertSame( $legacy_uuid, get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
		$this->assertEquals( 1, $this->count_meta_rows( $user_id, '_woocommerce_pos_uuid' ) );
	}

	/**
	 * Test users without any legacy rows are untouched.
	 */
	public function test_migration_leaves_unrelated_users_alone(): void {
		$user_id = $this->factory()->user->create();

		$this->run_migration();

		$this->assertSame( '', get_user_meta( $user_id, '_woocommerce_pos_uuid', true ) );
	}
}
