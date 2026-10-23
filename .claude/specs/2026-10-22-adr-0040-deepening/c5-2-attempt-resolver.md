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
| unchanged, or `asks` reaches `ASK_LIMIT` (6) | `attention_at_gmt` is set and ONE order note is added. The entry stays `sent`: in motion, still locked by rule 3; `close` answers `wcpos_payment_in_flight`. |

**No path cancels an attempt the provider never answered.**

It runs under the order lock on a fresh read, asks the handler outside the write (a write's change is pure),
and re-checks the entry inside the write (a concurrent resolution wins).

## The provider: `Gateway_Handler::status()`

The resolver hands `Capture_Mode_Registry::resolve( 'gateway', provider )->status()` the pseudo row
`{ id: attempt id, status: pending, capture_mode: gateway, method_id, order_id }`. Free's `Gateway_Handler`
reads the order (the gateway's PHP has no status API) and answers `captured` (amount = order total, the order's
`_transaction_id`) only when all three hold: the order is paid (`Completion_Stamp::is_paid()` or `date_paid`),
its `payment_method` is the attempt's, and the counting rows do not cover the total. Anything else, and any row
whose `capture_mode` is not `gateway` (a webview row reaching `Webview_Handler`, its subclass), is answered
unchanged. A provider-scoped `gateway:<provider>` handler may ask its own API. Pro's `server`/`device` handlers
are not asked.

## How the sweeper finds an attempt

- `Attempt_Log::to_index()`: the `sent` entries neither awaiting nor flagged.
- S1 adds them to the EXISTING `_wcpos_payment_live` index next to the live rows: `stage()`, `advance()`, and a
  held write on an order with no ledger (the first attempt on a sale). No new key or option. Flagged entries
  leave the index, so they cannot starve the batch; awaiting entries never join it.
- `Payments_Sweeper::run()` gains one step, inside the order's lock, before its trailing write: when
  `unresolved()` is older than the threshold, `reconcile( 'sweep' )`. The candidate list is the same index, so
  the step shares the live-leg batch; #2448's fiscal pass is untouched.

## The callers

1. **A replay of a `sent` attempt** (a new command naming it, `submit`): reconciles first. A row it mints for
   this attempt answers `recorded` with the row (D6: this command recorded it); a cancel answers the existing
   409 `wcpos_payment_conflict`; otherwise the fate as before.
2. **`cancel()`**: reconciles first. A minted row trips the existing refusal ("This invoice cannot be
   cancelled."); the D1 paid-facts refusal stays for paid facts the resolver could not attribute (another
   method).
3. **A new `submit`**: when an unresolved entry that is not awaiting stands, it reconciles; if one still stands,
   409 `wcpos_payment_in_flight` with `attempt_id`, before any check that reads the sale. "Send again" over an
   awaiting invoice is unchanged (an awaiting entry is not asked).
4. **The paid listener**: the completion observer's deferred job (`Completion_Stamp::defer()`) runs
   `reconcile( 'paid' )` before its completing write. It runs after the order's other deferred work, so a
   callback's own mint lands first (its webview row, the attempt id as the row id) and the resolver then finds
   nothing to do; a paid status written without `payment_complete()` (wp-admin, a status-only gateway) is
   minted by the resolver. With `paid` the resolver asks only about the attempt that awaited the customer.

`Gateway_Submission::resolve_paid()` is deleted: the resolver is its only replacement.

## The lost webview / order-pay mint (carried from c4-2 #2446)

A payment WooCommerce took whose deferred mint failed leaves a paid POS order with no row; `payment_complete()`
is a no-op on a `processing` order, so a retried webhook never re-mints. Under c5-2:

- `Webview_Passthrough::mint()`'s failure path (after its loud note) calls `Attempts::strand( $order, $id,
  $method )`: one held write that keeps the payment as an unresolved attempt (the row id the mint tried, its
  method) or, when the attempt already stands awaiting the customer, stops it awaiting (the customer paid).
  Best effort: a failure is logged; the note stands.
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
