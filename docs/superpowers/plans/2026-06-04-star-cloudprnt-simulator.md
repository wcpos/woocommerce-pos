# Star CloudPRNT Simulator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a small workspace package that simulates a Star CloudPRNT printer polling StarIO.Online.

**Architecture:** Add `packages/star-cloudprnt-simulator` as a dependency-free Node package. Split protocol behavior into device profile helpers, a CloudPRNT client class, and a CLI entry point.

**Tech Stack:** pnpm workspace, Node ESM, Node built-in `fetch`, Node built-in `node:test`.

---

## File Structure

- Create: `packages/star-cloudprnt-simulator/package.json` — package metadata and scripts.
- Create: `packages/star-cloudprnt-simulator/src/device-profile.mjs` — fake device headers, status payloads, and client-action responses.
- Create: `packages/star-cloudprnt-simulator/src/cloudprnt-client.mjs` — CloudPRNT POST/GET/DELETE protocol loop unit.
- Create: `packages/star-cloudprnt-simulator/src/cli.mjs` — command-line parser and polling loop.
- Create: `packages/star-cloudprnt-simulator/test/device-profile.test.mjs` — tests for device profile behavior.
- Create: `packages/star-cloudprnt-simulator/test/cloudprnt-client.test.mjs` — tests for CloudPRNT protocol behavior.
- Create: `packages/star-cloudprnt-simulator/test/cli.test.mjs` — tests for CLI config parsing.

## Task 1: Device Profile Test and Implementation

**Files:**
- Create: `packages/star-cloudprnt-simulator/test/device-profile.test.mjs`
- Create: `packages/star-cloudprnt-simulator/src/device-profile.mjs`

- [ ] Write failing tests for default profile, headers, poll body, and client actions.
- [ ] Run `node --test packages/star-cloudprnt-simulator/test/device-profile.test.mjs` and verify it fails because `src/device-profile.mjs` does not exist.
- [ ] Implement `createDeviceProfile`, `buildRequestHeaders`, `buildPollBody`, and `handleClientActions`.
- [ ] Re-run the device-profile test and verify it passes.

## Task 2: CloudPRNT Client Test and Implementation

**Files:**
- Create: `packages/star-cloudprnt-simulator/test/cloudprnt-client.test.mjs`
- Create: `packages/star-cloudprnt-simulator/src/cloudprnt-client.mjs`

- [ ] Write failing tests for no-job polls, job GET/DELETE flow, and client-action reporting.
- [ ] Run `node --test packages/star-cloudprnt-simulator/test/cloudprnt-client.test.mjs` and verify it fails because `src/cloudprnt-client.mjs` does not exist.
- [ ] Implement `CloudPrntSimulator.pollOnce()` using injected `fetch` for testability.
- [ ] Re-run the CloudPRNT client test and verify it passes.

## Task 3: CLI Test and Implementation

**Files:**
- Create: `packages/star-cloudprnt-simulator/test/cli.test.mjs`
- Create: `packages/star-cloudprnt-simulator/src/cli.mjs`
- Create: `packages/star-cloudprnt-simulator/package.json`

- [ ] Write failing tests for CLI flag and environment parsing.
- [ ] Run `node --test packages/star-cloudprnt-simulator/test/cli.test.mjs` and verify it fails because `src/cli.mjs` does not exist.
- [ ] Implement `parseArgs`, `runLoop`, and executable CLI behavior.
- [ ] Add package scripts: `start`, `test`, and `lint`.
- [ ] Re-run CLI tests and verify they pass.

## Task 4: Validation

**Files:**
- Validate the whole new package.

- [ ] Run `pnpm --filter @wcpos/star-cloudprnt-simulator test` and verify all tests pass.
- [ ] Run `pnpm --filter @wcpos/star-cloudprnt-simulator lint` and verify syntax checks pass.
- [ ] Run `pnpm -r --filter @wcpos/star-cloudprnt-simulator run test` if needed to confirm workspace filtering.
- [ ] Inspect `git diff --stat` and `git diff` for accidental scope creep.
