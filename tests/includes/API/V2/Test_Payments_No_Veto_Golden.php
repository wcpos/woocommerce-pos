<?php
/**
 * Golden tests for the payments routes.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Capture_Mode_Registry;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;
use WCPOS\WooCommercePOS\Tests\Helpers\Money_Route_Envelope;

/** Payments answers with no pre-capture callback registered, compared with a fixture generated on the base commit. */
class Test_Payments_No_Veto_Golden extends WCPOS_REST_Unit_Test_Case {
	use Money_Route_Envelope;

	private const PARTIAL_ROW = '10000000-0000-4000-8000-000000000001';
	private const FULL_ROW    = '10000000-0000-4000-8000-000000000002';
	private const REPLAY_ROW  = '10000000-0000-4000-8000-000000000003';
	private const INTENT_ROW  = '10000000-0000-4000-8000-000000000004';
	private const CAPTURE_ROW = '10000000-0000-4000-8000-000000000005';
	private const CLOSE_ROW   = '10000000-0000-4000-8000-000000000006';

	private const PARTIAL_COMMAND        = '20000000-0000-4000-8000-000000000001';
	private const FULL_COMMAND           = '20000000-0000-4000-8000-000000000002';
	private const REPLAY_COMMAND         = '20000000-0000-4000-8000-000000000003';
	private const INTENT_COMMAND         = '20000000-0000-4000-8000-000000000004';
	private const CAPTURE_INTENT_COMMAND = '20000000-0000-4000-8000-000000000005';
	private const CAPTURE_COMMAND        = '20000000-0000-4000-8000-000000000006';
	private const SUBMIT_COMMAND         = '20000000-0000-4000-8000-000000000007';
	private const CLOSE_COMMAND          = '20000000-0000-4000-8000-000000000008';
	private const SUBMIT_ATTEMPT         = '30000000-0000-4000-8000-000000000001';

	/**
	 * Log messages captured instead of writing to disk.
	 *
	 * @var array
	 */
	private array $logged_messages = array();

	/**
	 * Capture filter removed at teardown.
	 *
	 * @var \Closure
	 */
	private $log_filter;

	/** Capture log attempts without writing to disk. */
	public function setUp(): void {
		parent::setUp();
		Logger::reset_dedup_state();
		$this->logged_messages = array();
		$this->log_filter      = function ( $should_log, $message ) {
			$this->logged_messages[] = $message;
			return false;
		};
		add_filter( 'wcpos_logging', $this->log_filter, 10, 2 );
	}

	/** Remove the capture filter. */
	public function tearDown(): void {
		remove_filter( 'wcpos_logging', $this->log_filter, 10 );
		parent::tearDown();
	}

	/** Record cash against part of the balance. */
	public function test_no_callback_record_partial_answer_matches_the_golden_fixture(): void {
		$order    = $this->create_pos_order();
		$body     = $this->envelope( $order, array( 'command_id' => self::PARTIAL_COMMAND, 'payment' => $this->payment( self::PARTIAL_ROW, '50.00' ) ) );
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', $body );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'record_partial', $answer );
	}

	/** Record the full balance in cash. */
	public function test_no_callback_record_full_answer_matches_the_golden_fixture(): void {
		$order    = $this->create_pos_order();
		$body     = $this->envelope( $order, array( 'command_id' => self::FULL_COMMAND, 'payment' => $this->payment( self::FULL_ROW, '92.95' ) ) );
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments', $body );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'record_full', $answer );
	}

	/** Replay the same payment command at the original revision. */
	public function test_no_callback_record_replay_answer_matches_the_golden_fixture(): void {
		$order = $this->create_pos_order();
		$body  = $this->envelope( $order, array( 'command_id' => self::REPLAY_COMMAND, 'payment' => $this->payment( self::REPLAY_ROW, '50.00' ) ) );
		$path  = '/wcpos/v2/orders/' . $order->get_id() . '/payments';
		$this->command( $path, $body );
		$response = $this->command( $path, $body );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'record_replay', $answer );
	}

	/** Request a reader handoff. */
	public function test_no_callback_intent_answer_matches_the_golden_fixture(): void {
		$this->use_route_handler();
		$order    = $this->create_pos_order();
		$response = $this->intent( $order, self::INTENT_ROW, self::INTENT_COMMAND );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'intent', $answer );
	}

	/** Capture the intended payment at the order's new revision. */
	public function test_no_callback_capture_answer_matches_the_golden_fixture(): void {
		$this->use_route_handler();
		$order = $this->create_pos_order();
		$this->assertSame( 200, $this->intent( $order, self::CAPTURE_ROW, self::CAPTURE_INTENT_COMMAND )->get_status() );
		$body     = $this->envelope( $order, array( 'command_id' => self::CAPTURE_COMMAND, 'context' => array( 'amount' => '92.95' ) ) );
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . self::CAPTURE_ROW . '/capture', $body );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'capture', $answer );
	}

	/** Submit a cash attempt with no gateway fields. */
	public function test_no_callback_submit_answer_matches_the_golden_fixture(): void {
		$order    = $this->create_pos_order();
		$body     = $this->envelope( $order, array( 'command_id' => self::SUBMIT_COMMAND, 'attempt_id' => self::SUBMIT_ATTEMPT, 'values' => array() ) );
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payment-methods/pos_cash/submit', $body );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'submit', $answer );
	}

	/** Close an order with its full cash payment already captured. */
	public function test_no_callback_close_answer_matches_the_golden_fixture(): void {
		$order = $this->create_pos_order();
		$row   = array(
			'id'           => self::CLOSE_ROW,
			'method_id'    => 'pos_cash',
			'kind'         => 'cash',
			'capture_mode' => 'manual',
			'amount'       => '92.95',
			'status'       => 'captured',
		);
		Ledger_Seed::rows( $order, array( $row ), false );
		$body     = $this->envelope( $order, array( 'command_id' => self::CLOSE_COMMAND ) );
		$response = $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/close', $body );
		$answer   = array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) );
		$this->golden( 'close', $answer );
	}

	/** Create an open POS order at the contract total. */
	private function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();
		return $order;
	}

	/**
	 * Build a cash payment row.
	 *
	 * @param string $id     Payment UUID.
	 * @param string $amount Payment amount.
	 */
	private function payment( string $id, string $amount ): array {
		return array( 'id' => $id, 'method_id' => 'pos_cash', 'amount' => $amount, 'currency' => 'USD' );
	}

	/** Route reader commands through the local handler. */
	private function use_route_handler(): void {
		Capture_Mode_Registry::instance()->register( 'golden_route', Golden_Route_Handler::class );
		add_filter( 'wcpos_payment_method_capture_mode', static fn() => 'golden_route' );
	}

	/**
	 * Request an intent for the full order balance.
	 *
	 * @param \WC_Order $order      POS order.
	 * @param string    $id         Payment UUID.
	 * @param string    $command_id Command UUID.
	 */
	private function intent( \WC_Order $order, string $id, string $command_id ): \WP_REST_Response {
		$body = array(
			'command_id' => $command_id,
			'payment'    => $this->payment( $id, '92.95' ),
			'context'    => array( 'reader' => 'reader-1' ),
		);
		return $this->command( '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $id . '/intent', $this->envelope( $order, $body ) );
	}

	/**
	 * POST a money command body.
	 *
	 * @param string $path Route path.
	 * @param array  $body Enveloped body.
	 */
	private function command( string $path, array $body ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( $path );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/**
	 * Replace run-specific values without changing field order.
	 *
	 * @param mixed  $value Response value.
	 * @param string $key   Field name.
	 * @return mixed
	 */
	private function normalise( $value, string $key = '' ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $field => $entry ) {
				$value[ $field ] = $this->normalise( $entry, (string) $field );
			}
			return $value;
		}
		if ( is_string( $value ) && in_array( $key, array( 'revision', 'currentRevision' ), true ) ) {
			return '<revision>';
		}
		if ( ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) && preg_match( '/(^|_)id$/', $key ) ) {
			return '<int>';
		}
		if ( is_int( $value ) && preg_match( '/(_at|_time|_date|^time|^date)$/', $key ) ) {
			return '<time>';
		}
		if ( 'order_key' === $key ) {
			return '<order_key>';
		}
		if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value ) ) {
			return '<date>';
		}
		if ( is_string( $value ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) && ! in_array( $value, ( new \ReflectionClass( self::class ) )->getConstants(), true ) ) {
			return '<uuid>';
		}
		return $value;
	}

	/**
	 * Write or compare one fixture entry.
	 *
	 * @param string $key    Scenario name.
	 * @param array  $answer Normalised response.
	 */
	private function golden( string $key, array $answer ): void {
		$path = dirname( __DIR__, 3 ) . '/fixtures/payments-no-veto-golden.json';
		$all  = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
		if ( '1' === getenv( 'WCPOS_GOLDEN_WRITE' ) ) {
			$all[ $key ] = $answer;
			ksort( $all );
			file_put_contents( $path, wp_json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Base fixture generation.
			$this->assertTrue( true );
			return;
		}
		if ( ! isset( $all[ $key ] ) ) {
			$this->fail( 'Golden fixture missing: run once with WCPOS_GOLDEN_WRITE=1 on the base commit.' );
		}
		$this->assertSame( wp_json_encode( $all[ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), wp_json_encode( $answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Route fixtures stay with their tests.
/** A local capture handler returns the reader handoff and confirmed amount. */
class Golden_Route_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler {
	/**
	 * Describe the local capture mode.
	 *
	 * @param \WC_Payment_Gateway $gateway Payment gateway.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$descriptor                    = parent::describe( $gateway );
		$descriptor['capture']['mode'] = 'golden_route';
		return $descriptor;
	}

	/**
	 * Return the payment row with its reader handoff.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Reader context.
	 */
	public function intent( array $row, array $context ) {
		$row['handoff'] = array( 'reader' => $context['reader'] );
		return $row;
	}

	/**
	 * Return the provider-confirmed capture.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Capture context.
	 */
	public function capture( array $row, array $context ) {
		return array_merge( array( 'status' => 'captured' ), $context );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
