# Carry the Drawer (plugin half of cashier/register map slice 6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One server act, `POST /wcpos/v2/sessions/{id}/carry`, closes an open session at the server's expected figures and opens the target register's session with that cash as its float, recording why on both rows.

**Architecture:** A new `Session_Carry` service does the act under the source register's lock-free sequence the stores already own: transition the source to `counting`, write its closure through `Closure_Store::create` (which derives the totals itself when none are reported), then open the target through `Register_Session_Store::create`. Closures gain a document key `reason` (`end_of_day` for every ordinary close, `store_switch` for a carry) and `carried_to_register_id`; sessions gain two columns, `float_source` and `carried_from_closure_id` (schema 14). The controller stays thin: validate, call the service, answer both rows through the existing blind-safe `visible_row`.

**Tech Stack:** PHP 7.4+, WordPress REST, `$wpdb`, PHPUnit through wp-env (one test file per run).

**Spec:** monorepo `packages/core/specs/2026-10-28-cashier-register-map-design.md` § 4.6 (Take the drawer with you), § 4.9, § 5 slice 6.

## Global Constraints

- Budget: ≤ 250 added production lines (tests excluded). Stop at the budget.
- `Sync_Api::SCHEMA_VERSION` becomes `'14'`; new columns are added with `add_column_if_missing` exactly as schema 13 added `counting_by` (`includes/Activator.php` near line 271).
- No new env vars, no new options, no feature flags, no retries.
- Every write route needs `manage_woocommerce_pos_cash` (the controller's `permissions_check` already does this for POST).
- Blind cashiers never receive figures: both rows in the carry response pass through the controller's `visible_row` / the closures controller's stripping (`counted`, `expected`, `variance`, floats).
- The client mints every id (`closure_id`, `session_id` for the new session) so the outbox can replay the act: a replay returns the existing rows and writes nothing.
- phpcs clean (`composer run lint` or the repo's phpcs config), `@throws` tags on methods that throw.

## Review Focus

1. The target register already has an open or counting session at the moment of the open (a race after validation): the source is then closed and no target opened. The service answers the open's `WP_Error` (409 `wcpos_session_already_open`) with the written closure in its error data under `closure`; the client treats the carry as failed and the cashier opens Y's register by hand with the carried cash. Pinned by a test that opens the target between validation and the open through the `wcpos_session_row` filter is NOT possible (that filter runs only on directly addressed rows); pin it by opening the target before the carry instead (ordinary 409) and by a unit test of the service with the target store pre-seeded.
2. A `counting` source session: this device's own count is in progress; the carry refuses 409 `wcpos_session_transition_refused` (the client guard says _Finish or abandon the count first_).
3. Source and target the same register: 400 `rest_invalid_param` on `to_register_id`.
4. A target out of the caller's store reach: 400 `rest_invalid_param` on `to_register_id` (never a 404 that names it).
5. An old closure document with no `reason` key reads as `end_of_day` everywhere (`hydrate`), so the client never sees `null`.

---

### Task 1: Sessions schema 14 — `float_source` and `carried_from_closure_id`

**Files:**
- Modify: `includes/Services/Register_Session_Store.php` (`COLUMNS`, `schema_sql`)
- Modify: `includes/Sync/Api.php` (`SCHEMA_VERSION = '14'`)
- Modify: `includes/Activator.php` (the schema-13 block near line 271: add a `< '14'` block with two `add_column_if_missing` calls)
- Test: `tests/includes/Test_Activator.php` (the column table near line 1514), `tests/includes/Services/Test_Register_Session_Store.php`

**Interfaces:**
- Produces: session rows carry `float_source` (`VARCHAR(16) NULL`; `'carried'` when opened by a carry, `NULL` for an ordinary open) and `carried_from_closure_id` (`CHAR(36) NULL`).

- [ ] **Step 1: Write the failing tests**

In `Test_Activator.php`, `test_fresh_session_schema_carries_closure_columns_and_indexes` (its column table near line 1514): add `'float_source' => array( 'varchar', 'YES' )` and `'carried_from_closure_id' => array( 'char', 'YES' )` beside `counting_by` (a fresh install carries them with zero alterations). Add one new test beside it, `test_schema_thirteen_upgrade_adds_the_carry_columns`, in the same shape: install the store, `ALTER TABLE … DROP COLUMN float_source, DROP COLUMN carried_from_closure_id` (the DDL survives the test transaction, so restore both columns in `finally` with `ALTER TABLE … ADD COLUMN`), set the option to `'13'`, run `install_sync_schema()`, assert both columns exist and the option reads `'14'`. There is no schema-13 upgrade test to copy; the fresh-schema test is the shape. In `Test_Register_Session_Store.php` add `test_create_persists_float_source_and_carried_from_closure_id`: `create()` with `float_source => 'carried'` and `carried_from_closure_id => <uuid>` reads back both; an ordinary `create()` reads back `null` for both.

- [ ] **Step 2: Run them to verify they fail**

Run (from the plugin root, through wp-env; one file per run): `npm run test:php -- tests/includes/Test_Activator.php` then `… tests/includes/Services/Test_Register_Session_Store.php`. Expected: FAIL on the missing columns.

- [ ] **Step 3: Implement**

`COLUMNS` gains `float_source, carried_from_closure_id` after `counting_by`; `schema_sql` gains the two column lines after `counting_by BIGINT NULL`; `Activator` gains the `'14'` block; `Api::SCHEMA_VERSION = '14'`.

- [ ] **Step 4: Run both files to verify they pass**

- [ ] **Step 5: Commit** `feat(sessions): float_source and carried_from_closure_id on the session row, schema 14`

### Task 2: Closures record their reason and the register they were carried to

**Files:**
- Modify: `includes/Services/Closure_Store.php` (`ROW`, `hydrate`, `create`)
- Test: `tests/includes/Services/Test_Closure_Store.php`

**Interfaces:**
- Produces: every closure row carries `reason` (`'end_of_day' | 'store_switch'`) and `carried_to_register_id` (`string | null`). `create( $fields )` writes `reason` from `$fields['reason'] ?? 'end_of_day'` and `carried_to_register_id` from `$fields['carried_to_register_id'] ?? null`. `create()` derives the four totals (`period_*`, `perpetual_*`) when `$fields` lacks them, recording a `total_mismatch` finding only for a total that WAS reported and differs.

- [ ] **Step 1: Write the failing tests**

In `Test_Closure_Store.php`: `test_create_records_end_of_day_by_default_and_hydrates_an_old_document_without_reason` (create an ordinary closure → `reason === 'end_of_day'`, `carried_to_register_id === null`; then rewrite that session row's `closure` JSON without the `reason` key through `$wpdb->update` and read it back through `get()` → `'end_of_day'`); `test_create_derives_totals_when_none_are_reported` (a closure created with the four total keys absent gets the derived totals and `findings === null`).

- [ ] **Step 2: Run to verify they fail**

`npm run test:php -- tests/includes/Services/Test_Closure_Store.php`

- [ ] **Step 3: Implement**

`ROW` gains `'reason' => null` and `'carried_to_register_id' => null` (document keys). `hydrate()` sets `$row['reason'] = $row['reason'] ?? 'end_of_day'` after the loop. In `create()`, before the totals loop: `$fields['reason'] = $fields['reason'] ?? 'end_of_day';` and the mismatch check becomes `if ( isset( $fields[ $key ] ) && '0.0000' !== self::sum( … ) )`. The closures controller does not accept `reason` from the client (only a carry writes `store_switch`).

- [ ] **Step 4: Run to verify pass; also run `tests/includes/API/V2/Test_Closures_Controller.php` (one run) to confirm the response shape only gained keys.**

- [ ] **Step 5: Commit** `feat(closures): a closure records its reason; totals derive when unreported`

### Task 3: The carry service

**Files:**
- Create: `includes/Services/Session_Carry.php`
- Test: `tests/includes/Services/Test_Session_Carry.php` (new; copy the fixture style of `Test_Register_Session_Store.php` / `Closure_Test_Fixture.php`)

**Interfaces:**
- Consumes: `Register_Session_Store::{get,list,transition,create}`, `Closure_Store::{get,create,last_number}`, `Session_Figures::for_session( $row )->expected()`, `Register_Store::get`.
- Produces:

```php
final class Session_Carry {
	/**
	 * @param array  $session      The source session row (status must be 'open').
	 * @param array  $target       The target register row (already validated by the caller: in reach, not the source register).
	 * @param array  $ids          array( 'closure_id' => uuid, 'session_id' => uuid ) minted by the client.
	 * @param string $at_gmt       'Y-m-d H:i:s' GMT: the closure's closed_at and the new session's opened_at.
	 * @return array{closure: array, session: array}|\WP_Error
	 * @throws \RuntimeException On database failure (the controller maps it to wcpos_session_write_failed).
	 */
	public function carry( array $session, array $target, array $ids, string $at_gmt );
}
```

Behaviour, in order:
1. Replay: if `Closure_Store::get( $ids['closure_id'] )` and `Register_Session_Store::get( $ids['session_id'] )` both exist, return them (log through each store's `log_replay`).
2. `'open' !== $session['status']` → `WP_Error( 'wcpos_session_transition_refused', …, array( 'status' => 409 ) )`.
3. Target has a not-closed session (`list( array( 'register_id' => $target['id'], 'not_closed' => true ) )`) → `WP_Error( 'wcpos_session_already_open', …, array( 'status' => 409, 'session_id' => <that id> ) )`.
4. `$expected = Session_Figures::for_session( $session )->expected()`.
5. Transition `open → counting` with `counting_started_at_gmt => $at_gmt`, `counting_by => get_current_user_id()`; re-read the row.
6. `Closure_Store::create( array( 'id' => closure_id, 'session_id', 'business_day' => $session['business_day'], 'software_version' => 'woocommerce-pos/' . \WCPOS\WooCommercePOS\VERSION . ' carry', 'breakdowns' => array(), 'number' => last_number( register ) + 1, 'unsynced_count' => 0, 'unsynced_total' => '0.0000', 'first_sale_counter' => null, 'last_sale_counter' => null, 'opened_at_gmt' => $session['opened_at_gmt'], 'closed_at_gmt' => $at_gmt, 'printed_at_gmt' => null, 'till_expected' => $expected, 'counted' => $expected, 'reason' => 'store_switch', 'carried_to_register_id' => $target['id'] ) )` — no totals (Task 2 derives them). A `WP_Error` returns as is.
7. `Register_Session_Store::create( array( 'id' => session_id, 'business_day' => $session['business_day'], 'register_id' => $target['id'], 'store_id' => $target['store_id'], 'opened_at_gmt' => $at_gmt, 'opened_by' => get_current_user_id(), 'counted_float' => $expected['cash'] ?? '0.0000', 'expected_float' => $expected['cash'] ?? '0.0000', 'float_source' => 'carried', 'carried_from_closure_id' => closure_id ) )`. A `WP_Error` here (the race in Review Focus 1) is returned with `'closure' => $closure` added to its data.
8. Return `array( 'closure' => $closure, 'session' => $opened )`.

- [ ] **Step 1: Write the failing tests** (`Test_Session_Carry.php`): happy path (an open session with a cash sale of 100 and a float of 0 → closure `counted === expected === array( 'cash' => '100.0000' )`, `variance` zero, `reason === 'store_switch'`, `carried_to_register_id === target`, `number === 1`; the new session `float_source === 'carried'`, `carried_from_closure_id === closure id`, `counted_float === '100.0000'`, `opening_variance === '0.0000'`, `status === 'open'`; the source `status === 'closed'`, `closure_id` set); replay returns the same two rows and writes nothing (row counts unchanged); a counting source → 409 `wcpos_session_transition_refused`; a target with an open session → 409 `wcpos_session_already_open` naming it, and the source stays `open`.

- [ ] **Step 2: Run to verify fail** — `npm run test:php -- tests/includes/Services/Test_Session_Carry.php`

- [ ] **Step 3: Implement `Session_Carry` as specified.**

- [ ] **Step 4: Run to verify pass.**

- [ ] **Step 5: Commit** `feat(sessions): Session_Carry closes at expected and opens the target with the cash as its float`

### Task 4: The route

**Files:**
- Modify: `includes/API/V2/Sessions_Controller.php` (`register_routes`, `dispatch`, new `carry()` private method)
- Test: `tests/includes/API/V2/Test_Sessions_Controller.php`

**Interfaces:**
- Produces: `POST /wcpos/v2/sessions/{id}/carry` with body `{ to_register_id, closure_id, session_id, at }` → 201 `{ closure, session }` (both through the blind-safe strip: the session through `visible_row`; the closure through the same key list the closures controller's `visible()` uses — reuse by making `Closures_Controller::visible` a `public static` if it is not, or duplicate the `unset` line, whichever is fewer lines), or the service's `WP_Error`.

Validation in `carry()`: `to_register_id`, `closure_id`, `session_id` are uuids (400 naming the field); `at` passes `Pos_Order_Audit::is_valid_till_value( '_wcpos_sale_time', … )` (400 `at`); target register exists and `Store_Reach::for_request( $request )->admits( $target['store_id'] )` (400 `to_register_id`); `$target['id'] !== $row['register_id']` (400 `to_register_id`). Then `( new Session_Carry() )->carry( $row, $target, array( 'closure_id' => …, 'session_id' => … ), gmdate( 'Y-m-d H:i:s', strtotime( $request['at'] ) ) )`.

- [ ] **Step 1: Write the failing tests** in `Test_Sessions_Controller.php`: `test_carry_closes_the_source_and_opens_the_target` (two registers in the same store; a session with a cash sale; the response 201 carries `closure.reason === 'store_switch'` and `session.float_source === 'carried'`; a second identical POST replays 201 with the same ids); `test_carry_refuses_the_same_register_an_unknown_target_and_a_target_out_of_reach` (400 on `to_register_id` for each; the out-of-reach case uses the reach fixture of `test_session_opening_on_a_register_out_of_reach_is_refused_as_unknown`); `test_carry_refuses_a_counting_source_and_an_open_target` (409 codes); `test_blind_carry_response_omits_figures` (a `manage_woocommerce_pos_cash` user without `view_woocommerce_pos_reports` gets neither `counted`, `expected`, `variance` nor `counted_float`/`expected_float`).

- [ ] **Step 2: Run to verify fail** — `npm run test:php -- tests/includes/API/V2/Test_Sessions_Controller.php`

- [ ] **Step 3: Implement the route and `carry()`.**

- [ ] **Step 4: Run to verify pass; run phpcs on every changed file.**

- [ ] **Step 5: Commit** `feat(sessions): POST /sessions/{id}/carry — take the drawer to another register`
