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
	const META_ERROR      = '_wcpos_pj_error';
	const META_CLAIMED_AT = '_wcpos_pj_claimed_at';

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
	 * @param array $args printer_id (required), content_type, payload (base64), order_id, format.
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
			)
		);

		update_post_meta( $id, self::META_PRINTER, sanitize_text_field( $args['printer_id'] ) );
		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );
		update_post_meta( $id, self::META_CTYPE, sanitize_text_field( $args['content_type'] ?? 'application/octet-stream' ) );
		if ( ! empty( $args['order_id'] ) ) {
			update_post_meta( $id, self::META_ORDER_ID, (int) $args['order_id'] );
		}
		if ( ! empty( $args['format'] ) ) {
			update_post_meta( $id, self::META_FORMAT, sanitize_text_field( $args['format'] ) );
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
			'payload'      => (string) $post->post_content,
		);
	}

	/**
	 * Render the bytes a printer should fetch for a job.
	 *
	 * @param array $job Job array returned by get().
	 *
	 * @return string
	 */
	public function render_payload( array $job ): string {
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
	 * Query jobs by printer and/or status (newest first).
	 *
	 * @param array $filters printer_id, status, limit.
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
		update_post_meta( $id, self::META_STATUS, self::STATUS_CLAIMED );
		update_post_meta( $id, self::META_CLAIMED_AT, time() );
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
