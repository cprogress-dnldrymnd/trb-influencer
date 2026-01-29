<?php

/**
 * 1. Register the Menu Item
 * Adds a "Theme Settings" link under the "Appearance" menu.
 */
function mytheme_add_admin_menu()
{
    add_theme_page(
        'Theme Settings',       // Page Title
        'Theme Settings',       // Menu Title
        'manage_options',       // Capability required
        'mytheme-settings',     // Menu Slug
        'mytheme_settings_page_html' // Callback function to render the page
    );
}
add_action('admin_menu', 'mytheme_add_admin_menu');

/**
 * 2. Register Settings
 * Registers the OpenAI API Key setting so WordPress handles saving/retrieving.
 */
function mytheme_settings_init()
{
    // Register a new setting for "mytheme_options" page
    register_setting('mytheme_options', 'mytheme_openai_key', array(
        'sanitize_callback' => 'sanitize_text_field', // Basic security sanitization
        'default'           => ''
    ));

    // Register a new section in the "mytheme_options" page
    add_settings_section(
        'mytheme_api_section', // Section ID
        'API Configuration',   // Section Title
        'mytheme_section_callback', // Callback
        'mytheme-settings'     // Page slug (must match menu slug)
    );

    // Register the field in the "mytheme_api_section" section
    add_settings_field(
        'mytheme_openai_key',  // Field ID
        'Open AI API Key',     // Field Title
        'mytheme_openai_key_callback', // Callback to render input
        'mytheme-settings',    // Page slug
        'mytheme_api_section'  // Section ID
    );
}
add_action('admin_init', 'mytheme_settings_init');

/**
 * 3. Section Callback
 * Description text for the section.
 */
function mytheme_section_callback()
{
    echo '<p>Enter your third-party API keys below.</p>';
}

/**
 * 4. Field Callback
 * Renders the actual input field.
 */
function mytheme_openai_key_callback()
{
    // Get the value of the setting we've registered with register_setting()
    $setting = get_option('mytheme_openai_key');

    // Output the field
?>
    <input type="text"
        name="mytheme_openai_key"
        value="<?php echo isset($setting) ? esc_attr($setting) : ''; ?>"
        class="regular-text"
        placeholder="sk-..." />
    <p class="description">Enter your private Open AI API Key here.</p>
<?php
}

/**
 * 5. Page HTML Callback
 * Renders the settings page wrapper.
 */
function mytheme_settings_page_html()
{
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <form action="options.php" method="post">
            <?php
            // Output security fields for the registered setting "mytheme_options"
            settings_fields('mytheme_options');

            // Output setting sections and their fields
            do_settings_sections('mytheme-settings');

            // Output save settings button
            submit_button('Save Settings');
            ?>
        </form>
    </div>
<?php
}
