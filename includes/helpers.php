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
