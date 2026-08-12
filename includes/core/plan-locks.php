<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Visual "grey out + padlock" treatment for premium features a free/lower-tier plan doesn't
 * include — layered on top of the existing dd_user_can() capability gate
 * (includes/core/plan-capabilities.php). This file only decides how a locked feature LOOKS;
 * it never replaces the server-side boundary each feature already enforces independently
 * (the AJAX handlers in saves-manager.php/search.php/outreach.php keep rejecting exactly as
 * before).
 */

/**
 * Registry of the features that can be visually locked — deliberately the same feature keys
 * dd_plan_feature_option_key() already recognizes (includes/core/plan-capabilities.php), so
 * the lock UI can never reference a feature the capability layer doesn't.
 *
 * @return array<string, array{label:string, blurb_key:string}>
 */
function dd_plan_lockable_features()
{
    return [
        'export_pdf'              => [
            'label'     => 'Export PDF',
            'blurb_key' => 'dd_msg_lock_blurb_export_pdf',
        ],
        'outreach'                => [
            'label'     => 'Outreach',
            'blurb_key' => 'dd_msg_lock_blurb_outreach',
        ],
        'saved_lists'              => [
            'label'     => 'Saved Lists',
            'blurb_key' => 'dd_msg_lock_blurb_saved_lists',
        ],
        'custom_outreach_message' => [
            'label'     => 'Custom Outreach Message',
            'blurb_key' => 'dd_msg_lock_blurb_custom_outreach_message',
        ],
        'saved_search'            => [
            'label'     => 'Saved Search',
            'blurb_key' => 'dd_msg_lock_blurb_saved_search',
        ],
    ];
}

/**
 * Wraps $content in a greyed-out/blurred lock overlay when the given (or current) user's plan
 * doesn't include $feature. Returns $content untouched when the user is entitled, when the
 * feature key isn't recognized, or inside the Elementor editor (an admin styling a template
 * should always see the real thing) — same editor guard shortcode_account_notice() uses at
 * includes/core/shortcodes.php.
 *
 * @param string $feature Feature key — see dd_plan_lockable_features().
 * @param string $content Pre-rendered HTML of the real feature UI.
 * @param array  $args    Optional overrides: title, blurb, cta_label, cta_url, user_id.
 * @return string
 */
function dd_render_feature_lock($feature, $content, $args = [])
{
    $features = dd_plan_lockable_features();
    if (! isset($features[$feature])) {
        return $content;
    }

    $is_editor_preview = class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode();
    if ($is_editor_preview) {
        return $content;
    }

    $user_id = isset($args['user_id']) ? (int) $args['user_id'] : null;
    if (function_exists('dd_user_can') && dd_user_can($feature, $user_id)) {
        return $content;
    }

    $definition = $features[$feature];
    $title      = ! empty($args['title']) ? $args['title'] : $definition['label'];
    $blurb      = ! empty($args['blurb'])
        ? $args['blurb']
        : (function_exists('dd_get_message') ? dd_get_message($definition['blurb_key']) : '');
    $cta_label  = ! empty($args['cta_label'])
        ? $args['cta_label']
        : (function_exists('dd_get_message') ? dd_get_message('dd_msg_lock_cta') : 'Upgrade your plan');
    $cta_url    = ! empty($args['cta_url'])
        ? $args['cta_url']
        : (function_exists('dd_plan_upgrade_url') ? dd_plan_upgrade_url() : home_url('/'));

    ob_start();
?>
    <div class="dd-locked" data-feature="<?php echo esc_attr($feature); ?>">
        <div class="dd-locked__content" aria-hidden="true" inert><?php echo $content; ?></div>
        <div class="dd-locked__overlay">
            <span class="dd-locked__icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2" />
                </svg>
            </span>
            <p class="dd-locked__title"><?php echo esc_html($title); ?></p>
            <?php if ($blurb) : ?>
                <p class="dd-locked__blurb"><?php echo esc_html($blurb); ?></p>
            <?php endif; ?>
            <a class="dd-locked__cta" href="<?php echo esc_url($cta_url); ?>"><?php echo esc_html($cta_label); ?></a>
        </div>
    </div>
<?php
    return ob_get_clean();
}
