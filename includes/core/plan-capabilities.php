<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central capability layer for membership-plan feature gating.
 *
 * Each gated feature is stored as its own PMPro allowed-levels option (the same shape as
 * the pre-existing `dd_export_pdf_allowed_levels`), editable from Settings → Influencer
 * Theme → Functionality via `dd_render_pmpro_levels_checkboxes()`. Keeping every check
 * behind `dd_user_can()` means no feature ever hardcodes a level ID — only which levels
 * are allowed, which admins configure per plan.
 */

/**
 * Maps a feature key to its allowed-levels option name.
 *
 * @param string $feature
 * @return string Option name, or '' if the feature key is unrecognized.
 */
function dd_plan_feature_option_key($feature)
{
    $map = [
        'export_pdf'              => 'dd_export_pdf_allowed_levels',
        'outreach'                => 'dd_outreach_allowed_levels',
        'saved_lists'              => 'dd_saved_lists_allowed_levels',
        'custom_outreach_message' => 'dd_custom_outreach_message_allowed_levels',
    ];

    return isset($map[$feature]) ? $map[$feature] : '';
}

/**
 * Whether the given (or current) user's PMPro level is allowed to use $feature.
 *
 * Fail-closed: an unrecognized feature key, an inactive PMPro, a logged-out user, or an
 * empty allowed-levels option all resolve to false — mirroring the existing
 * `Saves_Manager::user_can_export_pdf()` behaviour this helper generalizes.
 *
 * @param string   $feature Feature key — see dd_plan_feature_option_key().
 * @param int|null $user_id Defaults to the current user.
 * @return bool
 */
function dd_user_can($feature, $user_id = null)
{
    if (! function_exists('pmpro_getMembershipLevelForUser')) {
        return false;
    }

    $option_key = dd_plan_feature_option_key($feature);
    if (empty($option_key)) {
        return false;
    }

    $allowed_levels = get_option($option_key, []);
    if (empty($allowed_levels)) {
        return false;
    }

    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if (! $user_id) {
        return false;
    }

    $level = pmpro_getMembershipLevelForUser($user_id);

    return ! empty($level->id) && in_array((int) $level->id, array_map('intval', $allowed_levels), true);
}

/**
 * The creator-search cap configured for the given (or current) user's PMPro level.
 *
 * Unlike dd_user_can(), this fails OPEN (-1 = unlimited) when nothing is configured for a
 * level, or for logged-out users — matching today's unrestricted search behaviour until an
 * admin explicitly sets a cap for a level under Settings → Influencer Theme → Functionality.
 *
 * @param int|null $user_id
 * @return int -1 for unlimited, otherwise the numeric cap.
 */
function dd_user_search_limit($user_id = null)
{
    if (! function_exists('pmpro_getMembershipLevelForUser')) {
        return -1;
    }

    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if (! $user_id) {
        return -1;
    }

    $level = pmpro_getMembershipLevelForUser($user_id);
    if (empty($level->id)) {
        return -1;
    }

    $limits = get_option('dd_search_limits', []);
    if (! isset($limits[$level->id]) || $limits[$level->id] === '') {
        return -1;
    }

    return (int) $limits[$level->id];
}

/**
 * Searches remaining for the given (or current) user's PMPro level, or null when unlimited
 * (or logged out) — callers should render nothing in that case.
 *
 * @param int|null $user_id
 * @return int|null
 */
function dd_searches_remaining($user_id = null)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if (! $user_id) {
        return null;
    }

    $limit = dd_user_search_limit($user_id);
    if ($limit < 0) {
        return null;
    }

    $used = (int) get_user_meta($user_id, 'number_of_searches', true);
    return max(0, $limit - $used);
}

/**
 * Consistent "upgrade your plan" destination for capability-gate CTAs.
 *
 * @return string
 */
function dd_plan_upgrade_url()
{
    return function_exists('pmpro_url') ? pmpro_url('levels') : home_url();
}
