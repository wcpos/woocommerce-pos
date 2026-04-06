# Web Bundle Architecture

The POS frontend is a React Native (Expo) app bundled by Metro for web. It is **not** served via Expo's generated `index.html`. Instead, it's rendered by the WordPress plugin's `Frontend.php`.

## Build Pipeline

```text
apps/web/scripts/build.js
  -> runs `npx expo export` from apps/main (Metro bundler)
  -> post-processes JS: replaces baseUrl placeholders with window.cdnBaseUrl / window.baseUrl
  -> prependRuntimeChunks(): merges __expo-metro-runtime and __common into entry bundle
  -> generates metadata.json listing bundles and CSS
  -> output: apps/web/build/
```

## How WordPress Loads the Bundle

`Frontend.php` (`includes/Templates/Frontend.php`) renders the POS page:

1. Sets inline JS variables: `initialProps`, `cdnBaseUrl`, `baseUrl` (declared as `var` in global scope)
2. Fetches `metadata.json` from CDN
3. Reads `fileMetadata.web.bundles` array (v1) or falls back to single `bundle` string (v0)
4. Chain-loads all bundles in order via `getScript()` (sequential, not parallel)
5. Loads CSS via `loadCSS()` before bundles

## CDN URLs

- **Production**: `https://cdn.jsdelivr.net/gh/wcpos/web-bundle@1.8/build/`
- **Development**: `http://localhost:4567/build/` (when `WCPOS_DEVELOPMENT` is true)

## metadata.json Format

```json
{
  "version": 1,
  "bundler": "metro",
  "fileMetadata": {
    "web": {
      "bundles": [
        "_expo/static/js/web/entry-<hash>.js"
      ],
      "css": "_expo/static/css/global-<hash>.css"
    }
  }
}
```

The `bundles` array may contain 1 entry (runtime pre-merged) or 3 entries (runtime, common, entry) depending on the build configuration. The PHP loader handles both cases.

## Key Repositories

- **wcpos/monorepo** (`apps/main/`): Expo app source, Metro config, app.config.ts
- **wcpos/web-bundle** (`apps/web/`): Build script, metadata generation, deployed to CDN (git submodule)
- **wcpos/woocommerce-pos**: WordPress plugin with `Frontend.php` that loads the bundle

## Bundle Optimization

Metro produces async chunks via `import()`. Current async chunks include:
- **Locale chunks** (date-fns): ~1KB each, loaded per user's language
- **Chart**: victory-native, loaded when reports screen is visited
- **Migration chunks**: rxdb-old, loaded only during storage migration
- **Network adapter**: loaded when network printer is configured
