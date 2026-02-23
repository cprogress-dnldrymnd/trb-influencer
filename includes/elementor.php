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
 *
 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record     The form submission record.
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
            <img src="https://ui-avatars.com/api/?name=Cory+Ruth&background=random&rounded=true" alt="Profile" class="dd-avatar">
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
<?php
    $custom_html = ob_get_clean();

    // Inject the payload into Elementor's native AJAX response object
    $ajax_handler->add_response_data('dd_custom_html', $custom_html);
}
add_action('elementor_pro/forms/new_record', 'dd_custom_elementor_form_response', 10, 2);