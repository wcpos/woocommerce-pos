=== WCPOS - Point of Sale (POS) plugin for WooCommerce ===
Contributors: kilbot
Tags: ecommerce, point-of-sale, pos, inventory, woocommerce
Requires at least: 5.6
Tested up to: 6.8
Stable tag: 1.8.3
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

WCPOS is a simple application for taking orders at the Point of Sale (POS) using your WooCommerce store.

== Description ==

WCPOS (formerly WooCommerce POS) is a simple application for taking orders at the Point of Sale using your [WooCommerce](https://www.woocommerce.com/) store. _It's great for phone orders too!_

> 🕒 Install and start taking orders in less than 2 minutes.

= 🎥 DEMO =
You can see a demo of the WCPOS plugin in action by going to [demo.wcpos.com/pos](https://demo.wcpos.com/pos) with 🔑`login/pass` : `demo/demo`

**Desktop Apps:**
⬇️ [Windows](https://updates.wcpos.com/electron/download/win32-x64)
⬇️ [Mac (Intel)](https://updates.wcpos.com/electron/download/darwin-x64)
⬇️ [Mac (Apple Silicon)](https://updates.wcpos.com/electron/download/darwin-arm64)

**Mobile Apps (Beta):**
📱 [iOS (TestFlight)](https://testflight.apple.com/join/JGBdVRrW)
📱 [Android (Google Play)](https://play.google.com/apps/testing/com.wcpos.main)

= ✨ FEATURES = 
* **Cross-platform:** Accessible via browser, desktop, iOS & Android _(mobile apps in beta)_
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
2. Search for "WCPOS" in the WordPress Plugin Directory.
3. Install the plugin
4. Click Activate Plugin to activate it.

= Pro installation =
If you have purchased a license for [WCPOS Pro](http://wcpos.com/pro) please follow the steps below to install and activate the plugin:

1. Go to: http://wcpos.com/my-account/
2. Under My Downloads, click the download link and save the plugin to your desktop.
3. Then go to your site, login and go to the Add New Plugin page, eg: http://<yourstore.com>/wp-admin/plugin-install.php?tab=upload
4. Upload the plugin zip file from your desktop and activate.
5. Next, go to the POS Settings page and enter your License Key and License Email to complete the activation.

= Manual installation =
To install a WordPress Plugin manually:

1. Download the WCPOS plugin to your desktop.
2. If downloaded as a zip archive, extract the Plugin folder to your desktop.
3. With your FTP program, upload the Plugin folder to the wp-content/plugins folder in your WordPress directory online.
4. Go to Plugins screen and find the newly uploaded Plugin in the list.
5. Click Activate Plugin to activate it.

== Frequently Asked Questions ==

= Where can I find more information on WCPOS? =
There is more information on our website at [https://wcpos.com](https://wcpos.com).

* FAQ - https://wcpos.com/faq
* Documentation - https://wcpos.com/docs
* Blog - https://wcpos.com/blog

== Screenshots ==

1. WCPOS main screen

== Changelog ==

= 1.8.3 - 2025/12/23 =
* Fix: 'Headers already sent' warnings effecting some users

= 1.8.2 - 2025/12/19  = 
* Fix: critical error when old Pro plugin installed and activated < 1.8.0

= 1.8.1 - 2025/12/19 =
* Fix: search not working after update

= 1.8.0 - 2025/12/18 =
**🎉 Major Update - Native Mobile Apps & Improved Architecture**

This release marks a significant milestone for WCPOS! The entire codebase has been rewritten to support our new native iOS and Android applications (currently in beta).

* **New:** Native iOS app now available via [TestFlight](https://testflight.apple.com/join/JGBdVRrW)
* **New:** Native Android app now available via [Google Play Beta](https://play.google.com/apps/internaltest/4701620234973853884)
* **New:** Theme support - choose between light and dark modes
* **New:** Receipt template editor (beta) - customize your receipts directly in the settings
* **New:** Session management - view and revoke active sessions from the POS Settings
* **New:** Support for WooCommerce Cost of Goods Sold (COGS) field
* **New:** Product Brands API endpoint for better brand management
* **Improved:** Error logging - no more mysterious "Invalid response from server" messages! Errors now provide clear, actionable information
* **Improved:** Authentication system with better security and session handling
* **Improved:** Styling updated to Tailwind v4 for a more consistent UI
* **Pro:** WCPOS Pro is now a standalone plugin - download from [wcpos.com/my-account](https://wcpos.com/my-account)

⚠️ **Note:** This is a major update. We recommend updating when you have time to test the POS thoroughly. You can rollback to version 1.7.14 if needed.

= 1.7.14 - 2025/11/19 =
* Change: Plugin name changed from "WooCommerce POS" to "WCPOS" to comply with WooCommerce trademark requirements
* Note: This is a branding change only - all functionality remains the same

= 1.7.13 - 2025/08/06 = 
* Fix: New Order emails to send after order calculations

= 1.7.12 - 2025/07/25 = 
* Security Fix: POS receipts should not be publically accessible, NOTE: you may need to re-sync past orders to view the receipt
* Fix: Remove the X-Frame-Options Header for which prevents desktop application users from logging in
* Fix: Checkout email settings have been tested and should now work

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
* Plugin Conflict: The wePOS plugin alters the standard WC REST API response, which in turn breaks WCPOS
This small update adds code to prevent WCPOS from being activated if wePOS is detected

= 1.7.0 - 2024/11/13 =
* Enhancement: Updated all React components to use modern standards (Tailwind, Radix UI), improving reliability and usability
* Enhancement: Improved the local database query engine for a more responsive POS experience
* Enhancement: Improved barcode scanning detection
* Fix: Popover positioning issues
* Fix: Customer search on Android devices
* Fix: Quick discounts calculation bug affecting some users
* Pro: New Reports page for End of Day Reporting (Z-Report)

== Upgrade Notice ==

= 1.8.0 =
**Major Update** - Update when you have time to test the POS. You can rollback to the previous version if needed. Pro users: WCPOS Pro is now standalone - download the latest version from wcpos.com/my-account
