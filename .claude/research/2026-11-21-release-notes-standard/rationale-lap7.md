# Rationale (lap 7: readme side only)

The GitHub side is untouched. Lines 1-160 of standard.md (intro, workflow, classify table, the whole GitHub section) are byte-identical to lap 6, and so are checklist items 1, 2 and 5-7 and the References line. Lap 6's mixed checklist item was split: its GitHub clause is kept word for word as its own item, and its readme greps moved into a new readme item. standard.md is 271 lines. The Upgrade Notice is unchanged (296 characters), because no rule change touches it.

## Required changes
1. Summary sentence. Every entry opens with one plain sentence of at most 200 characters, as its own paragraph. It is in the template, the rules and the Don't list ("the summary sentence included" under no bold). For 2.0.5 it is the ruled one: moving a sale to another cashier, and guest payments on WooCommerce 11.3.1. The 2.0.0 summary comes from the source's own "gives the POS its own checkout" paragraph. "Major" now appears only in 2.0.0's paragraphs and the notice. The rule says "claims nothing the release's PRs do not support", not "the lines below", because 2.0.0's summary names split payments and offline cash, and neither has a bullet.
2. Cap of 7, counting every list in an entry, with one closer per list. The closer starts "Also fixes" (or "Also new" for X.Y.0 features), names the kinds of problem, and links `releases/tag/vX.Y.Z`. Security fixes, lost-sale, lost-payment and lost-record fixes, shop actions, and removals always get their own line.
   - In 2.0.5, these fold into one closer, as ruled: the two sign-in fixes (#2656), the re-created sync table (#2712, #2711) and the error-report context (#2700). The closer still says the error reports are opt-in and go to WCPOS (the data-off-site rule).
   - Two pairs share a line under the new same-PR rule. #2713 joins the checkout failure and the custom-field overwrite, so the loosened-guard warning stays. #2684 joins the returned item and its template action, as an "if you" clause.
3. Selection test stated, and every entry rewritten:
   - 2.0.4, 2.0.3, 2.0.2 and 2.0.1 keep all their lines. Each one passes the test, so only a summary was added.
   - 2.0.0 now has 5 main bullets and 2 for beta testers. The checkout line keeps the Legacy-tab payment point. The registers line keeps the Free/Pro register wording unchanged. The magnetic-reader/Pro line and the Developers line stay as upgrade safety.
   - In 2.0.0, Reports, closures, the display and POS Only hiding fold into "Also new". Split payments, card readers and offline cash are in the summary.
   - In the beta block, the closing-register data-loss fix keeps its own line. The memory, currency-symbol and refund-receipt fixes fold.
4. "Rest of the readme" section: 8 lines.
   - Headings carry structure. A bold-only line becomes `= … =` or a plain line ending in a colon.
   - Bold is allowed for at most one phrase per `== ==` section, and never for a whole line, a line's opening or a lead-in.
   - Pricing, licensing and feature wording is owned by the product.
   - The checklist adds `grep -nE '^([*-] )?\*\*'`, a per-section `**` count and a word-diff check on claims.
5. Upgrade Notice: unchanged.

## Judgement calls
- The 2.0.0 CDN/tamper-check line left the readme; it is in the v2.0.0 GitHub release. (The cashier-approval rule and the POS Only upgrade write were dropped here too; the final pass restores both.)
- A link counts as its text for the 150-character limit. Otherwise no closer could fit.

## Checks run
- `grep '\*\*'` and the italic grep on changelog.txt find nothing.
- Only headings start with `=`.
- Bullet counts: 2.0.5 has 7, 2.0.4 has 2, 2.0.3 has 3, 2.0.2 has 2, 2.0.1 has 1 and 2.0.0 has 7, counted with the checklist's awk.
- The longest bullet is 148 characters and the longest summary is 161.
- The extraction leaks only `== Upgrade Notice ==` after 2.0.0.
- The worked examples were extracted from changelog.txt by the script, so they match it exactly.

## Final pass (judge rulings applied; nothing re-judged)
1. Returned item: "at its negative quantity and real price", because the price stays positive (#2684). The change is in changelog.txt and in the 2.0.5 example.
2. #2713: "a till's custom order field edit now replaces a WP Admin change since its last sync" (146 characters), still sharing the line, so 2.0.5 stays at 7 bullets.
3. Reassign: "from the order screen in WP Admin". The source is changelog-current line 5 and #2719 ("wp-admin cashier select").
4. 2.0.0:
   - The beta block is one plain paragraph after the list, outside the cap. The cashier-approval and POS Only upgrade lines are back (changelog-current lines 58 and 55), and the closer reads "saved closing reports".
   - The always-own list gains new safeguards and upgrade-time record changes, and the beta rule and Cap lines are rewritten to match.
5. Customer line: it quotes "Missing parameter(s): username" or "password", and "them" now covers both.
6. 2.0.5 closer: the error reports are their own "now" clause, and the Closer rule allows one such opt-in, off-site data clause.
7. Readme checklist: widened lead-in grep, a per-section bold awk, and `--word-diff-regex='[^*[:space:]]+'`.
8. Checks rerun: bullets 7/2/3/2/1/7 (2.0.5 to 2.0.0); no `**`; only headings start with `=`; the longest bullet is 148; the examples match; 271 lines; lines 1-160 match lap 6 under cmp.
