<?php
add_action('wp_ajax_my_custom_loop_filter', 'my_custom_loop_filter_handler');
add_action('wp_ajax_nopriv_my_custom_loop_filter', 'my_custom_loop_filter_handler');

function my_custom_loop_filter_handler()
{
    // 1. GATHER INPUTS (explicit form values)
    $explicit = [
        'niche'        => isset($_POST['niche']) ? $_POST['niche'] : [],
        'platform'     => isset($_POST['platform']) ? $_POST['platform'] : [],
        'country'      => isset($_POST['country']) ? $_POST['country'] : [],
        'lang'         => isset($_POST['lang']) ? $_POST['lang'] : [],
        'followers'    => isset($_POST['followers']) ? $_POST['followers'] : [],
        'filter'       => isset($_POST['filter']) ? $_POST['filter'] : [],
        'topic'        => isset($_POST['topic']) ? $_POST['topic'] : [],
        'content_tag'  => isset($_POST['content_tag']) ? $_POST['content_tag'] : [],
    ];

    // Ensure arrays
    foreach ($explicit as $k => $v) {
        if (!is_array($v)) {
            $explicit[$k] = $v ? [$v] : [];
        }
    }

    // 2. PARSE BRIEF (if provided) and merge with explicit
    $brief_text = isset($_POST['search_brief']) ? sanitize_textarea_field($_POST['search_brief']) : '';
    if (!empty($brief_text) && function_exists('parse_search_brief') && function_exists('merge_brief_with_explicit_filters')) {
        $parsed   = parse_search_brief($brief_text);
        $explicit = merge_brief_with_explicit_filters($parsed, $explicit);
    }

    $niche        = $explicit['niche'];
    $platform     = $explicit['platform'];
    $country      = $explicit['country'];
    $lang         = $explicit['lang'];
    $followers    = $explicit['followers'];
    $filter       = $explicit['filter'];
    $topic        = $explicit['topic'];
    $content_tag  = $explicit['content_tag'];

    // 3. BUILD THE QUERY ARGS
    $args = [
        'post_type'      => 'influencer',
        'posts_per_page' => 12,
        'post_status'    => 'publish',
    ];

    // --- Taxonomy Query ---
    // Niche, topic, content_tag use OR (broaden: match any of these).
    // Platform uses AND (must match).
    $tax_query = [];

    $content_taxonomies = []; // niche, topic, content_tag — OR together
    if (!empty($niche)) {
        $content_taxonomies[] = [
            'taxonomy' => 'niche',
            'field'    => 'slug',
            'terms'    => $niche,
        ];
    }
    if (!empty($topic)) {
        $content_taxonomies[] = [
            'taxonomy' => 'topic',
            'field'    => 'slug',
            'terms'    => $topic,
        ];
    }
    if (!empty($content_tag)) {
        $content_taxonomies[] = [
            'taxonomy' => 'content_tag',
            'field'    => 'slug',
            'terms'    => $content_tag,
        ];
    }

    if (count($content_taxonomies) > 1) {
        $content_taxonomies['relation'] = 'OR';
        $tax_query[] = $content_taxonomies;
    } elseif (count($content_taxonomies) === 1) {
        $tax_query[] = $content_taxonomies[0];
    }

    if (!empty($platform)) {
        $tax_query[] = [
            'taxonomy' => 'platform',
            'field'    => 'slug',
            'terms'    => $platform,
        ];
    }

    if (!empty($tax_query)) {
        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }
        $args['tax_query'] = $tax_query;
    }

    // --- Meta Query (Country, Lang, Followers) ---
    $meta_query = [];

    if (!empty($country)) {
        $country_arr = is_array($country) ? $country : [$country];
        // Match both uppercase and lowercase (DB may store gbr or GBR)
        $country_arr = array_merge($country_arr, array_map('strtolower', $country_arr), array_map('strtoupper', $country_arr));
        $country_arr = array_unique($country_arr);
        $meta_query[] = [
            'key'     => 'country',
            'value'   => $country_arr,
            'compare' => 'IN',
        ];
    }

    if (!empty($lang)) {
        $lang_arr = is_array($lang) ? $lang : [$lang];
        $meta_query[] = [
            'key'     => 'lang',
            'value'   => $lang_arr,
            'compare' => count($lang_arr) > 1 ? 'IN' : '=',
        ];
    }

    // Filter: Include only verified influencers
    if (!empty($filter) && in_array('Include only verified influencers', $filter, true)) {
        $meta_query[] = [
            'key'     => 'isverified',
            'value'   => '1',
            'compare' => '=',
        ];
    }

    // Followers Logic
    if (!empty($followers)) {
        // Check if it contains a hyphen indicating a range (e.g., "1000-10000")
        if (strpos($followers[0], '-') !== false) {
            $range = explode('-', $followers[0]);
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => $range, // array(min, max)
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];
        } else {
            // No hyphen, assumed to be the top tier (e.g., "10000000")
            // Requirement: search for value GREATER THAN selected
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => $followers[0],
                'compare' => '>',
                'type'    => 'NUMERIC',
            ];
        }
    }

    if (!empty($meta_query)) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    // 3. EXECUTE QUERY
    $query = new WP_Query($args);

    // Broadening: if fewer than 6 results with full filters, retry with niche + platform only (drop country, followers, lang, verified)
    $min_results = 6;
    $has_broadening_filters = !empty($country) || !empty($followers) || !empty($lang)
        || (!empty($filter) && in_array('Include only verified influencers', $filter, true));
    if ($query->found_posts < $min_results && $has_broadening_filters && ($query->have_posts() || !empty($niche) || !empty($platform))) {
        $broadened_args = [
            'post_type'      => 'influencer',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
        ];
        $content_tax = [];
        if (!empty($niche)) {
            $content_tax[] = ['taxonomy' => 'niche', 'field' => 'slug', 'terms' => $niche];
        }
        if (!empty($topic)) {
            $content_tax[] = ['taxonomy' => 'topic', 'field' => 'slug', 'terms' => $topic];
        }
        if (!empty($content_tag)) {
            $content_tax[] = ['taxonomy' => 'content_tag', 'field' => 'slug', 'terms' => $content_tag];
        }
        $broadened_tax = [];
        if (count($content_tax) > 1) {
            $content_tax['relation'] = 'OR';
            $broadened_tax[] = $content_tax;
        } elseif (count($content_tax) === 1) {
            $broadened_tax[] = $content_tax[0];
        }
        if (!empty($platform)) {
            $broadened_tax[] = ['taxonomy' => 'platform', 'field' => 'slug', 'terms' => $platform];
        }
        if (count($broadened_tax) > 1) {
            $broadened_tax['relation'] = 'AND';
        }
        if (!empty($broadened_tax)) {
            $broadened_args['tax_query'] = $broadened_tax;
        }
        $query = new WP_Query($broadened_args);
    }

    // Fallback: if 0 results with full filters, retry with just niche + platform (drop country, followers)
    if (!$query->have_posts() && (!empty($niche) || !empty($platform) || !empty($country) || !empty($followers))) {
        $fallback_args = [
            'post_type'      => 'influencer',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
        ];
        $fallback_tax = [];
        if (!empty($niche)) {
            $fallback_tax[] = ['taxonomy' => 'niche', 'field' => 'slug', 'terms' => $niche];
        }
        if (!empty($platform)) {
            $fallback_tax[] = ['taxonomy' => 'platform', 'field' => 'slug', 'terms' => $platform];
        }
        if (count($fallback_tax) > 1) {
            $fallback_tax['relation'] = 'AND';
        }
        if (!empty($fallback_tax)) {
            $fallback_args['tax_query'] = $fallback_tax;
        }
        $query = new WP_Query($fallback_args);
        // Last resort: if still 0, return all published (no filters)
        if (!$query->have_posts()) {
            $query = new WP_Query([
                'post_type'      => 'influencer',
                'posts_per_page' => 12,
                'post_status'    => 'publish',
            ]);
        }
    }

    if ($query->have_posts()) {
        $search_criteria = [
            'niche'       => $niche,
            'platform'    => $platform,
            'country'     => $country,
            'followers'   => $followers,
            'filter'      => $filter,
            'topic'       => $topic,
            'content_tag' => $content_tag,
        ];
        set_query_var('search_criteria', $search_criteria);

        $posts = $query->posts;
        if (function_exists('influencer_calculate_match_score')) {
            usort($posts, function ($a, $b) use ($search_criteria) {
                $sa = influencer_calculate_match_score($a->ID, $search_criteria);
                $sb = influencer_calculate_match_score($b->ID, $search_criteria);
                return $sb <=> $sa; // descending (highest first)
            });
        }

        ob_start();
        foreach ($posts as $post) {
            $GLOBALS['post'] = $post;
            setup_postdata($post);
            set_query_var('current_influencer_id', $post->ID);
            if (class_exists('\Elementor\Plugin')) {
                echo do_shortcode('[elementor-template id="1839"]');
            }
        }
        wp_reset_postdata();

        // 1. Capture the HTML into a variable
        $html_output = ob_get_clean();

        // 2. Send an array containing both the HTML and the count
        wp_send_json_success(array(
            'html'        => $html_output,
            'found_posts' => $query->found_posts
        ));
    } else {
        // It's often good practice to clean the buffer even on error to prevent stray output
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
