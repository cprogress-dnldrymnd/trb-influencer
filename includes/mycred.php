<?php
/**
 * Plugin Name: myCred PMPro Deduplication
 * Description: Prevents double points for PMPro memberships by checking existing DB logs.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.1
 */

add_filter( 'mycred_run_this', 'dd_prevent_mycred_pmpro_db_duplicates', 10, 3 );

function dd_prevent_mycred_pmpro_db_duplicates( $run, $mycred, $entry ) {
    
    // 1. CONFIGURATION: Set this to the exact Reference ID from your logs
    // Common IDs: 'reward_purchase_membership', 'pmpro_membership', 'new_membership'
    $target_ref = 'reward_purchase_membership'; 

    // If the hook firing isn't the one we care about, exit immediately.
    if ( $entry['ref'] !== $target_ref ) {
        return $run;
    }

    // 2. SETUP: specific user and time window
    global $wpdb;
    $log_table = $mycred->log_table;
    $user_id   = absint( $entry['user_id'] );
    $time_window = 15; // Look back 15 seconds
    $now       = current_time( 'timestamp' );
    $limit     = $now - $time_window;

    // 3. QUERY: Check if we have ALREADY written a log for this specific reference recently
    // We strictly check the user_id, the specific reference, and the timestamp.
    $duplicate_check = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$log_table} 
         WHERE user_id = %d 
         AND ref = %s 
         AND time > %d 
         LIMIT 1",
        $user_id, 
        $target_ref, 
        $limit
    ) );

    // 4. ACTION: If a log entry exists, BLOCK this new attempt.
    if ( $duplicate_check ) {
        return false; 
    }

    return $run;
}