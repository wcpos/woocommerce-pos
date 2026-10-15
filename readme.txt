=== WCPOS - Point of Sale (POS) plugin for WooCommerce ===
Contributors: kilbot
Tags: ecommerce, point-of-sale, pos, inventory, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 1.11.2
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Turn any device into a register for your WooCommerce store — same catalog, stock and prices, online or off.

== Description ==

**WCPOS turns any device into a point of sale for your [WooCommerce](https://www.woocommerce.com/) store.** Same catalog, same stock, same prices — at the counter, on the road, or over the phone. Run it in a browser or as a native desktop, iOS or Android app, with offline product browsing and cart building during connection drops. _(WCPOS was formerly known as WooCommerce POS.)_

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
* **Receipt Templates:** Pick from a built-in gallery — receipts, invoices, quotes, packing slips, gift receipts, kitchen tickets — or design your own
* **Thermal Printing:** Print directly to 58mm and 80mm thermal printers over network, Bluetooth, or USB
* **Customer Tax IDs:** Built-in field for VAT, ABN, GST, and other regional tax numbers
* **Multilingual:** Available in most major languages
* **Built-in Support:** Access live chat for instant help

= 🔓 PRO FEATURES =
* **Stock Management:** quickly adjust stock levels, pricing and more
* **Order Management:** re-open and print receipts for older orders
* **Customer Management:** create new customers and edit customer details
* **Payment Terminals:** take in-person card payments with Stripe Terminal and SumUp readers
* **Payment Gateways:** check out with any WooCommerce gateway — Stripe, PayPal, Square, Mollie and more
* **Coupons:** apply coupons at the POS with search, coupon pills, and sequential discounts
* **Refunds:** refund POS orders directly from the till
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
If you have purchased a license for [WCPOS Pro](https://wcpos.com/pro) please follow the steps below to install and activate the plugin:

1. Go to: https://wcpos.com/my-account/
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

= Is WCPOS really free? =
Yes. The free plugin is a complete point of sale — unlimited products, orders and customers, with no transaction fees and no per-register charges. Pro adds advanced tools (see below), but you never need it to start selling.

= Do I need anything besides WooCommerce? =
No. If you have a WooCommerce store, install WCPOS and you're taking orders in under two minutes — same catalog, same stock, same prices, at the counter.

= Which devices does it run on? =
Any modern browser, plus native desktop apps (Windows, macOS) and iOS & Android apps (mobile in beta). One login, everything stays in sync.

= Does it work offline? =
Yes. Products are stored locally for instant search, so you can keep browsing, building carts and saving orders during a connection drop, and your changes sync automatically when you reconnect. Taking payment is the one step that still needs a connection -- offline checkout is coming in a future release.

= What hardware do I need? =
Whatever you already have. WCPOS works with standard barcode scanners, 58mm and 80mm thermal receipt printers (network, Bluetooth or USB), and cash drawers — no proprietary equipment and no lock-in.

= Can I take card payments? =
Cash and manual card payments are built in. WCPOS Pro adds integrated payment terminals (Stripe Terminal, SumUp) so you can take chip-and-PIN payments right from the register.

= What's the difference between free and Pro? =
Free is a full POS for taking orders. Pro lets you run your whole store from the register without opening wp-admin: adjust stock and prices, manage orders and customers, apply coupons and refunds, use supported payment gateways and integrated terminals such as Stripe Terminal and SumUp, and print end-of-day reports. See [wcpos.com/pro](https://wcpos.com/pro).

= Can I try it before installing? =
Yes — there's a live demo at [demo.wcpos.com/pos](https://demo.wcpos.com/pos) (login `demo` / `demo`).

= Where do I get help? =
Browse the documentation at [docs.wcpos.com](https://docs.wcpos.com), or reach the community and Pro priority support on [Discord](https://wcpos.com/discord).

= What data does WCPOS send outside my site? =
Your products, orders and customers stay in your own WooCommerce database. WCPOS only talks to outside services for a few specific reasons:

* **To load the app.** The POS interface, its assets and translations are served from a CDN (jsDelivr), like any web app.
* **To find and fix bugs — only if you opt in.** With your permission, WCPOS sends us anonymous usage data (which features are used, on what kind of setup) and anonymous error reports from the POS and from the WCPOS plugin on your server (what went wrong, on which version and platform). This is how we spot problems before you have to write in, fix them faster, and decide what to build next. It never includes customer details, order contents, prices or your site address, and you can change your mind any time in POS > Settings > General.
* **To activate Pro.** Your license key, site identifier (`site_uuid`) and anonymous identifier (`anon_id`) are validated with wcpos.com.
* **To print through the WCPOS Cloud Print relay — only if you use cloud printing.** Your site registers with the relay at cloudprint.wcpos.com when you open the Cloud Print settings, and print jobs for relay-addressed printers are passed through it to your printer, so those receipt contents (which include order details) leave your site. Developers can opt out with the `woocommerce_pos_cloud_print_relay_enabled` filter.

Full details are in our [privacy policy](https://wcpos.com/privacy).

== Screenshots ==

1. WCPOS main screen

== Changelog ==

= 1.11.2 - 2026/10/15 =

- **The POS no longer grows in memory through a cashier day.** Background sync reads, the search index's start-up reads and the storage engine's change history were each kept for the life of the page; an all-day till on web, desktop and mobile now holds steady.
- **Desktop no longer adds a listener on every sync poll.** Each change check left one more abort handler on the store's connection, thousands over a day.
- **Process Payment stops spinning when the store's pay page blocks a sale.** A third-party gateway script that vetoes the submit is reported back with the store's notice instead of leaving the button busy.
- **Cash and Card tenders submit the pay form directly,** so another gateway's checkout script can no longer cancel them.
- **Stripe's checkout script stays off the POS payment page on stores with an older saved script list.** The shipped removals are now a floor that a saved list adds to, and they show as always removed in Checkout Settings.
- **The pay page always has a payment method preselected.** A default gateway that was disabled or removed now falls back to the first enabled one instead of failing with "Invalid payment method".
- **Receipt PDFs keep Greek and other non-Latin text** instead of printing "?" for labels, dates and payment methods.
- **The plugin download is a third smaller.** Template gallery previews load from a CDN and unused PDF fonts are no longer bundled.
- Developers: translations updated to 2026.10.0; a site can host its own gallery previews with the `woocommerce_pos_template_gallery_preview_base_url` filter.

= 1.11.1 - 2026/10/15 =

- **Cash and card payments work again on stores running WooCommerce Stripe 11.1.0.** Stripe's checkout script now stays off the POS payment page, where since Stripe 11.1.0 it blocked every other payment method with "Payment failed. Please try again."
- **A payment page for an order that no longer exists answers "not found"** instead of crashing inside another gateway's code.
- **Free sites offer Cash and Card at the till consistently.** The free-tier gateway rule applied only to the settings screen; it now applies wherever the POS reads its gateways, and falls back to an enabled gateway rather than a disabled default.
- **POS login lockouts are keyed by account and connecting device.** Forwarded address headers are no longer trusted, and five wrong passwords from one place no longer lock the cashier out everywhere.
- **The POS login page only redirects to known origins.** A sign-in link can no longer hand the cashier's tokens to another site.
- **A failed sign-in no longer leaves a phantom session** in the Sessions list.
- Developers: the uninstall cleanup definitions load on their own so Pro can reuse them; CI fails when the vendored worker's RxDB moves inside a published plugin lane.

= 1.11.0 - 2026/10/14 =

- **Sync now finishes its check for records deleted on the store.** Tills on some stores rejected that check and never removed orders or customers deleted in WooCommerce.
- **A guest customer entry no longer stops customer sync.** A change recorded for the Guest customer could stall the customer lane on a till.
- **Long product searches no longer fail on variable products.** Searches of more than ten words, or a long scanned barcode, now return results instead of an error.
- **Orders no longer go missing from search.** A background index cleanup could restore an order's older text, so a sale stopped matching its own order number.
- **Checkout failures show one error, not two.** A failed sale reported the same error twice; it now shows once, with the store's reason when one is given.
- **Adding a customer whose email already exists now says so.** The store's message, such as "an account is already registered with this email", replaces a generic error.
- **A cashier who is signed out in another tab now sees why sync is paused.** The till recovers on its own as soon as another tab renews the session.
- **Stores behind a caching host can connect again.** A host cache that replayed old responses no longer blocks connecting; the app works around it.
- **Damaged local sync data repairs itself.** A damaged row in a till's record of which orders exist on the store is now dropped and re-checked with the store, instead of being refused on every launch.
- **The app no longer rebuilds its local storage indexes on every launch.** A storage check mistook normal edits for damage and rebuilt the indexes each time the app reopened.
- **A payment page that fails to load on Android now offers Retry.** Process Payment no longer spins forever when the store's payment page cannot load.
- **Typing quickly in Android search no longer logs sync errors.** A search cancelled by the next keystroke is now treated as cancelled.
- **Opening the POS page no longer signs out a till that is already open.** A page load now reuses the browser's live session instead of revoking it.
- **The POS survives a plugin update in progress.** A request that lands mid-update retries instead of failing, and Site Health checks the plugin's files against the published checksums.
- **PrintNode and Star cloud printing stop retrying a bad key or account.** A 401, 403 or 404 fails once with a clear message.
- **Cloud printing explains that it needs HTTPS.** Sites without HTTPS get a clear message instead of a relay error.
- **A shop manager can no longer list or end an administrator's POS sessions.** On multisite this includes administrators of archived or deactivated network sites.
- **The order-pay coupon form requires the order key**, as the pay form already does.
- **Refunds stay attached to matched orders on HPOS stores.**
- **POS Only products are hidden from ID-listing queries and related products.**
- **The old /wcpos-login/ page is retired**, and lookalike paths no longer serve the sign-in form.
- **Order paid dates follow one rule** for all three payment-complete answers.
- Developers: storage engine updated to RxDB 17.6.0; clearer error reports for failed pushes and checkout timeouts; Guest (customer id 0) never enters the sync journal; the order-meta receipt snapshot is no longer written and existing rows are cleaned up; a query-string token signs in REST requests only; the scheduled integrity digest rebuild retries on database contention; updated translations template.

= 1.10.27 - 2026/10/12 =

- **The pay window closes a sale on the stored Order Status of whichever gateway the cashier picks on the pay page, not only the gateway the sale opened with.** A bank transfer chosen inside the pay page on a store whose default gateway is something else no longer leaves the till waiting on a checkmark.
- **A gateway that lands a POS order on its stored unpaid status never marks the order as paid.** 1.10.26 fixed this for bank transfer, cheque and cash on delivery; it now holds for every gateway, including third-party ones, and for a save made by another plugin between the status filter and the gateway's own write.
- **POS Only products show in WooCommerce Analytics and WP-CLI again.** They stay hidden from the storefront only; a category report over POS Only products no longer reads "No data".
- Developers: a new filter, `woocommerce_pos_hide_pos_only_products`, switches the storefront exclusion off for a context of your own.

= 1.10.26 - 2026/10/12 =

- **A sale through a gateway set to land on Pending payment or On hold now closes at the till.** The pay window no longer hangs on a checkmark with the order left in the cart; once the order reaches the status you stored for that gateway, the sale is done. Failed, cancelled and parked orders never close this way.
- **A bank-transfer, cheque or cash-on-delivery sale set to land on an unpaid status is no longer marked as paid.** Such an order was stamped with a paid date and counted as revenue in Analytics while nothing had been paid; it now stays unpaid until payment is recorded.
- **A server error quoted on the Logs screen keeps its comparison operators.** Only real HTML tags are stripped from the quoted text now.
- Updates the bundled Sentry PHP SDK to 4.33.2.

= 1.10.25 - 2026/10/08 =

- **Saving or checking out a sale no longer fails with "Allowed memory size exhausted" on a store with a few thousand orders.** Since 1.9.0 every order save loaded the 200 most recent orders to guess which field holds VAT numbers; it now asks the database one small question instead.
- **A damaged row in the till's own bookkeeping no longer blocks the local database.** The till discards the row on the spot instead of refusing the read on every launch; sales, products and customers are never discarded this way.

= 1.10.24 - 2026/10/08 =

- **Removing a coupon from a cart that had already been saved no longer makes Checkout fail.** Until now the next Checkout or Save was refused with "Coupon code is required" and the sale could not be completed until the coupon was put back or the cart rebuilt. This has been the case since coupons arrived in 1.9.0.

= 1.10.23 - 2026/10/07 =

- **Paying with a hosted gateway such as Dintero, Mollie, PayPal or Klarna no longer jumps the till to the receipt before the payment.** The order is left open and settles from the gateway's callback, as before 1.10.20; merchants on 1.10.20–1.10.22 with such a gateway should update.
- **"Checkout failed" no longer appears for a sale the store did accept** on a slow connection. The till now waits up to 45 seconds for the store's answer.
- **Sales waiting behind an expired login are now announced.** A banner in the header shows how many sales are waiting, with a Log in button.
- **A delete at the till is only dropped from the queue once the store confirms it.**
- **Opening an order's refunds while offline, or after the app was in the background, shows an offline card instead of "Couldn't load refunds"**.
- **A corrupt entry in the diagnostic log no longer crashes the Logs screen on iPhone, iPad and Android.**
- Developers: a failed POS session revoke now returns its intended HTTP status (400, 404 or 500) instead of 200. Details on GitHub.

= 1.10.22 - 2026/10/06 =

- **Cashiers can no longer change a customer's role.** Only the Customer role can be assigned from a cashier account; staff roles are reserved for store managers and administrators.

= 1.10.21 - 2026/10/06 =

- **Till totals now match your store to the cent** on stores that round tax at subtotal level.
- **Adding a Bluetooth printer no longer crashes the app** on iPad and iPhone.
- **Cashiers can only edit customer accounts**, never staff accounts.
- **Changing a user's password signs them out of the POS on every device.**
- **Cashiers can edit and delete their own sales again** when their role lacks "edit others' orders".
- **Payment gateways with no customer-facing title now show a name** in POS settings and at the till.
- **Fixes for stores using High-Performance Order Storage:** cash tendered and change, tax-ID suggestions and fiscal submission status now read correctly.
- **Connecting the app to a store with no REST link on its front page works again.**
- **A staging copy of your store no longer overwrites the live store in the app's site list.** A store moved to a new domain appears as a new site and needs connecting once more.
- Developers: a faster product-ID listing on `wcpos/v2`, advertised as a capability on `GET /wcpos/v2/status`. Details on GitHub.

= 1.10.20 - 2026/09/23 =

- **Checkout no longer reports "Checkout failed" for a sale that reached the store.** If the till was busy sending another change, or an earlier attempt had failed, the checkout screen could give up waiting even though the order had been saved. It now hears back as soon as the order is saved, and pressing Checkout again after a failure tries straight away.
- **Search repairs a damaged search index instead of failing at every launch.** When the saved search index could not be read, search stayed broken with "Failed to initialize search". The index is now discarded and rebuilt from the till's records.
- **Gateways that take no payment at the till — quotes, invoices, purchase orders — now close the order with the Order Status you set for them.** The checkout used to succeed but leave the order at POS - Open. Only gateways with an Order Status saved in POS > Settings > Checkout are affected, and the order is not marked paid.
- **Refunds that arrive from a payment gateway now respect your POS customer-email settings.** A refund recorded by a gateway's webhook could email the customer even with POS customer emails switched off; refunds made in wp-admin or at the till were not affected.

Earlier releases (1.10.0 – 1.10.19 and the 1.9 series) are listed at https://github.com/wcpos/woocommerce-pos/releases
