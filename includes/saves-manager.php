<?php
/**
 * Plugin Name: Saves Manager
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Description: Pro-level manager for handling saved searches and advanced influencer list grouping.
 * * Class Saves_Manager
 * Handles server-side AJAX operations, custom shortcodes, user meta list tracking, 
 * and client-side modal scripts for saving searches and influencers.
 */
class Saves_Manager {

    /**
     * Constructor: Initialize hooks, actions, and shortcodes.
     * Binds all necessary AJAX actions, shortcodes, and script enqueues.
     *
     * @return void
     */
    public function __construct() {
        // AJAX hooks for logged-in users
        add_action( 'wp_ajax_save_user_search', [ $this, 'handle_save_search_ajax' ] );
        add_action( 'wp_ajax_get_influencer_modal_data', [ $this, 'handle_get_modal_data_ajax' ] );
        add_action( 'wp_ajax_save_influencer_to_lists', [ $this, 'handle_save_influencer_lists_ajax' ] );
        add_action( 'wp_ajax_get_group_influencers', [ $this, 'handle_get_group_influencers_ajax' ] );
        add_action( 'wp_ajax_upsert_influencer_group', [ $this, 'handle_upsert_group_ajax' ] );
        add_action( 'wp_ajax_delete_influencer_group', [ $this, 'handle_delete_group_ajax' ] );

        // Shortcodes
        add_shortcode( 'my_saved_groups', [ $this, 'render_saved_groups_shortcode' ] );

        // Frontend hooks for injecting variables, styles, and scripts
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_ajax_variables' ] );
        add_action( 'wp_footer', [ $this, 'render_inline_assets' ], 100 );
    }

    /**
     * Enqueues AJAX localized variables.
     * Registers an empty script specifically to localize variables.
     *
     * @return void
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
     * Data Normalization Helper: Get User Groups
     * Retrieves the user's groups. Upgrades legacy strings to the new 
     * associative array format ensuring 'id', 'name', 'desc', and 'date' exist.
     *
     * @param int $user_id The user ID.
     * @return array Array of group objects.
     */
    private function get_normalized_groups( $user_id ) {
        $groups = get_user_meta( $user_id, 'custom_influencer_lists', true );
        $groups = is_array( $groups ) ? $groups : [];
        $normalized = [];
        $changed = false;

        foreach ( $groups as $key => $val ) {
            if ( is_string( $val ) ) {
                $id = 'grp_' . md5( $val );
                $normalized[$id] = [
                    'id'   => $id,
                    'name' => $val,
                    'desc' => '',
                    'date' => wp_date('Y-m-d H:iA') // Assign current date to legacy items
                ];
                $changed = true;
            } else {
                // Ensure existing arrays have a date
                if ( !isset($val['date']) ) {
                    $val['date'] = wp_date('Y-m-d H:iA');
                    $changed = true;
                }
                $normalized[$key] = $val;
            }
        }

        if ( $changed ) {
            update_user_meta( $user_id, 'custom_influencer_lists', $normalized );
        }

        return $normalized;
    }

    /**
     * Shortcode: Render user's custom saved groups.
     * Displays a UI grid matching the video spec, complete with 3-dot menus,
     * dates, and dynamically loaded influencer avatars.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_saved_groups_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="inf-alert">Please log in to view your saved groups.</div>';
        }

        $user_id = get_current_user_id();
        $user_lists = $this->get_normalized_groups( $user_id );

        if ( empty( $user_lists ) ) {
            return '<div class="inf-alert">You have not created any custom groups yet.</div>';
        }

        global $post;
        $original_post = $post; // Cache original post to restore later

        ob_start();
        ?>
        <div class="inf-groups-grid" id="inf-groups-shortcode-grid">
            <?php foreach ( $user_lists as $list ) : ?>
                <div class="inf-group-card" id="card-<?php echo esc_attr( $list['id'] ); ?>">
                    
                    <div class="inf-card-header">
                        <h4 class="inf-group-title" data-field="name"><?php echo esc_html( $list['name'] ); ?></h4>
                        <div class="inf-card-actions">
                            <button class="inf-btn-icon inf-trigger-edit-group" data-id="<?php echo esc_attr( $list['id'] ); ?>" data-name="<?php echo esc_attr( $list['name'] ); ?>" data-desc="<?php echo esc_attr( $list['desc'] ); ?>" title="Edit Group">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            </button>
                            <div class="inf-dropdown-wrapper">
                                <button class="inf-btn-icon inf-trigger-dropdown" title="Options">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                                </button>
                                <div class="inf-dropdown-menu">
                                    <button class="inf-dropdown-item inf-trigger-delete-group" data-id="<?php echo esc_attr( $list['id'] ); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        Delete group
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="inf-card-body view-group-influencers-trigger" data-group-id="<?php echo esc_attr( $list['id'] ); ?>" data-group-name="<?php echo esc_attr( $list['name'] ); ?>">
                        <div class="inf-group-date"><?php echo esc_html( $list['date'] ?? wp_date('Y-m-d H:iA') ); ?></div>
                        
                        <div class="inf-group-avatars">
                            <?php
                            // Query for up to 5 influencers in this specific group to show avatars
                            $saved_posts = get_posts([
                                'post_type'      => 'saved-influencer',
                                'author'         => $user_id,
                                'posts_per_page' => 5,
                                'meta_query'     => [
                                    [ 'key' => 'saved_in_lists', 'value' => '"' . $list['id'] . '"', 'compare' => 'LIKE' ]
                                ]
                            ]);

                            if ( ! empty( $saved_posts ) ) {
                                foreach ( $saved_posts as $saved_post ) {
                                    $influencer_id = get_post_meta( $saved_post->ID, 'influencer_id', true );
                                    if ( $influencer_id ) {
                                        // Temporarily hijack the global $post to ensure the shortcode outputs the correct avatar
                                        $post = get_post( $influencer_id );
                                        setup_postdata( $post );
                                        
                                        echo '<div class="inf-avatar-wrapper">';
                                        echo do_shortcode( '[influencer_avatar]' );
                                        echo '</div>';
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
        <?php

        // Restore original post data globally
        $post = $original_post;
        if ( $post ) setup_postdata( $post );

        return ob_get_clean();
    }

    /**
     * AJAX Handler: Save User Search
     * Handles the server-side logic when the "Save Search" button is clicked.
     *
     * @return void Sends a JSON response.
     */
    public function handle_save_search_ajax() {
        check_ajax_referer('save_search_nonce', 'security');

        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please login to save searches.']);

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

        if (is_wp_error($post_id)) wp_send_json_error(['message' => 'Error creating save file.']);

        update_post_meta($post_id, 'search_query', $final_string);
        wp_send_json_success(['message' => 'Search saved successfully!']);
    }

    /**
     * Helper: Get Saved Influencer Post ID
     * Queries the database to find if the current user already has a saved entry.
     *
     * @param int $influencer_id The ID of the influencer.
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
     * Retrieves custom lists and normalizes data structures dynamically.
     *
     * @return void Sends a JSON response.
     */
    public function handle_get_modal_data_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'You must be logged in.']);

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $user_id = get_current_user_id();

        $user_lists = $this->get_normalized_groups($user_id);
        $active_lists = [];
        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);
        
        if ($post_id) {
            $saved_in = get_post_meta($post_id, 'saved_in_lists', true);
            if (is_array($saved_in)) {
                foreach ($saved_in as $idx => $val) {
                    if (!str_starts_with($val, 'grp_')) $saved_in[$idx] = 'grp_' . md5($val);
                }
                update_post_meta($post_id, 'saved_in_lists', $saved_in); 
                $active_lists = $saved_in;
            }
        }

        wp_send_json_success([
            'all_groups'   => array_values($user_lists),
            'active_lists' => $active_lists
        ]);
    }

    /**
     * AJAX Handler: Save Influencer to Lists
     * Overwrites the CPT list bindings using Group IDs.
     *
     * @return void Sends a JSON response.
     */
    public function handle_save_influencer_lists_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'You must be logged in to save.']);

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $selected_lists = isset($_POST['lists']) ? array_map('sanitize_text_field', (array)$_POST['lists']) : [];
        $user_id = get_current_user_id();

        if (empty($influencer_id)) wp_send_json_error(['message' => 'No Influencer ID provided.']);

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
     * AJAX Handler: Upsert (Create/Update) Group
     * Creates a new group or updates an existing one's name and description.
     * Records the creation date.
     *
     * @return void Sends a JSON response.
     */
    public function handle_upsert_group_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Unauthorized']);

        $user_id = get_current_user_id();
        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $desc = isset($_POST['desc']) ? sanitize_textarea_field($_POST['desc']) : '';

        if (empty($name)) wp_send_json_error(['message' => 'Group name is required.']);

        $user_lists = $this->get_normalized_groups($user_id);

        if (empty($group_id)) {
            $group_id = uniqid('grp_');
            $date = wp_date('Y-m-d H:iA');
        } else {
            // Preserve existing date if updating
            $date = isset($user_lists[$group_id]['date']) ? $user_lists[$group_id]['date'] : wp_date('Y-m-d H:iA');
        }

        $user_lists[$group_id] = [
            'id'   => $group_id,
            'name' => $name,
            'desc' => $desc,
            'date' => $date
        ];

        update_user_meta($user_id, 'custom_influencer_lists', $user_lists);
        wp_send_json_success(['group' => $user_lists[$group_id]]);
    }

    /**
     * AJAX Handler: Delete Group
     * Deletes the group and unassigns it from all relevant influencer saves.
     *
     * @return void Sends a JSON response.
     */
    public function handle_delete_group_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Unauthorized']);

        $user_id = get_current_user_id();
        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';

        if (empty($group_id)) wp_send_json_error(['message' => 'Group ID missing.']);

        $user_lists = $this->get_normalized_groups($user_id);
        if (isset($user_lists[$group_id])) {
            unset($user_lists[$group_id]);
            update_user_meta($user_id, 'custom_influencer_lists', $user_lists);
        }

        $saved_posts = get_posts([
            'post_type'      => 'saved-influencer',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'meta_query'     => [
                [ 'key' => 'saved_in_lists', 'value' => '"' . $group_id . '"', 'compare' => 'LIKE' ]
            ]
        ]);

        foreach ($saved_posts as $post) {
            $lists = get_post_meta($post->ID, 'saved_in_lists', true);
            if (is_array($lists)) {
                $lists = array_diff($lists, [$group_id]);
                if (empty($lists)) {
                    wp_delete_post($post->ID, true);
                } else {
                    update_post_meta($post->ID, 'saved_in_lists', $lists);
                }
            }
        }

        wp_send_json_success(['message' => 'Group deleted.']);
    }

    /**
     * AJAX Handler: Get Group Influencers
     * Fetches all influencers assigned to a specific group using Group ID.
     *
     * @return void Sends a JSON response.
     */
    public function handle_get_group_influencers_ajax() {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Unauthorized.']);

        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $user_id = get_current_user_id();

        if (empty($group_id)) wp_send_json_error(['message' => 'Invalid group requested.']);

        $saved_posts = get_posts([
            'post_type'      => 'saved-influencer',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'meta_query'     => [
                [ 'key' => 'saved_in_lists', 'value' => '"' . $group_id . '"', 'compare' => 'LIKE' ]
            ]
        ]);

        if (empty($saved_posts)) {
            wp_send_json_success(['html' => '<div class="inf-alert" style="margin:20px;">No creators found in this group.</div>']);
        }

        $html = '<ul class="inf-group-creators-list">';
        foreach ($saved_posts as $post) {
            $influencer_id = get_post_meta($post->ID, 'influencer_id', true);
            $influencer_title = get_the_title($influencer_id);
            $influencer_url = get_permalink($influencer_id);
            
            $html .= sprintf(
                '<li>
                    <a href="%s" target="_blank">%s</a>
                </li>',
                esc_url($influencer_url),
                esc_html($influencer_title ?: 'Unknown Creator')
            );
        }
        $html .= '</ul>';

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Renders Inline JavaScript, CSS, and HTML for the Modals & Shortcode.
     * Handles the complex DOM interactions for the custom dropdowns, modal views, 
     * and asynchronous saving.
     *
     * @return void Outputs directly to wp_footer.
     */
    public function render_inline_assets() {
        ?>
        <style>
            /* Shortcode Grid Styling (Updated to Match Video) */
            .inf-groups-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin: 20px 0; }
            .inf-group-card {
                background: #fdfdfd; border-radius: 8px; border: 1px solid #e2e4e7;
                display: flex; flex-direction: column; transition: 0.2s; position: relative;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04); height: 160px;
            }
            .inf-group-card:hover { border-color: #d0d3d8; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
            
            /* Card Header & Actions */
            .inf-card-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px 20px 0 20px; }
            .inf-group-title { margin: 0; font-size: 15px; color: #333; font-weight: 500; }
            .inf-card-actions { display: flex; gap: 4px; position: relative; z-index: 10; }
            .inf-btn-icon {
                background: transparent; border: none; cursor: pointer; color: #888; padding: 6px; 
                border-radius: 4px; display: flex; align-items: center; justify-content: center;
            }
            .inf-btn-icon:hover { background: #f0f2f5; color: #333; }
            
            /* Dropdown Menu */
            .inf-dropdown-wrapper { position: relative; }
            .inf-dropdown-menu {
                position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #eee;
                border-radius: 6px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 140px;
                display: none; z-index: 20; padding: 6px; margin-top: 4px;
            }
            .inf-dropdown-wrapper.active .inf-dropdown-menu { display: block; }
            .inf-dropdown-item {
                display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px;
                border: none; background: transparent; text-align: left; cursor: pointer;
                font-size: 13px; color: #444; border-radius: 4px; font-family: inherit;
            }
            .inf-dropdown-item:hover { background: #f8f9fa; color: #dc3545; }

            /* Card Body (Clickable Area) */
            .inf-card-body { padding: 0 20px 20px 20px; cursor: pointer; flex-grow: 1; display: flex; flex-direction: column; justify-content: flex-end; }
            .inf-group-date { font-size: 12px; color: #999; margin-bottom: 12px; }
            
            /* Avatars */
            .inf-group-avatars { display: flex; align-items: center; }
            .inf-avatar-wrapper { 
                width: 28px; height: 28px; border-radius: 50%; border: 2px solid #fff; 
                margin-left: -8px; overflow: hidden; background: #eee;
            }
            .inf-avatar-wrapper:first-child { margin-left: 0; }
            .inf-avatar-wrapper img { width: 100%; height: 100%; object-fit: cover; }

            .inf-alert { padding: 12px; background: #fff3cd; color: #856404; border-radius: 6px; }

            /* Modal Framework */
            .inf-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); z-index: 99999; display: none; align-items: center; justify-content: center; font-family: inherit; }
            .inf-modal-content { background: #fdfdfd; padding: 24px; border-radius: 12px; width: 100%; max-width: 420px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); position: relative; display: none; }
            .inf-modal-content.active-view { display: block; }
            .inf-modal-header { display: flex; align-items: center; margin-bottom: 20px; }
            .inf-modal-header h3 { margin: 0; font-size: 18px; color: #333; font-weight: 500; }
            .inf-btn-back { background: transparent; border: none; cursor: pointer; color: #666; padding: 0 10px 0 0; display: flex; align-items: center; gap: 4px; font-size:14px; }
            .inf-btn-back:hover { color: #333; }
            .inf-input-group { margin-bottom: 16px; }
            .inf-input-group label { display: block; margin-bottom: 6px; font-size: 13px; color: #444; font-weight: 500;}
            .inf-input-group label span { color: #dc3545; }
            .inf-input, .inf-textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
            .inf-textarea { resize: vertical; min-height: 80px; }
            .inf-lists-container { max-height: 250px; overflow-y: auto; margin-bottom: 12px; }
            .inf-list-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 4px; border-radius: 6px; }
            .inf-list-item:hover { background: #f5f5f5; }
            .inf-list-item-left { display: flex; align-items: center; flex-grow: 1; cursor: pointer; }
            .inf-list-item-left input { margin-right: 12px; cursor: pointer; }
            .inf-list-item-left label { cursor: pointer; font-size: 14px; color: #444; user-select: none; }
            .inf-create-btn { background: none; border: none; color: #666; font-size: 14px; cursor: pointer; padding: 10px 4px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; width: 100%; }
            .inf-create-btn:hover { color: #5034c4; }
            .inf-modal-actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; }
            .inf-btn { flex: 1; padding: 10px; border-radius: 8px; font-weight: 500; cursor: pointer; text-align: center; border: none; transition: 0.2s; font-size: 14px;}
            .inf-btn-cancel { background: transparent; color: #555; border: 1px solid #ddd; }
            .inf-btn-cancel:hover { background: #eaeaea; }
            .inf-btn-save { background: #5034c4; color: #fff; }
            .inf-btn-save:hover { background: #40299e; }
            .inf-group-creators-list { list-style: none; padding: 0; margin: 0; max-height: 300px; overflow-y: auto; }
            .inf-group-creators-list li { padding: 12px 20px; border-bottom: 1px solid #eaeaea; }
            .inf-group-creators-list li:last-child { border-bottom: none; }
            .inf-group-creators-list a { color: #333; text-decoration: none; font-weight: 500; }
            .inf-group-creators-list a:hover { color: #5034c4; }
        </style>

        <div id="inf-modal-overlay" class="inf-modal-overlay">
            
            <div id="inf-view-manage" class="inf-modal-content">
                <div class="inf-modal-header"><h3>Manage groups</h3></div>
                <div class="inf-lists-container" id="inf-lists-wrapper"></div>
                <button type="button" class="inf-create-btn" id="inf-btn-go-create">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create new group
                </button>
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-modal-save-influencer">Save</button>
                </div>
            </div>

            <div id="inf-view-edit" class="inf-modal-content">
                <div class="inf-modal-header">
                    <button class="inf-btn-back" id="inf-btn-back-manage">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back
                    </button>
                </div>
                <input type="hidden" id="inf-edit-id" value="">
                <div class="inf-input-group">
                    <label>Group name<span>*</span></label>
                    <input type="text" id="inf-edit-name" class="inf-input" placeholder="Enter group name">
                </div>
                <div class="inf-input-group">
                    <label>Description</label>
                    <textarea id="inf-edit-desc" class="inf-textarea" placeholder="Enter group description (optional)"></textarea>
                </div>
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-modal-save-group">Save</button>
                </div>
            </div>

            <div id="inf-view-influencers" class="inf-modal-content" style="padding:0; padding-bottom: 12px;">
                <div class="inf-modal-header" style="padding: 24px 24px 0 24px;">
                    <h3 id="inf-view-group-title">Loading...</h3>
                </div>
                <div id="inf-view-group-body">
                    <div style="text-align:center; padding:20px;">Loading creators...</div>
                </div>
                <div style="padding: 0 24px;">
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal" style="width: 100%;">Close</button>
                </div>
            </div>

        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {

                function display_mycred_notice(html) {
                    var $n = $('<div class="notice-wrap"><div class="notice-item-wrapper"><div class="notice-item succes">' + html + '</div></div></div>');
                    $('body').append($n); $n.fadeIn(300);
                    setTimeout(function () { $n.fadeOut(300, function () { $(this).remove(); }); }, 4000);
                }

                // Global State
                let state = { influencerId: null, triggerBtn: null, groups: [], activeIds: [], entryPoint: '' };

                // Navigation Helper
                function switchModalView(viewId) {
                    $('.inf-modal-content').removeClass('active-view');
                    $('#' + viewId).addClass('active-view');
                    $('#inf-modal-overlay').css('display', 'flex');
                }
                $('.inf-close-modal').on('click', function() { $('#inf-modal-overlay').hide(); });
                $('#inf-modal-overlay').on('click', function(e) { if (e.target === this) $(this).hide(); });

                // Render Checkboxes
                function renderGroupsList() {
                    let html = '';
                    if(state.groups.length === 0) {
                        html = '<p style="font-size:13px; color:#666;">No groups found. Create one below.</p>';
                    } else {
                        state.groups.forEach(function(g) {
                            let checked = state.activeIds.includes(g.id) ? 'checked' : '';
                            html += `
                                <div class="inf-list-item">
                                    <div class="inf-list-item-left">
                                        <input type="checkbox" id="chk_${g.id}" value="${g.id}" class="inf-list-checkbox" ${checked}>
                                        <label for="chk_${g.id}">${g.name}</label>
                                    </div>
                                    <button type="button" class="inf-btn-icon inf-trigger-edit-group" data-id="${g.id}" data-name="${g.name}" data-desc="${g.desc}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                    </button>
                                </div>
                            `;
                        });
                    }
                    $('#inf-lists-wrapper').html(html);
                }

                // 1. Influencer Saving Flow
                $(document).on('click', '.save-influencer-trigger', function (e) {
                    e.preventDefault();
                    state.triggerBtn = $(this);
                    state.influencerId = state.triggerBtn.attr('influencer-id');
                    state.entryPoint = 'influencer';
                    
                    let $btnText = state.triggerBtn.find('.elementor-button-text');
                    let ogText = $btnText.text();
                    $btnText.text('Loading...'); state.triggerBtn.prop('disabled', true);

                    $.ajax({
                        url: ajax_vars.ajax_url, type: 'POST',
                        data: { action: 'get_influencer_modal_data', security: ajax_vars.save_influencer_nonce, influencer_id: state.influencerId },
                        success: function (res) {
                            if (res.success) {
                                state.groups = res.data.all_groups;
                                state.activeIds = res.data.active_lists;
                                renderGroupsList();
                                switchModalView('inf-view-manage');
                            } else { alert('Error: ' + res.data.message); }
                            $btnText.text(ogText); state.triggerBtn.prop('disabled', false);
                        }
                    });
                });

                $('#inf-modal-save-influencer').on('click', function() {
                    let selected = [];
                    $('.inf-list-checkbox:checked').each(function() { selected.push($(this).val()); });
                    let $btn = $(this); $btn.text('Saving...').prop('disabled', true);

                    $.ajax({
                        url: ajax_vars.ajax_url, type: 'POST',
                        data: { action: 'save_influencer_to_lists', security: ajax_vars.save_influencer_nonce, influencer_id: state.influencerId, lists: selected },
                        success: function (res) {
                            if (res.success) {
                                if (res.data.notice_html) display_mycred_notice(res.data.notice_html);
                                let $text = state.triggerBtn.find('.elementor-button-text');
                                if(res.data.status === 'saved') { $text.text('SAVED'); state.triggerBtn.addClass('delete-save'); } 
                                else { $text.text('UNSAVE'); state.triggerBtn.removeClass('delete-save'); }
                                $('#inf-modal-overlay').hide();
                            } else { alert(res.data.message); }
                            $btn.text('Save').prop('disabled', false);
                        }
                    });
                });

                // 2. Group Edit / Create Flow
                $('#inf-btn-go-create').on('click', function() {
                    $('#inf-edit-id, #inf-edit-name, #inf-edit-desc').val('');
                    $('#inf-btn-back-manage').show();
                    switchModalView('inf-view-edit');
                });

                $(document).on('click', '.inf-trigger-edit-group', function(e) {
                    e.stopPropagation(); // Prevent triggering card body click
                    $('#inf-edit-id').val($(this).attr('data-id'));
                    $('#inf-edit-name').val($(this).attr('data-name'));
                    $('#inf-edit-desc').val($(this).attr('data-desc'));
                    
                    if (state.entryPoint === 'influencer') { $('#inf-btn-back-manage').show(); } 
                    else { $('#inf-btn-back-manage').hide(); }
                    
                    switchModalView('inf-view-edit');
                });

                $('#inf-btn-back-manage').on('click', function() { switchModalView('inf-view-manage'); });

                $('#inf-modal-save-group').on('click', function() {
                    let id = $('#inf-edit-id').val();
                    let name = $('#inf-edit-name').val().trim();
                    let desc = $('#inf-edit-desc').val().trim();
                    if (!name) { alert("Group name is required."); return; }
                    let $btn = $(this); $btn.text('Saving...').prop('disabled', true);

                    $.ajax({
                        url: ajax_vars.ajax_url, type: 'POST',
                        data: { action: 'upsert_influencer_group', security: ajax_vars.save_influencer_nonce, group_id: id, name: name, desc: desc },
                        success: function (res) {
                            if (res.success) {
                                let newGrp = res.data.group;
                                if (state.entryPoint === 'influencer') {
                                    let idx = state.groups.findIndex(g => g.id === newGrp.id);
                                    if (idx > -1) state.groups[idx] = newGrp; else { state.groups.push(newGrp); state.activeIds.push(newGrp.id); }
                                    renderGroupsList();
                                    switchModalView('inf-view-manage');
                                } else {
                                    let $card = $('#card-' + newGrp.id);
                                    if($card.length) {
                                        $card.find('.inf-group-title').text(newGrp.name);
                                        $card.find('.inf-trigger-edit-group').attr('data-name', newGrp.name).attr('data-desc', newGrp.desc);
                                        $card.find('.view-group-influencers-trigger').attr('data-group-name', newGrp.name);
                                    } else { location.reload(); }
                                    $('#inf-modal-overlay').hide();
                                }
                            } else { alert(res.data.message); }
                            $btn.text('Save').prop('disabled', false);
                        }
                    });
                });

                // 3. Shortcode Interactions (Dropdown, View, Delete)
                $('.inf-groups-grid').on('click', '.inf-trigger-edit-group', function() { state.entryPoint = 'shortcode'; });

                // Toggle 3-dot dropdown menu
                $(document).on('click', '.inf-trigger-dropdown', function(e) {
                    e.stopPropagation();
                    $('.inf-dropdown-wrapper').removeClass('active'); // Close others
                    $(this).closest('.inf-dropdown-wrapper').toggleClass('active');
                });
                $(document).click(function() { $('.inf-dropdown-wrapper').removeClass('active'); });

                // View Creators
                $(document).on('click', '.view-group-influencers-trigger', function() {
                    let id = $(this).attr('data-group-id');
                    let name = $(this).attr('data-group-name');
                    $('#inf-view-group-title').text(name);
                    $('#inf-view-group-body').html('<div style="text-align:center; padding:20px;">Loading creators...</div>');
                    switchModalView('inf-view-influencers');

                    $.ajax({
                        url: ajax_vars.ajax_url, type: 'POST',
                        data: { action: 'get_group_influencers', security: ajax_vars.save_influencer_nonce, group_id: id },
                        success: function(res) {
                            if (res.success) $('#inf-view-group-body').html(res.data.html);
                            else $('#inf-view-group-body').html('<div class="inf-alert">' + res.data.message + '</div>');
                        }
                    });
                });

                // Delete Group
                $(document).on('click', '.inf-trigger-delete-group', function(e) {
                    e.stopPropagation();
                    $('.inf-dropdown-wrapper').removeClass('active');
                    if(!confirm("Are you sure you want to delete this group? This will remove the group from all saved creators.")) return;
                    
                    let id = $(this).attr('data-id');
                    let $card = $('#card-' + id);
                    $card.css('opacity', '0.5');

                    $.ajax({
                        url: ajax_vars.ajax_url, type: 'POST',
                        data: { action: 'delete_influencer_group', security: ajax_vars.save_influencer_nonce, group_id: id },
                        success: function(res) {
                            if (res.success) $card.fadeOut(300, function(){ $(this).remove(); });
                            else { alert(res.data.message); $card.css('opacity', '1'); }
                        }
                    });
                });

                // 4. Search Form Saving (Unchanged)
                $('.save-search-trigger').on('click', function (e) {
                    e.preventDefault();
                    let $btn = $(this); let ogText = $btn.text(); $btn.text('Saving...');
                    let getChecked = (name) => { let v=[]; $('input[name^="'+name+'"]:checked').each(function(){v.push($(this).val());}); return v; };

                    let searchData = {
                        'niche': getChecked('niche'), 'platform': getChecked('platform'), 'followers': getChecked('followers'), 
                        'country': getChecked('country'), 'lang': getChecked('lang'), 'gender': getChecked('gender'), 'score': $('input[name="score"]').val()
                    };

                    $.ajax({
                        url: ajax_vars.ajax_url, type: 'POST',
                        data: { action: 'save_user_search', security: ajax_vars.save_search_nonce, search_data: searchData },
                        success: function (res) {
                            if (res.success) { $btn.text('Saved!'); setTimeout(() => $btn.text(ogText), 2000); } 
                            else { alert(res.data.message); $btn.text(ogText); }
                        }
                    });
                });
            });
        </script>
        <?php
    }
}

// Initialize the class
new Saves_Manager();