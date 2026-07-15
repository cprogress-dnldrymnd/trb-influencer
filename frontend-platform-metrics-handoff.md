# Frontend handoff: YouTube / TikTok metrics & charts

Guide for building Elementor blocks, shortcodes, and graphs against Influencer Collective Data Hub platform metrics.

---

## Goal

YouTube and TikTok use the **same growth-history path as Instagram**. Charts/blocks should reuse existing Instagram chart logic and only switch **platform**.

**Important:** YouTube **subscribers** are stored and plotted as `followers` in history rows so existing chart code keeps working. Label YT charts “Subscribers” in the UI even though the data key is `followers`.

---

## How to read history (preferred)

**Theme helper (use this):**

```php
$rows = trb_platform_history_rows( $post_id, 'youtube' ); // or 'tiktok' | 'instagram'
```

**Plugin bridge (same data):**

```php
$rows = icdh_platform_history_display_rows( $post_id, 'youtube' );
```

Instagram alias (unchanged):

```php
$rows = trb_instagram_history_rows( $post_id ); // == platform=instagram
```

Do **not** read raw meta arrays in Elementor/theme unless building something custom. Prefer the helpers above.

Updated timestamp (optional):

```php
$ts = icdh_platform_history_updated_ts( $post_id, 'youtube' );
```

---

## Ready-made chart shortcodes

These already accept `platform`:

| Shortcode | Use |
|-----------|-----|
| `[follower_growth_chart platform="youtube"]` | Monthly growth bars |
| `[follower_timeline_chart platform="youtube"]` | Followers/subscribers over time |
| `[follower_growth_rate_chart platform="tiktok"]` | Growth rate |
| `[follower_like_range_chart platform="instagram"]` | Like range |

**Attrs:**

| Attr | Values | Default |
|------|--------|---------|
| `platform` | `instagram` \| `youtube` \| `tiktok` | `instagram` |
| `id` | influencer post ID | current influencer |

Empty state copy is provider-neutral: *“History will build as this profile is refreshed.”*

Charts need **≥2 usable points** with `followers > 0` (same as Instagram today).

---

## History row shape (what charts get)

Each row matches the Instagram chart payload:

| Key | Type | Notes |
|-----|------|--------|
| `timestamp_ms` | int | Unix milliseconds |
| `date` | string | `YYYY-MM-DD` |
| `followers` | int | **YouTube subscribers mapped here** |
| `following` | int | Often `0` on YouTube |
| `posts` | int | YouTube/TikTok = video count when available |
| `avglikes` | float | |
| `avgcomments` | float | |
| `avgvideoviews` | float | Avg views/plays when available |
| `videoviews` | int | Cumulative views when available |
| `engagerate` | float | Decimal rate (e.g. `0.038` = 3.8%) |
| `likes` | int | Totals when available |
| `comments` | int | Totals when available |
| `provider` | string | `creatordb` \| `influencers_club` \| `manual` |
| `source_type` | string | see below |

### `source_type` values

| Value | Meaning |
|-------|---------|
| `import_seed` | First CSV/import point |
| `provider_snapshot` | Live enrich/raw/full capture |
| `provider_historical` | CreatorDB history API backfill |

---

## Where history is stored (meta keys)

One store per platform (separate arrays):

| Platform | History meta | Updated stamp |
|----------|--------------|---------------|
| Instagram | `instagram_metrics_history` | `instagram_metrics_history_updated` |
| YouTube | `youtube_metrics_history` | `youtube_metrics_history_updated` |
| TikTok | `tiktok_metrics_history` | `tiktok_metrics_history_updated` |

Legacy Instagram-only fallback still exists: `creatordb_history` (Instagram charts only).

---

## Live “current metrics” fields (stat cards / labels)

These are **current snapshot** fields (not time series). Use for profile header stats; use history helpers for graphs.

### YouTube

| Meta key | Meaning |
|----------|---------|
| `youtubeid` / `youtube_id` | Channel ID (`UC…`) |
| `youtubedisplayid` / `youtube_custom_url` | Handle / custom URL |
| `youtubename` | Channel display name |
| `youtube_subscribers` | Current subscriber count |
| `youtube_engagement_rate` | Current engagement (decimal) |
| `ic_youtube_link` | Influencers.Club profile link (when present) |

### TikTok

| Meta key | Meaning |
|----------|---------|
| `tiktokid` / `tiktok_username` | Username |
| `tiktok_followers` | Current followers |
| `tiktok_engagement_rate` | Current engagement (decimal) |
| `ic_tiktok_link` | Influencers.Club profile link (when present) |

### Shared / primary-platform (unchanged)

Site-wide `followers`, `engagerate`, `avglikes`, `avgcomments` remain **primary-platform** fields (usually Instagram). For YouTube/TikTok UI, prefer the namespaced keys above.

---

## When data appears

History grows when:

1. Club CSV import includes YouTube/TikTok metrics → seeds 1 `import_seed` point
2. IC enrich/raw or enrich/full for that platform → appends a snapshot
3. CreatorDB Status Hub **Fetch YouTube history** / **Fetch TikTok history** → merges multi-point historical series

Until then, charts may show empty state. That’s expected.

---

## Frontend build checklist

1. Duplicate existing Instagram chart/stat blocks.
2. Point history reads at `trb_platform_history_rows($id, 'youtube'|'tiktok')` **or** shortcode `platform=`.
3. For current stats, read `youtube_subscribers` / `tiktok_followers` (not plain `followers` unless primary platform is that network).
4. Label YouTube charts “Subscribers” in the UI even though the data key is `followers`.
5. Treat missing/empty history as empty state, not an error.
6. Deploy **plugin first**, then theme (writes before reads).

---

## Quick PHP example (custom Elementor / block)

```php
$post_id  = get_the_ID();
$platform = 'youtube'; // or tiktok / instagram

$history = function_exists( 'trb_platform_history_rows' )
  ? trb_platform_history_rows( $post_id, $platform )
  : [];

$current = $platform === 'youtube'
  ? (int) get_post_meta( $post_id, 'youtube_subscribers', true )
  : (int) get_post_meta( $post_id, 'tiktok_followers', true );

// $history[] => timestamp_ms, date, followers, avglikes, engagerate, ...
```

---

## Related theme / plugin files

| Area | Path |
|------|------|
| Theme helper | `trb-influencer-git/includes/core/helpers.php` → `trb_platform_history_rows()` |
| Chart shortcodes | `trb-influencer-git/modules/frontend-utilities/charts.php` |
| Plugin bridge | `includes/instagram-history-bridge.php` → `icdh_platform_history_*` |
| History meta keys | `includes/DataLayer/PlatformMetricsHistoryKeys.php` |
