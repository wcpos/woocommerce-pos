# WCPOS Agent Instructions

This repo uses `CLAUDE.md` as the single repo-local source of truth for both Claude and Codex.

Before substantial work:
1. Read `/Users/kilbot/.claude/CLAUDE.md` (primary maintainer local setup).
2. Read `/Users/kilbot/.claude/rules/*.mdc` (primary maintainer local setup).
3. Read this repo's `CLAUDE.md`.

Do not create duplicate rule or skill sets in `.ai/`, `.codex/`, or repo-local `.claude/skills` when the same guidance belongs in the global `/Users/kilbot/.claude` tree or this root `CLAUDE.md`.

Critical repo rule: PHP/WordPress tests must run through Docker/wp-env. Do not fall back to local Composer/PHPUnit.
