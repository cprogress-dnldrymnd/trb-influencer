<?php
/**
 * Plugin Name: Saves Manager
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Description: Pro-level manager for handling saved searches and advanced influencer list grouping.
 * * Class Saves_Manager
 * * Handles server-side AJAX operations, custom shortcodes, user meta list tracking, 
 * and client-side modal scripts for saving searches and influencers.
 */
class Saves_Manager {

    /**
     * Constructor: Initialize hooks, actions, and shortcodes.
     */
    public function __construct() {
        // AJAX hooks for logged-in users
        add_action( 'wp_ajax_save_user_search', [ $this, 'handle_save_search_ajax' ] );
        add_action( 'wp_ajax_get_influencer_modal_data', [ $this, 'handle_get_modal_data_ajax' ] );
        add_action( 'wp_ajax_save_influencer_to_lists', [ $this, 'handle_save_influencer_lists_ajax' ] );
        add_action( 'wp_ajax_get_group_influencers', [ $this, 'handle_get_group_influencers_ajax' ] );

        // Shortcodes
        add_shortcode( 'my_saved_groups', [ $this, 'render_saved_groups_shortcode' ] );

        // Frontend hooks for injecting variables, styles, and scripts
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_ajax_variables' ] );
        add_action( 'wp_footer', [ $this, 'render_inline_assets' ], 100 );
    }

    /**
     * Enqueues AJAX localized variables.
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
     * Shortcode: Render user's custom saved groups.
     * * Displays a UI grid of the user's custom lists. 
     * * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_saved_groups_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="inf-alert">Please log in to view your saved groups.</div>';
        }

        $user_id = get_current_user_id();
        $user_lists = get_user_meta( $user_id, 'custom_influencer_lists', true );

        if ( empty( $user_lists ) || ! is_array( $user_lists ) ) {
            return '<div class="inf-alert">You have not created any custom groups yet.</div>';
        }

        ob_start();
        ?>
        <div class="inf-groups-grid">
            <?php foreach ( $user_lists as $list ) : ?>
                <div class="inf-group-card view-group-influencers-trigger" data-group="<?php echo esc_attr( $list ); ?>">
                    <h4 class="inf-group-title"><?php echo esc_html( $list ); ?></h4>
                    <span class="inf-group-action">View Creators &rarr;</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX Handler: Save User Search
     * * @return void
     */
    public function handle_save_search_ajax() {
        check_ajax_referer('save_search_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Please login to save searches.']);
        }

        $user_id = get_current_user_id();
        $raw_data = isset($_POST['search_data']) ? $_POST['search_data'] : [];

        $allowed_keys = ['niche', 'platform', 'followers', 'country', 'lang', 'gender', 'score'];
        $clean_data = [];

        foreach ($allowed_keys as $key) {
            if (isset($raw_data[$key])) {
                if (is_array($raw_data[$key])) {
                    $clean_data[$key] = array_map('sanitize_text_field', $raw_data[$key]);
                } else {
                    $clean_data[$key] = sanitize_text_field($raw_data[$key]);
                }
            }
        }

        $query_string = http_build_query($clean_data);
        $query_string = preg_replace('/%5B\d+%5D/', '%5B%5D', $query_string);
        $final_string = '?' . $query_string;

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

        update_post_meta($post_id, 'search_query', $final_string);
        wp_send_json_success(['message' => 'Search saved successfully!']);
    }

    /**
     * Helper: Get Saved Influencer Post ID
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
     * * Retrieves custom lists. The 'Favorites' default has been intentionally removed.
     * * @return void
     */
    public function handle_get_modal_data_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');

        if (!is_user_logged_in()) wp_send_json_error(['message' => 'You must be logged in.']);

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $user_id = get_current_user_id();

        $user_lists = get_user_meta($user_id, 'custom_influencer_lists', true);
        if (!is_array($user_lists)) {
            $user_lists = []; // Blank slate; no forced fallback
        }

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
     * * @return void
     */
    public function handle_save_influencer_lists_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');

        if (!is_user_logged_in()) wp_send_json_error(['message' => 'You must be logged in to save.']);

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $selected_lists = isset($_POST['lists']) ? array_map('sanitize_text_field', (array)$_POST['lists']) : [];
        $new_list_name = isset($_POST['new_list_name']) ? sanitize_text_field($_POST['new_list_name']) : '';
        $user_id = get_current_user_id();

        if (empty($influencer_id)) wp_send_json_error(['message' => 'No Influencer ID provided.']);

        // Manage User Lists
        $user_lists = get_user_meta($user_id, 'custom_influencer_lists', true);
        $user_lists = is_array($user_lists) ? $user_lists : [];

        if (!empty($new_list_name)) {
            if (!in_array($new_list_name, $user_lists)) {
                $user_lists[] = $new_list_name;
                update_user_meta($user_id, 'custom_influencer_lists', $user_lists);
            }
            if (!in_array($new_list_name, $selected_lists)) {
                $selected_lists[] = $new_list_name; 
            }
        }

        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);

        if (empty($selected_lists)) {
            if ($post_id) wp_delete_post($post_id, true);
            $message = sprintf('<div class="my-cred-notice-text"><h4>Creator unsaved</h4><p>This creator has been removed from your Saved Lists</p></div>');
            wp_send_json_success(['message' => 'Unsaved successfully!', 'notice_html' => $message, 'status' => 'unsaved']);
        } else {
            if (!$post_id) {
                $post_args = [
                    'post_title'  => 'Influencer saved on ' . current_time('M j, Y @ g:i a'),
                    'post_type'   => 'saved-influencer',
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                ];
                $post_id = wp_insert_post($post_args);
                
                if (is_wp_error($post_id)) wp_send_json_error(['message' => 'Could not create post.']);
                update_post_meta($post_id, 'influencer_id', $influencer_id);
            }

            update_post_meta($post_id, 'saved_in_lists', $selected_lists);
            $message = sprintf('<div class="my-cred-notice-text"><h4>Creator successfully saved</h4><p>This creator has been updated in your Saved Lists</p></div>');
            wp_send_json_success(['message' => 'Saved successfully!', 'notice_html' => $message, 'status' => 'saved']);
        }
    }

    /**
     * AJAX Handler: Get Group Influencers
     * * Fetches all influencers assigned to a specific group for the modal view.
     * * @return void
     */
    public function handle_get_group_influencers_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');

        if (!is_user_logged_in()) wp_send_json_error(['message' => 'You must be logged in.']);

        $group_name = isset($_POST['group_name']) ? sanitize_text_field($_POST['group_name']) : '';
        $user_id = get_current_user_id();

        if (empty($group_name)) wp_send_json_error(['message' => 'Invalid group requested.']);

        // Query the custom post type for saves containing this list name
        // WordPress serializes arrays, so we look for the list name enclosed in quotes
        $saved_posts = get_posts([
            'post_type'      => 'saved-influencer',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'saved_in_lists',
                    'value'   => '"' . $group_name . '"',
                    'compare' => 'LIKE'
                ]
            ]
        ]);

        if (empty($saved_posts)) {
            wp_send_json_success(['html' => '<div class="inf-alert">No creators found in this group.</div>']);
        }

        $html = '<ul class="inf-group-creators-list">';
        foreach ($saved_posts as $post) {
            $influencer_id = get_post_meta($post->ID, 'influencer_id', true);
            $influencer_title = get_the_title($influencer_id);
            $influencer_url = get_permalink($influencer_id);
            
            $html .= sprintf(
                '<li>
                    <a href="%s" target="_blank">%s</a>
                    <button class="inf-btn-remove-from-list" data-save-id="%d" data-group="%s" title="Remove">&times;</button>
                </li>',
                esc_url($influencer_url),
                esc_html($influencer_title ?: 'Unknown Creator'),
                $post->ID,
                esc_attr($group_name)
            );
        }
        $html .= '</ul>';

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Renders Inline JavaScript, CSS, and HTML for the Modals & Shortcode.
     * * @return void
     */
    public function render_inline_assets() {
        ?>
        <style>
            /* Shortcode Grid Styling */
            .inf-groups-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin: 20px 0;
            }
            .inf-group-card {
                background: #f4f4f5; padding: 20px; border-radius: 8px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;
                border: 1px solid #e1e1e3; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;
            }
            .inf-group-card:hover {
                transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: #5034c4;
            }
            .inf-group-title { margin: 0 0 8px 0; font-size: 16px; color: #222; }
            .inf-group-action { font-size: 13px; color: #5034c4; font-weight: 500; }
            .inf-alert { padding: 12px; background: #fff3cd; color: #856404; border-radius: 6px; }

            /* Modal Overlays Styling */
            .inf-modal-overlay {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.4); z-index: 99999;
                display: none; align-items: center; justify-content: center;
                font-family: inherit;
            }
            .inf-modal-content {
                background: #f4f4f5; padding: 24px; border-radius: 12px;
                width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); position: relative;
            }
            .inf-modal-content h3 {
                margin: 0 0 16px 0; font-size: 18px; color: #333; font-weight: 500;
            }
            .inf-modal-close-icon {
                position: absolute; top: 16px; right: 20px; font-size: 24px; cursor: pointer; color: #888; border: none; background: transparent; line-height: 1;
            }
            .inf-modal-close-icon:hover { color: #333; }
            
            /* Management List Styles */
            .inf-lists-container { max-height: 200px; overflow-y: auto; margin-bottom: 12px; }
            .inf-list-item { display: flex; align-items: center; margin-bottom: 8px; cursor: pointer; }
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
            
            .inf-modal-actions { display: flex; justify-content: space-between; gap: 12px; }
            .inf-btn {
                flex: 1; padding: 10px; border-radius: 8px; font-weight: 500;
                cursor: pointer; text-align: center; border: none; transition: 0.2s;
            }
            .inf-btn-cancel { background: transparent; color: #555; border: 1px solid #ddd; }
            .inf-btn-cancel:hover { background: #eaeaea; }
            .inf-btn-save { background: #5034c4; color: #fff; }
            .inf-btn-save:hover { background: #40299e; }

            /* Group View Specific Styles */
            .inf-group-creators-list { list-style: none; padding: 0; margin: 0; max-height: 300px; overflow-y: auto; }
            .inf-group-creators-list li {
                display: flex; justify-content: space-between; align-items: center;
                padding: 10px 0; border-bottom: 1px solid #eaeaea;
            }
            .inf-group-creators-list li:last-child { border-bottom: none; }
            .inf-group-creators-list a { color: #333; text-decoration: none; font-weight: 500; }
            .inf-group-creators-list a:hover { color: #5034c4; }
            .inf-btn-remove-from-list {
                background: #ff4d4f; color: white; border: none; border-radius: 50%; width: 24px; height: 24px;
                display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 14px;
            }
        </style>

        <div id="inf-save-modal" class="inf-modal-overlay">
            <div class="inf-modal-content">
                <h3>Manage groups</h3>
                <div class="inf-lists-container" id="inf-lists-wrapper"></div>
                <button type="button" class="inf-create-btn" id="inf-trigger-new-group"><span>+</span> Create new group</button>
                <input type="text" id="inf-new-group-name" class="inf-new-group-input" placeholder="Enter group name...">
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel" id="inf-modal-cancel">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-modal-save">Save</button>
                </div>
            </div>
        </div>

        <div id="inf-view-group-modal" class="inf-modal-overlay">
            <div class="inf-modal-content">
                <button class="inf-modal-close-icon" id="inf-view-modal-close">&times;</button>
                <h3 id="inf-view-group-title">Loading...</h3>
                <div id="inf-view-group-body">
                    <div style="text-align:center; padding:20px;">Loading creators...</div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {

                function display_dynamic_mycred_notice(htmlContent) {
                    var $notice = $('<div class="notice-wrap"><div class="notice-item-wrapper"><div class="notice-item succes">' + htmlContent + '</div></div></div>');
                    $('body').append($notice);
                    $notice.fadeIn(300);
                    setTimeout(function () {
                        $notice.fadeOut(300, function () { $(this).remove(); });
                    }, 4000);
                }

                // ---------------------------------------------------------
                // 1. Core Influencer Save System
                // ---------------------------------------------------------
                let currentInfluencerId = null;
                let $currentTriggerBtn = null;

                function init_influencer_list_system() {
                    $(document).on('click', '.save-influencer-trigger', function (e) {
                        e.preventDefault();
                        $currentTriggerBtn = $(this);
                        currentInfluencerId = $currentTriggerBtn.attr('influencer-id');
                        
                        let $btnText = $currentTriggerBtn.find('.elementor-button-text');
                        let originalText = $btnText.text();
                        $btnText.text('Loading...');
                        $currentTriggerBtn.prop('disabled', true);

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
                                    let html = '';
                                    if(response.data.all_lists.length === 0) {
                                        html = '<p style="font-size:13px; color:#666; margin-bottom:12px;">No groups found. Create one below.</p>';
                                    } else {
                                        response.data.all_lists.forEach(function(listName) {
                                            let isChecked = response.data.active_lists.includes(listName) ? 'checked' : '';
                                            html += `
                                                <div class="inf-list-item">
                                                    <input type="checkbox" id="list_${listName}" value="${listName}" class="inf-list-checkbox" ${isChecked}>
                                                    <label for="list_${listName}">${listName}</label>
                                                </div>
                                            `;
                                        });
                                    }
                                    
                                    $('#inf-lists-wrapper').html(html);
                                    $('#inf-new-group-name').val('').hide();
                                    $('#inf-trigger-new-group').show();
                                    $('#inf-save-modal').css('display', 'flex');
                                } else {
                                    alert('Error: ' + response.data.message);
                                }
                                $btnText.text(originalText);
                                $currentTriggerBtn.prop('disabled', false);
                            }
                        });
                    });

                    $('#inf-trigger-new-group').on('click', function() {
                        $(this).hide();
                        $('#inf-new-group-name').show().focus();
                    });

                    $('#inf-modal-cancel').on('click', function() {
                        $('#inf-save-modal').hide();
                    });

                    $('#inf-modal-save').on('click', function() {
                        let selectedLists = [];
                        $('.inf-list-checkbox:checked').each(function() { selectedLists.push($(this).val()); });
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
                                    if (response.data.notice_html) display_dynamic_mycred_notice(response.data.notice_html);
                                    
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

                // ---------------------------------------------------------
                // 2. View Shortcode Group Influencers System
                // ---------------------------------------------------------
                function init_group_view_system() {
                    $(document).on('click', '.view-group-influencers-trigger', function() {
                        let groupName = $(this).attr('data-group');
                        
                        $('#inf-view-group-title').text(groupName);
                        $('#inf-view-group-body').html('<div style="text-align:center; padding:20px;">Loading creators...</div>');
                        $('#inf-view-group-modal').css('display', 'flex');

                        $.ajax({
                            url: ajax_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'get_group_influencers',
                                security: ajax_vars.save_influencer_nonce,
                                group_name: groupName
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#inf-view-group-body').html(response.data.html);
                                } else {
                                    $('#inf-view-group-body').html('<div class="inf-alert">' + response.data.message + '</div>');
                                }
                            }
                        });
                    });

                    $('#inf-view-modal-close').on('click', function() {
                        $('#inf-view-group-modal').hide();
                    });
                    
                    // Close Modals on Background Click
                    $('.inf-modal-overlay').on('click', function(e) {
                        if (e.target === this) {
                            $(this).hide();
                        }
                    });
                }

                // ---------------------------------------------------------
                // 3. Search Save System (Unchanged)
                // ---------------------------------------------------------
                function init_saved_search_trigger() {
                    function getCheckedValues(name) {
                        var values = [];
                        jQuery('input[name^="' + name + '"]:checked').each(function () { values.push(jQuery(this).val()); });
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
                            }
                        });
                    });
                }

                init_influencer_list_system();
                init_group_view_system();
                init_saved_search_trigger();
            });
        </script>
        <?php
    }
}

// Initialize the class
new Saves_Manager();