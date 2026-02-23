<?php
/**
 * Plugin Name: DD Outreach Manager
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Manages Elementor form submissions for outreach and provides dynamic shortcode views for project management.
 * Version: 1.3.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly for security.
}

/**
 * Class DD_Outreach_Manager
 * Handles Elementor form interception, dynamic HTML generation, and shortcode
 * rendering for the master-detail outreach dashboard.
 */
class DD_Outreach_Manager {

    /**
     * Initializes the class, registers hooks, shortcodes, and AJAX endpoints.
     * @return void
     */
    public function __construct() {
        // Global Styles
        add_action( 'wp_head', [ $this, 'inject_global_styles' ] );

        // Legacy/Existing Form Functionality
        add_action( 'elementor_pro/forms/new_record', [ $this, 'process_elementor_form_response' ], 10, 2 );
        add_action( 'wp_footer', [ $this, 'inject_elementor_success_scripts' ] );

        // New Master-Detail Dashboard Functionality
        add_shortcode( 'dd_outreach_list', [ $this, 'render_list_shortcode' ] );
        add_shortcode( 'dd_outreach_view', [ $this, 'render_view_shortcode' ] );

        // AJAX Handlers for dynamic viewing
        add_action( 'wp_ajax_dd_get_outreach_details', [ $this, 'ajax_get_outreach_details' ] );
        
        // Enqueue necessary scripts for the interactive dashboard
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_dashboard_scripts' ] );
    }

    /**
     * Outputs consolidated CSS into the document <head>.
     * Reuses standard message classes for both the form summary and the dynamic AJAX view.
     *
     * @return void
     */
    public function inject_global_styles() {
        ?>
        <style>
            /* Unified Summary & View Component Styles */
            .dd-message-overview { display: flex; justify-content: space-between; flex-wrap: wrap; font-size: 14px; font-weight: 500; color: #555; margin-bottom: 10px; }
            .dd-message-overview-container { font-family: inherit; border-radius: 8px; border: 1px solid #4DB2A6; background: #fff; margin-bottom: 20px; }
            .dd-profile-header { display: flex; align-items: center; gap: 15px; padding: 20px; border-bottom: 1px solid #E7E7E7; }
            .dd-avatar { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; }
            .dd-profile-info { flex-grow: 1; line-height: 1.4; }
            .dd-profile-info strong { font-size: 20px; color: #333; }
            .dd-profile-info small { color: #777; font-size: 14px; }
            .dd-btn-outline { font-family: inherit; font-size: 12px; font-weight: bold; color: #ff9999; border: 1px solid #ffcccc; padding: 8px 15px; border-radius: 4px; text-decoration: none; background: transparent; }
            .tags-container { display: flex; flex-wrap: wrap; gap: 10px; padding: 20px; border-bottom: 1px solid #E7E7E7; margin: 0; }
            .tag { border: 1px solid #4DB2A6; color: #4DB2A6; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
            .dd-subject-title { color: #1c4ea1; font-size: 18px !important; font-weight: bold; margin: 0; padding: 20px; font-family: Inter, sans-serif !important; border-bottom: none; }
            .dd-message-content { font-size: 14px; color: #444; line-height: 1.6; max-height: 350px; overflow-y: auto; padding: 0 20px 25px 20px; font-family: Inter, sans-serif; }

            /* Elementor specific footer buttons */
            .dd-footer { display: flex; gap: 15px; margin-top: 15px; }
            .view-outreach a { background-color: var(--e-global-color-accent, #4DB2A6) !important; border: 1px solid var(--e-global-color-accent, #4DB2A6); color: #fff !important; }
            .close-outreach a { border: 1px solid var(--e-global-color-ee06e41, #ff9999) !important; background-color: transparent !important; color: var(--e-global-color-ee06e41, #ff9999) !important; }

            /* Dashboard List Navigation Styles */
            .dd-dashboard-list-container { background: #fdfdfd; border: 1px solid #eaeaea; border-radius: 8px; padding: 20px; width: 100%; max-width: 350px; }
            .dd-filter-controls { margin-bottom: 20px; }
            .dd-list-search { width: 100%; margin-bottom: 15px; padding: 10px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
            .dd-filter-label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
            .dd-filter-reset { font-size: 12px; color: #888; text-decoration: none; }
            .dd-filter-select { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
            .dd-filter-buttons-row { margin-top: 15px; display: flex; gap: 10px; }
            .dd-filter-btn { border-radius: 20px; border: 1px solid #ccc; background: transparent; padding: 5px 15px; cursor: pointer; font-size: 13px; }
            .dd-filter-btn.active { border-color: #4DB2A6; background: #E6F4F1; color: #4DB2A6; }
            .dd-item-list { max-height: 600px; overflow-y: auto; }
            .dd-outreach-item { display: flex; align-items: center; padding: 15px 10px; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s; border-radius: 6px; }
            .dd-outreach-item:hover, .dd-outreach-item.active-item { background: #f4f4f4; }
            .dd-item-avatar { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; margin-right: 15px; }
            .dd-item-content { flex-grow: 1; }
            .dd-item-name { display: block; font-size: 14px; font-weight: bold; color: #333; }
            .dd-item-handle { display: block; color: #777; font-size: 12px; }
            .dd-item-title { display: block; font-size: 12px; color: #4DB2A6; font-weight: bold; margin-top: 4px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 200px; }
            .dd-item-date { color: #aaa; font-size: 11px; }

            /* Dashboard View Layout & Note Styles */
            .dd-outreach-view-container { background: #f4f4f4; border-radius: 8px; padding: 30px; min-height: 600px; width: 100%; box-sizing: border-box; }
            .dd-view-placeholder { text-align: center; color: #888; margin-top: 50%; transform: translateY(-50%); display: block; }
            .dd-view-error { text-align: center; color: red; margin-top: 50%; transform: translateY(-50%); display: block; }
            .dd-notes-grid { display: flex; gap: 20px; flex-wrap: wrap; }
            .dd-note-card { flex: 1; min-width: 280px; background: #fff; border: 1px solid #ffcc00; border-radius: 8px; padding: 20px; box-sizing: border-box; }
            .dd-steps-card { flex: 1; min-width: 280px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-sizing: border-box; }
            .dd-note-title { margin-top: 0; font-size: 16px; margin-bottom: 5px; color: #333; }
            .dd-note-desc { font-size: 12px; color: #888; margin-bottom: 15px; }
            .dd-note-input { width: 100%; margin-bottom: 10px; padding: 10px; border: 1px solid #eee; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
            .dd-note-textarea { width: 100%; height: 80px; padding: 10px; border: 1px solid #eee; border-radius: 4px; box-sizing: border-box; resize: vertical; font-family: inherit; }
            .dd-note-btn { margin-top: 10px; background: #ffcc00; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; color: #333; font-size: 13px; }
            .dd-steps-content { background: #fdf5e6; padding: 15px; border-radius: 8px; font-size: 13px; color: #444; margin-bottom: 15px; line-height: 1.5; }
            .dd-steps-actions { display: flex; justify-content: flex-end; gap: 10px; }
            .dd-delete-btn { border: none; background: transparent; color: #aaa; cursor: pointer; font-size: 12px; }
            .dd-edit-btn { border: 1px solid #ddd; background: #f9f9f9; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 12px; color: #333; }
            .dd-last-edited { text-align: right; font-size: 11px; color: #ccc; margin-top: 10px; }
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
    public function process_elementor_form_response( $record, $ajax_handler ) {
        $form_id = $record->get_form_settings('form_id');

        if ('outreach_form' !== $form_id) {
            return;
        }

        $raw_fields = $record->get('fields');
        $data       = [];

        foreach ($raw_fields as $id => $field) {
            $data[$id] = $field['value'];
        }

        $influencer_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
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

            update_post_meta($post_id, 'influencer_id', $influencer_id);

            if ( function_exists( 'deduct_points_from_current_user' ) ) {
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
                    <strong><?php echo get_the_title(); ?> ✓</strong><br>
                    <small>@<?php echo get_post_meta(get_the_ID(), 'instagramId', true); ?></small>
                </div>
                <a href="<?= get_the_permalink() ?>" class="dd-btn-outline">VIEW CREATOR PROFILE</a>
            </div>
            <div class="dd-overview-body">
                <div class="tags-container">
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
    public function inject_elementor_success_scripts() {
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
    public function render_list_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to view your projects.</p>';
        }

        $args = [
            'post_type'      => 'outreach',
            'posts_per_page' => -1,
            'author'         => get_current_user_id(),
            'post_status'    => 'publish'
        ];
        
        $query = new WP_Query( $args );

        ob_start();
        ?>
        <div class="dd-dashboard-list-container">
            <div class="dd-filter-controls">
                <input type="text" class="dd-list-search" placeholder="Search by influencer or message">
                <div class="dd-filter-label-row">
                    <strong>Project type</strong>
                    <a href="#" class="dd-filter-reset">Reset</a>
                </div>
                <select class="dd-filter-select">
                    <option value="">Filter by project type</option>
                    <option value="affiliate">Affiliate partnership</option>
                    <option value="collaboration">Collaboration</option>
                </select>
                <div class="dd-filter-buttons-row">
                    <button class="dd-filter-btn active">All</button>
                    <button class="dd-filter-btn">Favourites</button>
                </div>
            </div>

            <div class="dd-item-list">
                <?php if ( $query->have_posts() ) : ?>
                    <?php while ( $query->have_posts() ) : $query->the_post(); 
                        $influencer_id = get_post_meta( get_the_ID(), 'influencer_id', true );
                        $influencer_handle = get_post_meta( $influencer_id, 'instagramId', true );
                        $influencer_name = $influencer_id ? get_the_title( $influencer_id ) : 'Unknown Creator';
                    ?>
                        <div class="dd-outreach-item" data-post-id="<?php echo get_the_ID(); ?>">
                            <img src="<?php echo get_the_post_thumbnail_url( $influencer_id, 'thumbnail' ) ?: 'default-avatar.png'; ?>" class="dd-item-avatar">
                            <div class="dd-item-content">
                                <span class="dd-item-name"><?php echo esc_html( $influencer_name ); ?> ✓</span>
                                <span class="dd-item-handle">@<?php echo esc_html( $influencer_handle ); ?></span>
                                <span class="dd-item-title"><?php the_title(); ?></span>
                                <span class="dd-item-date"><?php echo get_the_date('M j, Y'); ?></span>
                            </div>
                        </div>
                    <?php endwhile; wp_reset_postdata(); ?>
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
    public function render_view_shortcode( $atts ) {
        return '<div id="dd-outreach-view-container" class="dd-outreach-view-container">
            <span class="dd-view-placeholder">Select a project from the list to view details.</span>
        </div>';
    }

    /**
     * AJAX endpoint to fetch specific outreach post details.
     * Refactored to reuse the HTML structure and classes from the Elementor form summary.
     *
     * @return void
     */
    public function ajax_get_outreach_details() {
        check_ajax_referer( 'dd_outreach_nonce', 'security' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== 'outreach' ) {
            wp_send_json_error( '<span class="dd-view-error">Invalid post ID.</span>' );
        }

        $post = get_post( $post_id );
        
        if ( $post->post_author != get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '<span class="dd-view-error">Unauthorized.</span>' );
        }

        $influencer_id = get_post_meta( $post_id, 'influencer_id', true );
        $influencer_name = $influencer_id ? get_the_title( $influencer_id ) : 'Unknown Creator';
        $influencer_handle = get_post_meta( $influencer_id, 'instagramId', true );
        
        $project_type   = get_post_meta( $post_id, 'project_type', true ) ?: 'N/A';
        $project_length = get_post_meta( $post_id, 'project_length', true ) ?: 'Ongoing';
        $project_dates  = get_post_meta( $post_id, 'project_dates', true ) ?: 'Flexible';
        $budget         = get_post_meta( $post_id, 'budget', true ) ?: 'To be discussed';
        $message        = get_post_meta( $post_id, 'message', true ) ?: 'No message provided.';
        $sent_date      = get_the_date('g:i A, F jS, Y', $post_id);

        ob_start();
        ?>
        
        <div class="dd-message-overview">
            <span>Potential Partnership with Ribbon Box Community</span>
            <span>Sent at <?php echo esc_html( $sent_date ); ?></span>
        </div>

        <div class="dd-message-overview-container">
            <div class="dd-profile-header">
                <img src="<?php echo get_the_post_thumbnail_url( $influencer_id, 'thumbnail' ) ?: 'default-avatar.png'; ?>" alt="Profile" class="dd-avatar">
                <div class="dd-profile-info">
                    <strong><?php echo esc_html( $influencer_name ); ?> ✓</strong><br>
                    <small>@<?php echo esc_html( $influencer_handle ); ?></small>
                </div>
                <a href="<?php echo get_permalink( $influencer_id ); ?>" class="dd-btn-outline">VIEW CREATOR PROFILE</a>
            </div>
            <div class="dd-overview-body">
                <div class="tags-container">
                    <span class="tag"><strong>Project type:</strong> <?php echo esc_html( $project_type ); ?></span>
                    <span class="tag"><strong>Project length:</strong> <?php echo esc_html( $project_length ); ?></span>
                    <span class="tag"><strong>Project Dates:</strong> <?php echo esc_html( $project_dates ); ?></span>
                    <span class="tag"><strong>Budget:</strong> <?php echo esc_html( $budget ); ?></span>
                </div>

                <h3 class="dd-subject-title"><?php echo esc_html( $post->post_title ); ?></h3>

                <div class="dd-message-content">
                    <?php echo nl2br( esc_html( $message ) ); ?>
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
        wp_send_json_success( $html );
    }

    /**
     * Enqueues the custom jQuery required to bridge the list clicks with the AJAX
     * endpoint.
     *
     * @return void
     */
    public function enqueue_dashboard_scripts() {
        wp_enqueue_script( 'jquery' );
        
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
        
        wp_register_script( 'dd-outreach-app', '', [], '', true );
        wp_enqueue_script( 'dd-outreach-app' );
        wp_add_inline_script( 'dd-outreach-app', $script );
        
        wp_localize_script( 'dd-outreach-app', 'ddOutreach', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dd_outreach_nonce' )
        ] );
    }
}

new DD_Outreach_Manager();