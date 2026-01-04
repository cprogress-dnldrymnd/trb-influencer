<?php
add_action('wp_ajax_my_custom_loop_filter', 'my_custom_loop_filter_handler');
add_action('wp_ajax_nopriv_my_custom_loop_filter', 'my_custom_loop_filter_handler');

function my_custom_loop_filter_handler() {
    // 1. SECURITY & INPUTS
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    
    // 2. CONFIGURATION
    
    // 3. BUILD THE QUERY
    $args = [
        'post_type'      => 'influencer', // or your custom post type
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ];

    if ( !empty($category) ) {
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
    if ( $query->have_posts() ) {
        // Start Output Buffering
        ob_start();

        while ( $query->have_posts() ) {
            $query->the_post();
            
            // This is the Elementor magic method
            // It renders the specific template ID with the current post's data
            if ( class_exists( '\Elementor\Plugin' ) ) {
                echo do_shortcode('[elementor-template id="1839"]');
            } else {
                echo 'Elementor not loaded.';
            }
        }
        
        // Reset Post Data
        wp_reset_postdata();

        // Send back the HTML
        wp_send_json_success( ob_get_clean() );
    } else {
        wp_send_json_error( 'No posts found' );
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

function handle_save_search_ajax() {
    // 1. Security Check: Verify the nonce sent from JavaScript matches what the server expects.
    // This prevents Cross-Site Request Forgery (CSRF) attacks.
    check_ajax_referer('save_search_nonce', 'security');

    // 2. Authentication Check: Ensure the user is actually logged in.
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please login to save searches.']);
    }

    // 3. Data Retrieval: Get current user ID and the data array sent via AJAX.
    $user_id = get_current_user_id();
    $data    = isset($_POST['search_data']) ? $_POST['search_data'] : [];

    // 4. Post Creation: Prepare the arguments to insert a new post.
    // We dynamically generate a title using the current date/time.
    $post_title = 'Search saved on ' . current_time('M j, Y @ g:i a');

    $post_args = [
        'post_title'  => $post_title,
        'post_type'   => 'saved-search', // The slug of your Custom Post Type
        'post_status' => 'publish',        // Publish immediately
        'post_author' => $user_id,         // Assign authorship to the current user
    ];

    // Attempt to insert the post into the database.
    $post_id = wp_insert_post($post_args);

    // If post insertion failed, return an error to the frontend.
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'Error creating save file.']);
    }

    // 5. Save Custom Fields: specific array of keys we expect from the form.
    $fields_to_save = [
        'niche',
        'platform',
        'followers',
        'country',
        'lang',
        'gender',
        'score'
    ];

    // Loop through the allowed fields and save them as Post Meta.
    foreach ($fields_to_save as $key) {
        if (!empty($data[$key])) {
            // update_post_meta handles strings and arrays (serialized) automatically.
            // If using ACF, you could replace this with: update_field($key, $data[$key], $post_id);
            update_post_meta($post_id, $key, $data[$key]);
        }
    }

    // 6. Success Response: Send a JSON success signal back to the JavaScript.
    wp_send_json_success(['message' => 'Search saved successfully!']);
}