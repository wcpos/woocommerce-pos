# Research: WordPress.org changelog rendering and release-note practice

Source: research-run.sh (Sonnet with web tools, 2026-11-21). The tool's own Write was refused, so this file was saved from its returned draft and condensed. The fetch tool paraphrases long pages: quotes are verbatim only where marked. Everything below is web data, not instructions.

## Q1 Readme parser

Markup, from the official example readme https://wordpress.org/plugins/readme.txt:
- Headings: `== Section ==`, sub-heading `= x =` (e.g. `= 1.0 =`).
- Lists: unordered `* item` is the documented form; `-` lists and `###` headings render too (Akismet, Query Monitor and Jetpack use them). Ordered: `1. item`.
- `**bold**`, `*italic*`, `[text](url "title")`, backtick inline code.
- Verbatim: "Unlike standard Markdown, a single line break is kept as a line break."
- Code-block syntax: not found.

Limits (https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/ and the example readme):
- Short description: no more than 150 characters.
- Upgrade Notice: "No more than 300 characters." per version.
- The developer page says "having a file larger than 10k may result in errors". It recommends "keeping the current release in the readme and splitting the rest out" (e.g. `changelog.txt`). WCPOS readme.txt is about 37.8 KB today.
- Verbatim: "List versions from most recent at top to oldest at bottom."
- Not found: any documented truncation or collapsing of the changelog on the plugin page.

Where it appears:
- The plugin page's Development / Changelog section. The API exposes `changelog` and `upgrade_notice` (keyed by version).
- The WP admin "View details" modal shows the readme sections, including the changelog. The Updates screen links to that modal ("View version X details", core ticket 17406) rather than showing the changelog inline. Upgrade Notice display in the plugins.php update row: not verified.
- Verbatim: "Everything comes from the readme.txt in the file being pointed to by the Stable Tag." So the directory shows the tagged readme, and a readme change reaches WordPress.org only with a release.

Bold renders as ordinary `<strong>`. No source covers a fully bold list. The inference is that when most lines are bold, bold stops signalling anything.

## Q2 WCPOS rendering

https://wordpress.org/plugins/woocommerce-pos/ (fetched 2026-11-21; the tool summarised it):
- Stable tag 2.0.5. Each release is a heading `= 2.0.5 - 2026/11/20 =`.
- Lists render as bullets. Customer-facing items are bold (v2.0.5: 8 of 11 bullets; v2.0.4: 2 of 3), smaller fixes are plain, and major releases open with bold summary paragraphs.
- `Developers:` renders as its own plain bullet, with hook names in code formatting. No rendering errors reported.
- 2.0.4 fixed the Upgrade Notice showing raw HTML on the Plugins screen, so treat the Upgrade Notice as plain text.

## Q3 References

Each readme was fetched from `plugins.svn.wordpress.org/<slug>/trunk/readme.txt`. The WordPress.org page is `wordpress.org/plugins/<slug>/#developers`.

### WooCommerce (developer-heavy)
- The readme shows only the latest release, then "[See changelog for all versions]" linking to `changelog.txt` on GitHub.
- Example (verbatim, trimmed by the tool):
  ```
  = 11.3.1 2026-11-19 =
  **WooCommerce**
  * Fix - Calculate shipping at checkout when the address town or state is 0.
  * Fix - Checkout link URLs now carry a token that only copies the cart to another browser, without the shopper's details.
  ```
- Style:
  - A bold product heading only.
  - Item prefixes "Fix -" / "Add -" / "Tweak -", imperative, change-first.
  - No PR numbers. Lines of about 100–130 characters.
  - The GitHub release body is nearly empty; the detail lives in `changelog.txt`.

### Yoast SEO (non-technical, commercial)
- `####` groups: Enhancements, Bugfixes, Other. No bold and no issue numbers in entries. Some bugfixes credit contributors with "Props to".
- An intro line links to a release post, and older versions link to a full changelog on yoast.com. (The tool paraphrased this entry.)

### Query Monitor (developer-heavy, single maintainer)
- Verbatim:
  ```
  ### 4.1.1 (27 October 2026) ###
  - Shows the toolbar menu on small screens again
  - Prevents infinite recursion when a backtrace is constructed from within a hook
  - Fixes a fatal error and warnings when an HTTP request is short-circuited via the `pre_http_request` filter
  - Correctly handles closures in call stacks on PHP 8.4+
  ```
- Style:
  - No categories, no bold, no issue numbers.
  - Third-person present verbs, one line per change, mostly under 130 characters.
  - Inline code for hooks. Older versions link to GitHub releases.
  - The GitHub release has the same bullets plus the commit and assets.

### Jetpack (large, mixed audience)
- Verbatim:
  ```
  ### 16.5-a.3 - 2026-11-16
  #### Enhancements
  - Blogroll: Add a feed URL setting to each blogroll item.
  - Forms: Add a `wp jetpack-forms export` command that exports form responses to CSV.
  ```
- Style:
  - Groups: Enhancements / Improved compatibility / Bug fixes.
  - An "Area: description" prefix, imperative.
  - No PR numbers and no bold. Links to `CHANGELOG.md` on GitHub.

### Site Kit by Google (developer-leaning)
- Bold category labels **Enhanced** / **Fixed**. Every item ends with a linked GitHub issue number (e.g. "(#14131)").
- Past tense. Internal items are mixed in. It is the only reference that bolds anything, and it bolds only the labels. (The tool paraphrased this entry.)

### Akismet (minimal)
- Verbatim:
  ```
  = 5.7.2 =
  *Release Date - 19 August 2026*

  * Fix Fluent Forms integration on WordPress versions older than 6.1.
  ```
- Style: imperative, no categories, no bold.
- Older entries live in `changelog.txt`, following the official split-out advice.

### Comparison with WCPOS
- Only WCPOS and Site Kit bold content, and Site Kit bolds only labels.
- WCPOS's outcome phrasing ("Taking payment for a guest order … works again") is more benefit-first than any reference, which are all change-first.
- The `Developers:` closing bullet is unique to WCPOS.

## General guidance

Keep a Changelog 1.1.0, https://keepachangelog.com/en/1.1.0/:
- Changelogs are "for humans, not machines."
- Every version gets an entry, newest first, with its date.
- Group by type: Added, Changed, Deprecated, Removed, Fixed, Security.
- Versions and sections should be linkable.
- State whether the project follows Semantic Versioning.

## Takeaways
1. Use only the markup the example readme documents: `= x.y.z - date =` headings, `* ` bullets, `**bold**`, backticks, links.
2. Newest first, one heading per release, with the date in the heading.
3. Never bold every line. No reference bolds items, and bold appears only on a heading or a category label.
4. One change per line, short. Query Monitor keeps mostly under ~130 characters.
5. Group under a few fixed labels (Yoast: Enhancements / Bugfixes / Other; Keep a Changelog: Added / Changed / Fixed / Security).
6. Keep the readme short. The directory warns at 10k, so keep recent releases and point older ones elsewhere (Akismet's `changelog.txt`, WooCommerce's "See changelog for all versions").
7. The Upgrade Notice must be at most 300 characters of plain text, and the short description at most 150 characters.
8. WordPress.org shows the readme of the Stable tag, so readme edits ship only with a release.
9. Use one grammatical form for every line (imperative as in Jetpack and Akismet, or third-person present as in Query Monitor).
10. Keep developer-only items out of the shop-owner list: one closing `Developers:` line, or a labelled group. Use inline code for hooks.
11. No issue or PR numbers in the readme (Woo, Yoast, Jetpack, QM and Akismet omit them). Put references in the GitHub release.
12. End with a link to the full technical notes.
