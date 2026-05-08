# WCPOS Agent Instructions

This repository is self-contained for both Claude and Codex. A fresh clone must include the repo-specific rules and skills needed to work here.

Before substantial work:
1. Read this repo's `CLAUDE.md`.
2. Read this repo's `.ai/rules/*.mdc` files.
3. Use this repo's `.claude/skills/*/SKILL.md` files when their descriptions match the task.

Global files such as `/Users/kilbot/.claude/*` are personal maintainer preferences only. They must never replace tracked project context, and agents must not move project-specific rules or skills out of this repository.

Do not create duplicate `.codex` rule/skill sets when the same project guidance already exists in `CLAUDE.md`, `.ai/rules`, or `.claude/skills`.

Critical repo rule: PHP/WordPress tests must run through Docker/wp-env. Do not fall back to local Composer/PHPUnit.

## Review guidelines

Respect documented author intent and check for companion PRs.

- Read the PR body before the diff. If it has sections like "Design
  decisions", "Companion PRs", "Cross-repo", or "Intent", treat them as
  the author's binding statement of design — constraints, not code to
  second-guess. Do not raise a finding that would contradict a documented
  choice.
- Assume work often spans multiple repos in this org. "Missing caller",
  "dead code", and "unused export" findings are often wrong because the
  caller lives in a companion PR. Before flagging dead or missing code,
  check whether the PR description references companion PRs in other
  repos.
- When author intent is unclear, ask a question rather than request a
  change.
