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