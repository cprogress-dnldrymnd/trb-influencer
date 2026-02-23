<?php

/**
 * Plugin Name: DD Outreach Manager
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Manages Elementor form submissions for outreach and provides dynamic shortcode views for project management.
 * Version: 1.7.0
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

        // AJAX Handlers for dynamic viewing & filtering
        add_action('wp_ajax_dd_get_outreach_details', [$this, 'ajax_get_outreach_details']);
        add_action('wp_ajax_dd_filter_outreach_list', [$this, 'ajax_filter_outreach_list']);

        // AJAX Handlers for Notes CRUD
        add_action('wp_ajax_dd_save_outreach_note', [$this, 'ajax_save_outreach_note']);
        add_action('wp_ajax_dd_delete_outreach_note', [$this, 'ajax_delete_outreach_note']);

        // Enqueue necessary scripts for the interactive dashboard
        add_action('wp_enqueue_scripts', [$this, 'enqueue_dashboard_scripts']);

        // Backend Meta Boxes
        add_action('add_meta_boxes', [$this, 'add_note_meta_box']);
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
                border-radius: 10px;
                border: 2px solid #034146;
            }

            .mt-0 {
                margin-top: 0 !important;
            }

            .dd-profile-header {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
                border-bottom: 1px solid #E7E7E7;
            }


            .dd-avatar.dd-avatar.dd-avatar {
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
                font-size: 14px;
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
                width: 100%;
                max-width: 350px;
            }

            .outreach-filter {
                padding: 20px;
                border-bottom: 1px solid #BCBCBC;
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
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                border-top: 1px solid transparent;
                cursor: pointer;
                transition: background 0.2s;
                gap: 15px;
            }

            .dd-outreach-item:hover,
            .dd-outreach-item.active-item {
                background: #FEF6F3;
                border-bottom: 1px solid #3B1527;
                border-top: 1px solid #3B1527;
            }

            .dd-item-avatar.dd-item-avatar.dd-item-avatar {
                width: 68px;
                height: 68px;
                border-radius: 50%;
                object-fit: cover;
                margin-right: 15px;
                border: 1px solid gray;
            }

            .dd-item-content {
                flex-grow: 1;
            }

            .dd-item-name {
                display: block;
                font-size: 15px;
                font-weight: 500;
                color: #000000;
            }

            .dd-item-handle {
                display: block;
                color: #000000;
                font-size: 14px;
                font-weight: 400;
            }

            .dd-item-title {
                display: block;
                font-size: 14px;
                color: #034146;
                font-weight: bold;
                margin-top: 4px;
                text-overflow: ellipsis;
                overflow: hidden;
                white-space: nowrap;
                max-width: 200px;
            }

            .dd-item-date {
                color: #8F8F8F;
                font-size: 13px;
            }

            /* --- Notes Component Styles --- */
            .dd-outreach-view-container {
                background: #f4f4f4;
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
                flex-wrap: nowrap;
                align-items: flex-start;
                margin-top: 20px;
                font-family: Inter;
            }

            .dd-note-card {
                flex: 1;
                min-width: 280px;
                background: #fff;
                border: 2px solid #FFE17B;
                border-radius: 8px;
                padding: 20px;
                box-sizing: border-box;
                position: sticky;
                top: 20px;
            }

            .dd-notes-list-container {
                flex: 1;
                min-width: 280px;
                display: flex;
                flex-direction: column;
                gap: 15px;
            }



            .dd-note-title.dd-note-title {
                margin-top: 0;
                font-size: 20px;
                margin-bottom: 15px;
                color: #3B1527;
                font-weight: bold;
            }

            .dd-note-desc {
                font-size: 14px;
                color: #8F8F8F;
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
                transition: opacity 0.2s;
            }

            .dd-note-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .dd-steps-content {
                background: #FCF3D5;
                padding: 15px;
                border-radius: 8px;
                font-size: 14px;
                color: #000;
                margin-bottom: 15px;
                line-height: 1.5;
                border: 1px solid #FFE17B;
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
                font-size: 14px;
                color: #8F8F8F;
                margin-top: 10px !important;
                display: block;
            }

            .dd-no-notes {
                text-align: center;
                color: #888;
                padding: 20px;
                border: 1px dashed #ccc;
                border-radius: 8px;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn {
                padding: 12px 20px !important;
                border: 1px solid #BCBCBC;
                font-family: Inter !important;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.6px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-yellow {
                background-color: #FFE17B;
                border-color: #FFE17B;
                color: #000000;

            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-outline {
                background-color: transparent;
                color: #BCBCBC;
                border-color: #BCBCBC;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-outline span {
                text-decoration: underline;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-link {
                border: none;
                background-color: transparent;
            }

            .dd-note-btn.dd-note-btn.dd-note-btn.dd-note-btn-link span {
                text-decoration: underline;
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
            'post_content' => $data['message'] . '<div class="hide-element">' . get_the_title($data['influencer_id']) . '</div>'
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
            $sent_date      = get_the_date('g:i A, F jS, Y', $post_id);
        } else {
            $sent_date = date_i18n(get_option('date_format'));
        }


        ob_start();
    ?>
        <div class="dd-message-overview">
            <span class="m-overview">Message overview</span>
            <span class="date">Sent at <?php echo esc_html($sent_date); ?></span>
        </div>
        <div class="dd-message-overview-container">
            <div class="dd-profile-header">
                <div class="avatar-holder">
                    <?= do_shortcode('[influencer_avatar post_id="' . esc_attr($data['influencer_id']) . '"]') ?>
                </div>
                <div class="dd-profile-info">
                    <strong><?php echo get_the_title($data['influencer_id']); ?></strong><br>
                    <small>@<?php echo get_post_meta($data['influencer_id'], 'instagramId', true); ?></small>
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
                <a class="elementor-button elementor-button-link elementor-size-sm" href="<?= get_the_permalink(8900) ?>">
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
     * Backend Meta Box setup for the wp-admin screen.
     * Provides a read-only list of all notes created for the project.
     */
    public function add_note_meta_box()
    {
        add_meta_box(
            'dd_outreach_note_meta',
            'Project Notes Monitor',
            [$this, 'render_note_meta_box'],
            'outreach',
            'side',
            'high'
        );
    }

    /**
     * Renders the HTML inside the backend meta box.
     */
    public function render_note_meta_box($post)
    {
        $notes = get_post_meta($post->ID, 'dd_outreach_project_notes', true);

        if (empty($notes) || !is_array($notes)) {
            echo '<p style="color:#666;">No notes have been added to this project yet.</p>';
            return;
        }

        echo '<div style="max-height: 500px; overflow-y: auto;">';
        foreach ($notes as $note) {
            echo '<div style="background: #fdf5e6; border: 1px solid #e0e0e0; padding: 12px; margin-bottom: 12px; border-radius: 4px;">';
            echo '<h4 style="margin: 0 0 5px 0;">' . esc_html($note['title']) . '</h4>';
            echo '<p style="margin: 0 0 10px 0; font-size: 13px; color: #444;">' . nl2br(esc_html($note['content'])) . '</p>';
            echo '<small style="color: #999;">Last edited: ' . esc_html(date_i18n('F jS, Y \a\t g:i a', strtotime($note['date']))) . '</small>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Renders the HTML block for all notes assigned to a post.
     * Reused by the main render view and the AJAX response.
     */
    private function generate_notes_list_html($post_id)
    {
        $notes = get_post_meta($post_id, 'dd_outreach_project_notes', true);
        $html = '';

        if (empty($notes) || !is_array($notes)) {
            $html .= '<div class="dd-no-notes">No notes have been added to this project yet.</div>';
        } else {
            // Display newest notes first
            $notes = array_reverse($notes);

            foreach ($notes as $note) {
                $fmt_date = date_i18n('F jS, Y', strtotime($note['date']));
                $title    = esc_html($note['title']);
                $content  = nl2br(esc_html($note['content']));
                $raw      = esc_textarea($note['content']);
                $note_id  = esc_attr($note['id']);

                $html .= '<div class="dd-steps-card dd-note-card" data-note-id="' . $note_id . '">';
                $html .= '<h4 class="dd-note-title dd-display-note-title">' . $title . '</h4>';
                $html .= '<div class="dd-steps-content dd-display-note-content">' . $content . '</div>';
                // Hidden textarea retains raw format for editing
                $html .= '<textarea class="dd-raw-note-content" style="display:none;">' . $raw . '</textarea>';

                $html .= '<div class="dd-steps-actions">';
                $html .= '<button class="dd-delete-note dd-delete-btn dd-note-btn dd-note-btn-link" data-post-id="' . esc_attr($post_id) . '" data-note-id="' . $note_id . '"><span>DELETE NOTE</span></button>';
                $html .= '<button class="dd-edit-note dd-edit-btn dd-note-btn dd-note-btn-outline" data-post-id="' . esc_attr($post_id) . '" data-note-id="' . $note_id . '">  <svg xmlns="http://www.w3.org/2000/svg" width="12.832" height="16.332" viewBox="0 0 12.832 16.332">
                            <path id="saved" fill="currentColor" d="M26.125,10.333V22a.583.583,0,0,1-.583.583h-.083a.584.584,0,0,1-.416-.174l-4.167-4.243-4.167,4.243a.583.583,0,0,1-.416.174h-.083A.583.583,0,0,1,15.625,22V10.333a1.752,1.752,0,0,1,1.75-1.75h7a1.752,1.752,0,0,1,1.75,1.75ZM25.541,6.25h-7a.583.583,0,0,0,0,1.167h7a1.752,1.752,0,0,1,1.75,1.75V18.5a.583.583,0,1,0,1.167,0V9.166A2.92,2.92,0,0,0,25.541,6.25Z" transform="translate(-15.625 -6.25)" />
                        </svg> <span>EDIT NOTE</span></button>';
                $html .= '</div>';

                $html .= '<p class="dd-last-edited">Last edited ' . $fmt_date . '</p>';
                $html .= '</div>';
            }
        }
        return $html;
    }

    /**
     * AJAX endpoint to save or update a note from the frontend view dashboard.
     */
    public function ajax_save_outreach_note()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized.');
        }

        $note_id = isset($_POST['note_id']) ? sanitize_text_field($_POST['note_id']) : '';
        $title   = isset($_POST['note_title']) ? sanitize_text_field($_POST['note_title']) : '';
        $content = isset($_POST['note_content']) ? sanitize_textarea_field($_POST['note_content']) : '';
        $date    = current_time('mysql');

        $notes = get_post_meta($post_id, 'dd_outreach_project_notes', true);
        if (!is_array($notes)) $notes = [];

        if (!empty($note_id)) {
            // Update existing note
            foreach ($notes as &$note) {
                if ($note['id'] === $note_id) {
                    $note['title'] = $title;
                    $note['content'] = $content;
                    $note['date'] = $date;
                    break;
                }
            }
        } else {
            // Create new note
            $notes[] = [
                'id'      => uniqid('note_'),
                'title'   => $title,
                'content' => $content,
                'date'    => $date
            ];
        }

        update_post_meta($post_id, 'dd_outreach_project_notes', $notes);

        // Return the fresh HTML for the notes list
        wp_send_json_success($this->generate_notes_list_html($post_id));
    }

    /**
     * AJAX endpoint to delete a specific note from the frontend view dashboard.
     */
    public function ajax_delete_outreach_note()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Unauthorized.');
        }

        $note_id = isset($_POST['note_id']) ? sanitize_text_field($_POST['note_id']) : '';
        $notes = get_post_meta($post_id, 'dd_outreach_project_notes', true);

        if (is_array($notes) && !empty($note_id)) {
            // Filter out the deleted note
            $notes = array_filter($notes, function ($note) use ($note_id) {
                return $note['id'] !== $note_id;
            });
            // Re-index array
            $notes = array_values($notes);
            update_post_meta($post_id, 'dd_outreach_project_notes', $notes);
        }

        // Return the fresh HTML for the notes list
        wp_send_json_success($this->generate_notes_list_html($post_id));
    }

    /**
     * Renders the left-side panel (list and filters) via shortcode [dd_outreach_list].
     */
    public function render_list_shortcode($atts)
    {
        if (! is_user_logged_in()) {
            return '<p>Please log in to view your projects.</p>';
        }

        $raw_fields = get_query_var('influencer_outreach_fields');
        $influencer_outreach_fields = is_array($raw_fields) ? $raw_fields : [];

        ob_start();
    ?>
        <div class="dd-dashboard-list-container">
            <div class="outreach-filter">
                <div class="influencer-search-filter-holder">
                    <div class="influencer-search-item">
                        <input type="text" id="dd-outreach-search" name="search" placeholder="Search by influencer or message">
                    </div>
                    <div class="influencer-search-item">
                        <?php
                        if (function_exists('select_filter')) {
                            echo select_filter('project_type', 'Project type', 'Filter by project type', $influencer_outreach_fields['project_type'] ?? '');
                        }
                        ?>
                    </div>
                    <div class="influencer-search-item">
                        <?php
                        if (function_exists('select_filter')) {
                            echo select_filter('project_length', 'Project length', 'Filter by project length', $influencer_outreach_fields['project_length'] ?? '');
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="dd-item-list" id="dd-outreach-list-container">
                <?php echo $this->generate_list_html(); ?>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Helper function to generate the HTML for the item list.
     */
    private function generate_list_html($search_query = '', $project_types = [], $project_lengths = [])
    {
        $args = [
            'post_type'      => 'outreach',
            'posts_per_page' => -1,
            'author'         => get_current_user_id(),
            'post_status'    => 'publish'
        ];

        if (!empty($search_query)) {
            $args['s'] = sanitize_text_field($search_query);
        }

        $meta_query = ['relation' => 'AND'];

        if (!empty($project_types) && is_array($project_types)) {
            $type_query = ['relation' => 'OR'];
            foreach ($project_types as $type) {
                if (!empty($type)) {
                    $type_query[] = [
                        'key'     => 'project_type',
                        'value'   => sanitize_text_field($type),
                        'compare' => 'LIKE'
                    ];
                }
            }
            if (count($type_query) > 1) {
                $meta_query[] = $type_query;
            }
        }

        if (!empty($project_lengths) && is_array($project_lengths)) {
            $length_query = ['relation' => 'OR'];
            foreach ($project_lengths as $length) {
                if (!empty($length)) {
                    $length_query[] = [
                        'key'     => 'project_length',
                        'value'   => sanitize_text_field($length),
                        'compare' => 'LIKE'
                    ];
                }
            }
            if (count($length_query) > 1) {
                $meta_query[] = $length_query;
            }
        }

        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($args);
        $html = '';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $influencer_id = get_post_meta($post_id, 'influencer_id', true);
                $influencer_handle = get_post_meta($influencer_id, 'instagramId', true);
                $influencer_name = $influencer_id ? get_the_title($influencer_id) : 'Unknown Creator';


                $title = get_the_title();
                $date = get_the_date('M j, Y');

                $html .= '<div class="dd-outreach-item" data-post-id="' . esc_attr($post_id) . '">';
                $html .= '<div class="avatar-holder">';
                $html .= do_shortcode('[influencer_avatar post_id="' . esc_attr($influencer_id) . '"]');
                $html .= '</div>';
                $html .= '<div class="dd-item-content">';
                $html .= '<span class="dd-item-name">' . esc_html($influencer_name) . '</span>';
                $html .= '<span class="dd-item-handle">@' . esc_html($influencer_handle) . '</span>';
                $html .= '<span class="dd-item-title">' . esc_html($title) . '</span>';
                $html .= '<span class="dd-item-date">' . esc_html($date) . '</span>';
                $html .= '</div></div>';
            }
            wp_reset_postdata();
        } else {
            $html .= '<p style="padding: 20px; color:#888;">No outreach projects found matching your criteria.</p>';
        }

        return $html;
    }

    /**
     * AJAX endpoint to filter the outreach list.
     */
    public function ajax_filter_outreach_list()
    {
        check_ajax_referer('dd_outreach_nonce', 'security');

        if (! is_user_logged_in()) {
            wp_send_json_error('Please log in.');
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        $project_types = isset($_POST['project_type']) && is_array($_POST['project_type']) ? array_map('sanitize_text_field', $_POST['project_type']) : [];
        $project_lengths = isset($_POST['project_length']) && is_array($_POST['project_length']) ? array_map('sanitize_text_field', $_POST['project_length']) : [];

        $html = $this->generate_list_html($search, $project_types, $project_lengths);

        wp_send_json_success($html);
    }

    /**
     * Renders the right-side panel placeholder via shortcode [dd_outreach_view].
     */
    public function render_view_shortcode($atts)
    {
        return '<div id="dd-outreach-view-container" class="dd-outreach-view-container">
            <span class="dd-view-placeholder">Loading...</span>
        </div>';
    }

    /**
     * AJAX endpoint to fetch specific outreach post details.
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
        <div class="dd-message-overview-container mt-0">
            <div class="dd-profile-header">
                <div class="avatar-holder" style="--size: 84px">
                    <?= do_shortcode('[influencer_avatar post_id="' . esc_attr($influencer_id) . '"]') ?>
                </div>
                <div class="dd-profile-info">
                    <strong><?php echo esc_html($influencer_name); ?> </strong><br>
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
                <h4 class="dd-note-title" id="dd-note-form-heading">🗒️ Create a note for this project</h4>
                <p class="dd-note-desc">Notes created are only visible to you and will never be shared.</p>
                <input type="hidden" id="dd-note-input-id" value="">
                <input type="text" id="dd-note-input-title" class="dd-note-input" placeholder="Note title">
                <textarea id="dd-note-input-content" class="dd-note-textarea" placeholder="Start typing your note..."></textarea>
                <div style="display:flex; gap:10px;">
                    <button id="dd-save-note" class="dd-note-btn dd-note-btn-yellow" data-post-id="<?php echo esc_attr($post_id); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12.832" height="16.332" viewBox="0 0 12.832 16.332">
                            <path id="saved" fill="currentColor" d="M26.125,10.333V22a.583.583,0,0,1-.583.583h-.083a.584.584,0,0,1-.416-.174l-4.167-4.243-4.167,4.243a.583.583,0,0,1-.416.174h-.083A.583.583,0,0,1,15.625,22V10.333a1.752,1.752,0,0,1,1.75-1.75h7a1.752,1.752,0,0,1,1.75,1.75ZM25.541,6.25h-7a.583.583,0,0,0,0,1.167h7a1.752,1.752,0,0,1,1.75,1.75V18.5a.583.583,0,1,0,1.167,0V9.166A2.92,2.92,0,0,0,25.541,6.25Z" transform="translate(-15.625 -6.25)" />
                        </svg>
                        SAVE NOTE
                    </button>
                    <button id="dd-cancel-edit-note" class="dd-delete-btn dd-note-btn dd-note-btn-link" style="display:none; margin-top:10px;">CANCEL</button>
                </div>
            </div>

            <div class="dd-notes-list-container" id="dd-notes-list-wrapper">
                <?php echo $this->generate_notes_list_html($post_id); ?>
            </div>
        </div>
<?php
        $html = ob_get_clean();
        wp_send_json_success($html);
    }

    /**
     * Enqueues the custom jQuery.
     */
    public function enqueue_dashboard_scripts()
    {
        wp_enqueue_script('jquery');

        $script = "
        jQuery(document).ready(function($) {
            
            // --- 1. Master-Detail List Click Loader ---
            function bindListItemClicks() {
                $('.dd-outreach-item').off('click').on('click', function() {
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
            }

            // Bind list clicks on initial load and click first item
            bindListItemClicks();
            var firstItem = $('.dd-outreach-item').first();
            if (firstItem.length) {
                firstItem.trigger('click');
            }


            // --- 2. Filtering Logic ---
            var filterTimer;
            
            function triggerFilter() {
                var searchQuery = $('#dd-outreach-search').val();
                
                // Collect project types
                var selectedTypes = [];
                $('input[name=\"project_type[]\"]:checked').each(function() {
                    var val = $(this).attr('data-label') || $(this).val();
                    selectedTypes.push(val);
                });
                if (selectedTypes.length === 0 && $('select[name=\"project_type\"]').length > 0 && $('select[name=\"project_type\"]').val() !== '') {
                    selectedTypes.push($('select[name=\"project_type\"]').val());
                }

                // Collect project lengths
                var selectedLengths = [];
                $('input[name=\"project_length[]\"]:checked').each(function() {
                    var val = $(this).attr('data-label') || $(this).val();
                    selectedLengths.push(val);
                });
                if (selectedLengths.length === 0 && $('select[name=\"project_length\"]').length > 0 && $('select[name=\"project_length\"]').val() !== '') {
                    selectedLengths.push($('select[name=\"project_length\"]').val());
                }

                $('#dd-outreach-list-container').html('<p style=\"padding: 20px; text-align:center;\">Loading...</p>');

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_filter_outreach_list',
                        security: ddOutreach.nonce,
                        search: searchQuery,
                        project_type: selectedTypes,
                        project_length: selectedLengths
                    },
                    success: function(response) {
                        if(response.success) {
                            $('#dd-outreach-list-container').html(response.data);
                            bindListItemClicks(); 
                            
                            var newFirstItem = $('.dd-outreach-item').first();
                            if (newFirstItem.length) {
                                newFirstItem.trigger('click');
                            } else {
                                $('#dd-outreach-view-container').html('<span class=\"dd-view-placeholder\">No project selected.</span>');
                            }
                        }
                    }
                });
            }

            $('#dd-outreach-search').on('keyup', function() {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(triggerFilter, 500);
            });

            $(document).on('change', 'input[name=\"project_type[]\"], select[name=\"project_type\"], input[name=\"project_length[]\"], select[name=\"project_length\"]', function() {
                triggerFilter();
            });


            // --- 3. Note CRUD Event Delegation ---
            var viewContainer = $('#dd-outreach-view-container');

            // Save / Update Action
            viewContainer.on('click', '#dd-save-note', function(e) {
                e.preventDefault();
                var btn = $(this);
                var postId = btn.data('post-id');
                var noteId = $('#dd-note-input-id').val();
                var title = $('#dd-note-input-title').val();
                var content = $('#dd-note-input-content').val();

                if (!content.trim()) {
                    alert('Please enter note content before saving.');
                    return;
                }

                btn.text('SAVING...').prop('disabled', true);

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_save_outreach_note',
                        security: ddOutreach.nonce,
                        post_id: postId,
                        note_id: noteId,
                        note_title: title,
                        note_content: content
                    },
                    success: function(res) {
                        btn.text('💾 SAVE NOTE').prop('disabled', false);
                        if(res.success) {
                            // Inject the freshly built list of notes into the wrapper
                            $('#dd-notes-list-wrapper').html(res.data);
                            
                            // Reset form back to \"Create\" state
                            $('#dd-cancel-edit-note').trigger('click');
                        } else {
                            alert('An error occurred while saving the note.');
                        }
                    }
                });
            });

            // Edit Action (Populate Form)
            viewContainer.on('click', '.dd-edit-note', function(e) {
                e.preventDefault();
                var card = $(this).closest('.dd-steps-card');
                var noteId = $(this).data('note-id');
                var currentTitle = card.find('.dd-display-note-title').text();
                var currentContent = card.find('.dd-raw-note-content').val();
                
                $('#dd-note-input-id').val(noteId);
                $('#dd-note-input-title').val(currentTitle);
                $('#dd-note-input-content').val(currentContent);
                
                $('#dd-note-form-heading').text('✏️ Edit Note');
                $('#dd-cancel-edit-note').show();
                $('#dd-note-input-content').focus();
            });

            // Cancel Edit Action
            viewContainer.on('click', '#dd-cancel-edit-note', function(e) {
                e.preventDefault();
                $('#dd-note-input-id').val('');
                $('#dd-note-input-title').val('');
                $('#dd-note-input-content').val('');
                $('#dd-note-form-heading').text('🗒️ Create a note for this project');
                $(this).hide();
            });

            // Delete Action
            viewContainer.on('click', '.dd-delete-note', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to permanently delete this note?')) return;
                
                var btn = $(this);
                var postId = btn.data('post-id');
                var noteId = btn.data('note-id');

                btn.text('DELETING...').prop('disabled', true);

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_delete_outreach_note',
                        security: ddOutreach.nonce,
                        post_id: postId,
                        note_id: noteId
                    },
                    success: function(res) {
                        if(res.success) {
                            // Inject updated notes list
                            $('#dd-notes-list-wrapper').html(res.data);
                            
                            // If they delete the note they were currently editing, reset the form
                            if ($('#dd-note-input-id').val() === noteId) {
                                $('#dd-cancel-edit-note').trigger('click');
                            }
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
