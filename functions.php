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
 * Retrieves the compiled CSS from an Elementor template and injects it directly 
 * into the <head> of the document as an inline style block.
 * * This prevents an additional HTTP request by outputting the CSS string directly,
 * which is useful for critical rendering paths or bypassing the enqueue queue.
 *
 * @author Digitally Disruptive - Donald Raymundo
 * @author_uri https://digitallydisruptive.co.uk/
 *
 * @return void
 */
function dd_inject_elementor_template_css_to_head(): void
{
    // Define the target Elementor Template ID.
    $template_id = 1571;

    // Verify Elementor core file management class exists to prevent fatal errors.
    if (! class_exists('\Elementor\Core\Files\CSS\Post')) {
        return;
    }

    // Initialize the CSS file object for the specific template.
    $css_file = new \Elementor\Core\Files\CSS\Post($template_id);

    // Fetch the raw CSS string. If the CSS file does not exist locally, 
    // Elementor will automatically compile it before returning the string.
    $css_content = $css_file->get_content();

    // If CSS content is successfully retrieved, output it wrapped in style tags.
    if (! empty($css_content)) {
        echo "\n";
        echo "<style id='dd-elementor-template-{$template_id}-inline-css'>\n";
        // wp_strip_all_tags is used as a safety mechanism to sanitize the output.
        echo wp_strip_all_tags($css_content);
        echo "\n</style>\n";
    }
}
// Hook the function into wp_head. Adjust the priority (10) if you need it 
// to load earlier (e.g., 1) or later (e.g., 99) in the <head>.
add_action('wp_head', 'dd_inject_elementor_template_css_to_head', 10);
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

include 'includes/hooks.php';
include 'includes/custom-functions.php';
include 'includes/brief-parser.php';
include 'includes/mycred.php';
include 'includes/pmpro.php';
include 'includes/acf.php';
include 'includes/elementor.php';
include 'includes/outreach.php';
include 'includes/charts.php';
include 'includes/feeds.php';
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

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
