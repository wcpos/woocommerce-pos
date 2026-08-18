<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Exception;
use WC_Coupon;
use WC_Customer;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder lists are generated from sanitized integer IDs.

/**
 * Stamps stable record UUIDs onto catalog-proxy responses.
 */
final class Proxy_Uuid_Stamper {
	/**
	 * Registered proxy callbacks.
	 *
	 * @var array<int, callable>
	 */
	private static $proxy_stampers = array();

	/**
	 * Registry-driven stamper config (#421 increment 3): build the
	 * stamp_proxy_generic config for one collection FROM ITS REGISTRY ROW —
	 * the per-collection mapping (which resources, which bulk reader, which
	 * detector, which loader) collapses into Collections; the
	 * loader-KIND switch below is the irreducible remainder (five loader
	 * strategies, not nine collection cases). Returns null when the
	 * collection has no identity or no proxy (nothing to stamp).
	 */
	public static function proxy_stamper_config( string $collection ): ?array {
		$row = Collections::row( $collection );
		if ( ! isset( $row['identity'], $row['proxy'] ) ) {
			return null;
		}
		$identity = $row['identity'];

		return array(
			'resources' => array( $row['proxy']['slug'] ),
			'bulk_read' => null === $identity['bulk_reader'] ? null : array( self::class, $identity['bulk_reader'] ),
			'load'      => self::loader_for( $identity['loader'] ),
			'collides'  => array( Pos_Uuid::class, $identity['detector'] ),
		);
	}

	/**
	 * Register one uuid stamper per identity-and-proxy registry row on the
	 * catalog-proxy response filter. Replaces plugin.php's hand-maintained
	 * list — adding a collection means adding ONE registry row. Returns the
	 * registered collection names (the wiring golden pins them).
	 */
	public static function register_proxy_stampers(): array {
		$registered = array();
		foreach ( array_keys( Collections::with( 'identity' ) ) as $collection ) {
			$config = self::proxy_stamper_config( $collection );
			if ( null === $config ) {
				continue;
			}
			$callback = static function ( $data, $resource = '', $request = null ) use ( $config ) {
				return self::stamp_proxy_generic( $data, $resource, $config );
			};
			add_filter(
				'woocommerce_pos_sync_proxy_response',
				$callback,
				10,
				3
			);
			self::$proxy_stampers[] = $callback;
			$registered[] = $collection;
		}

		return $registered;
	}

	/**
	 * Remove every registered UUID proxy stamper.
	 */
	public static function unregister_proxy_stampers(): void {
		foreach ( self::$proxy_stampers as $callback ) {
			remove_filter( 'woocommerce_pos_sync_proxy_response', $callback, 10 );
		}
		self::$proxy_stampers = array();
	}

	/**
	 * Catalog-proxy `/products` stamper: existing uuids from ONE wp_postmeta bulk
	 * read; the unstamped load via wc_get_product; post-scoped collision detector.
	 * See {@see stamp_proxy_generic} for the shared loop.
	 *
	 * @param mixed      $data
	 * @param mixed      $resource
	 * @param null|mixed $request
	 */
	public static function stamp_proxy_products( $data, $resource = '', $request = null ) {
		return self::stamp_proxy_generic(
			$data,
			$resource,
			array(
				'resources' => array( 'products' ),
				'bulk_read' => array( __CLASS__, 'bulk_read_post_uuids' ),
				'load'      => static function ( int $id ) {
					$product = \function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;

					return $product ? $product : null;
				},
				'collides'  => array( Pos_Uuid::class, 'uuid_owned_by_other' ),
			)
		);
	}

	/**
	 * Catalog-proxy `/coupons` stamper — the post-kind twin of stamp_proxy_products:
	 * coupons are `shop_coupon` posts, so existing uuids bulk-read from wp_postmeta
	 * exactly like products, but the unstamped load via `new WC_Coupon`
	 * (wc_get_product returns false for a non-product post).
	 *
	 * @param mixed      $data
	 * @param mixed      $resource
	 * @param null|mixed $request
	 */
	public static function stamp_proxy_coupons( $data, $resource = '', $request = null ) {
		return self::stamp_proxy_generic(
			$data,
			$resource,
			array(
				'resources' => array( 'coupons' ),
				'bulk_read' => array( __CLASS__, 'bulk_read_post_uuids' ),
				'load'      => static function ( int $id ) {
					if ( ! class_exists( 'WC_Coupon' ) ) {
						return null;
					}
					$coupon = new WC_Coupon( $id );

					return $coupon->get_id() ? $coupon : null;
				},
				'collides'  => array( Pos_Uuid::class, 'uuid_owned_by_other' ),
			)
		);
	}

	/**
	 * Catalog-proxy `/customers` stamper: customers are WP USERS — existing uuids
	 * bulk-read from wp_usermeta, the unstamped load via WC_Customer (guarded: a
	 * WC_Customer for a missing id can construct empty or throw), and clones re-key
	 * through the user-scoped detector. This is the LOAD-BEARING read-time identity
	 * surface for customers — there is no per-id customer serialize path.
	 *
	 * @param mixed      $data
	 * @param mixed      $resource
	 * @param null|mixed $request
	 */
	public static function stamp_proxy_customers( $data, $resource = '', $request = null ) {
		return self::stamp_proxy_generic(
			$data,
			$resource,
			array(
				'resources' => array( 'customers' ),
				'bulk_read' => array( __CLASS__, 'bulk_read_user_uuids' ),
				'load'      => static function ( int $id ) {
					if ( ! class_exists( 'WC_Customer' ) ) {
						return null;
					}

					try {
						$customer = new WC_Customer( $id );
					} catch ( Exception $e ) {
						return null;
					}

					// Guard the round-trip: a WC_Customer for a missing id can construct empty.
					return ( method_exists( $customer, 'get_id' ) && (int) $customer->get_id() === $id ) ? $customer : null;
				},
				'collides'  => array( Pos_Uuid::class, 'uuid_owned_by_other_user' ),
			)
		);
	}

	/**
	 * Catalog-proxy term stamper: ONE row serves ALL term resources — categories +
	 * brands + tags share wp_termmeta. WP_Term is NOT WC_Data, so the unstamped mint
	 * via the TermMetaAdapter (ensure_uuid reused unchanged). The in-response re-key
	 * handles same-response clones; CROSS-taxonomy clones (a uuid on a category,
	 * brand, and/or tag, served in SEPARATE responses) converge via /uuid/backfill —
	 * and uuid_owned_by_other_term is cross-taxonomy so that converges right.
	 *
	 * @param mixed      $data
	 * @param mixed      $resource
	 * @param null|mixed $request
	 */
	public static function stamp_proxy_terms( $data, $resource = '', $request = null ) {
		return self::stamp_proxy_generic(
			$data,
			$resource,
			array(
				'resources' => array( 'categories', 'brands', 'tags' ),
				'bulk_read' => array( __CLASS__, 'bulk_read_term_uuids' ),
				'load'      => static function ( int $id ) {
					// The adapter presents the WC_Data meta contract over wp_termmeta.
					return new Term_Meta_Adapter( $id );
				},
				'collides'  => array( Pos_Uuid::class, 'uuid_owned_by_other_term' ),
			)
		);
	}

	/**
	 * Catalog-proxy `/orders` stamper — the PAYLOAD-mode row: orders aren't posts
	 * (HPOS), so there is no postmeta to bulk-read; existing uuids come from each
	 * served order's own meta_data (HPOS wc/v3 EXPOSES the protected meta), and a
	 * stamped-and-unique order passes through untouched. Orders are only read-stamped
	 * (serialize_order / this proxy), NOT at write time — so an order pulled FIRST
	 * via the browser/targeted proxy is minted+persisted here.
	 *
	 * @param mixed      $data
	 * @param mixed      $resource
	 * @param null|mixed $request
	 */
	public static function stamp_proxy_orders( $data, $resource = '', $request = null ) {
		return self::stamp_proxy_generic(
			$data,
			$resource,
			array(
				'resources' => array( 'orders' ),
				'bulk_read' => null, // payload mode — see stamp_proxy_generic
				'load'      => static function ( int $id ) {
					$order = \function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;

					return $order ? $order : null;
				},
				'collides'  => array( Pos_Uuid::class, 'uuid_owned_by_other_order' ),
			)
		);
	}

	/**
	 * Read `_woocommerce_pos_uuid` for many post ids in ONE query — returns
	 * post_id => uuid for the valid ones (malformed/blank skipped). Empty when $wpdb or
	 * the id set is unavailable (then callers fall back to per-object minting).
	 */
	public static function bulk_read_post_uuids( array $ids ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $ids ) ) {
			return array();
		}
		$ids          = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$placeholders = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		// ORDER BY meta_id ASC + first-valid-wins below: if a post carries DUPLICATE uuid
		// metas (a concurrent first-stamp before prune converges them), pick the SAME
		// canonical one read_valid_uuid_from_meta / prune_duplicate_uuid_meta keep — the
		// earliest valid entry — so the served identity is deterministic, not row-order luck.
		$sql = $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders) ORDER BY meta_id ASC",
			array_merge( array( Pos_Uuid::META_KEY ), $ids )
		);
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$pid   = (int) ( \is_array( $row ) ? ( $row['post_id'] ?? 0 ) : 0 );
			$value = \is_array( $row ) ? ( $row['meta_value'] ?? '' ) : '';
			if ( ! isset( $out[ $pid ] ) && Pos_Uuid::is_uuid( $value ) ) {
				$out[ $pid ] = $value; // keep the FIRST valid uuid per post (canonical)
			}
		}

		return $out;
	}

	/**
	 * User-table twin of bulk_read_post_uuids for CUSTOMERS — reads existing
	 * uuids from wp_usermeta in ONE query. umeta_id ASC + first-valid-wins keeps
	 * the SAME canonical entry read_valid_uuid_from_meta would, so a customer with
	 * duplicate uuid metas serves a deterministic identity, not row-order luck.
	 */
	public static function bulk_read_user_uuids( array $ids ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $ids ) ) {
			return array();
		}
		$ids          = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$placeholders = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ($placeholders) ORDER BY umeta_id ASC",
			array_merge( array( Pos_Uuid::META_KEY ), $ids )
		);
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$uid   = (int) ( \is_array( $row ) ? ( $row['user_id'] ?? 0 ) : 0 );
			$value = \is_array( $row ) ? ( $row['meta_value'] ?? '' ) : '';
			if ( ! isset( $out[ $uid ] ) && Pos_Uuid::is_uuid( $value ) ) {
				$out[ $uid ] = $value; // keep the FIRST valid uuid per user (canonical)
			}
		}

		return $out;
	}

	/**
	 * Term-table twin for CATEGORIES + BRANDS — reads existing uuids from
	 * wp_termmeta in ONE query (meta_id ASC, first-valid-wins canonical). One read
	 * serves a whole proxy page; categories and brands share the table so the same
	 * helper feeds both stamp_proxy_terms resources.
	 */
	public static function bulk_read_term_uuids( array $ids ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $ids ) ) {
			return array();
		}
		$ids          = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$placeholders = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND term_id IN ($placeholders) ORDER BY meta_id ASC",
			array_merge( array( Pos_Uuid::META_KEY ), $ids )
		);
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$tid   = (int) ( \is_array( $row ) ? ( $row['term_id'] ?? 0 ) : 0 );
			$value = \is_array( $row ) ? ( $row['meta_value'] ?? '' ) : '';
			if ( ! isset( $out[ $tid ] ) && Pos_Uuid::is_uuid( $value ) ) {
				$out[ $tid ] = $value; // keep the FIRST valid uuid per term (canonical)
			}
		}

		return $out;
	}
	/**
	 * The ONE stamping loop behind every stamp_proxy_* collection hook on the
	 * `woocommerce_pos_sync_proxy_response` filter. The catalog proxy forwards wc/v3 LISTS,
	 * but wc/v3 STRIPS the protected `_woocommerce_pos_uuid` from most read payloads —
	 * so without these stampers the served records carry no identity and the
	 * uuid-native client (identifyRecord, mintOnMissing:false) throws. A collection
	 * differs ONLY by its config row (mirroring the backfill controller's entity-kind
	 * dispatch — reader / loader / detector per meta store):
	 *
	 * - 'resources' string[]: the proxy resources the row serves.
	 * - 'bulk_read' ?callable(int[] $ids): id => uuid — existing uuids read in ONE
	 *   meta-table query (the stamped fast path: one query per page, not N object
	 *   loads), then re-INJECTED into each payload (wc/v3 stripped them). null =
	 *   PAYLOAD mode (orders): existing uuids are read from each served record's own
	 *   meta_data (HPOS wc/v3 exposes the meta; orders aren't posts, so there is no
	 *   postmeta to bulk-read), and a record that is stamped AND unique passes
	 *   through UNTOUCHED — no injection, no meta_data reshuffle.
	 * - 'load' callable(int $id): the record's stampable object (the WC_Data meta
	 *   duck-type ensure_uuid consumes) or null — loaded ONLY for unstamped/collided
	 *   records, which ensure_uuid mints/re-keys + persists.
	 * - 'collides' callable: the collection's ownership detector (ensure_uuid's
	 *   'collides' opt) — posts / users / terms / HPOS orders each query their own
	 *   store, with deliberately different scoping (see each detector's docblock).
	 *
	 * A uuid shared by MORE THAN ONE record in this response is a clone/import that
	 * copied the protected meta — emitting it would give two RxDB records the SAME
	 * primary key (one hiding the other on the client). Those are routed through
	 * ensure_uuid so the detector re-keys the duplicate, exactly as the
	 * write-time/backfill path does — never injected off the fast bulk path.
	 * Cross-response clones remain the backfill's collision-repair job
	 * (/uuid/backfill?mode=collisions).
	 *
	 * @param mixed $data
	 * @param mixed $resource
	 */
	private static function stamp_proxy_generic( $data, $resource, array $config ) {
		if ( ! \in_array( $resource, $config['resources'], true ) || ! \is_array( $data ) ) {
			return $data;
		}
		$payload_mode = null === $config['bulk_read'];
		$existing     = array();
		if ( $payload_mode ) {
			// Count EVERY served uuid toward collision detection — including one on a
			// record with no id (it still reaches the client, so a uuid it shares must
			// re-key the id-bearing holder).
			$seen = array();
			foreach ( $data as $record ) {
				if ( \is_array( $record ) ) {
					$u = Pos_Uuid::read_valid_uuid_from_meta( $record['meta_data'] ?? array() );
					if ( '' !== $u ) {
						$seen[] = $u;
					}
				}
			}
		} else {
			$ids = array();
			foreach ( $data as $record ) {
				if ( \is_array( $record ) && isset( $record['id'] ) ) {
					$ids[] = (int) $record['id'];
				}
			}
			if ( empty( $ids ) ) {
				return $data;
			}
			$existing = $config['bulk_read']( $ids );
			$seen     = $existing;
		}
		/** @var list<string> $seen */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- narrow the bulk_read closure return for PHPStan.
		$collision = array_fill_keys(
			array_keys(
				array_filter(
					array_count_values( $seen ),
					static function ( $count ) {
						return $count > 1; }
				)
			),
			true
		);
		foreach ( $data as $index => $record ) {
			if ( ! \is_array( $record ) || ! isset( $record['id'] ) ) {
				continue;
			}
			$uuid = $payload_mode
				? Pos_Uuid::read_valid_uuid_from_meta( $record['meta_data'] ?? array() )
				: ( $existing[ (int) $record['id'] ] ?? '' );
			if ( '' !== $uuid && isset( $collision[ $uuid ] ) ) {
				$uuid = ''; // in-response collision — re-key below, don't emit a duplicate key
			}
			if ( '' !== $uuid && $payload_mode ) {
				continue; // stamped and unique — the served meta_data already carries it
			}
			if ( '' === $uuid ) {
				$object = $config['load']( (int) $record['id'] ); // unstamped or collided — mint/re-key + persist
				if ( $object ) {
					$uuid = Pos_Uuid::ensure_uuid( $object, array( 'collides' => $config['collides'] ) );
				}
			}
			if ( '' !== $uuid ) {
				$data[ $index ] = Pos_Uuid::ensure_in_payload( $record, $uuid );
			}
		}

		return $data;
	}

	/**
	 * The five loader strategies (kind-level — see proxy_stamper_config).
	 */
	private static function loader_for( string $loader ): callable {
		switch ( $loader ) {
			case 'coupon':
				return static function ( int $id ) {
					if ( ! class_exists( 'WC_Coupon' ) ) {
						return null;
					}
					$coupon = new WC_Coupon( $id );

					return $coupon->get_id() ? $coupon : null;
				};
			case 'customer':
				return static function ( int $id ) {
					if ( ! class_exists( 'WC_Customer' ) ) {
						return null;
					}

					try {
						$customer = new WC_Customer( $id );
					} catch ( Exception $e ) {
						return null;
					}

					// Guard the round-trip: a WC_Customer for a missing id can construct empty.
					return ( method_exists( $customer, 'get_id' ) && (int) $customer->get_id() === $id ) ? $customer : null;
				};
			case 'term':
				return static function ( int $id ) {
					// The adapter presents the WC_Data meta contract over wp_termmeta.
					return new Term_Meta_Adapter( $id );
				};
			case 'order':
				return static function ( int $id ) {
					$order = \function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;

					return $order ? $order : null;
				};
			case 'product':
			default:
				return static function ( int $id ) {
					$product = \function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;

					return $product ? $product : null;
				};
		}
	}
}
