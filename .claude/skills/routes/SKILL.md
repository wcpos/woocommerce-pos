---
name: routes
description: Use when adding new routes, modifying navigation, or understanding the WCPOS app's screen structure.
---

# Expo Router Screen Documentation

The app uses [Expo Router](https://docs.expo.dev/router/introduction/) for file-based navigation. Routes are defined by the file structure in `apps/main/app/`.

## Route Groups
- `(groupName)` - Route groups organize routes without affecting the URL path
- `[param]` - Dynamic route parameters
- `[...param]` - Catch-all route parameters

## Root Layout (`_layout.tsx`)

Provides: SafeAreaProvider, GestureHandlerRootView, KeyboardProvider, HydrationProviders, Toaster

### Protected Routes
```text
Stack.Protected guard={!!storeDB}
├── (app)     # Main app - requires authentication
└── (auth)    # Auth screens - shown when not authenticated
```

## Auth Routes (`(auth)/`)
**Initial Route:** `connect`

| Route | File | Description |
|-------|------|-------------|
| `/connect` | `connect.tsx` | Store connection/login screen |

Redirects to `/(app)` when `storeDB` exists.

## Main App Routes (`(app)/`)
**Initial Route:** `(drawer)`

### App-Level Modals

| Route | File | Description |
|-------|------|-------------|
| `/settings` | `(modals)/settings.tsx` | App settings modal |
| `/tax-rates` | `(modals)/tax-rates.tsx` | Tax rate configuration modal |

All modals use `containedTransparentModal` presentation with fade animation.

## Drawer Navigation (`(app)/(drawer)/`)
**Initial Route:** `(pos)`. Permanent on large screens, overlay on smaller screens.

| Route | Icon | Pro Required | Description |
|-------|------|--------------|-------------|
| `/(pos)` | `cashRegister` | No | Point of Sale |
| `/products` | `gifts` | Yes | Product management |
| `/orders` | `receipt` | Yes | Order history |
| `/customers` | `users` | Yes | Customer management |
| `/reports` | `chartMixedUpCircleDollar` | Yes | Sales reports |
| `/logs` | `heartPulse` | No | System logs |
| `/support` | `commentQuestion` | No | Support/help |

## POS Routes (`(app)/(drawer)/(pos)/`)

Responsive layouts: small screens use tabs, larger screens use columns with resizable panels.

### Tab Layout (`(tabs)/`) - Mobile

| Route | Tab Icon | Description |
|-------|----------|-------------|
| `/` | `gifts` | Products tab |
| `/cart` | `cartShopping` | Shopping cart tab |
| `/cart/[orderId]` | - | Specific order cart |

### Column Layout (`(columns)/`) - Desktop
Renders `POSProducts` and `OpenOrders` side-by-side in resizable panels.

| Route | Description |
|-------|-------------|
| `/` | Products + Cart columns |
| `/cart` | New order cart |
| `/cart/[orderId]` | Specific order cart |

### POS Modals

| Route | Description |
|-------|-------------|
| `/cart/[orderId]/checkout` | Payment/checkout |
| `/cart/receipt/[orderId]` | Order receipt |
| `/cart/add-misc-product` | Add custom product |

## Other Sections

**Products** (`/products`): Product list + edit modals for products and variations. Pro required.
**Orders** (`/orders`): Order list + edit/receipt modals. Pro required.
**Customers** (`/customers`): Customer list + add/edit modals. Pro required.
**Reports** (`/reports`): Sales reports. Pro required.
**Logs** (`/logs`): System/debug logs.
**Support** (`/support`): Help and support.

## Context Providers by Route

| Route | Providers |
|-------|-----------|
| Root | SafeAreaProvider, GestureHandlerRootView, KeyboardProvider, HydrationProviders, Toaster |
| App | OnlineStatusProvider, ExtraDataProvider, QueryProvider, UISettingsProvider, PortalHost |
| POS | CurrentOrderProvider, TaxRatesProvider, PortalHost("pos") |

## Navigation Patterns

- All modals: `containedTransparentModal` with fade animation
- Pro access: `withProAccess` HOC wraps Products, Orders, Customers, Reports
- Responsive: Large screens get permanent drawer + columns. Small screens get overlay drawer + tabs.

## Special Files

| File | Purpose |
|------|---------|
| `+html.tsx` | Custom HTML document for web builds |
| `+not-found.tsx` | 404 error page |
