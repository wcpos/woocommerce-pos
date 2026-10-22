# c2 design A: the Ledger as three entry points

The **Ledger** owns the payment rows and every write to them. The money commands become its callers.

## 1. Interface

```php
final class Ledger {                       // constants unchanged: META_KEY, LIVE_STATUSES, COUNTING_STATUSES, LIVE_LEG_META_KEY, EVENTS_MAX, …
  static function read( WC_Order $o ): Ledger_View;
  static function commit( WC_Order $o, callable $change ): Ledger_View|WP_Error;   // $change: fn( Ledger_Draft $d ): ?WP_Error
  static function after_save( int $order_id, callable $job, string $label ): void;
}
final class Ledger_Draft { public array $rows; public WC_Order $order; public bool $closing = false; }
final class Ledger_View  { rows(); row( $id ); summary(); completion(); order(); static refundable( $row ): int; static wire( $row ): array; }
```

`register_hooks()` stays as bootstrap (`Service_Groups.php:186`): the freeze keeper and `drain()` on `shutdown`.

**commit: invariants.**
- `$change` runs under `Order_Lock`. The lock is re-entrant: `acquire` counts nested holds (`Order_Lock.php:58–59`), so Pro's outer `with_lock` needs no change.
- `$d->rows` comes from a locking storage read (`stored(…, true)`, `Ledger.php:175–183`). `$d->order` is a fresh `wc_get_order` with `read_meta_data(true)`.
- The draft is the whole truth: a row absent from `$d->rows` is deleted. No caller ever holds a stale read, so `$read_rows`, `$deleted_ids`, the item carry and the pending-meta reflection (`Ledger.php:2046–2081, 2083–2124`) have nothing left to do.
- Commit enforces the glossary's Payment row rule in one place. A stored `captured` row keeps its id, amount, currency and method. It may move only to `voided`, and only for `manual` rows. A row carrying refunds or a `closure_id` cannot vanish. `closure_id` is write-once. Breaking any of these throws `LogicException`.
- `$change` must have no external effects: no provider call, no order note, no hook. Commit may run it twice.
- On success commit returns the view as read back from storage. It returns `WP_Error` only when `$change` returned one, and then nothing is written. The caller's `$o` is refreshed in place, as `Ledger.php:2172–2173` does today.

**commit: error modes.** It throws `Ledger_Persistence_Exception` in three cases:
- the lock cannot be taken;
- the read-back misses twice;
- it is called while a WooCommerce order save, status-transition or payment hook is running. This is fail-closed: inside a hook, use `after_save`.

**after_save.** The job runs once, outside every save hook, with no lock held. It runs at the end of the outermost `commit` in the request, otherwise at `shutdown` (`drain()`, the pattern `Cloud_Print_Trigger_Service` uses on `main`). A job may call `commit`. A failure is logged and noted on the order under `$label`.

## 2. Usage

The verbs move to `Money_Commands` with the signatures they have today.

- **record.** Taken under the route lock, then Settlement's park lock. One commit: replay check, then the balance check from `$d->rows`, then the row, refused or captured, with parked patches folded in. Settlement then clears the parked entries and the command fires `woocommerce_pos_payment_recorded`. This replaces `save(false)` + `apply_parked` + `derive` + `save` (`Ledger.php:733–748`), since nothing can park while record holds the park lock.
- **refund (rule 7/8).**
  - Commit A: reserve a `pending` allocation and the command entry. On `Ledger_Persistence_Exception`, *return* 409, because Pro sits inside WooCommerce's deleting catch.
  - Then the handler calls `process_refund`.
  - Commit B: the answer replaces the reservation. If B throws, strand: one commit re-asserts the reservation, then a note.
  - Deleted: `persist`, `after_settle`, `stored_row`, `reserved` and `same_allocation` (`Ledger.php:1238–1371`). Commit already compares the whole stored ledger to what it wrote, so the per-row "did it land" checks are covered.
- **close.** Commit with `$d->closing = true`, after the in-flight and coverage checks against `$d->rows`. It reads `view->completion()`. A second commit stages the command's `ref`. Commit's read-back covers `_wcpos_commands`, which replaces `remember_close`'s own verify (`Ledger.php:1801–1814`).
- **gateway submit.**
  - Commit 1 stages the `sent` attempt, the command entry and the payment method on `$d->order`, leaving the rows unchanged. Its read-back covers every key in `Pos_Order_Audit::SERVER_MONEY_META_KEYS` (`Pos_Order_Audit.php:44`) that the draft staged. That replaces the hand-rolled landing checks (`Gateway_Submission.php:216–236`) and `command_kept`/`verify_command`.
  - Then `process_payment`.
  - Commit 2: mint the row with the attempt id as its id, or stage the `settled`/`sent` stamp. `restore_method` and `answered` become commits. Marking attempts `paid`/`superseded` happens inside commit (`Ledger.php:2143–2157`).
- **keeper.** Becomes a private before-save closure. Outside a commit it discards pending changes to the four ledger keys. No lock, no storage read, no refusal. Rule 2 already strips those keys from every push (`Pos_Order_Audit.php:44–48`), so nothing legitimate writes them outside the Ledger. Replaces `Ledger.php:269–434, 472–513`.
- **stamp_closure.** `Session_Figures.php:335` becomes `Ledger::commit($o, fn($d) => <set closure_id on the named rows; throw if one is missing>)`. Write-once is commit's invariant, and the read is already `FOR UPDATE` inside the caller's transaction.
- **Pro `Order_Status_Void.php:43–56`.** The `with_lock` body moves verbatim into `Ledger::after_save( $order_id, fn() => Order_Lock::instance()->with_lock(…recheck at :52…, Money_Commands::instance()->void(…)), 'void live legs on cancel' )`. Today it voids and saves inside `woocommerce_order_status_changed`.

**Pro migration, as a companion PR per slice:**

| Today | Instead |
|---|---|
| `read` | `Ledger::read($o)->rows()` |
| `find` | `->row($id)` |
| `balance` | `->summary()['balance']` |
| `summary` | `->summary()` |
| `available_for_refund` | `Ledger_View::refundable` |
| `to_wire` | `Ledger_View::wire` |
| `void`, `refund`, `apply_result`, `adopt`, `settle_allocation`, `append_events` | `Money_Commands::instance()->…` |

`with_lock` and `Payments_Sweeper::HOOK` are unchanged.

**What happens to the 34 public methods:**

| Fate | Methods |
|---|---|
| Stay | `read` (now returns a view), `register_hooks` |
| Move to `Ledger_View` | `find`, `paid`, `balance`, `signed_balance`, `summary`, `to_wire`, `available_for_refund` |
| Become private | `save` (inside commit), `derive`, `keep_closure_stamps` |
| Deleted | `instance`, `is_deriving`, `saving`, `save_was_refused`, `release_closure_lock`, `stamp_closure` |
| Move to `Money_Commands` | `record`, `intent`, `capture`, `status`, `void`, `refund`, `close`, `apply_result`, `adopt`, `settle_allocation`, `append_events`, `record_failure_events`, `refusal_error`, `accepts_tender`, `apply_transition`, `is_unanswered` |

**Free callers:** `Payments_Controller` → `Money_Commands` + `Ledger::read`; `Order_Writer` loses the refusal (`:166/:180`), its adopt is a commit at prepare time (`:124`, `:751`); `Webview_Passthrough` → `after_save`; `Payments_Sweeper:159–161` → `Ledger::commit($o, fn() => null)` (re-derive); `Settlement` → `Money_Commands::apply_transition`; `Receipt_Data_Builder` → `summary()['paid']`; `Mutation_Store:455/499` → plain save; `Collection_Rules`, `Pos_Order_Audit` → constants.

## 3. Behind the seam: commit's sequence

1. If any order save, transition or payment hook is on `$wp_current_filter`, throw.
2. Acquire the lock. Do the fresh read and build the draft. Run `$change`.
3. Check the invariants. Normalise the rows, and stamp `updated_at_gmt` only on rows whose content changed (`same_content`). Rebuild the three indexes. Mark attempt fates.
4. Run the projection (today's `derive()` without its `payment_complete()`): payment method, title and transaction id from the paying row, plus `Completion_Stamp::memoise` and `clear_stamp`. If the sale is not covered, also move the status to `pos-partial`, `pending` or `pos-open`.
5. **Save 1**, ours: rows, indexes, attempts, method fields and the projected open status. The keeper is a no-op here (`in_commit[$id]`). Any `status_changed` listener now runs with the rows already stored.
6. Read-back: compare stored rows with what was written, and every staged server-owned key. On a miss, retry from step 2 once, then throw.
7. **Paid transition**, only after step 6 passes, only when covered and only under derive's present gate (`Ledger.php:2262–2290`): `$fresh->payment_complete()`. This is **save 2, WooCommerce's own**, carrying `date_paid` and the paid status. `woocommerce_order_status_changed` and `woocommerce_payment_complete` fire with the rows durable. `Completion_Stamp::on_completed` (status_changed@15, `Completion_Stamp.php:69`) stamps the completion through `save_meta_data()`. That is not a `WC_Data` save, so the guard does not see it. Then re-read the status. If it is not paid, log it; the next commit re-derives.
8. Tail: if the order is paid with no completion standing this cycle (a row landed late on an already-paid sale, such as a deferred webview mint), call `Completion_Stamp::stamp($fresh)`. It is idempotent (`Completion_Stamp.php:285–288`). Then release the lock and drain the `after_save` jobs. On failure, throw `Ledger_Persistence_Exception`; that is the only type that leaves the Ledger.

**The #2389 sites:**

| Site | Disposition |
|---|---|
| `Ledger.php:2165` | Payment-complete listeners no longer commit: webview and `mints_attempt` use `after_save` |
| `Ledger.php:748` | `record` saves through commit |
| `Gateway_Submission.php:235`, `:531`, `:595` | Become commits; a commit inside a hook is refused, so nothing saves there |
| `Gateway_Submission.php:475` | Moves to a before-save listener that clears the stamp on the object being saved |

The `except()` line (`tests/bootstrap.php:271`) goes. `is_deriving` dies: webview (`Webview_Passthrough.php:165–171`) and `has_origin` (`Completion_Stamp.php:477–483`) now find the paying row in storage.

## 4. Dependencies and adapters

WooCommerce order storage (HPOS/posts): local-substitutable (real WooCommerce in wp-env, both storages tested), internal seam. `Order_Lock`: local. `Completion_Stamp`, the attempt map: in-process. Providers: true external, outside the Ledger (commands call them between commits; existing `Capture_Mode_Registry` mocks). No port: nothing varies across one.

## 5. Slice plan (added production lines, tests excluded)

1. `Ledger_View`, `Ledger_Draft`, `commit`, `after_save`, `drain`, beside the old `save()`, with no callers. About 420.
2. Freeze keeper. Delete `saving`, `save_was_refused`, the closure locks and `server_saves`. 17 call sites become plain saves; `Order_Writer` loses the refusal; the stamp clear moves to before-save. About 140.
3. Webview mint, `adopt` and `mints_attempt` move onto `after_save`/commit. Delete `is_deriving`. Pro companion: Legacy_Adoption, Order_Status_Void. About 190.
4. `Money_Commands`: `record`, `close`, `void`, `status`, `apply_result`, `capture`, `intent` rewritten over commit. About 520. Pro companion: Payments_Answer_Controller.
5. `refund`, `settle_allocation`, `append_events`, Sweeper and `stamp_closure`. About 330. Pro companion: Refund_Processor, Order_Pay_Gateway, Refund_Sweep.
6. Gateway_Submission over commit. Delete the old `save()`/`derive()` paths and `Ledger::instance()`. Remove `except()`. About 230.

Each Pro companion lands before the Free slice that removes the old call; until then a one-line forwarder names that Pro PR.

## 6. Tests

**Deleted (waste):** keeper-merge tests (`Test_Ledger.php:175, 317, 347, 377, 419, 2827`) and the rest of the 20 intercepting before-save; lock release (`:2572`); stale rebase (`:2635, 2669, 2705`); refund storage-miss tests (`:3321, 3350, 3388, 3798, 3994, 4013`, re-expressed once through commit's read-back); `stamp_closure` (`:109–153`, become invariant tests).

**New `Test_Ledger_Commit`, both storages:** fresh read under lock; retry then throw; invariants; in-hook refusal; `status_changed` and `payment_complete` listeners read the rows from storage; `after_save` drains at commit end and at `drain()`; the keeper ignores staged ledger meta.

**Kept:** the 126 command tests, now through `Money_Commands`. The 17 `save()` seeders use a `Ledger::commit` helper; malformed-storage fixtures write through the data store.

**Sole-Writer guard:** forbids `update_meta_data`/`delete_meta_data` on the four ledger keys, and `->payment_complete(`, outside `Ledger.php`. The unexempted `Save_Reentry_Guard` covers the rest.

## 7. Trade-offs

- **Leverage:** one lock, fresh read, read-back, invariant set and projection serve every row write.
- **Two saves on completion** (rows, then WooCommerce's `payment_complete`). A death between them leaves covering rows unpaid; every later commit, `close` and the sweeper re-derive, with no checkpoint. This bends ADR 0040's "no second phase" consequence: needs Paul's nod. One save via `payment_complete` would let a half-landed write complete the sale.
- **`$change` purity is a convention**, not a type.
- **`after_save` loses a webview row if PHP dies before shutdown**; WooCommerce's paid facts remain (balance fallback `Ledger.php:552–556`, D5 adoption `Order_Writer.php:460–513`).
- **Visible changes:** intent projects `pending` one round trip earlier (same end state); an adopted row's `payment_method_title` comes from the descriptor; `resolve_provenance` reads `source: webview`, not `is_paying` (the window has closed by drain time).
- **Thin:** `Ledger_Draft::$closing` is a wart; `Completion_Stamp` still writes meta (not a save) inside `status_changed`; `Money_Commands` keeps its verbs: this deepens the write path, not the command vocabulary.
