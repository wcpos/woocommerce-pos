<?php
/**
 * Exercise order proxy storage detection without loading WooCommerce's OrderUtil.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

/** Stand in for WordPress hook registration in this isolated process. */
function add_filter( $hook ) {
	$GLOBALS['wcpos_fixture_hooks'][] = $hook;
	return true;
}

/** Stand in for WordPress hook removal in this isolated process. */
function remove_filter() {
	return true;
}

/** Minimal request data without bootstrapping WooCommerce's OrderUtil. */
class WP_REST_Request {
	/**
	 * Route for the proxy request.
	 *
	 * @return string
	 */
	public function get_route() {
		return '/wcpos/v2/orders';
	}

	/**
	 * Read the isolated request parameter.
	 *
	 * @param string $key Parameter name.
	 * @return string|null
	 */
	public function get_param( $key ) {
		return 'search' === $key ? 'AureliaProbe' : null;
	}
}

$wcpos_root = \dirname( __DIR__, 2 );
require_once $wcpos_root . '/includes/API/V2/Proxy/Proxy_Behavior.php';
require_once $wcpos_root . '/includes/API/V2/Proxy/Scoped_Proxy_Behavior.php';
require_once $wcpos_root . '/includes/API/V2/Proxy/Orders_Proxy_Behavior.php';

require_once $wcpos_root . '/includes/Sync/Meta_Entry.php';
require_once $wcpos_root . '/includes/Sync/Collection_Rules.php';
require_once $wcpos_root . '/includes/Sync/Collection_Rules_Plan.php';

// Arrange: use the real storage detector and search plan without OrderUtil.
$wcpos_plan = new \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan(
	'orders',
	array( 'search' => array( 'param' => 'search' ) ),
	\WCPOS\WooCommercePOS\Sync\Collection_Rules::detect_storage( 'orders' ),
	new WP_REST_Request(),
	array( 'search' => 'search' )
);
$wcpos_behavior = new \WCPOS\WooCommercePOS\API\V2\Proxy\Orders_Proxy_Behavior();
$wcpos_property = new ReflectionProperty( $wcpos_behavior, 'plan' );
$wcpos_property->setAccessible( true );
$wcpos_property->setValue( $wcpos_behavior, $wcpos_plan );

// Act / Assert: the parent test requires exactly the legacy search hook.
$wcpos_behavior->around(
	static function () {
		echo implode( ',', $GLOBALS['wcpos_fixture_hooks'] );
	}
);
