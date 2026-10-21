# Audit: public methods without callers

**Scanned 1,690 public class-method declarations across Free’s 299 `includes/` PHP files, excluding `includes/Vendor/`. Found 16 Dead, 45 Test-only, and 38 Kept on purpose: 99 methods without an attributable production caller in the requested scope.** Classifications are static-analysis inferences from observed source and search results, not proof that removal is safe for third-party consumers.

## Scope and method

Audited the current local working trees:

- Free HEAD: `ae0bf692a06eb9a437ed8ea2dabf77cae6e59b2b`
- Pro HEAD: `241c3cb2a1b141d9b8b018392b540b3f31e607a9`, including its existing staged changes.
- Pro has no top-level `templates/` directory in this checkout.

Inventory used PHP tokenization, including methods whose names tokenize as keywords. Interface declarations and methods declared in traits were not counted as **class-method declarations**.

Caller searches covered the requested directories and entry-point files, including both test trees. I ran batched, case-insensitive ripgrep searches equivalent to:

```text
(?:->|::)\s*METHOD\s*\(|['"]METHOD['"]
```

I then checked callback registrations, dynamic dispatch, class identity, inheritance, interfaces, magic methods, and retention comments. WordPress/WooCommerce parent declarations were inspected in the installed local wp-env source; Dompdf overrides were checked against its prefixed source. No WordPress application code or tests were executed.

**Evidence notation:**

- Source paths below are relative to Free’s `includes/`.
- `T/` means Free’s `tests/includes/`.
- `PT/` means Pro’s `tests/includes/`.
- **G** means the search above for that row’s method, followed by attribution to the declaring class. Comments, ordinary string keys, and calls to unrelated same-named methods are identified rather than counted as callers.
- Explicit retention evidence takes precedence over Test-only.

**Important checkout discrepancy:** `Products::product_set_stock()` is **not** included. This checkout still registers it on both stock hooks at `includes/Products.php:47–48`.

## Dead and Test-only, by class

### `API`

| file:line | method | classification | evidence |
|---|---|---|---|
| `API.php:287` | `get_auth_header` | Dead | G finds calls to `Services\Auth` and `Core_Order_Audit_Guard`, not this wrapper; no attributable production or test caller. |

### `API\V1\Products_Controller`

| file:line | method | classification | evidence |
|---|---|---|---|
| `API/V1/Products_Controller.php:407` | `wcpos_posts_clauses` | Dead | G returns no call/callback matches in production or tests; unlike its similarly named variation counterparts, it has no retention annotation. |

### `Abstracts\Store`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Abstracts/Store.php:509` | `get_tracking_consent` | Test-only | G: no production matches; calls at `T/Abstracts/Test_Store_Abstract.php:443,454`; not declared by `StoreInterface` or the inspected parent. |

### `Admin\Menu`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Admin/Menu.php:117` | `analytics_menu_items` | Dead | G returns only the commented-out hook at `Admin/Menu.php:71`; no executable registration or test caller. |

### `Gateways\Cash`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Gateways/Cash.php:56` | `payment_details` | Test-only | G: no production matches; actual call at `T/Gateways/Test_Cash_Gateway.php:94`; not a gateway-parent override. |

### `Init`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Init.php:572` | `ensure_order_services` | Test-only | G: production hits are documentation references; actual calls at `T/Test_Lazy_Service_Construction.php:207–208`. |
| `Init.php:581` | `reset_request_state` | Test-only | G: no production matches; class-specific call at `T/Test_Lazy_Service_Construction.php:39`. |

### `Logger`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Logger.php:67` | `set_log_level` | Test-only | G: no production matches; calls at `T/Services/Test_Error_Reporter.php:568,570`. |
| `Logger.php:160` | `reset_dedup_state` | Test-only | G: no production matches; call at `T/API/Print_Jobs_CloudPRNT_Test.php:42`, among others. |

### `Products`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Products.php:315` | `filter_category_count_exclude_pos_only` | Test-only | G: production registration is commented out at `Products.php:63`; actual call at `T/Test_Products_Direct.php:631`. |

### `Services\Analytics`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Analytics.php:117` | `reset_instance` | Test-only | G: no production matches; class-specific call at `T/Admin/Test_Menu_Analytics.php:41`. |
| `Services/Analytics.php:271` | `identify` | Test-only | G: no production matches; calls at `T/Services/Test_Analytics.php:161,234`. |

### `Services\Auth`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Auth.php:484` | `generate_refresh_token` | Test-only | G: no production matches; call at `T/API/Test_Auth_API.php:127`, among others. |

### `Services\Cashier`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Cashier.php:142` | `has_store_access` | Test-only | G: no production matches; calls at `T/Services/Test_Cashier_Service.php:358,367`. |
| `Services/Cashier.php:182` | `update_last_access` | Test-only | G: no production matches; calls at `T/Services/Test_Cashier_Service.php:402,414`. |

### `Services\Error_Reporter`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Error_Reporter.php:132` | `reset_instance` | Test-only | G: no production matches; class-specific call at `T/Services/Test_Error_Reporter.php:39`. |
| `Services/Error_Reporter.php:145` | `set_transport_factory_for_testing` | Test-only | G: no production matches; call at `T/Services/Test_Error_Reporter.php:41`. |
| `Services/Error_Reporter.php:160` | `set_dev_override_for_testing` | Test-only | G: no production matches; call at `T/Services/Test_Error_Reporter.php:46`. |
| `Services/Error_Reporter.php:168` | `is_initialized` | Test-only | G: production hit calls `ActionScheduler`, not this class; actual call at `T/Services/Test_Error_Reporter.php:110`. |

### `Services\Order_Write_Intent`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Order_Write_Intent.php:135` | `set_paid` | Test-only | G: production matches are request/data keys; actual method call at `T/Services/Test_Order_Write_Intent.php:77`. |
| `Services/Order_Write_Intent.php:140` | `subject` | Dead | G: no production matches; test matches are email `subject` keys, not calls; no attributable caller. |

### `Services\PrintNode_Client`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/PrintNode_Client.php:52` | `whoami` | Test-only | G: no production matches; call at `T/Services/PrintNode_Client_Test.php:84`, among others. |

### `Services\Print_Job_Service`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Print_Job_Service.php:612` | `oldest_pending_gmt` | Dead | G returns response/test field keys, including `API/V1/Print_Jobs_Controller.php:633`; no method call or callback. |
| `Services/Print_Job_Service.php:1032` | `claim` | Test-only | G: production call targets `Idempotency_Repository`; print-job call at `T/API/Print_Jobs_Controller_Test.php:130`, among others. |

### `Services\Provider`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Provider.php:224` | `content_type` | Test-only | G: production hits are data keys or adapter-interface calls; `Provider::content_type()` called at `T/Services/Provider_Test.php:57`. |
| `Services/Provider.php:235` | `poll_endpoint` | Test-only | G: production hits are capability-array keys; actual call at `T/Services/Provider_Test.php:73`. |
| `Services/Provider.php:246` | `supports_server_diagnostic` | Test-only | G: production hits are capability-array keys; actual call at `T/Services/Provider_Adapter_Test.php:301`. |

### `Services\Receipt_Data_Schema`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Receipt_Data_Schema.php:1383` | `get_json_schema` | Test-only | G: no production matches; calls at `T/Services/Test_Receipt_Data_Schema.php:220`, among others. |

### `Services\Receipt_Date_Formatter`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Receipt_Date_Formatter.php:78` | `from_wc_datetime` | Dead | G returns no call/callback matches in production or tests. |

### `Services\Service_Groups`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Service_Groups.php:132` | `armed` | Test-only | G: no production matches; call at `T/Services/Test_Service_Groups.php:77`. |
| `Services/Service_Groups.php:143` | `constructed` | Test-only | G: no production matches; call at `T/Services/Test_Service_Groups.php:44`. |

### `Services\Settings\Section_Registry`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Settings/Section_Registry.php:58` | `has` | Test-only | G: no production matches; call at `T/API/Test_Settings_API.php:257`, plus registry tests. |

### `Services\Settings`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Settings.php:98` | `reset_sections_for_testing` | Test-only | G: no production matches; call at `T/API/Test_Settings_API.php:77`. |
| `Services/Settings.php:497` | `delete_settings` | Dead | G returns two production comments referring to it; no executable call/callback or test caller. |
| `Services/Settings.php:683` | `receipt_default_mode` | Dead | G returns setting/schema/test keys, not invocation of this accessor; no attributable caller. |

### `Services\Tax_Id_Detector`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Tax_Id_Detector.php:359` | `reset_request_state` | Test-only | G: no production matches; class-specific call at `T/Services/Test_Tax_Id_Writer.php:229`. |

### `Services\Tax_Id_Types`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Services/Tax_Id_Types.php:237` | `default_label` | Test-only | G: no production matches; call at `T/Services/Test_Tax_Id_Types.php:82`, among others. |

### `Sync\Collection_Rules_Plan`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Collection_Rules_Plan.php:235` | `collection` | Dead | G returns numerous collection/request keys, but no `collection()` method call or callback. |
| `Sync/Collection_Rules_Plan.php:244` | `storage` | Dead | G returns metadata keys and a comment, not invocation of this accessor; no test caller. |
| `Sync/Collection_Rules_Plan.php:262` | `sort` | Test-only | G: production hit is a documentation reference; actual call at `T/Sync/Test_Collection_Rules_Plan.php:182`. |
| `Sync/Collection_Rules_Plan.php:271` | `claims` | Test-only | G: no production matches; call at `T/Sync/Test_Collection_Rules_Plan.php:97`, among others. |

### `Sync\Collections`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Collections.php:395` | `names` | Test-only | G: production hit is WooCommerce’s `'fields' => 'names'` argument; actual call at `T/Sync/Test_Collections.php:55`. |

### `Sync\Integrity_Digest`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Integrity_Digest.php:295` | `unregister_proxy_digest_stampers` | Test-only | G: no production matches; call at `T/API/WCPOS_REST_Unit_Test_Case.php:292`. |
| `Sync/Integrity_Digest.php:436` | `reset_request_state` | Test-only | G: no production matches; class-specific call at `T/Sync/Sync_Store_Test_Case.php:45`. |

### `Sync\Meta_Normalizer`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Meta_Normalizer.php:51` | `reset_request_state` | Test-only | G: no production matches; class-specific call at `T/API/Test_Customers_Controller.php:245`. |
| `Sync/Meta_Normalizer.php:67` | `unregister_hooks` | Test-only | G: no production matches; call at `T/API/WCPOS_REST_Unit_Test_Case.php:293`. |

### `Sync\Proxy_Uuid_Stamper`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Proxy_Uuid_Stamper.php:84` | `unregister_proxy_stampers` | Test-only | G: no production matches; call at `T/API/V2/Test_Catalog_Proxy_Cache_Priming.php:32`. |

### `Sync\Request_Write_Queue`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Request_Write_Queue.php:148` | `reset` | Test-only | G: production matches concern other classes or request keys; queue calls at `T/Sync/Test_Request_Write_Queue.php:112,116`. |

### `Sync\Revision`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Revision.php:50` | `unregister_proxy_stamps` | Test-only | G: no production matches; call at `T/API/V2/Test_Catalog_Proxy_Barcode.php:126`. |

### `Sync\Store_Scope`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Store_Scope.php:186` | `reset` | Test-only | G: production matches concern other classes or request keys; class-specific call at `T/Sync/Test_Store_Scope.php:43`, plus Pro tests. |

### `Sync\Sync_Journal`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Sync/Sync_Journal.php:568` | `reset_request_state` | Test-only | G: no production matches; class-specific call at `T/Sync/Sync_Store_Test_Case.php:44`. |
| `Sync/Sync_Journal.php:923` | `oldest_sequence` | Test-only | G: no production matches; call at `T/Sync/Test_Sync_Journal_Purge.php:396`, among others. |

### `Templates\Auth`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Templates/Auth.php:169` | `get_state` | Dead | G returns no call/callback matches in production or tests. |

### `Templates\Validator`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Templates/Validator.php:68` | `validate` | Dead | G: production calls target `Hook_Manifest`; test calls target unrelated test helpers; no caller of this validator. |
| `Templates/Validator.php:92` | `sanitize` | Dead | G: calls target settings-section sanitization; remaining matches are data keys/comments; no caller of this validator. |
| `Templates/Validator.php:112` | `can_edit_templates` | Dead | G returns no call/callback matches in production or tests. |
| `Templates/Validator.php:125` | `log_validation` | Dead | G returns no call/callback matches in production or tests. |

### `Templates`

| file:line | method | classification | evidence |
|---|---|---|---|
| `Templates.php:679` | `get_default_template` | Test-only | G: no production matches; call at `T/Test_Templates.php:905`. |
| `Templates.php:891` | `is_active_template` | Test-only | G: no production matches; call at `T/Test_Templates.php:749`, among others. |
| `Templates.php:1023` | `migrate_active_template_to_order` | Test-only | G: no production matches; call at `PT/Test_Templates_Resolution.php:110`. |
| `Templates.php:1234` | `install_starter_template` | Dead | G returns no call/callback matches in production or tests. |

## Kept on purpose — 38 methods

All methods below lack an attributable production caller in scope. Some retain test callers.

| file:line and method | Quoted justification |
|---|---|
| `API/Order_Search.php:27` `terms`; `:39` `hpos_where`; `:57` `posts_where` | Each: “`@deprecated Use the Collection Rules plan.`” File comment: “Legacy WCPOS search API; retained for Pro for one release.” |
| `API/Product_Search.php:65` `posts_orderby` | “`@deprecated Use the Collection Rules plan.`” |
| `API/V1/Coupons_Controller.php:170` `wcpos_check_permissions` | “`@deprecated Use Permission_Rules::wc_filter().`” Same-name test calls target `Write_Controller`, not this class. |
| `API/V1/Orders_Controller.php:565` `wcpos_order_item_fee_after_calculate_taxes` | “This public method is preserved for backward compatibility and delegates.” |
| `API/V1/Product_Variations_Controller.php:297` `wcpos_posts_search`; `:310` `wcpos_posts_join_to_posts_search`; `:323` `wcpos_posts_groupby_posts_search`; `:357` `wcpos_posts_where_product_variation_exclude_online_only`; `:456` `wcpos_posts_clauses` | Each: “`@deprecated Collection Rules now installs this behavior.`” |
| `API/V1/Products_Controller.php:394` `wcpos_posts_search`; `:600` `wcpos_posts_join_to_products_search`; `:614` `wcpos_posts_groupby_product_search`; `:631` `wcpos_posts_where_product_exclude_online_only` | Each: “`@deprecated Collection Rules now installs this behavior.`” |
| `API/V1/Settings.php:354` `payment_gateways_settings` | “`@deprecated 2.0.0 Use Payment_Gateways_Section::free_tier_rule().`” Pro’s same-name callbacks target other classes. |
| `API/V2/Variations_Controller.php:414` `wcpos_posts_clauses` | “`@deprecated Collection Rules now installs this behavior; retained for Pro callers.`” |
| `API/V2/Variations_Controller.php:462` `group_search_results` | “`@deprecated Collection Rules owns variation grouping.`” |
| `API/V2/Write_Controller.php:719` `wcpos_check_permissions` | “`@deprecated Use Permission_Rules::wc_filter().`” |
| `Services/Auth.php:648` `generate_token` | “Legacy method for backward compatibility.” / “`@deprecated Use generate_access_token() instead`” |
| `Services/Cloud_Print_Relay_Service.php:174` `disable` | “`@deprecated The stored flag is no longer consulted; opt out with the` … `woocommerce_pos_cloud_print_relay_enabled` filter.” |
| `Services/Customer_Account_Guard.php:22` `protected_capabilities` | “`@deprecated Use Permission_Rules::protected_capabilities().`” |
| `Services/Customer_Account_Guard.php:34` `can_modify` | “`@deprecated Use Permission_Rules::can_modify().`” |
| `Services/Customer_Account_Guard.php:45` `allow_target_roles` | “`@deprecated Use Permission_Rules::allow_target_roles().`” |
| `Services/Customer_Account_Guard.php:55` `denial` | “`@deprecated Use Permission_Rules::denial().`” |
| `Services/Settings.php:219` `get_general_settings`; `:243` `get_tax_ids_settings`; `:254` `get_checkout_settings`; `:265` `get_access_settings`; `:278` `get_tools_settings`; `:289` `get_license_settings` | Shared comment: “Public get_{id}_settings() delegates are supported read API for Pro and extensions. Keep them as non-deprecated facades until that public surface is intentionally replaced.” |
| `Services/Settings.php:633` `use_jwt_as_param_enabled` | “`@deprecated The use_jwt_as_param setting was removed.`” The comment further states that this method always returns false. |
| `Sync/Integrity_Digest.php:349` `customer_digest_select_sql` | “`@deprecated Use {@see Digest_Index::customer_digest_select_sql()}.`” Production calls target `Digest_Index`, not this wrapper. |
| `Sync/Integrity_Digest.php:480` `upsert_order_digest` | “`@deprecated Use record_order_saved().`” |
| `Sync/Integrity_Digest.php:548` `upsert_digest` | “`@deprecated Use record_post_saved().`” |
| `Sync/Integrity_Digest.php:558` `upsert_customer_digest` | “`@deprecated Use record_customer_saved().`” |
| `Sync/Order_Serializer.php:120` `augment_order_payload` | “Retained as the named shorthand for the three payload augmentations (links excluded); `document()` is the entry point new callers should use.” |
| `Templates.php:771` `set_active_template` | “Set template as active (legacy method for backwards compatibility).” |

## Important exclusions

These were checked rather than mistaken for dead methods:

- **Gateway overrides:** `payment_fields`, `process_payment`, and `process_refund` implement methods on `WC_Payment_Gateway`.
- **REST overrides:** inherited controller methods were excluded even where local grep found no explicit caller.
- **Store contracts:** methods such as `get_logo_image_src` and `get_tax_id` implement `StoreInterface`; Pro also overrides Store methods.
- **Dompdf overrides:** `Pdf_Font_Metrics::getFont` and `setFontFamilies` override inspected parent methods.
- **WP-CLI:** `CLI\Anon_ID_Command` is registered as a command class at `woocommerce-pos.php:148`; its public command methods are entry points.
- **Dynamic callbacks:** controller constants, settings permission maps, collection metadata dispatch, and hook-manifest callbacks count as callers.
- **Calls from otherwise unused methods:** still count as callers. This was not a transitive reachability audit.

## Not sure / limitations

- **No unresolved method classification remains among the 99 listed candidates within the specified scope.**
- **Unverified:** third-party plugins, customer integrations, other branches, companion work not present in these checkouts, and callers outside the requested paths.
- **Not evaluated:** runtime behavior or deletion compatibility. Test-only methods include deliberate reset/injection helpers; their classification is not a recommendation to delete them.
- Historical implementation plans and generated source listings were not treated as declarations of a currently supported extension API.

## Verification

| Step | Result |
|---|---|
| Public-method inventory | **PASS** — 1,690 distinct class-method declaration locations; keyword-named methods included. |
| Caller searches | **PASS** — batched ripgrep completed with exit status 0; comments, data keys, and name collisions reviewed. |
| Framework and callback exclusions | **PASS** — local parent declarations, interfaces, registrations, and dynamic dispatch inspected. |
| Classification reconciliation | **PASS** — 16 + 45 + 38 = 99. |
| Runtime tests | **Not run**, as requested. No Composer, PHPUnit, wp-env, or Docker commands executed. |
| Report artifact | **Inline in this final message**; no report file created. |

## Behavior changes / regressions

None introduced; this was read-only.

## Blocked on you

None.

## Changed

No files modified.

## Found

16 Dead methods, 45 Test-only methods, and 38 explicitly retained methods without an attributable production caller in scope.