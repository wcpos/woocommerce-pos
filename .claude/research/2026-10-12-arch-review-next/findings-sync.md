# Explorer findings — sync engine, search, write lanes

Read on Free `837e985f` (== origin/next). Verbatim explorer output. D9 (v1 orders narrowing map has no `search` key; `Orders_Controller.php:66-80`) re-verified by the orchestrator.

## A) Deepening candidates

### 1. Search has one declaration but five executors. Strong.
- Files: `includes/Sync/Collection_Rules.php:241-475` (638); `Collection_Rules_Plan.php:545-610` (`apply_read_rule`, 1050); `Sync/Order_Search.php` (165); `Sync/Customer_Search.php` (86); `Sync/Product_Search.php` (228); `API/Order_Search.php`, `API/Product_Search.php` (deprecated forwarders); `API/V2/Variations_Controller.php:370-380`.
- The `carriers` lists are read by nothing in `includes/`; only asserted against themselves in `Test_Collection_Rules_Order_Search.php:34` and `Test_Collection_Rules_Customer_Search.php:35`.
- The query that runs is hard-coded by collection name (`Collection_Rules_Plan.php:563-606`); the lane is found by sniffing the route string (`:195`).
- Phone-digit term block copied three times: `Customer_Search.php:57-65`, `Order_Search.php:58-62`, `:96-100` (orders hard-codes `'_billing_phone','_shipping_phone'` `:98`; customers read the declared `phone_meta`).
- Term cap applied four ways: slice (`Customer_Search.php:45`, `Order_Search.php:32`), collapse (`Product_Search.php:36,135`), reject (`Variations_Controller.php:377`).
- `Product_Search::posts_search` and `variation_posts_search` share a copy-pasted opening (`:24-37` vs `:124-137`).
- Customers bypass the Plan: `Customers_Controller.php:696`, `Customers_Proxy_Behavior.php:186` call `Customer_Search::where`.
- Tests: `Test_Collection_Rules_Search_Parity` covers products and variations on both lanes, not orders/customers; `Catalog_Proxy_Order_Search_Tests` v2 only; `Test_Product_Search_Contract` tests the deprecated shim.
- Pro: never referenced `API\*_Search` in its git history.

### 2. Collection Rules have two installation modes, and the v1 one leaks. Strong.
- `Collection_Rules_Plan.php:308-390` (`filter()` vs `around()`); `API/V1/Orders_Controller.php:944-1136` (1228); `API/V1/Products_Controller.php:430-435, 645-668` (724); `API/V2/Variations_Controller.php:176-189` (562).
- v1 orders adds hooks with `add_filter` never removed (`Orders_Controller.php:950, 952, 1133`); only the legacy orderby hook removes itself (`:1093-1097`). v1 products hand-codes `wcpos_include`/`wcpos_exclude` at `posts_where` priority 20, no post-type guard, no removal (`:433`, `:645-668`); the product rules have no id-set rows, so this sits outside Collection Rules entirely. `Bulk_ID_Fast_Path.php:247-256` parses the same parameter with `absint`+`explode` while the normal path uses an `intval` cast. v2 variations uses both modes in one request (`:177`, `:185`).
- `filter()` is a pass-through switch. `Test_Collection_Rules_Leak` covers `around()` only; the v1 topology is untested and called "frozen" (`Collection_Rules_Plan.php:507-511`).
- The stated reason "Pro subclasses these callbacks" (`Collection_Rules_Plan.php:31`, `Orders_Controller.php:963`) is false: Pro's v1 Orders/Products/Variations override only `wcpos_dispatch_request`, `update_item`, prepare.

### 3. Client-asserted order state is decided in three places (where the Oct 12 bugs lived). Strong.
- `API/V2/Writers/Order_Writer.php` (620; 11 next-only commits); `Services/Order_Write_Intent.php:72-73` (153); `API/V1/Orders_Controller.php:464-476, 498-506`; `Sync/Order_Write_Payload.php:171-197`.
- Three paths call `set_date_created` with a client value: intent `created_gmt` @ priority 1 (v2 create); v1's own pre-insert filter @ priority 10 (v1 create); Order_Writer's pre-insert closure for the cart restart (`:342`). Restart eligibility decided twice (`:462`, `:340`) and coupled to `Order_Lock` in `forward()` (`:159-173`).
- Three of four Oct fixes (2fd7cb7a, 61c80133, a41ea525) landed in the Order_Writer caller; only a0aa894a fixed the extracted pure function (`validate_client_created_gmt` calendar round-trip).
- Audit/provenance policy forks by lane: v1 update strips audit meta (`Orders_Controller.php:498-506`); v2 fills or refuses (`Order_Writer.php:435-520`); v1 update has no cart restart. Not recorded as a ruling on Order_Write_Payload (grep "restart" empty).
- `forward_with_order_lifecycle` (`:329-405`) holds three closures each re-deriving the intent.
- Tests: `Test_Rest_Dispatch_Cart_Restart(_HPOS)` dispatch the route (good); `Test_Orders_Controller` v1 create; `Test_Order_Writer` reflects into the constructor.

### 4. The Write_Controller↔Writer seam leaks collection knowledge back. Worth exploring. ADR 0037 conflict.
- `API/V2/Write_Controller.php` (812); `Writers/Null_Writer.php` (116). Writer interface 11 methods + four callbacks handed back (tax-id validation, forward, default document, default builder). Ordering invariants in the controller: `context_factory` runs after CAS (`:476-480`). Controller forks on collection: `revision_for` by post type/id type (`:803-811`); product augmentation (`:745-753`); post-type permission allowlist (`:439-450`). Every collection's default read goes through `Product_Serializer::dispatched_bare_read`, orders included (`:740-742`). Tests reflect into five private methods (`revision_for`, `document_for`, `envelope_document`, `validate_envelope`, `envelope`); docblocks `:724-729`, `:776-779` admit it.

### 5. Product id-space for integrity defined outside the registry, with two scopes. Worth exploring. ADR 0036/0037.
- `Changes_Controller.php:47-48, 334, 437, 456-466, 498-510, 744-745, 803-807` (838); `Digest_Index.php:101-102, 845-849` (1004); `Status_Controller.php:31`. `PRODUCT_POST_TYPES_SQL`/`EXCLUDED_POST_STATUSES_SQL` duplicated; Changes = "not trash/auto-draft", digests/fast path = "publish only". `HASH_LANE_COLLECTIONS`/`STREAM_LANE_COLLECTIONS` hard-coded, not from `Collections::with()`. `tax_rates` has four separate branches. `products_id_fast_path` advertised in Status_Controller, implemented in `Products_Proxy_Behavior`. The fast path (`Products_Proxy_Behavior.php:116-210`) skips `woocommerce_rest_product_object_query` and the proxy response filter — documented in its docblock, missing from ADR 0035's exception list.

## B) Code smells
- `API/Order_Search.php:3`, `API/Product_Search.php:3`, `V2/Variations_Controller.php:399`: "retained for Pro" is false. Free's own `V1/Products_Controller.php:21,395,601,615` still uses the deprecated shim.
- Dead deprecated callbacks hooked nowhere: `V1/Products_Controller.php:394, 600, 614, 631`; `V2/Variations_Controller.php:448`.
- `Write_Controller.php:717-720`: deprecated `wcpos_check_permissions` alive only for `Test_Write_Controller`.
- `Sync/Order_Document.php:47` docblock cites the stored revision retired by free#1757. `Order_Document::build` has one caller (`V2/Orders_Controller.php:210`) always passing the same payload twice, `partial=false`, `'custom-pull'`; `skeleton`/`snapshot` sources dead → pass-through.
- `Collection_Rules.php:82-103`: `search_terms()` between two constants under loose notes. `Collection_Rules::rules()` re-runs `apply_filters` every call; `client_search_meta_keys()` calls it 4× (`:494-495`).
- Duplicated customer query blocks: `V1/Customers_Controller.php:530-600, 739-828` vs `Proxy/Customers_Proxy_Behavior.php:112-221` (role hierarchy, CASE ladder, NUMERIC `last_update`, orderby switch).
- Stale TODOs: `V1/Orders_Controller.php:249, 254`; `V1/Customers_Controller.php:362, 437`. No `error_log`.

## C) Live defects
- C1 (VERIFIED): v1 order search lane does not use POS search — `V1/Orders_Controller.php:72-80` has no `search` key (unlike `created_via`, whose omission is commented as a ruling). `/wcpos/v1/orders?search=` gets WooCommerce native search: no AND-across-terms, line-item SKU, phone digits, order-number meta, or `woocommerce_pos_search_fields` keys. v1 customers/products do get shared search. Existing v1 tests (`Test_Orders_Controller.php:1141-1290`) only check id/name/email.
- C2 (latent): two v1 `posts_where` hooks stay installed for the whole request with no post-type guard — products include/exclude (`V1/Products_Controller.php:433`), orders id-sets (`V1/Orders_Controller.php:952`). Any later unsuppressed WP_Query inherits `ID IN (…)`.
- Suspected: `/changes/range-checksum` and `/changes/revision-hash` count draft/pending/private products (`Changes_Controller.php:456-466, 500-510`) while the served listing is publish-only (`Test_Catalog_Proxy_Products_Fast_Path.php:311-314`).
