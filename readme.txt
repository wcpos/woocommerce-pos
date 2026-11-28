=== WCPOS - Point of Sale (POS) plugin for WooCommerce ===
Contributors: kilbot
Tags: ecommerce, point-of-sale, pos, inventory, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.6
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

WCPOS is a point of sale (POS) for WooCommerce. Same stock online and at the till. Free, no transaction fees. Keep selling when the connection drops.

== Description ==

WCPOS is a point of sale (POS) for WooCommerce.

One catalogue, one stock count, one set of prices, online and at the till, and every sale a WooCommerce order. Ring them up in a browser, in the desktop app for Windows, macOS or Linux, or in the iOS and Android apps now in beta.

Free: ring up sales, take cash and cards on your own terminal, split a payment, run one register with its cash drawer, print receipts.

Pro: connected card readers, refunds, editing stock and prices, re-opening older orders, a customer display, more registers and stores.

WCPOS was called WooCommerce POS until November 2025.

Try it first at [demo.wcpos.com/pos](https://demo.wcpos.com/pos), login `demo` / `demo`.

Desktop app (Windows 10 or later, macOS 12 or later, Linux x64): [Windows](https://updates.wcpos.com/electron/download/win32-x64) · [Mac (Apple silicon)](https://updates.wcpos.com/electron/download/darwin-arm64) · [Mac (Intel)](https://updates.wcpos.com/electron/download/darwin-x64) · [Linux (.deb)](https://updates.wcpos.com/electron/download/linux-x64) · [Linux (.rpm)](https://updates.wcpos.com/electron/download/linux-x64-rpm)

Mobile apps, in beta (iOS and iPadOS 16.4 or later, Android 10 or later): [iPhone and iPad (TestFlight beta)](https://testflight.apple.com/join/JGBdVRrW) · [Android (Google Play beta)](https://play.google.com/apps/testing/com.wcpos.main)

= Free: a complete till =

Ring up the sale:

* Search or scan. Find products by name or barcode, or tap a quick filter button.
* Edit the cart. Change quantities and prices, and discount a line or the whole order.
* Coupons. Apply your existing WooCommerce coupons in the cart.
* Extras. Add an unlisted product, a fee or shipping.
* Customers. Choose an existing customer, with their tax ID on the order and receipt.
* Park a sale. Set it aside and come back to it.

Take the money:

* Cash. Use the keypad beside the cart and see the change as you type.
* Card. Record a card payment taken on your own terminal.
* Split payments. Split evenly, by amount, by percent or by item.

Close the day:

* One register. Shared by every device on the counter, with a float and cash in and out.
* Drawer count. Count the drawer at close and file a numbered closing report.
* Closing reports. Reprint, recount or export them as CSV.
* Today's report. Sales and closures for today on this register, to print or export as CSV.

Print and get help:

* Receipts. Print or email receipts, gift receipts, invoices, purchase orders and kitchen tickets.
* Printers. Thermal and cloud printers. See the hardware list below.
* Help. Press Support and the WCPOS Discord opens inside the POS.

= Works offline, on every device =

Each device keeps its own copy of the catalogue, customers and open orders. When the connection drops you keep searching and building carts, and sales and receipt emails send once the store is back.

* Known sales. A sale with an order number still takes cash or a card on your own terminal.
* New sales. A sale started offline waits for the connection before it is paid.
* Offline badge. Shown beside the store name, with each payment method marked as available or not.
* Needs attention. A payment the store refuses once it is back lands under Needs attention in Orders.

= Your data, your WooCommerce =

Products, orders and customers stay in your own WooCommerce database. There is no second catalogue to keep in step.

* No transaction fees. WCPOS takes no cut of your sales.
* No device charges. The free plugin has no per-register or per-device charges.
* Open source. GPL-3.0, with the code on [GitHub](https://github.com/wcpos).

= Your hardware, your gateways =

* Scanners. The device camera, or a USB or Bluetooth barcode scanner.
* Thermal printers. 58 mm and 80 mm, over the network, Bluetooth or USB.
* Cloud printers. PrintNode, Star Online, Star CloudPRNT or Epson Server Direct Print.
* Cash drawer. Wire it to the receipt printer, turn on Auto-open, and it opens with each receipt.
* Card terminal. Take cards on the terminal you already have, and record them at the till.
* With Pro. Connect a card reader (listed under What Pro adds), or turn on your own WooCommerce gateways.

The POS comes in over 40 languages and ten themes, and the cart sits on the left or the right.

= What Pro adds =

[WCPOS Pro](https://wcpos.com/pro) adds the screens for running the store from the POS, and more ways to pay.

Run the store from the POS:

* Stock and prices. Change them in the product list.
* Customers. Add new customers and edit existing ones.
* Coupons screen. Look up coupons and create new ones.
* Older orders. Find, re-open and edit them, and reprint their receipts.
* Refunds. Full or partial, back to the original method when the gateway supports it, or as cash.
* Full reports. Earlier days, every register and every store, and the closures history.

Take more payments:

* Card readers. Stripe Terminal (smart and Bluetooth readers), SumUp, Mollie Terminal, Square Terminal, PayArc, Mercado Pago Point, PayPal Reader (Zettle).
* Reader extensions. Each reader is its own extension, installed from the POS.
* Vipps MobilePay. Customers pay by QR code or push payment.
* Email Invoice. Send a link the customer pays online.
* Web Checkout. The customer pays on your online store.
* Your gateways. Turn on your store's other WooCommerce gateways for the POS, on their own payment page.
* Recorded payments. Cash on delivery, bank transfer and cheque, recorded at the till.

More registers, stores and extras:

* More registers. One per cash drawer, each with its own float, count and closing report.
* More stores. Several stores on one site, each with its own address, logo, tax IDs and cashiers.
* Store templates. Give each store its own list of receipt templates.
* Stock per location. Through ATUM Multi-Inventory.
* Customer display. A second screen in any browser, paired to the till with a code.
* Extensions. Install, activate and update them from the directory inside the POS.
* Priority support. On the WCPOS Discord, with your licence.

= Requirements =

* WordPress 5.6 or later (5.8 with Pro)
* WooCommerce 9.0 or later
* PHP 7.4 or later, PHP 8 recommended
* Upgrading from 1.11? Sync every till first. See the FAQ.

== Installation ==

= Automatic installation =
1. In WP Admin, go to Plugins > Add New Plugin.
2. Search for "WCPOS".
3. Click Install Now.
4. Click Activate.

= Installing WCPOS Pro =
Pro installs over the free plugin, so you do not remove anything first.

1. Sign in at wcpos.com and open [Downloads](https://wcpos.com/account/downloads).
2. Download the WCPOS Pro zip file.
3. In WP Admin, go to Plugins > Add New Plugin > Upload Plugin, upload the zip and click Activate.
4. Copy your licence key from the [Licenses](https://wcpos.com/account/licenses) page.
5. In WP Admin, go to POS > Settings. Under Account, open License, paste the key and press Activate.

Pro is a standalone plugin, so once it is active you can deactivate the free plugin.

= Manual installation =
1. Download the WCPOS plugin zip file.
2. Unzip it to get the plugin folder.
3. Upload the folder to `wp-content/plugins` on your server, by FTP or your host's file manager.
4. In WP Admin, go to Plugins and find WCPOS in the list.
5. Click Activate.

== Frequently Asked Questions ==

= Is WCPOS really free? =

Yes. The free plugin is a complete till, with no transaction fees and no charge per register or per device.

The extension directory is open to browse. Installing from it needs Pro.

You never need Pro to start selling.

= What does Pro cost? =

$129 a year, or $399 once for Lifetime. Each licence covers two sites.

Yearly includes one year of updates and priority support, and it does not renew by itself. When it ends, Pro keeps working and only new updates and priority support stop.

Prices as of November 2026, at [wcpos.com/pro](https://wcpos.com/pro).

= Is there a Pro trial? =

No. The free plugin is the trial, and every Pro screen in it has a View Demo button.

To try the POS before installing anything, open the demo at [demo.wcpos.com/pos](https://demo.wcpos.com/pos) and sign in with `demo` / `demo`.

= Do I need a second catalogue? =

No. The POS sells from your WooCommerce products, stock and prices, and every sale is recorded as a WooCommerce order.

= Which devices does it run on? =

Any modern web browser, plus desktop and mobile apps. One login works on all of them.

* Windows 10 or later, 64-bit.
* Mac on macOS 12 or later, Apple silicon or Intel.
* Linux, 64-bit, as a .deb or .rpm package.
* iPhone and iPad on iOS 16.4 or later, in beta through TestFlight.
* Android 10 or later, in beta through Google Play.

= What if the internet goes down? =

The till keeps working from what is on the device.

* Known sales. Pay any sale with an order number in cash or on your own card terminal.
* New sales. A sale you start offline waits for the connection, then you take its payment.
* Sending. Sales and receipt emails send themselves when the store is back.
* Refused sales. If the store refuses a payment taken offline, the sale shows under Needs attention in Orders.
* Offline badge. Beside the store name, it tells you which state you are in.
* Readers and gateways. Connected card readers and online gateways need the connection.

= What hardware do I need? =

None to start. A device with a browser is enough.

For a counter, use the scanner, printer, cash drawer and card terminal you already have. There is no WCPOS hardware to buy.

* Office printer. HTML templates print through the system print dialog.
* Cash drawer setup. Turn on Auto-open cash drawer for its printer in Settings > Printing.

= How do I take card payments? =

On your own terminal with the free plugin, or on a connected reader with Pro.

On the free plugin, take the card on your terminal and press Card to record it. With Pro, each reader is its own extension, installed from the POS.

Pro also lets you turn on your store's other WooCommerce gateways for the POS. You are not tied to one card company.

= What does WCPOS not do? =

WCPOS needs a WooCommerce store, and it has no fiscal certification in any country today.

* WooCommerce only. WCPOS sells from a WooCommerce store.
* No fiscal certification in any country today. Fiscal compliance is the focus of WCPOS 2.1.
* No kiosk mode for customers to serve themselves.
* No WCPOS hardware to buy.
* No offline-only sales. A sale cannot start and finish entirely offline.

= Upgrading from WCPOS 1.11? =

Sync every till first. 2.0 starts a new local database on each device and does not carry over 1.11 sales that have not been sent.

Update the app and the plugin together, because both must be on 2.0. WCPOS 2.0 needs WooCommerce 9.0 or later.

= Where do I get help? =

Inside the POS. Press Support at the foot of the menu and the WCPOS Discord opens.

You can also join at [wcpos.com/discord](https://wcpos.com/discord), and the documentation is at [docs.wcpos.com](https://docs.wcpos.com). Pro licences include priority support on Discord.

Licence questions and private matters go by email.

= Is WCPOS open source? =

Yes, under GPL-3.0, with the code on [GitHub](https://github.com/wcpos).

Developers get a REST API under `/wcpos/v1/` and `/wcpos/v2/`, and hooks and filters named `wcpos_*`.

= What leaves my site? =

WCPOS contacts outside services to load the app, send optional anonymous reports, check a Pro licence and relay cloud printing. Your products, orders and customers stay in your own WooCommerce database.

Besides a cloud print service you set up yourself, and the WCPOS Discord when you press Support, WCPOS sends data outside your site for four reasons.

To load the app:

* CDN. The POS app loads from cdn.wcpos.com, with jsDelivr as the fallback.
* Signed. The bundle is signed and checked before it runs.

Usage and error reports, only if you opt in:

* Consent. A pop-up asks after you activate the plugin.
* Your choice. Turn them on or off under POS > Settings > General > Privacy.
* Setup. PHP and WooCommerce versions, country and currency.
* Store size. Approximate counts of products, orders and POS users.
* Usage. Which screens and features are used.
* Errors. Error reports, with file paths redacted.
* Hostnames. The bare hostname of the store and of wp-admin, never a page address.
* Never sent. Customer details, order contents or prices.
* Not opted in? Then the store sends none of it.

To activate Pro:

* Licence check. Your licence key, site identifier (`site_uuid`) and anonymous identifier (`anon_id`) are checked with wcpos.com.

The WCPOS Cloud Print relay, only if you use it:

* Registration. The store registers with cloudprint.wcpos.com the first time someone opens the Cloud Print settings.
* Print jobs. Jobs for relay printers pass through it, receipt contents included.
* Never opened? A store that never opens Cloud Print never contacts the relay.
* Opt out. Developers can turn the relay off with the `wcpos_cloud_print_relay_enabled` filter.

Full details are in the [privacy policy](https://wcpos.com/privacy).

== Screenshots ==

1. The register: search or scan, quick filter buttons, and a cart with a discounted line.
2. Checkout at the till: the cash keypad beside the cart, with the change shown as you type.
3. Split payments: one sale taken in several payments, each recorded as it is taken.
4. Closing the register: the numbered closure, with the drawer count against the expected amount.
5. Reports (Pro view): a day's sales with a chart by hour, cards for orders and payments, and the register's float and expected cash. Free shows today on this register.
6. The template gallery in WP Admin: receipts, gift receipts, invoices, purchase orders and kitchen tickets.
7. Settings > Theme: ten themes and a Scale setting for compact, regular or spacious layouts.
8. Offline: the Offline badge beside the store name while the till keeps working.

== Changelog ==

Older releases are listed on [GitHub](https://github.com/wcpos/woocommerce-pos/releases).

= 2.0.6 - 2026/11/27 =

Paying for orders, the POS login and manager approvals are better protected, and WCPOS is now tested with WordPress 7.2.

* Security: paying for an order, checking it out or changing its email needs the right to edit it; if you limited cashiers' order rights, test payments.
* Security: manager approvals lock after five wrong passwords, like the POS login; failed sign-ins can no longer fill the store's database.
* WCPOS now cancels a card hold when its order is deleted or the hold runs out in the trash; a return moved to another product now moves its stock.
* A till no longer re-taxes a refunded order when its address changes, which left the refunded tax wrong; that change is now refused.
* New: a store copied from another store's database can give its users new POS identities in WooCommerce > Status > Tools, so cashiers sign in to both.
* New: with an app that offers it, a paid sale's tender (cash that was really card) or the day it counts on can be corrected, with an order note.
* Also fixes WP Admin, register and Cloud Print security, POS Only products in the shop, phone screens, sync memory and SQLite; full list on [GitHub](https://github.com/wcpos/woocommerce-pos/releases/tag/v2.0.6).

= 2.0.5 - 2026/11/20 =

A manager can now move a sale to another cashier, and guest payments on the pay page work again on WooCommerce 11.3.1.

* Paying for a guest order on the pay page works again on WooCommerce 11.3.1, which dropped the payment once the order was over 10 minutes old.
* Adding a customer at the till no longer fails with "Missing parameter(s): username" or "password" when WooCommerce does not generate them itself.
* Checking out an order with a quick discount no longer fails with "pos-discount does not exist" when the till sends the order again.
* Sales no longer fail when another plugin's order data keeps changing; a till's edit to a custom order field now replaces one made in WP Admin.
* A returned item prints and is saved at its negative quantity and real price, not 0 at 0.00; if you edited a receipt template, test it with a return.
* New: a manager can move a sale to another cashier from the order screen in WP Admin, and a completed sale keeps a record of the change.
* Also fixes several rare sign-in and syncing problems; opt-in error reports to WCPOS now say what the store was doing; the full list is on [GitHub](https://github.com/wcpos/woocommerce-pos/releases/tag/v2.0.5).

= 2.0.4 - 2026/11/15 =

Receipts show tax as the order was charged, and the Plugins screen shows the upgrade notice as formatted text.

* Receipts show each item's tax with the name and rate the order was charged, even after the tax rate is renamed (WooCommerce Tax does this).
* The orange "Important" upgrade notice on the Plugins screen shows formatted text instead of raw HTML tags.

= 2.0.3 - 2026/11/13 =

Sales no longer fail because of other plugins' hidden order data, and the online shop loads faster on stores with many POS Only products.

* Sales no longer fail with "Checkout failed" when another plugin keeps updating its own hidden data on an order.
* A till no longer overwrites another plugin's hidden order data, such as a tracking number, with an older copy.
* Product lists in the online shop load faster on stores with tens of thousands of POS Only products.

= 2.0.2 - 2026/11/12 =

Online shop pages load faster on stores with many POS Only products, and the daily file check no longer raises a false alarm.

* Online shop pages load faster on stores with many POS Only products, which are now looked up once per page instead of once per product.
* The daily file check no longer reports a healthy install as modified when an update lands while the old code is still running.

= 2.0.0 - 2026/11/09 =

WCPOS 2.0 gives the POS its own checkout, with cash, split payments and card readers (Pro) at the till, and the till keeps taking cash when the connection drops.

It is a major update with an entirely new interface: [what's new](https://docs.wcpos.com/getting-started/whats-new). Cashiers will need time to learn it, so update when you have time, not during a busy shift.

Sync every till before you upgrade: 2.0 starts a new local database and does not carry over unsent 1.11 sales. Rolling back to 1.11 also starts empty, so sync before you roll back too.

The app and the plugin must both be on 2.0; a 1.11 app asks for an update when it meets a 2.0 plugin. WCPOS 2.0 needs WooCommerce 9.0 or later.

* Checkout opens beside the cart, and cash taken there lands in the register's count; card and other manual payment methods stay on the Legacy tab.
* Registers track the opening float, cash in and out, and the count at close; Free includes one register, and more registers need Pro.
* The cashier who opened a register session cannot approve its cash difference.
* POS Only products are hidden from the online shop; products already marked POS Only are changed once at upgrade.
* The experimental magnetic card reader is removed, and card terminal extensions (Stripe, SumUp and the others) need WCPOS Pro from 2.0.
* Developers: hooks are renamed `wcpos_*` (the old names still fire, deprecated) and the 1.x compatibility shims are removed.
* Also new: a Reports screen, saved closing reports and a customer display (Pro); the full list is on [GitHub](https://github.com/wcpos/woocommerce-pos/releases/tag/v2.0.0).

For testers coming from the 2.0 beta 3: a sale, refund or payment change saved while a register is closing is never lost from the books. Other beta fixes are on [GitHub](https://github.com/wcpos/woocommerce-pos/releases/tag/v2.0.0).

== Upgrade Notice ==

= 2.0.6 =
Coming from 1.11? WCPOS 2.0 is a major update with a new interface: update when you have time, not during a busy shift. Sync every till first: unsent 1.11 sales do not carry over. The app must be on 2.0 too, and 2.0 needs WooCommerce 9.0 or later. https://docs.wcpos.com/getting-started/whats-new
