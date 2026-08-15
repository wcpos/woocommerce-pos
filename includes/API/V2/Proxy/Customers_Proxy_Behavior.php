<?php
/**
 * Customer catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WP_REST_Request;
use WP_User_Query;

/**
 * Applies the customer search and extended sorts without a v1 controller.
 */
final class Customers_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/** Customer orderby values WCPOS implements outside wc/v3. */
	private const WCPOS_ORDERBY = array( 'first_name', 'last_name', 'email', 'role', 'username' );

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
		if ( isset( $params['orderby'] ) && \in_array( (string) $params['orderby'], self::WCPOS_ORDERBY, true ) ) {
			$this->delegated['orderby'] = (string) $params['orderby'];
			unset( $params['orderby'] );
		}

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
