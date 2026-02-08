# Extension Directory Design

**Issue:** https://github.com/wcpos/roadmap/issues/23
**Date:** 2026-02-07

## Overview

A browsable directory of extensions for WooCommerce POS, served as an "Extensions" tab in POS Settings. Users can discover and browse extensions in the free plugin; installing requires Pro. Extensions are standalone WordPress plugins hosted in their own GitHub repos.

There are 3 extensions already built as standalone WP plugins in individual GitHub repos. Users currently install them manually from GitHub Releases. This design replaces that with an in-app directory and one-click install.

## Architecture

Two layers:

1. **Free plugin (read-only directory):** Fetches a remote catalog, enriches it with local plugin status, renders a card grid. Exposes a component slot for action buttons.
2. **Pro plugin (lifecycle management):** Registers a single REST endpoint for install/activate/deactivate/update and injects its action button component via the settings registry.

---

## Catalog Infrastructure

### `wcpos/extensions` repo

Contains a single `catalog.json` served via raw GitHub URL:
`https://raw.githubusercontent.com/wcpos/extensions/main/catalog.json`

```json
[
  {
    "slug": "stripe-terminal-for-woocommerce",
    "name": "Stripe Terminal for WooCommerce",
    "description": "Adds Stripe Terminal support to WooCommerce for in-person payments.",
    "version": "0.0.13",
    "author": "kilbot",
    "category": "payments",
    "tags": ["stripe", "terminal"],
    "requires_wp": "5.2",
    "requires_wc": "",
    "requires_wcpos": "",
    "requires_pro": true,
    "icon": "",
    "homepage": "https://github.com/wcpos/stripe-terminal-for-woocommerce",
    "download_url": "https://github.com/wcpos/stripe-terminal-for-woocommerce/releases/download/v0.0.13/stripe-terminal-for-woocommerce.zip",
    "latest_version": "0.0.13",
    "released_at": "2025-10-07T18:56:42Z"
  }
]
```

Adding a new extension = add an entry to this JSON file.

---

## Free Plugin (woocommerce-pos)

### Already built (PR #497, merged)

- `GET /wcpos/v1/extensions` endpoint — fetches catalog, caches in transient (12h TTL), enriches with local plugin status
- Extensions page with search, category filter, card grid
- `Extensions` service singleton with `get_catalog()`, `get_extensions()`, `clear_cache()`
- Extension card showing name, description, version, status badge

### Still needed for Pro integration

#### 1. Component registry on settings API

Add `registerComponent(key, Component)` and `getComponent(key)` to `window.wcpos.settings`. Simple key-value store.

```ts
// settings-registry.ts
const components: Record<string, React.ComponentType<any>> = {};

registerComponent(key: string, component: React.ComponentType<any>) {
  components[key] = component;
}

getComponent(key: string): React.ComponentType<any> | undefined {
  return components[key];
}
```

#### 2. Action slot in ExtensionCard

Check for a registered `extensions.action` component. If present, render it. Otherwise fall back to the current static status badge.

```tsx
const ActionSlot = window.wcpos?.settings?.getComponent('extensions.action');

{ActionSlot ? (
  <ActionSlot extension={ext} />
) : (
  <DefaultStatusBadge status={ext.status} />
)}
```

#### 3. Cache invalidation on plugin state change

Clear the extensions catalog transient when any plugin is activated or deactivated.

```php
add_action('activated_plugin', [Extensions::instance(), 'clear_cache']);
add_action('deactivated_plugin', [Extensions::instance(), 'clear_cache']);
```

---

## Pro Plugin (woocommerce-pos-pro)

### 1. REST Controller: `API\Extensions_Action`

Single endpoint handling all four operations.

**Route:** `POST /wcpos/v1/extensions/action`

**Permission:** `install_plugins` capability

**Request body:**
```json
{
  "slug": "stripe-terminal-for-woocommerce",
  "action": "install" | "activate" | "deactivate" | "update"
}
```

**Action behavior:**

| Action | Implementation | Notes |
|--------|---------------|-------|
| `install` | Download from `download_url` via `Plugin_Upgrader`, then `activate_plugin()` | Auto-activates after install |
| `activate` | `activate_plugin($plugin_file)` | Fast, sub-second |
| `deactivate` | `deactivate_plugins($plugin_file)` | Fast, sub-second |
| `update` | Download latest via `Plugin_Upgrader`, reactivate if was active | Preserves active state |

**Success response:**
```json
{
  "success": true,
  "slug": "stripe-terminal-for-woocommerce",
  "status": "active",
  "installed_version": "0.0.13"
}
```

**Error response:**
```json
{
  "code": "install_failed",
  "message": "Download failed: connection timeout",
  "data": { "status": 500 }
}
```

The controller looks up the extension in the catalog to get `download_url` (for install/update) and `plugin_file` (for activate/deactivate).

**Registration:** Via `woocommerce_pos_rest_api_controllers` filter.

### 2. Frontend: `ExtensionAction` Component

Pro registers its component on load:

```ts
window.wcpos.settings.registerComponent('extensions.action', ExtensionAction);
```

The component receives the full `extension` object and renders:

| Status | Button | Action |
|--------|--------|--------|
| `not_installed` | "Install" (primary) | POST action=install |
| `inactive` | "Activate" (primary) | POST action=activate |
| `active` | "Deactivate" (secondary) | POST action=deactivate |
| `update_available` | "Update" (primary) + "Deactivate" | POST action=update |

**Mutation pattern:**
- Uses React Query `useMutation` with `@wordpress/api-fetch`
- On success: invalidates `['extensions']` query key to refresh card grid
- During request: spinner + disabled button
- On error: toast notification, button returns to previous state

### 3. Asset Enqueue

Pro's settings JS loads after the free plugin's settings JS. The `registerComponent` call runs on script load, before React renders.

---

## UX Flows

### Install (slowest — 10-30s)
1. Click "Install" -> spinner + "Installing..."
2. Backend downloads zip, extracts, activates
3. Success -> card shows "Active" badge + "Deactivate" button
4. Error -> toast with message, button returns to "Install"

### Activate / Deactivate (fast — sub-second)
1. Click -> spinner + label
2. Success -> badge flips
3. Error -> toast + revert button state

### Update
1. Click "Update" -> spinner + "Updating..."
2. Backend downloads new zip, extracts, reactivates if was active
3. Success -> version updates, status returns to "Active"
4. Error -> toast, plugin remains at old version

### Error Categories

| Error | Message | Recovery |
|-------|---------|----------|
| Download failed | "Could not download extension. Check your server's internet connection." | Retry |
| Permission denied | "You don't have permission to manage plugins." | None |
| Disk/write error | "Installation failed: [WP error]" | Admin fixes server |
| Activation fatal | "Installed but could not be activated: [error]" | Shows "Inactive" |

---

## Not Building (YAGNI)

- Uninstall/delete — too destructive for v1, use WP plugins screen
- Bulk actions — only three extensions
- Auto-update toggle — WordPress handles natively
- Extension settings pages — each extension manages its own
- Progress bar for install — `Plugin_Upgrader` doesn't stream progress over REST
- Community/third-party submissions — curate catalog manually
- Star ratings or reviews
- Banner images on cards

---

## Implementation Scope

| Where | What | Size |
|-------|------|------|
| Free: `settings-registry.ts` | `registerComponent` / `getComponent` | ~10 lines |
| Free: `extension-card.tsx` | Action slot with fallback | ~5 lines changed |
| Free: `Extensions.php` service | Cache clear on plugin activate/deactivate hooks | ~5 lines |
| Pro: `API/Extensions_Action.php` | REST controller with install/activate/deactivate/update | ~150 lines |
| Pro: `ExtensionAction.tsx` | Button component with mutations | ~80 lines |
| Pro: settings JS entry | `registerComponent` call | ~3 lines |
