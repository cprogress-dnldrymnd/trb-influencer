<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class DD_Featured_Influencer_Manager
 * * Handles the column UI, AJAX toggling, settings page creation, 
 * and data synchronization for featured influencers.
 */
class DD_Featured_Influencer_Manager
{

    /**
     * Constructor.
     * * Initializes all necessary WordPress hooks for the functionality.
     */
    public function __construct()
    {
        // Admin Column Hooks
        add_filter('manage_influencer_posts_columns', [$this, 'add_featured_column']);
        add_action('manage_influencer_posts_custom_column', [$this, 'render_featured_column'], 10, 2);

        // AJAX Handler for the star toggle
        add_action('wp_ajax_dd_toggle_featured_influencer', [$this, 'ajax_toggle_featured']);

        // Scripts and Styles
        add_action('admin_head', [$this, 'print_admin_scripts']);

        // Settings Page Hooks
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Cleanup Hook
        add_action('deleted_post', [$this, 'handle_post_deletion'], 10, 2);
    }

    /**
     * Adds the 'Featured' column to the Influencer post list table.
     *
     * @param array $columns Existing post columns.
     * @return array Modified columns array with the featured star column.
     */
    public function add_featured_column($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ('title' === $key) {
                // Insert the featured column right after the title column.
                $new_columns['featured_influencer'] = '<span class="dashicons dashicons-star-filled" title="Featured"></span>';
            }
        }
        return $new_columns;
    }

    /**
     * Renders the star toggle icon in the 'Featured' column for each influencer.
     *
     * @param string $column  The current column name.
     * @param int    $post_id The current post ID.
     */
    public function render_featured_column($column, $post_id)
    {
        if ('featured_influencer' === $column) {
            $is_featured = get_post_meta($post_id, '_featured_influencer', true);
            $star_class  = ('yes' === $is_featured) ? 'dashicons-star-filled' : 'dashicons-star-empty';
            $nonce       = wp_create_nonce('dd_toggle_featured_' . $post_id);

            echo sprintf(
                '<a href="#" class="dd-featured-toggle" data-post-id="%d" data-nonce="%s" title="%s">
					<span class="dashicons %s"></span>
				</a>',
                esc_attr($post_id),
                esc_attr($nonce),
                esc_attr__('Toggle Featured Status', 'dd-influencer'),
                esc_attr($star_class)
            );
        }
    }

    /**
     * Processes the AJAX request to toggle the featured status.
     * * Updates the individual post meta and synchronizes the global option array.
     */
    public function ajax_toggle_featured()
    {
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $nonce   = isset($_POST['nonce']) ? $_POST['nonce'] : '';

        if (! $post_id || ! wp_verify_nonce($nonce, 'dd_toggle_featured_' . $post_id)) {
            wp_send_json_error('Invalid nonce or post ID.');
        }

        if (! current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Insufficient permissions.');
        }

        $current_status = get_post_meta($post_id, '_featured_influencer', true);
        $new_status     = ('yes' === $current_status) ? 'no' : 'yes';

        // Update individual post meta
        update_post_meta($post_id, '_featured_influencer', $new_status);

        // Sync with the global array
        $global_featured = get_option('featured_influencers', []);
        if (! is_array($global_featured)) {
            $global_featured = [];
        }

        if ('yes' === $new_status) {
            if (! in_array($post_id, $global_featured)) {
                $global_featured[] = $post_id;
            }
        } else {
            $global_featured = array_diff($global_featured, [$post_id]);
        }

        // Save the global option (this triggers the sanitize callback, which acts as a secondary sync layer)
        update_option('featured_influencers', array_values($global_featured));

        wp_send_json_success(['status' => $new_status]);
    }

    /**
     * Inlines CSS and JS strictly on the Influencer list table screen to handle the toggle UI.
     */
    public function print_admin_scripts()
    {
        global $typenow;
        if ('influencer' !== $typenow) {
            return;
        }
?>
        <style>
            .column-featured_influencer {
                width: 50px;
                text-align: center;
            }

            .dd-featured-toggle {
                text-decoration: none;
                cursor: pointer;
                outline: none;
                box-shadow: none;
            }

            .dd-featured-toggle .dashicons-star-filled {
                color: #f5da55;
            }

            .dd-featured-toggle .dashicons-star-empty {
                color: #b4b9be;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                $('.dd-featured-toggle').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $icon = $btn.find('.dashicons');
                    var post_id = $btn.data('post-id');
                    var nonce = $btn.data('nonce');

                    // Optimistic UI update
                    var is_filled = $icon.hasClass('dashicons-star-filled');
                    $icon.removeClass('dashicons-star-filled dashicons-star-empty')
                        .addClass(is_filled ? 'dashicons-star-empty' : 'dashicons-star-filled');

                    $.post(ajaxurl, {
                        action: 'dd_toggle_featured_influencer',
                        post_id: post_id,
                        nonce: nonce
                    }).fail(function() {
                        // Revert if request fails
                        $icon.removeClass('dashicons-star-filled dashicons-star-empty')
                            .addClass(is_filled ? 'dashicons-star-filled' : 'dashicons-star-empty');
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Registers the submenu page under the Influencer post type.
     */
    public function add_settings_page()
    {
        add_submenu_page(
            'edit.php?post_type=page',
            'Featured Influencers',
            'Featured Influencers',
            'manage_options',
            'dd-featured-influencers',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registers the global setting and its sanitization callback.
     */
    public function register_settings()
    {
        register_setting(
            'dd_featured_influencers_group',
            'featured_influencers',
            [
                'sanitize_callback' => [$this, 'sanitize_and_sync_featured_influencers']
            ]
        );
    }

    /**
     * Sanitizes the incoming global featured options and synchronizes the meta data
     * across all affected Influencer posts.
     *
     * @param array $input The submitted array of post IDs from the settings page.
     * @return array The filtered and sanitized array of post IDs.
     */
    public function sanitize_and_sync_featured_influencers($input)
    {
        // Ensure input is an array and filter out empty values (like the dummy 0)
        $input = array_filter(array_map('intval', (array) $input));
        $input = array_values($input); // Reset keys

        $old_value = get_option('featured_influencers', []);
        if (! is_array($old_value)) {
            $old_value = [];
        }

        $added   = array_diff($input, $old_value);
        $removed = array_diff($old_value, $input);

        // Sync newly added posts
        foreach ($added as $post_id) {
            update_post_meta($post_id, '_featured_influencer', 'yes');
        }

        // Sync newly removed posts
        foreach ($removed as $post_id) {
            update_post_meta($post_id, '_featured_influencer', 'no');
        }

        return $input;
    }

    /**
     * Renders the HTML markup for the global Featured Influencers settings page.
     */
    public function render_settings_page()
    {
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manage Featured Influencers', 'dd-influencer'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('dd_featured_influencers_group');
                do_settings_sections('dd_featured_influencers_group');

                $selected_influencers = get_option('featured_influencers', []);
                if (! is_array($selected_influencers)) {
                    $selected_influencers = [];
                }

                $all_influencers = get_posts([
                    'post_type'      => 'influencer',
                    'posts_per_page' => -1,
                    'post_status'    => 'any',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ]);
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="featured_influencers_select"><?php esc_html_e('Select Featured Influencers', 'dd-influencer'); ?></label></th>
                        <td>
                            <input type="hidden" name="featured_influencers[]" value="0">
                            <select name="featured_influencers[]" id="featured_influencers_select" multiple="multiple" style="width: 100%; max-width: 500px; height: 350px;">
                                <?php foreach ($all_influencers as $influencer) : ?>
                                    <?php $is_selected = in_array($influencer->ID, $selected_influencers) ? 'selected="selected"' : ''; ?>
                                    <option value="<?php echo esc_attr($influencer->ID); ?>" <?php echo $is_selected; ?>>
                                        <?php echo esc_html($influencer->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Hold down Ctrl (Windows) or Command (Mac) to select or deselect multiple influencers.', 'dd-influencer'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
<?php
    }

    /**
     * Hooks into post deletion to securely remove the influencer from the global 
     * options array if they are permanently deleted.
     *
     * @param int     $post_id The ID of the post being deleted.
     * @param WP_Post $post    The post object.
     */
    public function handle_post_deletion($post_id, $post)
    {
        if ('influencer' !== $post->post_type) {
            return;
        }

        $global_featured = get_option('featured_influencers', []);

        if (is_array($global_featured) && in_array($post_id, $global_featured)) {
            $global_featured = array_diff($global_featured, [$post_id]);
            // Update the option directly without triggering the sync hook redundantly
            update_option('featured_influencers', array_values($global_featured));
        }
    }
}

// Initialize the class
new DD_Featured_Influencer_Manager();
