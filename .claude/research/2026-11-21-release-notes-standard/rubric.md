# Rubric: WCPOS release-notes standard (hill climb)

Every judge scores the WHOLE submission of one lap. A submission is three files:
- `standard.md`: the release-notes standard. It has two sections, GitHub release notes and readme.txt changelog. Each section has a template, a do/don't list and worked examples for 2.0.3, 2.0.4 and 2.0.5.
- `changelog.txt`: the full `== Changelog ==` section of readme.txt, rewritten to the standard.
- `rationale.md`: what changed since the last lap and why.

Score each criterion from 1 to 10 using the anchors. 10 means you cannot name a change that would make it better for your reader, 7 means good with real flaws, 4 means a reader is let down often, and 1 means it fails. Give whole numbers. Score from your persona, but every criterion applies to you.

## C1 Audience fit
- For the readme changelog: a shop owner who runs a till and is not technical understands every line without help. Each line says what they will notice, and it reads as a reason to trust or update the plugin without turning into an advert.
- For the GitHub notes: a developer or integrator learns what changed in code and contract terms (routes, hooks, filters, data, compat) and whether they must act.
- Anchors:
  - 10: each audience is served, and nothing written for one leaks into the other.
  - 4: jargon in the readme, or marketing in GitHub.

## C2 Scannability
- Can a reader find what matters to them in about 5 seconds per release?
- Look at: line length, one change per line, ordering (most noticed first), grouping only where it helps, and the headings.
- Anchors:
  - 10: a reader skimming the rendered page picks out the change that affects them.
  - 4: wall of text or long compound lines.

## C3 Honesty
- Every claim in a worked example and in `changelog.txt` must be supported by the inputs: the current changelog text and the commit logs. Nothing may be inflated ("much faster", "major") or invented, and nothing material may be dropped.
- A fix stays a fix. It is not dressed up as a feature.
- Security or data-loss fixes are not hidden.
- Anchors:
  - 10: no unsupported claim.
  - Any invented fact caps this criterion at 3. Name the line.

## C4 Length
- A readme entry is short enough to read on the plugin page or in the "View details" modal.
- The changelog section as a whole respects WordPress.org's size guidance (the readme should stay small; older entries are moved out or linked).
- GitHub notes are complete but not padded.
- The standard itself must be short enough that a release agent actually reads it: about 250 lines or fewer.
- Anchors:
  - 10: every element earns its length.
  - 4: padded or truncated.

## C5 Formatting restraint
- Bold is almost never used in the readme. If it is allowed at all, the rule says exactly when (for example one lead line on a major release), and the examples obey it.
- No decorative emoji. No all-caps. Code formatting only for names a developer types.
- The GitHub notes use plain Markdown structure.
- Anchors:
  - 10: the rules are explicit and testable, and every example and every line of `changelog.txt` obeys them.
  - 4: bold or ornament without a rule.

## C6 WordPress.org parser correctness
- `changelog.txt` and the readme template render correctly under the readme parser as researched in `research.md`.
- The `= X.Y.Z - YYYY/MM/DD =` heading format is kept.
- No `= … =` heading may appear inside an entry, because `.github/scripts/readme-changelog-section.sh` extracts an entry up to the next line starting `= `.
- Only markup the parser documents is used.
- Upgrade Notice rules: at most 300 characters, plain text.
- Anchors:
  - 10: nothing would mis-render or break extraction.
  - 4: any breakage.

## C7 Operability
- Could a release-prep agent with no memory of this conversation follow the standard and produce both notes from the merged PRs, without asking anything?
- Look for: where each text is written and in what order; how to classify a change (shop owner vs developer vs leave out); what to do with release-mechanics PRs (translations, pot updates, version bumps); a pre-publish checklist. Also check how it handles today's fact that the GitHub release body is generated from the readme entry by a held `.github` workflow.
- Anchors:
  - 10: mechanical to follow.
  - 4: needs judgement calls the standard does not settle.

## Output (every judge)
Write the scores as a JSON line, then the findings:

```
{"judge":"<id>","lap":N,"C1":x,"C2":x,"C3":x,"C4":x,"C5":x,"C6":x,"C7":x,"total":sum}
```

- MUST-FIX: up to 5 items. Each one quotes the exact line it concerns and gives a concrete replacement or rule.
- NICE: up to 3 items.
- ONE-LINE VERDICT.

Only name problems that you would actually change. "None" is a valid answer.
