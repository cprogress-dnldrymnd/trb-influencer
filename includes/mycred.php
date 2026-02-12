<?php
/**
 * Filter: mycred_run_this
 * * Intercepts the point awarding process.
 * * UPDATE v1.1.0: Added default values to $mycred and $request to prevent 
 * "Too few arguments" errors if the hook is fired with insufficient parameters.
 *
 * @param bool         $run       Whether to run the point award (true/false).
 * @param object|null  $mycred    The myCred settings object (Optional).
 * @param array        $request   The request arguments (Optional).
 * @return bool                   Returns false to stop execution if duplicate is detected.
 */
add_filter( 'mycred_run_this', 'dd_prevent_duplicate_registration_points', 10, 3 );

function dd_prevent_duplicate_registration_points( $run, $mycred = null, $request = array() ) {
    
    // 1. Safety Guard: Check if required arguments are present.
    // If $mycred or $request are missing (due to the argument count error), 
    // we cannot perform the check, so we return the default $run value to avoid breaking the site.
    if ( ! is_object( $mycred ) || empty( $request ) ) {
        return $run;
    }

    // 2. Check if the current process is a 'registration' event.
    // We strictly look for the 'registration' reference.
    if ( isset( $request['ref'] ) && $request['ref'] === 'registration' ) {

        // 3. Validate that we have a valid User ID.
        $user_id = isset( $request['user_id'] ) ? absint( $request['user_id'] ) : 0;
        
        if ( $user_id ) {
            
            // 4. Query the log to see if this user already has a 'registration' entry.
            global $wpdb;
            
            // Safely retrieve the log table name from the myCred object
            if ( method_exists( $mycred, 'get_log_table' ) ) {
                $log_table = $mycred->get_log_table();
            } else {
                // Fallback if method doesn't exist (rare, but possible in older versions)
                $log_table = $wpdb->prefix . 'myCRED_log';
            }
            
            // Query: Count how many times this user has been awarded points for 'registration'.
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$log_table} WHERE ref = %s AND user_id = %d",
                'registration',
                $user_id
            ));

            // 5. Decision Logic:
            // If the count is greater than 0, the user already has points.
            if ( $count > 0 ) {
                return false;
            }
        }
    }

    // 6. If no duplicate is found, proceed as normal.
    return $run;
}