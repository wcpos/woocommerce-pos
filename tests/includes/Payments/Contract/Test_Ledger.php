<?php
/**
 * Payment ledger tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

require_once __DIR__ . '/ledger-clock.php';

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WC_Tax;

/** Payment ledger tests. */
class Test_Ledger extends WCPOS_REST_Unit_Test_Case {
	use \Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;


	public function setUp(): void {
		parent::setUp();
		Capture_Mode_Registry::instance()->register( 'integrity', Integrity_Handler::class );
		Integrity_Handler::$calls = 0;
		Integrity_Handler::$tips = 'none';
		add_filter(
			'wcpos_payment_method_capture_mode',
			static function ( $mode, $gateway ) {
				return 'pos_card' === $gateway->id ? 'integrity' : $mode;
			},
			10,
			2
		);
		add_filter(
			'wcpos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['pos_card']['enabled'] = true;
				return $settings;
			}
		);
	}

	/** A full till row cannot override server-owned or handler-owned facts. */
	public function test_record_drops_server_owned_and_unknown_keys(): void {
		$order = $this->create_pos_order();
		$input = $this->payment(
			'pos_cash',
			'92.95',
			array(
				'source'            => 'forged',
				'order_id'          => $order->get_id(),
				'provider'          => null,
				'kind'              => 'cash',
				'capture_mode'      => 'manual',
				'transport'         => 'offline',
				'recorded_offline'  => true,
				'currency'          => $order->get_currency(),
				'tendered'          => '100.00',
				'change'            => '99.00',
				'tip'               => null,
				'status'            => 'captured',
				'failure_reason'    => null,
				'refunded_amount'   => '50.00',
				'refunds'           => array( array( 'x' ) ),
				'provider_refs'     => array(),
				'receipt'           => array(),
				'cashier_id'        => 999,
				'store_id'          => 999,
				'register_id'       => wp_generate_uuid4(),
				'session_id'        => wp_generate_uuid4(),
				'created_at_gmt'    => '2026-10-13T08:00:00Z',
				'captured_at_gmt'   => '2026-10-13T08:00:05Z',
				'updated_at_gmt'    => '2000-01-01T00:00:00Z',
				'seen_events'       => array( 'evt_1' ),
				'authorized_at_gmt' => '2026-10-13T08:00:00Z',
				'expires_at'        => '2030-01-01T00:00:00Z',
			)
		);

		$row = Ledger::instance()->record( $order, $input );

		$this->assertIsArray( $row );
		$this->assertSame( 'app', $row['source'] );
		$this->assertSame( 'cash', $row['kind'] );
		$this->assertSame( '7.05', $row['change'] );
		$this->assertSame( '0.00', $row['refunded_amount'] );
		$this->assertSame( array(), $row['refunds'] );
		$this->assertSame( get_current_user_id(), $row['cashier_id'] );
		$this->assertSame( null, $row['store_id'] );
		$this->assertSame( array(), $row['seen_events'] );
		$this->assertSame( null, $row['authorized_at_gmt'] );
		$this->assertSame( null, $row['expires_at'] );
		$this->assertNotSame( '2000', substr( $row['updated_at_gmt'], 0, 4 ) );
	}

	/** A client cannot claim that a closure counted a payment. */
	public function test_record_drops_a_client_closure_id(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '5.00', array( 'closure_id' => wp_generate_uuid4() ) );
		Ledger::instance()->record( $order, $input );
		$this->assertArrayNotHasKey( 'closure_id', Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] ) );
	}

	/** Stamping changes only the named row's closure id, including on replay. */
	public function test_stamp_closure_stamps_only_the_named_legs_and_keeps_updated_at(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$before = $ledger->read( $order );
		foreach ( $before as &$row ) {
			$row['updated_at_gmt'] = '2020-01-01T00:00:00+00:00';
		}
		unset( $row );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => $before,
				)
			)
		);
		$order->save_meta_data(); // Seed server-owned stamps directly; an order save keeps the stored ones.
		$closure_id = wp_generate_uuid4();
		for ( $i = 0; $i < 2; ++$i ) {
			$ledger->stamp_closure( $order, array( $before[0]['id'] ), $closure_id );
			$after = $ledger->read( wc_get_order( $order->get_id() ) );
			$this->assertSame( $closure_id, $after[0]['closure_id'] );
			$this->assertArrayNotHasKey( 'closure_id', $after[1] );
			unset( $after[0]['closure_id'] );
			$this->assertSame( $before, $after );
		}
	}

	/** A locking storage read must not erase a leg appended to another object. */
	public function test_stamp_closure_never_writes_back_a_stale_order_object(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$first = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$stale = wc_get_order( $order->get_id() );
		$stale->get_meta( Ledger::META_KEY, true );
		$second = $ledger->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '5.00' ) );
		$ledger->stamp_closure( $stale, array( $first['id'] ), wp_generate_uuid4() );
		$this->assertSame( array( $first['id'], $second['id'] ), array_column( $ledger->read( wc_get_order( $order->get_id() ) ), 'id' ) );
	}

	/** Missing ids and a different closure cannot rewrite the fiscal fact. */
	public function test_stamp_closure_refuses_a_missing_leg_or_another_closures_leg(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$row = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$closure_id = wp_generate_uuid4();
		try {
			$ledger->stamp_closure( $order, array( wp_generate_uuid4() ), $closure_id );
			$this->fail( 'Expected a missing-leg refusal.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Closure stamp: leg missing.', $error->getMessage() );
		}
		$ledger->stamp_closure( $order, array( $row['id'] ), $closure_id );
		try {
			$ledger->stamp_closure( $order, array( $row['id'] ), wp_generate_uuid4() );
			$this->fail( 'Expected a different-closure refusal.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Closure stamp: leg belongs to another closure.', $error->getMessage() );
		}
		$this->assertSame( $closure_id, $ledger->find( wc_get_order( $order->get_id() ), $row['id'] )['closure_id'] );
	}

	/** Typed-meta and JSON order pushes preserve only server-written stamps. */
	public function test_order_save_carrying_the_ledger_keeps_server_stamps_and_drops_forged_ones(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$first = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$closure_id = wp_generate_uuid4();
		$ledger->stamp_closure( $order, array( $first['id'] ), $closure_id );
		foreach ( array( false, true ) as $json ) {
			$rows = $ledger->read( wc_get_order( $order->get_id() ) );
			unset( $rows[0]['closure_id'] );
			$rows[1]['closure_id'] = wp_generate_uuid4();
			$value = array(
				'schema' => Ledger::SCHEMA,
				'payments' => $rows,
			);
			$order->update_meta_data( Ledger::META_KEY, $json ? wp_json_encode( $value ) : $value );
			$order->save();
			$rows = $ledger->read( wc_get_order( $order->get_id() ) );
			$this->assertSame( $closure_id, $rows[0]['closure_id'] );
			$this->assertArrayNotHasKey( 'closure_id', $rows[1] );
		}
	}

	/** Order pushes replace stale or collapsed client indexes with distinct live methods. */
	public function test_order_save_carrying_the_ledger_rebuilds_the_payment_method_index(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->add_meta_data( Ledger::INDEX_META_KEY, 'old', false );
		$order->save();
		$value = array(
			'schema'   => Ledger::SCHEMA,
			'payments' => array(
				$this->payment( 'pos_cash', '5.00', array( 'status' => 'pending' ) ),
				$this->payment( 'pos_cash', '5.00', array( 'status' => 'authorized' ) ),
				$this->payment( 'stripe', '5.00', array( 'status' => 'captured' ) ),
				$this->payment( 'gift', '5.00', array( 'status' => 'voided' ) ),
			),
		);
		$order->update_meta_data( Ledger::META_KEY, $value );
		$order->update_meta_data( Ledger::INDEX_META_KEY, 'pos_cash', '' );
		$order->update_meta_data( Ledger::INDEX_META_KEY, 'stripe', '' );

		// Act.
		$order->save();

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$index = $this->index_values( $stored );
		sort( $index );
		$this->assertSame( array( 'pos_cash', 'stripe' ), $index );
		// Refund totals are server-owned: an order write that omits them stores none.
		foreach ( $value['payments'] as &$row ) {
			$row['refunded_amount'] = '0';
		}
		unset( $row );
		$this->assertSame( $value, $stored->get_meta( Ledger::META_KEY ) );
	}

	/** A re-push of an unchanged ledger beside id-less index rows must not collapse the stored index. */
	public function test_order_save_with_unchanged_ledger_and_collapsed_index_rows_rederives_the_index(): void {
		// Arrange: a stored two-leg split (written through the save hook, as an order push is).
		$order = $this->create_pos_order();
		$value = array(
			'schema'   => Ledger::SCHEMA,
			'payments' => array(
				$this->payment( 'pos_cash', '5.00', array( 'status' => 'captured' ) ),
				$this->payment( 'stripe', '5.00', array( 'status' => 'captured' ) ),
			),
		);
		$order->update_meta_data( Ledger::META_KEY, $value );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertEqualsCanonicalizing( array( 'pos_cash', 'stripe' ), $this->index_values( $order ) );

		// Act: the client re-sends the ledger unchanged with ONE id-less index row, which
		// WooCommerce's REST meta loop turns into "update the first match, delete the rest".
		$order->update_meta_data( Ledger::META_KEY, $order->get_meta( Ledger::META_KEY ) );
		$order->update_meta_data( Ledger::INDEX_META_KEY, 'pos_cash', '' );
		$order->save();

		// Assert.
		$this->assertEqualsCanonicalizing( array( 'pos_cash', 'stripe' ), $this->index_values( wc_get_order( $order->get_id() ) ) );
	}

	/** A new order carrying index rows but no readable ledger is saved with no index at all. */
	public function test_order_create_with_index_rows_and_no_ledger_drops_the_rows(): void {
		// Arrange: a client-minted order that claims a method it never recorded.
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->add_meta_data( Ledger::INDEX_META_KEY, 'stripe', false );

		// Act.
		$order->save();

		// Assert.
		$this->assertSame( array(), $this->index_values( wc_get_order( $order->get_id() ) ) );
	}

	/** Deleting an unstamped ledger removes its payment-method index too. */
	public function test_order_save_deleting_an_unstamped_ledger_removes_the_payment_method_index(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( wc_get_order( $order->get_id() ) ) );

		// Act.
		$order->delete_meta_data( Ledger::META_KEY );
		$order->save();

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( array(), $this->index_values( $stored ) );
		$this->assertFalse( $stored->meta_exists( Ledger::META_KEY ) );
	}

	/** A new order has no storage to inherit from, so every stamp it carries is forged. */
	public function test_order_create_carrying_a_forged_stamp_drops_it(): void {
		foreach ( array( true, false ) as $typed ) {
			// Arrange.
			$order = new \WC_Order();
			$row   = array(
				'id'         => wp_generate_uuid4(),
				'closure_id' => wp_generate_uuid4(),
				'amount'     => '5.00',
			);
			$value = array(
				'schema'   => Ledger::SCHEMA,
				'payments' => array( $row ),
			);
			$order->update_meta_data( Ledger::META_KEY, $typed ? $value : wp_json_encode( $value ) );
			// Act.
			$order->save();
			$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
			// Assert.
			$this->assertCount( 1, $rows );
			$this->assertSame( $row['id'], $rows[0]['id'] );
			$this->assertArrayNotHasKey( 'closure_id', $rows[0] );
		}
	}

	/** A full-ledger payload may remove uncounted legs, but never counted ones. */
	public function test_order_save_keeps_a_stamped_row_the_incoming_ledger_omits(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$first = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$second = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$closure_id = wp_generate_uuid4();
		$ledger->stamp_closure( $order, array( $first['id'] ), $closure_id );
		$stamped = $ledger->read( wc_get_order( $order->get_id() ) )[0];
		foreach ( array( array( $second ), array() ) as $incoming ) {
			// Act.
			$value = array(
				'schema' => Ledger::SCHEMA,
				'payments' => $incoming,
			);
			$order->update_meta_data( Ledger::META_KEY, $value );
			$order->save();
			$rows = $ledger->read( wc_get_order( $order->get_id() ) );
			// Assert.
			$this->assertCount( count( $incoming ) + 1, $rows );
			$this->assertSame( $stamped, $rows[ count( $incoming ) ] );
			$this->assertSame( $closure_id, $rows[ count( $incoming ) ]['closure_id'] );
			if ( $incoming ) {
				$this->assertSame( $second['id'], $rows[0]['id'] );
				$this->assertArrayNotHasKey( 'closure_id', $rows[0] );
			}
		}
	}

	/** Deleting the ledger preserves all stored legs only when a closure counted one. */
	public function test_order_save_deleting_the_ledger_keeps_the_stored_stamped_ledger(): void {
		foreach ( array( true, false ) as $stamp ) {
			// Arrange.
			$order      = $this->create_pos_order();
			$ledger     = Ledger::instance();
			$first      = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
			$second     = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
			$closure_id = wp_generate_uuid4();
			if ( $stamp ) {
				$ledger->stamp_closure( $order, array( $first['id'] ), $closure_id );
			}
			$before = $ledger->read( wc_get_order( $order->get_id() ) );
			// Act.
			$order->delete_meta_data( Ledger::META_KEY );
			$order->save();
			$rows = $ledger->read( wc_get_order( $order->get_id() ) );
			// Assert.
			if ( $stamp ) {
				$this->assertSame( $before, $rows );
				$this->assertSame( array( $first['id'], $second['id'] ), array_column( $rows, 'id' ) );
				$this->assertSame( $closure_id, $rows[0]['closure_id'] );
				$this->assertCount( 1, $this->stored_ledger_values( $order->get_id() ) );
			} else {
				$this->assertSame( array(), $rows );
				$this->assertCount( 0, $this->stored_ledger_values( $order->get_id() ) );
			}
		}
	}

	/** JSON keeper write-backs preserve the wire contract for both empty maps. */
	public function test_keeper_write_back_encodes_empty_maps_as_objects(): void {
		// Arrange.
		$order      = $this->create_pos_order();
		$ledger     = Ledger::instance();
		$first      = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$second     = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$closure_id = wp_generate_uuid4();
		$ledger->stamp_closure( $order, array( $first['id'] ), $closure_id );
		// Act.
		$value = array(
			'schema'   => Ledger::SCHEMA,
			'payments' => array( $second ),
		);
		$order->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		$order->save();
		$raw  = $this->stored_ledger_values( $order->get_id() )[0];
		$rows = $ledger->read( wc_get_order( $order->get_id() ) );
		// Assert.
		foreach ( array( 'provider_refs', 'receipt' ) as $field ) {
			$this->assertStringContainsString( '"' . $field . '":{}', $raw );
			$this->assertStringNotContainsString( '"' . $field . '":[]', $raw );
		}
		$this->assertSame( array( $second['id'], $first['id'] ), array_column( $rows, 'id' ) );
		$this->assertSame( $closure_id, $rows[1]['closure_id'] );
	}

	/**
	 * Read every raw ledger meta row from the active order storage backend.
	 *
	 * @param int $order_id Order ID.
	 * @return array Stored meta values.
	 */
	private function stored_ledger_values( int $order_id ): array {
		global $wpdb;
		$hpos  = Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' );
		$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$owner = $hpos ? 'order_id' : 'post_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Inspect actual ledger rows on the active storage backend.
		return $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE {$owner} = %d AND meta_key = %s", $order_id, Ledger::META_KEY ) );
	}

	/** Undecodable writes cannot erase stored fiscal facts. */
	public function test_order_save_with_an_undecodable_ledger_keeps_the_stored_stamped_ledger(): void {
		foreach ( array( true, false ) as $stamp ) {
			// Arrange.
			$order = $this->create_pos_order();
			$ledger = Ledger::instance();
			$first = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
			$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
			$closure_id = wp_generate_uuid4();
			if ( $stamp ) {
				$ledger->stamp_closure( $order, array( $first['id'] ), $closure_id );
			}
			$before = $ledger->read( wc_get_order( $order->get_id() ) );
			// Act.
			$order->update_meta_data( Ledger::META_KEY, 'not json' );
			$order->save();
			$fresh = wc_get_order( $order->get_id() );
			$rows = $ledger->read( $fresh );
			// Assert.
			if ( $stamp ) {
				$this->assertCount( 2, $rows );
				$this->assertSame( $before, $rows );
				$this->assertSame( $closure_id, $rows[0]['closure_id'] );
				$this->assertTrue( is_string( $fresh->get_meta( Ledger::META_KEY, true ) ) );
			} else {
				$this->assertSame( array(), $rows );
				$this->assertSame( 'not json', $fresh->get_meta( Ledger::META_KEY, true ) );
			}
		}
	}

	/** The keeper owns the lock across persistence, not just its storage read. */
	public function test_order_save_carrying_the_ledger_holds_the_order_lock_until_after_save(): void {
		// Arrange.
		$supports = new \ReflectionMethod( Order_Lock::class, 'supports_multiple_locks' );
		$supports->setAccessible( true );
		if ( ! $supports->invoke( null ) ) {
			$this->markTestSkipped( 'Order_Lock uses an option lease: this server does not support multiple named locks.' );
		}
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$connection = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = sprintf( 'wcpos_order_%d_%d', absint( $order->get_id() ), get_current_blog_id() );
		$before = null;
		$after = null;
		$before_probe = static function ( $saving ) use ( $order, $connection, $name, &$before ) {
			if ( $saving->get_id() === $order->get_id() ) {
				$before = $connection->get_var( $connection->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
			}
		};
		$after_probe = static function ( $saving ) use ( $order, $connection, $name, &$after ) {
			if ( $saving->get_id() === $order->get_id() ) {
				$after = $connection->get_var( $connection->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $before_probe, 11, 1 );
		add_action( 'woocommerce_after_order_object_save', $after_probe, 11, 1 );
		try {
			// Act.
			$value = array(
				'schema' => Ledger::SCHEMA,
				'payments' => array(),
			);
			$order->update_meta_data( Ledger::META_KEY, $value );
			$order->save();
			// Assert.
			$this->assertSame( '0', $before );
			$this->assertSame( '1', $after );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $before_probe, 11 );
			remove_action( 'woocommerce_after_order_object_save', $after_probe, 11 );
			$connection->close();
		}
	}

	/**
	 * Saving a changed leg preserves the stored timestamp of its untouched sibling.
	 */
	public function test_save_keeps_the_stamp_of_an_untouched_leg_and_restamps_a_changed_one(): void {
		$order  = $this->create_pos_order();
		$ledger = Ledger::instance();
		$extra  = array(
			'capture_mode' => 'manual',
			'status'       => 'captured',
		);
		$ledger->save(
			$order,
			array(
				$this->payment( 'pos_cash', '5.00', $extra ),
				$this->payment( 'pos_cash', '5.00', $extra ),
			)
		);
		$rows  = $ledger->read( $order );
		$stamp = gmdate( 'c', time() - 3600 );
		foreach ( $rows as &$row ) {
			$row['updated_at_gmt'] = $stamp;
		}
		unset( $row );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema'   => 1,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data(); // Seed server-owned stamps directly; an order save keeps the stored ones.

		$rows               = $ledger->read( $order );
		$rows[1]['receipt'] = array(
			'number' => 'R-2',
		);
		$ledger->save( $order, $rows );

		$rows = $ledger->read( $order );
		$this->assertSame( $stamp, $rows[0]['updated_at_gmt'] );
		$this->assertGreaterThan( time() - 60, strtotime( $rows[1]['updated_at_gmt'] ) );
	}

	public function test_record_provenance_survives_transitions_and_register_replay_conflicts(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$input = $this->payment( 'pos_cash', '20.00' );
		$input['register_id'] = strtoupper( wp_generate_uuid4() );
		$input['session_id'] = strtoupper( wp_generate_uuid4() );
		$row = $ledger->record(
			$order,
			$input,
			array(
				'register_id' => wp_generate_uuid4(),
				'session_id' => wp_generate_uuid4(),
			)
		);
		$this->assertSame( strtolower( $input['register_id'] ), $row['register_id'] );
		$this->assertSame( strtolower( $input['session_id'] ), $row['session_id'] );
		$stored = $ledger->find( wc_get_order( $order->get_id() ), $row['id'] );
		$this->assertSame( $row['register_id'], $stored['register_id'] );
		$this->assertSame( $row['session_id'], $stored['session_id'] );
		$row['status'] = 'authorized';
		foreach ( array( 'captured', 'voided' ) as $status ) {
			$row = $ledger->apply_transition(
				$row,
				array(
					'status' => $status,
					'register_id' => wp_generate_uuid4(),
					'session_id' => wp_generate_uuid4(),
				)
			);
			$this->assertSame( $status, $row['status'] );
			$this->assertSame( strtolower( $input['register_id'] ), $row['register_id'] );
			$this->assertSame( strtolower( $input['session_id'] ), $row['session_id'] );
		}
		$input['session_id'] = wp_generate_uuid4();
		$this->assertIsArray( $ledger->record( $order, $input ) );
		$input['register_id'] = wp_generate_uuid4();
		$error = $ledger->record( $order, $input );
		$this->assertSame( 'wcpos_command_conflict', $error->get_error_code() );
		$this->assertSame( 422, $error->get_error_data()['status'] );
	}

	public function test_record_invalid_provenance_normalizes_to_null(): void {
		$order = $this->create_pos_order();
		$input = array_merge(
			$this->payment( 'pos_cash', '20.00' ),
			array(
				'register_id' => array(),
				'session_id' => 'bad',
			)
		);
		$row = Ledger::instance()->record( $order, $input );
		$this->assertNull( $row['register_id'] );
		$this->assertNull( $row['session_id'] );
		$stored = Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] );
		$this->assertNull( $stored['register_id'] );
		$this->assertNull( $stored['session_id'] );
	}

	public function test_intent_disabled_method_returns_403(): void {
		$order = $this->create_pos_order();
		add_filter(
			'wcpos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['pos_card']['enabled'] = false;
				return $settings;
			},
			20
		);
		$input = $this->payment( 'pos_card', '20.00' );
		$error = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'wcpos_payment_method_disabled', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	public function test_intent_paid_order_returns_409_without_row(): void {
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95' ) );
		$input = $this->payment( 'pos_card', '20.00' );
		$error = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'wcpos_order_already_paid', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertCount( 1, Ledger::instance()->read( $order ) );
	}

	public function test_intent_excess_balance_does_not_persist_or_call_handler(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '93.00' );
		$error = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'wcpos_amount_exceeds_balance', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
		$this->assertSame( 0, Integrity_Handler::$calls );
	}

	/** A device leg is minted for one reader; the order keeps which one, and nothing else the till claims. */
	public function test_intent_keeps_the_reader_from_the_row_and_no_other_client_ref(): void {
		$order = $this->create_pos_order();
		$input = $this->payment(
			'pos_card',
			'20.00',
			array(
				'provider_refs' => array(
					'reader' => 'sn-1',
					'payment_intent' => 'pi_forged',
				),
			)
		);
		Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( array( 'reader' => 'sn-1' ), Ledger::instance()->find( $order, $input['id'] )['provider_refs'] );
	}

	public function test_intent_handler_error_does_not_persist(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$error = new \WP_Error( 'test_declined', 'Declined' );
		$this->assertSame( $error, Ledger::instance()->intent( $order, $input['id'], $input, array( 'error' => $error ) ) );
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	public function test_intent_indeterminate_error_preserves_saved_pending_row(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$ledger = Ledger::instance();
		$error = new \WP_Error( 'test_timeout', 'Timeout', array( 'indeterminate' => true ) );
		$result = $ledger->intent( $order, $input['id'], $input, array(
			'error' => $error,
			'observe' => function ( $row ) use ( $order, $ledger ) {
				$stored = $ledger->find( wc_get_order( $order->get_id() ), $row['id'] );
				$this->assertNotNull( $stored );
				$this->assertSame( 'pending', $stored['status'] );
				$this->assertSame( 'pos-open', wc_get_order( $order->get_id() )->get_status() );
			},
		) );
		$this->assertSame( $error, $result );
		$rows = $ledger->read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $input['id'], $rows[0]['id'] );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertArrayNotHasKey( 'action', $rows[0]['provider_refs'] );
		$this->assertSame( 'warning', $rows[0]['events'][0]['level'] );
		$this->assertSame( 'Provider did not answer: test_timeout', $rows[0]['events'][0]['message'] );
	}

	public function test_intent_determinate_error_removes_saved_row_and_indexes(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$kept = $ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$input = $this->payment( 'pos_card', '20.00' );
		$error = new \WP_Error( 'test_declined', 'Declined' );
		$result = $ledger->intent( $order, $input['id'], $input, array(
			'error' => $error,
			'observe' => function ( $row ) use ( $order, $ledger ) {
				$this->assertNotNull( $ledger->find( wc_get_order( $order->get_id() ), $row['id'] ) );
			},
		) );
		$this->assertSame( $error, $result );
		$order = wc_get_order( $order->get_id() );
		$this->assertNull( $ledger->find( $order, $input['id'] ) );
		$this->assertSame( array( $kept['id'] ), array_values( wp_list_pluck( $order->get_meta( Ledger::PAYMENT_ID_META_KEY, false ), 'value' ) ) );
		$this->assertEmpty( $order->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
		$this->assertCount( 1, $ledger->read( $order ) );
	}

	public function test_intent_new_captured_result_verifies_money(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$result = Ledger::instance()->intent( $order, $input['id'], $input, array( 'resume' => array( 'status' => 'captured', 'amount' => '99.00' ) ) );
		$this->assertWPError( $result );
		$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'amount_mismatch', $row['failure_reason'] );
		$this->assertNull( wc_get_order( $order->get_id() )->get_date_paid() );
	}

	public function test_intent_records_the_context_reader_when_the_input_has_none(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		Ledger::instance()->intent( $order, $input['id'], $input, array( 'reader' => 'tmr_ctx' ) );
		$this->assertSame( array( 'reader' => 'tmr_ctx' ), Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] )['provider_refs'] );
	}

	public function test_intent_pending_leg_of_another_method_blocks_new_uuid(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$first = $this->payment( 'pos_card', '92.95' );
		$ledger->intent( $order, $first['id'], $first, array() );
		add_filter(
			'wcpos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['cod']['enabled'] = true;
				return $settings;
			}
		);
		$second = $this->payment( 'cod', '92.95' );
		$result = $ledger->intent( $order, $second['id'], $second, array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_payment_in_flight', $result->get_error_code() );
		$this->assertSame( $first['id'], $result->get_error_data()['payment_id'] );
	}

	public function test_intent_replay_determinate_refusal_on_unanswered_row_fails_it(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$lost = new \WP_Error( 'test_timeout', 'Timeout', array( 'indeterminate' => true ) );
		$this->assertSame( $lost, Ledger::instance()->intent( $order, $input['id'], $input, array( 'error' => $lost ) ) );
		$this->assertSame( 'pending', Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] )['status'] );
		$refused = new \WP_Error( 'test_declined', 'Declined' );
		$this->assertSame( $refused, Ledger::instance()->intent( $order, $input['id'], $input, array( 'error' => $refused ) ) );
		$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'test_declined', $row['failure_reason'] );
		$next = $this->payment( 'pos_card', '92.95' );
		$this->assertIsArray( Ledger::instance()->intent( $order, $next['id'], $next, array() ) );
	}

	public function test_intent_indeterminate_error_projects_the_order_as_pending(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '92.95' );
		$lost = new \WP_Error( 'test_timeout', 'Timeout', array( 'indeterminate' => true ) );
		Ledger::instance()->intent( $order, $input['id'], $input, array( 'error' => $lost ) );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_record_refuses_cash_that_exceeds_balance_minus_pending_leg(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$card = $this->payment( 'pos_card', '92.95' );
		$ledger->intent( $order, $card['id'], $card, array() );
		$cash = $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) );
		$result = $ledger->record( $order, $cash );
		$this->assertWPError( $result );
		$row = $ledger->find( wc_get_order( $order->get_id() ), $cash['id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'payment_in_flight', $row['failure_reason'] );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_record_allows_cash_that_fits_beside_a_pending_leg(): void {
		$order = $this->create_pos_order();
		$order->set_total( '20.00' );
		$order->save();
		$ledger = Ledger::instance();
		$card = $this->payment( 'pos_card', '10.00' );
		$ledger->intent( $order, $card['id'], $card, array() );
		$cash = $this->payment( 'pos_cash', '10.00', array( 'tendered' => '10.00' ) );
		$this->assertIsArray( $ledger->record( $order, $cash ) );
	}

	public function test_record_accepts_cash_once_the_pending_leg_has_been_asked_to_cancel(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$card = $this->payment( 'pos_card', '92.95' );
		$ledger->intent( $order, $card['id'], $card, array() );
		$rows = $ledger->read( $order );
		$rows[0]['void_requested_at'] = gmdate( 'c' );
		$ledger->save( $order, $rows, false );
		$cash = $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) );
		$this->assertIsArray( $ledger->record( $order, $cash ) );
		$retry = $this->payment( 'pos_card', '92.95' );
		$this->assertSame( 'wcpos_order_already_paid', $ledger->intent( $order, $retry['id'], $retry, array() )->get_error_code() );
	}

	public function test_record_in_flight_refusal_names_the_live_leg(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$card = $this->payment( 'pos_card', '92.95' );
		$ledger->intent( $order, $card['id'], $card, array() );
		$cash = $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) );
		$result = $ledger->record( $order, $cash );
		$this->assertSame( 'wcpos_payment_in_flight', $result->get_error_code() );
		$this->assertSame( $card['id'], $result->get_error_data()['payment_id'] );
	}

	public function test_in_flight_refusal_names_the_leg_that_blocks_not_the_one_being_cancelled(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$older = $this->payment( 'pos_card', '40.00' );
		$ledger->intent( $order, $older['id'], $older, array() );
		$newer = $this->payment( 'pos_card', '52.95' );
		$ledger->intent( $order, $newer['id'], $newer, array() );
		$rows = $ledger->read( $order );
		foreach ( $rows as &$row ) {
			if ( $row['id'] === $newer['id'] ) {
				$row['void_requested_at'] = gmdate( 'c' );
			}
		}
		unset( $row );
		$ledger->save( $order, $rows, false );
		$cash = $this->payment( 'pos_cash', '60.00', array( 'tendered' => '60.00' ) );
		$result = $ledger->record( $order, $cash );
		$this->assertSame( 'wcpos_payment_in_flight', $result->get_error_code() );
		$this->assertSame( $older['id'], $result->get_error_data()['payment_id'] );
	}

	public function test_intent_replay_determinate_error_on_answered_row_leaves_it_pending(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00', array( 'provider_refs' => array( 'reader' => 'sn-1' ) ) );
		Ledger::instance()->intent( $order, $input['id'], $input, array( 'resume' => array( 'provider_refs' => array( 'reader' => 'sn-1', 'action' => 'act_1' ) ) ) );
		$this->assertSame( 'act_1', Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] )['provider_refs']['action'] );
		$blip = new \WP_Error( 'test_blip', 'Provider blip' );
		$this->assertSame( $blip, Ledger::instance()->intent( $order, $input['id'], $input, array( 'error' => $blip ) ) );
		$this->assertSame( 'pending', Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] )['status'] );
	}

	public function test_intent_pending_full_balance_blocks_new_uuid(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$first = $this->payment( 'pos_card', '92.95' );
		$ledger->intent( $order, $first['id'], $first, array() );
		$before = $ledger->read( $order );
		$second = $this->payment( 'pos_card', '92.95' );
		$result = $ledger->intent( $order, $second['id'], $second, array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_payment_in_flight', $result->get_error_code() );
		$this->assertSame( array( 'status' => 409, 'payment_id' => $first['id'] ), $result->get_error_data() );
		$this->assertSame( 1, Integrity_Handler::$calls );
		$this->assertSame( $before, $ledger->read( wc_get_order( $order->get_id() ) ) );
	}

	public function test_intent_pending_partial_balance_allows_remaining_amount(): void {
		$order = $this->create_pos_order();
		$order->set_total( '20.00' );
		$order->save();
		$ledger = Ledger::instance();
		$first = $this->payment( 'pos_card', '10.00' );
		$ledger->intent( $order, $first['id'], $first, array() );
		$second = $this->payment( 'pos_card', '10.00' );
		$result = $ledger->intent( $order, $second['id'], $second, array() );
		$this->assertIsArray( $result );
		$this->assertSame( $second['id'], $result['payment']['id'] );
		$this->assertSame( 2, Integrity_Handler::$calls );
		$this->assertCount( 2, $ledger->read( $order ) );
	}

	public function test_intent_pending_full_balance_replay_is_allowed(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$input = $this->payment( 'pos_card', '92.95' );
		$ledger->intent( $order, $input['id'], $input, array() );
		$result = $ledger->intent( $order, $input['id'], $input, array() );
		$this->assertIsArray( $result );
		$this->assertSame( $input['id'], $result['payment']['id'] );
		$this->assertSame( 2, Integrity_Handler::$calls );
		$this->assertCount( 1, $ledger->read( $order ) );
	}

	public function test_intent_source_webview_is_persisted_and_bogus_defaults_to_app(): void {
		foreach ( array( 'webview' => 'webview', 'bogus' => 'app' ) as $source => $expected ) {
			$order = $this->create_pos_order();
			$input = $this->payment( 'pos_card', '20.00', array( 'source' => $source ) );
			$result = Ledger::instance()->intent( $order, $input['id'], $input, array() );
			$this->assertIsArray( $result );
			$this->assertSame( $expected, Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] )['source'] );
		}
	}

	public function test_capture_handler_error_preserves_pending_row(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$error = new \WP_Error( 'test_declined', 'Declined' );
		$this->assertSame( $error, Ledger::instance()->capture( $order, $input['id'], array( 'error' => $error ) ) );
		$this->assertSame( 'pending', Ledger::instance()->find( $order, $input['id'] )['status'] );
	}

	public function test_intent_replay_resumes_the_handler_and_returns_the_handoff(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$ledger = Ledger::instance();
		$first = $ledger->intent( $order, $input['id'], $input, array() );
		$again = $ledger->intent( $order, $input['id'], $input, array() );
		// routes.md §4.3: a leg still in flight RESUMES at the provider rather than
		// repeating — the handler keys its provider call on the row id — so a replay
		// hands back the same handoff the app lost when the first response never
		// arrived. Returning an empty handoff would strand the till mid-payment.
		$this->assertSame( 2, Integrity_Handler::$calls );
		$this->assertSame( array( 'token' => 'secret' ), $first['handoff'] );
		$this->assertSame( $first['handoff'], $again['handoff'] );
		$this->assertSame( $first['payment']['id'], $again['payment']['id'] );
		$this->assertSame( 'pending', $again['payment']['status'] );
		$this->assertCount( 1, $ledger->read( $order ) );
		$this->assertArrayNotHasKey( 'handoff', $ledger->find( $order, $input['id'] ) );
		$this->assertSame( 'pending', $order->get_status() );
		$error = $ledger->intent( $order, $input['id'], array_merge( $input, array( 'amount' => '21.00' ) ), array() );
		$this->assertSame( 'wcpos_command_conflict', $error->get_error_code() );
	}

	/** @dataProvider capture_confirmations */
	public function test_capture_verifies_provider_money( array $confirmed, string $tips, bool $mismatch ): void {
		$order = $this->create_pos_order();
		$product = ProductHelper::create_simple_product();
		$product->set_price( '92.95' );
		$product->set_tax_status( 'none' );
		$product->save();
		$order->add_product( $product );
		$order->calculate_totals( false );
		Integrity_Handler::$tips = $tips;
		$input = $this->payment( 'pos_card', '92.95' );
		$ledger = Ledger::instance();
		$ledger->intent( $order, $input['id'], $input, array() );
		$result = $ledger->capture( $order, $input['id'], $confirmed );
		$stored = $ledger->find( wc_get_order( $order->get_id() ), $input['id'] );
		if ( $mismatch ) {
			$this->assertSame( 'wcpos_amount_mismatch', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( 'failed', $stored['status'] );
			$this->assertSame( 'amount_mismatch', $stored['failure_reason'] );
			$this->assertSame( '92.95', $stored['amount'] );
			$replay = $ledger->capture( $order, $input['id'], $confirmed );
			// assertEquals, not assertSame: to_wire() mints a fresh stdClass for the two
			// empty maps on every call, so identity comparison could never hold.
			$this->assertEquals( $result->get_error_data(), $replay->get_error_data() );
			$this->assertNotEmpty( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
			$this->assertFalse( $order->is_paid() );
		} else {
			$this->assertSame( $confirmed['status'] ?? 'captured', $stored['status'] );
			$this->assertSame( $confirmed['amount'] ?? '92.95', $stored['amount'] );
			// An authorization reserves the balance but pays nothing (D6); a capture pays it.
			$this->assertSame( 'authorized' === ( $confirmed['status'] ?? null ) ? '92.95' : '0.00', $ledger->summary( $order )['balance'] );
			if ( 'on_reader' === $tips ) {
				$fees = array_values( $order->get_fees() );
				$this->assertCount( 1, $fees );
				$this->assertSame( 'Tip', $fees[0]->get_name() );
				$this->assertSame( 'none', $fees[0]->get_tax_status() );
				$this->assertSame( '7.05', $stored['tip'] );
				$this->assertSame( '100.00', $order->get_total() );
				$this->assertSame( 'wcpos_invalid_transition', $ledger->capture( $order, $input['id'], $confirmed )->get_error_code() );
				$this->assertCount( 1, $order->get_fees() );
			}
		}
	}

	public function capture_confirmations(): array {
		return array(
			'exact' => array( array( 'amount' => '92.95' ), 'none', false ),
			'authorized exact' => array(
				array(
					'status' => 'authorized',
					'amount' => '92.95',
				),
				'none',
				false,
			),
			'authorized mismatch' => array(
				array(
					'status' => 'authorized',
					'amount' => '90.00',
				),
				'none',
				true,
			),
			'implicit' => array( array(), 'none', false ),
			'tip' => array( array( 'amount' => '100.00' ), 'on_reader', false ),
			'over' => array( array( 'amount' => '100.00' ), 'none', true ),
			'under' => array( array( 'amount' => '90.00' ), 'none', true ),
			'currency' => array( array( 'currency' => 'EUR' ), 'on_reader', true ),
		);
	}

	/**
	 * A money command answers the row as stored, so a replay answers the same document (ADR 0040
	 * rule 5, D14). The ticking clock puts every normalization a second after the last: the
	 * caller's copy and the row save() stores always differ in updated_at_gmt.
	 *
	 * @dataProvider stored_row_answers
	 *
	 * @param string $command The money command that answers.
	 */
	public function test_money_command_answer_on_a_ticking_clock_is_the_stored_row( string $command ): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$ledger = Ledger::instance();
		$GLOBALS['woocommerce_pos_ledger_clock'] = time();
		$input  = $this->payment( 'pos_card', '92.95' );
		$refund = null;
		if ( in_array( $command, array( 'record refused', 'refund' ), true ) ) {
			$input = $this->payment( 'pos_cash', 'refund' === $command ? '20.00' : '100.00' );
		} elseif ( 'settle allocation' === $command ) {
			list( $order, $seeded, $refund ) = $this->seed_settlement_allocation();
			$input['id'] = $seeded['id'];
		} else {
			$ledger->intent( $order, $input['id'], $input, array() );
		}
		if ( 'refund' === $command ) {
			$ledger->record( $order, $input );
			$refund = new \WC_Order_Refund();
			$refund->set_parent_id( $order->get_id() );
			$refund->set_amount( '20.00' );
			$refund->save();
		}
		$event   = array(
			'status'   => 'authorized',
			'amount'   => '92.95',
			'event_id' => 'evt_d14',
		);
		$noted   = array(
			'id'   => wp_generate_uuid4(),
			'fp'   => 'fp_d14',
			'kind' => 'capture',
		);
		$answers = array(
			'capture captured'   => fn() => $ledger->capture( $order, $input['id'], array( 'amount' => '92.95' ) ),
			'capture mismatch'   => fn() => $ledger->capture( $order, $input['id'], array( 'amount' => '90.00' ) ),
			'event seen again'   => fn() => $ledger->apply_result( $order, $input['id'], $event, true, $noted ),
			'record refused'     => fn() => $ledger->record( $order, $input ),
			'refund'             => fn() => $ledger->refund( $order, $input['id'], $refund->get_id(), '5.00' ),
			'settle allocation'  => fn() => $ledger->settle_allocation( $order, $input['id'], $refund->get_id(), 'succeeded', 'ref_d14' ),
		);
		if ( 'event seen again' === $command ) {
			$ledger->apply_result( $order, $input['id'], $event );
		}

		// Act.
		$answer = $answers[ $command ]();
		$stored = $ledger->find( wc_get_order( $order->get_id() ), $input['id'] );
		$replay = in_array( $command, array( 'capture captured', 'event seen again' ), true ) ? null : $answers[ $command ]();

		// Assert.
		$row = is_wp_error( $answer ) ? $answer->get_error_data()['payment'] : $answer;
		$this->assertIsArray( $row );
		$this->assertSame( $stored['updated_at_gmt'], $row['updated_at_gmt'] );
		// assertEquals for a refusal: to_wire() mints a fresh stdClass for each empty map.
		$this->assertEquals( is_wp_error( $answer ) ? Ledger::to_wire( $stored ) : $stored, $row );
		if ( null !== $replay ) {
			$this->assertEquals( is_wp_error( $answer ) ? $answer->get_error_data() : $answer, is_wp_error( $replay ) ? $replay->get_error_data() : $replay );
		}
	}

	/** Every money command that saves a row and answers with it, or with a refusal built from it. */
	public function stored_row_answers(): array {
		return array(
			'capture captured (apply_result tail)'         => array( 'capture captured' ),
			'capture mismatch (apply_result refusal)'      => array( 'capture mismatch' ),
			'provider event seen again with a new command' => array( 'event seen again' ),
			'record refused'                               => array( 'record refused' ),
			'refund allocated'                             => array( 'refund' ),
			'settle allocation'                            => array( 'settle allocation' ),
		);
	}

	public function test_capture_carves_tax_out_of_a_taxable_tip_and_the_order_stays_paid(): void {
		$old_calc_taxes    = get_option( 'woocommerce_calc_taxes' );
		$old_tax_based_on  = get_option( 'woocommerce_tax_based_on' );
		$old_base_location = get_option( 'woocommerce_default_country' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		$base_tax_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'Base tax',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		$tax_rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'AU',
				'tax_rate_state'    => 'VIC',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'Tip tax',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);

		try {
			$order = $this->create_pos_order();
			$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'billing' );
			$order->set_billing_country( 'AU' );
			$order->set_billing_state( 'VIC' );
			$product = ProductHelper::create_simple_product();
			$product->set_price( '92.95' );
			$product->set_tax_status( 'none' );
			$product->save();
			$order->add_product( $product );
			$order->calculate_totals( false );
			Integrity_Handler::$tips = 'on_reader';
			add_filter( 'wcpos_payment_tip_fee_taxable', '__return_true' );
			$input = $this->payment( 'pos_card', '92.95' );
			$ledger = Ledger::instance();
			$ledger->intent( $order, $input['id'], $input, array() );

			$row = $ledger->capture( $order, $input['id'], array( 'amount' => '100.00' ) );

			// The customer paid 100.00 and no more. A 7.05 tip at 10% is 6.41 + 0.64 tax,
			// carved out of the tip — never 7.05 + 0.71 on top, which would leave a
			// phantom 0.71 balance on an order the customer has fully paid.
			$fee = array_values( $order->get_fees() )[0];
			$this->assertSame( 'taxable', $fee->get_tax_status() );
			$this->assertSame( '6.41', wc_format_decimal( $fee->get_total(), 2 ) );
			$this->assertSame( '0.64', wc_format_decimal( $fee->get_total_tax(), 2 ) );
			$this->assertSame( '100.00', $order->get_total() );
			$this->assertSame( '100.00', $row['amount'] );
			$this->assertSame( '7.05', $row['tip'] );
			$this->assertSame( '0.00', $ledger->summary( $order )['balance'] );
			$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		} finally {
			WC_Tax::_delete_tax_rate( $tax_rate_id );
			WC_Tax::_delete_tax_rate( $base_tax_rate_id );
			update_option( 'woocommerce_calc_taxes', $old_calc_taxes );
			update_option( 'woocommerce_tax_based_on', $old_tax_based_on );
			update_option( 'woocommerce_default_country', $old_base_location );
		}
	}

	public function test_refund_replay_and_rollup_preserve_one_allocation(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$row = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '20.00' );
		$refund->save();
		$first = $ledger->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$again = $ledger->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$this->assertSame( $first, $again );
		$this->assertCount( 1, $again['refunds'] );
		$this->assertSame( '5.00', $again['refunded_amount'] );
		$this->assertSame( '5.00', $ledger->find( wc_get_order( $order->get_id() ), $row['id'] )['refunded_amount'] );
	}

	public function test_refund_pending_allocations_reserve_balance(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$row = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$row['refunds'] = array(
			array(
				'id' => 123,
				'amount' => '15.00',
				'status' => 'pending',
				'provider_ref' => null,
			),
		);
		$ledger->save( $order, array( $row ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '20.00' );
		$refund->save();
		$error = $ledger->refund( $order, $row['id'], $refund->get_id(), '6.00' );
		$this->assertSame( 'wcpos_refund_not_allocatable', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$exact = $ledger->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$this->assertSame( '5.00', $exact['refunded_amount'] );
	}

	/** Allocations for one refund across every row are capped by the refund's own amount. */
	public function test_refund_allocations_across_rows_cannot_exceed_the_refund_amount(): void {
		// Arrange: two rows, one 10.00 refund.
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$cash = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$card = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '10.00' );
		$refund->save();
		// Act.
		$first = $ledger->refund( $order, $cash['id'], $refund->get_id(), '6.00' );
		$over = $ledger->refund( $order, $card['id'], $refund->get_id(), '6.00' );
		$rest = $ledger->refund( $order, $card['id'], $refund->get_id(), '4.00' );
		// Assert.
		$this->assertSame( '6.00', $first['refunded_amount'] );
		$this->assertSame( 'wcpos_refund_not_allocatable', $over->get_error_code() );
		$this->assertSame( 400, $over->get_error_data()['status'] );
		$this->assertSame( '4.00', $rest['refunded_amount'] );
	}

	public function test_refund_rejects_foreign_parent(): void {
		$order = $this->create_pos_order();
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $this->create_pos_order()->get_id() );
		$refund->set_amount( '20.00' );
		$refund->save();
		$error = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
	}

	public function test_row_schema_normalizes_expiry_and_seen_events(): void {
		$order = $this->create_pos_order();
		$row = $this->payment(
			'pos_cash',
			'20.00',
			array(
				'status' => 'pending',
				'expires_at' => 'invalid',
				'seen_events' => array( 'event', 123 ),
			)
		);
		Ledger::instance()->save( $order, array( $row ) );
		$stored = Ledger::instance()->find( $order, $row['id'] );
		$this->assertNull( $stored['expires_at'] );
		$this->assertSame( array( 'event' ), $stored['seen_events'] );
		$new = Ledger::instance()->apply_transition(
			$stored,
			array(
				'expires_at' => '2026-09-09T00:00:00Z',
				'seen_events' => array( 'forged' ),
			)
		);
		$this->assertSame( '2026-09-09T00:00:00Z', $new['expires_at'] );
		$this->assertSame( array( 'event' ), $new['seen_events'] );
	}

	public function test_resumed_intent_and_void_results_take_capture_verification(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		// A resumed intent that comes back captured for the wrong money is refused.
		$input = $this->payment( 'pos_card', '20.00' );
		$ledger->intent( $order, $input['id'], $input, array() );
		$error = $ledger->intent(
			$order,
			$input['id'],
			$input,
			array(
				'resume' => array(
					'status' => 'captured',
					'amount' => '999.00',
				),
			)
		);
		$this->assertSame( 'wcpos_amount_mismatch', $error->get_error_code() );
		$this->assertSame( 'failed', $ledger->find( $order, $input['id'] )['status'] );
		// A void whose cancel raced a capture is verified the same way.
		$input = $this->payment( 'pos_card', '20.00' );
		$ledger->intent( $order, $input['id'], $input, array() );
		Integrity_Handler::$void_result = array(
			'status' => 'captured',
			'amount' => '999.00',
		);
		$error = $ledger->void( $order, $input['id'], 'changed mind' );
		$this->assertSame( 'wcpos_amount_mismatch', $error->get_error_code() );
		// A cancel that is only requested leaves the row pending with the stamp.
		$input = $this->payment( 'pos_card', '20.00' );
		$ledger->intent( $order, $input['id'], $input, array() );
		Integrity_Handler::$void_result = array( 'void_requested_at' => '2026-09-08T10:00:00Z' );
		$row = $ledger->void( $order, $input['id'], 'changed mind' );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '2026-09-08T10:00:00Z', $row['void_requested_at'] );
	}

	public function test_intent_drains_a_settlement_parked_before_the_row_existed(): void {
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$this->assertTrue(
			wcpos_settle_payment(
				$input['id'],
				array(
					'status' => 'captured',
					'amount' => '20.00',
					'event_id' => 'early',
				)
			)
		);
		$result = Ledger::instance()->intent( $order, $input['id'], $input, array() );
		$this->assertSame( 'captured', $result['payment']['status'] );
		$this->assertSame( array( 'early' ), $result['payment']['seen_events'] );
		$this->assertArrayNotHasKey( $input['id'], get_option( 'wcpos_pending_settlements', array() ) );
		$this->assertSame( 'pos-partial', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_intent_derives_only_after_a_parked_void_is_applied(): void {
		// The webhook that voided the leg arrived before the row existed; the intent then
		// returns an authorization for the full balance. Deriving before the drain would
		// complete the order on money that was already reversed — and never unwind it.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '92.95' );
		$this->assertTrue(
			wcpos_settle_payment(
				$input['id'],
				array(
					'status' => 'voided',
					'event_id' => 'reversed',
				)
			)
		);
		$result = Ledger::instance()->intent( $order, $input['id'], $input, array( 'resume' => array( 'status' => 'authorized' ) ) );
		$this->assertSame( 'voided', $result['payment']['status'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertSame( 'pos-open', $order->get_status() );
	}

	public function test_row_schema_carries_events_and_void_requested_at_for_handlers(): void {
		$order = $this->create_pos_order();
		$row = $this->payment(
			'pos_cash',
			'20.00',
			array(
				'status' => 'pending',
				'events' => 'nope',
				'void_requested_at' => 'invalid',
			)
		);
		Ledger::instance()->save( $order, array( $row ) );
		$stored = Ledger::instance()->find( $order, $row['id'] );
		$this->assertSame( array(), $stored['events'] );
		$this->assertNull( $stored['void_requested_at'] );
		// A handler owns both: they survive apply_transition, and the log is capped newest-last.
		$events = array();
		for ( $i = 0; $i < Ledger::EVENTS_MAX + 5; ++$i ) {
			$events[] = array(
				't' => gmdate( 'c' ),
				'level' => 'info',
				'message' => 'event ' . $i,
			);
		}
		$new = Ledger::instance()->apply_transition(
			$stored,
			array(
				'events' => $events,
				'void_requested_at' => '2026-09-09T00:00:00Z',
			)
		);
		Ledger::instance()->save( $order, array( $new ) );
		$stored = Ledger::instance()->find( $order, $row['id'] );
		$this->assertCount( Ledger::EVENTS_MAX, $stored['events'] );
		$this->assertSame( 'event ' . ( Ledger::EVENTS_MAX + 4 ), $stored['events'][ Ledger::EVENTS_MAX - 1 ]['message'] );
		$this->assertSame( '2026-09-09T00:00:00Z', $stored['void_requested_at'] );
	}

	/** A full cash tender completes and indexes the order. */
	public function test_record_single_cash_payment_completes_order_and_sets_payment_method(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) ) );

		// Assert.
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( '7.05', $row['change'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pos_cash', $order->get_payment_method() );
		$this->assertSame( 'Cash', $order->get_payment_method_title() );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( $order ) );
		$stored = json_decode( $order->get_meta( Ledger::META_KEY, true ), true );
		$this->assertSame( 1, $stored['schema'] );
		$this->assertCount( 1, $stored['payments'] );
	}

	/** Split tenders derive the largest method and composed title. */
	public function test_record_cash_then_card_split_derives_largest_tender_and_composed_title(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$ledger = Ledger::instance();

		// Act.
		$ledger->record( $order, $this->payment( 'pos_cash', '30.00' ) );
		$first = $ledger->summary( $order );
		$ledger->record( $order, $this->payment( 'pos_card', '62.95' ) );

		// Assert.
		$this->assertSame( 'pos-partial', $first['status'] );
		$this->assertSame( '30.00', $first['paid'] );
		$this->assertSame( '62.95', $first['balance'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pos_card', $order->get_payment_method() );
		$this->assertSame( 'Cash + Card', $order->get_payment_method_title() );
		$this->assertSame( array( 'pos_cash', 'pos_card' ), $this->index_values( $order ) );
	}

	/** Stored value never displaces a conventional payment method. */
	public function test_derive_payment_method_excludes_stored_value_tender(): void {
		// Arrange.
		$order  = $this->create_pos_order();
		$filter = static function ( string $kind, $gateway ): string {
			return 'pos_card' === $gateway->id ? 'stored_value' : $kind;
		};
		add_filter( 'wcpos_payment_method_kind', $filter, 10, 2 );

		try {
			// Act.
			Ledger::instance()->record( $order, $this->payment( 'pos_card', '80.00' ) );
			Ledger::instance()->record( $order, $this->payment( 'pos_cash', '12.95' ) );

			// Assert.
			$this->assertSame( 'pos_cash', $order->get_payment_method() );
		} finally {
			remove_filter( 'wcpos_payment_method_kind', $filter, 10 );
		}
	}

	/** A full authorization leaves the sale open, reserves its amount, and only capture completes it (D6). */
	public function test_full_authorization_leaves_the_order_open_and_reserves_its_amount(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$card  = $this->payment( 'pos_card', '92.95' );

		// Act: the reader approves the whole total.
		Ledger::instance()->intent( $order, $card['id'], $card, array( 'resume' => array( 'status' => 'authorized' ) ) );

		// Assert: open, nothing paid, the authorization shown beside the balance.
		$fresh   = wc_get_order( $order->get_id() );
		$summary = Ledger::instance()->summary( $fresh );
		$this->assertSame( 'pos-open', $fresh->get_status() );
		$this->assertSame( '92.95', $summary['authorized'] );
		$this->assertSame( '0.00', $summary['paid'] );
		$this->assertSame( '92.95', $summary['balance'] );
		$this->assertNull( $fresh->get_date_paid( 'edit' ) );
		$this->assertFalse( (bool) $fresh->get_data_store()->get_stock_reduced( $order->get_id() ) );

		// A second tender against the standing authorization is refused, naming it.
		$cash  = Ledger::instance()->record( $fresh, $this->payment( 'pos_cash', '92.95' ) );
		$this->assertWPError( $cash );
		$this->assertSame( 'wcpos_payment_in_flight', $cash->get_error_code() );
		$this->assertSame( 409, $cash->get_error_data()['status'] );
		$this->assertSame( $card['id'], $cash->get_error_data()['payment_id'] );
		$intent = $this->payment( 'pos_card', '10.00' );
		$second = Ledger::instance()->intent( wc_get_order( $order->get_id() ), $intent['id'], $intent, array() );
		$this->assertWPError( $second );
		$this->assertSame( 'wcpos_payment_in_flight', $second->get_error_code() );
		$this->assertSame( $card['id'], $second->get_error_data()['payment_id'] );

		// Capture completes it.
		Ledger::instance()->capture( wc_get_order( $order->get_id() ), $card['id'], array() );
		$paid = wc_get_order( $order->get_id() );
		$this->assertTrue( $paid->is_paid() );
		$this->assertNotNull( $paid->get_date_paid( 'edit' ) );
		$this->assertCount( 1, Completion_History::of( $paid )->entries() );
	}

	/** An authorization is money in motion, not money taken: it cannot take a refund allocation. */
	public function test_authorized_row_is_not_refundable(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = Ledger::instance()->record( $order, $this->payment( 'pos_card', '20.00', array( 'status' => 'authorized' ) ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '5.00' );
		$refund->save();

		// Act.
		$result = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '5.00' );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_invalid_transition', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/** An approval for the wrong amount fails the row, though an authorization no longer counts. */
	public function test_authorization_for_a_different_amount_fails_the_row(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '92.95' );

		// Act.
		$result = Ledger::instance()->intent(
			$order,
			$input['id'],
			$input,
			array(
				'resume' => array(
					'status' => 'authorized',
					'amount' => '90.00',
				),
			)
		);

		// Assert.
		$this->assertWPError( $result );
		$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $input['id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'amount_mismatch', $row['failure_reason'] );
	}

	/** Captured money above a reduced total is owed back, and reads apart from money still due (§4.2). */
	public function test_summary_reports_refund_due_when_captured_money_exceeds_a_reduced_total(): void {
		// Arrange: 100.00 captured on two lines, reopened, the 20.00 line removed.
		list( $order, $row, $lines ) = $this->paid_sale_with_lines( array( '80.00', '20.00' ) );
		$order->set_status( 'pos-open' );
		$order->remove_item( $lines[1] );
		$order->calculate_totals( false );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '80.00', $order->get_total() );

		// Act + Assert: covered, and 20.00 owed back.
		$summary = Ledger::instance()->summary( $order );
		$this->assertSame( '0.00', $summary['balance'] );
		$this->assertSame( '20.00', $summary['refund_due'] );
		$this->assertSame( '0.00', $summary['refund_pending'] );

		// A succeeded 20.00 allocation settles what was owed.
		$refund = $this->refund_of( $order, '20.00' );
		$this->assertIsArray( Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '20.00' ) );
		$summary = Ledger::instance()->summary( wc_get_order( $order->get_id() ) );
		$this->assertSame( '0.00', $summary['refund_due'] );
		$this->assertSame( '0.00', $summary['balance'] );
	}

	/** A pending allocation is a reservation shown beside the balance, never added to it (rule 1). */
	public function test_summary_reports_a_pending_allocation_as_refund_pending_not_as_balance(): void {
		// Arrange.
		list( $order, $row, $lines ) = $this->paid_sale_with_lines( array( '80.00', '20.00' ) );
		$order->set_status( 'pos-open' );
		$order->remove_item( $lines[1] );
		$order->calculate_totals( false );
		$order->save();
		$order  = wc_get_order( $order->get_id() );
		$refund = $this->refund_of( $order, '20.00' );
		$stored = Ledger::instance()->find( $order, $row['id'] );
		$stored['refunds'] = array(
			array(
				'id' => $refund->get_id(),
				'amount' => '20.00',
				'status' => 'pending',
				'provider_ref' => null,
			),
		);
		Ledger::instance()->save( $order, array( $stored ), false );

		// Act.
		$summary = Ledger::instance()->summary( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( '20.00', $summary['refund_due'] );
		$this->assertSame( '20.00', $summary['refund_pending'] );
		$this->assertSame( '0.00', $summary['balance'] );
	}

	/** derive() completes on the signed balance, not on gross captured money (§4.2). */
	public function test_derive_does_not_complete_a_partly_refunded_reopened_sale_on_gross_captured_money(): void {
		// Arrange: 100.00 captured, 20.00 refunded and allocated, reopened unchanged.
		list( $order, $row ) = $this->paid_sale_with_lines( array( '100.00' ) );
		$refund = $this->refund_of( $order, '20.00' );
		$this->assertIsArray( Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '20.00' ) );
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'pos-open' );
		$order->save();

		// Act: 10.00 cash leaves 10.00 still due.
		$first = Ledger::instance()->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '10.00' ) );

		// Assert.
		$this->assertSame( 'captured', $first['status'] );
		$this->assertSame( 'pos-partial', wc_get_order( $order->get_id() )->get_status() );

		// Another 10.00 covers it and completes the sale again.
		Ledger::instance()->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '10.00' ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( array( 1, 2 ), array_column( Completion_History::of( wc_get_order( $order->get_id() ) )->entries(), 'n' ) );
	}

	/** A money write never completes a zero-total order; only close does (§4.3, Paul 2026-10-20). */
	public function test_a_money_write_never_completes_a_zero_total_order(): void {
		// Arrange.
		list( $order ) = $this->open_sale_of( '0.00' );
		$this->assertSame( '0.00', wc_format_decimal( $order->get_total(), 2 ) );

		// Act: the non-closing path.
		Ledger::instance()->derive( $order, array(), false );
		$order->save();

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos-open', $stored->get_status() );
		$this->assertNull( $stored->get_date_paid( 'edit' ) );

		// Act: the closing path.
		Ledger::instance()->derive( $stored, array(), true );

		// Assert.
		$completed = wc_get_order( $order->get_id() );
		$this->assertTrue( $completed->is_paid() );
		$this->assertNotNull( $completed->get_date_paid( 'edit' ) );
	}

	/** With prevent-overselling on, a tender holds the order's stock; a void releases it (§4.8). */
	public function test_a_tender_holds_stock_against_another_order_until_it_is_voided(): void {
		// Arrange: one unit of stock, two open POS orders for it.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		$settings                = get_option( 'woocommerce_pos_settings_checkout' );
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => true ) );
		try {
			$product = ProductHelper::create_simple_product();
			$product->set_regular_price( '10' );
			$product->set_price( '10' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 1 );
			$product->save();
			$orders = array();
			foreach ( array( 'a', 'b' ) as $key ) {
				$orders[ $key ] = wc_create_order();
				$orders[ $key ]->set_created_via( 'woocommerce-pos' );
				$orders[ $key ]->set_status( 'pos-open' );
				$orders[ $key ]->add_product( $product, 1 );
				$orders[ $key ]->calculate_totals( false );
				$orders[ $key ]->save();
			}
			$hold = $this->payment( 'pos_card', $orders['a']->get_total() );
			Ledger::instance()->intent( $orders['a'], $hold['id'], $hold, array( 'resume' => array( 'status' => 'authorized' ) ) );
			$this->assertSame( 'authorized', Ledger::instance()->find( wc_get_order( $orders['a']->get_id() ), $hold['id'] )['status'] );

			// Act: B tries to take the same unit.
			$refused = Ledger::instance()->record( wc_get_order( $orders['b']->get_id() ), $this->payment( 'pos_cash', $orders['b']->get_total() ) );

			// Assert.
			$this->assertWPError( $refused );
			$this->assertSame( 'wcpos_insufficient_stock', $refused->get_error_code() );
			$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $orders['b']->get_id() ) ) );

			// Voiding A's hold releases the unit.
			Ledger::instance()->void( wc_get_order( $orders['a']->get_id() ), $hold['id'], 'customer left' );
			$recorded = Ledger::instance()->record( wc_get_order( $orders['b']->get_id() ), $this->payment( 'pos_cash', $orders['b']->get_total() ) );
			$this->assertIsArray( $recorded );
			$this->assertSame( 'captured', $recorded['status'] );
		} finally {
			unset( $_SERVER['HTTP_X_WCPOS'] );
			update_option( 'woocommerce_pos_settings_checkout', $settings );
		}
	}

	/**
	 * Payment rows read from storage, not from an order object.
	 *
	 * @param int $order_id Order ID.
	 */
	private function stored_rows( int $order_id ): array {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_data_store()->read_meta( $order ) as $meta ) {
			if ( Ledger::META_KEY === $meta->meta_key ) {
				return json_decode( maybe_unserialize( $meta->meta_value ), true )['payments'];
			}
		}
		return array();
	}

	/** A completed sale owes 20.00 after a refund, yet takes no new tender until it is reopened (§4.2). */
	public function test_a_completed_sale_with_a_refund_takes_no_new_tender_until_reopened(): void {
		// Arrange: 100.00 captured completes the sale; 20.00 refunded and allocated.
		list( $order, $row ) = $this->paid_sale_with_lines( array( '100.00' ) );
		$refund = $this->refund_of( $order, '20.00' );
		$this->assertIsArray( Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '20.00' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '20.00', Ledger::instance()->summary( $order )['balance'] );

		// Act.
		$cash   = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$card   = $this->payment( 'pos_card', '20.00' );
		$intent = Ledger::instance()->intent( wc_get_order( $order->get_id() ), $card['id'], $card, array() );

		// Assert.
		foreach ( array( $cash, $intent ) as $refused ) {
			$this->assertWPError( $refused );
			$this->assertSame( 'wcpos_order_already_paid', $refused->get_error_code() );
			$this->assertSame( 409, $refused->get_error_data()['status'] );
			$this->assertSame( '20.00', $refused->get_error_data()['order']['balance'] );
		}
		// No new money counts: the cash attempt is kept only as the refused row record() always writes
		// for a paid sale (offline cash may already be in the drawer); the intent writes nothing.
		$stored = $this->stored_rows( $order->get_id() );
		$this->assertSame( array( 'captured', 'failed' ), array_column( $stored, 'status' ) );
		$this->assertSame( 'order_already_paid', $stored[1]['failure_reason'] );
		$this->assertNotContains( $card['id'], array_column( $stored, 'id' ) );
		$this->assertSame( '20.00', Ledger::instance()->summary( wc_get_order( $order->get_id() ) )['balance'] );

		// Reopened, the same tender is taken and the sale completes again.
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$taken = Ledger::instance()->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '20.00' ) );
		$this->assertIsArray( $taken );
		$this->assertSame( 'captured', $taken['status'] );
		$paid = wc_get_order( $order->get_id() );
		$this->assertTrue( $paid->is_paid() );
		$this->assertSame( array( 1, 2 ), array_column( Completion_History::of( $paid )->entries(), 'n' ) );
	}

	/** A completion standing on a configured on-hold status refuses a new tender though on-hold is editable. */
	public function test_a_standing_on_hold_completion_takes_no_new_tender_until_reopened(): void {
		// Arrange: Cash's POS Order Status stored as On hold; 100.00 cash completes onto it; 20.00 refunded.
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array(
				'default_gateway' => 'pos_cash',
				'gateways'        => array(
					'pos_cash' => array(
						'enabled'      => true,
						'order_status' => 'wc-on-hold',
					),
					'pos_card' => array( 'enabled' => true ),
				),
			)
		);
		list( $order, $row ) = $this->paid_sale_with_lines( array( '100.00' ) );
		$this->assertSame( 'on-hold', $order->get_status() );
		$refund = $this->refund_of( $order, '20.00' );
		$this->assertIsArray( Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '20.00' ) );

		// Act.
		$refused = Ledger::instance()->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '20.00' ) );

		// Assert.
		$this->assertWPError( $refused );
		$this->assertSame( 'wcpos_order_already_paid', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );

		// Reopened, the 20.00 lands and completes the sale again.
		$reopened = wc_get_order( $order->get_id() );
		$reopened->set_status( 'pos-open' );
		$reopened->save();
		$taken = Ledger::instance()->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '20.00' ) );
		$this->assertIsArray( $taken );
		$this->assertSame( 'captured', $taken['status'] );
		$this->assertSame( array( 1, 2 ), array_column( Completion_History::of( wc_get_order( $order->get_id() ) )->entries(), 'n' ) );
	}

	/** A cancelled order takes no tender. */
	public function test_a_cancelled_order_takes_no_tender(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_status( 'cancelled' );
		$order->save();

		// Act.
		$result = Ledger::instance()->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '92.95' ) );

		// Assert.
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_invalid_transition', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->stored_rows( $order->get_id() ) );
	}

	/**
	 * With prevent-overselling on and one unit of stock, run $work with two open POS orders for it.
	 *
	 * @param callable $work Receives the two orders (a, b).
	 */
	private function with_one_unit_on_two_orders( callable $work ): void {
		$_SERVER['HTTP_X_WCPOS'] = '1';
		$settings                = get_option( 'woocommerce_pos_settings_checkout' );
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => true ) );
		try {
			$product = ProductHelper::create_simple_product();
			$product->set_regular_price( '10' );
			$product->set_price( '10' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 1 );
			$product->save();
			$orders = array();
			foreach ( array( 'a', 'b' ) as $key ) {
				$orders[ $key ] = wc_create_order();
				$orders[ $key ]->set_created_via( 'woocommerce-pos' );
				$orders[ $key ]->set_status( 'pos-open' );
				$orders[ $key ]->add_product( $product, 1 );
				$orders[ $key ]->calculate_totals( false );
				$orders[ $key ]->save();
			}
			$work( $orders['a'], $orders['b'] );
		} finally {
			unset( $_SERVER['HTTP_X_WCPOS'] );
			update_option( 'woocommerce_pos_settings_checkout', $settings );
		}
	}

	/**
	 * Order B's cash record for its total.
	 *
	 * @param \WC_Order $order Order B.
	 * @return array|\WP_Error
	 */
	private function record_full( \WC_Order $order ) {
		$order = wc_get_order( $order->get_id() );
		return Ledger::instance()->record( $order, $this->payment( 'pos_cash', $order->get_total() ) );
	}

	/** An intent the provider answers failed leaves nothing live, so the hold goes back to the shelf. */
	public function test_an_intent_answered_failed_releases_the_hold(): void {
		$this->with_one_unit_on_two_orders(
			function ( \WC_Order $a, \WC_Order $b ): void {
				// Act.
				$card = $this->payment( 'pos_card', $a->get_total() );
				Ledger::instance()->intent( $a, $card['id'], $card, array( 'resume' => array( 'status' => 'failed' ) ) );

				// Assert.
				$this->assertSame( 'failed', Ledger::instance()->find( wc_get_order( $a->get_id() ), $card['id'] )['status'] );
				$this->assertIsArray( $this->record_full( $b ) );
			}
		);
	}

	/** A determinate intent error deletes the row and releases the hold. */
	public function test_a_determinate_intent_error_releases_the_hold(): void {
		$this->with_one_unit_on_two_orders(
			function ( \WC_Order $a, \WC_Order $b ): void {
				// Act.
				$card = $this->payment( 'pos_card', $a->get_total() );
				Ledger::instance()->intent( $a, $card['id'], $card, array( 'error' => new \WP_Error( 'test_declined', 'Declined' ) ) );

				// Assert.
				$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $a->get_id() ) ) );
				$this->assertIsArray( $this->record_full( $b ) );
			}
		);
	}

	/** An indeterminate intent error leaves the row pending, and it keeps the hold. */
	public function test_an_indeterminate_intent_error_keeps_the_hold(): void {
		$this->with_one_unit_on_two_orders(
			function ( \WC_Order $a, \WC_Order $b ): void {
				// Act.
				$card = $this->payment( 'pos_card', $a->get_total() );
				Ledger::instance()->intent( $a, $card['id'], $card, array( 'error' => new \WP_Error( 'test_timeout', 'Timeout', array( 'indeterminate' => true ) ) ) );

				// Assert.
				$this->assertSame( 'pending', Ledger::instance()->find( wc_get_order( $a->get_id() ), $card['id'] )['status'] );
				$refused = $this->record_full( $b );
				$this->assertWPError( $refused );
				$this->assertSame( 'wcpos_insufficient_stock', $refused->get_error_code() );
			}
		);
	}

	/** The approval time survives capture; captured_at_gmt alone could not tell them apart. */
	public function test_authorized_at_gmt_is_stamped_on_approval_and_kept_through_capture(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$cash  = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '10.00' ) );
		$this->assertNull( $cash['authorized_at_gmt'] );

		// Act.
		$card = Ledger::instance()->record( $order, $this->payment( 'pos_card', '82.95', array( 'status' => 'authorized' ) ) );
		$approved = $card['authorized_at_gmt'];
		// A capture answer that carries no approval time (here an explicit null) must not erase it.
		$captured = Ledger::instance()->apply_result(
			$order,
			$card['id'],
			array(
				'status' => 'captured',
				'captured_at_gmt' => '2030-01-01T00:00:00+00:00',
				'authorized_at_gmt' => null,
			)
		);

		// Assert.
		$this->assertNotNull( $approved );
		$this->assertSame( $approved, $captured['authorized_at_gmt'] );
		$this->assertSame( '2030-01-01T00:00:00+00:00', $captured['captured_at_gmt'] );
		$this->assertNotSame( $approved, $captured['captured_at_gmt'] );
	}

	/** An offline authorization keeps the device's approval time: a valid caller value wins over the server clock. */
	public function test_record_of_an_authorized_row_keeps_the_callers_authorized_at_gmt(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment(
			'pos_card',
			'92.95',
			array(
				'status'            => 'authorized',
				'authorized_at_gmt' => '2026-10-13T08:00:00Z',
			)
		);

		// Act.
		$row = Ledger::instance()->record( $order, $payment );

		// Assert: the returned row and the stored row carry the device's instant, not a server stamp.
		$this->assertIsArray( $row );
		$stored = $this->stored_rows( $order->get_id() );
		$this->assertCount( 1, $stored );
		foreach ( array( $row['authorized_at_gmt'], $stored[0]['authorized_at_gmt'] ) as $authorized_at ) {
			$this->assertIsString( $authorized_at );
			$this->assertSame( strtotime( '2026-10-13T08:00:00Z' ), strtotime( $authorized_at ) );
			$this->assertFalse( time() - strtotime( $authorized_at ) <= 60 );
		}
	}

	/** An unparseable approval time is not stored; the server stamps the authorization instead. */
	public function test_record_of_an_authorized_row_with_an_invalid_authorized_at_gmt_is_stamped_by_the_server(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment(
			'pos_card',
			'92.95',
			array(
				'status'            => 'authorized',
				'authorized_at_gmt' => 'not-a-time',
			)
		);

		// Act.
		$row = Ledger::instance()->record( $order, $payment );

		// Assert.
		$this->assertIsArray( $row );
		$stored = $this->stored_rows( $order->get_id() );
		$this->assertCount( 1, $stored );
		foreach ( array( $row['authorized_at_gmt'], $stored[0]['authorized_at_gmt'] ) as $authorized_at ) {
			$this->assertIsString( $authorized_at );
			$this->assertNotFalse( strtotime( $authorized_at ) );
			$this->assertTrue( abs( time() - strtotime( $authorized_at ) ) <= 60 );
		}
	}

	/** A captured record was never on hold: a caller's approval time is dropped. */
	public function test_record_of_a_captured_row_drops_a_caller_authorized_at_gmt(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_card', '92.95', array( 'authorized_at_gmt' => '2026-10-13T08:00:00Z' ) );

		// Act.
		$row = Ledger::instance()->record( $order, $payment );

		// Assert.
		$this->assertIsArray( $row );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( null, $row['authorized_at_gmt'] );
		$stored = $this->stored_rows( $order->get_id() );
		$this->assertCount( 1, $stored );
		$this->assertSame( null, $stored[0]['authorized_at_gmt'] );
	}

	/** A paid order records and returns a stable refusal. */
	public function test_record_refuses_overpay_when_balance_is_zero(): void {
		// Arrange.
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95' ) );

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '10.00' ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_order_already_paid', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( 'failed', $error->get_error_data()['payment']['status'] );
		$this->assertSame( 'order_already_paid', $error->get_error_data()['payment']['failure_reason'] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( $order ) );
	}

	/** A paid legacy order without a ledger cannot accept another tender. */
	public function test_record_refuses_payment_for_paid_order_without_ledger(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->set_status( 'completed' );
		$order->save();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95' ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_order_already_paid', $error->get_error_code() );
		$this->assertSame( '0.00', $error->get_error_data()['order']['balance'] );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/** Amounts above the current balance are refused and retained. */
	public function test_record_refuses_amount_above_balance(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '100.00' ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_amount_exceeds_balance', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( 'failed', $error->get_error_data()['payment']['status'] );
	}

	/** Identical request IDs replay without another ledger row. */
	public function test_record_replay_with_same_id_returns_stored_row_without_duplicate(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '92.95' );

		// Act.
		$first  = Ledger::instance()->record( $order, $input );
		$second = Ledger::instance()->record( $order, $input );

		// Assert.
		$this->assertSame( $first, $second );
		$this->assertCount( 1, Ledger::instance()->read( $order ) );
		$this->assertSame( array( 'pos_cash' ), $this->index_values( $order ) );
	}

	/** Reusing a request ID for different money conflicts. */
	public function test_record_replay_with_different_amount_returns_conflict(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '92.95' );
		Ledger::instance()->record( $order, $input );

		// Act.
		$error = Ledger::instance()->record( $order, array_merge( $input, array( 'amount' => '90.00' ) ) );

		// Assert: one code across the command family for the same id with another body (§7 decision 12).
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_command_conflict', $error->get_error_code() );
		$this->assertSame( 422, $error->get_error_data()['status'] );
	}

	/** Pin 21: Pro's callers pass no command, and its rows keep an empty command map. */
	public function test_refund_and_void_without_a_command_store_no_entry(): void {
		// Arrange: a captured cash row, refundable, and a pending one, voidable.
		$order    = $this->create_pos_order();
		$captured = $this->payment( 'pos_cash', '50.00', array( 'status' => 'captured', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		$pending  = $this->payment( 'pos_cash', '10.00', array( 'status' => 'pending', 'kind' => 'cash', 'capture_mode' => 'manual' ) );
		Ledger::instance()->save( $order, array( $captured, $pending ), false );
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => '5.00',
				'refund_payment' => false,
			)
		);

		// Act: called as Pro calls them, with no command.
		$refunded = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $captured['id'], $refund->get_id(), '5.00' );
		$voided   = Ledger::instance()->void( wc_get_order( $order->get_id() ), $pending['id'], '' );

		// Assert.
		$this->assertSame( 'succeeded', $refunded['refunds'][0]['status'] );
		$this->assertSame( 'voided', $voided['status'] );
		$stored = wc_get_order( $order->get_id() );
		foreach ( $stored->get_data_store()->read_meta( $stored ) as $meta ) {
			if ( Ledger::META_KEY === $meta->meta_key ) {
				foreach ( json_decode( (string) maybe_unserialize( $meta->meta_value ), true )['payments'] as $row ) {
					$this->assertSame( array(), $row['commands'] );
				}
			}
		}
	}

	/** A stored UUID replay does not depend on the gateway's current capture mode. */
	public function test_record_replay_returns_stored_row_after_capture_mode_changes(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_card', '20.00' );
		$first = Ledger::instance()->record( $order, $input );
		$filter = static function ( string $mode, $gateway ): string {
			return 'pos_card' === $gateway->id ? 'webview' : $mode;
		};
		add_filter( 'wcpos_payment_method_capture_mode', $filter, 10, 2 );

		try {
			// Act.
			$replay = Ledger::instance()->record( $order, $input );

			// Assert.
			$this->assertSame( $first, $replay );
			$this->assertCount( 1, Ledger::instance()->read( $order ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter, 10 );
		}
	}

	/** Only manual and offline-recordable methods may use the record route. */
	public function test_record_rejects_method_that_cannot_be_recorded(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$filter = static function ( string $mode, $gateway ): string {
			return 'pos_card' === $gateway->id ? 'webview' : $mode;
		};
		add_filter( 'wcpos_payment_method_capture_mode', $filter, 10, 2 );

		try {
			// Act.
			$error = Ledger::instance()->record( $order, $this->payment( 'pos_card', '20.00' ) );

			// Assert.
			$this->assertWPError( $error );
			$this->assertSame( 'wcpos_method_not_recordable', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
			$this->assertSame( array(), Ledger::instance()->read( $order ) );
		} finally {
			remove_filter( 'wcpos_payment_method_capture_mode', $filter, 10 );
		}
	}

	/** Structured amounts return a validation error instead of reaching the formatter. */
	public function test_record_rejects_non_scalar_amount(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$input = $this->payment( 'pos_cash', '92.95' );
		$input['amount'] = array( '92.95' );

		// Act.
		$error = Ledger::instance()->record( $order, $input );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/** Structured tendered values return the same validation error as other invalid tender input. */
	public function test_record_rejects_non_scalar_tendered_amount(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '92.95', array( 'tendered' => array( '100.00' ) ) ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/** The manual record route always stores its server-owned app source. */
	public function test_record_ignores_client_source(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00', array( 'source' => 'webview' ) ) );

		// Assert.
		$this->assertSame( 'app', $row['source'] );
	}

	/** Tendered is cash-only. */
	public function test_record_rejects_tendered_on_non_cash_kind(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$error = Ledger::instance()->record( $order, $this->payment( 'pos_card', '20.00', array( 'tendered' => '20.00' ) ) );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'rest_invalid_param', $error->get_error_code() );
	}

	/** Voiding the only pending row reopens the order. */
	public function test_void_pending_row_returns_order_to_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'pos_cash',
			'10.00',
			array(
				'status' => 'pending',
				'kind' => 'cash',
				'capture_mode' => 'manual',
			)
		);
		Ledger::instance()->save( $order, array( $row ) );
		$this->assertSame( 'pending', $order->get_status() );

		// Act.
		$voided = Ledger::instance()->void( $order, $row['id'], 'Customer cancelled' );

		// Assert.
		$this->assertSame( 'voided', $voided['status'] );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( array(), $this->index_values( $order ) );
	}

	/** Cancelling a split mid-way voids a captured cash row: the cashier hands the cash back. */
	public function test_void_captured_manual_row_returns_order_to_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( 'pos-partial', $order->get_status() );

		// Act.
		$voided = Ledger::instance()->void( $order, $row['id'], 'Customer cancelled' );

		// Assert.
		$this->assertSame( 'voided', $voided['status'] );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( array(), $this->index_values( $order ) );
	}

	/** Once the order has completed, a captured cash leg is refunded, never voided. */
	public function test_void_captured_manual_row_on_completed_order_is_invalid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = Ledger::instance()->record( $order, $this->payment( 'pos_cash', (string) $order->get_total() ) );
		$this->assertTrue( $order->is_paid() );

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Too late' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( 'captured', Ledger::instance()->find( $order, $row['id'] )['status'] );
	}

	/** Money captured at a provider is refunded, never voided. */
	public function test_void_captured_provider_row_is_invalid_transition(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'stripe_terminal',
			'20.00',
			array(
				'status' => 'captured',
				'kind' => 'card',
				'capture_mode' => 'device',
			)
		);
		Ledger::instance()->save( $order, array( $row ) );

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Too late' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/** A pending row projects the WooCommerce pending status. */
	public function test_status_projection_pending_row_flips_order_to_pending(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'pos_card',
			'20.00',
			array(
				'status' => 'pending',
				'kind' => 'card',
				'capture_mode' => 'manual',
			)
		);

		// Act.
		Ledger::instance()->save( $order, array( $row ) );

		// Assert.
		$this->assertSame( 'pending', $order->get_status() );
	}

	/** Every live split method is written as an index value. */
	public function test_index_meta_matches_any_leg_of_a_split(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '30.00' ) );
		Ledger::instance()->record( $order, $this->payment( 'pos_card', '62.95' ) );

		// Assert.
		$this->assertContains( 'pos_cash', $this->index_values( $order ) );
		$this->assertContains( 'pos_card', $this->index_values( $order ) );
	}

	/** Corrupt ledger JSON is treated as an empty ledger. */
	public function test_read_ignores_corrupt_ledger_meta(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->update_meta_data( Ledger::META_KEY, 'not json' );
		$order->save();

		// Act / Assert.
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	/** The app writes the ledger as a typed-meta object; an already-decoded array reads the same as JSON. */
	public function test_read_accepts_array_form_ledger_meta(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = array(
			'id'           => 'a1b2c3d4-0000-4000-8000-000000000001',
			'method_id'    => 'pos_cash',
			'kind'         => 'cash',
			'capture_mode' => 'manual',
			'amount'       => '10.00',
			'status'       => 'captured',
		);
		$order->update_meta_data(
			Ledger::META_KEY,
			array(
				'schema' => Ledger::SCHEMA,
				'payments' => array( $row ),
			)
		);
		$order->save();

		// Act.
		$rows = Ledger::instance()->read( $order );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pos_cash', $rows[0]['method_id'] );
	}

	/** A stored row without a capture mode returns the contract error from status. */
	public function test_status_rejects_row_without_capture_mode(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'status' => 'pending' ) );
		unset( $row['capture_mode'] );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => array( $row ),
				)
			)
		);
		$order->save();

		// Act.
		$error = Ledger::instance()->status( $order, $row['id'] );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $error->get_error_code() );
		$this->assertSame( 501, $error->get_error_data()['status'] );
	}

	/** A stored row without a status cannot be voided. */
	public function test_void_rejects_row_without_status(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'capture_mode' => 'manual' ) );
		unset( $row['status'] );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => array( $row ),
				)
			)
		);
		$order->save();

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Malformed row' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_invalid_transition', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/** A voidable stored row without a capture mode returns the contract error. */
	public function test_void_rejects_row_without_capture_mode(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment( 'pos_card', '20.00', array( 'status' => 'pending' ) );
		unset( $row['capture_mode'] );
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema' => Ledger::SCHEMA,
					'payments' => array( $row ),
				)
			)
		);
		$order->save();

		// Act.
		$error = Ledger::instance()->void( $order, $row['id'], 'Malformed row' );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $error->get_error_code() );
		$this->assertSame( 501, $error->get_error_data()['status'] );
	}

	/** Pending and succeeded allocations reserve balance; failed ones do not. */
	public function test_available_for_refund_counts_pending_and_succeeded_allocations(): void {
		// Arrange.
		$row = array(
			'amount'  => '20.00',
			'refunds' => array(
				array(
					'amount' => '5.00',
					'status' => 'pending',
				),
				array(
					'amount' => '3.00',
					'status' => 'succeeded',
				),
				array(
					'amount' => '4.00',
					'status' => 'failed',
				),
			),
		);
		// Act / Assert.
		$this->assertSame( 1200, Ledger::available_for_refund( $row ) );
		$row['refunds'][0]['amount'] = '15.00';
		$row['refunds'][1]['amount'] = '10.00';
		$this->assertSame( 0, Ledger::available_for_refund( $row ) );
		unset( $row['refunds'] );
		$this->assertSame( 2000, Ledger::available_for_refund( $row ) );
	}

	/** Settlement persists the final allocation and notifies listeners with its amount. */
	public function test_settle_allocation_final_answer_updates_refunded_amount_and_fires_allocated(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->seed_settlement_allocation();
		$calls = array();
		$listener = static function ( $order, $row, $refund, $amount ) use ( &$calls ) {
			$calls[] = array( $order, $row, $refund, $amount );
		};
		add_action( 'wcpos_refund_allocated', $listener, 10, 4 );
		$events = array(
			array(
				't'       => gmdate( 'c' ),
				'level'   => 'info',
				'message' => 'settled',
			),
		);
		// Act.
		Ledger::instance()->settle_allocation( $order, $row['id'], $refund->get_id(), 'succeeded', 'ref_1', $events );
		remove_action( 'wcpos_refund_allocated', $listener, 10 );
		// Assert.
		$saved = Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] );
		$this->assertSame(
			array(
				'id'           => $refund->get_id(),
				'amount'       => '15.00',
				'status'       => 'succeeded',
				'provider_ref' => 'ref_1',
			),
			$saved['refunds'][0]
		);
		$this->assertSame( '15.00', $saved['refunded_amount'] );
		$this->assertSame( 'settled', end( $saved['events'] )['message'] );
		$this->assertCount( 1, $calls );
		$this->assertSame( $order, $calls[0][0] );
		$this->assertSame( $refund->get_id(), $calls[0][2]->get_id() );
		$this->assertSame( '15.00', $calls[0][3] );
		$this->assertSame( 'succeeded', $calls[0][1]['refunds'][0]['status'] );
	}

	/** A failed answer releases the reservation and preserves a known reference. */
	public function test_settle_allocation_failed_answer_releases_the_reservation_and_fires_allocated(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->seed_settlement_allocation( 'pending', 'ref_seed' );
		$before = did_action( 'wcpos_refund_allocated' );
		// Act.
		Ledger::instance()->settle_allocation( $order, $row['id'], $refund->get_id(), 'failed', null );
		// Assert.
		$saved = Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] );
		$this->assertSame( 'failed', $saved['refunds'][0]['status'] );
		$this->assertSame( 'ref_seed', $saved['refunds'][0]['provider_ref'] );
		$this->assertSame( '0.00', $saved['refunded_amount'] );
		$this->assertSame( 2000, Ledger::available_for_refund( $saved ) );
		$this->assertSame( $before + 1, did_action( 'wcpos_refund_allocated' ) );
	}

	/** A pending answer fills a reference only once and never fires the allocation hook. */
	public function test_settle_allocation_pending_answer_records_a_missing_reference_only(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->seed_settlement_allocation();
		$ledger = Ledger::instance();
		$before = did_action( 'wcpos_refund_allocated' );
		// Act / Assert.
		$this->assertSame( $row, $ledger->settle_allocation( $order, $row['id'], $refund->get_id(), 'pending', null ) );
		$this->assertSame( $row, $ledger->find( wc_get_order( $order->get_id() ), $row['id'] ) );
		$ledger->settle_allocation( $order, $row['id'], $refund->get_id(), 'pending', 'ref_late' );
		$saved = $ledger->find( wc_get_order( $order->get_id() ), $row['id'] );
		$this->assertSame( 'ref_late', $saved['refunds'][0]['provider_ref'] );
		$this->assertSame( 'pending', $saved['refunds'][0]['status'] );
		$ledger->settle_allocation( $order, $row['id'], $refund->get_id(), 'pending', 'ref_other' );
		$this->assertSame( $saved, $ledger->find( wc_get_order( $order->get_id() ), $row['id'] ) );
		$this->assertSame( $before, did_action( 'wcpos_refund_allocated' ) );
	}

	/** Settled and missing allocations are no-ops; invalid requests return contract errors. */
	public function test_settle_allocation_on_a_settled_or_unknown_allocation_changes_nothing(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->seed_settlement_allocation( 'succeeded' );
		$ledger = Ledger::instance();
		$before = did_action( 'wcpos_refund_allocated' );
		// Act / Assert.
		$this->assertSame( $row, $ledger->settle_allocation( $order, $row['id'], $refund->get_id(), 'failed', 'x' ) );
		$this->assertSame( $row, $ledger->settle_allocation( $order, $row['id'], 0, 'succeeded' ) );
		$this->assertSame( 'wcpos_payment_not_found', $ledger->settle_allocation( $order, wp_generate_uuid4(), $refund->get_id(), 'succeeded' )->get_error_code() );
		$this->assertSame( 'rest_invalid_param', $ledger->settle_allocation( $order, $row['id'], $refund->get_id(), 'refunded' )->get_error_code() );
		$this->assertSame( $row, $ledger->find( wc_get_order( $order->get_id() ), $row['id'] ) );
		$this->assertSame( $before, did_action( 'wcpos_refund_allocated' ) );
	}

	/** Adoption normalizes identity, indexes the leg and refuses duplicates. */
	public function test_adopt_appends_a_row_and_refuses_an_existing_id(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$uuid = wp_generate_uuid4();
		$status = $order->get_status();
		$input = $this->payment(
			'pos_card',
			'92.95',
			array(
				'id'           => strtoupper( $uuid ),
				'capture_mode' => 'server',
				'status'       => 'pending',
			)
		);
		// Act.
		$row = $ledger->adopt( $order, $input, false );
		// Assert.
		$this->assertSame( $uuid, $row['id'] );
		$this->assertCount( 1, $ledger->read( $order ) );
		$this->assertContains( $uuid, wp_list_pluck( $order->get_meta( Ledger::LIVE_LEG_META_KEY, false ), 'value' ) );
		$this->assertSame( $status, wc_get_order( $order->get_id() )->get_status() );
		$error = $ledger->adopt( $order, $input );
		$this->assertSame( 'wcpos_payment_conflict', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertCount( 1, $ledger->read( $order ) );
		$input['id'] = 'not-a-uuid';
		$this->assertSame( 'rest_invalid_param', $ledger->adopt( $order, $input )->get_error_code() );
	}

	/** Adoption saves the caller's staged meta in its single order write. */
	public function test_adopt_writes_the_order_once_with_the_callers_staged_meta(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$order->update_meta_data( '_wcpos_test_staged', 'yes' );
		$count = 0;
		$listener = static function ( $saving ) use ( $order, &$count ) {
			if ( $saving->get_id() === $order->get_id() ) {
				++$count;
			}
		};
		add_action( 'woocommerce_before_order_object_save', $listener );
		// Act.
		Ledger::instance()->adopt( $order, $this->payment( 'pos_card', '92.95', array( 'status' => 'captured' ) ), false );
		remove_action( 'woocommerce_before_order_object_save', $listener );
		// Assert.
		$this->assertSame( 1, $count );
		$this->assertSame( 'yes', wc_get_order( $order->get_id() )->get_meta( '_wcpos_test_staged' ) );
	}

	/** Appending events caps the target log without restamping an untouched sibling. */
	public function test_append_events_appends_to_one_leg_and_keeps_the_newest(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$rows = $ledger->read( $order );
		$stamp = gmdate( 'c', time() - 3600 );
		$rows[0]['updated_at_gmt'] = $stamp;
		$rows[1]['updated_at_gmt'] = $stamp;
		$order->update_meta_data(
			Ledger::META_KEY,
			wp_json_encode(
				array(
					'schema'   => Ledger::SCHEMA,
					'payments' => $rows,
				)
			)
		);
		$order->save_meta_data(); // Seed server-owned stamps directly; an order save keeps the stored ones.
		$events = array();
		for ( $i = 0; $i < Ledger::EVENTS_MAX + 5; ++$i ) {
			$events[] = array( 'message' => 'e' . $i );
		}
		// Act.
		$ledger->append_events( $order, $rows[0]['id'], $events );
		// Assert.
		$saved = $ledger->read( wc_get_order( $order->get_id() ) );
		$this->assertCount( Ledger::EVENTS_MAX, $saved[0]['events'] );
		$this->assertSame( 'e5', $saved[0]['events'][0]['message'] );
		$this->assertSame( 'e' . ( Ledger::EVENTS_MAX + 4 ), end( $saved[0]['events'] )['message'] );
		$this->assertSame( $stamp, $saved[1]['updated_at_gmt'] );
		$ledger->append_events( $order, wp_generate_uuid4(), $events );
		$ledger->append_events( $order, $rows[0]['id'], array() );
		$this->assertSame( $saved, $ledger->read( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * Seed a real refund and its allocation for settlement tests.
	 *
	 * @param string      $status Allocation status.
	 * @param string|null $ref    Existing provider reference.
	 * @return array Order, saved payment row and refund.
	 */
	private function seed_settlement_allocation( string $status = 'pending', ?string $ref = null ): array {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$row = $ledger->record( $order, $this->payment( 'pos_cash', '20.00' ) );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( '20.00' );
		$refund->save();
		$row['refunds'] = array(
			array(
				'id'           => $refund->get_id(),
				'amount'       => '15.00',
				'status'       => $status,
				'provider_ref' => $ref,
			),
		);
		$row['refunded_amount'] = 'succeeded' === $status ? '15.00' : '0.00';
		$ledger->save( $order, array( $row ) );
		return array( $order, $ledger->find( $order, $row['id'] ), $refund );
	}

	/** The multi-valued index meta as a plain list of method ids, in write order. */
	private function index_values( \WC_Order $order ): array {
		return array_values( wp_list_pluck( $order->get_meta( Ledger::INDEX_META_KEY, false ), 'value' ) );
	}

	/** Create an open WCPOS order with the contract test total. */
	/** @dataProvider persistence_storage */
	public function test_failed_ledger_save_releases_keeper_locks_but_preserves_outer_lock( bool $hpos ): void {
		if ( $hpos ) { add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); $this->setup_cot(); $this->toggle_cot_feature_and_usage( true ); $this->disable_cot_sync(); }
		$order = $this->create_pos_order();
		$lock = Order_Lock::instance();
		$competitor = new Order_Lock();
		$lease = static fn( $sql ) => 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $sql;
		$refuse = static function ( $saving ) use ( $order ): void {
			if ( $saving->get_id() === $order->get_id() ) { throw new \RuntimeException( 'Save interrupted after keeper acquisition' ); }
		};
		add_filter( 'query', $lease );
		add_action( 'woocommerce_before_order_object_save', $refuse, PHP_INT_MAX );
		try {
			$this->assertTrue( $lock->acquire( $order->get_id() ) );
			try {
				Ledger::instance()->adopt( $order, $this->payment( 'pos_cash', '5' ) );
				$this->fail( 'Interrupted ledger save returned.' );
			} catch ( \RuntimeException $error ) {
				$this->assertInstanceOf( \WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception::class, $error );
			}
			$this->assertFalse( $competitor->acquire( $order->get_id() ), 'The caller still owns its outer lock.' );
			$lock->release( $order->get_id() );
			$this->assertFalse( get_option( 'wcpos_payment_lock_' . $order->get_id() ), 'Failed save leaked an option lease.' );
			$this->assertTrue( $competitor->acquire( $order->get_id() ), 'Failed save stranded a reentrant acquisition.' );
		} finally {
			$competitor->release( $order->get_id() );
			remove_filter( 'query', $lease );
			remove_action( 'woocommerce_before_order_object_save', $refuse, PHP_INT_MAX );
			if ( $hpos ) { $this->toggle_cot_feature_and_usage( false ); $this->clean_up_cot_setup(); remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); }
		}
	}

	/** @dataProvider persistence_storage */
	public function test_locked_save_uses_fresh_total_and_refreshes_caller( bool $hpos ): void {
		if ( $hpos ) { add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); $this->setup_cot(); $this->toggle_cot_feature_and_usage( true ); $this->disable_cot_sync(); }
		try {
			$ledger = Ledger::instance();
			$order = $this->create_pos_order();
			$order->set_total( '100' );
			$attempt = wp_generate_uuid4();
			$order->update_meta_data( '_wcpos_gateway_attempts', array( $attempt => 'sent' ) );
			$order->save();
			$stale = wc_get_order( $order->get_id() );
			$stale->update_meta_data( '_wcpos_test_staged', 'kept' );
			$fresh = wc_get_order( $order->get_id() );
			$fresh->set_total( '200' );
			$fresh->save();

			$row = $ledger->adopt( $stale, $this->payment( 'pos_cash', '100', array( 'status' => 'captured' ) ) );

			$saved = wc_get_order( $order->get_id() );
			$this->assertSame( 'pos-partial', $saved->get_status() );
			$this->assertNull( $saved->get_date_paid() );
			$this->assertSame( 'sent', $saved->get_meta( '_wcpos_gateway_attempts' )[ $attempt ] );
			$this->assertSame( 'kept', $saved->get_meta( '_wcpos_test_staged' ) );
			$this->assertSame( '200.00', $stale->get_total() );
			$this->assertSame( $saved->get_status(), $stale->get_status() );
			$this->assertSame( $row, $ledger->find( $stale, $row['id'] ) );
		} finally {
			if ( $hpos ) { $this->toggle_cot_feature_and_usage( false ); $this->clean_up_cot_setup(); remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); }
		}
	}

	/** @dataProvider persistence_storage */
	public function test_stale_mint_rebases_an_existing_provider_updated_row( bool $hpos ): void {
		if ( $hpos ) { add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); $this->setup_cot(); $this->toggle_cot_feature_and_usage( true ); $this->disable_cot_sync(); }
		try {
			$ledger = Ledger::instance();
			$order = $this->create_pos_order();
			$order->set_payment_method( 'pos_cash' );
			$order->save();
			$ledger->save( $order, array( $this->payment( 'pos_cash', '20', array( 'status' => 'failed' ) ) ), false );
			$stale = wc_get_order( $order->get_id() );
			$rows = $ledger->read( $stale );
			$rows[0]['status'] = 'captured';
			$rows[0]['refunded_amount'] = '3.00';
			$rows[0]['refunds'] = array( array( 'id' => 123, 'status' => 'succeeded', 'amount' => '3.00' ) );
			$rows[0]['events'] = array( array( 'type' => 'refunded' ) );
			$rows[0]['seen_events'] = array( 'refund-123' );
			$rows[0]['provider_refs'] = array( 'transaction_id' => 'fresh' );
			$lock = Order_Lock::instance();
			$this->assertTrue( $lock->acquire( $order->get_id() ) );
			try { $ledger->save( wc_get_order( $order->get_id() ), $rows, false ); }
			finally { $lock->release( $order->get_id() ); }
			$fresh_row = $ledger->read( wc_get_order( $order->get_id() ) )[0];

			$minted = \WCPOS\WooCommercePOS\Payments\Contract\Webview_Passthrough::mint( $stale );

			$saved = wc_get_order( $order->get_id() );
			$this->assertIsArray( $minted );
			$this->assertCount( 2, $ledger->read( $saved ) );
			$this->assertSame( $fresh_row, $ledger->find( $saved, $fresh_row['id'] ) );
		} finally {
			if ( $hpos ) { $this->toggle_cot_feature_and_usage( false ); $this->clean_up_cot_setup(); remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); }
		}
	}

	/** @dataProvider persistence_storage */
	public function test_rebase_keeps_caller_edits_and_fresh_server_fields( bool $hpos ): void {
		if ( $hpos ) { add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); $this->setup_cot(); $this->toggle_cot_feature_and_usage( true ); $this->disable_cot_sync(); }
		try {
			$ledger = Ledger::instance();
			$order = $this->create_pos_order();
			$ledger->save( $order, array( $this->payment( 'pos_cash', '20', array( 'status' => 'captured' ) ) ), false );
			$read_rows = $ledger->read( $order );
			$fresh = $read_rows;
			$fresh[0]['refunded_amount'] = '3.00';
			$fresh[0]['refunds'] = array( array( 'id' => 123, 'status' => 'succeeded', 'amount' => '3.00' ) );
			$fresh[0]['events'] = array( array( 'type' => 'refunded' ) );
			$fresh[0]['seen_events'] = array( 'refund-123' );
			$ledger->save( wc_get_order( $order->get_id() ), $fresh, false );
			$changed = $read_rows;
			$changed[0]['receipt'] = array( 'reference' => 'caller edit' );

			$ledger->save( $order, $changed, false, array(), $read_rows );

			$saved = $ledger->read( wc_get_order( $order->get_id() ) )[0];
			$this->assertSame( $changed[0]['receipt'], $saved['receipt'] );
			foreach ( array( 'refunded_amount', 'refunds', 'events', 'seen_events' ) as $key ) {
				$this->assertSame( $fresh[0][ $key ], $saved[ $key ], $key );
			}
			// An explicit server-field edit remains authoritative, not overwritten by the rebase.
			$changed[0]['refunded_amount'] = '4.00';
			$changed[0]['refunds'][0] = array( 'id' => 124, 'status' => 'succeeded', 'amount' => '4.00' );
			$ledger->save( $order, $changed, false, array(), $read_rows );
			$saved = $ledger->read( wc_get_order( $order->get_id() ) )[0];
			$this->assertSame( '4.00', $saved['refunded_amount'] );
			$this->assertSame( $changed[0]['refunds'], $saved['refunds'] );
		} finally {
			if ( $hpos ) { $this->toggle_cot_feature_and_usage( false ); $this->clean_up_cot_setup(); remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' ); }
		}
	}

	/** @dataProvider persistence_storage */
	public function test_stale_webview_save_keeps_a_concurrently_recorded_row( bool $hpos ): void {
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->toggle_cot_feature_and_usage( true );
			$this->disable_cot_sync();
		}
		try {
			$ledger = Ledger::instance();
			$order = $this->create_pos_order();
			$order->set_payment_method( 'pos_cash' );
			$order->update_meta_data( '_wcpos_test_update', 'old' );
			$order->update_meta_data( '_wcpos_test_delete', 'old' );
			$order->save();
			$stale = wc_get_order( $order->get_id() );
			$this->assertSame( array(), $ledger->read( $stale ) );
			$stale->update_meta_data( '_wcpos_test_staged', 'kept' );
			$stale->update_meta_data( '_wcpos_test_update', 'new' );
			$stale->delete_meta_data( '_wcpos_test_delete' );
			$recorded = $ledger->record( wc_get_order( $order->get_id() ), $this->payment( 'pos_cash', '5.00' ) );

			$minted = \WCPOS\WooCommercePOS\Payments\Contract\Webview_Passthrough::mint( $stale );

			$saved = wc_get_order( $order->get_id() );
			$this->assertCount( 2, $ledger->read( $saved ) );
			$this->assertSame( $recorded, $ledger->find( $saved, $recorded['id'] ) );
			$this->assertSame( $minted['id'], $ledger->find( $saved, $minted['id'] )['id'] );
			$this->assertSame( $ledger->read( $saved ), $ledger->read( $stale ) );
			$this->assertSame( 'kept', $saved->get_meta( '_wcpos_test_staged' ) );
			$this->assertSame( 'new', $saved->get_meta( '_wcpos_test_update' ) );
			$this->assertSame( '', $saved->get_meta( '_wcpos_test_delete' ) );
		} finally {
			if ( $hpos ) {
				$this->toggle_cot_feature_and_usage( false );
				$this->clean_up_cot_setup();
				remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			}
		}
	}

	/** @dataProvider persistence_storage */
	public function test_record_refused_save_throws_without_returning_an_unstored_row( bool $hpos ): void {
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->toggle_cot_feature_and_usage( true );
			$this->disable_cot_sync();
		}
		$lock = new Order_Lock();
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$ledger->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$before = $ledger->read( wc_get_order( $order->get_id() ) );
		$payment = $this->payment( 'pos_cash', '5.00' );
		try {
			$this->assertTrue( $lock->acquire( $order->get_id() ) );
			$error = null;
			try {
				$ledger->record( $order, $payment );
			} catch ( \RuntimeException $caught ) {
				$error = $caught;
			}
			$this->assertSame( $before, $ledger->read( wc_get_order( $order->get_id() ) ) );
			$this->assertNotNull( $error, 'record() returned despite a refused save.' );
			$this->assertInstanceOf( \WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception::class, $error );
			$lock->release( $order->get_id() );
			$row = $ledger->record( wc_get_order( $order->get_id() ), $payment );
			$this->assertSame( $payment['id'], $row['id'] );
			$this->assertCount( 2, $ledger->read( wc_get_order( $order->get_id() ) ) );
		} finally {
			$lock->release( $order->get_id() );
			if ( $hpos ) {
				$this->toggle_cot_feature_and_usage( false );
				$this->clean_up_cot_setup();
				remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			}
		}
	}

	public function persistence_storage(): array {
		return array( 'CPT' => array( false ), 'HPOS' => array( true ) );
	}

	public function test_dirty_ledger_save_is_refused_while_another_owner_holds_lock(): void {
		$order = $this->create_pos_order();
		Ledger::instance()->record( $order, $this->payment( 'pos_cash', '5.00' ) );
		$before = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$lock = new Order_Lock();
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array() ) );
			$order->set_customer_note( 'Must wait' );
			$order->save();
			$this->assertSame( $before, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_customer_note() );
			// Reading the refusal also clears it, so it cannot leak to a later order that reuses this id.
			$this->assertTrue( Ledger::save_was_refused( $order->get_id() ) );
		} finally {
			$lock->release( $order->get_id() );
		}
		$order->save();
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 'Must wait', wc_get_order( $order->get_id() )->get_customer_note() );
	}

	/** @dataProvider duplicate_order_lanes */
	public function test_order_save_drops_duplicate_payment_ids( bool $existing ): void {
		$order = $existing ? wc_create_order() : new \WC_Order();
		$row = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'status' => 'captured', 'amount' => '50' );
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $row, array_merge( $row, array( 'amount' => '99' ) ) ) ) );
		$order->save();
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( '50', $rows[0]['amount'] );
		$this->assertSame( '50.00', Ledger::instance()->paid( $rows ) );
	}

	public function duplicate_order_lanes(): array {
		return array( array( false ), array( true ) );
	}

	/** @dataProvider duplicate_order_lanes */
	public function test_order_save_keeps_only_server_refund_allocations( bool $existing ): void {
		$order = $existing ? wc_create_order() : new \WC_Order();
		$allocation = array( 'id' => 123, 'amount' => '5.00', 'status' => 'succeeded', 'provider_ref' => null );
		$row = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'status' => 'captured', 'amount' => '50', 'refunded_amount' => '5.00', 'refunds' => array( $allocation ) );
		if ( $existing ) {
			$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $row ) ) );
			$order->save_meta_data();
		}
		$forged = $row;
		$forged['refunds'][] = array_merge( $allocation, array( 'id' => 456 ) );
		$forged['refunded_amount'] = '10.00';
		$new = $forged;
		$new['id'] = wp_generate_uuid4();
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $forged, $new ) ) );
		$order->save();
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$this->assertSame( $existing ? array( $allocation ) : array(), $rows[0]['refunds'] );
		$this->assertSame( $existing ? '5.00' : '0', $rows[0]['refunded_amount'] );
		$this->assertSame( array(), $rows[1]['refunds'] );
		$this->assertSame( '0', $rows[1]['refunded_amount'] );
	}

	/** @dataProvider duplicate_order_lanes */
	public function test_order_save_preserves_server_event_history( bool $existing ): void {
		$order = $existing ? wc_create_order() : new \WC_Order();
		$command  = wp_generate_uuid4();
		$commands = array( $command => array( 'fp' => 'sha256:stored', 'kind' => 'capture', 'ref' => null, 'at_gmt' => '2026-10-18T12:00:00Z' ) );
		$row = array( 'id' => wp_generate_uuid4(), 'status' => 'captured', 'amount' => '50', 'seen_events' => array( 'evt_1' ), 'events' => array( array( 'message' => 'Captured' ) ), 'commands' => $commands, 'updated_at_gmt' => '2026-10-18T12:00:00Z' );
		if ( $existing ) {
			$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $row ) ) );
			$order->save_meta_data();
		}
		// A client-seeded command entry would make a later real command a silent replay (ADR 0040 rule 5).
		$forged = array( wp_generate_uuid4() => array( 'fp' => 'sha256:forged', 'kind' => 'void', 'ref' => null, 'at_gmt' => '2000-01-01T00:00:00Z' ) );
		$pushed = array_merge( $row, array( 'seen_events' => array(), 'events' => array(), 'commands' => $forged, 'updated_at_gmt' => '2000-01-01T00:00:00Z' ) );
		$new = $row;
		$new['id'] = wp_generate_uuid4();
		$new['commands'] = $forged;
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $pushed, $new ) ) );
		$order->save();
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$this->assertSame( $existing ? $row['seen_events'] : array(), $rows[0]['seen_events'] );
		$this->assertSame( $existing ? $row['events'] : array(), $rows[0]['events'] );
		$this->assertSame( $existing ? $commands : array(), $rows[0]['commands'] );
		$this->assertSame( $existing ? $row['updated_at_gmt'] : null, $rows[0]['updated_at_gmt'] ?? null );
		$this->assertSame( array(), $rows[1]['seen_events'] );
		$this->assertSame( array(), $rows[1]['events'] );
		$this->assertSame( array(), $rows[1]['commands'] );
		$this->assertArrayNotHasKey( 'updated_at_gmt', $rows[1] );
	}

	/** @dataProvider duplicate_restore_paths */
	public function test_order_save_restores_closed_duplicate_rows_only_once( bool $delete ): void {
		$order = wc_create_order();
		$row = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'status' => 'captured', 'amount' => '50', 'closure_id' => wp_generate_uuid4() );
		$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array( $row, $row ) ) );
		$order->save_meta_data(); // Historical duplicates, before the keeper enforced uniqueness.
		if ( $delete ) {
			$order->delete_meta_data( Ledger::META_KEY );
		} else {
			$order->update_meta_data( Ledger::META_KEY, array( 'schema' => 1, 'payments' => array() ) );
		}
		$order->save();
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $row['closure_id'], $rows[0]['closure_id'] );
		$this->assertSame( '50.00', Ledger::instance()->paid( $rows ) );
	}

	public function duplicate_restore_paths(): array {
		return array( array( false ), array( true ) );
	}

	public function test_intent_decline_removes_only_its_row_after_another_payment_arrives(): void {
		$order = $this->create_pos_order();
		$ledger = Ledger::instance();
		$input = $this->payment( 'pos_card', '20.00' );
		$other = $this->payment( 'pos_cash', '5.00' );
		$error = new \WP_Error( 'test_declined', 'Declined' );
		$result = $ledger->intent( $order, $input['id'], $input, array(
			'error' => $error,
			'observe' => static function () use ( $order, $ledger, $other ) {
				$ledger->record( wc_get_order( $order->get_id() ), $other );
			},
		) );
		$this->assertSame( $error, $result );
		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( array( $other['id'] ), array_column( $ledger->read( $saved ), 'id' ) );
		$this->assertSame( array( $other['id'] ), array_values( wp_list_pluck( $saved->get_meta( Ledger::PAYMENT_ID_META_KEY, false ), 'value' ) ) );
		$this->assertEmpty( $saved->get_meta( Ledger::LIVE_LEG_META_KEY, false ) );
	}

	/** Capturing an authorized leg with an on-reader tip persists the tip fee and the new total. */
	public function test_authorized_capture_with_tip_persists_fee_and_total(): void {
		// Arrange: an authorized on-reader leg for the order total.
		$order   = $this->create_pos_order();
		$product = ProductHelper::create_simple_product();
		$product->set_price( '92.95' );
		$product->set_tax_status( 'none' );
		$product->save();
		$order->add_product( $product );
		$order->calculate_totals( false );
		Integrity_Handler::$tips = 'on_reader';
		$input  = $this->payment( 'pos_card', '92.95' );
		$ledger = Ledger::instance();
		$ledger->intent( $order, $input['id'], $input, array() );
		$ledger->capture(
			$order,
			$input['id'],
			array(
				'status' => 'authorized',
				'amount' => '92.95',
			)
		);
		$this->assertSame( 'authorized', $ledger->find( wc_get_order( $order->get_id() ), $input['id'] )['status'] );

		// Act: the provider confirms a higher amount.
		$ledger->capture( wc_get_order( $order->get_id() ), $input['id'], array( 'amount' => '100.00' ) );

		// Assert: read storage, not the caller's object.
		$saved = new \WC_Order( $order->get_id() );
		$fees  = array_values( $saved->get_fees() );
		$this->assertCount( 1, $fees );
		$this->assertSame( 'Tip', $fees[0]->get_name() );
		$this->assertSame( '7.05', $fees[0]->get_total() );
		$this->assertSame( '100.00', $saved->get_total() );
		$row = $ledger->find( $saved, $input['id'] );
		$this->assertSame( '100.00', $row['amount'] );
		$this->assertSame( '7.05', $row['tip'] );
	}

	/** D5: a tip capture whose row save fails, retried, adds the Tip fee once. */
	public function test_tip_capture_retried_after_a_failed_row_save_adds_one_fee(): void {
		// Arrange: an authorized on-reader leg for the order total, as in the tip test above.
		$order   = $this->create_pos_order();
		$product = ProductHelper::create_simple_product();
		$product->set_price( '92.95' );
		$product->set_tax_status( 'none' );
		$product->save();
		$order->add_product( $product );
		$order->calculate_totals( false );
		Integrity_Handler::$tips = 'on_reader';
		$input  = $this->payment( 'pos_card', '92.95' );
		$ledger = Ledger::instance();
		$ledger->intent( $order, $input['id'], $input, array() );
		$ledger->capture(
			$order,
			$input['id'],
			array(
				'status' => 'authorized',
				'amount' => '92.95',
			)
		);
		$refused = 0;
		$refuse  = static function ( $saving ) use ( $order, &$refused ): void {
			$staged = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			$status = array_column( (array) ( $staged['payments'] ?? array() ), 'status' );
			if ( $saving->get_id() === $order->get_id() && in_array( 'captured', $status, true ) ) {
				++$refused;
				throw new \RuntimeException( 'Simulated refused row save' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $refuse, 1 );

		// Act: every save staging the captured row fails during the first capture; the retry lands.
		try {
			$ledger->capture( wc_get_order( $order->get_id() ), $input['id'], array( 'amount' => '100.00' ) );
			$this->fail( 'Expected the failed row save to throw.' );
		} catch ( Ledger_Persistence_Exception $error ) {
			$this->assertGreaterThan( 0, $refused );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $refuse, 1 );
		}
		$ledger->capture( wc_get_order( $order->get_id() ), $input['id'], array( 'amount' => '100.00' ) );

		// Assert: one Tip fee and the tipped total, read from storage.
		$saved = new \WC_Order( $order->get_id() );
		$fees  = array_values( $saved->get_fees() );
		$this->assertCount( 1, $fees );
		$this->assertSame( 'Tip', $fees[0]->get_name() );
		$this->assertSame( '7.05', $fees[0]->get_total() );
		$this->assertSame( '100.00', $saved->get_total() );
		$row = $ledger->find( $saved, $input['id'] );
		$this->assertSame( 'captured', $row['status'] );
		$this->assertSame( '100.00', $row['amount'] );
		$this->assertSame( '7.05', $row['tip'] );
	}

	/** D5: a retry after the fee was saved but the new total was not still brings the total to the tipped amount. */
	public function test_tip_capture_retried_after_the_total_save_failed_recalculates_the_total(): void {
		// Arrange: as above; every save after update_taxes() persisted the fee fails during the first capture.
		$order   = $this->create_pos_order();
		$product = ProductHelper::create_simple_product();
		$product->set_price( '92.95' );
		$product->set_tax_status( 'none' );
		$product->save();
		$order->add_product( $product );
		$order->calculate_totals( false );
		Integrity_Handler::$tips = 'on_reader';
		$input  = $this->payment( 'pos_card', '92.95' );
		$ledger = Ledger::instance();
		$ledger->intent( $order, $input['id'], $input, array() );
		$ledger->capture(
			$order,
			$input['id'],
			array(
				'status' => 'authorized',
				'amount' => '92.95',
			)
		);
		$refuse = static function ( $saving ) use ( $order ): void {
			$saved_fees = array_filter( $saving->get_fees(), static fn( $fee ) => 0 < (int) $fee->get_id() );
			if ( $saving->get_id() === $order->get_id() && $saved_fees ) {
				throw new \RuntimeException( 'Simulated refused save after the fee landed' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $refuse, 1 );
		try {
			$ledger->capture( wc_get_order( $order->get_id() ), $input['id'], array( 'amount' => '100.00' ) );
			$this->fail( 'Expected the failed row save to throw.' );
		} catch ( Ledger_Persistence_Exception $error ) {
			$stranded = new \WC_Order( $order->get_id() );
			$this->assertCount( 1, $stranded->get_fees() );
			$this->assertSame( '92.95', $stranded->get_total() );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $refuse, 1 );
		}

		// Act: the retry finds the fee already stored.
		$ledger->capture( wc_get_order( $order->get_id() ), $input['id'], array( 'amount' => '100.00' ) );

		// Assert: one fee, and the order totals the captured amount.
		$saved = new \WC_Order( $order->get_id() );
		$this->assertCount( 1, $saved->get_fees() );
		$this->assertSame( '100.00', $saved->get_total() );
		$this->assertSame( 'captured', $ledger->find( $saved, $input['id'] )['status'] );
	}

	/** An authorization for more than the leg adds no tip: only the captured amount confirms one. */
	public function test_an_authorized_on_reader_tip_adds_no_fee_until_capture(): void {
		// Arrange: a 100.00 sale; the reader takes on-reader tips.
		list( $order ) = $this->open_sale_of( '100.00' );
		Integrity_Handler::$tips = 'on_reader';
		$ledger = Ledger::instance();
		$first  = $this->payment( 'pos_card', '100.00' );

		// Act: the reader authorizes 110.00.
		$ledger->intent(
			$order,
			$first['id'],
			$first,
			array(
				'resume' => array(
					'status' => 'authorized',
					'amount' => '110.00',
				),
			)
		);

		// Assert: tolerated, but no fee and no amount change.
		$saved = new \WC_Order( $order->get_id() );
		$row   = $ledger->find( $saved, $first['id'] );
		$this->assertSame( 'authorized', $row['status'] );
		$this->assertSame( '100.00', $row['amount'] );
		$this->assertCount( 0, $saved->get_fees() );
		$this->assertSame( '100.00', $saved->get_total() );

		// A void leaves the order as it was.
		$ledger->void( $saved, $first['id'], 'customer left' );
		$voided = new \WC_Order( $order->get_id() );
		$this->assertCount( 0, $voided->get_fees() );
		$this->assertSame( '100.00', $voided->get_total() );

		// A fresh leg authorized at 110.00 and captured at 110.00 takes the 10.00 tip then.
		$second = $this->payment( 'pos_card', '100.00' );
		$ledger->intent(
			$voided,
			$second['id'],
			$second,
			array(
				'resume' => array(
					'status' => 'authorized',
					'amount' => '110.00',
				),
			)
		);
		$this->assertCount( 0, ( new \WC_Order( $order->get_id() ) )->get_fees() );
		$ledger->capture( wc_get_order( $order->get_id() ), $second['id'], array( 'amount' => '110.00' ) );
		$captured = new \WC_Order( $order->get_id() );
		$fees     = array_values( $captured->get_fees() );
		$this->assertCount( 1, $fees );
		$this->assertSame( '10.00', $fees[0]->get_total() );
		$this->assertSame( '110.00', $ledger->find( $captured, $second['id'] )['amount'] );
		$this->assertSame( '110.00', $captured->get_total() );
	}

	/**
	 * An open POS sale of one untaxed line.
	 *
	 * @param string $price Line price.
	 * @return array{0: \WC_Order}
	 */
	private function open_sale_of( string $price ): array {
		$order   = wc_create_order();
		$product = ProductHelper::create_simple_product();
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_tax_status( 'none' );
		$product->save();
		$order->add_product( $product );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->calculate_totals( false );
		$order->save();
		return array( wc_get_order( $order->get_id() ) );
	}

	/**
	 * A POS sale paid in full in cash, with one simple-product line per price.
	 *
	 * @param string[] $prices Line prices.
	 * @return array{0: \WC_Order, 1: array, 2: int[]} The paid order, its cash row, its line ids.
	 */
	private function paid_sale_with_lines( array $prices ): array {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$lines = array();
		foreach ( $prices as $price ) {
			$product = ProductHelper::create_simple_product();
			$product->set_regular_price( $price );
			$product->set_price( $price );
			$product->set_tax_status( 'none' );
			$product->save();
			$lines[] = $order->add_product( $product );
		}
		$order->calculate_totals( false );
		$order->save();
		$row = Ledger::instance()->record( $order, $this->payment( 'pos_cash', $order->get_total() ) );
		$this->assertNotNull( wc_get_order( $order->get_id() )->get_date_paid( 'edit' ) );
		return array( wc_get_order( $order->get_id() ), $row, $lines );
	}

	/**
	 * Messages logged while a refund pin runs.
	 *
	 * @var string[]
	 */
	private $refund_logs = array();

	/**
	 * The gateway filter a refund pin installed, removed again in tearDown().
	 *
	 * @var \Closure|null
	 */
	private $probe_gateways = null;

	/** Drop the probe gateway from WooCommerce's loaded list after the pin that added it. */
	public function tearDown(): void {
		unset( $GLOBALS['woocommerce_pos_ledger_clock'] );
		if ( null !== $this->probe_gateways ) {
			remove_filter( 'woocommerce_payment_gateways', $this->probe_gateways );
			\WC_Payment_Gateways::instance()->init();
			$this->probe_gateways = null;
		}
		parent::tearDown();
	}

	/**
	 * A captured row refunded through the given capture mode, on an open order, and a 20.00 refund.
	 * `gateway` runs Gateway_Handler against Refund_Probe_Gateway; `refund_answer` runs Refund_Answer_Handler.
	 *
	 * @param string $capture_mode Capture mode of the row.
	 * @return array{0: \WC_Order, 1: array, 2: \WC_Order_Refund}
	 */
	private function probed_refundable( string $capture_mode = 'gateway' ): array {
		Refund_Probe_Gateway::reset();
		Refund_Answer_Handler::$answer = null;
		Refund_Answer_Handler::$calls  = 0;
		Capture_Mode_Registry::instance()->register( 'refund_answer', Refund_Answer_Handler::class );
		$this->probe_gateways = static fn( array $gateways ): array => array_merge( $gateways, array( Refund_Probe_Gateway::class ) );
		add_filter( 'woocommerce_payment_gateways', $this->probe_gateways );
		\WC_Payment_Gateways::instance()->init();
		$this->refund_logs = array();
		add_filter(
			'wcpos_logging',
			function ( $should_log, $message ) {
				$this->refund_logs[] = (string) $message;
				return false;
			},
			10,
			2
		);
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'wcpos_refund_probe',
			'50.00',
			array(
				'status'       => 'captured',
				'kind'         => 'card',
				'capture_mode' => $capture_mode,
			)
		);
		Ledger::instance()->save( $order, array( $row ), false );
		$order = wc_get_order( $order->get_id() );
		return array( $order, Ledger::instance()->find( $order, $row['id'] ), $this->refund_of( $order, '20.00' ) );
	}

	/**
	 * Rewrite the refund's allocation in every staged ledger save of the order while $active() holds:
	 * null strips it, an array puts that allocation in its place.
	 *
	 * @param int        $order_id   Order id.
	 * @param int        $refund_id  Refund id.
	 * @param array|null $allocation The allocation storage is forced to hold.
	 * @param callable   $active     Whether this save is rewritten.
	 */
	private function force_allocation( int $order_id, int $refund_id, ?array $allocation, callable $active ): \Closure {
		$hook = static function ( $saving ) use ( $order_id, $refund_id, $allocation, $active ): void {
			if ( ! $saving instanceof \WC_Order || $saving->get_id() !== $order_id || ! $active() ) {
				return;
			}
			$value = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( $value['payments'] as &$row ) {
				$kept = array_values( array_filter( $row['refunds'] ?? array(), static fn( $existing ) => (int) $existing['id'] !== $refund_id ) );
				if ( null !== $allocation ) {
					$kept[] = $allocation;
				}
				$row['refunds'] = $kept;
			}
			unset( $row );
			$saving->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		};
		add_action( 'woocommerce_before_order_object_save', $hook, PHP_INT_MAX );
		return $hook;
	}

	/**
	 * The reservation an allocation of $amount for the refund starts as.
	 *
	 * @param int    $refund_id Refund id.
	 * @param string $amount    Amount.
	 */
	private function reservation( int $refund_id, string $amount ): array {
		return array(
			'id'           => $refund_id,
			'amount'       => $amount,
			'status'       => 'pending',
			'provider_ref' => null,
		);
	}

	/**
	 * A stored row, read from storage.
	 *
	 * @param int    $order_id Order id.
	 * @param string $id       Payment id.
	 */
	private function stored_row_of( int $order_id, string $id ): ?array {
		foreach ( $this->stored_rows( $order_id ) as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The refund's allocations on a stored row.
	 *
	 * @param array|null $row       Stored row.
	 * @param int        $refund_id Refund id.
	 */
	private function allocations_of( ?array $row, int $refund_id ): array {
		return array_values( array_filter( $row['refunds'] ?? array(), static fn( $allocation ) => (int) $allocation['id'] === $refund_id ) );
	}

	/**
	 * The order's notes that mark a stranded refund.
	 *
	 * @param int $order_id Order id.
	 */
	private function stranded_notes( int $order_id ): array {
		return array_values( array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' ), static fn( $note ) => 0 === strpos( $note, 'WCPOS: refund #' ) ) );
	}

	/**
	 * Logged lines that mark a stranded refund.
	 */
	private function stranded_logs(): array {
		return array_values( array_filter( $this->refund_logs, static fn( $line ) => false !== strpos( $line, 'WCPOS refund #' ) ) );
	}

	/**
	 * A till command for a ledger call.
	 */
	private function refund_command(): array {
		return array(
			'id'   => wp_generate_uuid4(),
			'fp'   => 'sha256:' . str_repeat( 'a', 64 ),
			'kind' => 'refund',
		);
	}

	/**
	 * Listen to wcpos_refund_allocated; the statuses it saw for the refund land in $seen.
	 *
	 * @param int   $refund_id Refund id.
	 * @param array $seen      Allocation statuses, by reference.
	 */
	private function listen_allocated( int $refund_id, array &$seen ): void {
		add_action(
			'wcpos_refund_allocated',
			static function ( $order, $row ) use ( $refund_id, &$seen ): void {
				foreach ( $row['refunds'] as $allocation ) {
					if ( (int) $allocation['id'] === $refund_id ) {
						$seen[] = $allocation['status'];
					}
				}
			},
			10,
			2
		);
	}

	/** §6.6 pin 1: the allocation is in storage, pending with its entry, before the provider is asked. */
	public function test_refund_reserves_the_allocation_pending_before_the_provider_is_called(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$command = $this->refund_command();
		$during  = null;
		Refund_Probe_Gateway::$observe = function () use ( $order, $row, &$during ): void {
			$during = $this->stored_row_of( $order->get_id(), $row['id'] );
		};
		$seen = array();
		$this->listen_allocated( $refund->get_id(), $seen );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $command );

		// Assert.
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $during, $refund->get_id() ) );
		$this->assertArrayHasKey( $command['id'], $during['commands'] );
		$stored = $this->stored_row_of( $order->get_id(), $row['id'] );
		$this->assertSame( 'succeeded', $this->allocations_of( $stored, $refund->get_id() )[0]['status'] );
		$this->assertCount( 1, $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertSame( '10.00', $stored['refunded_amount'] );
		$this->assertArrayHasKey( $command['id'], $stored['commands'] );
		$this->assertSame( 'succeeded', $result['refunds'][0]['status'] );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$this->assertSame( array( 'succeeded' ), $seen );
	}

	/** §6.6 pin 2: a reservation that does not land is withdrawn whole, and the provider is never asked. */
	public function test_refund_whose_reservation_does_not_land_never_calls_the_provider(): void {
		// Arrange: every save strips the allocation and keeps the entry.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$command = $this->refund_command();
		$strip   = $this->force_allocation( $order->get_id(), $refund->get_id(), null, static fn() => true );

		// Act.
		$error = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $command );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$stored = $this->stored_row_of( $order->get_id(), $row['id'] );
		$retry  = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '10.00', $command );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( array(), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertArrayNotHasKey( $command['id'], $stored['commands'] );
		$this->assertSame( 'succeeded', $retry['refunds'][0]['status'] );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
	}

	/** §6.6 pin 3: a settle that does not land leaves the reservation standing, noted, and the hook silent. */
	public function test_refund_whose_settle_does_not_land_keeps_the_pending_allocation_and_notes_the_order(): void {
		// Arrange: every save after the provider call is forced back to the reservation.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$force = $this->force_allocation( $order->get_id(), $refund->get_id(), $this->reservation( $refund->get_id(), '10.00' ), static fn() => Refund_Probe_Gateway::$calls > 0 );
		$seen  = array();
		$this->listen_allocated( $refund->get_id(), $seen );
		$before = Ledger::instance()->summary( wc_get_order( $order->get_id() ) );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $this->allocations_of( $result, $refund->get_id() )[0]['status'] );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$notes = $this->stranded_notes( $order->get_id() );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( '#' . $refund->get_id(), $notes[0] );
		$this->assertStringContainsString( '10.00', $notes[0] );
		$this->assertCount( 1, $this->stranded_logs() );
		$this->assertSame( array(), $seen );
		$summary = Ledger::instance()->summary( wc_get_order( $order->get_id() ) );
		$this->assertSame( '10.00', $summary['refund_pending'] );
		$this->assertSame( $before['balance'], $summary['balance'] );
	}

	/** #2362 review: a settle whose saves lose the allocation leaves the reservation re-persisted, never an empty answer. */
	public function test_a_settle_that_keeps_stripping_the_allocation_re_persists_the_reservation(): void {
		// Arrange: the two settle saves after the provider call land with the allocation stripped; later saves land as written.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$command  = $this->refund_command();
		$stripped = 0;
		$strip    = $this->force_allocation(
			$order->get_id(),
			$refund->get_id(),
			null,
			static function () use ( &$stripped ): bool {
				if ( Refund_Probe_Gateway::$calls > 0 && $stripped < 2 ) {
					++$stripped;
					return true;
				}
				return false;
			}
		);

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $command );
		$stored = $this->stored_row_of( $order->get_id(), $row['id'] );
		$again  = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );

		// Assert.
		$this->assertSame( 2, $stripped );
		$this->assertIsArray( $result );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $result, $refund->get_id() ) );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertArrayHasKey( $command['id'], $stored['commands'] );
		$this->assertIsArray( $again );
		$this->assertSame( 'pending', $this->allocations_of( $again, $refund->get_id() )[0]['status'] );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$this->assertCount( 1, $this->stranded_logs() );
	}

	/** #2362 review: a reservation storage will not keep is logged on its own line, and the answer still says pending. */
	public function test_a_reservation_storage_will_not_keep_is_logged_and_answered_pending(): void {
		// Arrange: every save after the provider call lands with the allocation stripped.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$strip = $this->force_allocation( $order->get_id(), $refund->get_id(), null, static fn() => Refund_Probe_Gateway::$calls > 0 );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $result, $refund->get_id() ) );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$this->assertNotEmpty( array_filter( $this->refund_logs, static fn( $line ) => false !== strpos( $line, 'storage will not keep the reservation' ) ) );
	}

	/** §6.6 pin 3, second case: a later answer's reference is part of the read-back, not only its status. */
	public function test_refund_whose_pending_answer_loses_its_reference_is_stranded(): void {
		// Arrange: the handler answers pending with a reference; storage keeps the bare reservation.
		list( $order, $row, $refund ) = $this->probed_refundable( 'refund_answer' );
		Refund_Answer_Handler::$answer = static function ( array $row, int $refund_id, string $amount ): array {
			$row['refunds'][] = array(
				'id'           => $refund_id,
				'amount'       => $amount,
				'status'       => 'pending',
				'provider_ref' => 're_later',
			);
			return $row;
		};
		$force = $this->force_allocation( $order->get_id(), $refund->get_id(), $this->reservation( $refund->get_id(), '10.00' ), static fn() => Refund_Answer_Handler::$calls > 0 );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00' );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertNull( $this->allocations_of( $result, $refund->get_id() )[0]['provider_ref'] );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$this->assertCount( 1, $this->stranded_logs() );
	}

	/** §6.6 pin 6: a definite refusal withdraws the reservation and its entry, so the same command runs again. */
	public function test_refund_the_provider_refuses_withdraws_the_reservation_and_the_entry(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$command                      = $this->refund_command();
		Refund_Probe_Gateway::$result = false;

		// Act.
		$error  = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $command );
		$stored = $this->stored_row_of( $order->get_id(), $row['id'] );
		Refund_Probe_Gateway::$result = true;
		$retry = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '10.00', $command );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_provider_error', $error->get_error_code() );
		$this->assertSame( 502, $error->get_error_data()['status'] );
		$this->assertSame( array(), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertArrayNotHasKey( $command['id'], $stored['commands'] );
		$this->assertSame( 'succeeded', $retry['refunds'][0]['status'] );
		$this->assertSame( 2, Refund_Probe_Gateway::$calls );
		$this->assertSame( array(), $this->stranded_notes( $order->get_id() ) );
	}

	/** §6.6 pin 7: a provider that throws mid-refund leaves the reservation standing; nothing escapes. */
	public function test_refund_whose_provider_throws_keeps_the_reservation_and_notes_the_order(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->probed_refundable();
		Refund_Probe_Gateway::$result = new \RuntimeException( 'gateway timed out' );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		$again  = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '10.00' );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $this->allocations_of( $result, $refund->get_id() )[0]['status'] );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$logs = $this->stranded_logs();
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'gateway timed out', $logs[0] );
		$this->assertIsArray( $again );
		$this->assertSame( 'pending', $this->allocations_of( $again, $refund->get_id() )[0]['status'] );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
	}

	/** §6.6 pin 8: an indeterminate provider error leaves the reservation standing, as a throw does. */
	public function test_refund_with_an_indeterminate_provider_error_keeps_the_reservation(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->probed_refundable( 'refund_answer' );
		Refund_Answer_Handler::$answer = static fn() => new \WP_Error(
			'wcpos_provider_error',
			'provider unreachable',
			array(
				'status'        => 502,
				'indeterminate' => true,
			)
		);

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $this->allocations_of( $result, $refund->get_id() )[0]['status'] );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$logs = $this->stranded_logs();
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'provider unreachable', $logs[0] );
	}

	/** §6.6 pin 11, ledger effect: a gateway's indeterminate refund error reaches the ledger through Gateway_Handler. */
	public function test_refund_with_a_gateways_indeterminate_error_keeps_the_reservation(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->probed_refundable();
		Refund_Probe_Gateway::$result = new \WP_Error( 'timeout', 'gateway did not answer', array( 'indeterminate' => true ) );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$this->assertStringContainsString( 'gateway did not answer', $this->stranded_logs()[0] );
	}

	/** §6.6 pin 8b: a settle save that throws is a miss, never an escape; on the reservation it is the 409. */
	public function test_refund_whose_settle_save_throws_is_stranded_not_escaped(): void {
		// Arrange: every save after the provider call throws.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$order_id = $order->get_id();
		$throws   = static function ( $saving ) use ( $order_id ): void {
			if ( $saving instanceof \WC_Order && $saving->get_id() === $order_id && Refund_Probe_Gateway::$calls > 0 ) {
				throw new \RuntimeException( 'settle save failed' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $throws, PHP_INT_MAX );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $throws, PHP_INT_MAX );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $this->allocations_of( $result, $refund->get_id() )[0]['status'] );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order_id, $row['id'] ), $refund->get_id() ) );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$this->assertCount( 1, $this->stranded_notes( $order_id ) );
		$this->assertCount( 1, $this->stranded_logs() );
	}

	/** §6.6 pin 8b, reservation half: a reservation save that throws is the 409, and the provider is not asked. */
	public function test_refund_whose_reservation_save_throws_is_the_retryable_conflict(): void {
		// Arrange: every save throws.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$order_id = $order->get_id();
		$throws   = static function ( $saving ) use ( $order_id ): void {
			if ( $saving instanceof \WC_Order && $saving->get_id() === $order_id ) {
				throw new \RuntimeException( 'reservation save failed' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $throws, PHP_INT_MAX );

		// Act.
		$error = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $throws, PHP_INT_MAX );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $error->get_error_code() );
		$this->assertSame( 0, Refund_Probe_Gateway::$calls );
		$this->assertSame( array(), $this->allocations_of( $this->stored_row_of( $order_id, $row['id'] ), $refund->get_id() ) );
	}

	/** §6.6 pin 8c: a handler's failed allocation is a definite refusal: the 400, and nothing kept. */
	public function test_a_handler_answering_a_failed_allocation_withdraws_the_reservation(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->probed_refundable( 'refund_answer' );
		$command                       = $this->refund_command();
		Refund_Answer_Handler::$answer = static function ( array $row, int $refund_id, string $amount ): array {
			$row['refunds'][] = array(
				'id'           => $refund_id,
				'amount'       => $amount,
				'status'       => 'failed',
				'provider_ref' => 're_declined',
			);
			return $row;
		};

		// Act.
		$error = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $command );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_refund_not_allocatable', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$stored = $this->stored_row_of( $order->get_id(), $row['id'] );
		$this->assertSame( array(), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertArrayNotHasKey( $command['id'], $stored['commands'] );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
	}

	/** §6.6 pin 8d: a post-call check that fails after the handler reports money moved strands; it never withdraws. */
	public function test_a_handler_that_refunded_more_than_asked_is_stranded_not_withdrawn(): void {
		// Arrange: the handler reports 25.00 refunded against a 20.00 refund.
		list( $order, $row, $refund ) = $this->probed_refundable( 'refund_answer' );
		Refund_Answer_Handler::$answer = static function ( array $row, int $refund_id ): array {
			$row['refunds'][] = array(
				'id'           => $refund_id,
				'amount'       => '25.00',
				'status'       => 'succeeded',
				'provider_ref' => null,
			);
			return $row;
		};

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		$again  = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $refund->get_id(), '10.00' );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $result, $refund->get_id() ) );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$logs = $this->stranded_logs();
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'wcpos_refund_not_allocatable', $logs[0] );
		$this->assertIsArray( $again );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
	}

	/** §6.6 pin 8e: a throwing note hook while stranding, or a throwing allocation listener after a landed settle, never escapes refund(). */
	public function test_a_throwing_note_or_allocation_listener_does_not_escape_refund(): void {
		// Arrange: a stranded refund whose note hook throws.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$force = $this->force_allocation( $order->get_id(), $refund->get_id(), $this->reservation( $refund->get_id(), '10.00' ), static fn() => Refund_Probe_Gateway::$calls > 0 );
		$note  = static function (): void {
			throw new \RuntimeException( 'note hook failed' );
		};
		add_action( 'woocommerce_order_note_added', $note );

		// Act.
		$stranded = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_order_note_added', $note );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );

		// Assert.
		$this->assertIsArray( $stranded );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertNotEmpty( array_filter( $this->refund_logs, static fn( $line ) => false !== strpos( $line, 'note hook failed' ) ) );

		// Arrange: a landed settle whose allocation listener throws.
		$second   = $this->refund_of( wc_get_order( $order->get_id() ), '5.00' );
		$listener = static function (): void {
			throw new \RuntimeException( 'allocation listener failed' );
		};
		add_action( 'wcpos_refund_allocated', $listener );

		// Act.
		$settled = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $second->get_id(), '5.00', $this->refund_command() );
		remove_action( 'wcpos_refund_allocated', $listener );

		// Assert.
		$this->assertIsArray( $settled );
		$this->assertSame( 'succeeded', $this->allocations_of( $settled, $second->get_id() )[0]['status'] );
		$this->assertSame( 'succeeded', $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $second->get_id() )[0]['status'] );
		$this->assertNotEmpty( array_filter( $this->refund_logs, static fn( $line ) => false !== strpos( $line, 'allocation listener failed' ) ) );
		$this->assertSame( 2, Refund_Probe_Gateway::$calls );
	}

	/**
	 * A reopened sale holding more captured money than its reduced total (100.00 captured, total 80.00,
	 * refund_due 20.00), its row refunded through Refund_Answer_Handler, and a 20.00 refund.
	 *
	 * @return array{0: \WC_Order, 1: array, 2: \WC_Order_Refund}
	 */
	private function reopened_sale_owing_twenty(): array {
		list( $order, $row, $lines ) = $this->paid_sale_with_lines( array( '80.00', '20.00' ) );
		$order->set_status( 'pos-open' );
		$order->remove_item( $lines[1] );
		$order->calculate_totals( false );
		$order->save();
		Refund_Answer_Handler::$answer = null;
		Refund_Answer_Handler::$calls  = 0;
		Capture_Mode_Registry::instance()->register( 'refund_answer', Refund_Answer_Handler::class );
		$row['capture_mode'] = 'refund_answer';
		Ledger::instance()->save( wc_get_order( $order->get_id() ), array( $row ), false );
		$this->refund_logs = array();
		add_filter(
			'wcpos_logging',
			function ( $should_log, $message ) {
				$this->refund_logs[] = (string) $message;
				return false;
			},
			10,
			2
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( '20.00', Ledger::instance()->summary( $order )['refund_due'] );
		$this->assertCount( 1, Completion_History::of( $order )->entries() );
		return array( $order, Ledger::instance()->find( $order, $row['id'] ), $this->refund_of( $order, '20.00' ) );
	}

	/** #2362 review: a settle that does not land never completes the sale; derivation runs only after it landed. */
	public function test_a_settle_that_does_not_land_leaves_the_order_uncompleted(): void {
		// Arrange: every save after the provider call is forced back to the reservation.
		list( $order, $row, $refund ) = $this->reopened_sale_owing_twenty();
		$force                        = $this->force_allocation( $order->get_id(), $refund->get_id(), $this->reservation( $refund->get_id(), '20.00' ), static fn() => Refund_Answer_Handler::$calls > 0 );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '20.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$this->assertIsArray( $result );
		$this->assertSame( 'pos-open', $stored->get_status() );
		$this->assertCount( 1, Completion_History::of( $stored )->entries() );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '20.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() ) );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
	}

	/** #2362 review: a landed settle that covers the signed balance completes the sale once, before the allocated hook. */
	public function test_a_succeeded_refund_that_covers_the_signed_balance_completes_the_order(): void {
		// Arrange.
		list( $order, $row, $refund ) = $this->reopened_sale_owing_twenty();
		$seen                         = array();
		$this->listen_allocated( $refund->get_id(), $seen );
		$paid_when_allocated = null;
		add_action(
			'wcpos_refund_allocated',
			static function ( $allocated ) use ( &$paid_when_allocated ): void {
				$paid_when_allocated = wc_get_order( $allocated->get_id() )->is_paid();
			}
		);

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '20.00', $this->refund_command() );

		// Assert.
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'succeeded', $this->allocations_of( $result, $refund->get_id() )[0]['status'] );
		$this->assertTrue( $stored->is_paid() );
		$this->assertSame( array( 1, 2 ), array_column( Completion_History::of( $stored )->entries(), 'n' ) );
		$this->assertSame( array( 'succeeded' ), $seen );
		$this->assertTrue( $paid_when_allocated );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
	}

	/** #2362 review: a derive save after a landed settle that strips the allocation and entry is repaired before the hook fires. */
	public function test_a_derive_save_that_strips_the_settled_allocation_is_repaired(): void {
		// Arrange: count the order's saves after the provider call; strip the refund and every command entry on the
		// saves derive() makes (payment_complete() inside Ledger::is_deriving()), leaving the status they carry.
		list( $order, $row, $refund ) = $this->reopened_sale_owing_twenty();
		$command   = $this->refund_command();
		$order_id  = $order->get_id();
		$refund_id = $refund->get_id();
		$after     = 0;
		$stripped  = array();
		$strip     = static function ( $saving ) use ( $order_id, $refund_id, &$after, &$stripped ): void {
			if ( ! $saving instanceof \WC_Order || $saving->get_id() !== $order_id || 0 === Refund_Answer_Handler::$calls ) {
				return;
			}
			++$after;
			if ( ! Ledger::is_deriving( $order_id ) ) {
				return;
			}
			$stripped[] = $after;
			$value      = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			foreach ( $value['payments'] as &$payment ) {
				$payment['refunds']  = array_values( array_filter( $payment['refunds'] ?? array(), static fn( $allocation ) => (int) $allocation['id'] !== $refund_id ) );
				$payment['commands'] = array();
			}
			unset( $payment );
			$saving->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		};
		add_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$seen = array();
		$this->listen_allocated( $refund_id, $seen );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund_id, '20.00', $command );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$stored = $this->stored_row_of( $order_id, $row['id'] );
		$again  = Ledger::instance()->refund( wc_get_order( $order_id ), $row['id'], $refund_id, '20.00', $this->refund_command() );

		// Assert: save 1 after the call is the settle, save 2 is derive()'s payment_complete() save, save 3 the re-persist.
		$this->assertSame( array( 2 ), $stripped );
		$this->assertSame( 3, $after );
		$this->assertSame( 'succeeded', $this->allocations_of( $result, $refund_id )[0]['status'] );
		$this->assertSame( 'succeeded', $this->allocations_of( $stored, $refund_id )[0]['status'] );
		$this->assertArrayHasKey( $command['id'], $stored['commands'] );
		$this->assertTrue( wc_get_order( $order_id )->is_paid() );
		$this->assertSame( array( 'succeeded' ), $seen );
		$this->assertIsArray( $again );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
	}

	/** #2362 review: a settled refund that storage will not keep after the derive save falls back to its reservation. */
	public function test_a_settled_refund_storage_will_not_keep_falls_back_to_its_reservation(): void {
		// Arrange: the settle (the first save after the provider call) lands; every later save that carries
		// the refund's allocation as succeeded has it stripped, and a pending allocation goes through.
		list( $order, $row, $refund ) = $this->reopened_sale_owing_twenty();
		$command   = $this->refund_command();
		$order_id  = $order->get_id();
		$refund_id = $refund->get_id();
		$after     = 0;
		$stripped  = 0;
		$strip     = static function ( $saving ) use ( $order_id, $refund_id, &$after, &$stripped ): void {
			if ( ! $saving instanceof \WC_Order || $saving->get_id() !== $order_id || 0 === Refund_Answer_Handler::$calls || 1 === ++$after ) {
				return;
			}
			$value = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			foreach ( $value['payments'] as &$payment ) {
				$kept = array_values( array_filter( $payment['refunds'] ?? array(), static fn( $allocation ) => ! ( (int) $allocation['id'] === $refund_id && 'succeeded' === $allocation['status'] ) ) );
				if ( $kept !== $payment['refunds'] ) {
					++$stripped;
					$payment['refunds'] = $kept;
				}
			}
			unset( $payment );
			$saving->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		};
		add_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );
		$seen = array();
		$this->listen_allocated( $refund_id, $seen );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund_id, '20.00', $command );
		$lost   = $stripped;
		$stored = $this->stored_row_of( $order_id, $row['id'] );
		$again  = Ledger::instance()->refund( wc_get_order( $order_id ), $row['id'], $refund_id, '20.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $strip, PHP_INT_MAX );

		// Assert: the derive save and both re-persists of the settled row lost it; the reservation landed.
		$this->assertSame( 3, $lost );
		$this->assertSame( array( $this->reservation( $refund_id, '20.00' ) ), $this->allocations_of( $result, $refund_id ) );
		$this->assertSame( array( $this->reservation( $refund_id, '20.00' ) ), $this->allocations_of( $stored, $refund_id ) );
		$this->assertArrayHasKey( $command['id'], $stored['commands'] );
		$logs = $this->stranded_logs();
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'the provider settled it, but storage will not keep the settled allocation', $logs[0] );
		$this->assertCount( 1, $this->stranded_notes( $order_id ) );
		$this->assertSame( array(), $seen );
		$this->assertSame( 'pending', $this->allocations_of( $again, $refund_id )[0]['status'] );
		$this->assertSame( 1, Refund_Answer_Handler::$calls );
	}

	/**
	 * Edit every payment row of the order's staged ledger on the saves $active() picks.
	 *
	 * @param int      $order_id Order id.
	 * @param callable $active   Whether this save is edited.
	 * @param callable $edit     Takes and returns a payment row.
	 */
	private function edit_staged_rows( int $order_id, callable $active, callable $edit ): \Closure {
		$hook = static function ( $saving ) use ( $order_id, $active, $edit ): void {
			if ( ! $saving instanceof \WC_Order || $saving->get_id() !== $order_id || ! $active() ) {
				return;
			}
			$value = json_decode( (string) $saving->get_meta( Ledger::META_KEY, true ), true );
			$value['payments'] = array_map( $edit, $value['payments'] );
			$saving->update_meta_data( Ledger::META_KEY, wp_json_encode( $value ) );
		};
		add_action( 'woocommerce_before_order_object_save', $hook, PHP_INT_MAX );
		return $hook;
	}

	/**
	 * True for the first $limit saves after the probe gateway was called.
	 *
	 * @param int $limit Saves to pick.
	 */
	private function first_saves_after_the_call( int $limit ): callable {
		$picked = 0;
		return static function () use ( &$picked, $limit ): bool {
			if ( Refund_Probe_Gateway::$calls > 0 && $picked < $limit ) {
				++$picked;
				return true;
			}
			return false;
		};
	}

	/** #2362 review: a save whose command entry is rewritten (same key, other fingerprint) is a miss, on either save. */
	public function test_a_save_that_rewrites_the_command_entry_is_not_acknowledged(): void {
		// Arrange: the settle's two saves land with the entry's fingerprint rewritten.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$command = $this->refund_command();
		$forge   = static function ( array $payment ) use ( $command ): array {
			if ( isset( $payment['commands'][ $command['id'] ] ) ) {
				$payment['commands'][ $command['id'] ]['fp'] = 'sha256:' . str_repeat( 'f', 64 );
			}
			return $payment;
		};
		$hook = $this->edit_staged_rows( $order->get_id(), $this->first_saves_after_the_call( 2 ), $forge );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $command );
		remove_action( 'woocommerce_before_order_object_save', $hook, PHP_INT_MAX );

		// Assert: stranded, and the re-persisted reservation carries the entry as written.
		$stored = $this->stored_row_of( $order->get_id(), $row['id'] );
		$this->assertSame( 'pending', $this->allocations_of( $result, $refund->get_id() )[0]['status'] );
		$this->assertSame( array( $this->reservation( $refund->get_id(), '10.00' ) ), $this->allocations_of( $stored, $refund->get_id() ) );
		$this->assertSame( $command['fp'], $stored['commands'][ $command['id'] ]['fp'] );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );

		// Arrange: a second refund whose reservation saves all land with the entry rewritten.
		$second  = $this->refund_of( wc_get_order( $order->get_id() ), '5.00' );
		$command = $this->refund_command();
		$forge   = static function ( array $payment ) use ( $command ): array {
			if ( isset( $payment['commands'][ $command['id'] ] ) ) {
				$payment['commands'][ $command['id'] ]['fp'] = 'sha256:' . str_repeat( 'f', 64 );
			}
			return $payment;
		};
		$hook = $this->edit_staged_rows( $order->get_id(), static fn() => true, $forge );

		// Act.
		$error = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $row['id'], $second->get_id(), '5.00', $command );
		remove_action( 'woocommerce_before_order_object_save', $hook, PHP_INT_MAX );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'woo_rxdb_sync_record_locked', $error->get_error_code() );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
		$this->assertSame( array(), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $second->get_id() ) );
	}

	/** #2362 review: a settle save that leaves a second allocation for the refund beside the answer is a miss. */
	public function test_a_save_that_duplicates_the_allocation_is_not_acknowledged(): void {
		// Arrange: the settle's two saves land with the reservation appended again beside the settled allocation.
		list( $order, $row, $refund ) = $this->probed_refundable();
		$refund_id = $refund->get_id();
		$duplicate = $this->reservation( $refund_id, '10.00' );
		$merge     = static function ( array $payment ) use ( $duplicate ): array {
			$payment['refunds'][] = $duplicate;
			return $payment;
		};
		$hook = $this->edit_staged_rows( $order->get_id(), $this->first_saves_after_the_call( 2 ), $merge );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund_id, '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $hook, PHP_INT_MAX );

		// Assert: stranded, and storage holds exactly the one reservation for the refund.
		$this->assertSame( array( $this->reservation( $refund_id, '10.00' ) ), $this->allocations_of( $result, $refund_id ) );
		$this->assertSame( array( $this->reservation( $refund_id, '10.00' ) ), $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund_id ) );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
	}

	/** §6.6 pin 9: Pro's shape, no command: two saves, the hook once with the settled allocation. */
	public function test_refund_without_a_command_takes_two_saves_and_fires_the_hook_once(): void {
		// Arrange: a cash row already projected onto a completed sale, so the derive after the settle moves nothing.
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'pos_cash',
			'50.00',
			array(
				'status'       => 'captured',
				'kind'         => 'cash',
				'capture_mode' => 'manual',
			)
		);
		Ledger::instance()->save( $order, array( $row ) );
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'completed' );
		$order->save();
		$refund   = $this->refund_of( $order, '5.00' );
		$order    = wc_get_order( $order->get_id() );
		$order_id = $order->get_id();
		$saves    = 0;
		$counter  = static function ( $saving ) use ( $order_id, &$saves ): void {
			if ( $saving instanceof \WC_Order && $saving->get_id() === $order_id ) {
				++$saves;
			}
		};
		$seen = array();
		$this->listen_allocated( $refund->get_id(), $seen );
		add_action( 'woocommerce_before_order_object_save', $counter );

		// Act.
		$result = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '5.00' );
		remove_action( 'woocommerce_before_order_object_save', $counter );

		// Assert.
		$this->assertSame( 'succeeded', $result['refunds'][0]['status'] );
		$this->assertSame( 2, $saves );
		$this->assertSame( array( 'succeeded' ), $seen );
		$this->assertSame( array(), $this->stored_row_of( $order_id, $row['id'] )['commands'] );
	}

	/** §6.6 pin 8f: the note of a withdrawal that did not land says the refund was refused, not that it was sent. */
	public function test_a_withdrawal_that_does_not_land_notes_the_refusal_not_a_send(): void {
		// Arrange: the provider refuses; every save after the call keeps the reservation.
		list( $order, $row, $refund ) = $this->probed_refundable();
		Refund_Probe_Gateway::$result = false;
		$force                        = $this->force_allocation( $order->get_id(), $refund->get_id(), $this->reservation( $refund->get_id(), '10.00' ), static fn() => Refund_Probe_Gateway::$calls > 0 );

		// Act.
		Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );

		// Assert.
		$notes = $this->stranded_notes( $order->get_id() );
		$this->assertSame(
			array( sprintf( 'WCPOS: refund #%d for 10.00 %s did not go through wcpos_refund_probe but its reservation could not be removed; check the payment before retrying.', $refund->get_id(), $order->get_currency() ) ),
			$notes
		);
	}

	/** §6.6 pin 10: a withdrawal that does not land still answers the refusal, and the order is noted. */
	public function test_refund_withdrawal_that_does_not_land_answers_the_refusal_and_notes_the_order(): void {
		// Arrange: the provider refuses; every save after the call keeps the reservation.
		list( $order, $row, $refund ) = $this->probed_refundable();
		Refund_Probe_Gateway::$result = false;
		$force                        = $this->force_allocation( $order->get_id(), $refund->get_id(), $this->reservation( $refund->get_id(), '10.00' ), static fn() => Refund_Probe_Gateway::$calls > 0 );

		// Act.
		$error = Ledger::instance()->refund( $order, $row['id'], $refund->get_id(), '10.00', $this->refund_command() );
		remove_action( 'woocommerce_before_order_object_save', $force, PHP_INT_MAX );

		// Assert.
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_provider_error', $error->get_error_code() );
		$this->assertSame( 502, $error->get_error_data()['status'] );
		$this->assertSame( 'pending', $this->allocations_of( $this->stored_row_of( $order->get_id(), $row['id'] ), $refund->get_id() )[0]['status'] );
		$this->assertCount( 1, $this->stranded_notes( $order->get_id() ) );
		$this->assertCount( 1, $this->stranded_logs() );
		$this->assertSame( 1, Refund_Probe_Gateway::$calls );
	}

	/**
	 * A WooCommerce refund object on the order, with no gateway call.
	 *
	 * @param \WC_Order $order  Parent order.
	 * @param string    $amount Refund amount.
	 */
	private function refund_of( \WC_Order $order, string $amount ): \WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount' => $amount,
				'refund_payment' => false,
				'restock_items' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		return $refund;
	}

	private function create_pos_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();

		return $order;
	}

	/** Build a valid record request. */
	private function payment( string $method_id, string $amount, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => $method_id,
				'amount'    => $amount,
			),
			$extra
		);
	}
}

/** Deterministic provider confirmations; no external payment operations. */
class Integrity_Handler extends Manual_Handler {
	public static $calls = 0;
	public static $tips = 'none';
	public static $void_result = null;
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor = parent::describe( $gateway );
		$descriptor['capture']['mode'] = 'integrity';
		$descriptor['capabilities']['tips'] = self::$tips;
		return $descriptor;
	}
	public function intent( array $row, array $context ) {
		++self::$calls;
		if ( isset( $context['observe'] ) ) {
			$context['observe']( $row );
		}
		if ( isset( $context['error'] ) ) {
			return $context['error']; }
		if ( isset( $context['resume'] ) ) {
			$row = array_merge( $row, $context['resume'] ); }
		$row['handoff'] = array( 'token' => 'secret' );
		return $row;
	}
	public function void( array $row, string $reason ) {
		if ( null !== self::$void_result ) {
			$result = array_merge( $row, self::$void_result );
			self::$void_result = null;
			return $result; }
		return parent::void( $row, $reason );
	}
	public function capture( array $row, array $context ) {
		return $context['error'] ?? array_merge( array( 'status' => 'captured' ), $context );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Refund fixtures stay with their tests.
/** A gateway that refunds through process_refund(), counting calls; it can succeed, refuse, or throw. */
class Refund_Probe_Gateway extends \WC_Payment_Gateway {
	/**
	 * What process_refund() answers; a Throwable is thrown.
	 *
	 * @var mixed
	 */
	public static $result = true;
	/**
	 * Provider calls.
	 *
	 * @var int
	 */
	public static $calls = 0;
	/**
	 * Run during the call, before it answers.
	 *
	 * @var callable|null
	 */
	public static $observe = null;

	/** Back to a succeeding, unobserved gateway. */
	public static function reset(): void {
		self::$result  = true;
		self::$calls   = 0;
		self::$observe = null;
	}

	/** Register fixture identity. */
	public function __construct() {
		$this->id       = 'wcpos_refund_probe';
		$this->title    = 'Refund probe';
		$this->supports = array( 'products', 'refunds' );
	}

	/**
	 * Count the call, run the observer, answer as set.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount.
	 * @param string $reason   Reason.
	 * @return bool|\WP_Error
	 * @throws \Throwable When set to throw.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		++self::$calls;
		if ( null !== self::$observe ) {
			( self::$observe )();
		}
		if ( self::$result instanceof \Throwable ) {
			throw self::$result;
		}
		return self::$result;
	}
}

/** A capture-mode handler whose refund answer the test sets, counting calls. */
class Refund_Answer_Handler extends Manual_Handler {
	/**
	 * The answer: a callable( row, refund_id, amount ); null refunds as the manual handler does.
	 *
	 * @var callable|null
	 */
	public static $answer = null;
	/**
	 * Refund calls.
	 *
	 * @var int
	 */
	public static $calls = 0;

	/**
	 * Count the call and answer as set.
	 *
	 * @param array  $row       Payment row.
	 * @param int    $refund_id Refund id.
	 * @param string $amount    Amount.
	 * @return array|\WP_Error
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		++self::$calls;
		return null === self::$answer ? parent::refund( $row, $refund_id, $amount ) : ( self::$answer )( $row, $refund_id, $amount );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
