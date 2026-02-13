<?php
/**
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

add_action('wp_ajax_my_custom_loop_filter', 'my_custom_loop_filter_handler');
add_action('wp_ajax_nopriv_my_custom_loop_filter', 'my_custom_loop_filter_handler');

function my_custom_loop_filter_handler()
{
    // 1. GATHER INPUTS
    // ... (Your existing input gathering code remains the same) ... 

    // [Truncated for brevity - assume inputs are gathered here as per your original code]
    $niche        = isset($_POST['niche']) ? $_POST['niche'] : [];
    $platform     = isset($_POST['platform']) ? $_POST['platform'] : [];
    $country      = isset($_POST['country']) ? $_POST['country'] : [];
    $lang         = isset($_POST['lang']) ? $_POST['lang'] : [];
    $followers    = isset($_POST['followers']) ? $_POST['followers'] : [];
    $filter       = isset($_POST['filter']) ? $_POST['filter'] : [];
    $topic        = isset($_POST['topic']) ? $_POST['topic'] : [];
    $content_tag  = isset($_POST['content_tag']) ? $_POST['content_tag'] : [];

    // --- FIX 1: Capture the current page number ---
    $paged = (isset($_POST['paged']) && $_POST['paged']) ? intval($_POST['paged']) : 1;

    // 3. BUILD THE QUERY ARGS
    $args = [
        'post_type'      => 'influencer',
        'posts_per_page' => 12,
        'post_status'    => 'publish',
        'paged'          => $paged, // <--- FIX 2: Pass the page number to the query
    ];

    // ... (Your Taxonomy and Meta Query logic remains exactly the same) ...
    // ... Copy your existing tax_query and meta_query logic here ...

    // [Assuming tax_query and meta_query are built here as per your original code]
    // Re-adding the logic blocks just for context of where they fit in the original file
    // (You don't need to change the logic inside the tax/meta blocks, just keep them as is)

    // ... 

    // 3. EXECUTE QUERY
    $query = new WP_Query($args);

    // --- BROADENING / FALLBACK LOGIC ---
    // You must also apply 'paged' to your fallback queries if you want pagination to work on fallbacks.

    $min_results = 6;
    $has_broadening_filters = !empty($country) || !empty($followers) || !empty($lang)
        || (!empty($filter) && in_array('Include only verified influencers', $filter, true));

    if ($query->found_posts < $min_results && $has_broadening_filters && ($query->have_posts() || !empty($niche) || !empty($platform))) {
        // ... (Broadened args setup) ...
        $broadened_args['paged'] = $paged; // <--- FIX 3: Add pagination to fallback
        $query = new WP_Query($broadened_args);
    }

    if (!$query->have_posts() && (!empty($niche) || !empty($platform) || !empty($country) || !empty($followers))) {
        // ... (Fallback args setup) ...
        $fallback_args['paged'] = $paged; // <--- FIX 3: Add pagination to fallback
        $query = new WP_Query($fallback_args);

        if (!$query->have_posts()) {
            $query = new WP_Query([
                'post_type'      => 'influencer',
                'posts_per_page' => 12,
                'post_status'    => 'publish',
                'paged'          => $paged // <--- FIX 3: Add pagination to last resort
            ]);
        }
    }

    if ($query->have_posts()) {
        // ... (Your sorting and output buffer logic remains the same) ...

        $search_criteria = [ /* ... your array ... */];
        // ... set_query_var ... 

        $posts = $query->posts;

        // Note: usort here sorts ONLY the 12 posts on the current page, not the whole DB.
        if (function_exists('influencer_calculate_match_score')) {
            usort($posts, function ($a, $b) use ($search_criteria) {
                $sa = influencer_calculate_match_score($a->ID, $search_criteria);
                $sb = influencer_calculate_match_score($b->ID, $search_criteria);
                return $sb <=> $sa;
            });
        }

        ob_start();
        foreach ($posts as $post) {
            $GLOBALS['post'] = $post;
            setup_postdata($post);
            // ... output ...
            if (class_exists('\Elementor\Plugin')) {
                echo do_shortcode('[elementor-template id="1839"]');
            }
        }
        wp_reset_postdata();
        
        $html_output = ob_get_clean();

        // --- UPDATE: Increment User Meta on Finish ---
        if ( is_user_logged_in() ) {
            $current_user_id = get_current_user_id();
            $current_count   = (int) get_user_meta($current_user_id, 'number_of_searches', true);
            update_user_meta($current_user_id, 'number_of_searches', $current_count + 1);
        }

        // --- FIX 4: Send 'max_pages' in the response ---
        wp_send_json_success(array(
            'html'        => $html_output,
            'found_posts' => $query->found_posts,
            'max_pages'   => $query->max_num_pages // <--- CRITICAL FIX for button visibility
        ));

        
    } else {
        ob_end_clean();
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
    // 1. Security Check
    check_ajax_referer('save_search_nonce', 'security');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please login to save searches.']);
    }

    $user_id = get_current_user_id();
    $raw_data = isset($_POST['search_data']) ? $_POST['search_data'] : [];

    // 2. Sanitize Data
    // We strictly define allowed keys to prevent garbage data
    $allowed_keys = ['niche', 'platform', 'followers', 'country', 'lang', 'gender', 'score'];
    $clean_data = [];

    foreach ($allowed_keys as $key) {
        if (isset($raw_data[$key])) {
            if (is_array($raw_data[$key])) {
                // Sanitize array items (checkboxes)
                $clean_data[$key] = array_map('sanitize_text_field', $raw_data[$key]);
            } else {
                // Sanitize string (range slider)
                $clean_data[$key] = sanitize_text_field($raw_data[$key]);
            }
        }
    }

    // 3. Build Query String
    // http_build_query creates the string: niche%5B0%5D=artist&niche%5B1%5D=beauty...
    $query_string = http_build_query($clean_data);

    // 4. Format Adjustment (Optional but requested)
    // PHP adds indices [0], [1] by default. Your request used [] (%5B%5D).
    // This regex replaces %5B0%5D, %5B1%5D, etc. with just %5B%5D
    $query_string = preg_replace('/%5B\d+%5D/', '%5B%5D', $query_string);

    // 5. Prepend the Question Mark
    $final_string = '?' . $query_string;

    // 6. Create Post
    $post_args = [
        'post_title'  => 'Search saved on ' . current_time('M j, Y @ g:i a'),
        'post_type'   => 'saved-search',
        'post_status' => 'publish',
        'post_author' => $user_id,
    ];

    $post_id = wp_insert_post($post_args);

    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'Error creating save file.']);
    }

    // 7. Save the Single String
    update_post_meta($post_id, 'search_query', $final_string);

    wp_send_json_success(['message' => 'Search saved successfully!']);
}



// 2. Handle the AJAX Request
function handle_save_influencer_ajax()
{
    // Security check
    check_ajax_referer('save_influencer_nonce', 'security');

    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'You must be logged in to save.'));
    }

    // Get the data
    $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'save';

    if (empty($influencer_id)) {
        wp_send_json_error(array('message' => 'No Influencer ID provided.'));
    }

    if ($type == 'save') {
        $current_user_id = get_current_user_id();

        // Format: Jan 4, 2026 @ 8:57 pm
        // Note: current_time gets the time based on your WP timezone settings
        $post_title = 'Influencer saved on ' . current_time('M j, Y @ g:i a');

        // Prepare Post Data
        $new_post = array(
            'post_title'    => $post_title,
            'post_type'     => 'saved-influencer', // Ensure this Post Type is registered
            'post_status'   => 'publish',
            'post_author'   => $current_user_id,
        );

        // Insert the Post
        $post_id = wp_insert_post($new_post);

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Could not create post.'));
        } else {
            // Update Meta Data
            update_post_meta($post_id, 'influencer_id', $influencer_id);

            wp_send_json_success(array('message' => 'Saved successfully!', 'id' => $post_id));
        }
    } else {
        $saved_id = is_influencer_saved($influencer_id);
        if ($saved_id) {
            wp_delete_post($saved_id, true);
            wp_send_json_success(array('message' => 'Unsaved successfully!', 'id' => $saved_id));
        }
    }
}

// Register the AJAX action for logged-in users
add_action('wp_ajax_save_influencer', 'handle_save_influencer_ajax');
