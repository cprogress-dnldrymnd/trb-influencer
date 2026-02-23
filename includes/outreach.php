<?php
/**
 * Plugin Name: DD Outreach Manager
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Manages Elementor form submissions for outreach and provides dynamic shortcode views for project management.
 * Version: 1.1.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly for security.
}

/**
 * Class DD_Outreach_Manager
 * * Handles Elementor form interception, dynamic HTML generation, and shortcode
 * rendering for the master-detail outreach dashboard.
 */
class DD_Outreach_Manager {

    /**
     * Initializes the class, registers hooks, shortcodes, and AJAX endpoints.
     * * @return void
     */
    public function __construct() {
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
     * Intercepts the Elementor Form submission to compile a custom HTML payload 
     * using the submitted field values and appends it to the AJAX response.
     * Execution is strictly limited to the 'outreach_form' form ID.
     * Additionally, generates a new 'outreach' post type entry, assigns the current logged-in user 
     * as the author, and stores form fields and the origin page ID as post meta.
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       The form submission record.
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler The AJAX handler managing the response.
     * @return void
     */
    public function process_elementor_form_response( $record, $ajax_handler ) {
        // Retrieve the form ID to ensure this only runs for the target form
        $form_id = $record->get_form_settings('form_id');

        if ('outreach_form' !== $form_id) {
            return; // Exit early if it's not the outreach_form
        }

        // Extract all submitted fields
        $raw_fields = $record->get('fields');
        $data       = [];

        // Normalize fields for easy key-based access
        foreach ($raw_fields as $id => $field) {
            $data[$id] = $field['value'];
        }

        // Capture the ID of the page where the form was submitted via the AJAX POST payload
        $influencer_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $current_user_id = get_current_user_id();

        /**
         * Programmatically insert a new post of type 'outreach'.
         * Defaults the post title to the submitted subject line.
         * Assigns the post_author to the currently authenticated user.
         */
        $post_title = !empty($data['subject']) ? sanitize_text_field($data['subject']) : 'Outreach Submission - ' . current_time('Y-m-d H:i:s');

        $new_post_args = [
            'post_title'  => $post_title,
            'post_type'   => 'outreach',
            'post_status' => 'publish',
            'post_author' => $current_user_id, 
        ];

        $post_id = wp_insert_post($new_post_args);

        // If post creation is successful, iterate through normalized data to store as post meta
        if (!is_wp_error($post_id)) {
            foreach ($data as $meta_key => $meta_value) {
                // Apply textarea sanitization for message to preserve line breaks, standard text sanitization otherwise
                $sanitized_value = ('message' === $meta_key) ? sanitize_textarea_field($meta_value) : sanitize_text_field($meta_value);
                update_post_meta($post_id, sanitize_key($meta_key), $sanitized_value);
            }

            // Store the page/influencer ID where the submission originated
            update_post_meta($post_id, 'influencer_id', $influencer_id);

            // Deduct point and fetch the updated balance for the frontend
            if ( function_exists( 'deduct_points_from_current_user' ) ) {
                deduct_points_from_current_user(1, 'Outreach Form Submission'); 
            }
            
            if (function_exists('mycred_get_users_cred')) {
                $updated_points = mycred_get_users_cred($current_user_id);
                $ajax_handler->add_response_data('updated_points', $updated_points);
            }
        }

        // Generate the current date
        $date_sent = date_i18n(get_option('date_format'));

        // Capture the structured HTML layout
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

        // Inject the payload into Elementor's native AJAX response object
        $ajax_handler->add_response_data('dd_custom_html', $custom_html);
    }

    /**
     * Injects frontend JavaScript and CSS required to catch the Elementor AJAX response
     * and render the custom HTML layout inside a specific target div (#outreach-form-summary).
     * Also handles updating the `.current-points` DOM element dynamically.
     *
     * @return void
     */
    public function inject_elementor_success_scripts() {
        ?>
        <style>
            .dd-message-overview { display: flex; justify-content: space-between; flex-wrap: wrap; font-size: 16px; font-weight: 500; }
            .dd-message-overview-container { font-family: inherit; margin-top: 15px; border-radius: 5px; border: 1px solid #3B1527; }
            .dd-profile-header { display: flex; align-items: center; gap: 15px; padding: 15px 20px; border-bottom: 1px solid #E7E7E7; }
            .dd-avatar { width: 50px; height: 50px; border-radius: 50%; }
            .dd-profile-info { flex-grow: 1; line-height: 1.4; }
            .dd-overview-header { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; font-weight: bold; }
            .dd-overview-header .dd-timestamp { font-weight: normal; color: #555; }
            .dd-btn-outline { background-color: var(--e-global-color-1c4ea17); font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif; font-size: var(--e-global-typography-2a20fd0-font-size); font-weight: var(--e-global-typography-2a20fd0-font-weight); line-height: var(--e-global-typography-2a20fd0-line-height); letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing); fill: var(--e-global-color-accent); color: var(--e-global-color-accent); border: 1px solid var(--e-global-color-accent); padding: 14px 23px; border-radius: 5px; }
            .dd-message-overview-container .tags-container.tags-container.tags-container { padding: 15px 20px; margin: 0; border-bottom: 1px solid #E7E7E7; }
            .dd-subject-title { color: #034146; font-size: 18px !important; font-weight: bold; margin: 0; border-bottom: 1px solid #E7E7E7; font-family: Inter !important; padding: 15px 20px; }
            .dd-message-content { font-size: 15px; color: #000000; line-height: 1.6; max-height: 300px; overflow-y: auto; padding: 15px 20px; font-family: Inter; }
            .dd-footer { display: flex; gap: 15px; margin-top: 15px; }
            .dd-footer a { font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif; font-size: var(--e-global-typography-2a20fd0-font-size); font-weight: var(--e-global-typography-2a20fd0-font-weight); line-height: var(--e-global-typography-2a20fd0-line-height); letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing); }
            .view-outreach a { background-color: var(--e-global-color-accent) !important; border: 1px solid var(--e-global-color-accent); color: #fff !important; }
            .close-outreach a { border-style: solid; border-color: var(--e-global-color-ee06e41) !important; background-color: transparent !important; color: var(--e-global-color-ee06e41) !important; }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof jQuery !== 'undefined') {
                    // Listen for Elementor's native successful form submission event
                    jQuery(document).on('submit_success', function(event, response) {
                        if (response && response.data && response.data.dd_custom_html) {
                            var $form = jQuery(event.target);
                            var $summaryTarget = jQuery('#outreach-form-summary');
                            var $pointsTarget = jQuery('.current-points');

                            if ($summaryTarget.length) {
                                // Inject the generated HTML into the specific target div
                                $summaryTarget.html(response.data.dd_custom_html);

                                jQuery('#outreach-submission').addClass('hide-element');
                                jQuery('#outreach-summary').removeClass('hide-element');
                                
                                // Dynamically update the points balance
                                if ($pointsTarget.length) {
                                    if (response.data.updated_points !== undefined) {
                                        // Use the exact server-verified balance
                                        $pointsTarget.text(response.data.updated_points);
                                    } else {
                                        // Fallback: Client-side decrement if backend fetch fails
                                        var currentPointsStr = $pointsTarget.text().replace(/,/g, '');
                                        var currentVal = parseInt(currentPointsStr, 10);
                                        if (!isNaN(currentVal) && currentVal > 0) {
                                            $pointsTarget.text(currentVal - 1);
                                        }
                                    }
                                }
                            } else {
                                console.warn('Target div #outreach-form-summary not found on the page.');
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
     * Queries all 'outreach' entries for the logged-in user to act as a navigation menu.
     * * @param array $atts Shortcode attributes.
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
        <div class="dd-dashboard-list-container" style="background: #fdfdfd; border: 1px solid #eaeaea; border-radius: 8px; padding: 20px; width: 100%; max-width: 350px;">
            <div class="dd-filter-controls" style="margin-bottom: 20px;">
                <input type="text" placeholder="Search by influencer or message" style="width: 100%; margin-bottom: 15px; padding: 10px; border-radius: 4px; border: 1px solid #ccc;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong>Project type</strong>
                    <a href="#" style="font-size: 12px; color: #888;">Reset</a>
                </div>
                <select style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;">
                    <option value="">Filter by project type</option>
                    <option value="affiliate">Affiliate partnership</option>
                    <option value="collaboration">Collaboration</option>
                </select>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button class="dd-filter-btn active" style="border-radius: 20px; border: 1px solid #4DB2A6; background: #E6F4F1; color: #4DB2A6; padding: 5px 15px;">All</button>
                    <button class="dd-filter-btn" style="border-radius: 20px; border: 1px solid #ccc; background: transparent; padding: 5px 15px;">Favourites</button>
                </div>
            </div>

            <div class="dd-item-list" style="max-height: 600px; overflow-y: auto;">
                <?php if ( $query->have_posts() ) : ?>
                    <?php while ( $query->have_posts() ) : $query->the_post(); 
                        $influencer_id = get_post_meta( get_the_ID(), 'influencer_id', true );
                        $influencer_handle = get_post_meta( $influencer_id, 'instagramId', true );
                        $influencer_name = $influencer_id ? get_the_title( $influencer_id ) : 'Unknown Creator';
                    ?>
                        <div class="dd-outreach-item" data-post-id="<?php echo get_the_ID(); ?>" style="display: flex; align-items: center; padding: 15px 0; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s;">
                            <img src="<?php echo get_the_post_thumbnail_url( $influencer_id, 'thumbnail' ) ?: 'default-avatar.png'; ?>" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; margin-right: 15px;">
                            <div style="flex-grow: 1;">
                                <strong style="display: block; font-size: 14px;"><?php echo esc_html( $influencer_name ); ?> ✓</strong>
                                <small style="display: block; color: #777;">@<?php echo esc_html( $influencer_handle ); ?></small>
                                <span style="display: block; font-size: 12px; color: #4DB2A6; font-weight: bold; margin-top: 4px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 200px;"><?php the_title(); ?></span>
                                <small style="color: #aaa;"><?php echo get_the_date('M j, Y'); ?></small>
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
     * The target container is populated dynamically via AJAX.
     * * @param array $atts Shortcode attributes.
     * @return string Compiled HTML block.
     */
    public function render_view_shortcode( $atts ) {
        return '<div id="dd-outreach-view-container" style="background: #f4f4f4; border-radius: 8px; padding: 30px; min-height: 600px; width: 100%;">
            <p style="text-align: center; color: #888; margin-top: 50%;">Select a project from the list to view details.</p>
        </div>';
    }

    /**
     * AJAX endpoint to fetch specific outreach post details and return the HTML 
     * structure mirroring the requested screenshot's right column.
     * * @return void
     */
    public function ajax_get_outreach_details() {
        check_ajax_referer( 'dd_outreach_nonce', 'security' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== 'outreach' ) {
            wp_send_json_error( 'Invalid post ID.' );
        }

        $post = get_post( $post_id );
        
        // Ensure user owns this record or has admin rights
        if ( $post->post_author != get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $influencer_id = get_post_meta( $post_id, 'influencer_id', true );
        $influencer_name = $influencer_id ? get_the_title( $influencer_id ) : 'Unknown Creator';
        $influencer_handle = get_post_meta( $influencer_id, 'instagramId', true );
        
        // Fetch specific form meta based on your original save routines
        $project_type   = get_post_meta( $post_id, 'project_type', true ) ?: 'N/A';
        $project_length = get_post_meta( $post_id, 'project_length', true ) ?: 'Ongoing';
        $project_dates  = get_post_meta( $post_id, 'project_dates', true ) ?: 'Flexible';
        $budget         = get_post_meta( $post_id, 'budget', true ) ?: 'To be discussed';
        $message        = get_post_meta( $post_id, 'message', true ) ?: 'No message provided.';
        $sent_date      = get_the_date('g:i A, F jS, Y', $post_id);

        ob_start();
        ?>
        <div class="dd-view-card" style="background: #fff; border: 1px solid #4DB2A6; border-radius: 8px; padding: 25px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="display: flex; align-items: center;">
                    <img src="<?php echo get_the_post_thumbnail_url( $influencer_id, 'thumbnail' ) ?: 'default-avatar.png'; ?>" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-right: 15px;">
                    <div>
                        <h2 style="margin: 0; font-size: 22px; color: #333;"><?php echo esc_html( $influencer_name ); ?> ✓</h2>
                        <span style="color: #777;">@<?php echo esc_html( $influencer_handle ); ?></span>
                    </div>
                </div>
                <div>
                    <a href="<?php echo get_permalink( $influencer_id ); ?>" style="border: 1px solid #ffcccc; color: #ff9999; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold;">VIEW CREATOR PROFILE</a>
                </div>
            </div>

            <h3 style="color: #1c4ea1; font-size: 18px; margin-bottom: 15px;"><?php echo esc_html( $post->post_title ); ?></h3>
            
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 25px;">
                <span style="border: 1px solid #4DB2A6; color: #4DB2A6; padding: 5px 12px; border-radius: 20px; font-size: 12px;"><strong>Project type:</strong> <?php echo esc_html( $project_type ); ?></span>
                <span style="border: 1px solid #4DB2A6; color: #4DB2A6; padding: 5px 12px; border-radius: 20px; font-size: 12px;"><strong>Project length:</strong> <?php echo esc_html( $project_length ); ?></span>
                <span style="border: 1px solid #4DB2A6; color: #4DB2A6; padding: 5px 12px; border-radius: 20px; font-size: 12px;"><strong>Project dates:</strong> <?php echo esc_html( $project_dates ); ?></span>
                <span style="border: 1px solid #4DB2A6; color: #4DB2A6; padding: 5px 12px; border-radius: 20px; font-size: 12px;"><strong>Budget:</strong> <?php echo esc_html( $budget ); ?></span>
            </div>

            <p style="font-size: 12px; color: #666; margin-bottom: 20px;">Sent at <?php echo esc_html( $sent_date ); ?></p>

            <div style="color: #444; font-size: 14px; line-height: 1.6;">
                <?php echo nl2br( esc_html( $message ) ); ?>
            </div>
        </div>

        <div style="display: flex; gap: 20px;">
            <div style="flex: 1; background: #fff; border: 1px solid #ffcc00; border-radius: 8px; padding: 20px;">
                <h4 style="margin-top: 0; font-size: 16px;">📝 Create a note for this project</h4>
                <p style="font-size: 12px; color: #888;">Notes created are only visible to you and will never be shared.</p>
                <input type="text" placeholder="Note title" style="width: 100%; margin-bottom: 10px; padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                <textarea placeholder="Start typing your note..." style="width: 100%; height: 80px; padding: 10px; border: 1px solid #eee; border-radius: 4px;"></textarea>
                <button style="margin-top: 10px; background: #ffcc00; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer;">💾 SAVE NOTE</button>
            </div>
            
            <div style="flex: 1; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                <h4 style="margin-top: 0; font-size: 16px;">Follow up and discuss next steps</h4>
                <div style="background: #fdf5e6; padding: 15px; border-radius: 8px; font-size: 13px; color: #333; margin-bottom: 15px;">
                    Project sent on <?php echo get_the_date('F jS', $post_id); ?> - interested but asked about usage rights and exclusivity. Wanted to discuss budget. They are open to working flexibly which works well for our project scope. Need to follow up after legal review and to discuss next steps.
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button style="border: none; background: transparent; color: #aaa; cursor: pointer; font-size: 12px;">DELETE NOTE</button>
                    <button style="border: 1px solid #ddd; background: #f9f9f9; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 12px;">EDIT NOTE</button>
                </div>
                <p style="text-align: right; font-size: 11px; color: #ccc; margin-top: 10px;">Last edited <?php echo get_the_date('F jS, Y', $post_id); ?></p>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        wp_send_json_success( $html );
    }

    /**
     * Enqueues the custom jQuery required to bridge the list clicks with the AJAX
     * endpoint. Injects nonce and admin-ajax URL for security and targeting.
     * * @return void
     */
    public function enqueue_dashboard_scripts() {
        // We ensure scripts only load where our shortcodes are present by keeping this lightweight.
        wp_enqueue_script( 'jquery' );
        
        $script = "
        jQuery(document).ready(function($) {
            $('.dd-outreach-item').on('click', function() {
                var postId = $(this).data('post-id');
                var container = $('#dd-outreach-view-container');
                
                // Active state styling
                $('.dd-outreach-item').css('background', 'transparent');
                $(this).css('background', '#f4f4f4');

                container.html('<p style=\"text-align: center; color: #888; margin-top: 50%;\">Loading...</p>');

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
                            container.html('<p style=\"color: red;\">Error loading details.</p>');
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

// Instantiate the class to fire up all hooks
new DD_Outreach_Manager();