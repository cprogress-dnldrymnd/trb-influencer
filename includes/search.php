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


        // Register Search Shortcodes
        add_shortcode('influencer_search_filter', [$this, 'shortcode_influencer_search_filter']);
        add_shortcode('influencer_search_filter_main', [$this, 'shortcode_influencer_search_filter_main']);
        add_shortcode('influencer_search_summary', [$this, 'shortcode_influencer_search_summary']);
        add_shortcode('influencer_match_score', [$this, 'shortcode_influencer_match_score']);
        add_shortcode('saved_search_url', [$this, 'shortcode_saved_search_url']);
    }

    // ========================================================================
    // 3. MATCH SCORE LOGIC
    // ========================================================================

    public static function calculate_match_score($post_id, $criteria)
    {
        if (function_exists('creatordb_calculate_match_score')) {
            return creatordb_calculate_match_score($post_id, $criteria);
        }

        if (!$post_id || get_post_type($post_id) !== 'influencer') return -1;
        if (!is_array($criteria) || (empty($criteria['niche']) && empty($criteria['platform']) && empty($criteria['country']) && empty($criteria['followers']) && empty($criteria['topic']) && empty($criteria['content_tag']))) return -1;

        $earned = 0;
        $max_poss = 0;
        $content_terms = array_unique(array_filter(array_merge((array)($criteria['niche'] ?? []), (array)($criteria['topic'] ?? []), (array)($criteria['content_tag'] ?? []))));

        if (!empty($content_terms)) {
            $max_poss += 40;
            $influencer_slugs = [];
            foreach (['niche', 'topic', 'content_tag'] as $tax) {
                foreach (wp_get_post_terms($post_id, $tax) as $t) {
                    $influencer_slugs[] = $t->slug;
                }
            }
            $matched = count(array_intersect($content_terms, $influencer_slugs));
            $earned += $matched > 0 ? (int) round(($matched / count($content_terms)) * 40) : 0;
        }

        if (!empty($criteria['country'])) {
            $max_poss += 30;
            $incountry = strtoupper(trim((string) get_post_meta($post_id, 'country', true)));
            $req = array_map('strtoupper', array_map('trim', (array) $criteria['country']));
            if ($incountry && in_array($incountry, $req, true)) $earned += 30;
        }

        if (!empty($criteria['platform'])) {
            $max_poss += 15;
            $platforms = wp_get_post_terms($post_id, 'platform');
            foreach ($platforms as $t) {
                if (in_array($t->slug, (array) $criteria['platform'], true)) {
                    $earned += 15;
                    break;
                }
            }
        }

        if (!empty($criteria['followers']) && !empty($criteria['followers'][0])) {
            $max_poss += 10;
            $f = (int) get_post_meta($post_id, 'followers', true);
            $range = $criteria['followers'][0];
            if (strpos($range, '-') !== false) {
                $parts = explode('-', $range);
                $min = isset($parts[0]) ? (int) $parts[0] : 0;
                $max = isset($parts[1]) ? (int) str_replace('+', '', $parts[1]) : PHP_INT_MAX;
                if ($f >= $min && $f <= $max) $earned += 10;
            } else {
                if ($f >= (int)$range) $earned += 10;
            }
        }

        if (!empty($criteria['filter']) && in_array('Prioritise engagement over reach', (array) $criteria['filter'], true)) {
            $max_poss += 5;
            $pct = ((float) get_post_meta($post_id, 'engagerate', true)) * 100;
            if ($pct > 0) $earned += min(5, round(($pct / 20) * 5, 0));
        }

        if (!empty($criteria['filter']) && in_array('Include only verified influencers', (array) $criteria['filter'], true)) {
            $max_poss += 5;
            if (get_post_meta($post_id, 'isverified', true)) $earned += 5;
        }

        if ($max_poss <= 0) return -1;
        return max(50, min(100, (int) round(($earned / $max_poss) * 100)));
    }

    public static function get_matched_criteria_labels($post_id, $criteria)
    {
        if (function_exists('creatordb_get_match_evidence_html')) {
            return creatordb_get_match_evidence_html($post_id, $criteria);
        }

        $phrases = [];
        if (!$post_id || !is_array($criteria)) return $phrases;

        $content_terms = array_unique(array_filter(array_merge((array)($criteria['niche'] ?? []), (array)($criteria['topic'] ?? []), (array)($criteria['content_tag'] ?? []))));
        if (!empty($content_terms)) {
            $influencer_slugs = [];
            foreach (['niche', 'topic', 'content_tag'] as $tax) {
                foreach (wp_get_post_terms($post_id, $tax) as $t) $influencer_slugs[] = $t->slug;
            }
            if (count(array_intersect($content_terms, $influencer_slugs)) > 0) {
                $phrases[] = '<span class="checklist">Frequently posts about topics related to your brief</span>';
            }
        }

        if (!empty($criteria['country'])) {
            $incountry = strtoupper(trim((string) get_post_meta($post_id, 'country', true)));
            $req = array_map('strtoupper', array_map('trim', (array) $criteria['country']));
            if ($incountry && in_array($incountry, $req, true)) {
                $phrases[] = '<span class="checklist">Audience demographics align well with your target</span>';
            }
        }

        if (!empty($criteria['platform'])) {
            foreach (wp_get_post_terms($post_id, 'platform') as $t) {
                if (in_array($t->slug, (array) $criteria['platform'], true)) {
                    $phrases[] = '<span class="checklist">Content style fits your campaign goals</span>';
                    break;
                }
            }
        }

        if (!empty($criteria['followers']) && !empty($criteria['followers'][0])) {
            $f = (int) get_post_meta($post_id, 'followers', true);
            $range = $criteria['followers'][0];
            $in_range = (strpos($range, '-') !== false)
                ? ($f >= (int)explode('-', $range)[0] && $f <= (int)str_replace('+', '', explode('-', $range)[1]))
                : ($f >= (int)$range);
            if ($in_range) $phrases[] = '<span class="checklist">Reach aligns with your campaign scope</span>';
        }

        if (!empty($criteria['filter']) && in_array('Prioritise engagement over reach', (array) $criteria['filter'], true)) {
            if ((float) get_post_meta($post_id, 'engagerate', true) > 0) $phrases[] = '<span class="checklist">Engagement levels suit this campaign type</span>';
        }

        if (!empty($criteria['filter']) && in_array('Include only verified influencers', (array) $criteria['filter'], true)) {
            if (get_post_meta($post_id, 'isverified', true)) $phrases[] = '<span class="checklist">Verified creator</span>';
        }

        return array_unique($phrases);
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
                'niche'     => isset($_GET['niche']) ? (array) $_GET['niche'] : [],
                'country'   => isset($_GET['country']) ? (array) $_GET['country'] : [],
                'followers' => isset($_GET['followers']) ? (array) $_GET['followers'] : [],
                'filter'    => isset($_GET['filter']) ? (array) $_GET['filter'] : [],
            ];
            $parsed = self::parse_search_brief(sanitize_textarea_field($_GET['search-brief']));
            $merged = self::merge_brief_with_explicit_filters($parsed, $explicit);
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
    // 4. SHORTCODES
    // ========================================================================

    public function shortcode_influencer_search_filter()
    {
        ob_start();
        $raw_fields = get_query_var('influencer_search_fields');
        $influencer_search_fields = is_array($raw_fields) ? $raw_fields : [];
        $influencer_search_page = get_query_var('influencer_search_page');
        $form_action = $influencer_search_page ? get_the_permalink($influencer_search_page) : '';
    ?>
        <form class="influencer-search" action="<?= esc_url($form_action) ?>" method="GET">
            <div class="influencer-search-filter-holder">
                <div class="influencer-search-item niche-filters">
                    <?= self::select_filter('niche', 'Tag Filter', 'Select your tag filters', $influencer_search_fields['niche'] ?? '', 'checkbox', true) ?>
                </div>
                <div class="influencer-search-item">
                    <?= self::select_filter('min_followers', 'Minimum Followers', 'Select Minimum Followers', $influencer_search_fields['followers'] ?? '', 'radio') ?>
                </div>
                <div class="influencer-search-item">
                    <?= self::select_filter('max_followers', 'Maximum Followers', 'Select Maximum Followers', $influencer_search_fields['followers'] ?? '', 'radio') ?>
                </div>
                <div class="influencer-search-item">
                    <?= self::select_filter('country', 'Location', 'Select a new location', $influencer_search_fields['country'] ?? '', 'checkbox', true) ?>
                </div>
                <div class="influencer-search-item">
                    <?= self::select_filter('lang', 'Language', 'Select a new language', $influencer_search_fields['lang'] ?? '', 'checkbox', true) ?>
                </div>
                <div class="influencer-search-item">
                    <?= self::checkbox_filter('filter', false, $influencer_search_fields['filter'] ?? '') ?>
                </div>
                <div class="influencer-search-item">
                    <button type="submit" class="influencer-search-button influencer-search-trigger elementor-button elementor-button-link elementor-size-sm">
                        <span class="elementor-button-content-wrapper"><span class="elementor-button-text">REFINE SEARCH</span></span>
                    </button>
                </div>
                <div class="influencer-search-item">
                    <div class="save-this-search"><span class="save-this-search-button save-search-trigger">Save this search</span></div>
                </div>
            </div>
        </form>
    <?php
        return ob_get_clean();
    }

    public function shortcode_influencer_search_filter_main()
    {
        ob_start();
        $raw_fields = get_query_var('influencer_search_fields');
        $influencer_search_fields = is_array($raw_fields) ? $raw_fields : [];
        $influencer_search_page = get_query_var('influencer_search_page');
        $form_action = $influencer_search_page ? get_the_permalink($influencer_search_page) : '';
        $brief = isset($_GET['search-brief']) ? trim(sanitize_textarea_field(wp_unslash($_GET['search-brief']))) : '';

        // LOGIC: If a brief exists in the URL, Full Brief Search is active (Toggle Checked).
        // Otherwise, Filtered Search is the default (Toggle Unchecked).
        $is_brief_active = !empty($brief);
        $checked_attr = $is_brief_active ? 'checked="checked"' : '';
    ?>
        <form class="influencer-search influencer-search-main" action="<?= esc_url($form_action) ?>" method="GET">
            <!-- START CLEANED TOGGLE HEADER -->
            <div id="search-header">
                <!-- 1. FILTERED SEARCH -->
                <div class="filtered-search toggle-text <?= !$is_brief_active ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="23.66" height="20" viewBox="0 0 23.66 20">
                        <path id="target" d="M24.044,20.152A10.187,10.187,0,0,1,24.1,21.2,10,10,0,1,1,19.973,13.1l-.745,2.778a7.375,7.375,0,1,0,2.037,3.527l2.777.744ZM13.436,21.579a.764.764,0,0,0,1.045.278l6.549-3.781,2.312.619,4.414-2.549-3.356-.9.9-3.356-4.414,2.549-.619,2.312-6.551,3.782a.764.764,0,0,0-.278,1.045Zm.661-3.032a2.671,2.671,0,0,1,.518.05L17.2,17.106a5.132,5.132,0,1,0,2.03,4.089,5.173,5.173,0,0,0-.04-.641l-2.582,1.491a2.649,2.649,0,1,1-2.51-3.5Z" transform="translate(-4.097 -11.195)" fill="#00a6ed" fill-rule="evenodd"></path>
                    </svg>
                    <span>FILTERED SEARCH</span>
                </div>

                <!-- 2. TOGGLE SWITCH -->
                <div class="toggle-html">
                    <label class="toggle-switch">
                        <input type="checkbox" id="my-toggle" <?= $checked_attr ?>>
                        <span class="slider round"></span>
                    </label>
                </div>

                <!-- 3. FULL BRIEF SEARCH -->
                <div class="full-brief-search toggle-text <?= $is_brief_active ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 46.322 46.948">
                        <path id="sparkers" d="M15.96,24.3a.809.809,0,0,0,.851-.751c.9-6.685,1.127-6.685,8.038-8.012a.847.847,0,0,0,.776-.851.864.864,0,0,0-.776-.851c-6.911-.951-7.161-1.177-8.038-7.987a.84.84,0,0,0-1.678.025c-.826,6.71-1.177,6.685-8.037,7.962a.884.884,0,0,0-.776.851c0,.5.326.776.876.851,6.811,1.1,7.111,1.277,7.937,7.962A.811.811,0,0,0,15.96,24.3ZM32.937,52.02a1.289,1.289,0,0,0,1.252-1.152c1.778-13.721,3.706-15.8,17.277-17.3a1.256,1.256,0,0,0,1.177-1.252,1.274,1.274,0,0,0-1.177-1.252c-13.571-1.5-15.5-3.581-17.277-17.3a1.266,1.266,0,0,0-1.252-1.127,1.225,1.225,0,0,0-1.227,1.127c-1.778,13.721-3.731,15.8-17.277,17.3a1.277,1.277,0,0,0-1.2,1.252,1.26,1.26,0,0,0,1.2,1.252c13.521,1.778,15.4,3.606,17.277,17.3A1.248,1.248,0,0,0,32.937,52.02Z" transform="translate(-6.32 -5.073)" fill="#ffe17b"></path>
                    </svg>
                    <span>FULL BRIEF SEARCH</span>
                </div>

            </div>
            <!-- END CLEANED TOGGLE HEADER -->
            <div class="influencer-search-filter-holder">

                <input type="hidden" value="true" name="search_active">

                <!-- 1. FILTERED SEARCH CONTAINER -->
                <div class="influencer-search-item-row influencer-search-item-wrapper filtered-search <?= !$is_brief_active ? 'active' : '' ?>">
                    <div class="influencer-search-item">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Location</div>
                        <?= self::select_filter('country', false, 'Location', $influencer_search_fields['country'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Language</div>
                        <?= self::select_filter('lang', false, 'Language', $influencer_search_fields['lang'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item required-on-search">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Niche</div>
                        <?= self::select_filter('niche', false, 'Niche', $influencer_search_fields['niche'] ?? '', 'checkbox', true) ?>
                    </div>
                    <div class="influencer-search-item">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Follower Count</div>
                        <div class="field-groups">
                            <?= self::select_filter('min_followers', false, 'Minimum', $influencer_search_fields['followers'] ?? '', 'radio') ?>
                            <?= self::select_filter('max_followers', false, 'Maximum', $influencer_search_fields['followers'] ?? '', 'radio') ?>
                        </div>
                    </div>
                </div>

                <!-- 2. FULL BRIEF SEARCH CONTAINER -->
                <div class="influencer-search-item influencer-search-item-wrapper influencer-search-item-field full-brief-search <?= $is_brief_active ? 'active' : '' ?>">
                    <textarea rows="6" name="search-brief" id="search-brief" placeholder="Type or paste your campaign brief..." <?= $is_brief_active ? 'required' : '' ?>><?= esc_html($brief) ?></textarea>
                </div>

                <!-- Checkboxes & Buttons -->
                <div class="influencer-search-item checkbox-row">
                    <?= self::checkbox_filter('filter', false, $influencer_search_fields['filter'] ?? '') ?>
                </div>
                <div class="influencer-search-item" style="display: flex; justify-content: space-between">
                    <button type="button" class="reset-filters-btn elementor-button elementor-button-outline elementor-size-sm">
                        <span class="elementor-button-content-wrapper"><span class="elementor-button-text">RESET ALL</span></span>
                    </button>
                    <button type="submit" class="influencer-search-button elementor-button elementor-button-link elementor-size-sm">
                        <span class="elementor-button-content-wrapper"><span class="elementor-button-text">GENERATE MATCHES</span></span>
                    </button>
                </div>
            </div>
        </form>
<?php
        return ob_get_clean();
    }

      public function shortcode_influencer_search_summary()
    {
        global $search_results_page_id;
        if ((int) get_queried_object_id() !== $search_results_page_id) return '';

        $brief = isset($_GET['search-brief']) ? trim(sanitize_textarea_field(wp_unslash($_GET['search-brief']))) : '';
        $niche = isset($_GET['niche']) ? (array) $_GET['niche'] : [];
        $country = isset($_GET['country']) ? (array) $_GET['country'] : [];
        $followers = isset($_GET['followers']) ? (array) $_GET['followers'] : [];
        $filter = isset($_GET['filter']) ? (array) $_GET['filter'] : [];

        if (empty($brief) && empty($niche) && empty($country) && empty($followers)) return '';
        $fields = is_array(get_query_var('influencer_search_fields')) ? get_query_var('influencer_search_fields') : [];

        $parts = [];
        if (!empty($niche)) {
            $niche_names = [];
            foreach ($niche as $slug) $niche_names[] = $fields['niche'][$slug] ?? ucfirst($slug);
            $parts[] = implode(', ', $niche_names);
        }
        if (!empty($country)) {
            $country_names = [];
            foreach ($country as $code) $country_names[] = $fields['country'][$code] ?? strtoupper($code);
            $parts[] = implode(', ', $country_names);
        }
        if (!empty($followers) && !empty($followers[0])) {
            $f_opts = $fields['followers'] ?? [];
            $parts[] = $f_opts[$followers[0]] ?? $followers[0];
        }

        $prioritise_engagement = in_array('Prioritise engagement over reach', $filter, true);
        $engagement_boost_soft = false;
        if (!empty($brief) && function_exists('creatordb_parse_search_brief_structured')) {
            $structured_summary = creatordb_parse_search_brief_structured($brief);
            if (!empty($structured_summary['soft_intents']['engagement_boost'])) {
                $engagement_boost_soft = true;
            }
        }
        $verified_only = in_array('Include only verified influencers', $filter, true);
        $expert_only = in_array('Professional experts only', $filter, true);

        ob_start();
    ?>
        <div class="influencer-search-summary">
            <?php if (!empty($brief)): ?>
                <div class="search-summary-brief search-summary-item">
                    <input type="hidden" name="search-brief" id="search-brief" value="<?= esc_attr($brief) ?>">
                    <div class="summary-brief-label">Your brief:</div>
                    <div class="summary-brief">
                        <div class="summary-brief-inner"><?= wpautop(esc_html(wp_trim_words($brief, 25))) ?></div>
                    </div>
                    <a class="edit-summary-brieft" href="<?= get_the_permalink(2149) ?>?search-brief=<?= urlencode($brief) ?>">EDIT BRIEF</a>
                </div>
            <?php endif; ?>
            <?php if (!empty($parts) && empty($brief)): ?>
                <div class="search-summary-item search-summary-filters"><strong>Filters:</strong> <?= esc_html(implode(' • ', $parts)) ?></div>
            <?php endif; ?>
            <?php if ($prioritise_engagement || $engagement_boost_soft || $verified_only || $expert_only): ?>
                <div class="search-summary-item search-summary-notes">
                    <?php
                    $notes = [];
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
            <?php if (function_exists('creatordb_brief_search_debug_enabled') && creatordb_brief_search_debug_enabled()) : ?>
                <div id="ic-brief-search-debug" class="ic-brief-search-debug" aria-live="polite">
                    <details open>
                        <summary>Brief search debug (dev)</summary>
                        <p class="ic-brief-search-debug-hint">Runs after each search. Requires <code>WP_DEBUG</code> or <code>IC_BRIEF_SEARCH_DEBUG</code> in wp-config.</p>
                        <pre class="ic-brief-search-debug-body">Waiting for search AJAX…</pre>
                    </details>
                </div>
                <style>
                    .ic-brief-search-debug { margin: 1rem 0; padding: 0.75rem 1rem; background: #1e1e2e; color: #cdd6f4; border-radius: 8px; font-size: 12px; }
                    .ic-brief-search-debug summary { cursor: pointer; font-weight: 600; color: #89b4fa; }
                    .ic-brief-search-debug-hint { opacity: 0.85; margin: 0.5rem 0; }
                    .ic-brief-search-debug-body { max-height: 420px; overflow: auto; white-space: pre-wrap; word-break: break-word; margin: 0; }
                </style>
            <?php endif; ?>
        </div>
<?php
        return ob_get_clean();
    }

    public function shortcode_influencer_match_score()
    {
        $post_id  = get_query_var('current_influencer_id') ?: get_the_ID();
        $criteria = get_query_var('search_criteria');
        $criteria = is_array($criteria) ? $criteria : [];
        $score    = self::calculate_match_score($post_id, $criteria);

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
            : implode("\n", self::get_matched_criteria_labels($post_id, $criteria));

        $html = '<div class="influencer-match-score-wrap tooltip-wrapper"><span class="influencer-match-score-trigger tooltip-trigger">' . esc_html($badge_label) . '</span>';
        if ($tooltip !== '') {
            $html .= '<div class="influencer-match-score-tooltip"><span class="influencer-match-score-checklist">' . $tooltip . '</span></div>';
        }
        $html .= '</div>';
        return $html;
    }

    public function shortcode_saved_search_url()
    {
        global $search_results_page_id;
        $search_query = get_field('search_query', get_the_ID());
        return get_the_permalink($search_results_page_id) . $search_query . '&search_active=true';
    }

    // ========================================================================
    // 5. AJAX LOOP FILTER
    // ========================================================================

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
        $parsed_brief = null;
        if (!empty($brief_text)) {
            $parsed_brief = self::parse_search_brief($brief_text);
            $explicit = self::merge_brief_with_explicit_filters($parsed_brief, $explicit);
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
    // 6. BRIEF PARSER LOGIC
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

        $keys = ['niche', 'country', 'platform', 'followers', 'filter', 'topic', 'content_tag'];

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
}

// Initialize the class
new Influencer_Search();
