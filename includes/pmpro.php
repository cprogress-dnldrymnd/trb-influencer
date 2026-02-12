<?php
/**
 * Shortcode to display current user's PMPro membership level name.
 * Usage: [current_membership_level]
 */
add_shortcode( 'current_membership_level', 'get_pmpro_membership_level_shortcode' );

function get_pmpro_membership_level_shortcode() {
    // Ensure PMPro is active
    if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
        return '';
    }

    $current_user_id = get_current_user_id();
    
    // If user is not logged in, return early
    if ( empty( $current_user_id ) ) {
        return 'Guest';
    }

    $membership_level = pmpro_getMembershipLevelForUser( $current_user_id );

    if ( ! empty( $membership_level ) ) {
        return esc_html( $membership_level->name );
    }

    return 'No Active Membership';
}

