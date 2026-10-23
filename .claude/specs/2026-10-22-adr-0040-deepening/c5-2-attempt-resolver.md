# Spec: the Attempt module resolves a sent attempt from the provider's answer (c5-2)

**Date:** 2026-10-23 · **Lane:** `next` · **Binding:** ADR 0040 rule 7 · **Stakes:** checkout-money, High.
**Builds on:** c5-1 (#2450, `c5-1-attempts.md`, "as built" section) and #2448 (the sweeper's fiscal pass).
**Parent spec:** `c5-attempts.md` §"The resolver", §"The P1 carried from #2312", §"Rule 7: no retry before resolution".

## Outcome

An attempt whose outcome is unknown (`sent`, awaiting no one) is resolved from the provider's answer by one
function, `Attempts::reconcile( $order, $source )`, and nothing else decides its fate. Four answers, exactly:

| Provider answer | What the resolver does |
|---|---|
| `captured` | One `write()` puts the row: the attempt id as the row id, the entry's method and cashier, the answer's transaction id; `completes_at()` names the entry's origin, so S3 appends the completion (the c4-2 path). `settle()` marks the attempt `paid`; the command that sent it answers `recorded` on replay. `put()` replaces by id: a second run changes nothing. |
| `failed` / `voided` | The attempt becomes `cancelled`; the write's idle check releases the stock hold. |
| indeterminate (a `WP_Error`, or no handler) | `asks++`. |
| unchanged, or `asks` reaches `ASK_LIMIT` (6) | at the sweep only (past its threshold): `attention_at_gmt` is set and ONE order note is added. The entry stays `sent`: in motion, still locked by rule 3; `close` answers `wcpos_payment_in_flight`. Other callers leave it unflagged. |

**No path cancels an attempt the provider never answered.**

It runs under the order lock on a fresh read, asks the handler outside the write (a write's change is pure),
and re-checks the entry inside the write (a concurrent resolution wins).

## One selection per source: `Attempt_Log::eligible( $source )`

The resolver acts on exactly the attempt `eligible()` returns; its predicate per source IS the eligibility, so
nothing is selected and then rejected.

- `paid`: the newest attempt awaiting the customer, or one whose row was lost.
- `sweep`: among the attempts `to_index()` lists (owed, nobody awaiting them or their row lost, not flagged), the
  newest one the handler can answer for (its row lost, or its method the order's), else the newest listed. An
  attempt the handler cannot answer never starves an older one it can. It is flagged in its turn and leaves the list,
  so an order holding only flagged attempts leaves the sweep (the staff clear is its exit).
- `replay`, `cancel`, `submit`: the newest owed attempt nobody awaits (a lost one included).

`to_index()` and `eligible( 'sweep' )` share one predicate, so the index never lists an order the sweep would skip.

## The attempt carries its origin

`open()` keeps the submit's validated provenance and cashier on the entry; `strand()` keeps the order's till tuple and
`_pos_user` (the sale that was rung), never the customer who paid. The resolver's row takes the entry's register and
session. `Completion_Origin::for_entry()` reads, after the command's and the stamp's, the provenance of the `paid`
attempt whose stored row no completion has counted, so a completion healed by the sweep names the submit's register,
session and cashier, and the fiscal sale does too.

## The provider: `Gateway_Handler::status()`

The resolver hands `Capture_Mode_Registry::resolve( 'gateway', provider )->status()` the pseudo row
`{ id: attempt id, status: pending, capture_mode: gateway, method_id, order_id, sent_at_gmt }`. Free's
`Gateway_Handler` reads the order (the gateway's PHP has no status API) and answers `captured` (the order's
`_transaction_id`) only when all three hold: its `payment_method` is the attempt's, the counting rows do not cover
the total, and the order was paid after the attempt was sent. Paid after the attempt means one of:

- with no reopen, `date_paid` on or after `sent_at_gmt`, or a paid status with no `date_paid` (a wp-admin status
  change; the sale took the attempt while open, so the paid status followed it);
- on a reopened sale, which keeps its first `date_paid` because WooCommerce never moves it, the attempt was sent
  after the reopen and the order is in a paid status now (a sale takes an attempt only while open).

Paid facts older than the attempt are indeterminate (an ask), not unchanged. A row with no `sent_at_gmt` (a stored
row) is answered unchanged. Anything else, and any row
whose `capture_mode` is not `gateway` (a webview row reaching `Webview_Handler`, its subclass), is answered
unchanged. A provider-scoped `gateway:<provider>` handler may ask its own API. Pro's `server`/`device` handlers
are not asked.

## A paid attempt whose row is missing is unresolved (Codex P1 on #2453)

A captured write whose S1 stored the attempt's `paid` fate but lost its row (meta keys save one by one) leaves
money taken with no row. `Attempt_Log::unresolved()` and `to_index()` therefore read a `paid` entry with no row of
its id as unresolved (the row ids are read only when a `paid` entry asks). Every caller re-asks it, whatever it
awaited; the resolver's captured path re-mints the row under the same id and keeps the fate (nothing is charged
again); a non-captured answer never cancels it. Recovery is across requests, by the sweep.

**The index is written last, from read-back state.** S1 no longer writes `_wcpos_payment_live`. Once S1's read-back
confirms the rows and attempts landed, `Ledger::index_live()` computes the index from storage (rows pending,
authorized or owed, plus `Attempt_Log::to_index()`) and saves that one key meta-only (`Ledger::save_meta`), only
when it differs, then reads it back. S3's cleanup does the same after its own read-back. A write whose rows did not
land on both S1 tries never reaches it, so the index keeps listing the attempt it listed while `sent`, and the next
sweep re-mints the row. The index never claims more than storage holds. The extra meta save happens only on writes
that change the live set.

**A write that leaves something live must prove the index lists it.** The index is the sweep's only way back to an
order (`Payment_Index::order_ids_with_live_legs()` selects through that key alone). So when the index read-back does
not hold a non-empty computed index (an unresolved attempt, a live intent, or completion debt), the write fails closed
with `Ledger_Persistence_Exception`, as a lost row does. A submit's held write fails before dispatch, its attempt is
withdrawn, and the till gets the retryable 409. When the computed index is empty (the order leaving the list), a
mismatch is a logged warning: a stale entry costs the sweep one look, and the next write recomputes it.

**The index carries completion debt.** While `completion_due()` holds (captured rows cover the sale and no
completion counted them), the uncounted captured rows stay in the index; S3 recomputes it after its own read-back
and drops them once the completion stands. If S3 throws, the order stays listed and the sweep's trailing write
(its `heal()`) completes the sale once, writing the fiscal sale.

`strand()` opens the kept attempt with the cashier the lost row would have named (the order's `_pos_user`, else 0
so `Webview_Passthrough::row()` decides), never the customer who paid through order-pay (Codex P2 on #2453).

## The captured amount on a split tender (CodeRabbit on #2453)

`Gateway_Handler::status()` answers `captured` for the remainder the counting rows leave (order total minus
`Ledger::paid()`), never the whole total again: a cash row plus a gateway attempt the webhook paid sums to the total.

## How the sweeper finds an attempt

- `Attempt_Log::to_index()`: the unresolved entries (`sent`, or `paid` with no row) neither awaiting nor flagged.
- They join the EXISTING `_wcpos_payment_live` index next to the live rows, written last from read-back state (see
  above). No new key or option. Flagged entries leave the index, so they cannot starve the batch; awaiting entries
  never join it.
- `Payments_Sweeper::run()` gains one step, inside the order's lock, before its trailing write: when
  `unresolved()` is older than the threshold, `reconcile( 'sweep' )`. The candidate list is the same index, so
  the step shares the live-leg batch; #2448's fiscal pass is untouched.

## The callers

1. **A replay of a `sent` attempt** (a new command naming it, `submit`): reconciles first. A row it mints for
   this attempt answers `recorded` with the row (D6: this command recorded it); a cancel answers the existing
   409 `wcpos_payment_conflict`; otherwise the fate as before.
2. **`cancel()`**: reconciles first. A minted row trips the existing refusal ("This invoice cannot be
   cancelled."); the D1 paid-facts refusal stays for paid facts the resolver could not attribute (another
   method). **Staff clear (ruling 2026-10-23, option b):** a stranded attempt the sweep has flagged, on a sale
   whose counting rows already cover the total, is marked `cancelled` by `cancel()` without reopening the sale
   and without touching its rows or stock. The sweep's note stays as the audit trail and one more note names who
   closed it (the command origin's cashier, else the current user). An attempt not yet flagged (still within the
   threshold) is refused as before: it waits for the resolver. Nothing attributes money by inference, so no
   webhook mint ever reuses an attempt id. This clear is the only way the "in motion" state ends on a covered
   sale.
3. **A new `submit`**: when an unresolved entry that is not awaiting stands, it reconciles; if one still stands,
   409 `wcpos_payment_in_flight` with `attempt_id`, before any check that reads the sale. "Send again" over an
   awaiting invoice is unchanged (an awaiting entry is not asked).
4. **The paid listener**: the completion observer's deferred job (`Completion_Stamp::defer()`) runs
   `reconcile( 'paid' )` before its completing write. It runs after the order's other deferred work, so a
   callback's own mint lands first (its webview row, the attempt id as the row id) and the resolver then finds
   nothing to do; a paid status written without `payment_complete()` (wp-admin, a status-only gateway) is
   minted by the resolver. With `paid` the resolver selects the attempt `awaiting()` names (the one that awaited
   the customer, as `resolve_paid()` did), never the newest unresolved entry: a later send stranded beside an
   awaited invoice stays `sent` for the sweep.

`Gateway_Submission::resolve_paid()` is deleted: the resolver is its only replacement.

## The lost webview / order-pay mint (carried from c4-2 #2446)

A payment WooCommerce took whose deferred mint failed leaves a paid POS order with no row; `payment_complete()`
is a no-op on a `processing` order, so a retried webhook never re-mints. Under c5-2:

- `Webview_Passthrough::mint()`'s failure path (after its loud note) calls `Attempts::strand( $order, $id,
  $method )`: one held write. A lost mint is a known payment whose row was lost, not an unanswered attempt, so the
  attempt (the row id the mint tried, or the attempt that stood `sent` for it) is kept `paid` with no row, awaiting
  no one, `sent_at_gmt` the moment of the mint, with the sale's till tuple and `_pos_user`. `eligible()` lists it and
  the resolver re-mints its row under that id for the remainder the rows leave, keeping the fate and never asking
  `status()`, while WooCommerce's own paid facts stand (`is_paid()` or `date_paid`; a lost save of the paid status
  leaves nothing to mint from, and the attempt is asked about and flagged like any other). A submit that saw the
  gateway take the money and lost the row keeps its attempt `paid` the same way, and its replay answers `paid`. So the reopen rule in `status()` governs only attempts the till sent. Best effort: a failure is
  logged; the note stands.
- The sweep then finds it: the resolver's `captured` answer IS the re-mint, and its write completes the sale,
  once. When the handler cannot answer (the order is no longer paid through that method), the attention path
  applies: one note, never a guess.
- The paid listener asks only about the attempt that awaited the customer: an attempt nobody awaited (a stranded
  send, or the payment a failed mint kept) is the sweep's, so a resolver write never runs inside the same outage that
  just refused the mint. A write that stores only part of S1 (the attempt `paid`, its row missing) is redone by the
  resolver's in-write re-check instead of being read as resolved.
- `mint()` adopts no attempt id it was not given: a payment that is not the attempt's (a webview payment beside a
  stranded send of the same method) must never mark that attempt `paid` and hide a charge.

## Tests (both storages where the class has an HPOS twin; new tests dispatch `wcpos/v2`)

New in `Test_Attempts` (+HPOS): captured → one row, one completion, one fiscal sale, the command's replay
answers `recorded` with the row; failed → cancelled, hold released; indeterminate → `asks` increments, no row;
the limit or an unchanged answer → attention + one note, still in motion, `close` answers
`wcpos_payment_in_flight`, never cancelled; the index lists only sent, unflagged, non-awaiting entries.

New in `Test_Payments_Sweeper`: the sweep picks only indexed entries older than the threshold;
`test_sweeper_mints_a_paid_attempt_whose_mint_failed_and_its_replay_answers_recorded`.

`Test_Gateway_Submission` (+HPOS): `:474` `test_paid_mint_failure_is_loud_and_retry_does_not_redispatch`
rewritten to assert `recorded`, `payment.id === attempt`, one gateway call; a new submit over an unresolved
attempt → 409 `wcpos_payment_in_flight {attempt_id}`; "send again" over an awaiting invoice unchanged; the D1
case re-expressed: a stampless cancel on a paid order mints the row and refuses.

`Test_Completion_Stamp` (+HPOS): `test_an_async_order_pay_completion_waits_for_a_row_whose_mint_failed`
extended: the sweep re-mints and completes it, once.

`Test_Gateway_Handler`: paid facts are attributed only when the rows do not cover and the method is the attempt's.

## Pro

Grep `origin/next` for `resolve_paid`, `reconcile`, `Gateway_Handler`, `status(`,
`Capture_Mode_Registry::resolve`, `_wcpos_payment_live`, `Support_Bundle`. `Payments_Sweeper::HOOK` is
unchanged. Pro's `Server_Handler`/`Device_Handler` override `status()` for their own modes and are never asked
for a gateway attempt. `Support_Bundle.php:140` selects candidate orders by `_wcpos_payment_live`, so an order
with a stranded attempt is now selected; the bundle prints ledger rows only.

## Client handoff (the app)

A new `submit` while an earlier attempt's outcome is unknown answers 409 `wcpos_payment_in_flight` with
`data.attempt_id`. The till shows the sale as waiting on that attempt's outcome and keeps "Try again" disabled
until it resolves: a replay of the same attempt (or the sweep, within ~10 minutes) resolves it to `recorded`
(the provider took the money; the row is in the answer), or the till's cancel releases it when nothing was
charged. When the provider cannot say, the order carries one note for staff ("check the gateway, then cancel the
attempt at the POS"), and the attempt stays in motion until staff act.
