<?php
if (!defined('ABSPATH')) {
    exit;
}
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

    // 3. Resolve to a 2-letter flag code. The 'country' meta may hold a
    // 2-letter code, a 3-letter code, or a full country name (e.g. "United States").
    $code_clean = strtolower(trim($raw_code));
    $flag_code  = false;

    if (strlen($code_clean) === 2) {
        $flag_code = $code_clean;
    } elseif (strlen($code_clean) === 3) {
        $flag_code = iso_alpha3_to_alpha2($code_clean);
    } else {
        $flag_code = country_name_to_alpha2($code_clean);
        if ($flag_code) {
            // Show the resolved code rather than the full name, to match other entries
            $display_text = strtoupper($flag_code);
        }
    }

    // If no valid flag code found after conversion, just return text
    if (! $flag_code) {
        return $display_text;
    }

    // 4. Generate HTML
    $output  = '<span class="meta-country-wrapper">';

    // Flag Image
    $output .= sprintf(
        '<img src="https://flagcdn.com/%s.svg" alt="%s Flag" >',
        esc_attr($flag_code),
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

        if ($display_name != 'Unknown language') {
            // Ensure we capitalize the first letter
            return ucfirst($display_name);
        }
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

/**
 * Maps a platform= shortcode attr to the namespaced current-metric meta key for that
 * platform, falling back to the flat/primary-platform key when no namespaced key exists
 * for that metric (avglikes/avgcomments/posts have no per-platform equivalent yet).
 */
function trb_platform_stat_meta_key($platform, $flat_key, array $platform_keys)
{
    if (isset($platform_keys[$platform])) {
        return $platform_keys[$platform];
    }

    return $flat_key;
}

/**
 * Single source of truth for the snapshot stat metrics: each metric's flat/primary-platform
 * meta key, its per-platform key overrides, and how to format the raw meta value. Both the
 * stat shortcodes below and trb_build_platform_stats_map() (modules/frontend-utilities/charts.php
 * consumer) read this map, so a shortcode's static output and its live-switched value can never
 * drift apart.
 */
function trb_platform_stat_metric_map()
{
    return [
        'followers' => [
            'flat'     => 'followers',
            'platform' => ['youtube' => 'youtube_subscribers', 'tiktok' => 'tiktok_followers'],
            'format'   => 'number',
        ],
        'engagerate' => [
            'flat'     => 'engagerate',
            'platform' => ['youtube' => 'youtube_engagement_rate', 'tiktok' => 'tiktok_engagement_rate'],
            'format'   => 'percent',
        ],
        'avglikes' => [
            'flat'     => 'avglikes',
            'platform' => [],
            'format'   => 'number',
        ],
        'avgcomments' => [
            'flat'     => 'avgcomments',
            'platform' => [],
            'format'   => 'number',
        ],
        'posts' => [
            'flat'     => 'posts',
            'platform' => [],
            'format'   => 'number',
        ],
    ];
}

/**
 * Wraps a stat shortcode's rendered value in the reactive span the platform switcher
 * rewrites on click (see ddPlatformSwitcher.set() in modules/frontend-utilities/charts.php).
 * Inert wherever no switcher is present on the page (search cards, group rows, etc.).
 */
function trb_wrap_platform_stat($value, $metric)
{
    return '<span class="platform-stat" data-metric="' . esc_attr($metric) . '">' . $value . '</span>';
}

/**
 * Resolves the raw (unformatted) value for one metric on a platform.
 *
 * When an explicit platform is given, prefers the latest row of that platform's own history
 * (`trb_platform_current_metric_from_history()`) over the flat/namespaced current-metric meta —
 * CreatorDB-sourced influencers often never populate `youtube_subscribers`/`tiktok_followers`/
 * `*_engagement_rate` at all, and the flat fields aren't reliably platform-scoped anyway (they
 * track whichever platform is that influencer's `primary_platform`). Falls back to the meta-key
 * lookup when no history value is available.
 *
 * When no platform is given (bare `[influencer_followers]` etc., used site-wide on search cards,
 * group rows, etc.), behaves exactly as before — flat meta key only, no history involved — so
 * non-switcher pages are unaffected.
 */
function trb_resolve_platform_stat_raw($post_id, $platform, $metric)
{
    if ($platform !== '' && function_exists('trb_platform_current_metric_from_history')) {
        $raw = trb_platform_current_metric_from_history($post_id, $platform, $metric);
        if ($raw !== null) {
            return $raw;
        }
    }

    $config = trb_platform_stat_metric_map()[$metric];
    $meta_key = trb_platform_stat_meta_key($platform, $config['flat'], $config['platform']);

    return get_post_meta($post_id, $meta_key, true);
}

function shortcode_influencer_followers($atts = [])
{
    $atts = shortcode_atts(['platform' => ''], (array) $atts, 'influencer_followers');
    $raw = trb_resolve_platform_stat_raw(get_the_ID(), $atts['platform'], 'followers');

    $value = wp_custom_number_format_short($raw);

    return trb_wrap_platform_stat($value, 'followers');
}

add_shortcode('influencer_followers', 'shortcode_influencer_followers');

function shortcode_influencer_avglikes($atts = [])
{
    $atts = shortcode_atts(['platform' => ''], (array) $atts, 'influencer_avglikes');
    $raw = trb_resolve_platform_stat_raw(get_the_ID(), $atts['platform'], 'avglikes');

    $value = wp_custom_number_format_short($raw);

    return trb_wrap_platform_stat($value, 'avglikes');
}

add_shortcode('influencer_avglikes', 'shortcode_influencer_avglikes');

function shortcode_influencer_avgcomments($atts = [])
{
    $atts = shortcode_atts(['platform' => ''], (array) $atts, 'influencer_avgcomments');
    $raw = trb_resolve_platform_stat_raw(get_the_ID(), $atts['platform'], 'avgcomments');

    $value = wp_custom_number_format_short($raw);

    return trb_wrap_platform_stat($value, 'avgcomments');
}

add_shortcode('influencer_avgcomments', 'shortcode_influencer_avgcomments');

function shortcode_influencer_posts($atts = [])
{
    $atts = shortcode_atts(['platform' => ''], (array) $atts, 'influencer_posts');
    $raw = trb_resolve_platform_stat_raw(get_the_ID(), $atts['platform'], 'posts');

    $value = wp_custom_number_format_short($raw);

    return trb_wrap_platform_stat($value, 'posts');
}

add_shortcode('influencer_posts', 'shortcode_influencer_posts');

function shortcode_influencer_engagerate($atts = [])
{
    $atts = shortcode_atts(['platform' => ''], (array) $atts, 'influencer_engagerate');
    $engagerate = trb_resolve_platform_stat_raw(get_the_ID(), $atts['platform'], 'engagerate');
    $engagerate = $engagerate ? $engagerate : 0;
    $value = convertDecimalToPercentage(floatval($engagerate));

    return trb_wrap_platform_stat($value, 'engagerate');
}

add_shortcode('influencer_engagerate', 'shortcode_influencer_engagerate');

/**
 * Resolves the post ID for the combined-platform stat shortcodes below: an explicit `id`
 * attribute when > 0, otherwise the current post. Mirrors the per-platform stat shortcodes,
 * which read get_the_ID(), while allowing an override for parity with the platform shortcodes.
 */
function trb_combined_stat_post_id($atts_id)
{
    $id = intval($atts_id);

    return $id > 0 ? $id : get_the_ID();
}

/**
 * Wraps a combined cross-platform stat value. Unlike trb_wrap_platform_stat(), this uses a
 * distinct `combined-stat` class and a `data-metric` key that is deliberately absent from
 * trb_build_platform_stats_map(), so ddPlatformSwitcher.set() never rewrites it — combined totals
 * are platform-independent and must stay constant when the switcher is clicked.
 */
function trb_wrap_combined_stat($value, $metric)
{
    return '<span class="combined-stat" data-metric="' . esc_attr($metric) . '">' . $value . '</span>';
}

/**
 * [influencer_total_followers] — sum of followers/subscribers across every platform the influencer
 * actually has data for (Instagram + YouTube + TikTok). Non-reactive to the platform switcher.
 *
 * Usage: [influencer_total_followers] or [influencer_total_followers id="123"]
 */
function shortcode_influencer_total_followers($atts = [])
{
    $atts = shortcode_atts(['id' => 0], (array) $atts, 'influencer_total_followers');
    $post_id = trb_combined_stat_post_id($atts['id']);

    $total = 0.0;
    foreach (trb_platforms_available($post_id) as $platform) {
        $total += (float) trb_resolve_platform_stat_raw($post_id, $platform, 'followers');
    }

    return trb_wrap_combined_stat(wp_custom_number_format_short($total), 'total_followers');
}
add_shortcode('influencer_total_followers', 'shortcode_influencer_total_followers');

/**
 * [influencer_combined_engagerate] — engagement rate across all available platforms, weighted by
 * each platform's follower/subscriber count (a large-audience platform counts proportionally more).
 * Platforms with no followers or no engagement data are excluded from both weight and total.
 * Non-reactive to the platform switcher.
 *
 * Usage: [influencer_combined_engagerate] or [influencer_combined_engagerate id="123"]
 */
function shortcode_influencer_combined_engagerate($atts = [])
{
    $atts = shortcode_atts(['id' => 0], (array) $atts, 'influencer_combined_engagerate');
    $post_id = trb_combined_stat_post_id($atts['id']);

    $weighted_sum = 0.0;
    $weight_total = 0.0;
    foreach (trb_platforms_available($post_id) as $platform) {
        $followers = (float) trb_resolve_platform_stat_raw($post_id, $platform, 'followers');
        $rate      = (float) trb_resolve_platform_stat_raw($post_id, $platform, 'engagerate');
        if ($followers <= 0 || $rate <= 0) {
            continue;
        }
        $weighted_sum += $rate * $followers;
        $weight_total += $followers;
    }

    $combined = $weight_total > 0 ? $weighted_sum / $weight_total : 0.0;

    return trb_wrap_combined_stat(convertDecimalToPercentage($combined), 'combined_engagerate');
}
add_shortcode('influencer_combined_engagerate', 'shortcode_influencer_combined_engagerate');

/**
 * [influencer_combined_follower_growth] — one blended ~1-month follower-growth percentage across
 * all available platforms: (sum of current followers - sum of ~1-month-ago followers) / sum of
 * ~1-month-ago followers. Reuses trb_platform_follower_growth_display()'s per-platform date matching
 * (which now returns raw latest/past follower counts); platforms whose growth can't be determined
 * (insufficient history, IC percent-only fallback) are skipped. Non-reactive to the platform switcher.
 *
 * Usage: [influencer_combined_follower_growth] or [influencer_combined_follower_growth id="123"]
 */
function shortcode_influencer_combined_follower_growth($atts = [])
{
    $atts = shortcode_atts(['id' => 0], (array) $atts, 'influencer_combined_follower_growth');
    $post_id = trb_combined_stat_post_id($atts['id']);

    $sum_latest = 0;
    $sum_past   = 0;
    $have_data  = false;
    foreach (trb_platforms_available($post_id) as $platform) {
        $growth = trb_platform_follower_growth_display($post_id, $platform);
        if ($growth === null || !isset($growth['latest_followers'], $growth['past_followers'])) {
            continue;
        }
        $sum_latest += (int) $growth['latest_followers'];
        $sum_past   += (int) $growth['past_followers'];
        $have_data   = true;
    }

    if (!$have_data || $sum_past <= 0) {
        return trb_wrap_combined_stat('N/A', 'combined_follower_growth');
    }

    $decimal   = ($sum_latest - $sum_past) / $sum_past;
    $formatted = ($decimal > 0 ? '+' : '') . convertDecimalToPercentage($decimal);

    return trb_wrap_combined_stat(esc_html($formatted), 'combined_follower_growth');
}
add_shortcode('influencer_combined_follower_growth', 'shortcode_influencer_combined_follower_growth');

function shortcode_influence_isverified()
{
    $is_verified = get_post_meta(get_the_ID(), 'isverified', true);
    if ($is_verified) {
        return 'is-verified';
    }
}
add_shortcode('influencer_isverified', 'shortcode_influence_isverified');


/**
 * Shortcode to count and merge recent posts and reels meta data.
 * Retrieves meta values and strictly validates them as arrays to prevent TypeErrors during array_merge.
 *
 * @return int The combined count of recent posts and reels.
 */
function shortcode_influencer_recentposts_reels()
{
    // Retrieve post meta values
    $recentposts = get_post_meta(get_the_ID(), 'recentposts', true);
    $recentreels = get_post_meta(get_the_ID(), 'recentreels', true);

    // Strictly ensure both variables are arrays. 
    // If get_post_meta returns an empty string or bool, fallback to an empty array.
    $valid_posts = is_array($recentposts) ? $recentposts : [];
    $valid_reels = is_array($recentreels) ? $recentreels : [];

    // Safely merge the validated arrays
    $merge = array_merge($valid_posts, $valid_reels);

    // Count the total items
    $count = count($merge);

    return $count;
}
add_shortcode('influencer_recentposts_reels', 'shortcode_influencer_recentposts_reels');



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


/**
 * Retrieves and displays 'topic' terms associated with the current post.
 * Iterates through the assigned terms and outputs them as styled chip elements.
 * * @return string HTML output containing the influencer topics.
 */
function shortcode_influencer_topics()
{
    ob_start();

    // Fetch terms for the 'topic' taxonomy
    $terms = get_the_terms(get_the_ID(), 'topic');

?>
    <div class="chips-holder influencer-topics-holder">
        <?php
        // Verify terms exist and no WP_Error was returned before looping
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                // Escaped for security, converted to uppercase to match previous hardcoded styling
                echo '<span class="chip style-2">' . esc_html(strtoupper($term->name)) . '</span>';
            }
        } else {
            echo '<style>#influencer-topic{display: none}</style>';
        }
        ?>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('influencer_topics', 'shortcode_influencer_topics');


/**
 * Retrieves and displays 'niche' terms associated with the current post.
 * Iterates through the assigned terms and outputs them as styled chip elements.
 * * @return string HTML output containing the influencer niches.
 */
function shortcode_influencer_niches()
{
    ob_start();

    // Fetch terms for the 'niche' taxonomy
    $terms = get_the_terms(get_the_ID(), 'niche');

?>
    <div class="chips-holder influencer-niches-holder">
        <?php
        // Verify terms exist and no WP_Error was returned before looping
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                // Escaped for security, converted to lowercase to match previous hardcoded styling
                echo '<span class="chip style-2 bg-2">' . esc_html(strtolower($term->name)) . '</span>';
            }
        } else {
            echo '<style>#influencer-niches{display: none}</style>';
        }
        ?>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('influencer_niches', 'shortcode_influencer_niches');


function breadcrumbs()
{
    ob_start();

    global $search_results_page_id, $search_page_id, $dashboard_page_id;
    $dashboard_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="15.768" height="15.758" viewBox="0 0 15.768 15.758"><path id="dashboard" d="M23.064,13.707,16.518,8.167a1.267,1.267,0,0,0-1.641,0L8.33,13.707a1.458,1.458,0,0,0-.517,1.115v7.342a1.461,1.461,0,0,0,1.46,1.46h2.336a1.461,1.461,0,0,0,1.46-1.46V19.536a2.628,2.628,0,1,1,5.256,0v2.628a1.461,1.461,0,0,0,1.46,1.46h2.336a1.461,1.461,0,0,0,1.46-1.46V14.821a1.457,1.457,0,0,0-.517-1.115Z" transform="translate(-7.813 -7.866)" fill="currentColor"/></svg>';
    $search_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="45.416" height="45.401" viewBox="0 0 45.416 45.401"><path id="search" d="M39.3,20a19.295,19.295,0,1,0,11.35,34.9l9.609,9.609a3.028,3.028,0,1,0,4.283-4.283l-9.609-9.609A19.279,19.279,0,0,0,39.3,20Zm0,32.536A13.241,13.241,0,1,1,52.538,39.295,13.241,13.241,0,0,1,39.3,52.536Z" transform="translate(-20.01 -20)" fill="currentColor"/></svg>';

    if (get_the_ID() == $dashboard_page_id) {
        $type = 'dashboard';
    } else if (get_the_ID() == $search_page_id || get_the_ID() == $search_results_page_id || (is_single() && get_post_type() == 'influencer')) {
        $type = 'search';
    } else {
        $type = 'other';
    }
?>
    <nav class="breadcrumbs breadcrumbs-<?= esc_attr($type) ?>" aria-label="Breadcrumbs">
        <ul>
            <li>
                <?php if (get_the_ID() == $dashboard_page_id) { ?>
                    <?= $dashboard_icon ?> <span>Dashboard</span>
                <?php } else { ?>
                    <a href="<?= esc_url(get_the_permalink($dashboard_page_id)) ?>"><?= $dashboard_icon ?> <span>Dashboard</span></a>
                <?php } ?>
            </li>

            <?php if (get_the_ID() == $search_page_id || get_the_ID() == $search_results_page_id || (is_single() && get_post_type() == 'influencer')) { ?>
                <?php if (get_the_ID() == $search_page_id) { ?>
                    <li><?= $search_icon ?> <span>Influencer Discovery</span></li>
                <?php } ?>


                <?php if (get_the_ID() == $search_results_page_id || is_single() && get_post_type() == 'influencer') { ?>
                    <li><a href="<?= esc_url(get_the_permalink($search_page_id)) ?>">Influencer Discovery</a></li>
                <?php } ?>

                <?php if (is_single() && get_post_type() == 'influencer') { ?>
                    <li><a href="<?= esc_url(get_the_permalink($search_results_page_id)) ?>">Search Results</a></li>
                    <li><span>Creator Profile</span></li>

                <?php } ?>


                <?php if (get_the_ID() == $search_results_page_id) { ?>
                    <li><span>Search Results</span></li>
                <?php } ?>

            <?php } else { ?>
                <?php if (!is_page(1565)) { ?>
                    <li><span><?= esc_html(get_the_title()) ?></span></li>
                <?php } ?>
            <?php } ?>

        </ul>
    </nav>
<?php
    return ob_get_clean();
}

add_shortcode('breadcrumbs', 'breadcrumbs');


/**
 * Helper Function: Generates the HTML for the current user's avatar.
 * Retrieves the avatar from Paid Memberships Pro or falls back to
 * generating initials based on the user's name or email.
 *
 * @param string|bool $is_email_template Whether to output inline CSS for email compatibility.
 * @return string The HTML representation of the user avatar, or an empty string if not logged in.
 */
function dd_get_user_avatar_html($is_email_template = false)
{
    // 1. Check if user is logged in. If not, return nothing.
    if (!is_user_logged_in()) {
        return '';
    }

    // 2. Prepare inline styles if it's an email template
    $img_style = '';
    $fallback_style = '';

    if (filter_var($is_email_template, FILTER_VALIDATE_BOOLEAN)) {
        $base_style = 'width: 60px !important; height: 60px !important; border-radius: 50% !important; border: 1px solid #CCCCCC !important; object-fit: cover !important;';
        $img_style = " style='{$base_style}'";
        $fallback_style = " style='{$base_style} text-align: center; font-size: 20px; padding: 17px 0; box-sizing: border-box; font-weight: bold;'";
    }

    // 3. Get User Info via PMPro
    $avatar = convert_pmpro_path_to_url(get_pmpro_file_field_url(get_current_user_id(), 'user_avatar', 'thumbnail'));

    // Output the featured image if it exists
    if ($avatar) {
        $avatar_html = "<img src='{$avatar}' alt='User Avatar' class='cad-avatar'{$img_style}>";
    } else {
        // Fallback: Generate initials from the user data
        $current_user = wp_get_current_user();
        $first_name   = $current_user->user_firstname;
        $last_name    = $current_user->user_lastname;

        if (!$first_name && !$last_name) {
            $name = $current_user->nickname;
        } else {
            $name = $first_name . ' ' . $last_name;
        }

        $email = $current_user->user_email;

        // Build the HTML for the initials avatar
        $avatar_html  = "<div class='avatar-fallback'{$fallback_style}>";
        $avatar_html .= esc_html(dd_get_initials_from_string($name ? $name : $email));
        $avatar_html .= "</div>";
    }

    return $avatar_html;
}

/**
 * Shortcode: Displays the user avatar standalone.
 * Usage: [user_avatar] or [user_avatar is_email_template="true"]
 *
 * @return string HTML output for the user avatar.
 */
function custom_standalone_avatar_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'is_email_template' => 'false',
    ), $atts, 'user_avatar');

    return dd_get_user_avatar_html($atts['is_email_template']);
}
add_shortcode('user_avatar', 'custom_standalone_avatar_shortcode');

function custom_avatar_dropdown_shortcode($atts)
{
    // 1. Check if user is logged in. If not, return nothing (or a login button).
    if (!is_user_logged_in()) {
        return '';
    }

    // 2. Get Attributes (Page IDs to link to)
    $atts = shortcode_atts(
        array(
            'ids' => '', // Comma separated IDs: e.g., "12, 45, 20"
        ),
        $atts
    );

    // 3. Fetch the Avatar HTML via our centralized helper function
    $avatar_html = dd_get_user_avatar_html();

    $logout_url = wp_logout_url(home_url()); // Redirects to home after logout

    // 4. Build the Page Links
    $menu_items = '';
    if (!empty($atts['ids'])) {
        $page_ids = explode(',', $atts['ids']);
        foreach ($page_ids as $id) {
            $id = trim($id);
            if (get_post_status($id)) {
                $link  = get_permalink($id);
                $title = get_the_title($id);
                $menu_items .= "<a href='{$link}' class='cad-menu-item'>{$title}</a>";
            }
        }
    }

    // 5. Build the HTML Output
    // We include a tiny inline SVG for the chevron arrow
    $output = "
    <div class='cad-wrapper' onclick='this.classList.toggle(\"active\")'>
        <div class='cad-trigger'>
            <div class='cad-avatar-wrapper'>
                {$avatar_html}
            </div>
            <span class='cad-arrow'>
                <svg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M1 1L5 5L9 1' stroke='#333' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>
            </span>
        </div>
        
        <div class='cad-dropdown'>
            {$menu_items}
            <div class='cad-divider'></div>
            <a href='{$logout_url}' class='cad-menu-item cad-logout'>Logout</a>
        </div>
    </div>
    
    <script>
    // Close dropdown if clicked outside
    document.addEventListener('click', function(event) {
        var wrapper = document.querySelector('.cad-wrapper');
        // Prevent JS errors if the element isn't found on the page
        if (wrapper) {
            var isClickInside = wrapper.contains(event.target);
            if (!isClickInside) {
                wrapper.classList.remove('active');
            }
        }
    });
    </script>
    ";

    return $output;
}
add_shortcode('avatar_dropdown', 'custom_avatar_dropdown_shortcode');

add_shortcode('mycred_circle_progress', 'render_mycred_circle_progress');

function render_mycred_circle_progress($atts)
{
    // 1. Configure default settings
    $atts = shortcode_atts(array(
        'max'   => '1000',             // The "Goal" or max credits
        'type'  => 'mycred_default',  // The point type key
        'color' => '#ffcc00',         // The active circle color (Yellow)
        'bg'    => '#eeeeee',         // The empty circle color (Grey)
        'size'  => '150px',           // Width of the widget
    ), $atts);

    // 2. Check requirements: User must be logged in & myCred active
    if (! is_user_logged_in() || ! function_exists('mycred_get_users_balance')) {
        return '';
    }

    // 3. Get the dynamic data
    $user_id = get_current_user_id();
    $balance = mycred_get_users_balance($user_id, $atts['type']);

    // 4. Calculate Percentage
    $max = intval($atts['max']);
    if ($max <= 0) $max = 100; // Prevent division by zero
    $percentage = ($balance / $max) * 100;

    // Cap at 100% (so the line doesn't loop around twice)
    $percentage = min($percentage, 100);
    $percentage = max($percentage, 0);

    // 5. Generate a unique ID for this specific circle
    $uid = 'mc-circle-' . uniqid();

    // 6. Output the HTML/CSS
    ob_start();
?>

    <div id="<?php echo esc_attr($uid); ?>" class="mycred-circle-widget" style="width: <?php echo esc_attr($atts['size']); ?>;">
        <svg viewBox="0 0 36 36" class="circular-chart">
            <path class="circle-bg"
                d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                fill="none"
                stroke="<?php echo esc_attr($atts['bg']); ?>"
                stroke-width="3" />
            <path class="circle-progress"
                stroke-dasharray="<?php echo esc_attr($percentage); ?>, 100"
                d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                fill="none"
                stroke="<?php echo esc_attr($atts['color']); ?>"
                stroke-width="3"
                stroke-linecap="round" />
        </svg>
    </div>

    <style>
        /* Basic Animation on Load */
        #<?php echo esc_attr($uid); ?>.circle-progress {
            animation: progress-<?php echo esc_attr($uid); ?> 1s ease-out forwards;
        }

        @keyframes progress-<?php echo esc_attr($uid); ?> {
            0% {
                stroke-dasharray: 0, 100;
            }

            100% {
                stroke-dasharray: <?php echo esc_attr($percentage); ?>, 100;
            }
        }
    </style>

<?php
    return ob_get_clean();
}
/**
 * Shortcode handler for counting saved influencers.
 * Usage: [saved_influencer_count] or [saved_influencer_count this_month_only="true"]
 *
 * @param array $atts Shortcode attributes.
 * @return int        Total count of saved influencers.
 */
function saved_influencer_count($atts)
{
    $this_month_only = parse_month_only_attribute($atts);
    return count(get_saved_influencer($this_month_only));
}
add_shortcode('saved_influencer_count', 'saved_influencer_count');


/**
 * Shortcode handler for counting viewed influencers.
 * Usage: [viewed_influencer_count] or [viewed_influencer_count this_month_only="true"]
 *
 * @param array $atts Shortcode attributes.
 * @return int        Total count of viewed influencers.
 */
function viewed_influencer_count($atts)
{
    $this_month_only = parse_month_only_attribute($atts);
    return count(get_viewed_influencer($this_month_only));
}
add_shortcode('viewed_influencer_count', 'viewed_influencer_count');


/**
 * Shortcode handler for counting outreaches.
 * Usage: [outreach_count] or [outreach_count this_month_only="true"]
 *
 * @param array $atts Shortcode attributes.
 * @return int        Total count of outreaches.
 */
function outreach_count($atts)
{
    $this_month_only = parse_month_only_attribute($atts);
    return count(get_outreach($this_month_only));
}
add_shortcode('outreach_count', 'outreach_count');

/**
 * Shortcode handler for counting saved searches.
 * Usage: [saved_search_count] or [saved_search_count this_month_only="true"]
 *
 * @param array $atts Shortcode attributes.
 * @return int        Total count of saved searches.
 */
function saved_search_count($atts)
{
    $this_month_only = parse_month_only_attribute($atts);

    // Call the direct count function instead of the meta-array function
    return get_saved_search_count_direct($this_month_only);
}
add_shortcode('saved_search_count', 'saved_search_count');
function unlocked_influencer_count()
{
    return count(get_user_purchased_post_ids('influencer', true));
}

add_shortcode('unlocked_influencer_count', 'unlocked_influencer_count');



function most_engage_niches()
{
    $current_user_id = get_current_user_id();
    $ranked_niches = get_user_niche_ranking($current_user_id, 3);

    if (empty($ranked_niches)) return;

    return sprintf(
        /* translators: 1: niche name, 2: percentage, 3: niche name, 4: percentage, 5: niche name, 6: percentage */
        __('Your top niches this month: %1$s (%2$s%%), %3$s (%4$s%%), %5$s (%6$s%%).', 'hello-elementor-child'),
        $ranked_niches[0]['name'],
        $ranked_niches[0]['percentage'],
        $ranked_niches[1]['name'],
        $ranked_niches[1]['percentage'],
        $ranked_niches[2]['name'],
        $ranked_niches[2]['percentage']
    );
}

add_shortcode('most_engage_niches', 'most_engage_niches');

function most_engage_niches_graph()
{
    ob_start();
    $current_user_id = get_current_user_id();
    $top_niches = get_user_niche_ranking($current_user_id, 3);
    if (empty($top_niches)) return;

    // 2. Define colors to match your image (Light Teal, Dark Teal, Yellow)
    // We assign them in order: 1st, 2nd, 3rd
    $colors = ['#BCE0D9', '#35676B', '#FFE17B'];

    // Prepare arrays for JavaScript
    $js_labels = [];
    $js_data   = [];
    $js_colors = [];

?>

    <div class="niche-dashboard-widget">

        <div class="chart-container">
            <canvas id="nicheDonutChart"></canvas>
        </div>

        <div class="legend-container">
            <?php
            if (! empty($top_niches)) :
                foreach ($top_niches as $index => $niche) :
                    // Assign color based on index (fallback to grey if more than 3)
                    $color = isset($colors[$index]) ? $colors[$index] : '#cccccc';

                    // Push data for JS later
                    $js_labels[] = $niche['name'];
                    $js_data[]   = $niche['percentage']; // Use the calculated %
                    $js_colors[] = $color;
            ?>

                    <div class="legend-item">
                        <span class="legend-label" style="background-color: <?php echo esc_attr($color); ?>;">
                            <?php echo esc_html($niche['name']); ?>
                        </span>

                        <span class="legend-value">
                            <?php echo esc_html($niche['percentage']); ?>%
                        </span>
                    </div>

                <?php endforeach;
            else : ?>
                <p>No data available yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        var nicheChartData = {
            labels: <?php echo json_encode($js_labels); ?>,
            data: <?php echo json_encode($js_data); ?>,
            colors: <?php echo json_encode($js_colors); ?>
        };

        document.addEventListener("DOMContentLoaded", function() {

            // Safety check if data exists
            if (!window.nicheChartData || !document.getElementById('nicheDonutChart')) {
                return;
            }

            const ctx = document.getElementById('nicheDonutChart').getContext('2d');

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: nicheChartData.labels,
                    datasets: [{
                        data: nicheChartData.data,
                        backgroundColor: nicheChartData.colors,
                        borderWidth: 4, // Removes white border between slices
                        borderColor: '#fff',
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '55%', // Makes the donut hole size match your image
                    plugins: {
                        legend: {
                            display: false // IMPORTANT: We hide the default legend
                        },
                        tooltip: {
                            enabled: true // Keep tooltips on hover
                        }
                    }
                }
            });
        });
    </script>

<?php
    return ob_get_clean();
}

add_shortcode('most_engage_niches_graph', 'most_engage_niches_graph');


function number_of_searches()
{
    return get_user_meta(get_current_user_id(), 'number_of_searches', true);
}

add_shortcode('number_of_searches', 'number_of_searches');


function roi_calculator()
{
    ob_start();
?>
    <div class="ic-calc" id="ic-calc">
        <div class="ic-calc__grid">
            <div class="ic-calc__controls">
                <h3>Campaign inputs</h3>

                <label>
                    Budget (USD)
                    <input type="number" id="budget" value="1000" min="0" step="100">
                </label>

                <label>
                    Avg. followers per influencer
                    <input type="number" id="followers" value="1000" min="0" step="100">
                </label>

                <label>
                    Tier
                    <select id="tier">
                        <option value="nano" selected>Nano</option>
                        <option value="micro">Micro</option>
                        <option value="macro">Macro</option>
                    </select>
                </label>

                <div class="ic-calc__row">
                    <label>
                        Stories / influencer
                        <input type="number" id="stories" value="0" min="0" step="1">
                    </label>
                    <label>
                        Posts / influencer
                        <input type="number" id="posts" value="1" min="0" step="1">
                    </label>
                    <label>
                        Videos / influencer
                        <input type="number" id="videos" value="1" min="0" step="1">
                    </label>
                </div>

                <div class="ic-calc__guardrail" id="guardrail" aria-live="polite"></div>

                <hr>

                <h3>True ROI assumptions (optional)</h3>
                <p style="margin:0 0 10px;">Use this to estimate ROI from conversions. If you leave these blank, we will still show CPM and CPE.</p>

                <div class="ic-calc__row">
                    <label>
                        Click-through rate (CTR %)
                        <input type="number" id="ctr" value="0.8" min="0" step="0.1">
                    </label>
                    <label>
                        Conversion rate (%)
                        <input type="number" id="cvr" value="2" min="0" step="0.1">
                    </label>
                    <label>
                        Avg order value (AOV)
                        <input type="number" id="aov" value="60" min="0" step="1">
                    </label>
                </div>

                <div class="ic-calc__row">
                    <label>
                        Gross margin (%)
                        <input type="number" id="margin" value="60" min="0" max="100" step="1">
                    </label>
                    <label>
                        LTV multiplier (optional)
                        <input type="number" id="ltvMult" value="1" min="0" step="0.1">
                    </label>
                </div>
            </div>

            <div class="ic-calc__results">
                <h3>Estimated results</h3>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Estimated reach</div>
                    <div class="ic-calc__metricValue" id="reachOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Number of influencers</div>
                    <div class="ic-calc__metricValue" id="infOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Engagement</div>
                    <div class="ic-calc__metricValue" id="engOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Impressions</div>
                    <div class="ic-calc__metricValue" id="impOut">-</div>
                </div>

                <hr>

                <h3>Efficiency + ROI view</h3>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">CPM (cost per 1,000 impressions)</div>
                    <div class="ic-calc__metricValue" id="cpmOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">CPE (cost per engagement)</div>
                    <div class="ic-calc__metricValue" id="cpeOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Estimated clicks</div>
                    <div class="ic-calc__metricValue" id="clicksOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Estimated conversions</div>
                    <div class="ic-calc__metricValue" id="convOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">Estimated gross profit</div>
                    <div class="ic-calc__metricValue" id="profitOut">-</div>
                </div>

                <div class="ic-calc__metric">
                    <div class="ic-calc__metricLabel">ROI (profit vs budget)</div>
                    <div class="ic-calc__metricValue" id="roiOut">-</div>
                </div>

            </div>
        </div>
    </div>



    <script>
        (function() {
            // Tier configuration: adjust these to match your chosen model.
            // Notes:
            // - Nano values here match the earlier examples you shared (post-only + post+video) reasonably well.
            // - Macro values are based on the later screenshots you provided (55k followers macro scenarios).
            // - Micro values are placeholders; you can tune them using the same method we used for nano/macro.
            const TIER_CONFIG = {
                nano: {
                    costs: {
                        story: {
                            min: 25,
                            max: 60
                        },
                        post: {
                            min: 85,
                            max: 130
                        },
                        video: {
                            min: 115,
                            max: 203
                        }
                    },
                    mults: {
                        story: {
                            min: 0.20,
                            max: 0.35
                        },
                        post: {
                            min: 1.00,
                            max: 1.25
                        },
                        video: {
                            min: 1.1333,
                            max: 1.15
                        }
                    }
                },
                micro: {
                    costs: {
                        story: {
                            min: 80,
                            max: 160
                        },
                        post: {
                            min: 250,
                            max: 450
                        },
                        video: {
                            min: 400,
                            max: 700
                        }
                    },
                    mults: {
                        story: {
                            min: 0.18,
                            max: 0.30
                        },
                        post: {
                            min: 0.95,
                            max: 1.20
                        },
                        video: {
                            min: 0.85,
                            max: 1.05
                        }
                    }
                },
                macro: {
                    costs: {
                        story: {
                            min: 1000,
                            max: 2000
                        },
                        post: {
                            min: 4000,
                            max: 6000
                        },
                        video: {
                            min: 6000,
                            max: 9000
                        }
                    },
                    mults: {
                        story: {
                            min: 0.15,
                            max: 0.25
                        },
                        post: {
                            min: 0.95,
                            max: 1.30
                        },
                        video: {
                            min: 0.55,
                            max: 0.65
                        }
                    }
                }
            };

            // Global constants confirmed by your screenshots
            const ER_MIN = 0.08;
            const ER_MAX = 0.10;
            const REACH_MIN_MULT = 0.5;
            const REACH_MAX_MULT = 6;

            const el = (id) => document.getElementById(id);

            const inputs = {
                budget: el("budget"),
                followers: el("followers"),
                tier: el("tier"),
                stories: el("stories"),
                posts: el("posts"),
                videos: el("videos"),
                ctr: el("ctr"),
                cvr: el("cvr"),
                aov: el("aov"),
                margin: el("margin"),
                ltvMult: el("ltvMult")
            };

            const outputs = {
                guardrail: el("guardrail"),
                reachOut: el("reachOut"),
                infOut: el("infOut"),
                engOut: el("engOut"),
                impOut: el("impOut"),
                cpmOut: el("cpmOut"),
                cpeOut: el("cpeOut"),
                clicksOut: el("clicksOut"),
                convOut: el("convOut"),
                profitOut: el("profitOut"),
                roiOut: el("roiOut")
            };

            function toNum(v) {
                const n = Number(v);
                return Number.isFinite(n) ? n : 0;
            }

            function clampInt(n, min, max) {
                n = Math.floor(n);
                if (!Number.isFinite(n)) return min;
                return Math.max(min, Math.min(max, n));
            }

            function formatCompact(n) {
                if (!Number.isFinite(n)) return "-";
                const abs = Math.abs(n);
                if (abs >= 1e6) return (n / 1e6).toFixed(abs >= 1e7 ? 1 : 2).replace(/\.0+$/, "") + "M";
                if (abs >= 1e3) return (n / 1e3).toFixed(abs >= 1e5 ? 1 : 1).replace(/\.0+$/, "") + "K";
                return String(Math.round(n));
            }

            function formatMoney(n) {
                if (!Number.isFinite(n)) return "-";
                return "$" + Math.round(n).toLocaleString("en-US");
            }

            function formatRange(min, max, formatter) {
                if (min === null || max === null) return "-";
                if (!Number.isFinite(min) || !Number.isFinite(max)) return "-";
                if (max < min)[min, max] = [max, min];
                if (Math.round(min) === Math.round(max)) return formatter(min);
                return formatter(min) + " - " + formatter(max);
            }

            function safeDiv(a, b) {
                if (!Number.isFinite(a) || !Number.isFinite(b) || b === 0) return null;
                return a / b;
            }

            function calc() {
                const budget = Math.max(0, toNum(inputs.budget.value));
                const followers = Math.max(0, toNum(inputs.followers.value));

                const stories = clampInt(toNum(inputs.stories.value), 0, 999);
                const posts = clampInt(toNum(inputs.posts.value), 0, 999);
                const videos = clampInt(toNum(inputs.videos.value), 0, 999);

                const tierKey = inputs.tier.value;
                const cfg = TIER_CONFIG[tierKey];

                // Guardrail: must have at least 1 deliverable
                if ((stories + posts + videos) === 0) {
                    outputs.guardrail.textContent = "Add at least 1 deliverable (story, post, or video) to estimate results.";
                    setAllOutputsToDash();
                    return;
                }

                // Cost per influencer (min/max)
                const costMin =
                    (stories * cfg.costs.story.min) +
                    (posts * cfg.costs.post.min) +
                    (videos * cfg.costs.video.min);

                const costMax =
                    (stories * cfg.costs.story.max) +
                    (posts * cfg.costs.post.max) +
                    (videos * cfg.costs.video.max);

                // Influencer count range via floor(budget / cost band)
                let infMin = Math.floor(budget / costMax);
                let infMax = Math.floor(budget / costMin);

                // Guardrail: budget too low
                if (infMax <= 0) {
                    outputs.guardrail.textContent = "Budget is too low for this tier and content mix. Reduce deliverables, lower the tier, or increase budget.";
                    setAllOutputsToDash();
                    return;
                }

                // UX guardrail: avoid 0 min influencers when max is > 0
                if (infMin <= 0) infMin = 1;

                // Keep sane bounds
                infMin = clampInt(infMin, 1, 9999);
                infMax = clampInt(infMax, infMin, 9999);

                // Impression multipliers (min/max)
                const multMin =
                    (stories * cfg.mults.story.min) +
                    (posts * cfg.mults.post.min) +
                    (videos * cfg.mults.video.min);

                const multMax =
                    (stories * cfg.mults.story.max) +
                    (posts * cfg.mults.post.max) +
                    (videos * cfg.mults.video.max);

                const impressionsMin = infMin * followers * multMin;
                const impressionsMax = infMax * followers * multMax;

                // Engagement (crossed)
                const engagementMin = impressionsMin * ER_MAX;
                const engagementMax = impressionsMax * ER_MIN;

                // Reach derived from impressions
                const reachMin = impressionsMin * REACH_MIN_MULT;
                const reachMax = impressionsMax * REACH_MAX_MULT;

                // Output (top section)
                outputs.guardrail.textContent = "";
                outputs.infOut.textContent = formatRange(infMin, infMax, (n) => String(Math.round(n)));
                outputs.impOut.textContent = formatRange(impressionsMin, impressionsMax, formatCompact);
                outputs.engOut.textContent = formatRange(engagementMin, engagementMax, formatCompact);
                outputs.reachOut.textContent = formatRange(reachMin, reachMax, formatCompact);

                // Efficiency metrics
                const cpmMin = safeDiv(budget, impressionsMax) !== null ? (budget / impressionsMax) * 1000 : null; // best-case CPM
                const cpmMax = safeDiv(budget, impressionsMin) !== null ? (budget / impressionsMin) * 1000 : null; // worst-case CPM
                outputs.cpmOut.textContent = (cpmMin === null || cpmMax === null) ?
                    "-" :
                    formatRange(cpmMin, cpmMax, (n) => formatMoney(n));

                const cpeMin = safeDiv(budget, engagementMax) !== null ? (budget / engagementMax) : null; // best-case CPE
                const cpeMax = safeDiv(budget, engagementMin) !== null ? (budget / engagementMin) : null; // worst-case CPE
                outputs.cpeOut.textContent = (cpeMin === null || cpeMax === null) ?
                    "-" :
                    formatRange(cpeMin, cpeMax, (n) => formatMoney(n));

                // True ROI view (optional assumptions)
                const ctr = Math.max(0, toNum(inputs.ctr.value)) / 100;
                const cvr = Math.max(0, toNum(inputs.cvr.value)) / 100;
                const aov = Math.max(0, toNum(inputs.aov.value));
                const margin = Math.max(0, Math.min(100, toNum(inputs.margin.value))) / 100;
                const ltvMult = Math.max(0, toNum(inputs.ltvMult.value));

                // clicks from impressions (range)
                const clicksMin = impressionsMin * ctr;
                const clicksMax = impressionsMax * ctr;

                // conversions (range)
                const convMin = clicksMin * cvr;
                const convMax = clicksMax * cvr;

                // revenue (range) with optional LTV multiplier
                const revenueMin = convMin * aov * ltvMult;
                const revenueMax = convMax * aov * ltvMult;

                // gross profit (range)
                const profitMin = revenueMin * margin;
                const profitMax = revenueMax * margin;

                // ROI (profit vs budget) expressed as %
                const roiMin = safeDiv((profitMin - budget), budget);
                const roiMax = safeDiv((profitMax - budget), budget);

                outputs.clicksOut.textContent = formatRange(clicksMin, clicksMax, formatCompact);
                outputs.convOut.textContent = formatRange(convMin, convMax, formatCompact);
                outputs.profitOut.textContent = formatRange(profitMin, profitMax, formatMoney);

                outputs.roiOut.textContent = (roiMin === null || roiMax === null) ?
                    "-" :
                    formatRange(roiMin * 100, roiMax * 100, (n) => (Math.round(n) + "%"));
            }

            function setAllOutputsToDash() {
                outputs.reachOut.textContent = "-";
                outputs.infOut.textContent = "-";
                outputs.engOut.textContent = "-";
                outputs.impOut.textContent = "-";
                outputs.cpmOut.textContent = "-";
                outputs.cpeOut.textContent = "-";
                outputs.clicksOut.textContent = "-";
                outputs.convOut.textContent = "-";
                outputs.profitOut.textContent = "-";
                outputs.roiOut.textContent = "-";
            }

            Object.values(inputs).forEach((node) => {
                node.addEventListener("input", calc);
                node.addEventListener("change", calc);
            });

            calc();
        })();
    </script>

<?php
    return ob_get_clean();
}
add_shortcode('roi_calculator', 'roi_calculator');


/**
 * Registers the [influencer_avatar] shortcode.
 *
 * Evaluates the specified or current post for a featured image. 
 * If present, it returns the standard image tag. If absent, it relies
 * on a helper function to generate an HTML-based initial avatar.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output for the featured image or initial avatar.
 */
function dd_influencer_avatar_shortcode($atts)
{
    // Parse attributes, allowing an optional post_id, size override, and email template flag
    $args = shortcode_atts(array(
        'post_id'           => get_the_ID(),
        'size'              => 'thumbnail', // Accepts standard WordPress image sizes
        'is_email_template' => 'false',
    ), $atts, 'influencer_avatar');

    $post_id = intval($args['post_id']);

    if (! $post_id) {
        $post_id = get_the_ID();
    }

    // Prepare inline styles if it's an email template
    $img_style = '';
    $fallback_style = '';

    if (filter_var($args['is_email_template'], FILTER_VALIDATE_BOOLEAN)) {
        $base_style = 'width: 60px !important; height: 60px !important; border-radius: 50% !important; border: 1px solid #CCCCCC !important; object-fit: cover !important;';
        $img_style = $base_style; // get_the_post_thumbnail takes raw CSS in the style array
        $fallback_style = " style='{$base_style} text-align: center; font-size: 20px; padding: 17px 0; box-sizing: border-box; font-weight: bold;'";
    }

    // Output the featured image if it exists
    if (has_post_thumbnail($post_id)) {
        $attr = array('class' => 'influencer-avatar-img');
        if (!empty($img_style)) {
            $attr['style'] = $img_style;
        }
        return get_the_post_thumbnail($post_id, $args['size'], $attr);
    }

    // Fallback: Generate initials from the post title
    $title    = get_the_title($post_id);
    $initials = dd_get_initials_from_string($title);

    // Build the HTML for the initials avatar
    $html  = "<div class='influencer-avatar-fallback'{$fallback_style}>";
    $html .= esc_html($initials);
    $html .= "</div>";

    return $html;
}
add_shortcode('influencer_avatar', 'dd_influencer_avatar_shortcode');

/**
 * Shortcode to display an influencer's content tags (hashtags).
 * Uses content_tag taxonomy; when CreatorDB is active, prioritizes Niche Management admin keywords.
 *
 * Usage: [influencer_hashtags] [influencer_hashtags limit="10" post_id="123"]
 *
 * @author Digitally Disruptive - Donald Raymundo <https://digitallydisruptive.co.uk/>
 * @param array $atts Shortcode attributes.
 * @return string HTML for the hashtag cloud.
 */
function shortcode_influencer_hashtags($atts = [])
{
    $atts = shortcode_atts(
        [
            'limit'   => 10,
            'post_id' => get_the_ID(),
        ],
        $atts,
        'influencer_hashtags'
    );

    $post_id = (int) $atts['post_id'];
    $limit   = max(1, min(120, (int) $atts['limit']));

    if ($post_id <= 0) {
        return '';
    }

    if (function_exists('creatordb_get_display_content_tags')) {
        $hashtags = creatordb_get_display_content_tags($post_id, ['limit' => $limit]);
    } else {
        $terms = get_the_terms($post_id, 'content_tag');
        $hashtags = [];
        if (!empty($terms) && !is_wp_error($terms)) {
            $hashtags = wp_list_pluck($terms, 'name');
        }
        $hashtags = array_slice($hashtags, 0, $limit);
    }

    $html = '<div class="influencer-hashtags">';
    $html .= '<div class="influencer-hashtags-title">';
    $html .= esc_html__('HASHTAGS', 'hello-elementor-child');
    $html .= '</div>';
    $html .= render_hashtag_cloud($hashtags, $limit, true);
    $html .= '</div>';

    return $html;
}

add_shortcode('influencer_hashtags', 'shortcode_influencer_hashtags');


/**
 * Computes the follower/subscriber growth from the latest 2 months of a platform's history,
 * shared by shortcode_influencer_follower_growth() and trb_build_platform_stats_map() so the
 * static and live-switched values can never drift apart.
 *
 * @return array{formatted:string,latest_date:?string,past_date:?string}|null Null when growth
 *         cannot be determined (insufficient history and no IC fallback available).
 */
function trb_platform_follower_growth_display($post_id, $platform = 'instagram')
{
    $post_id = intval($post_id);
    $platform = in_array($platform, ['instagram', 'youtube', 'tiktok'], true) ? $platform : 'instagram';

    if (empty($post_id)) {
        return null;
    }

    $history = function_exists('trb_platform_history_rows')
        ? trb_platform_history_rows($post_id, $platform)
        : get_post_meta($post_id, 'creatordb_history', true);

    if (empty($history) || ! is_array($history) || count($history) < 2) {
        if ($platform === 'instagram' && get_post_meta($post_id, 'source_provider', true) === 'influencers_club') {
            $club_growth = get_post_meta($post_id, 'instagram_creator_follower_growth_3_months_ago', true);
            if ($club_growth !== '' && $club_growth !== null && is_numeric($club_growth)) {
                $growth_percent = (float) $club_growth;
                $formatted_percent = ($growth_percent > 0 ? '+' : '') . number_format($growth_percent, 2) . '%';

                return ['formatted' => $formatted_percent, 'latest_date' => null, 'past_date' => null];
            }
        }

        return null;
    }

    // Sort the array by timestamp_ms in descending order to ensure index 0 is always the latest.
    usort($history, function ($a, $b) {
        return $b['timestamp_ms'] <=> $a['timestamp_ms'];
    });

    // The latest data point is now guaranteed to be at the start of the array.
    $latest_entry = $history[0];
    $latest_followers = intval($latest_entry['followers']);

    try {
        // Instantiate DateTime for the latest entry to accurately subtract 1 calendar month.
        $latest_date = new DateTime($latest_entry['date']);
        $target_date = clone $latest_date;
        $target_date->modify('-1 month'); // Modified to target a 1-month delta
        $target_timestamp = $target_date->getTimestamp();
    } catch (Exception $e) {
        return null;
    }

    // Initialize variables to find the closest historical entry to our target date.
    $closest_entry = null;
    $shortest_diff = PHP_INT_MAX;

    /**
     * Iterate through the history array to find the entry closest to exactly 1 month ago.
     * We calculate the absolute difference in seconds to find the nearest neighbor.
     */
    foreach ($history as $entry) {
        $entry_timestamp = strtotime($entry['date']);

        if (false === $entry_timestamp) {
            continue; // Skip malformed dates.
        }

        $diff = abs($target_timestamp - $entry_timestamp);

        if ($diff < $shortest_diff) {
            $shortest_diff = $diff;
            $closest_entry = $entry;
        }
    }

    if (null === $closest_entry) {
        return null;
    }

    // Calculate the actual metrics.
    $past_followers = intval($closest_entry['followers']);
    $growth_count   = $latest_followers - $past_followers;

    // Calculate the raw decimal variance to pass into the strict-typed helper function.
    $raw_decimal_growth = ($past_followers > 0) ? (float) ($growth_count / $past_followers) : 0.0;

    // Prefix with a '+' for positive growth, then append the percentage string returned by the helper.
    $formatted_percent = ($raw_decimal_growth > 0 ? '+' : '') . convertDecimalToPercentage($raw_decimal_growth);

    return [
        'formatted'        => $formatted_percent,
        'latest_date'      => $latest_entry['date'],
        'past_date'        => $closest_entry['date'],
        'latest_followers' => $latest_followers,
        'past_followers'   => $past_followers,
    ];
}

/**
 * Calculates and outputs the follower growth from the latest 2 months.
 *
 * Usage: [creatordb_follower_growth] (Uses current post ID)
 * Usage: [creatordb_follower_growth post_id="123"] (Uses specific post ID)
 *
 * @param array $atts Shortcode attributes.
 * @return string The formatted HTML output displaying raw growth and percentage, or N/A if data is unavailable.
 */
function shortcode_influencer_follower_growth($atts)
{
    // Define a consistent fallback output for missing data to maintain DOM structure.
    // Carries the same platform-stat/data-metric hooks as the success case so a subsequent
    // platform switch can still populate it once that platform has growth data.
    $na_output = '<span class="platform-stat creatordb-follower-growth" data-metric="follower_growth">N/A</span>';

    // Extract shortcode attributes with sensible defaults.
    $args = shortcode_atts(
        array(
            'post_id'  => get_the_ID(),
            'platform' => 'instagram',
        ),
        $atts
    );

    $post_id = intval($args['post_id']);
    $platform = in_array($args['platform'], ['instagram', 'youtube', 'tiktok'], true) ? $args['platform'] : 'instagram';

    if (empty($post_id)) {
        return $na_output;
    }

    $growth = trb_platform_follower_growth_display($post_id, $platform);

    if ($growth === null) {
        return $na_output;
    }

    $date_attrs = '';
    if (!empty($growth['latest_date'])) {
        $date_attrs .= ' data-latest-date="' . esc_attr($growth['latest_date']) . '"';
    }
    if (!empty($growth['past_date'])) {
        $date_attrs .= ' data-past-date="' . esc_attr($growth['past_date']) . '"';
    }

    // Output wrapped in a span for easy front-end styling, returning exclusively the percentage string.
    return sprintf(
        '<span class="platform-stat creatordb-follower-growth" data-metric="follower_growth"%s>%s</span>',
        $date_attrs,
        esc_html($growth['formatted'])
    );
}
add_shortcode('influencer_follower_growth', 'shortcode_influencer_follower_growth');

/**
 * Builds the current formatted stat values for one platform, keyed the same as
 * trb_platform_stat_metric_map() plus 'follower_growth'. Consumed by
 * DD_Follower_Growth_Chart::enqueue_scripts() (modules/frontend-utilities/charts.php) to localize
 * ddPlatformStats, which ddPlatformSwitcher.set() uses to rewrite every .platform-stat[data-metric]
 * element on the page when a platform button is clicked. Each value is resolved through the exact
 * same metric map + formatters as the stat shortcodes above, so a switched value always equals what
 * the equivalent shortcode would render with platform="$platform".
 *
 * @return array<string,string> metric => formatted value (metrics with no usable data are omitted,
 *         so the JS leaves the corresponding span untouched rather than blanking it).
 */
function trb_build_platform_stats_map($post_id, $platform = 'instagram')
{
    $post_id = (int) $post_id;
    $platform = in_array($platform, ['instagram', 'youtube', 'tiktok'], true) ? $platform : 'instagram';

    $stats = [];

    foreach (trb_platform_stat_metric_map() as $metric => $config) {
        $raw = trb_resolve_platform_stat_raw($post_id, $platform, $metric);

        if ($raw === '' || $raw === null || $raw === false) {
            continue;
        }

        if ($config['format'] === 'percent') {
            $stats[$metric] = convertDecimalToPercentage(floatval($raw));
        } else {
            $stats[$metric] = wp_custom_number_format_short($raw);
        }
    }

    $growth = trb_platform_follower_growth_display($post_id, $platform);
    if ($growth !== null) {
        $stats['follower_growth'] = $growth['formatted'];
    }

    return $stats;
}

/**
 * Calculate Platform Score for an influencer (0-100).
 * Uses: followers, engagement rate, growth rate (30-day), avg posts per day.
 * Meta: followers, engagerate, followersgrowth, creatordb_history.
 *
 * @param int|null $post_id Influencer post ID (default: current post).
 * @return array{score: int, label: string, breakdown: array, engagement_rate: float, growth_rate: float|null, posts_per_month: float}|null
 */
function calculate_influencer_platform_score($post_id = null)
{
    $post_id = $post_id ?: get_the_ID();
    if (!$post_id || get_post_type($post_id) !== 'influencer') {
        return null;
    }

    $followers = (int) get_post_meta($post_id, 'followers', true);
    if ($followers <= 0) {
        return null;
    }

    $history = function_exists('trb_instagram_history_rows')
        ? trb_instagram_history_rows($post_id)
        : (array) get_post_meta($post_id, 'creatordb_history', true);

    // 1. Engagement rate: use meta or derive from history
    $engagement_rate = (float) get_post_meta($post_id, 'engagerate', true);
    if ($engagement_rate <= 0 && is_array($history) && !empty($history)) {
        $latest = end($history);
        $avglikes   = (float) ($latest['avglikes'] ?? 0);
        $avgcomments = (float) ($latest['avgcomments'] ?? 0);
        if ($followers > 0) {
            $engagement_rate = (($avglikes + $avgcomments) / $followers) * 100;
        }
    }

    // 2. Growth rate: use followersgrowth meta (assume percentage) or derive from history
    $growth_rate = null;
    $growth_meta = get_post_meta($post_id, 'followersgrowth', true);
    if ($growth_meta !== '' && $growth_meta !== null && is_numeric($growth_meta)) {
        $growth_rate = (float) $growth_meta;
    } elseif (is_array($history) && count($history) >= 2) {
        $rows = function_exists('trb_instagram_history_sort_asc')
            ? trb_instagram_history_sort_asc($history)
            : array_values($history);
        $current  = (int) ($rows[count($rows) - 1]['followers'] ?? 0);
        $previous = null;
        $now_ms   = (int) ($rows[count($rows) - 1]['timestamp_ms'] ?? 0);
        $thirty_days_ms = 30 * 24 * 60 * 60 * 1000;
        for ($i = count($rows) - 2; $i >= 0; $i--) {
            $ts = (int) ($rows[$i]['timestamp_ms'] ?? 0);
            if ($now_ms - $ts >= $thirty_days_ms) {
                $previous = (int) ($rows[$i]['followers'] ?? 0);
                break;
            }
        }
        if ($previous === null && count($rows) >= 2) {
            $previous = (int) ($rows[0]['followers'] ?? 0);
        }
        if ($previous !== null && $previous > 0) {
            $growth_rate = (($current - $previous) / $previous) * 100;
        }
    }

    // 3. Avg posts per day: derive from history rows
    $avg_posts_per_day = 0.0;
    if (is_array($history) && count($history) >= 2) {
        $rows = function_exists('trb_instagram_history_sort_asc')
            ? trb_instagram_history_sort_asc($history)
            : array_values($history);
        $first_ts = (int) ($rows[0]['timestamp_ms'] ?? 0);
        $last_ts  = (int) ($rows[count($rows) - 1]['timestamp_ms'] ?? 0);
        $days = ($last_ts - $first_ts) / (24 * 60 * 60 * 1000);
        if ($days > 0) {
            $first_posts = (int) ($rows[0]['posts'] ?? 0);
            $last_posts  = (int) ($rows[count($rows) - 1]['posts'] ?? 0);
            $total_posts = max(0, $last_posts - $first_posts);
            $avg_posts_per_day = $total_posts / $days;
        }
    }

    $posts_per_month = $avg_posts_per_day * 30;

    // --- Engagement score (40%)
    $engagement_score = 25;
    if ($followers < 50000) {
        if ($engagement_rate >= 4) {
            $engagement_score = 75;
        } elseif ($engagement_rate >= 2) {
            $engagement_score = 50;
        }
    } elseif ($followers < 250000) {
        if ($engagement_rate >= 3) {
            $engagement_score = 75;
        } elseif ($engagement_rate >= 1.5) {
            $engagement_score = 50;
        }
    } elseif ($followers < 1000000) {
        if ($engagement_rate >= 2.5) {
            $engagement_score = 75;
        } elseif ($engagement_rate >= 1) {
            $engagement_score = 50;
        }
    } else {
        if ($engagement_rate >= 2) {
            $engagement_score = 75;
        } elseif ($engagement_rate >= 0.5) {
            $engagement_score = 50;
        }
    }
    $engagement_component = $engagement_score * 0.40;

    // --- Growth score (30%)
    $growth_score = 50;
    if ($growth_rate !== null) {
        if ($growth_rate < -2) {
            $growth_score = 0;
        } elseif ($growth_rate < 0) {
            $growth_score = 40;
        } elseif ($growth_rate < 2) {
            $growth_score = 60;
        } elseif ($growth_rate < 5) {
            $growth_score = 80;
        } else {
            $growth_score = 100;
        }
    }
    $growth_component = $growth_score * 0.30;

    // --- Consistency score (15%)
    if ($posts_per_month >= 6) {
        $consistency_score = 75;
    } elseif ($posts_per_month >= 3) {
        $consistency_score = 50;
    } else {
        $consistency_score = 25;
    }
    $consistency_component = $consistency_score * 0.15;

    // --- Audience score (15%)
    if ($followers >= 1000000) {
        $audience_score = 100;
    } elseif ($followers >= 250000) {
        $audience_score = 85;
    } elseif ($followers >= 50000) {
        $audience_score = 70;
    } elseif ($followers >= 10000) {
        $audience_score = 50;
    } else {
        $audience_score = 30;
    }
    $audience_component = $audience_score * 0.15;

    $final_score = round($engagement_component + $growth_component + $consistency_component + $audience_component);

    // Label ranges per spec: 0-30, 31-50, 51-70, 71-100
    $label = __('Growth Opportunity', 'hello-elementor-child');
    if ($final_score >= 71) {
        $label = __('High Growth', 'hello-elementor-child');
    } elseif ($final_score >= 51) {
        $label = __('Steady Growth', 'hello-elementor-child');
    } elseif ($final_score >= 31) {
        $label = __('Moderate Growth', 'hello-elementor-child');
    } elseif ($final_score >= 0) {
        $label = __('Growth Opportunity', 'hello-elementor-child');
    }

    return [
        'score'           => min(100, max(0, $final_score)),
        'label'           => $label,
        'breakdown'       => [
            'engagement'  => round($engagement_component, 2),
            'growth'      => round($growth_component, 2),
            'consistency' => round($consistency_component, 2),
            'audience'    => round($audience_component, 2),
        ],
        'engagement_rate'  => $engagement_rate,
        'growth_rate'      => $growth_rate,
        'posts_per_month'  => round($posts_per_month, 2),
    ];
}

/**
 * Shortcode: [influencer_platform_score]
 * Displays dynamic Platform score (e.g. 62/100) with styled label tag.
 * Optional attr: show_breakdown="1" for tooltip. show_label="Platform score:" for prefix.
 */
function shortcode_influencer_platform_score($atts)
{
    $atts = shortcode_atts([
        'show_breakdown' => '0',
        'show_label'     => '1',
        'prefix'         => __('Platform score:', 'hello-elementor-child'),
    ], $atts ?? []);
    $post_id = get_the_ID();
    if (get_query_var('current_influencer_id')) {
        $post_id = (int) get_query_var('current_influencer_id');
    }

    $data = calculate_influencer_platform_score($post_id);
    if (!$data) {
        return '<span class="influencer-platform-score influencer-platform-score--empty">—</span>';
    }

    $score = (int) $data['score'];
    $label = $data['label'];
    $tooltip = '';
    if (!empty($atts['show_breakdown'])) {
        $b = $data['breakdown'];
        $tooltip = sprintf(
            "Engagement: %.1f | Growth: %.1f | Consistency: %.1f | Audience: %.1f",
            $b['engagement'],
            $b['growth'],
            $b['consistency'],
            $b['audience']
        );
    }

    // Tier for styling: growth-opportunity, moderate-growth, steady-growth, high-growth
    $tier = 'growth-opportunity';
    if ($score >= 71) {
        $tier = 'high-growth';
    } elseif ($score >= 51) {
        $tier = 'steady-growth';
    } elseif ($score >= 31) {
        $tier = 'moderate-growth';
    }

    $html = '<span class="influencer-platform-score influencer-platform-score--' . esc_attr($tier) . '">';
    if (!empty($atts['show_label']) && !empty($atts['prefix'])) {
        $html .= '<span class="influencer-platform-score-prefix">' . esc_html($atts['prefix']) . ' </span>';
    }
    $html .= '<span class="influencer-platform-score-value">' . $score . '</span>';
    $html .= '<span class="influencer-platform-score-total">/100</span>';
    $icon = '<span class="influencer-platform-score-icon" aria-hidden="true">↗</span>';
    $html .= ' <span class="influencer-platform-score-tag chip">' . $icon . ' ' . esc_html($label) . '</span>';
    if ($tooltip) {
        $html .= ' <span class="influencer-platform-score-info" title="' . esc_attr($tooltip) . '" aria-label="Score breakdown">ℹ</span>';
    }
    $html .= '</span>';

    return $html;
}
add_shortcode('influencer_platform_score', 'shortcode_influencer_platform_score');


function shortcode_instagram_id_fixed($atts)
{

    $atts = shortcode_atts(array(
        'id'  => get_the_ID(),
    ), $atts, 'instagram_id');

    $instagramid = get_post_meta($atts['id'], 'instagramid', true);
    if ($instagramid) {
        return $instagramid;
    } else {
        $instagramid = get_post_meta(get_the_ID(), 'instagramId', true);
        if ($instagramid) {

            return $instagramid;
        } else {
            return '';
        }
    }
}
add_shortcode('instagram_id', 'shortcode_instagram_id_fixed');


/**
 * Returns the current calendar year.
 *
 * This function utilizes the PHP date() function to retrieve the 
 * current 4-digit year. It is hooked into the WordPress shortcode 
 * API to allow usage within post content, widgets, or templates.
 *
 * @return string The current 4-digit year.
 */
function dd_current_year_shortcode()
{
    // Return the current year in 'Y' format (e.g., 2026)
    return date('Y');
}

// Register the shortcode with WordPress
add_shortcode('current_year', 'dd_current_year_shortcode');



/**
 * Shortcode to display current user's PMPro membership level name.
 * Usage: [current_membership_level]
 */
add_shortcode('current_membership_level', 'get_pmpro_membership_level_shortcode');

function get_pmpro_membership_level_shortcode()
{
    // Ensure PMPro is active
    if (! function_exists('pmpro_getMembershipLevelForUser')) {
        return '';
    }

    $current_user_id = get_current_user_id();

    // If user is not logged in, return early
    if (empty($current_user_id)) {
        return __('Guest', 'hello-elementor-child');
    }

    $membership_level = pmpro_getMembershipLevelForUser($current_user_id);

    if (! empty($membership_level)) {
        return esc_html($membership_level->name);
    }

    return __('No Active Membership', 'hello-elementor-child');
}

/**
 * Retrieves and formats raw post content.
 * 
 * This function creates a shortcode that fetches the 'post_content' property 
 * directly from the global $post object. It bypasses 'the_content' filter where 
 * paywalls and credit restrictions are typically injected, allowing the raw text 
 * to render. The wpautop function is applied to maintain basic paragraph structuring.
 * 
 * @global WP_Post $post The current post object.
 * @return string The unfiltered, formatted post content.
 */
function dd_raw_post_content_shortcode()
{
    global $post;

    // Verify that a valid post object exists before attempting to access its properties.
    if (! $post) {
        return '';
    }

    // Return the raw post content, wrapped in standard paragraph tags for readability.
    return wpautop($post->post_content);
}
add_shortcode('raw_post_content', 'dd_raw_post_content_shortcode');


/**
 * Shortcode to display an "Unlocked" badge when the current user has unlocked
 * the influencer. Renders nothing if the influencer is still locked.
 *
 * Relies on is_influencer_unlocked() defined in includes/core/helpers.php.
 *
 * Usage: [influencer_unlocked_badge] or [influencer_unlocked_badge id="123"]
 *
 * @param array $atts Optional. 'id' overrides the current post ID.
 * @return string The badge SVG markup, or an empty string when locked.
 */
function shortcode_influencer_unlocked_badge($atts)
{
    $atts = shortcode_atts([
        'id' => get_the_ID(),
    ], $atts, 'influencer_unlocked_badge');

    $influencer_id = absint($atts['id']);

    if (! $influencer_id || ! function_exists('is_influencer_unlocked')) {
        return '';
    }

    if (! is_influencer_unlocked($influencer_id)) {
        return '';
    }

    return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="139" height="23" viewBox="0 0 139 23">
  <defs>
    <linearGradient id="linear-gradient" x1="0.857" y1="-0.44" x2="0.106" y2="1.587" gradientUnits="objectBoundingBox">
      <stop offset="0" stop-color="#ecf799"/>
      <stop offset="0.297" stop-color="#caf7a1"/>
      <stop offset="0.674" stop-color="#d6f79e"/>
      <stop offset="1" stop-color="#bbf7a6"/>
    </linearGradient>
  </defs>
  <g id="profile_with_bg" data-name="profile with bg" transform="translate(-255 -1245)">
    <rect id="Rectangle_399" data-name="Rectangle 399" width="139" height="23" rx="2" transform="translate(255 1245)" fill="#fff"/>
    <g id="unlockedwithbg" transform="translate(-2 680)">
      <rect id="Rectangle_394" data-name="Rectangle 394" width="139" height="23" rx="2" transform="translate(257 565)" opacity="0.5" fill="url(#linear-gradient)"/>
      <g id="unlocked" transform="translate(262.904 569.006)">
        <g id="Group_1198" data-name="Group 1198" transform="translate(21.522 1.125)">
          <path id="Path_361" data-name="Path 361" d="M-52.486,0V-7.273h2.727a2.9,2.9,0,0,1,1.408.313,2.1,2.1,0,0,1,.863.858,2.573,2.573,0,0,1,.293,1.238,2.565,2.565,0,0,1-.295,1.243,2.1,2.1,0,0,1-.87.854,2.95,2.95,0,0,1-1.417.311h-1.808V-3.54h1.63a1.667,1.667,0,0,0,.8-.17,1.086,1.086,0,0,0,.463-.469,1.5,1.5,0,0,0,.151-.685,1.476,1.476,0,0,0-.151-.682,1.059,1.059,0,0,0-.465-.46,1.732,1.732,0,0,0-.808-.165h-1.207V0Zm6.935,0V-7.273h2.727a3.073,3.073,0,0,1,1.408.291,2,2,0,0,1,.863.815,2.458,2.458,0,0,1,.293,1.22,2.382,2.382,0,0,1-.3,1.213,1.966,1.966,0,0,1-.872.792,3.224,3.224,0,0,1-1.413.279h-1.942V-3.757h1.765a2.017,2.017,0,0,0,.8-.137.953.953,0,0,0,.463-.4,1.3,1.3,0,0,0,.151-.652,1.346,1.346,0,0,0-.153-.662.983.983,0,0,0-.465-.419,1.939,1.939,0,0,0-.806-.144h-1.207V0Zm3.757-3.3,1.8,3.3h-1.47l-1.768-3.3Zm9.747-.341a4.282,4.282,0,0,1-.439,2.012,3.131,3.131,0,0,1-1.193,1.28A3.309,3.309,0,0,1-35.388.1,3.3,3.3,0,0,1-37.1-.346a3.144,3.144,0,0,1-1.193-1.282,4.274,4.274,0,0,1-.439-2.008,4.282,4.282,0,0,1,.439-2.012A3.131,3.131,0,0,1-37.1-6.928a3.309,3.309,0,0,1,1.71-.444,3.309,3.309,0,0,1,1.71.444,3.131,3.131,0,0,1,1.193,1.28A4.282,4.282,0,0,1-32.047-3.636Zm-1.325,0a3.387,3.387,0,0,0-.257-1.4,1.944,1.944,0,0,0-.712-.863,1.891,1.891,0,0,0-1.048-.293,1.891,1.891,0,0,0-1.048.293,1.944,1.944,0,0,0-.712.863,3.387,3.387,0,0,0-.257,1.4,3.387,3.387,0,0,0,.257,1.4,1.944,1.944,0,0,0,.712.863,1.891,1.891,0,0,0,1.048.293,1.891,1.891,0,0,0,1.048-.293,1.944,1.944,0,0,0,.712-.863A3.387,3.387,0,0,0-33.371-3.636ZM-30.3,0V-7.273h4.659v1.1h-3.345l0,1.974,3.018,0,0,1.1h-3.026l0,3.089Zm7.67-7.273,0,7.273h-1.317V-7.273ZM-20.692,0V-7.273h1.314l0,6.168h3.2V0Zm6.161,0V-7.273h4.727l0,1.1h-3.416l0,1.974,3.164,0,0,1.1h-3.171l0,1.985h3.437l0,1.1ZM-.389-7.273H.928v4.751A2.555,2.555,0,0,1,.561-1.147,2.5,2.5,0,0,1-.471-.222,3.429,3.429,0,0,1-2.023.11,3.437,3.437,0,0,1-3.578-.222a2.483,2.483,0,0,1-1.03-.925,2.564,2.564,0,0,1-.366-1.374V-7.273h1.317v4.641a1.617,1.617,0,0,0,.2.81,1.422,1.422,0,0,0,.566.556,1.775,1.775,0,0,0,.866.2,1.785,1.785,0,0,0,.868-.2,1.407,1.407,0,0,0,.566-.556,1.629,1.629,0,0,0,.2-.81Zm9.225,0L8.839,0H7.668L4.241-4.954l-.064,0L4.18,0H2.863V-7.273H4.042L7.465-2.315l.067,0,0-4.961ZM10.771,0V-7.273h1.314l0,6.168h3.2V0ZM23.146-3.636a4.282,4.282,0,0,1-.439,2.012,3.131,3.131,0,0,1-1.193,1.28A3.309,3.309,0,0,1,19.8.1a3.3,3.3,0,0,1-1.71-.446A3.144,3.144,0,0,1,16.9-1.628a4.274,4.274,0,0,1-.439-2.008A4.282,4.282,0,0,1,16.9-5.648a3.131,3.131,0,0,1,1.193-1.28,3.309,3.309,0,0,1,1.71-.444,3.309,3.309,0,0,1,1.71.444,3.131,3.131,0,0,1,1.193,1.28A4.282,4.282,0,0,1,23.146-3.636Zm-1.325,0a3.387,3.387,0,0,0-.257-1.4,1.944,1.944,0,0,0-.712-.863A1.891,1.891,0,0,0,19.8-6.19a1.891,1.891,0,0,0-1.048.293,1.944,1.944,0,0,0-.712.863,3.387,3.387,0,0,0-.257,1.4,3.387,3.387,0,0,0,.257,1.4,1.944,1.944,0,0,0,.712.863,1.891,1.891,0,0,0,1.048.293,1.891,1.891,0,0,0,1.048-.293,1.944,1.944,0,0,0,.712-.863A3.387,3.387,0,0,0,21.821-3.636Zm9.3-1.183H29.793a1.671,1.671,0,0,0-.21-.581,1.566,1.566,0,0,0-.38-.431,1.614,1.614,0,0,0-.517-.268,2.076,2.076,0,0,0-.623-.091,1.891,1.891,0,0,0-1.051.3,1.97,1.97,0,0,0-.717.866,3.347,3.347,0,0,0-.259,1.39,3.366,3.366,0,0,0,.261,1.4,1.939,1.939,0,0,0,.717.859,1.911,1.911,0,0,0,1.046.289,2.094,2.094,0,0,0,.613-.087,1.669,1.669,0,0,0,.515-.257,1.56,1.56,0,0,0,.387-.419,1.6,1.6,0,0,0,.218-.568l1.328.007a2.883,2.883,0,0,1-.321.973,2.872,2.872,0,0,1-.645.8,2.919,2.919,0,0,1-.93.54A3.442,3.442,0,0,1,28.042.1,3.306,3.306,0,0,1,26.33-.344a3.1,3.1,0,0,1-1.186-1.282,4.328,4.328,0,0,1-.433-2.01,4.3,4.3,0,0,1,.437-2.012,3.124,3.124,0,0,1,1.19-1.28,3.292,3.292,0,0,1,1.7-.444,3.618,3.618,0,0,1,1.129.17,2.912,2.912,0,0,1,.93.5,2.717,2.717,0,0,1,.673.8A3.025,3.025,0,0,1,31.121-4.819ZM32.832,0V-7.273h1.317v3.342h.089l2.837-3.342h1.609L35.871-4.009,38.709,0H37.125l-2.17-3.118-.806.952V0ZM40.1,0V-7.273h4.727l0,1.1H41.414l0,1.974,3.164,0,0,1.1H41.414l0,1.985h3.438l0,1.1Zm9.04,0H46.676V-7.273H49.19a3.765,3.765,0,0,1,1.863.435,2.934,2.934,0,0,1,1.2,1.248,4.2,4.2,0,0,1,.419,1.946A4.217,4.217,0,0,1,52.25-1.69,2.934,2.934,0,0,1,51.039-.437,3.868,3.868,0,0,1,49.141,0ZM47.994-1.14h1.083a2.609,2.609,0,0,0,1.268-.279,1.761,1.761,0,0,0,.763-.835,3.326,3.326,0,0,0,.256-1.39,3.3,3.3,0,0,0-.256-1.387,1.757,1.757,0,0,0-.755-.827,2.535,2.535,0,0,0-1.238-.275H47.994Z" transform="translate(53 10)" fill="#034146"/>
        </g>
        <g id="Group_1199" data-name="Group 1199" transform="translate(0 0.869)">
          <g id="padlock-unlocked-svgrepo-com">
            <path id="Path_94" data-name="Path 94" d="M4.5,11.125c0,1.271,0,3.925,0,5.7,0,1.967,3.043,2.412,5.481,2.412s5.481-.445,5.481-2.412v-5.7a.729.729,0,0,0-.731-.729h-9.5A.729.729,0,0,0,4.5,11.125Zm4.385,3.369a1.172,1.172,0,0,0,.391.875V16.5a.731.731,0,0,0,.731.731h.1a.731.731,0,0,0,.731-.731V15.369a1.174,1.174,0,1,0-1.957-.875Z" transform="translate(-4.5 -4.991)" fill="#034146" fill-rule="evenodd"/>
            <path id="Path_95" data-name="Path 95" d="M9.182,8.629h-2v-3A3.6,3.6,0,0,1,8.242,3.1a3.939,3.939,0,0,1,5.46,0,3.6,3.6,0,0,1,1.06,2.527,1,1,0,0,1-2,0A1.694,1.694,0,0,0,10.972,4a1.8,1.8,0,0,0-1.316.514,1.591,1.591,0,0,0-.474,1.113Z" transform="translate(-5.491 -3)" fill="#034146"/>
          </g>
          <path id="Icon_awesome-check" data-name="Icon awesome-check" d="M3.51,12.133.151,8.774a.517.517,0,0,1,0-.731l.731-.731a.517.517,0,0,1,.731,0L3.876,9.575,8.722,4.729a.517.517,0,0,1,.731,0l.731.731a.517.517,0,0,1,0,.731L4.241,12.133A.517.517,0,0,1,3.51,12.133Z" transform="translate(6.476 -2.963)" fill="#f9c23c"/>
        </g>
      </g>
    </g>
  </g>
</svg>
SVG;
}
add_shortcode('influencer_unlocked_badge', 'shortcode_influencer_unlocked_badge');

/**
 * Resolve the visiting client's IP, honouring common proxy/CDN headers.
 *
 * @return string A validated public IP, or '' when none can be determined.
 */
function dd_get_client_ip() {
    $keys = array(
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    );

    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        // X-Forwarded-For can be a comma list "client, proxy1, proxy2".
        $ip = trim(explode(',', $_SERVER[$key])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

/**
 * Look up the ISO 3166-1 alpha-2 country code for an IP via a free geo API,
 * cached per-IP in a transient so we hit the network at most once per IP/week.
 *
 * @param string $ip Optional IP; defaults to the current visitor.
 * @return string Two-letter country code (e.g. "GB"), or '' if unknown.
 */
function dd_geolocate_country_code($ip = '') {
    if ($ip === '') {
        $ip = dd_get_client_ip();
    }
    // No IP, or a private/reserved address (local/dev) — nothing to geolocate.
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }

    $cache_key = 'dd_geo_cc_' . md5($ip);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached === 'none' ? '' : $cached;
    }

    $country = '';
    $response = wp_remote_get('https://ipapi.co/' . rawurlencode($ip) . '/country/', array(
        'timeout' => 3,
    ));

    if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
        $body = strtoupper(trim(wp_remote_retrieve_body($response)));
        if (preg_match('/^[A-Z]{2}$/', $body)) {
            $country = $body;
        }
    }

    // Cache successes for a week; cache failures briefly so a transient outage
    // or rate-limit doesn't wedge every subsequent request for a week.
    set_transient(
        $cache_key,
        $country === '' ? 'none' : $country,
        $country === '' ? HOUR_IN_SECONDS : WEEK_IN_SECONDS
    );

    return $country;
}

/**
 * Currency indicator, resolved from the visitor's geolocated country.
 * Falls back to USD for any country not in the map.
 */
function shortcode_currency() {
    $map = array(
        'GB' => 'GBP', // United Kingdom
        'US' => 'USD', // United States
        'AU' => 'AUD', // Australia
        'CA' => 'CAD', // Canada
    );

    // Euro-area countries (eurozone members + microstates that use the euro).
    $eur_countries = array(
        'AT', 'BE', 'HR', 'CY', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PT', 'SK', 'SI', 'ES',
        'AD', 'MC', 'SM', 'VA', 'ME', 'XK',
    );
    foreach ($eur_countries as $eur_country) {
        $map[$eur_country] = 'EUR';
    }

    $country = dd_geolocate_country_code();

    return isset($map[$country]) ? $map[$country] : 'USD';
}
add_shortcode('currency', 'shortcode_currency');