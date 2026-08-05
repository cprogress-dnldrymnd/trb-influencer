<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for the settings export/import feature. Every option that carries an
 * environment-local ID (page, Elementor template, PMPro level, attachment) is declared here once;
 * the exporter and importer both walk this table rather than each maintaining their own list, so
 * they cannot drift out of sync with each other.
 *
 * spec keys:
 *   class  'page_ref' | 'template_ref' | 'attachment_ref' | 'level_ref' | 'plain'
 *   shape  how the id(s) sit inside the option value — only meaningful for a *_ref class:
 *          'scalar' (value IS the id), 'list' (array of ids), 'keys' (ids are the array keys),
 *          'rows' (array of rows, id at $row[field]), 'json_columns' (JSON string; ids at
 *          decoded['columns'][n][field])
 *   field  row/column key holding the id, for 'rows'/'json_columns' shapes
 *   fail   'closed' | 'open' — only for level_ref: whether dropping an unresolved id from this
 *          option makes the feature MORE or LESS restrictive (see dd_user_can()/dd_user_search_limit()).
 *          Options with fail=>'closed' block the whole option write on any unresolved id unless the
 *          admin explicitly accepts a partial mapping.
 *   urls   true if the value may contain absolute site URLs that should be domain-rewritten
 */
function dd_settings_io_schema()
{
    $schema = [
        // Page assignments
        'dd_search_results_page_id' => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_search_page_id'         => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_dashboard_page_id'      => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_login_redirect_page_id' => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_saved_lists_page_id'    => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_saved_searches_page_id' => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_roi_calculator_page_id' => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_outreach_page_id'       => ['class' => 'page_ref', 'shape' => 'scalar'],
        'dd_buy_credits_page_id'    => ['class' => 'page_ref', 'shape' => 'scalar'],

        // Elementor template assignments
        'dd_tpl_header_nav'           => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_dashboard_content'    => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_dashboard_no_access'  => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_single_influencer'    => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_search_card'          => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_saves_empty'          => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_group_influencer_row' => ['class' => 'template_ref', 'shape' => 'scalar'],
        'dd_tpl_no_data_fallback'     => ['class' => 'template_ref', 'shape' => 'scalar'],

        // PMPro-level allow-lists — fail CLOSED (dd_user_can() treats an empty list as "nobody")
        'dd_export_pdf_allowed_levels'              => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],
        'dd_outreach_allowed_levels'                => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],
        'dd_saved_lists_allowed_levels'              => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],
        'dd_custom_outreach_message_allowed_levels' => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],
        'dd_saved_search_allowed_levels'            => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],
        'dd_trial_levels'                           => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],
        'dd_hide_checkout_sidebar_levels'           => ['class' => 'level_ref', 'shape' => 'list', 'fail' => 'closed'],

        // Single-level scalar — not a dd_user_can() gate, so no lockout risk either way
        'dd_free_level_id' => ['class' => 'level_ref', 'shape' => 'scalar', 'fail' => 'open'],

        // Per-level numeric map — fail OPEN (dd_user_search_limit() treats a missing level as unlimited)
        'dd_search_limits' => ['class' => 'level_ref', 'shape' => 'keys', 'fail' => 'open'],

        // Level-keyed repeater rows
        'dd_pmpro_rewards_settings' => ['class' => 'level_ref', 'shape' => 'rows', 'field' => 'level_id', 'fail' => 'closed'],

        // JSON-encoded columns, one of which is a level id; also carries CTA URLs
        'dd_feature_comparison_table' => ['class' => 'level_ref', 'shape' => 'json_columns', 'field' => 'level_id', 'fail' => 'closed', 'urls' => true, 'slash_on_write' => true],

        // Attachment IDs
        'dd_platform_icon_instagram' => ['class' => 'attachment_ref', 'shape' => 'scalar'],
        'dd_platform_icon_youtube'   => ['class' => 'attachment_ref', 'shape' => 'scalar'],
        'dd_platform_icon_tiktok'    => ['class' => 'attachment_ref', 'shape' => 'scalar'],

        // Plain values — copied verbatim (optionally URL-rewritten)
        'dd_public_email_domains'     => ['class' => 'plain'],
        'dd_global_header'            => ['class' => 'plain', 'urls' => true],
        'dd_global_footer'            => ['class' => 'plain', 'urls' => true],
        'dd_outreach_email_templates' => ['class' => 'plain', 'urls' => true],
        'dd_outreach_credit_cost'     => ['class' => 'plain'],
        'dd_outreach_default_message' => ['class' => 'plain', 'urls' => true],
        'dd_outreach_project_types'   => ['class' => 'plain'],
        'dd_outreach_project_lengths' => ['class' => 'plain'],

        // Carries a WP user ID (test_user_id) that is meaningless on another site — handled specially
        // by the exporter/importer rather than as a generic 'plain' value.
        'dd_pmpro_rewards_general' => ['class' => 'rewards_general'],
    ];

    if (function_exists('dd_message_definitions')) {
        foreach (array_keys(dd_message_definitions()) as $key) {
            $schema[$key] = ['class' => 'plain'];
        }
    }

    return apply_filters('dd_settings_io_schema', $schema);
}

/**
 * Options that must never travel in a bundle — runtime/environment state, not configuration.
 * The exporter asserts the schema never intersects this list, so a future addition to the schema
 * can't accidentally include one of these by copy-paste.
 */
function dd_settings_io_denylist()
{
    return [
        'dd_group_meta_migrated',
        'dd_company_trial_backfill_done',
        'dd_pmpro_logs_live',
        'dd_pmpro_logs_test',
    ];
}

function dd_settings_io_denylist_prefixes()
{
    return ['dd_desc_', 'dd_cta_'];
}
