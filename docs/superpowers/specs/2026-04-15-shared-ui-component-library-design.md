# Shared UI Component Library Design

Date: 2026-04-15
Status: Draft for review

## Summary

Create a shared React UI package in the free WooCommerce POS plugin at `packages/ui`, published internally as `@wcpos/ui`. The package will hold reusable UI primitives that are currently duplicated across `packages/settings`, `packages/template-editor`, `packages/template-gallery`, and `woocommerce-pos-pro` packages.

The first component to extract is `Button`, because the settings Button currently lacks an enabled `wcpos:cursor-pointer` style and Pro's store-edit package has a similar duplicated Button. The shared Button becomes the single source of truth for default button behavior, variants, loading/disabled state, accessibility defaults, and Tailwind classes.

## Goals

- Fix the enabled Button hover cursor by adding `wcpos:cursor-pointer` once in the shared Button.
- Reduce duplicated UI primitives across free and Pro packages.
- Keep the existing Tailwind v4 `wcpos:` prefix model.
- Keep CSS bundled by each consuming app, matching the current Vite/IIFE WordPress asset architecture.
- Allow incremental migration package by package.

## Non-goals

- Do not create a separately distributed npm package in this phase.
- Defer introducing a full design system, Storybook, or visual documentation site in this phase.
- Replace raw buttons incrementally rather than across all packages in the first implementation step.
- Postpone shipping a standalone `@wcpos/ui` CSS bundle initially.

## Current context

The free plugin already has workspace packages such as `@wcpos/i18n` and `@wcpos/thermal-utils`. `template-editor` and `template-gallery` already consume shared free-plugin packages, so a new shared workspace package fits the existing monorepo pattern.

Current UI duplication includes:

- `packages/settings/src/components/ui/button.tsx`
- `packages/store-edit/src/components/ui/button.tsx` (Pro package)
- Repeated raw `<button>` class lists in `packages/template-editor` and `packages/template-gallery`

The relevant packages use Tailwind v4 with the `wcpos` prefix. Some entrypoints also use the Tailwind `important` import option to compete with WordPress admin CSS.

## Architecture

Add a new workspace package:

```txt
packages/ui/
  package.json
  tsconfig.json
  src/
    button.tsx
    index.ts
```

Initial package identity:

```json
{
  "name": "@wcpos/ui",
  "private": true,
  "main": "src/index.ts",
  "types": "src/index.ts",
  "peerDependencies": {
    "react": "^18.0.0"
  },
  "dependencies": {
    "classnames": "^2.5.1"
  }
}
```

The package should be source-consumed by Vite, like `@wcpos/thermal-utils`, rather than prebuilt in phase 1.

## Button component design

The shared `Button` component will use static, fully spelled-out Tailwind classes so Tailwind can detect them during source scanning.

API:

```ts
type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'destructive'
  | /** `@deprecated` Use 'destructive' instead. */ 'danger';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  loading?: boolean;
}
```

Behavior:

- Defaults to `variant="secondary"` to preserve the current settings Button behavior.
- Defaults to `type="button"` to avoid accidental form submission.
- Sets `disabled={disabled || loading}`.
- Adds `wcpos:cursor-pointer` for enabled buttons.
- Adds `wcpos:opacity-50 wcpos:cursor-not-allowed` for disabled or loading buttons.
- Renders a spinner when `loading` is true.
- Preserves consumer `className` append support for incremental adoption.

Variant mapping:

- `primary`: WordPress admin theme background, white text, darker hover state.
- `secondary`: white background, gray border/text, subtle hover state.
- `destructive`: red background, white text, darker red hover state.

Pro currently uses a `danger` variant in store-edit. The shared Button will temporarily accept `danger` as a deprecated alias for `destructive` to reduce migration risk. The preferred long-term naming is `destructive`, and Pro call sites should be migrated to that name.

## Tailwind integration

Each consuming app remains responsible for producing its own CSS bundle.

Because Tailwind ignores dependencies such as `node_modules` by default, and because Pro may consume the free plugin package from `vendor/wcpos/woocommerce-pos`, consuming stylesheets should explicitly register the shared UI source when needed.

Free package example:

```css
@import "tailwindcss" prefix(wcpos) important;
@source "../../ui/src";
```

The exact relative path may differ per package stylesheet location. For example, from `packages/settings/src/index.css`, the UI source path is expected to be `../../ui/src`.

Pro package example:

```css
@import "tailwindcss" prefix(wcpos) important;
@source "../../../vendor/wcpos/woocommerce-pos/packages/ui/src";
```

The Pro path is relative to `packages/store-edit/src/index.css` and must be verified against both local-dev and Composer-installed layouts during implementation. Pro already has Vite alias logic for free-plugin packages, so `@wcpos/ui` should follow the same pattern.

## Free plugin migration plan

Phase 1 implementation should be intentionally small:

1. Create `packages/ui` with `Button` and Button tests.
2. Update `packages/settings` to depend on `@wcpos/ui`.
3. Keep `packages/settings/src/components/ui/button.tsx` as a temporary re-export shim from `@wcpos/ui` to minimize import churn.
4. Ensure settings Tailwind scans `packages/ui/src`.
5. Verify `@wcpos/settings` tests, lint, and build.

Later free-plugin migrations:

- Replace repeated buttons in `packages/template-editor` where the shared Button fits.
- Replace repeated buttons in `packages/template-gallery` where the shared Button fits.
- Move additional primitives after Button proves the package boundary:
  - `TextInput`
  - `TextArea`
  - `Select`
  - `Checkbox`
  - `Toggle`
  - `Modal`
  - `Tooltip`

## Pro migration plan

After the free package exists:

1. Add a Pro Vite alias for `@wcpos/ui` using the existing `resolveVendorPackage` pattern.
2. Update Pro `store-edit` to import `Button` from `@wcpos/ui`.
3. Support Pro migration by temporarily accepting `danger` as a deprecated alias for `destructive`, then update Pro call sites to `destructive` in the same or a follow-up change.
4. Add the appropriate Tailwind `@source` path for the shared UI source.
5. Verify Pro store-edit build and tests.

Pro should not duplicate the shared UI package. The source of truth remains the free plugin's `packages/ui` package.

## Error handling and edge cases

- If Tailwind does not scan `packages/ui/src`, the Button markup will render but its generated CSS may be missing. Build verification must inspect behavior through tests/build output rather than assuming imports are sufficient.
- WordPress admin CSS can override generic button styles. Packages that are embedded in wp-admin should continue using Tailwind's `important` import option where they already do.
- Some raw buttons are icon-only, link-like, tab-like, or table-action buttons. They should not be forced into the shared Button if the semantic or visual fit is poor.
- Shared components should not dynamically construct Tailwind class names from props. Variants must map to complete static class strings.

## Testing and verification

For the initial Button extraction:

- Add or update Button tests to verify:
  - children render
  - default `type="button"`
  - click handler fires when enabled
  - disabled/loading prevents clicks
  - enabled state includes `wcpos:cursor-pointer`
  - disabled/loading state includes `wcpos:cursor-not-allowed`
  - variants apply expected static classes
- Run `pnpm --filter=@wcpos/settings test`.
- Run targeted lint for changed settings files: `pnpm --filter=@wcpos/settings lint -- src/components/ui/button.tsx src/components/ui/__tests__/button.test.tsx`.
  - Note: Full-package lint may fail due to pre-existing issues unrelated to this change; use the targeted scope above for verification.
- Run `pnpm --filter=@wcpos/settings build`.

For follow-up migrations, run the relevant package's test, lint, and build commands.

## Implementation recommendations

- Temporarily support Pro's `danger` variant as a deprecated alias for `destructive` to reduce migration risk.
- Keep `packages/settings/src/components/ui/button.tsx` as a re-export shim during the first implementation so existing settings imports continue to work.
- Include only Button in the first implementation to validate package wiring with the smallest useful change. Move adjacent form primitives only after Button is working in at least one free package.
