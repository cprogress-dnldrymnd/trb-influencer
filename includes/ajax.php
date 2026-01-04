<?php
add_action('wp_ajax_my_custom_loop_filter', 'my_custom_loop_filter_handler');
add_action('wp_ajax_nopriv_my_custom_loop_filter', 'my_custom_loop_filter_handler');

function my_custom_loop_filter_handler()
{
    // 1. SECURITY & INPUTS
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';

    // 2. CONFIGURATION

    // 3. BUILD THE QUERY
    $args = [
        'post_type'      => 'influencer', // or your custom post type
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ];

    if (!empty($category)) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'category', // or your custom taxonomy
                'field'    => 'slug',
                'terms'    => $category,
            ]
        ];
    }

    $query = new WP_Query($args);

    // 4. RENDER ELEMENTOR LOOP
    if ($query->have_posts()) {
        // Start Output Buffering
        ob_start();

        while ($query->have_posts()) {
            $query->the_post();

            // This is the Elementor magic method
            // It renders the specific template ID with the current post's data
            if (class_exists('\Elementor\Plugin')) {
                echo do_shortcode('[elementor-template id="1839"]');
            } else {
                echo 'Elementor not loaded.';
            }
        }

        // Reset Post Data
        wp_reset_postdata();

        // Send back the HTML
        wp_send_json_success(ob_get_clean());
    } else {
        wp_send_json_error('No posts found');
    }

    wp_die();
}


/**
 * AJAX Handler: Save User Search
 * * This function handles the server-side logic when the "Save Search" button is clicked.
 * It verifies security, creates a new post in the 'saved_searches' CPT, 
 * and saves the filter inputs as post meta data.
 */

add_action('wp_ajax_save_user_search', 'handle_save_search_ajax');

function handle_save_search_ajax()
{
    // 1. Security & Auth Check
    check_ajax_referer('save_search_nonce', 'security');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please login to save searches.']);
    }

    $user_id = get_current_user_id();

    // Get the raw data array
    $raw_data = isset($_POST['search_data']) ? $_POST['search_data'] : [];

    // 2. Sanitize and Prepare Data
    // We create a clean array to ensure only allowed fields are saved
    $allowed_keys = [
        'niche',
        'platform',
        'followers',
        'country',
        'lang',
        'gender',
        'score'
    ];

    $clean_data = [];

    foreach ($allowed_keys as $key) {
        if (isset($raw_data[$key])) {
            // Sanitize based on type. 
            // If it's an array (checkboxes), we map over it with sanitize_text_field.
            // If it's a string (range slider), we sanitize it directly.
            if (is_array($raw_data[$key])) {
                $clean_data[$key] = array_map('sanitize_text_field', $raw_data[$key]);
            } else {
                $clean_data[$key] = sanitize_text_field($raw_data[$key]);
            }
        }
    }

    // 3. Convert to String (JSON is recommended for portability)
    $search_query_string = json_encode($clean_data);

    // 4. Create Post
    $post_title = 'Search saved on ' . current_time('M j, Y @ g:i a');

    $post_args = [
        'post_title'  => $post_title,
        'post_type'   => 'saved-search', // Matches your updated slug
        'post_status' => 'publish',
        'post_author' => $user_id,
    ];

    $post_id = wp_insert_post($post_args);

    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'Error creating save file.']);
    }

    // 5. Save as SINGLE meta field
    update_post_meta($post_id, 'search_query', $search_query_string);

    wp_send_json_success(['message' => 'Search saved successfully!']);
}
