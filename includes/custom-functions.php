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
