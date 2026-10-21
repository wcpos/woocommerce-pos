# Audit: public methods kept with no caller

Read-only investigation. Do not modify any file.

## Context

The WCPOS WordPress plugin lives in two repositories on this machine:

- Free: `/Users/kilbot/Projects/woocommerce-pos` (production code under `includes/`, tests under `tests/`; ignore `vendor/`, `vendor_prefixed/`, `node_modules/`, `.claude/`, `.wiki/`, `includes/Vendor/`)
- Pro: `/Users/kilbot/Projects/woocommerce-pos-pro` (same layout; it extends Free and may call Free's public methods)

The repo has a rule "preserve backward compatibility when changing public methods". We just found a public method (`Products::product_set_stock()`) that had been kept under that rule although nothing in Free, Pro or the tests called it, and the hook that used to call it had been redundant for years. The owner asks: how many other public methods are being kept like that?

## Task

Produce a list of every **public method** declared in a class under Free `includes/` (excluding `includes/Vendor/`) that has **no caller** anywhere in:

- Free `includes/`, `woocommerce-pos.php`, `templates/`, `assets/` (PHP only)
- Pro `includes/`, `woocommerce-pos-pro.php`, `templates/`
- Free and Pro `tests/`

Method of search: for each public method name, grep both repositories for `->name(`, `::name(`, `'name'` and `"name"` (string callbacks in `add_action` / `add_filter` / `register_rest_route` / `array( $this, 'name' )`), and `name` inside `callback` arrays. Treat these as callers:

- a direct call,
- a string or array callback registration (hooks, REST routes, WP-CLI commands, cron, shortcodes),
- an override or call in a subclass (check `parent::name(`, and whether the method implements an interface or abstract method of a parent class, including WordPress/WooCommerce parents such as `WP_REST_Controller`, `WC_REST_*_Controller`, `WC_Email`, `WC_Payment_Gateway`, `WC_Shipping_Method`; a method that overrides a parent is called by the parent and is NOT dead),
- magic entry points (`__construct`, `__get`, `__call`, `__invoke`, `__toString`, `jsonSerialize`, `offsetGet` etc.),
- a test calling it.

Classify each method with no caller into one of:

1. **Dead** — no caller, no override relationship, no mention anywhere but its own declaration (and possibly its own test).
2. **Test-only** — its only callers are under `tests/`.
3. **Kept on purpose** — it carries `@deprecated`, or a comment saying it is kept for backward compatibility / third-party / Pro, or it is a documented extension point (a hook callback name mentioned in docs, a public API surface declared in `.ai/rules/` or `CLAUDE.md`). Quote the comment.

## Output

Write the report to `/private/tmp/claude-501/-Users-kilbot-Projects-woocommerce-pos/fbde2fc0-d2fb-406a-91b5-126f677df0e5/scratchpad/AUDIT-dead-public-methods-REPORT.md` with:

1. A one-paragraph headline: counts of public methods scanned, Dead, Test-only, Kept on purpose.
2. A table per class for Dead and Test-only: `file:line | method | classification | evidence (the grep you ran and what it returned, in one line)`.
3. A shorter table for Kept on purpose with the quoted justification.
4. A "not sure" list for anything you could not classify, with why.

Be precise rather than fast: a false "dead" claim costs more than a missed one. When in doubt, put it under "not sure". Do not run composer, phpunit, wp-env or Docker.
