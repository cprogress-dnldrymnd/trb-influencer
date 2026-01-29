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

include 'includes/custom-functions.php';
include 'includes/pmpro.php';
include 'includes/elementor.php';
include 'includes/shortcodes.php';
include 'includes/ajax.php';

/**
 * Retrieve all Elementor Global Colors (System + Custom).
 *
 * @return array An array of color objects containing ID, Title, and Color Hex.
 */
function get_elementor_global_colors()
{
    // 1. Check if Elementor is active
    if (! class_exists('\Elementor\Plugin')) {
        return [];
    }

    // 2. Get the Active Kit (The post that holds global settings)
    $kits_manager = \Elementor\Plugin::$instance->kits_manager;
    $active_kit   = $kits_manager->get_active_kit_for_frontend();

    if (! $active_kit) {
        return [];
    }

    // 3. Get all settings from the kit
    $kit_settings = $active_kit->get_settings();

    $all_colors = [];

    // 4. Extract System Colors (Primary, Secondary, Text, Accent)
    if (! empty($kit_settings['system_colors'])) {
        foreach ($kit_settings['system_colors'] as $key => $color_data) {
            $all_colors[] = [
                'type'  => 'system',
                'id'    => $color_data['_id'], // e.g., 'primary'
                'title' => isset($color_data['title']) ? $color_data['title'] : ucfirst($color_data['_id']),
                'color' => $color_data['color'], // The Hex code
                'css_var' => "--e-global-color-{$color_data['_id']}" // The CSS variable Elementor generates
            ];
        }
    }

    // 5. Extract Custom Colors (User added colors)
    if (! empty($kit_settings['custom_colors'])) {
        foreach ($kit_settings['custom_colors'] as $color_data) {
            $all_colors[] = [
                'type'  => 'custom',
                'id'    => $color_data['_id'], // Random hash ID
                'title' => $color_data['title'],
                'color' => $color_data['color'],
                'css_var' => "--e-global-color-{$color_data['_id']}"
            ];
        }
    }

    return $all_colors;
}



add_filter('acf/load_field/name=header_text_colour', 'acf_elementor_global_colours');
add_filter('acf/load_field/name=header_accent_colour', 'acf_elementor_global_colours');
add_filter('acf/load_field/name=hero_background_colour', 'acf_elementor_global_colours');

function acf_elementor_global_colours($field)
{

    // 1. Reset choices to empty array
    $field['choices'] = array();
    $field['choices'][''] = 'Default';

    $global_colours = get_elementor_global_colors();

    foreach ($global_colours as $global_colour) {

        $field['choices'][$global_colour['css_var']] = $global_colour['color'] . '[' . $global_colour['title'] . ']';
    }

    return $field;
}


function action_wp_head()
{
    $header_text_colour = get_field('header_text_colour');
    $header_accent_colour = get_field('header_accent_colour');
    if ($header_text_colour || $header_accent_colour) {
?>
        <style id="page--options">
            <?php
            if ($header_accent_colour) {
                echo ".header.header.header.header.header .header--accent-color .elementor-heading-title { color: var($header_accent_colour) }";
            }
            if ($header_text_colour) {
                echo ".header.header.header.header.header .header--text-color * { color: var($header_text_colour) }";
                echo ".header.header.header.header.header .logo-box svg { color: var($header_text_colour) }";
                echo ".header.header.header.header.header .logo-box svg { fill: var($header_text_colour) }";
            }
            ?>
        </style>
    <?php
    }
}

add_action('wp_head', 'action_wp_head');
/**
 * Disable Elementor Pro / Pro Elements Header & Footer on Dashboard Template
 */
add_filter('elementor/theme/get_location_templates/template_id', function ($template_id, $location) {
    // Check if we are on the specific page template
    if (is_page_template('templates/page-dashboard.php') || (is_single() && get_post_type() == 'influencer')) {
        // If the location is header or footer, return 0 to skip the Elementor template
        if (in_array($location, ['header', 'footer'])) {
            return 0;
        }
    }

    return $template_id;
}, 10, 2);


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
