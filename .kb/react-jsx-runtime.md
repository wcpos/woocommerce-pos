# React JSX Runtime in WordPress Plugins

## The Problem

WordPress bundles React 18 as `window.React`. Our Vite builds externalize React to use this global. However, `@vitejs/plugin-react` defaults to `jsxRuntime: 'automatic'`, which compiles JSX using `react/jsx-runtime` from whatever React version is in `node_modules`.

If `node_modules` has React 19 (or any version different from WordPress's bundled one), the build output uses React 19's `jsx()` factory, which produces elements with a different internal `$$typeof` symbol. At runtime, WordPress's React 18 renderer doesn't recognize these elements and throws:

> Objects are not valid as a React child (found: object with keys {$$typeof, type, key, ref, props})

## The Fix

Use the **classic JSX runtime** in all Vite configs for packages that externalize React:

```ts
react({
  jsxRuntime: 'classic',
})
```

This compiles JSX to `React.createElement()` calls, which go through the externalized `globalThis.React` at runtime — always matching WordPress's bundled version.

## Why Not the Automatic Runtime?

The automatic runtime (`react/jsx-runtime`) is imported at build time from `node_modules`. Since our `pnpm-lock.yaml` is gitignored, CI resolves packages fresh each run. If `pnpm install` resolves a different React version than WordPress ships, the build silently produces incompatible JSX elements. There's no error at build time — it only crashes at runtime.

The classic runtime avoids this entirely because `React.createElement` is resolved at runtime through the externalized global, not at build time through `node_modules`.

## When This Applies

Any package in the monorepo that:
1. Uses `@vitejs/plugin-react`
2. Externalizes React to `globalThis.React` (i.e., uses WordPress's bundled React)
3. Builds as an IIFE loaded in the WordPress admin

Currently: `packages/settings`, `packages/template-editor`

## When WordPress Upgrades React

When WordPress ships React 19+, the classic runtime will still work — `React.createElement` exists in all React versions. If you want to switch to the automatic runtime later, you'd need to also externalize `react/jsx-runtime` and map it to WordPress's bundled JSX runtime module.
