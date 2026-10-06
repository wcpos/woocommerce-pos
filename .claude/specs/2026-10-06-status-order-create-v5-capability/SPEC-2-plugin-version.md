# Codex job spec — plugin version in `GET /wcpos/v2/status`

## Goal

`GET /wcpos/v2/status` gains a `wcpos_version` key, the plugin's `VERSION` constant, placed after
`capabilities`. Connectors then read the version from the same response as the capabilities instead of
a second request. Same key name and value as the existing public `GET /wcpos/v2/site`
(`includes/API/V2/Site.php`, `'wcpos_version' => VERSION`).

## Stakes

Additive key in a read-only status response; no behaviour change.

## In scope (the only files you may edit)

- `includes/API/V2/Status_Controller.php`
- `tests/includes/Sync/Test_Sync_Status.php`
- `CHANGELOG.md`

## Out of scope (do not edit)

- `includes/API/V2/Site.php`, `woocommerce-pos.php`, every other file
- Lockfiles, CI config, docs

## Context

- `VERSION` is the namespaced constant `WCPOS\WooCommercePOS\VERSION` (defined in `woocommerce-pos.php:27-28`).
  Other controllers import it with `use const WCPOS\WooCommercePOS\VERSION;` (e.g. `includes/API/V2/Site.php:13`).
- `includes/API/V2/Status_Controller.php` `get_status()` (line ~59) returns
  `array( 'healthy' => …, 'missing_tables' => …, 'schema_version' => …, 'capabilities' => self::CAPABILITIES )`.
- `tests/includes/Sync/Test_Sync_Status.php` asserts that exact array with `assertSame` in two tests
  (around lines 158–167 and 182–189), each ending with
  `'capabilities'   => array( 'products_id_fast_path', 'order_payments_list', 'order_create_v5' ),`.
  Its last method is `test_sync_status_lists_the_order_create_v5_capability()`.
- `CHANGELOG.md`: the first bullet under `## Unreleased` is the `order_create_v5` entry.

## Interfaces and constraints

1. `Status_Controller.php`: add `use const WCPOS\WooCommercePOS\VERSION;` after the existing `use` lines
   (after `use WP_REST_Server;`), and in `get_status()` add `'wcpos_version'  => VERSION,` as the last
   array element, after `'capabilities'`. Align the `=>` with the other keys (phpcs alignment).
2. `Test_Sync_Status.php`:
   - add `use const WCPOS\WooCommercePOS\VERSION;` after the existing `use` lines;
   - in both exact-array assertions add `'wcpos_version'  => VERSION,` after the `'capabilities'` line,
     aligned like the other keys;
   - add a method after `test_sync_status_lists_the_order_create_v5_capability()`:
     ```php
     /**
      * Status reports the plugin version next to the capabilities, even while the store is unhealthy.
      */
     public function test_sync_status_reports_the_plugin_version(): void {
         wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

         $response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) );
         $data     = $response->get_data();

         $this->assertSame( 200, $response->get_status() );
         $this->assertFalse( $data['healthy'] );
         $this->assertSame( VERSION, $data['wcpos_version'] );
     }
     ```
3. `CHANGELOG.md`: extend the first `## Unreleased` bullet (the `order_create_v5` one) by appending, before
   its end, ` It also reports the plugin version as \`wcpos_version\`, the same field \`GET /wcpos/v2/site\` returns.`
   so the bullet ends `… (fee, shipping and custom lines). It also reports the plugin version as \`wcpos_version\`, the same field \`GET /wcpos/v2/site\` returns.`

## Do not

- Rename, reorder or remove existing keys or capability names
- Add the Pro version, WooCommerce version or any other field
- Touch any other test, method or file
- Refactor or reformat code outside the change
- Create plan, notes or scratch files
- Commit or push

## Pre-authorised actions

- Edit the in-scope files listed above
- Run the acceptance commands below

## Budget

- Max non-test lines changed: 6
- Max total lines changed: 30

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l includes/API/V2/Status_Controller.php
php -l tests/includes/Sync/Test_Sync_Status.php
grep -c "'wcpos_version'  => VERSION," includes/API/V2/Status_Controller.php   # prints 1
grep -c "'wcpos_version'  => VERSION," tests/includes/Sync/Test_Sync_Status.php   # prints 2
grep -c "use const WCPOS\\\\WooCommercePOS\\\\VERSION;" includes/API/V2/Status_Controller.php   # prints 1
grep -c "function test_sync_status_reports_the_plugin_version" tests/includes/Sync/Test_Sync_Status.php   # prints 1
```

### Reviewer reruns (not for Codex)

```sh
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Sync_Status.php   # in wp-env tests-cli
vendor/bin/phpcs includes/API/V2/Status_Controller.php
```

## Environment

- Network: no
- Writable outside the worktree: none
- No test suite runs in acceptance (pass `--no-test-slot`)

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is
ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
