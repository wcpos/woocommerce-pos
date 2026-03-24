# WCPOS — Architecture

## Ecosystem Overview

WCPOS is a distributed system spanning multiple repos and services:

```text
┌─────────────────────────────────────────────────────────────────┐
│                        Client Apps                               │
│  monorepo-v2 (React Native + Expo + Electron)                   │
│  iOS / Android / Web / Desktop                                   │
└──────────┬──────────────────────────┬───────────────────────────┘
           │ WooCommerce REST API     │ WCPOS Services
           ↓                          ↓
┌─────────────────────┐   ┌──────────────────────────────────────┐
│  WordPress + WC     │   │  wcpos-infra (Hetzner/Coolify)       │
│  ┌───────────────┐  │   │  ┌─────────────────────────────────┐ │
│  │woocommerce-pos│  │   │  │ updates.wcpos.com — app updates │ │
│  │  (free)       │  │   │  │ license.wcpos.com — Keygen CE   │ │
│  ├───────────────┤  │   │  │ store-api.wcpos.com — MedusaJS  │ │
│  │woocommerce-   │  │   │  │ notifications.wcpos.com — Novu  │ │
│  │  pos-pro      │  │   │  │ btcpay.wcpos.com — BTC payments │ │
│  └───────────────┘  │   │  └─────────────────────────────────┘ │
└─────────────────────┘   │  PostgreSQL / Redis / MariaDB        │
                          │  Grafana / Loki / Uptime Kuma        │
                          └──────────────────────────────────────┘
```

**Key relationships:**
- **Client ↔ WordPress**: All product/order/customer data flows through WooCommerce REST API
- **Client ↔ Updates Server**: App updates (Electron), Pro plugin updates, license validation (proxies to Keygen)
- **Client ↔ Novu**: Push notifications, in-app messaging
- **MedusaJS → Keygen**: License creation on purchase
- **Free ↔ Pro plugin**: Pro includes free as a Composer dependency, extends via hooks/filters
- **wcpos.com**: Next.js on Vercel — marketing site + admin dashboard

## Plugin Architecture (woocommerce-pos)

### Structure

PHP plugin under namespace `WCPOS\WooCommercePOS\`. PSR-4 autoloading via Composer.

```text
includes/
  Init.php                 — Bootstrap: loads common, frontend, admin, integrations
  API.php                  — WCPOS REST API router (/wcpos/v1)
  WC_API.php               — Hooks into standard WC REST API for POS filtering
  Orders.php               — Order lifecycle, custom statuses, coupon context
  Products.php             — Product modifications for POS
  Gateways.php             — Payment gateway management
  Templates.php            — Frontend template registration
  API/                     — REST controllers (extend WC core controllers)
    Auth.php               — JWT authentication
    Orders_Controller.php  — POS order creation/updates
    Products_Controller.php
    Customers_Controller.php
    Receipts_Controller.php
    Settings.php
    Traits/                — Shared: WCPOS_REST_API, Uuid_Handler, Query_Helpers
  Services/                — Business logic singletons
    Auth.php               — JWT generation/validation (Firebase JWT)
    Settings.php           — Per-section settings management
    Receipt_Data_Builder.php
  Gateways/
    Cash.php               — Cash with change calculation, partial payments
    Card.php               — Card payment base
  Admin/                   — WP admin: settings pages, order/product screens
  Templates/               — Frontend page renderers (POS app, login, payment, receipt)
packages/                  — Frontend JS (pnpm workspaces): settings, analytics, template editor
tests/                     — PHPUnit suite
```

### How POS Orders Differ from Regular WooCommerce Orders

| Aspect | Regular WC | POS |
|--------|-----------|-----|
| Creation | Checkout form | REST API with line items + `_woocommerce_pos_data` meta |
| Payment | Redirect-based gateways | Cash/Card (no redirect, immediate) |
| Status flow | pending → processing | Direct to completed or `wc-pos-partial` |
| Pricing | WC handles subtotal/total | POS overrides via subtotal filter for cashier price changes |
| Auth | Cookie/nonce | JWT tokens (access + refresh) |

### Key Patterns

- **Dual API mode**: POS requests (`X-WCPOS` header) load WCPOS controllers; standard WC requests load `WC_API` for POS visibility filtering
- **JWT auth**: Two-secret system (access + refresh tokens), early determination via `determine_current_user_early()`
- **Pro extension**: Free plugin checks if Pro is active on load — if yes, returns early (Pro includes all free code). Pro hooks in via filters like `woocommerce_pos_rest_api_controllers`
- **Registry pattern**: `Registry::get_instance()` stores singleton references for hook removal
- **UUID sync**: `Uuid_Handler` trait generates UUIDs for offline-created records

### Extension Points (for Pro and third-party)

- `woocommerce_pos_rest_api_controllers` — add/override REST controllers
- `woocommerce_pos_payment_gateways` — register payment gateways
- `woocommerce_pos_general_settings` / `_checkout_settings` etc. — extend settings
- Standard WooCommerce hooks (`woocommerce_order_*`, `woocommerce_product_*`) — POS orders trigger these normally

### Running Tests

```bash
pnpm test:unit:php
# or: vendor/bin/phpunit -c .phpunit.xml.dist
```

Test base class: `WCPOS_REST_Unit_Test_Case` — sets up admin user with `X-WCPOS` header, helpers for GET/POST requests.
