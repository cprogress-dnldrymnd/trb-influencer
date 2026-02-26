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
 * Injects the custom "MyCred Visibility" controls into the Advanced tab of all Elementor widgets.
 * Hooked into 'elementor/element/common/_section_style/after_section_end' to register 
 * a new controls section immediately following the standard layout controls.
 *
 * @param \Elementor\Element_Base $element The current element instance being evaluated.
 * @param array                   $args    Additional arguments passed by the hook.
 * @return void
 */
function dd_add_mycred_visibility_control( \Elementor\Element_Base $element, $args ) {
	// Initialize a new controls section specifically for MyCred Visibility logic
	$element->start_controls_section(
		'dd_mycred_visibility_section',
		[
			'label' => esc_html__( 'MyCred Visibility', 'dd-elementor-mycred' ),
			'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
		]
	);

	// Register the Select control to define the MyCred balance condition for rendering
	$element->add_control(
		'dd_mycred_condition',
		[
			'label'   => esc_html__( 'Display Widget When Points Are:', 'dd-elementor-mycred' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'always',
			'options' => [
				'always'            => esc_html__( 'Always (Ignore Points)', 'dd-elementor-mycred' ),
				'less_than_zero'    => esc_html__( 'Less Than 0', 'dd-elementor-mycred' ),
				'greater_than_zero' => esc_html__( 'More Than 0', 'dd-elementor-mycred' ),
			],
			'description' => esc_html__( 'Determine if this widget should render based on the current user\'s MyCred balance. Non-logged-in users evaluate as having 0 points.', 'dd-elementor-mycred' ),
		]
	);

	$element->end_controls_section();
}
add_action( 'elementor/element/common/_section_style/after_section_end', 'dd_add_mycred_visibility_control', 10, 2 );

/**
 * Intercepts the render pipeline and evaluates the widget's visibility against the current user's MyCred balance.
 * Prevents the widget from being output on the frontend if the 'dd_mycred_condition' is not met.
 *
 * @param bool                    $should_render Boolean indicating if the widget is scheduled to render.
 * @param \Elementor\Element_Base $widget        The active widget instance payload.
 * @return bool Modified boolean dictating if the widget outputs HTML to the buffer.
 */
function dd_evaluate_mycred_widget_render( $should_render, \Elementor\Element_Base $widget ) {
	// Abort early if inside the Elementor Editor. 
	// Returning false here would break the editor UI, preventing users from modifying the condition.
	if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
		return $should_render;
	}

	// Retrieve the specific display settings for this localized widget instance
	$settings = $widget->get_settings_for_display();

	// Bail early if the condition is set to 'always' or is undefined
	if ( empty( $settings['dd_mycred_condition'] ) || 'always' === $settings['dd_mycred_condition'] ) {
		return $should_render;
	}

	// Check if MyCred is active; if not, default to standard rendering behavior to prevent fatal errors
	if ( ! function_exists( 'mycred_get_users_balance' ) ) {
		return $should_render;
	}

	// Fetch the current user's MyCred balance. Defaults to 0 for unauthenticated traffic.
	$user_id = get_current_user_id();
	$balance = $user_id ? mycred_get_users_balance( $user_id ) : 0;

	// Evaluate the selected condition against the user's fetched balance
	if ( 'less_than_zero' === $settings['dd_mycred_condition'] && $balance >= 0 ) {
		return false; 
	}

	if ( 'greater_than_zero' === $settings['dd_mycred_condition'] && $balance <= 0 ) {
		return false; 
	}

	return $should_render;
}
add_filter( 'elementor/frontend/widget/should_render', 'dd_evaluate_mycred_widget_render', 10, 2 );