<?php
if (!defined('ABSPATH')) {
    exit;
}

class Influencer_Search {

    public function __construct() {
        // Register AJAX actions for the custom loop filter
        add_action('wp_ajax_my_custom_loop_filter', [$this, 'my_custom_loop_filter_handler']);
        add_action('wp_ajax_nopriv_my_custom_loop_filter', [$this, 'my_custom_loop_filter_handler']);
        
        // Register AJAX actions for the niche options dropdown
        add_action('wp_ajax_dd_search_niche_options', [$this, 'dd_search_niche_options_handler']);
        add_action('wp_ajax_nopriv_dd_search_niche_options', [$this, 'dd_search_niche_options_handler']);
    }

    /**
     * AJAX: Return niche options for typeahead filter dropdown.
     * Behaviour:
     * - No results before 3 chars
     * - Partial, case-insensitive matching
     * - Max 20 results
     */
    public function dd_search_niche_options_handler() {
        $query = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $query = trim($query);
        $min_chars = 3;
        $limit = 20;

        if (strlen($query) < $min_chars) {
            wp_send_json_success(['items' => []]);
        }

        $selected = isset($_POST['selected']) ? (array) $_POST['selected'] : [];
        $selected = array_map('sanitize_title', $selected);
        $selected_map = array_fill_keys($selected, true);

        $terms = get_terms([
            'taxonomy'   => 'niche',
            'hide_empty' => false,
            'name__like' => $query,
            'number'     => $limit,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'fields'     => 'all',
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            wp_send_json_success(['items' => []]);
        }

        $items = [];
        foreach ($terms as $term) {
            if (!is_object($term) || empty($term->slug)) continue;
            $slug = (string) $term->slug;
            $items[] = [
                'value' => $slug,
                'label' => (string) $term->name,
                'selected' => !empty($selected_map[$slug]),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    /**
     * AJAX handler for filtering the custom loop of influencers.
     */
    public function my_custom_loop_filter_handler() {
        // 1. GATHER INPUTS (explicit form values)
        $explicit = [
            'niche'         => isset($_POST['niche']) ? $_POST['niche'] : [],
            'country'       => isset($_POST['country']) ? $_POST['country'] : [],
            'lang'          => isset($_POST['lang']) ? $_POST['lang'] : [],
            
            // Dedicated min/max follower inputs (scalar)
            'min_followers' => isset($_POST['min_followers']) ? sanitize_text_field($_POST['min_followers']) : '',
            'max_followers' => isset($_POST['max_followers']) ? sanitize_text_field($_POST['max_followers']) : '',
            
            'filter'        => isset($_POST['filter']) ? $_POST['filter'] : [],
            'topic'         => isset($_POST['topic']) ? $_POST['topic'] : [],
            'content_tag'   => isset($_POST['content_tag']) ? $_POST['content_tag'] : [],
        ];

        // Ensure arrays for array-expected inputs (bypassing min_followers/max_followers)
        foreach (['niche', 'country', 'lang', 'filter', 'topic', 'content_tag'] as $k) {
            if (!isset($explicit[$k]) || !is_array($explicit[$k])) {
                $explicit[$k] = !empty($explicit[$k]) ? [$explicit[$k]] : [];
            }
        }

        // 2. PARSE BRIEF (if provided) and merge with explicit
        $brief_text = isset($_POST['search_brief']) ? sanitize_textarea_field($_POST['search_brief']) : '';
        if (!empty($brief_text) && function_exists('parse_search_brief') && function_exists('merge_brief_with_explicit_filters')) {
            $parsed   = parse_search_brief($brief_text);
            $explicit = merge_brief_with_explicit_filters($parsed, $explicit);
        }

        $niche         = $explicit['niche'];
        $country       = $explicit['country'];
        $lang          = $explicit['lang'];
        $min_followers = $explicit['min_followers'];
        $max_followers = $explicit['max_followers'];
        $filter        = $explicit['filter'];
        $topic         = $explicit['topic'];
        $content_tag   = $explicit['content_tag'];

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
        $tax_query = [];
        $content_taxonomies = []; 

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

        if (!empty($tax_query)) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        // --- Meta Query Setup ---
        $meta_query = [];
        $strict_meta_query = []; // NEW: These filters will NEVER drop during fallback broadening

        $country_meta_clause = [];
        if (!empty($country)) {
            $country_arr = is_array($country) ? $country : [$country];
            $country_arr = array_map('trim', $country_arr);
            $country_arr = array_filter($country_arr, function ($v) { return $v !== ''; });
            $country_arr = array_merge($country_arr, array_map('strtolower', $country_arr), array_map('strtoupper', $country_arr));
            $country_arr = array_values(array_unique($country_arr));
            $country_meta_clause = [
                'key'     => 'country',
                'value'   => $country_arr,
                'compare' => 'IN',
            ];
            // Country must be non-droppable across broadening/fallback.
            $strict_meta_query[] = $country_meta_clause;
        }

        if (!empty($lang)) {
            $lang_arr = is_array($lang) ? $lang : [$lang];
            $meta_query[] = [
                'key'     => 'lang',
                'value'   => $lang_arr,
                'compare' => count($lang_arr) > 1 ? 'IN' : '=',
            ];
        }

        // --- Followers Logic: Strict Min/Max Handling ---
        if ($min_followers !== '' && $max_followers !== '') {
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => [(int)$min_followers, (int)$max_followers],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];
        } elseif ($min_followers !== '') {
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => (int)$min_followers,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        } elseif ($max_followers !== '') {
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => (int)$max_followers,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            ];
        }

        // Strict Filter: Include only verified influencers
        if (!empty($filter) && in_array('Include only verified influencers', $filter, true)) {
            $strict_meta_query[] = [
                'key'     => 'isverified',
                'value'   => '1',
                'compare' => '=',
            ];
        }
        // Strict Filter: Professional experts only
        if (!empty($filter) && in_array('Professional experts only', $filter, true)) {
            $strict_meta_query[] = [
                'key'     => 'is_expert',
                'value'   => ['1', 'yes'], 
                'compare' => 'IN',
            ];
        }

        // Combine standard and strict meta queries for the initial run
        $combined_meta_query = array_merge($meta_query, $strict_meta_query);
        if (!empty($combined_meta_query)) {
            $combined_meta_query['relation'] = 'AND';
            $args['meta_query'] = $combined_meta_query;
        }

        // 4. EXECUTE QUERY
        $query = new WP_Query($args);

        // --- BROADENING / FALLBACK LOGIC ---
        $min_results = 6;
        $has_droppable_filters = !empty($lang) || $min_followers !== '' || $max_followers !== '';

        if ($query->found_posts < $min_results && $has_droppable_filters && ($query->have_posts() || !empty($niche) || !empty($platform))) {
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
           
            if (count($broadened_tax) > 1) {
                $broadened_tax['relation'] = 'AND';
            }
            if (!empty($broadened_tax)) {
                $broadened_args['tax_query'] = $broadened_tax;
            }

            if (!empty($strict_meta_query)) {
                $strict_meta_query['relation'] = 'AND';
                $broadened_args['meta_query'] = $strict_meta_query;
            }

            $query = new WP_Query($broadened_args);
        }

        // Fallback: if 0 results with full filters, retry with taxonomy + strict filters.
        if (!$query->have_posts() && (!empty($niche) || !empty($country) || $min_followers !== '' || $max_followers !== '')) {
            $fallback_args = [
                'post_type'      => 'influencer',
                'posts_per_page' => 12,
                'post_status'    => 'publish',
                'paged'          => $paged, 
            ];

            $fallback_tax = [];
            if (!empty($niche)) {
                $fallback_tax[] = ['taxonomy' => 'niche', 'field' => 'slug', 'terms' => $niche];
            }
            
            if (count($fallback_tax) > 1) {
                $fallback_tax['relation'] = 'AND';
            }
            if (!empty($fallback_tax)) {
                $fallback_args['tax_query'] = $fallback_tax;
            }

            if (!empty($strict_meta_query)) {
                $strict_meta_query['relation'] = 'AND';
                $fallback_args['meta_query'] = $strict_meta_query;
            }

            $query = new WP_Query($fallback_args);

            if (!$query->have_posts()) {
                $last_resort_args = [
                    'post_type'      => 'influencer',
                    'posts_per_page' => 12,
                    'post_status'    => 'publish',
                    'paged'          => $paged
                ];
                
                if (!empty($strict_meta_query)) {
                    $strict_meta_query['relation'] = 'AND';
                    $last_resort_args['meta_query'] = $strict_meta_query;
                }

                $query = new WP_Query($last_resort_args);
            }
        }
        
        error_log('--- INFLUENCER AJAX ARGS ---');
        error_log(print_r($query->query_vars, true));
        
        if ($query->have_posts()) {
            $search_criteria = [
                'niche'         => $niche,
                'country'       => $country,
                'min_followers' => $min_followers, 
                'max_followers' => $max_followers, 
                'filter'        => $filter,
                'topic'         => $topic,
                'content_tag'   => $content_tag,
            ];
            set_query_var('search_criteria', $search_criteria);

            $posts = $query->posts;

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
                set_query_var('current_influencer_id', $post->ID);
                if (class_exists('\Elementor\Plugin')) {
                    echo do_shortcode('[elementor-template id="1839"]');
                }
            }
            wp_reset_postdata();

            $html_output = ob_get_clean();

            $number_of_searches = 0; 
            if (is_user_logged_in()) {
                $current_user_id = get_current_user_id();
                $current_count   = get_user_meta($current_user_id, 'number_of_searches', true);
                if (empty($current_count)) {
                    $current_count = 0;
                }
                update_user_meta($current_user_id, 'number_of_searches', $current_count + 1);
                $number_of_searches = get_user_meta($current_user_id, 'number_of_searches', true);
            }

            wp_send_json_success(array(
                'html'               => $html_output,
                'found_posts'        => $query->found_posts,
                'max_pages'          => $query->max_num_pages, 
                'number_of_searches' => $number_of_searches,
            ));
        } else {
            ob_end_clean();
            wp_send_json_error('No posts found');
        }

        wp_die();
    }
}

// Initialize the class
new Influencer_Search();