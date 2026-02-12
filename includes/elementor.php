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

