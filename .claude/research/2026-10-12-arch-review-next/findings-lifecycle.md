# Explorer findings — plugin lifecycle / bootstrap (Free + Pro) and the mechanical smell sweep

Read on Free `837e985f` and Pro `05f06c4`. Verbatim explorer output. Re-verified by the orchestrator: the nine byte-identical Store overrides (seven checked by md5: get_url get_phone get_email get_opening_hours get_personal_notes get_footer_imprint get_timezone), `StoreInterface` has no type-hint/instanceof consumer in either repo, Pro `WC_MIN_VERSION` '5.3' vs Free '9.0', `wcpos_payments_sweep` absent from `uninstall.php:64-78`.

## A) Deepening candidates

### A1. The plugin footprint (tables, cron hooks, option prefixes) is declared in five places. Strong.
- Free `includes/Activator.php:219-283`, `:692-735` (store-install list twice: `:222-231`, `:722-727`); `includes/Deactivator.php:70-74` (113 lines; clears 3 cron hooks); `uninstall.php:48-78` (table + cron lists), `:422-426` (5 hard-coded `DROP TABLE`); `tests/includes/Test_Uninstall.php:296-327` (drift guard naming constants by hand); seven `install()` copies: `Services/Cash_Movement_Store.php:51`, `Register_Store.php:72`, `Fiscal_Record_Store.php:86`, `Closure_Store.php`, `Register_Session_Store.php`, `Sync/Mutation_Store.php:89`, `Sync_Journal.php:90`.
- `install()` variants: skip-if-table-exists / `function_exists('dbDelta')` guard / hand-written `ALTER` (`Fiscal_Record_Store.php:88-99`). Skip-if-exists defeats `dbDelta` diffing → every column change needs hand-written upgrade code (`Activator::upgrade_business_days` `:295-328`). Schema 7 (`Sync/Api.php:30-32`) versions the session/closure tables under the sync latch.
- Drift guard already let something through: `Payments_Sweeper::HOOK` (`Payments/Contract/Payments_Sweeper.php:17`) missing from `uninstall.php:64-78` and `Test_Uninstall.php:313-327`.
- Tests: Test_Uninstall 22 tests, 2 skips; Test_Activator 20 tests, one covers session tables (`:665`). Pro's `uninstall.php` drops none of these tables (C4).

### A2. Two version ladders and two upgrade runners per plugin, different locks and timing. Strong.
- Free `Activator.php:411-503` (`version_check`), `:506-520` (lock via `WP_Upgrader::create_lock`), `:692-713` (ladder), `Sync/Api.php:30-33` (second ladder). Pro `includes/Activator.php:101-205` (218); lock = `add_option` + CAS `UPDATE` `:133-166`. `includes/updates/*`: 11 Free, 8 Pro.
- Free bumps the version (`:443-444`) before `db_upgrade`, deferred to `woocommerce_init` (`:477`); if the hook never fires the versioned files are skipped permanently — `update-2.0.0.php:20-26` documents and works around it. On every upgrade Free re-runs the install list outside both ladders (`:722-727`).
- Pro runs its ladder synchronously on `plugins_loaded@20` on every request. Pro `update-2.0.0.php:10` → `Registers.php:73` calls `__()` before `init`.
- Neither repo tests its `update-2.0.0.php` (Free tests 1.8.12, 1.9.0, 1.10.0; Pro 1.8.11, 1.8.14). Test_Activator lock/deferral tests `:467-664`.
- Pro's 2.0.0 file writes to Free's `wcpos_registers` table; survives load order only because `Register_Store::list` lazily `ensure_installed()` (`Services/Register_Store.php:162`).

### A3. Pro's bootstrap re-derives what Free's bootstrap owns. Worth exploring.
- Pro `woocommerce-pos-pro.php:109-141` mirrors 10 Free constants by hand (drifted — C1); `:356-392` "Copied from free plugin" duplicates Free `woocommerce-pos.php:189-217`. Pro `includes/Init.php:38-247` (248): ad hoc `add_action` (`:42-45`); re-classifies the request lane (`:210-240`); re-implements order-group timing in `when_order_services_ready` (`:196-202`) instead of joining `Service_Groups`.
- Free `Init.php` still ad hoc outside the manifest: constructor special cases keyed on callback identity (`:48-55`); the `init()` fan-out (`:408-413`, `:574-672`); hard-coded integrations (`:647-671`); Menu upgrade-click handlers from `init_admin` (`:633-634`).
- Tests: Free `Test_Init_Hook_Wiring.php` (794), `Test_Service_Groups.php` (110); Pro `Test_Init.php`. Pro vendors Free at build time and never executes Free's main file.

### A4. The two Store abstracts and a StoreInterface nobody consumes. Strong (cheap).
- Free `includes/Abstracts/Store.php` (686), `includes/Interfaces/StoreInterface.php` (40 methods), Pro `includes/Abstracts/Store.php` (780) extends Free's and implements the interface again.
- 9 of 15 re-declared methods byte-identical: `get_url` F:545/P:268, `get_phone` F:555/P:277, `get_email` F:565/P:286, `get_opening_hours` F:575/P:295, `get_opening_hours_notes` F:585/P:304, `get_personal_notes` F:595/P:313, `get_policies_and_conditions` F:605/P:322, `get_footer_imprint` F:615/P:331, `get_timezone` F:229/P:399. Real differences: `__construct`, `get_data`, `get_formatted_address`, `get_logo_image_src`, `get_tax_id`, `get_tax_ids`.
- `StoreInterface` has no type-hint or `instanceof` consumer anywhere. Pro `Store_Pricing.php:639-697` re-derives store authorization ("Mirrors the v1 gate"; `is_numeric` at `:680`).
- Tests: Pro `tests/includes/Abstracts/Test_Store.php`, `API/Test_Default_Store.php`. Risk: the duplicate-class hazard memo.

### A5. `wcpos-functions.php` and `Admin/Menu.php` are grab bags. Worth exploring.
- `wcpos-functions.php` (768, 26 globals): request lane `:159, :196`; URLs `:64-137, :517-536`; settings `:227`; payments `:242, :262`; site identity `:289-335`; templates `:445, :589` (`extract()` at `:609`); JSON/HTML `:424, :498`; 11 `@deprecated` `woocommerce_pos_*` aliases `:627-768` still called 52× from Free's own `includes/` (request 14, settings 13, `is_pos_order` 14, `locate_template` 6, url 5) and 9× from Pro. `woocommerce_pos_get_anon_id` (`:687`) "supported accessor for Pro" with 0 Pro callers.
- `Menu.php` (710): menu registration `:147-181`; inline PostHog snippet `:317-405`; upgrade-click tracking `:407-553`; gallery assets `:554-650`. Tests: 5 `Test_Menu_*`; none for the functions file.

## B) Smell sweep (both repos' includes/)
- Near-duplicate blocks ≥15 lines: `Templates/Thermal/Escpos_Thermal_Emitter.php:167-476` ↔ `Starprnt_Thermal_Emitter.php:175-481` (~98 lines); Epos_Xml `:192-223` ↔ Escpos/Starprnt (32); Star_Markup `:96-118` ↔ three others (23); Free `API/V1/Product_Variations_Controller.php:140-196` ↔ `Products_Controller.php:133-201` (24); Pro same pair `:131-167` ↔ `:98-134` (21); `API/V1/Orders_Controller.php:281-305` ↔ `API/V2/Order_Email_Controller.php:27-51` (17); Pro Store `:268-332` ↔ Free Store `:545-616` (16); cache-kill block Pro `woocommerce-pos-pro.php:356-392` ↔ Free `woocommerce-pos.php:189-217`.
- `error_log(` outside Logger: 0/0. `var_dump`: 0. `print_r`/`var_export`: `Logger.php:91,105`, `i18n.php:449,520`, Pro `Support_Bundle.php:259` — deliberate.
- TODO/FIXME: 20 Free, 4 Pro. Overdue: `Admin.php:141` "Remove in WCPOS 2.0.0" (VERSION is 2.0.0 at `woocommerce-pos.php:28`); `Templates/Frontend.php:73` "remove in 1.5.0". Others: `AJAX.php:69`, `API/V1/Orders_Controller.php:254`.
- `@deprecated`: 52 Free, 1 Pro, none with a version. Concentrations: `wcpos-functions.php` 11, `API/Product_Search.php` 5, `Order_Search.php` 4, `Product_Variations_Controller.php` 5.
- `markTestSkipped`: 41 Free (`Test_Templates_Controller.php` 11, `Test_Role_Meta_Guard.php` 3, `Test_Single_Template_Save.php` 3, `Test_Uninstall.php` 2 symlinks), 11 Pro (`Test_Stores.php` 2).
- Methods ≥120 lines: 32 Free, 1 Pro. Data declarations: `Receipt_Data_Schema::get_field_tree:321` (1481), `Gallery_Registry::catalogue:45` (589), `Receipt_I18n_Labels::get_labels:31` (304), `Init::hook_rows:72` (264). Behaviour-heavy: `Receipt_Data_Builder::build_data:444` (373), `Closure_Store::create:383` (348), `Gateway_Submission::submit:49` (275), `Templates_Controller::preview_item:976` (187), `Stock_Validator::validate_order:247` (149), `API.php::rest_pre_dispatch:347` (131). Pro: `Display_Controller::register_routes:45` (136).
- Classes >1000 lines: 16 Free (Receipt_Data_Schema 2411, Templates_Controller 1692, Receipt_Data_Builder 1669, Templates.php 1592), 1 Pro (Pro_Plugin_Updater 1141).
- `extract(`: 1 (`wcpos-functions.php:609`). `eval(`: 0. `$GLOBALS[`: 6 Free (`wcpos-functions.php:176-177`, `Admin/Consent.php:248-249`), 1 Pro (`Templates.php:39`). `global $`: 237 Free (~210 `$wpdb`), 23 Pro; plugin-invented global `$wcpos_auth_instance` (`Templates/Auth.php:197`, read by `templates/auth.php:12`).
- `*_post_meta` on order ids: effectively clean (only legacy `updates/update-0.4.6.php:33-37`).
- Wrong docblocks: missing/stale `@param` at `Write_Controller.php:261`, `Digest_Index.php:535`, `Pos_Uuid.php:101,122,818`, `Variable_Prices.php:67`, `Changes_Controller.php:655`; `Activator.php:536` "Check min version of PHP" on `get_blog_ids`.
- Test adapter shipped in production: Pro `includes/Services/Update_Server_Client/Fake_Update_Server_Client.php`.
- Deprecated WP hook `wpmu_new_blog` in both Activators (Free `:47`, Pro `:39`).
- Interfaces/adapters (Free+Pro): `StoreInterface` 1 hierarchy, 0 consumers (hypothetical); `Collection_Writer_Interface` 4 (real); cloud-print `Provider_Adapter_Interface` base for 4, `Poll_` 2, `Push_` 2 (real); `Receipt_Output_Adapter_Interface` 6; `Receipt_Renderer_Interface` 3; `Settings_Section_Interface` 9+; `Fast_Path_Behavior` 1 (hypothetical); `Proxy_Behavior` 8; `Gateway_Adapter_Interface` 2; `Capture_Mode_Handler_Interface` 5; `Idempotency_Store` 2; `Gateway_Registry` 2; `Update_Server_Client` 2; Pro `Provider_Adapter_Interface`/`Device_Provider_Adapter_Interface` only test simulators in-repo (extension seam). Two `Provider_Adapter_Interface` names in different namespaces.

## C) Possible live defects
1. (VERIFIED) Under Pro, Free's WooCommerce minimum is lowered from 9.0 to 5.3: Pro defines `WCPOS\WooCommercePOS\WC_MIN_VERSION` '5.3' (`woocommerce-pos-pro.php:133-134`, header `:17`); Free raised to 9.0 in #1994 (`woocommerce-pos.php:18,68`). Pro never runs Free's main file, so `Activator::woocommerce_check` (`Activator.php:370`) admits WooCommerce 5.3–8.x.
2. (VERIFIED) `wcpos_payments_sweep` (10-min recurring, `Payments_Sweeper.php:17,23-24`) missing from `uninstall.php:64-78`; drift test `Test_Uninstall.php:313-327` did not catch it. Low: `Deactivator.php:74` clears it.
3. `upgrade_business_days` (`Activator.php:309,321`) and `Fiscal_Record_Store::install` (`:96`) throw RuntimeException from the `woocommerce_init` closure (`Activator.php:479-500`, try/finally, no catch) and from activation — on DB failure every admin page load fatals until it succeeds.
4. Pro `uninstall.php` never drops Free's 8 `wcpos_*` tables or cron hooks even when `woocommerce_pos_pro_uninstall_free_installed()` is false (`:42-52`, `:205`). Needs a ruling.
5. `wcpos_admin_request()` dead; fallback checks `$_SERVER['HTTP_X_woocommerce_pos_ADMIN']` (PHP uppercases keys) and a case-sensitive `getallheaders` lookup; 0 callers (`wcpos-functions.php:196-211`).
6. Pro's 2.0.0 migration calls `__()` before `init` (`update-2.0.0.php` → `Registers.php:73` on `plugins_loaded@20`): WP 6.7+ "translation loaded too early" notice.
