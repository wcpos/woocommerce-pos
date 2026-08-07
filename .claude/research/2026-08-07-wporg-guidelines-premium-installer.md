# WordPress.org guidelines for installing Pro from the free plugin

**Date:** 2026-08-07
**Issue:** [wcpos/woocommerce-pos#1528](https://github.com/wcpos/woocommerce-pos/issues/1528)
**Question:** Can the wp.org-hosted free WCPOS plugin accept a license key and then download + install WCPOS Pro server-to-server from `updates.wcpos.com` via `Plugin_Upgrader`?

## Bottom line

**The "paste a license key into wp-admin, we install it for you" flow as described is the disallowed shape.** Both reference implementations avoid it the same way: **authorization happens on the vendor's own domain**, and the free plugin never treats a key typed into WordPress as the authority to fetch and install code.

Two shapes pass review today:
- **Elementor** — OAuth connect on `my.elementor.com`, then a single explicit nonce-protected **"Install & Activate"** button in wp-admin runs `Plugin_Upgrader`. Better UX, tolerated but not documented as permitted.
- **WooCommerce** — wp-admin merely redirects to `woocommerce.com/auto-install-init/`; woocommerce.com then calls **back** into an HMAC-authenticated REST endpoint on the site to install. Worse UX, squarely inside the documented carve-out.

The governing text is Guideline 8, which names our exact use case as prohibited:

> - Serving updates or otherwise installing plugins, themes, or add-ons from servers other than WordPress.org's
> - **Installing premium versions of the same plugin**

— [wporg-plugin-guidelines/guideline-08.md](https://github.com/WordPress/wporg-plugin-guidelines/blob/trunk/guideline-08.md), rendered at [Detailed Plugin Guidelines #8](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)

But the same guideline carries the carve-out the whole pattern rests on:

> Management services that interact with and push software down to a site *are* permitted, provided the service handles the interaction on it's own domain and not within the WordPress dashboard.

So the question is never "may we install Pro?" — it is **"is the install authorized by a service the user interacted with on our domain, or by a form in wp-admin?"** That distinction is the entire compliance boundary.

## 1. The governing guidelines

### Guideline 8 — executable code (the controlling one)

Full text: [guideline-08.md](https://github.com/WordPress/wporg-plugin-guidelines/blob/trunk/guideline-08.md). Prohibits "Serving updates or otherwise installing plugins, themes, or add-ons from servers other than WordPress.org's" and "Installing premium versions of the same plugin", while permitting management **services** that push software down to a site "provided the service handles the interaction on it's own domain and not within the WordPress dashboard."

### The 2017 clarification — the intent, and the "service" carve-out

[Clarification of Guideline 8 – Executable Code and Installs](https://make.wordpress.org/plugins/2017/03/16/clarification-of-guideline-8-executable-code-and-installs/) (Mika Epstein, 2017-03-16) is the single most useful primary source. Verbatim highlights:

> The longer answer involves understanding the intent of this guideline, which initially was to prevent nefarious developers from using your installs as a botnet. In addition, it's used to disallow plugins that exist only to install products from other locations, without actually being of use themselves (ie. marketplace only plugins).

> Plugins are expected to do 'something' to your site. **A plugin that exists only to check a license and install a product, while incredibly useful, is not something we currently allow as a standalone product.** This is why we allow plugins to have the in-situ code for updates that is used by their add-on plugins. The plugin we host has to use WordPress.org to update itself.

> The trick here, and this is what is about to sound like hair splitting, is that it's not the plugin UI on your site that does the install. In order for Manage WP and Jetpack to work, you have to go to your panel on their sites and install the items. If you wanted to make, say, my.servicename.com and let people log in, authenticate their sites, and from that interface use a JSON API to trigger an install, you absolutely, 100%, totally can.

The **comment thread on that post is more prescriptive than the post itself**, and all of the following were verified verbatim against the page source. Otto (Samuel Wood) on why button location is not superficial:

> The difference is in whether you're having the plugin do the install, or whether you (in the form of your service) are doing the install on the part of the user. If it goes wrong, then you are to blame for it, directly, by the user. They know what you did. They asked you (in the form of your service) to do it. **It's about accountability and responsibility and blame.** The intent of the guidelines is to prevent user confusion. People do not understand the differences between "WordPress" and "stuff that plugins or themes add to WordPress".

Three exchanges that bear directly on our design:

- **Consent alone does not cure it.** Asked "If an admin notice is added that clarifies exactly what is happening, which a user would have to click 'ok/accept' before they can install the themes, would that be acceptable?" — Mika Epstein: **"No. Not today.** We may revisit this in the future, but right now, the inconvenient UX of going to another site is worth the clarity for the end users." The developer's reply: "It's disappointing that obtaining explicit user consent isn't deemed sufficient."
- **No lightbox/iframe of the vendor site inside wp-admin.** Asked whether the plugin could open the vendor's install panel in a lightbox inside wp-admin — Epstein: **"It needs to be on the service account on the theme author's domain."** (Consistent with Guideline 8's separate prohibition on "Using iframes to connect admin pages".)
- **All modals, not just lightboxes.** Asked whether this applied to modals that are separate browser windows — Epstein: **"All modals. Use a new browser tab/window right now.** IF this changes, and that's a BIG IF, we'll post and explain it full details."

And the summary Q&A:

> - Can you have your plugin install things? **No.**
> - Can your service install things onto a connected site? **Yes.**
> - Are you allowed to have a marketplace plugin? **Not at this time.**

Two things follow directly for WCPOS:

1. We are safe on "marketplace only plugin" grounds — free WCPOS is a substantial product in its own right, not a license-checker shell. That clause is aimed at plugins whose only function is installing something else.
2. **Free WCPOS itself must continue to update from wp.org.** Only Pro may update from `updates.wcpos.com`. Never add a self-update path to the free plugin.

### The 2024 guidance post — the strict table

[Guidance on plugins that install other plugins](https://make.wordpress.org/plugins/2024/09/09/guidance-on-plugins-that-install-other-plugins/) (Francisco Torres, 2024-09-09). This post explicitly classifies "Pro / Premium versions of the plugin" as a *Recommendation*, and states:

> a plugin will not be able to perform the installation of another plugin that is not in the directory. The way to install plugins that are not part of the directory will be **a manual installation by the user**.

Its summary table:

| How are other plugins installed? | Manually | Core UX | Custom UX | No-asking |
|---|---|---|---|---|
| Plugins in the directory | ✅ | ✅ | ✅ | ❌ |
| **External plugins** | **✅** | **❌** | **❌** | **❌** |

Read literally, that table forbids the whole flow — the only permitted route for an external ZIP is manual upload. Two important caveats:

- The post was framed as **community consultation**, not a final ruling ("Please share your feedback before September 23rd… After this process, this post will be updated with specific details in those cases"). The promised follow-up ruling and 3-month compliance window were **never published** (see §3).
- In the comments, Andy Fragen pointed out the Core UX can also install non-wp.org plugins, and Francisco Torres immediately conceded and downgraded that cell to ❌ — showing the table was being drafted live rather than reflecting settled enforcement.

Its consent rules are, however, the clearest statement we have of what the review team wants from the UI, and they read as directly applicable:

> **Inform users and ask for permission:** Users must be adequately informed of the actions they are taking and be able to decide whether they want to perform that action or not, otherwise that would be considered dishonest towards the users.

> **No-asking:** Automatically install plugins without informing the user and/or asking for their permission. **This is expressly not allowed.**

Plus five named suggestions: (1) make it prominent that a plugin will be installed, (2) **avoid pre-selected options**, (3) **install one at a time**, never multi-install, (4) provide full information about what is being installed, (5) consider routing through the Core UX.

### Supporting guidelines

- **[#5 Trialware](https://github.com/WordPress/wporg-plugin-guidelines/blob/trunk/guideline-05.md)** — "Plugins may not contain functionality that is restricted or locked, only to be made available by payment or upgrade." Crucially it also blesses our architecture: "We recommend the use of **add-on plugins, hosted outside of WordPress.org**, in order to exclude the premium code." And: "Attempting to upsell the user on ad-hoc products and features *is* acceptable, provided it falls within bounds of guideline 11." So free WCPOS must not ship disabled Pro code or feature gates — Pro features live entirely in the Pro plugin, which is what we already do.
- **[#7 Tracking/consent](https://github.com/WordPress/wporg-plugin-guidelines/blob/trunk/guideline-07.md)** — "plugins may not contact external servers without *explicit* and authorized consent… commonly done via an 'opt in' method, requiring registration with a service." Any call to `updates.wcpos.com` must be user-initiated, and the readme must document it. The SaaS exception applies once the user registers/configures the service: "By installing, activating, registering, and configuring plugins that utilize those services, consent is granted."
- **[#11 Don't hijack the dashboard](https://github.com/WordPress/wporg-plugin-guidelines/blob/trunk/guideline-11.md)** — upgrade prompts "must be limited in scope and used sparingly, be that contextually or only on the plugin's setting page." Site-wide notices must be dismissible. So the connect/install UI belongs on a WCPOS settings screen, not in a global admin notice.

## 2. What Elementor does

Verified against the **actual wp.org-hosted zip**, not GitHub: `curl -sL https://downloads.wordpress.org/plugin/elementor.zip`, Elementor **4.2.2**, module at `modules/pro-install/`. This is live, currently-distributed, review-passing code that calls `Plugin_Upgrader->install()` with an **external** package URL — which is the strongest available evidence of where the real enforcement line sits, as distinct from the 2024 table's literal reading.

**The flow, in order:**

1. **Connect happens on Elementor's domain.** `Connect_Page_Renderer::render_connect_box()` shows a single "Connect to Elementor" button linking to the app's `authorize` action. `Base_App::action_authorize()` calls `redirect_to_remote_authorize_url()` — a standard OAuth `authorization_code` handshake against `https://my.elementor.com/connect/v1` (`base-app.php:23`), returning via `action_get_token()` with `state` validation. **The user authenticates and authorizes on my.elementor.com.** No license key is ever typed into wp-admin.
2. **Entitlement is discovered, not asserted.** `Connect::get_download_link()` (`modules/pro-install/connect.php`) GETs `https://my.elementor.com/api/v2/artifacts/PLUGIN/latest/download-link` over the authorized connection. The package URL is per-subscription and server-issued; nothing about it is hardcoded or user-supplied.
3. **No subscription → no install affordance at all.** If `get_download_link()` is empty, `render_promotion_box()` renders a plain marketing upsell with an "Upgrade Now" link **out** to elementor.com. The install button is not rendered, not disabled-with-a-tooltip — simply absent.
4. **Subscription → one explicit button.** `render_install_or_activate_box()` renders exactly one CTA whose label states the action verbatim: **"Install & Activate"** (or "Activate Elementor Pro" when Pro is already on disk). Under a heading "You've got Elementor Pro". No checkbox, nothing pre-selected, nothing bundled, one plugin only.
5. **The button is a nonced admin-post link, not a background request.**
   ```php
   $cta_url = wp_nonce_url( admin_url( 'admin-post.php?action=elementor_do_pro_install' ), 'elementor_do_pro_install' );
   ```
6. **The handler re-verifies everything.** `Module::admin_post_elementor_do_pro_install()`:
   ```php
   if ( ! current_user_can( 'install_plugins' ) ) { wp_die( … ); }
   check_admin_referer( 'elementor_do_pro_install' );
   $download_link = $app->get_download_link();
   if ( empty( $download_link ) ) { wp_die( esc_html__( 'There are no available subscriptions at the moment.', 'elementor' ) ); }
   $plugin_installer = new Plugin_Installer( 'elementor-pro', $download_link );
   ```
   Note it re-fetches the download link at execution time rather than trusting anything rendered earlier, and gates on `install_plugins` (not just `manage_options`, which is what the menu item itself uses).
7. **The install itself is stock core machinery.** `Plugin_Installer::do_install()` is a thin wrapper: `new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() )` then `$upgrader->install( $package_url )`, followed by `activate_plugin()`. No custom download/unzip, no bypassing core's filesystem abstraction or signature handling.
8. **The module only exists when relevant.** `Module::is_active()` returns `! Utils::has_pro() && current_user_can( 'manage_options' )` — the whole feature disappears once Pro is present.

**The load-bearing differences from WCPOS's current plan:** Elementor never accepts a pasted license key in wp-admin, and it never derives the package URL from user input. Authorization is delegated to my.elementor.com, which is precisely what converts "the plugin installs things" (prohibited) into "the service installs things onto a connected site" (permitted) under the 2017 clarification.

## 2b. What WooCommerce.com does (the stricter reference)

Verified against WooCommerce **11.0.0** from wp.org. Woo implements the *purest* form of the 2017 carve-out, and is notably more conservative than Elementor.

- **The install code lives in the wp.org plugin, but the plugin's own UI never calls it.** The installer is exposed only as a REST namespace `wccom-site/v3` (`includes/wccom-site/rest-api/endpoints/class-wc-rest-wccom-site-installer-controller.php`), running steps `get_product_info → download_product → unpack_product → move_product → activate_product`. The legacy in-process entry point is dead code.
- **wp-admin only hands off to woocommerce.com.** The "Add to store" affordance resolves to `WC_Helper::get_subscription_install_url()` (`includes/admin/helper/class-wc-helper.php:1355`), which builds an authenticated URL to `https://woocommerce.com/auto-install-init/`. The browser leaves the site; **woocommerce.com then calls back into the site's REST endpoint** to perform the install. This is literally the 2017 post's description: "you have to go to your panel on their sites and install the items."
- **The callback is authenticated as a service, not by a nonce.** `WC_WCCOM_Site::authenticate_wccom()` validates a Bearer token via `hash_equals` plus an HMAC `X-Woo-Signature` over host+URI+method+body, then requires the resolved user to hold **both** `install_plugins` and `install_themes`. Consent is the site↔woocommerce.com OAuth connection plus the user's explicit purchase/install click on woocommerce.com.
- **Woo stopped serving update ZIPs from the free plugin entirely.** `WC_Helper_Updater` injects update metadata into `pre_set_site_transient_update_plugins` but sets **`'package' => ''`** (`class-wc-helper-updater.php:92`); actually delivering update packages was moved out to a separate "WooCommerce.com Update Manager" plugin hosted off wp.org. That is a direct, expensive accommodation of Guideline 8's "Serving updates… from servers other than WordPress.org's" clause by the largest plugin in the directory — and the clearest signal available of how the clause is actually read.

**Implication for WCPOS:** there is a spectrum, and both ends pass review.
- *Elementor model* — in-dashboard button triggers `Plugin_Upgrader`, authorized by a prior OAuth connection. Better UX, more residual risk.
- *WooCommerce model* — the dashboard only redirects to the vendor site; the vendor calls back into an authenticated REST endpoint to install. Worse UX, near-zero guideline risk, and squarely inside the 2017 carve-out.

If we want maximum safety, the Woo model is the one to copy. If we want the better UX, Elementor's is demonstrably tolerated — but note it is tolerated, not documented as permitted.

## 3. Rejection precedents and the state of enforcement

Documented cases are thin — the review team does not publish a rejection log, and most evidence is second-hand. What is verifiable:

- **Advanced Plugin Dependencies (Andy Fragen) — rejected.** In the comments on the 2024 guidance post, Fragen states plainly: *"That was the case for my Advanced Plugin Dependencies plugin rejection and not even that the plugin itself installed other plugins but could be used by others for that purpose."* This is the clearest documented rejection under this guideline, and notable for how far it reaches: the plugin was rejected for merely **enabling** non-wp.org installs, without performing any itself. Source: comment thread on [Guidance on plugins that install other plugins](https://make.wordpress.org/plugins/2024/09/09/guidance-on-plugins-that-install-other-plugins/).
- **"Marketplace" plugins — categorically refused.** 2017 clarification: *"Are you allowed to have a marketplace plugin? Not at this time."* And: *"A plugin that exists only to check a license and install a product… is not something we currently allow as a standalone product."* Free WCPOS is not in this category, but a hypothetical standalone "WCPOS Pro Installer" plugin would be rejected outright. **Do not ship the installer as its own plugin.**
- **GravityKit** stated in the 2024 thread that they were *"forced to strip this functionality from directory-hosted products"*; Francisco Torres replied that *"guideline 8 seems essential for many reasons."*
- **No documented removal of an already-listed plugin** for auto-installing a pro version could be found. The enforcement record is rejections at submission (Fragen), forced feature removal (GravityKit), and evident non-enforcement against incumbents.
- **The review team corrected its own table live, in public.** Also in that comment thread, Fragen noted the Core UX can install non-wp.org plugins; Francisco Torres replied *"I wasn't aware of that possibility, in that case it's a ❌ then."* This is worth weighing: the 2024 table's strictness was partly reactive drafting, not a settled position that had been enforced.

**The promised follow-up ruling was never published.** The 2024 post committed to updating "after getting your feedback and the team makes a decision", followed by a 3-month compliance window. Reviewing the [Make/Plugins blog index](https://make.wordpress.org/plugins/) through August 2026, no such decision post exists — the most recent posts are team status updates, tooling, and directory-process items. The related meta ticket [#7716 "Need clear definition of Explicit Consent for Plugin Guidelines"](https://meta.trac.wordpress.org/ticket/7716) exists but is bot-gated to automated fetches, so its status could not be verified here.

Corroborating this: `guideline-08.md` in `WordPress/wporg-plugin-guidelines` has **not been modified since 2017-12-28** (verified via `gh api repos/WordPress/wporg-plugin-guidelines/commits?path=guideline-08.md`), and comments on the 2024 post are closed with the last reply on 12 Oct 2024. The consultation simply lapsed — it opened three weeks before the Sept/Oct 2024 WordPress governance dispute. Paid Memberships Pro, which [voluntarily closed its listing](https://www.paidmembershipspro.com/leaving-wordpress-org/) in Oct 2024, records being told at WCUS 2024 that "the team was aware of plugins breaking the guideline, that clarification would come later this year." It never came.

**What that means practically.** The strict 2024 table never became binding policy, the compliance window never started, and the guideline text itself is unchanged since 2017. Meanwhile Elementor — one of the most-scrutinised plugins in the directory — ships a `Plugin_Upgrader` install of an external premium ZIP in its current wp.org release, and WooCommerce ships the REST endpoint that lets woocommerce.com do the same. **Enforcement is discretionary and reviewer-dependent.** Design so that losing the in-dashboard installer costs us a button, not an architecture.

## 4. Constraints for the UX design

These are requirements, not preferences. Each maps to a cited guideline or to observed review-passing behaviour in Elementor 4.2.2.

### A. Authorization must happen on our domain, not in wp-admin

1. **Do not build a "paste your license key into wp-admin" box as the authorization step for installation.** This is the single biggest change from the plan in the issue. The 2017 clarification turns on *where the user authorized the install*: "it's not the plugin UI on your site that does the install… you have to go to your panel on their sites." Pasting a key into a WordPress form makes the plugin UI the authorizing surface — the disallowed side of the line.
2. **Use a redirect-based connect flow instead.** "Connect to WCPOS" → redirect to `wcpos.com` / `updates.wcpos.com` → user authenticates and authorizes *there* → redirect back with a code/token. Elementor uses OAuth `authorization_code` with `state` validation; match that shape.
3. **Derive the package URL from the authorized connection only.** It must be issued by our server for that specific subscription, per Elementor's `get_download_link()`. Never build the download URL from user input, and never hardcode a ZIP URL that an unauthorized site could hit.
4. A license-key field may still exist for *other* purposes (e.g. activating an already-installed Pro), but it must not be the trigger that causes the free plugin to download and install a ZIP.
5. **The connect step must open the real vendor site in a browser tab or window — never an iframe, lightbox, or embedded modal inside wp-admin.** Epstein, verbatim: *"All modals. Use a new browser tab/window right now."* Guideline 8 separately bans "Using iframes to connect admin pages". The user must be able to see our address bar.
6. **Do not treat a consent checkbox or admin notice as a substitute for off-site authorization.** This exact substitution was proposed and refused: *"No. Not today… the inconvenient UX of going to another site is worth the clarity for the end users."* Consent improves the flow but does not by itself move it onto the permitted side of the line — *where* the user authorizes is what matters.

### B. What must never be automatic

7. **No install may ever happen without a discrete user click on a control that says it installs.** "Automatically install plugins without informing the user and/or asking for their permission… is expressly not allowed" (2024 guidance).
8. **No install on activation, on connect, on upgrade, on cron, or as a side effect of saving settings.** Returning from the OAuth redirect must land the user on a screen where the install button is *offered*, not on a completed install.
9. **No pre-selected checkbox, no pre-checked "also install Pro", no opt-out framing** (2024 guidance, Suggestion 2). If a checkbox is used at all it must default to unchecked.
10. **One plugin at a time.** No bundling Pro with add-ons or any other install in a single action (Suggestion 3).
11. **No auto-update of Pro from our server without the user having opted into that separately** — and free WCPOS must continue to update from wp.org only. Never add a self-update path to the free plugin (2017: "The plugin we host has to use WordPress.org to update itself").

### C. Consent wording and affordance

12. **The button label must state the action literally.** Elementor uses **"Install & Activate"** and **"Activate Elementor Pro"**. Use the same register — e.g. "Install & Activate WCPOS Pro". Never "Get started", "Finish setup", "Continue", or "Enable Pro features", which hide that a plugin is being installed.
13. **The statement that a plugin will be installed must be the most prominent text in that block, adjacent to the control** — not in fine print, not separated by other UI (2024 guidance, Suggestion 1; and the review-team-endorsed comment thread asking for minimum font size/contrast and adjacency).
14. **Disclose what is being installed:** plugin name, that it comes from `updates.wcpos.com` rather than WordPress.org, the version, and a link to more information about WCPOS Pro (Suggestion 4). Because Pro is not in the directory there is no wp.org page to link — so we must supply that detail ourselves.
15. **Name the source domain in the UI.** Users are entitled to know code is arriving from a non-wp.org server before they consent.

### D. Gating and capabilities

16. **Capability check `install_plugins`** on the handler (not merely `manage_options`) — Elementor checks `install_plugins` in `admin_post_elementor_do_pro_install()` even though the menu page itself is `manage_options`. Also respect `DISALLOW_FILE_MODS`.
17. **Nonce the action** with `wp_nonce_url()` + `check_admin_referer()`, and route it through `admin-post.php` (or a nonce-verified REST route). Never a bare GET link, never an unauthenticated AJAX endpoint.
18. **Re-verify entitlement server-side at execution time**, re-fetching the download link inside the handler rather than trusting anything embedded in the page.
19. **Use core's `Plugin_Upgrader` + `Automatic_Upgrader_Skin`** and `activate_plugin()`. Do not hand-roll download/unzip/filesystem writes — core's machinery is what reviewers expect and it respects the filesystem abstraction.

### E. No entitlement → no install affordance

20. **If the connected account has no active subscription, do not render the install button at all.** Elementor swaps in a plain upsell box with an outbound "Upgrade Now" link. Do not render a disabled button, a teaser, or a "buy now to unlock this button" state.
21. **Keep the upsell within Guideline 11:** confined to the WCPOS settings screen, contextual, and any admin notice must be dismissible. No site-wide nags, no dashboard widgets that can't be dismissed.
22. **Hide the whole flow once Pro is active** — Elementor's module deactivates itself via `! Utils::has_pro()`.

### F. Trialware and code separation

23. **Ship no Pro code, no locked features, and no feature-gated stubs in the free plugin** (Guideline 5). Guideline 5 explicitly recommends our architecture: "the use of add-on plugins, hosted outside of WordPress.org, in order to exclude the premium code."
24. **Free WCPOS must remain fully useful on its own.** The "marketplace only plugin" prohibition targets plugins that exist only to check a license and install something — free WCPOS is a substantial product, which is what keeps us clear of that clause.

### G. Disclosure in the readme

25. **Add a "Use of 3rd-party services" section to `readme.txt`** naming `updates.wcpos.com` (and any account/licensing endpoint), what data is sent, when it is contacted, and linking our terms and privacy policy. Elementor does exactly this at `readme.txt` line ~220. Required by Guideline 7, which forbids contacting external servers "without *explicit* and authorized consent" and requires documentation "in the plugin's readme, preferably with a clearly stated privacy policy."
26. **No network call to our servers before the user initiates connect.** No phoning home on activation.

### H. Keep the fallback

27. **Retain manual ZIP upload as a documented, first-class path.** It is the one route the 2024 guidance unambiguously permits for external plugins (`External plugins | Manually | ✅`), and it is the safe harbour if the review team ever objects to the connect-driven installer. The design must degrade to it cleanly — including when `install_plugins` is denied or `DISALLOW_FILE_MODS` is set.

### Residual risk

The connect-driven installer sits in a **tolerated-but-not-explicitly-blessed** zone: Guideline 8's literal text and the 2024 table both read against it, while the 2017 "service" carve-out and Elementor's live, currently-distributed implementation read for it. Enforcement here is discretionary and reviewer-dependent. Two mitigations are worth taking regardless of design: **(a)** keep manual upload working so a forced removal of the installer is a small change rather than a redesign, and **(b)** consider emailing `plugins@wordpress.org` describing the exact flow before shipping — the 2017 post explicitly invites this ("you're always welcome to ask us if something's okay or not"), and a written answer converts this residual risk into a known quantity.

