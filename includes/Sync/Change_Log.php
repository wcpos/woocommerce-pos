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
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );
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
		// Customers are WP users. Mirror WooCommerce's own webhook topic hooks
		// (WC_Webhook::get_default_topic_hooks): create = user_register +
		// woocommerce_created_customer; update = profile_update +
		// woocommerce_update_customer; delete = delete_user. Each is filtered to
		// customer-role users (record_customer_*), so admin/editor user edits — which
		// fire the same WP-core hooks — never pollute the customer change-log.
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
		// Role transitions are detected two ways because WordPress splits them:
		// profile_update (wp_update_user) carries the OLD user data (arg 2), while
		// WP_User::set_role() / bulk role changes fire set_user_role (with the old
		// roles) and do NOT fire profile_update. Both feed one transition helper;
		// duplicate rows when a path fires both are harmless (one re-pull).
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
		if ( $this->is_customer( $customer_id ) ) {
			$this->record( 'customer', $customer_id, 'create' );
		}
	}

	/** WooCommerce create hooks — definitive post-persist create with persisted dedup. */
	public function record_customer_created_persisted( int $customer_id ): void {
		if ( $this->is_customer( $customer_id ) ) {
			$this->record( 'customer', $customer_id, 'create', 'hook', false );
		}
	}

	/**
	 * woocommerce_update_customer — the definitive post-persist update (fires AFTER
	 * the customer meta is written). Bypasses dedup so it always lands as the repair
	 * row for any read of the earlier, pre-persist profile_update row.
	 */
	public function record_customer_updated( int $customer_id ): void {
		if ( $this->is_customer( $customer_id ) ) {
			$this->record( 'customer', $customer_id, 'update', 'hook', false );
		}
	}

	/**
	 * profile_update handler — role-transition aware. WordPress has already stored
	 * the NEW role by the time this fires, so a plain current-role check would MISS
	 * a customer who just lost the `customer` role: they vanish from wc/v3/customers
	 * (which filters by role) yet the local doc would never be tombstoned. Compare
	 * the old role (arg 2, the pre-update WP_User) with the new:
	 *   - was + is customer      → update
	 *   - was NOT, is customer   → create (joined the customer collection)
	 *   - was customer, is NOT   → delete (left it — tombstone the stale local doc)
	 *   - neither                → ignore
	 */
	public function record_customer_profile_update( int $user_id, $old_user_data = null ): void {
		$was_customer = is_object( $old_user_data )
			&& isset( $old_user_data->roles )
			&& in_array( 'customer', (array) $old_user_data->roles, true );
		$this->record_customer_role_transition( $user_id, $was_customer, $this->is_customer( $user_id ) );
	}

	/**
	 * set_user_role handler — fires from WP_User::set_role() (bulk role changes,
	 * programmatic role sets) which do NOT fire profile_update. set_role REPLACES
	 * all roles with the single new $role, so the new customer-membership is exactly
	 * `$role === 'customer'`; $old_roles is the pre-change role array.
	 */
	public function record_customer_role_change( int $user_id, $role = '', $old_roles = array() ): void {
		$was_customer = is_array( $old_roles ) && in_array( 'customer', $old_roles, true );
		$this->record_customer_role_transition( $user_id, $was_customer, 'customer' === $role );
	}

	/**
	 * add_user_role handler — the customer role was ADDED to a (possibly multi-role)
	 * user. add_role fires ONLY when the role is genuinely new, so this is a join.
	 */
	public function record_customer_role_added( int $user_id, $role = '' ): void {
		if ( 'customer' === $role && $this->is_customer( $user_id ) ) {
			$this->record( 'customer', $user_id, 'create' );
		}
	}

	/**
	 * remove_user_role handler — the customer role was REMOVED. It's a genuine leave
	 * only if the user no longer holds the customer role (they had a single 'customer'
	 * role, or every customer grant is now gone) — so they've left wc/v3/customers.
	 */
	public function record_customer_role_removed( int $user_id, $role = '' ): void {
		if ( 'customer' === $role && ! $this->is_customer( $user_id ) ) {
			$this->record( 'customer', $user_id, 'delete' );
		}
	}

	/**
	 * One transition rule for every role-change path: was + is → update; joined
	 * (create); left → delete (tombstone the local doc — a former customer is gone
	 * from the role-filtered wc/v3/customers collection); neither → ignore.
	 */
	private function record_customer_role_transition( int $user_id, bool $was_customer, bool $is_customer ): void {
		if ( $is_customer ) {
			$this->record( 'customer', $user_id, $was_customer ? 'update' : 'create' );
		} elseif ( $was_customer ) {
			$this->record( 'customer', $user_id, 'delete' );
		}
	}

	public function record_customer_deleted( int $customer_id ): void {
		// delete_user fires BEFORE the row is removed, so the role is still readable.
		if ( $this->is_customer( $customer_id ) ) {
			$this->record( 'customer', $customer_id, 'delete' );
		}
	}

	/**
	 * Only WP users with the `customer` role belong in the customer change-log —
	 * the WP-core hooks (user_register / profile_update / delete_user) fire for
	 * EVERY user (admins, editors), so without this filter an admin profile edit
	 * would emit a phantom "customer" change the client would try to pull from
	 * wc/v3/customers (a 404 / wrong record).
	 */
	private function is_customer( int $user_id ): bool {
		return Customer_Role::is_customer( $user_id );
	}

	public function record( string $object_type, int $object_id, string $change_type, string $origin = 'hook', bool $dedup = true ): void {
		// Dedup CUSTOMER rows only: one customer role change fans out across
		// overlapping WP-core hooks (set_role fires remove/add_user_role AND
		// set_user_role). Products/variations/tax keep their per-hook-fire semantics.
		// Post-persist customer hooks pass $dedup=false to use a namespaced key:
		// their definitive row survives the earlier pre-persist row while identical
		// persisted hooks collapse.
		if ( 'customer' === $object_type ) {
			$dedup_key = ( $dedup ? '' : 'persisted:' ) . $object_id . ':' . $change_type;
			if ( isset( $this->recorded_this_request[ $dedup_key ] ) ) {
				return; // this exact customer change was already recorded this request
			}
			$this->recorded_this_request[ $dedup_key ] = true;
		}
		global $wpdb;
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
		self::$request_write_ms += ( microtime( true ) - $started ) * 1000;
	}

	public function changes_since( string $object_type, int $since_sequence, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sequence, object_id, change_type, modified_gmt FROM ' . $this->table_name() . ' WHERE object_type = %s AND sequence > %d ORDER BY sequence ASC LIMIT %d',
				$object_type,
				max( 0, $since_sequence ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$max_sequence = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(sequence) FROM ' . $this->table_name() . ' WHERE object_type = %s',
				$object_type
			)
		);

		return array(
			'rows' => array_map(
				static function ( array $row ): array {
					return array(
						'sequence' => isset( $row['sequence'] ) ? (int) $row['sequence'] : 0,
						'object_id' => isset( $row['object_id'] ) ? (int) $row['object_id'] : 0,
						'change_type' => isset( $row['change_type'] ) ? (string) $row['change_type'] : '',
						'modified_gmt' => isset( $row['modified_gmt'] ) ? (string) $row['modified_gmt'] : gmdate( 'Y-m-d H:i:s' ),
					);
				},
				$rows
			),
			'max_sequence' => $max_sequence,
		);
	}
}
