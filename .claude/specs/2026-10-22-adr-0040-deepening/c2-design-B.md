# c2 design B: `Sale_Write::run()`, one call per money command

A money command says *what* changes; the module owns lock, fresh read, merge, indexes, derive, paid transition, read-back and the single write. Paths are under `includes/Payments/Contract/` unless named.

## 1. Interface

```php
final class Sale_Write {
    /** @return mixed The edit's answer; a WP_Error answer still commits what the draft holds.
     *  @throws Ledger_Persistence_Exception */
    public static function run( WC_Order $order, callable $edit );   // $edit( Sale_Draft $sale )
    public static function completing( int $order_id ): bool;        // replaces Ledger::is_deriving()
    public static function register_hooks(): void;                   // foreign-save strip; replaces the keeper
}
final class Sale_Draft {
    public function order(): WC_Order;              // fresh, locked; stage meta, items, notes on it
    public function rows(): array;
    public function row( string $id ): ?array;
    public function put( array $row ): void;        // add or replace by id
    public function drop( string $id ): void;       // explicit delete (refused intent only)
    public function hold(): void;                   // stage only: no projection, no completion
    public function closing(): void;                // `close` only
    public function then( callable $after ): void;  // fn( bool $committed ), still under the lock
}
```

Invariants:

- **I1** Every ledger key (`_wcpos_payments`, the three indexes, `_wcpos_commands`, `_wcpos_gateway_attempts`, `_wcpos_awaiting_customer`) reaches storage only through a commit, under `Order_Lock`.
- **I2** A commit is exactly one carrier write: `payment_complete()`, `$order->save()` or `save_meta_data()`.
- **I3** The edit calls no provider and saves nothing. It may run twice, because the unit retries once.
- **I4** The read-back covers everything staged: rows, every staged ledger meta key, and the status when it was projected.

A caller needs three facts: provider calls go *between* runs; `hold()` marks the save before an external call; a returned `WP_Error` does not undo a `put()` (a refused row is a stored outcome, rule 5).

**Errors.** `Ledger_Persistence_Exception` (`Ledger_Persistence_Exception.php:11`): busy lock, storage read failure (today plain `RuntimeException`, `Ledger.php:185,193`), read-back miss after retry, or a core change staged in hook context. The route answers it with a retryable 409 (`API/V2/Payments_Controller.php:115`).

**Re-entrancy.** `Order_Lock::acquire()` counts nested acquires, so the route lock (`Payments_Controller.php:93`), Pro's `with_lock` and `Session_Figures.php:327` nest. No Pro companion PR.

## 2. Usage

**record.** Today 155 lines (`Ledger.php:617–771`); 19 are persistence (632–639, 717–718, 742–749) encoding four ordering facts: save with derive off before the drain, drain before derive, derive then a `saving()`-wrapped save, re-find on the refreshed object.

```php
$result = Sale_Write::run( $order, fn( Sale_Draft $sale ) => $this->admit_record( $sale, $input, $validated, $context, $command ) );
// admit_record(): validation unchanged; $sale->put( $row ); Settlement::instance()->drain( $sale, $row['id'] ); return $row;
// refused branch: $sale->put( $failed ); return $this->refusal_error( $failed, $sale->order() );
```

After: about 145 lines, 4 of them persistence (`run`, two `drain`, `find`), no ordering facts. Validation is the bulk, so the gain is knowledge, not lines.

**refund** (rule 8, two saves around `process_refund()`). Today `refund()` plus eight helpers is 322 lines (`Ledger.php:1091–1413`), about 45 of them persistence.

```php
if ( ! $this->try_write( $order, fn( $s ) => $s->hold() || $s->put( $held ) ) ) { return $this->withdraw( … ); }   // save 1
try { $new = $handler->refund( $row, $refund_id, $amount ); } catch ( \Throwable $e ) { return $this->strand( … ); }
/* caps and answer checks unchanged */
if ( ! $this->try_write( $order, fn( $s ) => $s->hold() || $s->put( $settled ) ) ) { return $this->strand( … ); }   // save 2
$this->try_write( $order, fn() => null );   // derive in its own commit (rule 3); a miss is a warning
```

`try_write()` catches `Throwable`: Pro allocates inside `woocommerce_create_refund` (`Refund_Processor.php:377`, 494–497), beside WooCommerce's deleting catch. After: about 190 lines, about 10 persistence. `persist`, `after_settle`, `stored_row`, `reserved`, `same_allocation` (`Ledger.php:1238–1371`) are deleted; read-back and retry cover them.

**close.** One `run` (checks, `$s->closing()`, command entry); a second adds the completion ref (today's `remember_close`, 1801–1821). The re-fetch at 1781 goes.

**submit** (rule 7). Save 1: a `hold()` run staging the `sent` attempt, command entry and method; one write read back whole, so the half-landed cleanup goes (`Gateway_Submission.php:222–236`). `process_payment()` runs between runs. Save 2: a run minting the row or writing `settled`/`sent` (312–365). `restore_method` (517) and `answered` (590) become one run each.

**keeper.** `keep_closure_stamps` (`Ledger.php:269–418`) becomes a strip on `woocommerce_before_order_object_save`: a save of any object but the commit's own (`spl_object_id`) loses pending ledger-key changes, with `_doing_it_wrong()`. It writes nothing, so no lock, no merge. The push lane already strips these keys (`Services/Pos_Order_Audit.php:44–60`); this guards wc/v3 and admin. The new-order branch (286–308) is subsumed.

**stamp_closure.** A run setting `closure_id` on named rows (throw on missing or foreign). Meta-only, so the carrier is `save_meta_data()` as today (254); the `FOR UPDATE` read (215) is the unit's normal read. `Session_Figures.php:335` unchanged.

**Pro `Order_Status_Void.php:43–56`.** Unchanged: `void()` inside `woocommerce_order_status_changed` (:26) under its own `with_lock`; the commit sees hook context and writes meta only (§3).

**Sweeper:** `Payments_Sweeper.php:158–162` becomes `run( $order, fn() => null )`.

## 3. Behind the seam

1. Acquire `Order_Lock`, re-entrant; throw on failure.
2. Fresh read: `wc_get_order()` + `read_meta_data( true )`, and rows from the raw locked read (today's `stored()`, `Ledger.php:175`, now always `FOR UPDATE`). The HPOS or postmeta choice stays internal.
3. Run the edit.
4. Stage:
   - Unnamed stored rows are kept (2122).
   - Untouched rows keep their stored `updated_at_gmt` (2112–2115).
   - The three indexes are rebuilt (2134–2142, `reindex` 2194).
   - When the order is covered, attempts are marked paid or superseded (2145–2157).
   - Deleted: the three-way merge against an unlocked caller's rows (2095–2111), since under I1 nothing writes ledger keys between locked read and commit; the reflection carry of caller meta and items (2045–2081), since edits stage on the fresh object.
5. Project, unless `hold()`: `derive()` (2217–2290), now private, sets method, title, transaction id and partial/open/pending status, clears the awaiting stamp, memoises provenance, and decides whether the sale completes. It no longer calls `payment_complete()`.
6. **Carrier, the #2389 answer.**
   - *Completes, at an entry point.* The carrier is `payment_complete()`, with `completing[id]` set:
     1. WooCommerce's one `save()` writes rows, indexes, attempt fates, method fields, `date_paid` and paid status. WooCommerce is the caller, so the guard's `plugin_caller()` passes it.
     2. `status_transition()` fires `woocommerce_order_status_changed` with rows already stored; `Completion_Stamp::on_completed`@15 (`Completion_Stamp.php:69`) appends `_wcpos_completions[n]` via `save_meta_data()` (651); `wcpos_sale_completed` fires.
     3. `woocommerce_payment_complete` fires: the mint sees `completing()` and skips (`Webview_Passthrough.php:172`); the stamp stands; the `Gateway_Submission` listener (470–479) finds no stamp, cleared in step 5.

     Rows and paid status: one save; then the stamp as a meta write; then fiscal. If `payment_complete()` writes nothing (status not completable), fall back to `$order->save()`, never both.
   - *Does not complete.* One `$order->save()`.
   - *Hook context* (`doing_action()` on the guard's order hooks: `woocommerce_(pre_)payment_complete`, `woocommerce_order_status_*`, `woocommerce_payment_complete_order_status_*`, `woocommerce_before/after_order_object_save`, `woocommerce_new/update_order`): `save_meta_data()` on ledger keys, no projection; the owner is mid-save. A staged core change throws; the next command, `close` or the sweeper re-derives (rule 1). Covers the mint in `woocommerce_payment_complete` (`Webview_Passthrough.php:108`; the 60 trips at `Ledger.php:2165`), `Completion_Stamp::mints_attempt` (495, called at 260), the stamp-clear listener (`Gateway_Submission.php:475`), and Pro's void.
   - *Meta-only diff* (`stamp_closure`). `save_meta_data()`.
7. Read back (I4). On a miss, run steps 2–7 once more, then throw.
8. Refresh the caller's object (today 2172–2173), run the `then()` callbacks, and release the lock.

`Settlement::drain( $sale, $id )` replaces `apply_parked` (`Settlement.php:106`): park lock after order lock, as today, released in `then()` after commit, so a concurrent `settle()` finds the indexed row or waits.

**Deleted global state** (`Ledger.php:87–101, 424–514`): `$closure_locks`, `release_closure_lock`, `$server_saves`, `$refused`, `save_was_refused()`, `saving()`. `saving()` only released keeper locks, so its 17 call sites become plain saves or runs; `Order_Writer.php:166,180` go. `release_hold_if_idle` (hand-called at 824, 917, 1029, 1073, 1933) becomes a post-commit step; it is a direct SQL delete (`Services/Stock_Validator.php:100–111`), safe in a hook.

## 4. Dependencies and adapters

- **Local-substitutable:** order storage (HPOS/postmeta), `Order_Lock` (GET_LOCK/option lease), parked option; wp-env MariaDB runs both of each. Internal seams, off the interface.
- **In-process:** `payment_complete()` and its listeners. No port.
- **True external:** providers, already behind `Capture_Mode_Handler_Interface` (Manual, Gateway, Webview, test fakes); outside the unit by I3.

No new port, table, column or option.

## 5. Slice plan

Each PR targets `next`, is green on its own and is not stacked. Figures are added production lines.

| PR | Content | Est. |
|---|---|---|
| 1 | `Sale_Write` and `Sale_Draft` with their tests and no callers. Includes a temporary bridge that runs the commit inside the old keeper's server-save scope. | ~480 |
| 2 | `record`, `intent`, `capture`/`apply_result`, `void`, `status`, `append_events`, `settle_allocation` and `adopt` move onto `run`. Adds `Settlement::drain`. Deletes `save()`, `replace_and_save()` and public `derive()`. | ~200 |
| 3 | `refund`'s two saves; deletes five helpers. | ~90 |
| 4 | `close`, the webview mint, the sweeper, and `Order_Writer` adopt (487). `completing()` replaces `is_deriving()` (`Completion_Stamp.php:479`, `Webview_Passthrough.php:172`). | ~90 |
| 5 | `Gateway_Submission` submit, cancel, listener, `restore_method` and `answered` move onto `run`. | ~130 |
| 6 | The strip replaces the keeper; `stamp_closure` moves onto `run`; deletes `saving()`, the refusal and lock state, and the bridge. **Removes `Save_Reentry_Guard::except()` (`tests/bootstrap.php:271`).** | ~120 |

Pro signatures are unchanged; after PR 6 run Pro's `Test_Order_Status_Void` against Free `next` by hand.

## 6. Tests

**New `Test_Sale_Write`** (HPOS and postmeta, interface only): one carrier per commit; a completing run stores rows and paid status in one save, with the completion entry present when `woocommerce_payment_complete` fires; a run in each listed hook writes meta only and refuses a core change; read-back miss retried once then thrown (intercept `query`); concurrent rows survive; untouched `updated_at_gmt` kept; nesting in `with_lock` keeps the outer lock; the strip drops a foreign ledger change; the hook list covers every order hook in `Save_Reentry_Guard::REENTRY_*`.

**Kept:** `Test_Ledger.php`'s 126 money-command tests as the spec; the rule-8 pins (`test_refund_whose_provider_throws_keeps_the_reservation…` and siblings) pass unchanged. The 17 seeding via `save()` seed via `run()`.

**Deleted:** keeper-merge tests (`test_order_save_carrying_the_ledger_*`, `test_order_create_*`, `test_order_save_keeps_a_stamped_row…`, `test_keeper_write_back_encodes_empty_maps_as_objects`), `test_failed_ledger_save_releases_keeper_locks_but_preserves_outer_lock`, `test_dirty_ledger_save_is_refused_while_another_owner_holds_lock`, `test_rebase_keeps_caller_edits_and_fresh_server_fields`, `test_a_settle_that_keeps_stripping_the_allocation_re_persists_the_reservation`, and `Order_Writer`'s refused-409 tests.

**Sole-Writer:** the regex scan (`Test_Ledger_Sole_Writer.php`) is deleted; the strip's `_doing_it_wrong()` enforces I1 at runtime in every test.

## 7. Trade-offs

- **High leverage:** eight commands, sweeper, `stamp_closure` and mint stop pairing save/derive/wrapper/re-fetch; five global flags and about 600 lines go (keeper 150, `save` 156, saving/refused 91, refund helpers ~130, submit cleanups ~70). #2389 closes by construction.
- **Thin:** `record()`/`refund()` length is validation and caps.
- **Hook detection reads `$wp_current_filter`.** A hook left on the stack by a throwing listener reads as hook context: fails safe (meta only, or a retryable refusal) but can refuse spuriously. The trips at `Gateway_Submission.php:235, 531, 595` and `Ledger.php:748` (4, 4, 2, 4) are in entry-point code; cause unverified, re-measured in PRs 5 and 6.
- **Cannot:** make a provider call and its row atomic (rules 7, 8 stay separate runs); stamp completion in the paid-status write (the stamp needs stored rows); complete a sale inside someone else's hook (waits for the next command, `close` or the sweeper).
- **Corrections to the brief:**
  - Pro also calls `apply_result` (`Payments_Answer_Controller.php:88`), `adopt` (`Legacy_Adoption.php:62`), `refund` (`Order_Pay_Gateway.php:156`), `settle_allocation` (`Refund_Sweep.php:244`), `append_events` (287) and `summary` (`Payments_Answer_Controller.php:103`). All keep their signatures.
  - Pro's in-hook void is a seventh #2389 site.
  - `release_hold_if_idle` is at 1073 and 1933, not 1074 and 1935.
