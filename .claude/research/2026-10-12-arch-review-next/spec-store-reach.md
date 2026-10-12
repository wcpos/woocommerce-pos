# Spec — Store Reach: one check for every till read (Free, `next` lane)

## Goal
Collapse the four separate readings of the store-authorization seam into one `Store_Reach` module, and route every till **read** through it: registers, till sessions, closures, fiscal records, register documents and reports. A named record out of reach must answer exactly as an absent one, and a collection must be narrowed inside its SQL.

## Stakes
Tier **High** (authorization). If this fails in the deny direction, every merchant's till reads 404. If it fails in the admit direction, a scoped manager reads another store's fiscal figures. In production the change is **inert until an extension hooks `woocommerce_pos_closures_list_args`**, and Pro hooks nothing on it today. So the live risk is the deny direction, and every existing test pins it by running without a filter. Accepted risk: write routes (POST create and replay, status, approve, movements) are **not** reach-checked in this change.

## Out of scope — do not add
No new filters or hooks, and no renaming of `woocommerce_pos_closures_list_args`. No changes to write routes (`Closures_Controller` POST create and replay at `:193-208`, `Sessions_Controller::dispatch` POST branch `:76-100`, `movement_fields` `:289`). No change to how a session's `store_id` is chosen (`Sessions_Controller.php:179-189`). No caching across requests, no locking, no retries, no new options, columns or tables, no feature flags, no env vars, no plan files. Do not touch Pro. Do not touch `Sync/Store_Scope.php` or `Report_Scope_Gate.php`; they are different concepts. Do not delete `woocommerce_pos_session_row`, `woocommerce_pos_sessions_list_args` or `woocommerce_pos_records_list_args`.

## Exact changes

### 1. New `includes/Services/Store_Reach.php`
```php
namespace WCPOS\WooCommercePOS\Services;

final class Store_Reach {
	public const FILTER = 'woocommerce_pos_closures_list_args';
	/** @var int[]|null null = unrestricted */
	private $store_ids;
	/** @var bool */
	private $malformed;

	private function __construct( ?array $store_ids, bool $malformed );
	public static function for_request( \WP_REST_Request $request ): self;   // required, no default
	public function store_ids(): ?array;      // null unrestricted; int[] (possibly empty) restricted
	public function is_malformed(): bool;
	public function admits( $store_id ): bool;
	public function narrow( array $args ): array;
}
```
Behaviour. These are moved, not invented: the rules come from `Report_Scope_Resolver::allowed_store_ids()` and `row_in_scope()`, `Report_Scope_Resolver.php:149-229`, and from `Closures_Controller::scope_store_ids_are_valid()`, `:273-296`.
- `for_request()`: `$answer = apply_filters( self::FILTER, array(), $request );`. The base is **empty**, so the caller's query parameters are never honoured.
  - If `$answer` is not an array: call `Logger::warning( 'Store reach refused: ' . self::FILTER . ' did not return an array', array( 'type' => gettype( $answer ) ) )` and return a malformed reach with `store_ids = array()`.
  - If there is no `store_id` key (`array_key_exists`): return unrestricted (`null`, not malformed).
  - Otherwise `$values = is_array( $answer['store_id'] ) ? $answer['store_id'] : array( $answer['store_id'] );`. This means `null` becomes `array( null )`, which is malformed. Each value must satisfy `( is_int( $v ) && $v > 0 ) || ( is_string( $v ) && 1 === preg_match( '/^[1-9]\d{0,17}$/D', $v ) )`. On the first value that fails, log `'Store reach refused: ' . self::FILTER . ' gave an unreadable store restriction'` with `array( 'type' => gettype( $v ) )` and return malformed with `array()`. Otherwise return `array_values( array_unique( array_map( 'intval', $values ) ) )`, not malformed. An empty list is valid: it restricts to nothing.
- `admits( $store_id )`: unrestricted → `true`. Restricted → `false` when `null === $store_id`, because an unassigned row is never in a restricted reach. Otherwise `in_array( (int) $store_id, $this->store_ids, true )`. Write it exactly like that: no `<= 0` test and no truthiness test.
- `narrow( $args )`: unrestricted → return `$args` unchanged. Restricted → if `store_id` is absent, set it to `store_ids()`. Otherwise take the requested values (`is_array ? : array( v )`), drop `null` and anything whose `(int)` is not > 0, and set `$args['store_id']` to `array_values( array_intersect( $this->store_ids, $requested_ints ) )`. The result is always an **array** of ints, possibly empty.
- Class docblock: carry over the four rules, condensed from `Report_Scope_Resolver.php:150-160, 168-173, 185-195, 211-223`. (1) The seam is read from an empty base. (2) A malformed answer denies, never unscopes. (3) There is no exemption for 0 or NULL, because `store_id` is `BIGINT NULL` on the registers, sessions and closures tables. (4) A named resource out of reach answers 404 exactly as absent, unlike `Report_Scope_Gate`'s visible 403. Say that Pro does not yet hook the filter. Do **not** repeat the false claim that it does.

### 2. `includes/Services/Report_Scope_Resolver.php` (235 lines)
- In `context()`: replace `$allowed = self::allowed_store_ids( $request );` with `$reach = Store_Reach::for_request( $request ?? new \WP_REST_Request( 'GET', '/wcpos/v2/reports' ) );`. The guard at `:30` becomes `if ( array() === $reach->store_ids() )`. Every `self::row_in_scope( X, $allowed )` becomes `$reach->admits( X )` (`:38, :66, :77, :93`). `'allowed_store_ids' => $reach->store_ids()` (`:107`).
- Delete `allowed_store_ids()` and `row_in_scope()` (`:149-229`). Keep `mismatch()`. Keep the inline comments at `:36-37, :54-62, :74-76` as they are.
- Drop `use WCPOS\WooCommercePOS\Logger;` if nothing else in the file uses it.

### 3. `includes/API/V2/Closures_Controller.php` (422 lines)
- `dispatch()` single-id branch `:142-146`: `if ( ! $row || ! Store_Reach::for_request( $request )->admits( $row['store_id'] ) ) { return $this->error( 'wcpos_closure_not_found', 404 ); }`. Add a one-line comment: out of reach answers exactly as absent, before any print, recount or correction read. This runs before print, recount and `corrections_for`.
- List branch `:173-176`: after the `is_wp_error` check, add `$args = Store_Reach::for_request( $request )->narrow( $args );`. This covers both `/closures` and `/closures/last`.
- `list_args()` `:383-412`: return `$args` without `apply_filters` (delete `:411`'s filter call). Update its docblock: "Validate list filters; Store_Reach narrows them in dispatch()."
- `export()` `:227-252`: replace with
  ```php
  $reach = Store_Reach::for_request( $request );
  if ( $reach->is_malformed() ) {
  	// Store_Reach has logged the unreadable answer; an export is never urgent enough to serve unscoped.
  	return $this->error( 'wcpos_closure_export_failed', 500 );
  }
  try { $csv = ( new Closure_Csv() )->build( $store, $reach->narrow( array() ) ); } …
  ```
  Shorten the method docblock (`:214-226`) to say the export reads the whole set within store reach and ignores caller filters. Delete `scope_store_ids_are_valid()` (`:273-296`).
- `includes/Services/Closure_Csv.php:44-48`: the `$scope` param doc now reads "List args from `Store_Reach::narrow()`: only `store_id`, when restricted." Keep the "required, no default" sentence.

### 4. `includes/API/V2/Sessions_Controller.php` (370 lines)
- `read()` `:113-117`:
  ```php
  $row = $store->get( strtolower( $request['id'] ) );
  // Out of reach answers exactly as absent, before the movements or figures are read.
  if ( ! $row || ! Store_Reach::for_request( $request )->admits( $row['store_id'] ) ) {
  	return $this->error( 'wcpos_session_not_found', 404 );
  }
  ```
  This removes `woocommerce_pos_session_row` from the read path only.
- `:151`: `return new WP_REST_Response( $store->list( Store_Reach::for_request( $request )->narrow( apply_filters( 'woocommerce_pos_sessions_list_args', $args, $request ) ) ) );`
- `:86` comment only: "Filter a directly addressed session or movement row on the write routes; nothing hooks it today. Read routes are checked by Store_Reach." Leave the code at `:87` unchanged.

### 5. `includes/API/V2/Registers_Controller.php` (425 lines)
- `get_items()` `:118`: `$args = Store_Reach::for_request( $request )->narrow( apply_filters( 'woocommerce_pos_registers_list_args', $args, $request ) );`. Pro's adapter keeps running first.
- `get_health()` `:129`: wrap the same way. `:131` (global `$known_ids`) is unchanged; it is pinned.
- `get_item()` `:147-148`: `return $row && Store_Reach::for_request( $request )->admits( $row['store_id'] ) ? new WP_REST_Response( $row ) : $this->not_found();`
- `update_item()` `:160-163`: `if ( ! $row || ! Store_Reach::for_request( $request )->admits( $row['store_id'] ) ) { return $this->not_found(); }`. This runs before `validated_fields()`.

### 6. `includes/API/V2/Records_Controller.php` (192 lines)
- `get_items()` after `:122`: `$args = Store_Reach::for_request( $request )->narrow( $args );`
- `get_item()` `:142-146`: replace the `records_list_args` empty-base read with `if ( ! Store_Reach::for_request( $request )->admits( $row['store_id'] ) ) { return $this->not_found(); }` and a comment: out of reach reads as absent.

### 7. `includes/Services/Fiscal_Record_Store.php` (388 lines)
- `resolve_document()` `:262-263`: `$reach = Store_Reach::for_request( $request ?? new \WP_REST_Request( 'GET', '/wcpos/v2/closures' ) );`. In the condition, replace the `array_key_exists… intval…` clause with `! $reach->admits( $row['store_id'] )`. Keep the clause order: row, then reach, then the X-report status check. This fixes D5.

### 8. Array `store_id` in two list queries
- `includes/Services/Register_Store.php:165-167`: if `is_array( $args['store_id'] )`, use `$ids = array_map( 'intval', … )` and `$ids ? 'store_id IN (' . implode( ',', $ids ) . ')' : '1=0'`. Otherwise keep the existing NULL and `%d` branches.
- `includes/Services/Register_Session_Store.php:96-101`: the same array branch for `store_id`, checked before the existing `'all' !==` / NULL / `%s` branch.

### 9. `CONTEXT.md`
Append a `### Till` section after `### Sync`, containing exactly the six entries (Register, Till session, Closure, Business day, Correction, Store reach) quoted in `verdict-till.md` § 1 "ADR / CONTEXT.md", without the `>` quote markers.

## Rulings to preserve verbatim (pinning test in brackets)
1. The seam is read from an empty base; the caller's filters are ignored by the export. [`Test_Closures_Controller::test_export_ignores_caller_supplied_list_filters`]
2. A non-array answer denies: export answers 500 `wcpos_closure_export_failed`, reports answer 404. [`test_export_refuses_a_non_array_scope_instead_of_serving_everything`; `Test_Reports_Controller::test_report_malformed_scope_filter_denies_rather_than_unscoping`]
3. An unreadable value denies, and is validated before coercion. [`test_export_refuses_a_malformed_store_scope`; `test_report_unreadable_store_restriction_denies`]
4. An empty list restricts to nothing: the export is header-only 200, reports are 404. [`test_export_empty_store_scope_exports_nothing`; `test_report_deny_all_store_scope_refuses_every_report`]
5. A NULL-store row is never admitted under a restriction. [`test_report_register_with_no_store_is_refused_under_a_scope`]
6. A session report keeps the session's stored store after its register moves, and the register name falls back to the closure label. [`test_report_session_survives_its_register_moving_to_another_store`; `test_report_session_does_not_leak_a_moved_registers_current_name`]
7. Export paging and ordering beat the filter. [`test_export_paging_and_order_beat_the_scope_filter`]
8. Health keeps global known ids. [`Test_Registers_Controller::test_health_scope_keeps_global_known_ids_and_filters_order_stores`]
9. `records_list_args` may still override the caller's store. [`Test_Records_Controller` `:33-46`]
10. A closures list under a scope that matches nothing is empty. [`Test_Closures_Controller` `:1242-1247`]
11. An out-of-scope document never increments `print_count`. [`Test_Closure_Receipts` `:446-458`]
12. Every test that registers no filter is unchanged: that is the unrestricted path.

## Tests
Conventions: extend `WCPOS_REST_Unit_Test_Case`; use `$this->wp_rest_get_request()` / `wp_rest_post_request()` / `wp_rest_patch_request()`; Arrange / Act / Assert; name tests `test_[feature]_[scenario]_[expected]`; prefer `assertSame( expected, actual )`. Remove every filter you add in a `finally` block or in `tearDown()`. To prove indistinguishability, record the response for an absent id (`wp_generate_uuid4()`), then assert the out-of-reach id returns the **same status and the same `code`**, compared against that recorded response rather than literals.

Add `tests/includes/Services/Test_Store_Reach.php`:
- `test_store_reach_without_store_id_key_is_unrestricted`: no filter, then a filter returning `array( 'foo' => 1 )`. `store_ids()` is null; `admits( null )` and `admits( 5 )` are true; `narrow( array( 'x' => 1 ) )` returns its input unchanged.
- `test_store_reach_reads_the_filter_from_an_empty_base`: the filter callback records its `$args` and `$request`. Assert `array()` and that it received the same request object.
- `test_store_reach_non_array_answer_denies_everything`: for `'nonsense', 7, null, false`: `is_malformed()` is true, `store_ids()` is `array()`, `admits( 1 )` is false.
- `test_store_reach_unreadable_restriction_denies_everything`: for `'invalid', false, null, 0, -1, true, 1.0, 1.9, '1.9', ' 1', '1e2', '007', '1 ', '+1', "1\n", array( 1 ), '1234567890123456789'` used as `store_id => array( $bad )`: malformed, and `admits()` is false for the coerced store (for example `admits( 100 )` for `'1e2'`). This is the matrix moved from `Test_Reports_Controller`.
- `test_store_reach_scalar_and_list_restrictions_read_the_same`: `7`, `'7'` and `array( 7, '7' )` all give `array( 7 )`.
- `test_store_reach_never_admits_an_unassigned_row_under_a_restriction`: reach `array( 7 )`. `admits( null )` and `admits( 0 )` are false; `admits( 7 )` and `admits( '7' )` are true.
- `test_store_reach_empty_list_is_a_restriction_not_a_fault`: `store_id => array()`. Not malformed, `store_ids() === array()`, `admits( 7 )` is false.
- `test_store_reach_narrow_intersects_requested_stores`: reach `array( 1, 2 )`. No `store_id` gives `array( 1, 2 )`; `3` gives `array()`; `2` gives `array( 2 )`; `array( 2, 3 )` gives `array( 2 )`; `null` gives `array()`; `-1` gives `array()`.

Add one wiring test per route. Each one hooks `Store_Reach::FILTER` to return `store_id => array( 789 )`, and each fixture row is in store 456 (`Closure_Test_Fixture`):
- `Test_Closures_Controller::test_closure_item_outside_store_reach_reads_as_absent`: GET `closures/{id}`, POST `closures/{id}/print` and POST `closures/{id}/recount` (as an admin) each match the absent-id response. `print_count` stays 0 and no `recount` fiscal record is written.
- `Test_Closures_Controller::test_closure_list_and_last_are_narrowed_to_store_reach`: GET `closures?store_id=456` returns `array()`. GET `closures/last?register_id=…` returns `null` data with status 200, as it does for a register with no closures.
- `Test_Sessions_Controller::test_session_read_outside_store_reach_reads_as_absent`: GET `sessions/{id}` and `sessions/{id}/movements` match the absent id. The GET `sessions` list excludes the row. Create the session through `Register_Session_Store::create()` with `store_id` 456; the POST route's store sourcing is out of scope.
- `Test_Registers_Controller::test_register_item_outside_store_reach_reads_as_absent`: GET and PATCH (`name` => 'Renamed') on a store-456 register match the absent id, and the stored name is unchanged.
- `Test_Registers_Controller::test_register_list_is_narrowed_to_store_reach`: registers in 456 and 789. GET `registers?status=all` returns only the store-789 id.
- `Test_Records_Controller::test_record_item_outside_store_reach_reads_as_absent`: a record in store 12 under reach `array( 13 )` matches the absent id. A record with NULL store under `store_id => array( 'invalid' )` also matches the absent id. The list is narrowed.
- `Test_Closure_Receipts::test_document_unreadable_store_restriction_denies_an_unassigned_closure` (D5, write it first; it must fail before change 7): set the closure's `store_id` to NULL via `$wpdb->update`; the filter returns `store_id => array( 'invalid' )`. `document( 'closure:' . $id )` matches `document( 'closure:' . wp_generate_uuid4() )`.

Shrink these tests, because their matrix now lives in `Test_Store_Reach`:
- `Test_Reports_Controller::test_report_unreadable_store_restriction_denies`: keep only the cases `array( 'invalid', null )`, `array( '1e2', 100 )` and `array( "1\n", 1 )`. Keep the docblock's paragraph on pairing each value with a store that a lax guard would admit.
- `Test_Closures_Controller::test_export_refuses_a_malformed_store_scope`: keep only `'invalid'`, `null` and `true`, along with their inline comments (the `array_key_exists()`-over-`isset()` comment stays on `null`).
No other test is deleted.

## Budget
From `wc -l`: the touched regions are `Report_Scope_Resolver.php:149-229` (81, deleted), `Closures_Controller.php:214-296` (83) and `:383-412` (30), `Sessions_Controller.php:106-152` (47), `Registers_Controller.php:100-175` (76), `Records_Controller.php:121-148` (28), `Fiscal_Record_Store.php:262-263`, plus two `list()` predicates and `CONTEXT.md`.
- **Non-test ceiling:** at most 300 inserted lines in `includes/` (new class about 130 with docblocks; wiring about 60; lint budget +110), with net `includes/` change at most +120.
- **Total-diff ceiling:** at most 1,100 lines of insertions plus deletions across all files, tests and `CONTEXT.md` included.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (from the sandbox, no Docker)
- `php -l` on every changed or added PHP file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Store_Reach.php includes/Services/Report_Scope_Resolver.php includes/API/V2/Closures_Controller.php includes/API/V2/Sessions_Controller.php includes/API/V2/Registers_Controller.php includes/API/V2/Records_Controller.php includes/Services/Fiscal_Record_Store.php includes/Services/Register_Store.php includes/Services/Register_Session_Store.php includes/Services/Closure_Csv.php tests/includes/Services/Test_Store_Reach.php` and the edited test files.
- `git grep -n "allowed_store_ids(\|row_in_scope(\|scope_store_ids_are_valid"`. It must return nothing under `includes/`.
- Afterwards the orchestrator runs these in wp-env, **one file per call** (several files in one call runs only one): `tests/includes/Services/Test_Store_Reach.php`, `tests/includes/Services/Test_Report_Scope_Resolver.php`, `tests/includes/API/V2/Test_Reports_Controller.php`, `Test_Closures_Controller.php`, `Test_Closure_Receipts.php`, `Test_Sessions_Controller.php`, `Test_Registers_Controller.php`, `Test_Records_Controller.php`, `tests/includes/Services/Test_Register_Store.php`, `Test_Register_Session_Store.php`, `Test_Fiscal_Record_Store.php`, `Test_Register_Logging.php`. It also runs Pro's `tests/includes/Test_Registers.php` and `tests/includes/API/Test_Order_Refunds_Session_Stamp.php` against this Free tree.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (and adds the new files).
