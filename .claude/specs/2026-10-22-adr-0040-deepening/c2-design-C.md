# c2 design C: the ledger write as an ordered unit of work

Checkout `next` 7f8cb7c2; WooCommerce read from wp-env WC 11.2.1 (`~/.wp-env/wp-env-arch-runner-next-64cf9d7c/woocommerce`). Nothing run.

## 0. Today, for one completing `record` (read, not run)

The route holds the order lock throughout (`Payments_Controller.php:93`).

- **Save A.** `save(rows, derive=false)` (`Ledger.php:742`). The rows land and are read back (`:2165`, `:2176`).
- **Save B.** `derive()` calls `payment_complete()` (`:747`, `:2283`), which saves through WooCommerce (`class-wc-order.php:183`). `WC_Order::save()` runs `parent::save()` then `status_transition()` (`:283-288`): `order_status_processing` reduces stock (`wc-stock-functions.php:126`); on `status_changed`, `Completion_Stamp::on_completed`@15 stamps via `save_meta_data()` and fires `wcpos_sale_completed`, writing the fiscal sale (`Completion_Stamp.php:218,651,306`). Then `woocommerce_payment_complete` fires (`class-wc-order.php:185`).
- **Save C.** A redundant `saving(fn() => $order->save())` (`Ledger.php:748`).

So `record` already writes the rows first. The #2389 trips come from two places:

- **In-hook writers.** The Webview mint (`Webview_Passthrough.php:108,145`) goes `adopt()` → `save()` → `:2165`. `Completion_Stamp::mints_attempt` (`:503`) does the same. `Gateway_Submission.php:475` saves the stamp-clear. Pro's `Order_Status_Void` reaches `void()` → `save()`. All run inside `payment_complete` or `status_changed`.
- **Probable test artefacts** (inferred, not run): the 4/4/4/2-trip sites (`:748`, `Gateway_Submission.php:235,531,595`). Each follows a test that throws from `woocommerce_before_order_object_save` (`Test_Payments_Controller.php:89-95`). `do_action()` pops `$wp_current_filter` only on a normal return (no `finally`), and `WC_Abstract_Order::save()` catches the throw (`abstract-wc-order.php:279-316`). The guard's `live_stack()` discounts only its own trips. A production keeper refusal (`Ledger.php:348`) leaves the same stale stack.

**Hazards no current ordering covers:**

- **H1.** When a path derives inside `save()` (`adopt`, or `apply_result` with derive on, `:2162`), the rows ride `payment_complete()`'s save. WooCommerce swallows that save's failure, yet the status and payment-complete hooks **still fire** (stock, fiscal sale, email); the read-back throws only afterwards (`:2176`).
- **H2.** On HPOS, `save_meta_data()` calls `after_meta_change()`, which runs a **full `$order->save()`** whenever `date_modified` is older than now and no changes are pending (`OrdersTableDataStore.php:3695-3741`). `Completion_Stamp::persist`, `Fiscal_Record_Writers::park/unpark` (`:560,577`) and `stamp_closure` (`Ledger.php:250`) can therefore re-run the save stack inside `status_changed`. Clock-dependent, and invisible to the guard (the caller is WooCommerce).
- **H3.** A crash between Save B and the stamp never heals. `derive()` returns early on a paid status (`:2267`). `close()` treats a paid sale with no history as standing and answers without a completion (`Ledger.php:1738-1743`, `Completion_Stamp.php:333-340`). No entry, no fiscal record.

## 1. Interface

```php
// On Ledger; public save()/derive() leave the surface. Reads (read, find, balance, summary,
// available_for_refund, to_wire, constants) are unchanged for Pro.
public function write( WC_Order|int $order, ?callable $change = null, string $mode = 'auto' ): Ledger_Written;
public static function defer( int $order_id, callable $work ): void;   // hook callbacks only
public static function is_writing( int $order_id ): bool;              // replaces is_deriving()

final class Ledger_Draft {             // $change( Ledger_Draft $d ): mixed
  public function order(): WC_Order;   // fresh, read under the lock
  public function rows(): array;
  public function put( array $row ): void;        // add/replace by id
  public function forget( string $id ): void;     // refused intent only
  public function attempt( string $id, string $fate ): void;
  public function command( string $id, array $entry ): void;
  public function stage( callable $on_order ): void; // items, notes, method, stamp meta
}
// Ledger_Written { order (fresh), rows, answer, completion|null }
```

**Mode:**

- `auto` derives.
- `none` skips the paid step: an offline arrival, refund save 1, or a reservation.
- `close` may complete a zero total or an on-hold order (today `derive($closing)`).

**Fixed sequence:**

1. Lock (re-entrant, `Order_Lock.php:58`).
2. Fresh read.
3. **Heal** (§3).
4. `$change($draft)`. Validation on the locked read; never saves, never calls a provider.
5. **S1.** One `$order->save()` carrying the rows, the three indexes, the attempts, the command entries, the staged items and props, and any projection that does not complete (`pos-partial`, `pos-open`, `pending`, method, title). Read it back.
6. **S2.** Only when the projection completes: `payment_complete()` and nothing else. Read back the paid status and `date_paid`.
7. **S3.** `Completion_Stamp::append()`, plus the close command's completion ref, in one save. Read it back. Then `wcpos_sale_completed`.
8. Flush deferred work for this order.
9. `release_hold_if_idle`.
10. Unlock.

**Invariants:**

- **I1 rows before paid.** No paid hook fires for a ledger-driven completion until S1 is verified.
- **I2.** S2 carries no ledger meta, so the keeper never locks or refuses it. This closes H1.
- **I3.** No step runs inside a WooCommerce save or transition hook. Listeners use `defer()`.
- **I4.** S2 and S3 are functions of storage, so they are idempotent.

**Errors.** `Ledger_Persistence_Exception` carries `->stage` (`lock|rows|paid|completion`; `stored()`'s `RuntimeException`, `:185,193`, becomes `rows`), answered 409. A prefix of S1..S3 may have committed; the caller's one rule: **retry the same command**. Under rule 5, the replay is `write($id)`: it heals, then answers.

## 2. Usage

- **record.** `write($id, fn($d) => …validate; $d->put($row + entry); return 'recorded')`. `apply_parked` becomes a draft operation. The replay branch (`:626-640`) becomes `write($id)`. Save C goes.
- **refund (rule 7).** `write(put(allocation pending + entry), 'none')`, then `process_refund()` (route lock held), then `write(put(answer))`. If the second write throws, the stored reservation *is* the strand state. `persist`, `after_settle`, `strand`, `reserved`, `same_allocation` and `mark` (`:1238-1415`) collapse into adding a note. `withdraw` becomes a third write.
- **close.** `write($id, checks, 'close')`. The S3 save carries the entry ref, which replaces `remember_close`'s extra save (`:1801-1817`).
- **submit.** `write(attempt sent + entry + method, 'none')`, then dispatch. A `payment_complete()` raised by the gateway meets only deferring listeners. Then `write(put(minted row))`: S2 is a no-op and S3 stamps. This replaces `Gateway_Submission.php:359-363`. `restore_method`, `answered` and `cancel` each become one write.
- **Keeper.** Push lane only. It skips when `is_writing`, which replaces `server_saves` (`:2158`, `:371`). After the rule-2 strip, the keeper and most of the 17 `saving()` sites go.
- **stamp_closure.** Stays meta-only (top level, inside `Session_Figures`' transaction). It is scoped with `woocommerce_orders_table_datastore_should_save_after_meta_change → false`, so it writes exactly one row (H2).
- **Pro `Order_Status_Void.php:43-56`.** Becomes `Ledger::defer($id, fn() => Order_Lock::…with_lock(…void…))`. `with_lock` still works (re-entrant lock). This needs a **Pro companion PR**, merged before `except()` is removed.

**Flush points:** the end of the outermost `write()` on the order; `woocommerce_payment_successful_result` (order-pay; already hooked, `Webview_Passthrough.php:39`); otherwise `shutdown`; none is a re-entry hook.

## 3. Behind the seam

The draft is built from the locked read, and every writer to the rows holds the lock: the routes, Pro `with_lock`, `Session_Figures`, and the keeper whenever ledger meta is pending. So the three-way `$read_rows` merge (`:2079-2104`) goes. "Absence is not deletion" stays.

**Heal:**

- **S3-heal.** Paid, register origin, and the latest entry does not stand: append, fire, and run `wc_maybe_reduce_stock_levels()`, which is idempotent through `_order_stock_reduced`. Only a crash leaves this: a reopen moves the sale to an open status (`Completion_Stamp.php:179-200`).
- **S2-heal.** Only on the replay of a command whose S1 entry says `phase: rows`, on `close`, and on the sweeper. Never on every write: a reopened sale that its rows still cover is also covered-and-open (rule 3).

| Crash after | Storage | Recovery |
|---|---|---|
| nothing committed | unchanged | the retry runs fresh |
| S1 | rows cover the total, status open, `phase: rows` | replay S2-heal; `close`; sweeper |
| part of S2 (not atomic: HPOS `wc_orders` then meta, `OrdersTableDataStore.php:3163-3176`; CPT meta then post) | maybe paid, hooks never ran | S3-heal stamps and reduces stock; a lost email is WooCommerce's own window |
| S2 (H3) | paid, no entry | the next `write` on the order; replay; sweeper |
| S3, inside the fiscal listener | entry n; the insert parked or missing | `record_sale` is idempotent by completion id (`Fiscal_Record_Writers.php:181-193`); `recover()` drains parked documents |

**Rejected orderings:**

- **A flush on `woocommerce_after_order_object_save`.** That hook is itself a re-entry hook (`Save_Reentry_Guard.php:154`). It also fires *before* `status_transition()` (`class-wc-order.php:285-286`): ahead of the paid hooks.
- **Rows and paid in one save.** That is H1.

## 4. Dependencies and adapters

- **WooCommerce order CRUD, HPOS or postmeta: local-substitutable.** wp-env runs the real WooCommerce on MariaDB in both modes. The seam is internal and there is **no port**. The only mode-specific code is the table switch in `stored()` (`:177-180`).
- **In-memory adapter: rejected.** Everything #2389 turns on is inside WooCommerce: the hook order in `payment_complete()` and `status_transition()`, H2, and the HPOS/CPT write order. A fake cannot express it; one real adapter is a hypothetical seam.
- **In-process: `Projection::of(rows, total, status, stamped, mode)`**, the pure half of `derive()` (`:2217-2290`). It returns `{status, method, title, txn, completes}`.
- **True external: gateways.** They already sit behind `Capture_Mode_Handler_Interface`, which has four real adapters. `write()` never calls them.

## 5. Slices (production lines added)

1. **`write()`, Draft, Projection, `defer()` with its flush, heal, and the scoped HPOS meta helper.** No callers. About **430**.
2. **In-hook writers move to `defer()`.** Covers the Webview mint, `mints_attempt`, and the Completion_Stamp listeners (they skip while `is_writing`). The `Gateway_Submission` stamp-clear moves to `before_order_object_save`. Fixes H2 in `persist`, `park`, `unpark` and `stamp_closure`. About **220**. The Pro companion (about 15 lines) goes with it.
3. **`record`, `close`, replay and the sweeper move to `write()`.** About **180** added, about 220 deleted.
4. **`refund`, `intent`, `capture`, `apply_result`, `void`, `adopt`, `settle_allocation` and `append_events` move to `write()`.** The seven wrappers are deleted. About **300** added, about 400 deleted.
5. **`Gateway_Submission` submit, cancel, `restore_method` and `answered` move to `write()`.** About **200**.
6. **Removals.** Public `save()` and `derive()`, `$deriving`, `server_saves` and `replace_and_save` go, along with `tests/bootstrap.php:271` `except()`. Under **40**. Needs the Pro companion merged first.

## 6. Tests

**Deleted:**

- The 17 tests that seed through `save()` are re-seeded through `write()`.
- `Test_Ledger_Sole_Writer`: the method it guards is gone, and no absence test replaces it.
- The tests on refund-wrapper internals.

**Moved to the interface.** The 20 save-hook and query interceptors become one table: failure at stage k × {HPOS, CPT}, then the same command is retried. Assert one completion, one fiscal sale, stock reduced once, rows as for a single run.

**New:**

- **Ordering trace.** Listeners on `pre_payment_complete`, `status_changed`, `payment_complete` and `wcpos_sale_completed` read storage: rows stored before the first, status before the last.
- **H2.** With `date_modified` set in the past, an in-hook stamp causes no nested `before_order_object_save`.
- **Guard helper.** Fix it so `live_stack()` drops stacks left by exceptions that WooCommerce caught.

## 7. Trade-offs

**Leverage.** One call owns write, replay, read-back and recovery, retiring 7 `derive`/`saving` pairings, 5 `release_hold_if_idle` calls, 5 re-fetches and the hand-built read-backs; H1–H3 are fixed once.

**Thin spots:**

- Three writes per completing tender, as today.
- S2 is still WooCommerce's non-atomic save, whose hooks fire even after its save fails. I2 only removes our own way of making it fail.
- **Cannot do:** WooCommerce-driven completions (order-pay, provider callbacks, wp-admin) are paid *before* the ledger hears of them. I1 cannot hold there; the ledger follows.
- `defer()` moves the webview row to the end of the request, so a SIGKILL loses it. D5 adoption at reopen and the zero-rows-paid balance fallback cover that.
- `is_writing()` is still process-global, but one flag where there were four.
- S2-heal depends on the `phase` field. Writers without a command rely on the sweeper.
- `wcpos_sale_completed` becomes at-least-once. The fiscal listener is already idempotent. A documented extension that hooks it has to be checked before slice 3.
