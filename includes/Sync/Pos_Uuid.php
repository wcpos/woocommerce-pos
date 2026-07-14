<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Exception;
use WC_Customer;
use WCPOS\WooCommercePOS\Logger;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries are prepared before execution.

/**
 * Uniform record identity on the server side — the sole authority that stamps,
 * validates, deduplicates, and checks ownership of `_woocommerce_pos_uuid`.
 * Legacy API callers delegate here (ADR 0021, decision c).
 *
 * The client uses this uuid as the stable RxDB primary key (ADR 0008, guardrail
 * G1): a record carries the SAME identity on the server and the client, may be
 * born on either side, and is NEVER re-keyed. This is the server half of that
 * contract — a record pulled from the lab namespace arrives WITH its uuid, so the
 * client never has to mint a divergent one for a server-born record.
 *
 * Reads an existing valid uuid from the record's meta; if absent/invalid it
 * generates one and PERSISTS it (so it is stable across pulls), then mirrors it
 * into the serialized payload's `meta_data`. Duck-typed on the WC_Data methods so
 * it stays unit-testable without WooCommerce loaded. UUID convergence does not
 * use a second object-cache lock; sync writes are serialized by their record
 * lock, while stamping deterministically converges duplicate meta rows.
 */
class Pos_Uuid {
	public const META_KEY = Api::UUID_META_KEY;
	/** Meta key carrying the freshly-recomputed variable-product price range (P2-2). */

	/**
	 * A standard 8-4-4-4-12 uuid shape (any version), case-insensitive.
	 */
	public static function is_uuid( $value ): bool {
		return \is_string( $value )
			 && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * The first VALID uuid among a record's meta entries (WC_Meta_Data objects
	 * with ->key/->value, or arrays), or '' if none. Skips blank / invalid / a
	 * blank duplicate in favour of a later valid one.
	 */
	public static function read_valid_uuid_from_meta( array $meta_data ): string {
		foreach ( $meta_data as $meta ) {
			$key = \is_object( $meta ) ? ( $meta->key ?? null ) : ( \is_array( $meta ) ? ( $meta['key'] ?? null ) : null );
			if ( self::META_KEY !== $key ) {
				continue;
			}
			$value = \is_object( $meta ) ? ( $meta->value ?? null ) : ( \is_array( $meta ) ? ( $meta['value'] ?? null ) : null );
			if ( self::is_uuid( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Ensure the record carries a stable, UNIQUELY-OWNED uuid: reuse a valid
	 * existing one, else generate + persist a new one. Returns the uuid (or '' if
	 * the object can't carry meta). Duck-typed on get_meta_data / update_meta_data
	 * / save_meta_data.
	 *
	 * $opts['collides'] is an optional callable (uuid, object) => bool: when it
	 * reports the existing uuid is already owned by ANOTHER record (a clone/import
	 * that copied the meta), we treat it as needing a fresh one rather than serving
	 * a duplicate RxDB key. Injected so the branching stays unit-testable; the live
	 * wiring uses the $wpdb-backed self::uuid_owned_by_other.
	 *
	 * @param mixed $object
	 */
	public static function ensure_uuid( $object, array $opts = array() ): string {
		if ( ! \is_object( $object ) || ! method_exists( $object, 'get_meta_data' ) ) {
			return '';
		}
		$collides = $opts['collides'] ?? null;
		$persist  = $opts['persist'] ?? true;
		$existing = self::read_valid_uuid_from_meta( (array) $object->get_meta_data() );
		if ( '' !== $existing && ! ( \is_callable( $collides ) && $collides( $existing, $object ) ) ) {
			// Converge any duplicate uuid metas (e.g. a concurrent first-stamp) to
			// the single canonical value — deterministic regardless of object-cache
			// backend, so no cross-request lock is required for correctness.
			self::prune_duplicate_uuid_meta( $object, $persist );

			return $existing;
		}
		if ( ! method_exists( $object, 'update_meta_data' ) ) {
			return '';
		}
		// Minting persists by default: a freshly-generated uuid that isn't written
		// back would differ on the next pull, making identity unstable — worse than
		// none. persist:false is for a BEFORE-save hook, where the in-progress save
		// writes the meta, so we add it but skip a redundant second save.
		if ( $persist && ! method_exists( $object, 'save_meta_data' ) ) {
			return '';
		}
		$uuid = self::generate_uuid();
		$object->update_meta_data( self::META_KEY, $uuid );
		if ( $persist ) {
			call_user_func( array( $object, 'save_meta_data' ) );
		}

		return $uuid;
	}

	/**
	 * Legacy WP_User adapter for the shared WC_Data identity path (ADR 0021).
	 *
	 * @param mixed $user WP_User-like object or numeric user id.
	 */
	public static function ensure_user_uuid( $user ): string {
		$user_id = \is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) $user;
		if ( $user_id <= 0 || ! class_exists( WC_Customer::class ) ) {
			return '';
		}

		try {
			$customer = new WC_Customer( $user_id );
		} catch ( Exception $e ) {
			Logger::log( 'Unable to load customer for UUID stamping: ' . $e->getMessage() );
			return '';
		}

		if ( ! method_exists( $customer, 'get_id' ) || $user_id !== (int) $customer->get_id() ) {
			return '';
		}

		return self::ensure_uuid(
			$customer,
			array( 'collides' => array( __CLASS__, 'uuid_owned_by_other_user' ) )
		);
	}

	/**
	 * Legacy WP_Term adapter for the shared identity path (ADR 0021).
	 *
	 * @param mixed $term WP_Term-like object or numeric term id.
	 */
	public static function ensure_term_uuid( $term ): string {
		$term_id = \is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;
		if ( $term_id <= 0 ) {
			return '';
		}

		$adapter = new Term_Meta_Adapter( $term_id );

		return self::ensure_uuid(
			$adapter,
			array( 'collides' => array( __CLASS__, 'uuid_owned_by_other_term' ) )
		);
	}

	/**
	 * Return a copy of the SERIALIZED payload whose `meta_data` mirrors `$uuid`
	 * exactly once (entries here are arrays: ['id'=>,'key'=>,'value'=>]). Drops
	 * blank / duplicate / mismatched `_woocommerce_pos_uuid` entries so the served
	 * record always carries one canonical identity.
	 */
	public static function ensure_in_payload( array $payload, string $uuid ): array {
		$meta   = ( isset( $payload['meta_data'] ) && \is_array( $payload['meta_data'] ) ) ? $payload['meta_data'] : array();
		$others = array();
		foreach ( $meta as $entry ) {
			$key = \is_array( $entry ) ? ( $entry['key'] ?? null ) : ( \is_object( $entry ) ? ( $entry->key ?? null ) : null );
			if ( self::META_KEY !== $key ) {
				$others[] = $entry;
			}
		}
		$others[]             = array(
			'key' => self::META_KEY,
			'value' => $uuid,
		);
		$payload['meta_data'] = array_values( $others );

		return $payload;
	}

	/**
	 * Hook for the per-collection `woocommerce_pos_sync_serialized_*` filters (product,
	 * order, …): stamp the served record's stable uuid (persisting a new one if
	 * needed) and mirror it into the payload, so EVERY read carries the identity the
	 * client keys on — regardless of how the record was born. A non-array payload or
	 * an object that can't carry meta passes through unchanged.
	 *
	 * Collection-agnostic: `ensure_uuid` only needs the WC_Data meta API
	 * (get/update/save_meta_data), which orders (HPOS-safe), customers, and terms all
	 * provide. Collision detection IS storage-specific: orders live in HPOS tables
	 * (not `wp_postmeta`), so they get the order-aware detector; products/variations
	 * keep the post-scoped one. Customers and terms use their storage-specific
	 * adapters and detectors.
	 *
	 * @param mixed      $payload
	 * @param mixed      $object
	 * @param null|mixed $request
	 */
	public static function stamp_serialized_record( $payload, $object, $request = null ) {
		if ( ! \is_array( $payload ) ) {
			return $payload;
		}
		$collides = is_a( $object, 'WC_Abstract_Order' )
			? array( __CLASS__, 'uuid_owned_by_other_order' )
			: array( __CLASS__, 'uuid_owned_by_other' );
		$uuid = self::ensure_uuid( $object, array( 'collides' => $collides ) );

		return '' === $uuid ? $payload : self::ensure_in_payload( $payload, $uuid );
	}

	/**
	 * Order-aware variant of {@see uuid_owned_by_other}. HPOS order meta does NOT live
	 * in `wp_postmeta`, so the post-scoped detector can't see an order that already owns
	 * `$uuid` — a duplicated/imported order with a copied uuid would slip through and two
	 * orders would share one RxDB key. Query the orders store (HPOS-safe via
	 * `wc_get_orders`) for the uuid; a match on a DIFFERENT order id is a real collision.
	 *
	 * @param mixed $uuid
	 * @param mixed $object
	 */
	public static function uuid_owned_by_other_order( $uuid, $object ): bool {
		if ( ! \is_object( $object ) || ! method_exists( $object, 'get_id' ) || ! \function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$order_id = (int) $object->get_id();
		foreach ( self::get_order_ids_by_uuid( (string) $uuid ) as $other_id ) {
			if ( (int) $other_id !== $order_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return at most two order ids carrying a UUID. The legacy create controller
	 * treats two results as an ambiguous identity and fails closed.
	 *
	 * Datastore-aware direct meta lookup: under HPOS the uuid lives in
	 * `wc_orders_meta`, otherwise in `wp_postmeta`. `wc_get_orders()` with a
	 * `meta_query` is NOT supported on the CPT order datastore (it fires a
	 * `doing_it_wrong` and returns unfiltered results), so we query the meta table
	 * directly — the same shape the plugin's other order-uuid lookups use.
	 */
	public static function get_order_ids_by_uuid( string $uuid ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		$hpos       = class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) );

		if ( $hpos ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta"
					. ' WHERE meta_key = %s AND meta_value = %s'
					. ' ORDER BY order_id ASC LIMIT 2',
					self::META_KEY,
					$uuid
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta}"
					. ' WHERE meta_key = %s AND meta_value = %s'
					. ' ORDER BY post_id ASC LIMIT 2',
					self::META_KEY,
					$uuid
				)
			);
		}

		return \is_array( $ids ) ? array_values( $ids ) : array();
	}

	/**
	 * Register WRITE-time stamping: a record gets its uuid the moment it is saved,
	 * so every read path (catalog proxy, change-signal hydration) then serves it
	 * straight from postmeta with no per-path stamping copy. Hooked BEFORE the data
	 * store writes, so the uuid lands in the SAME save — no second write, no
	 * change-log cascade, and no concurrent-first-READ race (stamping is per-save,
	 * not per-reader). The read-time filter remains as a fallback for records that
	 * predate these hooks until the backfill runs.
	 */
	public static function register_hooks(): void {
		if ( ! \function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'stamp_on_save' ), 10, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( __CLASS__, 'stamp_on_save' ), 10, 1 );
	}

	/**
	 * Before-save hook: ensure the WC object carries a unique uuid as part of the
	 * in-progress save (persist:false — the save itself writes it).
	 *
	 * @param mixed $object
	 */
	public static function stamp_on_save( $object ): void {
		self::ensure_uuid(
			$object,
			array(
				'collides' => array( __CLASS__, 'uuid_owned_by_other' ),
				'persist'  => false,
			)
		);
	}

	/**
	 * True when $uuid is already stored as `_woocommerce_pos_uuid` on a DIFFERENT
	 * post (a cloned/imported record that copied the meta). Post-scoped (products
	 * + variations); terms/customers live in their own meta tables and get their
	 * own detector when those seams land. Returns false when $wpdb or the object's
	 * id is unavailable (e.g. unit tests inject a fake detector instead).
	 *
	 * @param mixed $uuid
	 * @param mixed $object
	 */
	public static function uuid_owned_by_other( $uuid, $object ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! \is_object( $object ) || ! method_exists( $object, 'get_id' ) ) {
			return false;
		}
		// Only an ACTIVE post counts as a live owner — a trashed/auto-draft record
		// sharing the uuid is not a real collision (it will never be served), so it
		// must not force a needless regeneration on the active record.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = %s AND m.meta_value = %s AND m.post_id <> %d
               AND p.post_status NOT IN ('trash','auto-draft')",
			self::META_KEY,
			$uuid,
			(int) $object->get_id()
		);

		return (int) $wpdb->get_var( $sql ) > 0;
	}

	/**
	 * User-table twin of uuid_owned_by_other for CUSTOMERS (WC_Customer over
	 * wp_usermeta). DELIBERATE asymmetry: WP users have no post_status / trash, so
	 * every user row carrying the uuid is a live owner — there is NO status
	 * exclusion (a status filter here would reference a non-existent column).
	 *
	 * @param mixed $uuid
	 * @param mixed $object
	 */
	public static function uuid_owned_by_other_user( $uuid, $object ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! \is_object( $object ) || ! method_exists( $object, 'get_id' ) ) {
			return false;
		}
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} m
             JOIN {$wpdb->users} u ON u.ID = m.user_id
             WHERE m.meta_key = %s AND m.meta_value = %s AND m.user_id <> %d",
			self::META_KEY,
			$uuid,
			(int) $object->get_id()
		);

		return (int) $wpdb->get_var( $sql ) > 0;
	}

	/**
	 * Term-table twin for CATEGORIES + BRANDS. CROSS-TAXONOMY by design: product_cat
	 * and product_brand SHARE wp_termmeta, so a uuid on a different term in EITHER
	 * taxonomy is a real RxDB-primary-key clash — match on term_id across ALL
	 * taxonomies (NO taxonomy scoping). Terms have no trash/status, so no status
	 * exclusion (like users, unlike posts).
	 *
	 * @param mixed $uuid
	 * @param mixed $object
	 */
	public static function uuid_owned_by_other_term( $uuid, $object ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! \is_object( $object ) || ! method_exists( $object, 'get_id' ) ) {
			return false;
		}
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s AND term_id <> %d",
			self::META_KEY,
			$uuid,
			(int) $object->get_id()
		);

		return (int) $wpdb->get_var( $sql ) > 0;
	}

	/**
	 * A v4 uuid — `wp_generate_uuid4()` under WordPress, else a local fallback.
	 */
	public static function generate_uuid(): string {
		if ( \function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$data    = random_bytes( 16 );
		$data[6] = \chr( ( \ord( $data[6] ) & 0x0f ) | 0x40 ); // version 4
		$data[8] = \chr( ( \ord( $data[8] ) & 0x3f ) | 0x80 ); // variant 10xx

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Collapse duplicate `_woocommerce_pos_uuid` metas to ONE canonical entry —
	 * keep the first valid uuid, delete every other uuid meta (a concurrent-stamp
	 * duplicate, or a blank/invalid straggler). Only persisted metas (with a meta
	 * id) are deleted; a freshly-added unsaved one is left for the in-progress save.
	 * When $persist, the cleanup is saved immediately; otherwise the ongoing save
	 * (before-save hook) applies the deletions. Mirrors production's dedup, and is
	 * what makes the concurrent-first-stamp outcome correct without a lock.
	 *
	 * @param mixed $object
	 */
	private static function prune_duplicate_uuid_meta( $object, bool $persist ): void {
		if ( ! \is_object( $object ) || ! method_exists( $object, 'get_meta_data' ) || ! method_exists( $object, 'delete_meta_data_by_mid' ) ) {
			return;
		}
		$kept_valid = false;
		$deleted    = false;
		foreach ( (array) $object->get_meta_data() as $meta ) {
			if ( ! \is_object( $meta ) || self::META_KEY !== ( $meta->key ?? null ) ) {
				continue;
			}
			if ( ! $kept_valid && self::is_uuid( $meta->value ?? null ) ) {
				$kept_valid = true; // keep the first valid uuid meta

				continue;
			}
			$mid = $meta->id ?? null;
			if ( null !== $mid ) {
				$object->delete_meta_data_by_mid( $mid );
				$deleted = true;
			}
		}
		if ( $deleted && $persist && method_exists( $object, 'save_meta_data' ) ) {
			$object->save_meta_data();
		}
	}
}
