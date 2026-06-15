# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A **WordPress child theme** for an influencer-discovery / marketing SaaS, built on the
**Hello Elementor** parent theme. The theme is the presentation + application layer; almost
all page UI is authored in **Elementor templates** (stored in the database, referenced by ID),
and PHP renders them with `do_shortcode('[elementor-template id="…"]')`.

> The `readme.txt` and theme header are leftover Hello-Elementor-Child boilerplate — ignore
> them as documentation of this project. The real entry point is `functions.php`.

### The CreatorDB companion plugin (most important architectural fact)

This theme does **not** define the `influencer` post type, its taxonomies (`niche`, `topic`,
`content_tag`, `platform`), or the "smart" brief-parsing / match-scoring logic. Those come from
a **separate companion plugin** (CreatorDB) that exposes `creatordb_*` functions. Every call
into the plugin is guarded:

```php
if (function_exists('creatordb_calculate_match_score')) {
    return creatordb_calculate_match_score($post_id, $criteria); // prefer the plugin
}
// …otherwise a local fallback implementation runs (see includes/core/helpers.php, search.php)
```

When editing search, scoring, or brief logic, assume the **plugin's version wins at runtime in
production** and the in-theme code is the fallback. Keep the two behaviourally compatible.
`grep -rn "function_exists('creatordb" .` lists every integration seam.

## Build / test / lint

There is **no build system, package manager, or test suite** — no `composer.json`, no
`package.json`, no bundler. PHP, CSS, and JS are edited and deployed as-is.

- `style.css` (~120 KB) is committed directly and loaded as the theme stylesheet. There are no
  committed Sass sources (`.gitignore` references a `.sass-cache/` that lives outside the repo).
- `vendor/dompdf/` is **manually vendored** (no Composer). It is loaded via its native
  `autoload.inc.php`, not `vendor/autoload.php` — see `includes/integrations/dompdf.php`.

### Cache-busting is manual — bump the version constant

All enqueued CSS/JS use `HELLO_ELEMENTOR_CHILD_VERSION` (defined at the top of `functions.php`)
as their `?ver=` string. **After changing any asset in `assets/` or `style.css`, bump that
constant** or browsers/CDNs will serve stale files. It is the closest thing this project has to
a "build step."

### Debugging influencer search

The AJAX search handler (`Influencer_Search::my_custom_loop_filter_handler`) supports a debug
mode gated by `creatordb_brief_search_debug_enabled()`; when on, it `error_log`s the parsed
brief, merged filters, and `WP_Query` args. Watch `wp-content/debug.log`.

## Architecture

### Bootstrap & load order (`functions.php`)

`functions.php` enqueues assets and then `require`s every module in a **deliberate order** that
must be preserved: core helpers → admin settings → hooks → shortcodes → third-party
integrations → domain modules. Foundational helpers must load before the integrations and
modules that call them.

### "Modules" are theme code, not installed plugins

Files under `modules/` carry `Plugin Name:` docblocks and even describe themselves as plugins,
but they are **`require`d by the theme**, never installed via wp-admin → Plugins. Each domain
module is a **singleton class instantiated at the bottom of its own file**:

| File | Class (instantiated at EOF) | Responsibility |
|------|------------------------------|----------------|
| `modules/frontend-utilities/search.php` | `Influencer_Search` | Search form, AJAX loop filter, brief parser |
| `modules/saves/saves-manager.php` | `Saves_Manager` | Saved/viewed influencers, saved searches, groups, group Export PDF; registers the activity CPTs |
| `modules/outreach/outreach.php` | `DD_Outreach_Manager` | Outreach submissions, master-detail dashboard, HTML email builder |
| `modules/frontend-utilities/charts.php` | `DD_Follower_Growth_Chart` | Follower analytics charts (ApexCharts) via shortcodes; time-filter tabs + no-data fallback |
| `modules/frontend-utilities/feeds.php` | `CreatorDB_Instagram_Feed` | Instagram-style content feeds |
| `modules/email-manager/email-template-manager.php` | `DD_Global_Email_Manager` | Global transactional email layout |

A module's constructor registers its `wp_ajax_*` handlers, `add_shortcode` calls, and enqueues.

### Each feature = shortcode + thin Elementor widget wrapper

Module functionality is exposed as **shortcodes** (the canonical implementation) and mirrored by
**Elementor widgets** that are thin wrappers calling the same shortcode/render method.
`modules/frontend-utilities/elementor-widgets/register.php` `require`s and registers all widgets
under a custom **"Influencer Collective"** Elementor category on `elementor/widgets/register`.
When adding a feature, add the shortcode first, then a wrapper widget if it needs to be
drag-and-droppable in Elementor.

### Settings-driven page & template indirection (`includes/core/admin-settings.php`)

Page IDs and Elementor template IDs are **not hardcoded throughout the code** — they are stored
as `wp_options` (keys prefixed `dd_…`, e.g. `dd_search_results_page_id`, `dd_tpl_search_card`)
with hardcoded integer fallbacks, and edited from **Settings → Influencer Theme**. Always read
them through the accessors:

```php
dd_get_page_id('dd_search_results_page_id', 1949);
dd_get_template_id('dd_tpl_search_card', 1839);
```

The admin page provides an AJAX post/template autocomplete, and an admin-bar menu deep-links each
configured page/template straight into the Elementor editor.

The settings screen is a **tabbed UI** (`.dd-tab-btn` / `.dd-panel`) — alongside the page/template
ID panels there is a **"Functionality" tab** for behavioural toggles. Its first such option is
`dd_export_pdf_allowed_levels` ("Export PDF Restriction"), a multi-select of PMPro level IDs that
gates the group **Export PDF** action (see the saves module below). When adding a non-ID feature
toggle, register it on the `dd-theme-settings-functionality` page / `dd_functionality_section` and
read it via `get_option()`.

> Caveat: integration files still contain **environment-specific magic page IDs** tied to the
> production site (e.g. `is_page(1551)` checkout, `4191` buy-credits, free PMPro level `15`).
> These are not in the settings system; treat them as production constants.

### Influencer search pipeline (the core feature)

`Influencer_Search::my_custom_loop_filter_handler` (AJAX action `my_custom_loop_filter`, nonce
`search_filter_nonce`) is the heart of the app:

1. **Gather** explicit form filters (`niche`, `country`, `lang`, `gender`, `min/max_followers`,
   `filter`, `topic`, `content_tag`).
2. **Parse the natural-language brief** (`parse_search_brief`) into structured filters using
   keyword→slug dictionaries (`get_brief_keyword_mappings`), then **merge** with explicit filters.
   Prefers `creatordb_parse_search_brief*` when present.
3. **Build `WP_Query`** with tax_query (content taxonomies OR-ed) + meta_query, separating
   "strict" clauses (verified / expert / country) that are always applied.
4. **Score & sort** the matched IDs (`creatordb_brief_sort_post_ids_by_score`, fallback = flat
   score 50). The scored pool is **cached in a transient keyed by a hash of the query** so that
   "Load More" pagination reuses page 1's expensive scoring instead of re-running it.
5. **Render** each result by injecting the `dd_tpl_search_card` Elementor template per post.
6. A `register_shutdown_function` converts any fatal (Elementor's renderer is memory-hungry and
   can OOM on later pages) into a clean `{success:false, recoverable:true}` JSON the front-end
   can silently retry — instead of a bare HTTP 500.

Country meta is stored as **ISO alpha-3** (e.g. `GBR`); `helpers.php` has alpha-3→alpha-2 and
country-name→alpha-2 maps for flags and matching. Filter dropdown option lists
(countries/languages/genders) are built by direct `$wpdb` queries and **cached in transients**
that are flushed on `save_post`/`delete_post` of an influencer.

### Frontend JS (`assets/js/`)

- All client code attaches to a single global namespace: **`window.InfluencerApp`**.
- `main.js` is the **orchestrator** — `$(document).ready` calls `InfluencerApp.*` init methods in
  order and fires the initial search only on the configured results page.
- Modules in `assets/js/modules/` each extend `InfluencerApp`. They are enqueued in `functions.php`
  as a **dependency chain** — each handle declares the previous handle as its dependency
  (`jquery → dd-modal → … → inf-search-fetch → influencer-js`) to force load order. Insert new
  modules into that chain, not as standalone enqueues.
- `dd-modal.js` provides global `ddAlert()` / `ddConfirm()` and **must load first** (also enqueued
  separately on admin screens).
- `ajax_vars` (localized onto the `influencer-js` handle) carries `ajax_url`, the configured page
  IDs, and all nonces. Reference it from any module.
- `Saves_Manager::enqueue_ajax_variables()` (`modules/saves/saves-manager.php`) **also** localizes
  a second, smaller `ajax_vars` object onto a separate `theme-saves-js` handle (runs on every page,
  not just search). Both objects share the same global JS variable name, so on pages where both
  handles load, whichever prints last in the DOM wins. When adding a nonce/value that
  `saves-manager.js` needs on non-search pages (e.g. `export_pdf_nonce`), add it to **both**
  localizations.

### Data model & user activity

- **Influencer attributes** live in post meta: `followers`, `engagerate`, `avglikes`,
  `avgcomments`, `posts`, `country` (alpha-3), `lang`, `gender`, `isverified`, `is_expert`,
  `creatordb_last_updated`, etc. — plus taxonomies `niche` / `topic` / `content_tag` / `platform`.
- **User activity** is modelled as custom post types, **registered by `Saves_Manager`**:
  `saved-influencer`, `viewed-influencer`, `saved-search` (the `outreach` CPT is provided
  externally). These store an `influencer_id` meta linking back to the influencer; helpers in
  `helpers.php` (`get_saved_influencer`, `get_viewed_influencer`, `get_outreach`, with optional
  `this_month_only`) query them, often via direct `$wpdb` for performance.
- **Unlocks** (paying to reveal a profile) are derived from the **myCred `buy_content` log** plus
  a `dd_unlocked_influencers` user-meta array — see `is_influencer_unlocked()` /
  `get_user_purchased_post_ids()`.

### Group Export PDF gating (`Saves_Manager`)

The per-group **Export PDF** button is plan-gated by `Saves_Manager::user_can_export_pdf()`, which
checks the current user's PMPro level ID against the `dd_export_pdf_allowed_levels` option (set in
the Functionality settings tab). **Empty option ⇒ no one can export** (fail-closed). Server-side the
result is passed to the group modal as `data-*` attributes and the button is only rendered (and only
shown) for **non-empty** groups; the front-end (`saves-manager.js`) routes disallowed users to the
upgrade URL instead of triggering the `creatordb_export_saved_list_pdf` AJAX. Keep the JS guard and
the PHP check in sync — the PHP check is the real boundary.

### Third-party integrations (`includes/integrations/`, `modules/membership-extensions/`)

- **PMPro** (`pmpro.php`) — membership is the access spine: enforces a single active level,
  prorates initial payment on plan switches (`pmpro_checkout_level`), forces free-tier (level 15)
  members to the upgrade page, adds first/last-name checkout fields, restyles the member profile
  into tabs, and customizes login/logout redirects. A `pmpro-level-{slug}` body class is added for
  CSS gating (`.hide-on-free-trial`, etc., toggled in `hooks.php`).
- **myCred** (`mycred.php`) — credits/points: deduct/balance helpers, restyles the buy-credits
  checkout (`#buycred-checkout-form`) into the influencer look, a click-confirm gate before
  spending a credit (`mycred-buy-confirm.js`), and bank-transfer pending-notification handling.
  Also see `pmpro-mycred-rewards-manager.php` under membership-extensions.
- **Registration points on non-checkout level changes** (`pmpro-mycred-rewards-manager.php`,
  `DD_PMPro_Rewards_Manager`) — `pmpro_after_checkout` only fires for real front-end checkouts, so
  admin "Add Member"/Edit User level changes and direct `pmpro_changeMembershipLevel()` calls are
  also hooked via `pmpro_after_change_membership_level` → `award_points_on_level_change()`, which
  builds a pseudo-order and reuses `award_registration_points()`. The `_dd_registration_points_awarded`
  user-meta guard inside that method keeps this idempotent (no double-award on real checkouts where
  both hooks fire); level `0` (cancellation/expiry) is ignored.
- **Dynamic pricing table** (`pmpro-dynamic-pricing.php`, `DD_PMPro_Frontend_Pricing`) — the
  `[dd_pricing_table]` shortcode renders a Monthly/Yearly toggle pricing UI, auto-pairing each base
  level with its "Annual" Payment Plan extension, disabling owned/pending-downgrade plans, and
  locking plan changes during free trials (both in the UI and via a `template_redirect` URL guard).
  Also rewrites the native PMPro checkout DOM (`modify_checkout_plans_dom`,
  `influencer_style_pmpro_checkout`) into the influencer look.
  > Gotcha: the "billing starts on" date (`calculate_billing_start_date()`) must be derived from
  > `trial_limit`/`cycle_number`/`cycle_period` (populated when a discount code applies a Custom
  > Trial) rather than `profile_start_date`, which is only set by the Subscription Delays Add On and
  > goes stale once a trial-bearing discount code is used. Discount codes are applied **client-side
  > via AJAX after page render**, so the initial server-rendered date can't see a code-driven trial;
  > the checkout JS re-fetches it from `wp_ajax_dd_get_trial_start_date`
  > (`ajax_get_trial_start_date()`, nonce `dd_trial_start`) whenever the applied discount code
  > changes — detected via `ajaxComplete` on any pmpro/discount request (ignoring its own
  > `dd_get_trial_start_date` calls) plus a 1s poll comparing against the last-synced code — and
  > patches the `.dd-start-date` span. The applied code is read by `ddGetAppliedDiscountCode()`,
  > which scans non-button/checkbox/radio inputs whose name/id contains "discount" for a held value
  > (the block checkout doesn't use PMPro's classic field names), falling back to parsing an
  > "applied" confirmation message's text if no input holds a value. The refresh call itself is
  > guarded against a slow/blocked network: only one request in flight at a time, an 8s timeout, and
  > sync disables itself after 3 consecutive failures (`ddStopSync`) rather than piling up hung
  > requests. Server-side, `ajax_get_trial_start_date()` prefers reading the code's trial straight
  > from `{$wpdb->prefix}pmpro_discount_codes_levels` (`get_discounted_level_pricing()`), since
  > `pmpro_getLevelAtCheckout()` can silently drop the trial depending on validation context (use
  > limits, login state); it falls back to `pmpro_getLevelAtCheckout()` then plain `pmpro_getLevel()`.
- **Trial abuse protection** (`pmpro-trial-protection.php`, `DD_PMPro_Trial_Protection`) —
  fingerprints Stripe payment tokens to block repeat free trials, lets users opt out of a trial
  (forcing full payment via `pmpro_checkout_level` filters), and enforces the one-time Subscription
  Delay.
- **AJAX signup** (`pmpro-sign-up.php`, `DD_PMPro_Ajax_Signup`) — extends PMPro registration with an
  avatar upload field and a terms-acceptance checkbox.
- **Elementor** (`elementor.php`) — registers **custom query IDs** consumed by Loop widgets via
  `add_action('elementor/query/{id}', …)`: `recently_view_influencers`, `saved_lists`,
  `unlocked_influencers`, `current_user_posts`, `featured_influencers`. Also adds a **"MyCred
  Visibility"** control to *every* Elementor element (show/hide by points balance) and suppresses
  the parent header/footer on the dashboard template and influencer singles.
- **ACF** (`acf.php`) — populates header colour select fields from **Elementor global colours**,
  and the `members_only` field gates page access (enforced in `hooks.php`).
- **Dompdf** (`dompdf.php`) — `Dompdf_Service` singleton wrapping the manually-vendored library
  for server-side PDF generation.

### Access control & page gating (`includes/core/hooks.php`)

- Non-logged-in users hitting a `members_only` page or any single `influencer` are redirected to
  the configured login page (`dd_login_redirect_page_id`).
- `wp_head` emits a dynamic `<style id="custom--css">` block that conditionally hides dashboard
  widgets/stats based on the user's data and membership, and switches the search layout between
  "full brief" and "filtered" modes from the `?search-brief` query var.
- Subscribers have the admin bar hidden; a devtools/right-click blocker is injected on influencer
  singles for non-admins (cosmetic deterrent only — trivially bypassable).

## Conventions & gotchas

- **Prefixes:** `dd_` (Digitally Disruptive) for this theme's PHP functions/options/hooks;
  `creatordb_` for companion-plugin functions; `influencer_*` shortcode names; `inf-*` JS enqueue
  handles; `InfluencerApp.*` JS methods.
- **Rendering style:** PHP render functions use output buffering (`ob_start()` … `return
  ob_get_clean()`); inline `<style>`/`<script>` blocks are emitted directly from render functions
  and hooks rather than living in `style.css`/JS files. New UI should follow the surrounding
  convention rather than introducing a build step.
- **Editing the theme vs. editing pages:** changing PHP rarely changes what users see — most
  layout lives in Elementor templates referenced by the `dd_tpl_*` options. To change a card or
  page, you usually edit the Elementor template (deep-linked from the admin-bar "Theme Editor"
  menu), not PHP.
- **Charts no-data fallback:** every `DD_Follower_Growth_Chart` render path returns
  `render_no_data_fallback()` (which injects the `dd_tpl_no_data_fallback` Elementor template, with
  a hardcoded inline-`<div>` fallback if that template is empty) instead of an empty chart card when
  follower history is missing/unusable. The `.dd-time-filters` time-range tabs are shared chart
  chrome — keep their markup/CSS unified rather than re-inlining per chart.
- **reCAPTCHA v3 inside Elementor Popups:** the outreach form lives in an Elementor Popup, where
  Elementor's bundled reCAPTCHA v3 handler does not reliably regenerate a token. `DD_Outreach_Manager::inject_recaptcha_popup_fix()` (`wp_footer`) intercepts that single form's submit, reads the
  site key from the enqueued `recaptcha/api.js?render=…`, fetches a fresh token via
  `grecaptcha.execute()`, then submits — failing open (never blocks) if reCAPTCHA is unavailable.
- There is a stray `gitignore` file (no leading dot) alongside the real `.gitignore`.
