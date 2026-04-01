<?php

/**
 * Plugin Name: DD Featured Influencers Manager
 * Description: Advanced two-way synchronization for Featured Influencers, featuring AJAX list-table toggles, post meta integration, and a global tabbed repeater settings interface.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-featured-influencers
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class DD_Featured_Influencer_Manager
 * * Handles all functionality related to starring/featuring influencer CPTs
 * and synchronizing them with a global options repeater interface.
 */
class DD_Featured_Influencer_Manager
{

    /**
     * Meta key used on the individual post.
     * * @var string
     */
    private $meta_key = '_dd_is_featured_influencer';

    /**
     * Option key used to store the ordered array of featured IDs globally.
     * * @var string
     */
    private $option_key = 'dd_global_featured_influencers';

    /**
     * Constructor. Initializes all hooks.
     */
    public function __construct()
    {
        // Admin styling and scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // List Table Columns
        add_filter('manage_influencer_posts_columns', [$this, 'add_featured_column']);
        add_action('manage_influencer_posts_custom_column', [$this, 'render_featured_column'], 10, 2);

        // AJAX Handler for list table toggle
        add_action('wp_ajax_dd_toggle_featured_influencer', [$this, 'ajax_toggle_featured']);

        // Meta Box
        add_action('add_meta_boxes_influencer', [$this, 'add_meta_box']);
        add_action('save_post_influencer', [$this, 'save_post_meta'], 10, 2);

        // Global Options Page
        add_action('admin_menu', [$this, 'add_options_page']);
        add_action('admin_post_dd_save_global_influencers', [$this, 'save_global_options']);
    }

 
    /**
     * Enqueues CSS and JS required for the Admin interfaces.
     * * @param string $hook The current admin page.
     * @return void
     */
    public function enqueue_admin_assets($hook)
    {
        $is_influencer_list = ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'influencer');
        $is_global_page     = ($hook === 'influencer_page_dd-featured-influencers');

        if ($is_influencer_list) {
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', $this->get_list_table_script());

            // Updated CSS: Tightened column width to 40px for better alignment next to checkboxes
            wp_add_inline_style('common', '.column-featured_influencer { width: 40px; text-align: center; } .dd-star-toggle { cursor: pointer; color: #b4b9be; transition: color 0.2s ease; } .dd-star-toggle.is-featured { color: #f56e28; }');
        }

        if ($is_global_page) {
            wp_enqueue_script('jquery-ui-sortable');
            wp_add_inline_script('jquery-ui-sortable', $this->get_repeater_tabs_script());
            wp_add_inline_style('common', $this->get_repeater_tabs_css());
        }
    }
    /**
     * Adds the "Featured" column to the influencer post list.
     * * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_featured_column($columns)
    {
        $new_columns = [];

        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;

            // Inject the featured column immediately after the bulk action checkbox.
            if ($key === 'cb') {
                $new_columns['featured_influencer'] = '<span class="dashicons dashicons-star-filled" title="Featured" style="color:#a7aaad;"></span>';
            }
        }

        // Fallback: If 'cb' is missing from your custom setup, prepend it to the start of the table.
        if (! array_key_exists('featured_influencer', $new_columns)) {
            $featured    = ['featured_influencer' => '<span class="dashicons dashicons-star-filled" title="Featured" style="color:#a7aaad;"></span>'];
            $new_columns = array_merge($featured, $new_columns);
        }

        return $new_columns;
    }

    /**
     * Renders the star icon in the custom column.
     * * @param string $column_name The name of the column.
     * @param int    $post_id     The current post ID.
     * @return void
     */
    public function render_featured_column($column_name, $post_id)
    {
        if ($column_name === 'featured_influencer') {
            $is_featured = get_post_meta($post_id, $this->meta_key, true);
            $class       = $is_featured ? 'is-featured dashicons-star-filled' : 'dashicons-star-empty';
            $nonce       = wp_create_nonce('dd_toggle_star_' . $post_id);

            printf(
                '<span class="dashicons %s dd-star-toggle" data-id="%d" data-nonce="%s"></span>',
                esc_attr($class),
                esc_attr($post_id),
                esc_attr($nonce)
            );
        }
    }

    /**
     * AJAX endpoint to toggle the featured status from the list table.
     * * @return void
     */
    public function ajax_toggle_featured()
    {
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $nonce   = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        if (! wp_verify_nonce($nonce, 'dd_toggle_star_' . $post_id) || ! current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $current_status = get_post_meta($post_id, $this->meta_key, true);
        $new_status     = $current_status ? '0' : '1';

        update_post_meta($post_id, $this->meta_key, $new_status);
        $this->sync_single_to_global($post_id, (bool) $new_status);

        wp_send_json_success(['new_status' => $new_status]);
    }

    /**
     * Adds the Featured meta box to the edit screen.
     * * @return void
     */
    public function add_meta_box()
    {
        add_meta_box(
            'dd_featured_influencer_mb',
            'Featured Influencer Status',
            [$this, 'render_meta_box'],
            'influencer',
            'side',
            'high'
        );
    }

    /**
     * Renders the meta box contents.
     * * @param WP_Post $post The current post object.
     * @return void
     */
    public function render_meta_box($post)
    {
        wp_nonce_field('dd_save_featured_meta', 'dd_featured_meta_nonce');
        $is_featured = get_post_meta($post->ID, $this->meta_key, true);
?>
        <label for="dd_is_featured">
            <input type="checkbox" id="dd_is_featured" name="dd_is_featured" value="1" <?php checked($is_featured, '1'); ?> />
            Mark as a Featured Influencer
        </label>
        <p class="description">If checked, this influencer will be added to the global featured roster.</p>
    <?php
    }

    /**
     * Saves the meta box data when the post is saved.
     * * @param int     $post_id The ID of the post being saved.
     * @param WP_Post $post    The post object.
     * @return void
     */
    public function save_post_meta($post_id, $post)
    {
        if (! isset($_POST['dd_featured_meta_nonce']) || ! wp_verify_nonce($_POST['dd_featured_meta_nonce'], 'dd_save_featured_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $is_featured = isset($_POST['dd_is_featured']) ? '1' : '0';
        update_post_meta($post_id, $this->meta_key, $is_featured);

        // Auto-adjust global configuration to maintain synchronization.
        $this->sync_single_to_global($post_id, $is_featured === '1');
    }

    /**
     * Adds the global configuration page under the CPT menu.
     * * @return void
     */
    public function add_options_page()
    {
        add_submenu_page(
            'edit.php?post_type=influencer',
            'Featured Roster',
            'Featured Roster',
            'manage_options',
            'dd-featured-influencers',
            [$this, 'render_options_page']
        );
    }

    /**
     * Renders the global tabbed UI and repeater interface.
     * * @return void
     */
    public function render_options_page()
    {
        $featured_ids = get_option($this->option_key, []);

        // Fetch all influencers to populate dropdowns
        $influencers = get_posts([
            'post_type'      => 'influencer',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC'
        ]);

    ?>
        <div class="wrap dd-options-wrap">
            <h1>Global Featured Influencers</h1>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings synchronized and saved.</p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper" id="dd-tabs">
                <a href="#tab-roster" class="nav-tab nav-tab-active">Featured Roster Builder</a>
                <a href="#tab-settings" class="nav-tab">Display Settings</a>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dd_save_global_influencers">
                <?php wp_nonce_field('dd_verify_global_save', 'dd_global_nonce'); ?>

                <div id="tab-roster" class="dd-tab-content active">
                    <p class="description">Order, duplicate, or delete featured influencers below. This strictly synchronizes with individual post data.</p>

                    <div id="dd-repeater-container">
                        <?php
                        if (empty($featured_ids)) {
                            $featured_ids = ['']; // Provide at least one empty row
                        }

                        foreach ($featured_ids as $index => $id) :
                        ?>
                            <div class="dd-repeater-row">
                                <div class="dd-repeater-header">
                                    <span class="dashicons dashicons-menu drag-handle"></span>
                                    <h3 class="row-title">Influencer Entry</h3>
                                    <div class="row-actions">
                                        <span class="dashicons dashicons-arrow-up move-up" title="Move Up"></span>
                                        <span class="dashicons dashicons-arrow-down move-down" title="Move Down"></span>
                                        <span class="dashicons dashicons-admin-page duplicate-row" title="Duplicate"></span>
                                        <span class="dashicons dashicons-arrow-up-alt2 collapse-row" title="Collapse/Expand"></span>
                                        <span class="dashicons dashicons-trash delete-row" title="Delete"></span>
                                    </div>
                                </div>
                                <div class="dd-repeater-body">
                                    <label>Select Influencer:
                                        <select name="dd_featured_ids[]" class="widefat">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($influencers as $inf) : ?>
                                                <option value="<?php echo esc_attr($inf->ID); ?>" <?php selected($id, $inf->ID); ?>>
                                                    <?php echo esc_html($inf->post_title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button button-secondary" id="dd-add-row">Add Influencer</button>
                </div>

                <div id="tab-settings" class="dd-tab-content" style="display: none;">
                    <p>Additional global configurations can be placed here (e.g., Grid Layout vs Carousel Layout).</p>
                    <label>
                        <input type="checkbox" name="dd_display_names" value="1" checked>
                        Display Influencer Names on Frontend
                    </label>
                </div>

                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Save & Synchronize Setup">
                </p>
            </form>
        </div>
<?php
    }

    /**
     * Processes the saving of the global options page and synchronizes downwards to single metas.
     * * @return void
     */
    public function save_global_options()
    {
        if (! isset($_POST['dd_global_nonce']) || ! wp_verify_nonce($_POST['dd_global_nonce'], 'dd_verify_global_save')) {
            wp_die('Security check failed.');
        }

        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        // Sanitize and save the ordered array
        $submitted_ids = isset($_POST['dd_featured_ids']) ? array_map('intval', $_POST['dd_featured_ids']) : [];
        $submitted_ids = array_filter($submitted_ids); // Remove empty 0s

        update_option($this->option_key, array_values($submitted_ids));

        // Synchronize downwards: Wipes all to 0, sets present to 1
        $this->sync_global_to_single($submitted_ids);

        wp_safe_redirect(admin_url('edit.php?post_type=influencer&page=dd-featured-influencers&updated=true'));
        exit;
    }

    /**
     * Helper: Adds or removes a single post ID from the global array to keep data in sync.
     * * @param int  $post_id  The influencer post ID.
     * @param bool $featured True to add, false to remove.
     * @return void
     */
    private function sync_single_to_global($post_id, $featured)
    {
        $current_global = get_option($this->option_key, []);

        if ($featured) {
            if (! in_array($post_id, $current_global)) {
                $current_global[] = $post_id;
            }
        } else {
            $current_global = array_diff($current_global, [$post_id]);
        }

        update_option($this->option_key, array_values($current_global));
    }

    /**
     * Helper: Enforces the global IDs onto the individual post meta records.
     * * @param array $active_ids Array of explicitly featured post IDs.
     * @return void
     */
    private function sync_global_to_single($active_ids)
    {
        global $wpdb;

        // Reset all to 0 first to ensure clean slate
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE meta_key = %s", $this->meta_key));

        // Set explicitly featured IDs to 1
        if (! empty($active_ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($active_ids), '%d'));
            $query = $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE meta_key = %s AND post_id IN ($ids_placeholder)",
                array_merge([$this->meta_key], $active_ids)
            );
            $wpdb->query($query);

            // Ensure meta exists if it was never created
            foreach ($active_ids as $id) {
                if (! get_post_meta($id, $this->meta_key, true)) {
                    update_post_meta($id, $this->meta_key, '1');
                }
            }
        }
    }

    /**
     * Returns the JS block for the List Table AJAX interactions.
     * * @return string
     */
    private function get_list_table_script()
    {
        return "
		jQuery(document).ready(function($) {
			$('.dd-star-toggle').on('click', function() {
				var \$star = $(this);
				var post_id = \$star.data('id');
				var nonce = \$star.data('nonce');

				// Optimistic UI Update
				\$star.toggleClass('dashicons-star-filled dashicons-star-empty is-featured');

				$.post(ajaxurl, {
					action: 'dd_toggle_featured_influencer',
					post_id: post_id,
					nonce: nonce
				}, function(response) {
					if(!response.success) {
						// Revert if failed
						\$star.toggleClass('dashicons-star-filled dashicons-star-empty is-featured');
						alert('Failed to toggle featured status.');
					}
				});
			});
		});
		";
    }

    /**
     * Returns the comprehensive CSS for the tabbed repeater architecture.
     * * @return string
     */
    private function get_repeater_tabs_css()
    {
        return "
		.dd-tab-content { display: none; padding: 20px; background: #fff; border: 1px solid #ccc; border-top: none; }
		.dd-tab-content.active { display: block; }
		#dd-repeater-container { margin-bottom: 15px; }
		.dd-repeater-row { background: #fafafa; border: 1px solid #ddd; margin-bottom: 10px; border-radius: 4px; }
		.dd-repeater-header { display: flex; align-items: center; padding: 10px; background: #eee; border-bottom: 1px solid #ddd; cursor: move; }
		.dd-repeater-header h3 { margin: 0; flex-grow: 1; font-size: 14px; padding-left: 10px; }
		.row-actions { display: flex; gap: 8px; }
		.row-actions span { cursor: pointer; color: #555; transition: color 0.2s; }
		.row-actions span:hover { color: #007cba; }
		.row-actions .delete-row:hover { color: #d63638; }
		.dd-repeater-body { padding: 15px; }
		.dd-repeater-row.collapsed .dd-repeater-body { display: none; }
		.dd-repeater-row.collapsed .dashicons-arrow-up-alt2 { transform: rotate(180deg); }
		.ui-sortable-placeholder { border: 2px dashed #bbb; background: #f9f9f9; height: 50px; margin-bottom: 10px; }
		";
    }

    /**
     * Returns the vanilla/jQuery logic for Tab Navigation and Repeater functionality
     * (Duplication, Reordering, Collapsing, Deletion).
     * * @return string
     */
    private function get_repeater_tabs_script()
    {
        return "
		jQuery(document).ready(function($) {
			// Tabbed Interface Logic
			$('.nav-tab').on('click', function(e) {
				e.preventDefault();
				$('.nav-tab').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.dd-tab-content').hide();
				$($(this).attr('href')).show();
			});

			// jQuery UI Sortable for Drag and Drop
			$('#dd-repeater-container').sortable({
				handle: '.dd-repeater-header',
				placeholder: 'ui-sortable-placeholder',
				axis: 'y'
			});

			// Repeater Actions Binding
			$('#dd-repeater-container').on('click', '.collapse-row', function() {
				$(this).closest('.dd-repeater-row').toggleClass('collapsed');
			});

			$('#dd-repeater-container').on('click', '.delete-row', function() {
				if ( confirm('Remove this row?') ) {
					$(this).closest('.dd-repeater-row').slideUp(function(){ $(this).remove(); });
				}
			});

			$('#dd-repeater-container').on('click', '.duplicate-row', function() {
				var \$row = $(this).closest('.dd-repeater-row');
				var \$clone = \$row.clone();
				// Reset select value to match original exactly
				\$clone.find('select').val( \$row.find('select').val() );
				\$clone.hide().insertAfter(\$row).slideDown();
			});

			$('#dd-repeater-container').on('click', '.move-up', function() {
				var \$row = $(this).closest('.dd-repeater-row');
				if (\$row.prev('.dd-repeater-row').length > 0) {
					\$row.insertBefore(\$row.prev('.dd-repeater-row'));
				}
			});

			$('#dd-repeater-container').on('click', '.move-down', function() {
				var \$row = $(this).closest('.dd-repeater-row');
				if (\$row.next('.dd-repeater-row').length > 0) {
					\$row.insertAfter(\$row.next('.dd-repeater-row'));
				}
			});

			// Add New Row
			$('#dd-add-row').on('click', function() {
				var \$template = $('.dd-repeater-row').first().clone();
				\$template.find('select').val('');
				\$template.removeClass('collapsed').hide();
				$('#dd-repeater-container').append(\$template);
				\$template.slideDown();
			});
		});
		";
    }
}

new DD_Featured_Influencer_Manager();
