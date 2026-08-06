<?php
/**
 * This array contains the names of the standalone functions that will become mockable via FunctionsMockerHack
 * when running unit tests. If you need to mock a function that isn't in the list, simply add it.
 * Please keep it sorted alphabetically.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

return array(
	// 'current_user_can',
	'delete_user_meta',
	'get_current_blog_id',
	'get_woocommerce_currencies',
	'get_woocommerce_currency_symbol',
	'get_user_meta',
	'is_multisite',
	'usleep',
	'wc_get_price_excluding_tax',
	'wc_get_shipping_method_count',
	'wc_prices_include_tax',
	'wc_site_is_https',
	'wp_cache_add',
	'wp_generate_uuid4',
);
