<?php
/**
 * Update the query to fetch only recently viewed post IDs.
 *
 * @since 1.0.0
 * @param \WP_Query $query The WordPress query instance.
 */
function recently_view_influencers( $query ) {

    // 1. Get the array of IDs
    $recently_viewed = get_recent_influencer_ids_array( 5 );

    // 2. Check if we actually have IDs to show
    if ( ! empty( $recently_viewed ) ) {
        // Only fetch posts that match these IDs
        $query->set( 'post__in', array(1) );

        // Optional: Ensure they display in the order they were viewed
        $query->set( 'orderby', 'post__in' );
        
        // Ensure pagination doesn't interfere if you want exactly these 5
        $query->set( 'posts_per_page', 5 ); 
    } else {
        // 3. If no history exists, force the query to return nothing
        // (Otherwise, WP might default to showing the latest posts)
        $query->set( 'post__in', array( 0 ) );
    }
}
add_action( 'elementor/query/recently_view_influencers', 'recently_view_influencers' );