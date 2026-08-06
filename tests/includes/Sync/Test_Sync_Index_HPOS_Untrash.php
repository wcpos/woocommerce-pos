<?php
/**
 * HPOS coverage for the sync-index order trash/untrash lifecycle.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Sync_Index;

/**
 * A restored COT order must be re-emitted to the sync index as PRESENT.
 *
 * `untrashed_post` never fires for COT orders, and the restore's own
 * `$order->save()` does NOT fire `woocommerce_update_order` either — the HPOS
 * data store returns early whenever `trash` sits on either side of the status
 * change. Without a COT-specific hook a client that tombstoned the order on
 * trash never learns it came back.
 *
 * The lifecycle is driven through the real WooCommerce APIs on purpose: the
 * bug is invisible to any test that calls the recorder methods directly.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Sync_Index_HPOS_Untrash extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Sync index under observation.
	 *
	 * @var Sync_Index
	 */
	private $sync_index;

	/**
	 * Enable the sync surface and COT storage; wire the sync-index observer.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );

		$this->sync_index = new Sync_Index();
		$this->sync_index->register_hooks();
	}

	/**
	 * Restore posts storage and unhook the observer.
	 */
	public function tearDown(): void {
		remove_action( 'woocommerce_new_order', array( $this->sync_index, 'record_order_created' ), 10 );
		remove_action( 'woocommerce_update_order', array( $this->sync_index, 'record_order_updated' ), 10 );
		remove_action( 'wp_trash_post', array( $this->sync_index, 'record_post_deleted' ), 10 );
		remove_action( 'before_delete_post', array( $this->sync_index, 'record_post_deleted' ), 10 );
		remove_action( 'woocommerce_before_trash_order', array( $this->sync_index, 'record_order_deleted' ), 10 );
		remove_action( 'woocommerce_before_delete_order', array( $this->sync_index, 'record_order_deleted' ), 10 );
		remove_action( 'untrashed_post', array( $this->sync_index, 'record_post_untrashed' ), 10 );
		remove_action( 'woocommerce_untrash_order', array( $this->sync_index, 'record_cot_order_untrashed' ), 10 );

		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Trash tombstones the COT order in the index; untrash re-emits it as present.
	 */
	public function test_cot_order_untrash_reemits_the_order_as_present(): void {
		// Arrange: a live COT order, tombstoned by a real trash.
		$order    = wc_create_order();
		$order_id = $order->get_id();

		$this->assertEquals( 'shop_order_placehold', get_post_type( $order_id ), 'This class must run under COT storage.' );
		$this->assertEquals( 0, $this->latest_index_row( $order_id )['deleted'], 'Creating a COT order must index it as present.' );

		$order->delete( false );
		$this->assertEquals( 1, $this->latest_index_row( $order_id )['deleted'], 'Trashing must tombstone the order in the index.' );

		// Bypass the order cache so the reloaded instance carries trash status.
		wp_cache_flush();
		$trashed = wc_get_order( $order_id );
		$this->assertEquals( 'trash', $trashed->get_status(), 'The reloaded order must be in the trash.' );
		$head_before_untrash = $this->sync_index->head_sequence();

		// Act: restore it exactly the way WooCommerce does.
		$trashed->untrash();

		// Assert: the client's pull sees a fresh PRESENT row past its cursor.
		$this->assertGreaterThan( 0, did_action( 'woocommerce_untrash_order' ), 'The HPOS untrash hook must fire.' );

		$appended = $this->index_rows_after( $head_before_untrash, $order_id );
		$this->assertNotEmpty( $appended, 'Untrash must append a sync-index row so tombstoned clients re-pull the order.' );

		$latest = $appended[ count( $appended ) - 1 ];
		$this->assertEquals( 0, $latest['deleted'], 'The restored order must be re-emitted as PRESENT.' );
		$this->assertEquals( 'hook:untrash', $latest['origin'], 'The restore must be recorded with the untrash origin.' );
		$this->assertStringStartsWith( 'sha256:', $latest['revision'], 'The re-emitted row must carry a live content revision, not the deleted marker.' );
	}

	/**
	 * The most recent index row for an order.
	 *
	 * @param int $order_id Order to read.
	 *
	 * @return array<string, mixed> Latest index row.
	 */
	private function latest_index_row( int $order_id ): array {
		$rows = $this->index_rows_after( 0, $order_id );
		$this->assertNotEmpty( $rows, 'Expected at least one sync-index row for order ' . $order_id . '.' );

		return $rows[ count( $rows ) - 1 ];
	}

	/**
	 * Index rows for one order appended past a sequence cursor.
	 *
	 * Read through the client-facing `rows_after_sequence` surface so the pin
	 * exercises the same page a pulling client would receive.
	 *
	 * @param int $sequence Exclusive sequence cursor.
	 * @param int $order_id Order to filter to.
	 *
	 * @return array<int, array<string, mixed>> Matching rows, oldest first.
	 */
	private function index_rows_after( int $sequence, int $order_id ): array {
		return array_values(
			array_filter(
				$this->sync_index->rows_after_sequence( $sequence, 250 ),
				static function ( array $row ) use ( $order_id ): bool {
					return $order_id === $row['order_id'];
				}
			)
		);
	}
}
