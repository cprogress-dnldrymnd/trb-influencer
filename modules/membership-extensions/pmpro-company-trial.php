<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * One-trial-per-company enforcement.
 *
 * "Company" is derived from the signup email's domain (jane@acme.com -> acme.com); domains on
 * the personal-email exemption list (dd_public_email_domains) are never grouped, so gmail/outlook/
 * etc. signups are always treated as independent companies. Which PMPro levels count as a "trial"
 * is admin-configured (dd_trial_levels) rather than a hardcoded level ID.
 *
 * The first account from a company to reach a trial level claims that company's one trial slot
 * (_dd_company_trial_claimed, permanent — it is not released on cancellation/upgrade, otherwise a
 * company could recycle it indefinitely). Any later account from the same company that reaches a
 * trial level is flagged _dd_company_trial_restricted instead, which:
 *   - forces dd_user_search_limit() to 0 (includes/core/plan-capabilities.php)
 *   - blocks registration credits / the monthly allowance seed (pmpro-mycred-rewards-manager.php)
 * Everything else (outreach, saved lists, export PDF, etc.) is unaffected — those keep following
 * the plan's normal dd_user_can() configuration.
 *
 * Moving a restricted account to a non-trial (paid) level clears the restriction immediately.
 */

/**
 * Built-in personal/free email providers, used when dd_public_email_domains is unset/empty.
 *
 * @return string[]
 */
function dd_default_public_email_domains()
{
    return [
        'gmail.com', 'googlemail.com',
        'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'msn.com',
        'yahoo.com', 'yahoo.co.uk', 'ymail.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com',
        'protonmail.com', 'proton.me',
        'gmx.com', 'gmx.net',
        'mail.com',
        'zoho.com',
        'yandex.com',
        'tutanota.com',
        'fastmail.com',
        'hey.com',
        'qq.com', '163.com', 'naver.com',
    ];
}

/**
 * The exemption list of domains that are never treated as a "company" — falls back to the
 * built-in default list when the admin hasn't configured one.
 *
 * @return string[]
 */
function dd_public_email_domains()
{
    $stored = get_option('dd_public_email_domains', []);

    if (! is_array($stored) || empty($stored)) {
        return dd_default_public_email_domains();
    }

    return $stored;
}

/**
 * Resolves a user's company domain from their account email, or '' if it's on the personal-email
 * exemption list (or the email is missing/malformed).
 *
 * @param int $user_id
 * @return string Lowercased domain, or ''.
 */
function dd_user_company_domain($user_id)
{
    $user_id = (int) $user_id;
    if (! $user_id) {
        return '';
    }

    $user = get_userdata($user_id);
    if (! $user || empty($user->user_email) || strpos($user->user_email, '@') === false) {
        return '';
    }

    $parts  = explode('@', $user->user_email);
    $domain = strtolower(trim(end($parts)));

    if ($domain === '' || in_array($domain, dd_public_email_domains(), true)) {
        return '';
    }

    return $domain;
}

/**
 * PMPro level IDs the admin has classified as "trial" (Functionality tab).
 *
 * @return int[]
 */
function dd_trial_level_ids()
{
    $levels = get_option('dd_trial_levels', []);
    return is_array($levels) ? array_map('intval', $levels) : [];
}

/**
 * @param int $level_id
 * @return bool
 */
function dd_is_trial_level($level_id)
{
    return in_array((int) $level_id, dd_trial_level_ids(), true);
}

/**
 * Finds the user (if any, other than $exclude_user_id) currently holding the trial claim for a
 * company domain.
 *
 * @param string $domain
 * @param int    $exclude_user_id
 * @return int User ID, or 0 if the domain's trial is unclaimed.
 */
function dd_company_trial_holder($domain, $exclude_user_id = 0)
{
    if ($domain === '') {
        return 0;
    }

    $args = [
        'meta_query' => [
            'relation' => 'AND',
            ['key' => '_dd_company_domain', 'value' => $domain],
            ['key' => '_dd_company_trial_claimed', 'value' => '1'],
        ],
        'number' => 1,
        'fields' => 'ID',
    ];

    if ($exclude_user_id) {
        $args['exclude'] = [(int) $exclude_user_id];
    }

    $users = get_users($args);

    return ! empty($users) ? (int) $users[0] : 0;
}

/**
 * Whether the given (or current) user is currently blocked from registration credits and search
 * by the one-trial-per-company rule.
 *
 * @param int|null $user_id
 * @return bool
 */
function dd_user_trial_restricted($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if (! $user_id) {
        return false;
    }

    return (bool) get_user_meta($user_id, '_dd_company_trial_restricted', true);
}

/**
 * Re-evaluates a user's company-trial claim/restriction for the level they just reached. Called
 * from both pmpro_after_checkout and pmpro_after_change_membership_level so a real checkout, an
 * admin-added member, and a manual level switch are all covered.
 *
 * @param int $user_id
 * @param int $level_id
 * @return void
 */
function dd_evaluate_company_trial_status($user_id, $level_id)
{
    $user_id  = (int) $user_id;
    $level_id = (int) $level_id;

    if (! $user_id || $level_id <= 0) {
        return;
    }

    $domain = dd_user_company_domain($user_id);
    update_user_meta($user_id, '_dd_company_domain', $domain);

    // Not a trial-classified level (e.g. an upgrade to a paid plan) — any existing restriction
    // no longer applies. The trial claim itself is left in place permanently.
    if (! dd_is_trial_level($level_id)) {
        delete_user_meta($user_id, '_dd_company_trial_restricted');
        return;
    }

    // Personal-email signups are never grouped into a shared "company".
    if ($domain === '') {
        delete_user_meta($user_id, '_dd_company_trial_restricted');
        return;
    }

    $holder_id = dd_company_trial_holder($domain, $user_id);

    if (! $holder_id) {
        // Nobody else from this company holds the trial claim (or this account already does) —
        // claim/re-claim it and ensure no restriction is set.
        update_user_meta($user_id, '_dd_company_trial_claimed', '1');
        delete_user_meta($user_id, '_dd_company_trial_restricted');
        return;
    }

    update_user_meta($user_id, '_dd_company_trial_restricted', '1');
}

/**
 * @param int    $user_id
 * @param object $morder
 * @return void
 */
function dd_company_trial_after_checkout($user_id, $morder)
{
    $level_id = 0;

    if (! empty($morder) && isset($morder->membership_id)) {
        $level_id = (int) $morder->membership_id;
    }

    if (! $level_id && function_exists('pmpro_getMembershipLevelForUser')) {
        $level = pmpro_getMembershipLevelForUser((int) $user_id);
        if ($level) {
            $level_id = (int) $level->id;
        }
    }

    dd_evaluate_company_trial_status($user_id, $level_id);
}
add_action('pmpro_after_checkout', 'dd_company_trial_after_checkout', 5, 2);

/**
 * @param int      $level_id     The new membership level ID (0 on cancellation/expiry).
 * @param int      $user_id
 * @param int|null $cancel_level Unused.
 * @return void
 */
function dd_company_trial_after_level_change($level_id, $user_id, $cancel_level = null)
{
    if ((int) $level_id <= 0) {
        return;
    }

    dd_evaluate_company_trial_status($user_id, $level_id);
}
add_action('pmpro_after_change_membership_level', 'dd_company_trial_after_level_change', 5, 3);

/**
 * One-time backfill for sites that already have users: stamps every user's company domain and
 * grants the trial claim to the earliest-registered trial-level user per domain, WITHOUT ever
 * restricting an existing account — this rule only ever starts restricting new claim attempts
 * going forward.
 *
 * Waits until the admin has actually classified at least one level as "trial" before running (and
 * only then marks itself done) — running earlier would mark the backfill complete without ever
 * assigning a claim holder, permanently losing the chance to protect existing companies once the
 * setting is configured.
 *
 * @return void
 */
function dd_company_trial_backfill()
{
    if (get_option('dd_company_trial_backfill_done')) {
        return;
    }

    if (! function_exists('pmpro_getMembershipLevelForUser')) {
        return;
    }

    if (empty(dd_trial_level_ids())) {
        return;
    }

    update_option('dd_company_trial_backfill_done', 1);

    $users = get_users(['fields' => ['ID', 'user_registered']]);

    $earliest_by_domain = [];

    foreach ($users as $user) {
        $user_id = (int) $user->ID;
        $domain  = dd_user_company_domain($user_id);
        update_user_meta($user_id, '_dd_company_domain', $domain);

        if ($domain === '') {
            continue;
        }

        $level = pmpro_getMembershipLevelForUser($user_id);
        if (empty($level->id) || ! dd_is_trial_level((int) $level->id)) {
            continue;
        }

        $registered = strtotime($user->user_registered);

        if (! isset($earliest_by_domain[$domain]) || $registered < $earliest_by_domain[$domain]['registered']) {
            $earliest_by_domain[$domain] = [
                'user_id'    => $user_id,
                'registered' => $registered,
            ];
        }
    }

    foreach ($earliest_by_domain as $info) {
        update_user_meta($info['user_id'], '_dd_company_trial_claimed', '1');
    }
}
add_action('admin_init', 'dd_company_trial_backfill');

/**
 * Surfaces the company-trial claim/restriction state on the wp-admin Users list — otherwise a
 * restricted account (0 searches, no registration credits) is invisible from the admin side.
 */
add_filter('manage_users_columns', function ($columns) {
    $columns['dd_company_trial'] = 'Company Trial';
    return $columns;
});

add_filter('manage_users_custom_column', function ($output, $column_name, $user_id) {
    if ($column_name !== 'dd_company_trial') {
        return $output;
    }

    $domain = get_user_meta($user_id, '_dd_company_domain', true);
    if ($domain === '' || $domain === false) {
        return '&#8212;';
    }

    if (dd_user_trial_restricted($user_id)) {
        $status = '<span style="color:#d63638;">Restricted</span>';
    } elseif (get_user_meta($user_id, '_dd_company_trial_claimed', true)) {
        $status = '<span style="color:#2271b1;">Trial holder</span>';
    } else {
        $status = '&#8212;';
    }

    return $status . '<br><span style="color:#787c82;font-size:11px;">' . esc_html($domain) . '</span>';
}, 10, 3);
