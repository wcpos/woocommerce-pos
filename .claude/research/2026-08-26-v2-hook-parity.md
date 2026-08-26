# WooCommerce hook parity of the WCPOS v2 API

Research for [#1735](https://github.com/wcpos/woocommerce-pos/issues/1735) (part of #1731).
Date: 2026-08-26. Investigation only — no production code changed, no tests run.

Baseline derived from **WooCommerce 10.4.3 source read locally**
(`/Users/kilbot/.wp-env/wp-env-woocommerce-pos-cf9da277/woocommerce`, the version pinned in
`.wp-env.json`) and **WordPress core** (`.../WordPress/wp-includes/rest-api*`). Entries derived
from knowledge rather than source are marked `[K]`; everything else has a file:line citation.

---

## 0. Executive verdict

**v2 is close to parity on writes and on list reads, and materially short of parity on the
sync-only read lanes and on checkout.**

The single most important structural fact, and it is good news: **every v2 write and every v2
list read is a real `rest_do_request()` into the stock `wc/v3` controller**
(`includes/API/V2/Write_Controller.php:804`, `:893`;
`includes/API/V2/Catalog_Proxy_Controller.php:86`). An internal `rest_do_request()` runs
`WP_REST_Server::dispatch()`, which fires `rest_pre_dispatch`, `rest_request_before_callbacks`,
`rest_dispatch_request` and `rest_request_after_callbacks`
(`wp-includes/rest-api.php:605-608`; `class-wp-rest-server.php:1079,1256,1287,1318`) and then
the controller's own `woocommerce_rest_pre_insert_*`, `woocommerce_rest_insert_*`,
`woocommerce_rest_delete_*`, `woocommerce_rest_{type}_object_query` and
`woocommerce_rest_prepare_*` hooks, plus every `WC_Data::save()` hook beneath them. So ATUM,
Smart Coupons and Product Add-ons see a v2 push as an ordinary `wc/v3` write.

Where v2 breaks parity is the lanes it built itself:

1. `/wcpos/v2/orders/pull`, `/changes`, `/resolve`, `/digests` and the `variations?include=`
   lane query the database with **raw SQL** and never build a `WP_Query`/`WC_Order_Query`, so
   `woocommerce_rest_shop_order_object_query`, `woocommerce_rest_product_object_query` and
   `pre_get_posts` never fire on them.
2. The **integrity digest** is computed by `INSERT…SELECT` over `postmeta`/`wc_orders`
   (`includes/Sync/Integrity_Digest.php:388-500`), not over the filtered REST payload, so a
   third party that only changes the *serialized* representation cannot invalidate a cached POS
   document.
3. **Checkout** (`includes/API/V2/Checkout_Controller.php` → `V1\Checkout_Controller` →
   `Payments\Gateway_Contract`) never calls `WC_Payment_Gateway::process_payment()`. A
   third-party gateway is invoked only if it opts into the proprietary
   `wcpos_payment_gateway_*` filter contract.

No hook v1 fired has been silently dropped by v2. Two things did change shape: the
`rest_post_dispatch` filter no longer sees a wc/v3-shaped controller run (v1's controllers
**extend** the wc/v3 controllers and are dispatched directly), and WCPOS's own payload
augmentations moved from *inside* `woocommerce_rest_prepare_{type}_object` at priority 10 to
*after* it — so a third-party filter that used to have the last word on the POS lane no longer
does (§3.1). v2 is also strictly better hygiene than v1: v1 leaked most of its per-request
hooks for the rest of the request, including an unremoved
`woocommerce_rest_check_permissions` grant (`V1/Coupons_Controller.php:93`), while v2 unwinds
every request-scoped hook in a `finally`.

---

## 1. How each v2 surface reaches WooCommerce

| Surface | File | Mechanism | Real controller? |
|---|---|---|---|
| `POST /wcpos/v2/push/{collection}` (create/update/delete) | `includes/API/V2/Write_Controller.php:777-810` | `rest_do_request()` into `wc/v3` | **Yes — full dispatch** |
| Write ack re-read | `Write_Controller.php:885-901` | `rest_do_request( GET wc/v3/{route}/{id} )` | **Yes — full dispatch** |
| Variation write ack document | `Writers/Variation_Writer.php:75-93` | `Product_Serializer::serialize()` | Controller object, **no dispatch** |
| `GET /wcpos/v2/{products,orders,customers,coupons,taxes,terms}` list | `Catalog_Proxy_Controller.php:74-96` | `rest_do_request()` into `wc/v3` | **Yes — full dispatch** |
| `GET /wcpos/v2/variations` (collection/search lanes) | `Variations_Controller.php:50,98-231` | `extends WC_REST_Product_Variations_Controller`, `parent::prepare_objects_query()` | **Yes (query)**, serialization via `Product_Serializer` |
| `GET /wcpos/v2/variations?include=` | `Variations_Controller.php:262-319` | `wc_get_product()` per id, no query | **No query at all** |
| `GET /wcpos/v2/orders/pull` | `Orders_Controller.php:102-186` + `Sync/Order_Query.php:68-98` | raw `$wpdb` over `wc_orders` / `wp_posts` | **No** |
| `GET /wcpos/v2/changes`, `/digests` | `Changes_Controller.php:325-509` | raw `$wpdb` | **No** |
| `GET /wcpos/v2/resolve` | `Resolve_Controller.php:108-111` | `Product_Serializer::serialize()` | Controller object, **no dispatch** |
| `POST /wcpos/v2/orders/{id}/checkout` | `V2/Checkout_Controller.php:14` → `V1/Checkout_Controller.php:120-208` | `Gateway_Contract::process_checkout_action()` | **No — proprietary contract** |

The two "controller object, no dispatch" rows still fire the per-object hooks, because
`Product_Serializer`/`Order_Serializer` instantiate the real controller and call
`prepare_object_for_response()` (`includes/Sync/Product_Serializer.php:109-114`;
`includes/Sync/Order_Serializer.php:63-65`), and WooCommerce fires
`woocommerce_rest_prepare_{type}_object` at the end of that method
(`Version2/class-wc-rest-products-v2-controller.php:209`,
`Version2/class-wc-rest-orders-v2-controller.php:559`,
`Version3/class-wc-rest-product-variations-controller.php:185`). They also run
`add_additional_fields_to_object()` (`products-v2:234`, `orders-v2:576`, `variations-v3:164`),
so **`register_rest_field()` get_callbacks still run** on every v2 read lane — the single most
common third-party extension point.

---

## 2. Hook-parity matrix

`wc/v3` = does a stock `wc/v3` REST request fire it. `v2` = does the equivalent v2 operation.
✅ fires, ⚠️ fires with degraded context / at a different time / more than once, ❌ does not fire,
`n/a` = neither fires (so parity holds).

### 2.1 Product read — list (`GET /wcpos/v2/products`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `rest_pre_dispatch`, `rest_request_before_callbacks`, `rest_dispatch_request`, `rest_request_after_callbacks` | ✅ | ✅ | inner dispatch; `class-wp-rest-server.php:1079,1256,1287,1318` |
| `rest_post_dispatch` | ✅ | ⚠️ | fires once, for the OUTER `/wcpos/v2/products` route only — never for the inner `/wc/v3/products` route (`class-wp-rest-server.php:464` lives in `serve_request`, not `dispatch`) |
| `woocommerce_rest_check_permissions` | ✅ | ✅ | plus v2 adds its own callback for coupons/taxes only (`Proxy/Coupons_Proxy_Behavior.php:27`, `Proxy/Taxes_Proxy_Behavior.php:64`) |
| `woocommerce_rest_product_object_query` | ✅ | ✅ | v2 additionally installs a POS-visibility filter on the same hook when hidden products exist (`Proxy/Products_Proxy_Behavior.php:43`) |
| `woocommerce_get_catalog_ordering_args` | ✅ | ⚠️ | v2 unconditionally adds a post-ID tiebreak (`Proxy/Products_Proxy_Behavior.php:32`) — ordering differs from stock wc/v3 by design |
| `woocommerce_rest_prepare_product_object` | ✅ | ✅ | fires inside the forward with the **inner** request (query params preserved; `Store_Scope::PARAM` stripped at `Catalog_Proxy_Controller.php:78`) |
| `register_rest_field` get_callbacks | ✅ | ✅ | `products-v2:234` |
| `woocommerce_pos_sync_proxy_response` | n/a | ✅ | WCPOS-only batch seam (`Catalog_Proxy_Controller.php:92`) |

### 2.2 Product read — per-id sync lanes (`/resolve`, `/changes`, `variations?include=`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_product_object_query` / `woocommerce_rest_product_variation_object_query` | ✅ | ❌ | raw `$wpdb` / direct `wc_get_product()`; `Changes_Controller.php:362,458`, `Variations_Controller.php:291-292` |
| `pre_get_posts` | ✅ | ❌ | same reason |
| `woocommerce_rest_prepare_product_object` / `..._product_variation_object` | ✅ | ⚠️ | fires, but with a synthetic bare `WP_REST_Request('GET','/')` — no route, no real query params (`Product_Serializer.php:88,216-221`) |
| `register_rest_field` get_callbacks | ✅ | ✅ | `products-v2:234`, `variations-v3:164` |
| `rest_*` dispatch filters for the inner read | ✅ | ❌ | no dispatch at all on these lanes |

### 2.3 Variation read (`GET /wcpos/v2/variations`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_product_variation_object_query` | ✅ | ✅ **collection/search lanes only** | `Variations_Controller.php:117` calls `parent::prepare_objects_query()`; the `include=` lane skips the query entirely (`:262-268`, comment at `:296-305`) |
| `woocommerce_rest_prepare_product_variation_object` | ✅ | ⚠️ | fires via `Product_Serializer` (`Product_Serializer.php:109-114`), bare request |
| `woocommerce_variation_is_visible` | ✅ (frontend) | n/a | POS uses its own `Pos_Visibility` gate (`Variations_Controller.php:198`) |

Existing pin: `tests/includes/API/V2/Test_Variations_Search.php:314-318` proves
`woocommerce_rest_prepare_product_variation_object` is honoured on this lane.

### 2.4 Order create (`POST /wcpos/v2/push/orders`, `operation: create`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_pre_insert_shop_order_object` | ✅ | ✅ | `orders-v2:180`; WCPOS itself hooks it to stamp till meta (`Writers/Order_Writer.php:184`) and to run `Stock_Validator` |
| `woocommerce_before_order_object_save` / `woocommerce_after_order_object_save` | ✅ | ✅ | via `WC_Order::save()` inside the forward; WCPOS hooks the former to force `created_via` (`Order_Writer.php:187`) |
| `woocommerce_new_order` | ✅ | ✅ | data store |
| `woocommerce_rest_insert_shop_order_object` (`$creating = true`) | ✅ | ✅ | `Version3/class-wc-rest-crud-controller.php:221` |
| `woocommerce_order_status_changed`, `woocommerce_order_status_{from}_to_{to}`, `woocommerce_order_status_{status}` | ✅ | ✅ | `WC_Order::status_transition()` |
| `woocommerce_pre_payment_complete`, `woocommerce_payment_complete_order_status`, `woocommerce_payment_complete` | ✅ (only with `set_paid: true`) | ✅ | `orders-v2:833-835`; **pinned** by `tests/includes/Sync/Test_Write_Controller.php:1117-1177` |
| `woocommerce_reduce_order_stock`, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock` | ✅ | ✅ | ride `woocommerce_payment_complete` / `woocommerce_order_status_{processing,completed,on-hold}` (`wc-stock-functions.php:124-127`) |
| `wc_update_coupon_usage_counts` chain | ✅ | ✅ | rides the same status hooks (`wc-order-functions.php:1067-1073`) |
| `woocommerce_order_applied_coupon` | ✅ | ✅ | `WC_REST_Orders_Controller::calculate_coupons()` → `WC_Abstract_Order::apply_coupon()` (`abstract-wc-order.php:1330`) |
| `woocommerce_applied_coupon` | ❌ (cart-only) | ❌ | **parity holds** — this is a `WC_Cart` hook and no REST path fires it |
| `woocommerce_checkout_order_processed`, `woocommerce_checkout_create_order*`, `woocommerce_before_checkout_process` | ❌ | ❌ | **parity holds** — `WC_Checkout` frontend only `[K]` |
| `created_via` value | `''` unless supplied | **always `woocommerce-pos`** | `Order_Writer.php:49,178-180` — deliberate; plugins gating on `created_via === 'checkout'` will skip POS orders |
| `woocommerce_rest_prepare_shop_order_object` | ✅ once, `context=edit` | ⚠️ **twice** | once inside the forward at `context=edit` (`crud:231-233`), then again on the ack re-read at `context=view` (`Write_Controller.php:885-901`) and a third time if the writer's document step runs. Filters with side effects run more than once. |
| `woocommerce_new_order_note` | ✅ | ⚠️ **extra fires** | v2 adds POS creation / cashier-change / store-change / customer-change notes (`Order_Writer.php:106,299-310`) |

### 2.5 Order update (`operation: update`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_pre_insert_shop_order_object` (`$creating = false`) | ✅ | ✅ | only installed when there is till meta to fill (`Order_Writer.php:182-185`) — otherwise the filter is absent, but WooCommerce still fires it from `orders-v2:180` |
| `woocommerce_rest_insert_shop_order_object` (`false`) | ✅ | ✅ | `crud:268` |
| `woocommerce_update_order` | ✅ once | ⚠️ **up to 3×** | the forward's save, plus `Order_Writer::persist()`'s extra `$order->save()` for cashier/store reassignment (`:297`) and the `clear_email` data-store update (`:119-120`) |
| status / stock / coupon-usage hooks | ✅ | ✅ | as §2.4 |
| `woocommerce_payment_complete` | ✅ | ✅ | pinned by `Test_Write_Controller.php:1179` (`test_update_completing_payment_fills_missing_audit_meta_before_payment_complete`) |

### 2.6 Order delete (`operation: delete`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_shop_order_object_trashable` | ✅ | ✅ | `crud:475` |
| `woocommerce_rest_delete_shop_order_object` | ✅ | ✅ | `crud:520` |
| `woocommerce_trash_order` / `woocommerce_delete_order` | ✅ | ✅ | data store |
| `woocommerce_rest_remove_order_item` | ✅ | ✅ | `orders-v2:242` |
| stock restore (`wc_maybe_increase_stock_levels` → `woocommerce_restore_order_stock`) | ❌ | ✅ **extra** | WCPOS-only, gated by `woocommerce_pos_restore_stock_on_delete` (`Order_Writer.php:202-221`). Fires **before** the forward for a force-delete and is rolled back on failure — so third parties see a stock increase that stock wc/v3 would not produce |

### 2.7 Customer create / update (`push/customers`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_insert_customer` | ✅ | ✅ | `Version1/class-wc-rest-customers-v1-controller.php:466,555` |
| `woocommerce_new_customer` | ✅ | ✅ | `WC_Customer::save()` (`customers-v1:450`) |
| `woocommerce_update_customer` | ✅ once | ⚠️ **twice** | v2 fires an **extra manual** `do_action( 'woocommerce_update_customer', … )` after writing `tax_ids` (`Writers/Customer_Writer.php:35`) |
| `woocommerce_rest_delete_customer` | ✅ | ✅ | `customers-v1:621` |
| `woocommerce_rest_prepare_customer` | ✅ | ✅ | `customers-v1:676`; list lane also runs it via the proxy |
| `woocommerce_rest_customer_query` | ✅ | ✅ | v2 adds its own callback (`Proxy/Customers_Proxy_Behavior.php:91`) |
| `pre_user_query` | ✅ | ✅ | v2 adds search/orderby callbacks (`Customers_Proxy_Behavior.php:94,98`) |
| Payload shaping | — | ⚠️ | `tax_ids` and an empty `billing.email` are **stripped before the forward** (`Customer_Writer.php:39-42`), so `woocommerce_rest_insert_customer` receives a request without them |

### 2.8 Product / variation / coupon writes (`push/products`, `push/variations`, `push/coupons`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_rest_pre_insert_product_object` / `..._product_variation_object` | ✅ | ✅ | `products-v3:1152`, `variations-v3:413` |
| `woocommerce_rest_insert_product_object` / `..._product_variation_object` / `..._shop_coupon_object` | ✅ | ✅ | `crud:221,268` |
| `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation`, `woocommerce_update_product_variation`, `woocommerce_new_coupon`, `woocommerce_update_coupon` | ✅ | ✅ | data stores |
| `woocommerce_before_product_object_save` / `after` | ✅ | ✅ | WCPOS itself hooks the former for decimal quantities (`includes/Products.php:52`) |
| `woocommerce_rest_delete_product_object` etc. | ✅ | ✅ | `crud:520` |
| `woocommerce_rest_prepare_product_object` on the ack | ✅ | ⚠️ | fires twice (forward at `edit`, re-read at `view`) — see §2.4 |
| Variation ack document | ✅ | ⚠️ | `Variation_Writer::document()` bypasses the dispatch entirely (`Variation_Writer.php:80`), so only the per-object prepare filter fires |
| `id` and the store-scope param in the payload | pass through | ⚠️ | stripped before the forward (`Write_Controller.php:783`) |

### 2.9 Stock adjustment

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `woocommerce_product_set_stock`, `woocommerce_variation_set_stock` | ✅ | ✅ | WCPOS *consumes* both (`includes/Products.php:27-28`), pinned by `tests/includes/Test_Products_Direct.php:91` |
| `woocommerce_reduce_order_stock`, `woocommerce_restore_order_stock` | ✅ | ✅ | ride the order status hooks |
| `woocommerce_stock_amount` | ✅ | ⚠️ **globally altered** | when decimal quantities are on, WCPOS does `remove_filter('woocommerce_stock_amount','intval')` + `add_filter(…, 'floatval')` **site-wide**, not scoped to POS requests (`includes/Products.php:50-51`) |
| `woocommerce_can_reduce_order_stock` | ✅ | ✅ | core |

### 2.10 Checkout (`POST /wcpos/v2/orders/{id}/checkout`)

| Hook | wc/v3 | v2 | Notes |
|---|---|---|---|
| `WC_Payment_Gateway::process_payment()` | n/a (wc/v3 has no checkout) | ❌ | v2 calls `process_pos_checkout_action()` on a WCPOS adapter instead (`Payments/Gateway_Contract.php:141-152`) |
| `wcpos_payment_gateway_process_checkout_action_{id}` | n/a | ✅ | the **only** integration seam (`Payments/Filter_Gateway_Adapter.php:153-165`) |
| `woocommerce_payment_complete` etc. | n/a | ⚠️ **bundled gateways only** | `pos_cash` / `pos_card` call `$order->payment_complete()` (`includes/Gateways/Cash.php:289`, `Card.php:246`); a third-party gateway fires nothing unless it implements the WCPOS filter |
| `woocommerce_checkout_*` | ❌ | ❌ | parity with wc/v3 holds; parity with a real storefront checkout does not `[K]` |
| `woocommerce_before_resend_order_emails` / `woocommerce_after_resend_order_email` | n/a | ✅ | fired by the order-email lane (`includes/API/V1/Orders_Controller.php:893,905`, inherited by `V2/Order_Email_Controller.php:15`) |

---

## 3. v1 → v2 delta

v1's controllers **extend** the wc/v3 controllers directly
(`V1/Products_Controller.php:35`, `V1/Orders_Controller.php:49`,
`V1/Customers_Controller.php:36`, `V1/Coupons_Controller.php:31`,
`V1/Product_Variations_Controller.php:33`, `V1/Taxes_Controller.php:28`), so a v1 request *is* a
wc/v3 request with a different route string. Its per-request hooks are installed from
`wcpos_dispatch_request()`, wired at `includes/API.php:85` on `rest_dispatch_request`. v2
reaches the same controllers one level down, through `rest_do_request()`.

Only ONE v2 controller still extends a WooCommerce controller
(`V2/Variations_Controller.php:50`); the rest are hand-rolled `WP_REST_Controller`s or thin
subclasses of the v1 class (`V2/Order_Email_Controller.php:15`, `V2/Checkout_Controller.php:14`,
`V2/Settings.php:14`, …), which inherit the v1 hook behaviour unchanged.

| | v1 | v2 |
|---|---|---|
| `rest_post_dispatch` sees a `wc/v3`-shaped controller run | ✅ (the outer route *is* the controller) | ⚠️ only for the `wcpos/v2` outer route — and WCPOS itself occupies that hook (`Sync/Response_Telemetry.php:39`, `Sync/Response_Envelope.php:19`, `Sync/Retry_After_Mirror.php:19`) |
| `$request->get_route()` inside `woocommerce_rest_*` | `/wcpos/v1/…` | `/wc/v3/…` (writes and list reads) or `/` (sync lanes) |
| `wcpos_request()` (header-based) | true | true — headers survive the inner dispatch; stated at `tests/includes/Sync/Test_Rest_Dispatch_Stock_Validation.php:14-26` |
| Order list/query filters | `woocommerce_rest_shop_order_object_query` fires (`V1/Orders_Controller.php:256`) | ✅ on `/wcpos/v2/orders` (`Sync/Collection_Rules_Plan.php:370`), ❌ on `/orders/pull` |
| Product query filters | fire on every read | ❌ on `/changes`, `/resolve`, `/digests`, `variations?include=` |
| Emails | resend hooks fired (`V1/Orders_Controller.php:893,905`) | inherited unchanged via `V2/Order_Email_Controller.php:15` |

### 3.1 Where v2 shapes the payload has moved

v1 hooked its own shapers onto WooCommerce's prepare filters at priority 10 —
`woocommerce_rest_prepare_shop_order_object` (`V1/Orders_Controller.php:253`),
`..._product_object` (`Products_Controller.php:82`),
`..._product_variation_object` (`Product_Variations_Controller.php:64`),
`woocommerce_rest_prepare_customer` (`Customers_Controller.php:73`),
`..._shop_coupon_object` (`Coupons_Controller.php:92`),
`woocommerce_rest_prepare_tax` (`Taxes_Controller.php:57`),
`woocommerce_rest_prepare_{$taxonomy}` (`Traits/Term_Controller.php:64`).

v2 removed all of those and shapes **after** serialization instead, through its own
`woocommerce_pos_sync_proxy_response` / `woocommerce_pos_sync_serialized_{product,order}`
pipeline (`Catalog_Proxy_Controller.php:92`, `Product_Serializer.php:186`,
`Order_Serializer.php:96`). The WooCommerce hook still fires — but a third-party filter
registered at priority > 10 that used to run *after* WCPOS's shaper and could inspect or
override its fields now runs *before* it and cannot. **Ordering relative to WCPOS's own
augmentations has inverted.** That is the one silent behavioural change in the read path.

### 3.2 Things v1 did that v2 correctly stopped doing

v1 leaked most of its per-request hooks for the remainder of the request (only 9 removals,
several conditional); v2 unwinds every request-scoped hook in a `finally`
(`Proxy/Scoped_Proxy_Behavior.php:42`, `Write_Controller.php:808`, `Order_Writer.php:193,196`,
`Variations_Controller.php:524`). Two of the v1 leaks are worth naming:

- `V1/Coupons_Controller.php:93` adds `woocommerce_rest_check_permissions` →
  `wcpos_check_permissions` and **never removes it** — the grant outlives the coupon read.
  v2's equivalents (`Write_Controller.php:645,797`, `Proxy/Coupons_Proxy_Behavior.php:27`,
  `Proxy/Taxes_Proxy_Behavior.php:64`) are always removed.
- `V1/Customers_Controller.php:248,262` set
  `pre_option_woocommerce_registration_generate_password` /
  `..._generate_username` inside `create_item` and never remove them, silently overriding the
  site's registration settings for the rest of the request. v2's `Customer_Writer` forwards to
  wc/v3 with neither, so **v2 customer creation respects the merchant's real registration
  settings and v1 did not** — a behaviour change third parties may notice.

### 3.3 Global observers v2 added that v1 never had

v2 registers a large permanent observer set on WooCommerce's write hooks —
`woocommerce_new_/update_{product,product_variation,coupon,customer,order}`,
`woocommerce_before_trash_order`, `woocommerce_before_delete_order`,
`woocommerce_untrash_order`, `woocommerce_after_order_object_save`,
`woocommerce_order_status_changed`, the term/tax-rate/user lifecycles, and
`woocommerce_before_product_object_save` (`Sync/Sync_Journal.php:212-243`,
`Sync/Integrity_Digest.php:128-163`, `Sync/Pos_Uuid.php:482-483`,
`Sync/Coupon_Modified_Date.php:49`, `Sync/Visibility_Observer.php:103-109`). v1 had **no**
analogue. These are consumers, not gaps — but they mean **every third-party write anywhere on
the site now runs WCPOS journal + digest code**, which is where a hook-ordering or
performance interaction with ATUM's bulk stock writer would first show up.

**Nothing v1 fired that v2 dropped outright**, other than the `rest_post_dispatch`-on-a-real-
controller nuance, the shaper-ordering inversion in §3.1, and the query filters on the *new*
sync lanes (which had no v1 equivalent — v1 had no pull/changes/digest lanes at all).

---

## 4. Prioritized gap list

### P1 — `woocommerce_rest_{shop_order,product}_object_query` never fires on the sync pull lanes

- `includes/Sync/Order_Query.php:68-98` — raw `SELECT id FROM {prefix}wc_orders` / `SELECT ID FROM {posts}`.
- `includes/API/V2/Changes_Controller.php:362,458,489-509` — raw `$wpdb` for the change feed and digest buckets.
- `includes/API/V2/Variations_Controller.php:262-268` — the `include=` lane loads ids directly.

Any plugin that constrains *which records a user may see* through the REST query filter
(multi-vendor, multi-store, membership-gated catalogs) is bypassed on `/orders/pull`,
`/changes`, `/resolve` and `variations?include=`. The POS-side gates that do exist
(`Pos_Visibility`, `Endpoint_Permissions`) are WCPOS's own and know nothing about third-party
scoping. This is the only gap with a security-adjacent edge, and it is the one to close first.

### P2 — change detection cannot see a filter-only change

Two independent signals decide whether a till re-pulls a record, and **neither observes the
serialized payload**:

- **The journal** (`includes/Sync/Sync_Journal.php:212-243`) is driven entirely by *save*
  hooks — `woocommerce_new_/update_{product,product_variation,coupon,customer,order}`,
  the term/tax-rate/user lifecycles, `wp_trash_post`, `before_delete_post`. Correct and
  well-covered for anything that calls `WC_Data::save()`.
- **The digest** (`includes/Sync/Integrity_Digest.php:388-500`) is an `INSERT…SELECT` over
  `postmeta` / `wc_orders`, refreshed from the same save hooks
  (`Integrity_Digest.php:128-163`).

`includes/Sync/Revision.php` *does* hash the filtered wc/v3 serialization, so the payload a
till receives is correct once it pulls. The gap is that nothing ever tells it to.

A plugin whose `woocommerce_rest_prepare_product_object` output depends on state outside the
product's own row — a role- or store-scoped price rule, a stock projection, an add-on group
edited on its own post type, a Smart Coupons generation rule — changes what the POS *should*
show without touching the product and without moving the digest. Tills keep serving the stale
cached document until some unrelated save bumps it. There is currently no public action a third
party can call to say "this record's representation changed".

### P3 — third-party payment gateways are not invoked by v2 checkout

`includes/API/V2/Checkout_Controller.php:14` → `includes/API/V1/Checkout_Controller.php:187`
→ `includes/Payments/Gateway_Contract.php:141-152` →
`includes/Payments/Filter_Gateway_Adapter.php:153-165`. The only path into a gateway is the
`wcpos_payment_gateway_process_checkout_action_{id}` filter. `WC_Payment_Gateway::process_payment()`
is never called, so a gateway that has not been WCPOS-adapted never charges, never sets
`transaction_id`, and never reaches `payment_complete()` — the order is marked paid by a
separate status push instead. Documented contract, but it is the largest "plugin does not work
with WCPOS" surface, and it is invisible to a merchant until the till is live.

### P4 — hooks that fire more than once per v2 write

- `woocommerce_rest_prepare_{type}_object`: twice or three times (forward at `context=edit`,
  ack re-read at `context=view`, writer document step) — `Write_Controller.php:885-901`,
  `Writers/Order_Writer.php:138-146`.
- `woocommerce_update_order`: up to 3× on an order update — the forward's save plus
  `Order_Writer.php:297` and `:119-120`.
- `woocommerce_update_customer`: twice, one of them a **manual** `do_action` —
  `Writers/Customer_Writer.php:35`.

Idempotent filters are unaffected; anything that counts, logs, emails, or enqueues on these
hooks double-counts against a v2 write. Smart Coupons' order-level listeners and ATUM's audit
log are both in this category `[K]`.

### P5 — third-party prepare filters now run *before* WCPOS's augmentations, not after

Detailed in §3.1. In v1, WCPOS shaped the payload from inside
`woocommerce_rest_prepare_{type}_object` at priority 10, so a third-party filter at priority
> 10 ran last and had the final word. In v2, WCPOS shapes *after* the whole prepare filter has
finished, via `woocommerce_pos_sync_proxy_response` / `woocommerce_pos_sync_serialized_*`
(`Catalog_Proxy_Controller.php:92`, `Product_Serializer.php:186`, `Order_Serializer.php:96`).
No hook stopped firing, but the ordering inverted: a plugin that previously overrode a WCPOS
field on the POS lane now has its value overwritten. The v2 filters are the documented
replacement seam, but they are WCPOS-specific and undiscoverable to a plugin author who only
knows the WooCommerce hook.

### P6 — degraded request context on the sync serializers

`includes/Sync/Product_Serializer.php:88,216-221` and `includes/Sync/Order_Serializer.php:57`
hand `woocommerce_rest_prepare_*_object` a synthetic `WP_REST_Request` with route `/` (or no
route at all) and no query params. Filters that read `$request->get_route()`,
`$request['context']`, `$request['_fields']`, or any collection param see nothing.
WooCommerce defaults the context to `view` (`products-v2:191`, `orders-v2:573`), so payload
shape is safe; discriminating filters are not. Two WCPOS-internal consumers already had to work
around this (`Product_Serializer.php:96` re-stamps store scope, `:104` re-stamps `product_id`),
which is good evidence third parties will hit it too.

### P7 — WCPOS-only behaviour third parties will observe as non-standard

- `created_via` is forced to `woocommerce-pos` on every v2 order create
  (`Writers/Order_Writer.php:49,178-180`). Correct per the repo's own
  gate-on-order-origin rule, but plugins keyed on `checkout` skip POS orders.
- Stock is restored **before** a force-delete and rolled back on failure
  (`Order_Writer.php:202-221`) — a stock movement stock wc/v3 never makes.
- `woocommerce_stock_amount` is re-bound from `intval` to `floatval` **site-wide** when decimal
  quantities are enabled (`includes/Products.php:50-51`), not scoped to POS requests.
- `woocommerce_get_catalog_ordering_args` carries an unconditional post-ID tiebreak on the
  products proxy (`Proxy/Products_Proxy_Behavior.php:32`).
- `tax_ids` and empty `billing.email` are stripped before the customer/order forward
  (`Customer_Writer.php:39-42`, `Order_Writer.php:50,236`).
- Every third-party write **anywhere on the site** now runs WCPOS journal + digest observers
  (`Sync/Sync_Journal.php:212-243`, `Sync/Integrity_Digest.php:128-163`,
  `Sync/Pos_Uuid.php:482-483`, `Sync/Visibility_Observer.php:103-109`). v1 registered none of
  these. Bulk writers (ATUM's stock sync, a CSV importer) pay the cost per row.

---

## 5. Existing tests that already pin part of this

| Test | Pins |
|---|---|
| `tests/includes/Sync/Test_Write_Controller.php:1117-1177` | `woocommerce_payment_complete` fires **inside** the forwarded create for `set_paid: true`, with `_pos_user` / cash tender already visible |
| `tests/includes/Sync/Test_Write_Controller.php:1179+` | audit meta is filled **before** `payment_complete` on an update |
| `tests/includes/Sync/Test_Rest_Dispatch_Stock_Validation.php:14-26` | `woocommerce_rest_pre_insert_shop_order_object` fires during the push's inner wc/v3 dispatch, and `wcpos_request()` is true there |
| `tests/includes/Sync/Test_Sync_Hook_Isolation.php:77,112,200` | the v2 surface does **not** modify stock wc/v3 products/orders/terms routes (isolation, the mirror of parity) |
| `tests/includes/Sync/Test_Sync_Hook_Isolation.php:168` | `woocommerce_rest_shop_order_object_query` is observable on the order lane |
| `tests/includes/API/V2/Test_Variations_Search.php:314-318` | `woocommerce_rest_prepare_product_variation_object` shapes the v2 variations payload |
| `tests/includes/API/V2/Catalog_Proxy_Order_Payload_Tests.php:36-77` | the proxied order row equals WooCommerce's own shape **plus** WooCommerce's own `currency_symbol`, which is itself injected by a core filter on `woocommerce_rest_prepare_shop_order_object` — direct evidence third-party prepare filters reach the cached payload |
| `tests/includes/Test_Products_Direct.php:91` | `woocommerce_product_set_stock` is wired |
| `tests/includes/Test_Init_Hook_Wiring.php:76-91` | the `woocommerce_new_*` / `woocommerce_update_*` observer set is pinned |
| `tests/includes/Sync/Test_Store_Scope.php:189,452-605` | `woocommerce_rest_prepare_product_object` carries store scope on every lane |

**No test asserts a third-party hook parity contract directly** — i.e. "register a probe on
hook X, run operation Y through v2, assert it fired once with these args". Every pin above is
incidental to some other behaviour. That is the test-shaped gap this audit exposes.

---

## 6. Suggested follow-ups (not done here)

1. A `Test_Hook_Parity` suite that registers counting probes on the ~25 hooks in §2 and drives
   each operation through **both** `/wc/v3/...` and `/wcpos/v2/...`, asserting the same hook set
   and fire counts. This turns the matrix into a regression gate and would immediately catch P4.
2. Route the `/orders/pull`, `/changes` and `variations?include=` id-selection through the
   corresponding `woocommerce_rest_*_object_query` filter (apply the filter to the args even
   where the SQL is hand-built), closing P1 without giving up the raw-SQL performance work.
3. Extend the digest source to cover the filtered payload, or expose a documented
   `woocommerce_pos_sync_invalidate_{collection}` action third parties can call — closing P2.
4. Document the `wcpos_payment_gateway_*` contract as the supported gateway integration path,
   and publish an adapter for at least one major third-party gateway (P3).
