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

    $influencer_search_fields['niche'] = $niche_options;
    $influencer_search_fields['platform'] = $platform_options;
    $influencer_search_fields['followers'] = $followers_options;
    $influencer_search_fields['country'] = $country_options;
    $influencer_search_fields['lang'] = $lang_options;
    $influencer_search_fields['gender'] = $gender_options;
    $influencer_search_fields['age'] = $age_options;

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

function select_filter($name, $label, $options = [], $type = 'checkbox')
{
    // Check URL parameters for this field
    $selected_values = [];
    if (isset($_GET[$name])) {
        // Ensure it is an array (handles cases where only 1 item is selected)
        $selected_values = is_array($_GET[$name]) ? $_GET[$name] : array($_GET[$name]);
    }

    ob_start();
?>
    <div class="filter-widget select-filter">
        <div class="header">
            <span><?= $label ?></span>
            <div class="reset-btn">Reset</div>
        </div>

        <div class="dropdown-container">
            <div class="dropdown-button">
                Select your <?= strtolower($label) ?>
                <span class="arrow-holder">
                    <span class="arrow"></span>
                </span>
            </div>

            <div class="dropdown-menu checkbox-lists">
                <?php foreach ($options as $key => $option) {
                    // Check if this specific key exists in the URL params
                    $is_checked = in_array((string)$key, $selected_values) ? 'checked="checked"' : '';
                ?>
                    <label class="dropdown-item checkbox-list-item">
                        <input type="<?= $type ?>" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]" <?= $is_checked ?>> <?= $option ?>
                    </label>
                <?php } ?>
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
        <div class="header">
            <span><?= $label ?></span>
        </div>


        <div class="dropdown-menu checkbox-lists">
            <?php foreach ($options as $key => $option) {
                // Check if this specific key exists in the URL params
                $is_checked = in_array((string)$key, $selected_values) ? 'checked="checked"' : '';
            ?>
                <label class="dropdown-item checkbox-list-item">
                    <input type="checkbox" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]" <?= $is_checked ?>> <?= $option ?>
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
                    <input type="radio" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>" <?= $is_checked ?>> <?= $option ?>
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
