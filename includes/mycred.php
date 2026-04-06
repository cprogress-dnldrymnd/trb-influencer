<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Triggers a custom myCred notification popup for a specific user.
 *
 * This function utilizes the built-in mycred_add_new_notice() function to inject 
 * a custom message into the user's notification transient queue. The myCred 
 * Notifications add-on will render this message on the subsequent page load.
 *
 * @param int    $user_id The ID of the user to receive the notification.
 * @param string $message The HTML/text content of the notification.
 * @param int    $life    The lifespan of the transient in days. Default is 1.
 * @return bool           True on success, false on failure or if the dependency is missing.
 */
function dd_trigger_mycred_notice($user_id, $message, $life = 1)
{
    // Validate required parameters and ensure the myCred Notifications module is active.
    if (empty($user_id) || empty($message) || ! function_exists('mycred_add_new_notice')) {
        return false;
    }

    // Construct the notice array structure expected by myCred.
    $notice = array(
        'user_id' => absint($user_id),
        'message' => wp_kses_post($message),
    );

    // Push the notice to the transient queue.
    mycred_add_new_notice($notice, $life);

    return true;
}

/**
 * Deducts myCRED points from the currently logged-in user.
 *
 * @param float|int $points    The amount of points to deduct.
 * @param string    $log_entry The log entry description visible in the user's history.
 * @param string    $reference A unique reference slug for this transaction type.
 * @return bool|string         Returns true/string on success, or false if it fails (e.g., user logged out).
 */
function deduct_points_from_current_user($points, $log_entry = 'Points deducted', $reference = 'custom_deduction')
{

    // Check if the myCRED plugin API is loaded
    if (! function_exists('mycred_subtract')) {
        return false;
    }

    // Retrieve the ID of the current logged-in user
    $user_id = get_current_user_id();

    // Abort if no user is logged in
    if (! $user_id) {
        return false;
    }

    // Ensure the points value is a positive number (mycred_subtract requires positive inputs)
    $amount_to_deduct = abs((float) $points);

    // Execute the deduction
    $success = mycred_subtract(
        $reference,         // The reference ID 
        $user_id,           // The user ID
        $amount_to_deduct,  // The amount to deduct
        $log_entry          // The log template
    );

    return $success;
}

/**
 * Retrieves the remaining (current) myCred points balance for the currently logged-in user.
 *
 * This function utilizes standard WordPress core functions to verify the user state
 * and the myCred API to fetch the active balance. It specifically targets the 
 * remaining points available for use, rather than the historical total.
 * * @param string $point_type_key (Optional) The specific point type key to query in a multi-point setup. 
 * Defaults to 'mycred_default' (the standard point type).
 * @return float|int|bool The user's current remaining balance as a raw number. 
 * Returns false if the user is not logged in, has an invalid ID, 
 * or is explicitly excluded from using myCred.
 */
function get_current_user_remaining_mycred_balance($point_type_key = 'mycred_default')
{
    // Prevent processing overhead if the session does not belong to an authenticated user
    if (! is_user_logged_in()) {
        return false;
    }

    // Retrieve the active user's WordPress ID
    $user_id = get_current_user_id();

    // Query the myCred API for the current unformatted balance (remaining points)
    $remaining_balance = mycred_get_users_balance($user_id, $point_type_key);

    return $remaining_balance;
}