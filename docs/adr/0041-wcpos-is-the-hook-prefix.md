---
status: accepted (Paul, 2026-10-22) — the Free cutover PR, the Pro cutover PR and the wiki pass follow
---

# `wcpos_` is the hook prefix

From 2.0 every hook WCPOS fires is named `wcpos_*` in Free and `wcpos_pro_*` in Pro.
Every `woocommerce_pos_*` and `woocommerce_pos_pro_*` hook name is a deprecated alias
fired through WordPress core's `apply_filters_deprecated()` / `do_action_deprecated()`,
deprecated in 2.0.0 with the `wcpos_` name as the replacement, and removed in 2.2 at the
earliest under the compatibility rule in `CLAUDE.md`. New hooks take the `wcpos_` name
only; a new `woocommerce_pos_` hook is a review defect.

**Why.** The product was WooCommerce POS and was renamed WCPOS for trademark reasons. The
code kept the old prefix: on `next` before this ADR Free fires 131 hooks named
`woocommerce_pos_*` and 18 named `wcpos_*`, with no rule saying which is current, so each
new hook picked a prefix by whatever its neighbour used. The failure mode is already in
the tree: `Stock_Validator::exempt_statuses()` fires the same list through both names back
to back, silently, so nobody ever migrated and both now look supported. A major release is
the one moment the cutover costs nothing extra: 2.0 is the protocol boundary, Pro 2.0
bundles Free 2.0, and the extensions already bootstrap behind a version guard.

**Scope: hooks and filters only.** Options, transients, meta keys, capabilities, post types
and the WordPress hooks core derives from them (`pre_update_option_woocommerce_pos_*`,
`update_option_woocommerce_pos_*`) are stored identifiers. Renaming one is a data
migration with an uninstall path and a rollback story, and it is a separate decision for
each identifier, not a sweep. They keep their names. Function aliases
(`woocommerce_pos_request()` and friends) are governed by the compatibility rule and the
2.0 shim removal (#2373), not by this ADR.

**Mechanism.** The new name fires first, then the deprecated one, so a legacy callback sees
the value every current callback has already filtered:

```php
$value = apply_filters( 'wcpos_receipt_data', $value, $order );
$value = apply_filters_deprecated( 'woocommerce_pos_receipt_data', array( $value, $order ), '2.0.0', 'wcpos_receipt_data' );
```

Actions use `do_action_deprecated()` the same way. Core only fires the deprecated hook,
and only logs a `_deprecated_hook()` notice under `WP_DEBUG`, when something is attached
to the old name, so an unhooked alias costs one `has_filter()` call. Dynamic names
(`woocommerce_pos_{$id}`, `woocommerce_pos_pre_save_{$id}`, `woocommerce_pos_saved_{$id}`)
follow the same two-call pattern with `wcpos_{$id}` as the replacement. A hook is never
fired under two names without the deprecated wrapper; the silent-twin pattern in
`Stock_Validator` is retired by this ADR.

**Who subscribes to what.**

- *Free, Pro and both test suites* attach to the `wcpos_` name only. Pro 2.0 ships its own
  bundled Free 2.0 and deactivates a standalone Free, so Pro migrates its 22 subscriptions
  outright and needs no dual registration. `Init::hook_rows()` and `Hook_Manifest` rows
  name the new hooks.
- *Extensions* keep the `woocommerce_pos_` name until 2.0 ships, because a callback attached
  only to a `wcpos_` name does nothing on a Free 1.x site, where nothing fires it. In the
  first release after 2.0 an extension switches to the `wcpos_` name and raises its Free
  floor to 2.0 in the bootstrap check it already has. An extension never attaches to both
  names: on 2.0 and 2.1 the old name still fires through the deprecated path, so both
  registrations would run the callback twice.
- *The wiki and docs.wcpos.com* name the `wcpos_` form, with the old name noted as
  deprecated until the 2.2 removal lands.

**Lint.** `.phpcs.xml.dist` lists `woocommerce_pos_` as the allowed global prefix, which is
why every `wcpos_` hook today carries a `NonPrefixedHooknameFound` ignore comment. The
cutover adds `wcpos_` to the prefix list and removes those comments; `woocommerce_pos_`
stays in the list until 2.2 because the deprecated hook names and the two surviving
function aliases still need it. Until then, "no new `woocommerce_pos_` hook" is enforced by
review, not by the sniff.

**Removal.** In 2.2 the `*_deprecated` calls, the ignore comments on them and the
`woocommerce_pos_` prefix entry go, in one PR per repo, with one "Developers:" changelog
line each. No test asserts that a removed hook is absent.
