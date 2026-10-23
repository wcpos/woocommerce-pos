<?php
/**
 * Completion append tests (ADR 0040 rule 1, spec c4-2 §1).
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Origin;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;

/** `Completion_Stamp::append()` and `reopen()` through their interface: one entry per completion, its origin passed in. */
class Test_Completion_Append extends WC_Unit_Test_Case {
	private const REGISTER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
	private const REGISTER_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
	private const REGISTER_C = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
	private const SESSION_A  = 'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa';
	private const SESSION_B  = 'bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb';
	private const SESSION_C  = 'cccccccc-3333-4ccc-8ccc-cccccccccccc';

	/**
	 * Completion numbers announced by `wcpos_sale_completed`, in order.
	 *
	 * @var int[]
	 */
	private $announced = array();

	/** Listen for the fiscal trigger. */
	public function setUp(): void {
		parent::setUp();
		$this->announced = array();
		add_action( 'wcpos_sale_completed', array( $this, 'announce' ), 10, 2 );
	}

	/** Stop listening. */
	public function tearDown(): void {
		remove_action( 'wcpos_sale_completed', array( $this, 'announce' ), 10 );
		parent::tearDown();
	}

	/**
	 * Record each announced completion.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $n     Completion number.
	 */
	public function announce( $order, $n ): void {
		$this->announced[] = $n;
	}

	/** A1: a second append while the completion stands returns it and appends nothing. */
	public function test_append_twice_writes_one_entry_with_the_same_n_and_id(): void {
		// Arrange.
		$order = $this->pos_order();

		// Act.
		$first  = Completion_Stamp::append( $order, new Completion_Origin() );
		$second = Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin() );

		// Assert.
		$this->assertSame( array( $first['n'], $first['id'] ), array( $second['n'], $second['id'] ) );
		$this->assertCount( 1, $this->entries( $order->get_id() ) );
		$this->assertSame( array( 1 ), $this->announced );
		$this->assertSame( $first['at_gmt'], $this->fresh( $order->get_id() )->get_meta( Completion_Stamp::COMPLETED_AT_META_KEY ) );
	}

	/** A2: reopen() marks the latest entry once; the next append is n+1 and leaves entry 1 as stored. */
	public function test_append_after_reopen_appends_the_next_completion(): void {
		// Arrange.
		$order = $this->pos_order();
		Completion_Stamp::append( $order, new Completion_Origin() );
		Completion_Stamp::reopen( $this->fresh( $order->get_id() ) );
		$reopened = $this->entries( $order->get_id() )[0];
		Completion_Stamp::reopen( $this->fresh( $order->get_id() ) );

		// Act.
		$second = Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin() );

		// Assert.
		$entries = $this->entries( $order->get_id() );
		$this->assertSame( 2, $second['n'] );
		$this->assertSame( array( 1, 2 ), array_column( $entries, 'n' ) );
		$this->assertSame( $reopened, $entries[0] );
		$this->assertNotNull( $entries[0]['reopened_at_gmt'] );
		$this->assertNull( $entries[1]['reopened_at_gmt'] );
		$this->assertSame( array( 1, 2 ), $this->announced );
	}

	/** An order completed before the history existed has entry 1 synthesised from its fiscal sale row; the append is 2. */
	public function test_append_on_a_legacy_completed_order_synthesises_entry_1_then_appends_2(): void {
		global $wpdb;
		// Arrange.
		$order = $this->pos_order();
		$store = new Fiscal_Record_Store();
		$store->ensure_installed();
		$wpdb->insert(
			$store->table_name(),
			array(
				'type'            => 'sale',
				'series'          => '',
				'number'          => 901,
				'order_id'        => $order->get_id(),
				'register_id'     => self::REGISTER_A,
				'payload'         => wp_json_encode( array( 'totals' => array( 'total_incl' => 92.95 ) ) ),
				'checksum'        => str_repeat( 'b', 64 ),
				'received_at_gmt' => '2026-10-01 10:00:00',
			)
		);

		// Act.
		$entry = Completion_Stamp::append( $order, new Completion_Origin( array( 'register_id' => self::REGISTER_B ) ) );

		// Assert.
		$entries = $this->entries( $order->get_id() );
		$this->assertSame( 2, $entry['n'] );
		$this->assertTrue( $entries[0]['legacy'] );
		$this->assertSame( self::REGISTER_A, $entries[0]['register'] );
		$this->assertNotNull( $entries[0]['reopened_at_gmt'] );
		$this->assertSame( self::REGISTER_B, $entries[1]['register'] );
		$this->assertSame( array( 2 ), $this->announced );
	}

	/**
	 * The origin's precedence, first non-empty source wins: its own provenance, the stored stamp's, the till
	 * tuple (completion 1 or order-pay only), the latest captured row's register and session.
	 *
	 * @dataProvider precedence
	 *
	 * @param array  $arrange  What the order holds: `stamp`, `till`, `row`, `reopened`.
	 * @param array  $origin   Constructor arguments.
	 * @param string $register The register the entry names.
	 * @param string $session  The session the entry names.
	 */
	public function test_append_names_the_first_source_that_names_any_provenance( array $arrange, array $origin, ?string $register, ?string $session ): void {
		// Arrange.
		$order = $this->pos_order( ! empty( $arrange['till'] ) );
		if ( ! empty( $arrange['row'] ) ) {
			Ledger_Seed::rows(
				$order,
				array(
					array(
						'id'           => wp_generate_uuid4(),
						'method_id'    => 'pos_cash',
						'kind'         => 'cash',
						'capture_mode' => 'manual',
						'amount'       => '92.95',
						'status'       => 'captured',
						'register_id'  => self::REGISTER_C,
						'session_id'   => self::SESSION_C,
					),
				),
				false
			);
		}
		if ( ! empty( $arrange['reopened'] ) ) {
			Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin( array( 'register_id' => self::REGISTER_C ) ) );
			Completion_Stamp::reopen( $this->fresh( $order->get_id() ) );
		}
		if ( ! empty( $arrange['stamp'] ) ) {
			$this->stamp(
				$order->get_id(),
				array(
					'register' => self::REGISTER_B,
					'session' => self::SESSION_B,
				)
			);
		}

		// Act.
		$entry = Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin( ...$origin ) );

		// Assert.
		$this->assertSame( array( $register, $session ), array( $entry['register'], $entry['session'] ) );
	}

	/** The precedence table. */
	public function precedence(): array {
		$body = array(
			'register_id' => self::REGISTER_A,
			'session_id'  => self::SESSION_A,
		);
		return array(
			'its own provenance first'          => array(
				array(
					'stamp' => true,
					'till' => true,
					'row' => true,
				),
				array( $body ),
				self::REGISTER_A,
				self::SESSION_A,
			),
			'then the stored stamp'             => array(
				array(
					'stamp' => true,
					'till' => true,
					'row' => true,
				),
				array(),
				self::REGISTER_B,
				self::SESSION_B,
			),
			'then the till tuple on entry 1'    => array(
				array(
					'till' => true,
					'row' => true,
				),
				array(),
				self::REGISTER_A,
				self::SESSION_A,
			),
			'not the till tuple on entry 2'     => array(
				array(
					'till' => true,
					'row' => true,
					'reopened' => true,
				),
				array(),
				self::REGISTER_C,
				self::SESSION_C,
			),
			'the till tuple on order-pay'       => array(
				array(
					'till' => true,
					'row' => true,
					'reopened' => true,
				),
				array( array(), true ),
				self::REGISTER_A,
				self::SESSION_A,
			),
			'invalid fields dropped, not taken' => array(
				array( 'row' => true ),
				array(
					array(
						'register_id' => 'not-a-uuid',
						'session_id' => self::SESSION_A,
					),
				),
				null,
				self::SESSION_A,
			),
			'nothing names nothing'             => array( array(), array(), null, null ),
		);
	}

	/** A3: an entry whose save does not reach storage throws `completion`, fires nothing, and leaves the stamp for the retry. */
	public function test_append_whose_entry_is_lost_throws_completion_and_fires_nothing(): void {
		// Arrange.
		$order = $this->pos_order();
		$this->stamp( $order->get_id(), array() );
		$lose = static fn( $sql ) => is_string( $sql ) && false !== strpos( $sql, Completion_Stamp::META_KEY ) && preg_match( '/^\s*(INSERT|UPDATE)/i', $sql ) ? 'SELECT 0' : $sql;
		add_filter( 'query', $lose );

		// Act.
		$caught = null;
		try {
			Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin() );
		} catch ( Ledger_Persistence_Exception $error ) {
			$caught = $error;
		} finally {
			remove_filter( 'query', $lose );
		}

		// Assert.
		$this->assertSame( 'completion', $caught ? $caught->stage : null );
		$this->assertSame( array(), $this->announced );
		$this->assertSame( array(), $this->entries( $order->get_id() ) );
		$this->assertNotNull( Gateway_Submission::read_stamp( $this->fresh( $order->get_id() ) ) );
	}

	/** A4: the action fires once, after the entry and its time are stored, while the order lock is held. */
	public function test_append_fires_the_action_once_after_the_entry_is_stored_under_the_lock(): void {
		// Arrange.
		$order    = $this->pos_order();
		$seen     = array();
		$listener = function ( $completed, $n ) use ( &$seen ): void {
			$other = new Order_Lock();
			$taken = $other->acquire( $completed->get_id() );
			if ( $taken ) {
				$other->release( $completed->get_id() );
			}
			$stored = $this->fresh( $completed->get_id() );
			$seen[] = array( $n, $taken, count( Completion_History::of( $stored )->entries() ), '' !== $stored->get_meta( Completion_Stamp::COMPLETED_AT_META_KEY ) );
		};
		add_action( 'wcpos_sale_completed', $listener, 1, 2 );

		// Act.
		Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin() );
		Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin() );
		remove_action( 'wcpos_sale_completed', $listener, 1 );

		// Assert.
		$this->assertSame( array( array( 1, false, 1, true ) ), $seen );
	}

	/** Ruling (a): the cashier on completion n ≥ 2 is the fiscal record's; without one it is the sale's `_pos_user`. */
	public function test_append_cashier_names_the_fiscal_cashier_of_a_later_completion_only(): void {
		// Arrange.
		add_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10, 2 );
		$first_cashier  = self::factory()->user->create();
		$second_cashier = self::factory()->user->create();
		$order          = $this->pos_order();
		$order->update_meta_data( '_pos_user', (string) $first_cashier );
		$order->save_meta_data();

		// Act.
		Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin( array(), false, $second_cashier ) );
		Completion_Stamp::reopen( $this->fresh( $order->get_id() ) );
		Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin( array(), false, $second_cashier ) );
		Completion_Stamp::reopen( $this->fresh( $order->get_id() ) );
		Completion_Stamp::append( $this->fresh( $order->get_id() ), new Completion_Origin() );
		remove_action( 'wcpos_sale_completed', array( Fiscal_Record_Writers::instance(), 'handle_sale_completed' ), 10 );

		// Assert.
		$sales = ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'sale',
			)
		);
		$this->assertSame( array( $second_cashier, $second_cashier, null ), array_map( static fn( $entry ) => $entry['cashier'] ?? null, $this->entries( $order->get_id() ) ) );
		$this->assertSame( array( $first_cashier, $second_cashier, $first_cashier ), array_map( static fn( $sale ) => (int) $sale['cashier_id'], $sales ) );
	}

	/**
	 * A POS order for 92.95, optionally carrying the till tuple (register A, session A).
	 *
	 * @param bool $till Whether the till tuple is stored.
	 */
	private function pos_order( bool $till = false ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		if ( $till ) {
			$order->update_meta_data( '_wcpos_register', self::REGISTER_A );
			$order->update_meta_data( '_wcpos_session', self::SESSION_A );
			$order->update_meta_data( '_wcpos_sale_time', '2026-10-19T08:00:00Z' );
			$order->update_meta_data( '_wcpos_sale_tz', 'UTC' );
			$order->update_meta_data( '_wcpos_sale_counter', '7' );
		}
		$order->save();
		return $this->fresh( $order->get_id() );
	}

	/**
	 * Store an awaiting stamp as a sent submit leaves it.
	 *
	 * @param int   $id         Order id.
	 * @param array $provenance Entry-shaped provenance.
	 */
	private function stamp( int $id, array $provenance ): void {
		$order = $this->fresh( $id );
		$order->update_meta_data(
			Gateway_Submission::STAMP_META_KEY,
			array(
				'attempt_id' => wp_generate_uuid4(),
				'method_id'  => 'pos_cash',
				'cashier_id' => 1,
				'provenance' => $provenance,
			)
		);
		$order->save_meta_data();
	}

	/**
	 * The stored entries.
	 *
	 * @param int $id Order id.
	 */
	private function entries( int $id ): array {
		return Completion_History::of( $this->fresh( $id ) )->entries();
	}

	/**
	 * The order and its meta as storage holds them.
	 *
	 * @param int $id Order id.
	 */
	private function fresh( int $id ): \WC_Order {
		$order = wc_get_order( $id );
		$order->read_meta_data( true );
		return $order;
	}
}
