#!/usr/bin/env python3
"""Compose side-by-side browser-vs-PDF snapshots for every rendered template.

Run AFTER the wp-env harness has populated out/ with <slug>.html + <slug>.pdf
(see render-receipt-pdfs.php). For each template this renders the same HTML
that Dompdf consumed in headless Chrome (the "browser truth") at the PDF's
page width, rasterizes the PDF at the matching dpi, and writes a labeled
side-by-side PNG to out/compare-<slug>.png for visual inspection.

Host-side tool: requires Google Chrome, poppler (pdftoppm) and Pillow.

    python3 tests/Tools/pdf-harness/compare-preview-pdf.py [slug ...]
"""

import pathlib
import subprocess
import sys
import tempfile

CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
OUT = pathlib.Path(__file__).resolve().parent / "out"
DPI = 96  # rasterize the PDF at CSS-pixel density so widths line up 1:1

# CSS-pixel page widths matching Template_Pdf_Service paper boxes.
A4_PX = 794  # 595.28pt
PAPER_PX = {
    "thermal-detailed-58mm": 219,  # 164.41pt
    "thermal-simple-58mm": 219,
}
THERMAL_DEFAULT_PX = 302  # 226.77pt (80mm)


def page_width_px(slug: str) -> int:
    if slug in PAPER_PX:
        return PAPER_PX[slug]
    if slug.startswith("thermal-"):
        return THERMAL_DEFAULT_PX
    return A4_PX


def chrome_screenshot(html_file: pathlib.Path, width: int, out_png: pathlib.Path) -> None:
    # Constrain the fragment with a fixed-width container instead of the
    # window size: headless Chrome clamps very narrow windows (thermal paper
    # is ~302px) and reflows the content. The default sans stack stands in for
    # the app preview's UI font: wp_kses strips quoted font-family
    # declarations from the PDF-path HTML, so the fragment itself carries none
    # (Dompdf falls back to DejaVu Sans there).
    wrapper = (
        "<!doctype html><html><head><meta charset='utf-8'>"
        "<style>html,body{margin:0;padding:0;background:#fff;"
        "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}</style></head><body>"
        f"<div style='width:{width}px'>"
        + html_file.read_text()
        + "</div></body></html>"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False) as tmp:
        tmp.write(wrapper)
        tmp_path = tmp.name

    subprocess.run(
        [
            CHROME,
            "--headless=new",
            "--disable-gpu",
            "--hide-scrollbars",
            "--force-device-scale-factor=1",
            f"--window-size={max(width + 40, 800)},4000",
            f"--screenshot={out_png}",
            f"file://{tmp_path}",
        ],
        check=True,
        capture_output=True,
    )

    from PIL import Image

    with Image.open(out_png) as shot:
        shot.crop((0, 0, width, shot.size[1])).save(out_png)


def pdf_to_png(pdf_file: pathlib.Path, prefix: pathlib.Path) -> list[pathlib.Path]:
    subprocess.run(
        ["pdftoppm", "-png", "-r", str(DPI), str(pdf_file), str(prefix)],
        check=True,
        capture_output=True,
    )
    return sorted(prefix.parent.glob(prefix.name + "-*.png"))


def trim_bottom(img):
    """Crop trailing all-white rows (the unused tail of the Chrome viewport)."""
    from PIL import ImageChops, Image

    background = Image.new(img.mode, img.size, "#ffffff")
    bbox = ImageChops.difference(img.convert("RGB"), background.convert("RGB")).getbbox()
    if bbox is None:
        return img
    return img.crop((0, 0, img.size[0], min(img.size[1], bbox[3] + 16)))


def compose(slug: str, browser_png: pathlib.Path, pdf_pngs: list[pathlib.Path]) -> pathlib.Path:
    from PIL import Image, ImageDraw

    browser = trim_bottom(Image.open(browser_png))
    pdf_pages = [Image.open(p) for p in pdf_pngs]

    pdf_w = max(p.size[0] for p in pdf_pages)
    pdf_h = sum(p.size[1] for p in pdf_pages) + 8 * (len(pdf_pages) - 1)

    label_h = 28
    gutter = 24
    width = browser.size[0] + gutter + pdf_w
    height = label_h + max(browser.size[1], pdf_h)

    sheet = Image.new("RGB", (width, height), "#dddddd")
    draw = ImageDraw.Draw(sheet)
    draw.text((4, 6), f"{slug} — BROWSER (Chrome)", fill="#000000")
    draw.text((browser.size[0] + gutter + 4, 6), "PDF (Dompdf)", fill="#000000")

    sheet.paste(browser, (0, label_h))
    y = label_h
    for page in pdf_pages:
        sheet.paste(page, (browser.size[0] + gutter, y))
        y += page.size[1] + 8

    out = OUT / f"compare-{slug}.png"
    sheet.save(out)
    return out


def main() -> int:
    slugs = sys.argv[1:] or sorted(p.stem for p in OUT.glob("*.pdf"))
    with tempfile.TemporaryDirectory() as tmp:
        tmp_dir = pathlib.Path(tmp)
        for slug in slugs:
            html_file = OUT / f"{slug}.html"
            pdf_file = OUT / f"{slug}.pdf"
            if not html_file.exists() or not pdf_file.exists():
                print(f"skip {slug}: missing html/pdf in {OUT}")
                continue

            browser_png = tmp_dir / f"{slug}-browser.png"
            chrome_screenshot(html_file, page_width_px(slug), browser_png)
            pdf_pngs = pdf_to_png(pdf_file, tmp_dir / f"{slug}-pdf")
            out = compose(slug, browser_png, pdf_pngs)
            print(f"wrote {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
