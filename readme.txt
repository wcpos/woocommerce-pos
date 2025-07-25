=== WooCommerce POS - Point of Sale ===
Contributors: kilbot
Tags: ecommerce, point-of-sale, pos, inventory, woocommerce
Requires at least: 5.6
Tested up to: 6.8
Stable tag: 1.7.11
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

WooCommerce POS is a simple application for taking orders at the Point of Sale using your WooCommerce store.

== Description ==

WooCommerce POS is a simple application for taking orders at the Point of Sale using your [WooCommerce](https://www.woocommerce.com/) store. _It's great for phone orders too!_

> 🕒 Install and start taking orders in less than 2 minutes.

= 🎥 DEMO =
You can see a demo of the WooCommerce POS plugin in action by going to [demo.wcpos.com/pos](https://demo.wcpos.com/pos) with 🔑`login/pass` : `demo/demo`
or download the desktop application:
⬇️ [Download WooCommerce POS for Windows](https://updates.wcpos.com/electron/download/win32-x64)
⬇️ [Download WooCommerce POS for Mac (Intel)](https://updates.wcpos.com/electron/download/darwin-x64)
⬇️ [Download WooCommerce POS for Mac (Apple Silicon)](https://updates.wcpos.com/electron/download/darwin-arm64)

= ✨ FEATURES = 
* **Cross-platform:** Accessible via browser or desktop application _(iOS & Android coming soon)_
* **Offline Storage:** Fast product search and order processing
* **Flexible Cart:** Add products not listed in WooCommerce
* **Barcode Support:** Scan products directly into the cart
* **Custom Receipts:** Tailor receipt templates with PHP
* **Multilingual:** Available in most major languages
* **Built-in Support:** Access live chat for instant help

= 🔓 PRO FEATURES = 
* **Stock Management:** quickly adjust stock levels, pricing and more
* **Order Management:** re-open and print receipts for older orders
* **Customer Management:** create new customers and edit customer details
* **Payment Gateways:** use any gateway for checkout
* **End of Day Reports:** summarise daily sales, transactions, and cash flow for reconciliation
* **Stores:** Manage locations with unique tax settings, pricing and receipts
* **Priority [Discord support](https://wcpos.com/discord):** one-on-one support via private chat

*Discover all PRO features at [wcpos.com/pro](https://wcpos.com/pro)*

= 📋 REQUIREMENTS =
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

1. Go to: http://wcpos.com/my-account/
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

= 1.7.12 - 2025/07/25 = 
* Security Fix: POS receipts should not be publically accessible, NOTE: you may need to re-sync past orders to view the receipt
* Fix: Remove the X-Frame-Options Header for which prevents desktop application users from logging in
* Fix: Checkout email settings have been tested and should now work

= 1.7.11 - 2025/06/18 = 
* Fix: is_internal_meta_key errors for barcodes as '_global_unique_id'

= 1.7.10 - 2025/05/27 = 
* Fix: Undefined variable $cashback in Card gateway
* Fix: Allow non-protected meta_data in Customer response data

= 1.7.9 - 2025/05/21 =
* Security Fix: fix missing authorisation on reading POS settings API (low severity), reported by Marek Mikita (patchstack)
* Fix: Add SKU and prices to Miscellaneous Products
* Fix: Update Card Gateway for HPOS compatibility

= 1.7.8 - 2025/05/06 = 
* Fix: disable Lite Speed caching for POS templates, causing issues with checkout

= 1.7.7 - 2025/04/14 = 
* Fix: issue where variant was not saving properly in the Order Item meta data

= 1.7.5 - 2025/04/09 =
* Fix: $object->object_type error, use $object->get_type() instead
* Fix: increase woocommerce_get_checkout_order_received_url to ensure POS Thank You page is used for POS orders

= 1.7.4 - 2025/03/22 = 
* Revert: changes to the default receipt template

= 1.7.3 - 2025/03/21 = 
* Fix: default receipt template to display Miscellaneous Product Price and better match WooCommerce templates
* Fix: issue where customer data is not correctly clearing when switching from Customer to Guest

= 1.7.2 - 2024/12/27 =
* Fix: Negative fees with tax_status='none' and/or tax_class are now applied correctly to the order
* Fix: Remove routes from WP API index for POS to reduce request size
* Fix: Annoying issue where pagination resets while searching
* Fix: Minor cart display issues
* Fix: Add html decode for special characters
* Fix: Remove 'low stock' as an option in the products filter - this status does not exist
* Fix: Variation attributes doubling when barcode scanning

= 1.7.1 - 2024/11/14 = 
* Fix: Error updating quantity for Product Variations when decimal quantities enabled
* Plugin Conflict: The wePOS plugin alters the standard WC REST API response, which in turn breaks WooCommerce POS
This small update adds code to prevent WooCommerce POS from being activated if wePOS is detected

= 1.7.0 - 2024/11/13 =
* Enhancement: Updated all React components to use modern standards (Tailwind, Radix UI), improving reliability and usability
* Enhancement: Improved the local database query engine for a more responsive POS experience
* Enhancement: Improved barcode scanning detection
* Fix: Popover positioning issues
* Fix: Customer search on Android devices
* Fix: Quick discounts calculation bug affecting some users
* Pro: New Reports page for End of Day Reporting (Z-Report)

= 1.6.6 - 2024/09/05 =
* Fix: POS Only Products appearing in the POS 😓

= 1.6.5 - 2024/09/04 =
* Fix: POS Only Products appearing in the web store

= 1.6.4 - 2024/09/04 =
* Fix: POS Only Products appearing in the web store
* Fix: Disable wp_footer for POS Order Pay template

= 1.6.3 - 2024/06/29 =
- Fix: Critical error preventing bulk update of products

= 1.6.2 - 2024/06/20 = 
- Fix: Error preventing resources (products, orders, customers, etc) from loading on Windows servers

= 1.6.1 - 2024/06/18 = 
- Enhancement: Changed the way POS Only and Online Only products are managed
  - Moved from using postmeta (`_pos_visibility`) to using centralized settings in the options table (`woocommerce_pos_settings_visibility`)
- Improved: Payment template settings, you can now disable all `wp_head` or `wp_footer` scripts
- Fix: 'Invalid response checking updates for products/tags' error

= 1.6.0 - 2024/06/12 = 
* Improved: Performance for large stores
* Added: Log screen for insights into the POS performance and events
* Added: Cart setting to enable/disable show receipt after checkout
* Added: Cart setting to enable/disable auto-print receipt after checkout
* Fix: Prevent order create duplication from the POS
* Fix: Cart subtotal showing tax when tax display is not enabled

= 1.5.1 - 2024/06/03 =
* Fix: "Sorry, you cannot list resources." error for cashier role

= 1.5.0 - 2024/06/03 =
* Fix: the POS will now correctly sync stock quantity after each sale
* Fix: cart tax logic has been improved to fix rounding issues
* Fix: shipping tax will now use the correct class
* Fix: re-opening orders now functions correctly
* Fix: quick discount calculation
* Fix: orders created with different currencies will show the correct currency symbol
* Added: split option to allow multiple cart lines for the same product
* Added: stock filter for products
* Added: Fees can now be a fix percentage of the cart total
* Added: order status can now be changed from the Orders page
* Added: extra display information for Orders, such as 'created_via' and 'cashier'
* Added: cart will only show open orders associated with the logged in cashier
* Pro: cart will only show open orders from the current POS Store
* Pro: current store will stay selected after refresh or application re-open
* ... numerous other bug fixes and performance improvements

= 1.4.16 - 2024/03/28 =
* Fix: nonce check failing for Guest orders when checking out with the desktop application

= 1.4.15 - 2024/03/20 = 
* Fix: another potential error introduced to Pro updater in previous version 🤦‍♂️

= 1.4.13 - 2024/03/19 = 
* Fix: potential error introduced to Pro updater in previous version

= 1.4.12 - 2024/03/18 =
* Security: Fix Insufficient Verification of Data Authenticity to Authenticated (Customer+) Information Disclosure (reported by Lucio Sá)
* Fix: Pro plugin not showing updates for some users

= 1.4.11 - 2024/03/09 =
* Fix: regression in tax calculation when POS settings are different to Online settings
* Fix: regression in product variation images, use 'medium' sized product image instead of full size
* Fix: remove POS Only products from frontend WC REST API response
* Fix: generic get meta method should not be used for '_create_via'
* Fix: add enabled POS gateways to the Order Edit select input
* Fix: other minor PHP warnings

= 1.4.10 - 2024/01/23 =
* Fix: compatibility issue with WooCommerce < 6.7.0

= 1.4.9 - 2024/01/21 =
= 1.4.8 - 2024/01/21 =
* Fix: duplicating Products in WC Admin also duplicated POS UUID, which caused problems

= 1.4.7 - 2024/01/18 =
* Bump: web application to version 1.4.1
* Fix: scroll-to-top issue when scrolling data tables
* Fix: variations not loading after first 10

= 1.4.6 - 2024/01/16 =
* Fix: decimal quantity for orders
* Fix: load translation files

= 1.4.5 - 2024/01/14 =
* Add: show change in checkout modal and receipt for the Cash gateway
* Add: use 'medium' sized product image instead of 'thumbnail'
* Fix: compatibility with alternative login urls, eg: WPS Hide Login

= 1.4.4 - 2024/01/12 =
* Desktop App: fix login for Desktop Application v1.4.0

= 1.4.3 - 2024/01/12 =
* Pro: fix update notification for Pro plugin

= 1.4.2 - 2024/01/12 =
* Urgent Fix: users with role 'cashier' unable to access tax rates API

= 1.4.1 - 2024/01/12 =
* No change, just messed up the release to WordPress.org

= 1.4.0 - 2024/01/11 = 
* Added: Support for High-Performance Order Storage (HPOS)
* Added: New API for Variation Barcode Search
* Pro: Set unique Product Price and Tax Status for each Store
* Pro: Fix Store login for Authorized Users
* Pro: Fix Analytics for POS vs Online Orders
* Pro: New updater
* Plus: Added a lot of PHPUnit Tests, fixed numerous bugs for a more stable POS!

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

= 1.3.7 - 2023/07/31 =
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

== Upgrade Notice ==
