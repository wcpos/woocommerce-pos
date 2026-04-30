# WCPOS Agent Instructions

This repository is self-contained for both Claude and Codex. A fresh clone must include the repo-specific rules and skills needed to work here.

Before substantial work:
1. Read this repo's `CLAUDE.md`.
2. Read this repo's `.ai/rules/*.mdc` files.
3. Use this repo's `.claude/skills/*/SKILL.md` files when their descriptions match the task.

Global files such as `/Users/kilbot/.claude/*` are personal maintainer preferences only. They must never replace tracked project context, and agents must not move project-specific rules or skills out of this repository.

Do not create duplicate `.codex` rule/skill sets when the same project guidance already exists in `CLAUDE.md`, `.ai/rules`, or `.claude/skills`.

Critical repo rule: PHP/WordPress tests must run through Docker/wp-env. Do not fall back to local Composer/PHPUnit.
