# Spec: c2-4, the row money commands are one `Ledger::write()` each

**Date:** 2026-10-23 · **Lane:** `next` · **Parent:** `c2-ledger-write.md` (slice c2-4) · **Binding:** ADR 0040 rules 1, 5, 7, 8 and the amendment (the order of the ledger write) · **Stakes:** checkout-money, High.

## What moves

`refund`, `intent`, `capture`, `apply_result`, `void`, `adopt`, `settle_allocation` and `append_events` stop calling `save()`, `replace_and_save()`, `derive()` and `saving( $order->save() )`. Each stores its rows through `write()`: lock, fresh read, heal, the change on a draft, S1 rows read back, S2 paid, S3 completion. Signatures are unchanged (Pro calls `apply_result`, `refund`, `void`, `adopt`, `settle_allocation`, `append_events` without a command).

| Command | Writes |
|---|---|
| `apply_result` | one write: `confirm()` applies the result, its tip fee and totals on the draft; the command's entry rides S1 unless the result mismatched. `$derive` false is `MODE_HOLD`. A result that changes nothing writes nothing (a status poll). |
| `capture`, `void` | the provider call, then `apply_result`'s write. `void`'s note is a comment row; its extra order save goes. |
| `intent` | `MODE_HOLD` before the provider is asked; then the indeterminate warning (`MODE_AUTO`) or the refused intent dropped (`MODE_HOLD`); or `apply_result` (`MODE_HOLD`), parked confirmations drained, and one no-change write that completes only while a command owes it. A resumed leg's determinate refusal is one write. |
| `adopt` | one write; meta the caller staged on its order (Pro's `Legacy_Adoption` lookup) is carried onto the draft so it rides S1. |
| `settle_allocation`, `append_events`, `record_failure_events` | one `MODE_HOLD` write each. |

`release_hold_if_idle()` runs inside `write()` for every write with a change, so the commands stop calling it.

## The refund, two writes (rules 7 and 8)

1. `write( put( reserved row ), MODE_HOLD )`: the `pending` allocation and the command's entry, read back. A failure answers the 409 `woo_rxdb_sync_record_locked` (returned, never thrown: Pro allocates inside WooCommerce's deleting catch) after withdrawing whatever landed; a write refused at its lock stored nothing.
2. `process_refund()` through the handler. A throw or an indeterminate answer strands; a definite refusal withdraws (one write of the row as it was, without the entry).
3. `write( put( settled row ) )`. When it throws before its rows land, **the stored reservation is the strand state**: one log line, one order note, the reservation written again in case the failed write took it, and the stored row is the answer. When it throws after its rows landed (`paid`, `completion`), the allocation stands and the replay, `close` or the sweep finishes the paid transition.

A reopened sale holding money owed back (signed balance below zero, in progress, non-zero total) that the refund returns completes again on it: write 2 runs in `MODE_CLOSE` there, as `derive()` after the old settle save did; every other refund is `MODE_AUTO`, so a refund never completes a sale its rows already covered (rule 3).

Deleted: `persist`, `after_settle`, `stored_row`, `reserved`, `same_allocation`, `mark` (folded into `strand()`'s one note), and the private `replace_and_save`, which has no caller left.

## The paid save carries no ledger meta, so S2 reads the rows again (D7)

The D7 probe (a capture whose completion save loses the ledger write) stays red when capture is merely one write: the rows land in S1, but a paid save that rewrites the ledger meta (another writer's stale order object, a listener staging meta on the saving object) leaves S3 stamping a completion over a pending row, and the command's entry is gone, so the retry is refused stale. `complete()` now reads the rows back after `payment_complete()` and, when they are not what S1 stored, restores them with a meta-only save (H2) before S3 counts them; still missing is `rows`. Kept test: `Test_Payments_Controller::test_capture_whose_read_back_fails_after_completion_retries_to_one_sale_record`.

## Tests touched

- `Test_Ledger::test_a_derive_save_that_strips_the_settled_allocation_is_repaired`: pinned three saves after the provider call (settle, derive's paid save, re-persist). Through `write()` there are two (S1, S2) and the restore is meta-only; the behaviour pins stay.
- `Test_Ledger::test_a_settled_refund_storage_will_not_keep_falls_back_to_its_reservation` → `test_a_settled_refund_later_saves_strip_stands_and_the_sale_completes_once`: its scenario (every save after the settle strips the settled allocation) only reached the paid save; under the amendment the rows S1 read back are restored before S3, so the settled allocation stands and the sale completes once.

## What c2-5 and c2-6 still own

- c2-5: `Gateway_Submission` submit, cancel, `restore_method`, `answered` onto `write()`.
- c2-6: delete public `save()`, `derive()`, `$deriving`, `server_saves`, `saving()`, `save_was_refused()`, the closure locks and the keeper's write path; the 17 seeders move from `save()` to `write()`; the `Save_Reentry_Guard::except()` exemption leaves `tests/bootstrap.php`.
