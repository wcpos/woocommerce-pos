# Spec — Session_Figures: one module for what is in the drawer, and one lateness rule

Read on Free `c42191d3` (origin/next, branch `chore/arch-review-wave2`; Store Reach #2192, closure 404 #2194 and Ledger `updated_at_gmt` #2186 merged) and Pro `808198b` (origin/next). Source: `verdict-till.md` candidate 2, `findings-till.md` A2, and Paul's ruling of 2026-10-13: **the closure stamps the counted ledger rows; a later sale, refund or movement is late when its row carries no closure id.**

**Two PRs, one spec.** Part A is the module and the re-pointing, and has no dependency. Part B is the lateness rule. **Part B's PR adds `Ledger::stamp_closure()` to the Ledger itself.** The sole-writer PR is being implemented from its own spec and does not carry it. Part B also adds the server-only stamp rule for client ledger writes and the per-register lock that fixes verdict D6. Run Part A first, and branch Part B from a tree that contains Part A. Each part has its own budget.

Status: **High tier (money, fiscal).** Every design question is decided; the coordinator's decisions of 2026-10-13 are at the end. Part A and Part B are each Codex-ready.

---

## Goal
Every figure about a till session's drawer comes from one module, `Session_Figures`: expected per tender, the sale/refund split, the cash-kind rule, refund tender rows, the reads of legs, refunds and movements, and (Part B) whether a closure counted a row. The eight callers stop re-deriving these figures. `expected()` loses its three preload parameters, and the fake-session calls disappear.

## Stakes
High tier (`.ai/rules/stakes-tiers.mdc`). A wrong figure misstates the drawer on an immutable, numbered closure. A wrong lateness answer double-counts a sale in a correction or drops it from one. That cash is then silently missing from, or doubled on, a fiscal document that is never edited. None of this code is released: `git cat-file -e origin/main:includes/Services/Register_Session_Store.php` fails, and the same is true for `Closure_Store.php` and `Fiscal_Record_Writers.php`. So deleting public methods costs no merchant anything, and closures already written on dev sites are the only legacy data.

**Accepted risks** (record them, do not engineer against them):
- A sale whose `record_sale()` runs *before* a concurrent closure commits, and whose leg was not in the closure's snapshot, is neither counted nor corrected. This is true today. Part B makes it detectable (a captured leg in a closed session with no stamp) but writes nothing for it.
- A closure written before Part B has no stamped legs. If `payment_complete` fires again for one of its orders, that order gets one `late_sale` (its identity is the order id, so at most one). This affects dev data only.
- On MySQL older than 5.7.5 or MariaDB older than 10.0.2, `Order_Lock` falls back to option leases (`Order_Lock.php:65-72`), and they do not exclude anyone inside the closure's transaction. On those servers a second `GET_LOCK` (the fiscal sequence lock inside `Fiscal_Record_Store::record()`) also releases the register lock. Those versions are end-of-life.
- A ledger writer that does not take `Order_Lock` and saves in the instant between the stamp keeper's read and its own write can still drop a stamp. Every Free and Pro ledger writer takes the lock except `Webview_Passthrough::mint` (`Webview_Passthrough.php:196`), which mints a new row on payment complete.
- With a persistent object cache, a stamp written in a closure transaction that then rolls back can stay cached. The retry reuses the same closure id (it is client-minted), so the stale stamp is the correct one. The `_wcpos_closure` refund stamp has the same property today.

## Out of scope
Do not add: a new table, a column, an option, a migration or a backfill of stamps onto old closures; a sweep for unstamped legs; retries; a `closure_id` column on `wcpos_cash_movements`; an order-meta row per stamp; a counted-refund-ids store; a transaction around movements that have no closure (the D6 fix is a named lock, not a row lock); changes to Pro; a new REST field or endpoint; a feature flag; an env var; a filter or action; float arithmetic (every sum stays a SQL `DECIMAL(65,4)` through `Closure_Store::sum()`); changes to `Closure_Store::variance()`, `Fiscal_Record_Store` identities, `refund_tender_rows()` attribution rules, or the `_wcpos_session`/`_wcpos_register` refund stamps. No plan files.

---

## Ruling as implemented, and the two places it cannot be literal

| Row | What "counted" means | The stamp | Late when |
|---|---|---|---|
| **Leg** (a payment row in `_wcpos_payments`) | The closure read it: `status = captured` and `session_id` = the closure's session, inside the closure's transaction | `closure_id` key on the ledger row, written by `Ledger::stamp_closure()` in that transaction | Captured, in the closure's session, and `closure_id` is not this closure's id |
| **Refund** (`shop_order_refund` with `_wcpos_session`) | The closure read it through `_wcpos_session` | **`_wcpos_closure` on the refund record, unchanged** | The refund does not carry this closure's id |
| **Cash movement** (`wcpos_cash_movements`) | It was committed before the closure's snapshot | **None.** The table has no stamp column, and the ruling forbids adding one | Its session's closure already exists when the movement, holding the register lock, reads it (`Cash_Movement_Store.php:124`, made exact in B5) |

**Refunds keep `_wcpos_closure`. This deviates from the ruling's letter, and the reason is a fact about the tree.** A refund's tender rows are its *succeeded allocations* on the parent's ledger legs, plus an unallocated remainder attributed to cash (`Register_Session_Store.php:440-473`). The remainder is on no ledger row. It is also the common drawer case: Pro's cash and manual refund destination builds `RefundPlan::manual()` with **no allocations** (Pro `Refund_Processor.php:206-207`, `RefundPlan.php:91`). A POS refund of a web order has no ledger at all. So a "cash refund the closure counted" has no ledger row to carry a stamp. The refund record is the refund's own row in order storage, and `_wcpos_closure` is a key on it, so this keeps to the ruling's constraint (no new table or column). The predicate is the same `Session_Figures::counted()` used for legs (decision 1).

**Movements are late by order, not by stamp.** "Counted" for a movement means committed before the closure's REPEATABLE READ snapshot (`Closure_Store.php:443` onwards). The rule "the closure exists when the movement is written" is exactly that, except for verdict D6's race. B5 closes the race with a per-register named lock that the closure holds from before `START TRANSACTION` until after COMMIT, and that the movement holds around its read and insert (decision 3). The rule's text lives in `Session_Figures`'s class docblock, so all three row types are explained in one place.

**Pro's `_wcpos_session`/`_wcpos_register` refund stamps are unchanged.** Pro writes them at `woocommerce_create_refund` priority 10 (Pro `Refund_Processor.php:549-554`). `Session_Figures::refunds()` selects refunds by `_wcpos_session` exactly as `session_refunds()` does today. Pro never reads `_wcpos_closure` or `closure_id`: I grepped Pro `includes/` and `tests/` for `_wcpos_closure`, `closure_id`, `expected(`, `captured_orders`, `session_refunds`, `refund_tender_rows`, `sales_count`, `corrections_for` and `->variance(`, and found 0 hits. Pro calls only `Register_Session_Store::get/list/create/transition/install` (`Refund_Processor.php:510-523`, `tests/includes/Test_Registers.php:170,207`, `tests/includes/API/Test_Order_Refunds_Session_Stamp.php:240-255`). It calls `transition()` only open→closed, which never reads expected.

## The `expected_delta` decision (cash-only frozen vs all-tender projection)
**Decided: freeze every tender.** The written payload's `expected_delta` becomes the same per-tender map the projection computes: `Session_Figures::tender_totals( $tender_rows )`, with cash first and every other tender after it. `variance_delta` becomes `Closure_Store::variance()` over **the tenders the closure counted** (`array_fill_keys( array_keys( $closure['counted'] ), '0' )`) instead of cash alone.

Why:
- The client glossary defines Expected per method, and Variance "for each payment method counted; a method that was not counted has no variance" (`monorepo-v2` `CONTEXT.md` "Expected", "Variance").
- Today the record says `{cash: 50}` while the projection says `{cash: 50, card: 30}` for the same late sale (`Fiscal_Record_Writers.php:110-117` vs `Closure_Store.php:257`).
- No production code reads the frozen deltas. `corrections_for()` recomputes from `tender_rows` (`Closure_Store.php:255-285`), and the client never reads `expected_delta` (grep of `monorepo-v2`: 0 hits). Nothing breaks.

The projection stays the reader. For late refunds it stays **live** on purpose (`Fiscal_Record_Writers.php:391-392`; `test_refund_later_allocation_projects_live_tenders`). The frozen map is the audit statement at write time. Both now come from one function. Existing tests assert only the `cash` key of the frozen deltas (`Test_Refund_Session_Binding.php:292,420,580-581,655`, `Test_Fiscal_Record_Writers.php:394-395`), so they still pass.

---

## Part A — the module and the eight callers

### A1. New `includes/Services/Session_Figures.php`
`final class Session_Figures` in `WCPOS\WooCommercePOS\Services`. The class docblock states the three-row lateness table above in five lines. Results are memoized per instance only: never static, never across requests.

```php
public static function for_session( array $session ): self   // reads nothing until asked
public function legs(): array        // order_id => captured legs bound to the session (was Register_Session_Store::captured_orders)
public function refunds(): array     // refund_id => ['refund' => WC_Order_Refund, 'rows' => tender rows] (was session_refunds)
public function movements(): array   // Cash_Movement_Store::list( $session['id'] ), read once
public function expected(): array    // per tender: counted_float + legs + refund rows + paid_in − paid_out (non-voided)
public function sales_count(): int   // count( legs() ): distinct orders
public function period_amounts(): array  // ['sales' => string[], 'refunds' => string[]], unsigned, for Closure_Store's totals loop
public function refund_count(): int  // count( refunds() ) + legs where is_refund_tender()  (X-report rule)
public function stamp( string $closure_id ): void  // Part A: refunds only (moved verbatim from Closure_Store.php:532-544)

public static function tender_key( array $row ): string          // the cash-kind rule
public static function is_refund_tender( array $row ): bool      // the sale/refund test
public static function tender_totals( array $rows ): array       // seeded ['cash' => '0'], replaces every fake-session call
public static function refund_tender_rows( \WC_Order_Refund $refund ): array  // moved verbatim
```

Each rule is written once, and must keep the current behaviour exactly:
- `tender_key`: `'cash' === ( $row['kind'] ?? '' ) ? 'cash' : ( array( 'pos_card' => 'card' )[ $method ] ?? $method )`, where `$method = $row['method'] ?? $row['method_id']` (today `Register_Session_Store.php:413-415`). Comment: "Every cash-kind gateway (pos_cash, cod, an extension's cash tender) is the drawer." The duplicate comment and rule at `Fiscal_Record_Writers.php:111-115` are deleted.
- `is_refund_tender`: `'refund' === ( $row['kind'] ?? '' ) || '-' === substr( (string) $row['amount'], 0, 1 )`. Today it is copied at `Closure_Store.php:261,523` and `Receipt_Data_Builder.php:48`. Ledger legs never carry either form (`Ledger::validate_input` refuses amounts ≤ 0 at `Ledger.php:705`, and `KINDS` has no `refund`), but a raw `Ledger::save()` can write them, and `test_xreport_refund_count_uses_live_session_ledger` pins that they count as refunds. Keep it, and say why in its docblock.
- `expected()` and `tender_totals()` share one private method that groups rows by `tender_key()` and sums each group with `Closure_Store::sum()`. That deletes the private SQL loop at `Register_Session_Store.php:424-431`. **Key order is part of the contract:** cash first (seeded), then other tenders in order of first appearance, legs before refund rows. `test_refund_allocation_recovers_a_missing_late_arrival_record` asserts `assertSame( array( 'cash' => …, 'card' => … ) )`.
- `period_amounts()`: legs are split by `is_refund_tender()`. A refund leg's amount goes to `refunds` with the sign stripped; every other leg goes to `sales`. Every refund tender row goes to `refunds` with its sign stripped (today `Closure_Store.php:517-531`).
- `stamp()` in Part A moves the refund stamp and its read-back verification unchanged: the same meta key, the same `RuntimeException( 'Closure refund stamp failed.' )`, and the same `$wpdb->last_error` check.
- `legs()` and `refunds()` move with their queries, HPOS/legacy selection, ordering, `RuntimeException` messages and the `LIKE '"session_id":"…"'` pre-filter unchanged. Do not "improve" the queries.

### A2. Re-point the eight callers
1. `Register_Session_Store::transition` `:307`: `Session_Figures::for_session( $session )->expected()['cash']`.
2. `Sessions_Controller::read` `:120-131`: build one `$figures`. Use `$figures->movements()` for both the `/movements` response and `detail.movements`, plus `$figures->sales_count()`, and `$figures->expected()` only when `current_user_can( 'view_woocommerce_pos_reports' )`. Remove the `Cash_Movement_Store` read at `:120`. The detail now reads legs once and movements once (today it reads each twice).
3. `Closure_Store::create` `:512-544`: `$figures = Session_Figures::for_session( $session )`, then `$fields['expected'] = $figures->expected()`. `$totals = $figures->period_amounts()` feeds the existing totals loop (`:546-572`) unchanged. `$figures->stamp( $fields['id'] )` goes where the refund stamp loop was. Delete the local `$orders`/`$refunds` reads and the inline split.
4. `Receipt_Data_Builder::build_closure_document` X-report `:27-71`: one `$figures`. `expected()`, `movements()`, `refund_count()`, and `transaction_count => $figures->sales_count()`. Cashiers come from `refunds()` (`get_refunded_by()`) and from `legs()` (`cashier_id`), in the same order and with the same id → name fallback as today.
5. `Closure_Store::corrections_for` `:255-285`: late_sale uses `Session_Figures::tender_totals( $tenders )` and a sales/refunds split built from `is_refund_tender()`. late_refund uses `Session_Figures::refund_tender_rows()` and `tender_totals()`. The figures keys and the strings they hold are unchanged.
6. `Fiscal_Record_Writers::record_sale` `:110-117`: `$delta = Session_Figures::tender_totals( $rows )`, and `variance_delta` over the closure's counted tenders (the decision above).
7. `Fiscal_Record_Writers::record_late_arrival` `:312-314`: `$rows = Session_Figures::refund_tender_rows( $refund )`, `$delta = Session_Figures::tender_totals( $rows )`, and `variance_delta` as in 6.
8. `Fiscal_Record_Writers::record_reallocation` `:399-425`: keep the hand-built `tender_rows` (`$tender` −amount, `$cash` +amount). `$delta = Session_Figures::tender_totals( array( $tender, $cash ) )`, which gives `{cash:+amount, <tender>:−amount}`. `variance_delta` as in 6.

### A3. Delete from `Register_Session_Store`
Delete `expected()`, `refund_tender_rows()`, `session_refunds()`, `sales_count()` and `captured_orders()` (`:392-542`, 151 lines), and any `use` lines left unused (`Ledger`, `Money`, `Collection_Rules`; check each). Leave no `@deprecated` delegates: there is no Pro caller (grep above) and none of this is released. Re-point the test callers: the `Session_Expected_Tests` trait (`:31-32,114-119`) and `Test_Sessions_Controller.php:505` call `Session_Figures::for_session( $session )->expected()` / `->sales_count()`. Keep the assertions identical.

### A4. `CONTEXT.md`, `### Till`: add after **Correction**
> **Expected**:
> What each tender should hold at the end of a till session: the counted opening float plus every cash-kind leg (any gateway whose kind is `cash`, not only `pos_cash`), the session's refund rows and paid-in minus paid-out for cash, and for any other tender the sum of its captured legs bound to the session plus its refund rows. `Session_Figures::expected()` is the one derivation. A correction's expected delta uses the same tender rule without the float or movements (`Session_Figures::tender_totals()`), and the written record freezes every tender, not only cash.
> _Avoid_: drawer total, balance, takings

---

## Part B — the one lateness rule

Part B does four things. It adds the Ledger's stamp verb and the rule that only the server writes stamps (B1). It makes `Session_Figures` stamp legs and answer "late" (B2). It wires the closure (B3), the writers (B4) and movements (B5). Work in that order, writing each failing test first (see Tests, Part B).

### B1. `includes/Payments/Contract/Ledger.php`

**(a) Storage reader, private.** One method serves both the verb and the keeper:
```php
/** The stored ledger meta row, read from storage rather than the order object; null when the order has none.
 * @return array{meta_id:int, payments:array}|null
 * @throws \RuntimeException On a read failure, or an undecodable ledger when $lock is true. */
private function stored( int $order_id, bool $lock ): ?array
```
- Select the storage the same way as `Session_Figures::legs()`: `Collection_Rules::STORAGE_HPOS === Collection_Rules::detect_storage( 'orders' )`. On HPOS use table `{$wpdb->prefix}wc_orders_meta`, id column `id` and owner column `order_id`. Otherwise use `$wpdb->postmeta`, `meta_id` and `post_id`.
- SQL: `SELECT {id} AS meta_id, meta_value FROM {table} WHERE {owner} = %d AND meta_key = %s ORDER BY {id} ASC LIMIT 1`, with ` FOR UPDATE` appended when `$lock` is true. Ascending order matches the row WooCommerce's `get_meta( $key, true )` returns.
- If `'' !== $wpdb->last_error`, throw `RuntimeException( 'Ledger storage read failed.' )`. No row means return null.
- Decode the way `read()` does, in one place: `$raw = maybe_unserialize( $row['meta_value'] )`. Accept an array (the typed-meta form; see `Ledger.php:91-92` and commit 5461ee5a) or a JSON string. Require `SCHEMA` and an array `payments`. If the value is undecodable, throw when `$lock` is true and return null otherwise.

**(b) The verb, public:**
```php
/**
 * Record that a closure counted these legs. The stamp is server-only and permanent.
 *
 * The caller is inside its own transaction and holds Order_Lock for $order until that
 * transaction commits or rolls back. The read is a locking read because the caller's
 * REPEATABLE READ snapshot predates the lock: writing back the snapshot would erase a
 * leg recorded since.
 *
 * @throws \RuntimeException When a named leg is missing, carries another closure's id, or the write cannot be verified.
 */
public function stamp_closure( WC_Order $order, array $row_ids, string $closure_id ): void
```
1. `$stored = $this->stored( $order->get_id(), true )`. If it is null and `$row_ids` is non-empty, throw `RuntimeException( 'Closure stamp: ledger missing.' )`.
2. Lower-case the ids. For each stored row whose id is named, compare its `closure_id`. A value equal to `$closure_id` is a no-op. A different non-empty value throws `RuntimeException( 'Closure stamp: leg belongs to another closure.' )`. Otherwise set `$row['closure_id'] = $closure_id`. If any named id is not found, throw `RuntimeException( 'Closure stamp: leg missing.' )`.
3. Change **nothing else**. Do not call `normalize_row()`, and leave `updated_at_gmt`, the events and the refunds untouched: a stamp is not a leg change, and #2186's sweeper freshness must not move.
4. Write `wp_json_encode( array( 'schema' => self::SCHEMA, 'payments' => array_map( array( __CLASS__, 'to_wire' ), $payments ) ) )` with `$order->update_meta_data( self::META_KEY, $json, $stored['meta_id'] )` and then `$order->save_meta_data()`. These are the refund stamp's mechanics (`Closure_Store.php:534-535`). There is no `derive()`, no index meta, no `$order->save()` (so the keeper in (d) does not run) and no action.
5. Verify. `$check = $this->stored( $order->get_id(), true )`; every named id must now carry `$closure_id`, else throw `RuntimeException( 'Closure stamp failed.' )`.

**(c) `record()` drops a client `closure_id`.** Add `unset( $input['closure_id'] );` at the top of `record()`, before the `array_merge( $input, … )` at `Ledger.php:240`. A till cannot claim that a closure counted its leg. `intent()` builds its row explicitly (`:409-428`) and needs nothing.

**(d) Order writes cannot wipe or forge a stamp.** The app writes `_wcpos_payments` as typed meta on order pushes (`Ledger.php:91-92`). Every lane ends in `$order->save()`: wc/v3's forward from `Order_Writer`, v1, wp-admin, and the Ledger's own `save()`. So guard that one point:
```php
/** Hooks: keep the closure stamps the server wrote, whatever an order save carries. */
public static function register_hooks(): void   // add_action( 'woocommerce_before_order_object_save', array( self::instance(), 'keep_closure_stamps' ), 10, 1 )

/** Each leg's closure_id is what storage says, never what the writer says.
 * @param mixed $order Order about to be saved. */
public function keep_closure_stamps( $order ): void
```
- Return early unless `$order instanceof WC_Order && $order->get_id() > 0`. Also return early unless a `_wcpos_payments` entry in `$order->get_meta_data()` is new (`null === $meta->id`) or changed (`$meta->get_changes()` is non-empty).
- `$stored = $this->stored( $order->get_id(), false )`. Build `$stamps` as id ⇒ non-empty `closure_id`.
- Decode the pending value (`$order->get_meta( self::META_KEY, true )`) the way `read()` does. If it is invalid, return; `read()` already treats it as empty.
- For each pending row, set `closure_id = $stamps[ strtolower( id ) ]` when present, otherwise `unset( $row['closure_id'] )`.
- If nothing changed, return. Otherwise write the value back **in the form it arrived** (an array stays an array, a string is re-encoded as JSON) with `$order->update_meta_data( self::META_KEY, $value )`.
- Register it in `Service_Groups::construct_always()` (`Service_Groups.php:168-184`), next to `Webview_Passthrough::register_hooks()`, as `Ledger::register_hooks();`. If a hook-inventory test fails because of the new callback, add it to that test's expected list and name the test in your report.

### B2. `Session_Figures` additions
```php
public static function counted( $stamp, array $closure ): bool   // is_string( $stamp ) && '' !== $stamp && $stamp === (string) $closure['id']
public static function late_legs( \WC_Order $order, array $closure ): array  // Ledger::instance()->read( $order ): status 'captured', session_id === $closure['session_id'], ! counted( $leg['closure_id'] ?? null, $closure ); values re-indexed
public function release(): void      // Order_Lock::instance()->release() for every order stamp() locked, then forget them; idempotent
```
`stamp( string $closure_id )` now stamps legs before refunds. For each order id of `legs()` in **ascending** id order (one lock order for every closure):
1. `Order_Lock::instance()->acquire( $order_id )`. If it returns false, throw `RuntimeException( 'Closure stamp lock failed.' )`.
2. Record the id so `release()` can release it.
3. `$order = wc_get_order( $order_id )`. If it is not an order, throw `RuntimeException( 'Closure stamp: order could not be read.' )`.
4. `Ledger::instance()->stamp_closure( $order, array_column( $legs, 'id' ), $closure_id )`.

The refund stamp then follows, unchanged from Part A. `stamp()` never releases locks itself (decision 2: they are held until COMMIT).

### B3. `Closure_Store::create`
- Before `START TRANSACTION` (`:409`), take the register lock with `( new Register_Store() )->lock( $session['register_id'] )` (B5). On false, log `Logger::warning( 'Closure register lock failed.', … )` with `closure_id` and `session_id`, the same way `:410-416` does, and throw `RuntimeException( 'Closure register lock failed.' )`. If `START TRANSACTION` itself fails (`:409-418`), unlock before throwing.
- Initialize `$figures = null` before the `try` (`:429`). Part A's `$figures` assignment stays where it is.
- In the `finally` (`:680-690`), after the ROLLBACK, or after the COMMIT that ran in the `try`: first `if ( $figures ) { $figures->release(); }`, then `( new Register_Store() )->unlock( $session['register_id'] )`, then the deferred logging and the replay log. A stamp failure is a `RuntimeException`, so the existing catch defers its warning until after the rollback. The stamps roll back with the closure because they are on the same connection and transaction.
- The early recount branch (`:449-473`) rolls back and returns inside the `try`, so the `finally` still unlocks.

### B4. `Fiscal_Record_Writers`
- `record_sale` `:97-135`: keep the closure lookup by the order's `_wcpos_session` (`:97`) and keep `$sale` (`:98`). Delete the `$snapshot_created` read (`:99`) and replace the condition at `:101` with `if ( $closure && $sale ) { $rows = Session_Figures::late_legs( $order, $closure ); if ( $rows ) { … } }`. Write `tender_rows => $rows` (**only the legs the closure did not count**), with the deltas from A2.6. Drop the now-unused `Ledger` filter at `:102-109`.
- Delete `closure_counted()` (`:292-299`). Its callers at `:246` and `:393` use `Session_Figures::counted( $refund->get_meta( '_wcpos_closure', true ), $closure )`.

### B5. Movements: the per-register lock (verdict D6)
**`includes/Services/Register_Store.php`** gets:
```php
/** Seconds a movement or a close waits for the register: a close reads and stamps every leg of the session, so allow more than Order_Lock's 5. */
private const LOCK_WAIT_SECONDS = 10;

/** Serialize a close and the movements of one register. A named lock, not a row lock, so a movement needs no transaction.
 * @param string $register_id Register UUID. */
public function lock( string $register_id ): bool    // '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', 'wcpos_register_' . strtolower( $register_id ), self::LOCK_WAIT_SECONDS ) )

/** @param string $register_id Register UUID. */
public function unlock( string $register_id ): void  // SELECT RELEASE_LOCK( same name )
```
The name is 51 characters, inside MySQL's 64-character limit.

**`Cash_Movement_Store::create` `:109-226`:**
- After the `accepts()` refusal (`:121-123`) and **before** `for_session()` (`:124`): when `$session` is set, `$register = $session['register_id']`. If `! ( new Register_Store() )->lock( $register )`, log `Logger::warning( 'Movement register lock failed.', array( 'movement_id', 'session_id', 'user_id' ) )` and throw `RuntimeException( 'Movement register lock failed.' )`. The controller already turns that into a 500 (`Sessions_Controller.php:102-104`), and the till's outbox retries.
- Wrap everything from `:124` through the existing catch (`:211`) in `try { … } finally { if ( $locked ) { ( new Register_Store() )->unlock( $register ); } }`. The transaction rule at `:128-129` is unchanged: a movement opens a transaction only for a void or a late correction.
- Why this is exact: the closure takes the lock before its snapshot exists and holds it through COMMIT. A movement that committed before the closure took the lock is in the snapshot, so it is counted. A movement that waited reads the committed closure, so it is late. There is no third case.

### B6. `CONTEXT.md`, `### Till`: add after **Expected**
> **Late**:
> A leg, refund or cash movement of a till session that the session's closure did not count. The closure records what it counted on the row itself: `closure_id` on each ledger leg (written only by `Ledger::stamp_closure()`; an order write can neither remove nor forge it), and `_wcpos_closure` on each refund (a cash refund has no ledger row). A movement is counted when it was written before the closure; the two serialize on the register. A late row never edits the closure. It produces a Correction that holds only the late rows. Late is a fact about the row, never a comparison of clocks.
> _Avoid_: post-close, after the cutoff (for lateness), orphan sale

---

## Rulings to preserve (must stay green, unedited)
- `Session_Expected_Tests::test_expected_seeded_ledger_and_distinct_sales_count`, run through `Test_Register_Session_Store` and `Test_Sessions_Controller`. Expected is float + captured session legs per tender. Pending, authorized, failed, voided and other-session legs are excluded. A leg's `refunded_amount` binds no refund. A voided movement is excluded. `sales_count` counts distinct orders. The v2 detail equals the module. Only the receiver is re-pointed.
- `Test_Closure_Store::test_first_closure_derives_totals_and_replay_is_immutable`: period and perpetual totals, expected `{cash 150, card 30}`, variance only for counted tenders, `findings.total_mismatch`, a byte-identical replay. Also `::test_variance_uses_all_nineteen_digits` and `::test_failed_insert_rolls_back_session_and_counter_start`.
- `Test_Fiscal_Record_Writers::test_late_sale_is_replay_safe_and_does_not_mutate_closure`: one late_sale per order, an extension cash-kind tender counts as cash 50, the closure row is untouched, and a sale in an open session or counted by the closure is not late. Also `::test_repaired_preclosure_sale_is_not_a_late_sale`.
- `Test_Refund_Session_Binding`, all eleven tests. In particular: `test_refund_allocation_after_closure_records_reallocation` (`:280` asserts the `_wcpos_closure` stamp, which stays); `test_refund_allocation_recovers_a_missing_late_arrival_record` (the frozen cash is 0 after a card allocation, the projection is live, and the key order is cash then card); `test_refund_later_allocation_projects_live_tenders`; `test_refund_same_session_subtracts_once`; `test_refund_cross_session_preserves_original_drawer`; `test_refund_closed_session_creates_one_correction`; `test_refund_cash_allocation_after_closure_records_nothing`; `test_refund_pending_allocation_after_closure_records_nothing`.
- `Test_Closure_Receipts::test_xreport_refund_count_uses_live_session_ledger` (negative and `kind: refund` legs count as refunds; refunds are bound by `_wcpos_session`); `::test_xreport_ledger_cashiers_and_live_movements` (the X-report reads legs **once** and movements **once**, and lists ledger cashiers); `::test_closure_copy_preserves_labels_and_uncounted_tenders`.
- `Test_Register_Logging::test_closure_calculation_failure_is_logged_after_the_rollback` (sums stay SQL `DECIMAL(65,4)` SELECTs); `::test_nested_write_failures_are_logged_after_the_rollback`; `::test_session_variance_refusal_logs_rule` (the threshold reads cash expected, variance 5.0000); `::test_failed_closure_write_does_not_log_a_committed_state_change`.
- `Test_Closures_Controller::test_closure_corrections_detail_list_and_receipt_contract` (projected `expected_delta {cash 50, card 30}`, `sales_delta 80`, `refunds_delta 0`); `::test_late_movement_cutoff_and_correction`; `::test_failed_late_correction_rolls_back_movement`.

## Tests

**Characterization first. Add these to the current code before moving anything; they must pass on `c42191d3`:**
- `Test_Closure_Store::test_closure_period_totals_count_negative_and_refund_kind_legs_as_refunds`. Arrange: `closure_ledger()`, then add (by raw `Ledger::save`) a captured leg of `-5` and a captured `kind: refund` leg of `7` in the session. Act: `create()`. Assert: `assertSame( '80.0000', period_sales_total )` and `assertSame( '12.0000', period_refunds_total )`.
- `Session_Expected_Tests::test_expected_counts_every_cash_kind_tender_as_the_drawer`. Arrange: a session with float 100, and one captured leg `method_id: cod, kind: cash, 20` plus one `method_id: ext_voucher, kind: other, 5`. Assert: `assertSame( array( 'cash' => '120.0000', 'ext_voucher' => '5.0000' ), expected )`.

**Part A, failing first:**
- `Test_Sessions_Controller::test_session_detail_reads_ledger_and_movements_once`. Count queries as `Test_Closure_Receipts.php:675-689` does: `SELECT DISTINCT … _wcpos_payments` and `SELECT * FROM …wcpos_cash_movements`. GET `sessions/{id}` as a reports user. Assert `array( 'orders' => 1, 'movements' => 1 )` (today it is 2 and 2).
- `Test_Fiscal_Record_Writers::test_late_sale_freezes_expected_delta_for_every_tender`. Arrange: a closure whose `counted` is `{cash 101, card 30}` written before `closure_ledger()`. Act: `record_sale()`. Assert that the payload `expected_delta` is `{cash 50.0000, card 30.0000}` and `variance_delta` is `{cash -50.0000, card -30.0000}` (today both are cash only).

**Part A, new file `tests/includes/Services/Test_Session_Figures.php`** (`WCPOS_REST_Unit_Test_Case`, using `Closure_Test_Fixture` where it needs sessions):
- `test_tender_key_maps_every_cash_kind_to_the_drawer`: pos_cash, cod and an extension with kind `cash` → `cash`; `pos_card` → `card`; any other method → its id; `method` wins over `method_id`.
- `test_is_refund_tender_by_kind_or_sign`.
- `test_tender_totals_seeds_cash_first_and_keeps_appearance_order`: `assertSame` on the whole array, including key order and four-place strings.
- `test_expected_reads_each_source_once_however_often_asked`: call `expected()`, `sales_count()`, `refund_count()` and `movements()` twice each, and count one ledger query and one movement query.
- `test_period_amounts_split_legs_and_refund_rows`.

**Part B, failing first, in this order:**
1. `Test_Ledger::test_record_drops_a_client_closure_id`. Arrange: `create_pos_order()`. Act: `record()` a `pos_cash` leg whose input carries `'closure_id' => wp_generate_uuid4()`. Assert that `Ledger::instance()->find( wc_get_order( $id ), $leg )` has no `closure_id` key. This fails today: the `array_merge( $input, … )` keeps it.
2. `Test_Ledger::test_stamp_closure_stamps_only_the_named_legs_and_keeps_updated_at`. Arrange: two captured legs, with `updated_at_gmt` set to a fixed past time on both. Write the meta directly with `update_meta_data()` + `save()`, because `Ledger::save()` would restamp it. Act: `stamp_closure( $order, array( $leg1 ), $closure_id )`. Assert on a fresh read: leg 1 carries `$closure_id`; leg 2 has no `closure_id`; both keep the fixed `updated_at_gmt` (`assertSame`); every other field of both rows is identical to before (`assertSame` on the row minus `closure_id`). Calling it again with the same arguments is a no-op.
3. `Test_Ledger::test_stamp_closure_never_writes_back_a_stale_order_object`. Arrange: `$stale = wc_get_order( $id )`, then append a leg through a second, fresh object and `record()`. Act: `stamp_closure( $stale, array( $leg1 ), $closure_id )`. Assert that the appended leg is still in the stored ledger.
4. `Test_Ledger::test_stamp_closure_refuses_a_missing_leg_or_another_closures_leg`. Assert `RuntimeException` for an unknown id. Stamp with C1, then assert `RuntimeException` for the same leg with C2, and that the stored stamp is still C1.
5. `Test_Ledger::test_order_save_carrying_the_ledger_keeps_server_stamps_and_drops_forged_ones`. Arrange: legs 1 and 2; `stamp_closure()` leg 1 with C. Act: take the rows from `read()`, unset leg 1's `closure_id`, and set leg 2's to `wp_generate_uuid4()`. Then `$order->update_meta_data( Ledger::META_KEY, array( 'schema' => Ledger::SCHEMA, 'payments' => $rows ) )` (the app's typed-meta form) and `$order->save()`. Assert on a fresh read: leg 1 carries C; leg 2 has no `closure_id`. Repeat once with the JSON-string form.
6. `Test_Fiscal_Record_Writers::test_late_sale_freezes_only_the_legs_the_closure_did_not_count` **(the double-count defect)**. Arrange: an order with leg A (cash 50, captured, session S) and order status `pos-partial`; transition S to counting; `Closure_Store::create()` (which stamps A); then append leg B (cash 20, captured, session S) through `Ledger::instance()->save()`; set `Receipt_Snapshot_Store::META_KEY_CREATED_AT` to the closure's `received_at_gmt`. Act: `record_sale( $order, array( 'fiscal' => array() ), 876546 )`. Assert one `late_sale`, that `array_column( $payload['tender_rows'], 'id' )` is exactly `array( $leg_b )`, and that `expected_delta.cash` is `'20.0000'`. Today it writes `[A, B]` and `70.0000`, counting the closure's 50 twice.
7. `Test_Fiscal_Record_Writers::test_sale_counted_by_the_closure_is_not_late_whatever_its_snapshot_clock`. Arrange: `closure_ledger()`, then `create()`, then set `META_KEY_CREATED_AT` one hour after `received_at_gmt`. Act: `record_sale()`. Assert no `late_sale` for the order. Today the clock rule writes one.
8. `Test_Closure_Store::test_closure_stamps_every_leg_it_counted_and_no_other`. Arrange: `closure_ledger()`, with the rows' `updated_at_gmt` noted. Act: `create()`. Assert: rows 0 and 1 carry the closure's id; the pending row and the other-session row have no `closure_id`; `updated_at_gmt` is unchanged on all four; `assertSame( $row, $store->get( $row['id'] ) )`.
9. `Test_Register_Logging::test_closure_stamp_failure_is_logged_after_the_rollback`. Use a `query` filter that turns the `_wcpos_payments` meta `UPDATE` into invalid SQL, in the style of `test_closure_calculation_failure_is_logged_after_the_rollback`. Assert the `RuntimeException`, a warning carrying `closure_id`, `assert_logged_after_rollback()`, no closure row, the session still `counting`, and no leg carrying `closure_id`.
10. `Test_Closure_Store::test_failed_closure_leaves_no_stamps_and_releases_its_locks`. Arrange as `test_failed_insert_rolls_back_session_and_counter_start` with `closure_ledger()`. Assert no leg carries `closure_id`, that `Order_Lock::instance()->acquire( $order_id )` succeeds afterwards (release it), and that `SELECT IS_FREE_LOCK( 'wcpos_register_<id>' )` is `'1'`.
11. `Test_Cash_Movement_Store::test_movement_reads_the_closure_only_under_the_register_lock`. Use a `query` filter that records SQL. Act: create a `paid_in` on a session that has a closure. Assert that the `GET_LOCK` for `wcpos_register_<register id>` comes before the closures `SELECT … session_id`, that `RELEASE_LOCK` comes after the movement `INSERT`, and that one `late_movement` was written. Today there is no `GET_LOCK`.
12. `Test_Closure_Store::test_closure_holds_the_register_lock_across_its_transaction`. Same filter. Assert `GET_LOCK( 'wcpos_register_…' )` precedes `START TRANSACTION`, and `RELEASE_LOCK` follows `COMMIT`.
13. `Test_Register_Store::test_register_lock_excludes_another_connection`. Open a second connection, `new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST )`. `lock( $id )` on the test connection. Assert that the second connection's `SELECT IS_FREE_LOCK( 'wcpos_register_<id>' )` is `'0'`; after `unlock( $id )`, that it is `'1'`. Close the second connection.
14. `Test_Session_Figures::test_counted_requires_this_closures_id` (null, `''`, another id and this id) and `::test_late_legs_are_captured_session_legs_without_this_closures_stamp`.

Tests 10, 13 and the `Test_Session_Figures` additions pin new behaviour rather than fail first. Each of the others is written before the code it pins.

**Tests to delete:** none. Re-pointing replaces the layer.

## Budget (from `wc -l` on c42191d3)
Regions: `Register_Session_Store.php:392-542` (151, moves), `Closure_Store.php:241-324` (84) and `:512-544` (33), `Receipt_Data_Builder.php:26-72` (47), `Fiscal_Record_Writers.php:97-135` (39), `:292-339` (48), `:364-428` (65), `Sessions_Controller.php:112-131` (20). Total 487. The new module is ~240 lines with docblocks.
- **Part A:** non-test diff (added + removed) ≤ **700** lines including lint; net non-test growth ≤ **+90**; total diff ≤ **1,250**.
- **Part B** (including the Ledger verb, the keeper and the register lock): Ledger +≈150 (reader 35, verb 60, record 1, keeper 45, hooks 8), Register_Store +≈30, Cash_Movement_Store +≈20, Closure_Store +≈20, Session_Figures +≈70, Fiscal_Record_Writers −≈35/+≈15, Service_Groups +1. That is ≈345 lines changed before lint. Ceilings: non-test diff ≤ **520** lines including lint; net non-test growth ≤ **+330**; total diff (with the 14 tests, ≈480 lines) ≤ **1,050**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l` on every changed PHP file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <changed files>`
- The orchestrator runs these in wp-env afterwards, one file per call: `tests/includes/Services/Test_Session_Figures.php`, `Test_Register_Session_Store.php`, `Test_Closure_Store.php`, `Test_Fiscal_Record_Writers.php`, `Test_Register_Logging.php`, `Test_Cash_Movement_Store.php`, `Test_Register_Store.php` (Part B), `tests/includes/API/V2/Test_Sessions_Controller.php`, `Test_Closures_Controller.php`, `Test_Closure_Receipts.php`, `Test_Refund_Session_Binding.php`, `tests/includes/Payments/Contract/Test_Ledger.php`; then, against this Free, Pro's `tests/includes/API/Test_Order_Refunds_Session_Stamp.php` and `tests/includes/Test_Registers.php`. For Part B also run Pro's `Test_Refund_Sweep.php` and `Test_Refund_Processor.php`: their raw `Ledger::save()` calls now pass through `keep_closure_stamps()`.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (`git add` the new test and module files by name).

---

## Decisions (coordinator, 2026-10-13; taken from this trace, none needs Paul)
1. Refunds keep the `_wcpos_closure` stamp on the refund record, read through the same `Session_Figures::counted()` predicate as legs. There is no counted-ids column or `breakdowns` key.
2. Stamping rewrites the ledger inside the closure transaction, under the existing per-order `Order_Lock` held until COMMIT, with a locking read. There is no separate meta row per leg.
3. Movements stay "late if the closure already existed when written", made exact by the per-register named lock (B5), not D6's row lock. It lands inside Part B.
4. `Ledger::record()` drops a client `closure_id`, and `keep_closure_stamps()` makes every order save keep the stored stamps, so no lane can wipe or forge one. Both are part of Part B's `stamp_closure()` contract. Part B's PR adds `stamp_closure()` itself; the sole-writer PR does not carry it.
