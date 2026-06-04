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
	const CLAIM_LOCK_PREFIX = 'wcpos_pj_claim_lock_';

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
					return ( new \WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer() )->render( $template, $order, 'escpos' );
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
				return ( new \WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer() )->render( $template, $order, $wire );
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
	 * Query jobs by printer, status and/or order (newest first).
	 *
	 * @param array $filters printer_id, status, order_id, limit.
	 *
	 * @return array<int, array>
	 */
	public function query( array $filters = array() ): array {
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

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => isset( $filters['limit'] ) ? (int) $filters['limit'] : 50,
				'orderby'        => 'date',
				'order'          => 'ASC',
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
	 * Set a job's status.
	 *
	 * @param int    $id     Job ID.
	 * @param string $status One of the STATUS_* constants.
	 */
	public function set_status( int $id, string $status ): void {
		update_post_meta( $id, self::META_STATUS, sanitize_text_field( $status ) );
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
