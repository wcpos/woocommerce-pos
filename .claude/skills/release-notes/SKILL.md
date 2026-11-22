---
name: release-notes
description: Use when preparing a WCPOS release (a `chore(release): X.Y.Z` PR), writing or editing the readme.txt changelog or Upgrade Notice, or writing GitHub release notes.
---

# Release notes

The standard is `.ai/rules/release-notes.mdc`. Read the whole file before you write a line. It holds the classification table, both templates, the do and don't lists, worked examples for 2.0.3 to 2.0.5, and the pre-publish checklist.

Every release gets two texts written from the same merged PRs:

- **GitHub release notes**, for developers. Write them in the release PR description under `## GitHub release notes`. They are technical, with a PR number on every bullet.
- **The readme.txt changelog and Upgrade Notice**, for shop owners on WordPress.org. They are plain words, with no bold, no PR numbers and no internal terms.

Steps, all in the release PR:

1. Run `git log --first-parent --format='%h %s' vPREV..HEAD` and read every PR body. Never write from commit subjects alone.
2. Classify each effect of each PR with the table in the standard.
3. Write the GitHub notes, then the readme entry, then the Upgrade Notice. Apply the retention rule.
4. Work through the pre-publish checklist and paste its result into the release PR.

While `release.yml` still builds the release body from the readme entry, whoever publishes the release replaces that body with the PR's `## GitHub release notes` section (`gh release edit vX.Y.Z --notes-file notes.md`), as the standard describes.

A release, tag or version bump of this plugin needs Paul's word. This skill only covers writing the texts.
