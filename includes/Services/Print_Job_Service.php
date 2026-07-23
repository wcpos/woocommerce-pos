<?php
/**
 * Print job store (wcpos_print_job CPT).
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Print_Job_Service class.
 */
class Print_Job_Service {
	const POST_TYPE       = 'wcpos_print_job';
	const META_PRINTER    = '_wcpos_pj_printer_id';
	const META_STATUS     = '_wcpos_pj_status';
	const META_CTYPE      = '_wcpos_pj_content_type';
	const META_ORDER_ID   = '_wcpos_pj_order_id';
	const META_FORMAT     = '_wcpos_pj_format';
	const META_TEMPLATE   = '_wcpos_pj_template_id';
	const META_ERROR      = '_wcpos_pj_error';
	const META_CLAIMED_AT   = '_wcpos_pj_claimed_at';
	const META_PN_KIND           = '_wcpos_pj_pn_kind';
	const META_EXTERNAL_PROVIDER = '_wcpos_pj_external_provider';
	const META_EXTERNAL_JOB_ID   = '_wcpos_pj_external_job_id';
	const META_EXTERNAL_STATE    = '_wcpos_pj_external_state';
	const META_SUBMIT_ATTEMPTS   = '_wcpos_pj_submit_attempts';
	const META_AUTO_OPEN_DRAWER = '_wcpos_pj_auto_open_drawer';
	const META_DRAWER_CONNECTOR = '_wcpos_pj_drawer_connector';
	const META_DRAWER_ERROR     = '_wcpos_pj_drawer_error';
	const CLAIM_LOCK_PREFIX = 'wcpos_pj_claim_lock_';

	/** Daily cron hook that prunes expired terminal jobs. */
	const PURGE_HOOK = 'wcpos_print_job_purge';

	/** Seconds a claimed job stays in-flight before it is treated as stale and re-queued. */
	const CLAIM_TTL = 120;

	const STATUS_PENDING   = 'pending';
	const STATUS_CLAIMED   = 'claimed';
	const STATUS_PRINTED   = 'printed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Constructor — register the CPT on init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( self::PURGE_HOOK, array( $this, 'purge_expired' ) );
	}

	/**
	 * Register the print job post type. Internal, not publicly queryable.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => 'WCPOS Print Jobs',
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor' ),
			)
		);

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Create a print job.
	 *
	 * @param array $args printer_id (required), content_type, payload (base64), order_id, format, template_id, pn_kind.
	 *
	 * @return int Job post ID.
	 */
	public function create( array $args ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'print-job',
				'post_content' => isset( $args['payload'] ) ? (string) $args['payload'] : '',
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, self::META_PRINTER, sanitize_text_field( $args['printer_id'] ) );
		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );
		update_post_meta( $id, self::META_CTYPE, sanitize_text_field( $args['content_type'] ?? 'application/octet-stream' ) );
		if ( ! empty( $args['order_id'] ) ) {
			update_post_meta( $id, self::META_ORDER_ID, (int) $args['order_id'] );
		}
		if ( ! empty( $args['format'] ) ) {
			update_post_meta( $id, self::META_FORMAT, sanitize_text_field( $args['format'] ) );
		}
		if ( ! empty( $args['template_id'] ) ) {
			update_post_meta( $id, self::META_TEMPLATE, sanitize_text_field( (string) $args['template_id'] ) );
		}
		if ( ! empty( $args['pn_kind'] ) ) {
			update_post_meta( $id, self::META_PN_KIND, sanitize_text_field( (string) $args['pn_kind'] ) );
		}
		if ( array_key_exists( 'auto_open_drawer', $args ) ) {
			update_post_meta( $id, self::META_AUTO_OPEN_DRAWER, ! empty( $args['auto_open_drawer'] ) ? 'yes' : 'no' );
		}
		if ( ! empty( $args['drawer_connector'] ) ) {
			update_post_meta( $id, self::META_DRAWER_CONNECTOR, self::normalize_drawer_connector( (string) $args['drawer_connector'] ) );
		}
		do_action( 'woocommerce_pos_print_job_created', (int) $id, (string) $args['printer_id'] );

		return (int) $id;
	}

	/**
	 * Get a single job as an array, or null.
	 *
	 * @param int $id Job ID.
	 *
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return array(
			'id'           => (int) $post->ID,
			'created_gmt'  => (string) $post->post_date_gmt,
			'printer_id'   => (string) get_post_meta( $id, self::META_PRINTER, true ),
			'status'       => (string) get_post_meta( $id, self::META_STATUS, true ),
			'content_type' => (string) get_post_meta( $id, self::META_CTYPE, true ),
			'order_id'     => (int) get_post_meta( $id, self::META_ORDER_ID, true ),
			'format'       => (string) get_post_meta( $id, self::META_FORMAT, true ),
			'template_id'  => (string) get_post_meta( $id, self::META_TEMPLATE, true ),
			'pn_kind'           => (string) get_post_meta( $id, self::META_PN_KIND, true ),
			'external_provider' => (string) get_post_meta( $id, self::META_EXTERNAL_PROVIDER, true ),
			'external_job_id'   => (string) get_post_meta( $id, self::META_EXTERNAL_JOB_ID, true ),
			'external_state'    => (string) get_post_meta( $id, self::META_EXTERNAL_STATE, true ),
			'payload'           => (string) $post->post_content,
			'auto_open_drawer'  => 'yes' === (string) get_post_meta( $id, self::META_AUTO_OPEN_DRAWER, true ),
			'drawer_connector'  => self::normalize_drawer_connector( (string) get_post_meta( $id, self::META_DRAWER_CONNECTOR, true ) ),
			'drawer_error'      => (string) get_post_meta( $id, self::META_DRAWER_ERROR, true ),
		);
	}

	/**
	 * Record a successful external (push-provider) submission against a job.
	 *
	 * @param int    $id       Job ID.
	 * @param string $provider Provider key (e.g. 'printnode', 'star-online').
	 * @param string $job_id   External job id (opaque string).
	 * @param string $state    Submission state (e.g. 'submitted').
	 */
	public function record_external_submission( int $id, string $provider, string $job_id, string $state ): void {
		update_post_meta( $id, self::META_EXTERNAL_PROVIDER, sanitize_text_field( $provider ) );
		update_post_meta( $id, self::META_EXTERNAL_JOB_ID, sanitize_text_field( $job_id ) );
		update_post_meta( $id, self::META_EXTERNAL_STATE, sanitize_text_field( $state ) );
	}

	/**
	 * Normalize a cash-drawer connector identifier to the server contract.
	 *
	 * @param string $connector Incoming connector value.
	 *
	 * @return string pin2 or pin5.
	 */
	public static function normalize_drawer_connector( string $connector ): string {
		$connector = strtolower( trim( $connector ) );

		if ( in_array( $connector, array( 'pin5', 'drawer_2', '1' ), true ) ) {
			return 'pin5';
		}

		return 'pin2';
	}

	/**
	 * Load a receipt template by id (numeric stored template or virtual slug).
	 *
	 * Single source of truth for template resolution shared by render_payload(),
	 * the auto-print trigger, and the manual print-jobs endpoint.
	 *
	 * @param string $template_id Template id (numeric) or virtual slug.
	 *
	 * @return array|null Template array, or null when not found.
	 */
	public static function load_template( string $template_id ): ?array {
		return is_numeric( $template_id )
			? \WCPOS\WooCommercePOS\Templates::get_template( (int) $template_id )
			: \WCPOS\WooCommercePOS\Templates::get_virtual_template( $template_id, 'receipt' );
	}

	/**
	 * Render the bytes a printer should fetch for a job.
	 *
	 * @param array $job Job array returned by get().
	 *
	 * @return string
	 */
	public function render_payload( array $job ): string {
		if ( ! empty( $job['order_id'] ) && ! empty( $job['template_id'] ) && ! empty( $job['pn_kind'] ) ) {
			$template = self::load_template( (string) $job['template_id'] );
			if ( null === $template ) {
				return '';
			}

			$order = wc_get_order( (int) $job['order_id'] );
			if ( ! $order ) {
				return '';
			}

			if ( 'pdf' === $job['pn_kind'] ) {
				try {
					return ( new Template_Pdf_Service() )->render( $template, $order );
				} catch ( \Throwable $e ) {
					\WCPOS\WooCommercePOS\Logger::log(
						sprintf( 'Cloud print: PrintNode PDF render failed for job %d: %s', (int) $job['id'], $e->getMessage() )
					);

					return '';
				}
			}

			if ( 'escpos' === $job['pn_kind'] ) {
				try {
					return ( new \WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer() )->render(
						$template,
						$order,
						'escpos',
						$this->drawer_render_options( $job )
					);
				} catch ( \Throwable $e ) {
					\WCPOS\WooCommercePOS\Logger::log(
						sprintf( 'Cloud print: PrintNode ESC/POS render failed for job %d: %s', (int) $job['id'], $e->getMessage() )
					);

					return '';
				}
			}

			return '';
		}

		if ( ! empty( $job['order_id'] ) && ! empty( $job['template_id'] ) ) {
			$template = self::load_template( (string) $job['template_id'] );
			if ( null === $template ) {
				return '';
			}

			$printer  = ( new Cloud_Print_Registry() )->get_printer( (string) $job['printer_id'] );
			$provider = $printer['provider'] ?? 'star-cloudprnt';
			$wire     = Provider::wire_format( $provider, (string) ( $template['engine'] ?? '' ) );
			if ( null === $wire ) {
				return '';
			}

			$order = wc_get_order( (int) $job['order_id'] );
			if ( ! $order ) {
				return '';
			}

			try {
				return ( new \WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer() )->render(
					$template,
					$order,
					$wire,
					$this->drawer_render_options( $job )
				);
			} catch ( \Throwable $e ) {
				// Defense in depth: never let a malformed template/payload bubble up
				// as a 500 and leave the poll's claimed job stuck. Returning empty
				// lets the caller treat the job as having nothing to print.
				\WCPOS\WooCommercePOS\Logger::log(
					sprintf( 'Cloud print: thermal render failed for job %d: %s', (int) $job['id'], $e->getMessage() )
				);

				return '';
			}
		}

		if ( ! empty( $job['order_id'] ) && ! empty( $job['format'] ) ) {
			$order = wc_get_order( (int) $job['order_id'] );
			if ( ! $order ) {
				return '';
			}

			$data    = ( new Receipt_Data_Builder() )->build( $order, 'live' );
			$adapter = ( new Receipt_Output_Adapter_Factory() )->create( (string) $job['format'] );

			return $adapter->transform( $data );
		}

		$payload = base64_decode( (string) $job['payload'], true );

		return false === $payload ? '' : $payload;
	}

	/**
	 * Build drawer options for thermal rendering.
	 *
	 * @param array $job Job array.
	 *
	 * @return array{auto_open_drawer:bool, drawer_connector:string}
	 */
	private function drawer_render_options( array $job ): array {
		return array(
			'auto_open_drawer' => ! empty( $job['auto_open_drawer'] ),
			'drawer_connector' => (string) ( $job['drawer_connector'] ?? 'pin2' ),
		);
	}

	/**
	 * Query jobs by printer, status and/or order (newest first).
	 *
	 * @param array $filters printer_id, status, order_id, limit.
	 *
	 * @return array<int, array>
	 */
	public function query( array $filters = array() ): array {
		$meta_query = $this->filters_to_meta_query( $filters );

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => isset( $filters['limit'] ) ? (int) $filters['limit'] : 50,
				'paged'          => isset( $filters['page'] ) ? max( 1, (int) $filters['page'] ) : 1,
				// ID breaks date ties: jobs created in the same second must
				// keep a stable order or offset pagination duplicates rows.
				'orderby'        => array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		return array_map(
			function ( $post ) {
				return $this->get( (int) $post->ID );
			},
			$posts
		);
	}

	/**
	 * Queue-view rows: like query(), but never hydrates post_content — a
	 * raster receipt payload is megabytes the queue table doesn't need, and
	 * a page of them would be loaded into memory on every refresh.
	 *
	 * @param array $filters printer_id / status / limit / page.
	 *
	 * @return array<int, array>
	 */
	public function query_rows( array $filters = array() ): array {
		global $wpdb;

		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => isset( $filters['limit'] ) ? (int) $filters['limit'] : 50,
				'paged'          => isset( $filters['page'] ) ? max( 1, (int) $filters['page'] ) : 1,
				'orderby'        => array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $this->filters_to_meta_query( $filters ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$ids   = array_map( 'intval', $query->posts );
		if ( empty( $ids ) ) {
			return array();
		}
		update_meta_cache( 'post', $ids );

		$placeholders = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		// Direct, content-free date lookup: get_post() would pull the full
		// row (payload included) into the object cache, defeating the point.
		$dates = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a %d list.
			$wpdb->prepare( "SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $ids ),
			OBJECT_K
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			function ( int $id ) use ( $dates ): array {
				return array(
					'id'           => $id,
					'created_gmt'  => isset( $dates[ $id ] ) ? (string) $dates[ $id ]->post_date_gmt : '',
					'printer_id'   => (string) get_post_meta( $id, self::META_PRINTER, true ),
					'status'       => (string) get_post_meta( $id, self::META_STATUS, true ),
					'content_type' => (string) get_post_meta( $id, self::META_CTYPE, true ),
					'order_id'     => (int) get_post_meta( $id, self::META_ORDER_ID, true ),
					'format'       => (string) get_post_meta( $id, self::META_FORMAT, true ),
					'template_id'  => (string) get_post_meta( $id, self::META_TEMPLATE, true ),
				);
			},
			$ids
		);
	}

	/**
	 * Count jobs matching the same filters query() accepts.
	 *
	 * @param array $filters printer_id / status / order_id / template_id.
	 *
	 * @return int
	 */
	public function count( array $filters = array() ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $this->filters_to_meta_query( $filters ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * One grouped pass over every job: per printer and status, the job count
	 * and the oldest creation time (GMT, MySQL format).
	 *
	 * Replaces a per-printer count/oldest query fan-out — the queue view
	 * refreshes every 30 seconds, so its summary must cost one query no
	 * matter how many printers are registered.
	 *
	 * @return array<string, array<string, array{count: int, oldest_gmt: string}>> printer_id => status => stats.
	 */
	public function status_summary(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one aggregate pass; WP_Query would need 2 queries per printer.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT printer.meta_value AS printer_id, status.meta_value AS job_status,
						COUNT(DISTINCT p.ID) AS jobs, MIN(p.post_date_gmt) AS oldest_gmt
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} printer ON printer.post_id = p.ID AND printer.meta_key = %s
				 INNER JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 GROUP BY printer.meta_value, status.meta_value",
				self::META_PRINTER,
				self::META_STATUS,
				self::POST_TYPE
			)
		);

		$summary = array();
		foreach ( (array) $rows as $row ) {
			$summary[ (string) $row->printer_id ][ (string) $row->job_status ] = array(
				'count'      => (int) $row->jobs,
				'oldest_gmt' => (string) $row->oldest_gmt,
			);
		}

		return $summary;
	}

	/**
	 * The creation time (GMT, MySQL format) of a printer's oldest waiting job.
	 *
	 * Waiting means pending or claimed: a printer that fetched a job and then
	 * died leaves it claimed forever, and that backlog must still surface.
	 *
	 * @param string $printer_id Printer id.
	 *
	 * @return string Empty when the printer has no waiting jobs.
	 */
	public function oldest_pending_gmt( string $printer_id ): string {
		$oldest = '';
		foreach ( array( self::STATUS_PENDING, self::STATUS_CLAIMED ) as $status ) {
			$rows = $this->query_rows(
				array(
					'printer_id' => $printer_id,
					'status'     => $status,
					'limit'      => 1,
				)
			);
			if ( ! empty( $rows ) && '' !== (string) $rows[0]['created_gmt'] ) {
				$created = (string) $rows[0]['created_gmt'];
				if ( '' === $oldest || $created < $oldest ) {
					$oldest = $created;
				}
			}
		}

		return $oldest;
	}

	/**
	 * Cancel every waiting (pending or claimed) job matching the filter.
	 *
	 * Printed, failed, and already-cancelled jobs are never touched — this
	 * exists to clear a backlog, not to rewrite history.
	 *
	 * @param array $filters ids (array of job ids) and/or printer_id.
	 *
	 * @return int Number of jobs cancelled.
	 */
	public function cancel_waiting( array $filters ): int {
		$cancellable = array( self::STATUS_PENDING, self::STATUS_CLAIMED );
		$cancelled   = 0;

		if ( ! empty( $filters['ids'] ) ) {
			foreach ( array_map( 'intval', (array) $filters['ids'] ) as $id ) {
				$job = $this->get( $id );
				if ( null !== $job && \in_array( $job['status'], $cancellable, true ) ) {
					$this->set_status( $id, self::STATUS_CANCELLED );
					++$cancelled;
				}
			}

			return $cancelled;
		}

		if ( empty( $filters['printer_id'] ) ) {
			return 0;
		}

		foreach ( $cancellable as $status ) {
			// Batched: query() pages from the front and cancelling removes
			// jobs from the result set, so repeat until the queue is drained.
			do {
				$jobs  = $this->query(
					array(
						'printer_id' => (string) $filters['printer_id'],
						'status'     => $status,
						'limit'      => 100,
					)
				);
				$batch = \count( $jobs );
				foreach ( $jobs as $job ) {
					$this->set_status( (int) $job['id'], self::STATUS_CANCELLED );
					++$cancelled;
				}
			} while ( 100 === $batch );
		}

		return $cancelled;
	}

	/**
	 * Translate public filters into a meta_query array.
	 *
	 * @param array $filters printer_id / status / order_id / template_id.
	 *
	 * @return array
	 */
	private function filters_to_meta_query( array $filters ): array {
		$meta_query = array();
		if ( ! empty( $filters['printer_id'] ) ) {
			$meta_query[] = array(
				'key'   => self::META_PRINTER,
				'value' => sanitize_text_field( $filters['printer_id'] ),
			);
		}
		if ( ! empty( $filters['status'] ) ) {
			$meta_query[] = array(
				'key'   => self::META_STATUS,
				'value' => sanitize_text_field( $filters['status'] ),
			);
		}
		if ( ! empty( $filters['order_id'] ) ) {
			$meta_query[] = array(
				'key'   => self::META_ORDER_ID,
				'value' => (int) $filters['order_id'],
				'type'  => 'NUMERIC',
			);
		}
		if ( ! empty( $filters['template_id'] ) ) {
			$meta_query[] = array(
				'key'   => self::META_TEMPLATE,
				'value' => sanitize_text_field( (string) $filters['template_id'] ),
			);
		}

		return $meta_query;
	}

	/**
	 * Set a job's status.
	 *
	 * @param int    $id     Job ID.
	 * @param string $status One of the STATUS_* constants.
	 */
	public function set_status( int $id, string $status ): void {
		update_post_meta( $id, self::META_STATUS, sanitize_text_field( $status ) );
		if ( \in_array( $status, array( self::STATUS_PRINTED, self::STATUS_CANCELLED ), true ) ) {
			// Terminal success (or abandonment): the payload has done its
			// job, and a raster receipt is hundreds of KB. The row survives
			// with metadata only — that's all the duplicate-trigger guard
			// and the queue's history view need. Failed jobs keep their
			// payload so Retry can copy it.
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => '',
				)
			);
		}
	}

	/**
	 * Delete terminal jobs past their retention window.
	 *
	 * Runs daily via PURGE_HOOK. Printed/cancelled jobs are kept for
	 * `woocommerce_pos_print_job_retention_days` (default 7 — long enough
	 * for the duplicate-trigger guard and "did it print?" questions);
	 * failed jobs for `woocommerce_pos_print_job_failed_retention_days`
	 * (default 30 — they represent unresolved problems). A filter
	 * returning 0 or less keeps that class of job forever. Waiting jobs
	 * (pending/claimed) are never purged.
	 */
	public function purge_expired(): void {
		$windows = array(
			array(
				'statuses' => array( self::STATUS_PRINTED, self::STATUS_CANCELLED ),
				'days'     => (int) apply_filters( 'woocommerce_pos_print_job_retention_days', 7 ),
			),
			array(
				'statuses' => array( self::STATUS_FAILED ),
				'days'     => (int) apply_filters( 'woocommerce_pos_print_job_failed_retention_days', 30 ),
			),
		);

		foreach ( $windows as $window ) {
			if ( $window['days'] <= 0 ) {
				continue;
			}
			$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $window['days'] * DAY_IN_SECONDS );
			$deleted = 0;
			do {
				$query = new \WP_Query(
					array(
						'post_type'      => self::POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => 200,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'date_query'     => array(
							array(
								'column' => 'post_date_gmt',
								'before' => $cutoff,
							),
						),
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'     => self::META_STATUS,
								'value'   => $window['statuses'],
								'compare' => 'IN',
							),
						),
					)
				);
				$batch = \count( $query->posts );
				foreach ( $query->posts as $post_id ) {
					wp_delete_post( (int) $post_id, true );
					++$deleted;
				}
				// Bounded per run — tomorrow's cron finishes any remainder.
			} while ( 200 === $batch && $deleted < 2000 );
		}
	}

	/**
	 * Claim a job for printing (one in-flight job per printer).
	 *
	 * @param int $id Job ID.
	 */
	public function claim( int $id ): void {
		$this->try_claim( $id );
	}

	/**
	 * Attempt to claim a job while preserving one active claim per printer.
	 *
	 * @param int $id Job ID.
	 *
	 * @return bool True when the job was claimed.
	 */
	public function try_claim( int $id ): bool {
		$job = $this->get( $id );
		if ( null === $job || self::STATUS_PENDING !== $job['status'] || '' === $job['printer_id'] ) {
			return false;
		}

		$printer_id = sanitize_text_field( $job['printer_id'] );
		if ( ! $this->acquire_claim_lock( $printer_id ) ) {
			return false;
		}

		try {
			if ( null !== $this->find_active_claim( $printer_id ) ) {
				return false;
			}

			update_post_meta( $id, self::META_STATUS, self::STATUS_CLAIMED );
			update_post_meta( $id, self::META_CLAIMED_AT, time() );

			return true;
		} finally {
			$this->release_claim_lock( $printer_id );
		}
	}

	/**
	 * The printer's current, non-stale in-flight claim, or null.
	 *
	 * @param string $printer_id Printer ID.
	 * @param int    $ttl        Claim TTL in seconds.
	 *
	 * @return array|null
	 */
	public function find_active_claim( string $printer_id, int $ttl = self::CLAIM_TTL ): ?array {
		$claimed = $this->query(
			array(
				'printer_id' => $printer_id,
				'status' => self::STATUS_CLAIMED,
				'limit' => 1,
			)
		);
		if ( empty( $claimed ) ) {
			return null;
		}
		$claimed_at = (int) get_post_meta( $claimed[0]['id'], self::META_CLAIMED_AT, true );
		if ( $claimed_at > 0 && ( time() - $claimed_at ) > $ttl ) {
			return null;
		}

		return $claimed[0];
	}

	/**
	 * Re-queue stale claims for a printer (crashed/aborted prints).
	 *
	 * @param string $printer_id Printer ID.
	 * @param int    $ttl        Claim TTL in seconds.
	 */
	public function release_stale_claims( string $printer_id, int $ttl = self::CLAIM_TTL ): void {
		$claimed = $this->query(
			array(
				'printer_id' => $printer_id,
				'status' => self::STATUS_CLAIMED,
			)
		);
		foreach ( $claimed as $job ) {
			$claimed_at = (int) get_post_meta( $job['id'], self::META_CLAIMED_AT, true );
			if ( 0 === $claimed_at || ( time() - $claimed_at ) > $ttl ) {
				update_post_meta( $job['id'], self::META_STATUS, self::STATUS_PENDING );
				delete_post_meta( $job['id'], self::META_CLAIMED_AT );
			}
		}
	}

	/**
	 * Acquire a short per-printer claim lock.
	 *
	 * @param string $printer_id Printer ID.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_claim_lock( string $printer_id ): bool {
		$option = $this->claim_lock_option( $printer_id );
		$now    = time();

		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( $option, 0 );
		if ( $locked_at > 0 && ( $now - $locked_at ) > self::CLAIM_TTL ) {
			delete_option( $option );

			return add_option( $option, (string) $now, '', false );
		}

		return false;
	}

	/**
	 * Release the per-printer claim lock.
	 *
	 * @param string $printer_id Printer ID.
	 */
	private function release_claim_lock( string $printer_id ): void {
		delete_option( $this->claim_lock_option( $printer_id ) );
	}

	/**
	 * Build the per-printer claim lock option name.
	 *
	 * @param string $printer_id Printer ID.
	 *
	 * @return string
	 */
	private function claim_lock_option( string $printer_id ): string {
		return self::CLAIM_LOCK_PREFIX . md5( $printer_id );
	}

	/**
	 * The next pending job for a printer, or null.
	 *
	 * @param string $printer_id Printer ID.
	 *
	 * @return array|null
	 */
	public function next_pending( string $printer_id ): ?array {
		$pending = $this->query(
			array(
				'printer_id' => $printer_id,
				'status' => self::STATUS_PENDING,
				'limit' => 1,
			)
		);

		return empty( $pending ) ? null : $pending[0];
	}
}
