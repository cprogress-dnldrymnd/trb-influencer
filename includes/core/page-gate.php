<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central authority for "can the current visitor see this page" — the click-interception
 * map, the template_redirect boundary, and the localizer all read through
 * dd_page_gate_for_post() so a gate's rule/message/CTA can never drift between the three.
 *
 * When a page is gated, popping a ddConfirm() over the current page (via the click
 * interceptor, or a bounce-back + query flag on direct navigation) replaces the theme's
 * old behaviour of silently redirecting the visitor away.
 */

define('DD_GATE_DASHBOARD_TEMPLATE', 'templates/page-dashboard.php');

/**
 * Whether the popup gate is active. When off, callers fall back to the original
 * redirect-only behaviour this feature replaces.
 *
 * @return bool
 */
function dd_page_gate_enabled()
{
    return (bool) get_option('dd_gate_use_popup', true);
}

add_action('admin_init', function () {
    register_setting('dd_theme_page_ids', 'dd_gate_use_popup', [
        'type'              => 'boolean',
        'sanitize_callback' => function ($value) {
            return ! empty($value);
        },
        'default'           => true,
    ]);

    add_settings_field('dd_gate_use_popup', 'Restricted Page Popup', function () {
?>
        <label>
            <input type="checkbox" name="dd_gate_use_popup" value="1" <?php checked(dd_page_gate_enabled()); ?>>
            Show an in-page popup (with a Log in / Upgrade your plan button) instead of
            redirecting visitors away from a restricted page.
        </label>
<?php
    }, 'dd-theme-settings-functionality', 'dd_functionality_section');
});

/**
 * The single authority: is $post_id gated for $user_id (defaults to the current user), and
 * if so, what should the popup say and where should its button go.
 *
 * @param int      $post_id
 * @param int|null $user_id
 * @return array{reason:string,message:string,cta_label:string,cta_url:string}|null Null = allowed.
 */
function dd_page_gate_for_post($post_id, $user_id = null)
{
    $post_id  = (int) $post_id;
    $user_id  = $user_id ? (int) $user_id : get_current_user_id();
    $is_guest = ! $user_id;

    if (! $post_id) {
        return null;
    }

    $login_url = get_the_permalink(dd_get_page_id('dd_login_redirect_page_id', 4144));

    // 1. ACF 'members_only' page, logged out.
    if ($is_guest && function_exists('get_field') && get_field('members_only', $post_id)) {
        return [
            'reason'    => 'login',
            'message'   => dd_get_message('dd_msg_gate_members_only'),
            'cta_label' => dd_get_message('dd_msg_gate_login_cta'),
            'cta_url'   => $login_url,
        ];
    }

    // 2. Single 'influencer' profile, logged out.
    if ($is_guest && get_post_type($post_id) === 'influencer') {
        return [
            'reason'    => 'login',
            'message'   => dd_get_message('dd_msg_gate_influencer'),
            'cta_label' => dd_get_message('dd_msg_gate_login_cta'),
            'cta_url'   => $login_url,
        ];
    }

    // 2b. Any page built on the Dashboard template, logged out.
    if ($is_guest && get_page_template_slug($post_id) === DD_GATE_DASHBOARD_TEMPLATE) {
        return [
            'reason'    => 'login',
            'message'   => dd_get_message('dd_msg_gate_members_only'),
            'cta_label' => dd_get_message('dd_msg_gate_login_cta'),
            'cta_url'   => $login_url,
        ];
    }

    // 3. Search / search-results page, logged in, at or over the plan's creator-search cap.
    $search_page_id         = dd_get_page_id('dd_search_page_id', 2149);
    $search_results_page_id = dd_get_page_id('dd_search_results_page_id', 1949);
    if (! $is_guest && in_array($post_id, [$search_page_id, $search_results_page_id], true)) {
        $limit = dd_user_search_limit($user_id);
        if ($limit >= 0) {
            $used = (int) get_user_meta($user_id, 'number_of_searches', true);
            if ($used >= $limit) {
                $msg_key = (function_exists('dd_user_trial_restricted') && dd_user_trial_restricted($user_id))
                    ? 'dd_msg_company_trial_block'
                    : 'dd_msg_search_limit';
                return [
                    'reason'    => 'upgrade',
                    'message'   => dd_get_message($msg_key),
                    'cta_label' => dd_get_message('dd_msg_notice_upgrade_cta'),
                    'cta_url'   => dd_plan_upgrade_url(),
                ];
            }
        }
    }

    // 4. PMPro level-gated page.
    if (function_exists('pmpro_has_membership_access')) {
        if (! pmpro_has_membership_access($post_id, $user_id ?: null)) {
            return [
                'reason'    => $is_guest ? 'login' : 'upgrade',
                'message'   => dd_get_message('dd_msg_gate_plan'),
                'cta_label' => $is_guest ? dd_get_message('dd_msg_gate_login_cta') : dd_get_message('dd_msg_notice_upgrade_cta'),
                'cta_url'   => $is_guest ? $login_url : dd_plan_upgrade_url(),
            ];
        }
    }

    return null;
}

/**
 * Raw candidate IDs for the two guest-only, meta-driven gates (members_only pages and
 * Dashboard-template pages), cached together since both are only relevant to guests and
 * invalidated by the same events.
 *
 * @return array{members_only:int[],dashboard:int[]}
 */
function dd_page_gate_guest_candidate_ids()
{
    $cached = get_transient('dd_gate_guest_candidate_ids');
    if (is_array($cached)) {
        return $cached;
    }

    $members_only = get_posts([
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'     => 'members_only',
            'value'   => '1',
            'compare' => '=',
        ]],
    ]);

    $dashboard = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'   => '_wp_page_template',
            'value' => DD_GATE_DASHBOARD_TEMPLATE,
        ]],
    ]);

    $result = [
        'members_only' => array_map('intval', $members_only),
        'dashboard'    => array_map('intval', $dashboard),
    ];

    set_transient('dd_gate_guest_candidate_ids', $result, DAY_IN_SECONDS);

    return $result;
}

function dd_page_gate_flush_guest_candidate_ids()
{
    delete_transient('dd_gate_guest_candidate_ids');
}
add_action('save_post', 'dd_page_gate_flush_guest_candidate_ids');
add_action('deleted_post', 'dd_page_gate_flush_guest_candidate_ids');

/**
 * PMPro's page-access table, cached the same way — see CLAUDE.md's note that
 * {$wpdb->prefix}pmpro_memberships_pages can carry rows for since-deleted levels; those are
 * left in deliberately and filtered per-request through pmpro_has_membership_access().
 *
 * @return int[]
 */
function dd_page_gate_pmpro_page_ids()
{
    if (! function_exists('pmpro_has_membership_access')) {
        return [];
    }

    $cached = get_transient('dd_gate_pmpro_page_ids');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    $ids = $wpdb->get_col("SELECT DISTINCT page_id FROM {$wpdb->pmpro_memberships_pages}");
    $ids = array_map('intval', $ids);

    set_transient('dd_gate_pmpro_page_ids', $ids, DAY_IN_SECONDS);

    return $ids;
}

function dd_page_gate_flush_pmpro_page_ids()
{
    delete_transient('dd_gate_pmpro_page_ids');
}
add_action('save_post', 'dd_page_gate_flush_pmpro_page_ids');
add_action('deleted_post', 'dd_page_gate_flush_pmpro_page_ids');
// PMPro itself fires this when an admin edits a page's membership-access checkboxes.
add_action('pmpro_save_membership_level', 'dd_page_gate_flush_pmpro_page_ids');

/**
 * The payload the click interceptor (assets/js/modules/dd-page-gate.js) needs, built once
 * per request. Every entry runs through dd_page_gate_for_post() so its wording/URL can
 * never drift from what the server-side boundary actually enforces.
 *
 * @return array{enabled:bool,paths:array<string,array>,prefixes:array<int,array>,notice:array|null}
 */
function dd_page_gate_client_map()
{
    $map = [
        'enabled'  => dd_page_gate_enabled(),
        'paths'    => [],
        'prefixes' => [],
        'notice'   => null,
    ];

    if (! $map['enabled']) {
        return $map;
    }

    $add_path = function ($post_id) use (&$map) {
        $post_id = (int) $post_id;
        $gate    = dd_page_gate_for_post($post_id);
        if (! $gate) {
            return;
        }
        $path = wp_parse_url(get_permalink($post_id), PHP_URL_PATH);
        if (! $path) {
            return;
        }
        $map['paths'][untrailingslashit($path) . '/'] = $gate;
    };

    if (! is_user_logged_in()) {
        $candidates = dd_page_gate_guest_candidate_ids();
        foreach (array_unique(array_merge($candidates['members_only'], $candidates['dashboard'])) as $post_id) {
            $add_path($post_id);
        }

        $influencer_type = get_post_type_object('influencer');
        if ($influencer_type) {
            $slug = ! empty($influencer_type->rewrite['slug']) ? $influencer_type->rewrite['slug'] : $influencer_type->name;
            $gate = dd_page_gate_for_post_type_sample('influencer');
            if ($gate) {
                $map['prefixes'][] = array_merge($gate, ['prefix' => '/' . trim($slug, '/') . '/']);
            }
        }
    } else {
        $search_page_id         = dd_get_page_id('dd_search_page_id', 2149);
        $search_results_page_id = dd_get_page_id('dd_search_results_page_id', 1949);
        $add_path($search_page_id);
        $add_path($search_results_page_id);
    }

    foreach (dd_page_gate_pmpro_page_ids() as $post_id) {
        $add_path($post_id);
    }

    // Direct-navigation fallback: if the current request itself is gated, tell the JS to
    // pop the modal immediately (dd_page_gate_bounce() lands here with ?dd_gate=<reason>).
    if (isset($_GET['dd_gate']) && function_exists('dd_get_message')) {
        $reason        = sanitize_key(wp_unslash($_GET['dd_gate']));
        $message_key   = $reason === 'upgrade' ? 'dd_msg_gate_plan' : 'dd_msg_gate_members_only';
        $map['notice'] = [
            'reason'    => $reason,
            'message'   => dd_get_message($message_key),
            'cta_label' => $reason === 'upgrade' ? dd_get_message('dd_msg_notice_upgrade_cta') : dd_get_message('dd_msg_gate_login_cta'),
            'cta_url'   => $reason === 'upgrade' ? dd_plan_upgrade_url() : get_the_permalink(dd_get_page_id('dd_login_redirect_page_id', 4144)),
        ];
    }

    return $map;
}

/**
 * A representative gate payload for every post of $post_type, used for the /influencer/
 * prefix rule where enumerating every profile isn't practical — the message/CTA are the
 * same for all of them (guest visiting any influencer profile), so one lookup is enough.
 *
 * @param string $post_type
 * @return array|null
 */
function dd_page_gate_for_post_type_sample($post_type)
{
    $sample = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);

    if (empty($sample)) {
        // No published post to sample from yet — build the message directly so the prefix
        // rule still exists on an otherwise-empty site.
        return [
            'reason'    => 'login',
            'message'   => dd_get_message('dd_msg_gate_influencer'),
            'cta_label' => dd_get_message('dd_msg_gate_login_cta'),
            'cta_url'   => get_the_permalink(dd_get_page_id('dd_login_redirect_page_id', 4144)),
        ];
    }

    return dd_page_gate_for_post($sample[0]);
}

/**
 * Server-side fallback for a gated request that wasn't intercepted client-side (direct URL
 * entry, a bookmark, JS disabled). Bounces back to a safe, ungated origin with
 * ?dd_gate={reason} so the popup opens there instead of showing the restricted page.
 *
 * @param array $gate
 * @return void
 */
function dd_page_gate_bounce($gate)
{
    $current_user_id = get_current_user_id();
    $candidates       = [];

    $referer = wp_get_referer();
    if ($referer) {
        $referer_host = wp_parse_url($referer, PHP_URL_HOST);
        if ($referer_host && $referer_host === wp_parse_url(home_url(), PHP_URL_HOST)) {
            $candidates[] = $referer;
        }
    }

    $candidates[] = $current_user_id
        ? get_permalink(dd_get_page_id('dd_dashboard_page_id', 1565))
        : home_url('/');

    $candidates[] = home_url('/');

    $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''));

    foreach ($candidates as $candidate) {
        if (! $candidate || untrailingslashit($candidate) === untrailingslashit($current_url)) {
            continue;
        }
        $candidate_id = url_to_postid($candidate);
        if ($candidate_id && dd_page_gate_for_post($candidate_id, $current_user_id ?: null)) {
            continue; // still gated — try the next candidate rather than looping.
        }
        wp_safe_redirect(add_query_arg('dd_gate', $gate['reason'], $candidate));
        exit;
    }

    // Every candidate was gated or unusable (shouldn't happen in practice) — go home plain.
    wp_safe_redirect(home_url('/'));
    exit;
}
