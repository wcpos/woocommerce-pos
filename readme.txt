=== WooCommerce POS ===
Contributors: kilbot
Tags: cart, e-commerce, ecommerce, inventory, point-of-sale, pos, sales, sell, shop, shopify, store, vend, woocommerce, wordpress-ecommerce
Requires at least: 5.6 & WooCommerce 5.3
Tested up to: 6.2
Stable tag: 1.1.0
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
* PHP >= 7.0

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

= 1.1.1 - 2023/05/xx =
* Fix: remove private meta data from Order Preview modal

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
