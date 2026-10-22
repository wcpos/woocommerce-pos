# Spec: one pushable document for ADR 0040 rule 2 (c8)

**Date:** 2026-10-22 · **Lane:** `next` · **Binding:** ADR 0040 rule 2, D4 · **Stakes:** High (checkout money) · **Order:** independent of c2; lands before c2-6; rebase after #2400 (open), which edits the same function. Lines are `origin/next` at `201d99a2`.

## Outcome

One module answers "what may a push write?": `Pushable_Document::shape()` returns exactly the body wc/v3 receives, or the D4 422. `Order_Writer` calls it once per create and update. The v2 lane then strips rule 2 in one place, completely, without the before-save keeper.

**Name: `Pushable_Document`** (over `Push_Document`, `Order_Push_Shape`): it names the result, as the wire's `pushable` does.

## The module: `includes/API/V2/Writers/Pushable_Document.php`

```php
final class Pushable_Document {
	public const STATUSES = array( 'pos-open', 'pending', 'on-hold', 'cancelled' ); // moved from Order_Writer::PUSHABLE_STATUSES
	public function __construct( Order_Write_Payload $payload );
	/** $id 0 is a create. On an update $order is the locked read (null only if it did not resolve). @return array|WP_Error */
	public function shape( array $payload, int $id = 0, ?\WC_Order $order = null );
}
```

**Fixed order**, each step on the previous one's output:

| # | step | today |
|---|---|---|
| 1 | **Status (D4)**: strip the `wc-` prefix. An echoed non-pushable current status is dropped. Any other non-pushable status returns 422 `wcpos_status_not_pushable` `{requested, pushable}` and no further step runs. | `Order_Writer::with_pushable_status` 908–931 |
| 2 | **Top-level**: drop `Pos_Order_Audit::SERVER_MONEY_FIELDS` and `tax_ids` (persisted separately). On create set `created_via` to `woocommerce-pos`; on update drop it. | `without_money` 934–939; 82–84, 686 |
| 3 | **Order meta**: `strip_audit_meta( $meta, audit_meta_ids( $order ) )`, by key (audit, money, data-store internal) or by the meta id of a protected stored row under any key. That covers `value: null` tombstones and keyless `{id, value}` entries, which WooCommerce resolves by id and deletes. Ids come from `$order`; the re-read at 944 goes. | `without_pos_audit_meta` 942–946 |
| 4 | **Lines**: `for_create()` or `for_update( $id )`. Inside those, id reconciliation runs first and then `without_internal_item_meta` (by key and by meta id). | `Order_Write_Payload` 74–106 |

Line meta stays in `Order_Write_Payload`: it must run after `reconcile_order_item_ids` (where a uuid-only line gets its `id`), and v1's `for_create` shares it. The money strip cannot go there, because v1 accepts `set_paid` (`V1/Orders_Controller.php:510`). The module is the only v2 caller of `for_create`/`for_update`, so step 4 is in its guarantee. No save, no hook, no read beyond `$order`; till meta and reassignment still read the raw `$payload`. A null order on update (today's behaviour, unreachable under the lock) protects no ids.

## The invariant c2-6 relies on

> **I-c8.** A v2 push leaves unchanged the stored rows of every `protected_order_meta_keys()` key, each line's internal-key and `_reduced_stock` rows, `date_paid`, `payment_method(_title)` and `transaction_id`, and never sets a non-pushable status. This holds for every entry form (key; meta id under the same key, another key or none; tombstone) and **with `keep_closure_stamps` unhooked**.

Today the keeper can mask a gap in the strip, because it restores a deleted ledger and merges stamped rows (Ledger.php:364–409). With I-c8 pinned, the push lane needs nothing from the keeper, so c2-6 can delete its write path outright instead of shrinking it to a strip; amend c2 §Keeper. v1 and `Core_Order_Audit_Guard` keep their own strips over the same list.

## Where the list lives

It stays in `Pos_Order_Audit` (:44, :67). **Deletion test:** remove it and it reappears in four readers: v1 `Orders_Controller` (:431, :504), `Core_Order_Audit_Guard` (:132, :169), the v1 trait (:380) and this module. Three are not push-only, so the module cannot own it.

Tidy: the literal `'_wcpos_commands'` (:49) becomes `Command_Log::META_KEY`.

## Order_Writer

- **Deleted:** `with_pushable_status()`, `without_money()`, `without_pos_audit_meta()`; `PUSHABLE_STATUSES` moves to the module. No test or Pro code reads them.
- `prepare_create()` 78–87 and 98 become one `shape( $payload )`; till meta reads the raw `$payload` as at 88.
- `prepare_order_update_after_read()` 670–689 and 750 become `$forward = $this->document->shape( $payload, $id, $order ?: null )`, refusal returned unchanged. `clear_email` (690) reads `$payload['billing']`, identical because steps 1–3 never touch billing.
- The constructor builds `new Pushable_Document( $this->order_payload )`. `Order_Write_Payload` is unchanged.

## Defect found: raw readers match a server key case-insensitively (suspected, failing test first)

The strip and WooCommerce match keys exactly, but `Ledger::stored()` (:183, `meta_key = %s ORDER BY id LIMIT 1`) runs under the table's `_ci` PAD SPACE collation, where `_WCPOS_PAYMENTS` and `_wcpos_payments␠` match. Suspected path: that row gets through the strip; on an order with no ledger yet (push, then pay) `stored()` reads it as the ledger, and the keeper merges its "stamped" rows into `_wcpos_payments` on the next ledger save (398–409). Under c2 `stored()` is `write()`'s locked read, so the hole outlives c2-6.

Fix at the reader: add `AND CAST(meta_key AS BINARY) = %s` to `stored()` and `Payment_Index::order_id_for_payment` (38, 50). Candidate-only scans (`Payment_Index`:85/96, `Session_Figures`:198/566/596) re-read through exact getters and stay. If the failing test passes on wp-env MariaDB, drop this section and report.

## Error codes

None renamed and none added. 422 `wcpos_status_not_pushable` `{requested, pushable}` stays; every strip is still silent.

## Tests

**Failing test first** (`tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php`): `test_a_case_variant_ledger_key_pushed_before_payment_is_never_read_as_the_ledger`.
On a `pos-open` order with no ledger, push `_WCPOS_PAYMENTS` holding a captured full-total row with a `closure_id`, then record a 1.00 cash tender. Assert that `read()` holds only the 1.00 row and the status is `pos-partial`.

**The invariant**, same file: `test_push_naming_every_server_owned_key_in_every_form_writes_none_of_them_without_the_keeper`.
- Run it for create and update, with `keep_closure_stamps` unhooked and restored in `finally`.
- Send every key in `server_money_meta_keys()` (:127) and the internal order keys by key and as a key tombstone. For each stored row, also send it by id (same key), by id (`harmless`), as an id tombstone and as a keyless id entry. Do the same for a stored line's `_reduced_stock` and `_qty`, and send every top-level money field.
- Assert: `stored_meta_rows()` for the order and the line is byte-identical; `date_paid`, method and transaction id are unchanged; `woocommerce_payment_complete` never fires; `harmless` is absent.

**At the module interface** (`tests/includes/Sync/Writers/Test_Pushable_Document.php`), one `test_shape_…` each: `wc-` status normalised; paid status refused before any other step; echoed non-pushable status dropped; money fields and `tax_ids` dropped, `created_via` set on create and dropped on update; server keys dropped by key, by id, as tombstones and as keyless id entries; public meta kept in order; line meta dropped by id on a line matched only by uuid (step 4 after reconciliation); an update with no order still strips by key.

**List completeness**, in `Pos_Order_Audit_Test`: `test_every_order_meta_key_the_money_modules_declare_is_server_owned`. It reflects every `*META_KEY` constant of `Ledger`, `Completion_Stamp`, `Gateway_Submission`, `Command_Log` and `Fiscal_Record_Writers`, so a c2 or c4 key cannot skip the list.

**Survive unchanged (they pin today's behaviour):**
- `Test_Rest_Dispatch_Money_Model`: 165, 213 (key, id, rename-by-id and tombstone), 269, 297 with its provider at 314, 329, 1273, 1332, 1355
- `Test_Order_Writer`: 11, 65, 78 (an update with no order), 93
- `Pos_Order_Audit_Test`: 21, 189, 222
- `Test_Write_Controller`: 1097, 1771, 1982, 2178, 2236, 2255, 2289
- `Test_Order_Write_Payload`: whole file

## Pro impact

On Pro `origin/next` (`43de04e`), a grep for every symbol and code named here, plus `audit_meta_ids` and `order_id_for_payment`, has no hits. Nothing in Pro changes.

## Budget

About 130 added production lines (net about +75): `Pushable_Document` about 110, `Order_Writer` +12 / −55, the reader fix about 8, `Pos_Order_Audit` 1. Cap 600; stop and report before 400.

## Open for Paul (one line)

`_wcpos_checkout_state` (`Checkout_State_Repository`:19) is server-written, reported to the till as checkout status (`V1/Checkout_Controller.php`:223, 332) and unlisted. **Recommend: add it here** (alternative: leave it, since v1 checkout derives no paid state).

## Rulings (orchestrating session, 2026-10-22)

- `_wcpos_checkout_state` joins `SERVER_MONEY_META_KEYS` in this PR: it is server-written and reported to the till; nothing legitimate pushes it.
- D13 (the case-insensitive key match) is the FIRST item of the PR: failing test first (a push carrying `_WCPOS_PAYMENTS` with a forged captured row must leave the ledger's `read()` unchanged and the order unpaid), then the fix in BOTH places: the raw readers compare the key byte-exactly (`BINARY meta_key = %s` or a PHP-side exact filter on the fetched rows — pick the one that keeps the index usable and say why), and the strip matches server-owned keys case-insensitively so no spelling of them reaches wc/v3. Audit every other raw `meta_key = %s` read of a server-owned key in `includes/` (`Payment_Index`, `Completion_History`/`Completion_Stamp`, `Gateway_Submission`, `Command_Log`, `Session_Figures`) for the same hole and fix each found in the same PR, with one test per reader.
- The invariant test runs with `keep_closure_stamps` unhooked, so c2-6 may delete the keeper's write path outright; the c2 spec's keeper paragraph is amended accordingly (strip only, no write path to keep).
- Rebases after #2400 (c1) merges; it edits `Order_Writer::prepare_order_update_after_read` too.
