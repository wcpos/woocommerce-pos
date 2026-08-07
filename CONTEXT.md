# WCPOS Free Plugin

Domain language for the `woocommerce-pos` WordPress plugin — the server-side foundation for WCPOS. Terms here are binding for code, docs, and reviews; architecture reviews and grilling sessions update this file as concepts crystallize.

## Language

### Settings

**Settings**:
User-configurable intent stored in `woocommerce_pos_settings_*` options and read through the Settings module. Distinct from Plugin State.
_Avoid_: options, config, preferences

**Settings Section**:
The unit that owns one settings group end to end: schema, defaults, sanitization, secret redaction, merge strategy, and (when not option-backed) custom read/write behaviour. Nine exist: general, checkout, tax_ids, payment_gateways, tools, visibility, cloud_print, access (role-backed), license (Pro-injected). Classes are named `{Id}_Section`.
_Avoid_: settings group, settings schema, settings tab

**Section Registry**:
The seam where Settings Sections are registered. The free plugin registers its nine; Pro and extensions register theirs through it instead of hooking ad-hoc filters.
_Avoid_: section manager, settings factory

**Plugin State**:
Machine bookkeeping stored in options but not user intent: site UUID, JWT secret keys, install timestamp, DB version. Owned by the module that uses it (e.g. Auth owns its secret keys), never by the Settings module.
_Avoid_: settings (for these), internal options

### Sync

**Collection Rule**:
One POS query behaviour for one collection — the params it claims, the clauses it contributes, the storage it targets — declared once in the Collection Rules module and applied identically on every read lane, so it cannot be wired into only one.
_Avoid_: query filter, orderby mapping, proxy mirror

**Read Lane**:
One of the two paths a collection read reaches the client by — the direct lane (`wcpos/v1` controllers) and the proxy lane (`wcpos/v2` → `wc/v3`). Behaviour that exists on one lane only is a parity bug, not a design.
_Avoid_: v1/v2 API, endpoint version
