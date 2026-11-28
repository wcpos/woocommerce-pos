# Codex job spec — coupon types 2 of 3: `GET wcpos/v2/coupon-types` and `wcpos_coupon_type_descriptors`

## Goal

Add a read-only route, `GET /wcpos/v2/coupon-types`. It answers the coupon types that plugins declare through a new filter, `wcpos_coupon_type_descriptors`. Core validates each entry and drops bad ones. Every drop gets a `Logger` line. The route never answers an error because of a bad entry. With no callback registered, the answer is `{"schema":1,"coupon_types":[]}`.

## Stakes

Medium tier (a settings-like read the till consumes). No money moves through this route. Its filter docblock is the promise a declaring plugin makes about money, so the docblock text below is copied exactly.

## In scope (the only files you may edit or create)

- `includes/API/V2/Coupon_Types_Controller.php` (new)
- `includes/API/Controller_Registry.php`: one line, in `v2_map()`'s `$natives`
- `tests/includes/API/V2/Test_Coupon_Types_Controller.php` (new)

## Out of scope (do not edit)

- Every other file. In particular:
  - `Payment_Methods_Controller.php` and `Test_Controller_Registry.php`;
  - `scripts/`, `tests/lane-coverage/`, `.github/`;
  - CHANGELOG, readme, languages.

## Context (read these first)

- `includes/API/V2/Payment_Methods_Controller.php`: copy its shape. The namespace is `wcpos/v2`; `register_routes()` uses `'schema' => array( $this, 'get_public_item_schema' )`; the permission check is `get_items_permissions_check()` with `access_woocommerce_pos`; it has a `get_item_schema()`.
- `includes/API/Controller_Registry.php` `v2_map()` (~line 142): the `$natives` array.
- `includes/Logger.php`: `Logger::log( string $message, $context = null )`.
- `tests/includes/API/V2/Test_Payment_Methods_Controller.php`: the GET pattern `$this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/payment-methods' ) )`, and the 401 test for anonymous users.
- `tests/includes/API/V2/Test_Payments_Controller.php` setUp/tearDown: how logs are captured through the `wcpos_logging` filter.

## Interfaces and constraints

1. `Controller_Registry::v2_map()`: add `'coupon_types' => V2\Coupon_Types_Controller::class,` directly after the `'payment_methods'` line, aligned with its neighbours. Change nothing else in that file.
2. `class Coupon_Types_Controller extends WC_REST_Controller`:
   - namespace `WCPOS\WooCommercePOS\API\V2`, `\defined( 'ABSPATH' ) || die;`;
   - `$namespace = 'wcpos/v2'`, `$rest_base = 'coupon-types'`;
   - one route: `WP_REST_Server::READABLE` → `get_items`, permission `get_items_permissions_check`, plus the schema callback;
   - no other routes or methods beyond those below.
3. `get_items_permissions_check( $request )`: the same body as `Payment_Methods_Controller`'s, with the message `__( 'Sorry, you cannot view coupon types.', 'woocommerce-pos' )`.
4. `get_items( $request )`: returns `rest_ensure_response( array( 'schema' => 1, 'coupon_types' => $this->descriptors() ) )`. It sets no headers.
5. `private function descriptors(): array`:
   - Call the filter with exactly this docblock above it (keep the wording; wrap lines at the file's usual width):

     ```php
     /**
      * Filters the coupon types a plugin declares to the till (`GET wcpos/v2/coupon-types`).
      *
      * By declaring a type with `calc: "store"`, a plugin promises that:
      * (1) on every order write that adds or changes a coupon of that type, WooCommerce's own totals
      *     (`coupon_lines[].discount`, `discount_tax`, `discount_total`, `total`) carry the store's amount,
      *     capped at what the order allows;
      * (2) the balance behind it is settled when the order reaches a paid status, and at no other time;
      * (3) it answers `wcpos_payment_pre_capture` whenever the amount it would settle is no longer the
      *     amount on the order.
      * A plugin that cannot keep all three must not declare the type.
      *
      * Each entry is `array( 'type' => string, 'calc' => 'store', 'offline' => 'none', 'provider' => slug )`.
      * Core drops an entry whose type is `percent`, `fixed_cart` or `fixed_product`, is empty or not a string,
      * or repeats an earlier type (the first wins), and one whose `calc` is not `store` or whose `offline`
      * is not `none`. Each drop is logged; the route never answers an error for it.
      *
      * @since 2.0.7
      *
      * @param array $descriptors The declared coupon types. Default empty.
      */
     $declared = apply_filters( 'wcpos_coupon_type_descriptors', array() );
     ```
   - If `$declared` is not an array: `Logger::log( 'WCPOS coupon types: the wcpos_coupon_type_descriptors filter did not return a list; no types declared.' )` and return `array()`.
   - Walk `$declared` in order and drop an entry, logging once per drop with the context `array( 'type' => <the type if it is a string, else null>, 'provider' => <the provider if it is a string, else null> )`, when:
     - the entry is not an array: `'WCPOS coupon types: dropped a declared entry that is not an object.'`;
     - `type` is missing, not a string, or `''`: `'WCPOS coupon types: dropped an entry with no type.'`;
     - `type` is `percent`, `fixed_cart` or `fixed_product`: `'WCPOS coupon types: dropped a core coupon type, which cannot be redeclared.'`;
     - `calc` is not exactly `'store'` or `offline` is not exactly `'none'`: `'WCPOS coupon types: dropped a type whose calc or offline value is not supported.'`;
     - `type` equals the type of an entry already kept: `'WCPOS coupon types: dropped a repeated type; the first declaration wins.'`.

     Check in that order; the first rule that matches decides.
   - A kept entry is rebuilt with exactly four keys, in this order: `'type' => $type, 'calc' => 'store', 'offline' => 'none', 'provider' => is_string( $entry['provider'] ?? null ) ? $entry['provider'] : ''`. Any other key the entry carries (`label`, `balance`, `applies`, `refund` or anything else) is never sent.
   - Return a list (`array_values`).
6. `get_item_schema(): array`:
   - `$schema` draft-04, `title` `wcpos_coupon_types`, type `object`;
   - properties: `schema` integer; `coupon_types` array of objects with `type` string, `calc` string `enum` `array( 'store' )`, `offline` string `enum` `array( 'none' )`, `provider` string.
7. The new test file, `Test_Coupon_Types_Controller extends WCPOS_REST_Unit_Test_Case`, namespace `WCPOS\WooCommercePOS\Tests\API\V2`:
   - setUp captures log messages through a `wcpos_logging` filter that returns false (as in `Test_Payments_Controller`) and calls `Logger::reset_dedup_state()`. tearDown removes it.
   - Every request uses the literal `'/wcpos/v2/coupon-types'`.
   - Tests, named `test_[feature]_[scenario]_[expected_result]`, Arrange/Act/Assert, `assertSame( expected, actual )`:
     - no callback: 200 and exactly `array( 'schema' => 1, 'coupon_types' => array() )`;
     - one valid entry with an extra `label` and `balance`: the answer has exactly the four keys, the right values, and no `label` or `balance`;
     - a missing `provider` and an int `provider` each become `''`;
     - one test per drop rule: not an array; missing type; int type; empty string type; each of `percent`, `fixed_cart`, `fixed_product` (a data provider is fine); `calc` `till`; `offline` `queue`; a duplicate type (the first entry's `provider` is kept).

       Each drop test asserts that the answer is 200, the dropped entry is absent, a valid sibling entry is still present, and the matching log line was captured (`assertContains` on the exact message, or `assertStringContainsString` if the logger prefixes it).
     - a filter that returns a string: 200, empty list, and the log line;
     - permission: an anonymous user gets 401 with code `woocommerce_pos_rest_unauthorized` (the same as the payment-methods test). A logged-in user without `access_woocommerce_pos` gets 403. A user with that capability gets 200;
     - schema: an OPTIONS request (or `rest_get_server()->get_routes()`) shows the route's schema `title` is `wcpos_coupon_types` and `coupon_types.items.properties` has exactly the keys `type`, `calc`, `offline`, `provider`.
   - Filters are added inside each test and removed by WordPress's hook reset between tests. Do not add removal code for them.

## Do not

- Edit any file outside the in-scope list.
- Add an option, a table, a migration, a cache, transients or response headers.
- Add a `label`, `balance`, `applies` or `refund` field, a restore hook, or any other field or filter.
- Name any new hook with the `woocommerce_pos_` prefix.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit or create the in-scope files
- Run the acceptance commands

## Budget

- Max non-test lines changed: 190
- Max total lines changed: 520

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l includes/API/V2/Coupon_Types_Controller.php
php -l includes/API/Controller_Registry.php
php -l tests/includes/API/V2/Test_Coupon_Types_Controller.php
php scripts/lane-coverage.php --compare=/Users/claude/.claude/jobs/a1d68413/tmp/lane-base-coupons/base.json   # must exit 0
```

## Reviewer reruns (not for Codex)

These run under wp-env:
- the new test file;
- `Test_Controller_Registry.php` and `Test_Payment_Methods_Controller.php`;
- mutations: remove each drop rule in turn, and send the reserved keys through. Each must fail a test;
- phpcs on the new files.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
