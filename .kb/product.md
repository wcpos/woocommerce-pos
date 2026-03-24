# WCPOS — Product Context

## What It Is

WCPOS is a Point of Sale application for WooCommerce. It lets store owners sell their
WooCommerce products in person — same catalog, same stock, same customers — using a
native app on a tablet or phone.

Three codebases make up the product:

| Repo | What It Does |
|---|---|
| `woocommerce-pos` | WordPress plugin (free). Extends the WooCommerce REST API for POS use, handles server-side order processing, tax calculation, and payment gateway integration. PHP. |
| `woocommerce-pos-pro` | WordPress plugin (paid). Incorporates the free plugin as a Composer dependency and adds Pro features: payment terminal integration, stock/price editing, order history, customer management, end-of-day reports. PHP. |
| `monorepo-v2` | Client application. React Native + Expo, cross-platform (iOS, Android, Web, Desktop via Electron). Uses RxDB as a local-first reactive database. TypeScript. |

## Who Uses It

1. **Small shop owners** — Non-technical. Run a small retail business, already have WooCommerce online. Want to sell in-store without re-entering products. Care about simplicity and reliability.
2. **Event/occasional sellers** — Use WooCommerce online but sell in person at markets, fairs, pop-ups. Need offline support and portability. May go months between uses.
3. **Developers/agencies** — Building or managing WooCommerce sites for clients. Evaluate WCPOS on architecture, extensibility, and maintainability. Comfortable with REST APIs and React Native.

## Core Design Principles

- **Offline-first**: RxDB is the single source of truth on the client. The app must work fully without internet. Sales sync when connectivity returns.
- **Native WooCommerce integration**: Uses the official WooCommerce REST API. No database hacks, no middleware. If WooCommerce supports it, WCPOS should too.
- **Merchant independence**: Store owners own their data, hosting, and infrastructure. No platform lock-in.
- **Reliability over features**: When a customer is at the counter, the POS has to work. Every time. Don't ship features that compromise reliability.

## Current State (2026)

- Web app shipped May 2023
- Native iOS and Android apps in beta (late 2025)
- ~6,000 active WordPress installations
- Free version is fully functional; Pro adds payment terminals, stock editing, order history, customer management, end-of-day reports
- Pro pricing: $129/year or $399 lifetime
- Solo developer (Paul), AI-assisted development

## Key Technical Decisions

- **RxDB** for local-first reactive data — enables offline mode and fast UI
- **React Native + Expo** for true cross-platform native apps
- **WooCommerce REST API** as the sole server communication layer
- **System fonts** everywhere — no external font dependencies
- **GPL v2** open source license, matching the WordPress ecosystem
