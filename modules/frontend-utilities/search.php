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

        // Register AJAX actions for the content_tag (hashtags) options dropdown
        add_action('wp_ajax_dd_search_content_tag_options', [$this, 'dd_search_content_tag_options_handler']);
        add_action('wp_ajax_nopriv_dd_search_content_tag_options', [$this, 'dd_search_content_tag_options_handler']);

        // Set up search variables on the 'wp' hook
        add_action('wp', [$this, 'setup_search_variables']);


        // Register Elementor Widgets directly from this class
        add_action('elementor/widgets/register', [$this, 'register_elementor_widgets']);
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
        ) {
            $explicit = [
                'niche'       => isset($_GET['niche']) ? (array) $_GET['niche'] : [],
                'country'     => isset($_GET['country']) ? (array) $_GET['country'] : [],
                'followers'   => isset($_GET['followers']) ? (array) $_GET['followers'] : [],
                'filter'      => isset($_GET['filter']) ? (array) $_GET['filter'] : [],
                'gender'      => isset($_GET['gender']) ? (array) $_GET['gender'] : [],
                'content_tag' => isset($_GET['content_tag']) ? (array) $_GET['content_tag'] : [],
            ];
            $parsed = self::parse_search_brief(sanitize_textarea_field($_GET['search-brief']));
            $merged = self::merge_brief_with_explicit_filters($parsed, $explicit);
            $_GET['niche']       = $merged['niche'] ?? $explicit['niche'];
            $_GET['country']     = $merged['country'] ?? $explicit['country'];
            $_GET['followers']   = $merged['followers'] ?? $explicit['followers'];
            $_GET['filter']      = $merged['filter'] ?? $explicit['filter'];
            $_GET['gender']      = $merged['gender'] ?? $explicit['gender'];
            $_GET['content_tag'] = $merged['content_tag'] ?? $explicit['content_tag'];
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

        $gender_options = self::get_unique_influencer_genders();

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
        $influencer_search_fields['content_tag'] = [];

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
     * Get sorted array of unique genders from 'influencer' post type meta.
     */
    public static function get_unique_influencer_genders()
    {
        global $wpdb;
        $results = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = %s
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
        ", 'influencer', 'gender'));

        $gender_list = array();
        foreach ($results as $val) {
            $clean = trim(ucfirst(strtolower($val)));
            if (!empty($clean)) {
                $gender_list[$clean] = $clean;
            }
        }
        asort($gender_list);
        return $gender_list;
    }

    /**
     * AJAX: Return hashtag (content_tag) options for typeahead filter dropdown.
     */
    public function dd_search_content_tag_options_handler()
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
            'taxonomy'   => 'content_tag',
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
     * Render Select Filter HTML
     */
    public static function select_filter($name, $label, $placeholder, $options = [], $type = 'checkbox', $has_search = false)
    {
        $selected_values = [];
        if (isset($_GET[$name])) {
            $selected_values = is_array($_GET[$name]) ? $_GET[$name] : array($_GET[$name]);
        }

        // Updated to support both niche and content_tag (hashtags) async searches
        $is_async = (($name === 'niche' || $name === 'content_tag') && $has_search && $type === 'checkbox');

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
                                placeholder="<?= esc_attr($is_async ? 'Search ' . ($name === 'content_tag' ? 'hashtags' : $name) . '...' : 'Search options...') ?>"
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                <?= $is_async ? 'data-ajax-search="' . esc_attr($name) . '" data-min-chars="3" data-limit="20"' : '' ?>>
                        </div>
                    <?php endif; ?>

                    <div class="options-list">
                        <?php if ($is_async): ?>
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


    // ========================================================================
    // 4. AJAX LOOP FILTER
    // ========================================================================

    public function my_custom_loop_filter_handler()
    {
        // 1. GATHER INPUTS (explicit form values)
        $explicit = [
            'niche'         => isset($_POST['niche']) ? $_POST['niche'] : [],
            'country'       => isset($_POST['country']) ? $_POST['country'] : [],
            'lang'          => isset($_POST['lang']) ? $_POST['lang'] : [],
            'gender'        => isset($_POST['gender']) ? $_POST['gender'] : [],

            // Dedicated min/max follower inputs (scalar)
            'min_followers' => isset($_POST['min_followers']) ? sanitize_text_field($_POST['min_followers']) : '',
            'max_followers' => isset($_POST['max_followers']) ? sanitize_text_field($_POST['max_followers']) : '',

            'filter'        => isset($_POST['filter']) ? $_POST['filter'] : [],
            'topic'         => isset($_POST['topic']) ? $_POST['topic'] : [],
            'content_tag'   => isset($_POST['content_tag']) ? $_POST['content_tag'] : [],
        ];

        // Ensure arrays for array-expected inputs (bypassing min_followers/max_followers)
        foreach (['niche', 'country', 'lang', 'filter', 'topic', 'content_tag', 'gender'] as $k) {
            if (!isset($explicit[$k]) || !is_array($explicit[$k])) {
                $explicit[$k] = !empty($explicit[$k]) ? [$explicit[$k]] : [];
            }
        }

        // 2. PARSE BRIEF (if provided) and merge with explicit
        $brief_text = isset($_POST['search_brief']) ? sanitize_textarea_field($_POST['search_brief']) : '';
        $parsed_brief = null;
        if (!empty($brief_text)) {
            $parsed_brief = self::parse_search_brief($brief_text);
            $explicit = self::merge_brief_with_explicit_filters($parsed_brief, $explicit);
        }

        $niche         = $explicit['niche'];
        $country       = $explicit['country'];
        $lang          = $explicit['lang'];
        $gender        = $explicit['gender'];
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
        $strict_meta_query = []; // Verified, expert, and country (when set) — always applied with the main query

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

        if (!empty($gender)) {
            $gender_arr = is_array($gender) ? $gender : [$gender];
            $meta_query[] = [
                'key'     => 'gender',
                'value'   => $gender_arr,
                'compare' => count($gender_arr) > 1 ? 'IN' : '=',
            ];
        }

        // --- Followers Logic: Strict Min/Max Handling (ignore bogus 0/0 from empty POST) ---
        $followers_ranges = isset($explicit['followers']) && is_array($explicit['followers'])
            ? $explicit['followers']
            : [];
        $follower_bounds = function_exists('creatordb_brief_resolve_follower_meta_bounds')
            ? creatordb_brief_resolve_follower_meta_bounds($min_followers, $max_followers, $followers_ranges)
            : ['min' => null, 'max' => null];

        $f_min = $follower_bounds['min'];
        $f_max = $follower_bounds['max'];

        if ($f_min !== null && $f_max !== null && $f_max >= $f_min) {
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => [$f_min, $f_max],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];
        } elseif ($f_min !== null) {
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => $f_min,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        } elseif ($f_max !== null) {
            $meta_query[] = [
                'key'     => 'followers',
                'value'   => $f_max,
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

        // 4. EXECUTE QUERY (no broadening / fallback — return only posts matching the requested filters)
        $search_criteria = function_exists('creatordb_brief_search_criteria_from_merged')
            ? creatordb_brief_search_criteria_from_merged($explicit)
            : [
                'niche'         => $niche,
                'country'       => $country,
                'platform'      => isset($explicit['platform']) ? (array) $explicit['platform'] : [],
                'followers'     => isset($explicit['followers']) ? (array) $explicit['followers'] : [],
                'filter'        => $filter,
                'topic'         => $topic,
                'content_tag'   => $content_tag,
                '_structured'   => isset($explicit['_structured']) ? $explicit['_structured'] : null,
            ];

        $score_cap = (int) apply_filters('creatordb_brief_search_score_cap', 500);
        $score_cap = max(12, min(1000, $score_cap));

        $ids_args = $args;
        $ids_args['posts_per_page'] = $score_cap;
        $ids_args['paged'] = 1;
        $ids_args['fields'] = 'ids';
        $ids_args['orderby'] = 'date';
        $ids_args['order'] = 'DESC';

        $ids_query = new WP_Query($ids_args);
        $all_ids = array_map('intval', (array) $ids_query->posts);
        $found_total = (int) $ids_query->found_posts;

        $scored = function_exists('creatordb_brief_sort_post_ids_by_score')
            ? creatordb_brief_sort_post_ids_by_score($all_ids, $search_criteria)
            : array_map(function ($id) {
                return ['id' => $id, 'score' => 50];
            }, $all_ids);

        $per_page = (int) $args['posts_per_page'];
        $offset = ($paged - 1) * $per_page;
        $page_slice = array_slice($scored, $offset, $per_page);
        $page_ids = array_column($page_slice, 'id');

        $query = new stdClass();
        $query->found_posts = $found_total;
        $query->max_num_pages = $per_page > 0 ? (int) ceil($found_total / $per_page) : 0;
        $query->posts = [];
        foreach ($page_ids as $pid) {
            $post = get_post((int) $pid);
            if ($post) {
                $query->posts[] = $post;
            }
        }
        $query->post_count = count($query->posts);
        $query->have_posts = $query->post_count > 0;

        $debug_payload = null;
        if (function_exists('creatordb_brief_search_debug_enabled') && creatordb_brief_search_debug_enabled()) {
            $debug_payload = function_exists('creatordb_brief_build_search_debug')
                ? creatordb_brief_build_search_debug([
                    'plugin_loaded'      => function_exists('creatordb_parse_search_brief'),
                    'brief_text'         => $brief_text,
                    'parsed'             => $parsed_brief,
                    'merged'             => $explicit,
                    'query_args'         => $args,
                    'found_posts'        => (int) $query->found_posts,
                    'post_ids'           => [],
                    'min_followers_raw'  => $min_followers,
                    'max_followers_raw'  => $max_followers,
                    'follower_bounds'    => isset($follower_bounds) ? $follower_bounds : null,
                ])
                : ['enabled' => true, 'wp_query_args' => $args, 'found_posts' => (int) $query->found_posts];
            error_log('--- INFLUENCER BRIEF SEARCH DEBUG ---');
            error_log(wp_json_encode($debug_payload, JSON_PRETTY_PRINT));
        }

        error_log('--- INFLUENCER AJAX ARGS ---');
        error_log(print_r($ids_args, true));

        if ($query->have_posts) {
            set_query_var('search_criteria', $search_criteria);

            $posts = $query->posts;

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

            if (is_array($debug_payload)) {
                $debug_payload['returned_post_ids'] = array_map(function ($p) {
                    return (int) $p->ID;
                }, $posts);
                $debug_payload['score_cap'] = $score_cap;
                $debug_payload['scored_pool'] = count($all_ids);
            }

            $success_data = array(
                'html'               => $html_output,
                'found_posts'        => $query->found_posts,
                'max_pages'          => $query->max_num_pages,
                'number_of_searches' => $number_of_searches,
            );
            if (is_array($debug_payload)) {
                $success_data['debug'] = $debug_payload;
            }
            wp_send_json_success($success_data);
        } else {
            ob_end_clean();
            if (is_array($debug_payload)) {
                wp_send_json_error(array(
                    'message' => 'No posts found',
                    'debug'   => $debug_payload,
                ));
            } else {
                wp_send_json_error('No posts found');
            }
        }

        wp_die();
    }


    // ========================================================================
    // 5. BRIEF PARSER LOGIC
    // ========================================================================

    public static function get_brief_keyword_mappings()
    {
        return [
            // Niche: Brief keyword => niche slug(s). Wellbeing & wellness are synonyms (both map to both).
            'niche' => [
                // Wellbeing / wellness (synonyms — both map to both slugs)
                'wellbeing'           => ['wellbeing', 'wellness'],
                'wellness'            => ['wellbeing', 'wellness'],
                'self-care'           => ['wellbeing', 'wellness'],
                'self care'           => ['wellbeing', 'wellness'],
                'holistic health'     => ['wellbeing', 'wellness'],
                'mindfulness'         => ['wellbeing', 'wellness'],
                'mental health'       => ['wellbeing', 'wellness'],
                'emotional wellbeing' => ['wellbeing', 'wellness'],
                'stress relief'       => ['wellbeing', 'wellness'],
                'relaxation'          => ['wellbeing', 'wellness'],
                'healthy living'      => ['wellbeing', 'wellness'],
                'lifestyle wellness'  => ['wellbeing', 'wellness'],
                'self-improvement'    => ['wellbeing', 'wellness'],
                'beauty wellness'     => ['wellbeing', 'wellness'],
                'wellness routines'   => ['wellbeing', 'wellness'],
                'women\'s wellbeing'  => ['wellbeing', 'wellness'],
                'men\'s wellbeing'    => ['wellbeing', 'wellness'],
                // Fertility
                'fertility'           => 'fertility-doctor',
                'ivf'                 => 'fertility-doctor',
                'ttc'                 => 'fertility-doctor',
                'trying to conceive'  => 'fertility-doctor',
                'infertility'         => 'fertility-doctor',
                // Pregnancy
                'pregnancy'           => 'pregnancy',
                'pregnant'            => 'pregnancy',
                'expecting'           => 'pregnancy',
                'mum-to-be'           => 'pregnancy',
                'mom-to-be'           => 'pregnancy',
                'dad-to-be'           => 'pregnancy',
                'postpartum'          => 'pregnancy',
                'maternity'           => 'pregnancy',
                // Parenting
                'parenting'           => ['parenting', 'motherhood'],
                'motherhood'          => ['parenting', 'motherhood'],
                'fatherhood'          => 'parenting',
                'mum life'            => ['parenting', 'motherhood'],
                'mom life'            => ['parenting', 'motherhood'],
                'family life'         => 'parenting',
                'newborn'             => 'parenting',
                'babies'              => 'parenting',
                'toddlers'            => 'parenting',
                'children'            => 'parenting',
                // General
                'skincare'            => 'skincare',
                'beauty'              => 'beauty',
                'fitness'             => 'fitness',
                'nutrition'           => 'nutrition',
                'diet'                => 'nutrition',
                'healthy eating'      => 'nutrition',
                'exercise'            => 'fitness',
                'movement'            => 'fitness',
                'yoga'                => 'fitness',
                'pilates'             => 'fitness',
                'running'             => 'fitness',
                'fashion'             => 'fashion',
                'travel'              => 'travel',
                'vegan'               => 'vegan',
                'food'                => 'food',
                'lifestyle'           => 'lifestyle',
                'health'              => 'health',
                'haircare'            => 'haircare',
            ],

            // Geography: Brief keyword => country alpha3 (UPPERCASE). From Geography dictionary.
            'country' => [
                'worldwide'        => 'GLOBAL',
                'global'           => 'GLOBAL',
                'international'    => 'GLOBAL',
                'uk'               => 'GBR',
                'u.k.'             => 'GBR',
                'united kingdom'   => 'GBR',
                'england'          => 'GBR',
                'scotland'         => 'GBR',
                'wales'            => 'GBR',
                'northern ireland' => 'GBR',
                'britain'          => 'GBR',
                'london'           => 'GBR',
                'manchester'       => 'GBR',
                'birmingham'       => 'GBR',
                'usa'              => 'USA',
                'united states'    => 'USA',
                'america'          => 'USA',
                'us'               => 'USA',
                'los angeles'      => 'USA',
                'new york'         => 'USA',
                'chicago'          => 'USA',
                'miami'            => 'USA',
                'europe'           => 'EUROPE',
                'eu'               => 'EUROPE',
                'germany'          => 'DEU',
                'france'           => 'FRA',
                'spain'            => 'ESP',
                'italy'            => 'ITA',
                'netherlands'      => 'NLD',
                'australia'        => 'AUS',
                'aus'              => 'AUS',
                'sydney'           => 'AUS',
                'melbourne'        => 'AUS',
                'canada'           => 'CAN',
                'ca'               => 'CAN',
                'toronto'          => 'CAN',
                'vancouver'        => 'CAN',
            ],

            // Platform
            'platform' => [
                'instagram' => 'instagram',
                'ig'        => 'instagram',
                'insta'     => 'instagram',
                'youtube'   => 'youtube',
                'yt'        => 'youtube',
                'tiktok'    => 'tiktok',
                'tik tok'   => 'tiktok',
                'facebook'  => 'facebook',
            ],

            // Budget => follower range
            'budget_to_followers' => [
                ['max' => 500, 'range' => '1000-10000'],
                ['max' => 2000, 'range' => '10000-50000'],
                ['max' => 5000, 'range' => '50000-250000'],
                ['max' => 15000, 'range' => '250000-1000000'],
                ['max' => 999999, 'range' => '1000000-10000000'],
            ],

            // Filter options
            'filter' => [
                'verified'     => 'Include only verified influencers',
                'verification' => 'Include only verified influencers',
                'engagement'   => 'Prioritise engagement over reach',
                'engage'       => 'Prioritise engagement over reach',
            ],

            // Audience (for future gender/age filters) — from Audience dictionary
            'gender' => [
                'female'   => 'Female',
                'women'    => 'Female',
                'woman'    => 'Female',
                'mums'     => 'Female',
                'moms'     => 'Female',
                'ladies'   => 'Female',
                'girls'    => 'Female',
                'male'     => 'Male',
                'men'      => 'Male',
                'man'      => 'Male',
                'dads'     => 'Male',
                'fathers'  => 'Male',
                'guys'     => 'Male',
                'boys'     => 'Male',
                'nonbinary' => 'Non-Binary',
                'non-binary' => 'Non-Binary',
                'nb'       => 'Non-Binary',
                'lgbtq'    => 'lgbtq_plus',
                'lgbt'     => 'lgbtq_plus',
                'queer'    => 'lgbtq_plus',
            ],
            'age' => [
                'gen z'           => '18-24',
                '18-24'           => '18-24',
                'young audience'  => '18-24',
                'students'        => '18-24',
                'teens'           => '18-24',
                'millennial'      => '25-34',
                'millennials'     => '25-34',
                '25-34'           => '25-34',
                'young adults'    => '25-34',
                'gen x'           => '35-44',
                '35-44'           => '35-44',
                'middle aged'     => '35-44',
                '45+'             => '45-54',
                '50+'             => '45-54',
                'older audience'  => '45-54',
                'midlife'         => '45-54',
            ],
        ];
    }

    public static function _brief_normalize_slugs($val)
    {
        if (is_array($val)) {
            return $val;
        }
        return $val ? [$val] : [];
    }

    public static function match_brief_to_taxonomy_terms($text, $taxonomy)
    {
        if (empty($text) || !is_string($text)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'all',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $text_lower = strtolower(trim($text));
        $found = [];

        foreach ($terms as $term) {
            $slug_lower = strtolower($term->slug);
            $name_lower = strtolower($term->name);
            // Slug "country-music" -> match "country music" or "country-music"
            $slug_as_words = str_replace('-', ' ', $slug_lower);
            // Match whole words: slug, name, or slug-with-spaces
            $patterns = [
                '/\b' . preg_quote($slug_lower, '/') . '\b/',
                '/\b' . preg_quote($name_lower, '/') . '\b/',
                '/\b' . preg_quote($slug_as_words, '/') . '\b/',
            ];
            foreach ($patterns as $pat) {
                if (preg_match($pat, $text_lower)) {
                    $found[$term->slug] = true;
                    break;
                }
            }
        }

        return array_keys($found);
    }

    public static function resolve_brief_niches($keywords)
    {
        if (empty($keywords)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => 'niche',
            'hide_empty' => false,
            'fields'     => 'id=>slug',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $slugs = array_values($terms);
        $found = [];

        foreach ($keywords as $kw) {
            $kw_lower = strtolower(trim($kw));
            foreach ($slugs as $slug) {
                if ($slug === $kw_lower) {
                    $found[$slug] = true;
                }
            }
        }

        return array_keys($found);
    }

    public static function resolve_brief_platforms($keywords)
    {
        if (empty($keywords)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => 'platform',
            'hide_empty' => false,
            'fields'     => 'id=>slug',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $slugs = array_values($terms);
        $found = [];

        foreach ($keywords as $kw) {
            $kw_lower = strtolower(trim($kw));
            foreach ($slugs as $slug) {
                if ($slug === $kw_lower) {
                    $found[$slug] = true;
                }
            }
        }

        return array_keys($found);
    }

    public static function resolve_brief_topics($keywords)
    {
        if (empty($keywords)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => 'topic',
            'hide_empty' => false,
            'fields'     => 'id=>slug',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $slugs = array_values($terms);
        $found = [];

        foreach ($keywords as $kw) {
            $kw_lower = strtolower(trim($kw));
            foreach ($slugs as $slug) {
                if ($slug === $kw_lower) {
                    $found[$slug] = true;
                }
            }
        }

        return array_keys($found);
    }

    public static function resolve_brief_content_tags($keywords)
    {
        if (empty($keywords)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => 'content_tag',
            'hide_empty' => false,
            'fields'     => 'id=>slug',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $slugs = array_values($terms);
        $found = [];

        foreach ($keywords as $kw) {
            $kw_lower = strtolower(trim($kw));
            foreach ($slugs as $slug) {
                if ($slug === $kw_lower) {
                    $found[$slug] = true;
                }
            }
        }

        return array_keys($found);
    }

    public static function parse_search_brief($text)
    {
        if (function_exists('creatordb_parse_search_brief')) {
            return creatordb_parse_search_brief($text);
        }

        $result = [
            'niche'        => [],
            'country'      => [],
            'platform'     => [],
            'followers'    => [],
            'filter'       => [],
            'topic'        => [],
            'content_tag'  => [],
        ];

        if (empty($text) || !is_string($text)) {
            return $result;
        }

        $text_lower = strtolower(trim($text));
        $mappings   = self::get_brief_keyword_mappings();

        // Budget
        if (preg_match('/[£$]\s*([\d,]+)(?:\s*(?:per|per creator|each))?/i', $text, $m)) {
            $amount = (int) preg_replace('/[^\d]/', '', $m[1]);
            foreach ($mappings['budget_to_followers'] as $tier) {
                if ($amount <= $tier['max']) {
                    $result['followers'] = [$tier['range']];
                    break;
                }
            }
            if (empty($result['followers']) && $amount > 15000) {
                $result['followers'] = ['1000000-10000000'];
            }
        }

        // Niches: 1) keyword dictionary (priority / platform-built-for), 2) taxonomy terms
        $niche_keywords = [];
        foreach ($mappings['niche'] as $phrase => $slug_or_slugs) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
                $niche_keywords = array_merge($niche_keywords, self::_brief_normalize_slugs($slug_or_slugs));
            }
        }
        $niche_from_dict = self::resolve_brief_niches(array_unique($niche_keywords));
        $niche_from_tax  = self::match_brief_to_taxonomy_terms($text, 'niche');
        $result['niche'] = array_values(array_unique(array_merge($niche_from_dict, $niche_from_tax)));

        // Countries
        $country_found = [];
        $europe_codes = ['DEU', 'FRA', 'ESP', 'ITA', 'NLD']; // From Geography dict: europe = germany, france, spain, italy, netherlands
        foreach ($mappings['country'] as $phrase => $code) {
            if ($code === 'GLOBAL') {
                continue; // Skip — no single alpha3
            }
            if (in_array($code, ['EU', 'EUROPE'], true)) {
                if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
                    foreach ($europe_codes as $c) {
                        $country_found[$c] = true;
                    }
                }
                continue;
            }
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
                $country_found[$code] = true;
            }
        }
        $result['country'] = array_keys($country_found);

        // Platforms: 1) keyword dictionary (ig, yt, etc.), 2) taxonomy terms
        $platform_keywords = [];
        foreach ($mappings['platform'] as $phrase => $slug) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
                $platform_keywords[] = $slug;
            }
        }
        $platform_from_dict = self::resolve_brief_platforms(array_unique($platform_keywords));
        $platform_from_tax  = self::match_brief_to_taxonomy_terms($text, 'platform');
        $result['platform'] = array_values(array_unique(array_merge($platform_from_dict, $platform_from_tax)));

        // Filters
        foreach ($mappings['filter'] as $phrase => $filter_key) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
                $result['filter'][] = $filter_key;
            }
        }
        $result['filter'] = array_unique($result['filter']);

        // Topics: 1) dictionary niche keywords (platform-built-for), 2) taxonomy terms
        $topic_keywords = array_unique($niche_keywords);
        $topic_from_dict = self::resolve_brief_topics($topic_keywords);
        $topic_from_tax  = self::match_brief_to_taxonomy_terms($text, 'topic');
        $result['topic'] = array_values(array_unique(array_merge($topic_from_dict, $topic_from_tax)));

        // Content Tags: 1) dictionary niche keywords, 2) taxonomy terms
        $content_tag_keywords = array_unique($niche_keywords);
        $ct_from_dict = self::resolve_brief_content_tags($content_tag_keywords);
        $ct_from_tax  = self::match_brief_to_taxonomy_terms($text, 'content_tag');
        $result['content_tag'] = array_values(array_unique(array_merge($ct_from_dict, $ct_from_tax)));

        return $result;
    }

    public static function merge_brief_with_explicit_filters($parsed, $explicit)
    {
        if (function_exists('creatordb_merge_brief_with_explicit_filters')) {
            return creatordb_merge_brief_with_explicit_filters($parsed, $explicit);
        }

        $keys = ['niche', 'country', 'platform', 'followers', 'filter', 'topic', 'content_tag', 'gender'];

        foreach ($keys as $key) {
            if (!isset($parsed[$key])) {
                $parsed[$key] = [];
            }
        }

        $merged = [];

        foreach ($keys as $key) {
            $explicit_val = isset($explicit[$key]) ? $explicit[$key] : [];
            if (!is_array($explicit_val)) {
                $explicit_val = $explicit_val ? [$explicit_val] : [];
            }
            $parsed_val = isset($parsed[$key]) ? $parsed[$key] : [];

            if (!empty($explicit_val)) {
                $merged[$key] = $explicit_val;
            } elseif (!empty($parsed_val)) {
                $merged[$key] = $parsed_val;
            } else {
                $merged[$key] = [];
            }
        }

        return $merged;
    }

    // ========================================================================
    // 5. ELEMENTOR WIDGET
    // ========================================================================

    public function register_elementor_widgets($widgets_manager)
    {
        // Failsafe: Check if Elementor is active before proceeding.
        // If Elementor is not loaded, gracefully exit this function to prevent fatal errors.
        if (! did_action('elementor/loaded')) {
            return;
        }

        require_once(get_stylesheet_directory() . '/modules/frontend-utilities/widgets/class-influencer-search-form-widget.php');
        require_once(get_stylesheet_directory() . '/modules/frontend-utilities/widgets/class-influencer-search-results-widget.php');
        require_once(get_stylesheet_directory() . '/modules/frontend-utilities/widgets/class-influencer-search-summary-widget.php');
        require_once(get_stylesheet_directory() . '/modules/frontend-utilities/widgets/class-influencer-match-score-widget.php');
        require_once(get_stylesheet_directory() . '/modules/frontend-utilities/widgets/class-module-shortcode-widgets.php');

        // Register core widgets
        $widgets_manager->register(new \Influencer_Search_Form_Widget());
        $widgets_manager->register(new \Influencer_Search_Results_Widget());
        $widgets_manager->register(new \Influencer_Search_Summary_Widget());
        $widgets_manager->register(new \Influencer_Match_Score_Widget());

        // Register module shortcode wrapper widgets
        $widgets_manager->register(new \DD_Widget_SC_Custom_Mycred_Log());
        $widgets_manager->register(new \DD_Widget_SC_Follower_Growth_Chart());
        $widgets_manager->register(new \DD_Widget_SC_Follower_Timeline_Chart());
        $widgets_manager->register(new \DD_Widget_SC_Follower_Growth_Rate_Chart());
        $widgets_manager->register(new \DD_Widget_SC_Follower_Like_Range_Chart());
        $widgets_manager->register(new \DD_Widget_SC_Creatordb_Feed());
        $widgets_manager->register(new \DD_Widget_SC_Outreach_List());
        $widgets_manager->register(new \DD_Widget_SC_Outreach_View());
        $widgets_manager->register(new \DD_Widget_SC_Outreach_Credit_Cost());
        $widgets_manager->register(new \DD_Widget_SC_Outreach_Message());
        $widgets_manager->register(new \DD_Widget_SC_Outreach_Button());
        $widgets_manager->register(new \DD_Widget_SC_My_Saved_Groups());
        $widgets_manager->register(new \DD_Widget_SC_Add_To_Groups());
        $widgets_manager->register(new \DD_Widget_SC_Remove_From_Group());
        $widgets_manager->register(new \DD_Widget_SC_My_Saved_Searches());
        $widgets_manager->register(new \DD_Widget_SC_Pricing_Table());
    }
}

// Initialize the class
new Influencer_Search();
