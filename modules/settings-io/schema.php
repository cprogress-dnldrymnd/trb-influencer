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
 *   fail   'closed' | 'open' — whether dropping an unresolved id from this option makes the
 *          underlying feature MORE or LESS restrictive. Every *_ref class defaults to 'closed'
 *          (a page/template/level/attachment that fails to resolve must never write a wrong or
 *          zeroed id over an existing one) — the one exception is 'open' options like
 *          dd_search_limits, where a missing entry means "unlimited", i.e. LESS restrictive, so
 *          the safe default there is to drop it rather than block the whole option.
 *          'closed' blocks the whole option write on any unresolved id unless the admin
 *          explicitly accepts a partial mapping for that option.
 *   urls   true if the value may contain absolute site URLs that should be domain-rewritten
 */
function dd_settings_io_schema()
{
    $schema = [
        // Page assignments
        'dd_search_results_page_id' => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_search_page_id'         => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_dashboard_page_id'      => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_login_redirect_page_id' => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_saved_lists_page_id'    => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_saved_searches_page_id' => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_roi_calculator_page_id' => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_outreach_page_id'       => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_buy_credits_page_id'    => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],

        // PMPro's own core page assignments (includes/init.php in the paid-memberships-pro plugin).
        // These are PMPro's own options, not dd_* — writing a wrong/zeroed value here doesn't just
        // break a theme widget, it breaks checkout, so 'fail' => 'closed' matters even more here
        // than on the dd_* page options above.
        'pmpro_account_page_id'              => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_billing_page_id'              => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_cancel_page_id'               => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_checkout_page_id'             => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_confirmation_page_id'         => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_invoice_page_id'              => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_levels_page_id'               => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_login_page_id'                => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'pmpro_member_profile_edit_page_id'  => ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'],

        // Elementor template assignments
        'dd_tpl_header_nav'           => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_dashboard_content'    => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_dashboard_no_access'  => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_single_influencer'    => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_search_card'          => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_saves_empty'          => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_group_influencer_row' => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_tpl_no_data_fallback'     => ['class' => 'template_ref', 'shape' => 'scalar', 'fail' => 'closed'],

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
        'dd_platform_icon_instagram' => ['class' => 'attachment_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_platform_icon_youtube'   => ['class' => 'attachment_ref', 'shape' => 'scalar', 'fail' => 'closed'],
        'dd_platform_icon_tiktok'    => ['class' => 'attachment_ref', 'shape' => 'scalar', 'fail' => 'closed'],

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

    // PMPro add-ons can register further page options beyond the core nine above (e.g. a
    // membership-directory add-on's own page) via the same filter PMPro itself reads in
    // includes/init.php. None are registered on this site today, but picking them up here means
    // a future add-on's page assignment travels with the bundle automatically.
    if (function_exists('apply_filters')) {
        $extra_pages = apply_filters('pmpro_extra_page_settings', []);
        foreach (array_keys((array) $extra_pages) as $name) {
            $schema['pmpro_' . $name . '_page_id'] = ['class' => 'page_ref', 'shape' => 'scalar', 'fail' => 'closed'];
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

/**
 * Classification for page post-meta keys carried by the page-content transfer (DD_Page_Transfer).
 * The default posture is "copy everything" (per product decision), but a handful of keys are
 * environment-local IDs that need remapping rather than a verbatim copy, and a further set is
 * either derived/cached state or another plugin's source-environment bookkeeping that must never
 * cross environments at all (see dd_page_meta_denylist*() below) — verified against the actual
 * postmeta on this site's assigned pages, not guessed.
 *
 * class: 'level_ref' | 'attachment_ref' — same $ref-marker/remap treatment as the option schema.
 * Anything not listed here, and not denylisted, is copied verbatim ('plain').
 */
function dd_page_meta_classes()
{
    return apply_filters('dd_settings_io_page_meta_classes', [
        'pmpro_default_level'                => ['class' => 'level_ref', 'fail' => 'closed'],
        '_thumbnail_id'                      => ['class' => 'attachment_ref'],
        '_yoast_wpseo_opengraph-image-id'    => ['class' => 'attachment_ref'],
        '_yoast_wpseo_twitter-image-id'      => ['class' => 'attachment_ref'],
    ]);
}

/**
 * Exact meta keys never copied: derived/cached Elementor state, another plugin's source-post
 * bookkeeping, editing-session state, and this module's own identity stamp.
 */
function dd_page_meta_denylist()
{
    return apply_filters('dd_settings_io_page_meta_denylist', [
        '_edit_lock', '_edit_last',
        '_elementor_data',            // handled via the document content pipeline, not raw meta
        '_elementor_page_settings',   // handled via document `settings`, not raw meta
        '_wp_page_template',          // handled via document `settings['template']`, not raw meta
        '_elementor_edit_mode',       // set by documents->create()/Document::save() themselves
        '_elementor_template_type',   // set by documents->create()/Document::save() themselves
        '_elementor_conditions',      // theme-builder locations only — not meaningful for a page;
                                       // see DD_Template_Transfer for the (template-side) narrow rewrite
        '_elementor_css',
        '_elementor_element_cache',
        '_elementor_page_assets',
        '_elementor_version',
        '_elementor_pro_version',
        '__elementor_forms_snapshot',
        '_wp_old_slug',
        '_wp_old_date',
        '_dp_original',               // Duplicate Post plugin — a raw SOURCE-SITE post ID
        '_dd_settings_io_uid',        // owned by DD_Settings_Refs::stamp_post_uid(), never generic-copied
    ]);
}

function dd_page_meta_denylist_prefixes()
{
    return apply_filters('dd_settings_io_page_meta_denylist_prefixes', [
        '_elementor_migrations_state_',
    ]);
}
