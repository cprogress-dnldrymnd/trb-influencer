<?php
function influencer_avatar_shortcode() {
    // Get the current post ID
    $post_id = get_the_ID();

    // Try to get the URL from the 'avatar' meta key
    $url = get_post_meta( $post_id, 'avatar', true );

    // If meta is empty, get the URL of the fallback image (Media ID 1843)
    if ( empty( $url ) ) {
        $url = wp_get_attachment_url( 1843 );
    }

    // If we found a URL (either from meta or fallback), return the image tag
    if ( $url ) {
        return '<img src="' . esc_url( $url ) . '" class="influencer-avatar" alt="Influencer Avatar" />';
    }

    return ''; // Return nothing if URL is invalid
}
add_shortcode( 'influencer_avatar', 'influencer_avatar_shortcode' );

/**
 * Shortcode to display Country Flag and Code from Meta
 * Usage: [country_with_flag]
 */
function get_country_flag_from_meta() {
    // 1. Get the current post ID
    $post_id = get_the_ID();

    // 2. Retrieve the country code from the 'country' meta key
    // Ensure it's stored as an ISO 3166-1 alpha-2 code (e.g., 'US', 'PH', 'GB')
    $country_code = get_post_meta( $post_id, 'country', true );

    // 3. Check if meta exists
    if ( empty( $country_code ) ) {
        return ''; // Return nothing if no country is set
    }

    // 4. Sanitize and format
    // FlagCDN requires lowercase for URLs, but we want Uppercase for the text display
    $code_lower = strtolower( esc_attr( $country_code ) );
    $code_upper = strtoupper( esc_html( $country_code ) );

    // 5. Generate the HTML
    // We use a flex container to align Flag (left) and Text (right)
    $output  = '<div class="meta-country-wrapper" style="display: inline-flex; align-items: center; gap: 8px;">';
    
    // SVG Flag Image from FlagCDN
    $output .= sprintf( 
        '<img src="https://flagcdn.com/%s.svg" alt="%s Flag" style="width: 24px; height: auto; box-shadow: 1px 1px 3px rgba(0,0,0,0.1); border-radius: 2px;">', 
        $code_lower, 
        $code_upper 
    );

    // Country Code Text
    $output .= sprintf( '<span class="country-code-text" style="font-weight: 600;">%s</span>', $code_upper );
    
    $output .= '</div>';

    return $output;
}
add_shortcode( 'influencer_country_with_flag', 'get_country_flag_from_meta' );