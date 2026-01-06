<?php
function influencer_avatar_shortcode()
{
    // Get the current post ID
    $post_id = get_the_ID();

    // Try to get the URL from the 'avatar' meta key
    $url = get_post_meta($post_id, 'avatar', true);

    // If meta is empty, get the URL of the fallback image (Media ID 1843)
    if (empty($url)) {
        $url = wp_get_attachment_url(1843);
    }

    // If we found a URL (either from meta or fallback), return the image tag
    if ($url) {
        return '<img src="' . esc_url($url) . '" class="influencer-avatar" alt="Influencer Avatar" />';
    }

    return ''; // Return nothing if URL is invalid
}
add_shortcode('influencer_avatar', 'influencer_avatar_shortcode');
/**
 * Shortcode to display Flag and Country Code (Supports 2 or 3 letter codes)
 * Usage: [country_with_flag]
 */
function get_country_flag_from_meta()
{
    $post_id = get_the_ID();

    // 1. Get the code from meta
    $raw_code = get_post_meta($post_id, 'country', true);

    if (empty($raw_code)) {
        return '';
    }

    // 2. Prepare Display Text (Keep original 3-letter or 2-letter format)
    $display_text = strtoupper(esc_html($raw_code));

    // 3. Prepare Image Source Code (Must be 2-letter lowercase)
    $code_clean = strtolower(esc_attr($raw_code));
    $flag_code  = $code_clean; // Default assumption

    // If it is a 3-letter code, convert it
    if (strlen($code_clean) === 3) {
        $flag_code = iso_alpha3_to_alpha2($code_clean);
    }

    // If no valid flag code found after conversion, just return text
    if (! $flag_code) {
        return $display_text;
    }

    // 4. Generate HTML
    $output  = '<span class="meta-country-wrapper" style="display: inline-flex; align-items: center; gap: 8px;">';

    // Flag Image
    $output .= sprintf(
        '<img src="https://flagcdn.com/%s.svg" alt="%s Flag" style="width: 24px; height: auto; box-shadow: 1px 1px 3px rgba(0,0,0,0.1); border-radius: 2px;">',
        $flag_code,
        $display_text
    );

    // Text (Right side)
    $output .= sprintf('<span class="country-code-text">%s</span>', $display_text);

    $output .= '</span>';

    return $output;
}
add_shortcode('influencer_country_with_flag', 'get_country_flag_from_meta');

/**
 * Get language name from 'lang' meta key using PHP Intl
 */
function get_lang_name_from_meta($post_id = null)
{
    // Get current post ID if none is provided
    if (! $post_id) {
        $post_id = get_the_ID();
    }

    // Get the language code from the 'lang' meta key
    $lang_code = get_post_meta($post_id, 'lang', true);

    // If meta is empty, return nothing
    if (empty($lang_code)) {
        return '';
    }

    // Check if the Intl extension is loaded (standard on most hosts)
    if (class_exists('Locale')) {
        // locale_get_display_language converts 'eng' -> 'English', 'de' -> 'German'
        // 'en_US' is the locale for the output language (so the result is in English)
        $display_name = Locale::getDisplayLanguage($lang_code, 'en_US');

        // Ensure we capitalize the first letter
        return ucfirst($display_name);
    }

    // Fallback if Intl is not enabled on server: Return code as uppercase
    return strtoupper($lang_code);
}

/**
 * Shortcode usage: [post_language]
 */
add_shortcode('influencer_language', 'get_lang_name_from_meta');


function shortcode_influencer_niche()
{
    // 1. Get terms from the current post for taxonomy 'niche'
    $terms = get_the_terms(get_the_ID(), 'niche');

    // 2. Check if terms exist and are not errors
    if (empty($terms) || is_wp_error($terms)) {
        return '';
    }

    // 3. Settings
    $display_limit = 3;
    $count = count($terms);

    // 4. Start Output Buffer
    ob_start();
?>

    <div class="influencer-niche-container">
        <?php
        $i = 0;
        foreach ($terms as $term) {
            $i++;

            // Determine if this term should be hidden initially
            $is_hidden = $i > $display_limit;
            $style = $is_hidden ? 'display:none;' : '';
            $class = $is_hidden ? 'niche-term term-hidden' : 'niche-term';

            // Output the term (You can change <span> to <a> if you want links)
            echo sprintf(
                '<span class="%s" style="%s">%s</span>',
                esc_attr($class),
                esc_attr($style),
                esc_html($term->name)
            );
        }

        // 5. Add the Plus Sign if needed
        if ($count > $display_limit) : ?>
            <span class="niche-toggle">
                + <?php echo ($count - $display_limit); ?>
            </span>
        <?php endif; ?>

    </div>

<?php
    return ob_get_clean();
}
add_shortcode('influencer_niche', 'shortcode_influencer_niche');

function shortcode_influencer_followers()
{
    return wp_custom_number_format_short(get_post_meta(get_the_ID(), 'followers', true));
}

add_shortcode('influencer_followers', 'shortcode_influencer_followers');

function shortcode_influence_isverified()
{
    $is_verified = get_post_meta(get_the_ID(), 'isverified', true);
    if ($is_verified) {
        return 'is-verified';
    }
}
add_shortcode('influencer_isverified', 'shortcode_influence_isverified');

function shortcode_influencer_search_filter()
{
    ob_start();
    $influencer_search_fields = get_query_var('influencer_search_fields');
    $influencer_search_page = get_query_var('influencer_search_page');
?>
    <form class="influencer-search" action="<?= get_the_permalink($influencer_search_page) ?>" method="GET">
        <div class="influencer-search-filter-holder">
            <div class="influencer-search-item">
                <?= select_filter('niche', 'Tag Filter', 'Select your tag filters', $influencer_search_fields['niche']) ?>
            </div>
            <div class="influencer-search-item">
                <?= checkbox_filter('platform', 'Platform', $influencer_search_fields['platform']) ?>
            </div>

            <div class="influencer-search-item">
                <?= radio_filter('followers', 'Follower Range', $influencer_search_fields['followers']) ?>
            </div>

            <div class="influencer-search-item">
                <?= select_filter('country', 'Location', 'Select a new location', $influencer_search_fields['country']) ?>
            </div>

            <div class="influencer-search-item">
                <?= select_filter('lang', 'Language', 'Select a new language', $influencer_search_fields['lang']) ?>
            </div>
            <div class="influencer-search-item">
                <?= select_filter('gender', 'Gender', 'Select a gender', $influencer_search_fields['gender']) ?>
            </div>
            <div class="influencer-search-item">
                <?= select_filter('age', 'Age', 'Select an age', $influencer_search_fields['age']) ?>
            </div>
            <div class="influencer-search-item">
                <div class="filter-widget range-filter">
                    <div class="header">
                        <span>Match Score</span>
                    </div>

                    <input type="range" id="score" value="<?= isset($_GET['score']) ? $_GET['score'] : 50 ?>" name="score" min="0" max="100">
                </div>
            </div>
            <div class="influencer-search-item">
                <button type="submit" class="influencer-search-button influencer-search-trigger elementor-button elementor-button-link elementor-size-sm">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-text">REFINE SEARCH</span>
                    </span>
                </button>
            </div>
            <div class="influencer-search-item">
                <div class="save-this-search">
                    <span class="save-this-search-button save-search-trigger">
                        Save this search
                    </span>
                </div>
            </div>
        </div>
    </form>



<?php
    return ob_get_clean();
}

add_shortcode('influencer_search_filter', 'shortcode_influencer_search_filter');

function shortcode_influencer_search_filter_main()
{
    ob_start();
    $influencer_search_fields = get_query_var('influencer_search_fields');
    $influencer_search_page = get_query_var('influencer_search_page');

?>
    <form class="influencer-search influencer-search-main" action="<?= get_the_permalink($influencer_search_page) ?>" method="GET">
        <div class="influencer-search-filter-holder">
            <div class="influencer-search-item influencer-search-item-field">
                <textarea rows="6" name="search-brief" id="search-brief" placeholder="Type or paste your campaign brief — e.g. ‘We’re launching a new vegan skincare line aimed at millennial women in the UK. Budget £1,000 per creator, prefer wellness and beauty influencers on Instagram.’"></textarea>
            </div>
            <div class="influencer-search-item checkbox-row">
                <?= checkbox_filter('filter', false, $influencer_search_fields['filter']) ?>
            </div>
            <div class="influencer-search-item-row filtered-search">
                <div class="influencer-search-item">
                    <?= select_filter('country', false, 'Location', $influencer_search_fields['country']) ?>
                </div>
                <div class="influencer-search-item">
                    <?= select_filter('lang', false, 'Language', $influencer_search_fields['lang']) ?>
                </div>
                <div class="influencer-search-item">
                    <?= select_filter('niche', false, 'Niche', $influencer_search_fields['niche']) ?>
                </div>
                <div class="influencer-search-item">
                    <?= select_filter('platform', false, 'Platform', $influencer_search_fields['platform']) ?>
                </div>
                <div class="influencer-search-item">
                    <?= select_filter('followers', false, 'Follower Range', $influencer_search_fields['followers'], 'radio') ?>
                </div>
            </div>

            <div class="influencer-search-item">
                <button type="submit" class="influencer-search-button  elementor-button elementor-button-link elementor-size-sm">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-text">GENERATE MATCHES</span>
                    </span>
                </button>
            </div>

        </div>
    </form>


<?php
    return ob_get_clean();
}

add_shortcode('influencer_search_filter_main', 'shortcode_influencer_search_filter_main');

function shortcode_influencer_last_updated()
{
    $creatordb_last_updated = get_post_meta(get_the_ID(), 'creatordb_last_updated', true);

    // Check if value exists to avoid returning the current date or 1970 if empty
    if (empty($creatordb_last_updated)) {
        return '';
    }

    // Format the timestamp
    // 'M' = Short textual representation of month (Nov)
    // 'j' = Day of the month without leading zeros (14)
    // 'Y' = Full numeric representation of a year (2025)
    return date_i18n('M j, Y', $creatordb_last_updated);
}

add_shortcode('influencer_last_updated', 'shortcode_influencer_last_updated');


function shortcode_influencer_topics()
{
    ob_start();

?>
    <div class="chips-holder influcencer-topics-holder">
        <span class="chip style-2">WELLBEING</span>
        <span class="chip style-2">NUTRITION</span>
        <span class="chip style-2">DIETING</span>
        <span class="chip style-2">HEALTH & WELLNESS</span>
        <span class="chip style-2">WOMENS HEALTH</span>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('influencer_topics', 'shortcode_influencer_topics');


function shortcode_influencer_niches()
{
    ob_start();

?>
    <div class="chips-holder influencer-niches-holder">
        <span class="chip style-2 bg-2">explore</span>
        <span class="chip style-2 bg-2">reel</span>
        <span class="chip style-2 bg-2">newyear</span>
        <span class="chip style-2 bg-2">selflove</span>
        <span class="chip style-2 bg-2">delicious</span>
        <span class="chip style-2 bg-2">dessert</span>
        <span class="chip style-2 bg-2">healthylifestyle</span>
        <span class="chip style-2 bg-2">pizza</span>
        <span class="chip style-2 bg-2">yummy</span>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('influencer_niches', 'shortcode_influencer_niches');


function breadcrumbs()
{
    ob_start();
    $dashboard = 1565;
    $search = 2149;
    $search_result = 1949;
    $dashboard_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="15.768" height="15.758" viewBox="0 0 15.768 15.758"><path id="dashboard" d="M23.064,13.707,16.518,8.167a1.267,1.267,0,0,0-1.641,0L8.33,13.707a1.458,1.458,0,0,0-.517,1.115v7.342a1.461,1.461,0,0,0,1.46,1.46h2.336a1.461,1.461,0,0,0,1.46-1.46V19.536a2.628,2.628,0,1,1,5.256,0v2.628a1.461,1.461,0,0,0,1.46,1.46h2.336a1.461,1.461,0,0,0,1.46-1.46V14.821a1.457,1.457,0,0,0-.517-1.115Z" transform="translate(-7.813 -7.866)" fill="currentColor"/></svg>';
    $search_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="45.416" height="45.401" viewBox="0 0 45.416 45.401"><path id="search" d="M39.3,20a19.295,19.295,0,1,0,11.35,34.9l9.609,9.609a3.028,3.028,0,1,0,4.283-4.283l-9.609-9.609A19.279,19.279,0,0,0,39.3,20Zm0,32.536A13.241,13.241,0,1,1,52.538,39.295,13.241,13.241,0,0,1,39.3,52.536Z" transform="translate(-20.01 -20)" fill="currentColor"/></svg>';

    if (get_the_ID() == $dashboard) {
        $type = 'dashboard';
    } else if (get_the_ID() == $search || get_the_ID() == $search_result || (is_single() && get_post_type() == 'influencer')) {
        $type = 'search';
    } else {
        $type = 'other';
    }
?>
    <nav class="breadcrumbs breadcrumbs-<?= $type ?>" aria-label="Breadcrumbs">
        <ul>
            <?php if ($type == 'dashboard') { ?>
                <li><?= $dashboard_icon ?> <span>Dashboard</span></li>
            <?php } else if ($type == 'search') { ?>
                <li><?= $search_icon ?> <span>Influencer Discovery</span></li>
                <?php if (get_the_ID() == $search) { ?>
                    <li><span>Search</span></li>
                <?php } ?>

                <?php if (get_the_ID() == $search_result || is_single() && get_post_type() == 'influencer') { ?>
                    <li><a href="<?= get_the_permalink($search) ?>">Search</a></li>
                <?php } ?>

                <?php if (is_single() && get_post_type() == 'influencer') { ?>
                    <li><a href="<?= get_the_permalink($search_result) ?>">Search Results</a></li>
                    <li><span>Creator Profile</span></li>

                <?php } ?>


                <?php if (get_the_ID() == $search_result) { ?>
                    <li><span>Search Results</span></li>
                <?php } ?>

            <?php } ?>

        </ul>
    </nav>
<?php
    return ob_get_clean();
}

add_shortcode('breadcrumbs', 'breadcrumbs');


function shortcode_check_influencer_saved($atts)
{
    // 1. Extract shortcode attributes
    $atts = shortcode_atts(array(
        'true'  => 'UNSAVED', // Text to show if ALREADY saved
        'false' => 'SAVE',    // Text to show if NOT saved
    ), $atts, 'influcencer_is_saved');

    // 2. Get current context
    $current_influencer_id = get_the_ID();
    $current_user_id       = get_current_user_id();

    // Optional: If user is not logged in, default to the 'false' (SAVE) state
    if (! is_user_logged_in()) {
        return $atts['false'];
    }
    $influcencer_is_saved = influcencer_is_saved($current_influencer_id);

    // 4. Return the correct label based on results
    if ($influcencer_is_saved) {
        return $atts['true'];
    } else {
        return $atts['false'];
    }
}
add_shortcode('influcencer_is_saved', 'shortcode_check_influencer_saved');


function influencer_is_saved($current_influencer_id)
{
    $current_user_id = get_current_user_id();

    if (! is_user_logged_in()) {
        return false;
    }

    $args = array(
        'post_type'      => 'saved-influencer',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids', // Returns an array of IDs directly
        'author'         => $current_user_id,
        'meta_query'     => array(
            array(
                'key'     => 'influencer_id',
                'value'   => $current_influencer_id,
                'compare' => '=',
            ),
        ),
    );

    $posts = get_posts($args);

    // Check if the array is not empty and return the first ID found
    if (! empty($posts)) {
        return $posts[0];
    }

    return false;
}
