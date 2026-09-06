<?php
/**
 * Exercise order proxy storage detection without loading WooCommerce's OrderUtil.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

/** Stand in for WordPress hook registration in this isolated process. */
function add_filter() {
	return true;
}

$wcpos_root = \dirname( __DIR__, 2 );
require_once $wcpos_root . '/includes/API/V2/Proxy/Proxy_Behavior.php';
require_once $wcpos_root . '/includes/API/V2/Proxy/Scoped_Proxy_Behavior.php';
require_once $wcpos_root . '/includes/API/V2/Proxy/Orders_Proxy_Behavior.php';

$wcpos_behavior = new \WCPOS\WooCommercePOS\API\V2\Proxy\Orders_Proxy_Behavior();
$wcpos_search   = new ReflectionProperty( $wcpos_behavior, 'search' );
$wcpos_search->setAccessible( true );
$wcpos_search->setValue( $wcpos_behavior, 'AureliaProbe' );

$wcpos_install = new ReflectionMethod( $wcpos_behavior, 'install' );
$wcpos_install->setAccessible( true );
$wcpos_bindings = $wcpos_install->invoke( $wcpos_behavior );

echo $wcpos_bindings[0][0];
