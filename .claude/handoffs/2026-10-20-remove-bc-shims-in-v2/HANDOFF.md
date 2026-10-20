# Handoff: remove the backward-compatibility shims in v2 (Free and Pro)

Written 2026-10-20 by the session that shipped wcpos/woocommerce-pos#2371. Paul's ruling, same day: **the backward-compatibility shims stay in 1.11.x and are removed in v2, in both Free and Pro.** The removal scope is the inventory rows below that carry a replacement or a removal note. Rows whose note says keep, leave, or out of scope (`get_tax_id()`, the augmentation projection callbacks kept for uninstall, the `'deleted'` journal op, the Pro update-client payload shapes) are listed so nobody mistakes them for shims; they are contracts and are not removed by this work.

## Why this exists

#2371 found `Products::product_set_stock()` kept under the repo's "preserve backward compatibility on public methods" rule although nothing in Free, Pro, `next` or the tests called it, and the WooCommerce behaviour it worked around had been fixed in WooCommerce 3.4.0 (woocommerce/woocommerce#19595, May 2018). **That method, its two hook registrations and its tests were deleted in #2371 itself; they are not part of this v2 work and appear in no table below.** Paul: *"How many other old functions have we kept with this rule"* and then *"I will leave them in 1.11.x, but they should be removed in v2 for free and pro."* This document is the inventory of everything else, and the procedure.

## Lanes and timing

- Free: `next` is live (843 commits ahead of `main` on 2026-10-20). Pro: `next` is live (183 ahead). Both removals target `next`, branched from `origin/next`.
- Removal of anything a client, Pro, or third-party code reads is a minor/major-only change under the patch-compatibility rule in `CLAUDE.md` (deprecate in `X.Y`, remove in `X.Y+2` at the earliest). v2 satisfies that for everything marked `@deprecated` on 1.x. Items that are *not* yet marked `@deprecated` on `main` (the "kept for compatibility" comments below) should get the tag in a 1.11.x patch first, so the public record shows a deprecation before the removal. That tagging PR targets `main` and syncs into `next`.
- Pro depends on Free. Remove a Free symbol only after the matching Pro `next` branch no longer references it; open the Pro PR first or in the same batch and link both.

## Procedure

0. **Re-run the inventory on `next` before touching anything.** The audit beside this file is a snapshot of Free `main` at `ae0bf692` and a Pro checkout that carried uncommitted staged changes, and it did not inventory public methods declared in traits. Pin a clean Free `origin/next` and Pro `origin/next` commit, re-run the full declaration-and-caller inventory there (classes **and** traits; the prompt that produced the audit is reproduced in `AUDIT-uncalled-public-methods.md`'s method section), and work from that result. A method that had a caller on `main` may have lost it on `next`, and the reverse; the tables here tell you what to expect, not what to delete.

Per symbol:

1. Grep where the consumer lives, not just the plugin repos. For a PHP symbol or a hook: Free `includes/`, `tests/`, `templates/`; Pro `includes/`, `tests/`, `templates/`; and Free + Pro `origin/next`, for the bare name, `->name(`, `::name(`, `'name'`, `"name"` (string hook callbacks and REST `callback` arrays count as callers). For anything a client reads (a REST route, response field such as `preview_html`, header, option, journal op): also the app, `../monorepo`, every package that talks to the plugin, on its current lane. For a hook or filter: also the `wcpos/extensions` catalog and the extension pages on `docs.wcpos.com`. A clean grep of Free and Pro alone does not make a client-facing field dead.
2. If a caller exists in `next`, migrate the caller to the replacement named in the `@deprecated` tag, in the same PR.
3. Delete the symbol and its direct test. **Do not add a test that the symbol is absent** (Paul, 2026-10-20: *"don't test the hook is absent, that is stupid"*). Keep or write a test of the behaviour the replacement provides.
4. One line in the changelog's "Developers:" entry naming the removed symbol and its replacement.

## Free inventory (`includes/`, excluding `includes/Vendor/`)

### Marked `@deprecated` on `main` (54 tags)

| File | Symbol | Replacement per tag |
|---|---|---|
| `includes/wcpos-functions.php` 574–720 | `woocommerce_pos_url()`, `woocommerce_pos_request()`, `woocommerce_pos_admin_request()`, `woocommerce_pos_get_settings()`, `woocommerce_pos_json_encode()`, `woocommerce_pos_locate_template()`, `woocommerce_pos_trim_html_string()`, `woocommerce_pos_doc_url()`, `woocommerce_pos_faq_url()`, `woocommerce_pos_is_pos_order()` | the `wcpos_*` function of the same name |
| `includes/API/Order_Search.php` | whole class `Order_Search` (`terms()` and two more static methods) | `Sync\Collection_Rules` plan |
| `includes/API/Product_Search.php` | whole class `Product_Search` (`posts_search()` and three more static methods) | `Sync\Collection_Rules` plan |
| `includes/API/V1/Settings.php:354` | `payment_gateways_settings()` | `Payment_Gateways_Section::free_tier_rule()`. **The tag says `@deprecated 2.0.0`, which would deprecate and remove in the same release.** Retag it `1.11.x` in the 1.11.x tagging PR (it has been dead since the section landed on `main`), so a released version carries the deprecation before v2 removes it; if that PR does not ship before 2.0, defer this one row to 2.2 |
| `includes/API/V1/Products_Controller.php` 394, 600, 614, 631 | `wcpos_posts_search()`, `wcpos_posts_join_to_products_search()`, `wcpos_posts_groupby_product_search()`, `wcpos_posts_where_product_exclude_online_only()` | Collection Rules installs the behaviour |
| `includes/API/V1/Product_Variations_Controller.php` 297, 310, 323, 357, 456 | `wcpos_posts_search()`, `wcpos_posts_join_to_posts_search()`, `wcpos_posts_groupby_posts_search()`, `wcpos_posts_where_product_variation_exclude_online_only()`, `wcpos_posts_clauses()` | Collection Rules |
| `includes/API/V1/Coupons_Controller.php:163` | `wcpos_check_permissions()` | `Permission_Rules::wc_filter()` |
| `includes/API/V1/Print_Jobs_Controller.php:37` | const `EPSON_SDP_PRINT_TIMEOUT_MS` | `Epson_Sdp_Adapter::EPSON_SDP_PRINT_TIMEOUT_MS` |
| `includes/API/V1/Traits/Product_Helpers.php:33` | `wcpos_get_barcode_field()` | `Barcode_Field::meta_key()` (named in the tag; "no caller remains in this repository") |
| `includes/API/V1/Traits/Query_Helpers.php:16` | `wcpos_combine_meta_queries()` | Customers_Controller's private copy |
| `includes/API/V2/Variations_Controller.php` 414, 462 | `wcpos_posts_clauses()` ("retained for Pro callers" — check Pro `next`), `group_search_results()` | Collection Rules |
| `includes/API/V2/Write_Controller.php:719` | `wcpos_check_permissions()` | `Permission_Rules::wc_filter()` |
| `includes/Sync/Pos_Visibility.php` 249, 263 | `online_only_product_ids()`, `online_only_variation_ids()` | `hidden_ids( self::PRODUCTS, $scope )` and `hidden_ids( self::VARIATIONS, $scope )` respectively; the constants are strings, so never combine them with `\|`, and a real union is `self::CATALOG` |
| `includes/Sync/Integrity_Digest.php` 349, 480, 548, 558 | `customer_digest_select_sql()`, `upsert_order_digest()`, `upsert_digest()`, `upsert_customer_digest()` | `Digest_Index::customer_digest_select_sql()`, `record_order_saved()`, `record_post_saved()`, `record_customer_saved()` |
| `includes/Services/Customer_Account_Guard.php` | whole class: `protected_capabilities()`, `can_modify()`, `allow_target_roles()`, `denial()` | `Permission_Rules::*` |
| `includes/Services/Auth.php` 33, 40, 47 | consts `MAX_SESSIONS_PER_USER`, `SESSION_EVICTION_IDLE_SECONDS`, `MAX_SESSIONS_ROW_BYTES` | `Session_Registry::*` |
| `includes/Services/Auth.php:642` | `generate_token()` | `generate_access_token()` |
| `includes/Services/Cloud_Print_Relay_Service.php:169` | `disable()` (stored flag no longer consulted) | the documented opt-out |
| `includes/Services/Settings.php:633` | `use_jwt_as_param_enabled()` (setting removed) | none |

### Kept "for backward compatibility" without a `@deprecated` tag (tag these in 1.11.x first)

| File | Symbol | Note in code |
|---|---|---|
| `includes/Templates.php:765` | `Templates::set_active_template()` | "legacy method for backwards compatibility" |
| `includes/Admin.php:132` | filter `woocommerce_pos_single_product_admin_class` | "Backwards compatibility for WCPOS Pro 1.4.2 and below" |
| `includes/API/V1/Settings.php:256` | the cloud-assignment sanitizer on the v1 Settings controller (delegates to `Cloud_Print_Section::sanitize_assignment()`) | kept only because `Settings_CloudPrint_Test` reaches it through `ReflectionMethod`; move the test to the section and drop the delegate |
| `includes/API/V1/Templates_Controller.php` 967, 1010 | `preview_html` response key | "backward compatibility and diagnostics only" — a response field: a client-facing removal, check the app |
| `includes/API/V1/Orders_Controller.php:560` | `wcpos_order_item_fee_after_calculate_taxes()` | "preserved for backward compatibility and delegates" |
| `includes/Services/Stock_Validator.php:663` | non-prefixed public filter | "backward-compatible public filter" — third-party code may hook it; deprecate loudly |

### Contracts, not shims (listed so nobody tags or removes them)

These carry "compatibility" comments but are live contracts. They get no `@deprecated` tag and are not part of the v2 removal.

| File | Symbol | Why it stays |
|---|---|---|
| `includes/Abstracts/Store.php:648` and `includes/Interfaces/StoreInterface.php:376` | `get_tax_id()` | receipt templates in the wild read `{{store.tax_id}}`; it moves only with the receipt data contract (ADR 0039) |
| `includes/Sync/Augmentation_Pipeline.php:76` | projection callbacks on public filters | kept for uninstall; not a shim |
| `includes/Sync/Sync_Journal.php:661` | `'deleted'` journal op | wire compatibility; governed by the sync protocol, not this list |

## Pro inventory (`includes/`)

| File | Symbol | Note |
|---|---|---|
| `includes/Services/LicenseSettings.php:112` | `LicenseSettings::register()` | `@deprecated 1.10.0 Use from_database()` |
| `includes/Payments/Refund_Orchestrator.php`, `RefundPlan::from_legacy_resolution()`, `Refund_Processor::plan_from_legacy_resolution()`, the `Refund_Orchestrator` constructor injection in `Refund_Processor` and `API/V1/Order_Refunds_Controller` (lines 63–80) | the whole "legacy 1.9 resolver" path | 1.9 wiring kept alive through the 1.10 refund rewrite; v2 removes the orchestrator and the dual constructor signatures together |
| `includes/Admin/Updaters/Pro_Plugin_Updater.php` 40, 50 | two methods "kept public for backward compatibility with custom code and tests" | make private or drop |
| `includes/Admin/Updaters/Extensions_Updater.php:65` | `bootstrap_legacy_updates()` | for extensions without an Update URI — product decision: are there still such extensions? |
| `includes/Services/License_Cache.php:77` | `remove_legacy_handler()` | unhooks a handler that no longer exists once the Free side is cleaned |
| `includes/Services/Settings.php:132` | `Settings::get_license_settings()` | "backward-compatible wrapper for existing callers; new code should depend on LicenseSettings directly" |
| `includes/Services/LicenseSettings.php:188` and `Update_Server_Client/*` "backward-compatible payload/error shape" | serialisation shapes | transient/wire shapes, not dead code; keep unless the client contract changes |

## Uncalled public methods with no marker at all

A Codex (GPT-6 Astra, read-only) audit on 2026-10-20 tokenised every public class method under Free `includes/` (1,690 declarations in 299 files, `includes/Vendor/` excluded) and grepped Free, Pro and both test trees for callers, with hook/REST/CLI callback registrations, interface and parent overrides (WooCommerce gateways, REST controllers, Dompdf, `StoreInterface`) and magic methods counted as callers. Full report with per-row evidence: `AUDIT-uncalled-public-methods.md` beside this file. It ran against `main` at `ae0bf692`, before #2371, so it still lists `Products::product_set_stock()` as hooked.

| class | count |
|---|---|
| **Dead** (no caller anywhere, no retention comment) | 16 |
| **Test-only** (only `tests/` calls it) | 45 |
| **Kept on purpose** (`@deprecated` or a retention comment) | 38 |

The **Test-only** group splits in two, and the audit does not split it for you. Test seams the suite relies on (`reset_request_state()`, `reset_instance()`, `set_*_for_testing()`, `unregister_*()`, `armed()`/`constructed()`) are test tooling and stay. A production method whose only "caller" is its own direct unit test is dead: a method's own test is not a consumer, and it goes with the method. The audit's own example is `Products::filter_category_count_exclude_pos_only()` (`includes/Products.php:315`): its hook registration is commented out at `Products.php:63` and its sole executable use is a direct test, so it belongs in the removal list below. Walk the 45 Test-only rows in `AUDIT-uncalled-public-methods.md` with that distinction; candidates of the second kind at a glance include `Gateways\Cash::payment_details()`, `Abstracts\Store::get_tracking_consent()`, `Services\Analytics::identify()`, `Services\Cashier::has_store_access()` and `update_last_access()`, and `Templates::get_default_template()`, `is_active_template()`, `migrate_active_template_to_order()`; confirm each with the consumer grep before deleting.

The **16 Dead** methods are the ones nobody flagged. Remove in v2 after the grep in the procedure above confirms `next` has not grown a caller:

| file:line (Free `includes/`) | method | audit note |
|---|---|---|
| `API.php:287` | `get_auth_header()` | callers use `Services\Auth` / `Core_Order_Audit_Guard`, not this wrapper |
| `API/V1/Products_Controller.php:407` | `wcpos_posts_clauses()` | no caller, no retention tag (its variation twin has one) |
| `Admin/Menu.php:117` | `analytics_menu_items()` | only a commented-out hook at `Admin/Menu.php:71` |
| `Services/Order_Write_Intent.php:140` | `subject()` | grep hits are email `subject` keys |
| `Services/Print_Job_Service.php:612` | `oldest_pending_gmt()` | hits are response/test field keys |
| `Services/Receipt_Date_Formatter.php:78` | `from_wc_datetime()` | no matches |
| `Services/Settings.php:497` | `delete_settings()` | two comments mention it; nothing calls it |
| `Services/Settings.php:683` | `receipt_default_mode()` | hits are setting/schema keys |
| `Sync/Collection_Rules_Plan.php:235` | `collection()` | hits are collection/request keys |
| `Sync/Collection_Rules_Plan.php:244` | `storage()` | hits are metadata keys and a comment |
| `Templates/Auth.php:169` | `get_state()` | no matches |
| `Templates/Validator.php:68` | `validate()` | production `validate` calls target `Hook_Manifest` |
| `Templates/Validator.php:92` | `sanitize()` | calls target settings-section sanitisation |
| `Templates/Validator.php:112` | `can_edit_templates()` | no matches |
| `Templates/Validator.php:125` | `log_validation()` | no matches |
| `Templates.php:1234` | `install_starter_template()` | no matches |
| `Products.php:315` | `filter_category_count_exclude_pos_only()` | audit filed it Test-only; its hook registration is commented out at `Products.php:63` and the only use is its own direct test, which goes with it |

`Templates\Validator` is four dead methods in one class: check whether the whole class goes. The audit's **Kept on purpose** table also surfaces two retention comments my grep missed: `Services/Settings.php:219–289`, the six public `get_{id}_settings()` delegates ("Public get_{id}_settings() delegates…"), and `Sync/Order_Serializer.php:120` `augment_order_payload()` ("retained as the named shorthand…; `document()` is the entry point new callers should use"). Both belong in the second Free table above.

Limits the audit states or that review found: it is a `main` snapshot plus a Pro checkout with uncommitted staged changes, so step 0 above re-runs it on clean `next`; it inventoried class methods only, so public methods declared in **traits** are outside all three counts (`API/V1/Traits/Product_Helpers::wcpos_pos_only_products_enabled()` has no reference in Free source or tests beyond its declaration and is a candidate the re-run must classify); third-party plugins and customer integrations are unverified by construction; it is not a transitive-reachability analysis (a method called only from another dead method still counts as called); and it did not run anything.

## How to run it (self-contained; nothing here depends on personal tooling)

- One worktree per repo, branched from the lane: `git fetch origin next && git worktree add ../wt-free -b chore/remove-bc-shims-v2 origin/next` (same in Pro). Confirm the lane first with `git rev-list --left-right --count origin/main...origin/next` as `CLAUDE.md` describes.
- Tests run through wp-env only (`pnpm exec wp-env start`, then the PHPUnit commands in `CLAUDE.md`); run the classes that cover each touched area, and let CI run the matrix.
- Delegation is fine for the mechanical part once the consumer grep is clean (the caller migration and the changelog line belong in the brief), but the grep and the split of test seams from dead wrappers are judgement calls and stay with the agent that owns the PR.
- Two PRs, Free and Pro, opened together, each linking the other, base `next` on both, Pro first or in the same batch (Pro references Free symbols). Review them against this document as the spec.

## Not in scope

- The sync-protocol items (`'deleted'` journal op, response shapes in Pro's update client) are contract changes and belong to the v2 protocol work, not to this cleanup.
- `get_tax_id()` is template-facing; it moves only with the receipt data contract (ADR 0039 territory).
