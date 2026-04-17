<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

add_action('wp_ajax_my_custom_loop_filter', 'my_custom_loop_filter_handler');
add_action('wp_ajax_nopriv_my_custom_loop_filter', 'my_custom_loop_filter_handler');

/**
 * AJAX handler for filtering the custom loop of influencers.
 * * Captures user inputs, parses search briefs, builds taxonomy and meta queries, 
 * handles fallback broadening logic for narrow searches, applies pagination, 
 * tracks search limits via user meta, and returns a JSON payload with rendered HTML.
 */
function my_custom_loop_filter_handler()
{
    // 1. GATHER INPUTS (explicit form values)
    $explicit = [
        'niche'        => isset($_POST['niche']) ? $_POST['niche'] : [],
        //'platform'     => isset($_POST['platform']) ? $_POST['platform'] : [],
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
    // $platform     = $explicit['platform'];
    $country      = $explicit['country'];
    $lang         = $explicit['lang'];
    $followers    = $explicit['followers'];
    $filter       = $explicit['filter'];
    $topic        = $explicit['topic'];
    $content_tag  = $explicit['content_tag'];

    // --- FIX 1: Capture the current page number ---
    $paged = (isset($_POST['paged']) && $_POST['paged']) ? intval($_POST['paged']) : 1;

    // 3. BUILD THE QUERY ARGS
    $args = [
        'post_type'      => 'influencer',
        'posts_per_page' => 12,
        'post_status'    => 'publish',
        'paged'          => $paged, // <--- FIX 2: Pass the page number to the query
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
    /*
    if (!empty($platform)) {
        $tax_query[] = [
            'taxonomy' => 'platform',
            'field'    => 'slug',
            'terms'    => $platform,
        ];
    }*/

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

    // Filter: Professional experts only
    if (!empty($filter) && in_array('Professional experts only', $filter, true)) {
        $meta_query[] = [
            'key'     => 'is_expert',
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

    // 4. EXECUTE QUERY
    $query = new WP_Query($args);

    // --- BROADENING / FALLBACK LOGIC ---
    // Broadening: if fewer than 6 results with full filters, retry with niche + platform only (drop country, followers, lang, verified)
    $min_results = 6;
    $has_broadening_filters = !empty($country) || !empty($followers) || !empty($lang)
        || (!empty($filter) && in_array('Include only verified influencers', $filter, true));

    if ($query->found_posts < $min_results && $has_broadening_filters && ($query->have_posts() || !empty($niche) || !empty($platform))) {
        $broadened_args = [
            'post_type'      => 'influencer',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'paged'          => $paged, // <--- FIX 3: Add pagination to fallback
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
            'paged'          => $paged, // <--- FIX 3: Add pagination to fallback
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
                'paged'          => $paged // <--- FIX 3: Add pagination to last resort
            ]);
        }
    }

    if ($query->have_posts()) {
        $search_criteria = [
            'niche'       => $niche,
            //'platform'    => $platform,
            'country'     => $country,
            'followers'   => $followers,
            'filter'      => $filter,
            'topic'       => $topic,
            'content_tag' => $content_tag,
        ];
        set_query_var('search_criteria', $search_criteria);

        $posts = $query->posts;

        // Note: usort here sorts ONLY the 12 posts on the current page, not the whole DB.
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

        $html_output = ob_get_clean();

        // --- UPDATE: Increment User Meta on Finish ---
        $number_of_searches = 0; // Safe fallback for non-logged-in users

        if (is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            $current_count   = get_user_meta($current_user_id, 'number_of_searches', true);
            if (empty($current_count)) {
                $current_count = 0;
            }
            update_user_meta($current_user_id, 'number_of_searches', $current_count + 1);
            $number_of_searches = get_user_meta($current_user_id, 'number_of_searches', true);
        }

        // --- FIX 4: Send 'max_pages' & search limits in the response ---
        wp_send_json_success(array(
            'html'               => $html_output,
            'found_posts'        => $query->found_posts,
            'max_pages'          => $query->max_num_pages, // <--- CRITICAL FIX for button visibility
            'number_of_searches' => $number_of_searches,
        ));
    } else {
        // Clean the buffer even on error to prevent stray output
        ob_end_clean();
        wp_send_json_error('No posts found');
    }

    wp_die();
}