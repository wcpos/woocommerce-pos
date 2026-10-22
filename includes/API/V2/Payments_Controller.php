<?php
/**
 * Order payments contract controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WC_REST_Controller;
use WCPOS\WooCommercePOS\Payments\Contract\Command_Log;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_History;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Gateway_Submission;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Persistence_Exception;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Writers;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Exposes the locked order payment route family. */
class Payments_Controller extends WC_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders';

	/**
	 * The money commands whose body may carry the completing register's provenance (record, intent,
	 * capture, submit, close). A status poll, void, refund or cancel completes nothing at a register.
	 *
	 * @var string[]
	 */
	private const PROVENANCE_HANDLERS = array( 'create_item', 'intent_item', 'capture_item', 'submit_item', 'close_item' );

	/**
	 * The money command each handler runs (ADR 0040 rules 5 and 6): every one carries the order
	 * revision it was built against, and every one but `record` (whose row id is its command id) a
	 * `command_id`. The status poll is not a command.
	 *
	 * @var array<string, string>
	 */
	private const COMMANDS = array(
		'create_item'  => 'record',
		'intent_item'  => 'intent',
		'capture_item' => 'capture',
		'void_item'    => 'void',
		'refund_item'  => 'refund',
		'submit_item'  => 'submit',
		'cancel_item'  => 'cancel',
		'close_item'   => 'close',
	);

	/** Register the order payment route family. */
	public function register_routes(): void {
		$payment_path = '/' . $this->rest_base . '/(?P<id>[\d]+)/payments';
		$method_path = '/' . $this->rest_base . '/(?P<id>[\d]+)/payment-methods/(?P<method>[\w-]+)';
		$routes       = array(
			$method_path . '/submit' => array( WP_REST_Server::CREATABLE, 'submit_item' ),
			$method_path . '/cancel' => array( WP_REST_Server::CREATABLE, 'cancel_item' ),
			$payment_path => array( WP_REST_Server::CREATABLE, 'create_item' ),
			'/' . $this->rest_base . '/(?P<id>[\d]+)/close' => array( WP_REST_Server::CREATABLE, 'close_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/status' => array( WP_REST_Server::READABLE, 'get_status' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/intent' => array( WP_REST_Server::CREATABLE, 'intent_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/capture' => array( WP_REST_Server::CREATABLE, 'capture_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/refund' => array( WP_REST_Server::CREATABLE, 'refund_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/void' => array( WP_REST_Server::CREATABLE, 'void_item' ),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					array(
						'methods'             => $definition[0],
						'callback'            => function ( $request ) use ( $definition ) {
							// Each body fetches its order only after acquiring the lock, including GET status.
							return Order_Lock::instance()->with_lock(
								(int) $request['id'],
								function () use ( $request, $definition ) {
									try {
										$kind    = self::COMMANDS[ $definition[1] ] ?? null;
										$command = null === $kind ? array() : $this->admit( $request, $kind );
										if ( ! is_array( $command ) ) {
											// A refusal, or a replay answered from the stored outcome.
											return $this->answer( $command, $kind, (int) $request['id'] );
										}
										$args = null === $kind ? array( $request ) : array( $request, $command ); // The status poll takes no command.
										$work = fn() => $this->{$definition[1]}( ...$args );
										if ( in_array( $definition[1], self::PROVENANCE_HANDLERS, true ) ) {
											// The command's provenance names the register that completes the sale, if it does (rule 1c).
											$params     = $request->get_json_params();
											$params     = is_array( $params ) ? $params : $request->get_body_params();
											$provenance = is_array( $params['provenance'] ?? null ) ? $params['provenance'] : array();
											$result     = Completion_Stamp::with_provenance( $provenance, $work, (int) $request['id'] );
										} else {
											$result = $work();
										}
										return $this->answer( $result, $kind, (int) $request['id'] );
									} catch ( Ledger_Persistence_Exception $error ) {
										// The payment was not stored: a retryable conflict, never an acknowledgement.
										return new WP_Error( 'woo_rxdb_sync_record_locked', $error->getMessage(), array( 'status' => 409 ) );
									}
								}
							);
						},
						'permission_callback' => array( $this, 'payments_permissions_check' ),
					),
				)
			);
		}
	}

	/**
	 * Check access to payment routes.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function payments_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot manage order payments.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Record money already taken by a manual payment method.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command; its id is the row id.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request, array $command = array() ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : $request->get_body_params();
		$payment = $params['payment'] ?? null;
		if ( ! is_array( $payment ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Payment must be an object.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}

		$context = array( 'cashier_id' => get_current_user_id() );
		if ( isset( $payment['store_id'] ) ) {
			$context['store_id'] = (int) $payment['store_id'];
		}
		$row = Ledger::instance()->record( $order, $payment, $context, $command );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$refusal = Ledger::instance()->refusal_error( $row, $order );
		if ( $refusal ) {
			return $refusal;
		}
		return $this->payment_response( $row, $order );
	}

	/**
	 * Close a sale: no money moves; a covered order completes (ADR 0040 rule 1, D2).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command (id, fingerprint, kind).
	 * @return \WP_REST_Response|WP_Error
	 */
	public function close_item( $request, array $command = array() ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$result = Ledger::instance()->close( $order, $command );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Submit a gateway's declared fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function submit_item( $request, array $command = array() ) {
		return $this->gateway_item( $request, 'submit', $command );
	}

	/**
	 * Cancel an awaiting-customer outcome.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function cancel_item( $request, array $command = array() ) {
		return $this->gateway_item( $request, 'cancel', $command );
	}

	/**
	 * Dispatch a gateway operation inside the route lock.
	 *
	 * @param WP_REST_Request $request   Request object.
	 * @param string          $operation Submit or cancel.
	 * @param array           $command   The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function gateway_item( WP_REST_Request $request, string $operation, array $command = array() ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$descriptor = Descriptor_Builder::instance()->get( (string) $request['method'] );
		if ( ! $descriptor ) {
			return new WP_Error( 'wcpos_payment_method_not_found', __( 'Payment method not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		if ( 'submit' === $operation ) {
			if ( ! is_array( $params['values'] ?? null ) || ! is_string( $params['attempt_id'] ?? null ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Values must be an object and attempt ID a UUID.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
			$result = Gateway_Submission::submit( $order, $descriptor, $params['attempt_id'], $params['values'], $command );
		} else {
			if ( ! is_string( $params['attempt_id'] ?? null ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Attempt ID must name the sent attempt to cancel.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
			$result = Gateway_Submission::cancel( $order, $descriptor, $params['attempt_id'], sanitize_text_field( $params['reason'] ?? '' ), $command );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['payment'] ) ) {
			$result['payment'] = Ledger::to_wire( $result['payment'] );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Return current status for one payment row.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$row = Ledger::instance()->status( $order, strtolower( (string) $request['uuid'] ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$prompt = $row['prompt'] ?? null;
		unset( $row['prompt'] );
		$response = $this->payment_response( $row, $order );
		$response->set_data( array_merge( $response->get_data(), array( 'prompt' => $prompt ) ) );
		return $response;
	}

	/**
	 * Void one pending or authorized payment row.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function void_item( $request, array $command = array() ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$reason = sanitize_text_field( (string) ( $params['reason'] ?? '' ) );
		$row    = Ledger::instance()->void( $order, strtolower( (string) $request['uuid'] ), $reason, $command );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return $this->payment_response( $row, $order );
	}

	/**
	 * Create a payment intent and return its transient handoff.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function intent_item( $request, array $command = array() ) {
		return $this->provider_item( $request, 'intent', $command );
	}

	/**
	 * Capture a payment through its handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function capture_item( $request, array $command = array() ) {
		return $this->provider_item( $request, 'capture', $command );
	}

	/**
	 * Allocate a refund through its handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $command The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refund_item( $request, array $command = array() ) {
		return $this->provider_item( $request, 'refund', $command );
	}

	/**
	 * Dispatch the new provider operations, inside the route's order lock.
	 *
	 * @param WP_REST_Request $request   Request object.
	 * @param string          $operation Ledger operation.
	 * @param array           $command   The admitted command.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function provider_item( WP_REST_Request $request, string $operation, array $command = array() ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$context = $params['context'] ?? array();
		// Money is a decimal string on the wire, but a client that sends the JSON number
		// is not wrong enough to refuse — the ledger validates the value either way.
		if ( ! is_array( $context ) || ( 'intent' === $operation && ! is_array( $params['payment'] ?? null ) ) || ( 'refund' === $operation && ( ! is_scalar( $params['amount'] ?? null ) || ! is_numeric( $params['amount'] ) || ! isset( $params['refund_id'] ) || ! is_numeric( $params['refund_id'] ) || (int) $params['refund_id'] != $params['refund_id'] ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid payment operation parameters.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$context['cashier_id'] = get_current_user_id();
		$id = strtolower( (string) $request['uuid'] );
		$ledger = Ledger::instance();
		if ( 'intent' === $operation ) {
			$result = $ledger->intent( $order, $id, $params['payment'], $context, $command );
		} elseif ( 'capture' === $operation ) {
			$result = $ledger->capture( $order, $id, $context, $command );
		} else {
			$result = $ledger->refund( $order, $id, (int) $params['refund_id'], (string) $params['amount'], $command );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'refund' === $operation ) {
			return rest_ensure_response( array( 'payment' => Ledger::to_wire( $result ) ) );
		}
		$response = $this->payment_response( 'intent' === $operation ? $result['payment'] : $result, $order );
		if ( 'intent' === $operation ) {
			$response->set_data( array_merge( $response->get_data(), array( 'handoff' => empty( $result['handoff'] ) ? new \stdClass() : $result['handoff'] ) ) );
		}
		return $response;
	}

	/**
	 * Admit a money command, under the route lock and before any ledger call: parse the envelope,
	 * answer a replay from its stored outcome (before the revision, ADR 0040 rule 6: a retry carries
	 * the revision from before its own effect), and refuse a fresh command built on a stale order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $kind    Command kind.
	 * @return array|WP_Error|\WP_REST_Response The command to run (empty for `record`), a refusal, or a replay.
	 */
	private function admit( WP_REST_Request $request, string $kind ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params  = $this->params( $request );
		$command = array();
		if ( 'record' === $kind && is_array( $params['payment'] ?? null ) && is_string( $params['payment']['id'] ?? null ) ) {
			// A recorded row's id is its command id (rule 5), and its whole body the fingerprint.
			$command = array(
				'id'   => strtolower( $params['payment']['id'] ),
				'fp'   => Command_Log::fingerprint( 'record', '', $params ),
				'kind' => 'record',
			);
		} elseif ( 'record' !== $kind ) {
			$id = $params['command_id'] ?? null;
			if ( ! is_string( $id ) || ! wp_is_uuid( strtolower( $id ) ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Command ID must be a UUID.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
			$target  = in_array( $kind, Command_Log::ROW_KINDS, true ) ? (string) $request['uuid'] : (string) $request['method'];
			$command = array(
				'id'   => strtolower( $id ),
				'fp'   => Command_Log::fingerprint( $kind, $target, $params ),
				'kind' => $kind,
			);
		}
		$revision = $params['revision'] ?? null;
		if ( ! is_string( $revision ) || '' === $revision ) {
			return new WP_Error( 'wcpos_revision_required', __( 'A payment command needs the order revision it was built against.', 'woocommerce-pos' ), array( 'status' => 428 ) );
		}
		$stored = $command ? Command_Log::find( $order, Ledger::instance()->read( $order ), $command['id'] ) : null;
		if ( $stored && $stored['fp'] !== $command['fp'] ) {
			$data = array(
				'status'     => 422,
				'command_id' => $command['id'],
				'kind'       => $kind,
			);
			if ( 'record' === $kind && null !== $stored['payment_id'] ) {
				$data['payment'] = Ledger::to_wire( (array) Ledger::instance()->find( $order, $stored['payment_id'] ) );
			}
			return new WP_Error( 'wcpos_command_conflict', __( 'This command ID was already used with a different request.', 'woocommerce-pos' ), $data );
		}
		if ( $stored && ! in_array( $kind, array( 'record', 'intent', 'submit' ), true ) ) {
			return $this->replay( $order, $kind, $stored );
		}
		// Only a command that has not run meets the revision; record, intent and submit replays are called through.
		if ( ! $stored ) {
			$stale = $this->stale( $order, $revision );
			if ( $stale ) {
				return $stale;
			}
		}
		return $command;
	}

	/**
	 * A replay's answer, from the order as it now stands.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $kind  Command kind (capture, void, refund, cancel or close).
	 * @param array    $entry The stored entry.
	 */
	private function replay( WC_Order $order, string $kind, array $entry ): \WP_REST_Response {
		$summary = Ledger::instance()->summary( $order );
		if ( 'cancel' === $kind ) {
			return rest_ensure_response( array( 'order' => $summary ) );
		}
		if ( 'close' === $kind ) {
			$history = Completion_History::of( $order );
			// A null ref is a close that crashed between its two saves: it names what stands, as close() would.
			$completed  = null === $entry['ref'] ? $history->standing() : $history->by_id( (string) $entry['ref'] );
			$completion = null === $completed ? null : array(
				'n'  => (int) $completed['n'],
				'id' => (string) $completed['id'],
			);
			return rest_ensure_response(
				array(
					'order'      => $summary,
					'completion' => $completion,
				)
			);
		}
		$row = Ledger::to_wire( (array) Ledger::instance()->find( $order, (string) $entry['payment_id'] ) );
		return rest_ensure_response(
			'refund' === $kind ? array( 'payment' => $row ) : array(
				'payment' => $row,
				'order'   => $summary,
			)
		);
	}

	/**
	 * Refuse a fresh command built against another revision of the order; propagate a failed read.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $revision The revision the command was built against.
	 */
	private function stale( WC_Order $order, string $revision ): ?WP_Error {
		$current = Order_Serializer::current_state( $order->get_id() );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( hash_equals( $current['revision'], $revision ) ) {
			return null;
		}
		return new WP_Error(
			'wcpos_revision_stale',
			__( 'The order changed since this command was built; read it again.', 'woocommerce-pos' ),
			array(
				'status'          => 409,
				'currentRevision' => $current['revision'],
				'current'         => $current['document'],
				'order'           => Ledger::instance()->summary( $order ),
			)
		);
	}

	/**
	 * Finish a route answer: keep the command maps out of the wire, recover a parked sale record,
	 * and give every 2xx command answer the revision after it, read once recovery has run (§5.3).
	 *
	 * @param mixed       $result   Handler, refusal or replay answer.
	 * @param string|null $kind     Command kind; null for the status poll.
	 * @param int         $order_id Order id.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function answer( $result, ?string $kind, int $order_id ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && is_array( $data['payment'] ?? null ) ) {
				unset( $data['payment']['commands'] );
				$result->add_data( $data );
			}
			return $result;
		}
		$response = rest_ensure_response( $result );
		$order    = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			// A sale record whose insert failed is written by the next money command (§4.7).
			Fiscal_Record_Writers::instance()->recover( $order );
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			if ( is_array( $data['payment'] ?? null ) ) {
				unset( $data['payment']['commands'] );
			}
			$state = null !== $kind && $response->get_status() < 300 && $order ? Order_Serializer::current_state( $order_id ) : null;
			if ( is_wp_error( $state ) ) {
				return $state; // The command's effect stands and its entry is kept: the retry replays it.
			}
			if ( is_array( $state ) ) {
				$data['revision'] = $state['revision'];
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * The request body, JSON or form.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	private function params( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Build the standard payment and order response.
	 *
	 * @param array    $row   Payment row.
	 * @param WC_Order $order Order object.
	 */
	private function payment_response( array $row, WC_Order $order ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'payment' => Ledger::to_wire( $row ),
				'order'   => Ledger::instance()->summary( $order ),
			)
		);
	}

	/**
	 * Get an order or the standard missing-order error.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return WC_Order|\WP_Error
	 */
	private function get_order( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		return $order;
	}
}
