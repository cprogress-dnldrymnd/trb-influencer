<?php

/**
 * Disable Elementor Pro / Pro Elements Header & Footer on Dashboard Template
 */
add_filter('elementor/theme/get_location_templates/template_id', function ($template_id, $location) {
    // Check if we are on the specific page template
    if (is_page_template('templates/page-dashboard.php') || (is_single() && get_post_type() == 'influencer')) {
        // If the location is header or footer, return 0 to skip the Elementor template
        if (in_array($location, ['header', 'footer'])) {
            return 0;
        }
    }

    return $template_id;
}, 10, 2);


/**
 * Update the query to fetch only recently viewed post IDs.
 *
 * @since 1.0.0
 * @param \WP_Query $query The WordPress query instance.
 */
function recently_view_influencers($query)
{

    // 1. Get the array of IDs
    $recently_viewed = get_recent_influencer_ids_array(5);

    // 2. Check if we actually have IDs to show
    if (! empty($recently_viewed)) {
        // Only fetch posts that match these IDs
        $query->set('post__in', $recently_viewed);

        // Optional: Ensure they display in the order they were viewed
        $query->set('orderby', 'post__in');

        // Ensure pagination doesn't interfere if you want exactly these 5
        $query->set('posts_per_page', 5);
    } else {
        // 3. If no history exists, force the query to return nothing
        // (Otherwise, WP might default to showing the latest posts)
        $query->set('post__in', array(0));
    }
}
add_action('elementor/query/recently_view_influencers', 'recently_view_influencers');


add_action('elementor/query/influencer_search', function ($query) {

    // Arrays to hold our conditions
    $meta_query = array();
    $tax_query = array();

    // 1. Check for 'color' in URL and add to Meta Query
    if (isset($_GET['color']) && !empty($_GET['color'])) {
        $meta_query[] = array(
            'key'     => 'product_color', // Your actual meta key
            'value'   => sanitize_text_field($_GET['color']),
            'compare' => '=',
        );
    }

    // 2. Check for 'cat' in URL and add to Tax Query
    if (isset($_GET['cat']) && !empty($_GET['cat'])) {
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => sanitize_text_field($_GET['cat']),
        );
    }

    // 3. Apply the queries if they exist
    if (! empty($meta_query)) {
        $query->set('meta_query', $meta_query);
    }

    if (! empty($tax_query)) {
        $query->set('tax_query', $tax_query);
    }
});




/**
 * Elementor Custom Query Filter: saved_lists
 * Filters the query to show posts defined in 'saved-influencer' CPT meta.
 */
add_action('elementor/query/saved_lists', function ($query) {

    // 1. Security: If not logged in, show nothing.
    if (! is_user_logged_in()) {
        $query->set('post__in', [0]);
        return;
    }


    $influencer_ids = get_saved_influencer();

    // 3. Apply the IDs to the Elementor Query
    if (! empty($influencer_ids)) {
        // Ensure they are integers

        $query->set('post__in', $influencer_ids);

        // Optional: If you want to keep the order they were saved in:
        // $query->set( 'orderby', 'post__in' );
    } else {
        // No saved items found, force empty result
        $query->set('post__in', [0]);
    }
});

/**
 * Elementor Custom Query Filter: unlocked_influencers
 * Filters the query to show posts purchased by current user.
 */
add_action('elementor/query/unlocked_influencers', function ($query) {

    // 1. Security: If not logged in, show nothing.
    if (! is_user_logged_in()) {
        $query->set('post__in', [0]);
        return;
    }


    $influencer_ids = get_user_purchased_post_ids();

    // 3. Apply the IDs to the Elementor Query
    if (! empty($influencer_ids)) {
        // Ensure they are integers

        $query->set('post__in', $influencer_ids);

        // Optional: If you want to keep the order they were saved in:
        // $query->set( 'orderby', 'post__in' );
    } else {
        // No saved items found, force empty result
        $query->set('post__in', [0]);
    }
});

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
function dd_custom_elementor_form_response($record, $ajax_handler)
{
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
        'post_author' => get_current_user_id(), // Attributes the post to the logged-in user submitting the form
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
    }

    // Generate the current date
    $date_sent = date_i18n(get_option('date_format'));

    // Capture the structured HTML layout
    ob_start();
?>
    <div class="dd-message-overview">
        <span class="m-overview">Message overview</span>
        <span class="date"><?php echo esc_html($date_sent); ?></span>
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
    </div>
<?php
    $custom_html = ob_get_clean();

    // Inject the payload into Elementor's native AJAX response object
    $ajax_handler->add_response_data('dd_custom_html', $custom_html);
}
add_action('elementor_pro/forms/new_record', 'dd_custom_elementor_form_response', 10, 2);

/**
 * Injects frontend JavaScript and CSS required to catch the Elementor AJAX response
 * and render the custom HTML layout inside a specific target div (#outreach-form-summary).
 *
 * @return void
 */
function dd_elementor_success_scripts()
{
?>
    <style>
        .dd-message-overview-container {
            font-family: inherit;
            margin-top: 20px;
            border-radius: 5px;
            border: 1px solid #3B1527;
        }

        .dd-profile-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
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

        .tags-container {
            padding: 15px 20px;
        }

        .dd-subject-title {
            color: #034146;
            font-size: 18px !important;
            font-weight: bold;
            margin: 0;
            border-top: 1px solid #E7E7E7;
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
            padding: 15px 20px;
            border-top: 1px solid #E7E7E7;
        }

        .dd-footer {
            display: flex;
            gap: 15px;
        }

        .view-outreach a {
            background-color: var(--e-global-color-accent) !important;
            border: 1px solid var(--e-global-color-accent);
            color: #fff !important;
        }

        .view-outreach a {
            border-style: solid;
            border-color: var(--e-global-color-ee06e41) !important;
            background-color: transparent !important;
            color: var(--e-global-color-ee06e41) !important;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof jQuery !== 'undefined') {
                // Listen for Elementor's native successful form submission event
                jQuery(document).on('submit_success', function(event, response) {
                    if (response && response.data && response.data.dd_custom_html) {
                        var $form = jQuery(event.target);
                        var $summaryTarget = jQuery('#outreach-form-summary');

                        if ($summaryTarget.length) {
                            // Inject the generated HTML into the specific target div
                            $summaryTarget.html(response.data.dd_custom_html);

                            jQuery('#outreach-submission').addClass('hide-element');
                            jQuery('#outreach-summary').removeClass('hide-element');
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
add_action('wp_footer', 'dd_elementor_success_scripts');
