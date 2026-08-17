<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and generated SQL fragments.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database failures are passed to exceptions, not rendered.

use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_REST_Request;

final class Sync_Journal {
	/** Persisted order backfill cursor. */
	public const BACKFILL_OPTION = 'woocommerce_pos_sync_index_backfill';

	/** Generation marker for the journal sequence space. */
	public const EPOCH_OPTION = 'woocommerce_pos_sync_journal_epoch';

	/** Wall-clock ms spent inside record() during the CURRENT request. */
	public static float $request_write_ms = 0.0;

	/** Per-request dedup of identical customer lifecycle events. */
	private array $recorded_this_request = array();

	/** Highest sequence ever removed by lossy tombstone pruning. */
	const PRUNE_WATERMARK_OPTION = 'wcpos_change_log_prune_watermark';

	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Health::SYNC_JOURNAL_TABLE;
	}

	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (\n"
			. "  sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
			. "  object_type VARCHAR(20) NOT NULL,\n"
			. "  object_id BIGINT UNSIGNED NOT NULL,\n"
			. "  deleted TINYINT(1) NOT NULL DEFAULT 0,\n"
			. "  revision VARCHAR(80) NOT NULL DEFAULT '',\n"
			. "  modified_gmt DATETIME NOT NULL,\n"
			. "  origin VARCHAR(40) NOT NULL DEFAULT 'hook',\n"
			. "  created_gmt DATETIME NOT NULL,\n"
			. "  PRIMARY KEY  (sequence),\n"
			. "  KEY type_sequence (object_type, sequence),\n"
			. "  KEY type_object (object_type, object_id)\n"
			. ") {$charset_collate};";
	}

	public function install(): void {
		global $wpdb;
		$table_name    = $this->table_name();
		$table_existed = Health::table_exists( $table_name );
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $table_name, $wpdb->get_charset_collate() ) );

		if ( ! $table_existed && Health::table_exists( $table_name ) ) {
			delete_option( self::PRUNE_WATERMARK_OPTION );
		}
		if ( ! Health::table_exists( $table_name ) ) {
			return;
		}

		// The epoch marks a SEQUENCE GENERATION. Regenerate it only when the
		// table was actually (re)created — dbDelta on a surviving table is a
		// no-op and every row survives, so activation/upgrade re-runs must not
		// force every client (both lanes, since the epoch is journal-global)
		// into a needless resync-from-zero.
		if ( ! $table_existed ) {
			$this->regenerate_epoch();
		}
		$backfill = $this->backfill_status();
		$backfill_has_progress = 'idle' !== $backfill['status'] || $backfill['nextPage'] > 1 || $backfill['processed'] > 0;
		if ( 0 === $this->head_sequence() && $backfill_has_progress ) {
			delete_option( self::BACKFILL_OPTION );
		}

		// A store with no orders has nothing to backfill: mark it complete on a
		// fresh table so sequence-zero pulls are journal-authoritative from the
		// first write (Order_Query holds the baseline on the modified-date scan
		// until the backfill is complete). Stores WITH history stay incomplete
		// until the admin backfill runs. Runs after the stale-cursor cleanup
		// above so a carried-over cursor cannot masquerade as completion.
		if ( ! $table_existed && function_exists( 'wc_get_orders' ) ) {
			$existing = wc_get_orders(
				array(
					'type'   => 'shop_order',
					'limit'  => 1,
					'return' => 'ids',
				)
			);
			if ( is_array( $existing ) && array() === $existing ) {
				update_option(
					self::BACKFILL_OPTION,
					array(
						'status'      => 'complete',
						'nextPage'    => 1,
						'pageSize'    => null,
						'processed'   => 0,
						'lastOrderId' => 0,
						'lastRunGmt'  => gmdate( 'c' ),
					),
					false
				);
			}
		}
	}

	/** Return the stable id for the current sequence generation. */
	public function ensure_epoch(): string {
		$epoch = (string) get_option( self::EPOCH_OPTION, '' );
		if ( '' !== $epoch ) {
			return $epoch;
		}

		$epoch = $this->mint_epoch();
		add_option( self::EPOCH_OPTION, $epoch, '', true );
		return (string) get_option( self::EPOCH_OPTION, $epoch );
	}

	/** Force a new id for the current sequence generation. */
	public function regenerate_epoch(): string {
		$epoch = $this->mint_epoch();
		update_option( self::EPOCH_OPTION, $epoch, true );
		return $epoch;
	}

	private function mint_epoch(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'wcpos-epoch', true ) );
	}

	/** Append a schema-upgrade customer row for every live user. */
	public function append_customer_updates_for_all_users(): bool {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, deleted, revision, modified_gmt, origin, created_gmt)'
				. " SELECT 'customer', ID, 0, '', %s, 'schema-upgrade', %s FROM " . $wpdb->users,
				$now,
				$now
			)
		);
	}

	public function register_hooks(): void {
		add_action( 'woocommerce_new_product', array( $this, 'record_product_created' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'record_product_updated' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'record_variation_created' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'record_variation_updated' ), 10, 1 );
		add_action( 'woocommerce_new_coupon', array( $this, 'record_coupon_created' ), 10, 1 );
		add_action( 'woocommerce_update_coupon', array( $this, 'record_coupon_updated' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'record_post_untrashed' ), 10, 1 );
		add_action( 'woocommerce_tax_rate_added', array( $this, 'record_tax_rate_created' ), 10, 1 );
		add_action( 'woocommerce_tax_rate_updated', array( $this, 'record_tax_rate_updated' ), 10, 1 );
		add_action( 'woocommerce_tax_rate_deleted', array( $this, 'record_tax_rate_deleted' ), 10, 1 );
		add_action( 'created_term', array( $this, 'record_term_created' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'record_term_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'record_term_deleted' ), 10, 3 );
		add_action( 'added_term_meta', array( $this, 'record_term_meta_change' ), 10, 2 );
		add_action( 'updated_term_meta', array( $this, 'record_term_meta_change' ), 10, 2 );
		add_action( 'deleted_term_meta', array( $this, 'record_term_meta_deleted' ), 10, 2 );
		add_action( 'user_register', array( $this, 'record_customer_created' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'record_customer_created_persisted' ), 10, 1 );
		add_action( 'woocommerce_new_customer', array( $this, 'record_customer_created_persisted' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'record_customer_profile_update' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'record_customer_role_change' ), 10, 3 );
		add_action( 'add_user_role', array( $this, 'record_customer_role_added' ), 10, 2 );
		add_action( 'remove_user_role', array( $this, 'record_customer_role_removed' ), 10, 2 );
		add_action( 'woocommerce_update_customer', array( $this, 'record_customer_updated' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'record_customer_deleted' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'record_order_created' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'record_order_updated' ), 10, 1 );
		add_action( 'woocommerce_before_trash_order', array( $this, 'record_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'record_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_untrash_order', array( $this, 'record_cot_order_untrashed' ), 10, 1 );
	}

	public function record_product_created( int $product_id ): void {
		$this->record_catalogue_object( 'product', $product_id, false );
	}

	public function record_product_updated( int $product_id ): void {
		$this->record_catalogue_object( 'product', $product_id, false );
	}

	public function record_variation_created( int $variation_id ): void {
		$this->record_catalogue_object( 'variation', $variation_id, false );
		$this->record_variation_parent( $variation_id );
	}

	public function record_variation_updated( int $variation_id ): void {
		$this->record_catalogue_object( 'variation', $variation_id, false );
		$this->record_variation_parent( $variation_id );
	}

	/** Record the representation change to a variation's parent product. */
	private function record_variation_parent( int $variation_id ): void {
		$parent_id = function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $variation_id ) : 0;
		if ( $parent_id > 0 ) {
			$this->record_catalogue_object( 'product', $parent_id, false );
		}
	}

	public function record_post_deleted( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			$this->record_catalogue_object( 'product', $post_id, true );
			return;
		}
		if ( 'product_variation' === $post_type ) {
			$this->record_catalogue_object( 'variation', $post_id, true );
			$this->record_variation_parent( $post_id );
			return;
		}
		if ( 'shop_coupon' === $post_type ) {
			$this->record( 'coupon', $post_id, true );
			return;
		}
		if ( 'shop_order' === $post_type ) {
			$this->record_order_deleted( $post_id );
		}
	}

	public function record_post_untrashed( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			$this->record_product_updated( $post_id );
			return;
		}
		if ( 'product_variation' === $post_type ) {
			$this->record_variation_updated( $post_id );
			return;
		}
		if ( 'shop_coupon' === $post_type ) {
			$this->record_coupon_updated( $post_id );
			return;
		}
		if ( 'shop_order' === $post_type ) {
			$this->record_order_untrashed( $post_id );
		}
	}

	public function record_coupon_created( int $coupon_id ): void {
		$this->record( 'coupon', $coupon_id, false );
	}

	public function record_coupon_updated( int $coupon_id ): void {
		$this->record( 'coupon', $coupon_id, false );
	}

	/** Map tracked product taxonomies to journal object types. */
	private static function term_taxonomy_object_types(): array {
		$map = array();
		foreach ( Collections::with( 'identity' ) as $row ) {
			if ( isset( $row['identity']['taxonomy'] ) ) {
				$map[ $row['identity']['taxonomy'] ] = $row['object_type'];
			}
		}
		return $map;
	}

	public function record_term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->record_term_change( $term_id, $taxonomy, 'create' );
	}

	public function record_term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->record_term_change( $term_id, $taxonomy, 'update' );
	}

	public function record_term_deleted( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->record_term_change( $term_id, $taxonomy, 'delete' );
	}

	private function record_term_change( int $term_id, string $taxonomy, string $change_type ): void {
		$object_type = self::term_taxonomy_object_types()[ $taxonomy ] ?? null;
		if ( null !== $object_type ) {
			$this->record( $object_type, $term_id, 'delete' === $change_type );
		}
	}

	/** Record added or updated metadata for a tracked term. */
	public function record_term_meta_change( int $meta_id, int $term_id ): void {
		$this->record_term_representation_change( $term_id );
	}

	/** Record deleted metadata for a tracked term. */
	public function record_term_meta_deleted( array $meta_ids, int $term_id ): void {
		$this->record_term_representation_change( $term_id );
	}

	/** Resolve a meta hook's term and record it only when tracked. */
	private function record_term_representation_change( int $term_id ): void {
		$term = get_term( $term_id );
		if ( is_object( $term ) && isset( $term->taxonomy ) ) {
			$this->record_term_change( $term_id, (string) $term->taxonomy, 'update' );
		}
	}

	public function record_tax_rate_created( int $tax_rate_id ): void {
		$this->record( 'tax_rate', $tax_rate_id, false );
	}

	public function record_tax_rate_updated( int $tax_rate_id ): void {
		$this->record( 'tax_rate', $tax_rate_id, false );
	}

	public function record_tax_rate_deleted( int $tax_rate_id ): void {
		$this->record( 'tax_rate', $tax_rate_id, true );
	}

	public function record_customer_created( int $customer_id ): void {
		$this->record_customer( $customer_id, false, true, 'create' );
	}

	/** WooCommerce create hooks for any user — definitive post-persist create with persisted dedup. */
	public function record_customer_created_persisted( int $customer_id ): void {
		$this->record_customer( $customer_id, false, false, 'create' );
	}

	/** Record the definitive post-persist customer update. */
	public function record_customer_updated( int $customer_id ): void {
		$this->record_customer( $customer_id, false, false );
	}

	/** Record a WordPress profile update as a customer update. */
	public function record_customer_profile_update( int $user_id, $old_user_data = null ): void {
		$this->record_customer( $user_id, false );
	}

	/** Record a customer role replacement. */
	public function record_customer_role_change( int $user_id, $role = '', $old_roles = array() ): void {
		$this->record_customer( $user_id, false );
	}

	/** add_user_role handler — any added role changes the served customer record. */
	public function record_customer_role_added( int $user_id, $role = '' ): void {
		$this->record_customer( $user_id, false );
	}

	/** Record a removed customer role as a present update. */
	public function record_customer_role_removed( int $user_id, $role = '' ): void {
		$this->record_customer( $user_id, false );
	}

	/** delete_user handler — deletion removes any user from the POS customer space. */
	public function record_customer_deleted( int $customer_id ): void {
		$this->record_customer( $customer_id, true, true, 'delete' );
	}

	public function record_order_created( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:create', false );
	}

	public function record_order_updated( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:update', false );
	}

	public function record_order_deleted( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:delete', true );
	}

	public function record_order_untrashed( int $order_id ): void {
		$this->record_order_change( $order_id, 'hook:untrash', false );
	}

	/**
	 * Record an HPOS order's restore once the status change has settled.
	 *
	 * `woocommerce_untrash_order` fires BEFORE the data store restores the
	 * status, so the row cannot be written there. The restore then performs
	 * MORE THAN ONE object save, so arming on the first
	 * `woocommerce_after_order_object_save` whose status is not `trash`
	 * captures a revision from part-way through the restore — anything a later
	 * save changes is missing from it, and the journal advertises a revision
	 * the order does not have.
	 *
	 * Measured sequence for an HPOS untrash (status read from wc_orders):
	 *
	 *   woocommerce_untrash_order                stored=trash
	 *   after_order_object_save  object=pending  stored=wc-pending
	 *   after_order_object_save  object=pending  stored=wc-pending
	 *   woocommerce_order_status_changed         stored=wc-pending  from=trash
	 *
	 * `woocommerce_order_status_changed` fires once, last, with the stored
	 * status settled — so observe that instead. CPT orders never reach here:
	 * their restore fires only `untrashed_post` (see record_post_untrashed).
	 *
	 * @param int $order_id Order being restored.
	 */
	public function record_cot_order_untrashed( int $order_id ): void {
		$handler = function ( $id, $from ) use ( $order_id, &$handler ): void {
			if ( (int) $id !== $order_id || 'trash' !== $from ) {
				return;
			}
			remove_action( 'woocommerce_order_status_changed', $handler );
			$this->record_order_untrashed( $order_id );
		};
		add_action( 'woocommerce_order_status_changed', $handler, 10, 2 );
	}

	public function record_order_change( int $order_id, string $origin, bool $deleted ): bool {
		global $wpdb;
		$order         = wc_get_order( $order_id );
		$modified_date = $order ? $order->get_date_modified() : null;
		$modified      = $modified_date ? gmdate( 'Y-m-d H:i:s', $modified_date->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );
		$revision      = 'deleted';

		if ( $order && ! $deleted ) {
			$serializer = new Order_Serializer();
			$payload    = $serializer->serialize_order( $order_id, new WP_REST_Request() );
			$sync_meta  = $serializer->sync_metadata( $payload, $order_id, 'custom-pull', false, 0 );
			$revision   = (string) $sync_meta['revision'];
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		return false !== $wpdb->insert(
			$this->table_name(),
			array(
				'object_type' => 'order',
				'object_id' => $order_id,
				'deleted' => $deleted ? 1 : 0,
				'revision' => $revision,
				'modified_gmt' => $modified,
				'origin' => $origin,
				'created_gmt' => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private function record_catalogue_object( string $object_type, int $object_id, bool $deleted ): void {
		$object = function_exists( 'wc_get_product' ) ? wc_get_product( $object_id ) : null;
		$this->record( $object_type, $object_id, $deleted, self::object_revision( $object ) );
	}

	private function record_customer( int $customer_id, bool $deleted, bool $dedup = true, string $dedup_namespace = 'update' ): void {
		$customer = class_exists( '\\WC_Customer' ) ? new \WC_Customer( $customer_id ) : null;
		$this->record( 'customer', $customer_id, $deleted, self::object_revision( $customer ), 'hook', $dedup, $dedup_namespace );
	}

	private static function object_revision( $object ): string {
		$date = is_object( $object ) && method_exists( $object, 'get_date_modified' ) ? $object->get_date_modified() : null;
		return $date && method_exists( $date, 'getTimestamp' ) ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : '';
	}

	public function record( string $object_type, int $object_id, bool $deleted, string $revision = '', string $origin = 'hook', bool $dedup = true, string $dedup_namespace = '' ): void {
		global $wpdb;
		$dedup_key = null;
		if ( 'customer' === $object_type ) {
			$dedup_key = ( $dedup ? '' : 'persisted:' ) . $object_id . ':' . ( '' !== $dedup_namespace ? $dedup_namespace : ( $deleted ? 'delete' : 'update' ) );
			if ( isset( $this->recorded_this_request[ $dedup_key ] ) ) {
				if ( $dedup ) {
					return; // a pre-persist duplicate carries no new state
				}
				$wpdb->delete(
					$this->table_name(),
					array( 'sequence' => (int) $this->recorded_this_request[ $dedup_key ] ),
					array( '%d' )
				);
			}
		}
		$started = microtime( true );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->table_name(),
			array(
				'object_type' => $object_type,
				'object_id' => $object_id,
				'deleted' => $deleted ? 1 : 0,
				'revision' => $revision,
				'modified_gmt' => $now,
				'origin' => $origin,
				'created_gmt' => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( null !== $dedup_key ) {
			$this->recorded_this_request[ $dedup_key ] = $dedup ? true : (int) $wpdb->insert_id;
		}
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
	}

	/** Return the persisted order-backfill cursor. */
	public function backfill_status(): array {
		$status = get_option( self::BACKFILL_OPTION, array() );
		$status = is_array( $status ) ? $status : array();

		return array(
			'status' => isset( $status['status'] ) ? (string) $status['status'] : 'idle',
			'nextPage' => isset( $status['nextPage'] ) ? max( 1, (int) $status['nextPage'] ) : 1,
			'pageSize' => isset( $status['pageSize'] ) ? max( 1, min( 250, (int) $status['pageSize'] ) ) : null,
			'processed' => isset( $status['processed'] ) ? max( 0, (int) $status['processed'] ) : 0,
			'lastOrderId' => isset( $status['lastOrderId'] ) ? max( 0, (int) $status['lastOrderId'] ) : 0,
			'lastRunGmt' => isset( $status['lastRunGmt'] ) ? (string) $status['lastRunGmt'] : null,
		);
	}

	/** Clear the full persisted order-backfill cursor. */
	public function reset_backfill_state(): void {
		delete_option( self::BACKFILL_OPTION );
	}

	/** Append one bounded page of existing orders to the journal. */
	public function run_backfill_chunk( int $limit ): array {
		$requested_limit = max( 1, min( 250, $limit ) );
		$status          = $this->backfill_status();
		if ( 'complete' === $status['status'] ) {
			return array_merge( $status, array( 'processedThisRun' => 0 ) );
		}

		$page_size     = null === $status['pageSize'] ? $requested_limit : (int) $status['pageSize'];
		$last_order_id = $status['lastOrderId'];
		$query_args    = array(
			'type' => 'shop_order',
			'limit' => $page_size,
			'orderby' => 'ID',
			'order' => 'ASC',
			'return' => 'ids',
		);
		$posts_where = null;
		if ( $last_order_id > 0 ) {
			if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$query_args['field_query'] = array(
					array(
						'field' => 'id',
						'value' => $last_order_id,
						'compare' => '>',
					),
				);
			} else {
				$posts_where = static function ( string $where ) use ( $last_order_id ): string {
					global $wpdb;
					return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last_order_id );
				};
				add_filter( 'posts_where', $posts_where );
			}
		}
		try {
			/** @var array<int|numeric-string>|mixed $queried_ids The 'return' => 'ids' arg yields ids; the stub over-narrows to WC_Order[]. */
			$queried_ids = wc_get_orders( $query_args );
		} finally {
			if ( null !== $posts_where ) {
				remove_filter( 'posts_where', $posts_where );
			}
		}
		$ids = is_array( $queried_ids ) ? array_map( 'absint', $queried_ids ) : array();
		$processed_this_run = 0;
		$failed_this_run    = 0;
		foreach ( $ids as $id ) {
			if ( $this->record_order_change( $id, 'backfill', false ) ) {
				$processed_this_run++;
				$last_order_id = $id;
			} else {
				$failed_this_run++;
				break;
			}
		}

		$all_writes_succeeded = 0 === $failed_this_run;
		$complete             = $all_writes_succeeded && count( $ids ) < $page_size;
		$advance_page         = $all_writes_succeeded && count( $ids ) === $page_size;
		$next_status          = array(
			'status' => $complete ? 'complete' : 'running',
			'nextPage' => $advance_page ? $status['nextPage'] + 1 : $status['nextPage'],
			'pageSize' => $page_size,
			'processed' => $status['processed'] + $processed_this_run,
			'lastOrderId' => $last_order_id,
			'lastRunGmt' => gmdate( 'c' ),
		);
		update_option( self::BACKFILL_OPTION, $next_status, false );

		return array_merge( $next_status, array( 'processedThisRun' => $processed_this_run ) );
	}

	/**
	 * The catalogue pointer-stream's object types, projected from the registry —
	 * every journal-covered collection except orders (which consume the journal
	 * via the payload-windowed pull lane). Single source for the sequence-log
	 * `all` stream and the purge's per-stream head protection.
	 *
	 * @return string[]
	 */
	public static function catalogue_object_types(): array {
		$types = array();
		foreach ( Collections::with( 'journal' ) as $row ) {
			$object_type = (string) ( $row['journal']['object_type'] ?? '' );
			if ( '' !== $object_type && 'order' !== $object_type ) {
				$types[] = $object_type;
			}
		}

		return $types;
	}

	/**
	 * Head of the sequence space — the change stream's current end.
	 *
	 * With `$object_types`, the head is STREAM-SCOPED: the highest sequence any
	 * row of those types holds. Every reader must serve the head of the stream
	 * it serves — orders and catalogue share one AUTO_INCREMENT space, so the
	 * global head moves on foreign writes. A stream-scoped head is what lets a
	 * cursor actually reach `head` (the 304 idle condition) while the other
	 * lane keeps writing, and is the pre-unification semantic of both lanes.
	 *
	 * @param string[] $object_types Empty = global head (retention clamps only).
	 */
	public function head_sequence( array $object_types = array() ): int {
		global $wpdb;
		if ( ! $this->table_available() ) {
			return 0;
		}

		$types = array_values( array_filter( array_map( 'strval', $object_types ), static fn( string $t ): bool => '' !== $t ) );
		if ( array() === $types ) {
			return (int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $this->table_name() );
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(sequence) FROM ' . $this->table_name() . " WHERE object_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %s placeholder list generated from count().
				...$types
			)
		);
	}

	public function table_available(): bool {
		global $wpdb;
		$table = $this->table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/** Return one sequence page for the order pull lane. */
	public function rows_after_sequence( int $sequence, int $limit, string $object_type = 'order' ): array {
		global $wpdb;
		if ( ! $this->table_available() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sequence, object_id AS order_id, modified_gmt, revision, deleted, origin, created_gmt FROM ' . $this->table_name()
				. ' WHERE object_type = %s AND sequence > %d ORDER BY sequence ASC LIMIT %d',
				$object_type,
				max( 0, $sequence ),
				max( 1, min( 251, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( self::class, 'normalize_order_row' ), $rows ) : array();
	}

	private static function normalize_order_row( array $row ): array {
		return array(
			'sequence' => isset( $row['sequence'] ) ? (int) $row['sequence'] : 0,
			'order_id' => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
			'modified_gmt' => isset( $row['modified_gmt'] ) ? (string) $row['modified_gmt'] : gmdate( 'Y-m-d H:i:s' ),
			'revision' => isset( $row['revision'] ) ? (string) $row['revision'] : '',
			'deleted' => ! empty( $row['deleted'] ) ? 1 : 0,
			'origin' => isset( $row['origin'] ) ? (string) $row['origin'] : 'hook:update',
			'created_gmt' => isset( $row['created_gmt'] ) ? (string) $row['created_gmt'] : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** Oldest sequence that remains available to incremental-sync clients. */
	public function oldest_sequence(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT MIN(sequence) FROM ' . $this->table_name() );
	}

	/** Delete one batch of superseded rows through the supplied sequence. */
	public function compact( int $cutoff_sequence, string $cutoff_gmt, int $batch ): int {
		global $wpdb;
		if ( $cutoff_sequence <= 0 || $batch <= 0 ) {
			return 0;
		}

		$table   = $this->table_name();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE sequence <= %d AND sequence IN ('
				. ' SELECT sequence FROM ('
				. ' SELECT stale.sequence FROM ' . $table . ' stale'
				. ' WHERE stale.sequence <= %d'
				. ' AND stale.created_gmt < %s'
				. ' AND EXISTS ('
				. ' SELECT 1 FROM ' . $table . ' newer'
				. ' WHERE newer.object_type = stale.object_type'
				. ' AND newer.object_id = stale.object_id'
				. ' AND newer.sequence > stale.sequence'
				. ' ) ORDER BY stale.sequence ASC LIMIT %d'
				. ' ) compactable'
				. ' )',
				$cutoff_sequence,
				$cutoff_sequence,
				$cutoff_gmt,
				$batch
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/** Delete one batch of expired tombstones — the log's only LOSSY deletion. */
	public function prune_tombstones( int $cutoff_sequence, string $cutoff_gmt, int $batch ): array {
		global $wpdb;
		$none = array(
			'deleted'   => 0,
			'watermark' => 0,
		);
		if ( $cutoff_sequence <= 0 || $batch <= 0 ) {
			return $none;
		}

		$sequences = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT sequence FROM ' . $this->table_name()
					. ' WHERE deleted = 1 AND sequence <= %d AND created_gmt < %s'
					. ' ORDER BY sequence ASC LIMIT %d',
					$cutoff_sequence,
					$cutoff_gmt,
					$batch
				)
			)
		);
		if ( empty( $sequences ) ) {
			return $none;
		}
		$watermark = max( $sequences );
		$this->advance_prune_watermark( $watermark );
		if ( $this->prune_watermark() < $watermark ) {
			return $none;
		}

		$deleted = $wpdb->query(
			'DELETE FROM ' . $this->table_name()
			. ' WHERE sequence IN (' . implode( ',', $sequences ) . ')'
		);

		return array(
			'deleted'   => false === $deleted ? 0 : (int) $deleted,
			'watermark' => $watermark,
		);
	}

	/** Resolve a wall-clock cutoff to one stable sequence boundary. */
	public function sequence_at_or_before( string $cutoff_gmt ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(sequence) FROM ' . $this->table_name() . ' WHERE created_gmt < %s',
				$cutoff_gmt
			)
		);
	}

	/** Highest sequence ever removed by lossy tombstone pruning. */
	public function prune_watermark(): int {
		return (int) get_option( self::PRUNE_WATERMARK_OPTION, 0 );
	}

	/** Advance the persisted prune watermark (never moves backwards). */
	public function advance_prune_watermark( int $sequence ): void {
		global $wpdb;
		if ( $sequence <= 0 ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->options . " (option_name, option_value, autoload) VALUES (%s, %d, 'yes')"
				. ' ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), %d)',
				self::PRUNE_WATERMARK_OPTION,
				$sequence,
				$sequence
			)
		);
		wp_cache_delete( self::PRUNE_WATERMARK_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** One page of the change stream past a cursor, plus the head it was read against. */
	public function page( array $object_types, int $since, int $limit ): array {
		global $wpdb;
		$types = array();
		foreach ( $object_types as $object_type ) {
			$object_type = (string) $object_type;
			if ( '' !== $object_type ) {
				$types[] = $object_type;
			}
		}

		$sql  = 'SELECT sequence, object_id, object_type, deleted, revision, modified_gmt FROM ' . $this->table_name() . ' WHERE ';
		$args = array();
		if ( array() !== $types ) {
			$sql .= 'object_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ';
			$args = $types;
		}
		$sql .= 'sequence > %d ORDER BY sequence ASC LIMIT %d';
		$args[] = max( 0, $since );
		$args[] = max( 1, $limit );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return array(
			'rows' => array_map(
				static function ( array $row ): array {
					return self::normalize_row( $row );
				},
				is_array( $rows ) ? $rows : array()
			),
			'head' => $this->head_sequence( $types ),
		);
	}

	/** Coerce a raw journal row to the served scalar types. */
	private static function normalize_row( array $row ): array {
		return array(
			'sequence' => isset( $row['sequence'] ) ? (int) $row['sequence'] : 0,
			'object_id' => isset( $row['object_id'] ) ? (int) $row['object_id'] : 0,
			'object_type' => isset( $row['object_type'] ) ? (string) $row['object_type'] : '',
			'deleted' => ! empty( $row['deleted'] ) ? 1 : 0,
			'revision' => isset( $row['revision'] ) ? (string) $row['revision'] : '',
			'modified_gmt' => isset( $row['modified_gmt'] ) ? (string) $row['modified_gmt'] : gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
