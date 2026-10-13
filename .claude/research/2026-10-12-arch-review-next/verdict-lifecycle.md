# Verdict — lifecycle / bootstrap (Free `837e985f`, Pro `05f06c4`)

Lane check (2026-10-12): `origin/main...origin/next` = `0 458` (Free) and `0 134` (Pro). `next` is live, so every PR below targets `next`. `Payments_Sweeper` exists only on `next`, and Free `main` still declares WC 5.3, so C1 and C2 are `next`-only defects.

Pro files grepped for every Pro-impact claim: `woocommerce-pos-pro.php`, `uninstall.php`, `phpstan-constants.php`, `includes/**` (in particular `Activator.php`, `Init.php`, `Registers.php`, `Abstracts/Store.php`, `Store_Pricing.php`, `Payments/Refund_Processor.php`) and `tests/**`.

---

## 1. Plugin footprint declared in five places, seven `install()` copies (includes the till "table lifecycle" item) — VERDICT: DO-RESHAPED
Strength (explorer): Strong · Risk tier: High (DDL on money/bookkeeping tables), but the change is behaviour-identical apart from one memo fix
- `uninstall.php` must stay standalone. It runs without the plugin loaded (`uninstall.php:41-78` hardcodes the lists by design). Under Pro, a bulk delete runs both uninstall files in one request with Pro's vendored Free classes already declared, which is the duplicate-class hazard. So a production "footprint module" that `uninstall.php` loads is wrong. It would also have only one production consumer, the Activator. **The drift is a test problem.** The source of truth should be the code itself, discovered by the tests.
- The drift guards are hand-maintained. `Test_Uninstall.php:296-307` checks only `Health::required_tables()`, which lists the 3 sync tables (`Sync/Health.php:37-45`). None of the 5 till tables is guarded. `Test_Uninstall.php:313-327` names each cron constant by hand, and that is how `Payments_Sweeper::HOOK` (`Payments/Contract/Payments_Sweeper.php:17`) slipped through (C2).
- The till-store install list is written twice: `Activator.php:226-231` and `:722-727`.
- The five till stores carry copy-pasted storage plumbing: `table_name`, `install` and `ensure_installed`, plus a static memo. This applies to `Register_Store.php:17-42, 67-81`, `Register_Session_Store.php:19-29, 61-76`, `Cash_Movement_Store.php:16-26, 50-65`, `Closure_Store.php:18-39, 88-103` and `Fiscal_Record_Store.php:29-39, 107-114`. `Register_Store` memoises with a single bool (`:22, 29, 35`) where the other four key by table. After a `switch_to_blog` in the same request, it therefore skips the existence check for the second site (LIKELY defect, Low).
- **The explorer is wrong that skip-if-exists "defeats dbDelta" and should go.** It is a deliberate guard: DDL commits the open transaction (`Register_Store.php:67-70`). Column changes belong in a schema-latch upgrade (`Activator::upgrade_business_days` `:295-328`, with schema 7 at `Sync/Api.php:30-32`), as memory rule "a `schema_sql()` change needs a `SCHEMA_VERSION` bump" requires. Keep it.
- The three sync stores (`Sync_Journal.php:90`, `Mutation_Store.php:89`, `Integrity_Digest.php:147`) run `dbDelta` unconditionally under the sync latch, and `Sync_Journal::install` has epoch and backfill side effects. They stay out of the shared trait.

Scope from the tree: a new trait `Services/Owned_Table.php` (~55 lines) absorbs 4 methods from 5 stores (~150 lines deleted). One private `Activator::install_till_tables()` replaces the two lists (−6). One `woocommerce_pos_uninstall_configuration_table_suffixes()` replaces the 5 `DROP` lines at `uninstall.php:422-426`. Production: ~95 added, ~165 deleted, net ≈ −70. Tests: +~200, −~35. Total diff with lint ≈ 550. **No table, column, option or cron hook is added.**
Pro impact: none. Pro calls only public `Register_Store` methods (`Registers.php:62, 143`) and `new Register_Session_Store()` (`Payments/Refund_Processor.php:510`), and every store stays `final`. Optional follow-up: `Registers.php:195` hardcodes `'wcpos_register_sessions'`; `( new Register_Session_Store() )->table_name()` is equivalent and does no install.
Tests: today `tests/includes/Test_Uninstall.php` (22 tests), `tests/includes/Test_Activator.php` (`:665` session tables), `Services/Test_Closure_Store.php` and `Test_Cash_Movement_Store.php`. Add the following:
- `test_uninstall_table_lists_cover_every_owned_table` discovers every `includes/` class declaring `schema_sql()` and compares that table set exactly against the two uninstall lists.
- `test_uninstall_cron_list_covers_every_scheduled_hook` tokenises every `wp_schedule_event`, `wp_schedule_single_event` and `as_schedule_single_action` call in `includes/`, resolves the hook constant, and asserts membership in the uninstall list.
- `Services/Test_Owned_Table.php::test_ensure_installed_checks_each_site_table`.

These replace `test_uninstall_table_list_covers_health_required_tables` and `test_uninstall_cron_list_covers_known_hooks`. Delete those two rather than layering on top of them.
ADR / CONTEXT.md: no conflict. Add the following entry:
> **Owned Table**: A database table WCPOS creates and must remove on uninstall: the three sync tables and the five till tables (registers, register sessions, cash movements, closures, fiscal records). Created only when missing, because DDL commits open transactions. A column change ships as a schema-latch upgrade, never as an `install()` diff. Registers, sessions, movements, closures and fiscal records are user-authored bookkeeping and drop only on a full wipe. _Avoid_: custom table, plugin table, store table.

Sequencing: after the C2/C3 fix PR, because both touch `uninstall.php`, `Activator.php` and `Test_Uninstall.php`. One PR. → `spec-owned-tables.md`.
Tonight? YES. The change is mechanical and behaviour-identical, the guard semantics are kept, and the drift tests are structural.

## 2. Two version ladders and two upgrade runners per plugin — VERDICT: DROP (record doctrine; one question to Paul)
Strength (explorer): Strong · Risk tier: High (non-reversible migrations)
- The "two ladders" in Free are two different things, and the code is right to keep them apart. The versioned files (`Activator.php:692-713`) are **migrations** that run at most once. The sync `SCHEMA_VERSION` latch (`Sync/Api.php:30-32`, `Activator.php:219-290`) is a **reconciliation** that converges until it succeeds. This is the doctrine in memory `versioned-upgrade-file-runs-at-most-once`. Merging them would break one of the two semantics.
- Free bumps the version before migrating (`Activator.php:441-444`) and defers to `woocommerce_init` (`:474-501`) for the WC Subscriptions conflict (#440, pinned by `Test_Activator.php:467-546`). Pro migrates synchronously on `plugins_loaded@20` (`Pro Activator.php:40, 105-128`) and bumps after. The different locks (`WP_Upgrader::create_lock` at `:506-520` vs `add_option` plus a CAS steal at Pro `:133-166`) are each tested and each correct. A shared runner would mean choosing one timing for Pro's ladder. That is a judgement on a High-tier path with no failure to fix except C6.
- The real costs are smaller: the duplicated install list (folded into §1), C3 (fatal on throw) and C6 (Pro's early `__()`).
- The explorer is right that neither repo tests its `update-2.0.0.php`. Free's file is `( new Catalog_Visibility() )->force_all()`, which `tests/includes/Test_Catalog_Visibility.php` covers. Pro's is `Registers::backfill_store_registers()`, which is also the activation path, and Pro `tests/includes/Test_Registers.php` exists.

Scope: none. Pro impact: none. Tests: unchanged.
CONTEXT.md entries to add (sharpening):
> **Versioned Migration**: A one-time transformation of existing data in `includes/updates/update-<version>.php`. It runs at most once ever and can be skipped permanently. _Avoid_: upgrade script, update routine (for reconciliations).
> **Schema Latch**: The stored `SCHEMA_VERSION` that gates the sync and till tables. Install and backfill retry until they succeed and only then latch. _Avoid_: DB version, schema version (unqualified).

Tonight? NO. Nothing to do; Pro ladder timing goes to Needs Paul.

## 3. Pro bootstrap re-derives Free's — VERDICT: DEFER
Strength (explorer): Worth exploring · Risk tier: High (bootstrap blast radius: a fatal here 500s every route, memory `free-pro-duplicate-class-hazard`)
- The constant mirror (`woocommerce-pos-pro.php:109-141`) is where drift actually bit (C1). C1's spec adds a constant-parity test to the existing `tests/includes/Test_Bootstrap_Parity.php`, which turns this drift into a CI failure. That is the cheap 80%.
- **The explorer overstated** "re-classifies the request lane". Pro `Init.php:210-211` delegates to Free's `Request_Lane::is_storefront()`. What Pro does re-implement is the order-group timing in `when_order_services_ready` (`Init.php:196-202`) instead of joining `Service_Groups`. Joining would need Free to expose a group-registration seam, which is a new Free public interface. That needs design.
- The cache-kill block (Pro `:356-392` duplicating Free `woocommerce-pos.php:189-217`) is 30 lines of `define()`s that change only when a cache plugin does. It could be extracted, but it is not worth a bootstrap-file PR on its own.
- Pro carries guards for "older vendored Free" that can never be false, because Pro builds against its own Free copy (`composer.json:25` `dev-next`). Examples: `Init.php:202` `method_exists( BaseInit::class, 'ensure_order_services' )`, `:211` `class_exists( Request_Lane::class )`, `Store_Pricing.php:640, 667` `class_exists( Store_Scope::class )`, and the `else LicenseSettings::register()` branch in `woocommerce-pos-pro.php`. They are harmless, so they go into the Pro dead-code backlog, not tonight.

Tests that pin this today: Free `Test_Init_Hook_Wiring.php`, `Test_Service_Groups.php`, `Test_Bootstrap_Pro_Coexistence.php`; Pro `Test_Init.php`, `Test_Bootstrap_Parity.php`.
Tonight? NO. This is a High blast radius, and the Service_Groups seam is a design question.

## 4. Two Store abstracts and a consumer-less StoreInterface — VERDICT: DO-RESHAPED (Pro deletion only; interface → Needs Paul)
Strength (explorer): Strong (cheap) · Risk tier: Medium (store data feeds receipts and the client)
- Nine Pro overrides are byte-identical in body to Free's: `get_url`, `get_phone`, `get_email`, `get_opening_hours`, `get_opening_hours_notes`, `get_personal_notes`, `get_policies_and_conditions` and `get_footer_imprint` (Pro `Abstracts/Store.php:263-333` vs Free `Abstracts/Store.php:539-616`), plus `get_timezone` (Pro `:391-402` vs Free `:229-232`). All of them call `WC_Data::get_prop()`, which is protected, so there is no private-property shadowing and inheritance is exact.
- Pro `class Store extends DefaultStore implements StoreInterface` (`:24`) re-implements an interface the parent already implements (Free `:27`). Dropping `implements` and the `use` at `:14` leaves `instanceof StoreInterface` true.
- Deleting `StoreInterface` (Free `Interfaces/StoreInterface.php`, 384 lines) is a BC question. No Free or Pro consumer exists (grep `StoreInterface`: only the two `implements` lines), but third parties may use `instanceof`. → Needs Paul, not specced.
- Pro `Store_Pricing.php:679-694` `authorized_store()` uses `is_numeric` (it admits `'1e2'`), but the cast id is still authorized through `Stores::current_user_is_authorized()` (`:689`). This is not a bypass.

Scope: Pro only. Delete `Abstracts/Store.php:263-335` (73 lines) and `:391-403` (13 lines), and drop `implements StoreInterface`, the `:14` `use` and the `:22` docblock line. That is ≈ −90 lines with 0 added in production. Add one test of about 30 lines. Total diff ≈ 130.
Pro impact: this is a Pro change. Free is untouched, so there is no duplicate-class risk (no new class).
Tests: today Pro `tests/includes/Abstracts/Test_Store.php` (35 tests, including `:277` notes and `:285` opening hours), `API/Test_Default_Store.php` and `API/Test_Stores.php`. Add `Test_Store::test_inherited_contact_getters_read_store_meta`. It must pass before and after the deletion. Delete none.
ADR / CONTEXT.md: none.
Sequencing: independent. It can share one "Pro cleanup" PR with the Pro section of `spec-dead-code.md`, since both are pure Pro deletions. → `spec-pro-store-overrides.md`.
Tonight? YES. It is a pure deletion and inheritance is exact.

## 5. `wcpos-functions.php` and `Admin/Menu.php` grab bags — VERDICT: DROP
Strength (explorer): Worth exploring · Risk tier: Low
- The global functions are the public interface (third parties, Pro, `templates/`). Splitting the file buys no depth: callers see the same 26 functions.
- I counted internal callers of the 11 `woocommerce_pos_*` aliases (`wcpos-functions.php:627-768`): Free `includes/`+`templates/` 52 (request 14, is_pos_order 14, get_settings 13, locate_template 6, url 5) and Pro 9. The aliases must stay for BC, so renaming internal callers is churn with no behaviour value. Do it opportunistically when a file is touched anyway.
- `Menu.php` (710 lines): the PostHog inline snippet (`:317-405`) and the upgrade-click tracking are pinned by 5 `Test_Menu_*` files. Moving them gains locality, but nothing currently hurts.
- The dead parts (`wcpos_admin_request` and its alias at `:190-211, 653-662`) go through `spec-dead-code.md`.

Tonight? NO.

---

## D1. Pro lowers Free's WooCommerce minimum from 9.0 to 5.3 — VERIFIED
Trace: Pro `woocommerce-pos-pro.php:133-134` defines `WCPOS\WooCommercePOS\WC_MIN_VERSION` as `'5.3'` before Free's classes load, and the header at `:17` says 5.3. Pro never runs Free's main file (`:310-313`), so Free's `'9.0'` (`woocommerce-pos.php:67-68`, raised in `90bcab33`/#1994 because the 2.0 till needs `GET /wc/v3/refunds`) is never defined. As a result, `Activator::init` (`Activator.php:63`) → `woocommerce_check` (`:369-370`) admits WooCommerce 5.3–8.x under Pro, and the till then fails at refund reads. Also stale: Pro `phpstan-constants.php:52` and Free `phpstan-constants.php:41` (`'5.3'`, static analysis only).
Fix (Pro, one file plus one constants file): change `:17` to `WC requires at least: 9.0`, `:134` to `'9.0'`, and `phpstan-constants.php:52` to `'9.0'`.
Failing test: Pro `tests/includes/Test_Bootstrap_Parity.php::test_free_requirement_constants_match_bundled_free_bootstrap`. Arrange: parse `FREE_PLUGIN_PATH . 'woocommerce-pos.php'` for the literal values of `WC_MIN_VERSION`, `PHP_MIN_VERSION` and `MIN_PRO_VERSION`. Act: read the live constants. Assert: `assertSame` for each, and `get_file_data()` "WC requires at least" is equal for both main files. Severity: Medium (a merchant on WC < 9 gets a broken till instead of the admin notice). → `spec-pro-wc-min-version.md`.

## D2. `wcpos_payments_sweep` missing from the uninstall cron list — VERIFIED
Trace: `Payments_Sweeper::register_hooks` (`Payments/Contract/Payments_Sweeper.php:20-25`, wired by `Init.php:201-205` in the post-latch phase) schedules a 10-minute recurring event. `uninstall.php:64-78` omits it, and `Test_Uninstall.php:313-327` does not name it. `Deactivator.php:74` clears it, and WordPress requires deactivation before delete, so the leak needs a deactivation that missed the event. Severity: Low.
Fix: add `'wcpos_payments_sweep',` after `'wcpos_analytics_group_refresh',` (`uninstall.php:71`).
Failing test: in `Test_Uninstall::test_uninstall_cron_list_covers_known_hooks` add `$this->assertContains( Payments_Sweeper::HOOK, $hooks );` and `use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;`. §1 later replaces it with the structural scan. → `spec-free-upgrade-defects.md`.

## D3. Upgrade exceptions escape `woocommerce_init` as fatals — VERIFIED (trace)
Trace: `version_check` runs on every request (`Activator.php:63-77`, not gated to admin) and hooks a closure on `woocommerce_init` (`:474-501`) that has `try/finally` but no `catch`. `db_upgrade` (`:717-719`) → `install_sync_schema` → `upgrade_business_days` throws `RuntimeException` on a read or write failure (`:309, :321`). One cause is a DB user without `ALTER` (dbDelta fails silently, then the `business_day IS NULL` SELECT errors). Because the latch stays below 7, `$sync_needs_upgrade` (`:413`) is true on the next request, the lock was released in `finally`, and so every request (front end, REST, admin) re-enters and fatals until the DB is fixed. `Fiscal_Record_Store::install` (`:94-96`) is reached the same way from `db_upgrade:727`. Its runtime throw from `ensure_installed` on a fiscal write is a correct High-tier refusal and stays.
Fix (one file): add `catch ( \RuntimeException $error ) { Logger::error( 'WCPOS database upgrade failed: ' . $error->getMessage() ); }` before `} finally {` at `:493`. The schema latch stays old, so the next request retries. That is today's designed degraded mode when the sync store is unhealthy (`:233-238`). Accepted risk: one retry and one log line per request while the DB is broken.
Failing test: `tests/includes/Sync/Test_Sync_Install.php::test_version_check_business_day_upgrade_failure_is_logged_not_thrown`. Arrange: latch `'6'`, and a `query` filter turns the `business_day IS NULL` SELECT into a query against a missing table. Act: `version_check` via reflection, then `do_action( 'woocommerce_init' )`. Assert: no exception, latch still `'6'`, upgrade lock released. Severity: High when triggered (site down), though it is conditional on a DB failure. Unreleased (2.0 / schema 7). → `spec-free-upgrade-defects.md`.

## D4. Pro's uninstall leaves Free's tables, crons and options on a Pro-only install — VERIFIED (by contract), NEEDS RULING
Trace: on a Pro-only site, Pro's bootstrap of the vendored Free creates the 8 tables and the cron hooks (`woocommerce-pos-pro.php:313` → Free `Activator`). Pro's `uninstall.php:19-23` declares Free's data "never touched", and `woocommerce_pos_pro_uninstall_free_installed()` (`:42-52`) gates only logs and translations (`:205`). `tests/includes/Test_Uninstall.php:121` (Pro) pins "never deletes free data" and has no Free-absent case. This contradicts the stewardship rule ("when we uninstall we remove everything we've added"). → Needs Paul.

## D5. `wcpos_admin_request()` dead with a never-matching fallback — NOT A DEFECT (dead code)
`wcpos-functions.php:196-211` looks up `$_SERVER['HTTP_X_woocommerce_pos_ADMIN']`. PHP upper-cases those keys, and the name looks like a botched search-and-replace of `WC_POS`. The `getallheaders` lookup is case-sensitive. There are 0 callers in Free, Pro, packages or tests, and the alias at `:653-662` also has 0. Because it is a global function, it is classed `@deprecated` + Needs Paul in `spec-dead-code.md` #18.

## D6. Pro's 2.0.0 migration calls `__()` before `after_setup_theme` — LIKELY
Trace: Pro `Activator.php:40` (`plugins_loaded@20`) → `version_check` → `db_upgrade` → `updates/update-2.0.0.php:10` → `Registers::backfill_store_registers` (`Registers.php:41-53`) → `ensure_store_register` → `__( 'Register', 'woocommerce-pos-pro' )` (`:73`). On WP ≥ 6.7 this gives a "translation loading triggered too early" notice, and on non-English sites possibly an English default register name persisted once. Free deliberately defers for exactly this reason (`Activator.php:448-450`). The failing test would be Pro `tests/includes/updates/Test_Update_2_0_0.php::test_backfill_defers_until_translations_are_loadable`, but it cannot be written cleanly in PHPUnit because `after_setup_theme` has already fired. The fix belongs to Pro ladder timing (§2). Severity: Low, one-time. → Needs Paul.

---

## Dead-code sweep (all areas) — `spec-dead-code.md`
23 items vetted by grep across Free and Pro `includes/ templates/ tests/ packages/`, the main files and `uninstall.php`. Callbacks registered by string name were checked too.
- **Delete tonight (Free PR):**
  - The Pro ≤1.4.2 filter (`Admin.php:137-144`, Paul's "Remove in 2.0.0" TODO)
  - The deprecated `woocommerce_pos_template_redirect` (`Templates/Frontend.php:70-78`)
  - The no-op OAuth error mapping (`API/V1/Auth.php:268, 272-280`)
  - The unused `$credentials` (`Templates/Login.php:82`)
  - The unreachable bodies of `install_starter_template`/`get_starter_templates` (`Templates.php:1324-1377, 1139-1157`)
  - Plus `@deprecated` tags and one stale comment (`V1/Products_Controller.php:717-719`)

  ≈ −82/+20 lines.
- **Pro PR:**
  - Dead `is_object` branches and unused `$active` (`Pro_Plugin_Updater.php:825-829, 995-1000`)
  - Move `Fake_Update_Server_Client` into `tests/`
- **Keep:**
  - `Thermal_Html_Renderer`: it is the fail-closed seam that keeps thermal content out of the PHP renderer
  - `Order_Document::build` (live at `V2/Orders_Controller.php:210`)
  - `woocommerce_pos_get_anon_id`
- **`@deprecated` + Needs Paul:** the remaining 10 items (questions are in the spec).

## Recommended tonight order
1. **Free fix PR — D2 + D3** (`spec-free-upgrade-defects.md`): two one-line fixes, each with a failing test first. Lands first because §1 builds on it.
2. **Pro fix PR — D1** (`spec-pro-wc-min-version.md`): a 3-line change plus a parity test.
3. **Pro cleanup PR — §4 + Pro section of `spec-dead-code.md`** (`spec-pro-store-overrides.md`): pure deletions. Free's dead-code PR (`spec-dead-code.md` PR 1) can go in the same slot as an independent Free PR.
4. **Free §1 Owned Tables** (`spec-owned-tables.md`): after item 1 merges.

## Needs Paul
1. Delete `Interfaces/StoreInterface.php` (no consumer in Free/Pro; third parties may `instanceof`), or keep it as a public contract?
2. On a Pro-only install (Free never installed), should Pro's uninstall drop Free's 8 tables, cron hooks and options under the same default/full-wipe split Free uses (D4)?
3. Should Pro's upgrade ladder adopt Free's timing (defer to `woocommerce_init`) so `update-2.0.0.php` stops calling `__()` before translations load (D6), or just defer that one backfill to `init`?
4. The 10 dead-code removal questions listed at the end of `spec-dead-code.md`.
