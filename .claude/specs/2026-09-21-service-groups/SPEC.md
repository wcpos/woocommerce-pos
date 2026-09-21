# Spec: give the lazy service groups their own module

**Status:** approved 2026-09-21. Card 10 of the 2026-09-17 architecture review, the last open item.
**Lane:** branch `refactor/service-groups`, cut from `origin/main`, PR targets `main`.
**Scope:** a pure refactor. No behaviour change, no new hooks, no hook removed, no timing change.

## Why

`Init` currently owns three separate concerns for lazy construction, as private/public statics
interleaved with unrelated bootstrap code:

- `Init::$constructed` — which groups exist this request
- `Init::init_common()` — the `always` group's construction, inline
- `Init::construct_pos_services()` / `arm_order_services()` / `ensure_order_services()`

Two costs. First, you cannot ask "what is in the order group, and is it armed?" without booting a
whole `Init` on a forced lane — every test in `tests/includes/Test_Lazy_Service_Construction.php`
pays that price, and all of them are integration tests as a result. Second, the group rules are
spread across a 712-line class whose other 600 lines are about hook wiring, so the reader has to
reconstruct the grouping from three methods that never name themselves as one thing.

Extracting the groups gives one module whose interface is "ensure this group exists" and whose
implementation is the entire membership list, the arming rule and the ready action. It passes the
deletion test: deleting it would scatter group membership back across `Init`, not relocate it.

## Interface

New file `includes/Services/Service_Groups.php`, `final class Service_Groups` in
`WCPOS\WooCommercePOS\Services`. Style reference: `includes/Services/Request_Lane.php` — same
namespace, same shape (final, all-static, memoised per request, a `reset()` for tests).

```php
public const ALWAYS = 'always';
public const ORDER  = 'order';
public const POS    = 'pos';

/** Construct a group's services exactly once per request. Idempotent. */
public static function ensure( string $group ): void;

/** Hook the order group to the first order write of this request. */
public static function arm_order_group(): void;

/** Whether the late order-write trigger is installed. */
public static function armed(): bool;

/** Groups constructed so far, in construction order. Test seam. @internal */
public static function constructed(): array;

/** Forget this request's construction state. Tests only. @internal */
public static function reset(): void;
```

`ensure()` with an unknown group name is a no-op — it must not fatal and must not mark the name as
constructed. Groups are independent: `ensure( POS )` does not imply `ensure( ORDER )`.

## What moves, verbatim

From `includes/Init.php`. Move the service lists as they are — do not reorder constructions, do not
swap `new X()` for `X::instance()` or back, do not "improve" anything on the way past.

| From | To |
|---|---|
| the `SettingsService`…`Order_Write_Intent` block in `init_common()` | `ensure( ALWAYS )` |
| `construct_pos_services()` body | `ensure( POS )` |
| `ensure_order_services()` body, including the `do_action( 'woocommerce_pos_order_services_ready' )` | `ensure( ORDER )` |
| `arm_order_services()` body | `arm_order_group()` |
| `private static array $constructed` | `Service_Groups` |

`init_common()` is then exactly this, keeping its existing docblock (retarget the
`{@see ensure_order_services()}` reference at the new module):

```php
private function init_common(): void {
    Service_Groups::ensure( Service_Groups::ALWAYS );

    if ( Services\Request_Lane::is_storefront() ) {
        // Order-event services arrive on the first order write, if any.
        Service_Groups::arm_order_group();
        return;
    }

    Service_Groups::ensure( Service_Groups::ORDER );
    Service_Groups::ensure( Service_Groups::POS );
}
```

## What stays on `Init`

- **`Init::ensure_order_services()` stays, as a one-line delegation to `Service_Groups::ensure( ORDER )`.**
  It is public, it is named in the `woocommerce_pos_order_services_ready` docblock published since
  1.10.8, and `includes/Services/Request_Lane.php` and `includes/Templates.php` both point readers
  at it. An extension may call it. Keep the method, keep it public, note in its docblock that the
  implementation now lives in `Service_Groups`. Do **not** call `_deprecated_function()` — this is a
  supported entry point, not a deprecation.
- **`Init::reset_request_state()` stays**, now calling `Service_Groups::reset()` and
  `Services\Request_Lane::reset()`. It is the test entry point for both.
- **`Init::constructed_groups()` is removed**; it is marked `@internal Test seam` and its only
  callers are our own tests. They move to `Service_Groups::constructed()`.
- The `woocommerce_before_order_object_save` callback must remain reachable as a public static —
  it is registered as `array( self::class, 'ensure_order_services' )` today. After the move it is
  registered from `Service_Groups::arm_order_group()`; point it at a public static on
  `Service_Groups`. Adding a second callback to that hook (or leaving the old one registered too)
  would construct nothing twice but WOULD change the golden hook set, so register exactly one.

## Constraints

1. **No timing change.** `arm_order_group()` registers on `woocommerce_before_order_object_save` at
   priority 0 with 0 args, exactly as now. The ready action fires from inside the order group's
   construction, after every service in it exists — same position in the body as today.
2. **Construction order is observable.** `constructed()` returns groups in construction order and
   the suite asserts `array( 'always', 'order', 'pos' )` with `assertSame`. Use an ordered
   `array<string,bool>` keyed by group, like `Init::$constructed` does now, and return
   `array_keys()`.
3. **`Templates::ensure_registered()` stays exactly as it is.** The order group's boundary genuinely
   cuts through `Templates`: its post type and taxonomy are read from My Account on the storefront
   lane, where the order group is not constructed. That per-class hatch is the right shape, not a
   symptom — say so in the `Service_Groups` class docblock so the next reader does not try to
   "fix" it by moving `Templates` into the always group. `tests/includes/Test_Lazy_Service_Construction.php`
   pins this in `test_reading_the_active_template_on_a_request_that_never_constructed_templates_keeps_it`.
4. **No new hooks, no removed hooks, no renamed hooks.** `Test_Init_Hook_Wiring` pins the golden set.
5. **Docblocks carry the reasons.** The `init_common()` docblock's explanation of the always group,
   and the long `arm_order_services()` docblock explaining why
   `woocommerce_before_order_object_save@0` is the right signal, are load-bearing — move them with
   the code, do not summarise them away.
6. WordPress/WooCommerce coding standards per `.phpcs.xml.dist`; PHPStan must pass at the repo's
   configured level against PHP 7.4 semantics (no `readonly`, no enums, no first-class callables).

## Tests

**New: `tests/includes/Services/Test_Service_Groups.php`.** This is the point of the refactor — it
tests the module through its own interface, with no `Init`, no forced lane and no `init` run:

- `ensure( ORDER )` twice fires `woocommerce_pos_order_services_ready` exactly once
- `ensure( POS )` does not construct the order group and does not fire the ready action
- `ensure()` with an unknown group name constructs nothing and does not appear in `constructed()`
- `constructed()` returns groups in construction order
- `armed()` is false before `arm_order_group()` and true after
- `arm_order_group()` then an order write constructs the order group; `armed()` alone constructs nothing
- `reset()` clears `constructed()` and `armed()`

Restore the global hook registry between cases the way `Test_Lazy_Service_Construction` does
(snapshot `$wp_filter` in `setUp`, restore in `tearDown`) — constructing services registers hooks
that leak into later cases otherwise.

**Updated: `tests/includes/Test_Lazy_Service_Construction.php`.** Swap `Init::constructed_groups()`
for `Service_Groups::constructed()` and keep every case, every assertion and every dataProvider
exactly as they are. That file is the behaviour contract; if any case needs its expectations changed
to pass, the refactor is wrong — stop and say so rather than editing the expectation.

## Budget

About **+170 / −75 net non-test lines** (the new module is mostly moved code plus its docblocks) and
**+130 test lines**. If your plan exceeds 3x that, stop and report what is driving it instead of
building it.

## Validation

The sandbox has no Docker, so you cannot run the PHP suite — do not try, and do not report a test
result you did not observe. Run what you can and report exactly what you ran:

```
vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Service_Groups.php includes/Init.php
vendor/bin/phpstan analyse --no-progress
php -l includes/Services/Service_Groups.php
```

Then hand back: the diffstat, anything you could not verify, and any place the spec turned out to be
wrong about the current code. The reviewer runs the suite in a wp-env runner.
