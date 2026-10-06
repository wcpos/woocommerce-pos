=== WCPOS - Point of Sale (POS) plugin for WooCommerce ===
Contributors: kilbot
Tags: ecommerce, point-of-sale, pos, inventory, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 1.10.22
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

= 1.10.19 - 2026/09/19 =

- **A till no longer loses live records during its startup tidy-up.** With the POS open twice on the same device, the tidy-up could mistake live records — including the sign-in row — for damaged ones and remove them. It now leaves alone anything it cannot fully account for. Web merchants receive this through WCPOS plugin 1.10.19, which serves the storage worker; desktop and phone apps carry it in this release.
- **A sale that could not be sent because the till was signed out now waits for sign-in instead of being given up on.** The cashier is told as soon as it happens, and the sale sends itself once the session is back.
- **A refused POS request now says why it was refused.** A 401 from the POS routes names the reason in the response and in Health > Logs, instead of only reporting that the request failed.

= 1.10.18 - 2026/09/18 =

- **A web till with the POS open in more than one tab repairs its local database again.** Since the 1.10 storage repairs shipped, every repair on web was refused whenever another tab of the same store could be open, so a damaged record stayed damaged and the same storage errors repeated on every sync. The tab that leads the store now owns the repair and the other tabs follow it. Web merchants receive this through WCPOS plugin 1.10.18, which serves the storage worker; desktop and phone apps carry it in this release.
- **Voiding an order the server had refused no longer fails silently.** When an order could not be created on the server (for example while the till was signed out) and the cashier then voided it, the void raised an error nothing caught and the order stayed in the cart. The till now removes the order and its failed request locally and confirms the removal; any other failure to void shows an error instead of nothing.
- **A till opened from WordPress admin no longer sends an invalid sign-in token on its first requests.** A request made before a token was stored carried the word "undefined" as its credential, which the server rejected as an invalid token instead of falling back to the WordPress login session. Such requests now carry no token, and a retry after a token refresh no longer keeps the old token in the request address.
- **A plugin update no longer restores cashier permissions the merchant had removed.** Every update re-granted every default capability, so a store that had turned off product or coupon editing for cashiers saw it come back after each release. Updates now grant only capabilities that are new since the last update; a deleted cashier role is still recreated whole.
- **Product search on the older `wcpos/v1` routes splits the typed text the same way as the rest of the POS.** Punctuation stays literal and single letters and common words count, so "IT 5012" no longer searches as "5012" and "0,4" is one term.
- **An order digest write no longer fails on a busy MariaDB.** The checkout-lane digest write now retries once on lock contention, as the product and customer digests already did.
- **The older order-update route checks order ownership the same way as the current one under HPOS.** A role that can edit its own orders but not other people's is treated the same on both routes. Cashiers are unaffected.

= 1.10.17 - 2026/09/17 =

- **A till no longer gets stuck at login on "Something went wrong: useStoreSession must be called within an active store session".** A damaged range in the till's local database made the login write fail and left the cashier on a red banner. The write is now repaired and retried, and if the saved session still cannot be honoured the till returns to the store list with a message instead of the banner. If the site has to be added again, it opens a fresh local database and does not pick up sales still waiting to sync in the old one. Web merchants receive the storage repair through WCPOS plugin 1.10.17, which serves the storage worker; desktop and phone apps carry it in this release.
- **A newly connected till no longer misses a stock change made in its first minute.** A product set out of stock on the server shortly after a new device, login or reset stayed "in stock" on the till indefinitely. The till now records the server's position before the first browse, so the change arrives with the next sync.
- **A variation whose stock is managed at product level shows the right stock in the variation picker.** When the parent product sold out, the picker's badge and Add to Cart button kept saying "in stock" for up to five minutes. They now read the parent's stock, the same way the cart does.
- **Switching stores no longer carries the previous store into the first requests of the new one.** Requests made while a switch was still completing used the outgoing store, so the first products or barcodes could belong to it until the next sync. Pro multi-store only.
- **Health > Logs records a screen error the app catches** as "Part of the screen failed to load", and reports it when you have allowed error reporting.

= 1.10.16 - 2026/09/16 =

- **Cashiers can sell an out-of-stock variation again when "Avoid overselling" is off.** The variation picker's Add to Cart button disabled on stock alone and ignored the store setting, so an out-of-stock simple product could be sold but an out-of-stock variation could not. The button now follows the same rule as the cart: it disables only while the setting is on and the variation is unsellable.
- **An idle web POS no longer sits at 30% CPU after the cashier stops.** Local database cleanup compacted storage fifty records at a time and rewrote every index between batches, so a till with a large backlog of deleted records churned for minutes. Cleanup now compacts in one batch and rewrites the indexes once. The web app loads its storage worker from this plugin, so web merchants receive this fix through this update.
- **Health > Database no longer shows "checking…" forever on a server that reports high load.** A host that stamps every response as under high load while answering in a tenth of a second kept the till backed off, and the backed-off checks always started with the same three record types, so the other six never got a turn. Checks now rotate fairly, all record types are probed on each backed-off tick, and a "high load" header on a fast reply no longer counts against the server.
- **Health > Performance says what pace the till is keeping with the server, and why.** One line at the top of "Your server, over time" reads Normal pace, Easing off (with the factor and the reason), or Paused until a given time.
- **Tills stop showing products the store has hidden from the POS.** Between 1.10.1 and 1.10.14 the POS product search could return a hidden product (fixed in 1.10.15), and a till that searched during that window kept a copy. Upgrading now writes a fresh removal notice for every hidden product and variation, and every till drops its stale copies on its next ordinary sync, with no reset or manual sync needed.
- **The POS Only and Online Only counts above the WooCommerce products list are accurate.** They counted trashed and auto-draft products that the view never lists; they now count the same statuses as WordPress's own "All" view.
- **The server no longer reports high load from a guessed CPU count.** On hosts that hide `/proc/cpuinfo`, the load average was divided by one CPU, so any load above 1.8 read as "high" forever and every till slowed its sync. When the CPU count is unknown, no load is reported.
- **The connection check can no longer be served from a page cache.** The public ping was answered before the cache-control headers were set, so a cached "ok" could mask an outage for the cache's lifetime. It now sends the same no-store headers as every other POS response.

= 1.10.15 - 2026/09/15 =

- **Tills no longer grow to gigabytes of memory over a long shift.** The product search index was keeping up to a hundred complete copies of itself in memory as it updated. Measured on a test store, memory after cleanup grew from 46 MB to 404 MB in an hour before the fix and from 47 MB to 160 MB after it; this is what took one merchant's till to 3.2 GB over an eleven-hour shift.
- **The Logs screen no longer freezes the app.**
- **Product search finds a product however you order the words.** Searching "blue shirt" and "shirt blue" now return the same results.
- **Start-up repair of a damaged local database is safer.** Oversized internal change logs are now bounded and recovered without unbounded reads, and the app no longer rewrites bookkeeping records that have not changed. The web app loads its storage worker from this plugin, so web merchants receive this fix through this update rather than through the app bundle.
- **A sync refresh that returns the same record twice is rejected** instead of being applied against an incomplete snapshot, which could previously prune records that were still present on the server.
- **Product search shows every match again.** When a search matched more products than fit on the first page, the Products page stopped at that page and scrolling to the end loaded nothing more.
- **The product search index repairs itself again.** When the app detected that the index had drifted from the product data, the rebuild silently did nothing, so stale or missing search results stayed that way until the app was reinstalled.

= 1.10.14 - 2026/09/14 =

- **Product search matches what you type as one phrase, in the order you typed it.** Searching for two words no longer also returns products with those words reversed, separated, or split between the title and the SKU. Partial and exact SKU and barcode matches work as before, and one- and two-character searches now match anywhere in a word.
- **The receipt template dropdown now lists templates in the order you set them**, for global templates and Pro per-store templates alike.
- **Switching the app language repeatedly no longer holds every previous product search index in memory.**
- **The WCPOS REST API index no longer errors when a client asks it for route help.** Only developers and integrations reading the API index were affected.

= 1.10.13 - 2026/09/13 =

- **Web: start-up repair of a damaged local database now applies each pending change to the row it belongs to**, rather than to that row's position, and refuses a stale change for a row already holding a newer entry. The desktop and mobile apps received this in 1.10.12; the web app loads its storage worker from this plugin, which still carried the previous copy.

= 1.10.12 - 2026/09/13 =

- **Cloud print and template reprints: a heading asked to be double-wide printed double-high.** The size command carried width and height the wrong way round. Square sizes are unaffected, which is why it went unseen for so long.
- **Cloud print: centred headings no longer overrun the paper.** Padding on a scaled heading was counted in characters rather than printed columns, so a centred double-width title was pushed right and wrapped onto a second line.
- **A product or order carrying an enormous meta value no longer takes down the request.** One store was losing about 22 requests an hour to a single record whose meta value serialised to roughly 1.25 GB. The value is now withheld rather than re-encoded, so the record loads instead of the till retrying it all day.
- **Star printers: centred headings print centred, and the time prints correctly.** Centring padding was emitted as spaces inside a magnified run, so each pad space was as wide as each glyph and a centred double-width heading wrapped. The narrow space in a short time no longer prints as a question mark.
- **Switching stores no longer tells the cashier to sign in again for the store they just opened.** A sign-in retry still in flight from the previous store could raise the message against the new one.
- **A store whose session has expired no longer records an error every minute.** The same failure was reported on every check because it was filed under a name the de-duplication never matched.
- **Start-up repair of a damaged local database matches each pending change to the row it belongs to**, rather than to that row's position, and refuses a stale change for a row already holding a newer entry.
- **Creating a virtual printer no longer fails intermittently with a certificate error.**

= 1.10.11 - 2026/09/11 =

- **The web app's local database can shrink again after it has grown large.** The clean-up that reclaims space failed on every attempt once the file was very large, so it only ever grew and the till could fail to start on it.
- **Rows zeroed by a power cut or an unfinished write no longer stop local database clean-up**; they are recognised as empty and dropped.
- **Stores behind Cloudflare: a security check during connection now reports HOST121 with a link to the Cloudflare guide**, instead of "Site does not seem to be a WordPress site".
- **Stores behind Cloudflare see a notice on the POS General settings screen** explaining which firewall rule to add so the POS is not blocked.
- **Firefox and Safari: cancelling a product search no longer records a SYNC321 error.**
- **A new order sent to WooCommerce no longer carries stale line ids**, which WooCommerce rejected with "invalid item id" when the order had already been acknowledged once.
- **Product search no longer loops or crawls on large catalogues.** A search with more than 100 matching products could re-request the same pages until the page was reloaded, and every scroll re-fetched the results from the start.
- **Search results are no longer capped at 100 products**; scrolling loads the rest, one page at a time.
- **Product search fills the grid faster on slow hosting**: one request per page replaces the previous escalating sequence of four.

= 1.10.10 - 2026/09/09 =

- **Security: cashiers can no longer change the email or password of administrator or other staff accounts** through the POS. POS users can only edit customer accounts.
- **Users with more than one role (for example administrator plus customer through the Members plugin) no longer get "forbidden" on every product load and sale**, and when access really is denied the error names the missing capability.
- **Stores using WooCommerce Tax (TaxJar) keep stable tax-rate IDs on POS saves**, so county and city rates no longer swap places on the order.
- **Stores using WooCommerce Tax with tax based on the store address send the store's street to TaxJar**, not the customer's, when a customer shares the store's location.
- **An installed Windows printer no longer hides behind the "no printers yet" screen**, and generic Bluetooth printer profiles set up correctly on iOS and Android.
- **Tapping the cart quantity on iOS and Android selects the number so typing replaces it**, and two-digit quantities no longer clip.
- **Fewer stalls on iPad while the till is idle**: the background variation check runs as one query instead of one per product.
- **The web app repairs a damaged local bookkeeping file** instead of logging the same storage error on every sync.
- **Faster, safer local saves on iOS and Android**: the storage swap file is copied natively and an interrupted write is recovered on the next open.
- **The iOS and Android apps report crashes when telemetry is enabled.**
- Updated translations.

Earlier releases (1.10.0 – 1.10.9 and the 1.9 series) are listed at https://github.com/wcpos/woocommerce-pos/releases
