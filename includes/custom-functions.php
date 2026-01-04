<?php

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


function select_filter($name, $label, $options = [])
{
    ob_start();
?>
    <div class="filter-widget select-filter">
        <div class="header">
            <span><?= $label ?></span>
            <button class="reset-btn">Reset</button>
        </div>

        <div class="dropdown-container">
            <button class="dropdown-button">
                Select your <?= strtolower($label) ?>
                <span class="arrow-holder">
                    <span class="arrow"></span>
                </span>
            </button>

            <div class="dropdown-menu checkbox-lists">
                <?php foreach ($options as $key => $option) {  ?>
                    <label class="dropdown-item checkbox-list-item">
                        <input type="checkbox" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]"> <?= $option ?>
                    </label>
                <?php } ?>
            </div>
        </div>

        <div class="tags-container" ></div>
    </div>

<?php

    return ob_get_clean();
}

function checkbox_filter($name, $label, $options = [])
{
    ob_start();
?>

    <div class="filter-widget checkbox-filter">
        <div class="header">
            <span><?= $label ?></span>
            <button class="reset-btn">Reset</button>
        </div>


        <div class="dropdown-menu checkbox-lists">
            <?php foreach ($options as $key => $option) {  ?>
                <label class="dropdown-item checkbox-list-item">
                    <input type="checkbox" value="<?= $key ?>" data-label="<?= $option ?>" name="<?= $name  ?>[]"> <?= $option ?>
                </label>
            <?php } ?>
        </div>
    </div>

<?php
    return ob_get_clean();
}
