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