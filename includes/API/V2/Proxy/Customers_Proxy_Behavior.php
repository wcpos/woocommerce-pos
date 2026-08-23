<?php
/**
 * Customer catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WP_REST_Request;
use WP_User_Query;

/**
 * Applies the customer search, filters and extended sorts without a v1 controller.
 */
final class Customers_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Params this behavior claims and handles itself, rather than forwarding.
	 *
	 * @var array
	 */
	private $delegated = array();

	/**
	 * Claim the customer params wc/v3 cannot express, and default the role filter.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		$this->delegated = array();
		if ( ! isset( $params['role'] ) ) {
			$params['role'] = 'all';
		}
		if ( isset( $params['search'] ) ) {
			$search = trim( (string) $params['search'] );
			unset( $params['search'] );
			if ( 0 !== preg_match( '/\S/u', $search ) ) {
				$this->delegated['search'] = $search;
			}
		}
		if ( isset( $params['orderby'] ) && \in_array( (string) $params['orderby'], Collection_Rules::orderby_enum( 'customers' ), true ) ) {
			$this->delegated['orderby'] = (string) $params['orderby'];
			unset( $params['orderby'] );
		}
		/*
		 * `roles` (plural) is WCPOS's multi-role filter, and `modified_after` is how the
		 * client asks for the customers touched since its last pull. Neither exists in
		 * wc/v3, which drops an unregistered param in silence — so before this claim the
		 * proxy lane answered a narrowed request with the UNNARROWED list, and a
		 * `modified_after` sync pull re-fetched the whole customer space every tick.
		 * `wcpos/v1` is the frozen authority for both, so the reads below reproduce its
		 * gates verbatim (`roles` must be a non-empty array; a blank date is no filter).
		 */
		$roles = $params['roles'] ?? null;
		if ( null !== $roles && ! \is_array( $roles ) ) {
			// v1 gets this for free: its `roles` schema row is `type => array`, and WP's
			// own arg sanitizer runs `wp_parse_list()` on a comma-joined string. The proxy
			// route carries no schema, so a `roles=a,b` request would otherwise be an
			// array on one lane and an ignored string on the other.
			$roles = wp_parse_list( $roles );
		}
		if ( ! empty( $roles ) ) {
			$this->delegated['roles'] = array_map( 'sanitize_text_field', $roles );
		}
		unset( $params['roles'] );
		if ( isset( $params['modified_after'] ) && '' !== $params['modified_after'] ) {
			$this->delegated['modified_after'] = (string) $params['modified_after'];
		}
		unset( $params['modified_after'] );

		return $params;
	}

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		if ( array() === $this->delegated ) {
			return array();
		}

		$bindings = array();
		add_filter( 'woocommerce_rest_customer_query', array( $this, 'customer_query' ) );
		$bindings[] = array( 'woocommerce_rest_customer_query', array( $this, 'customer_query' ), 10 );
		if ( isset( $this->delegated['search'] ) ) {
			add_action( 'pre_user_query', array( $this, 'search_user_table' ) );
			$bindings[] = array( 'pre_user_query', array( $this, 'search_user_table' ), 10 );
		}
		if ( 'role' === ( $this->delegated['orderby'] ?? null ) ) {
			add_action( 'pre_user_query', array( $this, 'orderby_role' ) );
			$bindings[] = array( 'pre_user_query', array( $this, 'orderby_role' ), 10 );
		}

		return $bindings;
	}

	/**
	 * Apply the delegated customer parameters to WP_User_Query arguments.
	 *
	 * @param array $args Prepared arguments.
	 *
	 * @return array
	 */
	public function customer_query( array $args ): array {
		switch ( $this->delegated['orderby'] ?? '' ) {
			case 'first_name':
			case 'last_name':
				$args['meta_key'] = $this->delegated['orderby'];
				$args['orderby']  = 'meta_value';
				break;
			case 'email':
				$args['orderby'] = 'user_email';
				break;
			case 'role':
				$args['_wcpos_orderby_role'] = true;
				break;
			case 'username':
				$args['orderby'] = 'user_login';
				break;
		}
		if ( isset( $this->delegated['search'] ) ) {
			unset( $args['search'] );
			$args['_wcpos_search'] = $this->delegated['search'];
		}
		if ( isset( $this->delegated['modified_after'] ) ) {
			$timestamp   = strtotime( $this->delegated['modified_after'] );
			$last_update = array(
				'key'     => 'last_update',
				'value'   => $timestamp ? (string) $timestamp : '',
				'compare' => '>',
			);
			/*
			 * AND our row onto whatever is already there rather than replacing it, so a
			 * third party filtering the same query keeps its clauses. `wcpos/v1` flattens
			 * two AND-related sets into one level where it can; the nesting differs, the
			 * SQL WP_Meta_Query builds from it does not.
			 */
			$args['meta_query'] = empty( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Mirrors the v1 lane's `last_update` filter; the direct lane is the frozen authority.
				? array( $last_update )
				: array( 'relation' => 'AND', array( $last_update ), $args['meta_query'] );
		}
		if ( isset( $this->delegated['roles'] ) ) {
			// `role__in` and `role` are mutually exclusive in WP_User_Query; v1 drops
			// `role` for the same reason, so an explicit `role` cannot re-narrow the set.
			$args['role__in'] = $this->delegated['roles'];
			unset( $args['role'] );
		}

		return $args;
	}

	/**
	 * Add per-term user-table and customer-meta search clauses.
	 *
	 * @param WP_User_Query $query User query.
	 */
	public function search_user_table( WP_User_Query $query ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $placeholders is a generated list of %s placeholders, and the keys themselves are passed to prepare() as arguments.
		global $wpdb;

		if ( empty( $query->query_vars['_wcpos_search'] ) ) {
			return;
		}
		$terms = preg_split( '/\s+/u', (string) $query->query_vars['_wcpos_search'], -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $terms || empty( $terms ) ) {
			$query->query_where .= ' AND 1 = 0';
			return;
		}

		$terms     = array_slice( $terms, 0, 10 );
		$meta_keys = array_merge(
			array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_company', 'billing_phone' ),
			Tax_Id_Reader::fallback_user_meta_keys()
		);
		$placeholders = implode( ', ', array_fill( 0, \count( $meta_keys ), '%s' ) );
		$groups       = array();
		foreach ( $terms as $term ) {
			$like   = '%' . $wpdb->esc_like( $term ) . '%';
			$groups[] = $wpdb->prepare(
				"( {$wpdb->users}.user_email LIKE %s
					OR {$wpdb->users}.user_login LIKE %s
					OR {$wpdb->users}.display_name LIKE %s
					OR EXISTS (
						SELECT 1 FROM {$wpdb->usermeta} AS wcpos_search_meta
						WHERE wcpos_search_meta.user_id = {$wpdb->users}.ID
							AND wcpos_search_meta.meta_key IN ($placeholders)
							AND wcpos_search_meta.meta_value LIKE %s
					)
				)",
				array_merge( array( $like, $like, $like ), $meta_keys, array( $like ) )
			);
		}
		$query->query_where .= ' AND ( ' . implode( ' AND ', $groups ) . ' )';
	}

	/**
	 * Sort customer queries by the existing WCPOS role hierarchy.
	 *
	 * @param WP_User_Query $query User query.
	 */
	public function orderby_role( WP_User_Query $query ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb; $when_sql is built from %s placeholders whose values are passed to prepare() as arguments.
		global $wpdb;

		if ( empty( $query->query_vars['_wcpos_orderby_role'] ) ) {
			return;
		}
		$order     = isset( $query->query_vars['order'] ) && 'DESC' === strtoupper( (string) $query->query_vars['order'] ) ? 'DESC' : 'ASC';
		$hierarchy = array( 'administrator', 'shop_manager', 'cashier', 'editor', 'author', 'contributor', 'customer', 'subscriber' );
		$cap_key   = $wpdb->get_blog_prefix() . 'capabilities';
		if ( false === strpos( $query->query_from, 'wcpos_role_meta' ) ) {
			$query->query_from .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->usermeta} AS wcpos_role_meta ON ( {$wpdb->users}.ID = wcpos_role_meta.user_id AND wcpos_role_meta.meta_key = %s )",
				$cap_key
			);
		}

		$when_sql  = '';
		$when_args = array();
		$rank      = 1;
		foreach ( $hierarchy as $role ) {
			$when_sql  .= ' WHEN wcpos_role_meta.meta_value LIKE %s THEN ' . $rank;
			$when_args[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
			++$rank;
		}
		$order_by             = $wpdb->prepare( "CASE{$when_sql} ELSE {$rank} END", $when_args );
		$query->query_orderby = "ORDER BY ( {$order_by} ) {$order}, {$wpdb->users}.user_login ASC";
	}
}
