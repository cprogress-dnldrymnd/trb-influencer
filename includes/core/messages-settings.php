<?php
if (! defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Editable user-facing messages/notices — a "Messages" tab on the Influencer
// Theme settings hub (see includes/core/admin-settings.php for the tab
// mechanism). One central registry drives the settings fields, the PHP
// accessor (dd_get_message), and the JS payload (dd_js_messages) so every
// call site stays in sync.
// ---------------------------------------------------------------------------

/**
 * Single source of truth for every editable message: its option key, label,
 * default text, settings-tab group, and whether it must also reach front-end
 * JS. `multiline` renders a textarea instead of a text input; `html` allows a
 * small set of inline tags (used by the two messages that carry <strong>).
 *
 * @return array<string, array{label:string, default:string, group:string, desc:string, js?:bool, multiline?:bool, html?:bool}>
 */
function dd_message_definitions()
{
    return [
        // --- Plan & Upgrade Prompts -----------------------------------------------------
        'dd_msg_search_limit' => [
            'label'   => 'Search Limit Reached',
            'default' => "You've reached your plan's creator search limit.",
            'group'   => 'plan_gates',
            'desc'    => 'Shown when a user hits their plan\'s creator search cap (search page redirect, AJAX response, and the client-side popup).',
            'js'      => true,
        ],
        'dd_msg_company_trial_block' => [
            'label'   => 'Company Trial Limit',
            'default' => 'Your company already has a trial account. Upgrade to a paid plan to search for creators.',
            'group'   => 'plan_gates',
            'desc'    => 'Shown instead of the normal search-limit notice when a second trial account from the same company email domain tries to search.',
            'js'      => true,
        ],
        'dd_msg_export_pdf_gate' => [
            'label'   => 'Export PDF Gate',
            'default' => 'Exporting saved lists to PDF is not available on your current plan. Upgrade your plan to unlock this feature.',
            'group'   => 'plan_gates',
            'desc'    => 'Shown when a user without PDF export access tries to export a saved list.',
            'js'      => true,
        ],
        'dd_msg_save_search_gate' => [
            'label'   => 'Save Search Gate (popup)',
            'default' => 'Saving searches is not available on your current plan. Upgrade your plan to unlock this feature.',
            'group'   => 'plan_gates',
            'desc'    => 'Client-side confirm shown when clicking a locked "Save this search" trigger.',
            'js'      => true,
        ],
        'dd_msg_saved_lists_gate' => [
            'label'   => 'Saved Lists Gate',
            'default' => 'Your plan does not include saved lists. Please upgrade to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Server-side rejection when saving/updating an influencer to a list without access.',
        ],
        'dd_msg_saved_search_gate' => [
            'label'   => 'Saved Search Gate',
            'default' => 'Your plan does not include saved searches. Please upgrade to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Server-side rejection when saving a search without access.',
        ],
        'dd_msg_outreach_gate' => [
            'label'   => 'Outreach/Contact Gate',
            'default' => 'Your plan does not include contacting creators. Please upgrade to continue.',
            'group'   => 'plan_gates',
            'desc'    => 'Server-side rejection when submitting the outreach form without access.',
        ],
        'dd_msg_contact_upgrade_tooltip' => [
            'label'   => 'Contact Button Upgrade Tooltip',
            'default' => 'Upgrade your plan to contact creators',
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the "Upgrade to contact" button for unlocked creators when outreach isn\'t included in the plan.',
        ],
        'dd_msg_contact_upgrade_btn' => [
            'label'   => 'Contact Button Upgrade Label',
            'default' => 'Upgrade to contact',
            'group'   => 'plan_gates',
            'desc'    => 'Button text shown in place of the contact button when outreach isn\'t included in the plan.',
        ],
        'dd_msg_save_upgrade_tooltip' => [
            'label'   => 'Save Button Upgrade Tooltip',
            'default' => 'Upgrade your plan to save creators',
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the disabled save-to-list button when saved lists aren\'t included in the plan.',
        ],
        'dd_msg_save_search_upgrade_tooltip' => [
            'label'   => 'Save Search Upgrade Tooltip',
            'default' => 'Upgrade your plan to save searches',
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the locked "Save this search" trigger when saved search isn\'t included in the plan.',
        ],
        'dd_msg_unlock_locked_hint' => [
            'label'   => 'Unlock First Hint',
            'default' => "Unlock this creator's full profile first",
            'group'   => 'plan_gates',
            'desc'    => 'Tooltip on the disabled save/contact buttons for a creator the user hasn\'t unlocked yet.',
        ],

        // --- Unlock & Credit Confirmations -----------------------------------------------
        'dd_msg_unlock_spend_confirm' => [
            'label'   => 'Unlock Spend Confirmation',
            'default' => "You're about to spend 1 credit to unlock this creator's contact information. Credits are non-refundable once spent — would you like to continue?",
            'group'   => 'unlock',
            'desc'    => 'Confirm dialog shown before spending a credit via the myCred "buy content" button.',
            'js'      => true,
        ],
        'dd_msg_unlock_modal_body' => [
            'label'     => 'Unlock Confirm Modal Body',
            'default'   => 'Unlocking this creator will deduct <strong>1 credit</strong> from your balance and automatically add them to your <strong>"Unlocked Influencers"</strong> saved list.',
            'group'     => 'unlock',
            'desc'      => 'Body text of the "Unlock Creator" confirmation modal. Supports <strong> tags.',
            'multiline' => true,
            'html'      => true,
        ],
        'dd_msg_creator_unlocked_heading' => [
            'label'   => 'Creator Unlocked Notice Heading',
            'default' => 'Creator Unlocked',
            'group'   => 'unlock',
            'desc'    => 'Heading of the toast notice shown right after a creator is unlocked.',
        ],
        'dd_msg_creator_unlocked_body' => [
            'label'     => 'Creator Unlocked Notice Body',
            'default'   => '1 credit deducted. New balance: <strong>%s</strong>.',
            'group'     => 'unlock',
            'desc'      => 'Body of the toast notice shown right after a creator is unlocked. Keep the %s token — it is replaced with the new credit balance.',
            'multiline' => true,
            'html'      => true,
        ],

        // --- Confirmation Dialogs ---------------------------------------------------------
        'dd_msg_confirm_delete_group' => [
            'label'   => 'Delete Group Confirmation',
            'default' => 'Are you sure you want to delete this group? This will remove the group from all saved creators.',
            'group'   => 'confirm',
            'desc'    => 'Shown before deleting a saved-creators group.',
            'js'      => true,
        ],
        'dd_msg_confirm_remove_creator' => [
            'label'   => 'Remove Creator Confirmation',
            'default' => 'Are you sure you want to remove this creator from the current group?',
            'group'   => 'confirm',
            'desc'    => 'Shown before removing a single creator from a group.',
            'js'      => true,
        ],
        'dd_msg_confirm_delete_saved_search' => [
            'label'   => 'Delete Saved Search Confirmation',
            'default' => 'Are you sure you want to permanently delete this saved search?',
            'group'   => 'confirm',
            'desc'    => 'Shown before deleting a saved search.',
            'js'      => true,
        ],
        'dd_msg_confirm_delete_note' => [
            'label'   => 'Delete Outreach Note Confirmation',
            'default' => 'Are you sure you want to permanently delete this note?',
            'group'   => 'confirm',
            'desc'    => 'Shown before deleting an outreach project note.',
            'js'      => true,
        ],

        // --- Success Notices ---------------------------------------------------------------
        'dd_msg_saved_success' => [
            'label'   => 'Creator Saved',
            'default' => 'Saved successfully!',
            'group'   => 'success',
            'desc'    => 'Returned after saving a creator to one or more lists.',
        ],
        'dd_msg_unsaved_success' => [
            'label'   => 'Creator Unsaved',
            'default' => 'Unsaved successfully!',
            'group'   => 'success',
            'desc'    => 'Returned after removing a creator from every list.',
        ],
        'dd_msg_search_saved_heading' => [
            'label'   => 'Search Saved Notice Heading',
            'default' => 'Search Saved',
            'group'   => 'success',
            'desc'    => 'Heading of the toast shown after saving a search.',
            'js'      => true,
        ],
        'dd_msg_search_saved_body' => [
            'label'   => 'Search Saved Notice Body',
            'default' => 'Your custom search has been successfully saved.',
            'group'   => 'success',
            'desc'    => 'Body of the toast shown after saving a search.',
            'js'      => true,
        ],
        'dd_msg_creator_removed' => [
            'label'   => 'Creator Removed',
            'default' => 'Creator removed successfully.',
            'group'   => 'success',
            'desc'    => 'Returned after removing a creator from a specific group.',
        ],
        'dd_msg_group_deleted' => [
            'label'   => 'Group Deleted',
            'default' => 'Group deleted.',
            'group'   => 'success',
            'desc'    => 'Returned after deleting a saved-creators group.',
        ],

        // --- Validation ---------------------------------------------------------------------
        'dd_msg_filter_required' => [
            'label'   => 'Required Filter Missing',
            'default' => 'Please populate all required filters (e.g., Location) before generating matches.',
            'group'   => 'validation',
            'desc'    => 'Shown when the filtered-search form is submitted without a required field.',
            'js'      => true,
        ],
        'dd_msg_group_name_required' => [
            'label'   => 'Group Name Required',
            'default' => 'Group name is required.',
            'group'   => 'validation',
            'desc'    => 'Shown when creating/editing a group without a name.',
            'js'      => true,
        ],
        'dd_msg_search_name_required' => [
            'label'   => 'Search Name Required',
            'default' => 'Please enter a name for your search.',
            'group'   => 'validation',
            'desc'    => 'Shown when confirming a saved search without a name.',
            'js'      => true,
        ],
    ];
}

/**
 * Resolves an editable message's current value, falling back to its registry
 * default. Pass $args to interpolate %s-style tokens via vsprintf (e.g. the
 * unlocked-balance notice).
 *
 * @param string $key
 * @param array  $args
 * @return string
 */
function dd_get_message($key, $args = [])
{
    $definitions = dd_message_definitions();
    $default     = isset($definitions[$key]) ? $definitions[$key]['default'] : '';
    $value       = get_option($key, $default);

    if (! empty($args)) {
        return vsprintf($value, $args);
    }

    return $value;
}

/**
 * Returns only the messages flagged for front-end JS, keyed by option name,
 * for localization onto the `dd_messages` global.
 *
 * @return array<string, string>
 */
function dd_js_messages()
{
    $out = [];
    foreach (dd_message_definitions() as $key => $def) {
        if (! empty($def['js'])) {
            $out[$key] = dd_get_message($key);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Tab registration — appends onto the Influencer Theme settings hub via the
// same dd_theme_settings_tabs filter every other module tab uses.
// ---------------------------------------------------------------------------
add_filter('dd_theme_settings_tabs', function ($tabs) {
    $tabs[] = [
        'id'     => 'messages',
        'label'  => 'Messages',
        'render' => 'dd_render_messages_tab_panel',
    ];
    return $tabs;
});

/**
 * Field renderer shared by every message setting — a text input or textarea
 * depending on the registry entry, plus its description.
 *
 * @param array $args
 */
function dd_render_message_field($args)
{
    $value = dd_get_message($args['key']);

    if (! empty($args['multiline'])) {
        echo '<textarea name="' . esc_attr($args['key']) . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
    } else {
        echo '<input type="text" name="' . esc_attr($args['key']) . '" value="' . esc_attr($value) . '" class="large-text" />';
    }

    if (! empty($args['desc'])) {
        echo '<p class="description">' . esc_html($args['desc']) . '</p>';
    }
}

add_action('admin_init', function () {
    $definitions = dd_message_definitions();
    $groups = [
        'plan_gates' => 'Plan & Upgrade Prompts',
        'unlock'     => 'Unlock & Credit Confirmations',
        'confirm'    => 'Confirmation Dialogs',
        'success'    => 'Success Notices',
        'validation' => 'Validation',
    ];

    foreach ($groups as $group_key => $group_label) {
        add_settings_section('dd_messages_section_' . $group_key, $group_label, '__return_false', 'dd-messages-settings');
    }

    foreach ($definitions as $key => $def) {
        register_setting('dd_messages_group', $key, [
            'type'              => 'string',
            'sanitize_callback' => function ($value) use ($def) {
                $value = (string) $value;

                // Blank means "reset to default" rather than persisting an empty notice.
                if (trim(wp_strip_all_tags($value)) === '') {
                    return $def['default'];
                }

                if (! empty($def['html'])) {
                    return wp_kses($value, ['strong' => [], 'br' => [], 'em' => [], 'b' => [], 'i' => []]);
                }

                if (! empty($def['multiline'])) {
                    return sanitize_textarea_field($value);
                }

                return sanitize_text_field($value);
            },
            'default' => $def['default'],
        ]);

        add_settings_field(
            $key,
            $def['label'],
            'dd_render_message_field',
            'dd-messages-settings',
            'dd_messages_section_' . $def['group'],
            [
                'key'       => $key,
                'multiline' => ! empty($def['multiline']),
                'desc'      => $def['desc'] ?? '',
            ]
        );
    }
});

/**
 * Self-contained "Messages" tab panel body (the hub provides the surrounding
 * `<div class="dd-panel">`).
 */
function dd_render_messages_tab_panel()
{
    if (! current_user_can('manage_options')) {
        return;
    }
?>
    <p class="dd-tab-desc">Edit the wording of notices, confirmations, and prompts shown to users across the site. Leaving a field blank and saving restores its default text.</p>
    <form action="options.php" method="post">
        <?php
        settings_fields('dd_messages_group');
        do_settings_sections('dd-messages-settings');
        submit_button('Save Messages');
        ?>
    </form>
<?php
}
