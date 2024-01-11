=== WooCommerce POS ===
Contributors: kilbot
Tags: cart, e-commerce, ecommerce, inventory, point-of-sale, pos, sales, sell, shop, shopify, store, vend, woocommerce, wordpress-ecommerce
Requires at least: 5.6 & WooCommerce 5.3
Tested up to: 6.4
Stable tag: 1.3.12
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Finally, a Point of Sale plugin for WooCommerce! Sell online and in your physical retail store - no monthly fees, no need to sync inventory.

== Description ==

WooCommerce POS is a simple interface for taking orders at the Point of Sale using your [WooCommerce](https://www.woocommerce.com/) store.
WooCommerce POS provides an alternative to Vend or Shopify POS - no need to sync inventory and no monthly subscription fees.

= DEMO =
You can see a demo of the WooCommerce POS plugin in action by going to [https://demo.wcpos.com/pos](https://demo.wcpos.com/pos) with `login/pass` : `demo/demo`

= REQUIREMENTS =
* WordPress >= 5.6
* WooCommerce >= 5.3
* PHP >= 7.4

== Installation ==

= Automatic installation =
1. Go to Plugins screen and select Add New.
2. Search for "WooCommerce POS" in the WordPress Plugin Directory.
3. Install the plugin
4. Click Activate Plugin to activate it.

= Pro installation =
If you have purchased a license for [WooCommerce POS Pro](http://wcpos.com/pro) please follow the steps below to install and activate the plugin:

1. Go to: http://woopos.com.au/my-account/
2. Under My Downloads, click the download link and save the plugin to your desktop.
3. Then go to your site, login and go to the Add New Plugin page, eg: http://<yourstore.com>/wp-admin/plugin-install.php?tab=upload
4. Upload the plugin zip file from your desktop and activate.
5. Next, go to the POS Settings page and enter your License Key and License Email to complete the activation.

= Manual installation =
To install a WordPress Plugin manually:

1. Download the WooCommerce POS plugin to your desktop.
2. If downloaded as a zip archive, extract the Plugin folder to your desktop.
3. With your FTP program, upload the Plugin folder to the wp-content/plugins folder in your WordPress directory online.
4. Go to Plugins screen and find the newly uploaded Plugin in the list.
5. Click Activate Plugin to activate it.

== Frequently Asked Questions ==

= Where can I find more information on WooCommerce POS? =
There is more information on our website at [https://wcpos.com](https://wcpos.com).

* FAQ - https://wcpos.com/faq
* Documentation - https://wcpos.com/docs
* Blog - https://wcpos.com/blog

== Screenshots ==

1. WooCommerce POS main screen

== Changelog ==

= 1.4.0 - 2024/01/11 = 
* Added: Support for High-Performance Order Storage (HPOS)
* Added: New API for Variation Barcode Search
* Pro: Set unique Product Price and Tax Status for each Store
* Pro: Fix Store login for Authorized Users
* Pro: Fix Analytics for POS vs Online Orders
* Pro: New updater
* Plus: Add a lot of PHPUnit Tests, fixed numerous bugs for a more stable POS!

= 1.3.12 - 2023/09/29 =
* Fix: WordPress database error Not unique table/alias: 'wp_postmeta' for query

= 1.3.11 - 2023/09/27 =
* Urgent Fix: product and user search not returning results for some users

= 1.3.10 - 2023/08/29 =
* Urgent Fix: pages with slug starting with 'pos' redirecting to the POS page

= 1.3.9 - 2023/08/18 =
* Fix: limit query length for WC REST API, this was resulting in 0 products being returned for some users
* Fix: pos meta data showing in WP Admin order quick view
* Fix: cashier uuid not unique for multisite installs

= 1.3.8 - 2023/08/08 =
* Fix: login modal for desktop application

= 1.3.7 = 2023/07/31 =
* Fix: rest_pre_serve_request critical error reported by some users
* Fix: change 'woocommerce_available_payment_gateways' filter priority to 99
* Add: setting for servers that don't allow Authorization header

= 1.3.4 and 1.3.5 - 2023/07/29 =
* Urgent Fix: product descriptions being truncated to 100 characters

= 1.3.3 - 2023/07/28 =
* Fix: "Nonce value cannot be verified" when dequeue-ing scripts and styles in POS checkout modal

= 1.3.2 - 2023/07/27 =
* Urgent Fix for variations not downloading for some users

= 1.3.1 - 2023/07/27 =
* Urgent Fix for login modal problems

= 1.3.0 - 2023/07/27 =
* Major stability improvements!
* Fix: Login is now via JWT - no more 'Cookie nonce' errors
* Fix: Many fixes to local data sync and search should make the POS much more enjoyable to use
* Add: Dequeue WordPress styles and scripts on POS checkout page
* Fix: various fixes to the POS settings in WP Admin

= 1.2.4 - 2023/07/25 =
* Fix: empty products effecting some users due to malformed meta_data

= 1.2.3 - 2023/06/21 =
* Fix: coupon form on POS payment modal
* Fix: use woocommerce_gallery_thumbnail instead of full sized images for products and variations

= 1.2.2 - 2023/06/21 =
* Add: basic support for coupons until a more complete solution is implemented
* Fix: customer select in settings
* Pro: add Analytics for POS vs Online sales

= 1.2.1 - 2023/06/12 =
* Fix: Critical error introduced with PHP 7.2 compatibility

= 1.2.0 - 2023/06/12 =
* Refactor: data replication to improve performance
* Refactor: product search, search by sku and barcode for products now works
    - Note: barcode search for variations is still not working, this will be addressed in a future release
* Bug Fix: 'Cannot use object of type Closure as array' in the API.php file
* Bug Fix: Creating orders with decimal quantity
* Bug Fix: Update product with decimal quantity
* Fix: remove private meta data from Order Preview modal
* Fix: turn off PHP version check by composer, note that PHP 7.2+ is still required

= 1.1.0 - 2023/05/19 =
* Fix: disable Lite Speed Cache for POS page
* Fix: add id audit for product categories and tags
* Fix: add min/max price to variable meta data

= 1.0.2 - 2023/05/05 =
* No change, just messed up the release to WordPress.org.

= 1.0.1 - 2023/05/05 =
* Fix: Product and Variations not showing in POS
  Description: The WC REST API response can be too large in some cases causing a 502 server error.
  - Product and Variation descriptions are not truncated to 100 characters for the POS to reduce response size.
  - Yoast SEO is now programmatically disabled for the POS to reduce response size.
  - A message is now logged when the WC REST API product or variation response is too large.

= 1.0.0 - 2023/05/03 =
* Complete rewrite of the plugin with improved functionality and performance.
* Although extensive testing has been done, there may still be bugs.
* We recommend updating only when you have time to deal with potential issues.
* You can always revert to the old version if necessary, https://wordpress.org/plugins/woocommerce-pos/advanced/
* If you need assistance, join our Discord chat at https://wcpos.com/discord for support.
