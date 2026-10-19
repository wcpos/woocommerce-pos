# Spec: lean payments-contract 1.3 server PR (carved out of #2312)

**Date:** 2026-10-19 · **Lane:** `next` · **Branch:** `feat/payments-contract-1-3-lean` (this worktree, cut from `origin/next` at `b07bb046`) · **Source:** `origin/feat/payments-contract-1-3` at `25b01a80` (PR #2312) · **Stakes:** High (checkout money) · **Handoff:** `.claude/handoffs/2026-10-19-payments-1-3-carve/HANDOFF.md` on `main`, copied below where it matters.

## The rule this PR states

> **No auto-recovery of money transitions.** When a wc/v3 write has committed and a post-commit money transition (owner stamp, completion, reopen) then fails, the server fails loudly: error-level log, an order note "WCPOS: money state needs attention (mutation …)", and the retryable 409 the sync lane already understands. The next push re-runs the transitions idempotently from its own payload; a human reconciles anything that stays wrong.

Paul backs this rule. Do not add mechanism to answer "if X races Y between the commit and the transition" — that class of finding is answered by the rule.

## Shape of the work

This is **subtraction**. Every kept piece already exists on the source branch with its tests. The job:

1. Port the whole source branch onto this worktree: `git -C <this worktree> checkout origin/feat/payments-contract-1-3 -- includes tests .phpunit.xml.dist` (the source branch is 28 commits ahead of `origin/next`; `next` has 11 commits the branch lacks, none in `includes/Payments`, `includes/API/V2` or `includes/Sync` — verify with `git diff --stat origin/feat/payments-contract-1-3...origin/next -- includes tests` before trusting that).
2. Delete the cut set (below) and the tests that pin it.
3. Implement the two loud-failure replacements, **failing test first** for each.
4. Port round-37 item D only (the `tax_lines` settlement change) from the saved uncommitted diff.
5. Verify (section at the end). Do not commit `tests/logs/`.

The reference for every kept behaviour is the source branch itself; the wiki pages are `/Users/kilbot/Projects/wiki/architecture/client/payments-contract.md` §13, `cart-settlement-commands.md`, `cart-settlement-completion-proofs.md`, `payments-contract/ledger.md`, `payments-contract/gateway-mode.md`.

## Keep (do not touch beyond what the cut forces)

| Piece | Files |
|---|---|
| Money epoch meta, protected-meta guard (fail-closed throw in the before-save hook under the order lock), `Money_Meta_Guard::saving()` wrapping of every plugin order save, `Money_Meta_Guard::authorised()` | `Money_Meta_Guard.php`, `Money_Epoch.php`, every `saving()` call site incl. `Mutation_Store.php`, `Payments_Sweeper.php`, `Order_Writer.php`, `Gateway_Submission.php` |
| `_wcpos_money_owner` stamped on overrule from `tillAggregate`; legacy aggregate strip; `prepare_under_lock` | `Money_Owner.php`, `Money_Compare.php`, `Order_Writer.php` |
| Settlement-change detection (lines, fees, shipping incl. `method_title`/`instance_id`, coupons, `set_paid`, top-level payment identity, line uuid, malformed ledger wrappers) — plus **`tax_lines`** from round 37 D | `Settlement_Change.php` |
| Server-authorised completion and reopen transitions; completion stamp + `_wcpos_epoch_provenance`; reopen refusals `not_completed`, `settled_without_row`, `paid_without_row`, `attempt_unresolved`, `live_leg`; a completed sale becomes payable only through the reopen command (422 `reopen_required`, 426 pre-1.3); epoch-0 trusted offline assertion counts as completion evidence | `Order_Writer::complete_money_transition`, `Money_Epoch::reopen_refusal`, `is_completed_in_current_epoch` |
| Reverse gate for pre-1.3 clients (`Protocol_Gate::refusal`, 426 `wcpos_update_required`) on every payments route incl. the status poll, with the `WC_Order` instanceof check; `payments=` token in `X-WCPOS-Client` | `Protocol_Gate.php`, `Payments_Controller.php`, `Client_Signal.php`, `Money_Epoch::gate` |
| Envelope keys `expectedEpoch` / `tillAggregate` in `push()` (`$meta['money']`); push-lane refusals 422 (`wcpos_money_epoch_stale`, `wcpos_invalid_transition`); route-family 409; **lock-before-CAS ordering in `apply_update`** | `Write_Controller.php` |
| Ledger row `epoch`; `{state, epoch}` attempt history with the normalising reader; `paid` terminal replay; `superseded` for replaced `sent` invoices; stale-epoch replays 409; attempt persisted as `sent` BEFORE `process_payment()`, `cancelled` on a pre-dispatch failure | `Ledger.php`, `Gateway_Submission.php` |
| Every money read scoped to the current epoch through `Money_Epoch::current_rows()`: balance, completion proof, payment identity in derive, reservations, promotion, live legs, submission already-paid, the sweeper (void or retire prior-epoch authorizations — never capture), referenced webview identity; `summary.current_epoch` | `Money_Epoch.php`, `Ledger.php`, `Payments_Sweeper.php`, `Webview_Passthrough.php`, `Gateway_Submission.php` |
| Ledger saves hold the order lock, work on the freshly loaded order, verify persistence after save (`Ledger_Persistence_Exception` → 409 on routes), keep concurrent rows (explicit `$deleted_ids`), rebase unchanged caller rows; mint identity check under the lock (`Ledger::adopt( $order, $row, $derive, ?callable $identity )`) | `Ledger.php`, `Ledger_Persistence_Exception.php`, `Webview_Passthrough::adopt` |
| Row-targeting routes (void, capture, intent, cancel) refuse prior-epoch rows; explicit refunds of retained captures allowed | `Payments_Controller.php`, `Gateway_Submission.php` |
| Permanent PHPUnit lease guard (`tests/Helpers/Order_Lock_Guard.php`, `<listeners>` in `.phpunit.xml.dist`); lock-test ids above ten million; ledger keeper and guard release their own acquisitions on a failed save | `.phpunit.xml.dist`, `tests/Helpers/Order_Lock_Guard.php`, `Ledger.php`, `Money_Meta_Guard.php` |
| Born-twice create repair (`validate_existing_create`: owner stamp when missing, completion transition under the lock, explicit tax ids unless canonical meta exists); create identity proven right after the 201 and before post-create work (`preserve_create_identity`, `Create_Identity::prove` public, `stamp(..., $already_proven)`); `after_recovery( $id, $payload, $money )` runs the money transitions and returns `WP_Error` on lock failure | `Order_Writer.php`, `Create_Identity.php`, `Write_Controller::apply_create` |
| The `$meta['money']` block and the `poison`/`blocked` release guard in `push()` (the latter is already on `origin/next`) | `Write_Controller::push` |

## Cut (delete entirely)

### A. The update-transition checkpoint

Everything keyed on `_wcpos_update_transition`, `_wcpos_committed_mutation`, `retain_mutation`:

- `Write_Controller`: `recover_order_update()`, `update_checkpoint_error()`, `save_update_checkpoint()`; the `pending`/`poison`/`blocked` → `recover_order_update` branch and the done/applied checkpoint cleanup in `replay_or_conflict()` (restore `replay_or_conflict` to the `origin/next` body verbatim); in `apply_update()` the `$stale_checkpoint` block, the competing-update refusal, the `checkpoint_update` closure, every `save_update_checkpoint` call, and the `$locked ? finalize_poison : checkpoint_and_finalize` split (always `checkpoint_and_finalize`, as on `next`). `retains_mutation()` goes back to the `origin/next` body (no `retain_mutation` data anywhere).
- `Order_Writer`: `recover_update()`; in `forward()` the `$checkpoint` / `$mutation_id` locals, both `$checkpoint(...)` calls and the `_wcpos_committed_mutation` meta injection; `Meta_Entry` import if it becomes unused.
- `Mutation_Store::finalize_poison()` goes back to the `origin/next` body (the `blocked AND collection='orders' AND operation='update'` clause was the checkpoint's).
- `Money_Meta_Guard::KEYS`: remove `_wcpos_update_transition` and `_wcpos_mint_pending`. **Keep `_wcpos_money_hold`** (the wiki names it; nothing writes it yet).

### B. Deferred mint facts

Everything keyed on `_wcpos_mint_pending` / `Webview_Passthrough::PENDING_META_KEY`:

- `Webview_Passthrough`: the `PENDING_META_KEY` constant, the `$defer` parameter of `mint()` (signature returns to `mint( WC_Order $order, array $overrides = array() ): ?array`, as on `origin/next`), the whole deferral branch in `mint()`'s catch, `reconcile()`, the `$epoch` argument of the private `adopt()` and the `$row_epoch` parameter of `Ledger::adopt()` (keep the `$identity` callable — that is the round-10 identity check under the lock).
- `Payment_Index::order_ids_with_live_legs()` goes back to the `origin/next` body (no `$pending_mints`).
- `Payments_Sweeper`: the `true` argument and the `Webview_Passthrough::reconcile( $order )` call; keep the prior-epoch void/retire block and the `Money_Meta_Guard::authorised` save.
- `Money_Epoch::reopen_refusal()`: drop the `mint_pending` reason (the `$reason = … ? 'mint_pending' : null` line becomes `$reason = null`).
- `Gateway_Submission`: the `$paid` branch calls `Webview_Passthrough::mint( $order, array( …overrides… ) )` exactly as the **committed** source branch does (no `true`, no `PENDING_META_KEY` check — those are round 37 B, uncommitted, not ported).

### C. Round 37 A and C (uncommitted on the source worktree — simply do not port them)

The saved diff is `/private/tmp/claude-501/-Users-kilbot-Projects-woocommerce-pos/5f475196-7ff4-429f-b044-0c427f289357/scratchpad/round37-uncommitted.diff`. Apply ONLY the `Settlement_Change.php` and `Test_Settlement_Change.php` hunks (item D): `git -C <this worktree> apply --include='includes/Payments/Contract/Settlement_Change.php' --include='tests/includes/Payments/Contract/Test_Settlement_Change.php' <diff>`. Nothing else from that file.

### Tests that pin the cut set — delete them

`tests/includes/Payments/Contract/Test_Money_Meta_Guard.php`: `test_unrelated_save_does_not_prove_interrupted_put_committed`, `test_competing_update_preserves_pending_transition_until_original_recovers`, `test_reopen_waits_for_pending_mint_then_retains_original_epoch`, `test_committed_update_retry_preserves_checkpoint_cashier_context`, `test_update_checkpoint_write_failure_is_retryable` (+ its data provider), `test_failed_put_checkpoint_is_cleaned_and_retry_forwards_again`, `test_pending_mint_cannot_be_forged_or_removed_by_order_push`, `test_blocked_update_with_matching_checkpoint_recovers_money_transitions`, `test_committed_update_retry_recovers_money_without_reforwarding` (+ `update_transition_failures` provider), `test_done_update_cleans_stale_checkpoint` (+ provider), `test_successful_update_removes_transition_checkpoint`, `test_update_checkpoint_cannot_be_forged_changed_or_deleted`. Any other case in that file that asserts on `_wcpos_update_transition` or `_wcpos_mint_pending` goes too; a case that asserts kept behaviour and merely *mentions* a cut key in a forged-meta list keeps the assertion and loses the key.

`tests/includes/Payments/Contract/Test_Webview_Passthrough.php`: `test_two_deferred_callbacks_preserve_both_payments`, `test_failed_deferred_fact_write_leaves_note_and_rethrows`, `test_deferred_prior_epoch_mint_is_retained_and_cleared`, `test_contended_payment_hook_is_reconciled_by_sweep`. `test_payment_hook_failed_deferral_leaves_note_without_throwing` is **rewritten** as the new loud-failure test (below), not deleted.

`tests/includes/API/V2/Test_Gateway_Submission.php`: `test_paid_mint_failure_defers_without_redispatch_and_sweep_resolves` is **rewritten** (below).

Grep after deleting: `grep -rnE 'update_transition|recover_update|recover_order_update|mint_pending|PENDING_META_KEY|reconcile\(|retain_mutation|committed_mutation|pre_revision' includes tests` must return nothing.

## Replacement 1 — loud failure after a committed order write

**Where:** `Order_Writer::forward()`, the post-2xx block (lock → `Money_Owner::stamp` → `complete_money_transition`), and `Order_Writer::after_recovery()`.

**Behaviour:** when the PUT/POST has committed (2xx) and the post-commit money work fails — the order lock cannot be re-acquired, or `Money_Owner::stamp` / `complete_money_transition` throws (`\Exception`: the guard's `RuntimeException`, `Ledger_Persistence_Exception`, a WooCommerce save exception) — then:

1. `Logger::error( $message, array( 'order_id' => $id, 'mutation_id' => $mutation_id ) )` where `$message` is `sprintf( 'WCPOS money transition failed after the write on order #%d (mutation %s): %s', $id, $mutation_id, $reason )`.
2. `wc_get_order( $id )->add_order_note( sprintf( 'WCPOS: money state needs attention (mutation %s): %s', $mutation_id, $reason ) )` — `add_order_note` inserts a comment and does not re-enter the guard. Wrap the note itself in a try/catch that only logs, so the note can never mask the original failure.
3. Return `new WP_Error( 'woo_rxdb_sync_record_locked', 'Order money transition failed after the write; retry shortly.', array( 'status' => 409, 'order_id' => $id, 'mutation_id' => $mutation_id ) )`. No `retain_mutation`. No checkpoint. No marker meta.

The mutation id reaches the writer as `$meta['money']['mutation_id']`: set it in `Write_Controller::push()` right where `$meta['money']` is built (`'mutation_id' => $m['mutationId']`). It is then available as `$declared['money']['mutation_id']` in `forward()` and as `$money['mutation_id']` in `after_recovery()`. Fall back to `'unknown'` in the message if absent (third-party callers).

**What the retry does — by lane, no new code:**

- **Update.** The `WP_Error` returns through `apply_update()` to `push()`, which releases the reservation (the row is `pending`, not poison/blocked, no `retain_mutation`). The retry re-enters `apply_update()`: lock, CAS against the moved revision → `woo_rxdb_sync_conflict` 409 with the current document; the till rebases and its next push carries its own payload and aggregate, from which the transitions run again. A reopen re-runs because `Money_Epoch::is_reopen()` still matches the till's `_wcpos_money_epoch`/`_wcpos_reopened_at` meta against the unchanged stored epoch.
- **Create.** Before returning the `WP_Error`, mark the mutation poison so the existing identity-recovery lane owns the retry: `$this->store->mark_poison( $mutation_id, $id, 201 )` — but the writer has no store handle; so instead the controller does it: in `apply_create()`, after `$response = $writer->forward(...)`, if `is_wp_error( $response )` and `$response->get_error_data()['order_id'] ?? 0` is a positive id, call `mark_poison( $m['mutationId'], $id, 201 )` (falling back to `mark_indeterminate` when that fails, same as `preserve_create_identity` does) and return the error. The retry hits `replay_or_conflict` → `poison` → `Create_Identity::recover` → `after_recovery` (audit, tax ids, owner stamp if missing, completion transition under the lock) → `finalize_poison`. **`after_recovery` must fail the same loud way** (log + note + 409 `WP_Error`, status stays poison) when its transitions throw — wrap its body in the same catch and reuse the helper. This is the existing poison lane, not new mechanism.

Put the log+note+error in one private helper on `Order_Writer` (e.g. `money_attention( int $id, ?string $mutation_id, string $reason ): WP_Error`) and call it from both places.

**Tests (write first, see them fail, then implement):** in `tests/includes/Payments/Contract/Test_Money_Meta_Guard.php` (it already has the `push()`/`order()`/`reopen()` helpers) or `tests/includes/Sync/Test_Write_Controller.php`, whichever reads better:

- `test_update_money_transition_failure_is_loud_and_retryable`: arrange a pos-open order with a captured cash row covering the total; push a completing update (status + `_wcpos_completed_at` meta, `tillAggregate` present); make the post-commit transition throw. Assert: response status 409 and code `woo_rxdb_sync_record_locked`; the PUT's own change (status) **is** persisted; `_wcpos_completed_at` is **not** stamped; one order note starts with `WCPOS: money state needs attention (mutation <id>)`; the `woocommerce_pos_logging` filter saw an `error`-level entry containing the mutation id; `Mutation_Store::lookup()` for the mutation returns no row (released); with the fault removed, a second push with the **fresh** revision and the same payload returns 2xx and `_wcpos_completed_at` is stamped. Lease guard must stay quiet (no held lock after the test).
- `test_create_money_transition_failure_marks_poison_and_retry_completes`: same fault on a create; assert 409 + note + log; the mutation row status is `poison` with the new order's id; a retry of the same envelope returns 2xx with the same order id, the completion stamp present, and the mutation `done`.
- Fault lever (pick one, both are legitimate): (a) a `woocommerce_before_order_object_save` action registered by the test at a late priority that throws `RuntimeException( 'simulated transition failure' )` only when the order's pending meta changes include `_wcpos_completed_at` (the first PUT does not carry it — the writer strips forged completion meta — so only the transition save trips it); or (b) from `woocommerce_rest_insert_shop_order_object` (fires inside the committed PUT) release the writer's `Order_Lock::instance()` hold and acquire the lock with a separate `new Order_Lock()` so the post-PUT `lock()` fails; release it in `finally`. If (a) turns out to fire somewhere else first, use (b).

## Replacement 2 — a mint refused after WooCommerce committed a payment

**Where:** `Webview_Passthrough::mint()` catch block and the two hook callers.

**Behaviour:** when `adopt()` throws `Ledger_Persistence_Exception` (lock contention or a failed persistence check) after WooCommerce has already completed the payment:

1. `Logger::error( $message, array( 'order_id' => $order->get_id() ) )` with `$message = sprintf( 'WCPOS payment %s (%s %s) captured but not recorded in the ledger on order #%d; reconcile manually: %s', $reference, $row['amount'], $row['currency'], $order->get_id(), $error->getMessage() )` where `$reference` is the transaction id when non-empty, else the row id.
2. `$order->add_order_note( sprintf( 'WCPOS: payment %s (%s %s) captured but not recorded in the ledger; reconcile manually.', $reference, $row['amount'], $row['currency'] ) )`.
3. Rethrow. `on_payment_complete()` and `on_status_changed()` keep their existing `catch ( Ledger_Persistence_Exception ) { return; }` — hook callers never throw (round 13). `Gateway_Submission` lets it propagate as the committed branch does: the route answers 409 via `Payments_Controller`'s existing catch; the attempt was persisted as `sent` before dispatch, so a retry replays `sent` and does not call `process_payment()` again.

A manager reconciles through the existing payment routes. No deferred fact, no sweeper pickup.

**Tests:**

- Rewrite `Test_Webview_Passthrough::test_payment_hook_failed_deferral_leaves_note_without_throwing` → `test_payment_hook_contended_mint_leaves_note_and_logs_without_throwing` (`@dataProvider payment_hooks`): hold the lock with a separate `Order_Lock`, fire the hook in a POS request; assert no exception escapes, the ledger meta is unchanged, no row minted, an `error` log entry contains the transaction id, the note contains the transaction id and the amount (`92.95`), and `wc_get_order()->get_meta_data()` carries no key other than the protected ones (i.e. no deferred fact of any name). The existing `test_payment_hook_locked_ledger_logs_error_without_throwing` stays; adjust its expected message if you change the first log line, or leave that log line untouched.
- Rewrite `Test_Gateway_Submission::test_paid_mint_failure_defers_without_redispatch_and_sweep_resolves` → `test_paid_mint_failure_is_loud_and_retry_does_not_redispatch`: a paying test gateway succeeds, the mint is refused (use the same fault the current test uses to break the mint); assert the route answers 409, the attempt history holds the attempt as `sent` in the current epoch, the order note and the error log name the transaction reference and amount, a retry with the same attempt id answers `outcome: sent` with `payment: null`, and the test gateway's call count is 1.

## Verification (run every one; paste counts into your report)

wp-env for this worktree: `cd /Users/kilbot/Projects/woocommerce-pos-worktrees/payments-contract-1-3-lean && ./node_modules/.bin/wp-env …` (ports 8898/8899 via the gitignored `.wp-env.override.json`; plugin mapped at `wp-content/plugins/woocommerce-pos`; `vendor/` is already in place from the same lane). Confirm it is up with `docker ps --format '{{.Names}}' | grep payments-contract-1-3-lean`. If `wp-env run` says "Environment not initialized", run `./node_modules/.bin/wp-env start` from the worktree directory first.

One PHPUnit invocation at a time, never two in parallel, and **never while the sibling stack `wp-env-payments-contract-1-3-8c079ca4-tests-cli-1` is running phpunit** (check `docker exec wp-env-payments-contract-1-3-8c079ca4-tests-cli-1 ps aux | grep phpunit`; if it is, do phpcs/phpstan first and come back). Each file separately (`.phpunit.xml.dist` runs only the LAST path given; a bare directory runs zero tests and exits 0):

```
./node_modules/.bin/wp-env run tests-cli --env-cwd='wp-content/plugins/woocommerce-pos' -- vendor/bin/phpunit -c .phpunit.xml.dist <one test file>
```

Files: `tests/includes/Payments/Contract/Test_Money_Meta_Guard.php`, `…/Test_Money_Meta_Guard_HPOS.php`, `…/Test_Money_Epoch.php`, `…/Test_Money_Owner.php`, `…/Test_Settlement_Change.php`, `…/Test_Ledger.php`, `…/Test_Webview_Passthrough.php`, `…/Test_Payments_Sweeper.php`, `…/Test_Order_Lock.php`, `tests/includes/API/V2/Test_Gateway_Submission.php` (+ any HPOS twin present), `tests/includes/API/V2/Test_Payments_Controller.php`, `tests/includes/Sync/Test_Write_Controller.php`, `tests/includes/Sync/Writers/Test_Order_Writer.php`, `tests/includes/Sync/Test_Create_Identity.php`, `tests/includes/Sync/Test_Mutation_Store.php`, `tests/includes/Services/Test_Client_Signal.php`. Then the FULL suite once (no path argument; ~5 min; the lease-guard listener is active). Expected full-suite noise in a worktree: exactly the three `Test_Error_Reporter::test_in_plugin_fatal_with_consent_sends_fatal_event` data sets (they assert the `plugins/woocommerce-pos/` path) — nothing else. With the plugin mapped to `woocommerce-pos` they may pass too; either is fine.

Mutation-check both new loud-failure tests: revert the `money_attention` call site (or the note line), confirm the test fails, restore.

Host gates from the worktree root: `vendor/bin/phpcs --standard=.phpcs.xml.dist $(git diff --name-only origin/next -- '*.php')` (zero errors; warnings only if pre-existing), `vendor/bin/phpstan analyse --memory-limit=2G` (if it dies with "Class WCPOS\Vendor\… not found", read `~/.claude/projects/-Users-kilbot-Projects-woocommerce-pos/memory/phpstan-internal-error-on-scoped-vendor-parent-class.md`), `git diff --check origin/next`.

Lane coverage: `mkdir -p <scratch>/base && git -C <this worktree> archive origin/next tests scripts | tar -x -C <scratch>/base`, then `php scripts/lane-coverage.php --json --root=<scratch>/base > <scratch>/base.json` and `php scripts/lane-coverage.php --compare=<scratch>/base.json` from the worktree. Must report no new v1-only cases.

## Budget and stop rule

`origin/next...HEAD` for `includes/` ≤ 1,500 added lines; whole diff ≤ 3,200 added lines (#2312 is +5,517). If you are about to exceed either, stop and report why instead of continuing. Splitting into more files or commits does not raise the budget.

## Out of scope — do not add

Checkpoints, markers, deferred facts, reconcile passes, sweeper pickups, retry-with-backoff, new meta keys, new options, new tables or columns (`.ai/rules/db-tables.mdc`), new REST routes, new filters/actions, env vars, feature flags, a `summary.awaiting_customer` field, an `epoch` submit parameter, or the `contract: "1.3"` bump in `GET payment-methods` (those are P1b, a later PR). No edits to `docs/` or the wiki. No `*_post_meta()` on an order id (the test bootstrap fails the test). Do not weaken or delete any guard clause in `includes/Payments` beyond the cut list without reading the round that introduced it (`git -C <this worktree> log origin/next..origin/feat/payments-contract-1-3 -- <file>`).

Stop and report only if: this spec contradicts itself, something it names is missing, or a test was already failing on `origin/feat/payments-contract-1-3` before you started (check the source worktree at `/Users/kilbot/Projects/woocommerce-pos-worktrees/payments-contract-1-3` with `git stash`-free means: `git show HEAD:<file>`). A test you wrote failing first is expected. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR; do not commit (the orchestrator commits). Leave the worktree with the finished diff and your report.
