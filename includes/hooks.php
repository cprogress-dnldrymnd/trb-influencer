<?php

/**
 * Injects dynamic CSS into the site header based on ACF field values.
 *
 * This function retrieves specific color settings from Advanced Custom Fields
 * (header_text_colour and header_accent_colour) and outputs an inline style block
 * if they are present.
 * * Note: The CSS selectors use class chaining (e.g., .header.header...) to artificially 
 * boost specificity and override default Elementor or theme styles without using !important.
 *
 * @return void
 */
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

