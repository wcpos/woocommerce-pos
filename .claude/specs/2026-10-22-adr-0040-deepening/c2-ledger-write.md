# Spec: one locked write for payment rows (c2) — the ledger write as an ordered unit of work

**Date:** 2026-10-22 · **Lane:** `next` · **Binding:** ADR 0040 rules 1, 5, 6, 7, 8; handoff decisions D1–D6 (settled); closes #2389 · **Stakes:** checkout-money, High — a half-landed write marks an unpaid sale paid, fires stock, email and fiscal side effects for a state that never stored, or loses a tender. · **Decision owner for the ADR amendment (§8):** Paul. · **Vocabulary:** `GLOSSARY.md` (Sale, Tender, Payment row, Balance, Paid, Completion, Money command, Attempt); module, interface, seam, adapter, leverage, locality from the codebase-design skill.

## Outcome

Every write of a payment row goes through one locked unit of work on the Ledger. It reads the order as storage holds it, runs the command's change on that read, saves the rows and verifies them, and only then runs the paid transition and stamps the completion, each as its own idempotent step. No step runs inside a WooCommerce save, status-transition or payment hook; listeners that need to write defer to the end of the outermost write. `stamp_closure`, the before-save keeper, the sweeper and the gateway submission become callers. The command signatures Pro calls do not change.

## Decision record

Three interfaces were designed in parallel against one brief (`c2-design-A/B/C.md` beside this spec).

- **A** (three entry points; verbs move to a new class; Pro migrates every call) was rejected for the Pro migration: moving `record/void/refund/…` to another class changes ten Pro call sites for no behavioural gain. Its invariant set (a captured row is immutable; `closure_id` is write-once) is adopted.
- **B** (one `run()` with a draft; the completing carrier is `payment_complete()` itself, one save) was rejected on its carrier: WooCommerce swallows a failed save in `WC_Abstract_Order::save()` and `status_transition()` still fires the paid hooks (stock, fiscal sale, email) for rows that never stored (design C's H1). Its draft ergonomics and unchanged signatures are adopted.
- **C** (ordered S1/S2/S3 with heal, `defer()` for in-hook writers, WooCommerce source read for hook order) is the base. It also found three live hazards (H1 rows riding the paid save; H2 HPOS `save_meta_data()` running a full save inside status hooks; H3 a crash between the paid save and the stamp never heals) and the likely cause of the guard's 4/4/4/2 trips (a throwing before-save listener leaves `$wp_current_filter` dirty).

## Interface

```php
// includes/Payments/Contract/Ledger.php — reads unchanged: read(), find(), balance(), signed_balance(),
// summary(), paid(), available_for_refund(), to_wire(), the constants.
public function write( $order, ?callable $change = null, string $mode = self::MODE_AUTO ): Ledger_Written;
public static function defer( int $order_id, callable $work, string $label ): void;   // hook listeners only
public static function is_writing( int $order_id ): bool;                             // replaces is_deriving()

final class Ledger_Draft {            // handed to $change( Ledger_Draft $d ): mixed
	public function order(): WC_Order;                 // fresh, read under the lock; stage items, notes, props on it
	public function rows(): array;
	public function row( string $id ): ?array;
	public function put( array $row ): void;           // add or replace by id
	public function drop( string $id ): void;          // only a refused intent may vanish (A's invariant)
	public function attempt( string $id, string $fate ): void;
	public function command( string $id, array $entry ): void;
}
final class Ledger_Written { public WC_Order $order; public array $rows; public $answer; public ?array $completion; }
```

**Modes.** `auto` projects and may complete the sale. `hold` stages only: no projection, no completion (an offline arrival, refund save 1, the `sent` attempt before dispatch). `close` may complete a zero total or an on-hold order (today's `derive( $closing )`, #2366).

**Invariants.**
- I1 *Rows before paid.* No paid hook fires for a ledger-driven completion until the rows are stored and read back.
- I2 *The paid save carries no ledger meta.* So the keeper never locks or refuses it, and a failed paid save cannot half-land rows.
- I3 *No step inside a WooCommerce save, transition or payment hook.* `write()` called there throws; listeners use `defer()`.
- I4 *S2 and S3 are functions of storage.* Replaying them is harmless; heal relies on this.
- I5 *A stored `captured` row keeps its id, amount, currency and method and moves only to `voided`, and only for manual rows; a row carrying refunds or a `closure_id` never vanishes; `closure_id` is write-once.* Breaking I5 throws `LogicException` (a programming error, never a merchant outcome).
- I6 *`$change` is pure*: no provider call, no save, no hook; it may run twice (one retry on a read-back miss).

**Errors.** `Ledger_Persistence_Exception` carrying `->stage` ∈ {`lock`, `rows`, `paid`, `completion`}; `stored()`'s plain `RuntimeException` (PR 1 of the programme already retypes it) is `rows`. The route answers it 409 as today. A prefix of S1..S3 may have committed; the caller's one rule is *retry the same command*: under rule 5 the replay is `write( $id )` with no change, which heals, then answers from storage.

## The fixed sequence

1. **Lock** (`Order_Lock`, re-entrant; nested acquires counted) — throw `lock` on failure.
2. **Fresh read**: `wc_get_order()` + `read_meta_data( true )`; rows from the locked raw read (today's `stored( …, true )`).
3. **Heal** (§heal).
4. **Change**: `$change( $draft )` on the locked read.
5. **S1 — rows**: one `$order->save()` carrying the rows, the three indexes, attempt fates, command entries, staged items and props, and the non-completing projection (`pos-partial` / `pos-open` / `pending`, method, title, transaction id). Read back rows and every staged server-owned key; on a miss retry once from step 2, then throw `rows`.
6. **S2 — paid**: only when the projection completes and derive's present gate allows (`IN_PROGRESS_STATUSES`, stamped, or `close` on an editable order): `payment_complete()` and nothing else. Read back the paid status and `date_paid`; on a miss throw `paid`.
7. **S3 — completion**: `Completion_Stamp::append( $order, $origin )` plus the close command's completion ref in one save, read back, then `wcpos_sale_completed`. (This is where c4 later lands its explicit call; in c2 the stamp module keeps its shape but is *called*, not hook-triggered, for ledger-driven completions.)
8. **Flush** deferred work for this order (outermost write only).
9. `release_hold_if_idle`.
10. **Unlock.**

**Projection** becomes a pure function `Projection::of( rows, total, status, stamped, mode )` → `{ status, method, title, txn, completes }` (today's `derive()` minus its side effects); tested directly, in-process.

**Hook context.** `write()` checks `$wp_current_filter` for the order hooks the `Save_Reentry_Guard` polices and throws I3. Listeners that write (the webview mint, `Completion_Stamp::mints_attempt`, the `Gateway_Submission` stamp-clear, Pro's `Order_Status_Void`) call `defer( $order_id, $work, $label )`. Flush points: the end of the outermost `write()` on that order; `woocommerce_payment_successful_result` (order-pay, already hooked by the passthrough); otherwise `shutdown`. None is a re-entry hook. A failed deferred job is logged and noted on the order under its label.

**H2.** Every meta-only write the ledger side still performs (`stamp_closure`, `Completion_Stamp::persist`, `Fiscal_Record_Writers::park/unpark`) runs under `woocommerce_orders_table_datastore_should_save_after_meta_change → false` so HPOS writes exactly the meta row and never a nested full save.

**Keeper.** (Amended 2026-10-22 after the c8 spec: c8 delivers the one push-lane strip as `Pushable_Document`, with an invariant test that runs with the keeper unhooked, so slice 6 deletes `keep_closure_stamps` outright rather than reducing it.) `keep_closure_stamps` is reduced to a push-lane strip (rule 2 already lists the keys in `Pos_Order_Audit::SERVER_MONEY_META_KEYS`) that skips while `is_writing()`. It writes nothing, takes no lock, and refuses nothing. `saving()`, `save_was_refused()`, `$refused`, `$closure_locks`, `$server_saves` and `release_closure_lock()` are deleted in the last slice; their 17 call sites become plain saves.

## Heal

| Crash after | Storage shows | Recovery |
|---|---|---|
| nothing | unchanged | the retry runs fresh |
| S1 | rows cover the total, status open, command entry `phase: rows` | replay of that command, `close`, or the sweeper runs S2 |
| part of S2 (HPOS writes `wc_orders` then meta; CPT meta then post) | maybe paid, hooks never ran | S3-heal stamps and runs `wc_maybe_reduce_stock_levels()` (idempotent via `_order_stock_reduced`) |
| S2 (H3) | paid, no standing completion, register origin | the next `write()` on the order, the replay, or the sweeper runs S3 |
| S3 inside the fiscal listener | entry n; sale record parked or missing | `record_sale` is idempotent by completion id; `recover()` drains parked documents |

S2-heal runs only on a replay whose entry says `phase: rows`, on `close`, and on the sweeper — never on every write, because a reopened sale that its rows still cover is also covered-and-open (rule 3). S3-heal runs on every write for a paid order with register origin and no standing completion.

## Usage (signatures unchanged)

- **record**: `write( $order, fn( $d ) => … validate on $d->rows(); $d->put( $row ); $d->command( … ); Settlement::drain( $d, $row['id'] ); return $row )`. The replay branch is `write( $order )`. Today's save-A / derive / save-C triple (`:742–748`) collapses.
- **refund** (rules 7, 8): `write( …, 'hold' )` reserving the `pending` allocation and the command entry → `process_refund()` → `write( … )` with the answer. If the second write throws, the stored reservation *is* the strand state; `persist`, `after_settle`, `stored_row`, `reserved`, `same_allocation`, `mark` collapse into one note. Pro's `Refund_Processor` sits inside WooCommerce's deleting catch, so the route still converts the exception to 409 there.
- **close**: `write( $order, checks, 'close' )`; S3 carries the completion ref, replacing `remember_close`'s extra save.
- **submit** (rule 7): `write( stage sent attempt + entry + method, 'hold' )` → dispatch (a `payment_complete()` raised by the gateway meets only deferring listeners) → `write( put( minted row ) )` where S2 is a no-op and S3 stamps. `restore_method`, `answered`, `cancel` each become one write.
- **keeper**: see above. **stamp_closure**: a write setting `closure_id` on named rows; write-once is I5; meta-only carrier under the H2 filter.
- **sweeper**: `write( $order )` replaces `derive(read()); saving(save())`.
- **Pro `Order_Status_Void.php:43–56`** (voids inside `woocommerce_order_status_changed`): becomes `Ledger::defer( $id, fn() => Order_Lock::…->with_lock( …recheck…, void ) , 'void live legs on cancel' )`. Pro companion PR, merged before the Free slice that removes the guard exemption.

## Disposition of the #2389 sites

| Site | Disposition |
|---|---|
| `Ledger.php:2165` save inside `woocommerce_payment_complete` | the in-hook writers (webview mint, `mints_attempt`) defer; the ledger never saves inside the hook |
| `Ledger.php:748` | `record` is one `write()` |
| `Gateway_Submission.php:235, 531, 595` | become writes; measured again in slice 5 — probably the guard's stale-stack artefact (fixed in the guard helper, slice 1) |
| `Gateway_Submission.php:475` | the stamp-clear moves to a before-save listener on the object being saved, or is projected in S1 |
| Pro `Order_Status_Void` (seventh site) | `defer()`, Pro companion |

## ADR 0040 amendment (needs Paul's yes before slice 1 opens)

ADR 0040 "Consequences" says: *"there is no second phase when the status transition happens inside the ledger save"*. WooCommerce's save mechanics make that sentence unsafe: `WC_Abstract_Order::save()` catches a failed save and `status_transition()` still fires the paid hooks, so rows riding the paid save can half-land while stock, email and the fiscal sale go out. Proposed amendment paragraph: *"Amended 2026-10-22: the ledger lands its rows in their own save and verifies them before it runs `payment_complete()`, and stamps the completion after it. Each step is a function of storage and idempotent, and a retry of the same command heals a crash between them. This is not the money epoch returning: no row is filtered or archived; it is the order in which one write reaches storage."* The ADR's rule 7 already accepts a two-step shape where an external effect cannot be rolled back; the paid hooks are such an effect.

## Slices (each one PR against `next`, green alone, never stacked; added production lines, tests excluded)

| PR | Content | Est. | Pro |
|---|---|---|---|
| c2-1 | `write()`, `Ledger_Draft`, `Ledger_Written`, `Projection`, `defer()`+flush, heal, the H2 meta helper, the guard-helper fix for stale `$wp_current_filter`. **No callers.** | ~430 | — |
| c2-2 | In-hook writers move to `defer()`: webview mint, `mints_attempt`, Completion_Stamp listeners skip while `is_writing`; stamp-clear to before-save; H2 filter on `persist`/`park`/`unpark`/`stamp_closure`. | ~220 | companion: `Order_Status_Void` → `defer()` (~15 lines), merged first |
| c2-3 | `record`, `close`, the replay branch and the sweeper onto `write()`. Before opening: grep the extension catalog and docs for `wcpos_sale_completed` listeners (it becomes at-least-once). | ~180 (−220) | — |
| c2-4 | `refund`, `intent`, `capture`, `apply_result`, `void`, `adopt`, `settle_allocation`, `append_events` onto `write()`; the seven refund wrappers deleted. | ~300 (−400) | signatures unchanged |
| c2-5 | `Gateway_Submission` submit, cancel, `restore_method`, `answered` onto `write()`; re-measure the three trip sites. | ~200 | — |
| c2-6 | Delete public `save()`/`derive()`, `$deriving`, `server_saves`, `replace_and_save`, `saving()`, `save_was_refused()`, the closure locks, the keeper's write path; remove `Save_Reentry_Guard::except()` from `tests/bootstrap.php`. | <40 | companion merged before this |

If any slice's estimate is passed by 3x, or 600 is near, the implementer stops and reports.

## Tests

- **New `Test_Ledger_Write`** (HPOS and postmeta): ordering trace (listeners on `pre_payment_complete`, `status_changed`, `payment_complete`, `wcpos_sale_completed` read storage and see rows before the first and paid status before the last); failure injected at each stage × storage mode, then the same command retried: one completion, one fiscal sale, stock reduced once, rows as for a single run; I3 refusal inside each guarded hook; `defer()` flushes at the end of the outermost write and at `shutdown`; nested `with_lock` keeps the outer lock; untouched `updated_at_gmt` kept; concurrent rows survive; I5 violations throw; H2: an in-hook meta write with `date_modified` in the past causes no nested `before_order_object_save`.
- **`Test_Projection`**: the pure function's table.
- **Kept**: `Test_Ledger`'s 126 money-command tests as the behavioural spec; the rule-8 refund pins; the 17 seeders move from `save()` to `write()`.
- **Deleted as waste** (slice 6): the keeper-merge tests, the closure-lock release and "dirty save refused" tests, the stale-rebase tests, the refund storage-miss internals (re-expressed once through the stage × storage table), `Test_Ledger_Sole_Writer` (no absence test replaces it; I3 and the strip enforce at runtime).
- **Guard**: the `Save_Reentry_Guard` exemption comes out in slice 6 and the full suite runs with the guard watching `includes/` whole.

## Open before slice 1

- Paul's yes on the amendment (§8).
- PR 1 of the programme (ledger defects) lands first: D4 retypes `stored()`'s exception, D5 deletes the unreachable carry block; c2-1 builds on both.
- c1 and c7 land before c2-3 so `close`'s in-motion check is already `sale_state()`.

## Pro impact

Read signatures and constants unchanged. Command signatures unchanged. One companion PR (`Order_Status_Void` → `defer()`), small, merged before c2-2 and again confirmed before c2-6. Pro `next` runs no PHP tests on push: run Pro's `Test_Order_Status_Void` by hand against Free `next` after c2-2 and c2-6.
