---
status: accepted
---

# No comparison logic in receipt templates

Receipt templates stay logic-less. WCPOS will not add an equality or comparison
operator to the template language — no `eq` helper, no lambda convention, no
preprocessor, no engine change to Handlebars — and no vendor-specific boolean in
the receipt data contract to stand in for one. Branching in a template is done on
booleans and sections that the data already carries; anything a template needs to
branch on that WooCommerce does not model is added to the payload by the plugin
that owns the concept, through `woocommerce_pos_receipt_data`.

**Why not an operator.** Every receipt renders in two places from the same
template: the server (`mustache/mustache`, PHP) and the app's offline renderer
(`mustache.js`). Neither engine forbids helpers or lambdas. What forbids them is
the wire: receipt data travels as JSON from REST into the app's local store and
functions do not survive serialisation, so a server-side `eq` would work on the
server and render nothing offline. Keeping the two renders identical is the
reason Mustache was chosen (`docs/plans/2026-03-05-offline-rendering-design.md`),
and any operator would have to be implemented and kept in step twice, in two
languages, with engines that expose the current row differently. A Handlebars
migration fails the same parity test and adds a worse problem: the PHP
implementations compile merchant-editable templates to PHP at render time, which
reopens the arbitrary-code class this project already closed when it retired the
legacy PHP templates.

**Why not a flag per plugin.** A `discounts[].is_store_credit` was proposed and
rejected. WCPOS is extensible the way WooCommerce is: it exposes WooCommerce's own
data (`discount_type` is the coupon type WooCommerce stores) and it filters its
payload so plugins add what they need. It does not learn one vendor's coupon type,
because the next vendor would need the next field and the contract would become a
list of plugins.

**What templates do instead.** Guard on data. The contract already ships
`tax.display_incl`, `tax.display_excl`, `has_tax_summary` and the zero-falsy money
fields for exactly this purpose, and the shipped gallery templates use them. A
plugin that needs a template to know about its concept adds a key on
`woocommerce_pos_receipt_data` and its users guard on that key:

```
{{#gift_card}}Gift Card{{/gift_card}}{{^gift_card}}{{i18n.discount}}{{/gift_card}}
```

If a second enum ever needs template branching without a plugin, the recorded
option is a slug-keyed boolean map beside the enum (`<enum>_is.<slug>`), which is
spec-guaranteed identical in both engines and degrades to falsy for unknown
values. That is a data-shape decision, not a reopening of the engine question.

Research: `.claude/research/2026-09-02-mustache-equality-in-receipt-templates.md`.

Decided 2026-09-02 with Paul while landing `discount_type` and the
`woocommerce_pos_receipt_data` filter (free#1832).
