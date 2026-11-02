# Spec: completion as an explicit call with its origin passed in, plus one completion-history read model (c4)

**Date:** 2026-10-22 · **Lane:** `next` · **Binding:** ADR 0040 rule 1 and its fiscal section ("money never reads `n`"; `(order_id, n)` is the sale record's key); c2 spec (S3, `defer()`, `is_writing()`, H2) · **Stakes:** checkout-money and fiscal writes, High. · **Vocabulary:** `GLOSSARY.md`; codebase-design terms.

## Outcome

Only the Ledger's S3 writes a completion: `Completion_Stamp::append( $order, $origin )`, with the origin passed in, never read from statics. Completions outside a money command (gateway callback, order-pay, wp-admin) reach the same S3 through one observer's deferred write. Every reader uses `Completion_History`. The wire, `_wcpos_completions` and `wcpos_sale_completed` do not change.

## 1. Write side (`Completion_Stamp`, PR c4-2)

**Name: `append`.** c2's S3 already uses this name, and the history is append-only. `complete()` would read as "make the sale paid", and that is the Ledger's job (rule 1).

```php
public static function append( WC_Order $order, Completion_Origin $origin ): array;  // the standing entry
public static function reopen( WC_Order $order ): void;                               // marks latest entry reopened
final class Completion_Origin {
	public function __construct( array $provenance = array(), bool $order_pay = false ); // body-shaped, validated
	public function for_entry( WC_Order $fresh, int $n ): array; // register, session, sale_time, sale_tz, counter
}
```

`for_entry` keeps today's precedence (:546–560), first non-empty source wins: request provenance, the stored stamp's provenance, the till tuple (`n === 1` or `order_pay` only), the latest captured row's register and session. It is stateless.

**Invariants.**
- **A1. Idempotent.** Under the re-entrant order lock, `append` returns the latest entry when it stands; otherwise it appends `n+1`, synthesising legacy entry 1 first when that applies (:272–275).
- **A2. Write-once.** Only `reopen()` touches a stored entry, and it only sets `reopened_at_gmt` on the latest one, once.
- **A3. One save.** The entry, `_wcpos_completed_at` and the removal of `_wcpos_awaiting_customer` go out in one meta-only save under H2, then are read back. A miss throws `Ledger_Persistence_Exception` with stage `completion`: 409, and the retry runs S3-heal.
- **A4. The action.** `wcpos_sale_completed( $order, $n )` fires only on an append, after the read-back, under the lock. A throwing listener is logged.
- **A5. The caller decides that a completion happened.** `append` does not check paid; S3 already decided it through the Projection. That keeps one rule-1 decision and lets the fixture call `append`.

**The awaiting stamp outlives the paid save.** `append` removes it (A3). The Projection stops clearing it on a completing projection, and `Gateway_Submission`'s terminal clear (:470–480) drops the paid statuses. So the origin is a function of storage at S3: S3-heal names the submitting register exactly, and the passthrough reads the attempt from the stored stamp (`Webview_Passthrough:106`), not from `Completion_Stamp::attempt()`.

**How each caller gets its origin.**

| Caller | Origin |
|---|---|
| Money command (record, intent, capture, close) | The route sets `$command['origin']` from the body's `provenance`. The command calls `$d->completes_at( $command['origin'] ?? new Completion_Origin() )`, new on `Ledger_Draft`, and S3 passes it to `append`. Signatures are unchanged. Provenance is part of the fingerprinted body, so a same-id replay heals with the same origin. |
| Submit | `$command['origin']` goes into the stamp in place of `Completion_Stamp::provenance()` (:348). The final write's S3 appends; the `stamp()` call at :359 goes. |
| Gateway callback, order-pay, wp-admin paid write | The single observer (below). |
| Pro `intent`/`capture` with no `$command` | The default origin: the storage chain, as today. |

**The single observer** is `on_status_changed` at priority 5:
- It returns at once while `Ledger::is_writing( $id )`, because S3 owns that completion.
- **Into the completed set**, it acts only when the order has an origin: a stored stamp, a captured row, or `Webview_Passthrough::is_paying( $id )` on a POS order (the one foreign flag left, read once to set `order_pay`). It queues `Ledger::defer( $id, $job, 'completion' )`. The job is one `write()` whose change calls `Gateway_Submission::resolve_paid( $d )` and then `$d->completes_at( new Completion_Origin( [], $order_pay ) )`; S3-heal (paid, has an origin, nothing standing) appends.
- **A reopen** calls `reopen()` synchronously (meta-only under H2, as c2-2 leaves `persist`), so `stands()` matches storage for the rest of the request.
- **One change to c2's flush:** a `completion` job runs after the order's other deferred jobs, once per flush. That replaces `awaits_mint` and `$completing`: the passthrough's mint (queued at `payment_complete` 10) is stored first.
- A job queued during a submit's dispatch flushes after the outermost write's S3, finds the entry standing, and does nothing.

**`mints_attempt` moves to `Gateway_Submission::resolve_paid( Ledger_Draft $d ): bool`**, which c5 absorbs into the Attempt module. An attempt resolves to a payment row (glossary), so resolution sits with the owner of attempts and the stamp. Not the passthrough: it is the order-pay adapter, gated by its own window, and a wp-admin write is outside every window. The row builder stays `Webview_Passthrough::mint()`'s until the one-builder PR.

**Deleted:** the statics `$context`, `$attempts`, `$completing`; `with_provenance`, `provenance`, `in_command`, `reset`, `attempt`, `memoise`, `stamp`, `has_origin`, `awaits_mint`; the hooks `on_completed` (status_changed 15), `on_payment_complete` (payment_complete 15), `on_pre_payment_complete` with its dynamic `payment_complete_order_status_*` → `on_payment_completed`; the calls at `Ledger:2249` and `Payments_Controller:110`. `resolve_provenance`, `till_tuple`, `validated` and `latest_captured` move into `Completion_Origin`.

## 2. Read model (`Completion_History`, PR c4-1)

`Completion_History::of( WC_Order ): self` decodes once (today's `read()`). Members: `entries()`, `latest()`, `standing()`, `stands()` (with the legacy fallback: `date_paid` and a completed status), `by_n()`, `by_id()`, `counted( $payment_id )` (case-insensitive), `in_session( $id ): int`, `is_empty()`, and `till()` (the standing entry's five fields, nulls included, else the till meta). The static `orders_in_session( $id ): int[]` owns the JSON `LIKE`, so one module knows the stored shape.

| Today | After |
|---|---|
| `end()` + `reopened_at_gmt`: CS:268, CS:335, Ledger:1786, Fiscal:198/209, PC:457; bare `end()`: Ledger:1741, Fiscal:367, Fiscal:494 | `standing()` / `latest()` |
| Find by n: Fiscal:97–101, 203–206 | `by_n()` |
| Find by id: Fiscal:140–144, PC:458–459 | `by_id()` |
| `Session_Figures:198–212` (`LIKE`, then a loop over entries) | `orders_in_session()` + `in_session()` |
| `Fiscal:478` (`is_register_sale`) | `! is_empty()` |
| `Completion_Stamp::stands/counted` (Ledger:1707, 1972; Order_Writer:443, or c1's `Sale_State`) | `Completion_History::of()->stands()/counted()` |
| `as_completed` clone (Fiscal:450–470) | Deleted: after PR 2 (D8) the builder reads the standing entry through `till()`, and `record_sale` builds only for the standing latest (:209). If PR 2 fell back per field, c4-1 makes it whole-tuple, so fiscal documents stay byte-identical. |

Money reads `stands` and `counted`, never `n`: the ADR line holds.

## 3. Wire and fiscal contract (unchanged)

- Route answers keep `completion {n, id}`; `_wcpos_completions` keeps its shape and its push-lane strip.
- **Correction to the brief:** the hook is `wcpos_sale_completed( $order, int $n )`, not `$entry` (CS:306); kept.
- No listeners outside Free: the extension, terminal, gateway and docs clones and `monorepo` grep clean.
- Wiki edits after c4-2: `cart-settlement-completion-proofs.md:29` (priorities 5/15, the windows) and `v2-money-model.md:156`.

## 4. Tests

- **Survive as the behavioural spec:** all 28 `Test_Completion_Stamp` tests and the HPOS subclass; they drive routes, `payment_complete()` and `update_status()`. No test is deleted; `test_a_no_op_payment_complete_…` stays as the `$completing` leak's record.
- **Waste:** the 8 `reset()` calls (that file's setUp, tearDown, :263, :283, :310, :345; `Test_Gateway_Submission` :679, :700). With no statics, "a later request" is the default.
- **New `Test_Completion_History`:** standing vs latest after a reopen; legacy `stands`; `by_n`/`by_id`; `counted` case-insensitive; a reopened, re-completed sale counted twice in its session; `orders_in_session` under CPT and HPOS; `till()` keeps a null counter.
- **New `Test_Completion_Append`:** two calls, one entry, same `{n, id}`; `reopen()` then n+1; legacy synthesis then 2; the origin precedence table, invalid fields dropped; a read-back miss throws `completion` and fires nothing; the stamp goes in the same save; the action fires once, under the lock.
- **New in `Test_Ledger_Write`:** a sent attempt whose S3 fails, retried, names the submit's register; a completion job queued during dispatch is a no-op after the final S3.
- **`Completion_Fixture`**'s 15 call sites (12 `sale`, 2 `reopen`, 1 `seed`) are raw meta writes. `seed()` becomes `append( $order, new Completion_Origin() )` and `reopen()` calls `Completion_Stamp::reopen()`. Entry 1 now takes the whole till tuple, so `sale_time`, `sale_tz` and `counter` stop being forced to null: run the 9 using files and fix any assertion that leaned on the nulls.

## 5. Pro impact

Pro `origin/next` greps clean for `Completion_Stamp`, `_wcpos_completions`, `_wcpos_completed_at`, `wcpos_sale_completed`, `with_provenance`, `as_completed`. Its `intent`/`capture` calls (Conformance, Device tests) pass no `$command` and get the default origin, as today. No companion PR.

## 6. PRs and budget (added production lines, tests excluded)

| PR | Content | Needs | Est. |
|---|---|---|---|
| **c4-1** | `Completion_History`; all readers moved; `read/stands/counted` leave `Completion_Stamp`; `as_completed` deleted | PR 2 (D8). **Independent of c2: land early**; before or after c1 (one line in `Sale_State`) | +190 / −120 |
| **c4-2** | `append`, `reopen`, `Completion_Origin`, the observer, `completes_at`, completion-last flush, `resolve_paid`, stamp consumed, route origin | c2-5 (all completing writers on `write()`); c4-1 | +260 / −330 |

Passing an estimate by 3x, or nearing 600, is a stop-and-report.

## Open (for Paul)

- **Fiscal cashier for n ≥ 2** comes from `_pos_user`, the first sale's cashier (`Fiscal_Record_Store:485`), the cashier sibling of D8. (a) An optional, additive `cashier` on entry and origin that the writer prefers, ~10 lines in c4-2 (recommended); (b) leave it.
- **A crash between A3 and A4** leaves entry n standing with no sale row and nothing parked. That is #2378's gap: fix it in #2378 (scheduled recovery also writes a missing sale for a standing entry), not in c4.

## Rulings (orchestrating session, 2026-10-22)

- Option (a): the completion entry and `Completion_Origin` carry an optional `cashier`; the fiscal writer reads it for completion n≥2 and falls back to `_pos_user` when absent. Lands in c4-2.
- The crash gap between the entry save and `wcpos_sale_completed` is #2378's; it gets its own PR after c4-2 (a scheduled retry on the sweeper hook that re-runs `recover()` for completions without a sale record), not a line here.
- The hook signature is `( $order, int $n )` as the code says; the spec text must not claim `$entry`.
