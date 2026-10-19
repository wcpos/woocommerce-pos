# Spec: the epoch-independent ledger hardening from #2328, as one PR against `next`

**Date:** 2026-10-19 · **Lane:** `next` · **Branch:** `fix/ledger-hardening-from-2328` (this worktree, cut from `origin/next` at `e4eac6e2`) · **Source:** `origin/feat/payments-contract-1-3-lean` at `770667ad` (PR #2328, closed unmerged under ADR 0040) · **Stakes:** High (payment ledger persistence) · **Decision:** ADR 0040 (`docs/adr/0040-server-derives-paid-no-money-epoch.md` on the ADR branch, PR #2332): the money epoch is not built; the ledger's persistence hardening is.

## What this PR is

#2328 carried two kinds of change: the money-epoch model, which ADR 0040 rejects, and hardening of how the payment ledger persists, which the review rounds proved and which stands on its own. This PR brings back the second kind only. Every piece below already exists on the source branch with its tests; the job is to extract each hunk **without its epoch coupling** (no `epoch` on rows, no `Money_Epoch`, no `adoption_epochs`, no `current_rows`, no `Money_Meta_Guard`, no `Money_Owner`, no `Settlement_Change`, no `Write_Controller`/`Order_Writer` money transitions, no `Client_Signal` payments token, no `Create_Identity` early proof). Extract by reading `git diff origin/next origin/feat/payments-contract-1-3-lean -- <file>` per file and porting hunks by hand; do not `checkout` whole files.

## Include

### A. PHPUnit lease guard
- `tests/Helpers/Order_Lock_Guard.php` (verbatim) and the `<listeners>` block in `.phpunit.xml.dist`.
- `tests/includes/Payments/Contract/Test_Order_Lock.php`: the lock-test ids above ten million and any other hunk that is not about epochs.

### B. `Ledger::save()` persistence hardening
From the source branch's `save( $order, $rows, $derive, array $deleted_ids = array(), ?array $read_rows = null )`:
- Acquire `Order_Lock` for the save; when it cannot be acquired throw `Ledger_Persistence_Exception` (new class `includes/Payments/Contract/Ledger_Persistence_Exception.php`, verbatim).
- Reload the order fresh under the lock; core totals and status come from the locked read, pending meta staged by the caller is carried over (the `$pending` / `$caller` / `$order = $fresh` block), and **unsaved items on the caller are carried too** (the on-reader tip fee; see `test_authorized_capture_with_tip_persists_fee_and_total`).
- Concurrent-row merge: rows the caller did not send are kept (omission is not deletion); only ids in `$deleted_ids` are removed (a refused intent); a caller row unchanged since `$read_rows` is rebased onto the fresh stored row (`same_content`, `normalize_row`), a changed one wins.
- Verify persistence after the save and throw `Ledger_Persistence_Exception( 'WCPOS payment ledger was not persisted.' )` when the stored ledger does not match.
- The keeper releases only its own lock acquisitions on a failed save (`closure_locks` counting in `save()`'s `finally`), so an enclosing holder keeps its lock. The source branch homed the request-level wrapper as `Money_Meta_Guard::saving( $order_id, $fn )` delegating to `Ledger::with_save_lock_cleanup()`; here it is **`Ledger::saving( int $order_id, callable $fn )`** (static, same semantics, no guard counter), and the plugin's own order saves that the branch wrapped are wrapped with it: `Mutation_Store` (two `$order->save()` calls), `Payments_Sweeper` (`$order->save()` after derive), `Gateway_Submission` (the saves around the attempt and the stamp), `Order_Writer::persist_cashier_store_reassignment`.
- `adopt( $order, $row, $derive, ?callable $identity = null )`: the identity callback checked under the lock against stored rows, reloading the order fresh for the check. No epoch argument.
- `record()`: a refused save throws rather than returning an unstored row (`test_record_refused_save_throws_without_returning_an_unstored_row`).
- Callers that must persist core changes before a ledger save (the two sweeper fixtures the branch adjusted) — port those fixture adjustments.

Tests to port into `tests/includes/Payments/Contract/Test_Ledger.php` (names as on the source branch; drop any epoch assertion inside them): `test_failed_ledger_save_releases_keeper_locks_but_preserves_outer_lock`, `test_locked_save_uses_fresh_total_and_refreshes_caller`, `test_stale_mint_rebases_an_existing_provider_updated_row`, `test_rebase_keeps_caller_edits_and_fresh_server_fields`, `test_stale_webview_save_keeps_a_concurrently_recorded_row`, `test_record_refused_save_throws_without_returning_an_unstored_row`, `test_dirty_ledger_save_is_refused_while_another_owner_holds_lock`, `test_order_save_drops_duplicate_payment_ids`, `test_order_save_keeps_only_server_refund_allocations`, `test_order_save_preserves_server_event_history`, `test_order_save_restores_closed_duplicate_rows_only_once`, `test_intent_decline_removes_only_its_row_after_another_payment_arrives`, `test_authorized_capture_with_tip_persists_fee_and_total`. Do NOT port: anything named `reopened`, `prior_epoch`, `current_epoch`, `locked_epoch`, `attempt_promotion`.

### C. Routes answer 409 on a persistence failure
`Payments_Controller`: inside the `with_lock` wrapper, `try { return $this->{$definition[1]}( $request ); } catch ( Ledger_Persistence_Exception $error ) { return new WP_Error( 'woo_rxdb_sync_record_locked', $error->getMessage(), array( 'status' => 409 ) ); }`. No `Money_Epoch::gate`, no prior-epoch row refusal. Port the matching `Test_Payments_Controller` cases (409 on a locked/refused ledger save; drop epoch cases).

### D. Gateway attempts: persisted before dispatch, resolved after
`Gateway_Submission`:
- The attempt is persisted as `sent` **before** `process_payment()` runs, and read back; if not persisted, throw `Ledger_Persistence_Exception` (so the route answers 409 and nothing is dispatched).
- A pre-dispatch failure (gateway threw before dispatch started) records the attempt as `cancelled` through the existing `restore_method( …, $attempt_id )` path; a post-dispatch throw does not.
- A settling or paying submission marks earlier `sent` attempts `superseded` (`supersede_sent`), and a `superseded`/`cancelled` attempt replays as the existing `wcpos_payment_conflict` 409.
- A `paid` attempt replays as terminal (`outcome: paid`, no dispatch) — the `paid` state is recorded when the gateway paid and the row minted.
- History keeps **`next`'s shape**: `attempt_id => 'sent' | 'settled' | 'paid' | 'cancelled' | 'superseded'` as strings. No `{state, epoch}` entries, no normalising reader for them.
- When the gateway paid but the mint threw, let the `Ledger_Persistence_Exception` propagate (the route answers 409; the attempt stays `sent`, so a retry replays and never dispatches again). No deferred fact.

Port the non-epoch `Test_Gateway_Submission` cases that pin these (the source branch's names include `test_submit_paid_gateway_records_once_and_replays`, `test_paid_mint_failure_is_loud_and_retry_does_not_redispatch`, `test_paid_mint_save_hook_error_is_loud_conflict`, the sent-before-dispatch persistence case, the pre-dispatch-cancel case, the superseded case; drop `test_prior_epoch_recorded_attempt_is_a_conflict` and any `epoch` assertion). Keep the `_HPOS` twin in step.

### E. Webview passthrough: one row per payment, loud when it cannot record
`Webview_Passthrough`:
- `mint()` adopts through `Ledger::adopt()` with the identity callback (same row id, or same method and an intersecting provider ref, or a reference-less live webview row), so concurrent callbacks mint one row.
- `mint()`'s catch is `\Throwable`: `Logger::error( 'WCPOS payment <ref> (<amount> <currency>) captured but not recorded in the ledger on order #<id>; reconcile manually: <message>' )`, an order note `WCPOS: payment <ref> (<amount> <currency>) captured but not recorded in the ledger; reconcile manually.` inside its own try/catch that only logs, then rethrow as-is when already a `Ledger_Persistence_Exception`, else wrapped (`new Ledger_Persistence_Exception( esc_html( $message ), 0, $error )`).
- `on_payment_complete()` and `on_status_changed()`: `catch ( Ledger_Persistence_Exception ) { return; }` then `catch ( \Throwable $error ) { Logger::error( 'WCPOS webview payment hook failed on order #%d: %s' ) }`. Hooks never throw.

Port from `Test_Webview_Passthrough`: `test_payment_hook_locked_ledger_logs_error_without_throwing` (one error line), `test_payment_hook_contended_mint_leaves_note_and_logs_without_throwing`, `test_payment_hook_ledger_read_error_is_loud_without_throwing`, `test_payment_hook_failing_note_keeps_original_error_without_throwing`, and the concurrent-callback identity cases; drop every deferred-mint and epoch case.

## Exclude (ADR 0040 rejects these; do not port even in part)
`Money_Epoch.php`, `Money_Owner.php`, `Money_Compare.php`, `Money_Meta_Guard.php`, `Settlement_Change.php`, `Payment_Index` changes, the `epoch` row field and every `current_rows`/`current_epoch_balance` reader, `Write_Controller.php` and `Order_Writer.php` changes (except the `Ledger::saving` wrap in `persist_cashier_store_reassignment`), `Create_Identity.php`, `Client_Signal.php`, `Protocol_Gate.php`, `Payments_Controller`'s gate and prior-epoch checks, `Test_Money_*`, `Test_Settlement_Change`, `Test_Write_Controller`/`Test_Order_Writer` changes, `Test_Client_Signal`, `Test_Mutation_Store`/`Test_Create_Identity` changes.

## Verification
wp-env for this worktree: `cd /Users/kilbot/Projects/woocommerce-pos-worktrees/ledger-hardening && ./node_modules/.bin/wp-env …` (ports 8900/8901 from the gitignored `.wp-env.override.json`; plugin mapped at `wp-content/plugins/woocommerce-pos`; `vendor/` present). One PHPUnit invocation at a time, each file separately; never while another stack on this machine is running phpunit (`docker ps` then `docker exec <tests-cli> ps aux | grep phpunit`). Files: `Test_Order_Lock`, `Test_Ledger`, `Test_Payments_Controller`, `Test_Gateway_Submission` (+ `_HPOS`), `Test_Webview_Passthrough`, `Test_Payments_Sweeper`, `Test_Mutation_Store`, `Test_Order_Writer`; then the full suite once (lease guard active). Mutation-check: the persistence verification (make it return early → a ported test fails), the pre-dispatch persist (skip it → the sent-before-dispatch test fails), the mint identity check, the hook catch. Host: `vendor/bin/phpcs --standard=.phpcs.xml.dist $(git diff --name-only origin/next -- '*.php')`, `vendor/bin/phpstan analyse --memory-limit=2G`, `git diff --check origin/next`, lane-coverage compare against an `origin/next` archive (see the lean spec for the recipe). Do not commit `tests/logs/`.

## Budget and stop rule
`includes/` ≤ 700 added lines; whole diff ≤ 2,000 added lines. If you are about to exceed either, stop and report why. Stop and report only if this spec contradicts itself, something it names is missing on the source branch, or a test was already failing on `origin/next`. Anything else you fix and list.

Do not push; do not open or edit a PR; do not reply on any PR; do not commit.
