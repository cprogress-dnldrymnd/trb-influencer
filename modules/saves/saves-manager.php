<?php

/**
 * Plugin Name: Saves Manager
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Description: Pro-level manager for handling saved searches and advanced influencer list grouping.
 *
 * Class Saves_Manager
 * Handles server-side AJAX operations, custom shortcodes, user meta list tracking, 
 * and client-side modal scripts for saving searches and influencers.
 */
class Saves_Manager
{

    /**
     * Constructor: Initialize hooks, actions, and shortcodes.
     * Binds all necessary AJAX actions, shortcodes, and script enqueues.
     *
     * @return void
     */
    public function __construct()
    {

        // NEW: Register Native Post Types
        add_action('init', [$this, 'register_native_post_types']);

        // NEW: Disable Edit/View/Quick Edit Row Actions
        add_filter('post_row_actions', [$this, 'disable_cpt_row_actions'], 10, 2);

        // NEW: Map Row Actions to our Custom Title Column
        add_filter('list_table_primary_column', [$this, 'set_custom_primary_column'], 10, 2);

        // NEW: Admin columns for Saved Influencers
        add_filter('manage_saved-influencer_posts_columns', [$this, 'add_saved_influencer_admin_columns']);
        add_action('manage_saved-influencer_posts_custom_column', [$this, 'populate_saved_influencer_admin_columns'], 10, 2);

        // NEW: Admin columns for Saved Searches
        add_filter('manage_saved-search_posts_columns', [$this, 'add_saved_search_admin_columns']);
        add_action('manage_saved-search_posts_custom_column', [$this, 'populate_saved_search_admin_columns'], 10, 2);

        // NEW: Admin columns for Viewed Influencers
        add_filter('manage_viewed-influencer_posts_columns', [$this, 'add_viewed_influencer_admin_columns']);
        add_action('manage_viewed-influencer_posts_custom_column', [$this, 'populate_viewed_influencer_admin_columns'], 10, 2);

        // NEW: Integrate viewing tracker
        add_action('template_redirect', [$this, 'track_influencer_post_view']);

        // AJAX hooks for logged-in users
        add_action('wp_ajax_save_user_search', [$this, 'handle_save_search_ajax']);
        add_action('wp_ajax_get_influencer_modal_data', [$this, 'handle_get_modal_data_ajax']);
        add_action('wp_ajax_save_influencer_to_lists', [$this, 'handle_save_influencer_lists_ajax']);
        add_action('wp_ajax_get_group_influencers', [$this, 'handle_get_group_influencers_ajax']);
        add_action('wp_ajax_upsert_influencer_group', [$this, 'handle_upsert_group_ajax']);
        add_action('wp_ajax_delete_influencer_group', [$this, 'handle_delete_group_ajax']);
        add_action('wp_ajax_remove_influencer_from_group', [$this, 'handle_remove_influencer_from_group_ajax']);
        add_action('wp_ajax_bulk_remove_from_group', [$this, 'handle_bulk_remove_from_group_ajax']);
        add_action('wp_ajax_unlock_and_save_influencer', [$this, 'handle_unlock_and_save_ajax']);

        // Saved Searches Pagination & Deletion
        add_action('wp_ajax_load_more_saved_searches', [$this, 'handle_load_more_searches_ajax']);
        add_action('wp_ajax_delete_saved_search', [$this, 'handle_delete_saved_search_ajax']);

        // Shortcodes
        add_shortcode('my_saved_groups', [$this, 'render_saved_groups_shortcode']);
        add_shortcode('add_to_groups_btn', [$this, 'render_add_to_groups_shortcode']);
        add_shortcode('remove_from_group_btn', [$this, 'render_remove_from_group_shortcode']);
        add_shortcode('my_saved_searches', [$this, 'render_saved_searches_shortcode']);

        // Frontend hooks for injecting variables, styles, and scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_ajax_variables']);
        add_action('wp_footer', [$this, 'render_inline_assets'], 100);

        add_action('admin_init', [$this, 'maybe_migrate_group_companion_meta']);
    }

    /**
     * One-time migration: backfills _in_group_{id} companion meta for all existing
     * saved-influencer posts so the indexed EXISTS query replaces the old LIKE query.
     */
    public function maybe_migrate_group_companion_meta()
    {
        if (get_option('dd_group_meta_migrated')) {
            return;
        }

        $posts = get_posts([
            'post_type'              => 'saved-influencer',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'update_post_term_cache' => false,
        ]);

        foreach ($posts as $post_id) {
            $lists = get_post_meta($post_id, 'saved_in_lists', true);
            if (is_array($lists)) {
                foreach ($lists as $group_id) {
                    if ($group_id) {
                        update_post_meta($post_id, '_in_group_' . sanitize_key($group_id), 1);
                    }
                }
            }
        }

        update_option('dd_group_meta_migrated', 1);
    }

    /**
     * Integrates the "Viewed Influencer" tracking logic.
     * Restricts existing log lookups to the current month/year to ensure 
     * unique logging cycles per month.
     * * @author Digitally Disruptive - Donald Raymundo
     * @link   https://digitallydisruptive.co.uk/
     */
    public function track_influencer_post_view()
    {
        // 1. Exit early if not a logged-in user viewing a singular influencer post.
        if (! is_user_logged_in() || ! is_singular('influencer')) {
            return;
        }

        $current_user_id = get_current_user_id();
        $influencer_id   = get_the_ID();
        $current_time    = current_time('d-M-Y H:i:s');
        $post_title      = 'Viewed on ' . $current_time;

        // 2. Query optimization: Prevent unnecessary SQL calculations and enforce monthly boundary.
        $existing_log = get_posts([
            'post_type'              => 'viewed-influencer',
            'author'                 => $current_user_id,
            'meta_key'               => 'influencer_id',
            'meta_value'             => $influencer_id,
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'post_status'            => 'any',
            'no_found_rows'          => true,  // Bypasses expensive SQL_CALC_FOUND_ROWS
            'ignore_sticky_posts'    => true,  // Skips sticky post logic
            'update_post_meta_cache' => false, // Optimization: We only need the ID
            'update_post_term_cache' => false, // Optimization: We only need the ID
            'date_query'             => [      // Enforces current month/year boundary
                [
                    'year'  => current_time('Y'),
                    'month' => current_time('n'),
                ],
            ],
        ]);

        // 3. Update existing log for the current month, or create a new one.
        if (! empty($existing_log)) {

            // Prevent WP from creating a heavy database revision just for a timestamp update
            remove_action('post_updated', 'wp_save_post_revision');

            wp_update_post([
                'ID'         => $existing_log[0],
                'post_title' => $post_title,
            ]);

            // Re-hook revisions to maintain normal site functionality elsewhere
            add_action('post_updated', 'wp_save_post_revision');
        } else {

            $new_id = wp_insert_post([
                'post_title'  => $post_title,
                'post_type'   => 'viewed-influencer',
                'post_status' => 'publish',
                'post_author' => $current_user_id,
            ]);

            if (! is_wp_error($new_id)) {
                update_post_meta($new_id, 'influencer_id', $influencer_id);
            }
        }
    }

    /**
     * Registers the custom post types natively.
     * Configures them as non-public, background data structures. 
     */
    public function register_native_post_types()
    {
        // 1. Saved Influencer CPT
        $influencer_args = [
            'labels'              => [
                'name'          => 'Saved Influencers',
                'singular_name' => 'Saved Influencer',
                'menu_name'     => 'Saved Influencers',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-groups',
            'supports'            => ['title', 'author'],
            'capabilities'        => ['create_posts' => 'do_not_allow'],
            'map_meta_cap'        => true,
        ];
        register_post_type('saved-influencer', $influencer_args);

        // 2. Saved Search CPT
        $search_args = [
            'labels'              => [
                'name'          => 'Saved Searches',
                'singular_name' => 'Saved Search',
                'menu_name'     => 'Saved Searches',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-search',
            'supports'            => ['title', 'author'],
            'capabilities'        => ['create_posts' => 'do_not_allow'],
            'map_meta_cap'        => true,
        ];
        register_post_type('saved-search', $search_args);

        // 3. NEW: Viewed Influencer CPT
        $viewed_args = [
            'labels'              => [
                'name'          => 'Viewed Influencers',
                'singular_name' => 'Viewed Influencer',
                'menu_name'     => 'Viewed Influencers',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-visibility',
            'supports'            => ['title', 'author'],
            'capabilities'        => ['create_posts' => 'do_not_allow'],
            'map_meta_cap'        => true,
        ];
        register_post_type('viewed-influencer', $viewed_args);
    }

    /**
     * Intercepts and removes the "Edit", "Quick Edit", and "View" links.
     */
    public function disable_cpt_row_actions($actions, $post)
    {
        $restricted_types = ['saved-influencer', 'saved-search', 'viewed-influencer'];

        if (in_array($post->post_type, $restricted_types)) {
            unset($actions['edit']);
            unset($actions['inline hide-if-no-js']);
            unset($actions['view']);
        }
        return $actions;
    }

    /**
     * Instructs WordPress to treat our 'custom_title' column as the primary column.
     */
    public function set_custom_primary_column($default, $screen)
    {
        $restricted_screens = ['edit-saved-search', 'edit-saved-influencer', 'edit-viewed-influencer'];

        if (in_array($screen, $restricted_screens)) {
            return 'custom_title';
        }
        return $default;
    }

    /**
     * Columns for Saved Influencers
     */
    public function add_saved_influencer_admin_columns($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            // Replace native title with our unclickable custom title
            if ($key === 'title') {
                $new_columns['custom_title']  = 'Title';
                $new_columns['influencer_id'] = 'Influencer ID';
                $new_columns['saved_groups']  = 'Saved Groups';
            } else {
                $new_columns[$key] = $title;
            }
        }
        return $new_columns;
    }

    public function populate_saved_influencer_admin_columns($column, $post_id)
    {
        if ($column === 'custom_title') {
            echo '<strong>' . esc_html(get_the_title($post_id)) . '</strong>';
        }

        if ($column === 'influencer_id') {
            $influencer_id = get_post_meta($post_id, 'influencer_id', true);
            echo $influencer_id ? esc_html($influencer_id) : 'â€”';
        }

        if ($column === 'saved_groups') {
            $saved_in = get_post_meta($post_id, 'saved_in_lists', true);

            if (empty($saved_in) || !is_array($saved_in)) {
                echo '<em>Uncategorized</em>';
                return;
            }

            $author_id = get_post_field('post_author', $post_id);
            $user_lists = $this->get_normalized_groups($author_id);

            $group_names = [];
            foreach ($saved_in as $group_id) {
                if (isset($user_lists[$group_id])) {
                    $group_names[] = $user_lists[$group_id]['name'];
                } else {
                    $group_names[] = '<em>Unknown Group</em>';
                }
            }
            echo wp_kses_post(implode(', ', $group_names));
        }
    }

    /**
     * Columns for Saved Searches
     */
    public function add_saved_search_admin_columns($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            // Replace native title with our unclickable custom title
            if ($key === 'title') {
                $new_columns['custom_title']   = 'Title';
                $new_columns['search_filters'] = 'Filters';
            } else {
                $new_columns[$key] = $title;
            }
        }
        return $new_columns;
    }

    public function populate_saved_search_admin_columns($column, $post_id)
    {
        if ($column === 'custom_title') {
            echo '<strong>' . esc_html(get_the_title($post_id)) . '</strong>';
        }

        if ($column === 'search_filters') {
            $query = get_post_meta($post_id, 'search_query', true);

            if (empty($query)) {
                echo '<em>No specific filters applied</em>';
                return;
            }

            // Parse the URL query string into an array
            parse_str(ltrim($query, '?'), $params);

            $desc_parts = [];
            foreach ($params as $k => $v) {
                // Flatten arrays (e.g., multiple niches selected)
                if (is_array($v)) {
                    $v = implode(', ', $v);
                }

                // Clean up the key name for display
                $k_clean = ucfirst(str_replace('_', ' ', $k));

                // Build the formatted string
                $desc_parts[] = '<strong>' . esc_html($k_clean) . ':</strong> ' . esc_html($v);
            }

            echo !empty($desc_parts) ? wp_kses_post(implode(' | ', $desc_parts)) : '<em>No specific filters applied</em>';
        }
    }

    /**
     * Columns for Viewed Influencers
     */
    public function add_viewed_influencer_admin_columns($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            if ($key === 'title') {
                $new_columns['custom_title']  = 'Title';
                $new_columns['influencer_id'] = 'Influencer ID';
            } else {
                $new_columns[$key] = $title;
            }
        }
        return $new_columns;
    }

    public function populate_viewed_influencer_admin_columns($column, $post_id)
    {
        if ($column === 'custom_title') {
            echo '<strong>' . esc_html(get_the_title($post_id)) . '</strong>';
        }

        if ($column === 'influencer_id') {
            $influencer_id = get_post_meta($post_id, 'influencer_id', true);
            echo $influencer_id ? esc_html($influencer_id) : 'â€”';
        }
    }

    /**
     * Enqueues AJAX localized variables.
     *
     * @return void
     */
    public function enqueue_ajax_variables()
    {
        wp_enqueue_script(
            'theme-saves-js',
            get_stylesheet_directory_uri() . '/assets/js/modules/saves-manager.js',
            ['dd-modal'],
            HELLO_ELEMENTOR_CHILD_VERSION,
            true
        );
        wp_localize_script('theme-saves-js', 'ajax_vars', [
            'ajax_url'              => admin_url('admin-ajax.php'),
            'save_search_nonce'     => wp_create_nonce('save_search_nonce'),
            'save_influencer_nonce' => wp_create_nonce('save_influencer_nonce'),
            'export_pdf_nonce'      => wp_create_nonce('creatordb_export_saved_list_pdf'),
            'is_single_influencer'  => is_singular('influencer') ? true : false,
        ]);
    }

    /**
     * Data Normalization Helper: Get User Groups
     *
     * @param int $user_id The user ID.
     * @return array Array of group objects.
     */
    private function get_normalized_groups($user_id)
    {
        $groups = get_user_meta($user_id, 'custom_influencer_lists', true);
        $groups = is_array($groups) ? $groups : [];
        $normalized = [];
        $changed = false;

        foreach ($groups as $key => $val) {
            if (is_string($val)) {
                $id = 'grp_' . md5($val);
                $normalized[$id] = [
                    'id'   => $id,
                    'name' => $val,
                    'desc' => '',
                    'date' => wp_date('Y-m-d H:iA')
                ];
                $changed = true;
            } else {
                if (!isset($val['date'])) {
                    $val['date'] = wp_date('Y-m-d H:iA');
                    $changed = true;
                }
                $normalized[$key] = $val;
            }
        }

        if ($changed) {
            update_user_meta($user_id, 'custom_influencer_lists', $normalized);
        }

        return $normalized;
    }

    /**
     * Helper: Get Saved Influencer Post ID
     *
     * @param int $influencer_id The ID of the influencer.
     * @param int $user_id The User ID.
     * @return int|bool The Post ID if found, false otherwise.
     */
    private function get_existing_influencer_save_id($influencer_id, $user_id)
    {
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
     * Shortcode: Add to Groups Button
     */
    public function render_add_to_groups_shortcode($atts)
    {
        if (! is_user_logged_in()) return '';

        $influencer_id = get_the_ID();
        if (! $influencer_id) return '';

        $user_id = get_current_user_id();

        // --- UNLOCKED CHECK ---
        if (!is_influencer_unlocked($influencer_id)) {
            ob_start();
?>
            <div class="elementor-button-wrapper add-to-groups" data-locked="true" influencer-id="<?php echo esc_attr($influencer_id); ?>" style="cursor: not-allowed;" title="You need to unlock this influencer first before saving.">
                <button type="button" class="elementor-button elementor-button-link elementor-size-sm" disabled style="pointer-events: none; opacity: 0.6;">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-icon">
                            <svg aria-hidden="true" class="e-font-icon-svg e-fas-bookmark" viewBox="0 0 384 512" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor" d="M0 512V48C0 21.49 21.49 0 48 0h288c26.51 0 48 21.49 48 48v464L192 400 0 512z"></path>
                            </svg>
                        </span>
                        <span class="elementor-button-text">SAVE</span>
                    </span>
                </button>
            </div>
        <?php
            return ob_get_clean();
        }

        // --- STANDARD SAVE BUTTON ---
        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);
        $button_text = 'SAVE';
        $extra_class = '';

        if ($post_id) {
            $saved_in = get_post_meta($post_id, 'saved_in_lists', true);
            if (is_array($saved_in) && count($saved_in) > 0) {
                $count = count($saved_in);
                $button_text = "SAVED({$count})";
                $extra_class = 'delete-save';
            }
        }

        ob_start();
        ?>
        <div class="elementor-button-wrapper add-to-groups save-influencer-trigger <?php echo esc_attr($extra_class); ?>" influencer-id="<?php echo esc_attr($influencer_id); ?>" style="cursor: pointer;">
            <button type="button" class="elementor-button elementor-button-link elementor-size-sm" style="pointer-events: none;">
                <span class="elementor-button-content-wrapper">
                    <span class="elementor-button-icon">
                        <svg aria-hidden="true" class="e-font-icon-svg e-fas-bookmark" viewBox="0 0 384 512" xmlns="http://www.w3.org/2000/svg">
                            <path fill="currentColor" d="M0 512V48C0 21.49 21.49 0 48 0h288c26.51 0 48 21.49 48 48v464L192 400 0 512z"></path>
                        </svg>
                    </span>
                    <span class="elementor-button-text"><?php echo esc_html($button_text); ?></span>
                </span>
            </button>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Remove from Group Button
     * Used exclusively inside the group popup loop to remove an influencer from that list.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_remove_from_group_shortcode($atts)
    {
        if (! is_user_logged_in()) {
            return '';
        }

        $influencer_id = get_the_ID();
        if (! $influencer_id) {
            return '';
        }

        ob_start();
    ?>
        <div class="elementor-button-wrapper remove-from-group inf-remove-from-group-trigger" data-influencer-id="<?php echo esc_attr($influencer_id); ?>" style="cursor: pointer;">
            <button type="button" class="elementor-button elementor-button-link elementor-size-sm" style="pointer-events: none;">
                <span class="elementor-button-content-wrapper">
                    <span class="elementor-button-icon">
                        <svg aria-hidden="true" class="e-font-icon-svg e-fas-times" viewBox="0 0 352 512" xmlns="http://www.w3.org/2000/svg">
                            <path fill="currentColor" d="M242.72 256l100.07-100.07c12.28-12.28 12.28-32.19 0-44.48l-22.24-22.24c-12.28-12.28-32.19-12.28-44.48 0L176 189.28 75.93 89.21c-12.28-12.28-32.19-12.28-44.48 0L9.21 111.45c-12.28 12.28-12.28 32.19 0 44.48L109.28 256 9.21 356.07c-12.28 12.28-12.28 32.19 0 44.48l22.24 22.24c12.28 12.28 32.2 12.28 44.48 0L176 322.72l100.07 100.07c12.28 12.28 32.2 12.28 44.48 0l22.24-22.24c12.28-12.28 12.28-32.19 0-44.48L242.72 256z"></path>
                        </svg>
                    </span>
                    <span class="elementor-button-text">REMOVE</span>
                </span>
            </button>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Render user's custom saved groups.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_saved_groups_shortcode($atts)
    {
        if (! is_user_logged_in()) {
            return '<div class="inf-alert">Please log in to view your saved groups.</div>';
        }

        $user_id = get_current_user_id();
        $user_lists = $this->get_normalized_groups($user_id);

        global $post;
        $original_post = $post;

        ob_start();
    ?>
        <div class="inf-saved-groups-wrapper">
            <div class="inf-saved-groups-header">
                <button class="inf-btn inf-shortcode-add-group" style="flex: unset !important">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    New Group
                </button>
            </div>

            <?php if (empty($user_lists)) : ?>
                <div class="inf-alert">You have not created any custom groups yet.</div>
            <?php else : ?>
                <div class="inf-groups-grid" id="inf-groups-shortcode-grid">
                    <?php foreach ($user_lists as $list) : ?>
                        <div class="inf-group-card" id="card-<?php echo esc_attr($list['id']); ?>">

                            <div class="inf-card-header">
                                <div class="inf-group-header-left">
                                    <h4 class="inf-group-title" data-field="name"><?php echo esc_html($list['name']); ?></h4>
                                    <?php if (!empty($list['desc'])) : ?>
                                        <div class="inf-group-desc" data-field="desc"><?php echo esc_html($list['desc']); ?></div>
                                    <?php else : ?>
                                        <div class="inf-group-desc" data-field="desc" style="display:none;"></div>
                                    <?php endif; ?>
                                </div>

                                <div class="inf-card-actions">
                                    <button class="inf-btn-icon inf-trigger-edit-group" data-id="<?php echo esc_attr($list['id']); ?>" data-name="<?php echo esc_attr($list['name']); ?>" data-desc="<?php echo esc_attr($list['desc']); ?>" title="Edit Group">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg>
                                    </button>
                                    <div class="inf-dropdown-wrapper">
                                        <button class="inf-btn-icon inf-trigger-dropdown" title="Options">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1"></circle>
                                                <circle cx="19" cy="12" r="1"></circle>
                                                <circle cx="5" cy="12" r="1"></circle>
                                            </svg>
                                        </button>
                                        <div class="inf-dropdown-menu">
                                            <button class="inf-dropdown-item inf-trigger-delete-group" data-id="<?php echo esc_attr($list['id']); ?>">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="3 6 5 6 21 6"></polyline>
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                </svg>
                                                Delete group
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="inf-card-body view-group-influencers-trigger" data-group-id="<?php echo esc_attr($list['id']); ?>" data-group-name="<?php echo esc_attr($list['name']); ?>">
                                <div class="inf-group-date"><?php echo esc_html($list['date'] ?? wp_date('Y-m-d H:iA')); ?></div>

                                <div class="inf-group-avatars">
                                    <?php
                                    $saved_posts = get_posts([
                                        'post_type'      => 'saved-influencer',
                                        'author'         => $user_id,
                                        'posts_per_page' => 5,
                                        'meta_query'     => [
                                            ['key' => '_in_group_' . sanitize_key($list['id']), 'compare' => 'EXISTS']
                                        ]
                                    ]);

                                    if (! empty($saved_posts)) {
                                        foreach ($saved_posts as $saved_post) {
                                            $influencer_id = get_post_meta($saved_post->ID, 'influencer_id', true);
                                            if ($influencer_id) {
                                                $post = get_post($influencer_id);
                                                setup_postdata($post);

                                                echo '<div class="inf-avatar-wrapper">';
                                                echo do_shortcode('[influencer_avatar]');
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
            <?php endif; ?>
        </div>
    <?php

        $post = $original_post;
        if ($post) setup_postdata($post);

        return ob_get_clean();
    }

    /**
     * Helper: Generate a single Saved Search Card HTML block
     * Used by both the shortcode and the load more AJAX
     * * @param WP_Post $post
     * @return string
     */
    private function generate_search_card_html($post)
    {
        $query = get_post_meta($post->ID, 'search_query', true);
        $date = get_the_date('M j, Y \a\t g:i a', $post->ID);

        // Parse query string to generate a human-readable list of active filters
        parse_str(ltrim($query, '?'), $params);
        $desc_parts = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $v = implode(', ', $v);
            }
            $k_clean = ucfirst(str_replace('_', ' ', $k));
            $desc_parts[] = '<strong>' . esc_html($k_clean) . ':</strong> ' . esc_html($v);
        }
        $desc_text = !empty($desc_parts) ? implode(' | ', $desc_parts) : 'No specific filters applied';

        $search_url = get_permalink(dd_get_page_id('dd_search_results_page_id', 1949)) . $query;

        ob_start();
    ?>
        <div class="inf-group-card" id="search-card-<?php echo esc_attr($post->ID); ?>">
            <div class="inf-card-header">
                <div class="inf-group-header-left">
                    <h4 class="inf-group-title"><?php echo esc_html($post->post_title); ?></h4>
                    <div class="inf-group-desc" style="display:-webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"><?php echo wp_kses_post($desc_text); ?></div>
                </div>
                <div class="inf-card-actions">
                    <div class="inf-dropdown-wrapper">
                        <button class="inf-btn-icon inf-trigger-dropdown" title="Options">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1"></circle>
                                <circle cx="19" cy="12" r="1"></circle>
                                <circle cx="5" cy="12" r="1"></circle>
                            </svg>
                        </button>
                        <div class="inf-dropdown-menu">
                            <button class="inf-dropdown-item inf-trigger-delete-search" data-id="<?php echo esc_attr($post->ID); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                </svg>
                                Delete search
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="inf-card-body" onclick="window.location.href='<?php echo esc_url($search_url); ?>'" style="justify-content: flex-start; padding-top:15px;">
                <div class="inf-group-date" style="margin-bottom: 0;">Saved on: <?php echo esc_html($date); ?></div>
                <div class="inf-group-avatars" style="color:var(--e-global-color-secondary); font-size:13px; font-weight:500; margin-top:auto;">
                    View Search Results &rarr;
                </div>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Render user's Custom Saved Searches grid
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_saved_searches_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return '<div class="inf-alert">Please log in to view your saved searches.</div>';
        }

        $user_id = get_current_user_id();
        $args = [
            'post_type'      => 'saved-search',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 12,
            'paged'          => 1,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ];

        $q = new WP_Query($args);

        if (!$q->have_posts()) {
            return do_shortcode('[elementor-template id="' . dd_get_template_id('dd_tpl_saves_empty', 27501) . '"]');
        }

        ob_start();
    ?>
        <div class="inf-groups-grid" id="inf-searches-shortcode-grid">
            <?php
            foreach ($q->posts as $p) {
                echo $this->generate_search_card_html($p);
            }
            ?>
        </div>
        <?php if ($q->max_num_pages > 1) : ?>
            <div class="inf-load-more-wrapper" style="margin-top:20px;">
                <button type="button" class="inf-btn inf-btn-save inf-load-more-searches" data-paged="1" style="width: auto; padding: 12px 24px !important;">Load More Searches</button>
            </div>
        <?php endif; ?>
    <?php
        return ob_get_clean();
    }

    /**
     * AJAX Handler: Load More Saved Searches
     */
    public function handle_load_more_searches_ajax()
    {
        check_ajax_referer('save_search_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error();

        $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
        $user_id = get_current_user_id();

        $args = [
            'post_type'      => 'saved-search',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 12,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ];

        $q = new WP_Query($args);
        $html = '';

        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $html .= $this->generate_search_card_html($p);
            }
        }

        $has_more = $q->max_num_pages > $paged;
        wp_send_json_success(['html' => $html, 'has_more' => $has_more]);
    }

    /**
     * AJAX Handler: Delete Single Saved Search
     */
    public function handle_delete_saved_search_ajax()
    {
        check_ajax_referer('save_search_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized', 'hello-elementor-child')]);

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $post = get_post($post_id);

        if ($post && $post->post_author == get_current_user_id() && $post->post_type === 'saved-search') {
            wp_delete_post($post_id, true);
            wp_send_json_success(['message' => __('Search deleted successfully.', 'hello-elementor-child')]);
        }

        wp_send_json_error(['message' => __('Could not delete search.', 'hello-elementor-child')]);
    }

    /**
     * AJAX Handler: Save User Search
     */
    public function handle_save_search_ajax()
    {
        check_ajax_referer('save_search_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Please login to save searches.', 'hello-elementor-child')]);

        $user_id = get_current_user_id();
        $raw_data = isset($_POST['search_data']) ? $_POST['search_data'] : [];
        $search_name = isset($_POST['search_name']) ? sanitize_text_field($_POST['search_name']) : '';

        // Added 'filter' to allowed keys
        $allowed_keys = ['niche', 'platform', 'followers', 'country', 'lang', 'gender', 'score', 'filter'];
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

        $post_title = !empty($search_name) ? $search_name : 'Search saved on ' . current_time('M j, Y @ g:i a');

        $post_args = [
            'post_title'  => $post_title,
            'post_type'   => 'saved-search',
            'post_status' => 'publish',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_args);

        if (is_wp_error($post_id)) wp_send_json_error(['message' => __('Error creating save file.', 'hello-elementor-child')]);

        update_post_meta($post_id, 'search_query', $final_string);
        wp_send_json_success(['message' => __('Search saved successfully!', 'hello-elementor-child')]);
    }

    /**
     * AJAX Handler: Get Modal Data
     */
    public function handle_get_modal_data_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('You must be logged in.', 'hello-elementor-child')]);

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $user_id = get_current_user_id();

        $influencer_post = get_post((int) $influencer_id);
        if (!$influencer_post || $influencer_post->post_type !== 'influencer') {
            wp_send_json_error(['message' => __('Invalid influencer.', 'hello-elementor-child')]);
        }

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
            'active_lists' => $active_lists,
        ]);
    }

    /**
     * AJAX Handler: Save (and optionally Unlock) Influencer to Lists
     * Processes group assignment and handles dynamic credit deduction with linked logging.
     */
    public function handle_save_influencer_lists_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('You must be logged in to save.', 'hello-elementor-child')]);

        // Convert the ID to a strict integer so myCred recognizes it natively
        $influencer_id = isset($_POST['influencer_id']) ? intval($_POST['influencer_id']) : 0;
        $selected_lists = isset($_POST['lists']) ? array_map('sanitize_text_field', (array)$_POST['lists']) : [];
        $user_id = get_current_user_id();

        if (empty($influencer_id)) wp_send_json_error(['message' => __('No Influencer ID provided.', 'hello-elementor-child')]);

        $influencer_post = get_post($influencer_id);
        if (!$influencer_post || $influencer_post->post_type !== 'influencer') {
            wp_send_json_error(['message' => __('Invalid influencer.', 'hello-elementor-child')]);
        }

        // --- STANDARD SAVE LOGIC ---
        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);

        if (empty($selected_lists)) {
            if ($post_id) wp_delete_post($post_id, true);

            // Removed the $is_newly_unlocked check. Now it only returns the standard "unsaved" message.
            $message = '<div class="my-cred-notice-text"><h4>Creator unsaved</h4><p>This creator has been removed from your Saved Lists</p></div>';

            // Removed 'is_newly_unlocked' and 'new_balance' from the success array.
            wp_send_json_success([
                'message' => __('Unsaved successfully!', 'hello-elementor-child'),
                'notice_html' => $message,
                'status'      => 'unsaved',
                'count'       => 0
            ]);
        } else {

            if (!$post_id) {
                $post_args = [
                    'post_title'  => 'Influencer saved on ' . current_time('M j, Y @ g:i a'),
                    'post_type'   => 'saved-influencer',
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                ];
                $post_id = wp_insert_post($post_args);

                if (is_wp_error($post_id)) wp_send_json_error(['message' => __('Could not create post.', 'hello-elementor-child')]);
                update_post_meta($post_id, 'influencer_id', $influencer_id);
            }

            $old_lists = get_post_meta($post_id, 'saved_in_lists', true) ?: [];
            update_post_meta($post_id, 'saved_in_lists', $selected_lists);
            foreach (array_diff((array) $old_lists, $selected_lists) as $gid) {
                delete_post_meta($post_id, '_in_group_' . sanitize_key($gid));
            }
            foreach (array_diff($selected_lists, (array) $old_lists) as $gid) {
                update_post_meta($post_id, '_in_group_' . sanitize_key($gid), 1);
            }

            // Removed the $is_newly_unlocked check. Now it only returns the standard "saved" message.
            $message = '<div class="my-cred-notice-text"><h4>Creator successfully saved</h4><p>This creator has been updated in your Saved Lists</p></div>';

            // Removed 'is_newly_unlocked' and 'new_balance' from the success array.
            wp_send_json_success([
                'message' => __('Saved successfully!', 'hello-elementor-child'),
                'notice_html' => $message,
                'status'      => 'saved',
                'count'       => count($selected_lists)
            ]);
        }
    }


    /**
     * AJAX Handler: Remove Influencer from Group
     * Instantly removes the specific influencer from the targeted list via the new shortcode button.
     */
    public function handle_remove_influencer_from_group_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized', 'hello-elementor-child')]);

        $influencer_id = isset($_POST['influencer_id']) ? sanitize_text_field($_POST['influencer_id']) : '';
        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $user_id = get_current_user_id();

        if (empty($influencer_id) || empty($group_id)) {
            wp_send_json_error(['message' => __('Missing influencer or group data.', 'hello-elementor-child')]);
        }

        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);

        if ($post_id) {
            $saved_in = get_post_meta($post_id, 'saved_in_lists', true);
            if (is_array($saved_in)) {
                $saved_in = array_diff($saved_in, [$group_id]); // Strip the group out

                if (empty($saved_in)) {
                    wp_delete_post($post_id, true); // If they belong to no other groups, purge the save
                } else {
                    update_post_meta($post_id, 'saved_in_lists', $saved_in);
                    delete_post_meta($post_id, '_in_group_' . sanitize_key($group_id));
                }

                wp_send_json_success(['message' => __('Creator removed successfully.', 'hello-elementor-child')]);
            }
        }

        wp_send_json_error(['message' => __('Creator record not found in this group.', 'hello-elementor-child')]);
    }

    /**
     * AJAX Handler: Bulk Remove Multiple Influencers from a Group
     */
    public function handle_bulk_remove_from_group_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized', 'hello-elementor-child')]);

        $group_id       = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $influencer_ids = isset($_POST['influencer_ids']) && is_array($_POST['influencer_ids'])
            ? array_map('sanitize_text_field', $_POST['influencer_ids'])
            : [];
        $user_id = get_current_user_id();

        if (empty($group_id) || empty($influencer_ids)) {
            wp_send_json_error(['message' => __('Missing group or influencer data.', 'hello-elementor-child')]);
        }

        $removed = [];
        $failed  = [];

        foreach ($influencer_ids as $influencer_id) {
            $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);

            if (!$post_id) {
                $failed[] = $influencer_id;
                continue;
            }

            $saved_in = get_post_meta($post_id, 'saved_in_lists', true);
            if (!is_array($saved_in)) {
                $failed[] = $influencer_id;
                continue;
            }

            $saved_in = array_diff($saved_in, [$group_id]);

            if (empty($saved_in)) {
                wp_delete_post($post_id, true);
            } else {
                update_post_meta($post_id, 'saved_in_lists', $saved_in);
                delete_post_meta($post_id, '_in_group_' . sanitize_key($group_id));
            }

            $removed[] = $influencer_id;
        }

        wp_send_json_success([
            'removed' => $removed,
            'failed'  => $failed,
        ]);
    }

    /**
     * AJAX Handler: Unlock Influencer and Auto-Save to "Unlocked Influencers"
     */
    public function handle_unlock_and_save_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized', 'hello-elementor-child')]);

        $user_id = get_current_user_id();
        $influencer_id = isset($_POST['influencer_id']) ? intval($_POST['influencer_id']) : 0;

        if (!$influencer_id) wp_send_json_error(['message' => __('Invalid creator profile.', 'hello-elementor-child')]);

        $influencer_post = get_post($influencer_id);
        if (!$influencer_post || $influencer_post->post_type !== 'influencer') {
            wp_send_json_error(['message' => __('Invalid influencer.', 'hello-elementor-child')]);
        }

        // 1. Verify MyCred Balance
        if (function_exists('mycred_get_users_balance')) {
            $balance = mycred_get_users_balance($user_id);
            if ($balance < 1) {
                wp_send_json_error([
                    'action' => 'redirect',
                    'url' => '/buy-credit/',
                    'message' => __('Insufficient credits. Redirecting...', 'hello-elementor-child')
                ]);
            }
        } else {
            wp_send_json_error(['message' => __('Credit system is currently offline.', 'hello-elementor-child')]);
        }

        // 2. Deduct Credit & Suppress Reload Notice
        if (function_exists('mycred_subtract')) {
            mycred_subtract('unlock_influencer', $user_id, 1, 'Unlocked creator ID: ' . $influencer_id, $influencer_id);

            // Delete the queued myCred notice to prevent it from showing on the next page reload
            delete_user_meta($user_id, 'mycred_notice');
        }

        // Fetch new balance to pass back to the DOM
        $new_balance = function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user_id) : 0;

        // 3. Mark as unlocked in user meta
        $unlocked = get_user_meta($user_id, 'dd_unlocked_influencers', true);
        if (!is_array($unlocked)) $unlocked = [];
        if (!in_array($influencer_id, $unlocked)) {
            $unlocked[] = $influencer_id;
            update_user_meta($user_id, 'dd_unlocked_influencers', $unlocked);
        }

        // 4. Ensure "Unlocked Influencers" group exists
        $user_lists = $this->get_normalized_groups($user_id);
        $target_group_id = null;

        foreach ($user_lists as $id => $group) {
            if (strtolower($group['name']) === 'unlocked influencers') {
                $target_group_id = $id;
                break;
            }
        }

        if (!$target_group_id) {
            $target_group_id = uniqid('grp_');
            $user_lists[$target_group_id] = [
                'id'   => $target_group_id,
                'name' => 'Unlocked Influencers',
                'desc' => 'Creators you have automatically unlocked using credits.',
                'date' => wp_date('Y-m-d H:iA')
            ];
            update_user_meta($user_id, 'custom_influencer_lists', $user_lists);
        }

        // 5. Save the influencer to this specific group
        $post_id = $this->get_existing_influencer_save_id($influencer_id, $user_id);
        if (!$post_id) {
            $post_id = wp_insert_post([
                'post_title'  => 'Influencer saved on ' . current_time('M j, Y @ g:i a'),
                'post_type'   => 'saved-influencer',
                'post_status' => 'publish',
                'post_author' => $user_id,
            ]);
            update_post_meta($post_id, 'influencer_id', $influencer_id);
        }

        $saved_in = get_post_meta($post_id, 'saved_in_lists', true);
        if (!is_array($saved_in)) $saved_in = [];

        if (!in_array($target_group_id, $saved_in)) {
            $saved_in[] = $target_group_id;
            update_post_meta($post_id, 'saved_in_lists', $saved_in);
        }

        // Construct custom notice to return to JS
        $custom_notice = sprintf(
            '<div class="my-cred-notice-text">
                <h4>Creator Unlocked</h4>
                <p>1 credit deducted. New balance: <strong>%s</strong>.</p>
             </div>',
            esc_html($new_balance)
        );

        wp_send_json_success([
            'message' => __('Creator unlocked and saved!', 'hello-elementor-child'),
            'count' => count($saved_in),
            'new_balance' => $new_balance,
            'notice_html' => $custom_notice
        ]);
    }

    /**
     * AJAX Handler: Upsert (Create/Update) Group
     */
    public function handle_upsert_group_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized', 'hello-elementor-child')]);

        $user_id = get_current_user_id();
        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $desc = isset($_POST['desc']) ? sanitize_textarea_field($_POST['desc']) : '';

        if (empty($name)) wp_send_json_error(['message' => __('Group name is required.', 'hello-elementor-child')]);

        $user_lists = $this->get_normalized_groups($user_id);

        if (empty($group_id)) {
            $group_id = uniqid('grp_');
            $date = wp_date('Y-m-d H:iA');
        } else {
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
     */
    public function handle_delete_group_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized', 'hello-elementor-child')]);

        $user_id = get_current_user_id();
        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';

        if (empty($group_id)) wp_send_json_error(['message' => __('Group ID missing.', 'hello-elementor-child')]);

        $user_lists = $this->get_normalized_groups($user_id);
        if (isset($user_lists[$group_id])) {
            unset($user_lists[$group_id]);
            update_user_meta($user_id, 'custom_influencer_lists', $user_lists);
        }

        $saved_posts = get_posts([
            'post_type'              => 'saved-influencer',
            'author'                 => $user_id,
            'posts_per_page'         => 2000,
            'fields'                 => 'ids',
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'meta_query'             => [
                ['key' => '_in_group_' . sanitize_key($group_id), 'compare' => 'EXISTS']
            ]
        ]);

        foreach ($saved_posts as $post_id_item) {
            $lists = get_post_meta($post_id_item, 'saved_in_lists', true);
            if (is_array($lists)) {
                $lists = array_diff($lists, [$group_id]);
                if (empty($lists)) {
                    wp_delete_post($post_id_item, true);
                } else {
                    update_post_meta($post_id_item, 'saved_in_lists', $lists);
                    delete_post_meta($post_id_item, '_in_group_' . sanitize_key($group_id));
                }
            }
        }

        wp_send_json_success(['message' => __('Group deleted.', 'hello-elementor-child')]);
    }

    /**
     * AJAX Handler: Get Group Influencers
     * Now renders Elementor Loop Item 14897 per influencer to achieve the 1-per-row layout.
     */
    public function handle_get_group_influencers_ajax()
    {
        check_ajax_referer('save_influencer_nonce', 'security');
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Unauthorized.', 'hello-elementor-child')]);

        $group_id = isset($_POST['group_id']) ? sanitize_text_field($_POST['group_id']) : '';
        $user_id = get_current_user_id();

        if (empty($group_id)) wp_send_json_error(['message' => __('Invalid group requested.', 'hello-elementor-child')]);

        $saved_posts = get_posts([
            'post_type'      => 'saved-influencer',
            'author'         => $user_id,
            'posts_per_page' => 500,
            'meta_query'     => [
                ['key' => '_in_group_' . sanitize_key($group_id), 'compare' => 'EXISTS']
            ]
        ]);

        if (empty($saved_posts)) {
            wp_send_json_success(['html' => '<div class="inf-alert" style="margin:20px;">No creators found in this group.</div>']);
        }

        global $post;
        $original_post = $post;

        $html = '<div class="inf-group-creators-loop">';
        foreach ($saved_posts as $saved_post) {
            $influencer_id = get_post_meta($saved_post->ID, 'influencer_id', true);

            if ($influencer_id) {
                // Temporarily hijack the global $post so the Elementor Loop item populates correctly
                $post = get_post($influencer_id);
                setup_postdata($post);

                $html .= '<div class="inf-loop-item-row">';
                $html .= do_shortcode('[elementor-template id="' . dd_get_template_id('dd_tpl_group_influencer_row', 14897) . '"]');
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        // Restore global state to prevent interference with other plugins
        $post = $original_post;
        if ($post) setup_postdata($post);

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Renders Inline JavaScript, CSS, and HTML for the Modals & Shortcode.
     */
    public function render_inline_assets()
    {
    ?>
        <style>
            /* =========================================================================
               BULLETPROOF ELEMENTOR CSS RESETS
               ========================================================================= */
            #inf-groups-shortcode-grid button.inf-btn-icon,
            #inf-searches-shortcode-grid button.inf-btn-icon,
            #inf-modal-overlay button.inf-btn-icon,
            #inf-modal-overlay button.inf-btn,
            #inf-modal-overlay button.inf-btn-back,
            #inf-modal-overlay button.inf-create-btn,
            #inf-groups-shortcode-grid button.inf-dropdown-item,
            #inf-searches-shortcode-grid button.inf-dropdown-item {
                background-image: none !important;
                letter-spacing: normal !important;
                text-transform: none !important;
                box-shadow: none !important;
                text-decoration: none !important;
                font-family: inherit !important;
            }

            #inf-groups-shortcode-grid button.inf-btn-icon,
            #inf-searches-shortcode-grid button.inf-btn-icon,
            #inf-modal-overlay button.inf-btn-icon {
                background-color: transparent !important;
                border: none !important;
                cursor: pointer !important;
                color: #888 !important;
                padding: 0 !important;
                margin: 0 !important;
                border-radius: 4px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                min-width: 0 !important;
                min-height: 0 !important;
                width: 28px !important;
                height: 28px !important;
                line-height: 1 !important;
            }

            #inf-groups-shortcode-grid button.inf-btn-icon:hover,
            #inf-searches-shortcode-grid button.inf-btn-icon:hover,
            #inf-modal-overlay button.inf-btn-icon:hover {
                background-color: #f0f2f5 !important;
                color: var(--e-global-color-primary) !important;
            }

            #inf-groups-shortcode-grid button.inf-btn-icon svg,
            #inf-searches-shortcode-grid button.inf-btn-icon svg,
            #inf-modal-overlay button.inf-btn-icon svg {
                width: 16px !important;
                height: 16px !important;
                margin: 0 !important;
                padding: 0 !important;
                fill: none !important;
            }

            /* Resets for Modal Input Fields */
            #inf-modal-overlay .inf-input-group label {
                display: block !important;
                margin-bottom: 6px !important;
                font-size: 13px !important;
                color: #000 !important;
                font-weight: 500 !important;
                font-family: 'Work Sans', sans-serif !important;
                line-height: 1.5 !important;
            }

            #inf-modal-overlay .inf-input,
            #inf-modal-overlay .inf-textarea {
                width: 100% !important;
                padding: 10px 12px !important;
                border: 1px solid #ddd !important;
                border-radius: 6px !important;
                font-size: 14px !important;
                box-sizing: border-box !important;
                font-family: 'Work Sans', sans-serif !important;
                color: var(--e-global-color-primary) !important;
                background-color: #fff !important;
                line-height: 1.5 !important;
                box-shadow: none !important;
                height: auto !important;
                letter-spacing: normal !important;
                text-transform: none !important;
                text-shadow: none !important;
                margin: 0 !important;
            }

            #inf-modal-overlay .inf-textarea {
                resize: vertical !important;
                min-height: 80px !important;
            }

            /* Shortcode Grid Styling */
            .inf-groups-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .inf-group-card {
                background: #fdfdfd;
                border-radius: 8px;
                border: 1px solid #e2e4e7;
                display: flex;
                flex-direction: column;
                transition: 0.2s;
                position: relative;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
                height: 160px;
            }

            .inf-group-card:hover {
                border-color: #d0d3d8;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            }

            /* Card Header & Actions */
            .inf-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding: 20px 20px 0 20px;
            }

            .inf-group-header-left {
                flex: 1;
                padding-right: 12px;
                overflow: hidden;
            }

            .inf-group-title {
                margin: 0;
                font-size: 15px;
                color: var(--e-global-color-primary);
                font-weight: 500;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .inf-group-desc {
                margin: 6px 0 0 0;
                font-size: 13px;
                color: #666;
                line-height: 1.4;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                text-overflow: ellipsis;
                font-family: 'Work Sans', sans-serif;
            }

            .inf-card-actions {
                display: flex;
                gap: 4px;
                position: relative;
                z-index: 10;
            }

            /* Dropdown Menu */
            .inf-dropdown-wrapper {
                position: relative;
            }

            .inf-dropdown-menu {
                position: absolute;
                right: 0;
                top: 100%;
                background: #fff;
                border: 1px solid #eee;
                border-radius: 6px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                width: 140px;
                display: none;
                z-index: 20;
                padding: 6px;
                margin-top: 4px;
            }

            .inf-dropdown-wrapper.active .inf-dropdown-menu {
                display: block;
            }

            #inf-groups-shortcode-grid button.inf-dropdown-item,
            #inf-searches-shortcode-grid button.inf-dropdown-item {
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
                width: 100% !important;
                padding: 8px 10px !important;
                margin: 0 !important;
                border: none !important;
                background-color: transparent !important;
                text-align: left !important;
                cursor: pointer !important;
                font-size: 13px !important;
                color: #000 !important;
                border-radius: 4px !important;
            }

            #inf-groups-shortcode-grid button.inf-dropdown-item:hover,
            #inf-searches-shortcode-grid button.inf-dropdown-item:hover {
                background-color: #f8f9fa !important;
                color: #dc3545 !important;
            }

            /* Card Body */
            .inf-card-body {
                padding: 0 20px 20px 20px;
                cursor: pointer;
                flex-grow: 1;
                display: flex;
                flex-direction: column;
                justify-content: flex-end;
            }

            .inf-group-date {
                font-size: 12px;
                color: #999;
                margin-bottom: 12px;
            }

            /* Avatars */
            .inf-group-avatars {
                display: flex;
                align-items: center;
            }

            .inf-avatar-wrapper {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                border: 2px solid #fff;
                margin-left: -8px;
                overflow: hidden;
                background: #eee;
            }

            .inf-avatar-wrapper.inf-avatar-wrapper.inf-avatar-wrapper .influencer-avatar-fallback {
                width: 100%;
                height: 100%;
                font-size: 8px;
            }

            .inf-avatar-wrapper:first-child {
                margin-left: 0;
            }

            .inf-avatar-wrapper img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }

            .inf-alert {
                padding: 12px;
                background: #fff3cd;
                color: #856404;
                border-radius: 6px;
            }

            /* Modal Framework */
            .inf-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.4);
                z-index: 99999;
                display: none;
                align-items: center;
                justify-content: center;
                font-family: inherit;
            }

            .inf-modal-content {
                background: #fdfdfd;
                padding: 24px;
                border-radius: 12px;
                width: 100%;
                max-width: 420px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
                position: relative;
                display: none;
            }

            /* 1000px Wide Modal Modifier specifically for the list view */
            .inf-modal-content.inf-modal-wide {
                max-width: 1000px !important;
                width: 95% !important;
            }

            .inf-modal-content.active-view {
                display: block;
            }

            .inf-modal-header {
                display: flex;
                align-items: center;
                margin-bottom: 20px;
            }

            .inf-modal-header h3 {
                margin: 0;
                font-size: 18px;
                color: var(--e-global-color-primary);
                font-weight: 500;
                font-family: Work Sans, sans-serif;
            }

            #inf-modal-overlay button.inf-btn-back {
                background-color: transparent !important;
                border: none !important;
                cursor: pointer !important;
                color: #666 !important;
                padding: 0 10px 0 0 !important;
                margin: 0 !important;
                display: none;
                align-items: center !important;
                gap: 4px !important;
                font-size: 14px !important;
            }

            .inf-saved-groups-header {
                display: flex;
                justify-content: flex-end;
                margin-bottom: 16px;
            }

            .inf-shortcode-add-group {
                background-color: var(--e-global-color-primary) !important;
                color: #fff !important;
                border: none !important;
                border-radius: 6px !important;
                padding: 9px 18px !important;
                font-size: 14px !important;
                font-weight: 500 !important;
                font-family: inherit !important;
                cursor: pointer !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 6px !important;
                letter-spacing: normal !important;
                text-transform: none !important;
                box-shadow: none !important;
                background-image: none !important;
            }

            .inf-shortcode-add-group:hover {
                opacity: 0.88 !important;
            }

            #inf-modal-overlay button.inf-btn-back:hover {
                color: var(--e-global-color-primary) !important;
            }

            .inf-input-group label span {
                color: #dc3545;
            }

            .inf-lists-container {
                max-height: 250px;
                overflow-y: auto;
                margin-bottom: 12px;
            }

            .inf-list-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 4px;
                border-radius: 6px;
            }

            .inf-list-item:hover {
                background: #f5f5f5;
            }

            .inf-list-item-left {
                display: flex;
                align-items: center;
                flex-grow: 1;
                cursor: pointer;
            }

            .inf-list-item-left input {
                margin-right: 12px;
                cursor: pointer;
            }

            .inf-list-item-left label {
                cursor: pointer;
                font-size: 14px;
                color: #000;
                user-select: none;
                font-family: Work Sans, sans-serif;
            }

            #inf-modal-overlay button.inf-create-btn {
                background-color: transparent !important;
                border: none !important;
                color: #666 !important;
                font-size: 14px !important;
                cursor: pointer !important;
                padding: 10px 4px !important;
                margin: 0 0 16px 0 !important;
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
                width: 100% !important;
                justify-content: flex-start !important;
            }

            #inf-modal-overlay button.inf-create-btn:hover {
                color: var(--e-global-color-secondary) !important;
            }

            .inf-modal-actions {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                margin-top: 24px;
            }

            button.inf-btn {
                flex: 1 !important;
                padding: 10px !important;
                margin: 0 !important;
                border-radius: 8px !important;
                font-weight: 500 !important;
                cursor: pointer !important;
                text-align: center !important;
                transition: 0.2s !important;
                font-size: 14px !important;
                border: none !important;
                font-family: inherit !important;
            }

            button.inf-btn-cancel {
                background-color: transparent !important;
                color: var(--e-global-color-secondary) !important;
                border: 1px solid var(--e-global-color-secondary) !important;
            }

            button.inf-btn-cancel:hover {
                background-color: #eaeaea !important;
            }

            button.inf-btn-save {
                background-color: var(--e-global-color-secondary) !important;
                color: #fff !important;
            }

            /* Enhanced Group Creators Loop Grid */
            .inf-group-creators-loop {
                display: flex;
                flex-direction: column;
                gap: 15px;
                max-height: 65vh;
                overflow-y: auto;
                padding: 10px 0;
                width: 100%;
                padding: 0 24px 24px;
                box-sizing: border-box;
            }

            .inf-loop-item-row {
                width: 100%;
            }

            .add-to-groups.add-to-groups.add-to-groups button,
            .inf-remove-from-group-trigger.inf-remove-from-group-trigger.inf-remove-from-group-trigger button {
                font-family: var(--e-global-typography-2a20fd0-font-family), Sans-serif;
                font-size: var(--e-global-typography-2a20fd0-font-size);
                font-weight: var(--e-global-typography-2a20fd0-font-weight);
                line-height: var(--e-global-typography-2a20fd0-line-height);
                letter-spacing: var(--e-global-typography-2a20fd0-letter-spacing);
                padding: 15px 15px 15px 15px;
                width: 100%;
                text-transform: uppercase;
            }

            .add-to-groups.add-to-groups.add-to-groups:not(.delete-save) button {
                background-color: transparent;
                color: var(--e-global-color-accent);
            }

            .add-to-groups.add-to-groups.add-to-groups.delete-save button {
                background-color: var(--e-global-color-accent);
                color: #fff;
            }

            /* Remove from Group specific overrides */
            .inf-remove-from-group-trigger button {
                background-color: transparent !important;
                color: var(--e-global-color-accent) !important;
                transition: 0.2s;
            }

            .inf-remove-from-group-trigger button:hover {
                background-color: #ffe6e6 !important;
                color: #dc3545 !important;
            }

            .inf-view-group-footer {
                padding: 24px;
                display: flex;
                flex-direction: row;
                gap: 1rem;
                border-top: 1px solid var(--e-global-color-2210fb2);
            }

            #inf-export-group-pdf {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
            }
        </style>

        <div id="inf-modal-overlay" class="inf-modal-overlay">

            <div id="inf-view-manage" class="inf-modal-content">
                <div class="inf-modal-header">
                    <h3>Manage groups</h3>
                </div>

                <div class="inf-lists-container" id="inf-lists-wrapper"></div>
                <button type="button" class="inf-create-btn" id="inf-btn-go-create">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg> Create new group
                </button>
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-modal-save-influencer">Save</button>
                </div>
            </div>

            <div id="inf-view-edit" class="inf-modal-content">
                <div class="inf-modal-header">
                    <button class="inf-btn-back" id="inf-btn-back-manage" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg> Back
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

            <div id="inf-view-influencers" class="inf-modal-content inf-modal-wide" style="padding:0; padding-bottom: 12px;">
                <div class="inf-modal-header" style="padding: 24px 24px 0 24px;">
                    <h3 id="inf-view-group-title">LOADING...</h3>
                </div>
                <div id="inf-view-group-body">
                    <div style="text-align:center; padding:20px;">LOADING INFLUENCERS...</div>
                </div>
                <div class="inf-view-group-footer">
                    <button type="button" class="inf-btn inf-btn-save" id="inf-export-group-pdf" style="width: 100%; margin-bottom: 10px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-filetype-pdf" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M14 4.5V14a2 2 0 0 1-2 2h-1v-1h1a1 1 0 0 0 1-1V4.5h-2A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v9H2V2a2 2 0 0 1 2-2h5.5zM1.6 11.85H0v3.999h.791v-1.342h.803q.43 0 .732-.173.305-.175.463-.474a1.4 1.4 0 0 0 .161-.677q0-.375-.158-.677a1.2 1.2 0 0 0-.46-.477q-.3-.18-.732-.179m.545 1.333a.8.8 0 0 1-.085.38.57.57 0 0 1-.238.241.8.8 0 0 1-.375.082H.788V12.48h.66q.327 0 .512.181.185.183.185.522m1.217-1.333v3.999h1.46q.602 0 .998-.237a1.45 1.45 0 0 0 .595-.689q.196-.45.196-1.084 0-.63-.196-1.075a1.43 1.43 0 0 0-.589-.68q-.396-.234-1.005-.234zm.791.645h.563q.371 0 .609.152a.9.9 0 0 1 .354.454q.118.302.118.753a2.3 2.3 0 0 1-.068.592 1.1 1.1 0 0 1-.196.422.8.8 0 0 1-.334.252 1.3 1.3 0 0 1-.483.082h-.563zm3.743 1.763v1.591h-.79V11.85h2.548v.653H7.896v1.117h1.606v.638z" />
                        </svg> Export PDF
                    </button>
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal" style="width: 100%;">Close</button>
                </div>
            </div>
            <div id="inf-view-unlock-confirm" class="inf-modal-content">
                <div class="inf-modal-header">
                    <h3>Unlock Creator</h3>
                </div>
                <div style="padding: 10px 0 20px; font-size: 15px; color: #444; line-height: 1.5; font-family: 'Work Sans', sans-serif;">
                    Unlocking this creator will deduct <strong>1 credit</strong> from your balance and automatically add them to your <strong>"Unlocked Influencers"</strong> saved list.
                </div>
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-confirm-unlock-btn">Confirm & Unlock</button>
                </div>
            </div>
            <div id="inf-view-save-search" class="inf-modal-content">
                <div class="inf-modal-header">
                    <h3>Name your search</h3>
                </div>
                <div class="inf-input-group">
                    <label>Search name<span>*</span></label>
                    <input type="text" id="inf-save-search-name" class="inf-input" placeholder="e.g. Top Tech Creators UK">
                </div>
                <div class="inf-modal-actions">
                    <button type="button" class="inf-btn inf-btn-cancel inf-close-modal">Cancel</button>
                    <button type="button" class="inf-btn inf-btn-save" id="inf-modal-confirm-save-search">Save Search</button>
                </div>
            </div>

        </div>
<?php
    }
}

new Saves_Manager();
