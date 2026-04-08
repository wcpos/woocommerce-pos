<!-- DEPRECATED: This file is no longer maintained. See .wiki/ for current content. -->
# React Version in WordPress Plugins

## The Rule

**Always pin React to the same major version that WordPress ships.** As of WordPress 6.9, that's React 18.

```json
"react": "18.3.1",
"react-dom": "18.3.1"
```

## Why This Matters

WordPress bundles React 18 as `window.React`. Our Vite builds externalize React to this global — the built JS doesn't include React, it uses WordPress's copy at runtime.

However, dependencies like `@headlessui/react`, `@tanstack/react-router`, and `react-error-boundary` are **not** externalized. They get bundled into our JS. When `pnpm install` puts React 19 in `node_modules`, these dependencies compile their JSX against React 19's `jsx-runtime`. At runtime, React 18 (from WordPress) can't render these elements:

> Objects are not valid as a React child (found: object with keys {$$typeof, type, key, ref, props})

This error is silent at build time — everything compiles fine. It only crashes at runtime.

## What To Do

1. Pin `react` and `react-dom` to match WordPress's version (currently 18.3.1)
2. Pin `@types/react` and `@types/react-dom` to match (^18.x)
3. Since `pnpm-lock.yaml` is gitignored, CI resolves fresh each run — pinning is the only way to guarantee the right version

## When WordPress Upgrades React

When WordPress ships React 19, update the pins across all packages:
- `packages/settings/package.json`
- `packages/template-editor/package.json`
- Any other package that uses React

## Applies To

Any package in the monorepo that:
1. Externalizes React to `globalThis.React` (uses WordPress's bundled copy)
2. Bundles third-party React components (headless UI, tanstack, etc.)
3. Builds as an IIFE loaded in the WordPress admin
