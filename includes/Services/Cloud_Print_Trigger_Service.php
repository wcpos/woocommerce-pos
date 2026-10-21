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
	/**
	 * The Cloud Print settings option key.
	 *
	 * Read directly rather than through Cloud_Print_Section: the section's
	 * read() decorates rows with live printer status, which means outbound
	 * HTTP, and this class runs on woocommerce_new_order and
	 * woocommerce_order_status_changed. Network calls do not belong on the
	 * checkout path. It also redacts secrets, which the printer-poll and
	 * PrintNode paths need intact.
	 *
	 * @var string
	 */
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
	 * Keyed like the deferred list (blog and order id), so a paid signal on
	 * one blog cannot mark an order with the same id on another blog as paid.
	 *
	 * @var array<string, bool>
	 */
	private $payment_completed = array();

	/**
	 * Printer registry.
	 *
	 * @var Cloud_Print_Registry
	 */
	private $registry;

	/**
	 * Orders waiting for the end-of-request evaluation, in event order.
	 *
	 * Keyed by blog and order id, so an order changing status several times in
	 * one request is evaluated once, on the blog it belongs to.
	 *
	 * @var array<string, array{0: int, 1: int}> [ blog id, order id ] by key.
	 */
	private $deferred = array();

	/**
	 * Whether the shutdown flush has run on this request.
	 *
	 * @var bool
	 */
	private $shutdown_flushed = false;

	/**
	 * Constructor — hook order events.
	 *
	 * The order hooks fire inside WC_Order::save(), so they only record the
	 * order; the jobs are created at shutdown. Creating them inline ran a
	 * WP_Query, a wp_insert_post() per job (every plugin's save_post
	 * listeners), the relay hint and an order meta save inside the order's
	 * own save, where any throw became an "Error during status transition"
	 * order note.
	 */
	public function __construct() {
		$this->jobs     = new Print_Job_Service();
		$this->registry = new Cloud_Print_Registry();
		add_action( 'woocommerce_new_order', array( $this, 'defer_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'defer_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'defer_paid_order' ), 20, 1 );
	}

	/**
	 * Record an order event; the order is evaluated at shutdown.
	 *
	 * @param int $order_id Order ID.
	 */
	public function defer_order( $order_id ): void {
		$order_id = (int) $order_id;

		if ( $this->evaluates_now() ) {
			$this->evaluate( $order_id );
			return;
		}

		$key = self::order_key( $order_id );
		if ( ! isset( $this->deferred[ $key ] ) ) {
			$this->deferred[ $key ] = array( get_current_blog_id(), $order_id );
		}

		// LAST on shutdown, like the sync journal: WooCommerce saves the
		// customer at 10 and the session at 20, and an order event those raise
		// must still find the list open. Zero accepted args: do_action(
		// 'shutdown' ) passes an empty string otherwise.
		if ( false === has_action( 'shutdown', array( $this, 'flush_deferred_at_shutdown' ) ) ) {
			add_action( 'shutdown', array( $this, 'flush_deferred_at_shutdown' ), PHP_INT_MAX, 0 );
		}
	}

	/**
	 * Record a payment completing; the order is evaluated at shutdown.
	 *
	 * The paid signal is remembered now, so the shutdown evaluation sees it.
	 *
	 * @param int $order_id Order ID.
	 */
	public function defer_paid_order( $order_id ): void {
		$this->payment_completed[ self::order_key( (int) $order_id ) ] = true;
		$this->defer_order( $order_id );
	}

	/**
	 * Evaluate every deferred order, in event order.
	 *
	 * Idempotent: the list is emptied before any evaluation, so a throw or an
	 * order event raised while printing cannot replay an entry. One order's
	 * failure is logged and does not stop the next.
	 */
	public function flush_deferred(): void {
		$deferred       = $this->deferred;
		$this->deferred = array();

		foreach ( $deferred as $entry ) {
			list( $blog_id, $order_id ) = $entry;
			$switch                     = is_multisite() && get_current_blog_id() !== $blog_id;
			if ( $switch ) {
				switch_to_blog( $blog_id );
			}
			try {
				$this->evaluate( $order_id );
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Evaluate one order outside its save; a failure is logged, never thrown.
	 *
	 * Nothing propagates out of a shutdown handler anyway, and one order's
	 * failure must not stop the next; the log line is what support needs.
	 *
	 * @param int $order_id Order ID.
	 */
	private function evaluate( int $order_id ): void {
		try {
			$this->handle_order( $order_id );
		} catch ( \Throwable $e ) {
			Logger::log( sprintf( 'Cloud print: creating print jobs for order #%d failed: %s', $order_id, $e->getMessage() ) );
		}
	}

	/**
	 * Whether an order event must be evaluated now instead of queued.
	 *
	 * - After the shutdown flush: nothing flushes twice, so an event raised
	 *   by a later shutdown handler is evaluated at once.
	 * - In the last shutdown priority with no flush registered yet: a callback
	 *   added at PHP_INT_MAX while that priority is running never runs. A
	 *   flush registered before it started is still ahead (the latch is not
	 *   set), so the event is queued for it. At an earlier priority (WooCommerce
	 *   saves the customer at 10, the session at 20) WordPress still runs a
	 *   flush added late, so the event is queued as usual.
	 * - Under WP-CLI: the pre-deferral behaviour. A CLI process has no guest
	 *   or kses exposure, the operator sees the output, and receipts must not
	 *   wait for a long importer to finish.
	 */
	private function evaluates_now(): bool {
		if ( $this->shutdown_flushed || ( \defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		if ( ! doing_action( 'shutdown' ) ) {
			return false;
		}

		global $wp_filter;
		$priority = isset( $wp_filter['shutdown'] ) ? $wp_filter['shutdown']->current_priority() : false;
		if ( false === $priority ) {
			return true;
		}
		if ( PHP_INT_MAX !== $priority ) {
			return false;
		}

		return false === has_action( 'shutdown', array( $this, 'flush_deferred_at_shutdown' ) );
	}

	/**
	 * Request-scoped key for an order: blog and order id.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function order_key( int $order_id ): string {
		return get_current_blog_id() . ':' . $order_id;
	}

	/**
	 * Shutdown flush: evaluate the deferred orders, then evaluate any later
	 * event at once.
	 */
	public function flush_deferred_at_shutdown(): void {
		$this->shutdown_flushed = true;
		$this->flush_deferred();
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
	 * Order-meta key holding how many jobs an assignment has already fired.
	 *
	 * Keyed by the same triple the job count filters on, so two rules that
	 * differ only by trigger keep separate marks. Hashed because a template id
	 * can be an arbitrary virtual slug and meta keys have a length limit.
	 *
	 * @param string $printer_id  Printer id.
	 * @param string $template_id Template id.
	 * @param string $trigger     Normalized trigger.
	 */
	private static function fired_meta_key( string $printer_id, string $template_id, string $trigger ): string {
		return '_wcpos_cp_fired_' . md5( $printer_id . "\0" . $template_id . "\0" . $trigger );
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
				// Not a duplicate of the Settings Section's clamp: this one guards
				// the output of the woocommerce_pos_cloud_print_assignments filter,
				// which Pro substitutes rows into (Cloud_Print_Per_Outlet). Rows
				// that arrive through the filter never passed the section's
				// sanitizer, so an extension can hand us copies: 999. Keep it.
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
				// Counting rows alone cannot dedupe: the rows are deletable (by
				// the admin, and by the retention purge), and handle_order()
				// runs again on every later status change. A deleted receipt
				// would then read as never printed and be queued a second time
				// — including one the admin had deliberately cancelled. The
				// high-water mark survives the rows it counts.
				$fired_key = self::fired_meta_key( $printer_id, $template_id, $trigger );
				$fired     = (int) $order->get_meta( $fired_key );
				$shortfall = max( 0, $copies - max( $existing, $fired ) );
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
					if ( $job_id > 0 ) {
						++$fired;
						$order->update_meta_data( $fired_key, (string) $fired );
						$order->save_meta_data();
					}
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

		// Provider::format() owns both halves of the answer for every provider: an
		// empty kind means the template cannot be rendered on this printer.
		$fmt = Provider::format( $printer, $template );
		if ( '' === $fmt['kind'] ) {
			return 0;
		}

		$job_args = array(
			'printer_id'      => $printer_id,
			'content_type'    => $fmt['content_type'],
			'order_id'        => $order_id,
			'template_id'     => $template_id,
			'trigger'         => $trigger,
			'auto_open_drawer' => ! empty( $drawer_options['auto_open_drawer'] ),
			'drawer_connector' => $drawer_options['drawer_connector'],
		);
		if ( Provider::stores_job_kind( $provider ) ) {
			$job_args['pn_kind'] = $fmt['kind'];
		}

		$job_id = $jobs->create( $job_args );

		// Push providers (e.g. Star Online) don't poll us; submit out-of-band.
		if ( $job_id > 0 && Provider::requires_submit( $provider ) ) {
			wp_schedule_single_event( time(), self::CRON_SUBMIT, array( $job_id ) );
		}

		return $job_id;
	}

	/**
	 * Keep drawer metadata scoped to providers that can act on it.
	 *
	 * Zeroing it elsewhere is not cosmetic: a job that carries drawer metadata a
	 * renderer never reads would promise the cashier a drawer kick that never
	 * fires. Star Online is the remaining opt-out — stario.online renders our
	 * markup and the markup has no drawer verb.
	 *
	 * @param string $provider       Provider key.
	 * @param array  $drawer_options Drawer options.
	 *
	 * @return array{auto_open_drawer:bool, drawer_connector:string}
	 */
	private static function drawer_options_for_provider( string $provider, array $drawer_options ): array {
		if ( ! Provider::supports_drawer( $provider ) ) {
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
			|| ! empty( $this->payment_completed[ self::order_key( $order->get_id() ) ] )
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
