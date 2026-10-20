<?php
/**
 * Per-client integrity drift streak tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact contract-focused documentation.

class Test_Integrity_Drift_Streak extends Sync_REST_Store_Test_Case {
	private int $product_id;

	public function setUp(): void {
		parent::setUp();
		foreach ( array_keys( $this->streaks() ) as $option ) {
			delete_option( $option );
		}
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		$this->product_id = ProductHelper::create_simple_product()->get_id();
		( new Integrity_Digest() )->rebuild();
		$this->make_stale();
	}

	public function tearDown(): void {
		foreach ( array_keys( $this->streaks() ) as $option ) {
			delete_option( $option );
		}
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		parent::tearDown();
	}

	private function streaks(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( 'wcpos_drift_' ) . '%' ), ARRAY_A );
		return array_map( 'intval', array_column( $rows, 'option_value', 'option_name' ) );
	}

	private function streak_option( string $instance, int $bucket ): string {
		return 'wcpos_drift_' . sha1( 'instance:' . $instance ) . '_' . sha1( 'instance:' . $instance . ':1:' . $bucket );
	}

	public function test_drift_interleaved_clients_preserve_both_increments(): void {
		// Pause A immediately before its write; B writes without A re-reading state.
		$interleave = function ( $sql ) use ( &$interleave ) {
			if ( preg_match( '/^(INSERT|UPDATE)/i', $sql ) && ( false !== strpos( $sql, 'wcpos_drift_' ) || false !== strpos( $sql, Integrity_Controller::DRIFT_STREAK_OPTION ) ) ) {
				remove_filter( 'query', $interleave );
				$this->drill( 'till-B' );
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->drill( 'till-A' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertSame( array( 1, 1 ), array_values( $this->streaks() ) );
		$this->drill( 'till-B' );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->drill( 'till-B' );
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->assertSame( array(), $this->streaks() );
	}

	private function make_stale( string $title = 'Hookless edit' ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $this->product_id ) );
		clean_post_cache( $this->product_id );
	}

	private function drill( string $instance = '', bool $stale = true, ?int $store_id = null ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'bucket_size' => 1,
				'bucket'      => $this->product_id,
				'store_id'    => $store_id,
			)
		);
		if ( '' !== $instance ) {
			$request->set_header( 'X-WCPOS-Instance', $instance );
		}
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $stale ? array( 'changed' ) : array(), array_column( $response->get_data()['changes'], 'status' ) );
	}

	public function test_drift_three_instances_do_not_schedule_rebuild(): void {
		foreach ( array( 'till-A', 'till-B', 'till-C' ) as $instance ) {
			$this->drill( $instance );
		}
		// Before the fix, the third independent client incorrectly queues a rebuild.
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_one_instance_at_threshold_schedules_rebuild(): void {
		for ( $i = 1; $i <= Integrity_Controller::DRIFT_REBUILD_THRESHOLD; ++$i ) {
			$this->drill( 'till-A' );
			if ( $i < Integrity_Controller::DRIFT_REBUILD_THRESHOLD ) {
				$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
			}
		}
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_reconciled_instance_preserves_other_streaks(): void {
		foreach ( array( 'till-A', 'till-B', 'till-C' ) as $instance ) {
			$this->drill( $instance );
		}
		( new Integrity_Digest() )->record_post_saved( $this->product_id );
		Integrity_Digest::flush_pending_digests();
		$this->drill( 'till-A', false );
		$this->make_stale( 'Another hookless edit' );
		$this->drill( 'till-A' );
		$this->drill( 'till-B' );
		$this->drill( 'till-A' );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->drill( 'till-B' );
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_without_instance_different_users_do_not_share_streak(): void {
		$this->drill();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->drill();
		wp_set_current_user( $this->user );
		$this->drill();
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->drill();
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_same_instance_requests_share_streak(): void {
		$this->drill( 'till-A' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->drill( 'till-A' );
		$this->assertSame( array( 2 ), array_values( $this->streaks() ) );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	private function stale_products( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$this->product_id = ProductHelper::create_simple_product()->get_id();
			( new Integrity_Digest() )->record_post_saved( $this->product_id );
			Integrity_Digest::flush_pending_digests();
			$this->make_stale();
			$ids[] = $this->product_id;
		}
		return $ids;
	}

	public function test_drift_ten_clients_three_sweeps_schedule_rebuild(): void {
		$ids = $this->stale_products( 26 );
		for ( $sweep = 0; $sweep < 3; ++$sweep ) {
			for ( $client = 0; $client < 10; ++$client ) {
				foreach ( $ids as $id ) {
					$this->product_id = $id;
					$this->drill( 'till-' . $client );
				}
			}
			if ( 2 > $sweep ) {
				$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
			}
		}
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_client_cap_preserves_progress_over_three_sweeps(): void {
		$this->drill( 'other' );
		$expected = $this->streaks();
		$ids      = $this->stale_products( 70 );
		foreach ( $ids as $id ) {
			$this->product_id = $id;
			$this->drill( 'noisy' );
		}
		foreach ( array_slice( $ids, 0, 64 ) as $id ) {
			$expected[ $this->streak_option( 'noisy', $id ) ] = 1;
		}
		ksort( $expected );
		$this->assertSame( $expected, $this->streaks() );
		for ( $sweep = 1; $sweep < 3; ++$sweep ) {
			$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
			foreach ( $ids as $id ) {
				$this->product_id = $id;
				$this->drill( 'noisy' );
			}
		}
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_without_headers_different_query_stores_do_not_share_streak(): void {
		$this->drill( '', true, 7 );
		$this->drill( '', true, 9 );
		$this->assertSame( array( 1, 1 ), array_values( $this->streaks() ) );
		$this->drill( '', true, 7 );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->drill( '', true, 7 );
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public function test_drift_global_cap_refuses_new_keys_but_existing_progress_rebuilds(): void {
		$streaks = array_fill_keys( array_map( fn ( $client ) => $this->streak_option( 'till-' . $client, $this->product_id ), range( 0, 1023 ) ), 1 );
		foreach ( $streaks as $option => $count ) {
			add_option( $option, $count . '|' . time(), '', false );
		}
		ksort( $streaks );
		$this->drill( 'new-client' );
		$this->assertSame( $streaks, $this->streaks() );
		$this->drill( 'till-0' );
		$this->assertSame( 2, $this->streaks()[ $this->streak_option( 'till-0', $this->product_id ) ] );
		$this->assertCount( 1024, $this->streaks() );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
		$this->drill( 'till-0' );
		$this->assertNotFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}

	public static function cap_scopes(): array {
		return array(
			'client' => array( false, 64 ),
			'global' => array( true, 1024 ),
		);
	}

	/** @dataProvider cap_scopes */
	public function test_drift_expired_counters_at_cap_admit_new_bucket( bool $global, int $cap ): void {
		for ( $i = 0; $i < $cap; ++$i ) {
			add_option( $this->streak_option( $global ? 'old-' . $i : 'till-A', -$i ), '1|' . ( time() - DAY_IN_SECONDS - 60 ), '', false );
		}
		$this->drill( 'till-A' );
		$this->assertSame( array( $this->streak_option( 'till-A', $this->product_id ) => 1 ), $this->streaks() );
	}

	/** @dataProvider cap_scopes */
	public function test_drift_near_cap_interleaved_admissions_admit_only_one( bool $global, int $cap ): void {
		for ( $i = 0; $i < $cap - 1; ++$i ) {
			add_option( $this->streak_option( $global ? 'old-' . $i : 'till-A', -$i ), '1|' . time(), '', false );
		}
		$ids = $this->stale_products( 2 );
		// B inserts after A's last read but before A's admission statement executes.
		$interleave = function ( $sql ) use ( &$interleave, $ids ) {
			if ( 0 === strpos( $sql, 'INSERT' ) && false !== strpos( $sql, 'wcpos_drift_' ) ) {
				remove_filter( 'query', $interleave );
				$this->product_id = $ids[0];
				$this->drill( 'till-A' );
				$this->product_id = $ids[1];
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->drill( 'till-A' );
		} finally {
			remove_filter( 'query', $interleave );
		}
		$this->assertCount( $cap, $this->streaks() );
		$this->assertArrayHasKey( $this->streak_option( 'till-A', $ids[0] ), $this->streaks() );
		$this->assertArrayNotHasKey( $this->streak_option( 'till-A', $ids[1] ), $this->streaks() );
	}
}
