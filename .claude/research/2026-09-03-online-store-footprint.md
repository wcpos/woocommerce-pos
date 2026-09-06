# How much WCPOS code runs on the online store?

**Date:** 2026-09-03 · **Site:** dev-next.wcpos.com (Pro 1.10.7 with vendored free, WooCommerce 11.0.1, Twenty Twenty-Five block theme, HPOS, no persistent object cache) · **Status:** measured, no code changed.

## Question

The dev servers have slow-query and trace instrumentation, but nobody had ever browsed the online shop or placed an online order while measuring what the POS plugin does to that request. The expectation: a shopper browsing products or checking out online should exercise almost none of the POS code.

## Method

A temporary mu-plugin (`wcpos-footprint-probe.php`, alongside this note) was dropped into the shared `/data/wordpress/mu-plugins/` mount, gated on the dev-next host **and** a secret request header so it is inert for every other request and site. When armed it:

- walks `$wp_filter` at several stages (`plugins_loaded` at `-PHP_INT_MAX`, so `Activator::init` at 10 is caught; `init` at both ends; `wp_loaded`, `template_redirect`, `rest_api_init`, the checkout/order-save hooks) and replaces every callback whose defining file lives under `wp-content/plugins/woocommerce-pos*/` with a wrapper that records calls, exclusive/inclusive wall time and the `$wpdb` queries (with SQL, via `SAVEQUERIES`) it ran;
- supports three modes via a second header: `on` (wrap and attribute), `plain` (plugin active, nothing wrapped, for a clean A/B), `off` (filters `option_active_plugins` to unload both POS plugins for that request);
- appends one JSON line per request to `/tmp` inside the PHP container. Nothing touches the docroot.

`footprint-battery.sh` drove it: 5 iterations × 3 modes of `/`, `/shop/`, `/product/wcpos-e2e-simple/`, `/cart/`, then 3 iterations × 3 modes of a guest Store API checkout (`GET cart` → `POST cart/add-item` → `POST checkout` with COD, which was enabled for the run and disabled after). Probe, orders and log were removed afterwards.

## Results (medians, server-side)

### Storefront pages

| Request | Plugin off | Plugin on (plain) | Δ ms | Queries off → on | POS callbacks fired | POS files loaded |
|---|---|---|---|---|---|---|
| `GET /` | 180 ms / 51 q | 216 ms / 81 q | +36 | +30 | 17 | 98 (1.2 MB) |
| `GET /shop/` | 184 ms / 60 q | 218 ms / 90 q | +34 | +30 | 17 | 98 |
| `GET /product/…` | 191 ms / 56 q | 208 ms / 86 q | +17 | +30 | 17 | 98 |
| `GET /cart/` | 193 ms / 51 q | 208 ms / 81 q | +15 | +30 | 17 | 98 |
| `GET /wc/store/v1/cart` | 133 ms / 32 q | 148 ms / 61 q | +15 | +29 | 26 | 109 |
| `POST …/cart/add-item` | 150 ms / 58 q | 148 ms / 87 q | ~0 | +29 | 26 | 110 |

Time attributed inside the wrapped callbacks was 11–13 ms per request; the rest of the delta is loading 98–110 plugin files and constructing ~18 service objects in `Init::init_common()`. Memory: +0–2 MB peak.

### Online checkout (`POST /wc/store/v1/checkout`, order actually created)

| Mode | Server ms | Queries | POS-attributed | Journal rows written |
|---|---|---|---|---|
| off | 927–1191 | 540–549 | – | 0 |
| plain | 1089–1164 | 636–638 | – | 12 |
| on | 1156–1425 | 636–648 | 91–121 ms, 104 q, 47 callbacks / 107 calls | 12 |

So the plugin adds roughly **17 % more queries and ~100 ms** to an online checkout, and writes **12 sync-journal rows for one order**. The order itself is untouched: no `_pos_*`/uuid meta was stamped, status and totals matched the control.

### Realistic online checkout (added later the same night)

The first checkout above used a product without stock management, no coupon and no account. `footprint-battery2.sh` (alongside this note) repeats it with a stock-managed product ×2, a 10 % coupon and `create_account: true`, still COD, 3 iterations per mode. Pro lane:

| Mode | Server ms (median) | Queries | POS-attributed |
|---|---|---|---|
| off | 1420 | 808 | – |
| plain | 1751 | 941 | – |
| on | 1738 | 941 | 171 ms · 142 q · 66 callbacks / 138 calls |

So on a realistic order the plugin adds **~330 ms (+23 %) and 133 queries**. Breakdown of the attributed 171 ms: order journal ×11 = 66 ms/45 q; order digest ×11 = 35 ms/22 q; order create journal+digest = 13 ms; seven customer journal/digest writes for the new account ≈ 25 ms/20 q; product stock journal + digest + `Products::product_set_stock` (which does a `wp_posts` UPDATE to bump `post_modified`) ≈ 9 ms; Init loaders ≈ 11 ms. The remaining ~150 ms of the plain-vs-off delta is not inside wrapped callbacks (plugin file loading and service construction; within run-to-run variance of ±100 ms). A WooCommerce fact surfaced by the journal rows: the Store API saves the order as a `checkout-draft` **eight times before `woocommerce_new_order` fires**, so the 12 rows were 8 × update, create, 3 × update.

The same battery on the **free** lane (dev-next's lane-variant header): off 1379 ms / 808 q, plain 1551 ms / 935 q → +173 ms / +127 q, POS-attributed 131 ms / 136 q.

Every functional filter that fired on the checkout was also read for its gate: all check `woocommerce_pos_request()`, `woocommerce_pos_is_pos_order()` or the prevent-overselling setting, and the POS gateways are hard-coded `enabled = 'no'`. The interference is cost, not behavior.

### Fix applied for the checkout path (PR #1841, `fix/journal-order-write-coalescing`)

`Sync_Journal::record_order_updated` now only marks the order dirty and keeps the hook's order object; one `hook:update` row lands on `flush_pending_order_updates()` at `shutdown` (last, after WooCommerce's own customer/session saves), before any other-origin row for that order, or when a different order is saved; a `hook:create` row drops the owed update. `Integrity_Digest` order/customer upserts are coalesced likewise (bounded at 50 distinct records) and flushed at `shutdown` or before any `Digest_Index::read_digests()`. Hotpatched onto the dev-next free lane and re-measured with the same battery:

| Free lane, realistic checkout | off | before | after |
|---|---|---|---|
| server ms | 1379 | 1551 (+173) | 1393 (+67) |
| queries | 808 | 935 (+127) | 867 (+59) |
| POS-attributed | – | 131 ms / 136 q | 57 ms / 60 q |
| journal rows per order | – | 12 | 3 (2 with the create-drops-update rule that landed after this run) |
| per-save observer cost ×11 | – | ~9 ms / 6 q each | 0.03 ms / 0 q each |

The hotpatch was reverted afterwards (dev-next back on `main`); 351 journal rows for the deleted probe orders remain for the purge cron to sweep. What remains per checkout is the create row, the customer create rows, the product stock journal/digest and `Products::product_set_stock`'s `wp_posts` UPDATE.

### Per-request fixes (PRs #1842 and the i18n/settings PR)

Measured on **dev-free** (free plugin on `main`, French locale, 5 × 4 storefront pages, cache-busted — dev-free fronts anonymous GETs with a 60 s page cache, so a unique query string per request is required or the probe sees only uncacheable pages). Plugin-attributed queries per storefront page:

| state | plugin queries | `Init::init` | `Activator::init` | page queries on vs off |
|---|---|---|---|---|
| `main` after #1842 (latches not yet flipped on this site) | 10–12 | 7 | 3 | 62 vs 52 |
| + i18n storefront path, `general`/`visibility`/permalink autoloaded | 4 | 0 | 3 | 55 vs 52 |
| + the upgrade-time latch flip | **1** | 0 | 0 | **52 vs 52** |

The last query is WooCommerce's own `wc_installing` transient read inside `FeaturesUtil::declare_compatibility()`. Two facts surfaced on the way: an **absent** option (the permalink row on a store that never set a slug) costs a query on every request just like a non-autoloaded one, because notoptions is per-request; and `visibility` settings are read on every storefront query by the product-hiding filter, not only by POS requests.

What remains structurally is the ~80 files and 22 objects a storefront page constructs; `2026-09-03-lazy-service-construction-spec.md` is the proposal.

**Incident while measuring (2026-09-03 ~11:02–11:08):** deploying a second, host-gated copy of the probe mu-plugin beside the first fatalled every site sharing `/data/wordpress/mu-plugins/` — PHP hoists the class declaration regardless of the early host-mismatch return. One copy, host allowlist inside, and curl every site after touching that directory.

## Where the cost is

**Every request (31 queries, ~11 ms) — all from eager loading in `Init`:**

1. `Templates::register_taxonomy()` (`includes/Templates.php:142`, constructed in `init_common`) calls `register_default_template_types()` and `register_default_template_categories()` on **every** request. Nine `term_exists()` checks → **18 queries** (`wcpos_template_type` × 2, `wcpos_template_category` × 7). This is one-time seeding work running per request; it belongs on activation/upgrade, or behind a latched option, or at minimum `is_admin() || woocommerce_pos_request()`.
2. Non-autoloaded options read on every request, one query each because there is no object cache: `woocommerce_pos_settings_general`, `woocommerce_pos_settings_permalink`, `wcpos_sync_schema_version`, `woocommerce_pos_sync_visibility_tombstone_seed`, `woocommerce_pos_sync_config_fingerprint_cleanup_version` (+ `woocommerce_pos_pro_settings_license` in Pro). All are small latches/settings read on every load → they should be `autoload = yes`.
3. `i18n::load_translations()` reads three transients per plugin per request (`_active_path`, `_<locale>`, `_missing_<locale>`) — 6 queries across free + Pro. The "missing" one is a `SELECT autoload …` miss every time because notoptions isn't persistent. One combined autoloaded option, or skip on front-end requests where no POS strings are rendered.
4. Pro's `before_woocommerce_init` closure reads the `wc_installing` transient (1 query).

Everything else that fires on a storefront GET (`Form_Handler::pay_action`, `Template_Router::template_redirect`, `Init::send_headers`, `remove_x_frame_options`, `query_vars`, `option_rewrite_rules`) bails in microseconds — that part is as it should be.

**Online checkout (+95 queries, ~100 ms) — the sync observers:**

5. `Sync_Journal::record_order_updated` fires **11×** on `woocommerce_update_order` during one Store API checkout (create → addresses → totals → payment → status → stock each save). Each call does `wc_get_order()` (3 fresh queries, even though the hook passes the object) and an `INSERT` into `wp_wcpos_sync_journal` (~3 ms each) → 45 queries, 51 ms, and 12 rows for one order (the purge later deletes them). Journaling the online order is correct (the POS must see it); the multiplicity is not — coalesce per request (mark dirty, write once on `shutdown` or after the last save) and reuse the passed order object.
6. `Integrity_Digest::record_order_saved` fires the same 11×: `SET SESSION group_concat_max_len` + `INSERT … SELECT` digest upsert each time → 22 queries, 17 ms. Same coalescing fix.
7. `Sync_Journal::record_order_created` + `Integrity_Digest::record_order_saved` on `woocommerce_new_order`: 6 queries, 7 ms — fine, that's the one write we want.

The remaining ~40 POS callbacks on the checkout path (`Orders::*`, `Gateways::*`, `Emails::*`, `Cloud_Print_Trigger_Service::handle_order`, `Decimal_Quantities::relax_quantity_schemas`, `Core_Order_Audit_Guard`, `Rest_Cors`) each cost < 0.2 ms and 0 queries — they run, check `created_via`/context, and leave.

## Verdict

The POS does not change what the online store does — no order fields, meta, stock or status differ with the plugin on. But "almost none of the POS code runs" is not true today: every storefront hit loads ~100 plugin files, constructs the full service graph and spends 31 queries on seeding and un-autoloaded options; every online order pays ~95 extra queries because two observers write on every intermediate save instead of once. On a host with Redis/Memcached items 2–4 vanish; items 1, 5 and 6 do not.

## Suggested follow-ups (status as of the evening re-measure below)

- ✅ Move `Templates` default-term seeding to activation/upgrade behind a version latch; keep `register_taxonomy()` itself cheap. — #1842
- ✅ Coalesce `Sync_Journal`/`Integrity_Digest` order writes to one per request. — #1841 (orders, customers digests), #1854 (product digests)
- ✅ Autoload the per-request latch options; collapse the i18n transients. — #1846, #1849
- ✅ Defer `init_common()` service construction that is only meaningful for POS/admin/REST requests. — #1853 (free), Pro #519
- ⬜ Add this battery to `wcpos-wordpress/scripts/perf` as an online-store scenario so the numbers become a regression bar. Not done; `tests/includes/Sync/Test_Online_Checkout_Journal_Shape.php` (#1851) pins the journal/digest shape of an online checkout in the PHP suite instead.

## Cleanup performed

Probe mu-plugin removed from `/data/wordpress/mu-plugins/`, `/tmp/wcpos-footprint.jsonl` deleted, COD gateway disabled again, the nine probe orders (98046–98054, `created_via=store-api`, customer note "WCPOS footprint probe") force-deleted.

## Live re-measure after the merges (2026-09-03, evening)

Same probe, same batteries, against the deployed build: free `main` at #1854 (#1841, #1842, #1846, #1849, #1851, #1853, #1854) on **dev-free**, and Pro `main` at #519 with that free vendored on **dev-pro** (both via Pro's deploy-dev). dev-next is the `next`-lane target and is not deployed by deploy-dev; it still ran the morning's build and reproduced the baseline numbers exactly (30 plugin queries, 101 files, 21 callbacks), so it is left out below.

### Storefront pages (medians of 5)

| Site | Request | Queries off → on | POS queries | POS files | POS callbacks fired | POS ms |
|---|---|---|---|---|---|---|
| dev-free (free) | `GET /` | 52 → 53 | 2 | 66 | 8 | 1.7 |
| dev-free (free) | `GET /shop/` | 62 → 64 | 3 | 66 | 8 | 2.0 |
| dev-free (free) | `GET /product/…` | 48 → 50 | 3 | 66 | 8 | 2.6 |
| dev-pro (Pro) | `GET /` | 56 → 62 | 6 | 82 | 13 | 3.4 |
| dev-pro (Pro) | `GET /shop/` | 66 → 73 | 7 | 82 | 13 | 3.5 |
| dev-pro (Pro) | `GET /product/…` | 61 → 68 | 7 | 82 | 13 | 3.9 |

Baseline (morning, Pro lane on dev-next): +30 queries, 98–101 files, 17–21 callbacks, 12–19 ms per page.

What the remaining queries are:

- **dev-free**: WooCommerce's own `wc_installing` transient read on `before_woocommerce_init` (1); the visibility settings row on shop/product pages (1, autoload deliberately off — it can hold unbounded id lists); and one i18n `_active_path` transient read that only happens when BOTH the primary and the uploads translation files exist (designed: the active uploads copy beats a stale primary copy; dev-free is in that transitional state after the day's hotpatches — ordinary sites have one file or the other).
- **dev-pro**: the same, plus five rows the upgrade-time flip (`Activator::autoload_request_latches()`) has not run for on this site — `woocommerce_pos_settings_general`, `_permalink` and the three sync latches — because deploy-dev ships the same version number and the flip runs on `db_upgrade()` / activation. Merchants get it on the next release. Plus Pro's `woocommerce_pos_pro_settings_license`, which Pro's License section writes with autoload off and the flip could not reach (it derived the key from `id()`, and the License key is Pro-prefixed). Fixed in the PR that carries this note (free: the flip asks the section for its key) and its Pro companion (License section declares `autoload()`).

### Realistic online checkout (dev-pro, medians of 3, stock-managed product ×2, coupon, account created, COD)

| Mode | Server ms | Queries | POS-attributed |
|---|---|---|---|
| off | 1421 | 840 | – |
| on | 1898 | 876 | 53 ms · 39 q · 70 callbacks |

Baseline Pro lane: 941 queries (+133), 171 ms · 142 q · 66 callbacks. Server milliseconds on this box are noise at this level (the `plain` run was 1988 ms, slower than `on`); queries are the reliable axis: **+36 against +133**.

Journal rows per online order: create + 1–2 coalesced updates (2–3 rows; was 12). Product digest: one write per purchased product (was 2). Of the 39 attributed queries, ~15 come from creating the customer account: `user_register`, `woocommerce_created_customer`, `profile_update` and the role hooks each write their own customer journal row (4 rows per new customer). Customer journal rows are not coalesced — left as is: it happens once per new customer, and WooCommerce's own customer save on `shutdown` is exactly the ordering edge the digest coalescing already has to work around. The remaining ~10 are the `init`-time option reads listed above and the stock-change observers.

### Cleanup performed

Probe mu-plugin removed (0 copies left), the three containers' `/tmp/wcpos-footprint.jsonl` deleted, the nine dev-pro probe orders, nine probe customers, the probe product and coupon deleted; all four sites verified 200 afterwards. dev-next was not touched.
