<?php
/**
 * Every closure writer is serialised against the close (roadmap#390).
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;

/** A sale, movement, refund or reallocation committing during a close lands in its figures or as a correction. */
class Test_Closure_Write_Race extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as fixture_tear_down;
	}
	use Money_Route_Envelope;

	/** The writers' retry hooks, named here so the file runs against code that lacks the constants. */
	private const REFUND_RETRY = 'wcpos_fiscal_refund_retry';
	private const ALLOCATION_RETRY = 'wcpos_fiscal_allocation_retry';

	/**
	 * Orders whose records need cleanup after a committed close.
	 *
	 * @var int[]
	 */
	private $order_ids = array();

	/** Enable the card tender, grant closure rights, and leave the writers to the test. */
	public function setUp(): void {
		add_filter(
			'wcpos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['pos_card']['enabled'] = true;
				return $settings;
			}
		);
		parent::setUp();
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_closures' );
		$writers = Fiscal_Record_Writers::instance();
		// Each test calls the writer it races, at the moment it chooses.
		remove_action( 'wcpos_sale_completed', array( $writers, 'handle_sale_completed' ), 10 );
		remove_action( 'woocommerce_order_refunded', array( $writers, 'handle_refund' ), 10 );
		remove_action( 'wcpos_refund_allocated', array( $writers, 'handle_allocation' ), 10 );
		// The constructor's own registration is pinned by test_constructor_registers_retry_hooks_with_their_argument_counts.
		add_action( self::REFUND_RETRY, array( $writers, 'handle_refund' ), 10, 3 );
		add_action( self::ALLOCATION_RETRY, array( $writers, 'retry_allocation' ), 10, 5 );
		foreach ( Fiscal_Record_Store::TYPES as $type ) {
			delete_option( 'wcpos_fiscal_sequence_' . $type );
		}
	}

	/** Remove committed records of orders whose register the fixture does not own. */
	public function tearDown(): void {
		$this->fixture_tear_down();
		global $wpdb;
		foreach ( $this->order_ids as $id ) {
			$wpdb->delete( ( new Fiscal_Record_Store() )->table_name(), array( 'order_id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	/** A second database connection: the other request. */
	private function contender(): \wpdb {
		return new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	}

	/**
	 * The register's named lock.
	 *
	 * @param array $session Session row.
	 */
	private function register_lock( array $session ): string {
		return 'wcpos_register_' . strtolower( $session['register_id'] );
	}

	/**
	 * The order's named lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private function order_lock( int $order_id ): string {
		return sprintf( 'wcpos_order_%d_%d', $order_id, get_current_blog_id() );
	}

	/**
	 * Try a lock from the contender without waiting. The spacing differs from the writers' SQL so
	 * no race filter mistakes it for theirs.
	 *
	 * @param \wpdb  $contender Connection.
	 * @param string $name      Lock name.
	 */
	private function take( \wpdb $contender, string $name ): string {
		return (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
	}

	/**
	 * Release a contender's lock.
	 *
	 * @param \wpdb  $contender Connection.
	 * @param string $name      Lock name.
	 */
	private function free( \wpdb $contender, string $name ): void {
		$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Whether another connection can take the lock at once.
	 *
	 * @param string $name Lock name.
	 */
	private function is_free( string $name ): bool {
		$contender = $this->contender();
		try {
			$taken = '1' === $this->take( $contender, $name );
			if ( $taken ) {
				$this->free( $contender, $name );
			}
			return $taken;
		} finally {
			$contender->close();
		}
	}

	/**
	 * Commit a closure row straight onto the session: a close that read its figures before this money.
	 *
	 * @param array $session Session row.
	 * @return string Closure id.
	 */
	private function land_closure_row( array $session ): string {
		global $wpdb;
		$id = wp_generate_uuid4();
		$wpdb->update(
			( new Register_Session_Store() )->table_name(),
			array(
				'closure_id' => $id,
				'number' => 1,
				'status' => 'closed',
				'closed_at_gmt' => '2026-09-11 12:00:00',
				'closed_by' => get_current_user_id(),
				'closure' => wp_json_encode(
					array(
						'counted' => array( 'cash' => '101.0000' ),
						'expected' => array( 'cash' => '100.0000' ),
					)
				),
			),
			array( 'id' => $session['id'] )
		);
		return $id;
	}

	/**
	 * Run a writer while a close lands at its register lock request, and probe its closure read.
	 *
	 * The landing runs once, when the writer asks for the register lock. Each read of the session's
	 * closure has a contender try the register lock without waiting: '0' means the writer held it.
	 *
	 * @param array         $session Session row.
	 * @param callable|null $land    The close.
	 * @param callable      $work    The writer.
	 * @return array{landed:bool,probes:string[]}
	 */
	private function race( array $session, ?callable $land, callable $work ): array {
		$lock      = "GET_LOCK( '" . $this->register_lock( $session ) . "'";
		$read      = ', closure FROM ' . ( new Register_Session_Store() )->table_name() . ' WHERE number IS NOT NULL AND id =';
		$name      = $this->register_lock( $session );
		$contender = $this->contender();
		$state     = array(
			'landed' => false,
			'probes' => array(),
		);
		$filter    = function ( $sql ) use ( $lock, $read, $name, $land, $contender, $session, &$state ) {
			if ( null !== $land && ! $state['landed'] && false !== strpos( $sql, $lock ) ) {
				$state['landed'] = true;
				$land();
			} elseif ( false !== strpos( $sql, $read ) && false !== stripos( $sql, $session['id'] ) ) {
				$got = $this->take( $contender, $name );
				if ( '1' === $got ) {
					$this->free( $contender, $name );
				}
				$state['probes'][] = $got;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$work();
		} finally {
			remove_filter( 'query', $filter );
			$contender->close();
		}
		return $state;
	}

	/**
	 * Run a writer while the contender holds the register lock; the writer's ten-second wait becomes none.
	 *
	 * @param array    $session Session row.
	 * @param callable $work    The writer.
	 */
	private function with_register_held( array $session, callable $work ): void {
		$name      = $this->register_lock( $session );
		$waiting   = "GET_LOCK( '" . $name . "', 10 )";
		$now       = "GET_LOCK( '" . $name . "', 0 )";
		$rewrite   = static fn( $sql ) => str_replace( $waiting, $now, $sql );
		$contender = $this->contender();
		try {
			$this->assertSame( '1', $this->take( $contender, $name ) );
			add_filter( 'query', $rewrite );
			try {
				$work();
			} finally {
				remove_filter( 'query', $rewrite );
			}
		} finally {
			$this->free( $contender, $name );
			$contender->close();
		}
	}

	/**
	 * The named-lock requests a writer makes.
	 *
	 * @param callable $work The writer.
	 * @return string[] SQL.
	 */
	private function lock_requests( callable $work ): array {
		$requests = array();
		$trace    = static function ( $sql ) use ( &$requests ) {
			if ( false !== strpos( $sql, 'GET_LOCK' ) ) {
				$requests[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $trace );
		try {
			$work();
		} finally {
			remove_filter( 'query', $trace );
		}
		return $requests;
	}

	/**
	 * Assert the order lock is requested before the register lock.
	 *
	 * @param string[] $requests Lock SQL, in order.
	 * @param int      $order_id Order ID.
	 * @param array    $session  Session row.
	 */
	private function assert_order_lock_first( array $requests, int $order_id, array $session ): void {
		$order_at    = null;
		$register_at = null;
		foreach ( $requests as $i => $sql ) {
			if ( null === $order_at && false !== strpos( $sql, "'" . $this->order_lock( $order_id ) . "'" ) ) {
				$order_at = $i;
			}
			if ( null === $register_at && false !== strpos( $sql, "'" . $this->register_lock( $session ) . "'" ) ) {
				$register_at = $i;
			}
		}
		$this->assertNotNull( $order_at, implode( "\n", $requests ) );
		$this->assertNotNull( $register_at, implode( "\n", $requests ) );
		$this->assertLessThan( $register_at, $order_at );
	}

	/**
	 * Fiscal records of a type against a closure.
	 *
	 * @param string $type       Record type.
	 * @param string $closure_id Closure id.
	 */
	private function records( string $type, string $closure_id ): array {
		return ( new Fiscal_Record_Store() )->list(
			array(
				'type' => $type,
				'closure_id' => $closure_id,
			)
		);
	}

	/**
	 * The order's sale records.
	 *
	 * @param int $order_id Order ID.
	 */
	private function sales( int $order_id ): array {
		return ( new Fiscal_Record_Store() )->list(
			array(
				'order_id' => $order_id,
				'type' => 'sale',
			)
		);
	}

	/**
	 * The argument lists of every scheduled event on a hook.
	 *
	 * @param string $hook Hook name.
	 */
	private function scheduled( string $hook ): array {
		$found = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) ( $events[ $hook ] ?? array() ) as $event ) {
				$found[] = $event['args'];
			}
		}
		return $found;
	}

	/** The writers' retry cap. */
	private function max_attempts(): int {
		return (int) ( new \ReflectionClassConstant( Fiscal_Record_Writers::class, 'LATE_RETRY_MAX_ATTEMPTS' ) )->getValue();
	}

	/**
	 * Give a fixture order a completion entry without completing it.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function seed_completion( \WC_Order $order ): void {
		Completion_Fixture::seed( $order );
	}

	/**
	 * Move a session to counting, as the close requires.
	 *
	 * @param array $session Session row.
	 */
	private function counting( array $session ): array {
		return ( new Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'counting_started_at_gmt' => '2026-09-11 11:00:00',
			)
		);
	}

	/**
	 * A POS order with a session's sale legs and its completion entry.
	 *
	 * @param array $session Session row.
	 */
	private function completed_sale( array $session ): \WC_Order {
		$order = $this->closure_ledger( $session );
		$this->order_ids[] = $order->get_id();
		$this->seed_completion( $order );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Take a captured payment through the payment route.
	 *
	 * @param array  $session Session fixture.
	 * @param string $method  Tender method.
	 * @return array{0:\WC_Order,1:string} Order and payment id.
	 */
	private function sale( array $session, string $method = 'pos_cash' ): array {
		$order = wc_create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_total( '50' );
		$order->update_meta_data( '_wcpos_session', $session['id'] );
		$order->update_meta_data( '_wcpos_register', $session['register_id'] );
		$order->save();
		$this->order_ids[] = $order->get_id();
		$id      = wp_generate_uuid4();
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'payment' => array(
						'id' => $id,
						'method_id' => $method,
						'amount' => '50',
						'session_id' => $session['id'],
					),
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return array( wc_get_order( $order->get_id() ), $id );
	}

	/**
	 * A refund stamped with its session, as Pro stamps it.
	 *
	 * @param \WC_Order $order   Parent order.
	 * @param array     $session Refund session.
	 */
	private function refund( \WC_Order $order, array $session ): \WC_Order_Refund {
		$stamp = static function ( $refund ) use ( $session ) {
			$refund->update_meta_data( '_wcpos_session', strtoupper( $session['id'] ) );
			$refund->update_meta_data( '_wcpos_register', strtoupper( $session['register_id'] ) );
		};
		add_action( 'woocommerce_create_refund', $stamp );
		try {
			$refund = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount' => '10',
					'reason' => 'Damaged',
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
			return $refund;
		} finally {
			remove_action( 'woocommerce_create_refund', $stamp );
		}
	}

	/**
	 * A card sale with a refund allocated to the card, its refund record written.
	 *
	 * @param array $session Open session.
	 * @return array{0:\WC_Order,1:string,2:\WC_Order_Refund} Order, payment id, refund.
	 */
	private function allocated_card_refund( array $session ): array {
		list( $order, $payment ) = $this->sale( $session, 'pos_card' );
		$refund  = $this->refund( $order, $session );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $payment . '/refund' );
		$request->set_body_params(
			$this->envelope(
				$order,
				array(
					'refund_id' => $refund->get_id(),
					'amount' => '10',
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
		return array( wc_get_order( $order->get_id() ), $payment, wc_get_order( $refund->get_id() ) );
	}

	/**
	 * The ledger row an allocation settled on.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $payment Payment id.
	 */
	private function row( \WC_Order $order, string $payment ): array {
		$row = Ledger::instance()->find( wc_get_order( $order->get_id() ), $payment );
		$this->assertIsArray( $row );
		return $row;
	}

	/**
	 * A close request body for the session.
	 *
	 * @param array $session Session row.
	 */
	private function close_body( array $session ): array {
		$body = $this->closure_fields( $session );
		foreach ( array( 'opened_at', 'closed_at', 'printed_at' ) as $key ) {
			$body[ $key ] = null === $body[ $key . '_gmt' ] ? null : str_replace( ' ', 'T', $body[ $key . '_gmt' ] ) . 'Z';
			unset( $body[ $key . '_gmt' ] );
		}
		return $body;
	}

	/**
	 * Close through the REST write lane.
	 *
	 * @param array $body Request body.
	 */
	private function post_close( array $body ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/closures' );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/**
	 * The closure stamps on an order's ledger rows, read from storage.
	 *
	 * @param int $order_id Order ID.
	 */
	private function stamps( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$order->read_meta_data( true );
		return array_values( array_filter( array_column( Ledger::instance()->read( $order ), 'closure_id' ) ) );
	}

	/**
	 * The argument count a callback is registered with at priority 10, or null when it is absent.
	 *
	 * @param string $hook     Hook name.
	 * @param array  $callback Callback, matched by instance identity and method name.
	 */
	private function accepted_args( string $hook, array $callback ): ?int {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook ]->callbacks[10] ) ) {
			return null;
		}
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks[10] as $entry ) {
			$function = $entry['function'];
			if ( is_array( $function ) && $function[0] === $callback[0] && $function[1] === $callback[1] ) {
				return $entry['accepted_args'];
			}
		}
		return null;
	}

	/** The constructor registers both retry hooks with the argument counts the retries pass. */
	public function test_constructor_registers_retry_hooks_with_their_argument_counts(): void {
		$original = Fiscal_Record_Writers::instance();
		$instance = new \ReflectionProperty( Fiscal_Record_Writers::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		// Every hook the constructor adds, so the fresh instance leaves none behind.
		$registered = array(
			'wcpos_sale_completed'               => 'handle_sale_completed',
			'wcpos_payment_voided'               => 'handle_void',
			'woocommerce_order_status_cancelled' => 'handle_cancellation',
			'wcpos_refund_allocated'             => 'handle_allocation',
			'woocommerce_order_refunded'         => 'handle_refund',
			self::REFUND_RETRY                   => 'handle_refund',
			self::ALLOCATION_RETRY               => 'retry_allocation',
		);
		$fresh = null;
		try {
			$fresh = Fiscal_Record_Writers::instance();
			$this->assertNotSame( $original, $fresh );
			$this->assertSame( 3, $this->accepted_args( self::REFUND_RETRY, array( $fresh, 'handle_refund' ) ) );
			$this->assertSame( 5, $this->accepted_args( self::ALLOCATION_RETRY, array( $fresh, 'retry_allocation' ) ) );
		} finally {
			$instance->setValue( null, $original );
			if ( null !== $fresh ) {
				foreach ( $registered as $hook => $method ) {
					remove_action( $hook, array( $fresh, $method ), 10 );
				}
			}
		}
		foreach ( $registered as $hook => $method ) {
			$this->assertNull( $this->accepted_args( $hook, array( $fresh, $method ) ), $hook );
		}
	}

	/** A close landing while the sale writer waits for the register leaves one late_sale. */
	public function test_sale_closure_lands_at_register_lock_writes_one_late_sale(): void {
		// Arrange.
		$session    = $this->closure_session();
		$order      = $this->completed_sale( $session );
		$closure_id = '';

		// Act.
		$state = $this->race(
			$session,
			function () use ( $session, &$closure_id ): void {
				$closure_id = $this->land_closure_row( $session );
			},
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->record_sale( wc_get_order( $order->get_id() ) );
			}
		);

		// Assert.
		$this->assertTrue( $state['landed'] );
		$this->assertNotEmpty( $state['probes'] );
		$this->assertSame( array( '0' ), array_values( array_unique( $state['probes'] ) ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$late = $this->records( 'late_sale', $closure_id );
		$this->assertCount( 1, $late );
		$this->assertSame( $order->get_id(), $late[0]['order_id'] );
	}

	/** A close landing while a cash movement waits for the register leaves one late_movement. */
	public function test_cash_movement_closure_lands_at_register_lock_writes_one_late_movement(): void {
		// Arrange.
		$session = $this->closure_session();
		$closure = null;

		// Act.
		$state = $this->race(
			$session,
			function () use ( $session, &$closure ): void {
				$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
			},
			static function () use ( $session ): void {
				( new Cash_Movement_Store() )->create(
					array(
						'id' => wp_generate_uuid4(),
						'session_id' => $session['id'],
						'type' => 'paid_in',
						'amount' => '7',
						'reason' => 'During the close',
						'actor' => get_current_user_id(),
						'created_at_gmt' => '2026-09-11 10:00:00',
					)
				);
			}
		);

		// Assert.
		$this->assertTrue( $state['landed'] );
		$this->assertIsArray( $closure );
		$this->assertCount( 1, $this->records( 'late_movement', $closure['id'] ) );
	}

	/** A close landing while the refund writer waits for the register leaves one late_refund. */
	public function test_refund_closure_lands_at_register_lock_writes_one_late_refund(): void {
		// Arrange.
		$session    = $this->closure_session( null, 'open' );
		$order      = $this->sale( $session )[0];
		$refund     = $this->refund( $order, $session );
		$closure_id = '';

		// Act.
		$state = $this->race(
			$session,
			function () use ( $session, &$closure_id ): void {
				$closure_id = $this->land_closure_row( $session );
			},
			static function () use ( $order, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
			}
		);

		// Assert.
		$this->assertTrue( $state['landed'] );
		$this->assertNotEmpty( $state['probes'] );
		$this->assertSame( array( '0' ), array_values( array_unique( $state['probes'] ) ) );
		$this->assertNotNull( ( new Fiscal_Record_Store() )->find_refund( $order->get_id(), $refund->get_id() ) );
		$late = $this->records( 'late_refund', $closure_id );
		$this->assertCount( 1, $late );
		$this->assertSame( $refund->get_id(), $late[0]['refund_id'] );
	}

	/** A close that counted the refund, landing while the allocation writer waits, leaves one reallocation. */
	public function test_reallocation_closure_lands_at_register_lock_writes_one_reallocation(): void {
		// Arrange: the writer holds the refund as loaded before the close stamped it.
		$session                          = $this->closure_session( null, 'open' );
		list( $order, $payment, $refund ) = $this->allocated_card_refund( $session );
		$row        = $this->row( $order, $payment );
		$closure_id = '';

		// Act.
		$state = $this->race(
			$session,
			function () use ( $session, $refund, &$closure_id ): void {
				$closure_id = $this->land_closure_row( $session );
				$counted    = wc_get_order( $refund->get_id() );
				$counted->update_meta_data( '_wcpos_closure', $closure_id );
				$counted->save_meta_data();
			},
			static function () use ( $order, $row, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_allocation( $order, $row, $refund, '10.00' );
			}
		);

		// Assert.
		$this->assertTrue( $state['landed'] );
		$this->assertNotEmpty( $state['probes'] );
		$this->assertSame( array( '0' ), array_values( array_unique( $state['probes'] ) ) );
		$late = $this->records( 'late_refund', $closure_id );
		$this->assertCount( 1, $late );
		$this->assertSame( 'reallocation', $late[0]['payload']['kind'] );
		$this->assertTrue( $this->is_free( $this->register_lock( $session ) ) );
	}

	/** The sale writer takes the order lock before the register lock. */
	public function test_sale_writer_lock_order_takes_order_before_register(): void {
		// Arrange.
		$session = $this->closure_session();
		$order   = $this->completed_sale( $session );

		// Act.
		$requests = $this->lock_requests(
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->record_sale( wc_get_order( $order->get_id() ) );
			}
		);

		// Assert.
		$this->assert_order_lock_first( $requests, $order->get_id(), $session );
	}

	/** The refund writer takes the order lock before the register lock. */
	public function test_refund_writer_lock_order_takes_order_before_register(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$order   = $this->sale( $session )[0];
		$refund  = $this->refund( $order, $session );

		// Act.
		$requests = $this->lock_requests(
			static function () use ( $order, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
			}
		);

		// Assert.
		$this->assert_order_lock_first( $requests, $order->get_id(), $session );
	}

	/** The allocation writer takes the order lock before the register lock. */
	public function test_allocation_writer_lock_order_takes_order_before_register(): void {
		// Arrange.
		$session                          = $this->closure_session( null, 'open' );
		list( $order, $payment, $refund ) = $this->allocated_card_refund( $session );
		$row = $this->row( $order, $payment );

		// Act.
		$requests = $this->lock_requests(
			static function () use ( $order, $row, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_allocation( $order, $row, $refund, '10.00' );
			}
		);

		// Assert.
		$this->assert_order_lock_first( $requests, $order->get_id(), $session );
	}

	/** A close that stamps the refund while the writer holds a stale copy leaves no late_refund. */
	public function test_refund_counted_by_close_landing_at_register_lock_writes_no_late_refund(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$order   = $this->sale( $session )[0];
		$refund  = $this->refund( $order, $session );
		$session = $this->counting( $session );
		$closure = null;

		// Act.
		$state = $this->race(
			$session,
			function () use ( $session, &$closure ): void {
				$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
			},
			static function () use ( $order, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
			}
		);

		// Assert.
		$this->assertTrue( $state['landed'] );
		$this->assertIsArray( $closure );
		$stored = wc_get_order( $refund->get_id() );
		$stored->read_meta_data( true );
		$this->assertSame( $closure['id'], $stored->get_meta( '_wcpos_closure', true ) );
		$this->assertNotNull( ( new Fiscal_Record_Store() )->find_refund( $order->get_id(), $refund->get_id() ) );
		$this->assertSame( array(), $this->records( 'late_refund', $closure['id'] ) );
	}

	/**
	 * The order's parked sale documents, read from storage.
	 *
	 * @param int $order_id Order ID.
	 */
	private function parked( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$order->read_meta_data( true );
		$raw = $order->get_meta( Fiscal_Record_Writers::PENDING_META_KEY, true );
		return '' === $raw ? array() : (array) json_decode( $raw, true );
	}

	/**
	 * The order's parked map as stored.
	 *
	 * @param int $order_id Order ID.
	 */
	private function parked_json( int $order_id ): string {
		$order = wc_get_order( $order_id );
		$order->read_meta_data( true );
		return (string) $order->get_meta( Fiscal_Record_Writers::PENDING_META_KEY, true );
	}

	/**
	 * The id of the order's completion 1.
	 *
	 * @param int $order_id Order ID.
	 */
	private function completion_id( int $order_id ): string {
		$order = wc_get_order( $order_id );
		$order->read_meta_data( true );
		return strtolower( (string) Completion_History::of( $order )->by_n( 1 )['id'] );
	}

	/**
	 * Record the types wcpos_fiscal_record_failed signals.
	 *
	 * @param array $failed Collected types.
	 */
	private function observe_failures( array &$failed ): void {
		add_action(
			'wcpos_fiscal_record_failed',
			static function ( $type ) use ( &$failed ): void {
				$failed[] = $type;
			}
		);
	}

	/** A sale that cannot take the register lock parks its document, signals, and the sweep's retry writes both. */
	public function test_sale_register_lock_busy_parks_document_until_retry(): void {
		// Arrange: the session is already closed, so the sale is late.
		$session = $this->closure_session();
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$order   = $this->completed_sale( $session );
		$failed  = array();
		$this->observe_failures( $failed );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
			}
		);

		// Assert.
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$this->assertSame( array( $this->completion_id( $order->get_id() ) ), array_keys( $this->parked( $order->get_id() ) ) );
		$this->assertContains( 'sale', $failed );
		$this->assertTrue( $this->is_free( $this->order_lock( $order->get_id() ) ) );
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertCount( 1, $this->records( 'late_sale', $closure['id'] ) );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A sale turned away by a close, then reopened before the sweep, is still written from its parked document. */
	public function test_sale_register_lock_busy_then_reopened_retry_writes_parked_sale(): void {
		// Arrange.
		$session = $this->closure_session();
		$order   = $this->completed_sale( $session );
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
			}
		);
		$reopened = wc_get_order( $order->get_id() );
		Completion_Fixture::reopen( $reopened );
		$this->assertNull( Completion_History::of( wc_get_order( $order->get_id() ) )->standing() );

		// Act.
		Fiscal_Record_Writers::instance()->retry( wc_get_order( $order->get_id() ) );

		// Assert.
		$sales = $this->sales( $order->get_id() );
		$this->assertCount( 1, $sales );
		$this->assertSame( $this->completion_id( $order->get_id() ), $sales[0]['source_id'] );
		$this->assertSame( array(), $this->parked( $order->get_id() ) );
	}

	/** A sale turned away by a close leaves a document already parked for its completion as it was. */
	public function test_sale_register_lock_busy_with_parked_document_leaves_it_unchanged(): void {
		// Arrange: the sale insert failed and parked its document.
		$session = $this->closure_session();
		$order   = $this->completed_sale( $session );
		$stuck   = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
		// A recovery the close turned away stamps its attempt, so a rebuilt document would differ from it.
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->recover( wc_get_order( $order->get_id() ) );
			}
		);
		$before = $this->parked_json( $order->get_id() );
		$this->assertStringContainsString( '"attempted_at"', $before );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
			}
		);

		// Assert.
		$this->assertSame( $before, $this->parked_json( $order->get_id() ) );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
	}

	/** A sale turned away while another request holds its order parks nothing and still signals. */
	public function test_sale_register_lock_busy_and_order_held_parks_nothing_and_signals(): void {
		// Arrange.
		$session   = $this->closure_session();
		$order     = $this->completed_sale( $session );
		$name      = $this->order_lock( $order->get_id() );
		$rewrite   = static fn( $sql ) => str_replace( "GET_LOCK('" . $name . "', 5)", "GET_LOCK('" . $name . "', 0)", $sql );
		$failed    = array();
		$this->observe_failures( $failed );
		$contender = $this->contender();

		try {
			$this->assertSame( '1', $this->take( $contender, $name ) );

			// Act.
			$started = microtime( true );
			add_filter( 'query', $rewrite );
			try {
				$this->with_register_held(
					$session,
					static function () use ( $order ): void {
						Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
					}
				);
			} finally {
				remove_filter( 'query', $rewrite );
			}
			$elapsed = microtime( true ) - $started;
		} finally {
			$this->free( $contender, $name );
			$contender->close();
		}

		// Assert.
		$this->assertLessThan( 2.0, $elapsed );
		$this->assertSame( '', $this->parked_json( $order->get_id() ) );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$this->assertContains( 'sale', $failed );
	}

	/** A sale turned away by a close whose completion no longer stands parks nothing. */
	public function test_sale_register_lock_busy_completion_not_standing_parks_nothing(): void {
		// Arrange.
		$session = $this->closure_session();
		$order   = $this->completed_sale( $session );
		Completion_Fixture::reopen( $order );
		$entry = Completion_History::of( wc_get_order( $order->get_id() ) )->by_n( 1 );
		$this->assertNotNull( $entry );
		$failed = array();
		$this->observe_failures( $failed );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
			}
		);

		// Assert.
		$this->assertSame( '', $this->parked_json( $order->get_id() ) );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$this->assertContains( 'sale', $failed );
	}

	/** A sale turned away by a close after its row was written, as on a retry for its late correction, parks nothing. */
	public function test_sale_register_lock_busy_with_sale_row_parks_nothing(): void {
		// Arrange: the sale row is written.
		$session = $this->closure_session();
		$order   = $this->completed_sale( $session );
		Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$failed = array();
		$this->observe_failures( $failed );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->handle_sale_completed( wc_get_order( $order->get_id() ), 1 );
			}
		);

		// Assert.
		$this->assertSame( '', $this->parked_json( $order->get_id() ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertContains( 'sale', $failed );
	}

	/** A parked document whose register is locked stays parked; the next recovery writes it and its correction. */
	public function test_recover_register_lock_busy_keeps_document_parked(): void {
		// Arrange: closed session; the sale insert failed and parked its document.
		$session = $this->closure_session();
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$order   = $this->completed_sale( $session );
		$stuck   = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10, 2 );
		try {
			Fiscal_Record_Writers::instance()->record_sale( wc_get_order( $order->get_id() ) );
		} finally {
			remove_filter( 'pre_update_option_wcpos_fiscal_sequence_sale', $stuck, 10 );
		}
		$fresh = wc_get_order( $order->get_id() );
		$fresh->read_meta_data( true );
		$this->assertNotEmpty( $fresh->get_meta( Fiscal_Record_Writers::PENDING_META_KEY, true ) );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order ): void {
				Fiscal_Record_Writers::instance()->recover( wc_get_order( $order->get_id() ) );
			}
		);

		// Assert.
		$fresh = wc_get_order( $order->get_id() );
		$fresh->read_meta_data( true );
		$this->assertNotEmpty( $fresh->get_meta( Fiscal_Record_Writers::PENDING_META_KEY, true ) );
		$this->assertSame( array(), $this->sales( $order->get_id() ) );
		$this->assertTrue( $this->is_free( $this->order_lock( $order->get_id() ) ) );
		Fiscal_Record_Writers::instance()->recover( $fresh );
		$this->assertTrue( $this->is_free( $this->register_lock( $session ) ) );
		$fresh = wc_get_order( $order->get_id() );
		$fresh->read_meta_data( true );
		$this->assertSame( '', $fresh->get_meta( Fiscal_Record_Writers::PENDING_META_KEY, true ) );
		$this->assertCount( 1, $this->sales( $order->get_id() ) );
		$this->assertCount( 1, $this->records( 'late_sale', $closure['id'] ) );
	}

	/**
	 * A closed session with a refund taken after its close.
	 *
	 * @return array{0:array,1:\WC_Order,2:\WC_Order_Refund} Closure, order, refund.
	 */
	private function late_refund_fixture(): array {
		$session = $this->closure_session( null, 'open' );
		$order   = $this->sale( $session )[0];
		$session = $this->counting( $session );
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$refund  = $this->refund( $order, $session );
		return array( $closure, $order, $refund, $session );
	}

	/** A refund whose register is locked keeps its record, schedules a retry, and the retry writes late_refund. */
	public function test_refund_register_lock_busy_schedules_retry_that_writes_late_refund(): void {
		// Arrange.
		list( $closure, $order, $refund, $session ) = $this->late_refund_fixture();
		$failed  = array();
		$observe = static function ( $type ) use ( &$failed ): void {
			$failed[] = $type;
		};
		add_action( 'wcpos_fiscal_record_failed', $observe );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id() );
			}
		);

		// Assert.
		$this->assertNotNull( ( new Fiscal_Record_Store() )->find_refund( $order->get_id(), $refund->get_id() ) );
		$this->assertSame( array(), $this->records( 'late_refund', $closure['id'] ) );
		$this->assertContains( 'late_refund', $failed );
		$this->assertSame( array( array( $order->get_id(), $refund->get_id(), 2 ) ), $this->scheduled( self::REFUND_RETRY ) );
		$this->assertTrue( $this->is_free( $this->order_lock( $order->get_id() ) ) );
		do_action( self::REFUND_RETRY, $order->get_id(), $refund->get_id(), 2 );
		$this->assertCount( 1, $this->records( 'late_refund', $closure['id'] ) );
	}

	/**
	 * A card sale whose refund the close counted, its allocation settled after the close.
	 *
	 * @return array{0:array,1:\WC_Order,2:string,3:\WC_Order_Refund,4:array} Closure, order, payment, refund, session.
	 */
	private function reallocation_fixture(): array {
		$session                          = $this->closure_session( null, 'open' );
		list( $order, $payment, $refund ) = $this->allocated_card_refund( $session );
		$session                          = $this->counting( $session );
		$closure                          = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$refund                           = wc_get_order( $refund->get_id() );
		$refund->read_meta_data( true );
		$this->assertSame( $closure['id'], $refund->get_meta( '_wcpos_closure', true ) );
		return array( $closure, wc_get_order( $order->get_id() ), $payment, $refund, $session );
	}

	/** An allocation whose register is locked schedules a retry, and the retry writes the reallocation. */
	public function test_allocation_register_lock_busy_schedules_retry_that_writes_reallocation(): void {
		// Arrange.
		list( $closure, $order, $payment, $refund, $session ) = $this->reallocation_fixture();
		$row = $this->row( $order, $payment );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order, $row, $refund ): void {
				Fiscal_Record_Writers::instance()->handle_allocation( $order, $row, $refund, '10.00' );
			}
		);

		// Assert.
		$this->assertSame( array(), $this->records( 'late_refund', $closure['id'] ) );
		$scheduled = $this->scheduled( self::ALLOCATION_RETRY );
		$this->assertSame( array( array( $order->get_id(), strtolower( $payment ), $refund->get_id(), '10.00', 2 ) ), $scheduled );
		$this->assertTrue( $this->is_free( $this->order_lock( $order->get_id() ) ) );
		do_action_ref_array( self::ALLOCATION_RETRY, $scheduled[0] );
		$this->assertTrue( $this->is_free( $this->register_lock( $session ) ) );
		$late = $this->records( 'late_refund', $closure['id'] );
		$this->assertCount( 1, $late );
		$this->assertSame( 'reallocation', $late[0]['payload']['kind'] );
	}

	/** Replaying the refund retry hook leaves one refund record and one late_refund. */
	public function test_refund_retry_hook_replayed_writes_one_late_refund(): void {
		// Arrange.
		list( $closure, $order, $refund ) = $this->late_refund_fixture();

		// Act.
		do_action( self::REFUND_RETRY, $order->get_id(), $refund->get_id(), 2 );
		do_action( self::REFUND_RETRY, $order->get_id(), $refund->get_id(), 2 );

		// Assert.
		$this->assertCount(
			1,
			( new Fiscal_Record_Store() )->list(
				array(
					'type' => 'refund',
					'order_id' => $order->get_id(),
				)
			)
		);
		$this->assertCount( 1, $this->records( 'late_refund', $closure['id'] ) );
	}

	/** Replaying the allocation retry hook leaves one reallocation. */
	public function test_allocation_retry_hook_replayed_writes_one_reallocation(): void {
		// Arrange.
		list( $closure, $order, $payment, $refund ) = $this->reallocation_fixture();
		$args = array( $order->get_id(), $payment, $refund->get_id(), '10.00', 2 );

		// Act.
		do_action_ref_array( self::ALLOCATION_RETRY, $args );
		do_action_ref_array( self::ALLOCATION_RETRY, $args );

		// Assert.
		$late = $this->records( 'late_refund', $closure['id'] );
		$this->assertCount( 1, $late );
		$this->assertSame( 'reallocation', $late[0]['payload']['kind'] );
	}

	/** An allocation retry whose payment row is gone is dropped without an error or a reallocation. */
	public function test_allocation_retry_payment_row_gone_drops_quietly(): void {
		// Arrange.
		list( $closure, $order, , $refund ) = $this->reallocation_fixture();
		$args = array( $order->get_id(), wp_generate_uuid4(), $refund->get_id(), '10.00', 2 );

		// Act.
		do_action_ref_array( self::ALLOCATION_RETRY, $args );

		// Assert.
		$this->assertSame( array(), $this->records( 'late_refund', $closure['id'] ) );
	}

	/** At the attempt cap a busy register schedules no further retry. */
	public function test_retry_at_attempt_cap_schedules_nothing(): void {
		// Arrange.
		$max                                       = $this->max_attempts();
		list( $closure, $order, $refund, $session ) = $this->late_refund_fixture();
		list( , $card_order, $payment, $card_refund, $card_session ) = $this->reallocation_fixture();
		$row = $this->row( $card_order, $payment );

		// Act.
		$this->with_register_held(
			$session,
			static function () use ( $order, $refund, $max ): void {
				Fiscal_Record_Writers::instance()->handle_refund( $order->get_id(), $refund->get_id(), $max );
			}
		);
		$this->with_register_held(
			$card_session,
			static function () use ( $card_order, $row, $card_refund, $max ): void {
				Fiscal_Record_Writers::instance()->handle_allocation( $card_order, $row, $card_refund, '10.00', $max );
			}
		);

		// Assert.
		$this->assertGreaterThan( 1, $max );
		$this->assertSame( array(), $this->scheduled( self::REFUND_RETRY ) );
		$this->assertSame( array(), $this->scheduled( self::ALLOCATION_RETRY ) );
		$this->assertSame( array(), $this->records( 'late_refund', $closure['id'] ) );
	}

	/** A writer that throws under the locks releases both. */
	public function test_writer_throwing_under_locks_releases_both(): void {
		// Arrange: the closure read fails after the sale row is written.
		$session = $this->closure_session();
		$order   = $this->completed_sale( $session );
		$read    = ', closure FROM ' . ( new Register_Session_Store() )->table_name() . ' WHERE number IS NOT NULL AND id =';
		$throw   = static function ( $sql ) use ( $read ) {
			if ( false !== strpos( $sql, $read ) ) {
				throw new \RuntimeException( 'Closure read failed.' );
			}
			return $sql;
		};
		$thrown  = null;

		// Act.
		add_filter( 'query', $throw );
		try {
			Fiscal_Record_Writers::instance()->record_sale( wc_get_order( $order->get_id() ) );
		} catch ( \RuntimeException $error ) {
			$thrown = $error->getMessage();
		} finally {
			remove_filter( 'query', $throw );
		}

		// Assert.
		$this->assertSame( 'Closure read failed.', $thrown );
		$this->assertTrue( $this->is_free( $this->order_lock( $order->get_id() ) ) );
		$this->assertTrue( $this->is_free( $this->register_lock( $session ) ) );
	}

	/** A sale with no session, or a session the server does not know, takes no register lock and is written. */
	public function test_sale_without_known_session_takes_no_register_lock(): void {
		foreach ( array( 'unknown', 'none' ) as $case ) {
			// Arrange.
			$order = wc_create_order();
			$order->set_created_via( 'woocommerce-pos' );
			$order->set_status( 'pos-open' );
			$order->set_total( '100.00' );
			$order->update_meta_data( '_wcpos_register', wp_generate_uuid4() );
			if ( 'unknown' === $case ) {
				$order->update_meta_data( '_wcpos_session', wp_generate_uuid4() );
			}
			$order->save();
			$this->order_ids[] = $order->get_id();
			$this->seed_completion( $order );

			// Act.
			$requests = $this->lock_requests(
				static function () use ( $order ): void {
					Fiscal_Record_Writers::instance()->record_sale( wc_get_order( $order->get_id() ) );
				}
			);

			// Assert: the register repository's own default lock is no session's register.
			$register = static fn( $sql ) => false !== strpos( $sql, "'wcpos_register_" ) && false === strpos( $sql, "'" . Register_Store::DEFAULT_LOCK . "'" );
			$this->assertSame( array(), array_values( array_filter( $requests, $register ) ), $case );
			$this->assertCount( 1, $this->sales( $order->get_id() ), $case );
		}
	}

	/** A close that meets a busy order fails at once and leaves nothing behind; the retry closes and stamps both. */
	public function test_close_with_busy_order_fails_fast_and_retry_succeeds(): void {
		// Arrange: two orders in the session; the contender holds the higher one.
		$session   = $this->closure_session();
		$first     = $this->closure_ledger( $session );
		$second    = $this->closure_ledger( $session );
		$held      = max( $first->get_id(), $second->get_id() );
		$body      = $this->close_body( $session );
		$contender = $this->contender();

		try {
			$this->assertSame( '1', $this->take( $contender, $this->order_lock( $held ) ) );

			// Act.
			$started  = microtime( true );
			$response = $this->post_close( $body );
			$elapsed  = microtime( true ) - $started;

			// Assert.
			$this->assertSame( 500, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( 'wcpos_closure_write_failed', $response->get_data()['code'] );
			$this->assertLessThan( 2.0, $elapsed );
			$this->assertNull( ( new Closure_Store() )->for_session( $session['id'] ) );
			$this->assertSame( array(), $this->stamps( $first->get_id() ) );
			$this->assertSame( array(), $this->stamps( $second->get_id() ) );
			$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
			$this->assertTrue( $this->is_free( $this->register_lock( $session ) ) );
		} finally {
			$this->free( $contender, $this->order_lock( $held ) );
			$contender->close();
		}

		$retry = $this->post_close( $body );
		$this->assertSame( 201, $retry->get_status(), wp_json_encode( $retry->get_data() ) );
		$id = $retry->get_data()['id'];
		foreach ( array( $first->get_id(), $second->get_id() ) as $order_id ) {
			$stamps = $this->stamps( $order_id );
			$this->assertNotEmpty( $stamps );
			$this->assertSame( array_fill( 0, count( $stamps ), $id ), $stamps );
		}
	}

	/** A writer holding its order and asking for the register gets it at once while the close fails. */
	public function test_close_against_writer_holding_order_does_not_deadlock(): void {
		// Arrange: the contender is the writer, holding the order.
		$session   = $this->closure_session();
		$order     = $this->closure_ledger( $session );
		$body      = $this->close_body( $session );
		$contender = $this->contender();
		$dbh       = $contender->dbh;
		$this->assertInstanceOf( \mysqli::class, $dbh );
		$register  = $this->register_lock( $session );
		$sent      = false;
		$pending   = false;
		$send      = static function ( $sql ) use ( $dbh, $register, &$sent, &$pending ) {
			if ( ! $sent && 0 === stripos( ltrim( $sql ), 'START TRANSACTION' ) ) {
				$sent    = true;
				$pending = false !== mysqli_query( $dbh, "SELECT GET_LOCK( '" . $register . "', 10 )", MYSQLI_ASYNC );
			}
			return $sql;
		};
		$got = null;

		try {
			$this->assertSame( '1', $this->take( $contender, $this->order_lock( $order->get_id() ) ) );

			// Act.
			$started = microtime( true );
			add_filter( 'query', $send );
			try {
				$response = $this->post_close( $body );
			} finally {
				remove_filter( 'query', $send );
			}
			while ( $pending && microtime( true ) - $started < 12 ) {
				$read   = array( $dbh );
				$error  = array( $dbh );
				$reject = array( $dbh );
				if ( mysqli_poll( $read, $error, $reject, 1 ) > 0 ) {
					$result  = $dbh->reap_async_query();
					$pending = false;
					$got     = $result instanceof \mysqli_result ? (string) $result->fetch_row()[0] : null;
				}
			}
			$elapsed = microtime( true ) - $started;

			// Assert.
			$this->assertTrue( $sent );
			$this->assertSame( 500, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( '1', $got );
			$this->assertLessThan( 5.0, $elapsed );
		} finally {
			if ( $pending ) {
				$dbh->reap_async_query();
			}
			$this->free( $contender, $register );
			$this->free( $contender, $this->order_lock( $order->get_id() ) );
			$contender->close();
		}

		$retry = $this->post_close( $body );
		$this->assertSame( 201, $retry->get_status(), wp_json_encode( $retry->get_data() ) );
	}
}
