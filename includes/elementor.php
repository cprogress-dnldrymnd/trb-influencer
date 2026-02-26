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
 * Modifies an Elementor post query to filter posts by the current logged-in user's ID.
 * * This function hooks into Elementor's custom query API. When the query ID 
 * 'current_user_posts' is specified in the Elementor widget, this function intercepts 
 * the WP_Query object and assigns the current user's ID to the 'author' parameter.
 * If no user is logged in, it forces a '0' author ID to ensure no posts are returned.
 *
 * @param \WP_Query $query The WordPress query instance being modified by Elementor.
 * @return void
 */
function digitally_disruptive_filter_by_current_user($query)
{

    // Check if a user is currently logged into the site
    if (is_user_logged_in()) {
        // Retrieve the current user's ID
        $current_user_id = get_current_user_id();

        // Modify the query to only fetch posts authored by this user ID
        $query->set('author', $current_user_id);
    } else {
        // If the user is a guest, force the query to return no results
        // by setting the author ID to 0 (an invalid user ID in WordPress).
        $query->set('author', 0);
    }
}

// Hook the function to the specific Elementor query ID 'current_user_posts'
add_action('elementor/query/current_user_posts', 'digitally_disruptive_filter_by_current_user');



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
 * Registers the myCRED visibility control within the Elementor editor.
 *
 * This function injects a new control section into the 'Advanced' tab of all 
 * Elementor widgets, sections, and columns. It provides a dropdown selection 
 * to easily toggle visibility based on a 0 point or 1+ point threshold.
 *
 * @param \Elementor\Element_Base $element The current Elementor element instance being registered.
 * @param array                   $args    Additional arguments passed by the hook (unused).
 * @return void
 */
function dd_register_mycred_visibility_controls( $element, $args ) {
    // Initiate a new custom section in the Advanced Tab
    $element->start_controls_section(
        'dd_mycred_visibility_section',
        [
            'label' => __( 'myCRED Visibility', 'dd-elementor-mycred' ),
            'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
        ]
    );

    // Add a select control for predefined visibility rules
    $element->add_control(
        'dd_mycred_visibility_rule',
        [
            'label'   => __( 'Visibility Rule', 'dd-elementor-mycred' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '',
            'options' => [
                ''           => __( 'None (Always Show)', 'dd-elementor-mycred' ),
                'zero'       => __( 'Show only when 0 points', 'dd-elementor-mycred' ),
                'has_points' => __( 'Show when 1 or more points', 'dd-elementor-mycred' ),
            ],
            'description' => __( 'Select when this element should be visible to logged-in users based on their myCRED balance.', 'dd-elementor-mycred' ),
        ]
    );

    $element->end_controls_section();
}
// Attach the control function to widgets, sections, and columns
add_action( 'elementor/element/common/_section_style/after_section_end', 'dd_register_mycred_visibility_controls', 10, 2 );
add_action( 'elementor/element/section/section_advanced/after_section_end', 'dd_register_mycred_visibility_controls', 10, 2 );
add_action( 'elementor/element/column/section_advanced/after_section_end', 'dd_register_mycred_visibility_controls', 10, 2 );

/**
 * Evaluates the myCRED visibility condition before rendering an Elementor element.
 *
 * This function intercepts Elementor's frontend rendering pipeline. It checks the 
 * selected visibility rule and queries the 'mycred_default' user meta directly 
 * to determine the user's point balance, avoiding API loading sequence issues.
 *
 * @param bool                    $should_render Whether the element is currently set to render.
 * @param \Elementor\Element_Base $element       The current Elementor element instance.
 * @return bool True to render the element, false to hide it.
 */
function dd_evaluate_mycred_condition( $should_render, $element ) {
    // If the element is already marked not to render by another process, respect that decision.
    if ( ! $should_render ) {
        return $should_render;
    }

    // Retrieve the display settings for the current element
    $settings = $element->get_settings_for_display();
    $rule     = isset( $settings['dd_mycred_visibility_rule'] ) ? $settings['dd_mycred_visibility_rule'] : '';

    // If a specific visibility rule is applied
    if ( ! empty( $rule ) ) {
        $user_id = get_current_user_id();

        // Deny rendering if the user is a guest (no myCRED balance exists)
        if ( ! $user_id ) {
            return false; 
        }

        // Fetch the user's current myCRED balance directly from user meta
        // Casting to float ensures empty values (never earned points) safely become 0
        $balance = (float) get_user_meta( $user_id, 'mycred_default', true );

        // Evaluate the "Show only when 0 points" condition
        if ( 'zero' === $rule && $balance > 0 ) {
            return false; // Hide because they have more than 0 points
        }

        // Evaluate the "Show when 1 or more points" condition
        if ( 'has_points' === $rule && $balance < 1 ) {
            return false; // Hide because they have less than 1 point
        }
    }

    // Return the default render state if conditions are met or not applied
    return $should_render;
}
add_filter( 'elementor/frontend/should_render', 'dd_evaluate_mycred_condition', 10, 2 );