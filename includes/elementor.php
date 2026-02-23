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
 * Plugin Name: Elementor Custom Success Message Layout
 * Description: Overrides the default Elementor Form success message with a dynamically generated, structured layout displaying submitted data.
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Version:     1.0.0
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Intercepts the Elementor Form submission to compile a custom HTML payload 
 * using the submitted field values and appends it to the AJAX response.
 * Execution is strictly limited to the 'outreach_form' form ID.
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

    // Capture the structured HTML layout
    ob_start();
?>
    <div class="dd-message-overview-container">

        <div class="dd-overview-body">
            <div class="dd-dynamic-tags">
                <span class="dd-tag"><strong>Project type:</strong> <?php echo esc_html($data['project_type'] ?? 'N/A'); ?></span>
                <span class="dd-tag"><strong>Project length:</strong> <?php echo esc_html($data['project_length'] ?? 'N/A'); ?></span>
                <span class="dd-tag"><strong>Project Dates:</strong> <?php echo esc_html($data['project_dates'] ?? 'Flexible'); ?></span>
                <span class="dd-tag"><strong>Budget:</strong> <?php echo esc_html($data['budget'] ?? 'To be discussed'); ?></span>
            </div>

            <h3 class="dd-subject-title"><?php echo esc_html($data['subject']); ?></h3>

            <div class="dd-message-content">
                <?php echo nl2br(esc_html($data['message'] ?? 'No message provided.')); ?>
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
 * and render the custom HTML layout inside the success container.
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

        .dd-overview-body {
            border: 1px solid #90caf9;
            border-radius: 8px;
            background: #f4f6f8;
            padding: 25px;
            text-align: left;
        }

        .dd-profile-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
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

        .dd-btn-outline {
            margin-left: auto;
            padding: 8px 16px;
            border: 1px solid #ff8a65;
            color: #ff8a65;
            text-transform: uppercase;
            font-size: 12px;
            font-weight: bold;
            border-radius: 4px;
            text-decoration: none;
        }

        .dd-dynamic-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid #e0e0e0;
        }

        .dd-tag {
            background: transparent;
            border: 1px solid #00695c;
            color: #004d40;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
        }

        .dd-subject-title {
            color: #004d40;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: bold;
        }

        .dd-message-content {
            font-size: 14px;
            color: #333;
            line-height: 1.6;
            max-height: 300px;
            overflow-y: auto;
            padding-right: 10px;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof jQuery !== 'undefined') {
                // Listen for Elementor's native successful form submission event
                jQuery(document).on('submit_success', function(event, response) {
                    if (response && response.data && response.data.dd_custom_html) {
                        var $form = jQuery(event.target);
                        // Locate Elementor's success message wrapper
                        var $messageContainer = $form.siblings('.elementor-message-success').length ?
                            $form.siblings('.elementor-message-success') :
                            $form.find('.elementor-message-success');

                        // Replace text with our structured payload and strip default Elementor success styling
                        if ($messageContainer.length) {
                            $messageContainer.html(response.data.dd_custom_html).css({
                                'background': 'transparent',
                                'color': 'inherit',
                                'padding': '0',
                                'border': 'none',
                                'box-shadow': 'none'
                            });
                            // Optionally, hide the form fields so only the summary is visible
                            $form.find('.elementor-form-fields-wrapper').slideUp();
                        }
                    }
                });
            }
        });
    </script>
<?php
}
add_action('wp_footer', 'dd_elementor_success_scripts');
