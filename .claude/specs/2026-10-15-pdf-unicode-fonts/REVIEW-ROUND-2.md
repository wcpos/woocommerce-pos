# Review round 2 — two Codex findings on PR #2276

Both are valid. Implement exactly these two fixes with their tests. Read
`SPEC.md` next to this file first for the background.

## Finding A — a merchant's own `@font-face` under a core name must win

`Pdf_Font_Metrics::setFontFamilies()` aliases `helvetica`, `times`, `courier`
(and the generics) to DejaVu unconditionally. Dompdf's
`parent::setFontFamilies()` merges `$this->userFonts` (families registered by
a template's `@font-face`, loaded from `installed-fonts.json`) over the bundled
map, so a merchant who embeds a font under one of those names currently has it
silently replaced by DejaVu.

**Fix:** in the alias loop, skip any alias that exists in `$this->userFonts`
(protected on the parent; same phpcs ignore comment style as the existing
`fontFamilies` accesses). Keep aliasing families that only exist in the bundled
map. One `continue` plus a one-line comment; nothing else changes.

**Test:** new file `tests/includes/Services/Pdf_Font_Metrics_Test.php`
(`WCPOS\WooCommercePOS\Tests\Services\Pdf_Font_Metrics_Test extends
\WP_UnitTestCase`). Arrange: build `WCPOS\Vendor\Dompdf\Options` with
`fontDir`, `fontCache`, `tempDir` and `chroot` all pointing at a fresh temp
dir under `get_temp_dir()` (so `setFontFamily()`'s `installed-fonts.json`
write lands there and never in the vendor tree); `$dompdf = new Dompdf(
$options )`; `$metrics = new Pdf_Font_Metrics( $dompdf->getCanvas(), $options
)`. Act: `$metrics->setFontFamily( 'helvetica', array( 'normal' =>
'MerchantFont', 'bold' => 'MerchantFont-Bold', 'italic' => 'MerchantFont',
'bold_italic' => 'MerchantFont-Bold' ) )`. Assert with `assertStringEndsWith`:
`getFont( 'helvetica', 'normal' )` ends with `/MerchantFont` (the merchant's
registration survives), and `getFont( 'sans-serif', 'normal' )` ends with
`/DejaVuSans` (families with no user registration are still aliased). Remove
the temp dir in `tearDown()`. This test must fail before the `continue` is
added (the first assertion sees `/DejaVuSans`).

## Finding B — the thermal character grid must use DejaVu Sans Mono's advance

`Html_Thermal_Emitter` sizes the font and the fixed columns from
`CHAR_WIDTH_EM = 0.6`, Courier's advance. The PDF path now renders in DejaVu
Sans Mono, whose advance in Dompdf's metrics (`lib/fonts/DejaVuSansMono.ufm`,
every glyph `WX 602`) is 0.602em. The font-size formula deliberately fills the
printable width exactly, so a full-width line now overflows by 0.33% and
Dompdf wraps its last character (`white-space: pre-wrap; word-break:
break-all`).

`paper_width_px` is **not** a PDF-only signal — `Thermal_Html_Renderer` (the
browser print surface, which really does render in Courier New at 0.6em)
passes it too — so the emitter cannot infer the font. The caller that
guarantees the font says so:

1. `Pdf_Font_Metrics` gets a public constant `MONOSPACE_ADVANCE_EM = 0.602`
   with a comment: the advance of DejaVu Sans Mono per Dompdf's `.ufm`
   metrics, which the aliases in this class guarantee for every monospace
   stack the PDF renderer sees.
2. `Html_Thermal_Emitter::emit()` accepts `$opts['monospace_advance_em']`
   (float, must be numeric and between 0.3 and 1.0 or it is ignored; default
   `self::CHAR_WIDTH_EM`). Store it in a private property set at the top of
   `emit()` and use that property in all three places `CHAR_WIDTH_EM` is read
   today (font sizing, `render_row_cell()` width, the image/barcode width at
   the `$width_em = …` line). Document the option in `emit()`'s docblock next
   to `paper_width_px`.
3. The computed `$font_px` is rounded **down** to two decimals
   (`floor( $font_px * 100 ) / 100`) before formatting, in both paths, so the
   grid never exceeds the printable width through rounding. The existing test
   `test_font_size_scales_to_paper_width` asserts `9.67px` while its own
   docblock says the grid needs a `9.66px` font; change the assertion to
   `9.66px` (the docblock is right).
4. `Template_Pdf_Service::render()` passes
   `'monospace_advance_em' => Pdf_Font_Metrics::MONOSPACE_ADVANCE_EM` in the
   `emit()` options next to `paper_width_px`. `Thermal_Html_Renderer` is not
   changed.

**Tests:**

- `tests/includes/Templates/Thermal/Html_Thermal_Emitter_Test.php`: new
  `test_font_size_and_columns_use_given_monospace_advance`. Arrange a 48-column
  receipt with one row containing a 12-char fixed column (copy the row shape
  from `test_row_renders_table_with_em_col_widths`). Act with
  `array( 'paper_width_px' => 302.36, 'monospace_advance_em' => 0.602 )`.
  Assert `font-size: 9.63px` (278.36 / (48 × 0.602) = 9.6329, floored) and
  `width: 7.22em` (12 × 0.602 = 7.224). Also assert that an out-of-range value
  (`'monospace_advance_em' => 5`) falls back to the Courier grid (`font-size:
  9.66px`).
- ~~A fit-height test comparing 42-char and 41-char lines~~ — **withdrawn
  after the mutation check (2026-10-15):** Dompdf cannot break a run of
  letters, so the 0.33% overflow clips the last glyph instead of wrapping it,
  and page geometry never changes. The test passed with the Courier advance
  too. Replaced by `test_pdf_grid_fits_printable_width_in_dejavu_sans_mono`
  in `Html_Thermal_Emitter_Test`, which measures the emitted font size with
  Dompdf's own `FontMetrics::getTextWidth()` against the printable width and
  also asserts that the Courier-sized grid does *not* fit, so the measurement
  is proven sensitive.

Run the three affected test classes (`Html_Thermal_Emitter_Test`,
`Template_Pdf_Service_Test`, `Pdf_Renderer_Test`) — see Verification below for
how — plus the new `Pdf_Font_Metrics_Test`.

## Stakes, scope, budget

Same tier and out-of-scope list as `SPEC.md` (Medium; no new settings,
env vars, filters, fallbacks or retries; no `vendor_prefixed/` edits). The
`monospace_advance_em` option and the public constant are the only new
surface. Do not touch `Thermal_Html_Renderer`, the browser print path, or the
other thermal emitters.

Budget: at most 50 non-test lines across `Pdf_Font_Metrics.php`,
`Html_Thermal_Emitter.php` and `Template_Pdf_Service.php`, and 220 lines total
diff. If you are about to exceed either, STOP and report why instead of
continuing. Splitting the work into more files or commits does not raise this
budget.

## Verification

Codex's sandbox has no Docker, so the wp-env PHPUnit run is done by Claude
afterwards. Codex: `php -l` every PHP file you touch. Do not commit.
