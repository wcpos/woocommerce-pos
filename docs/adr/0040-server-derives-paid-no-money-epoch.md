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

1. *Paid is derived on every money write.* Balance = total − Σ captured rows + Σ refunds. Balance
   ≤ 0 on a non-zero total runs `payment_complete()` into the configured paid status (the
   per-gateway POS order status; "completed" in WooCommerce means fulfilled, "processing" means
   paid); 0 < paid < total is `pos-partial`; nothing paid leaves the order in the open status it
   had. The server writes the POS completion stamp (sale time, zone, register, session, counter,
   for receipts and fiscal provenance) at that transition, as a record of when it closed the
   sale, never as evidence read back from the till. The order's `payment_method` becomes the
   tender that actually paid.
2. *Money never travels in the order document.* `_wcpos_payments`, `_wcpos_completed_at`,
   `date_paid`, `set_paid` and `transaction_id` in a pushed order document are dropped before
   the write. The push lane carries lines, customer, address, notes and status changes among
   open statuses.
3. *Reopen is a status change with all payments still counting.* It is refused only while a
   provider leg is `pending`/`authorized` or a gateway attempt is unresolved; those are locks on
   money in motion, not money rules. A fully paid sale reopened and closed unchanged is still
   paid. Whether the till closes it before the cashier has finished editing is the app's trigger,
   not a server concept.
4. *Line edits need an open status first.* A pushed change to line, fee, shipping, coupon or tax
   lines on an order in a paid or completed status is refused with `reopen_required`. This is
   WooCommerce's `is_editable()` enforced at the POS layer, because wc/v3 does not enforce it.
5. *Every money command is idempotent by the row id the till minted, for as long as the row
   exists.* The row carries the command's body fingerprint and outcome: same id and body replays
   the outcome; same id and a different body is 422; same id while the first is in flight is
   409. Offline cash rows replay days later and create one row. No separate table (see
   `.ai/rules/db-tables.mdc`).
6. *Every money command carries the order revision it was built against* (the same content hash
   as the push lane, ADR 0033) and a stale one is refused so the till re-reads. Nothing in
   WooCommerce serialises concurrent writers to an order; the POS has to.
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
money to reset. The wiki pages named above are rewritten, not amended. Fiscal identity for a
sale amended after close (a new fiscal document in some jurisdictions) was borrowing the epoch;
the fiscal-records work carries its own sequence from the stamp history instead.

**Still to decide, in the design that follows this ADR.** Whether the till's `status` for
in-progress states is honoured as sent or mapped; how a reopened online order that was paid by
an online gateway shows its original tender in the ledger (a synthetic row from
`_transaction_id`, as the webview passthrough already does for order-pay); and the exact
refusal codes, which stay 422 on the push lane and 409 on the route family (#2312's ruling).

Research: `.claude/research/2026-10-19-pos-money-state-models/RESEARCH.md`. History:
#2312 (25 rounds), #2328 (the carve, 5 rounds), the carve handoff
`.claude/handoffs/2026-10-19-payments-1-3-carve/HANDOFF.md`.

Decided 2026-10-19 with Paul, after he asked that the money model be grounded in how
established point-of-sale systems and WooCommerce itself already solve it.
