# Audit: public methods kept with no caller

FREE_CHECKOUT: <absolute path to the Free worktree to audit, pinned to the commit under study>
PRO_CHECKOUT: <absolute path to the Pro worktree to audit, pinned to the commit under study>

Read-only investigation. Do not modify any file. Put the full report in your final message; do not write a file.

## Context

The WCPOS WordPress plugin lives in two repositories, at the two paths above:

- Free: `FREE_CHECKOUT` (production code under `includes/`, tests under `tests/`; ignore `vendor/`, `vendor_prefixed/`, `node_modules/`, `.claude/`, `.wiki/`, `includes/Vendor/`)
- Pro: `PRO_CHECKOUT` (same layout; it extends Free and may call Free's public methods)

Inventory public methods declared in classes **and in traits**. Include the root entry files (`woocommerce-pos.php`, `woocommerce-pos-pro.php`, `uninstall.php`, any other root `*.php`) in every caller search; `woocommerce-pos.php` is where WP-CLI commands are registered.

The repo has a rule "preserve backward compatibility when changing public methods". We just found a public method (`Products::product_set_stock()`) that had been kept under that rule although nothing in Free, Pro or the tests called it, and the hook that used to call it had been redundant for years. The owner asks: how many other public methods are being kept like that?

## Task

Produce a list of every **public method** declared in a class or trait under Free `includes/` (excluding `includes/Vendor/`) that has **no production caller**. The input set is methods with no caller in the production paths below; calls from `tests/` do not keep a method out of the set, they only decide whether it is classified **Test-only** rather than **Dead**. The production paths are:

- Free `includes/`, `templates/`, `assets/` (PHP only), and every root PHP file (`woocommerce-pos.php`, `uninstall.php`, any other `*.php` at the repository root)
- Pro `includes/`, `templates/`, and every root PHP file (`woocommerce-pos-pro.php`, `uninstall.php`, any other `*.php` at the repository root)
- (Free and Pro `tests/` are searched too, but only to classify: a method whose only callers are tests is **Test-only**, not out of the set.)

Method of search: for each public method name, grep both repositories for `->name(`, `::name(`, `'name'` and `"name"` (string callbacks in `add_action` / `add_filter` / `register_rest_route` / `array( $this, 'name' )`), and `name` inside `callback` arrays. Treat these as callers:

- a direct call,
- a string or array callback registration (hooks, REST routes, WP-CLI commands, cron, shortcodes),
- an override or call in a subclass (check `parent::name(`, and whether the method implements an interface or abstract method of a parent class, including WordPress/WooCommerce parents such as `WP_REST_Controller`, `WC_REST_*_Controller`, `WC_Email`, `WC_Payment_Gateway`, `WC_Shipping_Method`; a method that overrides a parent is called by the parent and is NOT dead),
- magic entry points (`__construct`, `__get`, `__call`, `__invoke`, `__toString`, `jsonSerialize`, `offsetGet` etc.),
- a test calling it.

Classify each method with no caller into one of:

1. **Dead** — no caller anywhere, tests included, no override relationship, no mention but its own declaration.
2. **Test-only** — its only callers are under `tests/` (its own direct unit test counts here, so a method with a direct test is never Dead; the handoff decides which Test-only rows are suite seams and which are dead wrappers).
3. **Kept on purpose** — it carries `@deprecated`, or a comment saying it is kept for backward compatibility / third-party / Pro, or it is a documented extension point (a hook callback name mentioned in docs, a public API surface declared in `.ai/rules/` or `CLAUDE.md`). Quote the comment.

## Output

Put the report in your final message (the run is read-only; the operator saves it beside the handoff as `AUDIT-uncalled-public-methods.md`) with:

1. A one-paragraph headline: counts of public methods scanned, Dead, Test-only, Kept on purpose.
2. A table per class for Dead and Test-only: `file:line | method | classification | evidence (the grep you ran and what it returned, in one line)`.
3. A shorter table for Kept on purpose with the quoted justification.
4. A "not sure" list for anything you could not classify, with why.

Be precise rather than fast: a false "dead" claim costs more than a missed one. When in doubt, put it under "not sure". Do not run composer, phpunit, wp-env or Docker.
