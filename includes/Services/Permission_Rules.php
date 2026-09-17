<?php
/**
 * WCPOS permission rules. WooCommerce remains the authority for its own fences.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * In-process decisions using the canonical Sync\Collections names.
 */
class Permission_Rules {
	/**
	 * Collection, WooCommerce controller suffix, filter object, POS read grant.
	 */
	private const RULES = array(
		'customers'        => array( 'Customers', 'user', false ),
		'orders'           => array( 'Orders', 'shop_order', false ),
		'products'         => array( 'Products', 'product', false ),
		'coupons'          => array( 'Coupons', 'shop_coupon', true ),
		'tax_rates'        => array( 'Taxes', 'settings', true ),
		'tax_classes'      => array( 'Tax_Classes', 'settings', true ),
		'shipping_methods' => array( 'Shipping_Methods', 'shipping_methods', true ),
	);

	/**
	 * Cashiers have edit_others_shop_orders, but NOT delete_others_shop_orders.
	 */
	private const ORDER_RULES = array(
		array(
			'lane'      => 'v1',
			'context'   => 'delete',
			'ownership' => false,
			'reason'    => 'Cashier lacks delete_others_shop_orders; preserve the legacy flat grant.',
		),
		array(
			'lane'      => 'v2',
			'context'   => 'delete',
			'ownership' => true,
			'reason'    => 'Cashier lacks delete_others_shop_orders; preserve the current non-owner denial.',
		),
		array(
			'lane'      => 'v1',
			'context'   => 'edit',
			'ownership' => true,
			'reason'    => 'Cashier holds edit_others_shop_orders; ownership-aware edit is safe on both lanes.',
		),
		array(
			'lane'      => 'v2',
			'context'   => 'edit',
			'ownership' => true,
			'reason'    => 'Keep the existing ownership-aware edit rule.',
		),
	);

	/**
	 * Nested forwards must restore the enclosing permission scope.
	 *
	 * @var array
	 */
	private static $scopes = array();
	/**
	 * Customer re-judge latch, moved from Write_Controller (cff66d7f).
	 *
	 * @var bool
	 */
	private static $rejudging_user_target = false;

	/**
	 * Return true or WooCommerce's original WP_Error, including credential fences.
	 * Optional plain-data lane and params preserve v1 delete and email/password checks.
	 * Actor 0 means the current user; temporary actor changes are always restored.
	 *
	 * @param string $collection Canonical collection name.
	 * @param string $context    Permission context.
	 * @param int    $object_id  Target object ID.
	 * @param int    $actor_id   Actor ID, or zero for the current user.
	 * @param string $lane       Permission lane.
	 * @param array  $params     Original request parameters.
	 * @return bool|\WP_Error
	 */
	public static function verdict( string $collection, string $context, int $object_id = 0, int $actor_id = 0, string $lane = 'v2', array $params = array() ) {
		$previous = get_current_user_id();
		$actor_id = $actor_id ? $actor_id : $previous;
		if ( $actor_id !== $previous ) {
			wp_set_current_user( $actor_id );
		}
		self::install_wc_filter( $collection, $lane );
		$restore = null;
		try {
			if ( 'customers' === $collection && in_array( $context, array( 'edit', 'delete' ), true ) && ! self::can_modify( $actor_id, $object_id ) ) {
				return self::denial();
			}
			if ( 'v1' === $lane && 'customers' === $collection && in_array( $context, array( 'edit', 'delete' ), true ) ) {
				$restore = self::allow_target_roles( $object_id );
			}
			$row = self::RULES[ $collection ] ?? null;
			if ( null === $row ) {
				return current_user_can( 'access_woocommerce_pos' ) ? true : new \WP_Error(
					'woocommerce_rest_cannot_view',
					__( 'Sorry, you cannot list resources.', 'woocommerce' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			if ( 'v1' === $lane && $row[2] && 'read' === $context && current_user_can( 'access_woocommerce_pos' ) ) {
				return true; // Legacy overrides bypassed even subsequent WC permission filters.
			}
			$class   = '\\WC_REST_' . $row[0] . '_Controller';
			$methods = array(
				'read'   => $object_id ? 'get_item_permissions_check' : 'get_items_permissions_check',
				'create' => 'create_item_permissions_check',
				'edit'   => 'update_item_permissions_check',
				'delete' => 'delete_item_permissions_check',
				'batch'  => 'batch_items_permissions_check',
			);
			$http_methods = array(
				'read'   => 'GET',
				'create' => 'POST',
				'edit'   => 'PUT',
				'delete' => 'DELETE',
				'batch'  => 'POST',
			);
			$request      = new \WP_REST_Request( $http_methods[ $context ] );
			$request->set_query_params( $params );
			$request->set_param( 'id', $object_id );
			$permission = ( new $class() )->{$methods[ $context ]}( $request );
			if ( 'v1' === $lane && 'customers' === $collection && 'create' === $context && is_wp_error( $permission ) ) {
				$cap = version_compare( WC()->version, '9.9', '>=' ) ? 'create_customers' : 'promote_users';
				if ( current_user_can( $cap ) ) {
					return true;
				}
			}
			if ( 'v1' === $lane && 'orders' === $collection && is_wp_error( $permission ) && self::wc_filter( false, $context, $object_id, 'shop_order', 'orders', 'v1' ) ) {
				return true;
			}
			return $permission;
		} finally {
			if ( $restore ) {
				$restore();
			}
			self::uninstall_wc_filter();
			if ( $actor_id !== $previous ) {
				wp_set_current_user( $previous );
			}
		}
	}

	/**
	 * Install only the rules needed by this forward (writes by default).
	 *
	 * @param string $collection Canonical collection name or writes.
	 * @param string $lane       Permission lane.
	 * @return void
	 */
	public static function install_wc_filter( string $collection = 'writes', string $lane = 'v2' ): void {
		// Removing the callback ends its scopes; reinstallation must not resurrect them.
		if ( false === has_filter( 'woocommerce_rest_check_permissions', array( self::class, 'wc_filter' ) ) ) {
			self::$scopes = array();
		}
		self::$scopes[] = array( $collection, $lane );
		add_filter( 'woocommerce_rest_check_permissions', array( self::class, 'wc_filter' ), 10, 4 );
	}

	/**
	 * Restore the enclosing scope, removing the hook at the outermost boundary.
	 *
	 * @return void
	 */
	public static function uninstall_wc_filter(): void {
		array_pop( self::$scopes );
		if ( empty( self::$scopes ) ) {
			remove_filter( 'woocommerce_rest_check_permissions', array( self::class, 'wc_filter' ), 10 );
		}
	}

	/**
	 * Single WC callback; optional scope arguments serve deprecated public forwarders.
	 *
	 * @param bool        $permission Incoming WC permission.
	 * @param string      $context    Permission context.
	 * @param int         $object_id  Target object ID.
	 * @param string      $post_type  WC object type.
	 * @param string|null $collection Explicit collection, or null for the active scope.
	 * @param string      $lane       Permission lane for an explicit collection.
	 * @return bool
	 */
	public static function wc_filter( $permission, $context, $object_id, $post_type, $collection = null, $lane = 'v2' ) {
		if ( null === $collection ) {
			if ( empty( self::$scopes ) ) {
				return $permission;
			}
			list( $collection, $lane ) = end( self::$scopes );
			if ( 'v1' === $lane && in_array( $collection, array( 'customers', 'orders' ), true ) ) {
				return $permission; // V1 judges the complete WC result, not its intermediate bool.
			}
		}
		if ( 'writes' === $collection || 'customers' === $collection ) {
			if ( 'user' === $post_type && (int) $object_id > 0 && in_array( $context, array( 'edit', 'delete' ), true ) ) {
				if ( self::$rejudging_user_target ) {
					return $permission;
				}
				if ( ! self::can_modify( get_current_user_id(), (int) $object_id ) ) {
					return false;
				}
				if ( $permission ) {
					return true;
				}
				self::$rejudging_user_target = true;
				$restore                      = self::allow_target_roles( (int) $object_id );
				try {
					return (bool) wc_rest_check_user_permissions( $context, (int) $object_id );
				} finally {
					$restore();
					self::$rejudging_user_target = false;
				}
			}
		}
		if ( ! $permission && 'shop_order' === $post_type && in_array( $collection, array( 'writes', 'orders' ), true ) ) {
			// V1 checked existence before its fallback (23defd774); v2 did not.
			if ( 'v1' === $lane && ( ! wc_get_order( $object_id ) || ! current_user_can( "{$context}_shop_orders" ) ) ) {
				return $permission;
			}
			// Without a post row, only v1 historically granted the flat edit cap.
			$caps = array(
				'read'   => 'read_private_shop_orders',
				'create' => 'publish_shop_orders',
				'edit'   => 'v1' === $lane ? 'edit_shop_orders' : null,
				'delete' => 'delete_shop_orders',
			);
			$cap  = $caps[ $context ] ?? null;
			foreach ( self::ORDER_RULES as $rule ) {
				if ( $lane === $rule['lane'] && $context === $rule['context'] && $rule['ownership'] ) {
					$post = get_post( $object_id );
					if ( $post ) {
						$cap = get_current_user_id() === (int) $post->post_author ? "{$context}_shop_orders" : "{$context}_others_shop_orders";
					}
				}
			}
			if ( $cap && current_user_can( $cap ) ) {
				$permission = true;
			}
		}
		$row = self::RULES[ $collection ] ?? null;
		if ( ! $permission && $row && $row[2] && $row[1] === $post_type && 'read' === $context ) {
			$permission = current_user_can( 'access_woocommerce_pos' );
		}
		return $permission;
	}

	/**
	 * Get the capabilities that identify protected staff accounts.
	 *
	 * @return array
	 */
	public static function protected_capabilities(): array {
		/*
		 * Filters the capabilities that mark an account as staff.
		 *
		 * A POS user who cannot manage_options may not edit or delete an account
		 * holding any of these. The default marks site and store administration
		 * (manage_options, manage_woocommerce), user management (edit_users, which
		 * the Cashier role holds) and an author seat in wp-admin (edit_posts:
		 * editors, authors and contributors). Narrowing the list moves a target into
		 * the cleared path. Cleared targets bypass WooCommerce's
		 * woocommerce_shop_manager_editable_roles role-name restriction before
		 * WooCommerce re-judges them.
		 *
		 * @param {array} $capabilities
		 * @returns {array} $capabilities
		 * @since 1.10.10
		 * @hook woocommerce_pos_protected_account_capabilities
		 */
		$caps = apply_filters( 'woocommerce_pos_protected_account_capabilities', array( 'manage_options', 'manage_woocommerce', 'edit_users', 'edit_posts' ) );

		return array_values( array_unique( array_filter( array_map( 'strval', (array) $caps ) ) ) );
	}

	/**
	 * Check staff protection before WooCommerce's own permission checks.
	 *
	 * Deny is the default for anything this method cannot resolve: it only ever
	 * removes permission, so a caller that reaches it with a bad id is refused
	 * rather than waved through.
	 *
	 * @param int $actor_id  Acting user ID.
	 * @param int $target_id Target user ID.
	 *
	 * @return bool
	 */
	public static function can_modify( int $actor_id, int $target_id ): bool {
		if ( $actor_id < 1 || $target_id < 1 ) {
			return false;
		}
		if ( user_can( $actor_id, 'manage_options' ) || $actor_id === $target_id ) {
			return true;
		}
		$target = get_user_by( 'id', $target_id );
		if ( ! $target ) {
			return false;
		}
		foreach ( self::protected_capabilities() as $cap ) {
			if ( user_can( $target, $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Let WooCommerce judge a cleared target by capability rather than role name.
	 *
	 * WooCommerce restricts a shop_manager to editing users whose role is in
	 * `woocommerce_shop_manager_editable_roles` (default: customer only), so a
	 * subscriber or a membership plugin's own customer role is refused outright
	 * even though the POS lists it. Once can_modify() has cleared the target of
	 * every staff capability, that role-name test adds nothing, so allow the
	 * target's own roles for the duration of the check.
	 *
	 * Call only after can_modify() returned true, and always invoke the returned
	 * closure to remove the filter.
	 *
	 * @param int $target_id Target user ID, already cleared by can_modify().
	 *
	 * @return callable Restores the unfiltered behaviour.
	 */
	public static function allow_target_roles( int $target_id ): callable {
		$target = get_user_by( 'id', $target_id );
		$roles  = $target ? array_values( (array) $target->roles ) : array();

		if ( empty( $roles ) ) {
			return static function (): void {};
		}

		$filter = static function ( $allowed ) use ( $roles ) {
			return array_values( array_unique( array_merge( (array) $allowed, $roles ) ) );
		};

		add_filter( 'woocommerce_shop_manager_editable_roles', $filter );

		return static function () use ( $filter ): void {
			remove_filter( 'woocommerce_shop_manager_editable_roles', $filter );
		};
	}

	/**
	 * Build the staff account permission error.
	 *
	 * @return \WP_Error
	 */
	public static function denial(): \WP_Error {
		return new \WP_Error(
			'woocommerce_pos_rest_cannot_edit_staff_account',
			__( 'Only an administrator can edit or delete a staff account from the POS.', 'woocommerce-pos' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
