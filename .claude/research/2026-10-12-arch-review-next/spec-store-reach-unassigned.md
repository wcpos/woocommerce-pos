# Spec: Store Reach can admit unassigned rows, when the seam says so explicitly

Read on Free `c42191d3` (origin/next, Store Reach merged as #2192). **Lane:** a Free PR. Branch from `origin/next` and target `next`. It **lands before** the Pro adapter (`spec-pro-store-reach-adapter.md`). The change is additive: with nothing hooking the new key, behaviour is unchanged, and every existing test stays as it is.

## Goal
`woocommerce_pos_closures_list_args` gains an explicit second key, `'unassigned' => true`. When that key is set and the reach is otherwise restricted, Store Reach admits rows that belong to no store, in item checks and in list SQL. Pro will set the key only for `manage_options` holders. That is Paul's ruling of 2026-10-13: administrators see unassigned rows, while cashiers and managers never do.

## Stakes
**High.** This touches an authorization boundary. A fail-open would show unassigned registers, sessions, closures and records to someone who should not see them. A fail-closed would hide them from an administrator, which is today's behaviour, so nothing regresses.

Accepted risk: a report producer that ignores the new scope key leaves unassigned rows out of an administrator's report. That fails closed.

## Decision you may reverse: "unassigned" means `store_id IS NULL OR store_id = 0`
The ruling says "NULL-store". But only registers store NULL for "no store" (`Register_Store.php:104`). Free-era rows elsewhere store **0**:
- Sessions keep the client's `store_id`, and the regex `/^\d{1,18}$/D` admits `'0'` (`Sessions_Controller.php:181,187-191`). The Free client sends `store.id`, which is 0 (verdict D3, `monorepo-v2 session-store.ts:83`).
- Closures copy the session's store (`Closure_Store.php:574`).
- Fiscal records take `_pos_store` or the session's store (`Fiscal_Record_Store.php:378`, `Fiscal_Record_Writers.php:288`).

With NULL alone, the ruling's purpose would fail for every Free-era session, closure and record. No store can have id 0: it is Free's default store and Pro's "all stores" wildcard (`Pro Services/Stores.php:171-173`), and Store Reach already refuses 0 in an answer (`Store_Reach.php:61-62`). So this spec treats NULL and 0 alike, as "unassigned".

If Paul says NULL only, delete the `OR store_id = 0` half, the `0 === (int)` branches and the 0-row test rows. Nothing else changes.

## Out of scope (do not add)
- No new filter, option, table, column, env var or flag. No per-capability logic in Free: who gets the key is Pro's call.
- No change to write paths or replays. No change to D3.
- No rename of the seam. No change to `Report_Scope_Gate`. No migration that rewrites 0 to NULL. No plan files.

## Exact changes

### 1. `includes/Services/Store_Reach.php` (120 lines)
- **Property.** Add `private $unassigned;` (bool). The private constructor becomes `__construct( ?array $store_ids, bool $malformed, bool $unassigned = false )`.
- **`for_request()` (`:47-72`).** After the `is_array( $answer )` check, read the key:
  `$unassigned = true === ( $answer['unassigned'] ?? null );`
  - Any value other than boolean `true` is ignored. It is **not** treated as malformed: nothing is logged and the store list is still read.
  - A missing `store_id` key stays unrestricted (`:56-58`). The flag is irrelevant there.
  - A malformed `store_id` still denies everything, with the flag false (`:63-69`).
  - Pass `$unassigned` to the constructor on the well-formed path (`:71`).
- **`admits()` (`:88-96`).** After the unrestricted check:
  `if ( null === $store_id || 0 === (int) $store_id ) { return $this->unassigned; }`
  Today `admits( 0 )` returns false, because the ids never contain 0. With the flag false that is unchanged.
- **`narrow()` (`:102-119`).** A `null` member in the narrowed `store_id` list means "the unassigned rows".
  - With no `store_id` in the args: return `$this->store_ids` plus `null` when the flag is set.
  - With a requested `store_id`: keep each requested positive id that is in reach, as today. Map a requested `null`, or a numeric 0, to `null`, but only when the flag is set. Write the loop out rather than using `array_intersect`, which mishandles `null`.
  - `store_ids()` keeps returning ints only. Its callers want ints.
- **New public static `store_predicate( array $store_ids ): string`.** It is the one SQL form of a narrowed list. Every store's `list()` uses it, so the "IN (ids) OR unassigned" rule exists once.
  ```php
  /** SQL for a narrowed store list: ints are stores, a null member is the unassigned rows.
   *
   * @param array $store_ids Narrowed list from narrow().
   */
  public static function store_predicate( array $store_ids ): string {
  	$parts = array();
  	$ids   = array_map( 'intval', array_filter( $store_ids, static function ( $id ) { return null !== $id; } ) );
  	if ( $ids ) {
  		$parts[] = 'store_id IN (' . implode( ',', $ids ) . ')';
  	}
  	if ( in_array( null, $store_ids, true ) ) {
  		$parts[] = 'store_id IS NULL OR store_id = 0';
  	}
  	return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '1=0';
  }
  ```
  For an all-int list this gives the same SQL as today, apart from the outer parentheses: `IN (...)`, or `1=0` when the list is empty.
- **Class docblock (`:13-17`).** Replace `:14`'s "Pro does not yet hook this filter." with:
  "Pro answers it with the stores the current user is authorized for. An explicit `'unassigned' => true` (boolean true only; anything else is ignored) also admits rows with no store, NULL or 0, while the reach is otherwise restricted."
  Replace `:15`'s "There is no exemption for 0 or NULL" with "0 and NULL are unassigned, never a store."

### 2. Four list SQL sites call the helper
Each replaces the two-line `$ids = array_map( 'intval', … ); $where[] = $ids ? 'store_id IN (…)' : '1=0';` with `$where[] = Store_Reach::store_predicate( (array) $args['store_id'] );`. All four are in the same namespace, so no `use` is needed.
- `Register_Store::list()`: the array branch (`:166-168`). The scalar `store_id`/`IS NULL` branch (`:169-171`) is unchanged. Pro's backfill uses it.
- `Register_Session_Store::list()`: `:97-99`.
- `Closure_Store::list()`: `:159-161`. The closure export (`Closure_Csv::rows()` `:168-177`) and `/closures/last` (`Closures_Controller.php:179-192`) both go through this method, so neither needs its own change.
- `Fiscal_Record_Store::where()`: `:348-351`. This one method serves both `list()` and `count()`, so the records `X-WP-Total` header stays consistent with the rows.

### 3. `includes/Services/Provenance_Health.php` (240 lines), `:40`
The `/registers/health` route passes the narrowed list (`Registers_Controller.php:133`). `strval( null )` is `''`, which already matches orders with no `_pos_store`. Add the 0 form before the `strval` map:
`if ( null !== $store_ids && in_array( null, $store_ids, true ) ) { $store_ids[] = 0; }`

### 4. `includes/Services/Report_Scope_Resolver.php` (152 lines)
- **`:29`.** Change to `if ( array() === $reach->store_ids() && ! $reach->admits( null ) ) {`. An administrator with no authorized store still reaches the unassigned rows, so the blanket 404 must not fire.
- Session mode (`:37`), register mode (`:65`) and a named `store_id` (`:76`) already go through `admits()`, so they follow the flag with no edit.
- **Context (`:106`).** Add `'allowed_unassigned' => $reach->admits( null ),` next to `allowed_store_ids`.
- **`resolve()` (`:125`).** Add `$scope['allowed_unassigned'] = $context['allowed_unassigned'];` and extend the `:121-124` comment: "`allowed_unassigned` says whether rows with no store (NULL or 0) are also within reach; it is true when unrestricted."

### 5. Docblocks that are false, in this PR
- `Store_Reach.php:14-15`: as in change 1.
- `Registers_Controller.php:118`, "Filter authorized store scope for the register list.": replace with "Extension filter for the register list; not an authorization seam. Store_Reach narrows the result." This stays true whether or not Pro still hooks it.
- `Records_Controller.php:122`, "Filter authorized history scope; Pro may constrain store_id.": replace with "Extension filter for the record list; not an authorization seam. Store_Reach narrows the result below."
- `CONTEXT.md:103` (Store reach). Replace "A NULL-store row is never within a restricted reach." with "A row with no store (NULL or 0) is outside a restricted reach unless the answer also carries `'unassigned' => true`; under Pro that is administrators only."

## Rulings to preserve
- Store Reach reads the seam from an empty base, and a non-array or unreadable `store_id` denies everything. Pinned by `Test_Store_Reach.php:48-92`.
- Without the key, unassigned rows are never admitted under a restriction (`:105-114`). Rename that test's docblock to "…without the unassigned key". Keep its assertions.
- Narrowing never replaces a caller's selection that does not overlap the reach (`:126-135`).
- Out of reach answers exactly as absent. Pinned by the per-route reach tests listed in `verdict-till.md` candidate 1.

## Tests
Use `assertSame( expected, actual )` and Arrange / Act / Assert throughout. Add or remove the filter in `try/finally`, the same way as `Test_Store_Reach::reach()`.

### Contract: `tests/includes/Services/Test_Store_Reach.php` (136 lines)
- `test_store_reach_unassigned_key_admits_rows_without_a_store`: the answer `array( 'store_id' => array( 7 ), 'unassigned' => true )`. Then `admits( null )` is true, `admits( 0 )` and `admits( '0' )` are true, `admits( 7 )` is true, `admits( 8 )` is false, `store_ids()` is `array( 7 )`, and `is_malformed()` is false.
- `test_store_reach_unassigned_key_accepts_only_boolean_true`: for each of `1, '1', 'true', 'yes', array( true ), null, false`, the answer with `store_id => array( 7 )` gives `is_malformed()` false, `store_ids()` `array( 7 )` and `admits( null )` false.
- `test_store_reach_unassigned_key_never_repairs_a_malformed_restriction`: `array( 'store_id' => array( 'x' ), 'unassigned' => true )` gives `is_malformed()` true and `admits( null )` false.
- `test_store_reach_unassigned_key_is_irrelevant_when_unrestricted`: `array( 'unassigned' => true )` gives `store_ids()` null and `narrow( array( 'x' => 1 ) )` unchanged.
- `test_store_reach_narrow_carries_unassigned_as_a_null_member`: with `array( 1, 2 )` plus the key:
  - `narrow( array() )` is `array( 'store_id' => array( 1, 2, null ) )`;
  - requested `2` gives `array( 2 )`;
  - requested `null` gives `array( null )`;
  - requested `0` gives `array( null )`;
  - requested `array( 2, null )` gives `array( 2, null )`;
  - requested `3` gives `array()`.

  Without the key, requested `null` and `0` both give `array()`.
- `test_store_reach_store_predicate_forms`:
  - `array()` gives `'1=0'`;
  - `array( 1, 2 )` gives `'(store_id IN (1,2))'`;
  - `array( null )` gives `'(store_id IS NULL OR store_id = 0)'`;
  - `array( 1, null )` gives `'(store_id IN (1) OR store_id IS NULL OR store_id = 0)'`.

### One wiring test per surface
Each test uses the filter answer `array( 'store_id' => array( 789 ), 'unassigned' => true )`. It asserts that the unassigned row is readable. It then repeats the same request **without** the key and asserts the row is absent: the same status and `code` as for a random id, or missing from the list.
- `Test_Registers_Controller::test_unassigned_register_is_reachable_only_with_the_unassigned_key`: create a register with no store. Assert on GET item, GET `/wcpos/v2/registers?status=all`, a PATCH of `name` (200 with the key, absent-404 without), and GET `/wcpos/v2/registers/health` (the register is in `registers` with the key and not without).
- `Test_Sessions_Controller::test_unassigned_session_is_reachable_only_with_the_unassigned_key`: one session with `store_id` NULL and one with 0. Check GET item, `/movements` and the list.
- `Test_Closures_Controller::test_unassigned_closure_is_reachable_only_with_the_unassigned_key`: create a closure through the fixture, then set its row `store_id` to NULL with `$wpdb->update` on `( new Closure_Store() )->table_name()`. Check GET item, the list, and `closures/export`. The export holds the closure's number with the key, and is byte-identical to an empty export without it, using the existing `export_csv` helper.
- `Test_Records_Controller::test_unassigned_record_is_reachable_only_with_the_unassigned_key`: `Fiscal_Record_Store::record()` with `store_id` null (base as at `:28-35`). Check GET item, the list, and that `X-WP-Total` is `'1'` with the key.
- `Test_Closure_Receipts::test_unassigned_closure_document_is_reachable_only_with_the_unassigned_key`: closure store set to NULL. The document `closure:{id}` resolves with the key, and without it gives the same status and code as `closure:{random uuid}`.
- `Test_Reports_Controller::test_unassigned_session_report_runs_only_with_the_unassigned_key`:
  - Arrange: set the fixture session's and closure's `store_id` to NULL.
  - With `array( 'store_id' => array(), 'unassigned' => true )`, a session-mode report is 200 and `$this->received['allowed_unassigned']` is true. This pins the `:29` change.
  - Without the key, it is 404 `wcpos_report_not_found`.
  - Also extend the existing unrestricted assertion at `:202-203` with `assertTrue( $this->received['allowed_unassigned'] )`.
- `Test_Provenance_Health::test_report_unassigned_scope_includes_orders_without_a_store`: three orders on one unknown session: no `_pos_store`, `_pos_store` `'0'`, and `_pos_store` 2. `report( array(), array(), array( 1, null ) )` counts 2 orders for that session. `array( 1 )` counts 0.

### Tests to delete
None. The existing tests pin the key-absent behaviour, which is unchanged.

## Budget
- Non-test: `Store_Reach.php` about +40, four list sites about −8/+4, `Provenance_Health` +1, `Report_Scope_Resolver` about +5, docblocks and `CONTEXT.md` 5. **Ceiling: 120 changed lines**, including the lint budget.
- Tests: about 90 in `Test_Store_Reach`, plus about 7 × 35 for the wiring tests.
- **Total diff ceiling: 520 lines.**
- "If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget."

## Verification
- `php -l` on every changed file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <changed PHP files>`.
- The orchestrator then runs these in wp-env, **one file per call**: `tests/includes/Services/Test_Store_Reach.php`, `Test_Provenance_Health.php`, and in `tests/includes/API/V2/`: `Test_Registers_Controller.php`, `Test_Sessions_Controller.php`, `Test_Closures_Controller.php`, `Test_Records_Controller.php`, `Test_Closure_Receipts.php`, `Test_Reports_Controller.php`.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again. Make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
