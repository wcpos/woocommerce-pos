# Shared UI Form Primitives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand `@wcpos/ui` beyond Button by adding shared baseline form primitives and previewing them in Pro Store Edit.

**Architecture:** Keep `@wcpos/ui` dependency-light and source-consumed by app packages. Shared components provide stable Tailwind-prefixed classes and small public prop APIs. Consumer packages keep local compatibility shims where their existing API differs.

**Tech Stack:** React 18, TypeScript, Tailwind v4 with `wcpos:` prefix, Vitest, Testing Library, classnames.

---

## Task 1: Add shared primitive coverage

**Files:**
- Create: `packages/settings/src/components/ui/__tests__/shared-form-controls.test.tsx`

- [ ] **Step 1: Write failing tests that import primitives directly from `@wcpos/ui`**

The test imports `Toggle`, `Checkbox`, `TextInput`, `TextArea`, `Select`, `Modal`, and `Tooltip` from `@wcpos/ui`, renders their baseline behavior, and asserts accessible roles/classes.

- [ ] **Step 2: Run the test and verify it fails**

Run: `pnpm --filter=@wcpos/settings test -- src/components/ui/__tests__/shared-form-controls.test.tsx`

Expected: FAIL because those exports do not exist yet.

## Task 2: Implement shared primitives in `packages/ui`

**Files:**
- Create: `packages/ui/src/toggle.tsx`
- Create: `packages/ui/src/checkbox.tsx`
- Create: `packages/ui/src/text-input.tsx`
- Create: `packages/ui/src/text-area.tsx`
- Create: `packages/ui/src/select.tsx`
- Create: `packages/ui/src/modal.tsx`
- Create: `packages/ui/src/tooltip.tsx`
- Modify: `packages/ui/src/index.ts`

- [ ] **Step 1: Add dependency-light React implementations**

Implement plain React primitives with static `wcpos:` classes. Use native inputs/selects, a plain `button role="switch"` Toggle, a conditional dialog Modal, and the existing Tooltip portal pattern.

- [ ] **Step 2: Run the shared primitive test and typecheck**

Run:

```bash
pnpm --filter=@wcpos/settings test -- src/components/ui/__tests__/shared-form-controls.test.tsx
pnpm exec tsc -p packages/ui/tsconfig.json --noEmit
```

Expected: PASS.

## Task 3: Convert settings local components to shims

**Files:**
- Modify: `packages/settings/src/components/ui/toggle.tsx`
- Modify: `packages/settings/src/components/ui/checkbox.tsx`
- Modify: `packages/settings/src/components/ui/text-input.tsx`
- Modify: `packages/settings/src/components/ui/text-area.tsx`
- Modify: `packages/settings/src/components/ui/select.tsx`
- Modify: `packages/settings/src/components/ui/modal.tsx`
- Modify: `packages/settings/src/components/ui/tooltip.tsx`

- [ ] **Step 1: Re-export shared components and types from `@wcpos/ui`**

Each file becomes a local compatibility shim so existing settings imports do not move.

- [ ] **Step 2: Run settings tests/build/changed-file lint**

Run:

```bash
pnpm --filter=@wcpos/settings test
pnpm --filter=@wcpos/settings build
cd packages/settings && ESLINT_USE_FLAT_CONFIG=false ./node_modules/.bin/eslint src/components/ui/__tests__/*.test.tsx src/components/ui/*.tsx --ext .ts,.tsx
```

Expected: targeted changed-file lint may only include existing ESLintRCWarning; tests/build pass.

## Task 4: Convert Pro wrappers and expand preview

**Files:**
- Modify: `packages/store-edit/src/components/ui/text-input.tsx`
- Modify: `packages/store-edit/src/components/ui/text-area.tsx`
- Modify: `packages/store-edit/src/components/ui/select.tsx`
- Modify: `packages/store-edit/src/components/button-variants-preview.tsx`
- Modify: `packages/store-edit/src/components/__tests__/button-variants-preview.test.tsx`

- [ ] **Step 1: Re-export TextInput/TextArea from shared UI and adapt Select**

Keep Pro Select's `(value: string) => void` API with a local wrapper around shared `Select`.

- [ ] **Step 2: Expand the preview to include Toggle, Checkbox, TextInput, TextArea, Select, Tooltip, and Modal**

Use direct shared components for preview-only primitives and local wrappers for Pro-used fields.

- [ ] **Step 3: Run Pro tests/build and verify CSS utilities**

Run:

```bash
pnpm --filter=@wcpos/store-edit test
pnpm --filter=@wcpos/store-edit build
```

Expected: PASS and generated `assets/css/store-edit.css` includes utility classes used by the shared primitives.
