# Equality tests in WCPOS receipt templates

Filed in `.claude/research/` — the repo's existing convention for research notes (17 prior files, e.g.
`.claude/research/2026-08-29-hostile-edge-browser-request-audit.md`). There is no `docs/research/`.

## Question

Receipt templates are Mustache, rendered by `mustache.php` server-side and mirrored by a JS renderer in
the POS app. There is no equality/comparison test, so a template cannot write
`if discount_type == 'smart_coupon'`. Why not, how hard would adding one be, and is the limitation
technical or a design choice?

## Short answer

**A design choice, but an inherited one — nobody in this repo ever decided to forbid comparisons.**
Mustache the *language* has no comparison operator; that is what "logic-less" means. WCPOS picked
Mustache for a different reason entirely: PHP↔JS render parity. The one recorded rationale
(`docs/plans/2026-03-05-offline-rendering-design.md:12-25`) is a table comparing bundle size and
parity — "Logic-less by design" appears as a neutral row, never as a goal.

Adding equality is not blocked by a technical wall on either side: `mustache.php` v3 has a helper
registry, lambdas on by default, and a `FILTERS` pragma; `mustache.js` 4.2 calls function values.
The wall is **serialisation**. `receipt_data` crosses the wire as JSON (REST → RxDB → offline
render), and functions do not survive JSON. Any lambda-based `eq` would work in PHP and silently
render nothing offline — destroying the exact property Mustache was chosen for.

The cheap answer is the one the codebase already uses everywhere: emit the boolean in the data.
`tax.display_incl`/`tax.display_excl` exist for precisely this reason and are used 16 times each in
the shipped gallery templates.

Note also that **`discount_type` is not currently emitted at all** — discount rows carry only
`label`, `code`, `total`, `total_incl`, `total_excl` (`includes/Services/Receipt_Data_Schema.php:803-828`).
So no comparison operator would help today regardless; the field has to be added either way.

## How WCPOS renders templates today

Three PHP call sites construct a Mustache engine, all with **identical, minimal** options — only
`entity_flags` and a custom `escape` closure. **No helpers, no pragmas, no `lambdas => false`:**

- `includes/Templates/Renderers/Logicless_Renderer.php:56-66` — HTML receipts
- `includes/Templates/Thermal/Thermal_Renderer.php:148-159` — thermal templates
- `includes/API/V1/Templates_Controller.php:1087-1097` — admin preview

Bundled engine: **`mustache/mustache` v3.0.0** (`composer.lock`; `vendor/mustache/mustache/src/Engine.php:38`).

**The thermal XML parser does not constrain Mustache.** `Thermal_Renderer::build_ast()` runs
`$mustache->render( $content, $data )` first, strips XML-illegal control characters, and only then
calls `Thermal_Markup_Parser::parse( $xml )` (`Thermal_Renderer.php:161-176`). Mustache never sees
XML structure; the parser never sees Mustache tags. The only constraint the pipeline imposes is that
the *rendered output* be well-formed XML.

**JS side: stock `mustache@4.2.0` (janl/mustache.js), unpatched.** Declared in
`packages/receipt-renderer/package.json`, `packages/core/package.json` (monorepo-v2) and
`packages/thermal-utils/package.json`, `packages/template-editor/package.json` (woocommerce-pos);
resolved to `4.2.0` in both lockfiles. Every one of the 7 call sites is a two-argument
`Mustache.render(template, data)` — **no partials object is ever passed**, so `{{>partial}}` silently
renders empty on the client. Actual render: `packages/receipt-renderer/src/render-template.ts`
(`renderLogiclessTemplate`, `renderThermalPreview`); offline entry point
`packages/core/src/screens/main/receipt/hooks/use-template-renderer.ts`.

**Data preparation already synthesises booleans so templates can branch.** This is the house pattern:

- `tax.display` (string `incl`/`excl`) is shipped *alongside* `tax.display_incl` / `tax.display_excl`
  booleans; `tax.breakdown` alongside `breakdown_hidden`/`breakdown_single`/`breakdown_itemized`
  (`includes/Services/Receipt_Data_Schema.php:933-964`).
- `has_tax_summary` is a **required top-level key** (`Receipt_Data_Schema.php:35`), described as
  "Whether tax_summary contains at least one row" (`:1412-1416`).
- `totals.total_saved_complete`, `lines[].savings_in_discounts`, `order.needs_payment` — same shape.
- `Receipt_Data_Builder.php:320-321` states the motive outright: *"Item count summaries — useful for
  packing slips and kitchen tickets where Mustache can't sum/count an array at render time."*
- `ZERO_FALSY_MONEY_FIELDS` (`Receipt_Data_Schema.php:74-79`) keeps zero money numeric *"so that
  section guards like `{{#change}}...{{/change}}` treat them as empty."*
- `_display` companion fields exist *"so a single Mustache template renders the same way in both the
  studio (JS) and production print (PHP)"* (`Receipt_Data_Schema.php:220-230`).

Live usage in `templates/gallery/standard-receipt.html:116`:
`{{#tax.display_excl}}{{i18n.total_tax}}{{/tax.display_excl}}{{#tax.display_incl}}{{i18n.included_tax}}{{/tax.display_incl}}`
— an enum branched on by two mutually-exclusive booleans, because there is no `eq`.

**The editor is not a WYSIWYG.** It is CodeMirror 6 plus a one-way field-picker tree fed by
`Receipt_Data_Schema::get_field_tree()` (`docs/plans/2026-03-06-template-editor-design.md:9,88,232`;
`includes/Admin/Templates/Single_Template.php:560`). Clicking a field inserts text at the cursor; the
editor never parses syntax back out. So "the visual editor must be able to parse it" is **not** a
constraint here — a common assumption that this repo does not actually have.

## What the Mustache spec allows

- Sections are **truthiness-only**. Non-list data is coerced to a list "if the data is truthy
  (e.g. `!!data == true`)". No comparison or equality operator is defined anywhere.
  (https://github.com/mustache/spec `specs/sections.yml`)
- **Dotted names are valid for section tags.** Test "Dotted Names - Truthy": *"Dotted names should be
  valid for Section tags."* Test "Dotted Names - Broken Chains": *"Dotted names that cannot be
  resolved should be considered falsey."* — an unresolvable key is falsey, not an error. This is what
  makes the data-shaped option safe for unknown coupon types.
- **Lambdas are an optional module.** The spec directory ships `~lambdas.yml`; the README states
  *"Specification files beginning with a tilde (`~`) describe optional modules"* and cites lambdas as
  the example, since they handle code as a data type not all languages support.
  (https://github.com/mustache/spec/blob/master/README.md)

## What mustache.php and mustache.js each support

| | mustache.php 3.0.0 | mustache.js 4.2.0 |
|---|---|---|
| Helper registry | **Yes** — `helpers` option, `HelperCollection` (`Engine.php:256,492-528`) | **No** — none documented, none exists |
| Lambdas / function values | **Yes, on by default** (`Engine.php:80 private $lambdas = true`) | **Yes** — `value.call(this.view)`; sections get `(rawText, render)` |
| Pragmas | `FILTERS`, `ANCHORED-DOT`, `BLOCKS` (`Engine.php:41-53`) | **None** |
| `strict_callables` | Default `true` — only Closures/invokables called, *"helps protect against arbitrary code execution when user input is passed directly into the template"* (`Engine.php:178-188`) | n/a |
| Lambda re-render | `double_render_lambdas` default **false** in v3 | Lambda must call `render()` itself |
| Partials | Available | **Dead in WCPOS** — no call site passes partials |

So an `eq` *is* expressible in each, but by **different mechanisms**: PHP via a registered helper or
`{{%FILTERS}}`, JS only by putting a function into the view object. Mustache tags take no arguments,
so the operand has to arrive as the section body text — e.g. `{{#eq_discount_type}}smart_coupon{{/eq_discount_type}}`
— and the lambda must reach the current row via `LambdaHelper` (PHP) or `this` (JS), two different
context mechanisms with different re-render defaults. They *could* be made to behave identically, but
only by writing and maintaining the operator twice.

## Options

### 1. Do nothing
**Effort:** zero. **Parity risk:** none. Templates keep branching on booleans the data provides.
Fails only when a merchant needs a distinction the schema does not already expose.

### 2. Lambda-based `eq` convention
```mustache
{{#discounts}}{{#eq_discount_type}}smart_coupon{{/eq_discount_type}}{{/discounts}}
```
**Effort:** ~1 day PHP, more in JS. **Parity risk: severe — this is the disqualifying option.**
`receipt_data` is JSON: served by `Templates_Controller::prepare_non_legacy_preview_response()`, validated
against a JSON Schema, rebuilt offline by `buildReceiptData` → `mapReceiptData` → `formatReceiptData`,
which construct fresh plain objects from Zod-typed scalars and **drop any function**. A lambda would
have to be re-injected client-side, i.e. implemented twice, in two languages, with two different
context/re-render semantics — reintroducing precisely the divergence the engine choice eliminated.
`docs/superpowers/specs/2026-04-29-template-studio-design.md:299` already flags lambdas as a parity
risk: *"PHP Mustache.php and JS Mustache may diverge on niche features (lambdas, partial inheritance,
custom delimiters)."*

### 3. Handlebars migration
```handlebars
{{#each discounts}}{{#if (eq discount_type "smart_coupon")}}Store credit{{/if}}{{/each}}
```
**Effort:** weeks. **Parity risk: high, plus a security regression.**
- Handlebars ships **no** `eq` helper. Built-ins are only `if`, `unless`, `each`, `with`, `lookup`,
  `log`; `#if` is truthiness-only. `{{#if (eq a b)}}` needs a helper you register yourself.
  (https://handlebarsjs.com/guide/builtin-helpers.html)
- The PHP counterpart, LightnCandy, **compiles templates to PHP source** and its README says
  *"DO NOT COMPILE ON PRODUCTION"*. WCPOS templates are merchant-editable and stored in `wp_posts`, so
  compilation would necessarily happen on production, at render time, over user-supplied input. That
  is the `Legacy_Php_Renderer` RCE class of bug the project has already fixed twice (commits
  `f90f3d59`, `375b8408`; `readme.txt:228`).
- LightnCandy also needs `FLAG_HANDLEBARSJS` opt-ins to match JS semantics, supports only 4 of 10
  Mustache lambda specs, and shows no published releases on GitHub.
- Blast radius: 15 gallery templates, the thermal XML path, `wp_kses_post`/DOMPurify escaping,
  the CodeMirror Mustache mode and section matcher, and every merchant's saved template.

### 4. Data-shaped booleans (recommended)
```mustache
{{#discounts}}
  {{#discount_type_is.smart_coupon}}<row>Store credit -{{total_display}}</row>{{/discount_type_is.smart_coupon}}
  {{^discount_type_is.smart_coupon}}<row>{{label}} -{{total_display}}</row>{{/discount_type_is.smart_coupon}}
{{/discounts}}
```
**Effort:** ~half a day to a day. **Parity risk: near zero** — it is plain JSON, and both engines
implement dotted-name sections per spec. Unknown types are falsey by "Broken Chains", so a
third-party coupon type never errors.
Touches: `Receipt_Data_Builder::build()` (the coupon loop, `:243-256`), `Receipt_Data_Schema`
field tree + JSON Schema, and the JS mirrors `build-receipt-data.ts` / `map-receipt-data.ts` /
`receipt-schema`.
**Costs, honestly:** the key set is unbounded — `smart_coupon` is registered through the
`woocommerce_coupon_discount_types` filter, so any plugin can add one. The field picker enumerates
the schema and cannot enumerate an open map. Mitigations: emit `discount_type` as a plain string
too (enumerable, printable, useful on its own), and note that `discount_type_is` has **exactly one
true key per row**, so schema noise is one small object per discount line. The JSON Schema already
sets `additionalProperties: true` on `discounts` items (`Receipt_Data_Schema.php:1419-1428`), so
validation needs no change.

### 5. Small custom preprocessor
Rewrite `{{#eq discount_type "smart_coupon"}}…{{/eq}}` into plain Mustache before rendering.
**Effort:** weeks. **Parity risk: high and permanent.** A preprocessor that resolves a value against
the data *is* a template engine, so it must be written twice (PHP + TS) and kept byte-identical
forever. It also breaks the CodeMirror Mustache language mode and section matcher
(`packages/template-editor/src/codemirror/`), and it invents syntax no Mustache tooling recognises.
Strictly worse than option 4 for the same outcome. *(A preprocessor over the **data** rather than the
template is just option 4 with extra indirection.)*

## Recommendation

**Option 4.** Add `discount_type` (string) and `discount_type_is` (single-key boolean map) to each
discount row, on both the PHP and JS builders, in the same commit as the schema and field-tree update.
It is the pattern the codebase already established for `tax.display_incl`/`display_excl` and
`has_tax_summary`, it is spec-guaranteed identical in both engines, it costs no new template syntax,
and it degrades to falsey — not to an error — for coupon types nobody anticipated.

Reject option 2 and option 5 for the same reason: both require the comparison operator to be
implemented twice in two languages, which is the failure mode Mustache was chosen to avoid. Reject
option 3 additionally because compiling merchant-editable templates to PHP on production reopens a
vulnerability class this project has already closed.

Worth considering separately: the *general* case. If a second enum needs branching later, prefer
extending the existing `<enum>` + `<enum>_is.<slug>` convention over reopening the engine question.

## Not verified

- I did not run a differential PHP/JS render test to confirm the two engines agree on lambda
  semantics. The `double_render_lambdas` default (`false` in mustache.php v3) versus mustache.js's
  "lambda calls `render()` itself" is a documented difference, but its practical impact is untested.
- LightnCandy's latest release version, date, and maintenance status — the GitHub page shows an empty
  Releases section and install instructions pointing at `dev-master`. CI matrix stops at PHP 8.1.
- Two PHP/JS divergences were reported by a sub-agent and not independently confirmed by me: the PHP
  renderers strip `<!-- -->` before Mustache runs while monorepo-v2's `renderLogiclessTemplate` does
  not, and the `$formatted_data['t'] = true` i18n passthrough flag has no JS equivalent (so `{{#t}}`
  blocks would render empty offline). Both are unrelated to equality but worth a separate look.
- No performance measurement of any option.
