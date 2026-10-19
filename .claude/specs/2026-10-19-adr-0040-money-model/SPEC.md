# Spec: ADR 0040 on the server — the money model without an epoch

**Date:** 2026-10-19 · **Lane:** `next` · **Decision:** `docs/adr/0040-server-derives-paid-no-money-epoch.md` (accepted, #2332) · **Handoff:** `.claude/handoffs/2026-10-19-adr-0040-money-model/HANDOFF.md` (D1–D6 settled by Paul, not re-asked here) · **Vocabulary:** `GLOSSARY.md` · **Stakes:** High (checkout money, stock) · **Ground truth:** every claim below was read from `origin/next` at `81499e59` on 2026-10-19; where the handoff's table was off, the correction is named.

This spec covers the whole design the handoff describes, PR by PR, and names the pinning test for every rule. PR 1 (the push lane) is specified to implementation depth; PRs 2–5 are specified to the depth the ADR and handoff fix them, so the PR 1 shapes (key list, lock, error codes) are built once and reused.

## 0. Ground-truth corrections to the handoff

| Handoff said | `origin/next` actually | Effect |
|---|---|---|
| `is_editable()` refuses paid, completed, cancelled, refunded, failed | WooCommerce's `WC_Order::is_editable()` is `pending`, `on-hold`, `auto-draft` plus the `wc_order_is_editable` filter. WCPOS adds `pos-open` **only in wp-admin** (`Admin\Orders\Single_Order`, constructed from `Admin.php:167`). On a REST request a `pos-open` order is **not** editable, and `pos-partial` is editable nowhere. | Rule 4 cannot be built on the bare core call. §3.6 registers the filter globally for both POS open statuses. |
| Stock helper is `wc_adjust_line_item_product_stock()` | The helper is `wc_maybe_adjust_line_item_product_stock( $item, $item_quantity = -1 )` in `includes/admin/wc-admin-functions.php`; wc/v3 `require_once`s it and calls it only when the order is `processing`, `completed` or `on-hold` (`WC_REST_Orders_V2_Controller::set_item()` 1082–1088). It diffs the line's `_reduced_stock` meta against the posted quantity, so it is idempotent; quantity `0` restores the whole reduction. | §3.7 uses it for every line, pre-save for removed lines. |
| Pushable statuses in the rule-2 row include `failed` | D4 rules `failed` server-owned. | §3.4 set is D4's: `pos-open`, `pending`, `on-hold`, `cancelled`. |
| `tax_lines` is a line type the till can change | wc/v3's `tax_lines` schema is `readonly`; a posted value is ignored by WooCommerce. | §3.6 does not read it for change detection. |

Everything else in the handoff's ground-truth table was verified as written (ledger constants, `derive()` early return, the push lock only for a cart restart or a pushed `_wcpos_payments`, `set_paid` → `_pos_payment_asserted = offline` on create and on update while `needs_payment()`, `Protocol_Gate` at 2/2 scoped to the non-exempt `wcpos/v2` surface, the attempt history states `sent|settled|paid|cancelled|superseded`, `Pos_Order_Audit::SERVER_META_KEYS` private and `_pos_payment_asserted` in it, no `_wcpos_commands` / `_wcpos_completions` / `_wcpos_completed_at` anywhere yet).

Pro (`woocommerce-pos-pro` at `origin/next`, grep run 2026-10-19, non-empty result): reads `Ledger::COUNTING_STATUSES`, `Ledger::LIVE_STATUSES`, `Ledger::instance()->read()/balance()/summary()`, `Ledger::available_for_refund()`, `Ledger::to_wire()`, `Order_Lock`, `Descriptor_Builder`, `Protocol_Gate::SERVER_PROTOCOL` (sent to the order-pay client as a string). It never references `Order_Writer`, `set_paid`, `_pos_payment_asserted`, `_wcpos_payments` by name, or `find_sale`. PR 1 changes none of those signatures; the constant bump reaches Pro's order-pay panel automatically and is correct there.

## 1. Vocabulary used below

**Server-owned key** — a document key the push lane drops before the write (ADR rule 2). **Money-sensitive push** — an update whose payload names lines, fees, shipping, coupons, `currency`, `status`, `customer_id`, or a cart restart (`date_created_gmt`). **Open status** — `pos-open`, `pos-partial`, `pending`, `on-hold` (editable after §3.6). **In motion** — a `pending` or `authorized` row, a `sent` gateway attempt, a `pending` refund allocation. **Locked read** — `wc_get_order()` taken after `Order_Lock` was acquired for the push.

## 2. Error codes (the whole design)

| Where | Code | Status | Meaning |
|---|---|---|---|
| gate | `wcpos_update_required` | 426 | protocol below 3 (D1) |
| push | `wcpos_status_not_pushable` | 422 | status outside D4's set |
| push | `reopen_required` | 422 | line change on a non-editable order (rule 4) |
| push | `wcpos_money_in_motion` | 422 | reopen or cancel while money is in motion (rule 3) |
| push | `wcpos_currency_locked` | 422 | currency change with any money on the order (rule 6b) |
| push | `woo_rxdb_sync_record_locked` | 409 | order lock held elsewhere; retry (rule 6b) |
| push | `woo_rxdb_sync_conflict` | 409 | stale `baseRevision` (unchanged) |
| routes | `wcpos_payment_locked` | 409 | order lock held (unchanged) |
| routes | `wcpos_revision_stale` | 409 | stale `revision` on a fresh command (PR 3) |
| routes | `wcpos_command_conflict` | 422 | same `command_id`, different body (PR 3) |

422 on the push lane, 409 on the route family: #2312's ruling, kept.

## 3. PR 1 — the push lane (`feat/adr-0040-1`)

### 3.1 Protocol 3 (D1)

`Protocol_Gate::MIN_PROTOCOL = 3`, `SERVER_PROTOCOL = 3`. Nothing else in the gate changes. Every test helper that models a current client sends `3`: `WCPOS_REST_Unit_Test_Case::wp_rest_get_request/post_request/patch_request`, `Test_Settlement` (220), `Test_Response_Envelope` (351), `Test_POS_Endpoint_Permissions_Matrix` (577), `Test_Echo_Probe` (114), `tests/Tools/fast-path-fixture/record-products-fast-path-fixture.php` (79).

Pins — `Test_Protocol_Gate` (adjusted: `min_protocol`/`server_protocol` 3; `2` is refused; `3` and `4` pass) and new in `tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php`:
- `test_push_from_a_protocol_2_client_is_refused_426_with_nothing_reserved` — `POST /wcpos/v2/push/orders` with `X-WCPOS-Protocol: 2` → 426 `wcpos_update_required`; the mutation id is not in `wcpos_sync_mutations`; no order exists for the record uuid.
- `test_payment_record_from_a_protocol_2_client_is_refused_426` — `POST /wcpos/v2/orders/{id}/payments` with protocol 2 → 426; the ledger is still empty.

### 3.2 ADR 0035 path 3 is deleted (D1)

Remove from `Order_Writer`: `PAYMENT_ASSERTED_META`, `PAYMENT_ASSERTED_OFFLINE`, `asserts_payment()`, the marker fills in `prepare_create()`, `validate_existing_create()` (the method stays, it only re-stamps audit), `prepare_order_update_after_read()`, `stamp_order_audit()`; `$declared['set_paid']` in `forward()`; the `set_paid` / `transaction_id` intent in `forward_with_reserved_stock()`. `around_paid_create()` keeps working for the one pushable status that is not stock-exempt (`on-hold`): the create lands as `pending`, stock is validated and held, the requested status is applied; `set_paid` is always false there. `Order_Write_Intent` keeps its `set_paid` field for the v1 lane's ad-hoc observation; the v2 declaration never sets it.

`Pos_Order_Audit::SERVER_META_KEYS` keeps the literal `_pos_payment_asserted` with a comment: the key is a legacy marker (ADR 0035 path 3, removed under ADR 0040); orders written before 2.0.0 carry it and a client may still not write it. `docs/adr/0035-hook-parity-contract.md` gains one line under path 3: *Path 3 is removed under ADR 0040 (2.0.0): the push lane carries no money; cash is recorded through `POST orders/{id}/payments`.*

The `wcpos/v1` orders controller's own `set_paid` path (`API\V1\Orders_Controller` 140–164, 441, 519) is **out of PR 1's scope**: it is the frozen data lane, not the push lane, and no 2.0 client reaches it. It is listed in §7 as a decision for Paul before PR 5, not changed here.

Tests deleted with the path: `Test_Rest_Dispatch_Till_Meta::test_set_paid_create_stamps_offline_payment_assertion_and_cashier`, `::test_create_without_set_paid_does_not_stamp_payment_assertion`, `::test_set_paid_update_stamps_offline_payment_assertion`, `::test_set_paid_update_does_not_relabel_a_gateway_paid_order`, `::test_replayed_create_does_not_relabel_a_gateway_paid_order`; `Test_Write_Controller::test_offline_completed_create_snapshots_cashier_and_tender_at_payment_complete`, `::test_update_completing_payment_fills_missing_audit_meta_before_payment_complete` (both pinned audit meta being present when `set_paid` fired `payment_complete` inside the forward; under ADR 0040 `payment_complete()` fires from the ledger on a later request, after the create push stamped the audit trail — the property has no push-lane subject left); `Test_Hook_Parity::test_parity_order_set_paid_checkout_path` (parity of a path that no longer exists on v2). Rewritten: `Test_Overselling_V2_Reservations` pushes `status: on-hold` without `set_paid`/`payment_method` (the hold is taken for an `on-hold` create exactly as it was for a paid one; the three case names say `on_hold` where they said `paid`); `Test_Rest_Dispatch_Cart_Restart::test_restart_and_checkout_in_one_push_takes_the_restart_date` becomes `test_restart_and_hold_in_one_push_takes_the_restart_date` (`status: on-hold`, no `set_paid`); `::test_a_held_payment_lock_forwards_the_update_without_the_restart` is replaced by §3.5's lock pin (a restart push changes lines, so a held lock now answers 409, not a restart-less forward).

### 3.3 Server-owned keys (rule 2)

New constants in `Pos_Order_Audit` (beside `SERVER_META_KEYS`), public so PR 2 and PR 3 reuse them:

```php
/** Meta the server alone writes: the ledger and its index rows, the command and completion maps,
 *  the gateway attempt history and stamp, and the WooCommerce money meta a push could address by id
 *  on posts storage. A new server-owned key joins this list in the change that introduces it (ADR 0040 rule 2). */
public const SERVER_MONEY_META_KEYS = array(
	Ledger::META_KEY, Ledger::INDEX_META_KEY, Ledger::PAYMENT_ID_META_KEY, Ledger::LIVE_LEG_META_KEY,
	'_wcpos_commands', '_wcpos_completed_at', '_wcpos_completions',
	Gateway_Submission::ATTEMPTS_META_KEY, Gateway_Submission::STAMP_META_KEY,
	'_payment_method', '_payment_method_title', '_transaction_id', '_date_paid', '_paid_date',
);
/** Top-level wc/v3 fields that carry money; dropped from every push. */
public const SERVER_MONEY_FIELDS = array( 'set_paid', 'date_paid', 'date_paid_gmt', 'transaction_id', 'payment_method', 'payment_method_title' );
```

`audit_meta_keys()` returns `SERVER_META_KEYS + TILL_META_KEYS + SERVER_MONEY_META_KEYS`, so `strip_audit_meta()` drops a money key by key and `audit_meta_ids()` collects its existing row ids, which makes an id-addressed edit or tombstone (`{id: N, value: null}`, `{id: N, key: 'harmless', value: …}`) of a money row drop too — the same mechanism that already protects audit rows. (`sanitize_create_meta()` on the v1 lane inherits the strip; that is correct under rule 2 and costs v1 nothing, since no v1 client sends those keys.)

`Order_Writer` gains `private static function without_money( array $payload ): array` that unsets every `SERVER_MONEY_FIELDS` key; `prepare_create()` and `prepare_order_update_after_read()` call it on the forward. `Ledger::keep_closure_stamps()` stays as the keeper for every other order-writing lane; the push lane simply never reaches its merge branch any more. The `_wcpos_payments` lock branch in `Order_Writer::forward()` (lines 167–185) is deleted; §3.5's lock replaces it. `Ledger::save_was_refused()` after the forward stays (a keeper refusal on an index-only re-derive is still a 409, not a 2xx).

Pins (`Test_Rest_Dispatch_Money_Model` + its `_HPOS` twin, storage read back with `$order->get_data_store()->read_meta( $order )` and `get_date_paid( 'edit' )` / `get_payment_method( 'edit' )` on a fresh `wc_get_order()`, never the object the push returned):
- `test_create_carrying_every_server_owned_key_stores_none_of_them` — create with every `SERVER_MONEY_FIELDS` key set and every `SERVER_MONEY_META_KEYS` key in `meta_data` → 201; storage has no row for any of the meta keys, `date_paid` null, `payment_method` `''`, `transaction_id` `''`, status `pending`, `payment_complete` never fired (hook counter 0).
- `test_update_carrying_every_server_owned_key_by_key_and_by_meta_id_leaves_storage_unchanged` — a `pos-open` order seeded through `Ledger::save()` with one captured row, a gateway attempt in history and a stamp; the push sends each money meta key by key with a changed value, by `id` with a changed value, and by `id` with `value: null`, plus the top-level fields → 200; `read_meta()` for every money key is byte-identical to before; `date_paid` still null; a `customer_note` in the same push did land (the push was applied, only the keys were dropped).
- `Test_Order_Writer::test_prepare_create_drops_money_fields_and_meta`, `::test_prepare_update_drops_money_fields_and_meta` — unit shape pins without wc/v3.

### 3.4 Pushable statuses (D4)

`Order_Writer::PUSHABLE_STATUSES = array( 'pos-open', 'pending', 'on-hold', 'cancelled' )`. In `prepare_create()` and `prepare_update()` (before the pre-read, like `tax_ids`): when the payload has `status`, normalise a `wc-` prefix away; a value outside the set → `WP_Error( 'wcpos_status_not_pushable', …, array( 'status' => 422, 'requested' => $status, 'pushable' => PUSHABLE_STATUSES ) )`. Absent or empty status is untouched (wc/v3 defaults a create to `pending`). `pos-partial` is derived and never pushed; `failed` and every paid status are server-owned.

Pins:
- `test_create_with_a_paid_status_is_refused_422_and_creates_nothing` — `status: processing` → 422 `wcpos_status_not_pushable`; no order for the uuid; mutation released.
- `test_update_to_processing_on_an_uncovered_order_is_refused_and_sets_no_date_paid` — `pos-open` order, no rows, `status: processing` → 422; storage status `pos-open`, `date_paid` null; the `wc-completed` spelling is refused the same way (data provider over `processing`, `completed`, `wc-completed`, `refunded`, `pos-partial`, `failed`, the configured per-gateway POS status).
- `test_update_to_each_pushable_status_is_accepted` — data provider over the four; `cancelled` on an order with no money → 200 and status `cancelled`.

### 3.5 The order lock around compare and write (rule 6b)

`Null_Writer::with_write_lock( int $id, array $payload, callable $work )` returns `$work()`. `Order_Writer::with_write_lock()` acquires `Order_Lock::instance()` when `self::is_money_sensitive( $payload )` (any of `line_items`, `fee_lines`, `shipping_lines`, `coupon_lines`, `currency`, `status`, `customer_id`, `date_created_gmt` present), answers `WP_Error( 'woo_rxdb_sync_record_locked', …, 409 )` when it cannot, runs `$work()` and releases in `finally`. `Write_Controller::apply_update()` wraps everything from the current read (`document_for()`) through the revision compare, the context factory (the pre-read), `forward()` and `after_update()` in `$writer->with_write_lock( $id, $m['payload'], … )`. The `Mutation_Store` record lock is already held when this runs, so the nesting order is the one `forward()` used. A push that is not money-sensitive (`customer_note`, billing, `meta_data` only) holds no order lock, as today. `forward()`'s own restart lock goes (the outer lock covers a restart, which is money-sensitive by `date_created_gmt`); `cart_restart_gmt()` and the pre-insert re-decision stay, because the lock is the serialisation and the re-decision is the rule.

Pins:
- `test_line_edit_push_is_refused_409_while_another_writer_holds_the_order_lock` — `new Order_Lock()` holds it; a line edit push → 409 `woo_rxdb_sync_record_locked`; storage unchanged; mutation released; the same push after release → 200 (replaces the deleted ledger-push case in `Test_Write_Controller`).
- `test_note_only_push_proceeds_while_the_order_lock_is_held` — kept (`Test_Write_Controller::test_order_push_without_ledger_proceeds_while_the_payment_lock_is_held`, renamed).
- `test_the_order_lock_is_held_from_the_revision_read_through_the_write` — a `woocommerce_rest_pre_insert_shop_order_object` filter at priority 20 records `( new Order_Lock() )->acquire( $id )` → `false` during a line edit push, and the same probe on a `rest_pre_dispatch` of the inner `/wc/v3/orders/{id}` read → `false`; after the push → `true`.
- `test_capture_landing_before_the_push_is_a_revision_conflict` — read the revision, `Ledger::instance()->record()` a covering cash row (order completes), push the line edit with the old revision → 409 `woo_rxdb_sync_conflict`, `currentRevision` differs, lines unchanged. Twice: once with `line_items`, once with `currency` as the payload.

### 3.6 Editable statuses and the `reopen_required` gate (rule 4, D3)

**`Orders.php` registers `wc_order_is_editable`** (same priority 10) returning `true` for `pos-open` and `pos-partial`; `Admin\Orders\Single_Order` drops its copy. Reason: ADR rule 2 calls both POS statuses open; D4 makes `pos-partial` derived, so a layaway line edit cannot go through a status flip to `pos-open` (the next money command would flip it straight back and the document would lie in between); `is_editable()` has to answer the same on every request, not only in wp-admin. Pin in `tests/includes/Test_Orders.php`: `is_editable()` true for `pos-open`, `pos-partial`, `pending`, `on-hold` and false for `processing`, `completed`, `cancelled`, `refunded`, `failed` on a plain `wc_get_order()` outside admin.

**The gate** runs in `prepare_order_update_after_read()` on the locked read. `Order_Write_Payload::lines_changed( $order, array $forwarded ): bool` (new, public, beside `for_update()` because it reads the forwarded shape that method produces) answers true when any of `line_items`, `fee_lines`, `shipping_lines`, `coupon_lines` is present and contains: an entry without a numeric `id` (a new line); an entry whose `id` is not on the order; a wc/v3 removal (`item_is_null()` shape — `product_id`/`method_id`/`name`/`code` null — or `quantity` 0); or an entry whose posted money field differs from the stored item (`quantity`, `subtotal`, `total`, `product_id`, `variation_id` for lines; `total`, `tax_class`, `name` for fees; `total`, `method_id` for shipping; `code` for coupons; decimals compared through `wc_format_decimal()` at store precision). `tax_lines` is not read (wc/v3 ignores it). A full-document push whose lines equal storage is **not** a line change, so a note or address edit on a paid order still lands.

When `lines_changed()` is true and `! $order->is_editable()` on the locked read, the push is refused with `reopen_required` (422, `data.status` = current status) **unless** the payload's `status` is in `PUSHABLE_STATUSES` and editable (`pos-open`, `pending`, `on-hold`): then D3 applies — the money-in-motion check of §3.8 runs first, then the status, lines and stock deltas go through as one write. `cancelled` in the payload never unlocks lines.

Pins:
- `test_line_change_on_a_processing_order_is_refused_reopen_required` — quantity change → 422 `reopen_required`; item quantity and `_reduced_stock` unchanged in storage; status unchanged. Data provider over `processing`, `completed`, `cancelled`, `refunded`, `failed`.
- `test_full_document_with_unchanged_lines_on_a_paid_order_applies_the_note` — the order's own serialized document plus `customer_note` → 200; note stored.
- `test_line_change_on_an_on_hold_order_is_applied` — core-editable status, no reopen needed.
- `test_reopen_and_line_change_in_one_push_is_one_write` (D3) — paid `processing` order with one captured cash row, payload `status: pos-open` + quantity change → 200; storage status `pos-open`, quantity applied, the row still present and unchanged (`read_meta()` byte-equal), `date_paid` unchanged, exactly one reopen order note.
- `test_reopen_with_cancelled_in_the_payload_does_not_unlock_lines` — `status: cancelled` + quantity change on `processing` → 422 `reopen_required`.

### 3.7 Stock deltas on a reopened order (rule 4b)

When the locked pre-read says `$order->get_data_store()->get_stock_reduced( $id )` is true and the forward is an update, `forward_with_order_lifecycle()` adds two hooks scoped to the intent's subject (same `is_subject()` guard as the provenance filter): on `woocommerce_rest_pre_insert_shop_order_object` (priority 20, after the intent at 1 and the stock validator at 10) every stored `line_item` whose id is no longer on the object about to be saved gets `wc_maybe_adjust_line_item_product_stock( $stored_item, 0 )` (restores its reduction while the item row still exists); on `woocommerce_rest_insert_shop_order_object` every `line_item` on the saved order gets `wc_maybe_adjust_line_item_product_stock( $item )` (a new line reduces in full, a changed quantity by the difference, an unchanged line is a no-op because `_reduced_stock` already matches). Each change adds wc/v3's own "Adjusted stock: name (from→to)" note. `require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php'` exactly as wc/v3 does. On an `on-hold` order wc/v3 has already adjusted set items; the post-save pass then diffs to zero, so nothing double-counts. An order whose stock was never reduced (an unpaid `pos-open` cart) gets no hook: its stock moves at `payment_complete()`.

Pins (product managing stock, qty 10; paid `processing` order with 2 units → stock 8, `_reduced_stock` 2; reopened in the same push with `status: pos-open`; assert through a fresh `wc_get_product()->get_stock_quantity()` and the line's `_reduced_stock`):
- `test_adding_a_line_to_a_reopened_paid_order_reduces_that_product` — second product 5 → 4.
- `test_removing_a_line_from_a_reopened_paid_order_restores_its_stock` — 8 → 10.
- `test_changing_quantity_on_a_reopened_paid_order_moves_stock_by_the_difference` — 2 → 3: 8 → 7; then 3 → 1 in a second push: 7 → 9.
- `test_line_change_on_an_unpaid_open_cart_leaves_stock_alone` — `pos-open`, stock never reduced → 10 stays 10.

### 3.8 Money in motion refuses a reopen or a cancel (rule 3)

In `prepare_order_update_after_read()` on the locked read, when the payload's `status` moves the order **out of a non-editable status into an editable one** (reopen) **or into `cancelled` from any status**, read `Ledger::instance()->read( $order )`, `Gateway_Submission::read_attempts( $order )` and `read_stamp( $order )`: a row `pending` or `authorized`, an attempt `sent`, a stamp present, or a refund allocation (`$row['refunds'][*]['status']`) `pending` → `WP_Error( 'wcpos_money_in_motion', …, array( 'status' => 422, 'payment_id' => …, 'attempt_id' => … ) )`, nothing written. Captured, failed and voided rows never block; nothing is archived or re-stamped; a reopen writes no completion entry (PR 2 owns the history) and leaves `date_paid`, `payment_method` and `transaction_id` as they are. The cancel case is this spec's extension of the handoff's rule-3 scope: the ADR calls these "locks on money in motion, not money rules", and a cancel with a card leg still pending would otherwise let the provider land money on a cancelled order; it is listed in §7 for Paul to strike if he disagrees.

Pins (seed rows through `Ledger::save( $order, $rows, false )`; attempts through the order's `_wcpos_gateway_attempts` meta the way `Test_Gateway_Submission` does):
- `test_reopen_with_a_pending_row_is_refused_money_in_motion` — `processing` + pending card row + `status: pos-open` → 422 `wcpos_money_in_motion`, `payment_id` named, status unchanged.
- `test_reopen_with_an_authorized_row_is_refused` — same with `authorized`.
- `test_reopen_with_a_sent_attempt_is_refused` — history `{attempt: 'sent'}`, `attempt_id` named.
- `test_reopen_with_a_pending_refund_allocation_is_refused` — captured row carrying one `pending` refund.
- `test_reopen_of_a_cancelled_order_with_a_standing_authorization_is_refused` — the handoff's pinning case.
- `test_reopen_of_a_paid_sale_with_captured_rows_is_accepted_with_rows_and_balance_unchanged` — `processing`, two captured rows covering the total, `status: pos-open` → 200; `read_meta( '_wcpos_payments' )` byte-equal; `Ledger::summary()` `paid`/`balance` unchanged; `date_paid` unchanged; one reopen note.
- `test_cancel_with_a_pending_row_is_refused_money_in_motion` — `pos-open` + pending row + `status: cancelled` → 422; `test_cancel_with_only_captured_rows_is_accepted` → 200 `cancelled`.

### 3.9 Currency is locked once money exists (rule 6b)

On the locked read, when the payload's `currency` is set and differs from `$order->get_currency()`, and the order has any ledger row (any status), any refund allocation, any attempt in history or a stamp → `WP_Error( 'wcpos_currency_locked', …, 422 )`, nothing written. There is no conversion protocol; a 100 USD row must not cover a 100 EUR total.

Pins: `test_currency_change_with_a_captured_row_is_refused_currency_locked` (storage currency unchanged, lines unchanged); `test_currency_change_with_a_voided_row_is_still_refused`; `test_currency_change_on_an_order_with_no_money_is_applied`.

### 3.10 Files PR 1 touches

`includes/Services/Protocol_Gate.php`, `includes/API/V2/Writers/Order_Writer.php`, `includes/API/V2/Writers/Null_Writer.php`, `includes/API/V2/Write_Controller.php`, `includes/Services/Pos_Order_Audit.php`, `includes/Sync/Order_Write_Payload.php`, `includes/Orders.php`, `includes/Admin/Orders/Single_Order.php`, `docs/adr/0035-hook-parity-contract.md`; tests named above plus `tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php` and `Test_Rest_Dispatch_Money_Model_HPOS.php` (the `HPOSToggleTrait` twin pattern of `Test_Gateway_Submission_HPOS`). No new table, column or option. Budget: `includes/` ≤ 450 added lines.

### 3.11 Verification order for PR 1

One phpunit invocation per file, in this stack (`/Users/kilbot/Projects/woocommerce-pos-worktrees/adr-0040-1`, ports 8900/8901, plugin mapped at `wp-content/plugins/woocommerce-pos`): `Test_Rest_Dispatch_Money_Model`, `_HPOS`, `Test_Order_Writer`, `Test_Write_Controller`, `Test_Protocol_Gate`, `Test_Rest_Dispatch_Till_Meta`, `Test_Rest_Dispatch_Cart_Restart`, `Test_Overselling_V2_Reservations`, `Test_Hook_Parity`, `Test_Orders`, `Test_Order_Lock`, `Test_Ledger`, `Test_Ledger_Sole_Writer`, `Test_Payments_Controller`, `Test_Gateway_Submission(_HPOS)`, `Test_Webview_Passthrough`, `Test_Payments_Sweeper`, `Test_Settlement`, `Test_Response_Envelope`, `Test_POS_Endpoint_Permissions_Matrix`, `Test_Echo_Probe`; then phpcs on the changed PHP, phpstan (bootstrap `vendor_prefixed/autoload.php`), `php scripts/lane-coverage.php --compare` against an `origin/next` baseline, `git diff --check`, the full suite once. Mutation checks: revert the strip → §3.3 pins fail; revert the lock wrap → §3.5's filter probe reads `true`; revert the editable filter → §3.6's `pos-partial` pin fails; revert the stock hooks → §3.7 pins fail.

## 4. PR 2 — the ledger (shapes fixed here, built after PR 1 merges)

- **Counting (D6, rule 1a):** `Ledger::COUNTING_STATUSES = array( 'captured' )`; `paid()` sums captured rows; `pending_reserved()` reserves `pending` **and** `authorized` rows. Pin: a full authorization leaves the order `pos-open` with `summary.authorized`; a second covering `record` is refused; `capture` completes it.
- **Signed balance:** `balance()` = total − Σ captured + Σ `succeeded` allocations; `summary.balance` is the clamped amount due, `summary.refund_due` the amount owed back when the signed balance is negative, `summary.refund_pending` the sum of `pending` allocations, never added. Pin: 100.00 captured, total reduced to 80.00 → `balance 0.00`, `refund_due 20.00`.
- **`close` (D2, rule 1b):** `POST /wcpos/v2/orders/{id}/close`, registered in `Payments_Controller` beside the family, same gate, lock, `command_id` and `revision` as the others (the latter two land in PR 3; until then the route takes neither). It re-derives; signed balance ≤ 0 on a non-zero total runs the paid transition; `derive()` accepts a `$closing` flag that lets it complete an order whose status is an editable status outside `IN_PROGRESS_STATUSES`. The push lane cannot complete a sale (§3.4 already refuses the statuses). Pins as the handoff's 1b row.
- **Completion stamp (rule 1c):** `Completion_Stamp` with one entry point on `woocommerce_payment_complete` at priority 5 (before `Fiscal_Record_Writers`), reading a request-scoped provenance context set by `record`/`capture`/`submit`/`close`, or the attempt's persisted provenance for a callback-completed `submit`, or the write-once till tuple for completion 1 and the webview mint; idempotent per `date_paid`/`_wcpos_completed_at` pair; origin gate as the handoff states; appends `_wcpos_completions[n] = { id, at_gmt, sale_time, sale_tz, register, session, counter, total }`, sets `_wcpos_completed_at`, fires `wcpos_sale_completed( $order, $n )` once per entry after the stamp is persisted. Both keys are already in §3.3's list.
- **Adopted online tender at reopen (D5):** in §3.8's accepted-reopen branch, when the order has `date_paid`, no counting rows and a `payment_method`, adopt a `captured` row (`source: woocommerce`, amount = total paid, `provider_refs.transaction_id` from `_transaction_id`, `captured_at_gmt` = `date_paid`) with `$derive = false` **before** the status transition, and import existing WooCommerce refunds as `succeeded` allocations. PR 2 adds `'woocommerce'` to `Ledger::SOURCES`. Not voidable from the till; refundable through `process_refund()`.
- **Voidability after reopen (rule 3):** `is_voidable()` consults `_wcpos_completions`: a captured manual row that counted in any completion stays non-voidable whatever the current status. Pin: void after reopen → refused; refund → allowed.
- **Fiscal identity (the handoff's Fiscal row, same PR):** `Fiscal_Record_Store::record()` keys `sale` and `cancellation` by `source_id` = the completion uuid; `find_sale( $order_id, ?string $completion_id = null )`; `sales_count()` counts completions with the legacy fallback; recovery on every money command; payload `supersedes_record_id` and signed adjustment; writer condition "has a completion entry". No backfill. `late_sale` keeps `order_id` identity unless PR 2's spec addendum finds it needs the completion id.

## 5. PR 3 — command idempotency and revision (rules 5, 6a)

- `command_id` (uuid) required on `intent`, `capture`, `void`, `refund`, `cancel`, `submit`, `close`; `record` and the webview mint use the row id. Row-level commands store `commands[id] = { fingerprint, outcome }` on the row, never evicted; row-less commands (`submit`, `cancel`, `close`) store in the order-level `_wcpos_commands` map (already in §3.3's list). Same id + same fingerprint replays the outcome; same id + different body → 422 `wcpos_command_conflict`; same id in flight → 409 `wcpos_payment_locked`.
- `revision` on every money command = `Order_Serializer::canonical_revision()` of the order document the till holds (the push lane's hash). Replay lookup first; a fresh command with a stale revision → 409 `wcpos_revision_stale` with `currentRevision`. The hash already covers `meta_data` (so `_wcpos_payments` moves it) and `date_paid`/`status`; PR 3 pins that a `record` moves the revision a later push compares against, and that the hash excludes `date_modified` (already true).

## 6. PR 4 and PR 5

PR 4: the refund allocation is written `pending` with its `command_id` before `process_refund()` and settled after; a retry with the same id does not call the provider again; a stranded `pending` allocation gets the order note and `summary.refund_pending`; the sweeper's provider lookup is verified. PR 5: `Descriptor_Builder` reports `contract: '1.3'`; the wiki pages the handoff lists are rewritten; the client handoff is written by the agent that finishes PR 5.

## 7. Decisions this spec makes that Paul should see (not re-asks of D1–D6)

1. **`pos-partial` is editable** (§3.6), globally, by the same filter that makes `pos-open` editable. Alternative rejected: requiring a reopen to `pos-open` for a layaway line edit, which the next money command would undo.
2. **A push into `cancelled` is refused while money is in motion** (§3.8), the same check as a reopen. Strike it and the cancel passes through as today.
3. **`lines_changed()` compares money fields only** (§3.6): a full-document push whose lines match storage is not a line change. A change to a line's display name alone is invisible to the gate, by design.
4. **The v1 `set_paid` path on `/wcpos/v1/orders` is untouched** (§3.2). It is the frozen data lane; D1 names the push lane. Whether it is stripped in 2.0.0 or dies with v1's deprecation is a question for Paul before PR 5.
5. **Overselling on the ledger path.** After PR 1 the only way a till completes a sale is a money command, and `Stock_Validator` guards none of them (`validate_stock` hooks only wc/v3 pre-insert; `around_paid_create` runs only for an `on-hold` create). Prevent-overselling at the moment of payment is therefore unenforced on `next` today and stays so after PR 1. It is a PR 2 design item (the hold belongs in `record`/`intent` under the order lock, before `derive()`), listed here so it is not lost.
