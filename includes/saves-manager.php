<?php
/**
 * Plugin Name: Saves Manager
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Description: Pro-level manager for handling saved searches and advanced influencer list grouping.
 * * Class Saves_Manager
 * * Handles all server-side AJAX operations, user meta list tracking, 
 * and client-side modal scripts for saving searches and influencers.
 */
class Saves_Manager {

    /**
     * Constructor: Initialize hooks and actions.
     * * Binds the necessary WordPress hooks for AJAX processing and 
     * front-end script/modal rendering.
     */
    public function __construct() {
        // AJAX hooks for logged-in users
        add_action( 'wp_ajax_save_user_search', [ $this, 'handle_save_search_ajax' ] );
        
        // New hooks for the advanced modal list system
        add_action( 'wp_ajax_get_influencer_modal_data', [ $this, 'handle_get_modal_data_ajax' ] );
        add_action( 'wp_ajax_save_influencer_to_lists', [ $this, 'handle_save_influencer_lists_ajax' ] );

        // Frontend hooks for injecting variables, styles, and scripts
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_ajax_variables' ] );
        add_action( 'wp_footer', [ $this, 'render_inline_assets' ], 100 );
    }

    /**
     * Enqueues AJAX localized variables.
     * * Registers an empty script specifically to localize `ajax_vars` 
     * containing our nonces and the admin-ajax URL required by the JS.
     * * @return void
     */
    public function enqueue_ajax_variables() {
        wp_register_script( 'theme-saves-handler', false );
        wp_enqueue_script( 'theme-saves-handler' );
        wp_localize_script( 'theme-saves-handler', 'ajax_vars', [
            'ajax_url'              => admin_url( 'admin-ajax.php' ),
            'save_search_nonce'     => wp_create_nonce( 'save_search_nonce' ),
            'save_influencer_nonce' => wp_create_nonce( 'save_influencer_nonce' ),
        ] );
    }

    /**
     * AJAX Handler: Save User Search
     * * This function handles the server-side logic when the "Save Search" button is clicked.
     * It verifies security, creates a new post in the 'saved_searches' CPT, 
     * and saves the filter inputs as post meta data.
     * * @return void Sends a JSON response.
     */
    public function handle_save_search_ajax() {
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
        $query_string = http_build_query($clean_data);

        // 4. Format Adjustment 
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
     * Helper: Get Saved Influencer Post ID
     * * Queries the database to find if the current user already has a 
     * 'saved-influencer' CPT entry for a specific influencer.
     * * @param int $influencer_id The ID of the influencer.
     * @param int $user_id The User ID.
     * @return int|bool The Post ID if found, false otherwise.
     */
    private function get_existing_influencer_save_id( $influencer_id, $user_id ) {
        $existing = get_posts([
            'post_type'      => 'saved-influencer',
            'author'         => $user_id,
            'meta_key'       => 'influencer_id',
            'meta_value'     => $influencer_id,
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ]);
        return !empty($existing) ? $existing[0] : false;
    }

    /**
     * AJAX Handler: Get Modal Data
     * * Retrieves the user's custom lists and checks which lists the 
     * current influencer is already a part of to populate the modal UI.
     * * @return void Sends a JSON response.
     */
    public function handle_get_modal_data_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $user_id = get_current_user_id();

        // Retrieve user's custom lists from user meta
        $user_lists = get_user_meta($user_id, 'custom_influencer_lists', true);
        if (!is_array($user_lists)) {
            $user_lists = ['Favorites']; // Default fallback list
        }

        // Check active lists for this specific influencer
        $active_lists = [];
        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);
        
        if ($post_id) {
            $saved_in = get_post_meta($post_id, 'saved_in_lists', true);
            if (is_array($saved_in)) {
                $active_lists = $saved_in;
            }
        }

        wp_send_json_success([
            'all_lists'    => array_unique($user_lists),
            'active_lists' => $active_lists
        ]);
    }

    /**
     * AJAX Handler: Save Influencer to Lists
     * * Processes the modal submission. Creates/updates the user's custom lists, 
     * updates the 'saved-influencer' CPT, and assigns the list metadata.
     * * @return void Sends a JSON response.
     */
    public function handle_save_influencer_lists_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to save.']);
        }

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $selected_lists = isset($_POST['lists']) ? array_map('sanitize_text_field', (array)$_POST['lists']) : [];
        $new_list_name = isset($_POST['new_list_name']) ? sanitize_text_field($_POST['new_list_name']) : '';
        $user_id = get_current_user_id();

        if (empty($influencer_id)) {
            wp_send_json_error(['message' => 'No Influencer ID provided.']);
        }

        // 1. Manage User Lists (Create new list if requested)
        $user_lists = get_user_meta($user_id, 'custom_influencer_lists', true);
        $user_lists = is_array($user_lists) ? $user_lists : ['Favorites'];

        if (!empty($new_list_name)) {
            if (!in_array($new_list_name, $user_lists)) {
                $user_lists[] = $new_list_name;
                update_user_meta($user_id, 'custom_influencer_lists', $user_lists);
            }
            if (!in_array($new_list_name, $selected_lists)) {
                $selected_lists[] = $new_list_name; // Automatically select the newly created list
            }
        }

        // 2. Manage the CPT Data
        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);

        if (empty($selected_lists)) {
            // If all lists are unchecked, delete the save entirely to maintain clean DB
            if ($post_id) {
                wp_delete_post($post_id, true);
            }
            $message = sprintf('<div class="my-cred-notice-text"><h4>Creator unsaved</h4><p>This creator has been removed from your Saved Lists</p></div>');
            wp_send_json_success(['message' => 'Unsaved successfully!', 'notice_html' => $message, 'status' => 'unsaved']);
        } else {
            // Update or Create Post
            if (!$post_id) {
                $post_args = [
                    'post_title'  => 'Influencer saved on ' . current_time('M j, Y @ g:i a'),
                    'post_type'   => 'saved-influencer',
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                ];
                $post_id = wp_insert_post($post_args);
                
                if (is_wp_error($post_id)) {
                    wp_send_json_error(['message' => 'Could not create post.']);
                }
                update_post_meta($post_id, 'influencer_id', $influencer_id);
            }

            // Sync the lists this influencer belongs to
            update_post_meta($post_id, 'saved_in_lists', $selected_lists);

            $message = sprintf('<div class="my-cred-notice-text"><h4>Creator successfully saved</h4><p>This creator has been updated in your Saved Lists</p></div>');
            wp_send_json_success(['message' => 'Saved successfully!', 'notice_html' => $message, 'status' => 'saved']);
        }
    }

    /**
     * Renders Inline JavaScript, CSS, and HTML for the Modal.
     * * Keeps the entire logic encapsulated in one file while providing 
     * an elegant UI mirroring the requested CreatorDB design.
     * * @return void
     */
    public function render_inline_assets() {
        ?>
        <style>
            /* Influencer Save Modal Styling */
            .inf-modal-overlay {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.4); z-index: 99999;
                display: none; align-items: center; justify-content: center;
                font-family: inherit;
            }
            .inf-modal-content {
                background: #f4f4f5; padding: 24px; border-radius: 12px;
                width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            }
            .inf-modal-content h3 {
                margin: 0 0 16px 0; font-size: 18px; color: #333; font-weight: 500;
            }
            .inf-lists-container {
                max-height: 200px; overflow-y: auto; margin-bottom: 12px;
            }
            .inf-list-item {
                display: flex; align-items: center; margin-bottom: 8px; cursor: pointer;
            }
            .inf-list-item input { margin-right: 10px; cursor: pointer; }
            .inf-list-item label { cursor: pointer; font-size: 14px; color: #444; }
            
            .inf-create-btn {
                background: none; border: none; color: #888; font-size: 14px; 
                cursor: pointer; padding: 0; margin-bottom: 16px; display: flex; align-items: center; gap: 6px;
            }
            .inf-create-btn:hover { color: #5034c4; }
            
            .inf-new-group-input {
                width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;
                margin-bottom: 16px; display: none; font-size: 14px;
            }
            
            .inf-modal-actions {
                display: flex; justify-content: space-between; gap: 12px;
            }
            .inf-btn {
                flex: 1; padding: 10px; border-radius: 8px; font-weight: 500;
                cursor: pointer; text-align: center; border: none; transition: 0.2s;
            }
            .inf-btn-cancel {
                background: transparent; color: #555; border: 1px solid #ddd;
            }
            .inf-btn-cancel:hover { background: #eaeaea; }
            .inf-btn-save {
                background: #5034c4; color: #fff;
            }
            .inf-btn-save:hover { background: #40299e; }
        </style>

        <div id="inf-save-modal" class="inf-modal-overlay">
            <div class="inf-modal-content">
                <h3>Manage groups</h3>
                <div class="inf-lists-container" id="inf-lists-wrapper">
                    </div>
                
                <button type="button" class="inf-create-btn" id="inf-trigger-new-group">
                    <span>+</span> Create new group
                </button>
                <input type="text" id="inf-new-group-name" class="inf-new-group-input" placeholder="Enter group name...">
                
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel" id="inf-modal-cancel">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-modal-save">Save</button>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {

                /**
                 * Renders a dynamic notification popup mimicking myCred's native transient notices.
                 */
                function display_dynamic_mycred_notice(htmlContent) {
                    var $notice = $('<div class="notice-wrap"> <div class="notice-item-wrapper"> <div class="notice-item succes" >' + htmlContent + '</div></div></div>');
                    $('body').append($notice);
                    $notice.fadeIn(300);
                    setTimeout(function () {
                        $notice.fadeOut(300, function () { $(this).remove(); });
                    }, 4000);
                }

                // Global variables for modal state
                let currentInfluencerId = null;
                let $currentTriggerBtn = null;

                /**
                 * Influencer Modal & Save Logic
                 */
                function init_influencer_list_system() {
                    
                    // 1. Open Modal and Fetch Data
                    $(document).on('click', '.save-influencer-trigger', function (e) {
                        e.preventDefault();
                        $currentTriggerBtn = $(this);
                        currentInfluencerId = $currentTriggerBtn.attr('influencer-id');
                        
                        let $btnText = $currentTriggerBtn.find('.elementor-button-text');
                        let originalText = $btnText.text();
                        $btnText.text('Loading...');
                        $currentTriggerBtn.prop('disabled', true);

                        // Fetch user lists
                        $.ajax({
                            url: ajax_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'get_influencer_modal_data',
                                security: ajax_vars.save_influencer_nonce,
                                influencer_id: currentInfluencerId
                            },
                            success: function (response) {
                                if (response.success) {
                                    populate_modal_lists(response.data.all_lists, response.data.active_lists);
                                    $('#inf-save-modal').css('display', 'flex');
                                } else {
                                    alert('Error: ' + response.data.message);
                                }
                                $btnText.text(originalText);
                                $currentTriggerBtn.prop('disabled', false);
                            }
                        });
                    });

                    // 2. Populate Checkboxes Helper
                    function populate_modal_lists(all_lists, active_lists) {
                        let html = '';
                        all_lists.forEach(function(listName) {
                            let isChecked = active_lists.includes(listName) ? 'checked' : '';
                            html += `
                                <div class="inf-list-item">
                                    <input type="checkbox" id="list_${listName}" value="${listName}" class="inf-list-checkbox" ${isChecked}>
                                    <label for="list_${listName}">${listName}</label>
                                </div>
                            `;
                        });
                        $('#inf-lists-wrapper').html(html);
                        $('#inf-new-group-name').val('').hide();
                        $('#inf-trigger-new-group').show();
                    }

                    // 3. UI Interactions inside Modal
                    $('#inf-trigger-new-group').on('click', function() {
                        $(this).hide();
                        $('#inf-new-group-name').show().focus();
                    });

                    $('#inf-modal-cancel').on('click', function() {
                        $('#inf-save-modal').hide();
                    });

                    // 4. Save Submission Action
                    $('#inf-modal-save').on('click', function() {
                        let selectedLists = [];
                        $('.inf-list-checkbox:checked').each(function() {
                            selectedLists.push($(this).val());
                        });
                        
                        let newListName = $('#inf-new-group-name').is(':visible') ? $('#inf-new-group-name').val().trim() : '';
                        let $saveBtn = $(this);
                        $saveBtn.text('Saving...').prop('disabled', true);

                        $.ajax({
                            url: ajax_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'save_influencer_to_lists',
                                security: ajax_vars.save_influencer_nonce,
                                influencer_id: currentInfluencerId,
                                lists: selectedLists,
                                new_list_name: newListName
                            },
                            success: function (response) {
                                if (response.success) {
                                    if (response.data.notice_html) {
                                        display_dynamic_mycred_notice(response.data.notice_html);
                                    }
                                    
                                    // Update visual state of original trigger button
                                    let $btnText = $currentTriggerBtn.find('.elementor-button-text');
                                    if(response.data.status === 'saved') {
                                        $btnText.text('SAVED');
                                        $currentTriggerBtn.addClass('delete-save');
                                    } else {
                                        $btnText.text('UNSAVE');
                                        $currentTriggerBtn.removeClass('delete-save');
                                    }
                                    
                                    $('#inf-save-modal').hide();
                                } else {
                                    alert('Error: ' + response.data.message);
                                }
                                $saveBtn.text('Save').prop('disabled', false);
                            }
                        });
                    });
                }

                /**
                 * Initializes the search parameters saving logic. (Unchanged functionality)
                 */
                function init_saved_search_trigger() {
                    function getCheckedValues(name) {
                        var values = [];
                        jQuery('input[name^="' + name + '"]:checked').each(function () {
                            values.push(jQuery(this).val());
                        });
                        return values;
                    }

                    jQuery('.save-search-trigger').on('click', function (e) {
                        e.preventDefault();
                        var $btn = jQuery(this);
                        var originalText = $btn.text();
                        $btn.text('Saving...');

                        var searchData = {
                            'niche': getCheckedValues('niche'),
                            'platform': getCheckedValues('platform'),
                            'followers': getCheckedValues('followers'),
                            'country': getCheckedValues('country'),
                            'lang': getCheckedValues('lang'),
                            'gender': getCheckedValues('gender'),
                            'score': jQuery('input[name="score"]').val()
                        };

                        jQuery.ajax({
                            url: ajax_vars.ajax_url, 
                            type: 'POST',
                            data: {
                                action: 'save_user_search', 
                                security: ajax_vars.save_search_nonce,  
                                search_data: searchData          
                            },
                            success: function (response) {
                                if (response.success) {
                                    $btn.text('Saved!');
                                    setTimeout(function () { $btn.text(originalText); }, 2000);
                                } else {
                                    alert(response.data.message);
                                    $btn.text(originalText);
                                }
                            },
                            error: function (response) {
                                alert('Server error. Please try again.');
                                $btn.text(originalText);
                            }
                        });
                    });
                }

                // Execute the trigger initializations on Document Ready
                init_influencer_list_system();
                init_saved_search_trigger();

            });
        </script>
        <?php
    }
}

// Initialize the class
new Saves_Manager();