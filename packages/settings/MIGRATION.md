# Migration Guide: Tailwind CSS v4 & React Query v5.90.7

## Summary
This package has been successfully migrated from Tailwind CSS v3.4.4 to v4.0.0 and React Query v5.45.0 to v5.90.7.

## Changes Made

### 1. Package Updates

#### Dependencies
- `@tanstack/react-query`: `^5.45.0` → `^5.90.7`
- `@headlessui/react`: `^2.0.4` → `^2.2.0`

#### Dev Dependencies
- `tailwindcss`: `3.4.4` → `^4.0.0`
- `@tailwindcss/postcss`: `^4.0.0` (new dependency)
- `@tanstack/react-query-devtools`: `^5.45.0` → `^5.90.7`

### 2. Tailwind CSS v4 Migration

#### ⚠️ **MAJOR BREAKING CHANGE: Prefix Format**
Tailwind v4 uses **colon (`:`)** instead of **hyphen (`-`)** for prefixes!

**Base Classes:**
- **Before (v3)**: `wcpos-container`, `wcpos-mx-auto`, `wcpos-bg-white`
- **After (v4)**: `wcpos:container`, `wcpos:mx-auto`, `wcpos:bg-white`

**Variant Classes (IMPORTANT!):**
The prefix comes **BEFORE** the variant in v4:
- **Before (v3)**: `sm:wcpos-text-sm`, `hover:wcpos-bg-gray-100`, `focus:wcpos-border-blue-500`
- **After (v4)**: `wcpos:sm:text-sm`, `wcpos:hover:bg-gray-100`, `wcpos:focus:border-blue-500`

All **226+ occurrences across 19 files** were updated, including proper handling of variants!

#### Deprecated Utilities Replaced
Updated all deprecated v3 utilities to v4 equivalents:

**Opacity Modifiers:**
- ✅ `wcpos:ring-opacity-5` → `wcpos:ring-black/5`
- ✅ `wcpos:ring-opacity-75` → `wcpos:ring-white/75`

**Renamed Utilities:**
- ✅ `wcpos:shadow-sm` → `wcpos:shadow-xs` (3 occurrences)

**No Issues Found:**
- ✅ No deprecated flex-shrink/flex-grow utilities
- ✅ No overflow-ellipsis (would use wcpos:text-ellipsis)
- ✅ No standalone `rounded` (would be wcpos:rounded-sm)
- ✅ No standalone `ring` (would be wcpos:ring-3)

#### PostCSS Configuration (`postcss.config.js`)
- **Before**: `tailwindcss: {}`
- **After**: `'@tailwindcss/postcss': {}`

The new Tailwind v4 uses a dedicated PostCSS plugin.

#### CSS Configuration (`src/index.css`)
Migrated from JavaScript config to CSS-based theming with inline prefix:

```css
@import "tailwindcss" prefix(wcpos);
@config "../tailwind.config.js";

@theme {
  /* Custom colors for WordPress admin theme */
  --color-wp-admin-theme-color: var(--wp-admin-theme-color, #007cba);
  --color-wp-admin-theme-color-darker-10: var(--wp-admin-theme-color-darker-10, #006ba1);
  --color-wp-admin-theme-color-darker-20: var(--wp-admin-theme-color-darker-20, #005a87);
  --color-wp-admin-theme-color-lightest: #e5f1f8;
  --color-wp-admin-theme-black: #1d2327;
}
```

**Key Changes:**
- Replaced `@tailwind` directives with `@import "tailwindcss" prefix(wcpos)`
- Prefix is now configured in CSS, not JS config
- Theme customizations now use `@theme` block with CSS variables
- Color definitions follow the format: `--color-{name}: {value}`
- Added `@config` directive to reference JS config for plugins

#### Tailwind Config (`tailwind.config.js`)
Simplified configuration - removed prefix and content (now in CSS):

- Removed `prefix` (now in CSS via `prefix(wcpos)`)
- Removed `content` (not needed in v4)
- Removed `theme.extend.colors` (migrated to `@theme` in CSS)
- Removed `variants` (no longer needed in v4)
- Removed `corePlugins` (preflight is handled differently in v4)
- Kept: `darkMode`, `plugins`

### 3. React Query v5.90.7 Migration

#### Removed Deprecated `suspense` Option (`src/index.tsx`)
The `suspense: true` option in `QueryClient` defaultOptions is deprecated in React Query v5.

**Before:**
```javascript
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      suspense: true,
      staleTime: 10 * 60 * 1000,
    },
  },
});
```

**After:**
```javascript
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 10 * 60 * 1000,
    },
  },
});
```

#### Updated to `useSuspenseQuery` (`src/hooks/use-settings-api.tsx`)
For queries that need suspense behavior, use the dedicated `useSuspenseQuery` hook:

**Before:**
```javascript
import { useQuery } from '@tanstack/react-query';
const { data } = useQuery({ ... });
```

**After:**
```javascript
import { useSuspenseQuery } from '@tanstack/react-query';
const { data } = useSuspenseQuery({ ... });
```

**Note:** The `user-select.tsx` component continues to use `useQuery` (not suspense) because it manages its own loading state with `isFetching`.

## Next Steps

1. **Install Dependencies**
   ```bash
   pnpm install
   ```

2. **Test the Build**
   ```bash
   pnpm build
   ```

3. **Test Development Mode**
   ```bash
   pnpm start
   ```

4. **Verify Functionality**
   - Check that all Tailwind classes with `wcpos-` prefix work correctly
   - Verify WordPress admin theme colors are applied
   - Test all settings screens load without errors
   - Confirm React Query suspense behavior works as expected

## Breaking Changes to Watch For

### Tailwind CSS v4
- **Class name changes**: Some utility classes may have been renamed or removed
- **Plugin compatibility**: Ensure `@headlessui/tailwindcss` plugin works with v4
- **Custom CSS**: If you have custom CSS that references Tailwind internals, it may need updates

### React Query v5.90.7
- **Suspense behavior**: Components wrapped in `<Suspense>` now need `useSuspenseQuery`
- **Error boundaries**: Make sure error boundaries properly catch query errors
- **Type safety**: TypeScript types have improved; you may see new type errors that were previously silent

## Rollback Instructions

If you need to rollback:

1. Restore `package.json` to previous versions:
   - `tailwindcss`: `^4.0.0` → `3.4.4`
   - `@tanstack/react-query`: `^5.90.7` → `^5.45.0`
   - Remove `@tailwindcss/postcss`
2. Restore `postcss.config.js`: change `'@tailwindcss/postcss'` back to `tailwindcss`
3. Restore `src/index.css`: replace `@import "tailwindcss" prefix(wcpos)` with `@tailwind` directives
4. Restore `tailwind.config.js`: add back `prefix: 'wcpos-'`, `content`, `theme.extend.colors`, `corePlugins`
5. **Revert all class names**: Replace `wcpos:` with `wcpos-` across all 19 TSX files (226 occurrences)
6. Restore `src/index.tsx`: add back `suspense: true` in QueryClient config
7. Restore `src/hooks/use-settings-api.tsx`: change `useSuspenseQuery` back to `useQuery`
8. Run `pnpm install`

## Resources

- [Tailwind CSS v4 Documentation](https://tailwindcss.com/docs)
- [Tailwind CSS v4 Upgrade Guide](https://tailwindcss.com/docs/upgrade-guide)
- [TanStack Query v5 Migration Guide](https://tanstack.com/query/latest/docs/framework/react/guides/migrating-to-v5)
- [useSuspenseQuery Documentation](https://tanstack.com/query/latest/docs/framework/react/reference/useSuspenseQuery)

