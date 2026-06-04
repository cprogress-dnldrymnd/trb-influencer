<?php

/**
 * Helper: Check if the current user has unlocked (purchased) the influencer.
 */
function is_influencer_unlocked($influencer_id)
{
    $user_id = get_current_user_id();

    // 1. Check custom meta from our new unlock function
    $unlocked_meta = get_user_meta($user_id, 'dd_unlocked_influencers', true);
    if (is_array($unlocked_meta) && in_array($influencer_id, $unlocked_meta)) {
        return true;
    }

    // 2. Check existing ecosystem function
    if (function_exists('get_user_purchased_post_ids')) {
        $unlocked_ids = (array) get_user_purchased_post_ids('influencer', true);
        return in_array($influencer_id, $unlocked_ids);
    }

    return false;
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
