<?php
/**
 * Creates cloud print jobs from WooCommerce order events.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;

/**
 * Cloud_Print_Trigger_Service class.
 */
class Cloud_Print_Trigger_Service {
	const OPTION = 'woocommerce_pos_settings_cloud_print';

	/**
	 * Cron hook used to submit a PrintNode job out-of-band (never on checkout).
	 */
	const CRON_SUBMIT = 'wcpos_cloud_print_submit';

	/**
	 * Seconds before an abandoned assignment lock may be reclaimed.
	 */
	const ASSIGNMENT_LOCK_TTL = 120;

	/**
	 * Default assignment trigger: never print before the customer has paid.
	 */
	const DEFAULT_TRIGGER = 'paid';

	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Order ids whose woocommerce_payment_complete fired this request.
	 *
	 * The payment event is the authoritative "paid" signal: WCPOS routes
	 * payment_complete() to a merchant-configured per-gateway status (see
	 * Orders::payment_complete_order_status), which may not be one of
	 * wc_get_is_paid_statuses() — e.g. on-hold for account sales.
	 *
	 * @var array<int, bool>
	 */
	private $payment_completed = array();

	/**
	 * Printer registry.
	 *
	 * @var Cloud_Print_Registry
	 */
	private $registry;

	/**
	 * Constructor — hook order events.
	 */
	public function __construct() {
		$this->jobs     = new Print_Job_Service();
		$this->registry = new Cloud_Print_Registry();
		add_action( 'woocommerce_new_order', array( $this, 'handle_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_paid_order' ), 20, 1 );
	}

	/**
	 * Handle payment completing for an order.
	 *
	 * Runs after WC_Order::payment_complete() has moved the order to its
	 * post-payment status, which a status-changed callback may have already
	 * seen as a non-paid status. Remember the paid signal, then re-evaluate.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_paid_order( $order_id ): void {
		$this->payment_completed[ (int) $order_id ] = true;
		$this->handle_order( $order_id );
	}

	/**
	 * Normalize an assignment trigger to a supported value.
	 *
	 * Shared by the order-event path, sanitize-on-write, and normalize-on-read
	 * so the three defaulting sites cannot drift: a drifted default here would
	 * print receipts for unpaid orders.
	 *
	 * @param mixed $trigger Raw trigger value.
	 *
	 * @return string created|paid.
	 */
	public static function normalize_trigger( $trigger ): string {
		return \in_array( $trigger, array( 'created', 'paid' ), true ) ? $trigger : self::DEFAULT_TRIGGER;
	}

	/**
	 * Create jobs for an order according to the configured assignments.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$settings    = get_option( self::OPTION, array() );
		$assignments = isset( $settings['assignments'] ) && \is_array( $settings['assignments'] ) ? $settings['assignments'] : array();

		/**
		 * Filter the cloud-print assignments for an order. Pro uses this to
		 * substitute per-outlet assignments based on the order's store.
		 *
		 * @param array     $assignments Global assignments.
		 * @param \WC_Order $order       The order being processed.
		 */
		$assignments = apply_filters( 'woocommerce_pos_cloud_print_assignments', $assignments, $order );
		if ( ! \is_array( $assignments ) ) {
			$assignments = array();
		}

		if ( empty( $assignments ) ) {
			return;
		}

		$is_pos = 'woocommerce-pos' === $order->get_created_via();

		foreach ( $assignments as $assignment ) {
			if ( empty( $assignment['printer_id'] ) || empty( $assignment['template_id'] ) ) {
				continue;
			}
			$scope = isset( $assignment['scope'] ) ? (string) $assignment['scope'] : 'every';
			if ( ! $this->scope_matches( $scope, $is_pos ) ) {
				continue;
			}
			$trigger = self::normalize_trigger( $assignment['trigger'] ?? '' );
			if ( ! $this->payment_state_matches( $trigger, $order ) ) {
				continue;
			}
			$printer_id  = (string) $assignment['printer_id'];
			$template_id = (string) $assignment['template_id'];
			$order_id    = $order->get_id();
			$lock        = 'wcpos_cloud_print_assignment_lock_' . md5( $order_id . "\0" . $printer_id . "\0" . $template_id );
			if ( ! $this->acquire_assignment_lock( $lock ) ) {
				continue;
			}
			try {
				$copies = min( 5, max( 1, (int) ( $assignment['copies'] ?? 1 ) ) );
				// Dedupe per trigger: a created-rule job must not satisfy a
				// paid rule for the same printer+template (and vice versa).
				// Trigger-less jobs (manual prints, pre-trigger installs)
				// still count toward every rule.
				$existing = $this->jobs->count(
					array(
						'printer_id'  => $printer_id,
						'order_id'    => $order_id,
						'template_id' => $template_id,
						'trigger'     => $trigger,
					)
				);
				$shortfall = max( 0, $copies - $existing );
				if ( 0 === $shortfall ) {
					continue;
				}

				$printer = $this->registry->get_printer( $printer_id );
				if ( empty( $printer ) ) {
					continue;
				}
				// Legacy printer rows may lack a stored provider; normalize() maps
				// them to the star-cloudprnt default like every other read path.
				$provider = Provider::normalize( (string) ( $printer['provider'] ?? '' ) );

				$template = Print_Job_Service::load_template( $template_id );
				if ( null === $template ) {
					continue;
				}

				for ( $copy = 0; $copy < $shortfall; $copy++ ) {
					$job_id = self::enqueue_order_job(
						$this->jobs,
						$printer_id,
						$printer,
						$order_id,
						$template_id,
						$template,
						array(),
						$trigger
					);
					if ( 0 === $job_id ) {
						Logger::log(
							sprintf(
								'Cloud print: skipping assignment for printer "%s" — template "%s" is not printable on provider "%s".',
								$printer_id,
								$template_id,
								$provider
							)
						);
						break;
					}
				}
			} finally {
				delete_option( $lock );
			}
		}
	}

	/**
	 * Acquire the lock covering copy counting and job creation.
	 *
	 * @param string $option Lock option name.
	 */
	private function acquire_assignment_lock( string $option ): bool {
		$now = time();

		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}

		$locked_at = get_option( $option, 0 );
		if ( (int) $locked_at > 0 && ( $now - (int) $locked_at ) > self::ASSIGNMENT_LOCK_TTL ) {
			global $wpdb;
			// The value predicate prevents deleting a lock replaced after get_option().
			$deleted = $wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $option,
					'option_value' => (string) $locked_at,
				),
				array( '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic option delete; cache cleared below.
			if ( 1 !== $deleted ) {
				return false;
			}
			wp_cache_delete( $option, 'options' );

			return add_option( $option, (string) $now, '', false );
		}

		return false;
	}

	/**
	 * Enqueue a print job for an order + template, deriving the wire format from
	 * the printer's provider. Shared by the order-event trigger and the manual
	 * print-jobs endpoint so the two cannot drift.
	 *
	 * For PrintNode the job's submit event is scheduled out-of-band (PrintNode
	 * does not poll). For polling providers (Star/Epson) the printer fetches the
	 * job on its next poll, so no submit is scheduled.
	 *
	 * @param Print_Job_Service $jobs        Job store.
	 * @param string            $printer_id  Registered printer id.
	 * @param array             $printer     Registered printer config.
	 * @param int               $order_id    Order id to render.
	 * @param string            $template_id Template id (numeric) or virtual slug.
	 * @param array             $template       Loaded template array.
	 * @param array             $drawer_options Drawer options.
	 * @param string            $trigger        Originating rule trigger (created|paid); empty for manual prints.
	 *
	 * @return int Created job id, or 0 when the template is not printable on the provider.
	 */
	public static function enqueue_order_job( Print_Job_Service $jobs, string $printer_id, array $printer, int $order_id, string $template_id, array $template, array $drawer_options = array(), string $trigger = '' ): int {
		// Normalize before EVERY consumer below (drawer options, printability,
		// requires_submit) — a legacy row without a provider is star-cloudprnt.
		$provider       = Provider::normalize( (string) ( $printer['provider'] ?? '' ) );
		$drawer_options = self::drawer_options_for_provider( $provider, $drawer_options );

		// The resolver owns both halves of the answer for every provider: an
		// empty kind means the template cannot be rendered on this printer.
		$fmt = ( new Print_Format_Resolver() )->resolve( $printer, $template );
		if ( '' === $fmt['kind'] ) {
			return 0;
		}

		if ( 'printnode' === $provider ) {
			$job_id = $jobs->create(
				array(
					'printer_id'   => $printer_id,
					'order_id'     => $order_id,
					'template_id'  => $template_id,
					'content_type' => $fmt['content_type'],
					'pn_kind'      => $fmt['kind'],
					'trigger'      => $trigger,
					'auto_open_drawer' => ! empty( $drawer_options['auto_open_drawer'] ),
					'drawer_connector' => $drawer_options['drawer_connector'],
				)
			);
			if ( $job_id > 0 ) {
				wp_schedule_single_event( time(), self::CRON_SUBMIT, array( $job_id ) );
			}

			return $job_id;
		}

		$job_id = $jobs->create(
			array(
				'printer_id'   => $printer_id,
				'content_type' => $fmt['content_type'],
				'order_id'     => $order_id,
				'template_id'  => $template_id,
				'trigger'      => $trigger,
				'auto_open_drawer' => ! empty( $drawer_options['auto_open_drawer'] ),
				'drawer_connector' => $drawer_options['drawer_connector'],
			)
		);

		// Push providers (e.g. Star Online) don't poll us; submit out-of-band.
		if ( $job_id > 0 && Provider::requires_submit( $provider ) ) {
			wp_schedule_single_event( time(), self::CRON_SUBMIT, array( $job_id ) );
		}

		return $job_id;
	}

	/**
	 * Keep drawer metadata scoped to providers implemented by this server change.
	 *
	 * Star providers use Star-specific drawer commands and are intentionally not
	 * changed by the Epson/PrintNode implementation.
	 *
	 * @param string $provider       Provider key.
	 * @param array  $drawer_options Drawer options.
	 *
	 * @return array{auto_open_drawer:bool, drawer_connector:string}
	 */
	private static function drawer_options_for_provider( string $provider, array $drawer_options ): array {
		if ( ! in_array( $provider, array( 'epson-sdp', 'printnode' ), true ) ) {
			return array(
				'auto_open_drawer' => false,
				'drawer_connector' => 'pin2',
			);
		}

		return array(
			'auto_open_drawer' => ! empty( $drawer_options['auto_open_drawer'] ),
			'drawer_connector' => Print_Job_Service::normalize_drawer_connector( (string) ( $drawer_options['drawer_connector'] ?? 'pin2' ) ),
		);
	}

	/**
	 * Whether an assignment trigger applies to this order's payment state.
	 *
	 * POS carts ARE orders from the moment the cart is saved (status
	 * pos-open), and online orders exist at checkout as pending — so
	 * 'created' fires before the customer has paid. 'paid' (the default)
	 * accepts any of three signals: a paid status per
	 * wc_get_is_paid_statuses(), the woocommerce_payment_complete event seen
	 * this request, or a stored date_paid — the latter two cover gateways
	 * whose configured post-payment status is not a WC paid status.
	 *
	 * @param string    $trigger created|paid.
	 * @param \WC_Order $order   The order being processed.
	 */
	private function payment_state_matches( string $trigger, \WC_Order $order ): bool {
		if ( 'created' === $trigger ) {
			return true;
		}

		return $order->is_paid()
			|| ! empty( $this->payment_completed[ $order->get_id() ] )
			|| null !== $order->get_date_paid();
	}

	/**
	 * Whether an assignment scope applies to this order origin.
	 *
	 * @param string $scope  every|pos|online.
	 * @param bool   $is_pos Whether the order was created via the POS.
	 */
	private function scope_matches( string $scope, bool $is_pos ): bool {
		if ( 'every' === $scope ) {
			return true;
		}
		if ( 'pos' === $scope ) {
			return $is_pos;
		}
		if ( 'online' === $scope ) {
			return ! $is_pos;
		}

		return false;
	}
}
