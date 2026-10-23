# Spec: one Attempts module owns gateway attempts end to end (c5)

**Date:** 2026-10-22 · **Lane:** `next` · **Binding:** ADR 0040 rules 2, 3, 5 and 7 · **Stakes:** checkout-money, High.

## Outcome

`includes/Payments/Contract/Attempts.php` is the only code that reads or writes `_wcpos_gateway_attempts`. It owns:

- the attempt entries;
- the awaiting state, which becomes derived;
- provenance;
- the "unresolved?" query;
- the resolver for rule 7.

## Facts (checked at `7f8cb7c2`)

- **Two writers.** `Gateway_Submission::remember_attempt` (:574) caps the map at 50 entries. `Ledger::save` (:2145–2157) writes it uncapped, and only when an attempt id is a counting row; after a cash completion, `sent` stays forever and close or reopen is refused.
- **The stamp.** Read at seven sites (Ledger, `Completion_Stamp::memoise` :160, Order_Writer, cancel) and cleared at five. `Completion_Stamp::$attempts`/`attempt()` exist only to survive that clear.
- **No consumer outside this plugin.** Neither key is on `origin/main`, and neither Pro `next` nor the app reads it. No `wcpos_attempt_*` code exists.
- **Gateway mode has no provider API.** Pro registers only `server` and `device` (`Init.php:107`). The sweeper visits only orders in `_wcpos_payment_live`.

## Interface

```php
final class Attempts {
	const META_KEY = '_wcpos_gateway_attempts';
	const CAP = 50;       // resolved entries only; an unresolved one is never evicted
	const ASK_LIMIT = 6;  // indeterminate asks (an hour of sweeps) before a human is asked
	static function of( WC_Order $o ): Attempt_Log;
	static function open( Ledger_Draft $d, string $id, array $descriptor, array $command ): array; // fate sent
	static function await( Ledger_Draft $d, string $id, ?string $destination ): void;
	static function resolve( Ledger_Draft $d, string $id, string $fate ): void; // paid|settled|cancelled|superseded
	static function settle( Ledger_Draft $d ): void;                           // the S1 rule below
	static function reconcile( WC_Order $o, string $source ): ?array;          // the resolver
}
// Attempt_Log: get( $id ), unresolved() (newest sent), awaiting() (stamp-shaped), provenance( $id ), to_index()
```

**Entry:** `{ id, fate, method_id, cashier_id, origin, command_id, sent_at_gmt, resolved_at_gmt, awaiting, asks, attention_at_gmt }`.

- `origin` is the validated provenance at `open()`.
- `awaiting` is null or `{ destination (≤254 chars), at_gmt }`.

**Writer.** There is one. c2's `Ledger_Draft::attempt( $id, $fate )` gains an optional `array $detail = array()` and has one caller, `Attempts`. S1 writes the map and the index in a single save.

**Encoding.**

- The value is JSON, `{"schema":1,"attempts":{…}}`, like `_wcpos_payments`. That is a one-step change, because nothing shipped reads the key.
- A 3-line reader maps the legacy PHP-serialized strings to `{fate: v}`, for `next` dev stores. Delete it at 2.0.0.
- At the cap the value is about 25 KB, which stays inside the bytes rule.

## `settle()` in S1 (replaces Ledger :2145–2157)

- **Any counting row lands:** clear `awaiting` on every `sent` entry. This is the same as today's derive clear.
- **The rows cover the total:**
  - a `sent` entry whose id is a counting row becomes `paid`;
  - one with `awaiting` becomes `superseded`, logged as `supersede_sent` (:382) does;
  - one without `awaiting` (outcome unknown) **stays `sent`** for the resolver, so a cash completion never buries a charge that may have landed.

## Awaiting becomes derived

- The stamp's readers move to `awaiting()`.
- `Sale_State::in_motion()` moves to `unresolved()`.
- The terminal-status listener (:469) becomes a `Ledger::defer` job. On a paid status it clears `awaiting` and runs `reconcile( 'paid' )`. On cancelled, refunded or trash it only clears `awaiting`.
- Deleted outright, since nothing consumes them, so no compatibility step is needed:
  - `STAMP_META_KEY` and its strip-list entry; the ADR 0040 rule-2 key list is edited to match;
  - `Completion_Stamp::$attempts`, `memoise()` and `attempt()`.
- `mints_attempt` (:495) moves into `reconcile()`. c4 then has nothing left to move.

## The resolver

`reconcile( $order, $source )` runs under the order lock, on a fresh read. It acts on `unresolved()` when that entry has no `awaiting`; with `$source = 'paid'` it acts on any `sent` entry.

1. **Ask the provider.** Pass the pseudo row `{ id: attempt id, status: pending, capture_mode: gateway, method_id, order_id }` to `Capture_Mode_Registry::resolve( 'gateway', provider )->status()`. No new interface method is needed.
   - `Gateway_Handler` overrides `status()` to read the order. It answers `captured` (amount = order total, with the `_transaction_id`) only when all three hold:
     - the order is paid;
     - `payment_method` is the attempt's method;
     - the counting rows do not cover the total.
   - A `gateway:<provider>` handler may ask its own API.
2. **Act on the answer.**
   - **`captured`:** `write()` puts the row, using the attempt id and the entry's method, cashier and origin. `settle()` marks the attempt `paid`, and S3 stamps it. `put()` replaces by id, so a second run changes nothing.
   - **`failed` or `voided`:** the attempt becomes `cancelled`.
   - **Indeterminate:** `asks++`.
   - **Unchanged, or `ASK_LIMIT` reached:**
     - set `attention_at_gmt`;
     - add one note: "outcome unknown; check the gateway, then cancel at the till if nothing was charged";
     - the entry stays `sent`: still in motion, still locked by rule 3.
   - **No path cancels an attempt the provider never answered.**

**How the sweeper finds an attempt.**

- `to_index()` lists the `sent` entries that are neither awaiting nor flagged.
- S1 adds them to the existing `_wcpos_payment_live` index.
- That means no new key or option, and a cost equal to today's `EXISTS` on an indexed `meta_key`. Scanning the map would unserialize every order that ever submitted.
- Flagged entries leave the index, so they cannot starve the batch.
- The sweeper body (c2-3) gains one step: when `unresolved()` is older than the threshold, call `reconcile( 'sweep' )`.

Other callers:

- the `submit` replay of `sent` (:77);
- `cancel()`;
- a new `submit`;
- the paid listener.

## The P1 carried from #2312 (the mint fails after `payment_complete`)

Today, `Test_Gateway_Submission.php:438` (`test_paid_mint_failure_is_loud_and_retry_does_not_redispatch`) **pins the defect**. The retry answers `sent` with `payment: null`, and the ledger is empty.

Under c5:

1. The entry is `sent` and not awaiting, so it is indexed.
2. The replay's `reconcile()` sees the paid facts and mints the row.
3. The replay answers `recorded` with that row (D6).
4. If no replay comes, the sweeper does the same.

Two tests pin this:

- :438, rewritten to assert `recorded`, `payment.id === attempt` and one gateway call;
- `Test_Payments_Sweeper::test_sweeper_mints_a_paid_attempt_whose_mint_failed_and_its_replay_answers_recorded`.

## PR 1 D1 is unsafe as briefed

In the scenario above, a cancel with no stamp runs `update_status( 'pos-open' )` (:422) on a paid order and marks the attempt `cancelled`, although the gateway took the money. A new submit then charges again.

- **Add to D1 now:** when `is_paid()` or `date_paid` holds and the counting rows do not cover the total, refuse 409 `wcpos_invalid_transition`. Add one test.
- **In c5:** `cancel()` runs `reconcile()` first, and the minted row trips the existing refusal at :407.

## Rule 7: no retry before resolution

Today a new attempt id is dispatched while an earlier attempt's outcome is unknown, and `supersede_sent` (:338) buries the earlier attempt.

Under c5:

- `submit` reconciles first.
- If an unresolved entry that is not awaiting remains, `submit` answers 409 `wcpos_payment_in_flight` `{attempt_id}`. The code already exists; it needs a client handoff.
- "Send again" over an awaiting invoice is unchanged.

## Stock hold (#2377): separate

The hold belongs to the sale: `wc_reserved_stock` is keyed by **order id**, refreshed by every `validate_checkout`, and expired by WooCommerce. There is no per-attempt reference.

The module adds one fact: idle (`release_hold_if_idle`, c2 step 9) also needs `unresolved()` to be null.

The fix is its own PR, about 20 lines, right after c2-5:

- `submit` runs `validate_checkout()` before `open()`;
- a definite failure or a refused redirect releases the hold through the write.

## Tests

**Survive as spec:**

- `Test_Gateway_Submission` (60 tests, plus HPOS), except :438, which is rewritten, and :963 and :1634, which are re-expressed on the entry;
- all 27 tests in `Test_Payments_Sweeper`;
- `Test_Completion_Stamp` :277, :303 and :340.

**Seed through the interface** (`write()` with `open`, `await` or `resolve`): `Test_Gateway_Submission` :333 and :967, `Test_Ledger` :2611, and `Test_Rest_Dispatch_Money_Model` :824 and :1235.

**Stay raw:** `Test_Rest_Dispatch_Money_Model` :219–220, which proves pushes cannot address stored meta (drop the stamp key from its list at :127 and from `Test_Order_Writer` :33), and the fault injections at :1346 and :1609.

**New:**

- `Test_Attempts` (HPOS and postmeta):
  - persisted before dispatch;
  - the cap never evicts an unresolved entry;
  - the legacy map is read;
  - provenance survives the clear.
- Sweeper:
  - the P1 test;
  - an attempt with no answer is noted once, stays in motion, and is never cancelled;
  - flagged entries leave the index.
- Submission:
  - `test_cash_completion_after_a_stranded_attempt_keeps_it_unresolved_and_supersedes_an_awaiting_one`;
  - a replay after the sweeper resolved answers `recorded` with no dispatch;
  - a new submit while an outcome is unknown answers 409;
  - a stampless cancel on a paid order mints the row and refuses.
- `Test_Gateway_Handler`: paid facts are attributed only when the rows do not cover.

## Pro impact

No code change in Pro:

- `Payments_Sweeper::HOOK` is unchanged (`Refund_Sweep.php:49`).
- `Support_Bundle.php:140` now also lists stranded attempts.

Run Pro's `Test_Refund_Sweep` by hand against c5-2.

## PRs (against `next`, never stacked; production lines)

| PR | Content | Needs | Est. |
|---|---|---|---|
| c5-1 | module, log, JSON + legacy reader, cap, `$detail`, `settle()`, awaiting derived, stamp key + memoise deleted, readers switched, ADR line | c1; c2-1, c2-2, c2-5 | ~330 (−170) |
| c5-2 | `reconcile()`, handler adapter, `to_index()`, sweeper pass (c2-3), the four callers, `mints_attempt` deleted | c5-1 | ~280 (−60) |

Stop and report on a 3x overrun of an estimate, or as a PR nears 600 lines.

## Open (Paul)

- Should D1's paid-facts refusal go into PR 1 before it merges? Recommended: yes.

## Rulings (orchestrating session, 2026-10-22)

- The D1 refusal is adopted and sent to PR 1 as an amendment: `cancel()` accepts a stamp-less `sent` attempt only when the order is not paid; when the order is paid and counting rows do not cover the total it refuses 409 `wcpos_invalid_transition`, leaving reconciliation to this module's resolver.
- Refusing a new submit while an earlier attempt's outcome is unknown (`wcpos_payment_in_flight`) is approved; it goes into the client handoff with c5-1.
- #2377 (stock hold per order on submit) stays out of this module: its own ~20-line PR after c2-5, listed in the plan.
- `_wcpos_awaiting_customer` deleted without a compatibility step is accepted: `next` has no users and neither Pro nor the app reads it.

## c5-1 as built (2026-10-23; the code wins where this differs from the sections above)

- **Interface.** `Attempts::of()`, `open( $d, $id, $method_id, ?Money_Command )`, `await( $d, $id, ?$destination )`, `resolve( $d, $id, $fate )` (a `settled` one supersedes the other sends, as `await()` does), `withdraw( $d, $id )` (nothing was dispatched: the entry goes, so the retry dispatches), `settle( $d, $projection )`, `store( $order, $patches )` (S1's encode, capped) and `unstamp( $order )` (S3's cleanup). `Attempt_Log`: `entries()`, `get()`, `fates()`, `unresolved()`, `awaiting()`. `provenance()`, `to_index()`, `reconcile()` and the entry's `asks`/`attention_at_gmt` arrive with their first caller in c5-2.
- **What an entry awaits lives until the completion's S3, not until S1.** `Projection::of()` reads "a sent submit owns this order whatever its status" from it, and both the S2 decision (re-projected from storage after S1, I4) and the replay after a failed S2 need that ownership to survive S1: a covered on-hold invoice sale would otherwise stay on-hold. So `settle()` resolves the fate in S1 (`paid` / `superseded`) and keeps `awaiting` on the entry; S3's cleanup clears it (`Attempts::unstamp()`), as it cleared the stamp key. `awaiting()` reads the newest entry carrying it. Money that lands without completing clears it in S1, as before; an unanswered `sent` with nothing awaited stays `sent`.
- **The terminal-status listener** is `Attempts::on_status_changed()` (status-changed 10): on cancelled, refunded or trash it defers one held write that clears what the entries await. It has no paid branch in c5-1: clearing on a paid status would run before the completion job (deferred work runs before the completion) and leave `Gateway_Submission::resolve_paid()` without the attempt it mints for. The paid branch comes with `reconcile( 'paid' )` in c5-2, which mints first.
- **`resolve_paid()` stays in `Gateway_Submission`** until c5-2 deletes it, reading the entry.
- **Encoding.** `{"schema":1,"attempts":{id: entry}}`; the legacy serialized `id => fate` map is read by a three-line reader marked for deletion at 2.0.0, and the next write that stages an attempt rewrites it. An emptied map deletes the key.
- **`:438`** (`test_paid_mint_failure_is_loud_and_retry_does_not_redispatch`) still pins `sent` with an empty ledger: its rewrite to `recorded` needs `reconcile()`, so it moves with c5-2.
