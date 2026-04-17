<?php

/**
 * Plugin Name: Influencer Extensions
 * Description: Adds featured influencer functionality (WooCommerce style), expert toggles, and synchronized global settings to the influencer post type.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.0.2
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the meta boxes for the 'influencer' post type.
 * * Adds a custom meta box to the sidebar of the post edit screen to manage
 * the 'Featured Influencer' and 'Professional experts only' toggles.
 *
 * @return void
 */
function dd_influencer_register_meta_boxes()
{
	add_meta_box(
		'influencer_attributes_meta_box',
		__('Influencer Attributes', 'textdomain'),
		'dd_influencer_attributes_meta_box_html',
		'influencer',
		'side',
		'high'
	);
}
add_action('add_meta_boxes', 'dd_influencer_register_meta_boxes');

/**
 * Renders the HTML for the Influencer Attributes meta box.
 * * Outputs nonces for security and the checkbox fields for `_is_featured_influencer`
 * and `is_expert`.
 *
 * @param WP_Post $post The current post object.
 * @return void
 */
function dd_influencer_attributes_meta_box_html($post)
{
	wp_nonce_field('dd_influencer_attributes_save', 'dd_influencer_attributes_nonce');

	$is_featured = get_post_meta($post->ID, '_is_featured_influencer', true);
	$is_expert   = get_post_meta($post->ID, 'is_expert', true);

?>
	<p>
		<label for="dd_is_featured_influencer">
			<input type="checkbox" name="dd_is_featured_influencer" id="dd_is_featured_influencer" value="yes" <?php checked($is_featured, 'yes'); ?> />
			<?php esc_html_e('Featured Influencer', 'textdomain'); ?>
		</label>
	</p>
	<p>
		<label for="dd_is_expert">
			<input type="checkbox" name="is_expert" id="dd_is_expert" value="yes" <?php checked($is_expert, 'yes'); ?> />
			<?php esc_html_e('Professional experts only', 'textdomain'); ?>
		</label>
	</p>
<?php
}
/**
 * Saves the custom meta box data when the influencer post is saved.
 * * Validates nonces and permissions, updates the post meta, and triggers
 * the synchronization function to update the global option.
 *
 * @param int $post_id The ID of the post being saved.
 * @return void
 */
function dd_influencer_save_meta_box_data($post_id)
{
	if (! isset($_POST['dd_influencer_attributes_nonce']) || ! wp_verify_nonce($_POST['dd_influencer_attributes_nonce'], 'dd_influencer_attributes_save')) {
		return;
	}
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (! current_user_can('edit_post', $post_id)) {
		return;
	}

	$featured_status = isset($_POST['dd_is_featured_influencer']) ? 'yes' : 'no';
	update_post_meta($post_id, '_is_featured_influencer', $featured_status);

	$expert_status = isset($_POST['is_expert']) ? 'yes' : 'no';
	update_post_meta($post_id, 'is_expert', $expert_status);

	dd_sync_global_featured_influencers();
}
add_action('save_post_influencer', 'dd_influencer_save_meta_box_data');

/**
 * Synchronizes the global featured influencers option with post meta.
 * * Queries all influencers that have the `_is_featured_influencer` meta set to 'yes'
 * and saves their IDs into a single global array (`wp_options`). 
 *
 * @return void
 */
function dd_sync_global_featured_influencers()
{
	$featured_query = new WP_Query(array(
		'post_type'      => 'influencer',
		'posts_per_page' => 1000, // Hard limit to prevent infinite query timeouts
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => '_is_featured_influencer',
				'value' => 'yes',
			),
		),
	));

	// Strictly cast to integers
	$featured_ids = array_map('intval', $featured_query->posts);
	update_option('global_featured_influencers', $featured_ids);
}

/**
 * Adds custom columns to the 'influencer' post type admin list.
 * * Injects the 'Featured' column immediately after the title. 
 * Utilizes priority 99 to prevent overwrites by third-party plugins.
 *
 * @param array $columns An array of existing column names.
 * @return array Modified array of column names.
 */
function dd_influencer_add_custom_columns($columns)
{
	$new_columns = array();
	$inserted    = false;

	foreach ($columns as $key => $title) {
		$new_columns[$key] = $title;
		if ('title' === $key) {
			$new_columns['featured'] = '<span class="dashicons dashicons-star-filled" title="' . esc_attr__('Featured', 'textdomain') . '"></span>';
			$inserted = true;
		}
	}

	if (! $inserted) {
		$new_columns['featured'] = '<span class="dashicons dashicons-star-filled" title="' . esc_attr__('Featured', 'textdomain') . '"></span>';
	}

	return $new_columns;
}
add_filter('manage_edit-influencer_columns', 'dd_influencer_add_custom_columns', 99);

/**
 * Renders the content for the custom 'featured' column.
 * * Outputs an anchor tag with data attributes necessary for the AJAX toggle.
 *
 * @param string $column  The name of the column to display.
 * @param int    $post_id The ID of the current post.
 * @return void
 */
function dd_influencer_render_custom_columns($column, $post_id)
{
	if ('featured' === $column) {
		$is_featured = get_post_meta($post_id, '_is_featured_influencer', true);
		$icon_class  = ('yes' === $is_featured) ? 'dashicons-star-filled' : 'dashicons-star-empty';

		printf(
			'<a href="#" class="dd-toggle-featured" data-post-id="%d" data-nonce="%s"><span class="dashicons %s"></span></a>',
			esc_attr($post_id),
			esc_attr(wp_create_nonce('dd-toggle-featured-' . $post_id)),
			esc_attr($icon_class)
		);
	}
}
add_action('manage_influencer_posts_custom_column', 'dd_influencer_render_custom_columns', 99, 2);

/**
 * Handles the AJAX request to toggle the featured status of an influencer.
 * * Validates the nonce, toggles the meta value, and syncs the global option.
 *
 * @return void
 */
function dd_influencer_ajax_toggle_featured()
{
	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
	$nonce   = isset($_POST['nonce']) ? $_POST['nonce'] : '';

	if (! wp_verify_nonce($nonce, 'dd-toggle-featured-' . $post_id) || ! current_user_can('edit_post', $post_id)) {
		wp_send_json_error(array('message' => 'Permission denied'));
	}

	$current_status = get_post_meta($post_id, '_is_featured_influencer', true);
	$new_status     = ('yes' === $current_status) ? 'no' : 'yes';

	update_post_meta($post_id, '_is_featured_influencer', $new_status);
	dd_sync_global_featured_influencers();

	wp_send_json_success(array('new_status' => $new_status));
}
add_action('wp_ajax_dd_toggle_featured', 'dd_influencer_ajax_toggle_featured');

/**
 * Injects inline JavaScript and CSS to handle the WooCommerce-style AJAX toggle.
 *
 * @return void
 */
function dd_influencer_admin_footer_scripts()
{
	$screen = get_current_screen();
	if (! $screen || 'edit-influencer' !== $screen->id) {
		return;
	}
?>
	<style>
		.column-featured {
			width: 60px;
			text-align: center !important;
		}

		.dd-toggle-featured {
			text-decoration: none;
			color: #b3b3b3;
			transition: color 0.2s;
		}

		.dd-toggle-featured .dashicons-star-filled {
			color: #f5da24;
		}

		.dd-toggle-featured:focus {
			box-shadow: none;
		}
	</style>
	<script>
		jQuery(document).ready(function($) {
			$('.dd-toggle-featured').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var $icon = $btn.find('.dashicons');
				var post_id = $btn.data('post-id');
				var nonce = $btn.data('nonce');

				var is_filled = $icon.hasClass('dashicons-star-filled');
				$icon.removeClass('dashicons-star-filled dashicons-star-empty').addClass(is_filled ? 'dashicons-star-empty' : 'dashicons-star-filled');

				$.post(ajaxurl, {
					action: 'dd_toggle_featured',
					post_id: post_id,
					nonce: nonce
				}, function(response) {
					if (!response.success) {
						$icon.removeClass('dashicons-star-filled dashicons-star-empty').addClass(is_filled ? 'dashicons-star-filled' : 'dashicons-star-empty');
						alert('Error toggling featured status.');
					}
				});
			});
		});
	</script>
<?php
}
add_action('admin_footer', 'dd_influencer_admin_footer_scripts');

/**
 * Registers a submenu page under 'Influencers' for the Global Settings.
 *
 * @return void
 */
function dd_influencer_register_settings_page()
{
	add_submenu_page(
		'edit.php?post_type=influencer',
		__('Featured Settings', 'textdomain'),
		__('Featured Settings', 'textdomain'),
		'manage_options',
		'influencer-featured-settings',
		'dd_influencer_settings_page_html'
	);
}
add_action('admin_menu', 'dd_influencer_register_settings_page');

/**
 * Enqueues Select2 assets strictly on the Influencer Featured Settings page.
 * 
 * Includes a bulletproof CSS reset to override aggressive third-party 
 * admin styles (e.g., page builders) that break Select2's flexible height.
 *
 * @param string $hook The current admin page hook.
 * @return void
 */
function dd_influencer_enqueue_settings_scripts($hook)
{
	if (empty($_GET['page']) || 'influencer-featured-settings' !== $_GET['page']) {
		return;
	}

	wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0-rc.0');
	wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0-rc.0', true);

	// Advanced Flexbox CSS Hardening
	$custom_css = "
		/* Bulletproof container height reset */
		.select2-container--default .select2-selection--multiple {
			height: auto !important;
			min-height: 36px !important;
			padding-bottom: 2px !important;
			border-color: #8c8f94 !important;
			border-radius: 4px !important;
		}
		
		/* Force the rendered list to wrap its children */
		.select2-container--default .select2-selection--multiple .select2-selection__rendered {
			display: flex !important;
			flex-wrap: wrap !important;
			box-sizing: border-box !important;
			list-style: none !important;
			margin: 0 !important;
			padding: 0 4px !important;
			width: 100% !important;
			white-space: normal !important;
		}

		/* Tag styling and spacing */
		.select2-container--default .select2-selection--multiple .select2-selection__choice {
			display: flex !important;
			align-items: center !important;
			margin-top: 4px !important;
			margin-bottom: 4px !important;
			margin-right: 4px !important;
			padding: 3px 6px !important;
			border: 1px solid #c3c4c7 !important;
			background: #f0f0f1 !important;
			border-radius: 3px !important;
			color: #3c434a !important;
			float: none !important; /* Defeat WP float rules */
		}

		/* 'x' button reset */
		.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
			border: none !important;
			background: transparent !important;
			color: #999 !important;
			cursor: pointer !important;
			padding: 0 6px 0 0 !important;
			font-weight: bold !important;
			position: static !important;
		}
		.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
			color: #d63638 !important;
			background: transparent !important;
		}

		/* Search input alignment */
		.select2-container .select2-search--inline {
			float: none !important;
			display: flex !important;
			align-items: center !important;
		}
		.select2-container .select2-search--inline .select2-search__field {
			margin-top: 4px !important;
			margin-bottom: 4px !important;
			height: 24px !important;
			line-height: 24px !important;
			box-shadow: none !important;
		}
		
		/* Native WP focus states */
		.select2-container--default.select2-container--focus .select2-selection--multiple {
			border-color: #2271b1 !important;
			box-shadow: 0 0 0 1px #2271b1 !important;
		}
	";
	wp_add_inline_style('select2-css', $custom_css);

	wp_add_inline_script('select2-js', "
		jQuery(document).ready(function($) {
			$('#featured_influencers').select2({
				placeholder: '" . esc_js(__('Search and select influencers...', 'textdomain')) . "',
				width: '100%'
			});
		});
	");
}
add_action('admin_enqueue_scripts', 'dd_influencer_enqueue_settings_scripts');

/**
 * Renders the HTML and handles the form submission for the Global Settings page.
 *
 * @return void
 */
function dd_influencer_settings_page_html()
{
	if (! current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'textdomain'));
	}


	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (isset($_POST['dd_settings_nonce']) && wp_verify_nonce($_POST['dd_settings_nonce'], 'dd_save_settings')) {

			$selected_featured = isset($_POST['featured_influencers']) ? array_map('intval', $_POST['featured_influencers']) : array();

			$all_influencer_ids = get_posts(array(
				'post_type'      => 'influencer',
				'posts_per_page' => 1000,
				'fields'         => 'ids'
			));

			foreach ($all_influencer_ids as $influencer_id) {
				$status = in_array((int) $influencer_id, $selected_featured, true) ? 'yes' : 'no';
				update_post_meta($influencer_id, '_is_featured_influencer', $status);
			}

			dd_sync_global_featured_influencers();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Featured influencers synchronized and updated globally.', 'textdomain') . '</p></div>';
		}
	}

	$all_influencer_ids_display = get_posts(array(
		'post_type'      => 'influencer',
		'posts_per_page' => 1000,
		'fields'         => 'ids'
	));

	$raw_global_featured = get_option('global_featured_influencers', array());
	$global_featured     = is_array($raw_global_featured) ? array_map('intval', $raw_global_featured) : array();

?>
	<div class="wrap">
		<h1><?php esc_html_e('Global Featured Influencers Settings', 'textdomain'); ?></h1>

		<form method="post" action="">
			<?php wp_nonce_field('dd_save_settings', 'dd_settings_nonce'); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="featured_influencers"><?php esc_html_e('Featured Influencers', 'textdomain'); ?></label>
						</th>
						<td>
							<div style="max-width: 600px;">
								<select name="featured_influencers[]" id="featured_influencers" multiple="multiple">
									<?php if (! empty($all_influencer_ids_display)) : ?>
										<?php foreach ($all_influencer_ids_display as $influencer_id) : ?>
											<option value="<?php echo esc_attr($influencer_id); ?>" <?php selected(in_array((int) $influencer_id, $global_featured, true), true); ?>>
												<?php echo esc_html(get_the_title($influencer_id)); ?>
											</option>
										<?php endforeach; ?>
									<?php else : ?>
										<option value="" disabled><?php esc_html_e('No influencers found.', 'textdomain'); ?></option>
									<?php endif; ?>
								</select>
							</div>
							<p class="description"><?php esc_html_e('Search by name and click to add. You can drag to reorder or click the "x" to remove an influencer.', 'textdomain'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(__('Sync and Save Changes', 'textdomain')); ?>
		</form>
	</div>
<?php
}