# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A **WordPress child theme** for an influencer-discovery / marketing SaaS, built on the
**Hello Elementor** parent theme. The theme is the presentation + application layer; almost
all page UI is authored in **Elementor templates** (stored in the database, referenced by ID),
and PHP renders them with `do_shortcode('[elementor-template id="…"]')`.

> The `readme.txt` and theme header are leftover Hello-Elementor-Child boilerplate — ignore
> them as documentation of this project. The real entry point is `functions.php`.

### Companion plugins (most important architectural fact)

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

A second companion plugin, **ICDH** (Influencers Club Data Handler), exposes `icdh_*` functions.
Metrics history for **Instagram, YouTube, and TikTok** is read through the theme helper
`trb_platform_history_rows($post_id, $platform)` (`includes/core/helpers.php`), which prefers the
platform-aware bridge `icdh_platform_history_display_rows($id, $platform)`, then the legacy
Instagram-only `icdh_instagram_history_display_rows($id)`, then the per-platform meta
(`{platform}_metrics_history`), then — Instagram only — the legacy `creatordb_history` post meta.
`trb_instagram_history_rows($id)` is a back-compat alias for the `instagram` platform.
Recent media (posts/videos) is read the same way through `trb_platform_recent_media($post_id, $platform)`,
which prefers `icdh_platform_recent_media($id, $platform)`, then the per-platform meta
(`youtube_recent_videos` / `tiktok_recent_posts`), then — Instagram only — the legacy `recentposts`
meta. Touch points: `grep -rn "function_exists('icdh" .`

Data comes from **two providers** — CreatorDB (`creatordb_*`) and Influencers.Club (`icdh_*`,
IC) — normalized by the plugin into the same namespaced keys/history-row shape regardless of
source (history rows carry a `provider` field). The one place provider matters in theme code is
availability: `trb_platform_has_data($post_id, $platform)` checks signals from **both** providers
(history rows, current-metric keys, provider-specific id/link keys) to decide whether an
influencer "has" a platform at all.

> `frontend-platform-metrics-handoff.md` (repo root) is a task-oriented reference for building
> Elementor blocks/shortcodes/graphs against YouTube/TikTok metrics — meta keys, history row
> shape, and shortcode attrs in table form. This file (CLAUDE.md) covers the same ground
> architecturally; consult the handoff doc for lookup-table detail.

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
must be preserved: core helpers → plan capabilities → admin settings → hooks → shortcodes →
third-party integrations → domain modules. Foundational helpers must load before the
integrations and modules that call them — `includes/core/plan-capabilities.php` in particular
must load before `admin-settings.php` (which renders its option fields) and before every module
that calls `dd_user_can()`.

### "Modules" are theme code, not installed plugins

Files under `modules/` carry `Plugin Name:` docblocks and even describe themselves as plugins,
but they are **`require`d by the theme**, never installed via wp-admin → Plugins. Each domain
module is a **singleton class instantiated at the bottom of its own file**:

| File | Class (instantiated at EOF) | Responsibility |
|------|------------------------------|----------------|
| `modules/frontend-utilities/search.php` | `Influencer_Search` | Search form, AJAX loop filter, brief parser |
| `modules/saves/saves-manager.php` | `Saves_Manager` | Saved/viewed influencers, saved searches, groups, group Export PDF; registers the activity CPTs |
| `modules/outreach/outreach.php` | `DD_Outreach_Manager` | Outreach submissions, master-detail dashboard, HTML email builder |
| `modules/frontend-utilities/charts.php` | `DD_Follower_Growth_Chart` | Multi-platform follower analytics charts (ApexCharts) via shortcodes + the `[platform_switcher]`/`[platform_panel]` shortcodes; time-filter tabs + no-data fallback |
| `modules/frontend-utilities/feeds.php` | `DD_Recent_Media_Feed` | Per-platform Recent Content feed (`[platform_recent_media]`) |
| `modules/email-manager/email-template-manager.php` | `DD_Global_Email_Manager` | Global transactional email layout |
| `modules/mycred-components/mycred-frontend-log.php` | `Custom_MyCred_Frontend_Log` | AJAX-paginated myCred points history table (`[custom_mycred_log]`) |

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
ID panels there is a **"Functionality" tab** for behavioural toggles. Four features are gated by
per-level checkbox lists rendered with `dd_render_pmpro_levels_checkboxes()` — `dd_export_pdf_allowed_levels`
("Export PDF Restriction"), `dd_outreach_allowed_levels` ("Contact / Outreach Restriction"),
`dd_saved_lists_allowed_levels` ("Saved Lists Restriction"), `dd_custom_outreach_message_allowed_levels`
("Custom Outreach Message Restriction") — plus a numeric-per-level field, `dd_search_limits`
("Creator Search Limit", rendered by `dd_render_pmpro_search_limits()`, blank/`-1` = unlimited).
These are all read through the capability layer (`includes/core/plan-capabilities.php`, see below)
rather than `get_option()` directly. When adding a new plan-gated feature, add it to
`dd_plan_feature_option_key()`'s map and register its checkbox field here rather than inventing a
bespoke option; a plain non-plan feature toggle still registers directly on the
`dd-theme-settings-functionality` page / `dd_functionality_section` and reads via `get_option()`.

A **"Platform Icons" tab** lets admins override the built-in Instagram/YouTube/TikTok SVG glyphs
with an uploaded image via `wp.media` (`dd_render_platform_icon_picker()`), stored as an attachment
ID in `dd_platform_icon_{instagram,youtube,tiktok}`. `trb_platform_icon_svg()`
(`includes/core/helpers.php`) checks this option first and returns an `<img>` instead of the inline
SVG when set — everywhere that reads through this helper (switcher, `[platform_text]`,
`[platform_icon]`) picks up the override automatically, but a custom image does **not** recolor via
`currentColor` the way the built-in SVGs do.

> Caveat: integration files still contain **environment-specific magic page/level IDs** tied to
> the production site (e.g. `is_page(1551)` checkout, `4191` buy-credits, free PMPro level `15`;
> `influencer_style_pmpro_checkout()` also hides `.checkout-sidebar` specifically for level `9`).
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

Before any of that, a **per-plan creator-search cap** is enforced: only `paged === 1` (a genuinely
new search, not a "Load More" page) checks `dd_user_search_limit($user_id)` against the
`number_of_searches` user-meta counter and rejects with `{success:false, limit_reached:true,
upgrade_url}` if the cap is already met; `paged === 1` is also the only case that increments the
counter. `search-fetch.js` special-cases `response.data.limit_reached` to render an upgrade CTA in
place of the results container instead of treating it as a retryable/no-results error. Logged-out
users and any level with no configured limit (`dd_search_limits` empty/blank for that level) are
unrestricted (`dd_user_search_limit()` fails **open**, unlike the other capability checks below).
`Influencer_Search::enforce_search_page_limit()` (`template_redirect`) mirrors this same check on
plain page loads of the search/search-results pages — a logged-in user already at/over their cap
is redirected to `dd_plan_upgrade_url()` before the page even renders, not just blocked on AJAX
submit.

Country meta is stored as **ISO alpha-3** (e.g. `GBR`); `helpers.php` has alpha-3→alpha-2 and
country-name→alpha-2 maps for flags and matching. Filter dropdown option lists
(countries/languages/genders) are built by direct `$wpdb` queries and **cached in transients**
that are flushed on `save_post`/`delete_post` of an influencer.

On the **filtered search** form (`filtered-search` block in `search.php`), Location is the
required field (`required-on-search` class + JS validation in `filter-validation.js`) and
Hashtags Used sits in the main filter row; Niche has been moved into Advanced filters. Keep
the markup, the `required-on-search`/`field-required` classes, and the validation message text
in sync if this layout changes again.

The hashtag (`content_tag`) typeahead AJAX handler over-fetches 100 candidates by name
(`name__like`) rather than querying only `$limit`, then re-ranks them client-independent in PHP —
exact match first, then starts-with, then word-boundary substring, then any substring — before
slicing to `$limit`. An exact-match term is force-merged into the candidate set even if it would
otherwise fall outside the first 100 alphabetically. Do this ranking in the AJAX handler, not by
changing the `get_terms()` `orderby`, since plain alphabetical order can't express relevance.

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
  `creatordb_last_updated`, etc. (Instagram/primary-platform) — plus taxonomies `niche` / `topic` /
  `content_tag` / `platform`. YouTube adds `youtube_subscribers`, `youtube_engagement_rate`,
  `youtubeid`/`youtube_id`, `youtubename`, `ic_youtube_link`; TikTok adds `tiktok_followers`,
  `tiktok_engagement_rate`, `tiktokid`/`tiktok_username`, `ic_tiktok_link` — these are
  **current-snapshot** fields, read via `platform=` on the stat shortcodes (`[influencer_followers
  platform="youtube"]`, etc. — no `platform=` = today's flat/Instagram behaviour, unchanged).
  Multi-platform history is accessed via `trb_platform_history_rows($post_id, $platform)` (see
  above); **YouTube subscriber counts are stored under the `followers` history key** — label them
  "Subscribers" in the UI via `trb_platform_metric_noun('youtube')`. Use
  `trb_instagram_history_sort_asc()` to sort rows — do not inline `usort` on the raw history array.
- **User activity** is modelled as custom post types, **registered by `Saves_Manager`**:
  `saved-influencer`, `viewed-influencer`, `saved-search` (the `outreach` CPT is provided
  externally). These store an `influencer_id` meta linking back to the influencer; helpers in
  `helpers.php` (`get_saved_influencer`, `get_viewed_influencer`, `get_outreach`, with optional
  `this_month_only`) query them, often via direct `$wpdb` for performance.
- **Unlocks** (paying to reveal a profile) are derived from the **myCred `buy_content` log** plus
  a `dd_unlocked_influencers` user-meta array — see `is_influencer_unlocked()` /
  `get_user_purchased_post_ids()`.

### Plan capability gating (`includes/core/plan-capabilities.php`)

A central capability layer generalizes what used to be a one-off Export PDF check. `dd_user_can($feature,
$user_id = null)` maps a feature key to its per-level allowed-levels option (via
`dd_plan_feature_option_key()`) and checks the user's current PMPro level against it — **fail-closed**:
an unrecognized feature, inactive PMPro, logged-out user, or an empty allowed-levels option (nobody
checked in the Functionality tab) all resolve to `false`. Four features currently register this way:
`export_pdf`, `outreach`, `saved_lists`, `custom_outreach_message` (see the settings section above for
their option names/labels). `dd_plan_upgrade_url()` (→ `pmpro_url('levels')`, falling back to `home_url()`)
is the shared "upgrade your plan" CTA destination used wherever a gate blocks a user. A sibling function,
`dd_user_search_limit($user_id = null)`, reads the separate `dd_search_limits` per-level numeric option and
**fails open** (`-1` = unlimited) rather than closed — see the search pipeline section above.
`dd_searches_remaining($user_id = null)` builds on it for display purposes — `dd_user_search_limit()` minus
the `number_of_searches` counter, floored at 0 — and returns `null` for unlimited plans or logged-out users
so callers render nothing rather than a bogus number. The `[searches_remaining template_id="…"]` shortcode
(`shortcode_searches_remaining()`, `includes/core/shortcodes.php`) wraps it: normal case prints "N search(es)
remaining" (pluralized), and once remaining hits 0 it swaps in the given Elementor template instead (e.g. an
upgrade nudge) if a `template_id` was supplied, otherwise still prints "0 searches remaining". Wrapper widget
`Widget_Searches_Remaining` (`sc_searches_remaining` / "Searches Remaining") exposes the same `template_id`
as a template-picker Content control.

Every gate follows the same **UI-hint + server-boundary** pattern — never trust the client-side cue alone:
- **Export PDF** (`Saves_Manager::user_can_export_pdf()`, now a thin wrapper around `dd_user_can('export_pdf')`) —
  the result is passed to the group modal as `data-*` attributes; the button is only rendered for non-empty
  groups, and `saves-manager.js` routes disallowed users to the upgrade URL instead of triggering the
  `creatordb_export_saved_list_pdf` AJAX. The PHP check remains the real boundary.
- **Outreach** (`modules/outreach/outreach.php`) — `.outreach-form-trigger` is hidden via inline CSS in
  `hooks.php`'s `action_wp_head()` when `!dd_user_can('outreach')`; `render_outreach_contact_button()` grew an
  `$upgrade_locked` param so an *unlocked* creator whose viewer lacks outreach access shows an "Upgrade to
  contact" CTA (routes to `dd_plan_upgrade_url()`) instead of the generic "unlock first" hint; and
  `process_elementor_form_response()` independently rejects the AJAX submission server-side if
  `!dd_user_can('outreach', $current_user_id)`, regardless of what the button showed.
- **Custom outreach message** — non-Growth users get the message textarea visually locked
  (`pointer-events: none`, dimmed, "Upgrade to Growth…" caption) via inline CSS in the same file; the *real*
  enforcement is server-side — `process_elementor_form_response()` only substitutes the user's raw typed
  message for the composed default template when `dd_user_can('custom_outreach_message', $current_user_id)`
  is true and the field isn't blank, so a bypassed/edited field is silently discarded otherwise.
- **Saved lists** (`Saves_Manager`) — `render_save_button()`/equivalent returns a disabled "Upgrade your plan
  to save creators" CTA in place of the normal save-to-list button when `!dd_user_can('saved_lists')`
  (checked *before* the unlock-state branch, so it wins even for already-unlocked creators); the
  `save_influencer`/group-management AJAX handlers reject with `{message, upgrade_url}` independently.

### Third-party integrations (`includes/integrations/`, `modules/membership-extensions/`)

- **PMPro** (`pmpro.php`) — membership is the access spine: enforces a single active level,
  prorates initial payment on plan switches (`pmpro_checkout_level`), redirects a user completing
  checkout for the Free Level (15) straight to the pricing page, adds first/last-name checkout
  fields, restyles the member profile into tabs, and customizes login/logout redirects. A
  `pmpro-level-{slug}` body class is added for CSS gating (`.hide-on-free-trial`, etc., toggled in
  `hooks.php`). `dd_force_free_members_to_upgrade()` used to also blanket-redirect Free members off
  every Dashboard-template page (search, unlocked-influencers, dashboard, etc.); that lockout was
  removed — Free/trial members may now use those pages, with access capped instead by the per-level
  creator-search limit (see below).
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
  `[dd_pricing_table order="…"]` shortcode renders a card per paid signup level
  (`get_orderable_plans()`, a public static method, excludes free/£0 levels, e.g. the Trial tier,
  by checking `initial_payment`/`billing_amount`), auto-pairing each with its "Annual" Payment Plan
  extension **when one is configured** — a level with no Annual plan still gets a card, just
  monthly-only (`annual_plan` is `false`, and `build_pricing_card()` hides the Yearly toggle and
  leaves the `data-price-annual`/`data-url-annual` attrs empty rather than excluding the level
  entirely). Default card order follows the admin's drag-and-drop order on the PMPro **Membership
  Plans** settings screen (`get_level_group_order()`: PMPro Level Groups `displayorder`, then each
  level's `displayorder` within its group), not raw level-ID order — falls back to level-ID order
  if the Level Groups tables don't exist (PMPro < 3.0 / groups unused). The `Widget_Pricing_Table`
  Elementor widget (`elementor-widgets/class-widget-pricing-table.php`) can override that default
  via a Content-tab **Plan Order** repeater — seeded from `get_orderable_plans()`, drag-reorderable,
  one row per plan — which the widget serializes to the shortcode's `order` attr (comma-separated
  level IDs) as `$preferred_order` into `get_dynamic_plan_pairs($preferred_order)`; any plan absent
  from that order (e.g. newly added after the widget was last saved) is appended at the end so new
  plans always render. The widget also exposes a responsive Style-tab **Columns** control
  (`{{WRAPPER}} .dd-pricing-container` `grid-template-columns`). Cards also disable owned/pending-
  downgrade plans, and lock plan changes during free trials (both in the UI and via a
  `template_redirect` URL guard).
  Also rewrites the native PMPro checkout DOM (`modify_checkout_plans_dom`,
  `influencer_style_pmpro_checkout`) into the influencer look. The summary card header
  prominently shows the **amount due today** (`dd-due-today-val`), not the recurring price; the
  recurring price is stored in a hidden `.membership-amount` span (`display:none`) for JS access.
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
  `trb_` for theme-defined helper wrappers (e.g. `trb_platform_history_rows`, `trb_platform_has_data`,
  `trb_instagram_history_rows` as its Instagram-only alias);
  `creatordb_` for CreatorDB companion-plugin functions; `icdh_` for ICDH companion-plugin
  functions; `influencer_*` shortcode names; `inf-*` JS enqueue handles; `InfluencerApp.*` JS methods.
- **Rendering style:** PHP render functions use output buffering (`ob_start()` … `return
  ob_get_clean()`); inline `<style>`/`<script>` blocks are emitted directly from render functions
  and hooks rather than living in `style.css`/JS files. New UI should follow the surrounding
  convention rather than introducing a build step.
- **Editing the theme vs. editing pages:** changing PHP rarely changes what users see — most
  layout lives in Elementor templates referenced by the `dd_tpl_*` options. To change a card or
  page, you usually edit the Elementor template (deep-linked from the admin-bar "Theme Editor"
  menu), not PHP.
- **Charts no-data fallback:** if a chart shortcode's post has *no* platform data at all
  (`get_available_platforms()` empty), the shortcode returns `render_no_data_fallback()` outright —
  the full-page fallback (injects the `dd_tpl_no_data_fallback` Elementor template, with a hardcoded
  inline-`<div>` fallback if that template is empty) instead of a chart card. The rendered HTML is
  memoized per-request (`get_no_data_fallback_html()`) since a single influencer page embeds this
  same markup as the empty state for every platform's chart card. When the post has *some* platform
  data but the currently-selected platform's series carries no real information — fewer than 2 real
  points, or no variation among them (a flat line, a single non-zero total, min==max, etc.) — each
  `prepare_*_chart_data()` function (`prepare_timeline_chart_data()`, `prepare_growth_rate_chart_data()`,
  `prepare_monthly_chart_data()`, `prepare_like_range_data()`) computes this itself and returns it as a
  `has_data` boolean in its payload; the JS render callback for each chart just checks
  `payload.has_data` rather than re-deriving emptiness from the series shape. Keep new sufficiency
  rules there, not in JS — the JS only decides what to do with the flag. Specifics: monthly requires
  snapshots landing in ≥2 distinct months *inside* the rendered 12-month window (older snapshots only
  seed the carried-forward starting total, so don't count) with a non-zero gain somewhere; growth rate
  requires ≥2 points with at least one non-zero rate (the first point is always a synthetic 0%);
  timeline and like-range require ≥2 points that aren't all identical. Every chart instead renders
  both states up front inside a `.dd-chart-shell#dd{Monthly,Timeline,GrowthRate,LikeRange}Shell` wrapper —
  a `.dd-chart-body` (the live chart markup) and a `.dd-chart-fallback` (the same
  `get_no_data_fallback_html()` markup, `display:none` initially) — and a per-shortcode
  `ddToggleFallback(shellId, isEmpty)` JS helper flips which one is visible from inside the chart's
  `ddChartPayload`-render callback. This lets the fallback react to `[platform_switcher]` clicks
  without a page reload, unlike the server-side full-page fallback above. The `.dd-time-filters`
  time-range tabs are shared chart chrome — keep their markup/CSS unified rather than re-inlining per
  chart.
- **Chart post ID in Elementor context:** Elementor may not set `global $post` when rendering a
  widget outside the main query. `DD_Follower_Growth_Chart` resolves the post ID via
  `resolve_chart_post_id()` which tries `get_the_ID()`, `global $post`, then `get_queried_object_id()`
  in order. Use this pattern (or `trb_platform_history_rows()`) rather than reading `$post->ID`
  directly in chart/shortcode code.
- **Platform switcher drives the whole page, not just charts:** `enqueue_scripts()` localizes
  `ddChartPayload` (keyed by platform, only platforms `trb_platform_has_data()` confirms) plus a
  global `window.ddPlatformSwitcher` controller (`register(fn)` / `set(platform)` / `get()`) onto
  the `apexcharts` handle. Each chart shortcode registers a callback that destroys/recreates its
  ApexCharts instance from `ddChartPayload[platform]` — never `updateSeries()` in place, since
  dataset shape differs across platforms. **`set(platform)` itself does not bail when
  `ddChartPayload[platform]` is missing** (only when `platform` is falsy) — it still switches
  `active` and fires every listener, so each chart callback must null-check its own payload entry
  and show the no-data fallback rather than assuming the switcher already filtered it out; otherwise
  the previous platform's chart is left on screen. `[platform_switcher]` renders one button per available
  platform and calls `ddPlatformSwitcher.set(platform)` on click, which toggles every
  `.dd-platform-panel[data-platform="…"]` block (wrap platform-specific content in
  `[platform_panel platform="youtube"]…[/platform_panel]`) and, via `ddPlatformMeta[platform] =
  {label, icon}`, rewrites every `.dd-platform-name`/`.dd-platform-icon` span on the page (used by
  the `[platform_text]`/`[platform_icon]` shortcodes — thin wrappers around
  `render_platform_text_shortcode()`/`render_platform_icon_shortcode()` in charts.php, reactive
  only on pages that also have a switcher). All chart shortcodes, `[platform_switcher]`,
  `[platform_text]`, `[platform_icon]` accept `id="123"` to target a specific post instead of
  `resolve_chart_post_id()`'s current-post inference; chart shortcodes also take an initial
  `platform=` attr, and `[platform_switcher platforms="instagram,youtube"]` restricts which buttons
  render. `trb_platform_label()`, `trb_platform_icon_svg()`, `trb_platforms_available($post_id,
  $candidates)` (validated against `trb_platform_has_data()`), and `trb_platform_default($post_id)`
  (Instagram if available, else first available, else `''`) in `includes/core/helpers.php` are the
  single source of truth all of the above reads from — keep chart, switcher, panel, text, and icon
  logic on these same helpers so they never disagree about which platforms exist. Icon sizing is
  CSS-custom-property driven rather than hardcoded: `[platform_switcher icon_size=".."]` and
  `[platform_text icon_size=".."]` set `--dd-sw-icon-size`/`--dd-pt-icon-size` inline (falling back
  to the existing hardcoded defaults), and their Elementor widgets expose the same via a Style-tab
  `icon_size` `SLIDER` control — follow this pattern (attr → CSS var with a default fallback) rather
  than branching PHP on the value. `[platform_icon size=".."]` (note: `size`, not `icon_size`) is
  the exception — it's a standalone glyph with no paired text, so it sets inline `font-size` directly
  (the SVG/`<img>` is `1em` square) rather than going through a CSS var; its widget likewise exposes
  one Style-tab `icon_size` `SLIDER` control that maps to the shortcode's `size` attr. **Text
  sizing/typography is real Elementor typography, not a slider:** both widgets
  (`class-widget-platform-switcher.php`, `class-widget-platform-text.php`) register a
  `\Elementor\Group_Control_Typography` Style-tab control (font family/size/weight/style/decoration/
  transform/line-height/letter-spacing) targeting `{{WRAPPER}} .dd-platform-btn .dd-platform-label`
  and `{{WRAPPER}} .dd-platform-text-label` respectively — Elementor emits the CSS itself, so the
  widget's `render()` never reads or forwards a text-size value. The underlying shortcodes still
  accept a `text_size=".."` attr (→ `--dd-sw-text-size`/`--dd-pt-text-size`, same CSS-var/fallback
  pattern as icons) for non-Elementor callers; the two mechanisms don't conflict because the
  Typography rule targets the more specific inner label element and simply wins over the inherited
  CSS-var value when an admin has actually set it. `class-widget-platform-switcher.php`'s Style tab
  also has the same Button Padding / Border Radius / Text Color / Background Color /
  `Group_Control_Border` (Normal+Hover) controls as the Social Links widget below, targeting
  `.dd-platform-btn`. **Gotcha:**
  unlike Social Links, `render_platform_switcher_shortcode()`'s own `<style>` block hardcodes the
  button look with a 2-class selector (`.dd-platform-switcher .dd-platform-btn`) — the same
  specificity Elementor's `{{WRAPPER}} .dd-platform-btn` would generate, so source order (not
  specificity) would decide the winner. The widget's selectors therefore include the extra
  `.dd-platform-switcher` ancestor class (`{{WRAPPER}} .dd-platform-switcher .dd-platform-btn`) to
  reliably outrank the shortcode's own CSS regardless of print order — keep that 3-class form for any
  new Style-tab control here rather than copying the shorter 2-class pattern used elsewhere. These
  controls apply to Normal/Hover tabs and (via the 3-class selector) still outrank the shortcode's
  own CSS when an admin sets them. Left unset, the shortcode's **default hover now matches `.active`**
  (both `background`/`border-color: var(--e-global-color-primary, #034146)`, via a shared
  `.dd-platform-btn:hover, .dd-platform-btn.active` rule) rather than the old light-gray hover. The
  widget's second tab is now labeled **"Hover / Active"** and its three controls (Text Color, Background
  Color, Border) target `:hover, .active` together via a combined selector — so setting them in Elementor
  styles the active button too, not just hover; there is no separate Style-tab state for `.active` alone.
- **`[platform_social_links id="0" platforms="" icon_size="" show_label="yes" layout="vertical"]`**
  (`charts.php`, widget `Widget_Social_Links`/`sc_social_links`, titled **"Influencer Social Platforms"**
  in Elementor) renders one clickable row (icon + handle, linking out in a new tab) per available
  platform, all at once — like the combined cross-platform stat shortcodes, it deliberately does
  **not** react to `[platform_switcher]`. The per-platform URL/handle resolution lives in
  `trb_platform_social_link($post_id, $platform)` (`includes/core/helpers.php`). Instagram
  (`instagramid`) and TikTok (`tiktok_username`/`tiktokid`) read the same identity meta
  `trb_platform_has_data()` already checks, so "available" always yields a link. **YouTube does not**
  — `trb_platform_has_data()` treats `youtubeid`/`youtube_id`/`youtubename` as sufficient identity
  signal, but those are typically all CreatorDB populates, with no true `@handle` (`youtube_custom_url`/
  `youtubedisplayid` are IC-sourced and often empty on CreatorDB influencers). So YouTube resolves in tiers:
  a real handle (`youtube_custom_url`/`youtubedisplayid`) → `@handle`, linking to `ic_youtube_link` or
  `youtube.com/@handle`; else a stored `ic_youtube_link` labeled with `youtubename`; else the channel ID
  (`youtubeid`/`youtube_id`) linking to `youtube.com/channel/{id}`, labeled with `youtubename` or the raw ID.
  Only the first tier gets an `@`-prefixed label — the others display a channel name/ID as-is rather than
  fabricate a handle. Returns `null` (row skipped) only when none of that resolves. **Gotcha:** each row's
  glyph wrapper uses a distinct `.dd-social-icon` class rather than the reactive `.dd-platform-icon` — the
  switcher controller rewrites *every* `.dd-platform-icon` on the page to the active platform's icon (even
  on first paint, via its default `set()` call in `enqueue_scripts()`), so sharing that class would collapse
  every row to the same icon. Same reasoning as `.combined-stat` vs `.platform-stat` above. `show_label="no"`
  drops the handle `<span>` from the DOM entirely (not just CSS-hidden) for an icons-only row, adding an
  `aria-label` to the anchor so it keeps an accessible name; the widget exposes this as a Style-tab
  "Show Handle" `SWITCHER` control. `layout="horizontal"` sets `--dd-sl-direction:row` on the
  `.dd-social-links` wrapper (default `column`), same attr→CSS-var-with-fallback pattern as icon/text
  sizing elsewhere; the widget exposes this as a responsive `CHOOSE` control (`Vertical`/`Horizontal`)
  whose selector sets `flex-direction` directly rather than going through the CSS var, so Elementor's
  choice always wins over the shortcode default regardless of source order. The widget's Style tab adds
  a responsive Border Radius control (shared across states) plus Text Color / Background Color /
  `Group_Control_Border` under Normal/Hover tabs (`{{WRAPPER}} .dd-social-link` / `:hover`) — pure
  Elementor-emitted CSS like Typography/Box Padding, no `render()` changes needed. A `.dd-social-link` base
  CSS transition (`platform_social_links_styles()`)
  smooths the hover state regardless of which colors an admin sets.
- **Stat shortcodes switch live too, with no Elementor changes:** the snapshot shortcodes
  (`[influencer_followers]`, `[influencer_avglikes]`, `[influencer_avgcomments]`, `[influencer_posts]`,
  `[influencer_engagerate]`, `[influencer_follower_growth]` — all in `includes/core/shortcodes.php`)
  wrap their value in `<span class="platform-stat" data-metric="…">` via `trb_wrap_platform_stat()`.
  `enqueue_scripts()` localizes a parallel `ddPlatformStats[platform][metric]` map (built by
  `trb_build_platform_stats_map()`) alongside `ddChartPayload`; `ddPlatformSwitcher.set()` rewrites
  every `.platform-stat[data-metric]` span's text from it on each click. `trb_platform_stat_metric_map()`
  is the source of truth for the five snapshot metrics; `follower_growth` is computed separately via
  `trb_platform_follower_growth_display($post_id, $platform)` (shared by the shortcode and the map
  builder). On the **Instagram** entry, a metric missing for the target platform is simply omitted,
  leaving that span's current text untouched (unchanged default behavior). For **any other platform**,
  `trb_build_platform_stats_map()` instead emits an explicit `''` for a missing metric, so
  `ddPlatformSwitcher.set()` blanks the span rather than leaving a stale Instagram value showing, and
  `hideEmptyData()` can then collapse its `.influencer-data-parent` wrapper. Inert wherever no switcher
  exists (search cards, group rows).
  > Gotcha: `trb_resolve_platform_stat_raw()` also returns `''` outright for an **explicit non-Instagram
  > platform** whose metric has no platform-specific meta key and no matching history field — `posts` is
  > the case that matters, since there's no `{platform}_posts` key and no post-count field in
  > YouTube/TikTok history. Without this guard the flat `posts` meta (which tracks the influencer's
  > primary platform) would leak through and display as if it were that platform's count.
  > Gotcha: the flat/namespaced current-metric meta keys (`youtube_subscribers`, `tiktok_followers`,
  > `youtube_engagement_rate`, `tiktok_engagement_rate`) are **not reliable** — CreatorDB-sourced
  > influencers often never populate them (only `{platform}_metrics_history` arrays), and neither
  > provider reliably populates the two `*_engagement_rate` keys. The flat `followers`/`engagerate`/
  > `avglikes`/`avgcomments` fields also aren't safely "Instagram" — they track whichever platform is
  > that influencer's `primary_platform`. So whenever an **explicit** platform is requested (a
  > `platform=` attr, or `trb_build_platform_stats_map()`, which always passes one),
  > `trb_resolve_platform_stat_raw()` (`includes/core/shortcodes.php`) prefers the latest row of that
  > platform's own history via `trb_platform_current_metric_from_history()`, falling back to the
  > meta-key lookup only when that platform has no history. Bare shortcode calls with no `platform=`
  > attr are untouched. This is also why `trb_platform_history_rows()` normalizes rows read from raw
  > `{platform}_metrics_history` meta into the older `creatordb_history` shape (`timestamp_ms`/`date`/
  > `avglikes`/`avgcomments`/`engagerate`) — without it, history sourced directly from that meta (i.e.
  > whenever `icdh_platform_history_display_rows()` isn't installed) collapsed every point's date to
  > "now" and read likes/comments as 0. Keep new history consumers reading through
  > `trb_platform_history_rows()` rather than raw postmeta.
- **Combined cross-platform stat shortcodes are deliberately non-reactive:** `[influencer_total_followers]`,
  `[influencer_combined_engagerate]`, and `[influencer_combined_follower_growth]` (`includes/core/shortcodes.php`,
  all accept `id="123"`) sum/blend a metric across every platform `trb_platforms_available()` returns for the
  influencer — total followers, a follower-weighted engagement rate, and a blended ~1-month growth percentage
  (`trb_platform_follower_growth_display()` now also returns raw `latest_followers`/`past_followers` so this
  shortcode can sum them across platforms before computing one ratio, rather than averaging per-platform
  percentages). They wrap output in `trb_wrap_combined_stat()` (`<span class="combined-stat" data-metric="…">`),
  a distinct class/attribute from `trb_wrap_platform_stat()`'s `.platform-stat` — `data-metric` values here
  (`total_followers`, `combined_engagerate`, `combined_follower_growth`) are intentionally absent from
  `ddPlatformStats`, so `ddPlatformSwitcher.set()` never touches them; a platform switcher click must not change
  a total that spans all platforms. No Elementor widget wrappers exist for these yet — shortcode-only.
- **Hiding empty-data blocks:** `InfluencerApp.hideEmptyData()` (`assets/js/modules/hide-empty-data.js`,
  enqueued as `inf-hide-empty-data` with no module deps) toggles a `.dd-empty-hidden` (`display:none
  !important`) class on any `.influencer-data-parent` wrapper whose `.platform-stat`/`.combined-stat`
  descendants are *all* empty (blank, `-`, `N/A`, or numerically zero, incl. a leading `+`/`-` sign or
  trailing `%`). It runs once from `main.js`'s init chain and again from `ddPlatformSwitcher.set()`
  (`charts.php`) after every platform switch, so a block that's empty on Instagram but populated on
  YouTube reappears without a page reload. `.influencer-data-parent` is an Elementor-side wrapper class,
  not defined in theme code — add it in the template around any stat block that should collapse when empty.
- **Recent Content feed is panel-reactive, not payload-reactive** (`modules/frontend-utilities/feeds.php`,
  `DD_Recent_Media_Feed`, shortcode `[platform_recent_media platform="" id="0"]`, widget
  `sc_platform_recent_media` / **"Influencer Recent Content"**). Unlike the charts and stat spans — which
  re-render in JS from a localized payload — the feed server-renders **one `.dd-platform-panel[data-platform]`
  per available platform** (same markup contract as `[platform_panel]`) and lets `ddPlatformSwitcher.set()`'s
  existing panel loop toggle them. That keeps card markup in PHP with no JS duplicate to drift; it costs
  rendering every platform up front. An explicit `platform=` attr pins one platform and opts out of the
  switcher. Rows are read **only** through `trb_platform_recent_media()` — never raw meta, never `icdh_*`
  directly — and normalized by `trb_normalize_media_row()` into the plugin's own row shape
  (`id`/`url`/`title`/`likes`/`comments`/`views`/`engageRate`/`updateDate`/`isShorts`/`hashtags`).
  ICDH already emits that shape identically for **both** providers, so only Instagram's legacy `recentposts`
  needs mapping (`shortcode`→`id` + composed `/p/{shortcode}/` url, `isReels`→`isShorts`, `videoViews`→`views`).
  > **Gotchas, all load-bearing:** (1) **Never trust a YouTube row's `id`** — CreatorDB stores the real
  > 11-char video ID, IC stores a 32-char MD5 hash of its own; only `url` carries a usable ID on both, which
  > is why `trb_youtube_video_id_from_row()` parses the URL and treats `id` as a last resort. (2) **IC rows
  > carry `engageRate: 0` and `updateDate: null`**, so the third footer stat is *views* on YT/TT (an ER column
  > would read "0.00%" on every IC card) and the date line is dropped entirely rather than formatted into
  > "1970 Jan 1st". (3) **Available ≠ has media** — `trb_platform_has_data()` counts a bare `youtubeid`/
  > `tiktokid` as "has the platform", and CreatorDB influencers have **no** `tiktok_recent_posts` at all, so
  > every panel must handle empty independently (it renders `dd_tpl_no_data_fallback`, memoized per request).
  Each platform gets the only embed its data supports: Instagram and TikTok as native `embed.js` blockquotes
  (the normalized rows have no thumbnail field, and raw IC media URLs are expiring CDN links), YouTube as an
  `i.ytimg.com` thumbnail card (an iframe per card is far too heavy). **Gotcha:** TikTok's embed.js exposes
  no public re-scan API (unlike `window.instgrm.Embeds.process()`), so Load More re-injects the script tag to
  pick up appended blockquotes. Both embed scripts are enqueued only on `influencer` singles and only for
  platforms `trb_platforms_available()` confirms.
- **Sparse like-range history:** ICDH's `import_seed` backfill is only ~1 month deep, so the
  30-day default window can leave the like-range chart with 0–1 points. `prepare_like_range_data()`
  widens the default window to 365 days when the series has ≤3 points (`default_days`), and the
  front-end JS further widens the *selected* window to all available points if fewer than 2 fall
  inside it — keep both widenings in sync if you touch this chart.
- **reCAPTCHA v3 inside Elementor Popups:** the outreach form lives in an Elementor Popup, where
  Elementor's bundled reCAPTCHA v3 handler does not reliably regenerate a token. `DD_Outreach_Manager::inject_recaptcha_popup_fix()` (`wp_footer`) intercepts that single form's submit, reads the
  site key from the enqueued `recaptcha/api.js?render=…`, fetches a fresh token via
  `grecaptcha.execute()`, then submits — failing open (never blocks) if reCAPTCHA is unavailable.
- There is a stray `gitignore` file (no leading dot) alongside the real `.gitignore`.
- **Geo-IP currency shortcode:** `[currency]` (`shortcode_currency()` in `includes/core/shortcodes.php`)
  resolves the visitor's country via `dd_geolocate_country_code()` — client IP from
  `dd_get_client_ip()` (checks `CF-Connecting-IP`/`X-Forwarded-For`/`X-Real-IP` before
  `REMOTE_ADDR`), looked up against the free `ipapi.co` API and cached per-IP in a transient
  (a week on success, an hour on failure/rate-limit so an outage doesn't wedge lookups for a
  week). `GB`/`US`/`AU`/`CA` map to their ISO currency code (`GBP`/`USD`/`AUD`/`CAD`), and eurozone
  members plus euro-using microstates (e.g. `DE`, `FR`, `IE`, `ES`, `AD`, `MC`, `SM`, `VA`, `ME`, `XK`)
  map to `EUR`; everything else (including local/private IPs) falls back to `USD`. In `modules/outreach/outreach.php`,
  the outreach budget field runs `do_shortcode()` over the submitted `budget` value (dashboard
  detail view, HTML email builder, and `{budget}` email-template token) so a brand's `[currency]`
  placeholder resolves wherever the budget is displayed; the outreach form's select/radio/checkbox
  `field_options` (e.g. budget-range choices) are also expanded via `do_shortcode()` before
  rendering, since Elementor prints those option labels verbatim without running shortcodes.
