# Spec: one order-revision check for both lanes (c7)

**Date:** 2026-10-22 · **Lane:** `next` (branch off `origin/next`, PR targets `next`) · **Binding:** ADR 0040 rule 6 (every money command carries the revision; replay is checked before the revision), ADR 0033 (one order revision recipe) · **Stakes:** High (checkout money). A failure here means a push or a money command that lands on an order which changed under the till, or a false 409 that wedges a till.

## Outcome

The order the two lanes compare is read by one function. Both lanes compare revisions through one function, and that function returns either null or the stale facts. Each lane wraps those facts in its existing refusal shape. **No wire shape, code or status changes** (see the one flagged exception under Found). The shapes are unified later, with the client (see Follow-up).

## Verified: the recipe exists twice today

**Push lane.**
- `Write_Controller::document_for()` (752) calls `Order_Writer::document()` (328–336).
- `document()` calls `$default_document( $meta, $id, [ 'dp' => '6' ] )`, which is `Write_Controller::default_document_for()` (757). That runs `Product_Serializer::dispatched_bare_read( $meta['route'] . '/' . $id, … )`: `Store_Scope::stamp`, `in_v2_lane`, `rest_do_request`, `Meta_Normalizer::normalize`.
- `$meta['route']` is `/wc/v3/orders` (`Collections.php:154`).
- `document()` then calls `add_pos_links()` when the data is an array and the order exists.
- `Write_Controller::revision_for()` (820–826) hashes that bare document with `Order_Serializer::canonical_revision()`.

**Money-command lane.**
- `Order_Serializer::current_state()` (274–287) runs the same `dispatched_bare_read( '/wc/v3/orders/' . $id, [ 'dp' => '6' ] )`, then `add_pos_links()`, then `canonical_revision()`.
- It turns a failed or non-array read into `WP_Error( code from the body, status max(400, …) )`.

Both lanes therefore hash the same bytes. They differ in only two ways: where the route string comes from, and how a failed read is handled. `Test_Rest_Dispatch_Money_Model::test_a_record_moves_the_revision_a_later_push_compares_against` (752) already pins that the two revisions are equal.

**The comparisons.**
- `Write_Controller` compares with `!==` at update (493) and at delete (568). It refuses with a 409 `WP_REST_Response` whose body is `{ code: woo_rxdb_sync_conflict, message, current, currentRevision }`, keys in that order.
- `Payments_Controller::stale()` (488–506) compares with `hash_equals`. It refuses with `WP_Error( 'wcpos_revision_stale', …, { status: 409, currentRevision, current, order } )`.

**The three 428 codes.** All three stay unchanged.

| code | where | shape |
|---|---|---|
| `wcpos_revision_required` | `Payments_Controller.php:414` | `WP_Error`, status 428 |
| `woo_rxdb_sync_revision_required` | `Write_Controller.php:473` (update) | `WP_REST_Response` body, 428 |
| `woo_rxdb_sync_precondition_required` | `Write_Controller.php:554` (delete) | `WP_REST_Response` body, 428 |

## The change

**1. One recipe: `Order_Serializer::compared_read( int $order_id ): WP_REST_Response`.**
- It reads the order as both lanes compare it: `dispatched_bare_read( '/wc/v3/orders/' . $order_id, [ 'dp' => '6' ] )`, then POS links added to the data.
- A failed inner read is returned as that response and is never hashed.
- Other names considered: `revision_read()`, `current_read()`.
- `Order_Writer::document()` becomes `return Order_Serializer::compared_read( $id );`. The `Null_Writer` interface does not change, and `$default_document` simply goes unused by the order writer.
- `current_state()` becomes `compared_read()` plus its existing `WP_Error` mapping, plus `canonical_revision()`. Its return shape is unchanged. Its callers (`Payments_Controller` 489 and 537, `tests/Helpers/Money_Route_Envelope.php`) do not change.
- `Write_Controller::revision_for()` is unchanged. Its order branch is still `canonical_revision()` over that same document.

**2. One comparison: `Revision::stale( string $claimed, string $current_revision, array $current_document ): ?array`** in `includes/Sync/Revision.php`.
- It returns null when `hash_equals( $current_revision, $claimed )`. Otherwise it returns `array{ currentRevision: string, current: array }`.
- It is collection-generic, because `Write_Controller` compares products, variations and coupons through the same lines. The variation revision is a date string, not `sha256:` (`write-contract/fixtures/responses.json:12`).
- `hash_equals` gives the same verdict as `!==` here. `baseRevision` is validated as a string at `Write_Controller.php:353`, and a null value 428s first. The payments `revision` is validated at `Payments_Controller.php:413`.
- Other names considered: `check()`, `conflict()`.

**3. Each lane wraps the facts in its existing shape.**
- In `Write_Controller`, add `private function stale_conflict( array $facts ): WP_REST_Response`, which builds today's 409 body in today's key order. Both the update site (493) and the delete site (568) call `Revision::stale()` and then this wrapper.
- `Payments_Controller::stale()` keeps its failed-read propagation and its `WP_Error`, now filled from `$facts` plus `order`.
- Replay-before-revision ordering in `admit()` (416–437) is not touched.

## Found while verifying (flagged exception; parent decides)

On a failed read (status ≥ 400), `Order_Writer::document()` still adds the payment and receipt links when the order exists. Both links carry the **order key** (`Order_Serializer.php:219–251`). `Write_Controller` returns that response as the push error (`:488–490`), so a caller whose read was refused after the edit gate passed receives the order key inside an error body. `current_state()` adds links only on a 2xx array read.

**Recommendation:** in `compared_read()`, add the links only on a 2xx array read. The only wire effect is removing keys from an error body, and no client reads links from an error.

- Failing test first, in `Test_Write_Controller`: `test_order_update_with_a_failed_current_read_carries_no_order_key`.
- Reachability is not proven. It needs a read that is refused after `post_permission_error( 'edit' )` passes, for example Pro's store reach. The test drives it with the existing `setRestResponse` 403 mock.

If the parent strikes this exception, `compared_read()` keeps `document()`'s behaviour exactly, and the fix becomes its own PR.

## Tests

Follow the `CLAUDE.md` PHPUnit conventions. This is a refactor with no behaviour change, so the pins below must pass untouched. **None are deleted.**

**`tests/includes/Sync/Test_Order_Revision_Recipe.php`** survives as written:
- 229 `…_agrees_on_all_three_sites`
- 253, 278 (`…_409s_a_stale_push`), 316, 346, 415
- Its `cas_revision()` helper (163) hand-rolls the recipe deliberately, as an independent oracle. Do **not** repoint it at `compared_read()`, or the test would check the function against itself. The same applies to the test-side copies at `Test_Rest_Dispatch_Money_Model.php:70`, `Test_Hook_Parity.php:301` and `Test_Rest_Dispatch_Cart_Restart.php:74`.
- Add `test_order_revision_money_command_read_agrees_with_the_cas_and_pull_revisions`, which compares `current_state()` with `cas_revision()` and `pull_revision()` under the serialized-order filter of 229. That makes the money lane a fourth site in this file.

**Push-lane stale pins** survive: `Test_Write_Controller` 2432, 2480, 2509, 2554, 2593, 2869 (428), 2886, 2914 (failed read propagated, not a false conflict), 3003 and 3148 (428). Also:
- `Test_Rest_Dispatch_Money_Model` 413 and 752.
- `Test_Rest_Dispatch_Write_Contract` 921, plus the wire fixtures in `write-contract/fixtures/responses.json`. Those fixtures are the byte-level evidence that the push shapes did not move.

**Money-lane stale pins** survive: `Test_Payments_Controller` 1322 (asserts that `currentRevision` and `current` equal `current_state()`), 1352 (428 `wcpos_revision_required` for every kind), 1459, 1793, 1982 and 2165.

**New, in `tests/includes/Sync/Test_Revision.php`:**
- `test_revision_stale_with_the_current_revision_returns_null`
- `test_revision_stale_with_another_revision_returns_the_current_revision_and_document`
- `test_revision_stale_with_a_date_revision_compares_as_a_string`

## Pro impact

`git grep` on Pro `origin/next` (`includes/`, `tests/`) finds none of `current_state`, `canonical_revision`, `Order_Writer`, `revision_for`, `document_for`, `wcpos_revision_stale` or `woo_rxdb_sync_conflict`. Pro uses only `Revision::compute()`, once, in `tests/includes/Test_Store_Pricing_V2.php:572`, and that is untouched. `Revision::stale()` is additive. Nothing in Pro changes.

## Follow-up (client handoff, not this PR)

Unify the wire shapes in one step with wcpos/monorepo:
- **Stale:** `woo_rxdb_sync_conflict` (409, top-level `current` and `currentRevision`) and `wcpos_revision_stale` (409, `data.currentRevision`, `data.current`, `data.order`).
- **Missing:** the three 428 codes.

Under the patch-compatibility rule, either lane keeps serving its old shape for one full minor after the new shape lands. Record this in `.claude/handoffs/2026-10-20-adr-0040-client/`.

## Out of scope

No change to `canonical_revision()`, `canonical_form()`, the pull and proxy revision sites, the ack revision (`Write_Controller::respond()` 774), or locking. No code, status or key renamed.

## Budget

About 55–75 added production lines:
- `Order_Serializer` ≈ +18 / −10
- `Order_Writer` +2 / −8
- `Revision` ≈ +20
- `Write_Controller` ≈ +15 / −22
- `Payments_Controller` +5 / −8

The hard cap is 150. Stop and report before you exceed it.

## Rulings (orchestrating session, 2026-10-22)

- The flagged exception is **in scope**: `compared_read()` adds the pay and receipt links only on a 2xx array read, with the failing test `test_order_update_with_a_failed_current_read_carries_no_order_key` first. The only wire effect is removing keys from an error body.
