# Spec: c4-2, a completion is one explicit append with its origin passed in

**Date:** 2026-10-23 · **Lane:** `next` · **Parent:** `c4-completion.md` (slice c4-2; §2, the read model, landed as c4-1 #2401) · **Binding:** ADR 0040 rule 1, its fiscal section ("money never reads `n`"; `(order_id, n)` is the sale record's key) and the amendment (S1, S2, S3); c2 (`Ledger::write()`, `defer()`, `is_writing()`, H2, `completion_due()`); `.ai/rules/no-saves-inside-save-hooks.mdc` · **Stakes:** checkout-money and fiscal writes, High.

## Outcome

Only the Ledger's S3 writes a completion: `Completion_Stamp::append( $order, Completion_Origin $origin )`, with the origin passed in, never read from statics. A completion outside a money command (gateway callback, order-pay, wp-admin paid write) reaches the same S3 through one observer's deferred write. The wire (`completion {n, id}`), the `_wcpos_completions` shape (plus the optional `cashier` the ruling adds) and `wcpos_sale_completed( $order, int $n )` do not change.

## 1. Write side

```php
Completion_Stamp::append( WC_Order $order, Completion_Origin $origin ): array; // the standing entry
Completion_Stamp::reopen( WC_Order $order ): void;                             // marks the latest entry reopened
Completion_Stamp::defer( int $order_id ): void;                                // the observer's job, queued last
final class Completion_Origin {
	__construct( array $provenance = array(), bool $order_pay = false, int $cashier = 0 ); // body-shaped, validated
	provenance(): array;                          // validated, entry-shaped (what a sent submit stores on its stamp)
	for_entry( WC_Order $fresh, int $n ): array;  // register, session, sale_time, sale_tz, counter (+ cashier when known)
}
Money_Command::origin(): Completion_Origin;       // parsed from the body's `provenance` on record, intent, capture, submit, close
Gateway_Submission::resolve_paid( Ledger_Draft $d ): bool;
```

`for_entry` keeps the precedence `Completion_Stamp::resolve_provenance()` had, first non-empty source wins: the origin's own provenance, the stored awaiting stamp's provenance, the till tuple (`n === 1` or `order_pay` only), the latest captured row's register and session. It reads storage only. The cashier is the origin's, else the stamp's `cashier_id`; the entry carries `cashier` only when one is known.

**Invariants.**
- **A1. Idempotent.** Under the re-entrant order lock, `append` returns the latest entry when it stands; otherwise it appends `n+1`, synthesising legacy entry 1 first when the order has a fiscal sale row and no history.
- **A2. Write-once.** Only `reopen()` touches a stored entry, and it only sets `reopened_at_gmt` on the latest one, once.
- **A3. One save.** The entry, `_wcpos_completed_at` and the removal of `_wcpos_awaiting_customer` go out in one meta-only save (H2), then are read back. A miss throws `Ledger_Persistence_Exception` with stage `completion` (409 on a route; logged and noted from a deferred job); the retry runs S3-heal.
- **A4. The action.** `wcpos_sale_completed( $order, $n )` fires only on an append, after the read-back, under the lock. A throwing listener is logged.
- **A5. The caller decides.** `append` does not check paid; S3 decided it (the Projection, or `completion_due()` for S3-heal). The test fixture calls `append` directly.

**The awaiting stamp outlives the paid save.** `append` removes it (A3); `Gateway_Submission::clear_obsolete_stamp()` drops the paid statuses (cancelled, refunded and trash still clear it). So the origin is a function of storage at S3: a retried S3 names the submitting register exactly, and the passthrough reads the paying attempt from the stored stamp.

**Where each write's origin comes from (code wins over §1 of the parent).** `Ledger::write()` fixes the origin once, before heal: the command's `origin()` when the write runs for a command, otherwise `new Completion_Origin( array(), Webview_Passthrough::is_paying( $id ) )` (the storage chain, with the order-pay window). heal, S2→S3 and S3-heal all pass it to `append`. The parent spec's `Ledger_Draft::completes_at()` is not built: S3-heal runs in `heal()` before the write's change, so an origin the change named would never reach the completion the observer's job most often makes, and every caller's origin is one of the two above.

| Caller | Origin |
|---|---|
| Money command (record, intent, capture, close) | `Money_Command::origin()`, from the fingerprinted body: a same-id replay heals with the same origin (`Command_Log::replay()` passes the command to its heal write). |
| Submit | Its `origin()->provenance()` goes into the stamp; the paid branch's final write passes the command, so its S3 names the submit's register. |
| Gateway callback, order-pay, wp-admin paid write | The observer's job: the default origin. |
| Pro `intent`/`capture` with no command | The default origin, as before. |

**The observer** is `Completion_Stamp::on_status_changed`, `woocommerce_order_status_changed` priority 5, and saves nothing on a completion:
- **Into the completed set** (from outside it) it returns while `Ledger::is_writing( $id )` (S3 owns that completion) or `Gateway_Submission::is_submitting( $id )` (the submit's final write owns it; a job flushed after dispatch would run before the attempt's row is minted). Otherwise, when the order has an origin (a stored stamp, a captured row, or the order-pay window on a POS order), it calls `defer( $id )`.
- **The job** reads the order fresh, returns when a completion stands, and otherwise runs one `write()` whose change is `Gateway_Submission::resolve_paid( $d )`: a sent attempt the stored stamp names, on a paid order with no captured row, is staged as its captured row (the attempt id as the row id, built by `Webview_Passthrough::row()`), so S1 stores it, `stage()` marks the attempt paid and S2/S3 complete it; with nothing to resolve the change answers `false` (a change answering `false` staged nothing, so S1 saves nothing) and S3-heal appends when `completion_due()` says so.
- **A reopen** calls `reopen()` synchronously, meta-only (H2), so `stands()` matches storage for the rest of the request; a write's own reopen included.
- **`Webview_Passthrough::defer_mint()`** queues the completion job with its mint (an asynchronous order-pay has no origin until the row lands); the mint job no longer stamps.
- **S2 on an already-paid sale** (a stamped order a callback or wp-admin moved to a paid status) skips `payment_complete()`: the paid transition already ran outside the ledger.

**Flush ordering (one change to c2's `flush()`).** `Ledger::defer( $id, $work, $label, true )` queues a completion job: it runs after the order's other deferred jobs, and once per flush (a second one queued before it runs is dropped). That replaces `awaits_mint` and `$completing`: the passthrough's mint (queued at `woocommerce_payment_complete` 10, after the observer at status-changed 5) is stored first.

**Deleted.** Statics `$context`, `$attempts`, `$completing`; `with_provenance`, `provenance`, `in_command`, `reset`, `attempt`, `memoise`, `stamp`, `has_origin`, `awaits_mint`, `mints_attempt` (moved, as `resolve_paid`), `on_completed` (status-changed 15), `on_payment_complete` (payment-complete 15), `on_pre_payment_complete` and its dynamic `payment_complete_order_status_*` → `on_payment_completed`; the `memoise()` calls in `Ledger::store_rows()`, `Ledger::finish()` and `Gateway_Submission::clear_obsolete_stamp()`; `Completion_Stamp::with_provenance()` in `Payments_Controller` with `PROVENANCE_HANDLERS`. `resolve_provenance`, `till_tuple`, `validated` and `latest_captured` move into `Completion_Origin`.

## 2. Wire and fiscal contract

- Route answers keep `completion {n, id}`; `_wcpos_completions` keeps its shape and its push-lane strip.
- The hook is `wcpos_sale_completed( $order, int $n )`, unchanged.
- **Ruling (a), cashier:** an entry and an origin carry an optional `cashier`; the fiscal sale writer reads it for completion `n ≥ 2` and falls back to `_pos_user` when absent. A command's origin carries the route's current user; the observer's, the stamp's `cashier_id`.
- **Ruling, crash gap:** a crash between the entry's save and `wcpos_sale_completed` is #2378's (a scheduled retry that writes a missing sale record for a standing entry), not this slice's.
- No listeners outside Free.

## 3. Tests

- **Behavioural spec, kept:** every `Test_Completion_Stamp` test and its HPOS twin. The `reset()` calls go (no statics). A completion outside a request now lands at the request's end, so tests that drove `payment_complete()` or `update_status()` outside a route call `Ledger::flush_all()` as the request's shutdown would.
- **New `Test_Completion_Append` (CPT and HPOS):** two calls, one entry, same `{n, id}`; `reopen()` then `n+1`; legacy synthesis then 2; the origin precedence table, invalid fields dropped; a read-back miss throws `completion` and fires nothing; the stamp goes with the entry, before the action; the action fires once, under the lock; the cashier on entry n ≥ 2 reaches the fiscal record, `_pos_user` otherwise.
- **New in `Test_Ledger_Write`:** a sent attempt whose S3 fails, retried, names the submit's register; a completion job queued while the ledger writes is a no-op after the final S3; the completion job runs after the order's other deferred jobs, once per flush.
- **`Completion_Fixture`:** `seed()` is `append( $order, new Completion_Origin() )`, `reopen()` is `Completion_Stamp::reopen()`. Entry 1 takes the whole till tuple, so `sale_time`, `sale_tz` and `counter` are no longer forced to null for later entries' fixtures.
- Tests that used `with_provenance()` run the command through its route or `append()` with the origin.

## 4. Pro

Pro `origin/next` greps clean for `Completion_Stamp`, `_wcpos_completions`, `_wcpos_completed_at`, `wcpos_sale_completed`, `with_provenance`, `as_completed`, `mints_attempt`, `resolve_paid`, `Money_Command`, `Ledger_Draft`. It calls `Ledger::defer()` with three arguments and `Ledger::flush()`; both keep their signatures (the fourth `defer()` argument is optional). Its `intent`/`capture` calls pass no command and get the default origin. No companion PR.
