<?php

/**
 * Class Saves_Manager
 * * Handles all server-side AJAX operations and client-side scripts 
 * for saving searches and influencers within the theme.
 */
class Saves_Manager
{

    /**
     * Constructor: Initialize hooks and actions.
     * * Binds the necessary WordPress hooks for AJAX processing and 
     * front-end script rendering.
     */
    public function __construct()
    {
        // AJAX hooks for logged-in users
        add_action('wp_ajax_save_user_search', [$this, 'handle_save_search_ajax']);
        add_action('wp_ajax_save_influencer', [$this, 'handle_save_influencer_ajax']);

        // Frontend hooks for injecting variables and scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_ajax_variables']);
        add_action('wp_footer', [$this, 'render_inline_javascript'], 100);
    }

    /**
     * Enqueues AJAX localized variables.
     * * Registers an empty script specifically to localize `ajax_vars` 
     * containing our nonces and the admin-ajax URL required by the JS.
     * * @return void
     */
    public function enqueue_ajax_variables()
    {
        wp_register_script('theme-saves-handler', false);
        wp_enqueue_script('theme-saves-handler');
        wp_localize_script('theme-saves-handler', 'ajax_vars', [
            'ajax_url'              => admin_url('admin-ajax.php'),
            'save_search_nonce'     => wp_create_nonce('save_search_nonce'),
            'save_influencer_nonce' => wp_create_nonce('save_influencer_nonce'),
        ]);
    }

    /**
     * AJAX Handler: Save User Search
     * * This function handles the server-side logic when the "Save Search" button is clicked.
     * It verifies security, creates a new post in the 'saved_searches' CPT, 
     * and saves the filter inputs as post meta data.
     * * @return void Sends a JSON response.
     */
    public function handle_save_search_ajax()
    {
        // 1. Security Check
        check_ajax_referer('save_search_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Please login to save searches.']);
        }

        $user_id = get_current_user_id();
        $raw_data = isset($_POST['search_data']) ? $_POST['search_data'] : [];

        // 2. Sanitize Data
        // We strictly define allowed keys to prevent garbage data
        $allowed_keys = ['niche', 'platform', 'followers', 'country', 'lang', 'gender', 'score'];
        $clean_data = [];

        foreach ($allowed_keys as $key) {
            if (isset($raw_data[$key])) {
                if (is_array($raw_data[$key])) {
                    // Sanitize array items (checkboxes)
                    $clean_data[$key] = array_map('sanitize_text_field', $raw_data[$key]);
                } else {
                    // Sanitize string (range slider)
                    $clean_data[$key] = sanitize_text_field($raw_data[$key]);
                }
            }
        }

        // 3. Build Query String
        // http_build_query creates the string: niche%5B0%5D=artist&niche%5B1%5D=beauty...
        $query_string = http_build_query($clean_data);

        // 4. Format Adjustment (Optional but requested)
        // PHP adds indices [0], [1] by default. Your request used [] (%5B%5D).
        // This regex replaces %5B0%5D, %5B1%5D, etc. with just %5B%5D
        $query_string = preg_replace('/%5B\d+%5D/', '%5B%5D', $query_string);

        // 5. Prepend the Question Mark
        $final_string = '?' . $query_string;

        // 6. Create Post
        $post_args = [
            'post_title'  => 'Search saved on ' . current_time('M j, Y @ g:i a'),
            'post_type'   => 'saved-search',
            'post_status' => 'publish',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_args);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => 'Error creating save file.']);
        }

        // 7. Save the Single String
        update_post_meta($post_id, 'search_query', $final_string);

        wp_send_json_success(['message' => 'Search saved successfully!']);
    }

    /**
     * AJAX Handler: Save Influencer
     * * Processes the save/unsave action for an influencer, updates the 'saved-influencer' 
     * custom post type, and passes the notification HTML back to the client via JSON.
     * * @return void Sends a JSON response.
     */
    public function handle_save_influencer_ajax()
    {
        // Security check
        check_ajax_referer('save_influencer_nonce', 'security');

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in to save.'));
        }

        // Get the data
        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'save';

        if (empty($influencer_id)) {
            wp_send_json_error(array('message' => 'No Influencer ID provided.'));
        }

        // Establish current user ID
        $current_user_id = get_current_user_id();

        if ($type == 'save') {
            // Format: Jan 4, 2026 @ 8:57 pm
            // Note: current_time gets the time based on your WP timezone settings
            $post_title = 'Influencer saved on ' . current_time('M j, Y @ g:i a');

            // Prepare Post Data
            $new_post = array(
                'post_title'    => $post_title,
                'post_type'     => 'saved-influencer', // Ensure this Post Type is registered
                'post_status'   => 'publish',
                'post_author'   => $current_user_id,
            );

            // Insert the Post
            $post_id = wp_insert_post($new_post);

            if (is_wp_error($post_id)) {
                wp_send_json_error(array('message' => 'Could not create post.'));
            } else {
                // Update Meta Data
                update_post_meta($post_id, 'influencer_id', $influencer_id);

                // Construct the notification HTML
                $message = sprintf('<div class="my-cred-notice-text"><h4>Creator succesfully saved</h4><p>This creator has been saved within your Saved Lists</p></div>');

                // Return the HTML directly in the success payload
                wp_send_json_success(array(
                    'message'     => 'Saved successfully!',
                    'id'          => $post_id,
                    'notice_html' => $message
                ));
            }
        } else {
            // Note: Assumes `is_influencer_saved()` is defined elsewhere in your environment
            $saved_id = is_influencer_saved($influencer_id);
            if ($saved_id) {
                wp_delete_post($saved_id, true);

                // Construct the notification HTML
                $message = sprintf('<div class="my-cred-notice-text"><h4>Creator succesfully unsaved</h4><p>This creator has been removed from your Saved Lists</p></div>');

                // Return the HTML directly in the success payload
                wp_send_json_success(array(
                    'message'     => 'Unsaved successfully!',
                    'id'          => $saved_id,
                    'notice_html' => $message
                ));
            }
        }
    }

    /**
     * Renders Inline JavaScript.
     * * Injects the contents of saves.js directly into the DOM footer 
     * to fulfill the requirement of keeping everything in a single PHP file.
     * * @return void
     */
    public function render_inline_javascript()
    {
?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {

                /**
                 * Renders a dynamic notification popup mimicking myCred's native transient notices.
                 *
                 * @param {string} htmlContent The HTML payload returned from the AJAX response.
                 * @return {void}
                 */
                function display_dynamic_mycred_notice(htmlContent) {
                    // Construct the wrapper element. Adjust inline styles as needed to match your theme.
                    var $notice = $('<div class="notice-wrap"> <div class="notice-item-wrapper"> <div class="notice-item succes" >' + htmlContent + '</div></div></div>');

                    // Append to DOM
                    $('body').append($notice);

                    // Animate in
                    $notice.fadeIn(300);

                    // Auto-remove after 4 seconds to keep the DOM clean
                    setTimeout(function() {
                        $notice.fadeOut(300, function() {
                            $(this).remove();
                        });
                    }, 4000);
                }

                /**
                 * Initializes the influencer save/unsave trigger logic.
                 * * @return {void}
                 */
                function saved_influencer_trigger() {
                    // Listen for click on .save-influencer-trigger
                    $(document).on('click', '.save-influencer-trigger', function(e) {
                        e.preventDefault();

                        var $button = $(this);

                        // Get the ID from the attribute
                        var influencerId = $button.attr('influencer-id');
                        var $buttonText = $(this).find('.elementor-button-text');

                        // Scope variables properly
                        var type, buttonupdated, buttonupdating;

                        // (Optional) Visual feedback: Change button text or disable it
                        if ($button.hasClass('delete-save')) {
                            type = 'delete';
                            buttonupdated = 'SAVED';
                            buttonupdating = 'UNSAVING...';
                        } else {
                            type = 'save';
                            buttonupdated = 'UNSAVE';
                            buttonupdating = 'SAVING...';
                        }
                        $buttonText.text(buttonupdating).prop('disabled', true);
                        $button.prop('disabled', true);

                        $.ajax({
                            url: ajax_vars.ajax_url, // From wp_localize_script
                            type: 'POST',
                            data: {
                                action: 'save_influencer', // Must match the wp_ajax_ hook
                                security: ajax_vars.save_influencer_nonce,
                                influencer_id: influencerId,
                                type: type
                            },
                            success: function(response) {
                                if (response.success) {

                                    // Render the dynamic HTML notice passed from PHP, replacing the native alert()
                                    if (response.data.notice_html) {
                                        display_dynamic_mycred_notice(response.data.notice_html);
                                    }

                                    $buttonText.text(buttonupdated);

                                    if (type == 'delete') {
                                        $button.removeClass('delete-save');
                                    } else {
                                        $button.addClass('delete-save');
                                    }

                                    $button.prop('disabled', false);

                                } else {
                                    alert('Error: ' + response.data.message);
                                    $buttonText.text('Save Influencer').prop('disabled', false);
                                }
                            },
                            error: function() {
                                alert('An unexpected error occurred.');
                                $buttonText.text('Save Influencer').prop('disabled', false);
                            }
                        });
                    });
                }

                /**
                 * Initializes the search parameters saving logic.
                 * * @return {void}
                 */
                function saved_search_trigger() {
                    /**
                     * Helper Function: Get Checked Values
                     * * Iterates through all checkboxes that share a specific "name" attribute
                     * (e.g., name="niche" or name="niche[]") and returns an array of their values.
                     * * @param {string} name - The name attribute of the input field.
                     * @returns {Array} - An array of values from checked boxes.
                     */
                    function getCheckedValues(name) {
                        var values = [];
                        // Selector explanation:
                        // input[name^="..."] selects inputs where the name STARTS with the string provided.
                        // This handles cases where the name might be "niche" or "niche[]".
                        jQuery('input[name^="' + name + '"]:checked').each(function() {
                            values.push(jQuery(this).val());
                        });
                        return values;
                    }

                    /**
                     * Event Listener: Save Button Click
                     * * Listens for a click on any element with class '.save-search-trigger'.
                     * Gathers data and sends it to the server.
                     */
                    jQuery('.save-search-trigger').on('click', function(e) {

                        // Prevent the link from jumping to the top of the page or reloading.
                        e.preventDefault();

                        var $btn = jQuery(this);
                        var originalText = $btn.text();

                        // UX: Change button text to indicate processing.
                        $btn.text('Saving...');

                        // 1. Collect Data Object
                        // We use our helper function for checkboxes and standard .val() for the range slider.
                        var searchData = {
                            'niche': getCheckedValues('niche'),
                            'platform': getCheckedValues('platform'),
                            'followers': getCheckedValues('followers'),
                            'country': getCheckedValues('country'),
                            'lang': getCheckedValues('lang'),
                            'gender': getCheckedValues('gender'),
                            'score': jQuery('input[name="score"]').val() // Range slider usually has a single value
                        };

                        // 2. AJAX Request
                        // Sends the collected data to the PHP function 'handle_save_search_ajax'.
                        jQuery.ajax({
                            url: ajax_vars.ajax_url, // URL passed from PHP via wp_localize_script
                            type: 'POST',
                            data: {
                                action: 'save_user_search', // Must match the wp_ajax_{action} hook in PHP
                                security: ajax_vars.save_search_nonce, // Security token passed from PHP
                                search_data: searchData // The object containing our form values
                            },

                            // 3. Handle Success
                            success: function(response) {
                                if (response.success) {
                                    $btn.text('Saved!');
                                    // Optional: Revert text back to original after 2 seconds
                                    setTimeout(function() {
                                        $btn.text(originalText);
                                    }, 2000);
                                } else {
                                    // If PHP sent wp_send_json_error()
                                    alert(response.data.message);
                                    $btn.text(originalText);
                                }
                            },

                            // 4. Handle Server/Network Errors
                            error: function(response) {
                                alert('Server error. Please try again.');
                                console.log(response);
                                $btn.text(originalText);
                            }
                        });
                    });
                }

                // Execute the trigger initializations on Document Ready
                saved_influencer_trigger();
                saved_search_trigger();

            });
        </script>
<?php
    }
}

// Initialize the class
new Saves_Manager();
