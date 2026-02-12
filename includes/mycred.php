<?php/**
 * Prevent myCred Duplicate Points for PMPro (Debounce)
 * * Checks if a user has received points for a specific reference 
 * within the last X seconds to prevent double-firing hooks.
 */
add_filter( 'mycred_run_this', 'dd_prevent_mycred_pmpro_duplicates', 10, 3 );

function dd_prevent_mycred_pmpro_duplicates( $run, $mycred_object, $args ) {
    // 1. Define the specific reference causing the issue.
    // Check your myCred Log to confirm this ID. It is usually 'new_membership' or 'pmpro_purchase'.
    $target_ref = 'new_membership'; 

    // If this isn't the reference we are worried about, exit early.
    if ( $args['ref'] !== $target_ref ) {
        return $run;
    }

    $user_id = $args['user_id'];
    $cache_key = 'mycred_lock_' . $user_id . '_' . $target_ref;

    // 2. Check if we have processed this recently
    if ( get_transient( $cache_key ) ) {
        // We found a lock, so this is a duplicate fire. Block it.
        return false;
    }

    // 3. Set a lock for 60 seconds
    set_transient( $cache_key, true, 60 );

    return $run;
}