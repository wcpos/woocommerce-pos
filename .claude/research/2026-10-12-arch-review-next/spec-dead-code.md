# Spec — vetted dead-code sweep (Free PR + small Pro PR)

Trees read: Free `837e985f` (`/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next`), Pro `05f06c4` (`/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next`). All line numbers below are re-read from those trees (several drifted from the findings).

## Goal
Delete code that no request can reach (unreachable branches, unused locals, author-scheduled hook shims whose only consumer is version-gated out) and mark public zero-caller methods `@deprecated` so Paul can rule on removal. No behaviour change on any reachable path.

## Stakes
Mixed tiers, but every change here is behaviour-identical on reachable paths. The one High-tier file touched (`API/V1/Auth.php`, token refresh) only loses an `if/elseif` whose every arm assigns the value the default already holds; four existing tests pin the response (`invalid_grant`). A failure costs a red test, not data. Accepted risk: a third party hooking `woocommerce_pos_single_product_admin_class` or the 1.4.0-deprecated `woocommerce_pos_template_redirect` loses that hook in 2.0.0. Paul wrote both removal TODOs, and the Pro builds the first one existed for (≤ 1.4.2) are deactivated by `MIN_PRO_VERSION` 1.8.7 (`woocommerce-pos.php:73-74`, `Activator.php:819-832`).

## Out of scope (do NOT add)
No new tests beyond what is listed; no `_deprecated_function()` / `_deprecated_hook()` runtime notices (they change output); no deletion of any public method, class, global function, filter, or template file except the two TODO-scheduled hook shims; no new options, tables, env vars, feature flags, fallbacks, or plan files; no refactor of `Order_Document`, `Thermal_Html_Renderer`, or the search shims; no edits to `CHANGELOG`/readme.

## Vetted list

Grep method: `/private/tmp/claude-501/-Users-kilbot-Projects-woocommerce-pos/e5f9e5cf-e43e-4bde-9bd8-9beb882421fd/scratchpad/dc/g.sh '<regex>'`, which runs `grep -rnE` over Free `includes/ templates/ tests/ packages/ woocommerce-pos.php uninstall.php` and Pro `includes/ templates/ tests/ packages/ woocommerce-pos-pro.php uninstall.php` (`*.php *.ts *.tsx *.js *.json`, excluding `node_modules vendor build dist`). I also grepped for dynamic dispatch (`method_exists(`, `is_callable(`), `add_filter`/`add_action` string callbacks, and Pro `extends` and `parent::` against each Free class.

| # | Item | Actual path:line | Visibility | Callers found (prod / tests) | Class | One-line change |
|---|---|---|---|---|---|---|
| 1 | Pro ≤1.4.2 product-screen filter (TODO "Remove in 2.0.0") | `includes/Admin.php:137-144` | public filter `woocommerce_pos_single_product_admin_class` | 0 / 0 in Free, Pro, and packages. Pro uses `woocommerce_pos_admin_screen_handlers` (Pro `includes/Admin.php:121-122`). `screen_handlers['product']` is already `Single_Product::class` at `Admin.php:79` | **DELETE TONIGHT** (Paul scheduled it; the consumer is version-gated out) | delete lines 137-144 |
| 2 | Deprecated action `woocommerce_pos_template_redirect` (TODO "remove in 1.5.0") | `includes/Templates/Frontend.php:70-78` | public action, `do_action_deprecated` since 1.4.0 | 0 / 0 | **DELETE TONIGHT** | delete lines 70-78 |
| 3 | Dead OAuth error mapping | `includes/API/V1/Auth.php:268, 272-280` | private branch in a public method | the `if`/`elseif` arms both assign `'invalid_grant'`, which is the default (`:273`); `$error_code` is used only there | **DELETE TONIGHT** | drop `$error_code` and the mapping block; return `'error' => 'invalid_grant'` |
| 4 | Unused local `$credentials` | `includes/Templates/Login.php:82` | local | never read (the script re-encodes at `:86`); `wp_json_encode` has no side effects | **DELETE TONIGHT** | delete line 82 |
| 5 | `Templates::install_starter_template()` unreachable body | `includes/Templates.php:1324-1377` (findings said 1331-1385) | public static | 0 / 0 callers. `get_starter_templates()` always returns `[]`, so the method always returns `WP_Error('invalid_starter')` at `:1335`; lines 1338-1376 cannot run | **DELETE TONIGHT** (body only) + `@deprecated` + Needs Paul (method) | keep the signature, body becomes the `invalid_starter` return |
| 6 | `Templates::get_starter_templates()` | `includes/Templates.php:1139-1157` | public static | 0 prod; test `Test_Templates.php:1113-1121` asserts no legacy keys (still true with `[]`) | **DELETE TONIGHT** (`array_filter` over a literal `[]`) + `@deprecated` + Needs Paul | body → `return array();` |
| 7 | `Templates::migrate_active_template_to_order()` | `includes/Templates.php:1103-1137` | public static | 0 prod; **Pro test** `tests/includes/Test_Templates_Resolution.php:99-118` calls it. Called with `'display'`, it deletes the Live display pin | `@deprecated` + Needs Paul | docblock tag only |
| 8 | `Thermal_Html_Renderer` | `includes/Templates/Renderers/Thermal_Html_Renderer.php` (110 lines) | public class | `Receipt_Renderer_Factory::create('thermal')` (`Services/Receipt_Renderer_Factory.php:30-34`) returns it. Both prod callers (`Receipt.php:292`, `Template_Pdf_Service.php:52`) branch on thermal first, but the factory is public, and returning it is the guard that stops thermal (kses-exempt) content reaching `Legacy_Php_Renderer` (RCE). Pinned by 4 tests (`Test_Receipt_Renderers.php:432-519`) | **KEEP** (security fail-closed seam, not dead) | none. Its duplication belongs to the templates area |
| 9 | `templates/closure.php` | `templates/closure.php` (141 lines) | shipped template file | unreachable in prod: `TEMPLATE_PLUGIN_CORE`+`closure` resolves to `templates/gallery/closure-default.html` (`Templates.php:662`). It is the only legacy-php closure template and the starting point for theme overrides (`<theme>/woocommerce-pos/closure.php`, `Templates.php:645-647`). Tests: `tests/includes/API/V2/Test_Closure_Receipts.php:40-43` renders it in 7 assertions (`:63, 437, 438, 443, 684, 686, 898, 906`) | Needs Paul | none tonight |
| 10 | `Services\Auth::generate_token()` | `includes/Services/Auth.php:539-550` | public, already `@deprecated` | 0 prod in Free and Pro; test `tests/includes/Services/Test_Auth_Service.php:1036-1045` | `@deprecated` (present) + Needs Paul | none tonight |
| 11 | `Services\Settings::delete_settings()` | `includes/Services/Settings.php:490-503` | public static | 0 callers; mentioned only in comments `Sync/Visibility_Observer.php:196`, `Services/Settings/Access_Section.php:245` | `@deprecated` + Needs Paul | docblock tag only |
| 12 | V1 Products deprecated callbacks | `includes/API/V1/Products_Controller.php:394, 600, 614, 631` | public, already `@deprecated` | hooked nowhere (no `add_filter` and no dynamic dispatch; `API.php:542` only calls `wcpos_dispatch_request`). Pro `API/V1/Products_Controller.php` extends it but never references them | `@deprecated` (present) + Needs Paul | none tonight |
| 12b | Same family, not in the findings | `API/V1/Products_Controller.php:407 wcpos_posts_clauses` (NOT tagged), `API/V1/Product_Variations_Controller.php:297, 310, 323, 357, 456`, `API/V2/Variations_Controller.php:402` | public | all hooked nowhere; `Collection_Rules_Plan::install()` (`Sync/Collection_Rules_Plan.php:428-450`) owns `posts_clauses`. The comment at `Products_Controller.php:717-719` wrongly says `wcpos_posts_clauses()` applies the sorts | `@deprecated` + Needs Paul | tonight: tag `:407` and correct the stale comment `:717-719` |
| 13 | `V2\Variations_Controller::group_search_results()` | `includes/API/V2/Variations_Controller.php:441-452` | public, already `@deprecated` | 0 / 0 | `@deprecated` (present) + Needs Paul | none tonight |
| 14 | `API\Order_Search` shim | `includes/API/Order_Search.php` (61) | public final class, `@deprecated` | 0 / 0 in Free and Pro. The file header says "retained for Pro" (`:3`), which is false | `@deprecated` (present) + Needs Paul | none tonight |
| 15 | `API\Product_Search` shim | `includes/API/Product_Search.php` (68) | public final class, `@deprecated` | used only by dead #12 (`Products_Controller.php:21, 395, 601, 615`) and `tests/includes/API/V2/Test_Product_Search_Contract.php:11, 314` | `@deprecated` (present) + Needs Paul (delete together with #12) | none tonight |
| 16 | `V2\Write_Controller::wcpos_check_permissions()` | `includes/API/V2/Write_Controller.php:717-720` | public, already `@deprecated` | 0 prod; `tests/includes/Sync/Test_Write_Controller.php:826-828, 844-846` (pins own/other/web order edit and delete rules, High tier) | `@deprecated` (present) + Needs Paul | none tonight |
| 17 | `Sync\Order_Document::build()` | `includes/Sync/Order_Document.php:55-73` | public static on a final class | **live**: `API/V2/Orders_Controller.php:210`, plus tests `Test_Order_Pull_Planner.php:332, 356`. Only the `skeleton`/`snapshot` values of `$source` are dead | **KEEP** | narrowing the signature belongs to the sync area |
| 18 | `wcpos_admin_request()` and alias `woocommerce_pos_admin_request()` | `includes/wcpos-functions.php:185-211`, `:653-662` | global functions | 0 / 0 everywhere. The body never matches: it looks up `'HTTP_X_woocommerce_pos_ADMIN'` (wrong name and wrong case) and a case-sensitive `getallheaders` key | `@deprecated` + Needs Paul | docblock tag on `:191-195` only |
| 19 | `woocommerce_pos_get_anon_id()` | `includes/wcpos-functions.php:678-689` | global function | 0 Pro callers; test `tests/includes/Services/Test_Anon_ID.php:104`. Added deliberately on 2026-06-13 (`66cfdd11`) for a planned Pro licence-activation join | **KEEP** | none (Needs Paul: is the join still planned?) |
| 20 | Pro `Refund_Orchestrator` | Pro `includes/Payments/Refund_Orchestrator.php` (131) | public class; optional ctor param on `Refund_Processor` (`:96-103`) and `Order_Refunds_Controller` (`:63-87`) | reachable only by injection; prod builds `Order_Refunds_Controller` by class name with no args. Tests: `Test_Refund_Processor.php:471, 509, 574` | `@deprecated` + Needs Paul (High tier: refunds) | none tonight |
| 21 | Pro `Fake_Update_Server_Client` | Pro `includes/Services/Update_Server_Client/Fake_Update_Server_Client.php` (72) | public class, a test double in production code | 0 prod; `tests/includes/Admin/Updaters/Test_Pro_Plugin_Updater.php:11` (+8 uses), `tests/.../Update_Server_Client/Test_Update_Server_Client.php:10, 24, 63, 262` | **MOVE TONIGHT** (Pro PR) | move it to `tests/` |
| 22 | Pro `Pro_Plugin_Updater` dead `is_object` branches, unused `$active` | Pro `includes/Admin/Updaters/Pro_Plugin_Updater.php:825-829, 995-999, 1000` | private method bodies | `check_license_status()` (private, `:963-985`) always returns an array (`:981` or `to_array()` at `:984`). `$active` at `:1000` is never read in `maybe_add_upgrade_notice()` | **DELETE TONIGHT** (Pro PR) | delete those lines |
| 23 | Pro `Settings::save_settings()` and `ProSettings::save_settings()` | Pro `includes/Services/Settings.php:150-158`, `ProSettings.php:86-88` | public | 0 prod; tests `Test_Settings.php:61-80`, `Admin/Test_Menu_Highlighting.php:187` | Needs Paul | none tonight |

Not dead, skipped: `wpmu_new_blog` (deprecated by WP but live). The 11 `woocommerce_pos_*` aliases in `wcpos-functions.php:627-768` are still called 52× from Free (A5 territory).

---

## PR 1 (Free) — exact changes

Branch from `origin/next`. One commit set; the orchestrator commits with `git add -u`.

1. `includes/Admin.php` — delete lines 137-144 (the `/* Backwards compatibility for WCPOS Pro 1.4.2 … @TODO: Remove in WCPOS 2.0.0. */` comment, the `$this->screen_handlers['product'] = apply_filters( 'woocommerce_pos_single_product_admin_class', … );` line, and the blank line after it). `current_screen()` then opens with the `/** Filters the screen handlers … */` docblock. Leave `use …\Single_Product;` (still used at `:79`).
2. `includes/Templates/Frontend.php` — delete lines 70-78 (the `/* Deprecated action. @TODO remove in 1.5.0 */` comment, the `if ( has_action( 'woocommerce_pos_template_redirect' ) ) { do_action_deprecated( … ); }` block, and the trailing blank line). Keep `do_action( 'woocommerce_pos_frontend_template_redirect' );` at `:68`.
3. `includes/API/V1/Auth.php` `refresh_token()` — delete `$error_code = $result->get_error_code();` (`:268`) and lines 272-280 (the comment, `$oauth_error = 'invalid_grant';`, the `if/elseif`, and the blank line). In the response array (`:283`) write `'error' => 'invalid_grant', // OAuth 2.0: every refresh-token failure is invalid_grant; the status code carries the detail.` Keep `$error_msg`, `$status`, and the response shape.
4. `includes/Templates/Login.php` — delete line 82 (`$credentials = wp_json_encode( $user_data );`).
5. `includes/Templates.php`
   - `migrate_active_template_to_order()` docblock (`:1103-1109`): add `@deprecated 2.0.0 No callers in WCPOS or WCPOS Pro; removal pending a maintainer ruling.` Body unchanged.
   - `get_starter_templates()` (`:1139-1157`): docblock gets the same `@deprecated` line plus "Always empty: no starter templates are bundled." The body becomes `return array();`.
   - `install_starter_template()` (`:1324-1377`): docblock gets the same `@deprecated` line. The body becomes:
     ```php
     unset( $starter_key ); // Signature kept for backward compatibility.

     return new \WP_Error( 'invalid_starter', __( 'Starter template not found.', 'woocommerce-pos' ) );
     ```
     (Identical to today: `get_starter_templates()` is empty, so `:1334-1336` always returned this.)
6. `includes/Services/Settings.php` `delete_settings()` docblock (`:490-496`): add `@deprecated 2.0.0 No callers; removal pending a maintainer ruling.` Body unchanged.
7. `includes/wcpos-functions.php` inner docblock of `wcpos_admin_request()` (`:191-195`): add `@deprecated 2.0.0 No callers; its header lookups never match. Removal pending a maintainer ruling.` Body unchanged.
8. `includes/API/V1/Products_Controller.php`
   - `wcpos_posts_clauses()` docblock (`:398-406`): add `@deprecated Collection Rules now installs this behavior.` (matching the sibling tags). Body unchanged.
   - Comment at `:717-719`: replace "`Sync\Collection_Rules` declares them and `wcpos_posts_clauses()` applies them as a LEFT JOIN" with "`Sync\Collection_Rules` declares them and its plan applies them on `posts_clauses` as a LEFT JOIN".

Rulings to preserve verbatim:
- The refresh endpoint answers `{"error":"invalid_grant","error_description":<msg>}` with the WP_Error's status (default 400) for every failure. Pinned by `tests/includes/API/Test_Auth_API.php:153`, `tests/includes/API/Test_Auth_Refresh_Status.php:70, 92, 134`, and `tests/includes/API/Test_Permissions.php:553`.
- The `woocommerce_pos_admin_screen_handlers` filter and the `'product' => Single_Product::class` default (`Admin.php:79`) still decide the product screen. Pinned by `tests/includes/Admin/Test_Menu_Highlighting.php` and the Pro `Admin.php:121-122` path.
- `woocommerce_pos_frontend_template_redirect` still fires before the template. Pinned by `tests/includes/Templates/Test_Frontend.php`.
- `Templates::get_starter_templates()` returns `[]`. Pinned by `tests/includes/Test_Templates.php:1113-1121`.

Tests: add none and delete none. Any existing test that references a removed hook name? `g.sh 'woocommerce_pos_single_product_admin_class|woocommerce_pos_template_redirect'` returns 0 test hits.

Budget (from line ranges in the tree): deleted ≈ 8 + 9 + 10 + 1 + 9 + 45 = 82 lines; added ≈ 20 (docblock tags, two collapsed bodies, one comment). **Non-test ceiling: 45 lines added. Total-diff ceiling (added + deleted, incl. lint): 170 lines.** If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

Verification (sandbox):
```
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Admin.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Templates/Frontend.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/API/V1/Auth.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Templates/Login.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Templates.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Settings.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/wcpos-functions.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/API/V1/Products_Controller.php
cd /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next && /Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Admin.php includes/Templates/Frontend.php includes/API/V1/Auth.php includes/Templates/Login.php includes/Templates.php includes/Services/Settings.php includes/wcpos-functions.php includes/API/V1/Products_Controller.php
```
phpcs: no NEW violations in changed lines. Pre-existing violations elsewhere in these files are not yours. Report them, do not fix them.

PHPUnit the orchestrator runs afterwards in wp-env, one file per call: `tests/includes/API/Test_Auth_API.php`, `tests/includes/API/Test_Auth_Refresh_Status.php`, `tests/includes/API/Test_Permissions.php`, `tests/includes/Test_Templates.php`, `tests/includes/Templates/Test_Frontend.php`, `tests/includes/Admin/Test_Menu_Highlighting.php`, then the full `pnpm run test:unit:php`. Then Pro's `tests/includes/Test_Templates_Resolution.php` against this Free tree (it calls `migrate_active_template_to_order`, unchanged).

## PR 2 (Pro) — exact changes

Branch from Pro's matching lane (`origin/next` if live; the orchestrator decides).

1. `includes/Admin/Updaters/Pro_Plugin_Updater.php` — delete lines 825-829 (the comment, the `if ( \is_object( $license_status ) ) { … }` block, and the blank line) in `license_status_notice()`, and lines 995-999 (same block) in `maybe_add_upgrade_notice()`. In `maybe_add_upgrade_notice()`, also delete `$active = isset( $license_status['activated'] ) && $license_status['activated'];` (was `:1000`). Re-align the `=` of the remaining `$inactive`/`$expired` pair if phpcs asks.
2. Move `includes/Services/Update_Server_Client/Fake_Update_Server_Client.php` to `tests/includes/Admin/Updaters/Update_Server_Client/Fake_Update_Server_Client.php`. Change its namespace to `WCPOS\WooCommercePOSPro\Tests\Admin\Updaters\Update_Server_Client`, its `@package` to the same, and add `use WCPOS\WooCommercePOSPro\Services\Update_Server_Client\Update_Server_Client;`, `UpdateInfo`, and `LicenseStatus` imports wherever unqualified names now resolve wrongly. It `implements Update_Server_Client` and its docblocks name `UpdateInfo` and `LicenseStatus`. The body is otherwise unchanged. Delete the original (the orchestrator stages the deletion; `git add -u` picks it up; the new file needs `git add`, so list it in your report).
3. `tests/includes/Admin/Updaters/Update_Server_Client/Test_Update_Server_Client.php` — remove `use …\Services\Update_Server_Client\Fake_Update_Server_Client;` (`:10`). The class now lives in this test's own namespace. Add `require_once __DIR__ . '/Fake_Update_Server_Client.php';` after the `namespace`/`use` block, the same pattern as `Test_Extensions_Updater.php:10`. Delete the `@covers …\Fake_Update_Server_Client` line (`:24`); a test double is not covered code.
4. `tests/includes/Admin/Updaters/Test_Pro_Plugin_Updater.php` — change `:11` to `use WCPOS\WooCommercePOSPro\Tests\Admin\Updaters\Update_Server_Client\Fake_Update_Server_Client;` and add `require_once __DIR__ . '/Update_Server_Client/Fake_Update_Server_Client.php';` after the `use` block.

Duplicate-class hazard: none. The moved class is in a `Tests\` namespace that Free does not declare.

Rulings to preserve: the license notice and upgrade notice text for active, inactive, expired, and error statuses. Pinned by `tests/includes/Admin/Updaters/Test_Pro_Plugin_Updater.php`.

Budget: **non-test ceiling: 0 lines added, about 11 deleted. Total-diff ceiling (excluding the rename's unchanged body): 70 lines.** If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

Verification: `php -l` on the four touched files, then `cd /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next && /Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Admin/Updaters/Pro_Plugin_Updater.php tests/includes/Admin/Updaters/Update_Server_Client/Fake_Update_Server_Client.php`. The orchestrator then runs `tests/includes/Admin/Updaters/Test_Pro_Plugin_Updater.php` and `tests/includes/Admin/Updaters/Update_Server_Client/Test_Update_Server_Client.php` in Pro wp-env.

## Fixed lines (both PRs)
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.

## Needs Paul (one question each)
1. `Templates::migrate_active_template_to_order` / `get_starter_templates` / `install_starter_template` (`Templates.php:1103-1157, 1324-1377`): delete in 2.0.0, along with Pro's `Test_Templates_Resolution::test_migration_moves_active_template_to_first_position` and Free's `Test_Templates::test_legacy_php_starter_templates_are_not_registered`?
2. `templates/closure.php`: is it still a supported legacy-php starting point for theme overrides? If not, delete it and move `Test_Closure_Receipts::html()` onto a tests fixture copy.
3. `Services\Auth::generate_token()` (`Auth.php:548`): remove in 2.0.0 (and `Test_Auth_Service.php:1036-1045`)?
4. `Services\Settings::delete_settings()` (`Settings.php:497`): remove, and reword the two comments that cite it (`Visibility_Observer.php:196`, `Access_Section.php:245`)?
5. The nine unhooked V1/V2 search/clauses callbacks (#12, #12b, #13) and the `API\Order_Search` / `API\Product_Search` shims (#14, #15): delete them all in 2.0.0 as one PR (≈ 250 lines; moves `Test_Product_Search_Contract.php:314` onto `Sync\Product_Search`)?
6. `V2\Write_Controller::wcpos_check_permissions()` (`:717-720`): remove, and repoint `Test_Write_Controller.php:826-846` at `Permission_Rules::wc_filter( …, 'writes' )`?
7. `wcpos_admin_request()` and `woocommerce_pos_admin_request()` (`wcpos-functions.php:190-211, 653-662`): delete both global functions in 2.0.0? Their lookups never match, so they always return `false`.
8. `woocommerce_pos_get_anon_id()`: is the Pro licence-activation join it was added for (2026-06-13) still planned? If not, it becomes a #7-style deletion.
9. Pro `Refund_Orchestrator` (High tier): remove the legacy injection path (`Refund_Processor.php:96-103, 191, 243-290`, `RefundPlan::from_legacy_resolution`, `Order_Refunds_Controller.php:63-87`) and its three tests?
10. Pro `Settings::save_settings()` and `ProSettings::save_settings()` (no prod callers): delete, or are they a planned write path?
