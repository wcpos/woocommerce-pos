<?php
/**
 * Receipt snapshot retirement tests on posts storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Cleanup;
use WCPOS\WooCommercePOS\Services\Service_Groups;
use WC_Unit_Test_Case;

/**
 * Covers removal without saving orders or deleting unrelated data.
 *
 * @internal
 * @coversNothing
 */
class Test_Receipt_Snapshot_Cleanup extends WC_Unit_Test_Case {
	/**
	 * Provision both tables outside the per-test transaction for compat-copy fixtures.
	 *
	 * @param mixed $factory WP test factory (unused).
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test lifecycle.
		OrderHelper::create_order_custom_table_if_not_exist();
	}

	/** Reset cleanup state and queued work. */
	public function setUp(): void {
		parent::setUp();
		delete_option( Receipt_Snapshot_Cleanup::OPTION );
		delete_option( 'wcpos_receipt_sequence_counter' );
		$this->clear_schedule();
	}

	/** Restore scheduler and service construction state. */
	public function tearDown(): void {
		$this->clear_schedule();
		Service_Groups::reset();
		parent::tearDown();
	}

	/** Cancel this test's work only. */
	private function clear_schedule(): void {
		wp_clear_scheduled_hook( Receipt_Snapshot_Cleanup::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Receipt_Snapshot_Cleanup::HOOK, array(), 'wcpos' );
		}
	}

	/** Payment hooks must not write snapshot data on either sales channel. */
	public function test_payment_complete_writes_no_snapshot_meta_for_pos_and_online_orders(): void {
		foreach ( array( 'woocommerce-pos', 'checkout' ) as $created_via ) {
			// Arrange.
			$order = OrderHelper::create_order();
			$order->set_created_via( $created_via );
			$order->save();
			// Act.
			$order->payment_complete();
			// Assert: normal WooCommerce status notes are not snapshot notes.
			$order = wc_get_order( $order->get_id() );
			foreach ( Receipt_Snapshot_Cleanup::META_KEYS as $key ) {
				$this->assertSame( '', $order->get_meta( $key, true ), $key );
			}
			foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
				$this->assertStringNotContainsString( 'POS fiscal receipt snapshot created', $note->content );
			}
			$this->assertFalse( get_option( 'wcpos_receipt_sequence_counter' ) );
		}
	}

	/** The late storefront order-group trigger must not restore the writer. */
	public function test_payment_complete_from_storefront_lane_writes_no_snapshot_meta(): void {
		// Arrange.
		Service_Groups::reset();
		Service_Groups::arm_order_group();
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		update_option( 'wcpos_receipt_sequence_counter', 42 );
		// Act.
		$order->payment_complete();
		// Assert.
		foreach ( Receipt_Snapshot_Cleanup::META_KEYS as $key ) {
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( $key, true ) );
		}
		$this->assertSame( 42, get_option( 'wcpos_receipt_sequence_counter' ) );
	}

	/** Every retired key is removed and an empty follow-up completes the job. */
	public function test_run_deletes_snapshot_meta_rows_and_marks_done(): void {
		// Arrange.
		$ids = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$order = OrderHelper::create_order();
			foreach ( Receipt_Snapshot_Cleanup::META_KEYS as $key ) {
				$order->update_meta_data( $key, 'snapshot' );
			}
			$order->save();
			$ids[] = $order->get_id();
		}
		// Act.
		$this->assertSame( 12, Receipt_Snapshot_Cleanup::run() );
		$this->assertSame( 0, Receipt_Snapshot_Cleanup::run() );
		// Assert.
		foreach ( $ids as $id ) {
			foreach ( Receipt_Snapshot_Cleanup::META_KEYS as $key ) {
				$this->assertSame( '', wc_get_order( $id )->get_meta( $key, true ), $key );
			}
		}
		$this->assertSame( 'done', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
		$this->assertSame( 0, Receipt_Snapshot_Cleanup::run() );
	}

	/** Direct SQL deletion must invalidate already populated order caches. */
	public function test_run_clears_warm_order_caches(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$id = $order->get_id();
		$key = Receipt_Snapshot_Cleanup::META_KEYS[0];
		$order->update_meta_data( $key, 'snapshot' );
		$order->save_meta_data();
		$this->assertSame( 'snapshot', wc_get_order( $id )->get_meta( $key, true ) );
		// Act.
		Receipt_Snapshot_Cleanup::run();
		// Assert.
		$this->assertSame( '', wc_get_order( $id )->get_meta( $key, true ) );
	}

	/** Only an empty SELECT, not a successful deletion, confirms completion. */
	public function test_run_finishes_only_when_nothing_is_found(): void {
		// Arrange / Act / Assert: no rows.
		$this->assertSame( 0, Receipt_Snapshot_Cleanup::run() );
		$this->assertSame( 'done', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
		// Arrange: restart with one candidate.
		delete_option( Receipt_Snapshot_Cleanup::OPTION );
		$order = OrderHelper::create_order();
		$order->update_meta_data( Receipt_Snapshot_Cleanup::META_KEYS[0], 'snapshot' );
		$order->save_meta_data();
		// Act / Assert.
		$this->assertSame( 1, Receipt_Snapshot_Cleanup::run( 1 ) );
		$this->assertSame( 'pending', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
	}

	/** Failed queries leave recovery to admin_init, not a perpetual retry. */
	public function test_failed_run_does_not_reschedule_itself(): void {
		global $wpdb;
		// Arrange: emulate a running job with no pending successor.
		Receipt_Snapshot_Cleanup::arm();
		$this->clear_schedule();
		$fail_query = static function ( $sql ) {
			return false !== strpos( $sql, 'comment_type' ) ? 'SELECT * FROM nonexistent_table_for_test' : $sql;
		};
		$suppress_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_query );
		try {
			// Act.
			Receipt_Snapshot_Cleanup::run();
		} finally {
			remove_filter( 'query', $fail_query );
			$wpdb->suppress_errors( $suppress_errors );
		}
		// Assert.
		$this->assertSame( 'pending', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
		$this->assertSame( 0, $this->scheduled_count() );
	}

	/** A note deleted before cleanup is harmless. */
	public function test_already_deleted_note_is_skipped(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$id = $order->add_order_note( sprintf( Receipt_Snapshot_Cleanup::NOTE_FORMAT, 5 ) );
		wp_delete_comment( $id, true );
		// Act / Assert.
		$this->assertSame( 0, Receipt_Snapshot_Cleanup::run() );
		$this->assertSame( 0, Receipt_Snapshot_Cleanup::run() );
		$this->assertSame( 'done', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
	}

	/** The admin self-heal initializes missing cleanup state. */
	public function test_admin_init_arms_pending_cleanup(): void {
		// Arrange.
		delete_option( Receipt_Snapshot_Cleanup::OPTION );
		// Act.
		if ( false !== has_action( 'admin_init', array( Receipt_Snapshot_Cleanup::class, 'arm' ) ) ) {
			do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the core admin lifecycle hook.
		} else {
			// Non-admin test bootstrap does not register the Activator's admin hook.
			Receipt_Snapshot_Cleanup::arm();
		}
		// Assert.
		$this->assertSame( 'pending', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
	}

	/** Compat-sync copies must be removed even when that table is not authoritative. */
	public function test_run_deletes_rows_from_both_meta_tables(): void {
		global $wpdb;
		// Arrange: seed both tables explicitly, regardless of storage mode.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_wcpos_receipt_snapshot', 'snapshot' );
		$order->save();
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id' => $order->get_id(),
				'meta_key' => '_wcpos_receipt_snapshot',
				'meta_value' => 'copy',
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'wc_orders_meta',
			array(
				'order_id' => $order->get_id(),
				'meta_key' => '_wcpos_receipt_snapshot',
				'meta_value' => 'copy',
			)
		);
		// Act.
		Receipt_Snapshot_Cleanup::run();
		// Assert.
		foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta' ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names from the fixture above.
			$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE meta_key = '_wcpos_receipt_snapshot'" ) );
		}
	}

	/** Snapshot notes are removed through the comment API, not order saves. */
	public function test_run_deletes_snapshot_order_notes_and_keeps_other_notes(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$snapshot = $order->add_order_note( sprintf( Receipt_Snapshot_Cleanup::NOTE_FORMAT, 5 ) );
		$other = $order->add_order_note( 'Customer called' );
		// Act.
		Receipt_Snapshot_Cleanup::run();
		// Assert.
		$this->assertNull( get_comment( $snapshot ) );
		$this->assertSame( 'Customer called', get_comment( $other )->comment_content );
	}

	/** Notes created in the site language must also be retired. */
	public function test_run_deletes_translated_snapshot_notes(): void {
		// Arrange.
		$translate = static function ( $translation, $text ) {
			return Receipt_Snapshot_Cleanup::NOTE_FORMAT === $text ? 'Instantané fiscal créé (reçu n°%d).' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 2 );
		try {
			$order = OrderHelper::create_order();
			$id = $order->add_order_note( sprintf( 'Instantané fiscal créé (reçu n°%d).', 5 ) );
			// Act.
			Receipt_Snapshot_Cleanup::run();
			// Assert.
			$this->assertNull( get_comment( $id ) );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}
	}

	/** A bounded run must leave work pending until an empty run confirms completion. */
	public function test_run_is_resumable_in_batches(): void {
		// Arrange.
		for ( $i = 0; $i < 3; ++$i ) {
			$order = OrderHelper::create_order();
			$order->update_meta_data( '_wcpos_receipt_snapshot', 'snapshot' );
			$order->save();
		}
		// Act / Assert.
		for ( $i = 0; $i < 3; ++$i ) {
			$this->assertSame( 1, Receipt_Snapshot_Cleanup::run( 1 ) );
			$this->assertSame( 'pending', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
		}
		$this->assertSame( 0, Receipt_Snapshot_Cleanup::run( 1 ) );
		$this->assertSame( 'done', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
	}

	/** Deleting raw metadata must not modify the order or emit save hooks. */
	public function test_run_does_not_change_order_date_modified(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_wcpos_receipt_snapshot', 'snapshot' );
		$order->set_date_modified( '2020-01-01 00:00:00' );
		$order->save();
		$before = wc_get_order( $order->get_id() )->get_date_modified()->getTimestamp();
		$saves = 0;
		$observer = static function () use ( &$saves ) {
			++$saves;
		};
		add_action( 'woocommerce_before_order_object_save', $observer );
		// Act.
		try {
			Receipt_Snapshot_Cleanup::run();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $observer );
		}
		// Assert.
		$this->assertSame( $before, wc_get_order( $order->get_id() )->get_date_modified()->getTimestamp() );
		$this->assertSame( 0, $saves );
	}

	/** Similar prefixes and unrelated WCPOS data must never be deleted. */
	public function test_run_leaves_unrelated_meta_and_notes(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		foreach ( array(
			'_wcpos_receipt_print_count' => '2',
			'_pos_user' => '123',
			'_wcpos_receipt_snapshot_extra' => 'keep',
		) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		$notes = array();
		foreach ( array( 'Customer called', 'POS fiscal receipt snapshot created (receipt #5). Keep this', 'POS fiscal receipt snapshot created (receipt #oops).', 'pos fiscal receipt snapshot created (receipt #5).', "POS fiscal receipt snapshot created (receipt #5).\n" ) as $text ) {
			$notes[] = $order->add_order_note( $text );
		}
		$comment = wp_insert_comment(
			array(
				'comment_post_ID' => $order->get_id(),
				'comment_content' => sprintf( Receipt_Snapshot_Cleanup::NOTE_FORMAT, 5 ),
				'comment_type' => 'comment',
			)
		);
		// Act.
		Receipt_Snapshot_Cleanup::run();
		// Assert.
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '2', $order->get_meta( '_wcpos_receipt_print_count' ) );
		$this->assertSame( '123', $order->get_meta( '_pos_user' ) );
		$this->assertSame( 'keep', $order->get_meta( '_wcpos_receipt_snapshot_extra' ) );
		foreach ( array_merge( $notes, array( $comment ) ) as $id ) {
			$this->assertNotNull( get_comment( $id ) );
		}
	}

	/** The counter survives nonempty batches and is deleted only at completion. */
	public function test_run_deletes_sequence_counter_option_when_finished(): void {
		// Arrange.
		update_option( 'wcpos_receipt_sequence_counter', 42 );
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_wcpos_receipt_snapshot', 'snapshot' );
		$order->save();
		// Act / Assert.
		Receipt_Snapshot_Cleanup::run();
		$this->assertSame( 42, get_option( 'wcpos_receipt_sequence_counter' ) );
		Receipt_Snapshot_Cleanup::run();
		$this->assertFalse( get_option( 'wcpos_receipt_sequence_counter' ) );
	}

	/** Missing work is re-armed, but pending work is not duplicated. */
	public function test_arm_is_idempotent_and_reschedules_pending_without_duplicates(): void {
		// Act.
		Receipt_Snapshot_Cleanup::arm();
		Receipt_Snapshot_Cleanup::arm();
		// Assert.
		$this->assertSame( 'pending', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
		$this->assertSame( 1, $this->scheduled_count() );
		$this->clear_schedule();
		Receipt_Snapshot_Cleanup::arm();
		$this->assertSame( 1, $this->scheduled_count() );
		update_option( Receipt_Snapshot_Cleanup::OPTION, 'done' );
		Receipt_Snapshot_Cleanup::arm();
		$this->assertSame( 1, $this->scheduled_count() );
		$this->assertSame( 'done', get_option( Receipt_Snapshot_Cleanup::OPTION ) );
	}

	/** Count only pending work for this cleanup. */
	private function scheduled_count(): int {
		$count = 0;
		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			$count += count(
				as_get_scheduled_actions(
					array(
						'hook' => Receipt_Snapshot_Cleanup::HOOK,
						'group' => 'wcpos',
						'status' => 'pending',
						'per_page' => -1,
					),
					'ids'
				)
			);
		}
		foreach ( _get_cron_array() as $hooks ) {
			$count += isset( $hooks[ Receipt_Snapshot_Cleanup::HOOK ] ) ? count( $hooks[ Receipt_Snapshot_Cleanup::HOOK ] ) : 0;
		}
		return $count;
	}
}
