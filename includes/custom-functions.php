<?php
/**
 * 1. Track Recently Viewed 'Influencer' Posts (Database Only)
 */
function track_recently_viewed_influencers() {
    // 1. Check if it is the 'influencer' post type
    // 2. Check if the user is logged in
    if ( ! is_singular( 'influencer' ) || ! is_user_logged_in() ) {
        return;
    }

    global $post;
    $current_post_id = $post->ID;
    $user_id         = get_current_user_id();
    $meta_key        = 'recently_viewed_influencers';
    $limit           = 5; // Max items to save

    // Get current list from DB
    $viewed_posts = get_user_meta( $user_id, $meta_key, true );

    if ( ! is_array( $viewed_posts ) ) {
        $viewed_posts = array();
    }

    // Remove current ID if it exists (to prevent duplicates and move to top)
    if ( ( $key = array_search( $current_post_id, $viewed_posts ) ) !== false ) {
        unset( $viewed_posts[$key] );
    }

    // Add current ID to the beginning of the array
    array_unshift( $viewed_posts, $current_post_id );

    // Slice array to keep only the limit
    $viewed_posts = array_slice( $viewed_posts, 0, $limit );

    // Save back to DB
    update_user_meta( $user_id, $meta_key, $viewed_posts );
}
add_action( 'template_redirect', 'track_recently_viewed_influencers' );

/**
 * 2. Shortcode to Display Recently Viewed (Database Only)
 * Usage: [recent_influencers limit="5"]
 */
function display_recently_viewed_influencers_shortcode( $atts ) {
    // If user is not logged in, show nothing
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts( array(
        'limit' => 5,
    ), $atts );

    $user_id    = get_current_user_id();
    $meta_key   = 'recently_viewed_influencers';
    $viewed_ids = get_user_meta( $user_id, $meta_key, true );

    // If no history found, return nothing
    if ( empty( $viewed_ids ) || ! is_array( $viewed_ids ) ) {
        return ''; 
    }

    // Limit the IDs for query
    $viewed_ids = array_slice( $viewed_ids, 0, $atts['limit'] );

    // Query parameters
    $args = array(
        'post_type'      => 'influencer',
        'post_status'    => 'publish',
        'post__in'       => $viewed_ids,
        'orderby'        => 'post__in', // Maintain the order from history
        'posts_per_page' => $atts['limit'],
    );

    $query = new WP_Query( $args );

    ob_start();

    if ( $query->have_posts() ) {
        echo '<div class="recently-viewed-influencers">';
        echo '<h3>Recently Viewed Influencers</h3>';
        echo '<ul>';
        while ( $query->have_posts() ) {
            $query->the_post();
            ?>
            <li>
                <a href="<?php the_permalink(); ?>">
                    <?php if ( has_post_thumbnail() ) {
                        the_post_thumbnail( 'thumbnail', array( 'style' => 'width: 50px; height: auto; vertical-align: middle; margin-right: 10px;' ) );
                    } ?>
                    <?php the_title(); ?>
                </a>
            </li>
            <?php
        }
        echo '</ul>';
        echo '</div>';
    }

    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode( 'recent_influencers', 'display_recently_viewed_influencers_shortcode' );