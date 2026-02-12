<?php
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
