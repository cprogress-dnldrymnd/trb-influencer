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
        $query->set( 'post__in', $recently_viewed );

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


add_action( 'elementor/query/influencer_search', function( $query ) {
    
    // Arrays to hold our conditions
    $meta_query = array();
    $tax_query = array();

    // 1. Check for 'color' in URL and add to Meta Query
    if ( isset( $_GET['color'] ) && !empty( $_GET['color'] ) ) {
        $meta_query[] = array(
            'key'     => 'product_color', // Your actual meta key
            'value'   => sanitize_text_field( $_GET['color'] ),
            'compare' => '=',
        );
    }

    // 2. Check for 'cat' in URL and add to Tax Query
    if ( isset( $_GET['cat'] ) && !empty( $_GET['cat'] ) ) {
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => sanitize_text_field( $_GET['cat'] ),
        );
    }

    // 3. Apply the queries if they exist
    if ( ! empty( $meta_query ) ) {
        $query->set( 'meta_query', $meta_query );
    }

    if ( ! empty( $tax_query ) ) {
        $query->set( 'tax_query', $tax_query );
    }
} );