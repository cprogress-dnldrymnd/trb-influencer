<?php

/**
 * Plugin Name: DD Outreach Manager
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Manages Elementor form submissions for outreach and provides dynamic shortcode views for project management.
 * Version: 1.4.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly for security.
}

/**
 * Class DD_Outreach_Manager
 * Handles Elementor form interception, dynamic HTML generation, and shortcode
 * rendering for the master-detail outreach dashboard.
 */
class DD_Outreach_Manager
{

    /**
     * Initializes the class, registers hooks, shortcodes, and AJAX endpoints.
     * @return void
     */
    public function __construct()
    {
        // Global Styles
        add_action('wp_head', [$this, 'inject_global_styles']);

        // Legacy/Existing Form Functionality
        add_action('elementor_pro/forms/new_record', [$this, 'process_elementor_form_response'], 10, 2);
        add_action('wp_footer', [$this, 'inject_elementor_success_scripts']);

        // New Master-Detail Dashboard Functionality
        add_shortcode('dd_outreach_list', [$this, 'render_list_shortcode']);
        add_shortcode('dd_outreach_view', [$this, 'render_view_shortcode']);

        // AJAX Handlers for dynamic viewing
        add_action('wp_ajax_dd_get_outreach_details', [$this, 'ajax_get_outreach_details']);

        // Enqueue necessary scripts for the interactive dashboard
        add_action('wp_enqueue_scripts', [$this, 'enqueue_dashboard_scripts']);
    }

    /**
     * Outputs consolidated CSS into the document <head>.
     * Restores the exact original CSS for the form summary and applies it to the view.
     *
     * @return void
     */
    public function inject_global_styles()
    {
?>
        <style>
            /* --- Original Elementor Form Summary Styles --- */
            .dd-message-overview {
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                font-size: 16px;
                font-weight: 500;
            }

            .dd-message-overview-container {
                font-family: inherit;
                margin-top: 15px;
                border-radius: 5px;
                border: 1px solid #3B1527;
            }

            .dd-profile-header {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
                border-bottom: 1px solid #E7E7E7;
            }

            .dd-avatar {
                width: 50px;
                height: 50px;
                border-radius: 50%;
            }

            .dd-profile-info {
                flex-grow: 1;
                line-height: 1.4;
            }

            .dd-message-sent-date {
                padding: 15px 20px;
                border-bottom: 1px solid #E7E7E7;

            }

            .dd-overview-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                font-size: 14px;
                font-weight: bold;
            }

            .dd-overview-header .dd-timestamp {
                font-weight: normal;
                color: #555;
            }

            .dd-btn-outline {
                background-color: var(--e-global-color-1c4ea17);
                font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif;
                font-size: var(--e-global-typography-2a20fd0-font-size);
                font-weight: var(--e-global-typography-2a20fd0-font-weight);
                line-height: var(--e-global-typography-2a20fd0-line-height);
                letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing);
                fill: var(--e-global-color-accent);
                color: var(--e-global-color-accent);
                border: 1px solid var(--e-global-color-accent);
                padding: 14px 23px 14px 23px;
                border-radius: 5px;
            }

            .dd-message-overview-container .tags-container.tags-container.tags-container {
                padding: 15px 20px;
                margin: 0;
                border-bottom: 1px solid #E7E7E7;
            }

            .dd-subject-title {
                color: #034146;
                font-size: 18px !important;
                font-weight: bold;
                margin: 0;
                border-bottom: 1px solid #E7E7E7;
                font-family: Inter !important;
                padding: 15px 20px;
            }

            .dd-message-content {
                font-size: 15px;
                color: #000000;
                line-height: 1.6;
                max-height: 300px;
                overflow-y: auto;
                padding: 15px 20px;
                font-family: Inter;
            }

            .dd-footer {
                display: flex;
                gap: 15px;
                margin-top: 15px;
            }

            .dd-footer a {
                font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif;
                font-size: var(--e-global-typography-2a20fd0-font-size);
                font-weight: var(--e-global-typography-2a20fd0-font-weight);
                line-height: var(--e-global-typography-2a20fd0-line-height);
                letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing);
            }

            .view-outreach a {
                background-color: var(--e-global-color-accent) !important;
                border: 1px solid var(--e-global-color-accent);
                color: #fff !important;
            }

            .close-outreach a {
                border-style: solid;
                border-color: var(--e-global-color-ee06e41) !important;
                background-color: transparent !important;
                color: var(--e-global-color-ee06e41) !important;
            }

            /* --- Dashboard List Navigation Styles --- */
            .dd-dashboard-list-container {
                background: #fdfdfd;
                border: 1px solid #eaeaea;
                border-radius: 8px;
                padding: 20px;
                width: 100%;
                max-width: 350px;
            }

            .dd-filter-controls {
                margin-bottom: 20px;
            }

            .dd-list-search {
                width: 100%;
                margin-bottom: 15px;
                padding: 10px;
                border-radius: 4px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }

            .dd-filter-label-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }

            .dd-filter-reset {
                font-size: 12px;
                color: #888;
                text-decoration: none;
            }

            .dd-filter-select {
                width: 100%;
                padding: 10px;
                border-radius: 4px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }

            .dd-filter-buttons-row {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }

            .dd-filter-btn {
                border-radius: 20px;
                border: 1px solid #ccc;
                background: transparent;
                padding: 5px 15px;
                cursor: pointer;
                font-size: 13px;
            }

            .dd-filter-btn.active {
                border-color: #4DB2A6;
                background: #E6F4F1;
                color: #4DB2A6;
            }

            .dd-item-list {
                max-height: 600px;
                overflow-y: auto;
            }

            .dd-outreach-item {
                display: flex;
                align-items: center;
                padding: 15px 10px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
                transition: background 0.2s;
                border-radius: 6px;
            }

            .dd-outreach-item:hover,
            .dd-outreach-item.active-item {
                background: #f4f4f4;
            }

            .dd-item-avatar {
                width: 45px;
                height: 45px;
                border-radius: 50%;
                object-fit: cover;
                margin-right: 15px;
            }

            .dd-item-content {
                flex-grow: 1;
            }

            .dd-item-name {
                display: block;
                font-size: 14px;
                font-weight: bold;
                color: #333;
            }

            .dd-item-handle {
                display: block;
                color: #777;
                font-size: 12px;
            }

            .dd-item-title {
                display: block;
                font-size: 12px;
                color: #4DB2A6;
                font-weight: bold;
                margin-top: 4px;
                text-overflow: ellipsis;
                overflow: hidden;
                white-space: nowrap;
                max-width: 200px;
            }

            .dd-item-date {
                color: #aaa;
                font-size: 11px;
            }

            /* --- Notes Component Styles --- */
            .dd-outreach-view-container {
                background: #f4f4f4;
                border-radius: 8px;
                padding: 30px;
                min-height: 600px;
                width: 100%;
                box-sizing: border-box;
            }

            .dd-view-placeholder {
                text-align: center;
                color: #888;
                margin-top: 50%;
                transform: translateY(-50%);
                display: block;
            }

            .dd-view-error {
                text-align: center;
                color: red;
                margin-top: 50%;
                transform: translateY(-50%);
                display: block;
            }

            .dd-notes-grid {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
                margin-top: 20px;
            }

            .dd-note-card {
                flex: 1;
                min-width: 280px;
                background: #fff;
                border: 1px solid #ffcc00;
                border-radius: 8px;
                padding: 20px;
                box-sizing: border-box;
            }

            .dd-steps-card {
                flex: 1;
                min-width: 280px;
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                box-sizing: border-box;
            }

            .dd-note-title {
                margin-top: 0;
                font-size: 16px;
                margin-bottom: 5px;
                color: #333;
            }

            .dd-note-desc {
                font-size: 12px;
                color: #888;
                margin-bottom: 15px;
            }

            .dd-note-input {
                width: 100%;
                margin-bottom: 10px;
                padding: 10px;
                border: 1px solid #eee;
                border-radius: 4px;
                box-sizing: border-box;
                font-family: inherit;
            }

            .dd-note-textarea {
                width: 100%;
                height: 80px;
                padding: 10px;
                border: 1px solid #eee;
                border-radius: 4px;
                box-sizing: border-box;
                resize: vertical;
                font-family: inherit;
            }

            .dd-note-btn {
                margin-top: 10px;
                background: #ffcc00;
                border: none;
                padding: 10px 20px;
                border-radius: 4px;
                font-weight: bold;
                cursor: pointer;
                color: #333;
                font-size: 13px;
            }

            .dd-steps-content {
                background: #fdf5e6;
                padding: 15px;
                border-radius: 8px;
                font-size: 13px;
                color: #444;
                margin-bottom: 15px;
                line-height: 1.5;
            }

            .dd-steps-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }

            .dd-delete-btn {
                border: none;
                background: transparent;
                color: #aaa;
                cursor: pointer;
                font-size: 12px;
            }

            .dd-edit-btn {
                border: 1px solid #ddd;
                background: #f9f9f9;
                padding: 8px 15px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
                color: #333;
            }

            .dd-last-edited {
                text-align: right;
                font-size: 11px;
                color: #ccc;
                margin-top: 10px;
            }
        </style>
    <?php
    }

    /**
     * Intercepts the Elementor Form submission to compile a custom HTML payload.
     * Generates a new 'outreach' post type entry.
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
     * @return void
     */
    public function process_elementor_form_response($record, $ajax_handler)
    {
        $form_id = $record->get_form_settings('form_id');

        if ('outreach_form' !== $form_id) {
            return;
        }

        $raw_fields = $record->get('fields');
        $data       = [];

        foreach ($raw_fields as $id => $field) {
            $data[$id] = $field['value'];
        }

        $current_user_id = get_current_user_id();

        $post_title = !empty($data['subject']) ? sanitize_text_field($data['subject']) : 'Outreach Submission - ' . current_time('Y-m-d H:i:s');

        $new_post_args = [
            'post_title'  => $post_title,
            'post_type'   => 'outreach',
            'post_status' => 'publish',
            'post_author' => $current_user_id,
        ];

        $post_id = wp_insert_post($new_post_args);

        if (!is_wp_error($post_id)) {
            foreach ($data as $meta_key => $meta_value) {
                $sanitized_value = ('message' === $meta_key) ? sanitize_textarea_field($meta_value) : sanitize_text_field($meta_value);
                update_post_meta($post_id, sanitize_key($meta_key), $sanitized_value);
            }


            if (function_exists('deduct_points_from_current_user')) {
                deduct_points_from_current_user(1, 'Outreach Form Submission');
            }

            if (function_exists('mycred_get_users_cred')) {
                $updated_points = mycred_get_users_cred($current_user_id);
                $ajax_handler->add_response_data('updated_points', $updated_points);
            }
        }

        $date_sent = date_i18n(get_option('date_format'));

        ob_start();
    ?>
        <div class="dd-message-overview">
            <span class="m-overview">Message overview</span>
            <span class="date">Sent at <?php echo esc_html($date_sent); ?></span>
        </div>
        <div class="dd-message-overview-container">
            <div class="dd-profile-header">
                <img src="<?= wp_get_attachment_image_url(get_post_thumbnail_id(get_the_ID()), 'medium') ?>" alt="Profile" class="dd-avatar">
                <div class="dd-profile-info">
                    <strong><?php echo get_the_title(); ?></strong><br>
                    <small>@<?php echo get_post_meta(get_the_ID(), 'instagramId', true); ?></small>
                </div>
                <a href="<?= get_the_permalink() ?>" class="dd-btn-outline">VIEW CREATOR PROFILE</a>
            </div>
            <div class="dd-overview-body">
                <div class="tags-container tags-container tags-container">
                    <span class="tag"><strong>Project type:</strong> <?php echo esc_html($data['project_type'] ?? 'N/A'); ?></span>
                    <span class="tag"><strong>Project length:</strong> <?php echo esc_html($data['project_length'] ?? 'N/A'); ?></span>
                    <span class="tag"><strong>Project Dates:</strong> <?php echo esc_html($data['project_dates'] ?? 'Flexible'); ?></span>
                    <span class="tag"><strong>Budget:</strong> <?php echo esc_html($data['budget'] ?? 'To be discussed'); ?></span>
                </div>

                <h3 class="dd-subject-title"><?php echo esc_html($data['subject'] ?? 'No Subject'); ?></h3>

                <div class="dd-message-content">
                    <?php echo nl2br(esc_html($data['message'] ?? 'No message provided.')); ?>
                </div>
            </div>
        </div>
        <div class="dd-footer">
            <div class="button-box view-outreach">
                <a class="elementor-button elementor-button-link elementor-size-sm" href="#">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17.081" height="18.396" viewBox="0 0 17.081 18.396">
                                <g id="outreach_icon" data-name="outreach icon" transform="translate(-9.375 -6.25)">
                                    <path id="Path_139" data-name="Path 139" d="M40.076,38.442a.649.649,0,0,1,.414.414l.69,2.069,1.59-4.77L38,37.745l2.069.69Z" transform="translate(-22.607 -23.619)" fill="#fff"></path>
                                    <path id="Path_140" data-name="Path 140" d="M17.916,6.25a8.532,8.532,0,0,0-6.563,13.993l-1.281,3.521a.668.668,0,0,0,.151.69.66.66,0,0,0,.69.151l4.526-1.642a8.38,8.38,0,0,0,2.477.368,8.541,8.541,0,1,0,0-17.081Zm3.909,5.466L19.2,19.6a.657.657,0,0,1-.624.447.666.666,0,0,1-.624-.447L16.74,15.967l-3.633-1.209a.657.657,0,0,1-.447-.624.666.666,0,0,1,.447-.624l7.884-2.628a.647.647,0,0,1,.67.158.664.664,0,0,1,.158.67Z" fill="#fff"></path>
                                </g>
                            </svg>
                        </span>
                        <span class="elementor-button-text">VIEW IN OUTREACH</span>
                    </span>
                </a>
            </div>
            <div class="button-box close-outreach">
                <a class="elementor-button elementor-button-link elementor-size-sm" href="#">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-text">CLOSE</span>
                    </span>
                </a>
            </div>
        </div>
    <?php
        $custom_html = ob_get_clean();
        $ajax_handler->add_response_data('dd_custom_html', $custom_html);
    }

    /**
     * Injects frontend JavaScript for Elementor AJAX response.
     *
     * @return void
     */
    public function inject_elementor_success_scripts()
    {
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).on('submit_success', function(event, response) {
                        if (response && response.data && response.data.dd_custom_html) {
                            var $summaryTarget = jQuery('#outreach-form-summary');
                            var $pointsTarget = jQuery('.current-points');

                            if ($summaryTarget.length) {
                                $summaryTarget.html(response.data.dd_custom_html);
                                jQuery('#outreach-submission').addClass('hide-element');
                                jQuery('#outreach-summary').removeClass('hide-element');

                                if ($pointsTarget.length) {
                                    if (response.data.updated_points !== undefined) {
                                        $pointsTarget.text(response.data.updated_points);
                                    } else {
                                        var currentPointsStr = $pointsTarget.text().replace(/,/g, '');
                                        var currentVal = parseInt(currentPointsStr, 10);
                                        if (!isNaN(currentVal) && currentVal > 0) {
                                            $pointsTarget.text(currentVal - 1);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * Renders the left-side panel (list and filters) via shortcode [dd_outreach_list].
     *
     * @param array $atts Shortcode attributes.
     * @return string Compiled HTML block.
     */
    public function render_list_shortcode($atts)
    {
        if (! is_user_logged_in()) {
            return '<p>Please log in to view your projects.</p>';
        }

        $args = [
            'post_type'      => 'outreach',
            'posts_per_page' => -1,
            'author'         => get_current_user_id(),
            'post_status'    => 'publish'
        ];

        $query = new WP_Query($args);

        ob_start();
    ?>
        <div class="dd-dashboard-list-container">
            <div class="influencer-search-filter-holder">
                <div class="influencer-search-item">
                    <?= select_filter('project_type', 'Project type', '', $influencer_search_fields['project_type'] ?? '') ?>
                </div>
            </div>

            <div class="dd-item-list">
                <?php if ($query->have_posts()) : ?>
                    <?php while ($query->have_posts()) : $query->the_post();
                        $influencer_id = get_post_meta(get_the_ID(), 'influencer_id', true);
                        $influencer_handle = get_post_meta($influencer_id, 'instagramId', true);
                        $influencer_name = $influencer_id ? get_the_title($influencer_id) : 'Unknown Creator';
                    ?>
                        <div class="dd-outreach-item" data-post-id="<?php echo get_the_ID(); ?>">
                            <img src="<?php echo get_the_post_thumbnail_url($influencer_id, 'thumbnail') ?: 'default-avatar.png'; ?>" class="dd-item-avatar">
                            <div class="dd-item-content">
                                <span class="dd-item-name"><?php echo esc_html($influencer_name); ?> ✓</span>
                                <span class="dd-item-handle">@<?php echo esc_html($influencer_handle); ?></span>
                                <span class="dd-item-title"><?php the_title(); ?></span>
                                <span class="dd-item-date"><?php echo get_the_date('M j, Y'); ?></span>
                            </div>
                        </div>
                    <?php endwhile;
                    wp_reset_postdata(); ?>
                <?php else: ?>
                    <p>No outreach projects found.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Renders the right-side panel placeholder via shortcode [dd_outreach_view].
     *
     * @param array $atts Shortcode attributes.
     * @return string Compiled HTML block.
     */
    public function render_view_shortcode($atts)
    {
        return '<div id="dd-outreach-view-container" class="dd-outreach-view-container">
            <span class="dd-view-placeholder">Select a project from the list to view details.</span>
        </div>';
    }

    /**
     * AJAX endpoint to fetch specific outreach post details.
     *
     * @return void
     */
    public function ajax_get_outreach_details()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (! $post_id || get_post_type($post_id) !== 'outreach') {
            wp_send_json_error('<span class="dd-view-error">Invalid post ID.</span>');
        }

        $post = get_post($post_id);

        if ($post->post_author != get_current_user_id() && ! current_user_can('manage_options')) {
            wp_send_json_error('<span class="dd-view-error">Unauthorized.</span>');
        }

        $influencer_id = get_post_meta($post_id, 'influencer_id', true);
        $influencer_name = $influencer_id ? get_the_title($influencer_id) : 'Unknown Creator';
        $influencer_handle = get_post_meta($influencer_id, 'instagramId', true);

        $project_type   = get_post_meta($post_id, 'project_type', true) ?: 'N/A';
        $project_length = get_post_meta($post_id, 'project_length', true) ?: 'Ongoing';
        $project_dates  = get_post_meta($post_id, 'project_dates', true) ?: 'Flexible';
        $budget         = get_post_meta($post_id, 'budget', true) ?: 'To be discussed';
        $message        = get_post_meta($post_id, 'message', true) ?: 'No message provided.';
        $sent_date      = get_the_date('g:i A, F jS, Y', $post_id);

        ob_start();
    ?>



        <div class="dd-message-overview-container">
            <div class="dd-profile-header">
                <img src="<?php echo get_the_post_thumbnail_url($influencer_id, 'thumbnail') ?: 'default-avatar.png'; ?>" alt="Profile" class="dd-avatar">
                <div class="dd-profile-info">
                    <strong><?php echo esc_html($influencer_name); ?> ✓</strong><br>
                    <small>@<?php echo esc_html($influencer_handle); ?></small>
                </div>
                <a href="<?php echo get_permalink($influencer_id); ?>" class="dd-btn-outline">VIEW CREATOR PROFILE</a>
            </div>
            <div class="dd-overview-body">
                <div class="tags-container tags-container tags-container">
                    <span class="tag"><strong>Project type:</strong> <?php echo esc_html($project_type); ?></span>
                    <span class="tag"><strong>Project length:</strong> <?php echo esc_html($project_length); ?></span>
                    <span class="tag"><strong>Project Dates:</strong> <?php echo esc_html($project_dates); ?></span>
                    <span class="tag"><strong>Budget:</strong> <?php echo esc_html($budget); ?></span>
                </div>
                <div class="dd-message-sent-date">
                    <span>Sent at <?php echo esc_html($sent_date); ?></span>
                </div>

                <h3 class="dd-subject-title"><?php echo esc_html($post->post_title); ?></h3>

                <div class="dd-message-content">
                    <?php echo nl2br(esc_html($message)); ?>
                </div>
            </div>
        </div>

        <div class="dd-notes-grid">
            <div class="dd-note-card">
                <h4 class="dd-note-title">📝 Create a note for this project</h4>
                <p class="dd-note-desc">Notes created are only visible to you and will never be shared.</p>
                <input type="text" class="dd-note-input" placeholder="Note title">
                <textarea class="dd-note-textarea" placeholder="Start typing your note..."></textarea>
                <button class="dd-note-btn">💾 SAVE NOTE</button>
            </div>

            <div class="dd-steps-card">
                <h4 class="dd-note-title">Follow up and discuss next steps</h4>
                <div class="dd-steps-content">
                    Project sent on <?php echo get_the_date('F jS', $post_id); ?> - interested but asked about usage rights and exclusivity. Wanted to discuss budget. They are open to working flexibly which works well for our project scope. Need to follow up after legal review and to discuss next steps.
                </div>
                <div class="dd-steps-actions">
                    <button class="dd-delete-btn">DELETE NOTE</button>
                    <button class="dd-edit-btn">EDIT NOTE</button>
                </div>
                <p class="dd-last-edited">Last edited <?php echo get_the_date('F jS, Y', $post_id); ?></p>
            </div>
        </div>
<?php
        $html = ob_get_clean();
        wp_send_json_success($html);
    }

    /**
     * Enqueues the custom jQuery required to bridge the list clicks with the AJAX
     * endpoint.
     *
     * @return void
     */
    public function enqueue_dashboard_scripts()
    {
        wp_enqueue_script('jquery');

        $script = "
        jQuery(document).ready(function($) {
            $('.dd-outreach-item').on('click', function() {
                var postId = $(this).data('post-id');
                var container = $('#dd-outreach-view-container');
                
                $('.dd-outreach-item').removeClass('active-item');
                $(this).addClass('active-item');

                container.html('<span class=\"dd-view-placeholder\">Loading...</span>');

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_get_outreach_details',
                        security: ddOutreach.nonce,
                        post_id: postId
                    },
                    success: function(response) {
                        if(response.success) {
                            container.html(response.data);
                        } else {
                            container.html(response.data || '<span class=\"dd-view-error\">Error loading details.</span>');
                        }
                    }
                });
            });
        });
        ";

        wp_register_script('dd-outreach-app', '', [], '', true);
        wp_enqueue_script('dd-outreach-app');
        wp_add_inline_script('dd-outreach-app', $script);

        wp_localize_script('dd-outreach-app', 'ddOutreach', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dd_outreach_nonce')
        ]);
    }
}

new DD_Outreach_Manager();
