# WCPOS — User Personas

Quick reference for making UX and code decisions. For full audience profiles
including marketing messaging and journey stages, see wcpos-brand/brand/audiences.md.

## 1. Small Shop Owner

- Runs a small independent retail business (1-5 employees)
- Already has a WooCommerce online store
- Limited technical skills — can install a plugin, can't write code
- Could be anywhere in the world, often not a native English speaker

**What they care about:** Simplicity, reliability, inventory sync, affordability.

**Design implications:**
- Default settings should work without configuration
- Error messages must be plain language, not technical
- UI labels should use "products" not "SKUs", "sync" not "replication"
- Assume slow/unreliable internet
- Assume the device is a shared tablet on a counter

## 2. Event / Occasional Seller

- Sells in person occasionally — markets, fairs, pop-ups, club events
- May go months between uses
- Needs fast setup and teardown
- Event locations have poor connectivity

**What they care about:** Portability, offline mode, not paying for idle months, ease of picking it back up.

**Design implications:**
- The app must be immediately usable after months of inactivity (no forced migrations, no broken state)
- Offline mode is not optional — it's the primary use case for this persona
- Onboarding/reconnection flow should be fast and forgiving
- Don't assume daily familiarity with the UI

## 3. Developer / Agency

- WordPress/WooCommerce developer setting up WCPOS for clients
- Technically proficient — REST APIs, React Native, server management
- Evaluates on architecture, extensibility, maintainability
- May manage multiple client installations

**What they care about:** Clean architecture, extensibility, good docs, not getting support calls.

**Design implications:**
- Hooks, filters, and extension points matter — don't hardcode what could be configurable
- API responses should be predictable and well-structured
- Error messages can be technical — include error codes, relevant IDs
- The plugin should be a good citizen in the WordPress ecosystem (no global namespace pollution, standard hooks)

## Anti-Personas (Don't Optimize For)

- **Large retailers (100k+ products)** — WooCommerce itself struggles here; local sync at this scale is unsolved
- **Enterprise needing ERP integration** — SAP/Oracle connectors are out of scope
- **Businesses without WooCommerce** — no WooCommerce, no WCPOS
- **E-commerce only** — no physical selling, no need for a POS
