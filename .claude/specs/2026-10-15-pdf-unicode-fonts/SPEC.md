# PDF receipts: non-Latin text renders as `?`

## Symptom

A Greek merchant's receipt PDF (default "Legacy PHP Template", rendered through
`Template_Pdf_Service` → `Pdf_Renderer` → bundled Dompdf 3.1.6) prints every
label, the date, the status pill and the payment method as runs of `?`, while
the item names and the three table headers keep their Greek letters.

## Root cause (verified with a standalone Dompdf probe)

`Pdf_Renderer::build()` sets Dompdf's `defaultFont` to `dejavu sans`, but that
only applies when *no* family in an element's `font-family` list is known to
Dompdf. Every receipt template (legacy PHP, all gallery HTML templates, the
thermal HTML emitter) ends its stack in a generic family — `sans-serif`,
`serif`, `monospace` — and Dompdf's bundled map
(`vendor_prefixed/dompdf/dompdf/lib/fonts/installed-fonts.dist.json`) resolves
those to the PDF core fonts Helvetica / Times / Courier. Core fonts are
Windows-1252 only; Dompdf's `Cpdf::filterText()` converts text with
`mb_convert_encoding(…, 'Windows-1252', 'UTF-8')`, so any character outside
cp1252 becomes a literal `?` in the content stream.

Why the table survived: `.items th` and `.items .name` use `font-weight: 600`.
`FontMetrics::getType()` turns 600 into the subtype string `"600"` (only 400 →
`normal` and 700 → `bold` are named), no core family has a `"600"` variant, so
`getFont('sans-serif', '600')` returns null and Dompdf falls through to the
default font — DejaVu Sans Bold, a Unicode TTF — which renders Greek. Weight
400/700 text matches Helvetica / Helvetica-Bold and is lost.

Probe output (same options as `Pdf_Renderer`):

```
sans-serif/normal => …/lib/fonts/Helvetica
sans-serif/bold   => …/lib/fonts/Helvetica-Bold
sans-serif/600    => NULL   → default/600 => …/lib/fonts/DejaVuSans-Bold
monospace/normal  => …/lib/fonts/Courier
BaseFonts in output: Helvetica, SUBAAC+DejaVuSans-Bold, Helvetica-Bold, Courier
Decompressed content stream contains: (??????)   ← "Σύνολο" at weight 400
```

## Fix

Alias the Latin-only core families to the bundled DejaVu Unicode families,
in memory, for every Dompdf instance `Pdf_Renderer` builds. No template edits:
merchants' custom templates and the thermal emitter are covered the same way.

Alias map (lower-case family → bundled family whose variants to reuse):

| alias        | target             |
|--------------|--------------------|
| sans-serif   | dejavu sans        |
| helvetica    | dejavu sans        |
| serif        | dejavu serif       |
| times        | dejavu serif       |
| times-roman  | dejavu serif       |
| monospace    | dejavu sans mono   |
| courier      | dejavu sans mono   |
| fixed        | dejavu sans mono   |

`symbol` and `zapfdingbats` stay as they are.

### Implementation shape

1. New class `includes/Services/Pdf_Font_Metrics.php`
   (`WCPOS\WooCommercePOS\Services\Pdf_Font_Metrics`) extending
   `WCPOS\Vendor\Dompdf\FontMetrics`. Override the public
   `setFontFamilies()`: call `parent::setFontFamilies()`, then for each alias
   whose target exists in `$this->fontFamilies`, set
   `$this->fontFamilies[ $alias ] = $this->fontFamilies[ $target ]`. The alias
   map is a class constant with a comment explaining why (cp1252 core fonts).
   Nothing is written to disk — do **not** use `setFontFamily()`, which
   persists `installed-fonts.json` into the font cache dir.
2. In `Pdf_Renderer::build()`, right after `new Dompdf( $options )`, install it
   on **both** holders: `Dompdf::setFontMetrics()` and
   `$dompdf->getCss()->setFontMetrics()`. The constructor hands its stock
   FontMetrics to the Stylesheet, every `Style` resolves `font-family` through
   that copy, and `FontMetrics::getFont()` caches by family name in a static
   shared across the process — so a half install lets the first empty inline
   element pin a family to its core font for the whole document (found in
   review, 2026-10-15). Wrap the two calls in `Pdf_Font_Metrics::install()`.
   `Dompdf::setPaper()` swaps the canvas without re-pointing the metrics; both
   the stock and the replacement instance stay bound to the constructor
   canvas, which is unchanged behaviour. Keep the install before `loadHtml()`.
3. Keep the existing `defaultFont` handling untouched.

### Tests (wp-env PHPUnit, `tests/includes/Services/Pdf_Renderer_Test.php`)

Add a private helper that decompresses every `stream … endstream` block of a
PDF with `gzuncompress()` (fall back to the raw bytes when that fails) and
returns the concatenation, plus a helper that lists `/BaseFont /Name` entries.

- `test_render_html_keeps_non_latin_text_at_core_font_weights`
  Arrange: a full HTML document (`<!DOCTYPE html>…<meta charset="utf-8">`)
  whose body font-family is
  `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`, with a
  `font-weight: 400` div, a `font-weight: 700` div and a
  `font-family: 'Courier New', Courier, monospace` div, each containing the
  Greek word `Σύνολο` (6 letters) between ASCII markers, e.g. `AAAA Σύνολο`.
  Act: `$this->renderer->render_html( $html )`.
  Assert: the decompressed streams contain no `(` … `??????` … `)` text run
  (use a regex like `/\(\s*[A-Z]{4} \?{6}/`), and the `/BaseFont` list
  contains no `Helvetica`, `Helvetica-Bold` or `Courier` entry while it does
  contain a `DejaVuSans` subset (name matches `/DejaVuSans/`).
  This test must fail before the production change (it does: see probe) and
  pass after.
- `test_render_html_receipt_layout_thermal_uses_unicode_monospace`
  Render through `Template_Pdf_Service` is heavier; instead call
  `render_html()` with `receipt_layout => true`, `default_font => 'dejavu sans mono'`
  and a div styled exactly like `Html_Thermal_Emitter::emit()`'s root
  (`font-family: 'Courier New', Courier, monospace`) containing `Σύνολο`.
  Assert no `Courier` BaseFont and no `??????` run.

Existing tests in `Pdf_Renderer_Test` and `Template_Pdf_Service_Test` must
keep passing (the thermal fit-height tests assert page widths, not fonts).

## Stakes

Medium tier (`.ai/rules/stakes-tiers.mdc`: receipt rendering and templates).
A failure here is a wrong font or a mis-laid-out PDF; no money or data is
lost. Treat concurrency, cache-dir permission and partial-failure findings as
accepted risks, not defects.

## Out of scope

- No edits to any template under `templates/` or to `Html_Thermal_Emitter`.
- No new Dompdf options, env vars, settings, filters or constants beyond the
  alias map.
- No writes to the font cache dir (`setFontFamily()` / `saveFontFamilies()`).
- No font files added; use only the DejaVu fonts Dompdf already bundles.
- No conditional "only when the HTML has non-Latin text" logic — the alias is
  unconditional so a store's PDFs always use the same embedded font.
- No changes to `vendor_prefixed/` (it is php-scoper output).
- No retries, fallbacks, feature flags, plan files, changelog edits.

## Budget

At most 60 non-test lines (new class + renderer wiring, docblocks included)
and 180 lines total diff. If you are about to exceed either, STOP and report
why instead of continuing. Splitting the work into more files or commits does
not raise this budget.

## Verification

Codex's sandbox has no Docker, so the wp-env PHPUnit run is done by Claude
afterwards. Codex: run `php -l` on every PHP file you touch, and run
`vendor/bin/phpcs --standard=.phpcs.xml.dist <changed files>` if a `vendor/`
directory exists (it may not — then skip and say so).
