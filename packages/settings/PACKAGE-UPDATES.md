# Package Updates Analysis

## ✅ Fixed

### 1. **node-sass → sass** (CRITICAL)
- ❌ `node-sass@9.0.0` - **DEPRECATED**
- ✅ `sass@^1.84.0` - Modern Dart Sass implementation

### 2. **@tanstack/react-query-devtools**
- ✅ Updated to `^5.90.7` to match `@tanstack/react-query`

## 🚨 Deprecated Packages (Action Recommended)

### 1. **react-beautiful-dnd** (Current: 13.1.1)
**Status:** DEPRECATED - No longer maintained

**Recommended Alternatives:**
- **@dnd-kit/core** (Modern, TypeScript-first, actively maintained)
- **@hello-pangea/dnd** (Community fork of react-beautiful-dnd)
- **react-dnd** (More low-level, flexible)

**Impact:** Used for drag-and-drop in payment gateway ordering
**Action:** Consider migrating to @dnd-kit or @hello-pangea/dnd

### 2. **@types/wordpress__components** (Current: 23.8.0)
**Status:** DEPRECATED

**Note:** Types might be included in `@wordpress/components` now
**Action:** Check if types are built-in with latest `@wordpress/components`

## 📦 Recommended Updates

### Minor/Patch Updates (Safe to Update)

| Package | Current | Latest | Priority |
|---------|---------|--------|----------|
| `@headlessui/react` | 2.2.0 | 2.2.9 | HIGH |
| `@babel/core` | 7.24.7 | 7.28.5 | HIGH |
| `@babel/plugin-transform-runtime` | 7.24.7 | 7.28.5 | HIGH |
| `@babel/preset-env` | 7.24.7 | 7.28.5 | HIGH |
| `@babel/preset-react` | 7.24.7 | 7.28.5 | HIGH |
| `@babel/preset-typescript` | 7.24.7 | 7.28.5 | HIGH |
| `@babel/runtime` | 7.24.7 | 7.28.4 | HIGH |
| `typescript` | 5.4.5 | 5.9.3 | HIGH |
| `webpack` | 5.92.0 | 5.102.1 | HIGH |
| `postcss` | 8.4.38 | 8.5.6 | MEDIUM |
| `autoprefixer` | 10.4.19 | 10.4.22 | MEDIUM |
| `prettier` | 3.3.2 | 3.6.2 | MEDIUM |
| `husky` | 9.0.11 | 9.1.7 | LOW |
| `terser-webpack-plugin` | 5.3.10 | 5.3.14 | LOW |
| `mini-css-extract-plugin` | 2.9.0 | 2.9.4 | LOW |
| `ts-loader` | 9.5.2 | 9.5.4 | LOW |
| `postcss-loader` | 8.1.1 | 8.2.0 | LOW |
| `html-webpack-plugin` | 5.6.0 | 5.6.4 | LOW |
| `fork-ts-checker-webpack-plugin` | 9.0.2 | 9.1.0 | LOW |
| `jsdoc` | 4.0.3 | 4.0.5 | LOW |
| `postcss-cli` | 11.0.0 | 11.0.1 | LOW |
| `@types/lodash` | 4.17.5 | 4.17.20 | LOW |
| `@transifex/native` | 7.1.3 | 7.1.4 | LOW |
| `@transifex/react` | 7.1.3 | 7.1.4 | LOW |
| `@transifex/cli` | 7.1.3 | 7.1.4 | LOW |

### Major Updates (⚠️ Test Thoroughly)

| Package | Current | Latest | Breaking Changes? |
|---------|---------|--------|-------------------|
| `react` | 18.3.1 | 19.2.0 | ⚠️ YES - React 19 has breaking changes |
| `react-dom` | 18.3.1 | 19.2.0 | ⚠️ YES |
| `@types/react` | 18.3.3 | 19.2.3 | ⚠️ YES |
| `@types/react-dom` | 18.3.0 | 19.2.2 | ⚠️ YES |
| `@wordpress/components` | 28.13.0 | 30.7.0 | ⚠️ Possibly |
| `@wordpress/api-fetch` | 7.0.0 | 7.34.0 | ⚠️ Possibly |
| `@wordpress/element` | 6.0.0 | 6.34.0 | ⚠️ Possibly |
| `@wordpress/url` | 4.0.0 | 4.34.0 | ⚠️ Possibly |
| `@wordpress/env` | 10.23.0 | 10.34.0 | ⚠️ Possibly |
| `react-error-boundary` | 4.0.13 | 6.0.0 | ⚠️ YES |
| `@typescript-eslint/eslint-plugin` | 7.13.0 | 8.46.4 | ⚠️ YES |
| `@typescript-eslint/parser` | 7.13.0 | 8.46.4 | ⚠️ YES |
| `babel-loader` | 9.1.3 | 10.0.0 | ⚠️ Possibly |
| `webpack-cli` | 5.1.4 | 6.0.1 | ⚠️ Possibly |
| `sass-loader` | 14.2.1 | 16.0.6 | ⚠️ Possibly |
| `jest` | 29.7.0 | 30.2.0 | ⚠️ Possibly |
| `@types/jest` | 29.5.12 | 30.0.0 | ⚠️ YES |

## 💡 Recommended Update Strategy

### Phase 1: Safe Updates (Do Now)
```bash
pnpm update @headlessui/react @babel/core @babel/plugin-transform-runtime @babel/preset-env @babel/preset-react @babel/preset-typescript @babel/runtime typescript webpack autoprefixer postcss prettier
```

### Phase 2: WordPress Packages (Test Thoroughly)
The WordPress packages have significant version jumps. Check release notes:
- https://github.com/WordPress/gutenberg/releases

### Phase 3: React 19 Migration (Plan Carefully)
**DO NOT UPDATE YET** without reviewing:
- https://react.dev/blog/2024/12/05/react-19
- Breaking changes in React 19
- Compatibility with @wordpress/components

### Phase 4: Major Dev Tools (When Ready)
- TypeScript ESLint v8
- Jest v30
- Webpack CLI v6
- Babel Loader v10
- Sass Loader v16

## 🔧 Migration Tasks

### 1. Replace react-beautiful-dnd
**Priority:** MEDIUM  
**Complexity:** HIGH  
**Files affected:**
- `src/screens/checkout/gateways.tsx`

**Options:**
```bash
# Option 1: Modern alternative
pnpm add @dnd-kit/core @dnd-kit/sortable

# Option 2: Community fork (drop-in replacement)
pnpm add @hello-pangea/dnd
pnpm remove react-beautiful-dnd
```

### 2. Remove @types/wordpress__components
Check if types are now included in `@wordpress/components@30.x`

## 📝 Notes

1. **Tailwind CSS v4** and **React Query v5.90.7** are already updated ✅
2. **node-sass** has been replaced with **sass** ✅
3. WordPress packages have very old versions - major updates might require code changes
4. React 19 is a significant update - requires migration planning
5. Consider running `pnpm audit` to check for security vulnerabilities

## 🚀 Quick Update Command

For safe, non-breaking updates:
```bash
cd /Users/kilbot/Projects/woocommerce-pos/packages/settings
pnpm update --latest @headlessui/react @babel/core @babel/plugin-transform-runtime @babel/preset-env @babel/preset-react @babel/preset-typescript @babel/runtime typescript webpack
```

