<?php

/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0');

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles()
{
    global $search_results_page_id;

    $page_id = get_the_ID();

    wp_enqueue_style('influencer-style', get_stylesheet_directory_uri() . '/style.css');


    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_enqueue_script('influencer-js', get_stylesheet_directory_uri() . '/assets/js/main.js', ['jquery']);
    wp_localize_script('influencer-js', 'ajax_vars', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'page_id' => $page_id,
        'search_results_page_id' => $search_results_page_id,
        'save_search_nonce'    => wp_create_nonce('save_search_nonce'),
        'save_influencer_nonce'    => wp_create_nonce('save_influencer_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20);

// Resolve and cache the directory path once.
// NOTE: Change to get_stylesheet_directory() if this is a child theme.
$dir = get_stylesheet_directory();

// Direct, unrolled require statements. 
// This is the fastest execution path in PHP for procedural files.
require $dir . '/includes/influencers.php';
require $dir . '/includes/hooks.php';
require $dir . '/includes/custom-functions.php';
require $dir . '/includes/brief-parser.php';
require $dir . '/includes/saves-manager.php';
require $dir . '/includes/mycred.php';
require $dir . '/includes/mycred-frontend-log.php';
#require $dir . '/includes/pmpro.php';
require $dir . '/includes/pmpro-mycred-rewards-manager.php';
require $dir . '/includes/email-template-manager.php';
require $dir . '/includes/pmpro-dynamic-pricing.php';
require $dir . '/includes/acf.php';
require $dir . '/includes/sign-up.php';
require $dir . '/includes/elementor.php';
require $dir . '/includes/outreach.php';
require $dir . '/includes/charts.php';
require $dir . '/includes/feeds.php';
require $dir . '/includes/shortcodes.php';
require $dir . '/includes/ajax.php';

function influencers_meta()
{
    ob_start();
?>
    <pre>
    <?php var_dump(get_post_meta(get_the_ID())); ?>
</pre>
<?php
    return ob_get_clean();
}
add_shortcode('influencers_meta', 'influencers_meta');



/**
 * Remove the default WordPress shutdown buffer flush action.
 *
 * This snippet unhooks 'wp_ob_end_flush_all' from the 'shutdown' action.
 * It is primarily used to suppress "Failed to send buffer" errors in 
 * specific server configurations or when custom output buffering is required.
 *
 * @return void
 */
add_action('init', function () {
    remove_action('shutdown', 'wp_ob_end_flush_all', 1);
});


/**
 * 1. BACKEND OVERRIDE: Push the next payment date to the existing expiration date, bypassing the delay.
 */
add_filter( 'pmpro_profile_start_date', 'influencer_collective_fix_delay_on_switch', 10, 2 );
function influencer_collective_fix_delay_on_switch( $startdate, $order ) {
    // Only apply to logged-in users who are upgrading/switching
    if ( ! is_user_logged_in() ) {
        return $startdate;
    }
    
    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    // Check if the user has an active level with a future payment date
    if ( ! empty( $current_level ) && ! empty( $current_level->next_payment ) ) {
        // Override the 3-day delay and force the new plan to start when the current banked time expires
        $startdate = date( "Y-m-d\TH:i:s", $current_level->next_payment );
    }
    
    return $startdate;
}

/**
 * 2. FRONTEND OVERRIDE: Fix the pricing text on the checkout page so it doesn't say "3 day trial".
 */
add_filter( 'pmpro_level_cost_text', 'influencer_collective_fix_checkout_text', 10, 4 );
function influencer_collective_fix_checkout_text( $text, $level, $tags, $short ) {
    if ( ! is_user_logged_in() ) {
        return $text;
    }
    
    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    // If they have banked time, rewrite the display text
    if ( ! empty( $current_level ) && ! empty( $current_level->next_payment ) ) {
        $formatted_date = date_i18n( get_option( 'date_format' ), $current_level->next_payment );
        
        // Dynamically replace the default trial text with their actual next payment date
        $text = preg_replace( '/after your \d+ day trial/i', 'starting on ' . $formatted_date, $text );
    }
    
    return $text;
}