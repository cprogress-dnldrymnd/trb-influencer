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


/**
 * CONFIGURATION: Point definitions for Membership Levels.
 * * Edit this function to define how many points each level ID receives.
 * Format: Level ID => [ 'registration' => int, 'monthly' => int ]
 *
 * @return array
 */
function dd_get_pmpro_point_rewards() {
	return array(
		1 => array( // Level ID 1
			'registration' => 100, // Points awarded immediately on signup
			'monthly'      => 50,  // Points awarded every 30 days
		),
		2 => array( // Level ID 2
			'registration' => 250,
			'monthly'      => 100,
		),
		3 => array( // Level ID 3
			'registration' => 500,
			'monthly'      => 200,
		),
	);
}

/**
 * 1. REGISTRATION REWARDS
 * * Hook into PMPro after checkout to award initial points and 
 * initialize the recurring timer.
 *
 * @param int $user_id The ID of the user who just checked out.
 * @param object $level The membership level object.
 */
function dd_pmpro_award_registration_points( $user_id, $level ) {
	// Ensure myCred is active
	if ( ! function_exists( 'mycred_add' ) ) {
		return;
	}

	$rewards = dd_get_pmpro_point_rewards();
	$level_id = isset( $level->id ) ? $level->id : 0;

	// Check if this level has defined rewards
	if ( isset( $rewards[ $level_id ] ) ) {
		
		// 1. Award Registration Points
		$reg_points = $rewards[ $level_id ]['registration'];
		
		if ( $reg_points > 0 ) {
			mycred_add(
				'pmpro_registration',
				$user_id,
				$reg_points,
				sprintf( 'Bonus for joining Membership Level %d', $level_id ),
				$level_id
			);
		}

		// 2. Initialize the "Last Awarded" meta to NOW.
		// This ensures the first "monthly" recurring point batch happens 
		// 30 days from today, not immediately.
		update_user_meta( $user_id, '_dd_last_monthly_point_date', current_time( 'timestamp' ) );
	}
}
add_action( 'pmpro_after_checkout', 'dd_pmpro_award_registration_points', 10, 2 );

/**
 * 2. RECURRING MONTHLY REWARDS (CRON SETUP)
 * * Schedule a daily event to check for users eligible for monthly points.
 */
function dd_pmpro_schedule_daily_cron() {
	if ( ! wp_next_scheduled( 'dd_pmpro_daily_points_check' ) ) {
		wp_schedule_event( time(), 'daily', 'dd_pmpro_daily_points_check' );
	}
}
add_action( 'init', 'dd_pmpro_schedule_daily_cron' );

/**
 * Cron Callback: Process Monthly Points
 * * Iterates through active members of configured levels and checks if
 * 30 days have passed since their last point award.
 */
function dd_process_pmpro_monthly_points() {
	// Ensure myCred and PMPro functions exist
	if ( ! function_exists( 'mycred_add' ) || ! function_exists( 'pmpro_getMembershipUsers' ) ) {
		return;
	}

	$rewards = dd_get_pmpro_point_rewards();
	$now     = current_time( 'timestamp' );

	// Loop through each configured level
	foreach ( $rewards as $level_id => $points_data ) {
		
		$monthly_points = isset( $points_data['monthly'] ) ? $points_data['monthly'] : 0;
		
		// Skip if no monthly points are set for this level
		if ( $monthly_points <= 0 ) {
			continue;
		}

		// Get all active users for this level
		// Note: Returns an array of User IDs
		$active_users = pmpro_getMembershipUsers( $level_id );

		if ( ! empty( $active_users ) ) {
			foreach ( $active_users as $user_id ) {
				
				// Retrieve the last time we gave this user points
				$last_awarded = get_user_meta( $user_id, '_dd_last_monthly_point_date', true );

				// Logic:
				// If meta doesn't exist, we assume they are a legacy user or manually added. 
				// We set the timestamp to now so they get their first batch in 30 days 
				// (preventing an instant award if you just installed the plugin).
				if ( empty( $last_awarded ) ) {
					update_user_meta( $user_id, '_dd_last_monthly_point_date', $now );
					continue;
				}

				// Calculate difference
				// 30 days * 24 hours * 60 mins * 60 seconds = 2592000
				$days_30_in_seconds = 2592000;

				if ( ( $now - $last_awarded ) >= $days_30_in_seconds ) {
					
					// Award the points
					mycred_add(
						'pmpro_monthly_recurring',
						$user_id,
						$monthly_points,
						sprintf( 'Monthly Loyalty: Membership Level %d', $level_id ),
						$level_id
					);

					// Update the last awarded date to NOW to reset the 30-day timer
					update_user_meta( $user_id, '_dd_last_monthly_point_date', $now );
				}
			}
		}
	}
}
add_action( 'dd_pmpro_daily_points_check', 'dd_process_pmpro_monthly_points' );

/**
 * Cleanup: Clear scheduled hook upon plugin deactivation
 */
register_deactivation_hook( __FILE__, 'dd_pmpro_deactivation' );
function dd_pmpro_deactivation() {
	$timestamp = wp_next_scheduled( 'dd_pmpro_daily_points_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'dd_pmpro_daily_points_check' );
	}
}