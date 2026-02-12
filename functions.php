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

    wp_enqueue_style('influencer-style', get_stylesheet_directory_uri() . '/style.css');


    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_enqueue_script('influencer-js', get_stylesheet_directory_uri() . '/assets/js/main.js', ['jquery']);
    wp_localize_script('influencer-js', 'ajax_vars', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'save_search_nonce'    => wp_create_nonce('save_search_nonce'),
        'save_influencer_nonce'    => wp_create_nonce('save_influencer_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20);

include 'includes/hooks.php';
include 'includes/custom-functions.php';
include 'includes/brief-parser.php';
include 'includes/pmpro.php';
include 'includes/acf.php';
include 'includes/elementor.php';
include 'includes/shortcodes.php';
include 'includes/ajax.php';
//include 'includes/openai.php';
include 'includes/theme-settings.php';




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
 * Plugin Name: Restrict Dashboard Access
 * Description: Redirects non-logged-in users to the homepage if they attempt to access the Dashboard page template.
 * Version: 1.0.1
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
