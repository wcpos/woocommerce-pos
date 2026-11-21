# Release-notes standard: hill climb, 2026-11-21

Paul asked for this on 21 Nov: "In the readme.txt on WordPress.org there is too much bold. We need formalised guidelines for how to present and craft the release notes. Keep in mind that the GitHub release notes are for technical people, the WordPress release notes are for non technical users and mostly marketing. Do a hill climb on formalising the standard".

How the climb ran:
- One Opus drafter wrote each lap: the standard, the changelog rewrite and the Upgrade Notice.
- Three independent Opus judges scored every lap against `rubric.md`: a non-technical shop owner, an extension developer, and a WordPress.org readme editor who also fact-checked.
- The judges did not see each other's reports or earlier laps' reports.
- Every claim was fact-checked against the commits in v2.0.3, v2.0.4 and v2.0.5 and today's readme.
- Each criterion C1–C7 scores out of 10, so each judge gives up to 70 and a lap up to 210.

| Lap | Shop | Dev | Editor | Total |
|---|---|---|---|---|
| 1 | 57 | 57 | 50 | 164 |
| 2 | 59 | 60 | 57 | 176 |
| 3 | 60 | 56 | 59 | 175 |
| 4 | 54 | 62 | 56 | 172 |
| 5 | 60 | 60 | 59 | 179 |
| 6 | not judged | | | |

How to read the scores:
- Lap 2 scored well but still held overclaims, which laps 3 and 4 found and removed. So the dip after lap 2 is mostly the judges getting stricter, not the text getting worse.
- Lap 5 was a pass that only corrected facts, and it scored highest.
- Lap 6 applied the lap 5 MUST-FIXes that the coordinator accepted (rulings in `rationale.md`) and was not re-judged.

Files:
- `research.md`: how the WordPress.org parser renders a readme, its limits, and the reference changelogs.
- `rubric.md`: the judging rubric.
- `rationale.md`: the drafter's decisions for laps 5 and 6.
