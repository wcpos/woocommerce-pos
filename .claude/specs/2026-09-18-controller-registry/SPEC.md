# Controller Registry: the wcpos/v2 service map derived from v1

Candidate 11 of the 2026-09-17 architecture review (tracking issue #2007, closed). Paul ruled on the two contested points on 2026-09-18 (§1). Proceed without asking questions; if a detail here does not match the tree, take the reading that matches the tree, note it in your report, and keep going. The tree is already at the right base (`origin/main` at 3d837d5d); **do not pull, fetch, or stop before editing**. Stakes tier: **Medium** (route registration and the route→controller map that the permission gate and the dispatch hook read; a wrong map drops a controller's dispatch hook, it does not write data). This is a **pure move with no wire change**: every route registered today under `wcpos/v1` and `wcpos/v2` is registered with the same handler behaviour, every route classification the gate sees today is still merged, and every existing test passes unchanged except where §5 says otherwise.

**Revision 2 (after the first run, 2026-09-18).** Two premises above were wrong and the tree, not the spec, is right: (a) the `$legacy_classifications` table is not dead. It is the gate's fallback for a *filtered replacement* of `auth`, `print_jobs` or `receipts` that predates `wcpos_route_classifications()`, pinned by `Test_Route_Classifier::test_filtered_legacy_controller_without_classifications_keeps_public_exemption`. It lives on in the registry as `LEGACY_CLASSIFICATIONS`, as route suffixes applied under whichever lane the replacement registers on. (b) A promoted replacement can register nothing under `wcpos/v2` (a plain object with no namespace to stamp, or a class that hard-codes `wcpos/v1`); today core's twin serves v2 on such a site, so the registry hands the lane back to the core service when the diff is empty (`register()` → `core_v1_map()`), and only `WP_REST_Controller` instances are stamped. The v2-native services also keep their place at the head of the map, which `test_built_classifications_match_current_permission_gate_routes_exactly` pins by order. A ninth test case covers (b).

**Revision 3 (after the PR's first review, 2026-09-18).** Three findings from the Codex reviewer, all valid, and one measurement. (a) The v1 filter also carries the ten sync controllers (`Sync\Api::register_controllers()`, keys `sync-*`), which are `wcpos/v2`-native; promoting every non-frozen v1 entry built each of them twice and appended duplicate handlers to every sync route. Promotion is now limited to the v1 entries that actually registered a route under `wcpos/v1`. (b) The core fallback applied to an explicit `woocommerce_pos_rest_api_v2_controllers` choice that serves nothing; it now applies only to a derived v1 replacement (the v2 entry is the same class the v1 filter supplied, and differs from core). (c) Attribution looked only at the lane's namespace, so a filtered controller's routes under a namespace added through `woocommerce_pos_rest_namespaces` were never attributed; it now takes the tail of the server's whole route table. (d) The per-controller `get_routes()` snapshots of §2.3 made API construction six times slower (median 58 ms against 4.7 ms on `main`, PHPUnit, 20 constructions); the registry now reads `WP_REST_Server::$endpoints` through a bound closure (copy-on-write, no cost) and takes the patterns appended since the count before registration. A pattern registered twice keeps its first owner, which is why the two existing tests that build a second `API` on a populated server now read the harness's instance instead.

## Why

`includes/API.php` `register_routes()` (lines 119–282) hand-keeps two controller maps. The v1 map is the shared-service list plus the frozen data controllers. The v2 map lists the same services again by naming fifteen one-line subclasses under `includes/API/V2/` whose only body is `protected $namespace = 'wcpos/v2';`. Between them sits a `$legacy_classifications` table that is dead (all three keyed controllers define `wcpos_route_classifications()` themselves), and after them a reverse lookup that walks every registered route handler and, for closure-wrapped callbacks (WooCommerce 10.5 `RestApiCache`), reflects into the closure's bound `$this` to find which controller registered it. Pro today hand-carries three v2 twins of its own because the docblock says "the v2 map is not derived from the v1 map", and the same docblock warns that a v1 replacement alone leaves the v2 twin serving core behaviour. The seam is: **one registry owns which controllers exist on which namespace and which controller answers a route; the v2 service map is derived from the v1 map; nothing reflects.**

## 1. Rulings (Paul, 2026-09-18)

1. **Derive and delete.** The v2 service map is derived from the v1 map: every v1 key except the nine frozen data controllers is registered a second time under `wcpos/v2`. The fifteen twin files under `includes/API/V2/` are deleted; each twin FQCN is aliased to its v1 parent in `includes/API/class-aliases.php` (the existing permanent-alias mechanism, loaded from Composer `files` and the bootstrap). The `#544` boundary ruling (everything under one version) is unchanged: it decided *what* is on v2, this changes *how* it gets there.
2. **The registry stamps the namespace, no opt-in.** `WP_REST_Controller::$namespace` is protected with no setter. A private helper in the registry binds a closure into the instance scope and assigns it. This applies to every class in the v2 map, so a v1 replacement registered through `woocommerce_pos_rest_api_controllers` flows to v2 with no work, and a class registered through the v2 filter needs no `$namespace` override of its own. Pro's `Extensions_Action` (extends WP core directly) is the reason a free trait was rejected: it could not inherit one across a free/Pro version-skew window.

## 2. `API\Controller_Registry`

New file `includes/API/Controller_Registry.php`, `final class Controller_Registry`, namespace `WCPOS\WooCommercePOS\API`. It owns everything that lines 119–282 of `API.php` do today except the Sync classifications merge (that stays in `API.php`, see §3).

```php
/** The v1 data controllers the sync surface replaced. Never promoted to wcpos/v2 (#544). */
public const FROZEN_DATA_KEYS = array( 'products', 'product_variations', 'orders', 'customers', 'product_tags', 'product_categories', 'product_brands', 'coupons', 'taxes' );

/** Namespace every entry of the v2 map is stamped with. */
public const V2_NAMESPACE = 'wcpos/v2';

/** @return array<string, class-string> The v1 map after `woocommerce_pos_rest_api_controllers`. */
public static function v1_map(): array;

/**
 * The v2 map: every promoted v1 entry, then the v2-native services, then `woocommerce_pos_rest_api_v2_controllers`.
 * Pure over its input so tests can feed it a fake v1 map.
 *
 * @param array<string, class-string> $v1 The v1 map.
 * @return array<string, class-string>
 */
public static function v2_map( array $v1 ): array;

/** Instantiate, stamp, register and attribute every controller on both namespaces; merge each controller's classifications. */
public function register( Route_Classifier $classifier ): void;

/** @return object|null The controller that registered the route, or null when no WCPOS controller did. */
public function controller_for_route( string $route ): ?object;

/** @return array<string, string> Route pattern → registry key. Test seam. */
public function routes(): array;

/** @return array<string, object> Registry key → instance. Keys are the v1 keys and `v2-` + the v2 keys. Test seam. */
public function controllers(): array;
```

- `v1_map()`: today's array and its filter call moved verbatim, including the `@since 1.5.0` docblock and the `// TODO: remove this?` comment. Keep the class-string values; the registry instantiates.
- `v2_map( $v1 )`: `array_diff_key( $v1, array_flip( self::FROZEN_DATA_KEYS ) )` first, then the four v2-native entries (`ping` → `API\V2\Ping`, `echo_probe` → `API\V2\Echo_Probe`, `site` → `API\V2\Site`, `order_email` → `API\V2\Order_Email_Controller`), in that key order so a v2-native key wins over a same-named v1 key, then `apply_filters( 'woocommerce_pos_rest_api_v2_controllers', $map )`. Rewrite the filter docblock: the map is **derived** from the v1 map minus `FROZEN_DATA_KEYS`; the filter is additive: add a v2-only service or replace a derived entry by key; every entry is instantiated by the registry and stamped with `wcpos/v2`, so a replacement needs no `$namespace` override; `@since 1.10.0`; a `@since 1.10.19` line saying the map became derived and a v1 replacement now reaches v2 on its own.
- `register( $classifier )`: for each `( $key, $class )` of `v1_map()` and then of `v2_map( v1 )` (v2 registry keys are `'v2-' . $key`, as today):
  1. `class_exists( $class )` guard as today (skip silently otherwise).
  2. `$controller = new $class();` For the v2 lane only, stamp the namespace (§2.1) **before** `register_routes()`, because `wcpos_route_classifications()` in `Auth`, `Print_Jobs_Controller` and `Receipts_Controller` interpolates `$this->namespace`; stamping later would classify the v2 auth routes under v1 and the gate would 401 anonymous `/wcpos/v2/auth/test`.
  3. Attribute routes **by diffing the server's route table around registration, never by callback identity**: `$before = rest_get_server()->get_routes( $namespace )` keys (the lane's namespace: `wcpos/v1` for the v1 map, `V2_NAMESPACE` for the v2 map), `$controller->register_routes()`, `$after = ...` keys, and every key in `array_diff_key( $after, $before )` maps to this registry key. `get_routes( $namespace )` applies the `rest_endpoints` filter to the namespace subset only, so this is cheap; and because attribution never looks at the callback, closure wrapping by WooCommerce's `RestApiCache` or anyone else cannot break it. The `ReflectionFunction` block is deleted with nothing replacing it.
  4. `if ( method_exists( $controller, 'wcpos_route_classifications' ) ) { $classifier->merge( $controller->wcpos_route_classifications() ); }`. The `$legacy_classifications` table is deleted (dead: `Auth`, `Print_Jobs_Controller`, `Receipts_Controller` all define the method).
- 2.1 Namespace stamping, one private static method:
  ```php
  /**
   * WP_REST_Controller::$namespace is protected with no setter (Paul, 2026-09-18: stamp
   * every v2 entry here rather than ask each class to opt in, so a v1 replacement from
   * Pro or a third party reaches wcpos/v2 with no work on its side).
   */
  private static function stamp_namespace( object $controller, string $namespace ): void {
      \Closure::bind( function () use ( $namespace ): void { $this->namespace = $namespace; }, $controller, $controller )();
  }
  ```
  If phpstan objects to `$this->namespace` on an untyped `$this`, add the narrowest `@phpstan-ignore` or `@var` that silences it; do not widen the helper.
- `controller_for_route()` reads `routes()` then `controllers()`; `null` when unmapped.
- Class docblock: what the registry owns (the two maps, the instances, route attribution, classification merge), that the v2 service map is derived (rule and exceptions), that attribution diffs the route table so callback wrapping is irrelevant, and that the `'v2-'` key prefix is internal. Cite `#544` for the frozen set.

## 3. `API.php` changes

- Replace the property `$controllers` and `$route_map` with `protected Controller_Registry $registry;` (keep `$route_classifier`).
- `register_routes()` becomes: build `$this->route_classifier` as today; `$this->registry = new Controller_Registry(); $this->registry->register( $this->route_classifier );`; then the existing `// Sync classifications are independent of feature-gated route registration.` merge of `Sync\Api::route_classifications()`. Delete everything else in the method (both maps, both filter docblocks — they move to the registry — the legacy table, the reverse lookup).
- `rest_dispatch_request()`: `if ( ! isset( $this->route_map[ $route ] ) )` becomes `$controller = $this->registry->controller_for_route( $route ); if ( null === $controller ) { return $dispatch_result; }`; the later `$key`/`$controller` lookup is deleted and the `wcpos_dispatch_request` call uses `$controller`. The ini/error_reporting block between them is unchanged.
- Nothing else in `API.php` changes.

## 4. Deletions and aliases

- Delete the fifteen twin files: `includes/API/V2/{Auth,Cashier,Checkout_Controller,Data_Order_Statuses_Controller,Extensions,Gateway_Bootstrap_Controller,Logs,Payment_Gateways,Print_Jobs_Controller,Receipts_Controller,Settings,Shipping_Methods_Controller,Stores,Tax_Classes_Controller,Templates_Controller}.php`. `Ping`, `Echo_Probe`, `Site`, `Order_Email_Controller` and every other file under `V2/` stay.
- `includes/API/class-aliases.php`: add fifteen entries `'WCPOS\WooCommercePOS\API\V2\<Name>' => 'WCPOS\WooCommercePOS\API\V1\<Name>'` in a second block under a comment: the wcpos/v2 service twins were folded into `Controller_Registry` in 1.10.19, which stamps the namespace on every v2 entry, so a subclass registered through `woocommerce_pos_rest_api_v2_controllers` still answers under wcpos/v2; a subclass instantiated outside the registry inherits the v1 namespace and must set its own. Update the file header's first paragraph to mention both blocks in one sentence. Alphabetical within the block, aligned like the existing block.
- `tests/includes/Templates/Thermal/Escpos_Thermal_Emitter_Test.php:11` and `Starprnt_Thermal_Emitter_Test.php:11`: delete the unused `use WCPOS\WooCommercePOS\API\V2\Templates_Controller;` line (grep confirms neither file references the name again).

## 5. Tests

Read `tests/lane-coverage/README.md` first. The gate fails any **new** case whose only lane signal is `wcpos/v1`; a `use ...\API\V1\...` import at class scope taints every case in the class. So: the pure cases below import nothing from `API\V1`, and every case that names a v1 class or route also asserts a `/wcpos/v2/...` route literal. Check with `php scripts/lane-coverage.php --json` and look at the new class's cases' `lanes` before you finish.

- **New `tests/includes/API/Test_Controller_Registry.php`** extending `WCPOS_REST_Unit_Test_Case` (Arrange / Act / Assert, `assertSame`, expected first, `test_[feature]_[scenario]_[expected_result]`):
  1. `test_v2_map_promotes_every_v1_key_except_the_frozen_data_controllers` — pure: feed `Controller_Registry::v2_map()` a fake map of string class names (`'auth' => 'Fake_Auth'`, `'products' => 'Fake_Products'`, `'custom_service' => 'Fake_Custom'`, one entry per frozen key) and assert the result's keys are exactly the non-frozen keys followed by the four v2-native keys, with the promoted values unchanged. No filter, no server.
  2. `test_v2_map_v2_native_entry_wins_over_a_v1_key_of_the_same_name` — pure: a v1 map containing `'ping' => 'Fake_Ping'` yields `API\V2\Ping::class` under `ping`.
  3. `test_v2_map_filter_can_add_and_replace_entries` — pure: with a `woocommerce_pos_rest_api_v2_controllers` filter that adds `'extra' => 'Fake_Extra'` and replaces `'auth'`, assert both; remove the filter in `tearDown()`.
  4. `test_frozen_data_keys_are_exactly_the_v1_controllers_that_own_a_dispatch_hook` — drift guard, pure over `Controller_Registry::v1_map()`: for every v1 key, `method_exists( $class, 'wcpos_dispatch_request' )` is true iff the key is in `FROZEN_DATA_KEYS`. This is the rule behind the frozen set (the include/exclude dispatch hack is v1-only), so a data controller added to the map without freezing it fails here.
  5. `test_a_v1_replacement_registers_its_routes_under_v2_without_a_v2_entry` — filter `woocommerce_pos_rest_api_controllers` in `setUp()` **before** `parent::setUp()` (routes are captured at `rest_api_init`) to replace `stores` with an in-file double extending `\WCPOS\WooCommercePOS\API\V1\Stores` (inline FQCN, no `use`) whose `register_routes()` calls the parent then registers `/stores/registry-probe`; assert `'/wcpos/v2/stores/registry-probe'` is a key of `$this->server->get_routes( 'wcpos/v2' )` and that dispatching `GET /wcpos/v2/stores/registry-probe` returns 200. Do not assert the v1 route. Remove the filter in `tearDown()`.
  6. `test_a_v2_filter_entry_without_a_namespace_override_is_stamped_v2` — a double extending `\WCPOS\WooCommercePOS\API\V1\Settings` with **no** `$namespace` property, registered through the v2 filter under `settings` with an extra route `/settings/registry-probe`; assert the route exists under `wcpos/v2` only and dispatches 200. (This is the guarantee ruling 2 gives extension authors.)
  7. `test_route_attribution_survives_closure_wrapped_callbacks` — add a `rest_endpoints` filter (priority 1, removed in `tearDown()`) that wraps every `wcpos/v2` handler callback in a closure (the shape WooCommerce 10.5 `RestApiCache` produces), then read the registry from a fresh `new \WCPOS\WooCommercePOS\API()` by reflection on its `registry` property and assert `controller_for_route( '/wcpos/v2/settings' )` is an instance of `\WCPOS\WooCommercePOS\API\V1\Settings` whose routes were stamped v2 (assert the route key exists in the server's `wcpos/v2` table in the same case). If constructing a second `API` re-registers routes noisily in this suite, instead read the registry off the suite's existing instance the way `Test_Hook_Isolation` does and say so in the report.
  8. `test_an_unmapped_route_has_no_controller` — `controller_for_route( '/wc/v3/products' )` is `null`; pair it with an assertion that `'/wcpos/v2/settings'` maps to a non-null controller so the case carries a current-lane signal.
- **`tests/includes/API/Test_V2_Controllers_Filter.php`**: the double now extends `\WCPOS\WooCommercePOS\API\V2\Settings` which is an alias of `V1\Settings` after this change. Keep the test double extending the **alias** on purpose (it proves the alias resolves and that a subclass registered through the filter is stamped v2) and add one sentence to its docblock saying so. Assertions unchanged.
- **`tests/includes/API/Test_Hook_Isolation.php`** `test_route_map_only_contains_wcpos_routes`: read the `registry` property by reflection instead of `route_map`, then iterate `->routes()`. Assertions unchanged.
- Everything else is expected to pass untouched. Run, in the sandbox, `php -l` on every changed PHP file; there is no PHPUnit in the sandbox (no Docker, no network), the orchestrator runs the suites. Run host phpcs on the changed files: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>` (paths relative to this tree).

## 6. `CONTEXT.md` and `CHANGELOG.md`

- `CONTEXT.md`: add a term after **Create Identity** in the same format:
  **Promoted Service**: a shared POS service (auth, settings, cashier, receipts, print jobs, stores, extensions, logs, gateways, checkout, templates, shipping methods, tax classes, order statuses) that answers identically under `wcpos/v1` and `wcpos/v2`. The Controller Registry derives the v2 map from the v1 map, so promotion is the default and only the nine frozen data controllers (the sync surface replaced them, #544) are excluded. _Avoid_: v2 twin, pass-through subclass, v2 controllers map.
- `CHANGELOG.md` under `## Unreleased`, one `- Changed:` line in the file's voice: the `wcpos/v2` service map is now derived from the v1 controller map, so a plugin that replaces a v1 service through `woocommerce_pos_rest_api_controllers` answers under `wcpos/v2` as well without registering a v2 twin; the fifteen `WCPOS\WooCommercePOS\API\V2\*` pass-through classes are gone and their names alias the v1 classes; a class registered through `woocommerce_pos_rest_api_v2_controllers` no longer needs its own `$namespace`.

## Out of scope

`Route_Classifier` (it differs on `next`; do not touch it). `Sync\Api` and its classifications. The `'v2-'` key prefix. Pro's own three v2 twins (redundant after this, deleted from Pro later). Any `Init.php` or service-construction change (that is card 10). Wiki pages (the orchestrator updates them).

## Budget

Production code net **about −290 lines** (fifteen twins −315, the registry section of `API.php` about −165, the registry about +170, aliases about +20). Total diff including tests **≤ 1100 lines** (the new test file is about 200). If you are about to exceed the total, STOP and report why instead of continuing; splitting across commits does not raise the budget.

## Report

Under 25 lines: files changed with net lines each, `php -l` and phpcs results, the lane-coverage `lanes` of every new case, and any reading you took that differs from this spec. Do not commit.
