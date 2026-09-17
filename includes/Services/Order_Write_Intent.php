<?php
/**
 * POS order write intent.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Names the subject and client intent before priority-10 order-write readers run.
 *
 * Priority 1 binds the prepared order and applies declared date/meta mutations first.
 * Tax needs the client's status: WooCommerce recalculates before applying it, and
 * around_paid_create() neutralises the request to pending before preparation.
 * Stock_Validator deliberately reads that neutralised request, not this intent:
 * its pending exemption avoids validating the unsaved order before reservation.
 */
final class Order_Write_Intent {
	/**
	 * Declared contexts, with an optional last ad-hoc observation below.
	 *
	 * @var self[]
	 */
	private static $stack = array();
	/**
	 * Whether a lane declared this context.
	 *
	 * @var bool
	 */
	private $declared = false;
	/**
	 * Declaration or request-derived intent.
	 *
	 * @var array
	 */
	private $data = array();
	/**
	 * Exact prepared order, once bound.
	 *
	 * @var \WC_Abstract_Order|null
	 */
	private $subject;

	/** Register once, before the stock gate and other order-write readers. */
	public static function register(): void {
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( self::class, 'publish' ), 1, 3 );
	}

	/**
	 * Bind a declared subject or replace the last ad-hoc POS observation.
	 *
	 * @param mixed $order    Prepared order or an upstream filter result.
	 * @param mixed $request  REST request when available.
	 * @param bool  $creating Whether WooCommerce is creating the order.
	 * @return mixed The filter value, unchanged.
	 */
	public static function publish( $order, $request = null, $creating = false ) {
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return $order;
		}
		$intent = self::current();
		if ( null !== $intent && $intent->declared ) {
			// A declaration is the lane's own word that this is a POS write; only
			// the ad-hoc observation below has to learn that from the request.
			$matches = $intent->is_create() ? $creating : ( ! $creating && $order->get_id() === $intent->id() );
			if ( null === $intent->subject && $matches ) {
				$intent->subject = $order;
				foreach ( $intent->data['fill_meta'] ?? array() as $key => $value ) {
					$order->update_meta_data( $key, $value );
				}
				if ( isset( $intent->data['created_gmt'] ) ) {
					$order->set_date_created( $intent->data['created_gmt'] );
				}
			}
		} elseif ( \wcpos_request() ) {
			$intent = new self();
			$intent->data = array(
				'operation'        => $creating ? 'create' : 'update',
				'id'               => $order->get_id(),
				'requested_status' => $request instanceof \WP_REST_Request ? (string) $request->get_param( 'status' ) : '',
				'set_paid'         => $request instanceof \WP_REST_Request && $request->has_param( 'set_paid' ) && rest_sanitize_boolean( $request->get_param( 'set_paid' ) ),
			);
			$intent->subject = $order;
			self::$stack = array( $intent );
		}
		return $order;
	}

	/**
	 * Declare a write, restoring the enclosing intent even if the write throws.
	 *
	 * @param array    $declared Operation, id, requested_status, set_paid, created_gmt, fill_meta.
	 * @param callable $write    The order write.
	 * @return mixed The write result.
	 */
	public static function open( array $declared, callable $write ) {
		$intent = new self();
		$intent->declared = true;
		$intent->data = $declared;
		self::$stack[] = $intent;
		try {
			return $write();
		} finally {
			array_pop( self::$stack );
		}
	}

	/** Return the enclosing declaration or last ad-hoc observation. */
	public static function current(): ?self {
		return self::$stack ? end( self::$stack ) : null;
	}

	/** Return the declared or observed operation. */
	public function operation(): string {
		return $this->data['operation'];
	}

	/** Whether this write creates an order. */
	public function is_create(): bool {
		return 'create' === $this->operation();
	}

	/** Return the declared or observed order ID (zero for an unsaved create). */
	public function id(): int {
		return (int) ( $this->data['id'] ?? 0 );
	}

	/** Return the client's raw status, before stock neutralisation. */
	public function requested_status(): string {
		return (string) ( $this->data['requested_status'] ?? '' );
	}

	/** Whether the client asked to mark the order paid. */
	public function set_paid(): bool {
		return (bool) ( $this->data['set_paid'] ?? false );
	}

	/** Return the prepared subject, or null before binding. */
	public function subject(): ?\WC_Abstract_Order {
		return $this->subject;
	}

	/**
	 * Test exact prepared-order identity, never an unbound context.
	 *
	 * @param mixed $order Candidate subject.
	 * @return bool
	 */
	public function is_subject( $order ): bool {
		return null !== $this->subject && $this->subject === $order;
	}
}
