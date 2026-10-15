# Plugin size audit — 1.11.1 release zips (2026-10-15)

**Problem.** Pro is installed by manual upload (it is not on wp.org), so the zip has to fit under the
host's upload ceiling. PHP's shipped `post_max_size` is 8M (8 MiB = 8,388,608 bytes) and that is the
common floor on shared hosts. Updates go through WP's updater (server-side download), so the limit bites
only the first install.

## Measured (GitHub release assets, `gh release download v1.11.1`)

| zip | bytes | MiB | vs 8 MiB |
|---|---|---|---|
| woocommerce-pos.zip (free) | 8,158,596 | 7.78 | −0.22 |
| woocommerce-pos-pro.zip | 8,738,835 | 8.33 | **+0.33 (over)** |

Pro = the vendored free plugin (7.4 MiB of 7.9 MiB compressed content) + ~0.5 MiB of its own. Free's
unprefixed `vendor/` libs are NOT duplicated in Pro (Composer merges them), so there is no dedupe win.

### Where the free zip's 7.46 MiB of compressed content goes

| compressed | what |
|---|---|
| 3.57 MiB | `vendor_prefixed/dompdf/dompdf` — of which 3.4 MiB is 12 DejaVu `.ttf` + `.ufm` files |
| 1.11 MiB | `assets/img/template-gallery` (16 preview `.webp`, 0.97 MiB, + `preview-assets/coffee-monster.png` 148 KiB) |
| 0.71 MiB | `assets/js` (template-editor 312 KiB = CodeMirror, template-gallery 160, settings 120, rest small) |
| 0.86 MiB | `includes/` PHP |
| 0.25 MiB | `vendor/` (ramsey/uuid, mustache, brick/math, parsedown) |
| 0.18 MiB | `assets/img` icons (android-chrome-512 64 KiB, wcpos-pro-icon 41 KiB) |
| 0.16 MiB | sentry/sentry (+ guzzle psr7 83 KiB it drags in) |
| ~0.5 MiB | everything else (qrcode 97, css-parser 53, html5 53, barcode 34, jwt 11, templates 54…) |

`zip -9` instead of the default level saves 18 KB. Not a lever.

## What was decided before, and where it landed

All three size PRs merged on **`next` (the 2.0 lane) on 2026-09-10** and have never been on `main`:

- #1933 — gallery previews served from jsDelivr (`cdn.jsdelivr.net/gh/wcpos/woocommerce-pos@<major.minor>/assets/img/template-gallery/previews`), `.distignore` drops the folder; plus `scripts/trim-dompdf-fonts.php` appended to `composer prefix-dependencies` (so it survives CI regenerating `vendor_prefixed/`).
- #1934 — zero TTFs in the zip: DejaVu moved to `fonts/packs/dejavu/` (distignored), `Font_Pack_Loader` installs into `uploads/wcpos-fonts/` from jsDelivr.
- #1938 — background install via Action Scheduler, admin notice on persistent failure.

`main` (1.11.x) still ships all 12 DejaVu faces and all previews. `main` also gained #2276
(`Pdf_Font_Metrics`, 2026-10-15) which aliases `serif`/`times` → `dejavu serif`, `sans-serif`/`helvetica`
→ `dejavu sans`, `monospace`/`courier`/`fixed` → `dejavu sans mono`. `next` has no `Pdf_Font_Metrics`
yet; the next sync will have to reconcile it with `Font_Pack_Loader`.

## Options for the released line (projected from the 1.11.1 zips)

| option | free | Pro | scope |
|---|---|---|---|
| A. Previews to jsDelivr (backport #1933 minus the font part) | 6.81 | 7.36 | ~60 lines in `Menu.php`/`Frontend.php` + `.distignore` + 1 test. Tag range `@1.11` verified to resolve on jsDelivr. |
| B. Trim 7 DejaVu faces (Serif ×4, Sans-BoldOblique, SansMono-Oblique, SansMono-BoldOblique) | 6.18 | 6.73 | `scripts/trim-dompdf-fonts.php` + `prefix-dependencies` step; `installed-fonts.dist.json` must alias removed variants (Dompdf falls through to the BOLD file for a missing italic). On `main` also change `Pdf_Font_Metrics` so `serif`/`times` → `dejavu sans` (one Georgia-stack template loses its serif look in PDF). |
| A + B | **5.21** | **5.76** | both of the above |
| C. Full font-pack backport (#1934 + #1938) + A | 3.61 | 4.16 | ~2 new classes, Activator/Init wiring, scheduler, notice, ~10 test files; a High-tier change (background downloads, uploads writes) on the release lane. |
| D. Wait for 2.0 | 7.78 | 8.33 | Pro stays un-uploadable on 8 MiB hosts until 2.0 ships. |

Smaller levers, not worth their own PR but free riders in A/B: `coffee-monster.png` (148 KiB, the
preview fixture logo, referenced by `Receipt_Preview_Fixture_Loader` via `PLUGIN_URL` so it can go to
the same CDN base), `android-chrome-512x512.png` (64 KiB; check for a consumer), `wcpos-pro-icon.png`
(41 KiB).

Not levers: dompdf src (needed), Sentry (166 KiB, consent-gated error reporting, #1811), CodeMirror
bundle (loaded only on the editor page; could be CDN-served later but it is 0.3 MiB).

**Recommendation:** A + B on `main` now (one PR, two commits; lands in both zips because Pro vendors
free and re-runs `prefix-dependencies` at build). Pro drops to ~5.8 MiB, free to ~5.2 MiB, with 2+ MiB
of headroom. C stays on `next` where it already lives; nothing is thrown away because 2.0 supersedes
the trim.

## Interaction with #2278 (merged to `main` 2026-10-15)

#2278 makes `serif`/`times`/`times-roman` resolve to the first of `dejavu serif`, `dejavu sans` present in
Dompdf's lookup table, and `monospace` to `dejavu sans mono` only. It was written for exactly the reduced
set option B produces (sans + mono faces, `bold_italic` → Bold, mono italic → regular), and its tests
build their own font table, so they hold with or without the trim. `Pdf_Renderer_Test` was relaxed in the
same PR to accept the Bold face where BoldOblique used to be asserted.

Option B therefore neither breaks nor is blocked by #2278. Two implementation requirements it adds:

1. The trim step on `main` must **rewrite** `installed-fonts.dist.json`, not just delete files: drop the
   `dejavu serif` entry entirely (an entry pointing at a deleted file would still win #2278's preference
   order and then fail to load), and alias the surviving families the way `fonts/packs/dejavu/pack.json`
   does on `next` (`dejavu sans` bold_italic → `DejaVuSans-Bold`; `dejavu sans mono` italic →
   `DejaVuSansMono`, bold_italic → `DejaVuSansMono-Bold`). `next`'s script unsets all three entries
   because the pack supplies a fresh map; `main` has no pack, so it keeps the two rewritten entries.
2. Keep `DejaVuSansMono.ttf` in the bundle: `Raster_Thermal_Emitter` reads it by path, and
   `Pdf_Font_Metrics::MONOSPACE_ADVANCE_EM` is its metric.

Option C (font packs) is the configuration #2278 was designed against on `next`, so it is compatible by
construction.
