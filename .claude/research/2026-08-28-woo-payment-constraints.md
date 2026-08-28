# WooCommerce payment constraints & gift-card / store-credit redemption

Research for [roadmap#100](https://github.com/wcpos/roadmap/issues/100) (parent: charting survey on
[roadmap#97](https://github.com/wcpos/roadmap/issues/97)). Facts and citations only.

**Core source read:** WooCommerce **10.4.3** at
`/Users/kilbot/.wp-env/wp-env-woocommerce-pos-cf9da277/woocommerce/`; paths and line numbers below are
relative to that root.

---

## 1. Core payment fields — storage and REST exposure

`WC_Order` declares `payment_method`, `payment_method_title`, `transaction_id` as **single-valued scalar
props** in `$data` (`includes/class-wc-order.php:82-84`); `date_paid` at `:90`. There is no array/multi
form anywhere in core.

| Prop | CPT (posts) storage | HPOS storage | `wc/v3/orders` |
|---|---|---|---|
| `payment_method` | `_payment_method` post meta (`includes/data-stores/class-wc-order-data-store-cpt.php:67`, mapping `:259`) | `wp_wc_orders.payment_method varchar(100)` (`src/Internal/DataStores/Orders/OrdersTableDataStore.php:3228`; mapping `:313-316`) | `payment_method` string, read+write (`…/Version2/class-wc-rest-orders-v2-controller.php:1412`) |
| `payment_method_title` | `_payment_method_title` (`…cpt.php:68`, `:260`) | `wp_wc_orders.payment_method_title text` (`OrdersTableDataStore.php:3229`) | `payment_method_title` string, read+write (`v2 controller:1417`) |
| `transaction_id` | `_transaction_id` (`…cpt.php:69`, `:261`) | `wp_wc_orders.transaction_id varchar(100)` (`OrdersTableDataStore.php:3230`) | `transaction_id` string, read+write (`v2 controller:1425`) |
| `date_paid` | `_date_paid` (+ legacy `_paid_date`, written for BC at `…cpt.php:385`) | `wp_wc_order_operational_data.date_paid_gmt datetime` (`OrdersTableDataStore.php:3275`) | `date_paid` / `date_paid_gmt`, **readonly** (`v2 controller:1430,1436`) |
| `total` | `_order_total` (`…cpt.php:66`) | `wp_wc_orders.total_amount decimal(26,8)` (`OrdersTableDataStore.php:3222`) | `total`, readonly (computed) |
| arbitrary custom meta | `wp_postmeta` | `wp_wc_orders_meta` (`OrdersTableDataStore.php:228`, DDL `:3283-3290`) | `meta_data[]` array of `{id,key,value}`, **read and write** |

**`meta_data` write path:** `wc/v3/orders` POST/PUT loops `meta_data` → `$order->update_meta_data( key,
value, id )` (`…/Version3/class-wc-rest-orders-controller.php:152-157`; v2 `:750`). Refunds accept
`meta_data` too (`…/Version3/class-wc-rest-order-refunds-controller.php:73-78`).

**`meta_data` read filtering — three filters, and no underscore rule among them:**
(1) `WC_Data_Store_WP::filter_raw_meta_data()` hides the store's `$internal_meta_keys` **plus**
`'_' . <every `$data` key>` **plus** any key starting with `wp_`
(`includes/data-stores/class-wc-data-store-wp.php:110-122`, `:214-216`; CPT list `…cpt.php:58-76`, HPOS list
`OrdersTableDataStore.php:78-113` — both include `_payment_method`, `_transaction_id`, `_date_paid`,
`_payment_tokens`). (2) Under HPOS only, REST re-applies the **CPT** list so both modes agree
(`…/Version2/class-wc-rest-orders-v2-controller.php:419`, body `:361-374`). (3) `include_meta` /
`exclude_meta` request params (`…/Version3/class-wc-rest-controller.php:645-673`).

→ **A leading-underscore key such as `_wcpos_payments` IS returned in `meta_data`** — core applies no
`is_protected_meta()` rule to order meta in REST. Avoid `_<order data key>` and the `wp_` prefix; everything
else round-trips.

**`set_payment_method()` gotcha** (`includes/class-wc-order.php:1483-1493`): passing a **gateway object**
sets both id and title; passing a **string** sets only the id and leaves the title untouched; passing `''`
clears both.

---

## 2. `payment_complete()`, paid statuses, refunds

### `payment_complete( $transaction_id = '' )` — `includes/class-wc-order.php:137-199`

1. `woocommerce_pre_payment_complete( $order_id, $transaction_id )` (`:143`).
2. **Guard:** proceeds only if the order is in `OrderStatus::PAYMENT_COMPLETE_STATUSES` =
   `on-hold, pending, failed, cancelled` (`src/Enums/OrderStatus.php:103-108`), filter
   `woocommerce_valid_order_statuses_for_payment_complete` (`:156`).
3. `set_transaction_id()` only if `$transaction_id` is **non-empty** (`:158-160`); `set_date_paid( time() )`
   only if unset (`:161-163`).
4. Next status = filter `woocommerce_payment_complete_order_status`, default
   `needs_processing() ? processing : completed` (`:173`); `needs_processing()` is false only when every line
   item is virtual **and** downloadable (`:1828-1859`).
5. Order note `"Payment via %1$s (%2$s)."` from **`payment_method_title` + `transaction_id`** (`:178-179`),
   group `OrderNoteGroup::PAYMENT`; then `save()` and
   `woocommerce_payment_complete( $order_id, $transaction_id )` (`:185`).
6. **Else branch** (not in a payable status): only
   `woocommerce_payment_complete_order_status_{status}` fires (`:187`) — no status change, no `date_paid`, no
   note. A second `payment_complete()` call is a silent no-op.

`maybe_set_date_paid()` (`:352-373`) stamps `date_paid` on any transition into that same computed status, so
`set_status('processing')` alone marks an order paid **without** firing `woocommerce_payment_complete`.

### Paid / needs-payment

- `wc_get_is_paid_statuses()` → `[ processing, completed ]` (`includes/wc-order-functions.php:134-143`, filter
  `woocommerce_order_is_paid_statuses`); `refunded` and `cancelled` are **not** paid.
  `wc_get_is_pending_statuses()` → `[ pending ]` (`:151-160`); `wc_get_order_statuses()` → the seven core
  statuses, filter `wc_order_statuses` (`:104-115`). `WC_Order::is_paid()` = `has_status(...)` (`:1680`).
- `WC_Order::needs_payment()` = status in `[ pending, failed ]` **AND `get_total() > 0`**
  (`includes/class-wc-order.php:1804-1814`). **A zero-total order never needs payment** — exactly where a
  gift card that covers the whole basket as a discount lands.
- REST `set_paid: true` calls `payment_complete( $request['transaction_id'] )`, but only
  `if ( $creating || $object->needs_payment() )` (`…/Version3/class-wc-rest-orders-controller.php:304-307`).

### Refunds

- A refund is a **child order** of type `shop_order_refund` (`includes/class-wc-order-refund.php:67`), extra
  props `amount, reason, refunded_by, refunded_payment` (`:38-43`), always saved status `completed`
  (`src/Internal/DataStores/Orders/OrdersTableRefundDataStore.php:134`).
- Storage is **meta in both worlds**: `_refund_amount`, `_refund_reason`, `_refunded_by`, `_refunded_payment`
  (CPT `…/class-wc-order-refund-data-store-cpt.php:28-31,127-130`; HPOS keeps the same keys in
  `wp_wc_orders_meta`, `OrdersTableRefundDataStore.php:22-27,168-171`). HPOS gives refunds **no** amount
  column; the refund's own `wc_orders` row carries only `parent_order_id`.
- `wc_create_refund( $args )` (`includes/wc-order-functions.php:558-…`): `refund_payment` and `restock_items`
  default `false`. After `$refund->save()`, `refund_payment` calls `wc_refund_payment()`; on `WP_Error` the
  refund row is **deleted** and the error returned; on success `refunded_payment = true` (`:667-676`).
  Hook order: `woocommerce_create_refund` (`:664`) → `woocommerce_order_partially_refunded` **or**
  `woocommerce_order_fully_refunded` (`:706-731`; the latter also `update_status( refunded )`, filter
  `woocommerce_order_fully_refunded_status`) → `woocommerce_refund_created` (`:741`) →
  `woocommerce_order_refunded` (`:742`).
- **`wc_refund_payment()`** (`:764-797`) resolves the gateway from **`$order->get_payment_method()` — the one
  scalar** — throwing if it is unregistered or lacks `supports( PaymentGatewayFeature::REFUNDS )`, then calls
  `$gateway->process_refund( $order_id, $amount, $reason )`. That method returns `false` in the base class
  (`includes/abstracts/abstract-wc-payment-gateway.php:454-456`); truthy = success, `WP_Error` = surfaced
  failure; capability is `'refunds'` in `$supports` (`:115`, `supports()` `:499-510`, filter
  `woocommerce_payment_gateway_supports`).
- REST: `POST wc/v3/orders/<id>/refunds` maps `api_refund` → `refund_payment`, `api_restock` →
  `restock_items` (`…/Version3/class-wc-rest-order-refunds-controller.php:54-63`); response carries
  `amount, reason, refunded_by, refunded_payment`
  (`…/Version2/class-wc-rest-order-refunds-v2-controller.php:173-176,399-417`). On the parent order `wc/v3`
  exposes only `refunds[] = {id, reason, total}` (`…/class-wc-rest-orders-v2-controller.php:436-445`) —
  **no per-refund payment attribution**.

Docs: [Payment Gateway API](https://developer.woocommerce.com/docs/apis/payment-gateway-api/),
[Orders REST reference](https://woocommerce.github.io/woocommerce-rest-api-docs/#orders).

---

## 3. HPOS-compatible order-linked storage

**(a) Order meta via CRUD** — the recipe book's only stated extension pattern: *"For interacting with
metadata, use the `update_`/`add_`/`delete_metadata` methods on the order object, followed by a `save` call."*
([HPOS recipe book](https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/)) —
never `update_post_meta()`. Routes to `wp_postmeta` or `wp_wc_orders_meta` automatically
(`OrderUtil::get_table_for_order_meta()`, `src/Utilities/OrderUtil.php:197`).
`wc_get_orders( ['meta_query' => …] )` works under HPOS (`…/Orders/OrdersTableQuery.php:320-338`) — but a
**JSON blob in one meta row is not queryable by its contents**.

**(b) Own table keyed by order id, written inside the order save.** Core's sanctioned hook since 6.8.0,
`woocommerce_orders_table_datastore_extra_db_rows_for_order`
(`src/Internal/DataStores/Orders/OrdersTableDataStore.php:2370-2380`): *"Allow third parties to include rows
that need to be inserted/updated in custom tables when persisting an order. Each entry should be an array with
keys 'table', 'data' (the row), 'format' (row format), 'where' and 'where_format'."* The sibling
`…_db_rows_for_order` at `:2391` is `@internal` — do not use. This hook is **HPOS-only**; the CPT lane needs a
`woocommerce_update_order` / `woocommerce_after_order_object_save` listener instead. Core does not document
adding columns to `wp_wc_orders`; that DDL is core-owned (`:3215-3239`).

**(c) Replace the data store** via `woocommerce_order_data_store` — core's own mechanism
(`…/Orders/CustomOrdersTableController.php:140`; default map `'order' => 'WC_Order_Data_Store_CPT'`,
`includes/class-wc-data-store.php:41`). Too invasive for a ledger.

**Detection / declaration / query hooks:** `OrderUtil::custom_orders_table_usage_is_enabled()`
(`src/Utilities/OrderUtil.php:39`), `::get_table_for_orders()` (`:188`), `::is_custom_order_tables_in_sync()`
(`:66`); `FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true )` on
`before_woocommerce_init` (`src/Utilities/FeaturesUtil.php:57-63`, via `wc_get_container()`). Making a ledger
table joinable in order queries needs **both** lanes: `woocommerce_hpos_pre_query`
(`OrdersTableQuery.php:241`) / `woocommerce_orders_table_datastore_get_orders_query`
(`OrdersTableDataStore.php:3165`) **and** `woocommerce_order_data_store_cpt_get_orders_query`
(`includes/data-stores/class-wc-order-data-store-cpt.php:1103`). Caveat: with HPOS + posts sync on, order
*meta* is mirrored to `wp_postmeta`; rows in a plugin's own table are mirrored by nothing.

---

## 4. Gift-card / store-credit plugin matrix

Read from source: **PW Gift Cards 2.51**, **YITH Gift Cards free 4.37.0**, **Smart Coupons 3.1.1** (public
mirror `github.com/riclain/woocommerce-smart-coupons`; retail is 9.x — cross-checked against
`/Users/kilbot/Projects/wcpos-storeapps-smart-coupons`). **WooCommerce Gift Cards (Woo)** is **docs-only**.

| | Redemption model | Partial | Balance / redeem API | On the Woo order | Refund → card |
|---|---|---|---|---|---|
| **PW Gift Cards** | none of coupon/fee/gateway: **overwrites `$cart->total` post-tax**, backed by an append-only activity ledger | yes; per-card cap, optional `pwgc_minimum_payment_amount` floor so a gateway always gets something | **no REST**; Store API cart extension namespace `pw-gift-cards`; AJAX `pw-gift-cards-redeem/-remove`; `?pw_gift_card_number=`; PHP `PW_Gift_Card::get_balance()/debit()/credit()` | custom **order-item type `pw_gift_card`** (itemmeta `card_number`, `amount`, `_pw_gift_card_debited`); **no fee, no coupon, no order meta**; card→order link is a free-text ledger `note` | **full-order only** — `woocommerce_order_status_refunded/cancelled/failed` credits the whole line back; **no** `woocommerce_order_refunded` hook, so a partial Woo refund returns nothing |
| **YITH Gift Cards (free)** | **two live paths**: (a) native form overwrites `$cart->total`; (b) code in the coupon box → virtual `fixed_cart` `WC_Coupon` (always on in free — `YITH_YWGC_FREE_INIT`). Admin recalculation converts (a) into a **negative `WC_Order_Item_Fee`** | yes; `min(amount, cart_total)`, smallest card first | **no REST**; AJAX `ywgc_apply_gift_card_code` / `ywgc_remove_gift_card_code` (session-scoped only); PHP `YITH_YWGC()->get_gift_card_by_code()`, `->update_balance()` | order meta `_ywgc_applied_gift_cards` (code→amount), `_ywgc_applied_gift_cards_totals`, `ywgc_gift_card_updated_as_fee`; **coupon item** on path (b); **negative fee item** after admin recalc; nothing at all on path (a) | **full status change only** — `woocommerce_order_status_changed` → cancelled/refunded/failed restores the recorded amount once (`_ywgc_is_gift_card_amount_refunded`); no partial-refund hook |
| **WooCommerce Gift Cards (Woo)** *(docs-only)* | vendor calls it **a payment method implemented by modifying the order total**; explicitly **not** a discount — line items stay undiscounted and revenue books in full | yes; remainder goes to the selected gateway; unused value stays on the same card | **first-party REST**: `GET/POST /wc/v3/gift-cards`, `/{id}`, `/batch`; card has `balance` (issued) and **read-only `remaining`**. Debit via **`gift_cards: [{id, code, amount}]` on `POST/PUT /wc/v3/orders`** (`amount: 0` removes; omitted = full balance). Store API `cart/extensions` | orders REST gains a **`gift_cards` array** of code + amount used; **no coupon line, no fee line**. Exact order meta keys **unverified** | supported, **manual**: order → Refund → "Refund $X to gift cards", capped by "Total available to refund to gift cards"; new cards are never issued by a refund |
| **StoreApps Smart Coupons** (store credit) | **(1) a coupon** — custom discount type **`smart_coupon`**, applied by filtering `woocommerce_calculated_total` (`$discount = min( $total, $coupon->amount )`) and written back into `coupon_discount_amounts` | yes; leftover **decrements the same coupon's `coupon_amount` in place** (no new coupon); trashed at zero if `woocommerce_delete_smart_coupon_after_usage` | **no dedicated REST** — balance is the coupon's `amount` on `/wc/v3/coupons` with `discount_type: smart_coupon` + `meta_data.wc_sc_original_amount`; shortcodes `[wc_sc_balance]` | a normal **coupon line item** with positive `discount`; order meta `smart_coupons_contribution` (code→amount used), `wc_sc_environment`. **`discount_total` absorbs the credit** | **automatic** on processing/completed → refunded/cancelled: restores the **whole** line discount and decrements `usage_count`; not proportional to a partial refund |

Load-bearing details:

- **All four shrink `order.total` or `discount_total`; none writes a payment record or touches
  `payment_method`.** Woo's own FAQ says why: *"WooCommerce does not natively support multiple payment methods
  per order. To work around this limitation, the plugin modifies the order total of every order that is paid
  with gift cards, partially or fully."* ([Gift Cards FAQ](https://woocommerce.com/document/gift-cards/faq/))
- **Only Woo Gift Cards treats it as a payment for accounting** — line items stay undiscounted and revenue
  books in full; Smart Coupons is indistinguishable from a discount (`discount_total` absorbs it). A POS
  `stored_value` tender must carry a flag for which, not assume either.
- **Zero-remaining handling differs.** PW filters `woocommerce_order_needs_payment` → `false`
  (`pw-gift-cards-redeeming.php:124,871-912`); YITH does nothing and relies on core's zero-total path. Both
  leave `payment_method` **empty** on a fully-card-paid order.
- **Balance storage differs in all four**: PW = two custom tables, balance = `SUM(activity.amount)`, MySQL
  `GET_LOCK` per card; YITH = a `gift_card` **post type** + `_ywgc_balance_total` post meta (no table);
  Smart Coupons = the coupon post's `coupon_amount`; Woo = its own store behind `balance` / `remaining`.
- **No plugin returns value on a partial Woo refund.** PW, YITH and Smart Coupons all hook order *status*
  transitions, not `woocommerce_order_refunded` / `woocommerce_refund_created`. Woo Gift Cards is the only one
  with a partial path, and it is a manual admin action.
- **Smart Coupons has an order-context gate** that matters for REST-created POS orders: its order-side credit
  logic runs only for admin AJAX, WC REST, or Store API requests — our integration force-returns `true` from
  `woocommerce_is_rest_api_request` during POS recalculation
  (`/Users/kilbot/Projects/wcpos-storeapps-smart-coupons/includes/class-plugin.php:77-121`).
- `/Users/kilbot/Projects/wcpos-webtoffee-smart-coupons` is a **scaffold only**; its README lists WebToffee
  facts (`store_credit` type, `wt_sc_coupon_lookup.amount`, `is_wt_gc_wallet_coupon`) as still to be verified.

---

## 5. Against the strawman (roadmap#97 §5)

1. **"`payment_method`/`_title` become derived (`pos_split` + summary title)" is viable but lossy in two
   named places.** (a) `wc_refund_payment()` looks the gateway up by that exact string
   (`wc-order-functions.php:764-797`) — `pos_split` means **no automatic refund at all** through core, for
   every tender on the order, and `api_refund: true` on `POST wc/v3/orders/<id>/refunds` returns a 500.
   (b) `payment_complete()` builds its order note from `payment_method_title` + `transaction_id`
   (`class-wc-order.php:178-179`), so the note reads "Payment via Split (…)" with one id. Neither blocks the
   design; both must be owned deliberately.
2. **`transaction_id` is one `varchar(100)`** (`OrdersTableDataStore.php:3230`). N provider references cannot
   live there — `provider_refs` must live in the ledger, with `transaction_id` carrying at most one
   *designated* payment's reference (or nothing).
3. **`Payment.status` has no core counterpart.** Core's only payment state is the *order* status plus
   `date_paid`; `wc_get_is_paid_statuses()` is `[processing, completed]` (`wc-order-functions.php:134-143`).
   An order holding `captured` + `pending` payments renders to Woo as one paid/unpaid bit, and tender-grouped
   reporting cannot come from core.
4. **`Payment.refunds[]` has no core mirror.** `WC_Order_Refund` records `amount`, `reason`, `refunded_by`,
   `refunded_payment` and **nothing identifying which tender was refunded** (`class-wc-order-refund.php:38-43`);
   the parent order's REST `refunds[]` is `{id, reason, total}`. Per-payment attribution must be POS-owned
   meta on the refund (refunds accept `meta_data` over REST —
   `class-wc-rest-order-refunds-controller.php:73-78`).
5. **"Gift cards must not be forced into one model" is confirmed, and understated.** §4 shows all four
   products reduce the payable amount rather than record a payment. A `stored_value` tender that assumes
   "amount tendered, order total unchanged" double-counts against every one of them. The descriptor needs an
   explicit *reduces order total / reduces discount_total / records a payment* axis.
6. **Ledger home — meta vs table.** Order meta via CRUD is the only pattern the HPOS recipe book documents,
   works in both storage modes free, and round-trips over `wc/v3` `meta_data` even with a leading underscore
   (§1). Its cost is exactly the strawman's stated one: a JSON blob is **not** queryable, so "filter orders by
   any tender" cannot be a `meta_query`. A custom table is sanctioned (§3b) but that hook is **HPOS-only** —
   the CPT lane and both query lanes need separate wiring. Middle path core already supports: **flat,
   queryable meta keys** (one `_wcpos_tender` row per tender, for filtering) alongside the JSON ledger.
7. **Client-minted `Payment.id` as idempotency key does not conflict with core** — but `payment_complete()` is
   already accidentally idempotent (the else-branch at `class-wc-order.php:187` makes a second call a silent
   no-op), so a retry will **not** re-stamp `date_paid` and will **not** re-fire
   `woocommerce_payment_complete`. No POS listener on that action may assume once-per-recorded-payment.
8. **`created_via` is a first-class column** (`wp_wc_order_operational_data.created_via`,
   `OrdersTableDataStore.php:3266`) — the cheap queryable way to mark POS orders, no meta needed.
9. **Unverified:** exact order meta keys written by WooCommerce Gift Cards (paid, no source); Smart Coupons
   9.x behaviour (read against a 3.1.1 mirror); whether YITH's coupon path reliably yields a gateway-less
   order under block checkout; the Stripe Terminal "prepare → collect → capture" shape the survey flagged for
   R2 (out of scope here).
