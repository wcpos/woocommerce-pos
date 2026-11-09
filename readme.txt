=== WCPOS - Point of Sale (POS) plugin for WooCommerce ===
Contributors: kilbot
Tags: ecommerce, point-of-sale, pos, inventory, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

WCPOS is a point of sale (POS) for WooCommerce. Same stock online and at the till. Free, no transaction fees. Keep selling when the connection drops.

== Description ==

**WCPOS is a point of sale (POS) for WooCommerce.**

It runs in a web browser or as a Windows, macOS or Linux desktop app, with iOS and Android apps in beta. It sells from the same catalogue, stock and prices as your online store and records every sale as a WooCommerce order.

**Free:** ring up sales, take cash and cards on your own terminal, split a payment, run one register with its cash drawer, print receipts.

**Pro:** connected card readers, refunds, editing stock and prices, re-opening older orders, a customer display, more registers and stores.

WCPOS was called WooCommerce POS until November 2025.

Try it first at [demo.wcpos.com/pos](https://demo.wcpos.com/pos), login `demo` / `demo`.

**Desktop app** (Windows 10 or later, macOS 12 or later, Linux x64): [Windows](https://updates.wcpos.com/electron/download/win32-x64) · [Mac (Apple silicon)](https://updates.wcpos.com/electron/download/darwin-arm64) · [Mac (Intel)](https://updates.wcpos.com/electron/download/darwin-x64) · [Linux (.deb)](https://updates.wcpos.com/electron/download/linux-x64) · [Linux (.rpm)](https://updates.wcpos.com/electron/download/linux-x64-rpm)

**Mobile apps, in beta** (iOS and iPadOS 16.4 or later, Android 10 or later): [iPhone and iPad (TestFlight beta)](https://testflight.apple.com/join/JGBdVRrW) · [Android (Google Play beta)](https://play.google.com/apps/testing/com.wcpos.main)

= Free: a complete till =

**Ring up the sale**

* **Search or scan.** Find products by name or barcode, or tap a quick filter button.
* **Edit the cart.** Change quantities and prices, and discount a line or the whole order.
* **Coupons.** Apply your existing WooCommerce coupons in the cart.
* **Extras.** Add an unlisted product, a fee or shipping.
* **Customers.** Choose an existing customer, with their tax ID on the order and receipt.
* **Park a sale.** Set it aside and come back to it.

**Take the money**

* **Cash.** Use the keypad beside the cart and see the change as you type.
* **Card.** Record a card payment taken on your own terminal.
* **Split payments.** Split evenly, by amount, by percent or by item.

**Close the day**

* **One register.** Shared by every device on the counter, with a float and cash in and out.
* **Drawer count.** Count the drawer at close and file a numbered closing report.
* **Closing reports.** Reprint, recount or export them as CSV.
* **Today's report.** Sales and closures for today on this register, to print or export as CSV.

**Print and get help**

* **Receipts.** Print or email receipts, gift receipts, invoices, purchase orders and kitchen tickets.
* **Printers.** Thermal and cloud printers. See the hardware list below.
* **Help.** Press Support and the WCPOS Discord opens inside the POS.

= Works offline, on every device =

Each device keeps its own copy of the catalogue, customers and open orders. When the connection drops you keep searching and building carts, and sales and receipt emails send once the store is back.

* **Known sales.** A sale with an order number still takes cash or a card on your own terminal.
* **New sales.** A sale started offline waits for the connection before it is paid.
* **Offline badge.** Shown beside the store name, with each payment method marked as available or not.

= Your data, your WooCommerce =

Products, orders and customers stay in your own WooCommerce database. There is no second catalogue to keep in step.

* **No transaction fees.** WCPOS takes no cut of your sales.
* **No device charges.** The free plugin has no per-register or per-device charges.
* **Open source.** GPL-3.0, with the code on [GitHub](https://github.com/wcpos).

= Your hardware, your gateways =

* **Scanners.** The device camera, or a USB or Bluetooth barcode scanner.
* **Thermal printers.** 58 mm and 80 mm, over the network, Bluetooth or USB.
* **Cloud printers.** PrintNode, Star Online, Star CloudPRNT or Epson Server Direct Print.
* **Cash drawer.** Wire it to the receipt printer, turn on Auto-open, and it opens with each receipt.
* **Card terminal.** Take cards on the terminal you already have, and record them at the till.
* **With Pro.** Connect a card reader (listed under What Pro adds), or turn on your own WooCommerce gateways.

The POS comes in over 40 languages and ten themes, and the cart sits on the left or the right.

= What Pro adds =

[WCPOS Pro](https://wcpos.com/pro) adds the screens for running the store from the POS, and more ways to pay.

**Run the store from the POS**

* **Stock and prices.** Change them in the product list.
* **Customers.** Add new customers and edit existing ones.
* **Coupons screen.** Look up coupons and create new ones.
* **Older orders.** Find, re-open and edit them, and reprint their receipts.
* **Refunds.** Full or partial, back to the original method when the gateway supports it, or as cash.
* **Full reports.** Earlier days, every register and every store, and the closures history.

**Take more payments**

* **Card readers.** Stripe Terminal (smart and Bluetooth readers), SumUp, Mollie Terminal, Square Terminal, PayArc, Mercado Pago Point, PayPal Reader (Zettle).
* **Reader extensions.** Each reader is its own extension, installed from the POS.
* **Vipps MobilePay.** Customers pay by QR code or push payment.
* **Email Invoice.** Send a link the customer pays online.
* **Web Checkout.** The customer pays on your online store.
* **Your gateways.** Turn on your store's other WooCommerce gateways for the POS, on their own payment page.
* **Recorded payments.** Cash on delivery, bank transfer and cheque, recorded at the till.

**More registers, stores and extras**

* **More registers.** One per cash drawer, each with its own float, count and closing report.
* **More stores.** Several stores on one site, each with its own address, logo, tax IDs and cashiers.
* **Store templates.** Give each store its own list of receipt templates.
* **Stock per location.** Through ATUM Multi-Inventory.
* **Customer display.** A second screen in any browser, paired to the till with a code.
* **Extensions.** Install, activate and update them from the directory inside the POS.
* **Priority support.** On the WCPOS Discord, with your licence.

= Requirements =

* **WordPress** 5.6 or later (5.8 with Pro)
* **WooCommerce** 9.0 or later
* **PHP** 7.4 or later, PHP 8 recommended
* **Upgrading from 1.11?** Sync every till first. See the FAQ.

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

Pro is a standalone plugin, so once it is active you can deactivate and delete the free plugin.

= Manual installation =
1. Download the WCPOS plugin zip file.
2. Unzip it to get the plugin folder.
3. Upload the folder to `wp-content/plugins` on your server, by FTP or your host's file manager.
4. In WP Admin, go to Plugins and find WCPOS in the list.
5. Click Activate.

== Frequently Asked Questions ==

= Is WCPOS really free? =

Yes. The free plugin is a complete till, with no transaction fees and no charge per register or per device. You can browse the extension directory, but installing from it needs Pro.

You never need Pro to start selling.

= What does Pro cost? =

$129 a year, or $399 once for Lifetime. Each licence covers two sites.

Yearly includes one year of updates and priority support, and it does not renew by itself. When it ends, Pro keeps working and only new updates and priority support stop.

Prices as of November 2026, at [wcpos.com/pro](https://wcpos.com/pro).

= Is there a free trial of Pro? =

No. The free plugin is the trial, and every Pro screen in it has a View Demo button.

To try the POS before installing anything, open the demo at [demo.wcpos.com/pos](https://demo.wcpos.com/pos) and sign in with `demo` / `demo`.

= Do I need a separate catalogue or a second stock count? =

No. The POS sells from your WooCommerce products, stock and prices, and every sale is recorded as a WooCommerce order.

= Which devices does it run on? =

Any modern web browser, plus desktop and mobile apps. One login works on all of them.

* **Windows** 10 or later, 64-bit.
* **Mac** on macOS 12 or later, Apple silicon or Intel.
* **Linux**, 64-bit, as a .deb or .rpm package.
* **iPhone and iPad** on iOS 16.4 or later, in beta through TestFlight.
* **Android** 10 or later, in beta through Google Play.

= What happens when the internet goes down? =

The till keeps working from what is on the device.

* **Known sales.** Pay any sale with an order number in cash or on your own card terminal.
* **New sales.** A sale you start offline waits for the connection, then you take its payment.
* **Sending.** Sales and receipt emails send themselves when the store is back.
* **Offline badge.** Beside the store name, it tells you which state you are in.
* **Readers and gateways.** Connected card readers and online gateways need the connection.

= What hardware do I need? =

None to start. A device with a browser is enough.

For a counter, use the scanner, printer, cash drawer and card terminal you already have. There is no WCPOS hardware to buy.

* **Office printer.** HTML templates print through the system print dialog.
* **Cash drawer setup.** Turn on Auto-open cash drawer for its printer in Settings > Printing.

= How do I take card payments? =

On your own terminal with the free plugin, or on a connected reader with Pro.

On the free plugin, take the card on your terminal and press Card to record it. With Pro, each reader is its own extension, installed from the POS.

Pro also lets you turn on your store's other WooCommerce gateways for the POS. You are not tied to one card company.

= What does WCPOS not do? =

It does not run without WooCommerce, and it is not fiscally certified anywhere today.

* **WooCommerce only.** It needs a WooCommerce store.
* **No fiscal certification** in any country today. Fiscal compliance is the focus of WCPOS 2.1.
* **No kiosk mode** for customers to serve themselves.
* **No WCPOS hardware** to buy.
* **No offline-only sales.** A sale cannot start and finish entirely offline.

= I am on WCPOS 1.11. What do I do before upgrading? =

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

**To load the app**

* **CDN.** The POS app loads from cdn.wcpos.com, with jsDelivr as the fallback.
* **Signed.** The bundle is signed and checked before it runs.

**Usage and error reports, only if you opt in**

* **Consent.** A pop-up asks after you activate the plugin.
* **Your choice.** Turn them on or off under POS > Settings > General > Privacy.
* **Setup.** PHP and WooCommerce versions, country and currency.
* **Store size.** Approximate counts of products, orders and POS users.
* **Usage.** Which screens and features are used.
* **Errors.** Error reports, with file paths redacted.
* **Hostnames.** The bare hostname of the store and of wp-admin, never a page address.
* **Never sent.** Customer details, order contents or prices.
* **Not opted in?** Then the store sends none of it.

**To activate Pro**

* **Licence check.** Your licence key, site identifier (`site_uuid`) and anonymous identifier (`anon_id`) are checked with wcpos.com.

**The WCPOS Cloud Print relay, only if you use it**

* **Registration.** The store registers with cloudprint.wcpos.com the first time someone opens the Cloud Print settings.
* **Print jobs.** Jobs for relay printers pass through it, receipt contents included.
* **Never opened?** A store that never opens Cloud Print never contacts the relay.
* **Opt out.** Developers can turn the relay off with the `wcpos_cloud_print_relay_enabled` filter.

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

= 2.0.0 - 2026/11/09 =

**Sync every till before upgrading: 2.0 starts a new local database and does not carry over unsent 1.11 sales.** Rolling back to 1.11 also starts from an empty local database, so sync before you roll back too. The app and the plugin must both be on 2.0; a 1.11 app asks for an update when it meets a 2.0 plugin. WCPOS 2.0 needs WooCommerce 9.0 or later.

WCPOS 2.0 gives the POS its own checkout: cash, split payments and card readers handled at the register itself, with a cash drawer that opens and closes with each shift. Every screen is redrawn, and the till keeps taking cash when the connection drops.

- **A checkout that belongs to the POS.** Checkout opens in a tender pane beside the cart instead of sending every sale through WooCommerce's order-pay page. Cash is taken at the till, so it lands in the register's count; card and the other manual gateways keep the Legacy tab.
- **Split payments.** Take one order in several payments, for example part cash and part card, each recorded as it is taken.
- **Card readers connected straight to the POS (Pro).** Stripe Terminal and SumUp readers pair with the till over Bluetooth; Tap to Pay on iPhone and Android follows in an app update.
- **Registers and cash drawer sessions.** Opening float, cash in and out, a count at close and the difference against what was expected; set up in POS > Settings > General > Register. Free includes one register; more registers need Pro.
- **Closures are saved, numbered documents** you can reprint, recount and export as CSV from POS Settings.
- **Cash sales keep going when the connection drops.**
- **A new Reports screen** with a comparison period, a chart and cards for orders, products, taxes, refunds, payments, categories and cashiers.
- **A customer-facing display (Pro).** Open the display page on any device's browser and pair it to a till with a code.
- **A redesigned POS** with a Scale setting for compact, regular or spacious layouts, and a Fonts setting under Settings > Theme.
- **POS Only products are hidden from your online shop.** Marking a product POS Only sets its catalog visibility to Hidden; existing POS Only products are updated once at upgrade.
- **The POS loads from cdn.wcpos.com, with jsDelivr as the fallback.** The bundle is signed and checked before it runs.
- **The template gallery has Receipts, Reports, Closures, Display and Email tabs**, and receipt fonts download on demand.
- **The cashier who opened a session cannot approve its own variance**, and the Access screen can restore the default capabilities.
- **The experimental magnetic card reader is removed.** Card terminal extensions (Stripe, SumUp and the others) need WCPOS Pro from 2.0.
- Developers: sync protocol 3; every plugin hook is now named `wcpos_*` (the `woocommerce_pos_*` names still fire as deprecated aliases); the 1.x compatibility shims are removed; translations pinned to 2026.11.14; the vendored SQLite worker matches web-bundle v2.0.17.

Since beta 3, for testers on the 2.0 beta:

- **A sale, refund or payment change saved while a register is closing is never lost from the books.** It lands in the closure or in a correction to it; before, one that arrived mid-close could land in neither.
- **Large register sessions no longer run out of memory.** A session with thousands of sales could fail its X-report and expected figures on PHP's memory limit; the session's orders are now read one at a time.
- **The order-pay page shows the order's own currency symbol on the Cash and Card fields** and formats amounts with the POS store's money format.
- **Refund receipts add up (Pro).** When part of a refund is not tied to a payment, the remainder now prints as cash, so the receipt total matches the amount refunded.
- Beta 3's notes said translations were pinned to 2026.11.12; beta 3 shipped pinned to 2026.11.13.

= 1.11.9 - 2026/11/01 =

- **Fixed POS sales saving with no tax on stores that calculate tax by the customer shipping address** -- a regression in 1.11.8. A sale with no shipping address is taxed at the customer billing address, else at the shop base address, as WooCommerce does; the till showed the right tax all along. Orders saved by 1.11.8 with no tax are corrected with Recalculate on the order's edit screen.
- **PHP 8.5 no longer logs a deprecation notice on every POS request** from the bundled login-token library.
- Developers: the bundled Sentry error-reporting library is updated to 4.34.0.

= 1.11.8 - 2026/11/01 =

- **New sales start from WooCommerce's own "Calculate tax based on" setting** (shop base address, customer billing address or customer shipping address) instead of always the shop base address. A sale with no customer address is still taxed at the shop base address, as in WooCommerce.
- **The Extensions screen and its menu badge no longer announce an extension update that needs a newer WCPOS than this site runs.**
- **The refund window is titled with the order number, not the order's database id.**
- Developers: translations updated to 2026.11.0.

= 1.11.7 - 2026/10/28 =

- **Reading an order from the POS no longer saves it on stores using WooCommerce's High-Performance Order Storage.** The first read of a web or admin order could attribute it to the cashier who opened it and make the till's next change to that order fail as out of date.
- **A line added to a sale that already has a coupon re-applies the coupon**, so the coupon's discount matches the order total (a 10% coupon on a $50 sale stayed at 5.00 after a second $50 line).
- **A receipt printed for an order that still needs payment no longer shows a "PAID" row**; the Invoice template shows "Amount due" instead. The bundled Detailed Receipt template is updated.
- **Variations load correctly on WooCommerce 11.3.** A change in how WooCommerce 11.3 applies its on-sale filter dropped variations from the POS's catalogue read, and the till treated them as removed.
- **A sale is no longer marked paid when the store's payment check answers with an order that has no status.** The pay page's fallback check now waits for a real order status.
- **A receipt opened from the device's own data for an order that still needs payment no longer prints a "PAID" row.** Offline, or before the store's receipt data arrives, a pending or open order printed "PAID" and the Invoice template hid its "Amount due".
- **The loading screen's progress bar is readable on a device in dark mode.**
- Developers: the translation template (POT) is updated; Electron desktop runtime 43.8.1.

= 1.11.6 - 2026/10/21 =

- **Thermal receipts no longer break words in the middle** ("devolucione / s") when printed through the print dialog, as a PDF, or as a full-receipt image. Text now wraps at word boundaries, as it does on a receipt printer.
- **Receipts open sooner after payment on sites with a heavy theme.** The payment window's "paid" signal no longer waits for the theme's styles and scripts to load.
- **Saving a product barcode, a cloud-print order, a negative fee's tax or a receipt template no longer saves the record twice.** Each write now runs once inside WooCommerce's own save, so other plugins' save hooks fire once, and a cloud print problem no longer leaves an "Error during status transition" note on the order.
- **The receipt screen warns when what prints will differ from the template you chose.** A thermal template with no receipt printer prints its on-screen preview through the print dialog; an HTML template on a receipt printer prints the built-in layout; an HTML template on a Star CloudPRNT or Epson cloud printer prints nothing.
- **Automatic receipt printing no longer waits for the on-screen preview to load when the printer does not use it**: receipt printers, cloud printers and already-rendered templates.
- **Card terminals and other non-cash gateways open the receipt as soon as the payment completes**, instead of waiting for the order to sync.
- **A receipt that could not be downloaded in time is reported as that**, not as a printer that did not answer.
- **Desktop: Epson network printers on port 9100 start printing up to 16 s sooner.**
- **Guest sales on a store that taxes by shipping address are taxed at the till again.** With no shipping address the till now falls back to the billing address, then to the shop address, as WooCommerce does.
- **The login screen says the app needs updating when the store's plugin no longer supports it**, instead of offering "Re-authenticate" and "No stores found".
- **Logs: a catalogue change the store refused is always reported, and switching store mid-sync no longer leaves a row in the other store's log.**
- Translations updated to 2026.10.8.

= 1.11.5 - 2026/10/20 =

- **Web-store sales no longer re-save the product when they reduce stock.** This fixes a checkout error and a doubled stock deduction on bank-transfer orders that a few stores saw, and keeps product descriptions exactly as you wrote them.
- Developers: the `woocommerce_product_set_stock` / `woocommerce_variation_set_stock` listener and `Products::product_set_stock()` are removed; WooCommerce has bumped the product's modified date on every save since 3.4.0.

= 1.11.4 - 2026/10/20 =

- **A failed translation download no longer slows every POS and admin request.** It is retried after an hour instead of on every request -- stores whose host cannot reach the translation CDN were waiting minutes for the POS to load; English stores were not affected.
- **Checkout and refunds no longer stop working after the browser tab or desktop window wakes with sync requests still in flight.** Every later request to the store failed until the page was reloaded.
- **A completed order no longer logs "a change on this device clashed with an edit in your store".** The first retry of a save was reported as a conflict even though the order had saved.
- **Logs and toasts alarm only when there is something for you to do.** A retry the app handles itself is now an information row; a warning names the record, says what happened and links to where it is fixed.
- **A sale that times out while sending is reported as "still on its way", not as failed, so it is not entered twice.**
- **A refund whose result could not be confirmed says so and tells you to check the order before trying again.**
- **A storage error whose text happened to contain "409" or "CONFLICT" is no longer reported as a clash with your store.** The real error is shown instead.
- **Web: the first download is a fifth smaller.** Error reporting and date-format languages load only when they are needed.
- Translations updated to 2026.10.7.

= 1.11.3 - 2026/10/17 =

- **Couponed sales no longer come back as "different totals" on stores with tax-inclusive prices or compound tax rates.** Each discount and its tax are now rounded the way WooCommerce rounds them.
- **Sync no longer stalls silently when the store never answers a request.** A request left unanswered for a minute is given up and retried instead of holding its sync lane until the app is restarted.
- **The 1.11.1 memory fix now covers large catalogues and idle tills.** The sync's stock-existence checks kept every page of the product list in memory, and background re-syncs rewrote rows that had not changed.
- **Enter Demo Store works on web when the browser blocks popups.** The demo signed in through the fallback redirect, but the result was never picked up, so nothing opened.
- **iOS: the cart quantity no longer shows the previous value after a quick run of taps.** The order was right; only the field lagged.
- **Centred and right-aligned receipt text prints in the right place on Star CloudPRNT and ESC/POS cloud printers.** Text was aligned twice and landed right of the logo; the preview was already correct.
- **Retrying a payment after a declined attempt no longer leaves Process Payment spinning.** A gateway that showed the same error again was read as nothing new, so the button stayed busy for ten seconds.
- Developers: the native and desktop apps send an `X-WCPOS-Instance` header identifying the installation on every store request, web sends it once a per-site probe confirms the header passes, and the integrity drift-rebuild counter is kept per till by it (falling back to user and store), so several tills can no longer trigger a rebuild together; `X-WCPOS-Instance` joins the CORS allow list; translations updated to 2026.10.3.

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

Earlier releases (1.10 and older) are listed at https://github.com/wcpos/woocommerce-pos/releases

== Upgrade Notice ==

= 2.0.0 =
Sync every till before upgrading: 2.0 starts a new local database and does not carry over unsent 1.11 sales. WCPOS 2.0 needs WooCommerce 9.0 or later, and the app and the plugin must both be on 2.0.
