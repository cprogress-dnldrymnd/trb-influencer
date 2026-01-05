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
    $unique_id = uniqid('niche_'); // Create unique ID for JS targeting

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
?>
    <form class="influencer-search" action="<?= get_the_permalink(1949) ?>" method="GET">
        <div class="influencer-search-filter-holder">
            <div class="influencer-search-item">
                <?= select_filter('niche', 'Tag Filter', $niche_options) ?>
            </div>
            <div class="influencer-search-item">
                <?= checkbox_filter('platform', 'Platform', $lang_options) ?>
            </div>

            <div class="influencer-search-item">
                <?= radio_filter('followers', 'Follower Range', $followers_options) ?>
            </div>

            <div class="influencer-search-item">
                <div class="influencer-search-item">
                    <?= select_filter('country', 'Location', $country_options) ?>
                </div>
            </div>

            <div class="influencer-search-item">
                <div class="influencer-search-item">
                    <?= select_filter('lang', 'Language', $lang_options) ?>
                </div>
            </div>
            <div class="influencer-search-item">
                <div class="influencer-search-item">
                    <?= select_filter('gender', 'Gender', $gender_options) ?>
                </div>
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

function shortcode_influencer_last_updated() {
    $creatordb_last_updated = get_post_meta( get_the_ID(), 'creatordb_last_updated', true );

    // Check if value exists to avoid returning the current date or 1970 if empty
    if ( empty( $creatordb_last_updated ) ) {
        return '';
    }

    // Format the timestamp
    // 'M' = Short textual representation of month (Nov)
    // 'j' = Day of the month without leading zeros (14)
    // 'Y' = Full numeric representation of a year (2025)
    return date_i18n( 'M j, Y', $creatordb_last_updated );
}

add_shortcode( 'influencer_last_updated', 'shortcode_influencer_last_updated' );