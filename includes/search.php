<?php
if (!defined('ABSPATH')) {
    exit;
}

class Influencer_Search
{

    public function __construct()
    {
        // Register AJAX actions for the custom loop filter
        add_action('wp_ajax_my_custom_loop_filter', [$this, 'my_custom_loop_filter_handler']);
        add_action('wp_ajax_nopriv_my_custom_loop_filter', [$this, 'my_custom_loop_filter_handler']);

        // Register AJAX actions for the niche options dropdown
        add_action('wp_ajax_dd_search_niche_options', [$this, 'dd_search_niche_options_handler']);
        add_action('wp_ajax_nopriv_dd_search_niche_options', [$this, 'dd_search_niche_options_handler']);

        // Set up search variables on the 'wp' hook
        add_action('wp', [$this, 'setup_search_variables']);
    }

    /**
     * Variable setup for search & outreach fields
     */
    public function setup_search_variables()
    {
        // Parse brief and merge into $_GET when on search results page with search-brief
        $influencer_search_page_id = 1949;
        if ((is_page($influencer_search_page_id) || (int) get_queried_object_id() === $influencer_search_page_id)
            && !empty($_GET['search-brief'])
            && function_exists('parse_search_brief')
            && function_exists('merge_brief_with_explicit_filters')
        ) {
            $explicit = [
                'niche'     => isset($_GET['niche']) ? (array) $_GET['niche'] : [],
                'country'   => isset($_GET['country']) ? (array) $_GET['country'] : [],
                'followers' => isset($_GET['followers']) ? (array) $_GET['followers'] : [],
                'filter'    => isset($_GET['filter']) ? (array) $_GET['filter'] : [],
            ];
            $parsed = parse_search_brief(sanitize_textarea_field($_GET['search-brief']));
            $merged = merge_brief_with_explicit_filters($parsed, $explicit);
            $_GET['niche']     = $merged['niche'];
            $_GET['country']   = $merged['country'];
            $_GET['followers'] = $merged['followers'];
            $_GET['filter']    = $merged['filter'];
        }

        $niche = get_terms(array(
            'taxonomy'   => 'niche',
            'hide_empty' => false,
        ));

        $niche_options = [];
        foreach ($niche as $term) {
            $niche_options[$term->slug] = $term->name;
        }

        $followers_options = array(
            '1000-10000' => '1K',
            '10000' => '10K',
            '50000' => '50K',
            '250000' => '250K',
            '1000000' => '1M',
            '10000000' => '10M',
        );

        // Notice we use self:: to call the static methods within the same class
        $country_options = self::get_unique_influencer_countries();
        $lang_options = self::get_unique_influencer_languages();

        $gender_options = array(
            'Male' => 'Male',
            'Female' => 'Female',
            'Non-Binary' => 'Non-Binary',
            'Prefer not to say' => 'Prefer not to say',
        );
        $age_options = array(
            '13-17' => '13-17',
            '18-24' => '18-24',
            '25-34' => '25-34',
            '35-44' => '35-44',
            '45-54' => '45-54',
            '55-64' => '55-64',
            '65+' => '65+',
        );

        $filter_options = array(
            'Include only verified influencers' => 'Include only verified influencers',
            'Prioritise engagement over reach' => 'Prioritise engagement over reach',
            'Professional experts only' => 'Professional experts only',
        );

        $influencer_search_fields['niche'] = $niche_options;
        $influencer_search_fields['followers'] = $followers_options;
        $influencer_search_fields['country'] = $country_options;
        $influencer_search_fields['lang'] = $lang_options;
        $influencer_search_fields['gender'] = $gender_options;
        $influencer_search_fields['age'] = $age_options;
        $influencer_search_fields['filter'] = $filter_options;

        // Assumes this generic helper stays in custom-functions.php
        $project_type_options = get_unique_meta_values_by_post_type('project_type');
        $project_length_options = get_unique_meta_values_by_post_type('project_length');

        $influencer_outreach_fields['project_type'] = $project_type_options;
        $influencer_outreach_fields['project_length'] = $project_length_options;

        set_query_var('influencer_search_fields', $influencer_search_fields);
        set_query_var('influencer_outreach_fields', $influencer_outreach_fields);
        set_query_var('influencer_search_page', $influencer_search_page_id);
    }

    /**
     * Get sorted array of unique countries from 'influencers' post type.
     */
    public static function get_unique_influencer_countries()
    {
        global $wpdb;
        $results = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = %s
            AND p.post_status = 'publish'
        ", 'influencer', 'country'));

        $country_list = array();
        foreach ($results as $original_val) {
            $alpha3 = strtolower(trim($original_val ?? ''));
            if (function_exists('iso_alpha3_to_alpha2')) {
                $alpha2 = iso_alpha3_to_alpha2($alpha3);
            } else {
                continue;
            }
            if ($alpha2) {
                if (class_exists('Locale')) {
                    $country_name = Locale::getDisplayRegion('-' . $alpha2, 'en');
                } elseif (class_exists('WC_Countries')) {
                    $wc_countries = new WC_Countries();
                    $countries    = $wc_countries->get_countries();
                    $country_name = isset($countries[strtoupper($alpha2)]) ? $countries[strtoupper($alpha2)] : $alpha2;
                } else {
                    $country_name = strtoupper($alpha2);
                }
                $country_list[$original_val] = $country_name;
            }
        }
        asort($country_list);
        return $country_list;
    }

    /**
     * Get sorted array of unique languages from 'influencer' post type.
     */
    public static function get_unique_influencer_languages()
    {
        global $wpdb;
        $results = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = %s
            AND p.post_status = 'publish'
        ", 'influencer', 'lang'));

        $language_list = array();
        foreach ($results as $lang_code) {
            $clean_code = trim($lang_code ?? '');
            if (empty($clean_code)) continue;

            if (class_exists('Locale')) {
                $lang_name = Locale::getDisplayLanguage($clean_code, 'en');
            } else {
                $lang_name = strtoupper($clean_code);
            }

            if ($lang_name == $clean_code) {
                $lang_name = ucfirst($clean_code);
            }

            if (stripos($lang_name, 'unknown') !== false) continue;
            if (in_array($lang_name, $language_list)) continue;

            $language_list[$clean_code] = $lang_name;
        }
        asort($language_list);
        return $language_list;
    }

    /**
     * Render Select Filter HTML
     */
    public static function select_filter($name, $label, $placeholder, $options = [], $type = 'checkbox', $has_search = false)
    {
        $selected_values = [];
        if (isset($_GET[$name])) {
            $selected_values = is_array($_GET[$name]) ? $_GET[$name] : array($_GET[$name]);
        }
        $is_niche_async = ($name === 'niche' && $has_search && $type === 'checkbox');

        ob_start();
?>
        <div class="filter-widget select-filter">
            <div class="header">
                <?php if ($label != false) { ?>
                    <span><?= $label ?></span>
                <?php } ?>
                <div class="reset-btn" style="display: none;">Reset</div>
            </div>

            <div class="dropdown-container">
                <div class="dropdown-button">
                    <?= $placeholder ?>
                    <span class="arrow-holder"><span class="arrow"></span></span>
                </div>

                <div class="dropdown-menu checkbox-lists" data-filter-name="<?= esc_attr($name) ?>">
                    <?php if ($has_search): ?>
                        <div class="dropdown-search-container" style="padding: 10px;">
                            <input type="text" class="dropdown-search-input"
                                placeholder="<?= esc_attr($is_niche_async ? 'Search niches...' : 'Search options...') ?>"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                <?= $is_niche_async ? 'data-ajax-search="niche" data-min-chars="3" data-limit="20"' : '' ?>>
                        </div>
                    <?php endif; ?>

                    <div class="options-list">
                        <?php if ($is_niche_async): ?>
                            <?php foreach ($selected_values as $selected_key) {
                                $selected_key = (string) $selected_key;
                                if ($selected_key === '') continue;
                                $selected_label = isset($options[$selected_key]) ? $options[$selected_key] : ucfirst(str_replace('-', ' ', $selected_key));
                            ?>
                                <label class="dropdown-item checkbox-list-item">
                                    <input class="pseudo-checkbox-input" type="<?= esc_attr($type) ?>" value="<?= esc_attr($selected_key) ?>" data-label="<?= esc_attr($selected_label) ?>" name="<?= esc_attr($name) ?>[]" checked="checked"> <span class="pseudo-checkbox"></span> <?= esc_html($selected_label) ?>
                                </label>
                            <?php } ?>
                        <?php else: ?>
                            <?php foreach ($options as $key => $option) {
                                $is_checked = in_array((string)$key, $selected_values) ? 'checked="checked"' : '';
                            ?>
                                <label class="dropdown-item checkbox-list-item">
                                    <input class="pseudo-checkbox-input" type="<?= $type ?>" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]" <?= $is_checked ?>> <span class="pseudo-checkbox"></span> <?= $option ?>
                                </label>
                            <?php } ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="tags-container"></div>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Render Checkbox Filter HTML
     */
    public static function checkbox_filter($name, $label, $options = [])
    {
        $selected_values = [];
        if (isset($_GET[$name])) {
            $selected_values = is_array($_GET[$name]) ? $_GET[$name] : array($_GET[$name]);
        }
        ob_start();
    ?>
        <div class="filter-widget checkbox-filter">
            <?php if ($label != false) { ?>
                <div class="header">
                    <span><?= $label ?></span>
                </div>
            <?php } ?>
            <div class="dropdown-menu checkbox-lists">
                <?php foreach ($options as $key => $option) {
                    $is_checked = in_array((string)$key, $selected_values) ? 'checked="checked"' : '';
                ?>
                    <label class="dropdown-item checkbox-list-item">
                        <input class="pseudo-checkbox-input" type="checkbox" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]" <?= $is_checked ?>> <span class="pseudo-checkbox"></span> <?= $option ?>
                    </label>
                <?php } ?>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Render Radio Filter HTML
     */
    public static function radio_filter($name, $label, $options = [])
    {
        $selected_values = [];
        if (isset($_GET[$name])) {
            $selected_values = is_array($_GET[$name]) ? $_GET[$name] : array($_GET[$name]);
        }
        ob_start();
    ?>
        <div class="filter-widget checkbox-filter">
            <div class="header">
                <span><?= $label ?></span>
            </div>
            <div class="dropdown-menu checkbox-lists">
                <?php foreach ($options as $key => $option) {
                    $is_checked = in_array((string)$key, $selected_values) ? 'checked="checked"' : '';
                ?>
                    <label class="dropdown-item checkbox-list-item">
                        <input class="pseudo-checkbox-input" type="radio" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>" <?= $is_checked ?>> <span class="pseudo-checkbox"></span> <?= $option ?>
                    </label>
                <?php } ?>
            </div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * AJAX: Return niche options for typeahead filter dropdown.
     * Behaviour:
     * - No results before 3 chars
     * - Partial, case-insensitive matching
     * - Max 20 results
     */
    public function dd_search_niche_options_handler()
    {
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
    public function my_custom_loop_filter_handler()
    {
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
            $country_arr = array_filter($country_arr, function ($v) {
                return $v !== '';
            });
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
