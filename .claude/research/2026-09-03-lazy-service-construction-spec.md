# Spec: construct POS services only on the requests that need them

**Status:** proposal, not started. **Owner:** Paul to approve before anyone builds it.
**Data:** `2026-09-03-online-store-footprint.md` (same directory) and the Codex inventory summarised below.

## Why

After PRs #1841, #1842 and the i18n/settings PR, the plugin's *query* cost on a storefront page is zero, but every storefront request still loads ~80 plugin files (1.2 MB of PHP) and constructs 22 objects in `Init::init_common()` / `init_frontend()` / `init_integrations()`. On dev-next that was the unexplained 15–35 ms between "plugin off" and "plugin on" once callbacks were accounted for. It is the last structural cost, and it is also the part with the most regression risk, which is why it is a spec and not a PR.

## Inventory (from a read-only audit of `includes/Init.php:449-517` and each class)

Legend: **SF** = plain storefront GET (shop / product / cart, no POS marker, not admin, not REST). **SC** = online checkout (Store API POST or classic checkout) and the order-event lanes that follow it (payment complete, status changes, webhooks, cron).

### Must stay eager on SF (4)

| Class | Why |
|---|---|
| `i18n` | Loads the storefront translation file (POS order-status labels on My Account, receipt shortcode strings). Already cheap: no queries on SF after the i18n PR. |
| `Products` | `pre_get_posts` / `woocommerce_variation_is_visible` hide POS-only products from the shop; the `floatval` stock coercion applies to the online store by design (`Products.php:43-52`). |
| `Storefront_Receipts` | Public `[wcpos_receipt]` shortcode and the My Account receipt action are storefront features. |
| `Integrations\WPSEO` (when Yoast is active) | Keeps the REST API link the app's site-discovery probe reads from an ordinary page (`Init::send_headers` docblock). |

### Needed on SC / order-event lanes, not on SF (7)

`Orders` (registers `wc-pos-open` / `wc-pos-partial` statuses — needed wherever an order can be *read*, see below), `Emails`, `Receipt_Snapshot_Store` (`woocommerce_payment_complete`, not POS-gated), `Stock_Validator` (`woocommerce_query_for_reserved_stock`: POS drafts must reduce online availability), `Cloud_Print_Trigger_Service` / `Print_Job_Service` / `Cloud_Print_Relay_Service` (online and `every` print assignments), `Gateways` (gateway class registration; availability override is POS-gated).

### POS / admin / REST / cron only (11)

`Auth` instance touch (already constructed by the global auth filter), `Extensions`, `Templates` (CPT + taxonomies; receipts, admin, cloud print), `Decimal_Quantities` (REST schema relax, POS-marked routes only), `Customer_Meta_Parity` (customer REST), `Cloud_Print_Submit_Service` (cron), `Template_Router` / `Form_Handler` (only act on matched POS routes), `WePOS` (admin notice), `Settings` instance touch (constructor is empty; sections load on demand).

## Design

**One request classifier, computed once, filterable.** `Request_Context::lane()` returning one of `storefront`, `checkout`, `pos`, `admin`, `rest`, `cron`, `cli`. Built from the same signals `i18n::is_maintenance_request()` uses (`is_admin()`, `wp_doing_cron()`, `wp_doing_ajax()`, `WP_CLI`, `woocommerce_pos_request()`, the REST prefix in `REQUEST_URI` because `REST_REQUEST` is not defined at `init`), plus WooCommerce's own signals for the checkout lane (`is_checkout()` is not available at `init`; use the `wc-ajax`, `wc/store` and `?wc-api=` markers and the classic checkout POST). Anything not positively classified is `storefront`. A filter `woocommerce_pos_request_lane` lets a host force a lane.

**Construct by lane, not by class list.** `init_common()` becomes three groups: `always`, `order_lanes` (constructed when lane ∈ {checkout, pos, admin, rest, cron, cli}), `pos_lanes` (pos, admin, rest, cron, cli). The storefront lane constructs only the `always` group.

**Order-event hooks are the hard part.** WooCommerce fires order hooks from places the classifier cannot see at `init`: a webhook delivery (`wc-api`), Action Scheduler running inside a storefront request (`WP_CRON` spawned via `spawn_cron` on a page view), a third-party plugin creating an order on `template_redirect`. The `order_lanes` group therefore must also be constructable *late*: `Init::ensure_order_services()` is idempotent and is hooked on the earliest generic order signal that fires before any WCPOS observer would need to run — `woocommerce_before_order_object_save` (priority 0) and `woocommerce_new_order` (priority 0). That guarantees `Orders`, `Emails`, `Stock_Validator`, receipt and cloud-print services exist before any order is written on any lane, at the cost of one `did_action` check per save.

**Status registration is not lazy.** `Orders::register_order_status()` registers `wc-pos-open` / `wc-pos-partial` with `register_post_status()`. Any lane that *reads* an order (My Account order list on SF, admin lists, REST) needs those statuses registered or WooCommerce mislabels them. Split that call out of `Orders` into a tiny `Order_Statuses` that stays eager; the rest of `Orders` (the payment/tax/coupon filters, all POS-gated) moves to `order_lanes`.

**`Templates` stays out of SF entirely.** Its CPT/taxonomy registration is only needed where templates are listed, edited, rendered or printed. The `Storefront_Receipts` shortcode renders a receipt on SF — so the shortcode handler constructs `Templates` itself when it runs, and `Storefront_Receipts` stays eager.

## Hook-parity and ordering constraints (ADR 0035, `Init` ordering table)

- The `Init` constructor's `plugins_loaded` wiring (rows 1–26 of the ordering table) is untouched: sync observers, auth, CORS and audit guards stay unconditional. This spec only touches `init_common` / `init_frontend` / `init_integrations`, i.e. what runs on `init`.
- `Test_Hook_Parity` (ADR 0035) asserts which hooks fire for POS vs core order writes. Late construction on `woocommerce_before_order_object_save@0` changes *when* `Orders`/`Emails` register their filters, not whether they fire for the write — but the parity test must be run on every lane, and a new test must assert that a storefront request that spawns an order (an Action Scheduler action creating an order inside a page view) still gets the full observer set.
- `Test_Init_Hook_Wiring` pins the latched hook set; the lazy groups add no latched hooks, but the golden may need the `woocommerce_before_order_object_save@0` / `woocommerce_new_order@0` ensure-hooks added as unconditional.

## Measure before and after

The probe and batteries in this directory. Acceptance: on the free lane, storefront GET plugin files loaded from ~80 to under 25 and plain-vs-off delta within run noise; the realistic checkout unchanged in queries and journal rows (the observers are in the `plugins_loaded` wiring, not here); `Test_Hook_Parity`, the sync suite and the full suite green; and a Store API checkout with an `online` cloud-print assignment still creates a print job.

## Risks called out

1. **A lane misclassified as storefront drops order services.** Mitigated by the late `ensure_order_services()` hooks, which make the classifier an optimisation rather than a correctness gate. This is the invariant to test hardest.
2. **Pro constructs its own services from `Init::init` at priority 20** (`woocommerce-pos-pro/includes/Init.php`). Pro must adopt the same lane groups or it will reintroduce the eager cost on Pro sites; coordinate as a companion PR.
3. **Third-party code that calls `Templates`/`Orders` statics on SF** would fatal if the class is not loaded. Autoloading still works (classes load on reference); only *construction* is skipped, so static calls are safe. Instance-dependent behaviour (a filter that expected `Orders`'s coupon context) is the thing to grep for across extensions before building.

## Estimate

Two to three days including the Pro companion, the lane classifier tests, the late-construction tests and a live re-measure. Not an evening.
