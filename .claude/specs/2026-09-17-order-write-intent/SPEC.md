# Name the POS order write's subject and intent instead of re-deriving them on a shared filter

Branch `codex/order-write-intent`, cut from `origin/main` (8b3d5b85). PR targets `main`.
Candidate 3 of the 2026-09-17 architecture review (tracking issue #2007, now closed). This is a
**wiring change with no wire-shape change**: no lane accepts or returns anything different, no
order is written differently. It follows the first order-write slice, PR #2013, which made
`Sync\Order_Write_Payload` the one home of the two safely shareable shaping rules.

## The problem, in the code as it is

`woocommerce_rest_pre_insert_shop_order_object` is WooCommerce's filter over the prepared
order object just before its REST controller saves it. For a POS order write it is an unnamed
shared bus: four WCPOS callbacks sit on it and each re-derives, on its own, *which* order this
write is about and *what* the client asked for.

| Reader | Where | Priority | Derives |
|---|---|---|---|
| `wcpos_track_creating_order` | `includes/API/V1/Orders_Controller.php:451` | 9, request-scoped | identity of the order prepared for this create (`$this->creating_order`; last creating order wins) |
| `wcpos_preserve_client_created_date_gmt` | same file, :465 | 10, request-scoped | sets the identity again, then applies the client date |
| `Stock_Validator::validate_stock` | `includes/Services/Stock_Validator.php:46` | 10, permanent | status/set_paid from the request, whether to validate at pre-insert |
| `WooCommerce_Tax::note_requested_status` | `includes/Integrations/WooCommerce_Tax.php:110` | 10, permanent | `(order, request status)` pair, read back by `is_open_pos_order()` through an identity check |
| anonymous `$pre_insert` | `includes/API/V2/Writers/Order_Writer.php:242` | 10, request-scoped | `$forwarded_order` identity (first creating order wins), applies `fill_meta` and `created_gmt` |

Two of the readers exist only to learn which order object WooCommerce prepared, and they answer
it differently (v1: last creating order; v2: first). The tax module needs the status the client
*asked for*, because WooCommerce recalculates totals before it applies the requested status; it
takes it from the request — but on a paid create both lanes have already rewritten the request
to `pending` inside `Stock_Validator::around_paid_create()`, so what the tax module sees is the
neutralised write, not the sale. (Today that happens to give the same answer, because a new
order's persisted status is `pending` too; nothing in the code says so.) Eight commits settled
this area between f2e948af and f1ae2bd5; the ordering only works because the priorities happen
to line up.

Note one lane that publishes nothing: the client also writes orders on **stock `wc/v3` with the
`X-WCPOS` header**. `tests/includes/Integrations/Test_WooCommerce_Tax.php` exercises that path
(`test_wc3_reopened_order_with_changed_lines_recalculates_tax`). The tax module must keep
working there, so the intent cannot be something only the WCPOS lanes publish.

## Goal

One module owns the answer to "which order is this POS write about, and what did the client ask
for", publishes it once per write, and the readers subscribe. After this change:

- `Services\Order_Write_Intent` is the **only** WCPOS subscriber that captures identity or
  requested status on the filter. It sits at priority 1, gated by `\wcpos_request()`, and
  publishes an intent for every POS REST order write, including a direct `wc/v3` write.
- A lane that knows more than the request can tell — the client's real status under
  `around_paid_create()` neutralisation, v2's `fill_meta`, v2's validated `created_gmt` —
  **declares** it before the write, and the module binds the prepared order to that declaration
  and applies the declared mutations itself.
- `WooCommerce_Tax` loses `note_requested_status()`, its `$requested` state and its filter
  registration; `is_open_pos_order()` reads the intent.
- The v1 controller loses `$creating_order`, `wcpos_track_creating_order()` and that
  add/remove pair; `wcpos_before_order_object_save()` reads the intent.
- `Order_Writer` loses `$forwarded_order` and the `$pre_insert` closure; its created-via action
  reads the intent.
- `Stock_Validator::validate_stock` is **unchanged**. Its gate must see the write as WooCommerce
  will perform it (the neutralised pass, where the exempt `pending` status is what stops it
  validating an unsaved order that `around_paid_create()` is about to validate with a
  reservation). Say so in the new class's docblock; do not route it through the intent.

## The module

`includes/Services/Order_Write_Intent.php`, `final class Order_Write_Intent` in
`WCPOS\WooCommercePOS\Services`. Match the PHP level and style of `Stock_Validator` (docblock
types on properties, no typed properties, `\wcpos_request()` gating, WordPress coding standards).

Static surface:

- `public static function register(): void` — idempotent; hooks `publish` on
  `woocommerce_rest_pre_insert_shop_order_object` at priority 1 with 3 accepted args. Call it
  from `includes/Init.php` right after `Services\Stock_Validator::instance();` (line ~547 — the
  "needed on every lane" block), so it is live wherever the validator is.
- `public static function publish( $order, $request = null, $creating = false )` — the filter
  callback. Returns `$order` unchanged, always (a `WP_Error` or a non-order value passes through
  untouched — `tests/includes/Sync/Writers/Test_Order_Writer.php::test_create_forward_ignores_non_order_filter_value`
  drives the filter with `null` and must keep passing). When `\wcpos_request()` is false, do
  nothing. Otherwise:
  - if a **declared** context is on top of the stack and is not yet bound and this order matches
    it (create: `$creating` is true; update: `! $creating && $order->get_id() === $context->id()`),
    bind the order as the subject and apply the declaration's `created_gmt`
    (`$order->set_date_created()`) and `fill_meta` (`update_meta_data()` per key) — exactly what
    the v2 closure does today. A creating order that arrives while the top context is already
    bound is a nested or foreign write and is left alone (v2's "first creating order wins" rule;
    it becomes the rule on v1 too — see Behavioural changes).
  - if no declared context is on top, publish an **ad-hoc** intent from the request: operation
    from `$creating`, id from the order, `requested_status` = `(string) $request->get_param( 'status' )`
    when the request is a `WP_REST_Request` (raw, no `wc-` normalisation — the tax module compares
    the raw value today), `set_paid` = `has_param && rest_sanitize_boolean`, subject bound to
    `$order`. An ad-hoc intent replaces a previous ad-hoc intent; it is never pushed above a
    declared one.
- `public static function open( array $declared, callable $write )` — pushes a declared,
  unbound context, runs `$write()`, pops it in `finally` (restoring whatever was below), returns
  `$write`'s result. Declaration keys, all optional except `operation`: `operation`
  (`'create'|'update'`), `id` (int, updates), `requested_status` (string), `set_paid` (bool),
  `created_gmt` (`WC_DateTime|string|null`, whatever `Order_Writer` passes today), `fill_meta`
  (`array<string, string>`).
- `public static function current(): ?self` — the intent on top of the stack, or null when no
  POS order write is in progress and none has been observed. Outside a declared context the
  last ad-hoc intent stays current until the next observation replaces it (the tax module's
  `$requested` behaves that way today; its identity check makes that safe).

Instance readers: `operation(): string`, `is_create(): bool`, `id(): int`,
`requested_status(): string`, `set_paid(): bool`, `subject(): ?\WC_Abstract_Order`,
`is_subject( $order ): bool` (strict `===` against the bound subject; false while unbound).

The class docblock is the place the ordering story lives: why priority 1, why the tax module
needs the client's status rather than the request's, why the stock gate deliberately does not.
Keep it to the facts above; no history.

## Readers

**`includes/Integrations/WooCommerce_Tax.php`.** Delete the constructor's filter registration,
`note_requested_status()`, the `$requested` property and its docblock (move the one load-bearing
sentence — WooCommerce recalculates before it applies the requested status — into
`is_open_pos_order()`'s docblock). `is_open_pos_order()` becomes: persisted status open, or the
current intent names this order as its subject and its `requested_status()` is open. Update the
constructor docblock, which describes priorities 9/10.

**`includes/API/V1/Orders_Controller.php`.** Delete `$creating_order`,
`wcpos_track_creating_order()` and its add/remove lines and the two `$this->creating_order = null`
resets. In `create_item()` wrap the existing `try { parent::create_item } finally { … }` in
`Order_Write_Intent::open()` with `operation => 'create'`, `requested_status` and `set_paid`
taken from the request **before** `save_object()` neutralises them (the request object is the
same one; read the params at `create_item` time). Keep `wcpos_preserve_client_created_date_gmt()`
public and registered at priority 10 exactly as now — `Test_Order_Write_Parity::test_client_date_public_callback_registered_at_original_priority`
pins it for extension subclasses — but drop its `$this->creating_order = $order;` line. In
`wcpos_before_order_object_save()`: `$intent = Order_Write_Intent::current();
$is_creating_order = null !== $intent && $intent->is_create() && $intent->is_subject( $order );`.
Nothing else in that method changes. `update_item()` declares nothing: the ad-hoc intent from
the request serves it, exactly as it serves a direct `wc/v3` update.

**`includes/API/V2/Writers/Order_Writer.php`.** `forward()` becomes the place the lane declares:
build the declaration from `$prepared['context']` (`operation`, `id`, `fill_meta`,
`created_gmt`) plus the payload's real `status` / `set_paid` (the same reads
`forward_with_reserved_stock()` makes today), and run `forward_with_reserved_stock()` inside
`Order_Write_Intent::open()`. Only declare when `context.operation` is `create` or `update`;
otherwise call through unchanged. In `forward_with_order_lifecycle()` delete `$forwarded_order`,
the `$pre_insert` closure, `$use_filter` and the add/remove of the filter; the `$created_via`
action stays, registered for creates as now, and tests the order with
`Order_Write_Intent::current()` + `is_subject()`. The `Stock_Validator::around_paid_create()`
call and everything else in `forward_with_reserved_stock()` are untouched.

**`includes/Sync/Order_Write_Payload.php`.** Add one row to the lane-differences table:
`- Write intent: both lanes declare through Services\Order_Write_Intent (v1 at create_item, v2 at Order_Writer::forward); v1 update and direct wc/v3 rely on the ad-hoc intent from the request.`

## Behavioural changes (all of them — list any other you find in the final report)

1. On v1, the order stamped by `wcpos_before_order_object_save()` as "the order being created"
   is now the **first** order WooCommerce prepares for the request, not the last. Only an
   extension creating a second order via REST mid-create can tell the difference, and the comment
   in that method already says the second order must not be treated as the created one.
2. The tax module reads the client's requested status on a paid create (`completed`) instead of
   the neutralised `pending`. Same outcome today (a new order's persisted status is `pending`,
   which is open); now stated.

No new options, env vars, filters, constants outside the new class, or parameters on existing
public methods. No `error_log()`.

## Tests

New file `tests/includes/Services/Test_Order_Write_Intent.php` extending
`WCPOS_REST_Unit_Test_Case`, REST-level through the real routes. The lane-coverage CI gate
(`tests/lane-coverage/README.md`) fails any new case that names `wcpos/v1` without also asserting
a current lane in the same method: for every case below that dispatches v1, dispatch v2 first
in the same method. Fixture conventions: `tests/includes/Integrations/Test_WooCommerce_Tax.php`
shows how to dispatch `wcpos/v1`, the `wcpos/v2` push and a direct `wc/v3` request with the POS
headers; `tests/includes/Services/Test_Stock_Validator.php` shows how to enable prevent-overselling
(`set_prevent_overselling( true )` — settings filters before `parent::setUp()`).

1. `test_paid_create_publishes_the_clients_intent_under_stock_neutralisation` — prevent
   overselling on, product in stock. Add an observer at priority 10 on the filter that records
   `Order_Write_Intent::current()->requested_status()`, `->set_paid()`, `->is_create()`,
   `->is_subject( $order )` and `$request->get_param( 'status' )`. Create with
   `status => completed, set_paid => true` on the v2 push, then on `POST /wcpos/v1/orders`.
   Assert on each: request param is `pending` (neutralised), intent requested status is
   `completed`, set_paid true, is_create true, is_subject true; both responses 201; the created
   order is `completed`; after each dispatch `current()` is null.
2. `test_direct_wc3_update_publishes_an_ad_hoc_intent` — create an order, then `PATCH
   /wc/v3/orders/<id>` with POS headers and `status => pos-open`; the observer sees operation
   `update`, `id()` equal to the order, requested status `pos-open`, `is_subject` true. Then the
   same PATCH **without** POS headers: the observer sees `current()` null or an intent whose
   subject is not this order (assert the exact shape your implementation produces and say why).
3. `test_nested_order_create_inside_a_pos_create_is_not_the_subject` — during a v2 create and
   then a v1 create, an observer at priority 10 dispatches a nested `POST /wc/v3/orders` (no POS
   headers needed; `\wcpos_request()` is already true for the outer request) and records
   `is_subject()` for the outer and inner orders. Assert outer true, inner false, and that the
   outer order ends up with `created_via` `woocommerce-pos`.
4. In `Test_WooCommerce_Tax`, nothing new unless a case above cannot express the tax outcome;
   the existing reopen cases on all three lanes are the regression net and must stay green.

Run nothing through local PHPUnit (the sandbox has no Docker and no wp-env). Paul's agent runs
the suite on the wp-env runner after you finish. Write the tests so that cases 1 and 3 fail on
the current tree (the class does not exist) and pass after the change.

## Budget and rules

- NET production change (additions minus deletions, non-test PHP): at most +70 lines — the new
  class is roughly 150 with docblocks against roughly 100 deleted. Tests: at most +220 lines net.
  Do not shrink completed work to satisfy a count; report the real numbers.
- WordPress coding standards: run the host linter on every PHP file you change —
  `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`
  (readable from the sandbox; the worktree has no vendor). Zero errors before you finish.
- Tests: Arrange / Act / Assert, `assertSame` over `assertEquals`, `( expected, actual )`
  argument order, `$this->wp_rest_*_request()` helpers for requests, settings filters before
  `parent::setUp()`, hooks you add removed in `finally`.
- Git is READABLE from the sandbox (`git diff`, `git show`, `git log -S`); writes are not. Do
  not commit; Paul's agent commits with explicit paths.
- Proceed; do not stop to ask. If a stated assumption is wrong, make the smallest reasonable
  choice, record it in your final report, and continue.

## Final report

List: the production diff summary with net line counts per file; phpcs result per file; every
behavioural change you can identify beyond the two listed; anything you chose differently from
this spec and why.
