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
        'enabled'    => dd_page_gate_enabled(),
        'paths'      => [],
        'prefixes'   => [],
        'notice'     => null,
        'logged_in'  => is_user_logged_in(),
        'block_page' => false,
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

    // Fresh bounce flash (cookie) wins; legacy ?dd_gate= is still honoured for old history
    // entries, but both go through dd_page_gate_notice_payload() so a logged-in visitor
    // never re-sees "please log in" from a stale Back navigation.
    $reason = dd_page_gate_consume_flash();
    if (! $reason && isset($_GET['dd_gate'])) {
        $reason = sanitize_key(wp_unslash($_GET['dd_gate']));
    }
    $map['notice'] = dd_page_gate_notice_payload($reason);

    return $map;
}

/**
 * One-shot flash cookie (legacy). New gates use dd_page_gate_render_block() instead of
 * redirecting; this remains so an old cookie still surfaces a notice once if present.
 *
 * @param string $reason
 * @return void
 */
function dd_page_gate_set_flash($reason)
{
    $reason = sanitize_key($reason);
    if (! $reason) {
        return;
    }

    $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    setcookie('dd_gate_flash', $reason, [
        'expires'  => time() + 120,
        'path'     => $path,
        'domain'   => $domain,
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Read and clear the bounce flash cookie for this request.
 *
 * @return string Empty when none.
 */
function dd_page_gate_consume_flash()
{
    if (empty($_COOKIE['dd_gate_flash'])) {
        return '';
    }

    $reason = sanitize_key(wp_unslash($_COOKIE['dd_gate_flash']));
    $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    setcookie('dd_gate_flash', '', [
        'expires'  => time() - YEAR_IN_SECONDS,
        'path'     => $path,
        'domain'   => $domain,
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['dd_gate_flash']);

    return $reason;
}

/**
 * Build the client notice payload for a bounce reason, or null when it no longer applies
 * (e.g. logged-in user hitting a leftover ?dd_gate=login history entry).
 *
 * @param string $reason
 * @return array{reason:string,message:string,cta_label:string,cta_url:string}|null
 */
function dd_page_gate_notice_payload($reason)
{
    $reason = sanitize_key($reason);
    if (! $reason || ! function_exists('dd_get_message')) {
        return null;
    }

    if ($reason === 'login' && is_user_logged_in()) {
        return null;
    }

    if ($reason === 'upgrade' && is_user_logged_in() && function_exists('dd_user_search_limit')) {
        $user_id = get_current_user_id();
        $limit   = dd_user_search_limit($user_id);
        if ($limit < 0) {
            return null;
        }
        $used = (int) get_user_meta($user_id, 'number_of_searches', true);
        if ($used < $limit) {
            return null;
        }
    }

    $is_upgrade = ($reason === 'upgrade');

    return [
        'reason'    => $reason,
        'message'   => dd_get_message($is_upgrade ? 'dd_msg_gate_plan' : 'dd_msg_gate_members_only'),
        'cta_label' => $is_upgrade ? dd_get_message('dd_msg_notice_upgrade_cta') : dd_get_message('dd_msg_gate_login_cta'),
        'cta_url'   => $is_upgrade ? dd_plan_upgrade_url() : get_the_permalink(dd_get_page_id('dd_login_redirect_page_id', 4144)),
    ];
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
 * entry, a bookmark, JS disabled). Renders a minimal blocked page in place (HTTP 200) and
 * pops the gate modal there — no redirect. Redirecting used to leave /?dd_gate=login in
 * history; browsers skip 302 sources on Back, so every Back felt like "go to login".
 *
 * @param array $gate
 * @return void
 */
function dd_page_gate_render_block($gate)
{
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }

    status_header(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $version   = defined('HELLO_ELEMENTOR_CHILD_VERSION') ? HELLO_ELEMENTOR_CHILD_VERSION : '1.0';
    $theme_uri = get_stylesheet_directory_uri();
    $notice    = [
        'reason'    => $gate['reason'],
        'message'   => $gate['message'],
        'cta_label' => $gate['cta_label'],
        'cta_url'   => $gate['cta_url'],
    ];
    $payload = [
        'enabled'    => true,
        'paths'      => new stdClass(),
        'prefixes'   => [],
        'notice'     => $notice,
        'logged_in'  => is_user_logged_in(),
        'block_page' => true,
    ];
    $messages = function_exists('dd_js_messages') ? dd_js_messages() : [];

    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html(wp_strip_all_tags($gate['message'])); ?></title>
    <style>html,body{margin:0;min-height:100%;background:#f3f4f6;}</style>
</head>
<body>
<script src="<?php echo esc_url($theme_uri . '/assets/js/modules/dd-modal.js?ver=' . rawurlencode($version)); ?>"></script>
<script>
var dd_gate = <?php echo wp_json_encode($payload); ?>;
var dd_messages = <?php echo wp_json_encode($messages); ?>;
</script>
<script src="<?php echo esc_url($theme_uri . '/assets/js/modules/dd-page-gate.js?ver=' . rawurlencode($version)); ?>"></script>
</body>
</html>
    <?php
    exit;
}

/**
 * @deprecated Use dd_page_gate_render_block() — kept as an alias so any leftover callers still work.
 *
 * @param array $gate
 * @return void
 */
function dd_page_gate_bounce($gate)
{
    dd_page_gate_render_block($gate);
}
