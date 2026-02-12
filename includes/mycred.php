<?php
/**
 * Filter: mycred_run_this
 * * Intercepts the point awarding process before it executes.
 * We check if the reference is 'registration' and if the user has already
 * received points for this specific action.
 *
 * @param bool   $run       Whether to run the point award (true/false).
 * @param object $mycred    The myCred settings object.
 * @param array  $request   The request arguments (ref, user_id, amount, etc.).
 * @return bool             Returns false to stop execution if duplicate is detected.
 */
add_filter( 'mycred_run_this', 'dd_prevent_duplicate_registration_points', 10, 3 );

function dd_prevent_duplicate_registration_points( $run, $mycred, $request ) {
    
    // 1. Check if the current process is a 'registration' event.
    // We strictly look for the 'registration' reference. If you are using a custom 
    // hook reference (e.g., 'hook_gravity_forms'), update this check accordingly.
    if ( isset( $request['ref'] ) && $request['ref'] === 'registration' ) {

        // 2. Validate that we have a valid User ID.
        $user_id = absint( $request['user_id'] );
        
        if ( $user_id ) {
            
            // 3. Query the log to see if this user already has a 'registration' entry.
            // We use the myCred log object to check for existing entries for this reference and user.
            global $wpdb;
            $log_table = $mycred->get_log_table();
            
            // Query: Count how many times this user has been awarded points for 'registration'.
            // We verify against the 'ref' column and 'user_id' column.
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$log_table} WHERE ref = %s AND user_id = %d",
                'registration',
                $user_id
            ));

            // 4. Decision Logic:
            // If the count is greater than 0, the user already has points.
            // We return `false` to stop myCred from processing this new request.
            if ( $count > 0 ) {
                return false;
            }
        }
    }

    // 5. If no duplicate is found (or it's not a registration event), proceed as normal.
    return $run;
}