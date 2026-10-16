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
		delete_option( Integrity_Controller::DRIFT_STREAK_OPTION );
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		$this->product_id = ProductHelper::create_simple_product()->get_id();
		( new Integrity_Digest() )->rebuild();
		$this->make_stale();
	}

	public function tearDown(): void {
		delete_option( Integrity_Controller::DRIFT_STREAK_OPTION );
		delete_transient( Integrity_Digest::REBUILD_LOCK );
		wp_clear_scheduled_hook( Integrity_Digest::REBUILD_HOOK );
		parent::tearDown();
	}

	private function make_stale( string $title = 'Hookless edit' ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $this->product_id ) );
		clean_post_cache( $this->product_id );
	}

	private function drill( string $instance = '', bool $stale = true ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/integrity/scan' );
		$request->set_query_params(
			array(
				'bucket_size' => 1,
				'bucket'      => $this->product_id,
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
		( new Integrity_Digest() )->upsert_digest( $this->product_id );
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
		$this->assertSame( array( 2 ), array_values( get_option( Integrity_Controller::DRIFT_STREAK_OPTION ) ) );
		$this->assertFalse( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) );
	}
}
