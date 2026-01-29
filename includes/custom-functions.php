<?php
function my_custom_variable_setup()
{
    $niche = get_terms(array(
        'taxonomy'   => 'niche',
        'hide_empty' => false,
    ));

    foreach ($niche as $term) {
        $niche_options[$term->slug] = $term->name;
    }

    $platform = get_terms(array(
        'taxonomy'   => 'platform',
        'hide_empty' => false,
    ));

    foreach ($platform as $term) {
        $platform_options[$term->slug] = $term->name;
    }
    $followers_options = array(
        '1000-10000' => '1K - 10K',
        '10000-50000' => '10K - 50K',
        '50000-250000' => '50K - 250K',
        '250000-1000000' => '250K - 1M',
        '1000000-10000000' => '1M-10M',
        '10000000+' => '10M+',
    );
    $country_options = get_unique_influencer_countries();

    $lang_options = get_unique_influencer_languages();

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
    );

    $influencer_search_fields['niche'] = $niche_options;
    $influencer_search_fields['platform'] = $platform_options;
    $influencer_search_fields['followers'] = $followers_options;
    $influencer_search_fields['country'] = $country_options;
    $influencer_search_fields['lang'] = $lang_options;
    $influencer_search_fields['gender'] = $gender_options;
    $influencer_search_fields['age'] = $age_options;
    $influencer_search_fields['filter'] = $filter_options;

    $influencer_search_page = 1949;

    set_query_var('influencer_search_fields', $influencer_search_fields);
    set_query_var('influencer_search_page', $influencer_search_page);
}
add_action('wp', 'my_custom_variable_setup');
/**
 * 1. Track Recently Viewed 'Influencer' Posts (Database Only)
 */
function track_recently_viewed_influencers()
{
    // 1. Check if it is the 'influencer' post type
    // 2. Check if the user is logged in
    if (! is_singular('influencer') || ! is_user_logged_in()) {
        return;
    }

    global $post;
    $current_post_id = $post->ID;
    $user_id         = get_current_user_id();
    $meta_key        = 'recently_viewed_influencers';
    $limit           = 5; // Max items to save

    // Get current list from DB
    $viewed_posts = get_user_meta($user_id, $meta_key, true);

    if (! is_array($viewed_posts)) {
        $viewed_posts = array();
    }

    // Remove current ID if it exists (to prevent duplicates and move to top)
    if (($key = array_search($current_post_id, $viewed_posts)) !== false) {
        unset($viewed_posts[$key]);
    }

    // Add current ID to the beginning of the array
    array_unshift($viewed_posts, $current_post_id);

    // Slice array to keep only the limit
    $viewed_posts = array_slice($viewed_posts, 0, $limit);

    // Save back to DB
    update_user_meta($user_id, $meta_key, $viewed_posts);
}
add_action('template_redirect', 'track_recently_viewed_influencers');



/**
 * 2. Shortcode: Output IDs as comma-separated string
 * Usage: [recent_influencer_ids limit="5"] 
 * Output Example: "102, 45, 305"
 */
function get_recent_influencer_ids_array($limit = 5)
{
    if (! is_user_logged_in()) {
        return array();
    }

    $user_id    = get_current_user_id();
    $meta_key   = 'recently_viewed_influencers';
    $viewed_ids = get_user_meta($user_id, $meta_key, true);

    if (empty($viewed_ids) || ! is_array($viewed_ids)) {
        return array();
    }

    // Slice to the requested limit
    return array_slice($viewed_ids, 0, $limit);
}


/**
 * Converts a number to a short metric format (e.g., 1.1K, 1.5M).
 *
 * @param int|float $number The number to format.
 * @param int       $precision Optional. The number of decimal places. Default 1.
 * @return string The formatted number with suffix.
 */
function wp_custom_number_format_short($number, $precision = 1)
{
    // Return 0 immediately if input is empty or zero
    if (empty($number)) {
        return '0';
    }

    // Define suffixes
    $suffixes = array(
        12 => 'T', // Trillion
        9  => 'B', // Billion
        6  => 'M', // Million
        3  => 'K', // Thousand
        0  => '',  // None
    );

    // Loop through suffixes to find the correct range
    foreach ($suffixes as $exponent => $suffix) {
        if (abs($number) >= pow(10, $exponent)) {
            // Divide number by the exponent value
            $display = $number / pow(10, $exponent);

            // Format number to specified precision
            $formatted = number_format($display, $precision);

            // Remove ".0" or ".00" if the decimal is zero (cleaner look)
            // e.g., turns "10.0K" into "10K"
            $formatted = str_replace('.0', '', $formatted);
            $formatted = str_replace('.00', '', $formatted); // Just in case precision is 2

            return $formatted . $suffix;
        }
    }

    // Fallback for numbers smaller than 1000
    return number_format($number);
}



/**
 * Helper function: Map 3-letter codes to 2-letter codes
 */
function iso_alpha3_to_alpha2($alpha3)
{
    $mapping = array(
        'afg' => 'af',
        'alb' => 'al',
        'dza' => 'dz',
        'asm' => 'as',
        'and' => 'ad',
        'ago' => 'ao',
        'aia' => 'ai',
        'ata' => 'aq',
        'atg' => 'ag',
        'arg' => 'ar',
        'arm' => 'am',
        'abw' => 'aw',
        'aus' => 'au',
        'aut' => 'at',
        'aze' => 'az',
        'bhs' => 'bs',
        'bhr' => 'bh',
        'bgd' => 'bd',
        'brb' => 'bb',
        'blr' => 'by',
        'bel' => 'be',
        'blz' => 'bz',
        'ben' => 'bj',
        'bmu' => 'bm',
        'btn' => 'bt',
        'bol' => 'bo',
        'bes' => 'bq',
        'bih' => 'ba',
        'bwa' => 'bw',
        'bvt' => 'bv',
        'bra' => 'br',
        'iot' => 'io',
        'brn' => 'bn',
        'bgr' => 'bg',
        'bfa' => 'bf',
        'bdi' => 'bi',
        'cpv' => 'cv',
        'khm' => 'kh',
        'cmr' => 'cm',
        'can' => 'ca',
        'cym' => 'ky',
        'caf' => 'cf',
        'tcd' => 'td',
        'chl' => 'cl',
        'chn' => 'cn',
        'cxr' => 'cx',
        'cck' => 'cc',
        'col' => 'co',
        'com' => 'km',
        'cod' => 'cd',
        'cog' => 'cg',
        'cok' => 'ck',
        'cri' => 'cr',
        'hrv' => 'hr',
        'cub' => 'cu',
        'cuw' => 'cw',
        'cyp' => 'cy',
        'cze' => 'cz',
        'dnk' => 'dk',
        'dji' => 'dj',
        'DMA' => 'dm',
        'dom' => 'do',
        'ecu' => 'ec',
        'egy' => 'eg',
        'slv' => 'sv',
        'gnq' => 'gq',
        'eri' => 'er',
        'est' => 'ee',
        'eth' => 'et',
        'flk' => 'fk',
        'fro' => 'fo',
        'fji' => 'fj',
        'fin' => 'fi',
        'fra' => 'fr',
        'guf' => 'gf',
        'pyf' => 'pf',
        'atf' => 'tf',
        'gab' => 'ga',
        'gmb' => 'gm',
        'geo' => 'ge',
        'deu' => 'de',
        'gha' => 'gh',
        'gib' => 'gi',
        'grc' => 'gr',
        'grl' => 'gl',
        'grd' => 'gd',
        'glp' => 'gp',
        'gum' => 'gu',
        'gtm' => 'gt',
        'ggy' => 'gg',
        'gin' => 'gn',
        'gnb' => 'gw',
        'guy' => 'gy',
        'hti' => 'ht',
        'hmd' => 'hm',
        'vat' => 'va',
        'hnd' => 'hn',
        'hkg' => 'hk',
        'hun' => 'hu',
        'isl' => 'is',
        'ind' => 'in',
        'idn' => 'id',
        'irn' => 'ir',
        'irq' => 'iq',
        'irl' => 'ie',
        'imn' => 'im',
        'isr' => 'il',
        'ita' => 'it',
        'jam' => 'jm',
        'jpn' => 'jp',
        'jey' => 'je',
        'jor' => 'jo',
        'kaz' => 'kz',
        'ken' => 'ke',
        'kir' => 'ki',
        'prk' => 'kp',
        'kor' => 'kr',
        'kwt' => 'kw',
        'kgz' => 'kg',
        'lao' => 'la',
        'lva' => 'lv',
        'lbn' => 'lb',
        'lso' => 'ls',
        'lbr' => 'lr',
        'lby' => 'ly',
        'lie' => 'li',
        'ltu' => 'lt',
        'lux' => 'lu',
        'mac' => 'mo',
        'mkd' => 'mk',
        'mdg' => 'mg',
        'mwi' => 'mw',
        'mys' => 'my',
        'mdv' => 'mv',
        'mli' => 'ml',
        'mlt' => 'mt',
        'mhl' => 'mh',
        'mtq' => 'mq',
        'mrt' => 'mr',
        'mus' => 'mu',
        'myt' => 'yt',
        'mex' => 'mx',
        'fsm' => 'fm',
        'mda' => 'md',
        'mco' => 'mc',
        'mng' => 'mn',
        'mne' => 'me',
        'msr' => 'ms',
        'mar' => 'ma',
        'moz' => 'mz',
        'mmr' => 'mm',
        'nam' => 'na',
        'nru' => 'nr',
        'npl' => 'np',
        'nld' => 'nl',
        'ncl' => 'nc',
        'nzl' => 'nz',
        'nic' => 'ni',
        'ner' => 'ne',
        'nga' => 'ng',
        'niu' => 'nu',
        'nfk' => 'nf',
        'mnp' => 'mp',
        'nor' => 'no',
        'omn' => 'om',
        'pak' => 'pk',
        'plw' => 'pw',
        'pse' => 'ps',
        'pan' => 'pa',
        'png' => 'pg',
        'pry' => 'py',
        'per' => 'pe',
        'phl' => 'ph',
        'pcn' => 'pn',
        'pol' => 'pl',
        'prt' => 'pt',
        'pri' => 'pr',
        'qat' => 'qa',
        'rou' => 'ro',
        'rus' => 'ru',
        'rwa' => 'rw',
        'reu' => 're',
        'blm' => 'bl',
        'shn' => 'sh',
        'kna' => 'kn',
        'lca' => 'lc',
        'maf' => 'mf',
        'spm' => 'pm',
        'vct' => 'vc',
        'wsm' => 'ws',
        'smr' => 'sm',
        'stp' => 'st',
        'sau' => 'sa',
        'sen' => 'sn',
        'srb' => 'rs',
        'syc' => 'sc',
        'sle' => 'sl',
        'sgp' => 'sg',
        'sxm' => 'sx',
        'svk' => 'sk',
        'svn' => 'si',
        'slb' => 'sb',
        'som' => 'so',
        'zaf' => 'za',
        'dgs' => 'gs',
        'ssd' => 'ss',
        'esp' => 'es',
        'lka' => 'lk',
        'sdn' => 'sd',
        'sur' => 'sr',
        'sjm' => 'sj',
        'swz' => 'sz',
        'swe' => 'se',
        'che' => 'ch',
        'syr' => 'sy',
        'twn' => 'tw',
        'tjk' => 'tj',
        'tza' => 'tz',
        'tha' => 'th',
        'tls' => 'tl',
        'tgo' => 'tg',
        'tkl' => 'tk',
        'ton' => 'to',
        'tto' => 'tt',
        'tun' => 'tn',
        'tur' => 'tr',
        'tkm' => 'tm',
        'tca' => 'tc',
        'tuv' => 'tv',
        'uga' => 'ug',
        'ukr' => 'ua',
        'are' => 'ae',
        'gbr' => 'gb',
        'usa' => 'us',
        'umi' => 'um',
        'ury' => 'uy',
        'uzb' => 'uz',
        'vut' => 'vu',
        'ven' => 've',
        'vnm' => 'vn',
        'vgb' => 'vg',
        'vir' => 'vi',
        'wlf' => 'wf',
        'esh' => 'eh',
        'yem' => 'ye',
        'zmb' => 'zm',
        'zwe' => 'zw'
    );

    return isset($mapping[$alpha3]) ? $mapping[$alpha3] : false;
}

function select_filter($name, $label, $placeholder, $options = [], $type = 'checkbox', $has_search = false)
{
    // Check URL parameters for this field
    $selected_values = [];
    if (isset($_GET[$name])) {
        $selected_values = is_array($_GET[$name]) ? $_GET[$name] : array($_GET[$name]);
    }

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
                <span class="arrow-holder">
                    <span class="arrow"></span>
                </span>
            </div>

            <div class="dropdown-menu checkbox-lists">

                <?php /* --- NEW SEARCH FIELD --- */ ?>
                <?php if ($has_search): ?>
                    <div class="dropdown-search-container" style="padding: 10px;">
                        <input type="text" class="dropdown-search-input" placeholder="Search options..." style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                <?php endif; ?>
                <?php /* ------------------------ */ ?>

                <div class="options-list">
                    <?php foreach ($options as $key => $option) {
                        $is_checked = in_array((string)$key, $selected_values) ? 'checked="checked"' : '';
                    ?>
                        <label class="dropdown-item checkbox-list-item">
                            <input class="pseudo-checkbox-input" type="<?= $type ?>" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]" <?= $is_checked ?>> <span class="pseudo-checkbox"></span> <?= $option ?>
                        </label>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="tags-container"></div>
    </div>

<?php
    return ob_get_clean();
}

function checkbox_filter($name, $label, $options = [])
{
    // Check URL parameters for this field
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
                // Check if this specific key exists in the URL params
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


function radio_filter($name, $label, $options = [])
{
    // Check URL parameters for this field
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
                // Check if this specific key exists in the URL params
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
 * Get sorted array of unique countries from 'influencers' post type.
 * * Returns: array( 'alpha3' => 'Country Name' )
 */
function get_unique_influencer_countries()
{
    global $wpdb;

    // 1. Efficiently query only the unique meta values from the database
    // We join with the posts table to ensure we only get data from 'influencers' that are 'published'
    $results = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT pm.meta_value 
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.post_type = %s
        AND pm.meta_key = %s
        AND p.post_status = 'publish'
    ", 'influencer', 'country'));

    $country_list = array();

    // 2. Loop through results and format
    foreach ($results as $original_val) {

        // Ensure we match the lowercase keys in your mapping function
        $alpha3 = strtolower(trim($original_val));

        // Convert 3-letter to 2-letter using your helper function
        if (function_exists('iso_alpha3_to_alpha2')) {
            $alpha2 = iso_alpha3_to_alpha2($alpha3);
        } else {
            continue; // Skip if helper is missing
        }

        if ($alpha2) {
            // Convert 2-letter code to Full Name
            // We use PHP's native Locale class (requires php-intl extension, standard on most hosts)
            if (class_exists('Locale')) {
                $country_name = Locale::getDisplayRegion('-' . $alpha2, 'en');
            } elseif (class_exists('WC_Countries')) {
                // Fallback: If you have WooCommerce installed
                $wc_countries = new WC_Countries();
                $countries    = $wc_countries->get_countries();
                $country_name = isset($countries[strtoupper($alpha2)]) ? $countries[strtoupper($alpha2)] : $alpha2;
            } else {
                // Fallback: If no libraries exist, just use the code
                $country_name = strtoupper($alpha2);
            }

            // Populate Array: Key = Original 3-digit Meta, Value = Country Name
            $country_list[$original_val] = $country_name;
        }
    }

    // 3. Sort alphabetically by the Country Name (the array value)
    asort($country_list);

    return $country_list;
}

/**
 * Get sorted array of unique languages from 'influencers' post type.
 * Returns: array( 'meta_value' => 'Language Name' )
 */
function get_unique_influencer_languages()
{
    global $wpdb;

    // 1. Efficiently query only the unique meta values from the database
    // We check for 'influencers' post type and 'publish' status
    $results = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT pm.meta_value 
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.post_type = %s
        AND pm.meta_key = %s
        AND p.post_status = 'publish'
    ", 'influencer', 'lang'));

    $language_list = array();

    // 2. Loop through results and format
    foreach ($results as $lang_code) {

        $clean_code = trim($lang_code);

        // Convert Code to Full Language Name
        // We use PHP's native Locale class (requires php-intl extension)
        if (class_exists('Locale')) {
            // getDisplayLanguage converts 'en' -> 'English', 'tl' -> 'Tagalog', etc.
            $lang_name = Locale::getDisplayLanguage($clean_code, 'en');
        } else {
            // Fallback if intl extension is missing
            $lang_name = strtoupper($clean_code);
        }

        // Populate Array: Key = Original Meta Value, Value = Language Name
        // We check if name generation failed (returns same code) and try to clean it up visually
        if ($lang_name == $clean_code) {
            $lang_name = ucfirst($clean_code);
        }

        $language_list[$clean_code] = $lang_name;
    }

    // 3. Sort alphabetically by the Language Name (the array value)
    asort($language_list);

    return $language_list;
}

/**
 * Add --admin-bar-height CSS variable to body based on #wpadminbar height.
 * Recalculates on window resize.
 */
function set_admin_bar_height_variable()
{
    // Only run this if the admin bar is actually showing
    if (is_admin_bar_showing()) {
    ?>
        <script type="text/javascript">
            (function() {
                function updateAdminBarHeight() {
                    var adminBar = document.getElementById('wpadminbar');
                    var height = adminBar ? adminBar.offsetHeight : 0;

                    // Set the CSS variable on the body
                    document.body.style.setProperty('--admin-bar-height', height + 'px');
                }

                // Run on initial load
                window.addEventListener('DOMContentLoaded', updateAdminBarHeight);
                window.addEventListener('load', updateAdminBarHeight);

                // Run whenever the window is resized (as admin bar height changes on mobile)
                window.addEventListener('resize', updateAdminBarHeight);
            })();
        </script>
    <?php
    } else {
        // Optional: Set variable to 0px if admin bar is not present to prevent CSS errors
    ?>
        <script type="text/javascript">
            document.body.style.setProperty('--admin-bar-height', '0px');
        </script>
<?php
    }
}
add_action('wp_footer', 'set_admin_bar_height_variable');


function get_saved_influencer()
{
    if (! is_user_logged_in()) {
        return [];
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // 2. Direct SQL Query
    // This fetches the 'influencer_id' meta directly from the database 
    // for all 'saved-influencer' posts authored by the current user.
    // We skip 'get_posts' entirely to avoid conflicts/loops.
    $influencer_ids = $wpdb->get_col($wpdb->prepare("
        SELECT pm.meta_value 
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'saved-influencer'
        AND p.post_status = 'publish'
        AND p.post_author = %d
        AND pm.meta_key = 'influencer_id'
    ", $user_id));

    $ids = array_map('intval', $influencer_ids);

    return $ids;
}

function get_viewed_influencer()
{
    if (! is_user_logged_in()) {
        return [];
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // 2. Direct SQL Query
    // This fetches the 'influencer_id' meta directly from the database 
    // for all 'saved-influencer' posts authored by the current user.
    // We skip 'get_posts' entirely to avoid conflicts/loops.
    $influencer_ids = $wpdb->get_col($wpdb->prepare("
        SELECT pm.meta_value 
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'viewed-influencer'
        AND p.post_status = 'publish'
        AND p.post_author = %d
        AND pm.meta_key = 'influencer_id'
    ", $user_id));

    $ids = array_map('intval', $influencer_ids);

    return $ids;
}
/**
 * Retrieve a list of Post IDs purchased by the current user via myCred.
 *
 * This function queries the myCred log to find all 'buy_content' entries
 * for the current user and returns the IDs of the purchased posts.
 * It allows filtering by specific post types and optionally restricting
 * the results to the current month only.
 *
 * @param string $post_type          Optional. The post type to filter by (e.g., 'influencer', 'post'). Default 'influencer'.
 * @param bool   $current_month_only Optional. Whether to return only purchases made in the current month. Default false.
 *
 * @return array An array of purchased Post IDs (integers). Returns an empty array if none found.
 */
function get_user_purchased_post_ids($post_type = 'influencer', $current_month_only = false)
{
    global $wpdb;

    $user_id = get_current_user_id();

    // Define table names
    $mycred_log_table = $wpdb->prefix . 'myCRED_log';
    $posts_table      = $wpdb->prefix . 'posts';

    // Base SQL Query
    $query = "
        SELECT DISTINCT p.ID 
        FROM {$mycred_log_table} l
        INNER JOIN {$posts_table} p ON l.ref_id = p.ID
        WHERE l.user_id = %d 
        AND l.ref = 'buy_content' 
        AND p.post_type = %s
    ";

    // Prepare arguments array
    $args = array($user_id, $post_type);

    // Filter by Current Month if requested
    if ($current_month_only) {
        // Calculate start and end of the current month based on WP Timezone
        $start_of_month = strtotime('first day of this month 00:00:00', current_time('timestamp'));
        $end_of_month   = strtotime('last day of this month 23:59:59', current_time('timestamp'));

        $query .= " AND l.time BETWEEN %d AND %d";

        $args[] = $start_of_month;
        $args[] = $end_of_month;
    }

    // Execute Query
    $post_ids = $wpdb->get_col($wpdb->prepare($query, $args));

    return $post_ids;
}

/**
 * Track views on 'influencer' posts for logged-in users.
 * - Creates a new log if none exists.
 * - Updates the existing log (Modified Date & Title) if it already exists.
 */
function track_influencer_post_view()
{
    // 1. Check if user is logged in
    if (! is_user_logged_in()) {
        return;
    }

    // 2. Check if we are viewing a single 'influencer' post
    if (is_singular('influencer')) {

        $current_user_id = get_current_user_id();
        $influencer_id   = get_the_ID();

        // Prepare the timestamp and title
        $current_time = current_time('d-M-Y H:i:s');
        $post_title   = 'Viewed on ' . $current_time;

        // 3. Search for an EXISTING log entry for this User + Influencer combo
        $existing_log = get_posts(array(
            'post_type'      => 'viewed-influencer',
            'author'         => $current_user_id,
            'meta_key'       => 'influencer_id',
            'meta_value'     => $influencer_id,
            'posts_per_page' => 1,
            'fields'         => 'ids', // We only need the ID
            'post_status'    => 'any', // Check published, private, etc.
        ));

        if (! empty($existing_log)) {
            // --- UPDATE EXISTING ---
            // We found a log. Update the title and the modified time.
            $log_id = $existing_log[0];

            $update_data = array(
                'ID'            => $log_id,
                'post_title'    => $post_title,
                // Updating the post content or title automatically updates 'post_modified'.
                // If you strictly want to force update the 'post_date' (published date) as well, uncomment below:
                // 'post_date'     => current_time( 'mysql' ), 
                // 'post_date_gmt' => current_time( 'mysql', 1 ),
            );

            wp_update_post($update_data);
        } else {
            // --- CREATE NEW ---
            // No log found. Create a new one.
            $view_log_data = array(
                'post_title'  => $post_title,
                'post_type'   => 'viewed-influencer',
                'post_status' => 'publish',
                'post_author' => $current_user_id,
            );

            $new_log_id = wp_insert_post($view_log_data);

            if (! is_wp_error($new_log_id)) {
                update_post_meta($new_log_id, 'influencer_id', $influencer_id);
            }
        }
    }
}
add_action('template_redirect', 'track_influencer_post_view');


/**
 * specific function to get the ranking of niches based on user history.
 *
 * @param int $user_id The ID of the current user.
 * @return array Sorted array of niches with counts and percentages.
 */
function get_user_niche_ranking($user_id)
{

    // 1. RETRIEVE VIEWED IDS
    // Replace this line with however you are currently saving the data. 
    // Assuming you store it as an array of Post IDs in user meta:
    $viewed_influencers_ids = get_viewed_influencer();
    $saved_influencers_ids = get_saved_influencer();
    $purchased_influencers_ids = get_user_purchased_post_ids();

    $engage_influencers_ids = array_merge($viewed_influencers_ids, $saved_influencers_ids, $purchased_influencers_ids);

    // Safety check: if no history, return empty
    if (empty($engage_influencers_ids) || ! is_array($engage_influencers_ids)) {
        return [];
    }

    $niche_counts = [];
    $total_terms_found = 0;

    // 2. AGGREGATE TERMS
    foreach ($engage_influencers_ids as $post_id) {
        // Get terms for the custom taxonomy 'niche'
        $terms = get_the_terms($post_id, 'niche');

        if (! empty($terms) && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_id = $term->term_id;

                // Initialize if not set
                if (! isset($niche_counts[$term_id])) {
                    $niche_counts[$term_id] = [
                        'term_id' => $term_id,
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                        'count'   => 0,
                    ];
                }

                // Increment count
                $niche_counts[$term_id]['count']++;
                $total_terms_found++;
            }
        }
    }

    // 3. SORT (Desc by count)
    usort($niche_counts, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    // 4. CALCULATE PERCENTAGE
    // Useful if you want to display "45%" like in your screenshot
    foreach ($niche_counts as &$niche) {
        if ($total_terms_found > 0) {
            $niche['percentage'] = round(($niche['count'] / $total_terms_found) * 100, 1);
        } else {
            $niche['percentage'] = 0;
        }
    }

    return $niche_counts;
}

function test_sc()
{
    ob_start();
    $current_user_id = get_current_user_id();
    $ranked_niches = get_user_niche_ranking($current_user_id);

    // Example Output Loop
    if (! empty($ranked_niches)) {
        echo '<ul>';
        foreach ($ranked_niches as $niche) {
            echo '<li>' . $niche['name'] . ': ' . $niche['percentage'] . '% (' . $niche['count'] . ' views)</li>';
        }
        echo '</ul>';
    }
    return ob_get_clean();
}

add_shortcode('test_sc', 'test_sc');
