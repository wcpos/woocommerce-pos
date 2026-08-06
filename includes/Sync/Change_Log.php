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

final class Change_Log {

	/**
	 * Wall-clock ms spent inside record() during the CURRENT request.
	 * Read (and reset) by the product-edit fixture so the hook-overhead bench
	 * can break the write tax down per component. Instrumentation cost is two
	 * microtime() calls per hook fire — sub-microsecond against a
	 * millisecond-scale INSERT, i.e. negligible distortion of the ON arm.
	 */
	public static float $request_write_ms = 0.0;

	/**
	 * Per-request dedup of identical (object_type, object_id, change_type) records.
	 * A single role change fans out across overlapping WordPress hooks —
	 * `WP_User::set_role()` fires remove_user_role + add_user_role AND set_user_role,
	 * and a WC customer create hits both user_register and woocommerce_created_customer
	 * — which would otherwise write the SAME customer row several times, bloating the
	 * log and pushing product/tax rows to later sequence-log pages. The change-log
	 * instance is registered once and lives for the request, so an instance set
	 * collapses those to one row while distinct changes (different id/type) still pass.
	 */
	private array $recorded_this_request = array();

	/**
	 * Option holding the highest sequence ever removed by lossy tombstone
	 * pruning. Owned by the log (it is a property of the stream's history
	 * completeness, not of the cron that happens to advance it).
	 */
	const PRUNE_WATERMARK_OPTION = 'wcpos_change_log_prune_watermark';

	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Health::CHANGE_LOG_TABLE;
	}

	public function schema_sql( string $table_name, string $charset_collate = '' ): string {
		return "CREATE TABLE {$table_name} (\n"
			. "  sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
			. "  object_type VARCHAR(20) NOT NULL,\n"
			. "  object_id BIGINT UNSIGNED NOT NULL,\n"
			. "  change_type VARCHAR(10) NOT NULL,\n"
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
	}

	/**
	 * Append a compensating 'update' row for EVERY live user — the sync-schema-3
	 * migration for the #1379 customer-space widening. The persisted stream can hold
	 * role-departure tombstones from the customer-role-only era; a client cursor
	 * behind one would replay the 'delete' against a user /customers?role=all now
	 * serves. A head-appended update per user supersedes every stale tombstone and
	 * (re-)announces users the old role-filtered stream never carried.
	 *
	 * @return bool Whether the append succeeded (false leaves the schema unlatched for retry).
	 */
	public function append_customer_updates_for_all_users(): bool {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table_name() . ' (object_type, object_id, change_type, modified_gmt, origin, created_gmt)'
				. " SELECT 'customer', ID, 'update', %s, 'schema-upgrade', %s FROM " . $wpdb->users,
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
		// Coupons are the `shop_coupon` post type; WC_Coupon's CPT data store fires
		// these on create/update (the coupon mirror of woocommerce_new/update_product).
		// Deletes ride the generic wp_trash_post/before_delete_post hooks below, which
		// record_post_deleted maps to 'coupon' by post_type — catching a direct
		// wp_delete_post too, exactly like products.
		add_action( 'woocommerce_new_coupon', array( $this, 'record_coupon_created' ), 10, 1 );
		add_action( 'woocommerce_update_coupon', array( $this, 'record_coupon_updated' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'record_post_deleted' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'record_post_untrashed' ), 10, 1 );
		add_action( 'woocommerce_tax_rate_added', array( $this, 'record_tax_rate_created' ), 10, 1 );
		add_action( 'woocommerce_tax_rate_updated', array( $this, 'record_tax_rate_updated' ), 10, 1 );
		add_action( 'woocommerce_tax_rate_deleted', array( $this, 'record_tax_rate_deleted' ), 10, 1 );
		// Product taxonomies (categories/brands/tags) are WP terms — one generic
		// created/edited/delete_term hook set, filtered by taxonomy to the three WC
		// product taxonomies (record_term_*). Each carries ($term_id, $tt_id, $taxonomy)
		// as its first args; delete_term also fires on the generic path here (WP terms
		// are not posts, so record_post_deleted never sees them).
		add_action( 'created_term', array( $this, 'record_term_created' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'record_term_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'record_term_deleted' ), 10, 3 );
		// Meta-only term updates (a category/brand image, display type, or menu order)
		// change term meta WITHOUT firing edited_term — WC's REST terms controller skips
		// wp_update_term() when only meta changed. Catch those via the term-meta hooks so
		// a representation change still reaches the client (issue #319). deleted_term_meta
		// matters too: clearing a category image is a delete_term_meta('thumbnail_id'),
		// and its FIRST arg is a meta-IDS ARRAY (not an int), so it needs its own handler.
		add_action( 'added_term_meta', array( $this, 'record_term_meta_change' ), 10, 2 );
		add_action( 'updated_term_meta', array( $this, 'record_term_meta_change' ), 10, 2 );
		add_action( 'deleted_term_meta', array( $this, 'record_term_meta_deleted' ), 10, 2 );
		// ALL WordPress users are POS customers under the #1379 ruling (1.9 parity).
		// Mirror WooCommerce's own webhook topic hooks: create = user_register +
		// woocommerce_created_customer; update = profile_update +
		// woocommerce_update_customer; delete = delete_user.
		// Duplicate rows on the WC path (both create hooks fire) are harmless: the
		// client re-pulls the customer id once regardless of how many rows it sees.
		add_action( 'user_register', array( $this, 'record_customer_created' ), 10, 1 );
		// The two WC post-persist create hooks: woocommerce_created_customer (the
		// wc_create_new_customer / registration path) and woocommerce_new_customer (the
		// WC_Customer::save() data-store path, the FINAL post-persist create event).
		// Both bypass the pre-persist dedup so a definitive post-save row always lands whichever
		// create flow ran. Together with user_register this matches WooCommerce's own
		// customer.created webhook hooks.
		add_action( 'woocommerce_created_customer', array( $this, 'record_customer_created_persisted' ), 10, 1 );
		add_action( 'woocommerce_new_customer', array( $this, 'record_customer_created_persisted' ), 10, 1 );
		// Role changes are plain customer updates because role membership is part
		// of every served user record. WordPress reports them through several hooks:
		// profile_update (wp_update_user) carries the OLD user data (arg 2), while
		// WP_User::set_role() / bulk role changes fire set_user_role (with the old
		// roles) and do NOT fire profile_update.
		add_action( 'profile_update', array( $this, 'record_customer_profile_update' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'record_customer_role_change' ), 10, 3 );
		// Multi-role paths: WP_User::add_role()/remove_role() (membership plugins)
		// fire add_user_role/remove_user_role, NOT set_user_role/profile_update.
		add_action( 'add_user_role', array( $this, 'record_customer_role_added' ), 10, 2 );
		add_action( 'remove_user_role', array( $this, 'record_customer_role_removed' ), 10, 2 );
		add_action( 'woocommerce_update_customer', array( $this, 'record_customer_updated' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'record_customer_deleted' ), 10, 1 );
	}

	public function record_product_created( int $product_id ): void {
		$this->record( 'product', $product_id, 'create' );
	}

	public function record_product_updated( int $product_id ): void {
		$this->record( 'product', $product_id, 'update' );
	}

	public function record_variation_created( int $variation_id ): void {
		$this->record( 'variation', $variation_id, 'create' );
		$this->record_variation_parent( $variation_id );
	}

	public function record_variation_updated( int $variation_id ): void {
		$this->record( 'variation', $variation_id, 'update' );
		$this->record_variation_parent( $variation_id );
	}

	/**
	 * A variation change alters its PARENT variable product's served representation — the
	 * recomputed `_woocommerce_pos_variable_prices` range (stamp_proxy_variable_prices). So
	 * record a parent product 'update' too; otherwise the client re-pulls the variation but
	 * never the parent, and the fresh price range never reaches it (#324 / codex). The parent
	 * is resolvable pre-delete (before_delete_post/wp_trash_post fire before removal). No
	 * parent (0) → nothing to record.
	 */
	private function record_variation_parent( int $variation_id ): void {
		$parent_id = function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $variation_id ) : 0;
		if ( $parent_id > 0 ) {
			$this->record( 'product', $parent_id, 'update' );
		}
	}

	public function record_post_deleted( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			$this->record( 'product', $post_id, 'delete' );
			return;
		}
		if ( 'product_variation' === $post_type ) {
			$this->record( 'variation', $post_id, 'delete' );
			$this->record_variation_parent( $post_id );
			return;
		}
		if ( 'shop_coupon' === $post_type ) {
			$this->record( 'coupon', $post_id, 'delete' );
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
		}
	}

	public function record_coupon_created( int $coupon_id ): void {
		$this->record( 'coupon', $coupon_id, 'create' );
	}

	public function record_coupon_updated( int $coupon_id ): void {
		$this->record( 'coupon', $coupon_id, 'update' );
	}

	/**
	 * The three WC product taxonomies we track, mapped to the change-log object_type
	 * (singular, like 'product'/'coupon'). Any other taxonomy (post_tag, nav_menu,
	 * a third-party taxonomy) is ignored — its term edits never enter the stream.
	 */
	private static function term_taxonomy_object_types(): array {
		// Registry projection (#421 increment 5): every identity row with a
		// taxonomy IS a tracked term collection; its object_type is the
		// stream's singular tag. One home for the mapping.
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
			$this->record( $object_type, $term_id, $change_type );
		}
	}

	/**
	 * added_term_meta / updated_term_meta fire ($meta_id, $object_id, $meta_key,
	 * $meta_value); $object_id is the term id. A meta change with no accompanying field
	 * change (so edited_term never fired) is thus still recorded — see #319.
	 */
	public function record_term_meta_change( int $meta_id, int $term_id ): void {
		$this->record_term_representation_change( $term_id );
	}

	/**
	 * deleted_term_meta fires ($meta_ids [ARRAY], $object_id, $meta_key, $meta_value) —
	 * note the first arg is a meta-IDs array, NOT an int. Clearing a category/brand image
	 * (delete_term_meta 'thumbnail_id') is a representation change with no field edit, so
	 * it is recorded as an 'update' exactly like an add/update (#321 follow-up).
	 */
	public function record_term_meta_deleted( array $meta_ids, int $term_id ): void {
		$this->record_term_representation_change( $term_id );
	}

	/**
	 * Resolve the term's taxonomy (the meta hooks carry none) and record an 'update' iff
	 * it is one of the WC product taxonomies — filtering out post/nav/third-party term
	 * meta and terms get_term can no longer resolve.
	 */
	private function record_term_representation_change( int $term_id ): void {
		$term = get_term( $term_id );
		if ( is_object( $term ) && isset( $term->taxonomy ) ) {
			$this->record_term_change( $term_id, (string) $term->taxonomy, 'update' );
		}
	}

	public function record_tax_rate_created( int $tax_rate_id ): void {
		$this->record( 'tax_rate', $tax_rate_id, 'create' );
	}

	public function record_tax_rate_updated( int $tax_rate_id ): void {
		$this->record( 'tax_rate', $tax_rate_id, 'update' );
	}

	public function record_tax_rate_deleted( int $tax_rate_id ): void {
		$this->record( 'tax_rate', $tax_rate_id, 'delete' );
	}

	public function record_customer_created( int $customer_id ): void {
		$this->record( 'customer', $customer_id, 'create' );
	}

	/** WooCommerce create hooks for any user — definitive post-persist create with persisted dedup. */
	public function record_customer_created_persisted( int $customer_id ): void {
		$this->record( 'customer', $customer_id, 'create', 'hook', false );
	}

	/**
	 * woocommerce_update_customer — the definitive post-persist update for any
	 * user. Bypasses dedup so it repairs an earlier pre-persist update row.
	 */
	public function record_customer_updated( int $customer_id ): void {
		$this->record( 'customer', $customer_id, 'update', 'hook', false );
	}

	/**
	 * profile_update handler — every WordPress user is a POS customer (#1379,
	 * 1.9 parity), so every profile change is an update. Keep the second argument
	 * because the WordPress hook passes two arguments.
	 */
	public function record_customer_profile_update( int $user_id, $old_user_data = null ): void {
		$this->record( 'customer', $user_id, 'update' );
	}

	/**
	 * set_user_role handler — every role replacement changes the served user
	 * record, but never removes the user from the POS customer space.
	 */
	public function record_customer_role_change( int $user_id, $role = '', $old_roles = array() ): void {
		$this->record( 'customer', $user_id, 'update' );
	}

	/**
	 * add_user_role handler — any added role changes the served customer record.
	 */
	public function record_customer_role_added( int $user_id, $role = '' ): void {
		$this->record( 'customer', $user_id, 'update' );
	}

	/**
	 * remove_user_role handler — any removed role changes the served customer
	 * record; role departure is never a customer tombstone.
	 */
	public function record_customer_role_removed( int $user_id, $role = '' ): void {
		$this->record( 'customer', $user_id, 'update' );
	}

	/** delete_user handler — deletion removes any user from the POS customer space. */
	public function record_customer_deleted( int $customer_id ): void {
		$this->record( 'customer', $customer_id, 'delete' );
	}

	public function record( string $object_type, int $object_id, string $change_type, string $origin = 'hook', bool $dedup = true ): void {
		// Dedup CUSTOMER rows only: one customer role change fans out across
		// overlapping WP-core hooks (set_role fires remove/add_user_role AND
		// set_user_role). Products/variations/tax keep their per-hook-fire semantics.
		//
		// Post-persist customer hooks pass $dedup=false and use a namespaced key
		// with LAST-WRITE-WINS replacement: a later post-persist row in the same
		// request carries NEWER state than the earlier one (checkout populates
		// billing between woocommerce_created_customer and woocommerce_new_customer;
		// a second save() sits between two woocommerce_update_customer fires), so
		// suppressing it could strand a client that pulled between the two on the
		// incomplete state forever. Instead the earlier same-request row is DELETED
		// and the new state lands at a fresh head sequence — one row per request,
		// and the definitive post-save state is always (re-)announced past any
		// cursor that consumed the earlier row.
		global $wpdb;
		$dedup_key = null;
		if ( 'customer' === $object_type ) {
			$dedup_key = ( $dedup ? '' : 'persisted:' ) . $object_id . ':' . $change_type;
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
				'change_type' => $change_type,
				'modified_gmt' => $now,
				'origin' => $origin,
				'created_gmt' => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( null !== $dedup_key ) {
			// Pre-persist keys only need existence; persisted keys remember their
			// row's sequence so a later same-request duplicate can replace it.
			$this->recorded_this_request[ $dedup_key ] = $dedup ? true : (int) $wpdb->insert_id;
		}
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
	}

	/**
	 * Head of the global sequence space — the change stream's current end.
	 *
	 * The sequence is ONE monotonic AUTO_INCREMENT space across every
	 * object_type, so MAX over the whole table is a valid head (and a valid
	 * baseline bound) for every scope. A FRESH client jumps its cursor straight
	 * here in one request instead of draining the historical log a page at a
	 * time — see finding F1 in docs/pos-replication-model.md.
	 */
	public function head_sequence(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $this->table_name() );
	}

	/**
	 * Oldest sequence that remains available to incremental-sync clients.
	 */
	public function oldest_sequence(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT MIN(sequence) FROM ' . $this->table_name() );
	}

	/**
	 * Delete one batch of superseded rows through the supplied sequence.
	 *
	 * @param int    $cutoff_sequence Inclusive upper sequence bound.
	 * @param string $cutoff_gmt      UTC datetime; only rows created before it are compacted.
	 * @param int    $batch           Maximum rows to delete.
	 *
	 * @return int Number of rows deleted.
	 */
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

	/**
	 * Delete one batch of expired tombstones — the log's only LOSSY deletion.
	 *
	 * Selects the batch first (sequence AND wall-clock checked per row — sequence
	 * order is not guaranteed to match timestamp order under concurrent writers),
	 * advances the prune watermark, then deletes by explicit sequence list. The
	 * destructive write is skipped unless the watermark is verified as persisted.
	 *
	 * @param int    $cutoff_sequence Inclusive upper sequence bound.
	 * @param string $cutoff_gmt      UTC datetime; only rows created before it are pruned.
	 * @param int    $batch           Maximum rows to delete.
	 *
	 * @return array{deleted: int, watermark: int} Rows deleted and the highest pruned sequence (0 when none).
	 */
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
					. " WHERE change_type = 'delete' AND sequence <= %d AND created_gmt < %s"
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

	/**
	 * Resolve a wall-clock cutoff to one stable sequence boundary.
	 *
	 * @param string $cutoff_gmt UTC cutoff datetime.
	 */
	public function sequence_at_or_before( string $cutoff_gmt ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(sequence) FROM ' . $this->table_name() . ' WHERE created_gmt < %s',
				$cutoff_gmt
			)
		);
	}

	/**
	 * Highest sequence ever removed by lossy tombstone pruning.
	 *
	 * The client-facing completeness boundary: a cursor at or past this value
	 * has missed nothing (compaction is lossless); a cursor below it may have
	 * missed pruned tombstones and must reconcile via the integrity surfaces.
	 * Zero means no lossy pruning has ever run.
	 */
	public function prune_watermark(): int {
		return (int) get_option( self::PRUNE_WATERMARK_OPTION, 0 );
	}

	/**
	 * Advance the persisted prune watermark (never moves backwards).
	 *
	 * @param int $sequence Highest sequence approved for lossy pruning.
	 */
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

	/**
	 * One page of the change stream past a cursor, plus the head it was read against.
	 *
	 * The ONLY read the change log exposes: callers name object TYPES, a cursor
	 * and a page size — never a table, a column or a WHERE clause. An EMPTY
	 * `$object_types` is the unified stream (every collection in one page,
	 * ordered by the single global sequence); one or more types narrow it (the
	 * products stream spans `product` AND `variation`, which is why the argument
	 * is a list). `WHERE sequence > N` is a primary-key range scan either way —
	 * the fastest query available.
	 *
	 * `head` is read AFTER the page so it can never be behind a served row: a
	 * row landing between the two reads must not produce an envelope whose head
	 * trails its own changes.
	 *
	 * @param string[] $object_types Change-log object types to include; empty = all.
	 * @param int      $since        Exclusive sequence cursor.
	 * @param int      $limit        Maximum rows to return.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, head: int}
	 */
	public function page( array $object_types, int $since, int $limit ): array {
		global $wpdb;
		$types = array();
		foreach ( $object_types as $object_type ) {
			$object_type = (string) $object_type;
			if ( '' !== $object_type ) {
				$types[] = $object_type;
			}
		}

		$sql  = 'SELECT sequence, object_id, object_type, change_type, modified_gmt FROM ' . $this->table_name() . ' WHERE ';
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
			'head' => $this->head_sequence(),
		);
	}

	/**
	 * Single-type page + that type's own max sequence.
	 *
	 * @deprecated Use {@see page()}, which spans types and reports the GLOBAL head.
	 */
	public function changes_since( string $object_type, int $since_sequence, int $limit ): array {
		global $wpdb;
		$rows = array_map(
			static function ( array $row ): array {
				unset( $row['object_type'] );

				return $row;
			},
			$this->page( array( $object_type ), $since_sequence, $limit )['rows']
		);
		$max_sequence = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(sequence) FROM ' . $this->table_name() . ' WHERE object_type = %s',
				$object_type
			)
		);

		return array(
			'rows' => $rows,
			'max_sequence' => $max_sequence,
		);
	}

	/**
	 * Coerce one raw change-log row to the served shape (ids as ints, everything
	 * else as strings) so no consumer has to re-type the DB's string columns.
	 */
	private static function normalize_row( array $row ): array {
		return array(
			'sequence' => isset( $row['sequence'] ) ? (int) $row['sequence'] : 0,
			'object_id' => isset( $row['object_id'] ) ? (int) $row['object_id'] : 0,
			'object_type' => isset( $row['object_type'] ) ? (string) $row['object_type'] : '',
			'change_type' => isset( $row['change_type'] ) ? (string) $row['change_type'] : '',
			'modified_gmt' => isset( $row['modified_gmt'] ) ? (string) $row['modified_gmt'] : gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
