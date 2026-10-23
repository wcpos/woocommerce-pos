# Spec: c2-5, `Gateway_Submission` submit, cancel and the undo are `Ledger::write()` calls

**Date:** 2026-10-23 · **Lane:** `next` · **Parent:** `c2-ledger-write.md` (slice c2-5) · **Binding:** ADR 0040 rules 3, 5, 6, 7 and the amendment (the order of the ledger write) · **Stakes:** checkout-money, High (a gateway charges a card).

## What moves

`Gateway_Submission` stops calling `Ledger::saving( $order->save() )`. Its eight save sites on `origin/next` (60005868) become writes; none remains. `submit()` and `cancel()` keep their public signatures (the v2 `Payments_Controller` calls them; Pro does not).

| Step | Write |
|---|---|
| Before dispatch | `write( attempt sent + command entry (outcome sent) + method, MODE_HOLD, $command )`. S1 reads back the attempt history, the command map and the method. |
| Dispatch | `process_payment()`. A `payment_complete()` the gateway raises meets only deferring listeners: `Completion_Stamp` waits for the row (`awaits_mint()`), the passthrough's mint and `mints_attempt` stand down (`is_submitting()`). |
| Paid | The mint (`Webview_Passthrough::mint()` → `adopt()`, one write) carries the stamp's removal and the command's `recorded` entry staged on its order, so both ride S1 with the row. Then `write( $order )`: S2 has nothing to run (the gateway's own save made the order paid) and S3-heal stamps the completion that waited for the row. If the gateway's paid save was lost, the row's write runs S2 itself. |
| Settled | `write( superseded sends + stamp cleared + attempt settled + entry (outcome settled), MODE_HOLD, $command )`; the note after it. |
| Sent | `write( superseded sends + stamp + attempt sent, MODE_HOLD )`; the note after it. |
| Refused, declined, redirected, threw | `undo()`: one held write that puts the previous method back, closes the attempt (`cancelled`) or, when nothing was dispatched, withdraws it from the history, and removes the command's entry. A failure is logged for manual reconciliation; the write retries once from a fresh read. |
| Cancel | `write( stamp cleared + attempt cancelled + status pos-open with its note, MODE_HOLD, $command )`. The stamp's removal is not among the keys S1 reads back, so `cancel()` checks it; a cancel that did not land whole withdraws its entry (`undo()`) and answers 409. |

Deleted with no caller left: `remember_attempt`, `remember_command`, `command_kept`, `verify_command`; `restore_method` becomes `undo()`.

## What the Ledger side needed

- **No empty ledger from a held write.** S1 staged the ledger meta on every save, so the attempt's held write created an empty `_wcpos_payments` on an order with no rows. A write that holds no rows and does not complete now leaves an order with no ledger without one (I2's spirit: the attempt's save carries no ledger meta). Pin: `test_paid_mint_save_hook_error_is_loud_conflict`.
- **`release_hold_if_idle()` and money in motion.** Rule 3 counts an unresolved gateway attempt as money in motion; the prevent-overselling hold now stays while a `sent` attempt or its awaiting-customer stamp stands (`Sale_State::in_motion()`), so the held write before dispatch never gives the stock back while a card is being charged. A cancel, a decline or a settled send leaves nothing in motion and releases it, as a void does.
- **A submit's replay heals.** `Command_Log::replay()` runs a no-change write for a submit as it does for a record, so a retry after the minted row's completion failed stamps it (S3-heal); a healthy order saves nothing.
- **`Command_Log::forget()` leaves no empty map.** A withdrawn entry that empties the map deletes the meta row, so the order document and its revision are as before the command and the till's retry is not refused as stale.

## `release_hold_if_idle` runs only when a change ran

Every write in the submit, cancel and undo flow carries a change, so all of them reach it. A write without a change (a replay's heal, the sweeper) cannot make a sale idle: its draft is the stored rows, `settle()` keeps them, and heal only advances commands or completes a covered sale (stock reduced). The write that made the sale idle (a void, a failed answer, a cancel, a decline) already ran it. So step 9 is met by running it after every write with a change.

## The flush after dispatch

`Ledger::flush( $order_id )` after `process_payment()` stays. Removed, the v1 and v2 checkout tests still pass (every dispatched path now writes, and the outermost write flushes at its end), so it is redundant for the answer. It is kept for order: work the gateway's hooks deferred lands before the submit reads the outcome from storage and writes on it, and it still runs when that write fails at its lock.

## The #2389 trip sites

`Gateway_Submission.php:235, 531, 595` at the spec's base (03fa86ea): the pre-dispatch withdraw save, `restore_method()`'s save, and `answered()`'s save. Now: the first two are `undo()`'s one write; `answered()` was deleted in c3 (its entry is `Command_Log::run()`'s held write). With `Save_Reentry_Guard::except( includes/Payments/Contract )` taken out of `tests/bootstrap.php` locally, `Test_Gateway_Submission`, its HPOS twin and `Test_Payments_Controller` trip the guard 0 times on `origin/next` and 0 times on this branch: none of the three was a save inside a hook (they run at the REST entry point); the trips were the stale-stack artefact c2-1 fixed in the guard helper.

## Tests

New, through `wcpos/v2` routes, under posts and HPOS (`Test_Gateway_Submission` and its HPOS twin):
- `test_paying_submit_failing_at_stage_then_retried_records_once` × at the held write (409), at the gateway's own paid save (200: the row's write carries the paid transition), at the minted row's completion (409); the same command retried: dispatched once, one row, attempt `paid`, one completion naming the row, one fiscal sale, stock reduced once, retry answers `recorded` with the row.
- `test_submit_sent_keeps_the_stock_hold_and_cancel_releases_it`.

Every existing pin passes untouched: `Test_Gateway_Submission` (68), `Test_Payments_Controller` (101), `Test_Ledger_Write`, `Test_Command_Log`, `Test_Webview_Passthrough`. `Save_Reentry_Guard` stays armed, no new `except()`.

## What c2-6 still owns

Delete public `save()`, `derive()`, `$deriving`, `server_saves`, `saving()`, `save_was_refused()`, the closure locks and the keeper's write path; the seeders move to `write()`; the `Save_Reentry_Guard::except()` exemption leaves `tests/bootstrap.php`.
