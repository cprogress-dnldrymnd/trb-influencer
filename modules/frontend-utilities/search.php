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

        // Flush filter dropdown transients when an influencer post is saved or deleted
        add_action('save_post', [__CLASS__, 'flush_filter_transients']);
        add_action('delete_post', [__CLASS__, 'flush_filter_transients']);

        // Register shortcodes
        add_shortcode('influencer_match_score',    [__CLASS__, 'shortcode_match_score']);
        add_shortcode('influencer_search_summary', [__CLASS__, 'shortcode_search_summary']);
        add_shortcode('influencer_search_results', [__CLASS__, 'shortcode_search_results']);
        add_shortcode('influencer_search_form',    [__CLASS__, 'shortcode_search_form']);
    }


    /**
     * Variable setup for search & outreach fields
     */
    public function setup_search_variables()
    {
        // Parse brief and merge into $_GET when on search results page with search-brief
        $influencer_search_page_id = dd_get_page_id('dd_search_results_page_id', 1949);
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

        $min_followers_options = array(
            '0' => '0',
            '10000' => '10K',
            '50000' => '50K',
            '250000' => '250K',
            '1000000' => '1M',
            '10000000' => '10M',
        );

          $max_followers_options = array(
            '1000' => '1K',
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
        $influencer_search_fields['min_followers'] = $min_followers_options;
        $influencer_search_fields['max_followers'] = $max_followers_options;
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
        $cached = get_transient('dd_influencer_countries');
        if ($cached !== false) return $cached;

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
        set_transient('dd_influencer_countries', $country_list, 12 * HOUR_IN_SECONDS);
        return $country_list;
    }

    /**
     * Get sorted array of unique languages from 'influencer' post type.
     */
    public static function get_unique_influencer_languages()
    {
        $cached = get_transient('dd_influencer_languages');
        if ($cached !== false) return $cached;

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
        set_transient('dd_influencer_languages', $language_list, 12 * HOUR_IN_SECONDS);
        return $language_list;
    }

    /**
     * Get sorted array of unique genders from 'influencer' post type meta.
     */
    public static function get_unique_influencer_genders()
    {
        $cached = get_transient('dd_influencer_genders');
        if ($cached !== false) return $cached;

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
        set_transient('dd_influencer_genders', $gender_list, 12 * HOUR_IN_SECONDS);
        return $gender_list;
    }

    /**
     * Flush filter dropdown transients whenever an influencer post is saved.
     */
    public static function flush_filter_transients($post_id)
    {
        if (get_post_type($post_id) !== 'influencer') return;
        delete_transient('dd_influencer_countries');
        delete_transient('dd_influencer_languages');
        delete_transient('dd_influencer_genders');
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
            <div class="tags-container" style="display: none;"></div>
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
        check_ajax_referer('search_filter_nonce', 'security');

        // 1. GATHER INPUTS (explicit form values)
        $explicit = [
            'niche'         => isset($_POST['niche']) ? $_POST['niche'] : [],
            'country'       => isset($_POST['country']) ? $_POST['country'] : [],
            'lang'          => isset($_POST['lang']) ? $_POST['lang'] : [],
            'gender'        => isset($_POST['gender']) ? $_POST['gender'] : [],

            // Dedicated min/max follower inputs (scalar)
            'min_followers' => isset($_POST['min_followers']) ? sanitize_text_field(wp_unslash($_POST['min_followers'])) : '',
            'max_followers' => isset($_POST['max_followers']) ? sanitize_text_field(wp_unslash($_POST['max_followers'])) : '',

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

        // Cache the (expensive, sometimes flaky) ID pool + scoring per unique search so that
        // paging through results (page 2, 3, ...) reuses the first page's work instead of
        // re-running the scorer on every "Load More" click — this is what was intermittently
        // erroring out and showing 0 results on later pages.
        $pool_cache_key = 'dd_brief_pool_' . md5(wp_json_encode([$ids_args, $search_criteria, $score_cap]));
        $cached_pool = get_transient($pool_cache_key);

        if (is_array($cached_pool) && isset($cached_pool['scored'], $cached_pool['found_total']) && is_array($cached_pool['scored'])) {
            $scored = $cached_pool['scored'];
            $found_total = (int) $cached_pool['found_total'];
        } else {
            $ids_query = new WP_Query($ids_args);
            $all_ids = array_map('intval', (array) $ids_query->posts);
            $found_total = (int) $ids_query->found_posts;

            $scored = null;
            if (function_exists('creatordb_brief_sort_post_ids_by_score')) {
                try {
                    $scored = creatordb_brief_sort_post_ids_by_score($all_ids, $search_criteria);
                } catch (\Throwable $e) {
                    error_log('Influencer brief search: scoring failed — ' . $e->getMessage());
                    $scored = null;
                }
            }

            if (!is_array($scored)) {
                $scored = array_map(function ($id) {
                    return ['id' => $id, 'score' => 50];
                }, $all_ids);
            }

            set_transient($pool_cache_key, [
                'scored'      => $scored,
                'found_total' => $found_total,
            ], 10 * MINUTE_IN_SECONDS);
        }

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
                    echo do_shortcode('[elementor-template id="' . dd_get_template_id('dd_tpl_search_card', 1839) . '"]');
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
                $debug_payload['scored_pool'] = count($scored);
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
                    'message' => __('No posts found', 'hello-elementor-child'),
                    'debug'   => $debug_payload,
                ));
            } else {
                wp_send_json_error(__('No posts found', 'hello-elementor-child'));
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
    // 5. SHORTCODES
    // ========================================================================

    public static function shortcode_match_score($atts = [])
    {
        $post_id  = get_query_var('current_influencer_id') ?: get_the_ID();
        $criteria = get_query_var('search_criteria');
        $criteria = is_array($criteria) ? $criteria : [];

        $score = calculate_match_score($post_id, $criteria);

        if ($score < 0) {
            if (function_exists('creatordb_brief_match_score_badge_html')) {
                return creatordb_brief_match_score_badge_html(-1);
            }
            return '<span class="influencer-match-score-wrap">— Match Score</span>';
        }

        $badge_label = function_exists('creatordb_brief_match_score_badge_html')
            ? creatordb_brief_match_score_badge_html((int) $score)
            : ('✨ ' . (int) $score . '% Match Score');

        $tooltip = function_exists('creatordb_get_match_evidence_tooltip_html')
            ? creatordb_get_match_evidence_tooltip_html($post_id, $criteria)
            : implode("\n", get_matched_criteria_labels($post_id, $criteria));

        $html  = '<div class="influencer-match-score-wrap tooltip-wrapper">';
        $html .= '<span class="influencer-match-score-trigger tooltip-trigger">' . esc_html($badge_label) . '</span>';
        if (! empty(trim($tooltip))) {
            if (strpos($tooltip, 'influencer-match-score-checklist') === false) {
                $tooltip = '<span class="influencer-match-score-checklist">' . $tooltip . '</span>';
            }
            $html .= '<div class="influencer-match-score-tooltip tooltip-content">' . wp_kses_post($tooltip) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    public static function shortcode_search_summary($atts = [])
    {
        global $search_results_page_id;
        if ((int) get_queried_object_id() !== $search_results_page_id) {
            return '';
        }

        $brief       = isset($_GET['search-brief']) ? trim(sanitize_textarea_field(wp_unslash($_GET['search-brief']))) : '';
        $niche       = isset($_GET['niche'])        ? (array) $_GET['niche']       : [];
        $country     = isset($_GET['country'])      ? (array) $_GET['country']     : [];
        $followers   = isset($_GET['followers'])    ? (array) $_GET['followers']   : [];
        $filter      = isset($_GET['filter'])       ? (array) $_GET['filter']      : [];
        $gender      = isset($_GET['gender'])       ? (array) $_GET['gender']      : [];
        $content_tag = isset($_GET['content_tag'])  ? (array) $_GET['content_tag'] : [];

        if (empty($brief) && empty($niche) && empty($country) && empty($followers) && empty($gender) && empty($content_tag)) {
            return '';
        }

        $fields = is_array(get_query_var('influencer_search_fields')) ? get_query_var('influencer_search_fields') : [];
        $parts  = [];

        if (! empty($niche)) {
            $niche_names = [];
            foreach ($niche as $slug) {
                $niche_names[] = $fields['niche'][$slug] ?? ucfirst($slug);
            }
            $parts[] = implode(', ', $niche_names);
        }
        if (! empty($country)) {
            $country_names = [];
            foreach ($country as $code) {
                $country_names[] = $fields['country'][$code] ?? strtoupper($code);
            }
            $parts[] = implode(', ', $country_names);
        }
        if (! empty($followers) && ! empty($followers[0])) {
            $f_opts  = $fields['followers'] ?? [];
            $parts[] = $f_opts[$followers[0]] ?? $followers[0];
        }
        if (! empty($gender)) {
            $gender_names = [];
            foreach ($gender as $g) {
                $gender_names[] = $fields['gender'][$g] ?? ucfirst($g);
            }
            $parts[] = implode(', ', $gender_names);
        }
        if (! empty($content_tag)) {
            $tag_names = [];
            foreach ($content_tag as $slug) {
                $tag_names[] = $fields['content_tag'][$slug] ?? ucfirst(str_replace('-', ' ', $slug));
            }
            $parts[] = implode(', ', $tag_names);
        }

        $prioritise_engagement = in_array('Prioritise engagement over reach', $filter, true);
        $engagement_boost_soft = false;
        if (! empty($brief) && function_exists('creatordb_parse_search_brief_structured')) {
            $structured_summary = creatordb_parse_search_brief_structured($brief);
            if (! empty($structured_summary['soft_intents']['engagement_boost'])) {
                $engagement_boost_soft = true;
            }
        }
        $verified_only = in_array('Include only verified influencers', $filter, true);
        $expert_only   = in_array('Professional experts only', $filter, true);

        $brief_quality = null;
        if (! empty($brief) && function_exists('creatordb_brief_assess_quality')) {
            $brief_quality = creatordb_brief_assess_quality($brief);
        }
        $quality_copy = function_exists('creatordb_brief_quality_copy') ? creatordb_brief_quality_copy() : [];
        $search_page_url = get_the_permalink(dd_get_page_id('dd_search_page_id', 2149));

        ob_start();
    ?>
        <div class="influencer-search-summary">
            <?php if (! empty($brief) && ! empty($brief_quality) && ($brief_quality['quality'] ?? '') === 'low') : ?>
                <div class="brief-quality-banner" role="status">
                    <div class="brief-quality-banner__inner">
                        <span class="brief-quality-banner__icon" aria-hidden="true">
                            <img src="<?= esc_url(get_stylesheet_directory_uri() . '/assets/images/lightbulb-notice.svg') ?>" alt="" width="20" height="20" decoding="async">
                        </span>
                        <div class="brief-quality-banner__content">
                            <p class="brief-quality-banner__text"><?= esc_html($quality_copy['low_results_banner'] ?? '') ?></p>
                            <div class="brief-quality-banner__actions">
                                <a class="brief-quality-banner__btn" href="<?= esc_url(add_query_arg('search-brief', rawurlencode($brief), $search_page_url)) ?>">
                                    <?= esc_html($quality_copy['refine_brief'] ?? 'Refine brief') ?>
                                </a>
                                <a class="brief-quality-banner__btn" href="<?= esc_url($search_page_url) ?>">
                                    <?= esc_html($quality_copy['switch_filtered'] ?? 'Try Filtered Search instead') ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (! empty($brief)) : ?>
                <div class="search-summary-brief search-summary-item">
                    <input type="hidden" name="search-brief" id="search-brief" value="<?= esc_attr($brief) ?>">
                    <div class="summary-brief-body">
                        <div class="summary-brief-label">Your brief</div>
                        <div class="summary-brief-text"><?= esc_html($brief) ?></div>
                    </div>
                    <a class="edit-summary-brieft" href="<?= esc_url(get_the_permalink(dd_get_page_id('dd_search_page_id', 2149))) ?>?search-brief=<?= urlencode($brief) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 20h9" />
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
                        </svg>
                        <span>Edit brief</span>
                    </a>
                </div>
            <?php endif; ?>
            <?php if (! empty($parts) && empty($brief)) : ?>
                <div class="search-summary-item search-summary-filters"><strong>Filters:</strong> <?= esc_html(implode(' • ', $parts)) ?></div>
            <?php endif; ?>
            <!--
            <?php if ($prioritise_engagement || $engagement_boost_soft || $verified_only || $expert_only) : ?>
                <div class="search-summary-item search-summary-notes">
                    <?php
                    $notes        = [];
                    $summary_copy = function_exists('creatordb_brief_summary_note_labels')
                        ? creatordb_brief_summary_note_labels()
                        : [];
                    if ($prioritise_engagement) {
                        $notes[] = '<span>' . esc_html($summary_copy['engagement_hard'] ?? 'Prioritising engagement over reach') . '</span>';
                    } elseif ($engagement_boost_soft) {
                        $notes[] = '<span>' . esc_html($summary_copy['engagement_soft'] ?? 'Engagement preference (sort boost — not a hard filter)') . '</span>';
                    }
                    if ($verified_only) {
                        $notes[] = '<span>' . esc_html($summary_copy['verified'] ?? 'Include only verified influencers') . '</span>';
                    }
                    if ($expert_only) {
                        $notes[] = '<span>' . esc_html($summary_copy['expert'] ?? 'Professional experts only') . '</span>';
                    }
                    echo implode(' • ', $notes);
                    ?>
                </div>
            <?php endif; ?>
            -->
            <?php if (function_exists('creatordb_brief_search_debug_enabled') && creatordb_brief_search_debug_enabled()) : ?>
                <div id="ic-brief-search-debug" class="ic-brief-search-debug" aria-live="polite">
                    <details open>
                        <summary>Brief search debug (dev)</summary>
                        <p class="ic-brief-search-debug-hint">Runs after each search. Requires <code>WP_DEBUG</code> or <code>IC_BRIEF_SEARCH_DEBUG</code> in wp-config.</p>
                        <pre class="ic-brief-search-debug-body">Waiting for search AJAX…</pre>
                    </details>
                </div>
                <style>
                    .ic-brief-search-debug {
                        margin: 1rem 0;
                        padding: .75rem 1rem;
                        background: #1e1e2e;
                        color: #cdd6f4;
                        border-radius: 8px;
                        font-size: 12px;
                    }

                    .ic-brief-search-debug summary {
                        cursor: pointer;
                        font-weight: 600;
                        color: #89b4fa;
                    }

                    .ic-brief-search-debug-hint {
                        opacity: .85;
                        margin: .5rem 0;
                    }

                    .ic-brief-search-debug-body {
                        max-height: 420px;
                        overflow: auto;
                        white-space: pre-wrap;
                        word-break: break-word;
                        margin: 0;
                    }
                </style>
            <?php endif; ?>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Permalink for the Saved Lists page (Elementor-managed page; slug varies by site).
     */
    private static function get_saved_lists_url()
    {
        $url = apply_filters('influencer_saved_lists_url', '');
        if ($url !== '') {
            return $url;
        }

        foreach (['saved-lists', 'my-saved-lists', 'saved-groups', 'my-saved-groups'] as $slug) {
            $page = get_page_by_path($slug);
            if ($page instanceof WP_Post) {
                return get_permalink($page);
            }
        }

        return home_url('/saved-lists/');
    }

    public static function shortcode_search_results($atts = [])
    {
        $saved_lists_url = self::get_saved_lists_url();

        ob_start();
    ?>
        <div id="influencer-search-result-holder">
            <div class="influencer-results-meta">
                <p class="influencer-results-meta__count">
                    Displaying <span class="current-found-influencer">0</span> of <span class="total-found-influencer">0</span> matches
                </p>
                <a class="influencer-results-meta__saved-link" href="<?= esc_url($saved_lists_url) ?>">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 384 512" xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor" d="M0 512V48C0 21.49 21.49 0 48 0h288c26.51 0 48 21.49 48 48v464L192 400 0 512z" />
                    </svg>
                    <span>View Saved Lists</span>
                </a>
            </div>
            <div class="influencer-grid-box">
                <div id="my-loop-grid-container" class="influencer-loop-grid" aria-live="polite" aria-atomic="false" aria-busy="false"></div>
            </div>
            <div class="load-more-wrapper">
                <button id="load-more-influencers" class="elementor-button" style="display: none;">
                    Load More
                </button>
            </div>
            <div class="loading-animation" style="display: none;">
                <p class="loading-text">
                    Scouring the globe for influencers who fit your brand<span class="loading-dots"><span></span><span></span><span></span></span>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function shortcode_search_form($atts = [])
    {
        $atts = shortcode_atts([
            'layout'   => 'main',
            'btn_text' => 'FIND MATCHES',
        ], $atts);

        $layout   = $atts['layout'];
        $btn_text = $atts['btn_text'];

        $raw_fields               = get_query_var('influencer_search_fields');
        $influencer_search_fields = is_array($raw_fields) ? $raw_fields : [];
        $influencer_search_page   = get_query_var('influencer_search_page');
        $form_action              = $influencer_search_page ? get_the_permalink($influencer_search_page) : '';
        $brief                    = isset($_GET['search-brief']) ? trim(sanitize_textarea_field(wp_unslash($_GET['search-brief']))) : '';

        ob_start();

        if ($layout === 'sidebar') {
        ?>
            <form class="influencer-search influencer-search-sidebar" action="<?= esc_url($form_action) ?>" method="GET">
                <div class="influencer-search-filter-holder">
                    <div class="influencer-search-item niche-filters required-on-search">
                        <?= self::select_filter('niche', 'Niche Filter', 'Select your niche filters', $influencer_search_fields['niche'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-followers-filter">
                        <div class="header">
                            <span>Follower Count</span>
                            <div class="reset-btn" style="display: none;">Reset</div>
                        </div>
                        <div class="followers-filter">
                            <div class="influencer-search-item">
                                <?= self::select_filter('min_followers', '', 'Min.', $influencer_search_fields['min_followers'] ?? '', 'radio') ?>
                            </div>
                            <div class="influencer-search-item">
                                <?= self::select_filter('max_followers', '', 'Max.', $influencer_search_fields['max_followers'] ?? '', 'radio') ?>
                            </div>
                        </div>
                    </div>
                    <div class="influencer-search-item">
                        <?= self::select_filter('country', 'Location', 'Select a new location', $influencer_search_fields['country'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item">
                        <?= self::select_filter('lang', 'Language', 'Select a new language', $influencer_search_fields['lang'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item">
                        <?= self::select_filter('gender', 'Gender', 'Select Gender', $influencer_search_fields['gender'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item">
                        <?= self::select_filter('content_tag', 'Hashtags', 'Search hashtags...', $influencer_search_fields['content_tag'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item">
                        <?= self::checkbox_filter('filter', false, $influencer_search_fields['filter'] ?? '') ?>
                    </div>
                    <div class="influencer-search-item">
                        <button type="submit" class="influencer-search-button influencer-search-trigger elementor-button elementor-button-link elementor-size-sm">
                            <span class="elementor-button-content-wrapper">
                                <span class="elementor-button-icon elementor-align-icon-left">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="37.295" height="33.001" viewBox="0 0 37.295 33.001">
                                        <g id="search" transform="translate(-10.238 -12.501)">
                                            <path id="Path_327" data-name="Path 327" d="M65.046,13.082a.62.62,0,0,1,1.238,0,4.667,4.667,0,0,0,4.047,4.047.62.62,0,0,1,0,1.238,4.667,4.667,0,0,0-4.047,4.047.62.62,0,0,1-1.238,0A4.667,4.667,0,0,0,61,18.367a.62.62,0,0,1,0-1.238A4.667,4.667,0,0,0,65.046,13.082Z" transform="translate(-27.197)" />
                                            <path id="Path_328" data-name="Path 328" d="M79.915,35.84a.476.476,0,0,1,.454-.422.482.482,0,0,1,.458.422,2.978,2.978,0,0,0,2.512,2.41.455.455,0,0,1,0,.907,2.972,2.972,0,0,0-2.515,2.517.456.456,0,0,1-.909,0,2.976,2.976,0,0,0-2.41-2.514.458.458,0,0,1,0-.912,2.864,2.864,0,0,0,2.408-2.408Z" transform="translate(-36.23 -12.421)" />
                                            <path id="Path_329" data-name="Path 329" d="M25.587,49.116A10.492,10.492,0,1,0,16.512,43.9a1.017,1.017,0,0,1-.136,1.233l-5.58,5.58a1.908,1.908,0,1,0,2.7,2.7l5.58-5.578a1.016,1.016,0,0,1,1.233-.136,10.443,10.443,0,0,0,5.278,1.421Zm0-3.816a6.68,6.68,0,1,0-6.679-6.679A6.678,6.678,0,0,0,25.587,45.3Z" transform="translate(0 -8.468)" fill-rule="evenodd" />
                                        </g>
                                    </svg>
                                </span>
                                <span class="elementor-button-content-wrapper"><span class="elementor-button-text"><?= esc_html($btn_text) ?></span></span>
                            </span>
                        </button>
                    </div>
                    <div class="reset-btn-holder">
                        <div class="reset-btn reset-all-btn" style="display: none">Reset All</div>
                    </div>
                    <div class="save-this-search">
                        <span class="save-search-trigger">Save this search</span>
                    </div>
                </div>
            </form>
        <?php
        } else {
            $is_brief_active = ! empty($brief);
            $checked_attr    = $is_brief_active ? 'checked="checked"' : '';
        ?>
            <form class="influencer-search influencer-search-main" action="<?= esc_url($form_action) ?>" method="GET">
                <div id="search-header">
                    <div class="toggle-holder">
                        <div class="filtered-search toggle-text <?= ! $is_brief_active ? 'active' : '' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="23.66" height="20" viewBox="0 0 23.66 20">
                                <path id="target" d="M24.044,20.152A10.187,10.187,0,0,1,24.1,21.2,10,10,0,1,1,19.973,13.1l-.745,2.778a7.375,7.375,0,1,0,2.037,3.527l2.777.744ZM13.436,21.579a.764.764,0,0,0,1.045.278l6.549-3.781,2.312.619,4.414-2.549-3.356-.9.9-3.356-4.414,2.549-.619,2.312-6.551,3.782a.764.764,0,0,0-.278,1.045Zm.661-3.032a2.671,2.671,0,0,1,.518.05L17.2,17.106a5.132,5.132,0,1,0,2.03,4.089,5.173,5.173,0,0,0-.04-.641l-2.582,1.491a2.649,2.649,0,1,1-2.51-3.5Z" transform="translate(-4.097 -11.195)" fill="#00a6ed" fill-rule="evenodd"></path>
                            </svg>
                            <span>FILTERED SEARCH</span>
                        </div>
                        <div class="toggle-html">
                            <label class="toggle-switch">
                                <input type="checkbox" id="my-toggle" <?= $checked_attr ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                        <div class="full-brief-search toggle-text <?= $is_brief_active ? 'active' : '' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 46.322 46.948">
                                <path id="sparkers" d="M15.96,24.3a.809.809,0,0,0,.851-.751c.9-6.685,1.127-6.685,8.038-8.012a.847.847,0,0,0,.776-.851.864.864,0,0,0-.776-.851c-6.911-.951-7.161-1.177-8.038-7.987a.84.84,0,0,0-1.678.025c-.826,6.71-1.177,6.685-8.037,7.962a.884.884,0,0,0-.776.851c0,.5.326.776.876.851,6.811,1.1,7.111,1.277,7.937,7.962A.811.811,0,0,0,15.96,24.3ZM32.937,52.02a1.289,1.289,0,0,0,1.252-1.152c1.778-13.721,3.706-15.8,17.277-17.3a1.256,1.256,0,0,0,1.177-1.252,1.274,1.274,0,0,0-1.177-1.252c-13.571-1.5-15.5-3.581-17.277-17.3a1.266,1.266,0,0,0-1.252-1.127,1.225,1.225,0,0,0-1.227,1.127c-1.778,13.721-3.731,15.8-17.277,17.3a1.277,1.277,0,0,0-1.2,1.252,1.26,1.26,0,0,0,1.2,1.252c13.521,1.778,15.4,3.606,17.277,17.3A1.248,1.248,0,0,0,32.937,52.02Z" transform="translate(-6.32 -5.073)" fill="#ffe17b"></path>
                            </svg>
                            <span>FULL BRIEF SEARCH</span>
                        </div>
                    </div>
                    <div class="advanced-search-trigger">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="4" y1="21" x2="4" y2="14"></line>
                            <line x1="4" y1="10" x2="4" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12" y2="3"></line>
                            <line x1="20" y1="21" x2="20" y2="16"></line>
                            <line x1="20" y1="12" x2="20" y2="3"></line>
                            <line x1="1" y1="14" x2="7" y2="14"></line>
                            <line x1="9" y1="8" x2="15" y2="8"></line>
                            <line x1="17" y1="16" x2="23" y2="16"></line>
                        </svg>
                        <span>Advanced</span>
                    </div>
                </div>

                <div class="influencer-search-filter-holder">
                    <input type="hidden" value="true" name="search_active">

                    <div class="influencer-search-item-row influencer-search-item-wrapper filtered-search <?= ! $is_brief_active ? 'active' : '' ?>">
                        <div class="influencer-search-item">
                            <div class="influencer-search-item-title">Location</div>
                            <?= self::select_filter('country', false, 'Location', $influencer_search_fields['country'] ?? '', 'checkbox', true) ?>
                        </div>
                        <div class="influencer-search-item">
                            <div class="influencer-search-item-title">Language</div>
                            <?= self::select_filter('lang', false, 'Language', $influencer_search_fields['lang'] ?? '', 'checkbox', true) ?>
                        </div>
                        <div class="influencer-search-item required-on-search">
                            <div class="influencer-search-item-title">Niche<span class="field-required" aria-hidden="true">*</span></div>
                            <?= self::select_filter('niche', false, 'Niche', $influencer_search_fields['niche'] ?? '', 'checkbox', true) ?>
                        </div>
                        <div class="influencer-search-item">
                            <div class="influencer-search-item-title">Follower Count</div>
                            <div class="field-groups">
                                <?= self::select_filter('min_followers', false, 'Minimum', $influencer_search_fields['min_followers'] ?? '', 'radio') ?>
                                <?= self::select_filter('max_followers', false, 'Maximum', $influencer_search_fields['max_followers'] ?? '', 'radio') ?>
                            </div>
                        </div>
                    </div>

                    <div class="filtered-search <?= ! $is_brief_active ? 'active' : '' ?>">
                        <div class="advanced-search-filters" style="display: none;">
                            <div class="influencer-search-item-row influencer-search-item-wrapper">
                                <div class="influencer-search-item">
                                    <div class="influencer-search-item-title">Gender</div>
                                    <?= self::select_filter('gender', false, 'Select Gender', $influencer_search_fields['gender'] ?? '', 'checkbox', true) ?>
                                </div>
                                <div class="influencer-search-item">
                                    <div class="influencer-search-item-title">Hashtags Used</div>
                                    <?= self::select_filter('content_tag', false, 'Search hashtags...', $influencer_search_fields['content_tag'] ?? '', 'checkbox', true) ?>
                                </div>
                            </div>
                            <div class="influencer-search-item checkbox-row filtered-search-checkboxes">
                                <?= self::checkbox_filter('filter', false, $influencer_search_fields['filter'] ?? '') ?>
                            </div>
                        </div>
                    </div>

                    <div class="influencer-search-item influencer-search-item-wrapper influencer-search-item-field full-brief-search <?= $is_brief_active ? 'active' : '' ?>">
                        <?php
                        $quality_copy = function_exists('creatordb_brief_quality_copy') ? creatordb_brief_quality_copy() : [];
                        $helper_hint  = $quality_copy['helper_hint'] ?? 'Describe the creators, audience and campaign goals you care about. Include topic, location, follower size, engagement or expertise where relevant.';
                        $examples_heading = $quality_copy['examples_heading'] ?? 'Good brief examples';
                        $example_cards = $quality_copy['example_cards'] ?? [
                            ['label' => 'Endometriosis campaign', 'text' => 'UK Instagram creators talking about endometriosis with good engagement'],
                            ['label' => 'Fertility education', 'text' => 'Fertility experts discussing IVF, egg freezing and TTC, prioritising educational content'],
                            ['label' => 'PCOS & hormone health', 'text' => 'PCOS and hormone balance creators who support fertility journeys, US-based, professional experts only'],
                        ];
                        ?>
                        <textarea rows="3" name="search-brief" id="search-brief" placeholder="<?= esc_attr($helper_hint) ?>" <?= $is_brief_active ? 'required' : '' ?>><?= esc_html($brief) ?></textarea>
                        <div id="brief-quality-notice" class="brief-quality-notice-holder" aria-live="polite"></div>
                        <?php if (! empty($example_cards) && is_array($example_cards)) : ?>
                            <div class="brief-example-cards">
                                <p class="brief-example-cards__heading"><?= esc_html($examples_heading) ?></p>
                                <div class="brief-example-cards__grid" aria-label="<?= esc_attr($examples_heading) ?>">
                                    <?php foreach ($example_cards as $card) :
                                        if (! is_array($card) || empty($card['text'])) {
                                            continue;
                                        }
                                        $label = $card['label'] ?? '';
                                    ?>
                                        <div class="brief-example-card">
                                            <?php if ($label !== '') : ?>
                                                <span class="brief-example-card__label"><?= esc_html($label) ?></span>
                                            <?php endif; ?>
                                            <p class="brief-example-card__text"><?= esc_html($card['text']) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="influencer-search-item" style="display: flex; justify-content: space-between; flex-direction: row">
                        <button type="button" class="reset-filters-btn elementor-button elementor-button-outline elementor-size-sm">
                            <span class="elementor-button-content-wrapper">
                                <span class="elementor-button-icon elementor-align-icon-left"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                        <path d="M3 3v5h5" />
                                    </svg></span>
                                <span class="elementor-button-text">RESET ALL</span>
                            </span>
                        </button>
                        <button type="submit" class="influencer-search-button elementor-button elementor-button-link elementor-size-sm">
                            <span class="elementor-button-content-wrapper">
                                <span class="elementor-button-icon elementor-align-icon-left">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="37.295" height="33.001" viewBox="0 0 37.295 33.001">
                                        <g id="search" transform="translate(-10.238 -12.501)">
                                            <path id="Path_327" data-name="Path 327" d="M65.046,13.082a.62.62,0,0,1,1.238,0,4.667,4.667,0,0,0,4.047,4.047.62.62,0,0,1,0,1.238,4.667,4.667,0,0,0-4.047,4.047.62.62,0,0,1-1.238,0A4.667,4.667,0,0,0,61,18.367a.62.62,0,0,1,0-1.238A4.667,4.667,0,0,0,65.046,13.082Z" transform="translate(-27.197)" />
                                            <path id="Path_328" data-name="Path 328" d="M79.915,35.84a.476.476,0,0,1,.454-.422.482.482,0,0,1,.458.422,2.978,2.978,0,0,0,2.512,2.41.455.455,0,0,1,0,.907,2.972,2.972,0,0,0-2.515,2.517.456.456,0,0,1-.909,0,2.976,2.976,0,0,0-2.41-2.514.458.458,0,0,1,0-.912,2.864,2.864,0,0,0,2.408-2.408Z" transform="translate(-36.23 -12.421)" />
                                            <path id="Path_329" data-name="Path 329" d="M25.587,49.116A10.492,10.492,0,1,0,16.512,43.9a1.017,1.017,0,0,1-.136,1.233l-5.58,5.58a1.908,1.908,0,1,0,2.7,2.7l5.58-5.578a1.016,1.016,0,0,1,1.233-.136,10.443,10.443,0,0,0,5.278,1.421Zm0-3.816a6.68,6.68,0,1,0-6.679-6.679A6.678,6.678,0,0,0,25.587,45.3Z" transform="translate(0 -8.468)" fill-rule="evenodd" />
                                        </g>
                                    </svg>
                                </span>
                                <span class="elementor-button-text"><?= esc_html($btn_text) ?></span></span>
                        </button>
                    </div>
                </div>
            </form>
<?php
        }

        return ob_get_clean();
    }
}

// Initialize the class
new Influencer_Search();
