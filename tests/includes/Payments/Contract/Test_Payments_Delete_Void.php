<?php
/**
 * Live payment legs are cancelled when their order is permanently deleted.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;

/**
 * Runs under CPT storage; the HPOS subclass re-runs every case.
 */
class Test_Payments_Delete_Void extends \WP_UnitTestCase {
	/**
	 * Log lines captured through `wcpos_logging`.
	 *
	 * @var string[]
	 */
	private $logs = array();

	/**
	 * Register the test handler and the sweeper's hooks, and reset the statics.
	 */
	public function setUp(): void {
		parent::setUp();
		Capture_Mode_Registry::instance()->register( 'delete_void_test', Delete_Void_Test_Handler::class );
		Delete_Void_Test_Handler::$voids  = array();
		Delete_Void_Test_Handler::$answer = array( 'status' => 'voided' );
		Delete_Void_Test_Handler::$throw  = false;
		// No register_hooks() here: the suite's Init already ran Payments_Sweeper::register_hooks()
		// (post-latch phase), and a second instance would ask every leg to cancel twice.
		$this->logs = array();
		add_filter( 'wcpos_logging', array( $this, 'capture_log' ), 10, 2 );
	}

	/**
	 * Stop capturing log lines.
	 */
	public function tearDown(): void {
		remove_filter( 'wcpos_logging', array( $this, 'capture_log' ), 10 );
		parent::tearDown();
	}

	/**
	 * Record a log line instead of writing it.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $message The log line.
	 */
	public function capture_log( $enabled, $message ): bool {
		$this->logs[] = (string) $message;
		return false;
	}

	/** Under CPT both delete hooks fire for one id, so this also pins the once-per-order dedupe. */
	public function test_delete_order_permanently_requests_a_cancel_once_per_live_leg(): void {
		// Arrange.
		$pending    = $this->live_row( 'pending' );
		$authorized = $this->live_row( 'authorized' );
		$captured   = array(
			'id'           => wp_generate_uuid4(),
			'method_id'    => 'pos_cash',
			'capture_mode' => 'manual',
			'amount'       => '10.00',
			'status'       => 'captured',
		);
		$voided     = array_merge( $this->live_row( 'voided' ), array( 'provider_refs' => array() ) );
		$order_id   = $this->order_with_rows( array( $pending, $authorized, $captured, $voided ) );

		// Act.
		wc_get_order( $order_id )->delete( true );

		// Assert.
		$this->assertSame(
			array(
				array( $pending['id'], 'order_deleted', 'pending' ),
				array( $authorized['id'], 'order_deleted', 'authorized' ),
			),
			Delete_Void_Test_Handler::$voids
		);
		$this->assertFalse( wc_get_order( $order_id ) );
	}

	/** Trash is reversible, so the hold stays; the sweeper handles trashed orders. */
	public function test_trash_order_requests_no_cancel(): void {
		// Arrange.
		$row      = $this->live_row( 'authorized' );
		$order_id = $this->order_with_rows( array( $row ) );

		// Act.
		wc_get_order( $order_id )->delete( false );

		// Assert.
		$this->assertSame( array(), Delete_Void_Test_Handler::$voids );
		$this->assertSame( 'authorized', Ledger::instance()->find( wc_get_order( $order_id ), $row['id'] )['status'] );
	}

	/**
	 * Failed cancel answers.
	 */
	public static function failed_cancels(): array {
		return array(
			'wp_error'  => array( false, 'Refused by the provider' ),
			'throwable' => array( true, 'Provider exploded' ),
		);
	}

	/**
	 * A refused or throwing cancel is logged and never blocks the delete.
	 *
	 * @dataProvider failed_cancels
	 *
	 * @param bool   $throw   Whether the handler throws instead of answering.
	 * @param string $message The failure message.
	 */
	public function test_delete_order_with_a_failed_cancel_still_deletes_and_logs( bool $throw, string $message ): void {
		// Arrange.
		if ( $throw ) {
			Delete_Void_Test_Handler::$throw = true;
		} else {
			Delete_Void_Test_Handler::$answer = new \WP_Error( 'wcpos_provider_error', $message );
		}
		$row      = $this->live_row( 'authorized' );
		$order_id = $this->order_with_rows( array( $row ) );

		// Act.
		wc_get_order( $order_id )->delete( true );

		// Assert.
		$this->assertFalse( wc_get_order( $order_id ) );
		$expected = sprintf( 'WCPOS payment %s: cancel before order #%d was deleted failed: %s', $row['id'], $order_id, $message );
		$this->assertCount( 1, $this->logs_containing( $expected ) );
	}

	/** A cancel already in flight is not requested again. */
	public function test_delete_order_skips_a_leg_whose_cancel_is_already_requested(): void {
		// Arrange.
		$row      = array_merge( $this->live_row( 'authorized' ), array( 'void_requested_at' => gmdate( 'c', time() - 30 ) ) );
		$order_id = $this->order_with_rows( array( $row ) );

		// Act.
		wc_get_order( $order_id )->delete( true );

		// Assert.
		$this->assertSame( array(), Delete_Void_Test_Handler::$voids );
		$this->assertFalse( wc_get_order( $order_id ) );
	}

	/** A direct post delete fires only before_delete_post. */
	public function test_wp_delete_post_on_an_order_requests_a_cancel(): void {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'A shop_order post is the order only under CPT storage.' );
		}
		// Arrange.
		$row      = $this->live_row( 'authorized' );
		$order_id = $this->order_with_rows( array( $row ) );

		// Act.
		wp_delete_post( $order_id, true );

		// Assert.
		$this->assertSame( array( array( $row['id'], 'order_deleted', 'authorized' ) ), Delete_Void_Test_Handler::$voids );
		$this->assertCount( 1, $this->logs_containing( sprintf( 'WCPOS payment %s: cancel requested before order #%d was deleted', $row['id'], $order_id ) ) );
	}

	/** Under HPOS the posts row is a placeholder or backup, so deleting it leaves the order's legs alone. */
	public function test_deleting_the_posts_copy_under_hpos_requests_no_cancel(): void {
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'Only under HPOS is the posts row a copy of the order.' );
		}
		// Arrange.
		$row      = $this->live_row( 'authorized' );
		$order_id = $this->order_with_rows( array( $row ) );

		// Act.
		wp_delete_post( $order_id, true );

		// Assert.
		$this->assertSame( array(), Delete_Void_Test_Handler::$voids );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'authorized', Ledger::instance()->find( $order, $row['id'] )['status'] );
	}

	/** The post-delete hook fires for every post type; a non-order returns before the ledger. */
	public function test_deleting_a_product_reads_no_ledger(): void {
		// Arrange.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Delete void product' );
		$product->save();

		// Act.
		$deleted = wp_delete_post( $product->get_id(), true );

		// Assert.
		$this->assertInstanceOf( \WP_Post::class, $deleted );
		$this->assertSame( array(), Delete_Void_Test_Handler::$voids );
	}

	/**
	 * A provider leg on the test handler.
	 *
	 * @param string $status Row status.
	 */
	private function live_row( string $status ): array {
		return array(
			'id'            => wp_generate_uuid4(),
			'method_id'     => 'pos_card',
			'capture_mode'  => 'delete_void_test',
			'amount'        => '20.00',
			'status'        => $status,
			'provider_refs' => array( 'action' => 'existing-action' ),
		);
	}

	/**
	 * An open POS order carrying these rows.
	 *
	 * @param array $rows Payment rows.
	 * @return int Order id.
	 */
	private function order_with_rows( array $rows ): int {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save(); // The ledger save reads core totals from storage.
		Ledger_Seed::rows( $order, $rows );
		return $order->get_id();
	}

	/**
	 * Captured log lines that contain a fragment.
	 *
	 * @param string $fragment Text to look for.
	 */
	private function logs_containing( string $fragment ): array {
		return array_values( array_filter( $this->logs, static fn( $line ) => false !== strpos( $line, $fragment ) ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A fixture stays with its tests, as Test_Payments_Sweeper keeps its handler.

/**
 * Records cancel requests and answers as the test sets.
 */
class Delete_Void_Test_Handler extends Manual_Handler {
	/**
	 * Recorded void calls: row id, reason, row status.
	 *
	 * @var array
	 */
	public static $voids = array();

	/**
	 * What void() answers.
	 *
	 * @var array|\WP_Error
	 */
	public static $answer = array( 'status' => 'voided' );

	/**
	 * Whether void() throws.
	 *
	 * @var bool
	 */
	public static $throw = false;

	/**
	 * Record the cancel request.
	 *
	 * @param array  $row    Payment row.
	 * @param string $reason Why the cancel was requested.
	 * @return array|\WP_Error
	 * @throws \RuntimeException When the test sets $throw.
	 */
	public function void( array $row, string $reason ) {
		self::$voids[] = array( $row['id'], $reason, $row['status'] );
		if ( self::$throw ) {
			throw new \RuntimeException( 'Provider exploded' );
		}
		return self::$answer;
	}
}
