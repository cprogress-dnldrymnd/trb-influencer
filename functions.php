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
#require $dir . '/includes/pmpro-dynamic-pricing.php';
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
 * Safely disable the PMPro Subscription Delays Add-on for existing paid members.
 * Hooked to 'template_redirect' to guarantee it fires AFTER the add-on has loaded.
 */
add_action( 'template_redirect', 'influencer_collective_disable_pmprosd_for_upgrades', 99 );
function influencer_collective_disable_pmprosd_for_upgrades() {
    // Only proceed if we are on the PMPro checkout page and the user is logged in
    if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) || ! is_user_logged_in() || ! function_exists( 'pmpro_is_checkout' ) || ! pmpro_is_checkout() ) {
        return;
    }

    $user_id = get_current_user_id();
    $current_level = pmpro_getMembershipLevelForUser( $user_id );

    // If the user already has an active membership, aggressively unhook the Delay Add-on's filters
    if ( ! empty( $current_level ) ) {
        // Unhook Subscription Delays from hijacking the checkout level price/trials
        remove_filter( 'pmpro_checkout_level', 'pmprosd_pmpro_checkout_level', 10 );
        
        // Unhook Subscription Delays from hijacking the start date
        remove_filter( 'pmpro_profile_start_date', 'pmprosd_pmpro_profile_start_date', 10 );
        
        // Unhook Subscription Delays from hijacking the checkout text UI
        remove_filter( 'pmpro_level_cost_text', 'pmprosd_pmpro_level_cost_text', 10 );
    }
}