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
must be preserved: core helpers → plan capabilities → admin settings → messages settings → hooks
→ shortcodes → third-party integrations → domain modules. Foundational helpers must load before
the integrations and modules that call them — `includes/core/plan-capabilities.php` in particular
must load before `admin-settings.php` (which renders its option fields) and before every module
that calls `dd_user_can()`. `modules/membership-extensions/pmpro-company-trial.php` loads later,
alongside the other membership-extension modules — its coupling to `plan-capabilities.php`
(`dd_user_search_limit()` calling `dd_user_trial_restricted()`) and to `admin-settings.php` (whose
Personal Email Domains field placeholder calls `dd_default_public_email_domains()`) is
**runtime-only and `function_exists`-guarded in both directions**, so its require position isn't
load-bearing the way the others are.

### "Modules" are theme code, not installed plugins

Files under `modules/` carry `Plugin Name:` docblocks and even describe themselves as plugins,
but they are **`require`d by the theme**, never installed via wp-admin → Plugins. Each domain
module is a **singleton class instantiated at the bottom of its own file** — the one deliberate
exception is `modules/membership-extensions/pmpro-company-trial.php`, which stays a plain
procedural file of global `dd_*` functions (see below) rather than being wrapped in a class, since
every call site already reaches it through a `function_exists('dd_…')` guard.

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
with hardcoded integer fallbacks, and edited from the top-level **"Influencer Theme"** admin menu
page (`admin.php?page=dd-theme-settings`, `add_menu_page()` — it is **not** under Settings any
more). Always read them through the accessors:

```php
dd_get_page_id('dd_search_results_page_id', 1949);
dd_get_template_id('dd_tpl_search_card', 1839);
```

The admin page provides an AJAX post/template autocomplete, and an admin-bar menu deep-links each
configured page/template straight into the Elementor editor.

The settings screen is a **tabbed UI** (`.dd-tab-btn` / `.dd-panel`) — alongside the page/template
ID panels there is a **"Functionality" tab** for behavioural toggles. Other modules that used to
register their own standalone admin page (`add_options_page`/`add_submenu_page`) now instead
append a tab here via the `dd_theme_settings_tabs` filter — each entry is
`['id' => 'slug', 'label' => 'Tab Label', 'render' => callable]`; `render` prints its own
self-contained `<form action="options.php">…</form>` (its own `settings_fields()` group) into a
`<div class="dd-panel" id="dd-panel-{id}">` the hub renders for it — do not wrap it in
`class="wrap"`/`<h1>`, the hub already provides those. Four registrants currently use this way:
`DD_Global_Email_Manager` ("Email Templates" tab), `DD_PMPro_Rewards_Manager` ("Rewards" tab),
`DD_Feature_Comparison_Table` ("Pricing Tables" tab, id `comparison-table` — feeds **both** pricing
tables, see below), and
`includes/core/messages-settings.php` ("Messages" tab, a plain file, not a class — see below).
`DD_PMPro_Frontend_Pricing` used to register a fifth ("Pricing Settings"); that tab and its
`dd_desc_*`/`dd_cta_*` options were removed when the pricing table moved onto the shared grid.
Each of those modules' own admin JS/CSS must scope its selectors to its own
`#dd-panel-{id}` root (e.g. `$('#dd-panel-rewards').find('.nav-tab')`) rather than querying
`.nav-tab`/`.dd-tab-content` page-wide, since several modules' nested tab systems now share one
DOM; each module's `admin_enqueue_scripts`/`admin_footer` hook must also check for the
`toplevel_page_dd-theme-settings` screen/hook id, not their old page-specific one. When adding a
new module settings UI, prefer hooking `dd_theme_settings_tabs` over registering a new admin page.

**Editable user-facing messages** (`includes/core/messages-settings.php`, loaded right after
`admin-settings.php`) centralize the wording of notices/confirmations/tooltips that used to be
hardcoded strings scattered across PHP and JS. `dd_message_definitions()` is the single registry —
each entry has an option key (`dd_msg_*`), label, default text, settings-tab group, and optional
`js` (also localize to front-end), `multiline` (textarea), `html` (allow `<strong>`/`<em>`/etc. via
`wp_kses`) flags. Read a message server-side via `dd_get_message($key, $args = [])` (never
`get_option()` directly) — `$args` is `vsprintf`'d into `%s` tokens (e.g. the unlock-balance notice).
On the front end, `dd_js_messages()` returns only the `js`-flagged entries, localized as the
`dd_messages` global onto **both** `influencer-js` (`functions.php`) and `theme-saves-js`
(`Saves_Manager::enqueue_ajax_variables()`) — the same dual-localization split as `ajax_vars` above,
so a message needed on non-search pages must stay flagged `js` (it's picked up automatically, no
extra wiring). JS call sites read it defensively — `(typeof dd_messages !== 'undefined' &&
dd_messages.dd_msg_x) || 'hardcoded fallback'` — so pages where the handle hasn't localized yet still
work. Saving a field blank resets it to the registry default rather than persisting an empty string.
When adding a new user-facing string that a client might want to reword, add it to
`dd_message_definitions()` and read it through `dd_get_message()`/`dd_messages` rather than inlining
new copy.

Five features are gated by
per-level checkbox lists rendered with `dd_render_pmpro_levels_checkboxes()` — `dd_export_pdf_allowed_levels`
("Export PDF Restriction"), `dd_outreach_allowed_levels` ("Contact / Outreach Restriction"),
`dd_saved_lists_allowed_levels` ("Saved Lists Restriction"), `dd_custom_outreach_message_allowed_levels`
("Custom Outreach Message Restriction"), `dd_saved_search_allowed_levels` ("Saved Search Restriction")
— plus a numeric-per-level field, `dd_search_limits`
("Creator Search Limit", rendered by `dd_render_pmpro_search_limits()`, blank/`-1` = unlimited).
These are all read through the capability layer (`includes/core/plan-capabilities.php`, see below)
rather than `get_option()` directly. When adding a new plan-gated feature, add it to
`dd_plan_feature_option_key()`'s map and register its checkbox field here rather than inventing a
bespoke option; a plain non-plan feature toggle still registers directly on the
`dd-theme-settings-functionality` page / `dd_functionality_section` and reads via `get_option()`.

Two further Functionality-tab fields back the **one-trial-per-company rule**
(`modules/membership-extensions/pmpro-company-trial.php`): `dd_trial_levels` ("Trial Levels") is a level-ID checkbox
list sharing the same shape/sanitizer as the capability gates above but registered separately —
it's a level *classification*, not a `dd_user_can()` feature, so it is deliberately **not** in
`dd_plan_feature_option_key()`'s map; and `dd_public_email_domains` ("Personal Email Domains") is a
newline-per-domain textarea (falls back to a built-in default list when blank) naming domains that
are never treated as a shared "company". See the third-party integrations section below for the
rule itself.

A **"Platform Icons" tab** lets admins override the built-in Instagram/YouTube/TikTok SVG glyphs
with an uploaded image via `wp.media` (`dd_render_platform_icon_picker()`), stored as an attachment
ID in `dd_platform_icon_{instagram,youtube,tiktok}`. `trb_platform_icon_svg()`
(`includes/core/helpers.php`) checks this option first and returns an `<img>` instead of the inline
SVG when set — everywhere that reads through this helper (switcher, `[platform_text]`,
`[platform_icon]`) picks up the override automatically, but a custom image does **not** recolor via
`currentColor` the way the built-in SVGs do.

> The environment-specific magic page/level IDs that used to live directly in integration files
> are now settings: the checkout page check uses PMPro's own `pmpro_is_checkout()` (no setting
> needed — it reads PMPro's page registry); the buy-credits page is `dd_buy_credits_page_id`
> (default `4191`, also backing the `dd_get_buy_credits_url()` helper used wherever a
> `/buy-credit/` redirect is needed); the "Free" level is `dd_free_level_id` (default `15`,
> read by the checkout-confirmation redirect and the one-time subscription-delay exemption); and
> `influencer_style_pmpro_checkout()`'s checkout-sidebar hide is `dd_hide_checkout_sidebar_levels`
> (a level-ID checkbox list, default `[9]`). All four are on the Functionality/Page Assignments
> tabs alongside the existing settings. Four further page assignments exist with no current
> consumer in theme code — `dd_saved_lists_page_id`, `dd_saved_searches_page_id`,
> `dd_roi_calculator_page_id`, `dd_outreach_page_id` — added so those destinations are
> configurable/portable even though nothing reads them yet.

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
counter. `search-fetch.js` special-cases `response.data.limit_reached` to show a `ddConfirm()` modal
(message + "Upgrade your plan"/"Close" buttons, confirming navigates to `upgrade_url`) instead of
treating it as a retryable/no-results error or touching the results container. A successful search
response also carries `searches_remaining`/`searches_remaining_label` (via `dd_searches_remaining()`),
which `search-fetch.js` uses to live-update every `.dd-searches-remaining-value`/`.dd-searches-remaining-label`
span on the page — the Searches Remaining widget (see below) otherwise only reflects the count as of
page load. `search-fetch.js` also caches this count client-side (`known_searches_remaining()`, lazily
seeded from `ajax_vars.searches_remaining` — localized via `dd_searches_remaining()` in
`functions.php`'s `hello_elementor_child_scripts_styles()` — then kept fresh from each response) so
`influencer_search_trigger()` can pop the same limit dialog (`show_search_limit_popup()`, falling back
to `ajax_vars.search_limit_message`/`search_upgrade_url`) the instant a search button is clicked at 0
remaining, skipping the loading animation and AJAX round-trip entirely; the server-side
`limit_reached` check remains the authoritative fallback for a stale/unknown client-side count. Logged-out
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
checked in the Functionality tab) all resolve to `false`. Five features currently register this way:
`export_pdf`, `outreach`, `saved_lists`, `custom_outreach_message`, `saved_search` (see the settings
section above for their option names/labels). `dd_plan_upgrade_url()` (→ `pmpro_url('levels')`, falling back to `home_url()`)
is the shared "upgrade your plan" CTA destination used wherever a gate blocks a user. A sibling function,
`dd_user_search_limit($user_id = null)`, reads the separate `dd_search_limits` per-level numeric option and
**fails open** (`-1` = unlimited) rather than closed — see the search pipeline section above.
One deliberate override of that fail-open posture: if `dd_user_trial_restricted($user_id)`
(`modules/membership-extensions/pmpro-company-trial.php`) is true — a second trial signup from a company that already
claimed its one trial — `dd_user_search_limit()` returns `0` regardless of the user's level, so the
existing search-cap enforcement chain (AJAX gate, page-load redirect, `[searches_remaining]`) blocks
them with no separate limit-checking code path needed; the AJAX handler and the localized
`ajax_vars.search_limit_message` do each independently swap in the distinct `dd_msg_company_trial_block`
message (vs. the normal `dd_msg_search_limit`) when `dd_user_trial_restricted()` is true, so a
restricted user sees "your company already has a trial" wording rather than the generic cap message.
`dd_searches_remaining($user_id = null)` builds on it for display purposes — `dd_user_search_limit()` minus
the `number_of_searches` counter, floored at 0 — and returns `null` for unlimited plans or logged-out users
so callers render nothing rather than a bogus number. The `[searches_remaining template_id="…"]` shortcode
(`shortcode_searches_remaining()`, `includes/core/shortcodes.php`) wraps it: normal case renders the count and
the pluralized "search(es) remaining" text in separate `<span class="dd-searches-remaining-value">`/
`<span class="dd-searches-remaining-label">` tags (so each half can be styled independently), and once
remaining hits 0 it swaps in the given Elementor template instead (e.g. an upgrade nudge) if a `template_id`
was supplied, otherwise still renders the same two-span "0 searches remaining" markup. Wrapper widget
`Widget_Searches_Remaining` (`sc_searches_remaining` / "Searches Remaining") exposes the same `template_id`
as a template-picker Content control, plus a Style tab with separate `Group_Control_Typography` controls for
the value and label spans (targeting the two classes above).
A second, simpler consumer of the same `dd_searches_remaining()`/`dd_user_trial_restricted()` pair is the
`[account_notice show_button="yes"]` shortcode (`shortcode_account_notice()`, `includes/core/shortcodes.php`,
widget `Widget_Account_Notice` / `sc_account_notice` / "Account Notice") — a standalone dismissable-looking
notice box (not tied to a template swap) that renders nothing for logged-out users, unlimited plans, or
anyone with searches still remaining, and otherwise prints a message + upgrade CTA. It picks the same
`dd_msg_company_trial_block` vs. `dd_msg_search_limit` message as the AJAX/redirect gates based on
`dd_user_trial_restricted()`, plus a dedicated `dd_msg_notice_upgrade_cta` message for the button label — all
three editable under Influencer Theme → Messages. `show_button="no"` omits the upgrade CTA anchor entirely
(message-only notice); the widget exposes this as a Content-tab "Show Upgrade Button" `SWITCHER` control that
also conditions the Style tab's Button section (hidden when the button is off). The widget's Style tab covers
message typography/color, box background/border/radius/padding/margin, and button typography/padding/radius/colors
with separate Normal/Hover tabs. Inside the Elementor editor (`\Elementor\Plugin::$instance->editor->is_edit_mode()`),
`shortcode_account_notice()` skips the logged-out/unlimited/remaining-quota empty-state checks entirely so the
admin always sees the configured message/button while styling the widget, regardless of their own account state.

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
- **Custom outreach message** — Growth users edit **inline regions inside the message preview**
  itself, not a separate textarea. The admin's "Default Outreach Message" template
  (`dd_outreach_default_message` option, `get_default_outreach_message()`) can contain
  `<!--customise-->…<!--end-customise-->` marker pairs around any freeform sentence(s); everything
  outside those markers (intro/sign-off chrome, `{{fields}}`) is always the trusted server template
  and can never be edited. The `[outreach_message]` preview div (`render_outreach_message_shortcode()`)
  carries `data-can-edit="1"` only when `dd_user_can('custom_outreach_message')`; `outreach.js`'s
  `updateMessagePreview()` reads that flag and, only when true, swaps each marked region for a
  `contenteditable` `<span class="dd-editable">` (visually cued via dashed outline, not disabled/dimmed
  like the old textarea lock). Non-Growth users still see the same template rendered, just with the
  markers stripped and no editable spans — same read-only behavior as before, just no visible lock
  styling since there's no separate field to dim anymore.
  On every edit/change, `syncCustomRegions()` flattens each `.dd-editable` region's HTML back to
  plain text (`editableRegionText()` normalizes Chrome/Safari's per-line `<div>`s and Firefox's `<br>`s)
  and writes the ordered regions as a base64url-encoded JSON array into a hidden
  `dd_custom_regions` input that the JS itself finds-or-injects directly into the `outreach_form`
  (`findOutreachForm()`, same `input[name="form_id"][value="outreach_form"]` lookup pattern as
  `inject_recaptcha_popup_fix()`) — deliberately **not** an Elementor-managed `form_fields[...]`
  field, since those aren't guaranteed to exist or to round-trip a JS-set value on submit (this was
  the root cause of an earlier bug where edited regions were silently discarded). The sync runs on
  every `.dd-editable` `input`, on every tracked form field `change`/`input` (inside
  `updateMessagePreview()`), and once more on the form's `submit` event as a final guarantee.
  `editedRegions` snapshotting also runs before every DOM rebuild so an unrelated field change
  elsewhere in the form doesn't wipe in-progress typing. Server-side,
  `process_elementor_form_response()` reads `$_POST['dd_custom_regions']` directly (not through
  `$record->get('fields')`), only trusts it when `dd_user_can('custom_outreach_message',
  $current_user_id)` is true and it base64url-decodes to an array — it then
  `preg_replace_callback`s the *server's own* template's `<!--customise-->` regions in order,
  substituting the matching decoded (and `sanitize_textarea_field()`-sanitized) region or falling
  back to that region's original template text if the client sent fewer regions than the template
  defines. A bypassed/tampered carrier can therefore only ever affect content already inside those
  marked slots — it can no longer replace the whole message the way the old raw-textarea
  substitution did.
- **Saved lists** (`Saves_Manager`) — `render_save_button()`/equivalent returns a disabled "Upgrade your plan
  to save creators" CTA in place of the normal save-to-list button when `!dd_user_can('saved_lists')`
  (checked *before* the unlock-state branch, so it wins even for already-unlocked creators); the
  `save_influencer`/group-management AJAX handlers reject with `{message, upgrade_url}` independently.
- **Saved search** (`dd_saved_search_allowed_levels`, gates the `saved-search` CPT usage) — the
  "Save this search" trigger in `search.php`'s filtered-search form renders with a
  `save-search-locked` class + `data-upgrade-url` when `!dd_user_can('saved_search')`; `saves-manager.js`
  intercepts a click on that class with a `ddConfirm()` upgrade prompt instead of opening the naming
  modal. `Saves_Manager`'s `wp_ajax_save_search` handler (nonce `save_search_nonce`) independently
  rejects with `{message, upgrade_url}` before touching `$_POST['search_data']` if the check fails —
  same `{message, upgrade_url}` shape the JS `else if` branch on the save-search AJAX response expects.

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
  > **Anti-Ladder Protocol** (`dd_pmpro_switch_credit()`): the monetary credit for unused days on
  > an old level (used by `dd_pmpro_append_billing_cycle_on_switch()`'s upgrade-proration branches)
  > is capped by what the user **actually paid** for that level — `min(billing_amount, last order
  > total)` — not the level's nominal `billing_amount` alone. Without this cap a $0 trial or a
  > heavily-discounted signup could still earn a full-rate cash credit toward an expensive upgrade.
  > The daily rate is also computed against the old level's real cycle length (`cycle_period`/
  > `cycle_number`), not a hardcoded 30-day divisor.
- **myCred** (`mycred.php`) — credits/points: deduct/balance helpers, restyles the buy-credits
  checkout (`#buycred-checkout-form`) into the influencer look, a click-confirm gate before
  spending a credit (`mycred-buy-confirm.js`), and bank-transfer pending-notification handling.
  Also see `pmpro-mycred-rewards-manager.php` under membership-extensions.
- **Registration points on non-checkout level changes** (`pmpro-mycred-rewards-manager.php`,
  `DD_PMPro_Rewards_Manager`) — `pmpro_after_checkout` only fires for real front-end checkouts, so
  admin "Add Member"/Edit User level changes and direct `pmpro_changeMembershipLevel()` calls are
  also hooked via `pmpro_after_change_membership_level` → `award_points_on_level_change()`, which
  builds a pseudo-order and reuses `award_registration_points()`. The `_dd_registration_points_awarded_levels`
  user-meta guard inside that method (an array of level IDs, not a single flag) keeps this idempotent
  **per level** — a real checkout firing both hooks for the same level won't double-award, but a later
  upgrade to a *different* level still gets its own registration points instead of being silently
  blocked forever; level `0` (cancellation/expiry) is ignored.
  > **Anti-Ladder Protocol:** each level's configured "Registration Points" is a **per-account
  > target, not a stackable per-level bonus** — a user who upgrades through several levels only
  > ever gets topped up to the new level's tier value, never a fresh full bonus on top of what
  > they already have. `get_registration_credit_watermark()` reads the running total from
  > `_dd_registration_points_credited` user meta (lazily backfilled by summing `reg_points` for
  > every level in `_dd_registration_points_awarded_levels` for pre-existing accounts); the award
  > path computes `delta = max(0, tier_value - already_credited)`, only calls `mycred_add()` for
  > that delta (logged as an "Upgrade credit top-up" when `already_credited > 0`), and updates the
  > watermark meta afterward. A downgrade-then-reupgrade cycle therefore doesn't re-earn points
  > already banked.
- **Dynamic pricing table** (`pmpro-dynamic-pricing.php`, `DD_PMPro_Frontend_Pricing`) — this class
  no longer renders any markup of its own. It owns the **membership-state layer**; the pricing
  table's markup is the shared grid described under "Feature comparison table" below, rendered by
  `DD_Feature_Comparison_Table::render_table(['pricing_mode' => true, 'exclude' => […]])`. The
  `[dd_pricing_table exclude="col_key,…"]` shortcode is a thin delegate to it, and content (plan
  columns, feature rows, column order) comes from the one authored `dd_feature_comparison_table`
  option, **not** from `get_orderable_plans()`.
  Three public methods supply the per-column state:
  `get_membership_context()` (per-request memo of user id / on-trial / highest held tier price /
  pending-downgrade target — the renderer asks once per column and
  `get_pending_downgrade_level_id()` alone is several lookups), `get_plan_button_state($level_id,
  $annual_plan_id)`, and `get_trial_notice($level_id)`. `get_plan_button_state()` is the **single
  source of truth** for the button cascade, in this precedence: free trial → pending-downgrade
  target → leaving current plan → owns shown term (`CURRENT PLAN`) → owns other term
  (`SWITCH PLAN`) → `UPGRADE PLAN`/`DOWNGRADE PLAN`/`SELECT PLAN` (upgrade vs downgrade decided on
  base-price hierarchy via `get_user_max_tier_base_price()`). It returns `null` when the level can't
  be resolved, and the renderer then falls back to that column's authored static CTA — which is what
  you'll see on any environment where the PMPro levels aren't imported. The **yearly-toggle JS**
  (in `pmpro-comparison-table.php`, keyed off `data-action-verb` being present on the head cell)
  reproduces the same cascade client-side from `data-*` attrs, so **any change to the precedence or
  to a button string must be made in both places**.
  Only a `pmpro`-type column with a real `level_id` gets state; a custom column keeps its authored
  price/CTA with no badge. `get_orderable_plans()`/`get_annual_payment_plan()` remain public statics
  (the latter is called by the comparison table), but `build_pricing_card()`,
  `get_dynamic_plan_pairs()`, the `.dd-card`/`.dd-pricing-container` markup and CSS, the widget's
  Plan Order repeater and the whole **"Pricing Settings"** tab (per-plan `dd_desc_*` descriptions +
  the `dd_cta_*` CTA card) are **gone** — the orphaned options are left in `wp_options` rather than
  migrated. The lockdowns are still enforced server-side by the `template_redirect` guards
  (`prevent_checkout_during_trial()`, `prevent_checkout_for_pending_downgrade()`), which remain the
  real boundary regardless of what the button shows.
  This class also rewrites the native PMPro checkout DOM (`modify_checkout_plans_dom`,
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
- **Feature comparison table** (`pmpro-comparison-table.php`, `DD_Feature_Comparison_Table`) —
  a Mailchimp-style feature-comparison grid. Columns
  (one per plan) and feature rows (each cell a tick/cross/free-text) are authored once on the
  **"Pricing Tables"** tab it registers via `dd_theme_settings_tabs` (tab **id** is still
  `comparison-table` — `#dd-panel-comparison-table` is what this module's admin JS/CSS scopes itself
  to, so don't rename it; see the settings-tab-indirection section above).
  **`render_table($args)` is the single renderer behind BOTH front-end tables**, so the grid CSS, the
  desktop sticky-header measurement pass and the mobile tab script can't drift between them:
  - default (`[dd_feature_comparison]` → `Widget_Feature_Comparison_Table`) — every column, each with
    the plain static CTA it authored.
  - `pricing_mode` (the `dd_pricing_table` shortcode → `Widget_Pricing_Table`) — same grid, but each
    PMPro column's button/badge is resolved per visitor by `DD_PMPro_Frontend_Pricing` (see that
    bullet above), and `exclude` (column keys) drops columns. The wrapper gains a `dd-fc-pricing`
    class; head cells gain `data-owns-*`/`data-action-verb`/`data-is-*` attrs, a `.dd-fc-badge`
    "CURRENT PLAN" tag (which **replaces** that column's recommended banner and sets `$has_recommended`
    so `--dd-fc-rec-pad` is still reserved — `measureBannerPad()` selects both), `.dd-fc-current` on
    the owned column's cells, `.dd-fc-trial-text`, and `.dd-fc-cta-disabled` with the href stripped.
    The toggle also opens on the term the visitor holds (`default_annual`), unlike comparison mode
    which always starts monthly. Mobile's `$initial_active` prefers the owned column over
    recommended/highlight.
    > **Gotcha:** a plan column's `data-url-monthly`/`data-url-annual` in pricing mode come from the
    > state's `url_monthly`/`url_annual` (`pmpro_url('checkout', '?level=N[&pmpropp_chosen_plan=…]')`),
    > **not** from the column's authored `cta_url`. The toggle script rewrites the href from those
    > attrs, so feeding it an authored generic link (these columns author `/sign-up/`) silently drops
    > the level and the chosen Payment Plan the instant someone flips the switch — the button lands on
    > a bare signup page instead of that plan's checkout.
  The dependency between the two modules is **bidirectional but runtime-only** (comparison calls
  `DD_PMPro_Frontend_Pricing::get_annual_payment_plan()`/`::instance()`; pricing calls
  `DD_Feature_Comparison_Table::instance()->render_table()`), both behind `class_exists()`, so the
  `functions.php` require order is not load-bearing here. Each class keeps a `private static
  $instance` set in its constructor with a public `instance()` accessor — reach the live object that
  way, never `new` a second one, or every hook re-registers.
  All authored content lives in a single
  JSON-encoded option (`dd_feature_comparison_table`) built entirely client-side against a hidden
  `#dd-fc-data-input` field and validated/re-encoded server-side in `sanitize()` (unknown cell types
  collapse to `text`; a cell referencing a column key that didn't survive column sanitation is
  dropped). The Columns/Feature Rows builder is a two-tab admin UI (`.dd-fc-admin-tabs`, "Plan Columns" /
  "Feature Rows") of collapsible cards (`.dd-fc-card.collapsed`) with per-card Duplicate/Remove actions —
  duplicating a column also copies that column's cell value onto every row so the new column starts
  populated rather than blank; duplicating a row deep-copies its cells as-is (label suffixed " (Copy)").
  A column can be a **PMPro plan column** — seeded via `get_pmpro_plans()`,
  which queries `pmpro_getAllLevels()` directly (filtered only on `allow_signups`) rather than reusing
  `DD_PMPro_Frontend_Pricing::get_orderable_plans()`, so free/£0 levels (e.g. Trial) **are** offered as
  columns here; the pricing table hides that column instead via its widget's **Hide Plans** control —
  or a **custom column**
  with its own price/CTA; on either type, a blank name/price/CTA URL is live-derived from the linked PMPro level
  at render time (`resolve_column()`, `private static`) so it never drifts stale — only fields the admin
  explicitly
  filled in override the live plan data. `resolve_column()` also resolves an **annual price/CTA URL**
  pair the same way: a PMPro column with no manually-entered annual price auto-detects the level's
  "Annual" Payment Plan extension via `DD_PMPro_Frontend_Pricing::get_annual_payment_plan()` (made
  `public static` specifically so this file can call it, mirroring how it already reuses
  `get_orderable_plans()`); a custom column, or a PMPro column with no Annual plan configured, only
  gets an annual price if the admin typed one into the Columns editor's "Annual Price" field, and a
  filled-in annual price with no explicit annual CTA URL reuses the monthly CTA rather than dead-end.
  It also returns `annual_plan_id` separately — pricing mode needs the Payment Plan **identifier**
  (not just its price) to tell a monthly holder from an annual holder of the same level, which is what
  makes `SWITCH PLAN` possible, so that lookup runs even when the admin overrode the annual price by
  hand.
  `render_table()` resolves every column once up front into `$resolved_columns` (avoiding a
  second DB-hitting `resolve_column()` call per column) and sets `$has_annual` when any column ends up
  with both a monthly and annual price. When `$has_annual` is true, each such column's head cell gets a
  monthly/yearly toggle switch (`.dd-fc-plan-toggle`, styled as `.dd-switch`/`.dd-slider`, scoped under
  `.dd-fc-wrap`) plus a `data-price-monthly`/`data-price-annual`/`data-period-*`/
  `data-url-*` attribute set; a small inline script per instance swaps the displayed price, period, and
  CTA `href` on toggle — and, in pricing mode only (detected by `data-action-verb` on the head cell),
  the button text/disabled state too. `Widget_Feature_Comparison_Table` contributes no annual-specific
  controls, since content stays settings-authored.
  **Both widgets' Style-tab controls live in one place**: the `DD_Comparison_Table_Style_Controls`
  trait (`elementor-widgets/trait-comparison-table-styles.php`, `require_once`d in `register.php`
  ahead of the widgets that `use` it). The control **names** in that trait are load-bearing — renaming
  one orphans settings already saved on existing Comparison Pricing Table widgets. `Widget_Pricing_Table`
  calls `register_comparison_style_controls()` then appends its own pricing-only groups (Current Plan
  Badge, Current Plan Column outline, Locked/Current Button, Free Trial Notice) into the same section.
  Both the Columns and Feature Rows lists are drag-to-reorder
  (jQuery UI Sortable, enqueued only on the settings screen) via a `.dd-fc-drag` handle; the client-side
  `state` object (not the DOM) is authoritative, so a drag's `update` callback reads the new DOM order
  back into `state.columns`/`state.rows` and calls `renderAll()` rather than rewriting input names in
  place — keep new list mutations going through `state` + `renderAll()` for the same reason. The widget's
  Style tab also exposes `Group_Control_Typography` controls for the plan-name header, the cell values
  (`.dd-fc-cell:not(.dd-fc-feature)`, i.e. every column's tick/cross/text — separate from the feature-label
  typography), and the "Recommended" banner text (plus a banner `DIMENSIONS` padding control); its
  padding/border-radius `DIMENSIONS` controls include the `custom` unit alongside `px`/`em`/`%`. A single
  "Cell Border Color" control sets `--dd-fc-border-color` on `.dd-fc-table`, which the shortcode's own
  `<style>` (`pmpro-comparison-table.php`) reads for the table's outer border, row dividers, and (via a
  `.dd-fc-cell:not(:last-child)` rule) column dividers — one control governs all three rather than
  separate ones per edge.
  The head row's reserved top padding (room for the banner, which is `position:absolute`) is not a
  fixed value — `render_shortcode()` only reserves the larger 30px gap when at least one column is
  actually marked recommended (`--dd-fc-rec-pad` CSS var, default 14px), and when it does, an inline
  script measures the rendered banner's live `offsetHeight` (via `ResizeObserver`, falling back to a
  `resize` listener) and sets `--dd-fc-rec-pad` to `height + 8px` — since the actual height depends on
  the admin's banner text length and the column width, a fixed padding either wastes space or lets a
  wrapped two-line banner overlap the plan name below it. Each rendered instance gets a unique
  `#dd-fc-{n}` wrapper id (`self::$instance_counter`) so the measurement stays scoped if the
  shortcode/widget appears more than once on a page.
  **Mobile (`≤768px`) collapses the grid to one visible plan at a time**, switched via a sticky
  `.dd-fc-mobile-tabs` bar (one button per column) rendered above the table; clicking a tab toggles
  `.dd-fc-mobile-active` on the matching plan-detail card, tab button, and every feature cell sharing
  that `data-col-index` (plain inline `<script>`, no shared JS module). The initially active column
  defaults to the first `recommended` column, else the first `highlight`ed column, else column 0
  (`$initial_active` in `render_shortcode()`). `.dd-fc-wrap` is a column flexbox specifically to avoid
  margin-collapse jumps between the sticky tab bar and the table on mobile — don't revert it to block
  layout without re-checking that.
  **Desktop (`≥769px`) sticks the plan-details head row** to the top of the viewport instead
  (`position: sticky` on `.dd-fc-head`/the head row's spacer cell), then condenses it once detached —
  a `.dd-fc-sticky-sentinel` (zero-height, rendered as the first child of `.dd-fc-wrap`) is watched by
  an `IntersectionObserver`; when it scrolls past `--dd-fc-sticky-offset` the script adds `.dd-fc-stuck`
  to `.dd-fc-wrap`, which shrinks the head's padding/price size and hides the recommended banner (plus,
  in pricing mode, the Current Plan badge and trial notice). The **Yearly toggle deliberately stays
  visible when stuck** — it's the one control a visitor still needs while scrolled down the feature
  rows — and only tightens its margin. A sticky item's containing block is its own **grid area**, so the head/spacer
  cells carry `grid-row: 1 / -1` to span every row (not just row 1) and stay pinned for the table's
  whole scroll — every row is therefore listed explicitly in `grid-template-rows` (PHP
  `$grid_template_rows`, from `$row_count`) rather than left to `grid-auto-rows`, since `-1` resolves
  against the grid's *explicit* tracks only; an implicit-only row 2+ silently collapses the span back
  down to row 1 alone. Every cell (head and body) also gets explicit `--dd-fc-c`/`--dd-fc-r` custom
  properties inline, read only inside the `≥769px` media query (`grid-column`/`grid-row: var(...)`) so
  mobile's auto-placement/`grid-column: 1 / -1` stays untouched. **Load-bearing gotchas:**
  (1) `overflow: hidden` anywhere on `.dd-fc-table` silently disables `position: sticky` on its
  descendants — the table's rounded corners are therefore drawn per-cell (`--dd-fc-radius-{tl,tr,bl,br}`
  on the four corner cells, set by the widget's "Table Border Radius" control) rather than via clipping;
  don't reintroduce `overflow: hidden` there. (2) condensing the head cell shrinks its content, which
  would otherwise shrink grid row 1 and jump every feature row up the page — a same-origin script
  measures the head's natural (unstuck) height into `--dd-fc-head-h` and its condensed (stuck) height
  into `--dd-fc-stuck-h`, and `.dd-fc-head`/the spacer cell are locked to those via `min-height`/`height`
  (not `height` alone on the head, so a column whose own content runs taller still isn't clipped); the
  CTA anchor's `margin-top: auto` then rides the resulting free space down to a shared bottom edge so
  every plan's button lines up. (3) the recommended-banner's live-measured `--dd-fc-rec-pad` (see below)
  changes the head's `padding-top`, so it **must be measured before `--dd-fc-head-h`** in the same pass
  — reversing that order under-measures the row and lets the head's background paint over the feature
  row beneath it. Because of this, `--dd-fc-head-h`/`--dd-fc-stuck-h`/`--dd-fc-rec-pad` are all
  (re)computed together by one `measureHeadHeights()` pass (banner pad → natural height → stuck height,
  toggling a `.dd-fc-measuring` class that strips the sticky span/min-height so a measurement can't just
  read back its own prior output), re-run on `document.fonts.ready`, `window.load`, a debounced `resize`,
  and a `ResizeObserver` on the banners — not four independent scripts. (4) as a defensive backstop for
  a still-stale measurement, non-head body cells are explicitly opaque and default to `z-index: 1` (above
  the header's `z-index: 0`), so any residual head overflow is covered by the row below rather than
  clipping its text; only `.dd-fc-stuck` raises the head/spacer back to `z-index: 10` to cover rows
  scrolling underneath it, which is the one state that actually needs it on top. `--dd-fc-sticky-offset`
  (also read by the mobile tab bar's `top`) is exposed as a responsive "Sticky Top Offset" Style-tab
  slider on the widget for sites with a fixed header.
- **Trial abuse protection** (`pmpro-trial-protection.php`, `DD_PMPro_Trial_Protection`) —
  fingerprints Stripe payment tokens to block repeat free trials, lets users opt out of a trial
  (forcing full payment via `pmpro_checkout_level` filters), and enforces the one-time Subscription
  Delay.
- **One trial per company** (`modules/membership-extensions/pmpro-company-trial.php`) — a complementary, payment-method-
  independent anti-abuse layer: "company" is derived from the signup email's domain
  (`dd_user_company_domain()`), exempting an admin-editable personal-provider list
  (`dd_public_email_domains`, falls back to a built-in default) so e.g. two separate Gmail signups
  are never grouped together. Which PMPro levels count as a "trial" is admin-configured
  (`dd_trial_levels`), not hardcoded. Hooked on `pmpro_after_checkout` and
  `pmpro_after_change_membership_level` (both priority 5, ahead of the rewards manager's 10) via
  `dd_evaluate_company_trial_status($user_id, $level_id)`: the first account from a company to
  reach a trial level claims that company's one trial slot (`_dd_company_trial_claimed` user meta —
  permanent, not released on cancellation/upgrade); any later account from the same company
  reaching a trial level is flagged `_dd_company_trial_restricted` instead. That flag does two
  things — forces `dd_user_search_limit()` to `0` (see the plan-capability section above) and blocks
  `DD_PMPro_Rewards_Manager::award_registration_points()`/`process_monthly_points()` from awarding
  registration credits or seeding the monthly allowance — and nothing else; outreach, saved lists,
  export PDF etc. keep following the plan's normal `dd_user_can()` configuration regardless. Moving
  a restricted account to a non-trial level clears the restriction on the very next
  checkout/level-change event. A one-time `admin_init` backfill
  (`dd_company_trial_backfill()`, gated by the `dd_company_trial_backfill_done` option, held back
  until at least one level is marked trial) stamps existing users' company domains and grants the
  claim to the earliest-registered trial-level user per domain **without ever restricting** an
  already-existing account. The Users list in wp-admin gets a read-only "Company Trial" column
  (`manage_users_columns`/`manage_users_custom_column`) showing claim/restricted status and domain —
  there is no admin UI to manually clear a restriction, only deleting the user's
  `_dd_company_trial_restricted` meta.
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

- **⚠️ Outbound email is currently globally disabled** (`functions.php`, near the bottom): a
  `pre_wp_mail` filter (`dd_disable_wp_outbound_mail`, priority 99) unconditionally short-circuits
  every `wp_mail()` call by returning `true` — no mail is actually sent, but callers see a
  successful dispatch (so PMPro/myCred/outreach/etc. won't log false-positive failures or retry).
  This was added as a temporary measure ("disable email temporarily") and affects **all** theme
  and plugin email, not just one module — remove that filter to re-enable mail. If a user reports
  "I should have gotten an email but didn't," check here first before debugging the specific
  feature's mail-sending code.
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
- **Never write a bracketed `[registered_shortcode_tag]` inside any file's actual output** —
  echoed HTML/CSS/JS, including `<style>`/`<script>` comments — even to reference another
  shortcode by name in a code comment. `do_shortcode()` regex-replaces any `[registered_tag]`
  text wherever it later appears in the page, so e.g. writing `[dd_pricing_table]` inside a
  comment in `pmpro-comparison-table.php`'s emitted `<style>` block got replaced with that other
  shortcode's full rendered HTML mid-tag, breaking the block (fixed by de-bracketing such
  references to plain text like "the `dd_pricing_table` shortcode"). PHP-only docblock/code
  comments that never reach output are unaffected, but treat any comment inside a render
  function's output buffer as reachable.
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
