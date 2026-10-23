---
status: accepted — 2026-10-19; replaces the money-epoch design of payments contract 1.3 (wiki `architecture/client/payments-contract.md` §13, `cart-settlement-completion-proofs.md`) before it ships; #2312 and its lean carve #2328 are closed unmerged under this decision
---

# Money state on a sale: the server derives "paid" from its payment rows, money never rides in the order document, and there is no money epoch

A WCPOS sale's money state is derived, by the server, from the payment rows it holds: the
balance is the order total minus every captured row plus every refund, and the order is paid
when that balance reaches zero. The till records tenders through payment commands and never
writes a payment, a completion stamp or a paid status into the order document it pushes.
Reopening a paid sale is a status change to an open status with every payment still counting;
the balance after the edit is simply collected or refunded. The `_wcpos_money_epoch`, the
epoch field on ledger rows, the epoch-scoped readers, the owner stamp and till aggregate, the
settlement-change diff, the protected-meta guard and the five-way completion proof are not
built. This is WooCommerce's own model with the two things WooCommerce lacks added on top:
several tenders per order, and a retry protocol that survives offline tills.

**Why this is being decided now.** The contract-1.3 server work (#2312) implemented the epoch
model and went through twenty-five Codex review rounds; its lean carve (#2328) went through
five more and sixteen findings. Fourteen of the sixteen were real, and most were the same
finding: a reader that forgot to filter prior-epoch rows (gateway replay, manual-record replay,
adopt, the born-twice repair, poison recovery, the sweeper), or a window between the proxied
wc/v3 PUT and the money transition that ran as a second phase under a lock. Each fix exposed
the neighbouring gap. A rule that has to be applied at fifteen read sites will be missed at
some of them; a write that commits in one phase and transitions money in another needs
recovery machinery for the gap, and that machinery (checkpoints, deferred facts, poison replays
for updates) is what the review loops kept finding races in. The loop was not reviewer noise;
it was the cost of the model.

**What the industry does.** A primary-source survey of Square, Stripe Terminal, Adyen, Shopify,
Lightspeed X-Series, Clover, Odoo POS (source), Toast, WooCommerce core (source) and the ledger
literature (Fowler, Modern Treasury, TigerBeetle, Stripe and Square's idempotency writing) is at
`.claude/research/2026-10-19-pos-money-state-models/RESEARCH.md`. Five patterns are shared by
three or more systems: payments are separate records that reference the sale and the order
carries read-only rollups; a money record is never rewritten, a correction is a new linked
refund, void or reversal; a paid sale is not reopened in place, and the three systems that do
let a paid sale be edited (Toast, Shopify, WooCommerce) have no epoch, prior payments keep
counting and the balance is recomputed; the server decides "paid" by summing its own rows
against the total and ignores the till's claim (Odoo: "Recompute amount paid because we don't
trust the client"); retries carry a client-minted id and the server stores the id, a fingerprint
of the body and the outcome. No surveyed system stops a captured payment counting because the
sale was reopened, and none archives or filters money by a sale generation.

**Why WooCommerce's own shape, not Square's.** WooCommerce merchants run one catalogue with two
front doors, and the common hybrid flows all cross them: an online order left pending or on-hold
(pay in store, cash on delivery, a bank transfer that never arrives) is finished at the counter;
an on-hold order has items added when the customer collects; an order paid online by card comes
back for an exchange or an extra item; an online sale is partly returned in store; a layaway is
paid in instalments. WooCommerce already models every one of these with a single order,
`payment_complete()` (sets `date_paid`, moves to the paid status, reduces stock, sends the
processing email), admin edits that recompute totals, and refunds as linked child orders. Its
`is_editable()` rule (items editable only on pending, on-hold and drafts) is the reopen rule;
merchants flip the status back and edit, and the REST API permits it. Everything downstream
(reports, accounting plugins, emails, other plugins' hooks) reads status, `date_paid`,
`_transaction_id` and refund objects. So the ledger expresses its conclusions through those
facts, never beside them, and the POS model is WooCommerce's with multi-tender and idempotency
added. Dropping reopen in favour of refund-and-re-ring (Square, Odoo, Lightspeed) was rejected:
it is not how WooCommerce merchants work, the till already reopens, and the Toast/Shopify shape
needs no new concept.

**The rules.**

1. *Paid is derived on every money write and on the explicit close.* Balance = total − Σ captured
   rows + Σ **succeeded** refunds; a `pending` refund is a reservation shown beside the balance,
   never added to it, and a `failed` refund adds nothing. Balance ≤ 0 on a non-zero total runs
   `payment_complete()` into the configured paid status (the per-gateway POS order status;
   "completed" in WooCommerce means fulfilled, "processing" means paid); 0 < paid < total is
   `pos-partial`; nothing paid leaves the order in the open status it had. The server writes the
   POS completion stamp (sale time, zone, register, session, counter, for receipts and fiscal
   provenance) at that transition, as a record of when it closed the sale, never as evidence read
   back from the till. The order's `payment_method` becomes the tender that actually paid. The
   money commands are **record, intent, capture, void, refund, cancel, submit** and **close**:
   `close` moves no money; it re-derives the balance and, when it is covered, runs the same paid
   transition, so a reopened sale that the cashier closes without a line change completes again
   (completion `n+1`) through a request the till is allowed to make. The push lane cannot close a
   sale.
2. *Money never travels in the order document.* The push lane carries lines, customer, address,
   notes and status changes among open statuses. Every **server-owned key** in a pushed document
   is dropped before the write, whether sent by key or as an id-addressed edit or tombstone of an
   existing meta row: `_wcpos_payments` and the ledger index keys, `_wcpos_completed_at` and
   `_wcpos_completions`, `_wcpos_gateway_attempts`, `_wcpos_awaiting_customer`, and the
   WooCommerce money fields `date_paid`, `set_paid` and `transaction_id`. This is a key list and a
   strip, not the fail-closed guard of #2312: the list is the design's own, and a new server-owned
   key joins it in the same change that introduces it.
3. *Reopen is a status change with all payments still counting.* It is refused only while money
   is in motion: a provider leg is `pending` or `authorized`, a gateway attempt is unresolved, or
   a refund allocation is `pending` at the provider. Those are locks on money in motion, not
   money rules. A fully paid sale reopened and closed unchanged is still paid. Whether the till
   closes it before the cashier has finished editing is the app's trigger, not a server concept.
4. *Line edits need an editable status first.* A pushed change to line, fee, shipping, coupon or
   tax lines on an order WooCommerce's `is_editable()` refuses (every paid, completed, cancelled,
   refunded or failed status, not only the paid ones) is refused with `reopen_required`. This is
   `is_editable()` enforced at the POS layer, because wc/v3 does not enforce it.
5. *Every money command is idempotent by a till-minted command id, for as long as the row it
   touched exists.* `record` and the webview mint use the row id as the command id, because they
   create the row; `intent`, `capture`, `void`, each `refund` allocation, `cancel`, `submit` and
   `close` carry their own command id, because several of them act on the same row over its life.
   The row keeps a history of `(command id, body fingerprint, outcome)` entries: same id and body
   replays the stored outcome; same id and a different body is 422; same id while the first is in
   flight is 409. Offline cash rows replay days later and create one row. No separate table (see
   `.ai/rules/db-tables.mdc`).
6. *Every money command carries the order revision it was built against* (the same content hash
   as the push lane, ADR 0033) and a stale one is refused so the till re-reads. **Replay is checked
   before the revision**: a retry of a command that already ran carries the revision from before
   its own effect, so the same-id-same-body lookup of rule 5 answers it with the stored outcome,
   and only a command that has not run meets the revision check. Nothing in WooCommerce
   serialises concurrent writers to an order; the POS has to.
7. *Gateway and terminal payments are two saves around the external call.* The attempt is
   persisted before `process_payment()`; the row is posted or voided after; a sweeper resolves a
   stranded attempt by asking the provider for the client's reference before any retry. This is
   the one place a two-phase shape is unavoidable, because a card charge cannot be rolled back.
8. *A refund record is never deleted after the gateway refunded.* WooCommerce core deletes the
   refund object in a `catch` even when `process_refund()` had already succeeded
   (`wc-order-functions.php`, trunk); a POS refund marks it for attention instead.

**Why not the epoch.** Keeping every row and excluding some by a generation marker is Fowler's
"rejected event" pattern, which the ledger literature allows only for pending entries and which
costs "many entries that every query has to see past"; WCPOS applied it to captured money. The
two concerns the epoch answered are answered elsewhere: "retained rows would complete the sale
again at once" is correct behaviour (nothing changed, so it is still paid) and the till's
auto-close trigger is the app's; "a legacy till could re-assert an overruled aggregate" goes away
because the till cannot write money at all and WooCommerce is the calculation authority for
lines and tax. **Why not archive prior rows on reopen** (the first greenfield sketch): the same
evidence; a captured payment is a real-world event and stops counting only through a linked
refund or void. **Why not accept the till's completion stamp with proof rules**: five proofs
(stamp, ledger coverage, settled attempt, legacy composite, zero-total/reduced-total) each need
their own scoping and their own reopen rule, and three of the sixteen #2328 findings lived in
that OR. **Why not one atomic save for gateway payments**: the external call is not part of any
local transaction; Brandur, Square's delayed capture and TigerBeetle's pending/post/void all
model it as two durable steps.

**Consequences.** Most of the 1.3 server work is removed back toward the 1.2 shape, since
`Ledger::derive()` already projected status from rows before the epoch was layered on. Deleted:
`Money_Epoch`, the row `epoch`, `Money_Owner`, `Money_Compare`, `tillAggregate`,
`expectedEpoch`, `Money_Meta_Guard` (replaced by a short strip of money keys on push),
`Settlement_Change` (replaced by the `is_editable` gate), the completion-proof OR, every
epoch-keyed reopen refusal, the update checkpoint and poison lanes, and the post-commit
loud-failure path (there is no second phase when the status transition happens inside the
ledger save). Kept: the ledger rows and their lock, the persistence read-back and
concurrent-row merge, the refund rollup from WooCommerce refund objects, the
sent-before-dispatch attempt, the sweeper, the webview passthrough for order-pay payments,
`Protocol_Gate`, and the PHPUnit lease guard; the epoch-independent hardening in #2328 returns
as one small PR. Added: the idempotency triple on the row, the revision check on payment
commands, the `is_editable` gate, the refund-record rule. The client's contract-1.3 gate
(wcpos/monorepo#2702, #2710) is redefined: 1.3 means "the server derives paid, refuses stale
revisions on payment commands and strips money from pushes", and the reopen UX stops expecting
money to reset. The wiki pages named above are rewritten, not amended, and so are the fiscal-groundwork spokes
that keyed the sale record on the money epoch (see the fiscal section below).

**Fiscal compliance: two worlds, one bridge.** WCPOS has to satisfy two models that contradict
each other on the face of it. WooCommerce's order is a living commercial record: merchants reopen
it, edit lines, change status, refund, and every report and plugin downstream expects that. The
register regimes WCPOS will meet (France: CGI art. 286 I 3° bis and the NF525 conditions of
inaltérabilité, sécurisation, conservation and archivage; Spain: VeriFactu, RD 1007/2023, with
chained, immutable invoicing records corrected by an anulación or a rectificativa; Germany:
KassenSichV with every transaction signed by the TSE and corrections as new transactions; Italy,
Portugal and Austria in the same family) say the opposite: once a sale document is issued it is
done, numbered gaplessly per register, never altered, and any change is a further document that
points at the one it corrects. The resolution is the one the fiscal-groundwork contract already
states and the ledger literature backs: **the two worlds never share a record.** The order stays
WooCommerce's mutable record. The fiscal store holds write-once rows about *events* on the order:
a sale completing at a register, a refund, a void, a cancellation, a closure. Reopening the order
touches no issued document; it is a change to the commercial record, not to the fiscal one.

What the fiscal side needs from the money side is only an identity for "which completion", and
that is what the money epoch had been lent for: the wiki keyed the sale record's write-once
identity on `(order_id, epoch)`, stamped `_wcpos_epoch_provenance[n]` and fired
`wcpos_sale_completed( $order, $epoch )`. Under this ADR that identity is the **completion
number**: each time the server completes the sale (rule 1), it appends an entry to the order's
completion history, `_wcpos_completions[n]` with the sale time and zone, register, session,
counter and total of that completion, and the pair **`(order_id, n)`** is the fiscal sale
record's write-once key, exactly where the wiki had `(order_id, epoch)`; `n` restarts at 1 on
every order and is never an identity on its own. Money never reads `n`; it is provenance, not a
money state, and the push lane strips `_wcpos_completions` like every other server-owned key
(rule 2). The fiscal writers then behave exactly as the
groundwork rules them: completion `n` writes `sale` record `n` whose payload names the record it
supersedes, closure totals take the signed adjustment (new total minus superseded total) through
the `adjustments` counter so sales and refunds stay monotonic, a cancellation names the current
completion's record, and a refund names the sale record whose payment rows it reverses. A sale
reopened and re-completed at the same total still writes record `n+1` superseding `n` with a zero
adjustment, because the regimes count documents, not differences. The groundwork's "POS-created
orders only" condition on the sale writer is refined to "completed through a register": the
hybrid flows above (an online order finished at the counter) are fiscally a sale at that
register, and the completion stamp carries the register that took the money, whatever door
created the order.

Where a regime forbids editing an issued ticket at all, the country module (2.1.0, wcpos/roadmap#225)
presents the reopen of a completed sale as "correct this sale" and produces the same two records
the model already writes, the superseding sale and any refund, in the regime's own vocabulary
(annulation and re-issue, rectificativa). Nothing in the money model changes for it, and
nothing here chooses a hash chain, a signing engine or an export format; those remain the country
modules' work. The fiscal-groundwork spokes `records-and-closures.md`, `corrections.md` and
`closure-as-ruled.md` are rewritten to key on the completion number where they say epoch; the
landed `Fiscal_Record_Store` on `next` has no epoch column and one sale record per order, so the
change is to unlanded design, not to data.

**Still to decide, in the design that follows this ADR.** Whether the till's `status` for
in-progress states is honoured as sent or mapped; how a reopened online order that was paid by
an online gateway shows its original tender in the ledger (a synthetic row from
`_transaction_id`, as the webview passthrough already does for order-pay); and the exact
refusal codes, which stay 422 on the push lane and 409 on the route family (#2312's ruling).

**Offline tenders: status (2026-10-23).** This is the section the app's code points at as
"ADR 0040 §offline" (`OFFLINE_TENDERS_QUEUE` in core's `checkout/tender/tiles.ts`, the SumUp
driver and module in `apps/main`). Today a tender the store cannot hear is refused: every tile is
disabled with the `offline` reason while the till is offline or the order's save is queued, and
nothing starts a SumUp offline session. That is sequencing, not product intent: offline card
payments on the Solo readers and offline cash are merchant features; they wait on the *offline
tender outbox*, the design that lets a tender be journaled on the till and sent when the store is
back. What already exists and what is still missing:

- *Exists, server:* rule 5 above. A `record` is keyed on its row id and replayed from its stored
  outcome, so one sent days later creates exactly one row; a command built on a stale revision is
  refused with the current document (rule 6) and the till re-sends it once. Nothing on this side
  needs to change for the outbox.
- *Exists, till:* the money command journal (`packages/query/src/store-local/money-commands.ts`)
  survives reloads, claims and leases its entries, and the recovery path re-sends unanswered
  commands on reconnect with `RECOVERY_RETRY_DELAYS_MS`; the completion journal and the receipt
  barrier (`residentHoldsAnswer`) already hold a sale "awaiting adoption" until the store's
  document arrives.
- *Exists, SumUp:* the driver's session query, upload-before-checkout, reconnect sync and the
  `authorized` outcome for a checkout taken inside a session, and the native module's four
  offline methods, validated on a physical Solo in wcpos/monorepo#2495. Kept dormant on purpose
  (wcpos/monorepo#2847 closed unmerged, Paul 2026-10-23); Pro settles the uploaded rows.
- *Decided 1, the product decision (Paul, 2026-10-23):* a sale the store has not heard completes
  on the till, and the cashier always knows it. The rules, in the order they bind:
  1. *The cashier is never unaware.* The till shows its online/offline state at all times, and
     the receipt stage, the open-orders chip and the printed receipt of a sale taken offline all
     carry an "awaiting store" mark until the record is answered. Completing an offline sale is a
     deliberate act: the tender tile says the sale will be sent later and what that risks (the
     store may refuse it), and the cashier confirms before the tender is journaled. A cashier
     must not be able to complete an order offline without knowing it carries that risk.
  2. *A gateway with no offline option cannot complete offline.* The method's
     `capabilities.offline` decides: `none` keeps the tile disabled with the `offline` reason
     exactly as today; `record` (manual tenders such as cash) journals the record and completes;
     `queue` (a SumUp Solo offline session) journals and completes only when the method opts in
     and the reader has a session. Online-only gateways never gain an offline path by accident.
  3. *The close is not held.* The cashier can start the next sale; the journal entry stays
     leased and the recovery bridge finishes the sale when the answer lands. The drawer opens for
     cash as usual.
  4. *One failed story.* When the store later refuses the record (an order another till paid or
     voided meanwhile, a payment the gateway rejects), the sale moves to a "needs attention" row
     in the orders screen with the refusal reason, the tender is never retried on its own, and
     the cashier resolves it there. Nothing is dropped silently and nothing is re-applied silently.
- *Built 2 and 3 (slices A and B, 2026-10-23):* wcpos/monorepo#2865 built the envelope behind a
  switch (the poster's `defer` journals a record as sent with no revision and a `deferredAt`; the
  offline `recordManualPayment` mirrors the derived status locally; the completion attempt awaits
  adoption; the recovery re-sends the deferred record and raises a refusal as the needs-attention
  story). wcpos/monorepo#2870 deleted the switch and rendered rule 1: the register-bar pill counts
  the sales the store has still to hear, the receipt stage, the open-orders chip and every printed
  path carry "Awaiting store" while the order's journal holds a deferred entry (never read from the
  order status, which flickers on reconnect; never from an online sale's in-flight send), the
  enabled tile says "Sent later · Store may refuse", and the cashier confirms the recorded amount in
  a dialog the tender flow owns. A cart the store has never seen (no server id) stays disabled: its
  record would have no route. The bridge's second completion on the store's answer is a replay:
  audited as such, it refreshes only a receipt tab that still exists and prints nothing.
  wcpos/monorepo#2869 is rule 4: the refusal becomes a durable needs-attention row in Orders
  (store-local document, "Open order" / "Handled", never a Retry).
- *Missing 4:* a live e2e spec that cuts the network mid-tender and reconnects.
- *Missing 5, SumUp:* the leg starts a session when the till is offline and the method opts in
  (`capabilities.offline === 'queue'`), then one day on a physical Solo.

Remaining: *Missing 4* (slice C, the live network-cut spec) and *Missing 5* (slice D, one SumUp
slice plus a hardware day). Open question for the owner: after a cashier switch, a deferred record
waits for its own cashier's session to re-send it; whether the till should send it under whichever
cashier is active is undecided.

**Amendment: the order of the ledger write.** The Consequences' "there is no second phase when the status transition happens inside the ledger save" is unsafe under WooCommerce's save mechanics: `WC_Abstract_Order::save()` catches a failed save and `WC_Order::save()` still runs `status_transition()`, which fires the paid hooks (stock, email, the fiscal sale), so rows riding the paid save can half-land while those effects go out. Amended 2026-10-22: the ledger lands its rows in their own save and verifies them before it runs `payment_complete()`, and stamps the completion after it. Each step is a function of storage and idempotent, and a retry of the same command heals a crash between them. This is not the money epoch returning: no row is filtered or archived; it is the order in which one write reaches storage.

Research: `.claude/research/2026-10-19-pos-money-state-models/RESEARCH.md`, committed beside this
ADR so a fresh checkout can audit the evidence. History:
#2312 (25 rounds), #2328 (the carve, 5 rounds), the carve handoff
`.claude/handoffs/2026-10-19-payments-1-3-carve/HANDOFF.md`.

Decided 2026-10-19 with Paul, after he asked that the money model be grounded in how
established point-of-sale systems and WooCommerce itself already solve it.
