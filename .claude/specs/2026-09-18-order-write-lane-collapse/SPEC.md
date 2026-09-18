# Order write payload: one shaper for both lanes

Candidate 2 of the 2026-09-17 architecture review, second and final slice. Paul ruled on the three contested rows on 2026-09-18 (recorded in §1). Proceed without asking questions. Revision 2: the update-envelope `recordId` and the lane-coverage command in §5 were wrong in revision 1 and are fixed; if you find any other detail that does not match the tree, take the reading that matches the tree, note it in the report, and keep going — do not stop again before editing. Stakes tier: **High** (order line items). Every behaviour claim below must be pinned by a test; where a claim turns out false, stop and report rather than widen the change.

## Why

`includes/Sync/Order_Write_Payload.php` shapes a POS order document into what the stock wc/v3 orders controller accepts. Its class docblock carries a fourteen-row table of rules the v2 lane (`API\V2\Writers\Order_Writer`) applies at the payload seam while the v1 lane (`API\V1\Orders_Controller`) applies its own copy through controller overrides (`get_product_id`, `maybe_set_item_meta_data`, `prepare_line_items`). Two copies of the same rule drift: the misc-SKU stamp already differs on trimming and typing, and the "any" attribute recovery differs on how it addresses the stored row. The seam is: **v1 shapes its request through the same `Order_Write_Payload` steps before handing the request to WooCommerce, and deletes its override copies.**

## 1. Rulings (Paul, 2026-09-18) — the rows that stay lane-specific

These rows are decided, not open. Record them in the class docblock (see §4) and do not change either lane's behaviour on them.

- **Empty coupon code.** v1 keeps skipping a `coupon_lines` entry whose `code` is `''` (its `calculate_coupons` override is untouched). v2 keeps forwarding it so wc/v3 returns 400. Reason: a released 1.x client must not start stranding orders.
- **Omitted items.** v1 keeps WooCommerce's partial-document semantics: a stored line absent from the posted document is left alone. v2 keeps adding wc/v3 deletion markers (`remove_omitted_order_items`). Reason: nothing proves every 1.x client posts the full document.
- **Incomplete tax-ID entries.** Parked until Paul's #1724 rework lands. v1 coerces, v2 rejects; touch neither `persist_tax_ids` nor `Tax_Id_Writer`.

Rows that are not payload rules and leave the table: audit phases, reserved stock, HPOS capability remaps. Client date and tax-ID persistence are already shared.

## 2. `Order_Write_Payload` changes

1. **Add `public function for_partial_update( int $order_id, array $payload ): array`.** The v1 update shape: WooCommerce partial-document semantics, so no omission markers and no coupon reconciliation. Steps, in this order, sharing the single `wc_get_order()` load exactly as `for_update` does:
   1. `reconcile_order_item_ids` (a line without an `id` whose POS UUID matches exactly one stored item becomes an update of that item instead of a duplicate create);
   2. the schema sanitization (`sanitize_order_wc_payload`), **without** the billing-email drop (see 3);
   3. `drop_unchanged_variation_line_identity` last, for the same load-bearing reason `for_update` gives.
   Docblock: say it is the v1 lane's shape, name the two rulings it omits, and point at `for_update` for the full-document shape.
2. **`for_update` is unchanged in behaviour.** Its step list stays `reconcile_order_item_ids → remove_omitted_order_items → reconcile_order_coupon_lines → sanitize → drop_unchanged_variation_line_identity`.
3. **Move `without_empty_billing_email` out of `sanitize_order_wc_payload`** and call it explicitly from `for_create` and `for_update` (keeping their current output identical). It must not run in `for_partial_update`: on a v1 update, `billing.email: ''` is a deliberate clear that WooCommerce applies directly, and dropping it would leave the stored email in place. Keep the method public; `Customer_Writer` calls it.
4. No other rule changes. The three private steps stay private.

## 3. `API\V1\Orders_Controller` changes

1. **Shape the request before the parent write.** Add a private helper (suggested name `wcpos_shape_request_payload( WP_REST_Request $request, array $shaped ): void`) that writes back, with `$request->set_param()`, each of the top-level keys the shaper may touch when present in `$shaped`: `billing`, `line_items`, `shipping_lines`, `fee_lines`, `coupon_lines`, `meta_data`. Neither shape removes a top-level key, so present-key replacement is sufficient; assert that in a comment.
   - In `create_item`, after the audit-meta sanitization and before `Order_Write_Intent::open`: `$this->wcpos_shape_request_payload( $request, $this->order_payload->for_create( $request->get_params() ) )`.
   - In `update_item`, after the audit-meta strip and before the parent call: `for_partial_update( (int) $request['id'], $request->get_params() )`.
   Shaping runs after WordPress schema validation, so v1's `get_item_schema()` relaxations (email format, nullable `parent_name`, decimal quantity) **stay**: they are what lets the raw document through validation at all. The docblock on `get_item_schema()` should say so in one sentence.
2. **Delete `get_product_id()`.** With the sku stripped from every non-misc line and the sentinel sku on misc lines, the stock `get_product_id` yields the same result: posted ids win over sku lookups, and a misc line resolves to product 0 without throwing `woocommerce_rest_required_product_reference`. Read the stock method in WooCommerce before deleting and confirm the sentinel path in a comment on the shaper if it is not already explained (it is, on `MISC_LINE_SKU_SENTINEL`).
3. **Delete `maybe_set_item_meta_data()`.** Its `_sku` stamp is now the `_sku` meta entry the shaper adds (the stock `maybe_set_item_meta_data` writes posted meta through `update_meta_data`, and `_sku` is not an internal order-item key). Its "any" attribute branch is now `recover_any_variation_attributes`: the posted key is authoritative, and display fields only fill a missing key.
4. **Delete `prepare_line_items()`** (the after-the-fact duplicate `pa_*` prune). `drop_unchanged_variation_line_identity` removes the cause for unchanged lines. This is the one deletion with a real regression surface; §5 test 4 must pass on the v1 lane **without** the prune. If it does not, keep the prune, say so in the report, and leave the table row for it.
5. **Declare write intent on update.** Wrap the parent update call in `Order_Write_Intent::open()` the way `create_item` does, mirroring the keys `Order_Writer::forward()` declares for an update: `operation => 'update'`, `id => (int) $request['id']`, `requested_status => (string) $request->get_param( 'status' )`, `set_paid` from the request the same way `create_item` computes it. Read `Order_Write_Intent::open()` and `Order_Writer::forward()`/`prepare_order_update_after_read()` first and copy the declared keys they actually use; do not invent keys.
6. Remove imports that become unused. `calculate_coupons`, `save_object`, the audit hooks, `wcpos_validate_billing_email` and everything else stay.

## 4. The class docblock table

Rewrite the "Lane differences still to be reconciled" list as two short lists:

- **Shared through this class** (both lanes): product identity and misc sku, "any" attribute recovery, variation identity dedupe, item-UUID id reconciliation, display-field and image drops, client date, tax-ID persistence. One line each, naming the v1 entry point (`create_item`/`update_item` shaping) and the v2 one.
- **Deliberately lane-specific (ruled 2026-09-18)**: the three rows in §1, one line each with the reason. Add: "v1's schema relaxations in `get_item_schema()` are the validation-time half of the same tolerance this class expresses at the forward seam."

Delete the rows for audit, reserved stock, HPOS caps and write intent (intent is now declared on both lanes' create and update).

## 5. Tests

All new cases go in `tests/includes/Sync/Test_Order_Write_Parity.php`, which already writes through both lanes (v2 first, then v1) and asserts on the **stored order**, never on a lane's response shape. Add an `update_in_both_lanes( string $v2_record_uuid, int $v2_id, int $v1_id, array $payload ): array` helper beside `create_in_both_lanes` (which must now return, or expose, the `recordId` UUID it generated). v2: a `push/orders` envelope with `operation => 'update'`, `recordId` the SAME UUID the create envelope used (the write controller requires a UUID and resolves the stored order by it — `Write_Controller.php` ~line 341), `baseRevision => Order_Serializer::canonical_revision( ( new Order_Serializer() )->serialize_order( $v2_id, new WP_REST_Request() ) )` as `Test_Write_Controller` does around line 327. v1: `PUT /wcpos/v1/orders/{id}` via `$this->wp_rest_put_request()` or the equivalent helper the base class offers. The lane-coverage gate (`tests/lane-coverage/README.md`) fails any new case whose only lane signal is `wcpos/v1`; every new case must dispatch the v2 route as well, as the existing cases do. Before you finish run `php scripts/lane-coverage.php --write` (it writes untracked inventory files under `tests/lane-coverage/`; leave them untracked) and confirm every new case's `lanes` entry names `wcpos/v2`; quote those entries in the report. Do not run `--compare`: it needs a merge-base baseline the orchestrator produces in CI.

Cases (Arrange / Act / Assert, `assertSame`, expected first):

1. `test_misc_line_both_lanes_store_the_typed_sku_as_line_meta` — create with one misc line (`product_id` 0, `sku` `' SKU-123 '`, a price). Both stored lines have product id 0, exactly one `_sku` meta equal to `'SKU-123'` (trimmed), and no product resolves from the sentinel (assert `wc_get_product_id_by_sku()` of the stored line's `_sku` is 0 for a sku that matches no catalog product).
2. `test_any_attribute_choice_both_lanes_store_the_posted_value` — a variable product with one taxonomy attribute set to "any" on the variation (reuse the helper in `Test_Order_Write_Payload` that builds one). Create with the line's meta carrying only `display_key`/`display_value`. Both stored lines carry the attribute meta under its `pa_*` key with the posted value.
3. `test_line_without_id_both_lanes_update_the_uuid_matched_item` — create, then update posting the same single line **without** its `id` but with its `_woocommerce_pos_uuid` meta and a changed quantity. Both stored orders still have exactly one line, with the new quantity and the original item id.
4. `test_variation_repush_both_lanes_keep_one_attribute_row` — create a variation order, then re-push the acknowledged full line (id, product_id, variation_id, meta with ids) twice. Both stored lines have exactly one meta row per variation attribute, and the row ids are unchanged across the two pushes (byte-stable ack). This case runs on v1 without the deleted prune; it is the #1456 guard.
5. `test_empty_billing_email_on_update_both_lanes_clear_the_stored_email` — create with a real email, update with `billing.email: ''`. Both stored orders have an empty billing email. (Pins that `for_partial_update` does not drop the clear, and that v2's writer still clears explicitly.)
6. `test_omitted_line_is_removed_on_v2_and_kept_on_v1` — create with two lines, update posting only the first. v2's stored order has one line; v1's has two. This pins the ruling, so the assertion message should cite it.

Existing tests: `Test_Orders_Controller` cases `test_order_save_line_item_attributes`, `test_order_with_miscellaneous_product_with_sku`, `test_misc_product_with_duplicate_sku_does_not_crash`, `test_order_list_with_conflicting_misc_sku_does_not_crash`, `test_update_order_with_unchanged_coupon_lines_preserves_ids`, `test_update_order_adding_new_coupon`, and everything in `Test_Order_Write_Payload`, `Test_Write_Controller`, `Test_HPOS_Orders_Controller`, `Test_Orders_Stock_Restore`, `Test_Order_Taxes`, `Test_Decimal_Quantities` must pass unchanged. Do not loosen any assertion. If a v1 case asserts on the line's `sku` in the **response**, that still holds: the synthetic-product read path serves `_sku` back. `Test_Order_Write_Payload` gains one case for `for_partial_update` (no omission marker, coupon_lines forwarded untouched, ids reconciled, identity dropped) and one asserting `for_create` still drops the empty email.

## Constraints

- Budget: `Orders_Controller.php` loses at least 120 lines net; `Order_Write_Payload.php` changes by −10 to +35 net (the new method and the rewritten docblock). Net production change between −90 and −150. If you land above −60, stop and report why.
- No new options, env vars, filters, constants or `Logger` calls. No public signature changes on `Order_Write_Payload` other than the added method; `Customer_Writer` and `Order_Writer` are untouched.
- Preserve every explanatory comment (sentinel sku, load-bearing step order, null-product-id marker, #1456) at its home.
- phpcs: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <changed files>` from this worktree must be clean. You cannot run PHPUnit here (no Docker in the sandbox); the orchestrator runs the suites. Reason about the stock WooCommerce methods by reading them under `vendor/` or `wp-content` if present, otherwise from your knowledge of `WC_REST_Orders_V2_Controller`, and say which you did.
- Do not commit; the orchestrator commits. Leave the tree with only your changes.
- Report: files changed with line deltas, the deleted v1 methods and what replaces each, the six parity cases, the lane-coverage output, the phpcs result, and anything in §3 you could not make true.
