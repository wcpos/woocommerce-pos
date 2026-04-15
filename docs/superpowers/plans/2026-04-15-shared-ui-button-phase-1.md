# Shared UI Button Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the first shared `@wcpos/ui` package with a reusable Button, then migrate settings to use it so enabled buttons show a pointer cursor.

**Architecture:** Add `packages/ui` as a source-consumed workspace package, matching existing packages like `@wcpos/thermal-utils`. Keep `packages/settings/src/components/ui/button.tsx` as a compatibility re-export so existing settings imports continue to work while the implementation moves to `@wcpos/ui`. Settings remains responsible for its CSS bundle and explicitly tells Tailwind to scan `packages/ui/src`.

**Tech Stack:** React 18, TypeScript, Vite, Vitest, Testing Library, Tailwind CSS v4 with `wcpos:` prefix, pnpm workspaces.

---

## Scope

This plan implements phase 1 only:

- Create `@wcpos/ui` in the free plugin repo.
- Extract the settings Button implementation into `@wcpos/ui`.
- Fix the cursor behavior in that shared Button.
- Migrate settings through a re-export shim.
- Verify settings tests, lint, and build.

This plan does not migrate `template-editor`, `template-gallery`, or `woocommerce-pos-pro`. Those are follow-up plans after the shared package is proven in settings.

## Preflight

- Work in a dedicated git worktree, not the main working tree.
- Start from current `main` after pulling latest.
- Do not include unrelated files such as `assets/js/opfs.worker.js` in these implementation commits unless the user explicitly requests that in the implementation worktree.
- `classnames@2.5.1` was verified with `npm view classnames version` on 2026-04-15 and is already used by settings.
- `react@19.2.5` is the npm latest as of 2026-04-15, but this repo's packages use React `18.3.1`; keep the shared package peer dependency at `^18.0.0` to match current consumers.

## File structure

- Create `packages/ui/package.json`
  - Declares `@wcpos/ui` as a private workspace package.
  - Source-consumed through `src/index.ts`.
  - Depends on `classnames` and peers on React 18.
- Create `packages/ui/tsconfig.json`
  - Mirrors the shared source-package style from `packages/i18n` and `packages/thermal-utils`.
- Create `packages/ui/src/button.tsx`
  - Owns Button props, variants, loading behavior, cursor classes, spinner, and rendering.
- Create `packages/ui/src/index.ts`
  - Public export surface for `Button` and its types.
- Modify `packages/settings/package.json`
  - Adds workspace dependency on `@wcpos/ui`.
- Modify `packages/settings/src/components/ui/button.tsx`
  - Re-exports Button from `@wcpos/ui` as a compatibility shim.
- Modify `packages/settings/src/components/ui/__tests__/button.test.tsx`
  - Adds cursor behavior tests and keeps existing behavior coverage.
- Modify `packages/settings/src/index.css`
  - Adds Tailwind `@source` for the shared UI package.
- Modify `pnpm-lock.yaml`
  - Updated by pnpm after adding the workspace package/dependency.
- Update workspace symlinks in `node_modules`
  - Created by `pnpm install` so `@wcpos/settings` can resolve `@wcpos/ui` during local tests and builds.

---

### Task 1: Add failing Button cursor coverage in settings

**Files:**
- Modify: `packages/settings/src/components/ui/__tests__/button.test.tsx`

- [ ] **Step 1: Replace the settings Button test file with cursor coverage**

Replace `packages/settings/src/components/ui/__tests__/button.test.tsx` with:

```tsx
import * as React from 'react';

import { render, screen, fireEvent } from '@testing-library/react';

import { Button } from '../button';

describe('Button', () => {
	it('renders children', () => {
		render(<Button>Click me</Button>);
		expect(screen.getByRole('button')).toHaveTextContent('Click me');
	});

	it('calls onClick handler', () => {
		const onClick = vi.fn();
		render(<Button onClick={onClick}>Click</Button>);
		fireEvent.click(screen.getByRole('button'));
		expect(onClick).toHaveBeenCalledOnce();
	});

	it('uses a pointer cursor when enabled', () => {
		render(<Button>Click</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:cursor-pointer');
		expect(button.className).not.toContain('wcpos:cursor-not-allowed');
	});

	it('disables when disabled prop is true', () => {
		render(<Button disabled>Click</Button>);
		const button = screen.getByRole('button');
		expect(button).toBeDisabled();
		expect(button.className).toContain('wcpos:cursor-not-allowed');
		expect(button.className).not.toContain('wcpos:cursor-pointer');
	});

	it('disables when loading prop is true', () => {
		render(<Button loading>Saving</Button>);
		const button = screen.getByRole('button');
		expect(button).toBeDisabled();
		expect(button.className).toContain('wcpos:cursor-not-allowed');
		expect(button.className).not.toContain('wcpos:cursor-pointer');
	});

	it('shows a spinner when loading', () => {
		const { container } = render(<Button loading>Saving</Button>);
		const svg = container.querySelector('svg');
		expect(svg).toBeInTheDocument();
	});

	it('does not show a spinner when not loading', () => {
		const { container } = render(<Button>Save</Button>);
		const svg = container.querySelector('svg');
		expect(svg).not.toBeInTheDocument();
	});

	it('does not call onClick when disabled', () => {
		const onClick = vi.fn();
		render(
			<Button disabled onClick={onClick}>
				Click
			</Button>
		);
		fireEvent.click(screen.getByRole('button'));
		expect(onClick).not.toHaveBeenCalled();
	});

	it('applies primary variant class', () => {
		render(<Button variant="primary">Primary</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:bg-wp-admin-theme-color');
	});

	it('applies destructive variant class', () => {
		render(<Button variant="destructive">Delete</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:bg-red-600');
	});

	it('keeps danger as a deprecated alias for destructive', () => {
		render(<Button variant="danger">Delete</Button>);
		const button = screen.getByRole('button');
		expect(button.className).toContain('wcpos:bg-red-600');
	});

	it('has type="button" by default', () => {
		render(<Button>Click</Button>);
		expect(screen.getByRole('button')).toHaveAttribute('type', 'button');
	});

	it('accepts custom type prop', () => {
		render(<Button type="submit">Submit</Button>);
		expect(screen.getByRole('button')).toHaveAttribute('type', 'submit');
	});
});
```

- [ ] **Step 2: Run the focused settings test and verify it fails**

Run:

```bash
pnpm --filter=@wcpos/settings test -- src/components/ui/__tests__/button.test.tsx
```

Expected: FAIL. The failure should include the enabled cursor assertion because the current Button does not contain `wcpos:cursor-pointer`. It may also include a TypeScript or runtime failure for the new `danger` variant because the current Button type only accepts `primary | secondary | destructive`.

---

### Task 2: Add `@wcpos/ui` and migrate settings through a re-export shim

**Files:**
- Create: `packages/ui/package.json`
- Create: `packages/ui/tsconfig.json`
- Create: `packages/ui/src/button.tsx`
- Create: `packages/ui/src/index.ts`
- Modify: `packages/settings/package.json`
- Modify: `packages/settings/src/components/ui/button.tsx`
- Modify: `pnpm-lock.yaml`
- Update: workspace symlinks in `node_modules`

- [ ] **Step 1: Create the shared UI package manifest**

Create `packages/ui/package.json`:

```json
{
	"name": "@wcpos/ui",
	"private": true,
	"main": "src/index.ts",
	"types": "src/index.ts",
	"dependencies": {
		"classnames": "^2.5.1"
	},
	"peerDependencies": {
		"react": "^18.0.0"
	}
}
```

- [ ] **Step 2: Create the shared UI TypeScript config**

Create `packages/ui/tsconfig.json`:

```json
{
	"compilerOptions": {
		"lib": ["dom", "dom.iterable", "esnext"],
		"target": "ES2020",
		"module": "ESNext",
		"moduleResolution": "node",
		"declaration": true,
		"strict": true,
		"esModuleInterop": true,
		"allowSyntheticDefaultImports": true,
		"skipLibCheck": true,
		"forceConsistentCasingInFileNames": true,
		"resolveJsonModule": true,
		"isolatedModules": true,
		"jsx": "react-jsx"
	},
	"include": ["src"]
}
```

- [ ] **Step 3: Create the shared Button implementation**

Create `packages/ui/src/button.tsx`:

```tsx
import * as React from 'react';

import classNames from 'classnames';

export type ButtonVariant = 'primary' | 'secondary' | 'destructive' | 'danger';

type CanonicalButtonVariant = Exclude<ButtonVariant, 'danger'>;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: ButtonVariant;
	loading?: boolean;
}

const variantClasses: Record<CanonicalButtonVariant, string> = {
	primary:
		'wcpos:bg-wp-admin-theme-color wcpos:text-white hover:wcpos:bg-wp-admin-theme-color-darker-10 focus:wcpos:ring-wp-admin-theme-color',
	secondary:
		'wcpos:bg-white wcpos:text-gray-700 wcpos:border wcpos:border-gray-300 hover:wcpos:bg-gray-50 focus:wcpos:ring-wp-admin-theme-color',
	destructive: 'wcpos:bg-red-600 wcpos:text-white hover:wcpos:bg-red-700 focus:wcpos:ring-red-500',
};

function normalizeVariant(variant: ButtonVariant): CanonicalButtonVariant {
	return variant === 'danger' ? 'destructive' : variant;
}

export function Button({
	variant = 'secondary',
	loading = false,
	disabled,
	className,
	children,
	type = 'button',
	...props
}: ButtonProps) {
	const isDisabled = disabled || loading;
	const normalizedVariant = normalizeVariant(variant);

	return (
		<button
			type={type}
			disabled={isDisabled}
			className={classNames(
				'wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:rounded-md wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:transition-colors wcpos:duration-150',
				'focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-offset-2',
				variantClasses[normalizedVariant],
				isDisabled ? 'wcpos:opacity-50 wcpos:cursor-not-allowed' : 'wcpos:cursor-pointer',
				className
			)}
			{...props}
		>
			{loading && (
				<svg
					className="wcpos:mr-2 wcpos:h-4 wcpos:w-4 wcpos:animate-spin"
					xmlns="http://www.w3.org/2000/svg"
					fill="none"
					viewBox="0 0 24 24"
				>
					<circle
						className="wcpos:opacity-25"
						cx="12"
						cy="12"
						r="10"
						stroke="currentColor"
						strokeWidth="4"
					/>
					<path
						className="wcpos:opacity-75"
						fill="currentColor"
						d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
					/>
				</svg>
			)}
			{children}
		</button>
	);
}
```

- [ ] **Step 4: Create the shared UI public export**

Create `packages/ui/src/index.ts`:

```ts
export { Button, type ButtonProps, type ButtonVariant } from './button';
```

- [ ] **Step 5: Add `@wcpos/ui` to settings dependencies**

Modify the `dependencies` block in `packages/settings/package.json` so it includes `@wcpos/ui` next to the other WCPOS workspace dependency:

```json
"dependencies": {
	"@atlaskit/pragmatic-drag-and-drop": "^1.7.7",
	"@atlaskit/pragmatic-drag-and-drop-hitbox": "^1.1.0",
	"@headlessui/react": "^2.2.9",
	"@tanstack/react-query": "^5.90.21",
	"@tanstack/react-router": "^1.159.5",
	"@wcpos/i18n": "workspace:*",
	"@wcpos/ui": "workspace:*",
	"@wordpress/api-fetch": "^7.39.0",
	"@wordpress/url": "^4.39.0",
	"classnames": "^2.5.1",
	"lodash": "^4.17.23",
	"react": "18.3.1",
	"react-dom": "18.3.1",
	"react-error-boundary": "^6.1.0",
	"zustand": "^5.0.11"
}
```

- [ ] **Step 6: Replace the settings Button implementation with a re-export shim**

Replace `packages/settings/src/components/ui/button.tsx` with:

```ts
export { Button, type ButtonProps, type ButtonVariant } from '@wcpos/ui';
```

- [ ] **Step 7: Update the lockfile**

Run:

```bash
pnpm install
```

Expected: `pnpm-lock.yaml` changes to include the new `packages/ui` importer and the settings dependency on `@wcpos/ui`. Workspace symlinks in `node_modules` are updated so `@wcpos/settings` can resolve `@wcpos/ui`. There should be no package version drift unrelated to the new workspace package.

- [ ] **Step 8: Run the focused settings test and verify it passes**

Run:

```bash
pnpm --filter=@wcpos/settings test -- src/components/ui/__tests__/button.test.tsx
```

Expected: PASS. The cursor tests should now pass because enabled buttons receive `wcpos:cursor-pointer`, and disabled/loading buttons receive `wcpos:cursor-not-allowed` instead.

- [ ] **Step 9: Commit the shared Button extraction**

Run:

```bash
git add packages/ui packages/settings/package.json packages/settings/src/components/ui/button.tsx packages/settings/src/components/ui/__tests__/button.test.tsx pnpm-lock.yaml
git commit -m "feat: add shared ui button package"
```

Expected: commit succeeds and includes only the shared UI package, settings Button shim, settings Button tests, settings package manifest, and lockfile.

---

### Task 3: Ensure Tailwind scans the shared UI package

**Files:**
- Modify: `packages/settings/src/index.css`

- [ ] **Step 1: Add the UI package as a Tailwind source**

At the top of `packages/settings/src/index.css`, keep the existing Tailwind import and add the `@source` directive immediately after it:

```css
@import "tailwindcss" prefix(wcpos) important;
@source "../../ui/src";
/* @config "../tailwind.config.js"; */

@theme {
	/* Custom colors for WordPress admin theme */
	--color-wp-admin-theme-color: var(--wp-admin-theme-color, #007cba);
	--color-wp-admin-theme-color-darker-10: var(--wp-admin-theme-color-darker-10, #006ba1);
	--color-wp-admin-theme-color-darker-20: var(--wp-admin-theme-color-darker-20, #005a87);
	--color-wp-admin-theme-color-lightest: #e5f1f8;
	--color-wp-admin-theme-black: #1d2327;
}
```

Do not change the rest of the file.

- [ ] **Step 2: Build settings to verify Tailwind can process the shared source**

Run:

```bash
pnpm --filter=@wcpos/settings build
```

Expected: PASS. Vite should build `@wcpos/settings` without Tailwind `@source` errors or unresolved `@wcpos/ui` imports.

- [ ] **Step 3: Verify the settings CSS contains the cursor utility**

Run:

```bash
rg --no-ignore "cursor:pointer|cursor: pointer|cursor-not-allowed|cursor-pointer" assets/css/settings.css
```

Expected: output includes generated cursor styles for the settings bundle. The exact output may be minified, but it must include cursor styling generated from `wcpos:cursor-pointer` and `wcpos:cursor-not-allowed`. Use `--no-ignore` because generated assets under `assets/css` are ignored by git.

- [ ] **Step 4: Commit the Tailwind source registration**

Run:

```bash
git add packages/settings/src/index.css
git commit -m "build: scan shared ui tailwind classes"
```

Expected: commit succeeds and includes only `packages/settings/src/index.css`.

---

### Task 4: Run full verification for the phase 1 change

**Files:**
- Verify only; no intended file changes.

- [ ] **Step 1: Run settings tests**

Run:

```bash
pnpm --filter=@wcpos/settings test
```

Expected: PASS.

- [ ] **Step 2: Run settings lint**

Run:

```bash
pnpm --filter=@wcpos/settings lint
```

Expected: PASS. Warnings are acceptable only if they are pre-existing and unrelated to the changed files.

- [ ] **Step 3: Run settings build**

Run:

```bash
pnpm --filter=@wcpos/settings build
```

Expected: PASS.

- [ ] **Step 4: Check changed files**

Run:

```bash
git status --short
```

Expected: no unstaged source changes from verification. Generated assets under `assets/css` or `assets/js` may appear as ignored files, but they should not be committed unless explicitly requested.

- [ ] **Step 5: Record verification in the final response or PR body**

Use this exact checklist format:

```markdown
Verification:
- `pnpm --filter=@wcpos/settings test` — PASS
- `pnpm --filter=@wcpos/settings lint` — PASS
- `pnpm --filter=@wcpos/settings build` — PASS
```
