# Spec: collapse the Sale-state question into the Ledger (c1)

**Date:** 2026-10-22 · **Lane:** `next` (branch off `origin/next`, PR targets `next`) · **Binding:** ADR 0040 rules 3 and 4; handoff `.claude/handoffs/2026-10-19-adr-0040-money-model/HANDOFF.md` D3 (reopen and edit in one push) and D4 (pushable statuses) · **Stakes:** High (checkout money).

## Outcome

One module answers "what may happen to this Sale now, and what money is in motion?": `Ledger::sale_state( WC_Order $order ): Sale_State`. The push lane (`Order_Writer`) and the money-command routes (`Ledger::accepts_tender()`, `Ledger::close()`) each translate that value into their own refusal codes. Nothing is renamed. A push-lane delete meets the cancel gate, under the order lock.

Names considered: `sale_state()`, `money_state()`, `standing()`. **Pick `sale_state()`**: the glossary term, and it answers locking as well as money.

## The module: `includes/Payments/Contract/Sale_State.php`

- **Shape.** A `final` class with private properties and getters (PHP 7.4), built only by `Ledger::sale_state()`.
- **What it reads.** It is a snapshot of one order, read once: the rows (`read()`), the attempts and the awaiting stamp (`Gateway_Submission`), `Completion_Stamp::stands()`, `is_editable()`, the status and `date_paid`.
- **Invariant.** The snapshot is valid until that order is next saved. Take it on the locked read, and never reuse it across a save.
- **Cost.** `has_money()` runs a refunds query, so it is computed on first call and memoised.

| member | answers | replaces |
|---|---|---|
| `OPEN_STATUSES` = pos-open, pos-partial, pending, on-hold | statuses a sale is open in | Ledger:1745, Order_Writer:449 |
| `stands()` | the latest completion has not been reopened | `Completion_Stamp::stands` |
| `locked()` | `! is_editable() \|\| stands()` | Order_Writer:442 |
| `open()` | status is in `OPEN_STATUSES` | Ledger:1745 |
| `reopens( $to )` | `$to` is open, differs from the status, and `locked()` | Order_Writer:447 |
| `takes_tender()` | `! stands()` and the order is editable, or in `IN_PROGRESS_STATUSES`, or has a stamp | Ledger:1707–1708 |
| `already_paid()` | `stands()` or `date_paid` is set | Ledger:1711 |
| `in_motion(): ?array{payment_id, attempt_id}` | the first `pending`/`authorized` row, or a row with a `pending` allocation; else the `sent` attempt or the stamp's attempt | Order_Writer:531–554, Ledger:1747–1758 (a copy) |
| `has_money()` | rows, attempts, a stamp, `date_paid`, `is_paid()`, a transaction id, or refunds | Order_Writer:520 |

`reopens()` now accepts `pos-partial` as a target, which Order_Writer:449 did not. Unreachable: `with_pushable_status()` refuses it first (D4).

**Status lists.** There are five today; this PR leaves four:
- `Order_Writer::PUSHABLE_STATUSES` is unchanged. It is D4's wire contract.
- `Sale_State::OPEN_STATUSES` replaces Ledger:1745 and Order_Writer:449.
- `Ledger::IN_PROGRESS_STATUSES` is unchanged. It is the projection boundary for `derive`, `is_voidable` and the sweeper, which is a different question.
- `Completion_Stamp::OPEN_STATUSES` (line 29) is deleted. `on_status_changed()` uses `OPEN_STATUSES` plus `failed` and `cancelled`, through one private helper. The resulting set is identical.

## Ledger

- Add `sale_state()`, about 30 lines.
- `accepts_tender()`:
  - `takes_tender()`: return null.
  - `already_paid()`: return the existing 409 `wcpos_order_already_paid`.
  - Otherwise: return `invalid_transition()`.

- `close()`:
  - `stands()`: replay.
  - `! open()`: return `invalid_transition()`.
  - `in_motion()`: return `in_flight()` with today's keys: only `payment_id`, or only `attempt_id`, which is `null` when the stamp has none.

  Everything after these checks is unchanged. Delete the loop at 1747–1758.

## Order_Writer: what is deleted and what becomes a translator

- **Deleted:** `locked()`, `reopens()`, `has_money()` and `money_in_motion()`. All four are private and have no tests of their own.
- **Translator: `money_gate()`.**
  - It takes the state built once from the locked pre-read in `prepare_order_update_after_read()`, before `adopt_original_tender()` runs. The same state is reused at line 759; adopt changes neither the status nor `stands`.
  - Order, codes and data stay byte-for-byte:
    1. `wcpos_currency_locked`
    2. `wcpos_money_in_motion`, with both keys present and the attempt id cast to `string`
    3. `reopen_required`, with `order_status`
  - Extract `in_motion_refusal( array $motion ): WP_Error` so the delete gate can use it.
- **Unchanged:** `with_pushable_status()` and `adopt_original_tender()`.

## Delete joins the gate (defect)

`Order_Writer::delete()` (316–325) takes no lock and checks no money. Trashing an order with a `sent` attempt or an `authorized` leg strands money that the sweeper must still resolve. The cancel gate (rule 3) is the minimum.

- Add `Null_Writer::with_delete_lock( int $id, callable $work )`; by default it just runs `$work`. `Order_Writer` overrides it with the `Order_Lock` body of `with_write_lock()`, extracted as `private under_order_lock()`. A busy lock answers 409 `woo_rxdb_sync_record_locked`.
- `Write_Controller::apply_delete()` wraps the read, the compare and `$writer->delete()` (562–586) in that lock. This mirrors the update path at 483.
- `Order_Writer::delete()` re-reads the order and returns `in_motion()` → `in_motion_refusal()` (422) before `forward_with_stock_restore_rollback()`. So no stock pre-restore runs on a refusal.

**Open question for Paul:** should a force delete of a sale holding captured money also be refused (new 422 `wcpos_order_has_payments`)? Recommendation: no. wp-admin allows it, and trash keeps the rows.

## Error codes (client contract: none renamed)

| lane · trigger | before | after |
|---|---|---|
| push · status not pushable | 422 `wcpos_status_not_pushable` | same |
| push · currency change, money exists | 422 `wcpos_currency_locked` | same |
| push · reopen or cancel, money in motion | 422 `wcpos_money_in_motion` `{payment_id, attempt_id}` | same |
| push · line change, locked, no reopen | 422 `reopen_required` `{order_status}` | same |
| push · **delete, money in motion** | 200, deleted | **422 `wcpos_money_in_motion`** (existing code) |
| push · **delete, lock busy** | n/a | **409 `woo_rxdb_sync_record_locked`** (existing code) |
| route · tender, closed and paid | 409 `wcpos_order_already_paid` `{order}` | same |
| route · tender, closed and never paid; close, not open | 409 `wcpos_invalid_transition` | same |
| route · close, money in motion | 409 `wcpos_payment_in_flight` `{payment_id \| attempt_id, order}` | same |
| route · close, uncovered | 409 `wcpos_balance_outstanding` | same |

Client handoff: a delete can now answer 422 `wcpos_money_in_motion`.

## Tests

Follow the `CLAUDE.md` PHPUnit conventions.

**Failing test first**, in `tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php` (it is in `Sync/`, not `API/V2/`): `test_delete_with_a_pending_row_is_refused_money_in_motion_and_deletes_nothing`. Today it returns 200 and trashes the order.

Then, in the same file:
- `test_delete_with_a_sent_attempt_is_refused_money_in_motion`
- `test_force_delete_with_an_authorized_row_is_refused_and_restores_no_stock`
- `test_delete_with_only_captured_rows_is_accepted`
- `test_delete_holds_the_order_lock_from_the_revision_read_through_the_forward` (uses the lock probe from :367)
- `test_close_and_push_name_the_same_payment_in_motion`

**New at the Ledger interface**, in `tests/includes/Payments/Contract/Test_Sale_State.php`:
- `test_sale_state_with_a_pending_row_names_it_in_motion`
- `test_sale_state_with_a_pending_refund_allocation_names_its_row_in_motion`
- `test_sale_state_with_a_sent_attempt_names_the_attempt_in_motion`
- `test_sale_state_with_captured_and_voided_rows_has_nothing_in_motion`
- `test_sale_state_on_a_paid_processing_order_is_locked_and_reopens_to_pos_open`
- `test_sale_state_on_a_completion_standing_on_on_hold_is_locked`
- `test_sale_state_reopens_is_false_for_cancelled_and_for_the_current_status`
- `test_sale_state_on_a_gateway_paid_order_without_rows_has_money`
- `test_sale_state_on_a_fresh_cart_has_no_money_and_takes_tender`
- `test_sale_state_on_a_cancelled_paid_sale_takes_no_tender_and_is_already_paid`

**These survive unchanged and pin today's behaviour; none is deleted:**
- `Test_Rest_Dispatch_Money_Model`: lines 269–329; 480–1186, which include `money_in_motion` (791–870) and adopt (972–1116); 1222–1247; 1398–1646; and 1823.
- `Test_Payments_Controller`: lines 538–600, which include 556 `test_close_with_a_pending_row_is_refused_in_flight`.
- `Test_Ledger`: the `wcpos_order_already_paid` cases at lines 618, 798, 1610, 1660, 1891 and 1911.

## Pro impact

On Pro `origin/next`, there are no hits for `accepts_tender`, `IN_PROGRESS_STATUSES`, `Completion_Stamp`, `Order_Writer`, `Null_Writer`, `->close(` or the push-lane codes. `Provider_Conformance_Test_Case.php` asserts `wcpos_payment_in_flight` (line 365) and `wcpos_invalid_transition` (505); both codes are unchanged. Nothing in Pro changes.

## Deferred to its own PR: one tender-adoption builder

`adopt_original_tender()` stays. It is not a copy of `Webview_Passthrough::mint()` (161–245): the source, amount, refunds, dates, derive, identity and failure handling all differ. Pro's `Payments/OrderPay/Legacy_Adoption.php:62` is a third row builder. One builder for three adapters is its own review.

## Budget

About 240–300 added production lines:
- `Sale_State`: about 120
- `Ledger`: +35
- `Order_Writer`: +45 / −85
- `Write_Controller`: about +30, mostly re-indentation
- everything else: under 15

The cap is 600. Stop and report before you exceed it.

## Rulings (orchestrating session, 2026-10-22)

- The open question above is closed: a force delete of a sale holding only captured money is **not** refused. The delete gate is rule 3 (money in motion), the same gate cancel has. No new error code.
- `adopt_original_tender` stays in this PR as the spec says; one tender-adoption builder is its own PR after c2.
- `Test_Rest_Dispatch_Money_Model` lives in `tests/includes/Sync/`; the wire code is `wcpos_invalid_transition`.
