<?php
/**
 * UUID backfill and collision repair endpoint.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use WC_Coupon;
use WC_Customer;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Term_Meta_Adapter;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use internal table names and generated SQL fragments.

/**
 * Paginated, idempotent UUID backfill and collision repair.
 *
 * A collection's entity kind selects its meta store, object loader, and
 * collision detector. Calls advance by numeric ID until `complete` is true.
 */
final class Uuid_Backfill_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	/** Well-formed UUID SQL pattern; no request input is interpolated. */
	private const UUID_SQL_REGEXP = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'uuid/backfill',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'backfill' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'collection' => array(
						'default'           => 'products',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'      => array(
						'default'           => 100,
						'sanitize_callback' => 'absint',
					),
					'since_id'   => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'mode'       => array(
						'default'           => 'missing',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/** Return the meta-store kind for a collection, or an empty string. */
	public function entity_kind( string $collection ): string {
		$row = Collections::row( $collection );

		return isset( $row['backfill'] ) ? $row['backfill']['kind'] : '';
	}

	/** Return the post types scanned by a post-backed collection. */
	public function post_types_for( string $collection ): array {
		$row = Collections::row( $collection );

		return $row['backfill']['scan_post_types'] ?? array();
	}

	/** Return the taxonomy scanned by a term-backed collection. */
	public function taxonomy_for( string $collection ): string {
		$row = Collections::row( $collection );

		return $row['backfill']['taxonomy'] ?? '';
	}

	/** Normalize unknown modes to the idempotent missing scan. */
	public function normalize_mode( string $mode ): string {
		return 'collisions' === $mode ? 'collisions' : 'missing';
	}

	/**
	 * Stamp one page of missing or colliding UUIDs.
	 *
	 * @return array<string, int|string|bool>
	 */
	public function backfill( WP_REST_Request $request ): array {
		$collection = (string) $request->get_param( 'collection' );
		$mode       = $this->normalize_mode( (string) $request->get_param( 'mode' ) );
		$limit      = max( 1, min( 500, (int) $request->get_param( 'limit' ) ) );
		$since_id   = max( 0, (int) $request->get_param( 'since_id' ) );
		$kind       = $this->entity_kind( $collection );

		if ( '' === $kind || ! isset( $GLOBALS['wpdb'] ) ) {
			return $this->response( $collection, $mode, 0, 0, 0, $since_id, true, false );
		}

		$ids      = $this->select_ids( $kind, $collection, $mode, $since_id, $limit );
		$detector = array( 'collides' => $this->collision_detector( $kind ) );
		$stamped  = 0;
		$skipped  = 0;
		$cursor   = $since_id;

		foreach ( $ids as $id ) {
			$cursor = $id;
			$entity = $this->load_entity( $kind, $id );
			if ( ! $entity ) {
				++$skipped;
				continue;
			}

			$before = Pos_Uuid::read_valid_uuid_from_meta( (array) $entity->get_meta_data() );
			$after  = Pos_Uuid::ensure_uuid( $entity, $detector );
			if ( '' !== $after && ( '' === $before || $before !== $after ) ) {
				++$stamped;
			} else {
				++$skipped;
			}
		}

		return $this->response(
			$collection,
			$mode,
			\count( $ids ),
			$stamped,
			$skipped,
			$cursor,
			\count( $ids ) < $limit,
			true
		);
	}

	/** Build the stable response contract shared by both modes. */
	private function response(
		string $collection,
		string $mode,
		int $scanned,
		int $stamped,
		int $skipped,
		int $next_since_id,
		bool $complete,
		bool $supported
	): array {
		return array(
			'collection'    => $collection,
			'mode'          => $mode,
			'scanned'       => $scanned,
			'stamped'       => $stamped,
			'skipped'       => $skipped,
			'next_since_id' => $next_since_id,
			'complete'      => $complete,
			'supported'     => $supported,
		);
	}

	/** Load a WC_Data-compatible entity for the selected kind and ID. */
	private function load_entity( string $kind, int $id ) {
		switch ( $kind ) {
			case 'post':
				if ( 'shop_coupon' === get_post_type( $id ) ) {
					$coupon = new WC_Coupon( $id );

					return $id === (int) $coupon->get_id() ? $coupon : null;
				}

				return wc_get_product( $id );
			case 'order':
				$order = wc_get_order( $id );

				return $order && $id === (int) $order->get_id() ? $order : null;
			case 'user':
				try {
					$customer = new WC_Customer( $id );
				} catch ( Exception $exception ) {
					return null;
				}

				return $id === (int) $customer->get_id() ? $customer : null;
			case 'term':
				return new Term_Meta_Adapter( $id );
			default:
				return null;
		}
	}

	/** Return the ownership detector for a meta store. */
	private function collision_detector( string $kind ): array {
		switch ( $kind ) {
			case 'order':
				return array( Pos_Uuid::class, 'uuid_owned_by_other_order' );
			case 'user':
				return array( Pos_Uuid::class, 'uuid_owned_by_other_user' );
			case 'term':
				return array( Pos_Uuid::class, 'uuid_owned_by_other_term' );
			case 'post':
			default:
				return array( Pos_Uuid::class, 'uuid_owned_by_other' );
		}
	}

	/** Dispatch candidate selection to the collection's native store. */
	private function select_ids( string $kind, string $collection, string $mode, int $since_id, int $limit ): array {
		switch ( $kind ) {
			case 'post':
				return $this->select_ids_post( $this->post_types_for( $collection ), $mode, $since_id, $limit );
			case 'order':
				return $this->select_ids_order( $mode, $since_id, $limit );
			case 'user':
				return $this->select_ids_user( $mode, $since_id, $limit );
			case 'term':
				return $this->select_ids_term( $this->taxonomy_for( $collection ), $mode, $since_id, $limit );
			default:
				return array();
		}
	}

	/** Select post-backed records with missing or duplicated valid UUID meta. */
	private function select_ids_post( array $post_types, string $mode, int $since_id, int $limit ): array {
		global $wpdb;
		if ( array() === $post_types ) {
			return array();
		}

		$type_slots = implode( ',', array_fill( 0, \count( $post_types ), '%s' ) );
		$regexp     = self::UUID_SQL_REGEXP;
		if ( 'collisions' === $mode ) {
			$sql = $wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m
				 JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 JOIN (
				     SELECT m2.meta_value, MIN(m2.post_id) AS keep_id
				     FROM {$wpdb->postmeta} m2
				     JOIN {$wpdb->posts} p2 ON p2.ID = m2.post_id
				     WHERE m2.meta_key = %s AND m2.meta_value REGEXP '$regexp'
				       AND p2.post_status NOT IN ('trash','auto-draft')
				     GROUP BY m2.meta_value HAVING COUNT(*) > 1
				 ) dups ON dups.meta_value = m.meta_value
				 WHERE m.meta_key = %s AND m.post_id <> dups.keep_id
				   AND p.post_type IN ($type_slots)
				   AND p.post_status NOT IN ('trash','auto-draft')
				   AND m.post_id > %d ORDER BY m.post_id ASC LIMIT %d",
				array_merge( array( Api::UUID_META_KEY, Api::UUID_META_KEY ), $post_types, array( $since_id, $limit ) )
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m
				   ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value REGEXP '$regexp'
				 WHERE p.post_type IN ($type_slots)
				   AND p.post_status NOT IN ('trash','auto-draft')
				   AND p.ID > %d AND m.meta_id IS NULL
				 ORDER BY p.ID ASC LIMIT %d",
				array_merge( array( Api::UUID_META_KEY ), $post_types, array( $since_id, $limit ) )
			);
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/** Select orders from the active HPOS or CPT datastore. */
	private function select_ids_order( string $mode, int $since_id, int $limit ): array {
		global $wpdb;
		$hpos   = class_exists( OrderUtil::class )
			&& method_exists( OrderUtil::class, 'custom_orders_table_usage_is_enabled' )
			&& OrderUtil::custom_orders_table_usage_is_enabled();
		$regexp = self::UUID_SQL_REGEXP;

		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			if ( 'collisions' === $mode ) {
				$sql = $wpdb->prepare(
					"SELECT m.order_id FROM {$meta} m
					 JOIN {$orders} o ON o.id = m.order_id AND o.type = 'shop_order'
					 JOIN (
					     SELECT m2.meta_value, MIN(m2.order_id) AS keep_id
					     FROM {$meta} m2
					     JOIN {$orders} o2 ON o2.id = m2.order_id AND o2.type = 'shop_order'
					     WHERE m2.meta_key = %s AND m2.meta_value REGEXP '$regexp'
					       AND o2.status NOT IN ('trash','auto-draft')
					     GROUP BY m2.meta_value HAVING COUNT(*) > 1
					 ) dups ON dups.meta_value = m.meta_value
					 WHERE m.meta_key = %s AND m.order_id <> dups.keep_id
					   AND o.status NOT IN ('trash','auto-draft')
					   AND m.order_id > %d ORDER BY m.order_id ASC LIMIT %d",
					Api::UUID_META_KEY,
					Api::UUID_META_KEY,
					$since_id,
					$limit
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT o.id FROM {$orders} o
					 LEFT JOIN {$meta} m
					   ON m.order_id = o.id AND m.meta_key = %s AND m.meta_value REGEXP '$regexp'
					 WHERE o.type = 'shop_order' AND o.status NOT IN ('trash','auto-draft')
					   AND o.id > %d AND m.id IS NULL ORDER BY o.id ASC LIMIT %d",
					Api::UUID_META_KEY,
					$since_id,
					$limit
				);
			}
		} elseif ( 'collisions' === $mode ) {
			$sql = $wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m
				 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_order'
				 JOIN (
				     SELECT m2.meta_value, MIN(m2.post_id) AS keep_id
				     FROM {$wpdb->postmeta} m2
				     JOIN {$wpdb->posts} p2 ON p2.ID = m2.post_id AND p2.post_type = 'shop_order'
				     WHERE m2.meta_key = %s AND m2.meta_value REGEXP '$regexp'
				       AND p2.post_status NOT IN ('trash','auto-draft')
				     GROUP BY m2.meta_value HAVING COUNT(*) > 1
				 ) dups ON dups.meta_value = m.meta_value
				 WHERE m.meta_key = %s AND m.post_id <> dups.keep_id
				   AND p.post_status NOT IN ('trash','auto-draft')
				   AND m.post_id > %d ORDER BY m.post_id ASC LIMIT %d",
				Api::UUID_META_KEY,
				Api::UUID_META_KEY,
				$since_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m
				   ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value REGEXP '$regexp'
				 WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash','auto-draft')
				   AND p.ID > %d AND m.meta_id IS NULL ORDER BY p.ID ASC LIMIT %d",
				Api::UUID_META_KEY,
				$since_id,
				$limit
			);
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/** Select all WordPress users with missing or duplicated valid UUID meta. */
	private function select_ids_user( string $mode, int $since_id, int $limit ): array {
		global $wpdb;
		$regexp = self::UUID_SQL_REGEXP;
		if ( 'collisions' === $mode ) {
			$sql = $wpdb->prepare(
				"SELECT m.user_id FROM {$wpdb->usermeta} m
				 JOIN {$wpdb->users} u ON u.ID = m.user_id
				 JOIN (
				     SELECT m2.meta_value, MIN(u2.ID) AS keep_id
				     FROM {$wpdb->users} u2
				     JOIN {$wpdb->usermeta} m2 ON m2.user_id = u2.ID
				     WHERE m2.meta_key = %s AND m2.meta_value REGEXP '$regexp'
				     GROUP BY m2.meta_value HAVING COUNT(*) > 1
				 ) dups ON dups.meta_value = m.meta_value
				 WHERE m.meta_key = %s AND m.user_id <> dups.keep_id
				   AND m.user_id > %d ORDER BY m.user_id ASC LIMIT %d",
				Api::UUID_META_KEY,
				Api::UUID_META_KEY,
				$since_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT u.ID FROM {$wpdb->users} u
				 LEFT JOIN {$wpdb->usermeta} m
				   ON m.user_id = u.ID AND m.meta_key = %s AND m.meta_value REGEXP '$regexp'
				 WHERE u.ID > %d AND m.umeta_id IS NULL ORDER BY u.ID ASC LIMIT %d",
				Api::UUID_META_KEY,
				$since_id,
				$limit
			);
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/** Select taxonomy terms with missing or cross-taxonomy duplicate UUIDs. */
	private function select_ids_term( string $taxonomy, string $mode, int $since_id, int $limit ): array {
		global $wpdb;
		if ( '' === $taxonomy ) {
			return array();
		}
		$regexp = self::UUID_SQL_REGEXP;
		if ( 'collisions' === $mode ) {
			$sql = $wpdb->prepare(
				"SELECT m.term_id FROM {$wpdb->termmeta} m
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.term_id AND tt.taxonomy = %s
				 JOIN (
				     SELECT m2.meta_value, MIN(m2.term_id) AS keep_id
				     FROM {$wpdb->termmeta} m2
				     WHERE m2.meta_key = %s AND m2.meta_value REGEXP '$regexp'
				     GROUP BY m2.meta_value HAVING COUNT(*) > 1
				 ) dups ON dups.meta_value = m.meta_value
				 WHERE m.meta_key = %s AND m.term_id <> dups.keep_id
				   AND m.term_id > %d ORDER BY m.term_id ASC LIMIT %d",
				$taxonomy,
				Api::UUID_META_KEY,
				Api::UUID_META_KEY,
				$since_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
				 LEFT JOIN {$wpdb->termmeta} m
				   ON m.term_id = t.term_id AND m.meta_key = %s AND m.meta_value REGEXP '$regexp'
				 WHERE t.term_id > %d AND m.meta_id IS NULL ORDER BY t.term_id ASC LIMIT %d",
				$taxonomy,
				Api::UUID_META_KEY,
				$since_id,
				$limit
			);
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}
}
