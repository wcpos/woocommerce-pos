# Spec: Pro answers Store Reach with the user's authorized stores

Read on Free `c42191d3` (origin/next; Store Reach merged as wcpos/woocommerce-pos#2192 at `c2adda73`) and Pro `808198b` (origin/next; includes #634, #635 and #636). This spec covers the Pro half of D2 in `verdict-till.md`.

**Lane:** a Pro PR. Branch from Pro `origin/next` and target `next`. **It lands AFTER the Free PR in `spec-store-reach-unassigned.md` has merged into Free `next`.** That PR adds the `'unassigned'` key this adapter answers, and until it merges, the unassigned tests below fail. Pro's PHPUnit suite mounts Free `next` at the vendor path (`vendor/wcpos/woocommerce-pos`; see Pro `tests/bootstrap.php:166-167`), so the tests below run against the merged Store Reach module.

## Goal
Pro hooks `Store_Reach::FILTER` (`woocommerce_pos_closures_list_args`) and answers with the current user's authorized store ids. From then on, every till read (registers, sessions, closures, fiscal records, documents and reports) is limited to the stores a user may use. Pro's own store logic for registers is reduced to the write side, and the write side uses the same rule.

## Stakes
**High**: this is an authorization boundary. If it fails open, a cashier can read another store's cash figures. If it fails closed, a till cannot read its own registers, sessions or closures, and the cashier sees "not found".

These risks are accepted:
- The reach is recomputed on each `Store_Reach::for_request()` call. That costs one `WP_Query` plus per-store meta reads, a few times per request. There is no memo.
- If an earlier hook answered the seam with garbage, it is treated as "no stores". It is not logged as malformed.
- The edge case where only *private* stores exist is not handled specially (see "No published store").

## Paul's rulings (2026-10-13), binding
1. Under Pro, a till user's store reach is the set of stores they are authorized for, as returned by `Stores::get_stores()` for the current user. This applies to everyone, administrators included. There is no exemption for `manage_woocommerce_pos` holders.
2. Administrators see unassigned rows. The adapter adds `'unassigned' => true` **only for `manage_options` holders**. Free's Store Reach then also admits rows with no store, NULL or 0 (see the Free spec's decision on 0). Cashiers and managers never see unassigned rows.

## What "authorized" means today (read before coding)
- `Services/Stores.php:132-166` `get_stores()` runs a `WP_Query` over `wcpos_store` with default statuses. It keeps only the stores for which `current_user_is_authorized()` is true (`:155`) and returns them as `Store` objects.
  - **If the query finds no store posts, it returns its `$stores` argument unchanged (`:145-147`).** Through the `woocommerce_pos_get_stores` filter, that argument is Free's default store, which has id 0 (`Free includes/wcpos-store-functions.php:33-36`; Pro `Services/Cloud_Print_Per_Outlet.php:63-66` says the same).
  - A direct call with no arguments returns `array()`.
- `current_user_is_authorized()` (`Stores.php:232-254`) returns true when the store is a valid store and either:
  - its `authorized_users` meta (stored as `_authorized_users`, an array of `absint` user ids, `API/V1/Stores.php:408-410`) is **empty, which authorizes everyone** (`:248-251`), or
  - the meta contains the current user id (`:253`).
- `is_valid_store_id()` (`:264-275`) means a `wcpos_store` post whose status is `publish` (`:272`). The test is the same for administrators and cashiers, because no capability is consulted.
- The POS store list (`GET wcpos/v1/stores`, view context) goes through Free `API/V1/Stores.php:77` `wcpos_get_stores()`, which is this same set. The admin edit context (`Pro API/V1/Stores.php:105-145`) lists every published store, but only to managers, for configuration. It is not a reach.
- `StoresService::instance()` is constructed in `Init::init_common()` only when the licence is activated (`Init.php:159-163`). `Registers` is always constructed (`Init.php:172`), and `Stores::instance()` registers its hooks on first call (`Stores.php:38-66`). The adapter therefore keeps working when a licence lapses, and a lapse never widens reach.

**An unassigned user:** Pro stores exist, every one of them has a non-empty `authorized_users` list, and the user is in none of them. That user gets `array( 'store_id' => array() )`. Store Reach treats this as a valid, empty restriction:
- every list comes back empty (`'1=0'`, `Register_Store.php:168`);
- every named read is a 404 that cannot be told apart from an absent record;
- every report is a 404 `wcpos_report_not_found` (`Report_Scope_Resolver.php:29-31`);
- the export is a CSV with a header and no rows.

In practice this is rare: one store with an empty list puts that store in everyone's reach.

**No published store** (a Pro site that never created a store, or drafted or trashed all of them): `get_stores()` falls back to the default store (id 0). Rows written under it carry `store_id` NULL (registers, `Register_Store.php:104`) or 0 (sessions and closures; the client sends `store.id` = 0). Store Reach cannot express "store 0 only": it rejects 0 as malformed (`Store_Reach.php:61-62`) and never admits NULL under a restriction (`:92-94`). So the adapter **leaves the answer unrestricted**, which is Free's single-store reach. This is the literal reading of the ruling: the user is authorized for the default store, and the default store's rows have no store id.

## Out of scope (do not add)
- No new filter, option, table, column, env var, feature flag, cache or static memo.
- No retries, locking, fallbacks or validation layers beyond the lines below.
- No change to any Free file. The Free docblocks listed at the end go in a separate Free PR.
- No change to write replays (`POST /sessions`, `/closures`, `/movements`; Needs Paul #3).
- No change to the session store source (D3) or the variance threshold (D4).
- No client change. No renaming of the seam.
- No new test helpers outside the new test file. No plan or notes files.

## Exact changes, one file: Pro `includes/Registers.php` (231 lines)
1. **Imports:** add `use WCPOS\WooCommercePOS\Services\Store_Reach;`.
2. **Constructor (`:24`):** replace the `woocommerce_pos_registers_list_args` hook with
   `add_filter( Store_Reach::FILTER, array( $this, 'store_reach' ) );`
   Use the constant so that a later rename of the seam (Needs Paul #2) follows automatically. Pro vendors Free at build time, so the class always exists.
3. **Delete `list_args()` (`:149-170`, 22 lines).**
   - It read the till's claimed `X-WCPOS-Store` header. Without a header it returned the args unscoped (`:161-164`), and it exempted `manage_woocommerce_pos` with `context=edit` (`:157-159`). That made it a browsing scope, and its exemption contradicts the ruling.
   - Store Reach now narrows every register list after the filter (`Free Registers_Controller.php:119,130`). A till that wants one store's registers passes `store_id`, which Free validates (`:112-117`) and Store Reach intersects with reach (`Store_Reach.php:110-117`).
   - Keep `header_scope()`, because `upsert_fields()` still uses it.
4. **Add `store_reach()`:**
   ```php
   /**
    * Answer Free's Store Reach seam: a till user reaches the stores they are authorized for.
    *
    * Administrators are not exempt (ruling 2026-10-13). An empty list is an answer: the
    * user reaches no store. With no published store, Stores::get_stores() falls back to
    * Free's default store, whose rows carry no store id, so reach stays unrestricted.
    * An earlier restriction is only ever narrowed.
    *
    * @param mixed $args Seam answer so far; Free passes an empty array.
    * @return mixed
    */
   public function store_reach( $args ) {
   	if ( ! is_array( $args ) ) {
   		return $args; // Store_Reach denies an unreadable answer; never repair it here.
   	}
   	$ids = array();
   	foreach ( Stores::instance()->get_stores() as $store ) {
   		if ( is_object( $store ) && 0 < (int) $store->get_id() ) {
   			$ids[] = (int) $store->get_id();
   		}
   	}
   	if ( ! $ids && ! Stores::get_select_options() ) {
   		return $args;
   	}
   	if ( array_key_exists( 'store_id', $args ) ) {
   		$ids = array_intersect( $ids, array_map( 'intval', (array) $args['store_id'] ) );
   	}
   	$args['store_id'] = array_values( $ids );
   	// Administrators also reach rows with no store (ruling 2026-10-13); never re-grant an earlier refusal.
   	if ( current_user_can( 'manage_options' ) && true === ( $args['unassigned'] ?? true ) ) {
   		$args['unassigned'] = true;
   	} else {
   		unset( $args['unassigned'] );
   	}
   	return $args;
   }
   ```
   The docblock adds one line: "`manage_options` holders also get `'unassigned' => true`, so they reach registers, sessions, closures and records that belong to no store." Use `manage_options`, not `manage_woocommerce_pos`: managers must not see unassigned rows.
   `Stores::get_select_options()` (`Stores.php:177-204`) lists published stores regardless of user. It runs only when the user's list is empty, to tell "no store exists" apart from "authorized for none".
5. **Make the write side use the same rule.** Keep `upsert_fields` and `update_fields` as the register-write seam (Free applies them at `Register_Store.php:97` and `Registers_Controller.php:170`).
   - `upsert_fields()` `:96`: replace `Stores::instance()->is_valid_store_id( $store_id )` with `Stores::instance()->current_user_is_authorized( (int) $store_id )`. A manager's body store outside their reach then takes the existing fallback to the header or NULL, the same path an invalid store takes (pinned by `test_registration_invalid_body_store_falls_back_to_header_or_null`).
   - `move_error()` `:222-225`: replace `is_valid_store_id( $store_id )` with `current_user_is_authorized( (int) $store_id )`. Change the message to `__( 'The target store must be a published store you are authorized for.', 'woocommerce-pos-pro' )`. Keep the code `wcpos_register_move_refused` and status 400.
   - `refuse_store_move()` `:143`. Because this check goes through `admits()`, it follows the unassigned key automatically. An administrator's PATCH of an Unassigned register reaches `move_error()` and then Free's update. A manager's falls through to Free's 404. Replace `! ( new Register_Store() )->exists( $id )` with a row read plus a reach check, so that a register outside reach falls through to Free's 404 instead of answering 400 or 409. Today a PATCH with a bad target store on another store's register returns 400 `wcpos_register_move_refused`, while an absent id returns 404, so the existence of the register leaks. The new code:
     ```php
     $row = ( new Register_Store() )->get( $id );
     // Out of reach reads as absent: leave it to Free's 404, never answer a move refusal.
     if ( ! array_key_exists( 'store_id', $body ) || ! $row || ! Store_Reach::for_request( $request )->admits( $row['store_id'] ) ) {
     	return $response;
     }
     ```
6. **Docblocks:** update the class docblock (`:17`) to "Store binding for registers, and the store reach every till read is limited to." Remove the stale "Free supports only one store_id" comment along with `list_args()`.

**What the `X-WCPOS-Store` header still does after this PR:** one thing only. On `POST /wcpos/v2/registers`, a manager who sends no body `store_id` gets the new register bound to the till's claimed store, if they are authorized for it (`upsert_fields()` `:100-103`). The header no longer scopes or authorizes any read. Reads are limited by reach alone.

## Rulings to preserve
- A named record out of reach answers exactly like an absent one: same status, same `code`. Pinned by the new tests below and by Free's `Test_Registers_Controller::test_register_item_outside_store_reach_reads_as_absent`, `Test_Sessions_Controller::test_session_read_outside_store_reach_reads_as_absent` and `Test_Reports_Controller::test_report_store_outside_the_caller_scope_is_not_found_and_never_runs_callback`.
- Moves are refused for an unknown or unpublished target store (400) and while a session is open or counting (409). Pinned by Pro `Test_Registers::test_move_unknown_store_is_refused`, `test_move_unpublished_store_is_refused` and `test_move_session_status_controls_refusal`.
- A cashier cannot PATCH (403). Pinned by `test_move_cashier_is_forbidden_even_for_invalid_store`.
- Registration binding by header and by body. Pinned by `Test_Registers.php:50-94`.

## Tests

### New file: Pro `tests/includes/API/Test_Till_Store_Reach.php`
Namespace `WCPOS\WooCommercePOSPro\Tests\API`; extends `WCPOS_REST_Unit_Test_Case`.

**Shared fixture**
- `require_once dirname( __DIR__, 3 ) . '/vendor/wcpos/woocommerce-pos/tests/includes/Services/Closure_Test_Fixture.php';`
- `use Closure_Test_Fixture { tearDown as private tear_down_closures; }`, the same pattern as Free `Test_Reports_Controller.php:24-26`.

**setUp**
- Call `Stores::instance()`.
- Create store A and store B with `StoreHelper::create_store()`.
- Create users:
  - a cashier: a `subscriber` with the caps `access_woocommerce_pos` and `view_woocommerce_pos_reports`;
  - an outsider with the same caps;
  - a fourth user who is listed on B only.
- Set `_authorized_users`:
  - A → `[ cashier, $this->user ]` (the admin);
  - B → `[ fourth user ]`.
- Build one till per store with a private helper `till( int $store_id ): array`:
  1. Create a register with `( new Register_Store() )->create( array( 'name' => …, 'store_id' => $store_id ) )` and push its id onto `$this->closure_registers`.
  2. Call `$this->closure_session( $register_id )`.
  3. Set the session's `store_id` to `$store_id` with `$wpdb->update` on `( new Register_Session_Store() )->table_name()`, because the trait hard-codes 456.
  4. Call `( new Closure_Store() )->create( $this->closure_fields( $session ) )`. This closes the session and copies its store.
  5. Return the register, the session and the closure.
- Register a server report `reach_probe` through `woocommerce_pos_reports` (scopes `range` and `session`, capability `view_woocommerce_pos_reports`, callback returning `( new Receipt_Preview_Fixture_Loader() )->build( 'report' )`). Reset `Reports_Registry::$reports` by reflection, as Free `Test_Reports_Controller.php:125-130` does. If the document fails validation, copy the registration shape from `Test_Reports_Controller.php:59-104`.

**tearDown**
1. Call `tear_down_closures()`.
2. Because `Closure_Store::create()` COMMITs the test transaction, the store posts and users created in setUp are committed too. Delete both stores with `wp_delete_post( $id, true )` and the three users with `wp_delete_user()`.
3. Run `$wpdb->query( 'COMMIT' )`.
4. Reset the report registry.

Leaked published `wcpos_store` posts would break every later Pro test that assumes the default store.

**Route literals:** every dispatched route string must start with a literal `'/wcpos/v2/...'` so that the lane-coverage scanner classifies it as v2 (`tests/lane-coverage/README.md`). Use `assertSame( expected, actual )` throughout. Each test is Arrange / Act / Assert.

**Test methods**
1. `test_closure_outside_reach_reads_as_absent_and_inside_reads`: as the cashier, GET `/wcpos/v2/closures/{B closure}` returns the same status and `code` as `/wcpos/v2/closures/{wp_generate_uuid4()}` (404 `wcpos_closure_not_found`). `/wcpos/v2/closures/{A closure}` returns 200 with the A id.
2. `test_closure_list_holds_only_reachable_stores`: as the cashier, GET `/wcpos/v2/closures` contains the A closure id and does not contain the B closure id.
3. `test_session_outside_reach_reads_as_absent_and_inside_reads`: the same pattern for `/wcpos/v2/sessions/{id}` and `/wcpos/v2/sessions/{id}/movements`. B and absent give the same status and code (404 `wcpos_session_not_found`). A gives 200.
4. `test_register_outside_reach_reads_as_absent_and_inside_reads`: the same pattern for `/wcpos/v2/registers/{id}` (404 `wcpos_register_not_found`). Also, GET `/wcpos/v2/registers?status=all` contains A's register and not B's.
5. `test_report_outside_reach_reads_as_absent_and_inside_runs`: as the cashier, GET `/wcpos/v2/reports/reach_probe` with `mode=session`, `session_id`, `register_id`:
   - store B returns the same status and code as an absent session uuid (404 `wcpos_report_session_not_found`);
   - store A returns 200;
   - `mode=range`, `from=to=today`, `store_id=B` returns 404 `wcpos_report_not_found`.
6. `test_administrator_reach_is_their_authorized_stores_plus_unassigned`:
   - Arrange: a register created with no store (`Register_Store::create( array( 'name' => 'Unassigned' ) )`, pushed onto `$this->closure_registers`).
   - As `$this->user` (the administrator, listed on A only), `/wcpos/v2/closures/{B closure}` and `/wcpos/v2/registers/{B register}` return 404 with the absent codes, the A records return 200, and `/wcpos/v2/registers/{unassigned register}` returns 200.
   - This pins both rulings: administrators get no store exemption, but they do reach unassigned rows.
6b. `test_cashier_and_manager_never_reach_unassigned_rows`: the same unassigned register.
   - As the cashier, `/wcpos/v2/registers/{unassigned register}` returns the same status and code as a random uuid (404 `wcpos_register_not_found`).
   - Repeat as a manager: a `subscriber` with `access_woocommerce_pos` and `manage_woocommerce_pos` and **no** `manage_options`, listed on A. Same result.
7. `test_user_authorized_for_no_store_reaches_nothing`: as the outsider:
   - `/wcpos/v2/closures` returns 200 and `array()`;
   - `/wcpos/v2/registers/{A register}` returns 404;
   - the session-mode report on A returns 404 `wcpos_report_not_found`.
8. `test_no_published_store_keeps_single_store_reach`: `wp_trash_post()` both stores. As the outsider, `/wcpos/v2/registers/{B register}` returns 200 (unrestricted).
9. `test_adapter_never_widens_an_earlier_restriction`:
   - As the cashier, `apply_filters( Store_Reach::FILTER, array( 'store_id' => array( $store_b ) ) )` returns `array( 'store_id' => array() )`, and `apply_filters( Store_Reach::FILTER, 'garbage' )` returns `'garbage'`.
   - As the cashier, `apply_filters( Store_Reach::FILTER, array( 'unassigned' => true ) )` has no `unassigned` key.
   - As the administrator, `array( 'unassigned' => false )` stays without `unassigned => true`, and `array()` gains `'unassigned' => true`.

### Edit: Pro `tests/includes/Test_Registers.php` (294 lines)
- **Delete `test_list_manager_without_edit_context_is_scoped` (`:215-229`).** It pins the header browsing scope and the `context=edit` exemption that this PR removes. The new test 4 replaces it.
- **Keep `test_list_cashier_edit_context_does_not_bypass_scope` (`:231-242`).** It still passes, now through reach: the cashier is not on store A's list. Add a one-line comment saying so.
- **Add `test_move_to_store_outside_reach_is_refused`.** Arrange: register in `store_id`; `other_store_id` has `_authorized_users = [ $this->user + 1 ]`. Act: PATCH to `other_store_id`. Assert: 400 `wcpos_register_move_refused`, and the stored `store_id` is unchanged.
- **Add `test_move_of_register_outside_reach_reads_as_absent`.** Arrange: a register created directly in `other_store_id`, with `other_store_id` restricted to `[ $this->user + 1 ]`. Act: PATCH it with body `store_id` 999999999, and PATCH a random uuid with the same body. Assert: same status and code (404 `wcpos_register_not_found`). **This must fail first:** today the first PATCH answers 400.
- **Add `test_unassigned_register_can_be_assigned_by_an_administrator`.**
  - Arrange: a register created with no store (`( new Register_Store() )->create( array( 'name' => 'Front' ) )`; set `$this->register_id`).
  - Act: as `$this->user` (administrator, so `manage_options`), call `patch_store( $this->store_id )`.
  - Assert: 200, `store_id` in the response is `$this->store_id`, and the stored row matches.
  - Today this is a 404 once any store exists: Free denies NULL under a restriction. Before this PR it passes only because no adapter is hooked.
- **Add `test_unassigned_register_reads_as_absent_to_a_manager_and_a_cashier`.**
  - Arrange: the same unassigned register; a manager (`subscriber` plus `access_woocommerce_pos` and `manage_woocommerce_pos`, no `manage_options`).
  - Act and assert, as the manager: `patch_store( $this->store_id )` gives the same status and code as the same PATCH on a random uuid (404 `wcpos_register_not_found`), and the row's `store_id` is still null.
  - As a cashier (`sign_in_cashier()`): GET `/wcpos/v2/registers/{id}` gives the same 404 as a random uuid. A cashier's PATCH stays 403 at the permission check (`Free Registers_Controller.php:74`; already pinned by `test_move_cashier_is_forbidden_even_for_invalid_store`). A cashier never gets far enough to see 404 on a PATCH, so 403 is the expected answer.
- **Add `test_registration_body_store_outside_reach_falls_back_to_header_or_null`.** Arrange: `other_store_id` restricted to `[ $this->user + 1 ]`. Act: POST a register with body `store_id = other_store_id` and no header. Assert: 201 and `store_id` null.

## Budget
- **Non-test:** `includes/Registers.php` only. Ceiling **100 changed lines** (insertions plus deletions; expected net about +18), including the lint budget.
- **Total diff ceiling: 700 lines.** The new test file is at most about 420 lines and the `Test_Registers.php` edits about +110/−16.
- Stop rule: "If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget."

## Verification (from the sandbox, no Docker)
- `php -l includes/Registers.php tests/includes/API/Test_Till_Store_Reach.php tests/includes/Test_Registers.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Registers.php tests/includes/API/Test_Till_Store_Reach.php tests/includes/Test_Registers.php`, run from the Pro root.
- Afterwards, the orchestrator runs these in Pro wp-env with Free `next` mounted at the vendor path, **one file per wp-env call**:
  - `tests/includes/API/Test_Till_Store_Reach.php`
  - `tests/includes/Test_Registers.php`
  - `tests/includes/API/Test_Order_Refunds_Session_Stamp.php`, as a regression check (it uses Free's session store with Pro stores).
- Before running any of them, confirm that the mounted Free contains the unassigned key: `grep -n "unassigned" vendor/wcpos/woocommerce-pos/includes/Services/Store_Reach.php`. If it is missing, Free's PR has not merged yet. Stop, because the PRs are out of order.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again. Make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (plus the new test file).

## Consequences Paul should see before merge (not Codex work)
1. **Resolved by ruling 2 and the Free unassigned-key PR, for administrators only.** What remains, by design: once a Pro site has a published store, NULL-store and 0-store rows are invisible to cashiers and to managers without `manage_options`. In particular, a manager who creates an "Unassigned" register from the admin screen cannot see it afterwards, and an administrator has to assign it. The original finding was: **NULL-store and 0-store rows disappear for everyone once a Pro site has a published store.** This follows from the ruling plus Free's "a NULL row is never within a restricted reach". It affects:
   - registers created as "Unassigned" from the admin screen (Free `packages/settings/src/screens/registers/index.tsx:140`). That screen GETs `/wcpos/v2/registers?status=all` with no `context=edit` and no header (`:40`), so today Pro leaves it unscoped. After this PR, an Unassigned register vanishes on refresh, and PATCHing it to a store returns 404, so it can never be assigned;
   - registers, sessions and closures written in the Free era, which drop out of lists, exports and reports.

   Smallest remedy, as a separate Free PR: hide "Unassigned" on create when Pro reports stores. Legacy rows would still need a decision: an admin assign path, or accept them as unreachable.
2. **The register list on the till gets wider.** A till that sends only `X-WCPOS-Store` now lists every register in the user's reach, not just its claimed store's. Today it gets that store's registers, or all registers when no header is sent. If the client needs one store, it should pass `store_id`.
3. **D3 is still open.** A session's `store_id` still comes from the client (`Sessions_Controller.php:181-191`). Reach is only as trustworthy as that column for sessions and closures.

## Free docblocks: what they claim about Pro, and whether that holds after this PR
`verdict-till.md` D2 named five. Status on Free `c42191d3`:

| Free text | Status today | After this PR |
|---|---|---|
| `Closures_Controller.php:216-220` (old "Pro restricts a manager…") | gone; rewritten by #2192 (`:217` "within store reach") | n/a |
| `Report_Scope_Resolver.php:152-154` | gone; deleted by #2192 | n/a |
| `Sessions_Controller.php:86` → now `:87` "nothing hooks it today. Read routes are checked by Store_Reach." | true | stays true (Pro does not hook `session_row`) |
| `Records_Controller.php:121` → now `:122` "Filter authorized history scope; Pro may constrain store_id." | **false** | **stays false.** Pro never hooks `records_list_args`; reach narrows on `:124`. Fix in Free: "Nothing hooks this; Store_Reach narrows the result below." |
| `Records_Controller.php:142` | gone | n/a |

Others found in this pass:

| Free text | Status today | After this PR |
|---|---|---|
| `Store_Reach.php:14` "Pro does not yet hook this filter." | true | **becomes false.** Fix in Free: "Pro answers it with the user's authorized stores (`Registers::store_reach`)." |
| `Registers_Controller.php:118` "Filter authorized store scope for the register list." | misleading (Pro's hook was a browsing scope) | **false.** Nothing hooks it once `list_args` is deleted. Fix: "Nothing hooks this; Store_Reach narrows the result." Deleting the filter is Needs Paul #2. |
| `Report_Scope_Resolver.php:123-125` "…no way to honour a Pro scope it cannot see" | describes a scope that does not exist yet | becomes true |
| tests: `Test_Closures_Controller.php:210-212` "Pro restricts a manager to its authorized stores through `woocommerce_pos_closures_list_args`" and `Test_Reports_Controller.php:310` "Pro answers `woocommerce_pos_closures_list_args` with the caller's stores" | false | become true ("a manager" should read "every user") |
| `Registers_Controller.php:169` "Pro may authorize store reassignment"; `Register_Store.php:96` "Pro may set store_id" | true | stay true |
| `CONTEXT.md:102-103` (Store reach) | true; it names no Pro behaviour | true. Optionally add: "Under Pro, the stores the user is authorized for." |

**These three Free fixes (`Store_Reach.php:14`, `Records_Controller.php:122`, `Registers_Controller.php:118`) and `CONTEXT.md:103` are part of the Free unassigned-key PR** (`spec-store-reach-unassigned.md` §5). That PR lands first.
