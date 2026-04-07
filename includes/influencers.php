<?php
/**
 * Plugin Name: Influencer Extensions
 * Description: Adds featured influencer functionality (WooCommerce style), expert toggles, and synchronized global settings to the influencer post type.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the meta boxes for the 'influencer' post type.
 * 
 * Adds a custom meta box to the sidebar of the post edit screen to manage
 * the 'Featured Influencer' and 'Professional experts only' toggles.
 *
 * @return void
 */
function dd_influencer_register_meta_boxes() {
	add_meta_box(
		'influencer_attributes_meta_box',
		__( 'Influencer Attributes', 'textdomain' ),
		'dd_influencer_attributes_meta_box_html',
		'influencer',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'dd_influencer_register_meta_boxes' );

/**
 * Renders the HTML for the Influencer Attributes meta box.
 * 
 * Outputs nonces for security and the checkbox fields for `_is_featured_influencer`
 * and `is_expert`.
 *
 * @param WP_Post $post The current post object.
 * @return void
 */
function dd_influencer_attributes_meta_box_html( $post ) {
	wp_nonce_field( 'dd_influencer_attributes_save', 'dd_influencer_attributes_nonce' );

	$is_featured = get_post_meta( $post->ID, '_is_featured_influencer', true );
	$is_expert   = get_post_meta( $post->ID, 'is_expert', true );

	?>
	<p>
		<label for="dd_is_featured_influencer">
			<input type="checkbox" name="dd_is_featured_influencer" id="dd_is_featured_influencer" value="yes" <?php checked( $is_featured, 'yes' ); ?> />
			<?php esc_html_e( 'Featured Influencer', 'textdomain' ); ?>
		</label>
	</p>
	<p>
		<label for="dd_is_expert">
			<input type="checkbox" name="is_expert" id="dd_is_expert" value="yes" <?php checked( $is_expert, 'yes' ); ?> />
			<?php esc_html_e( 'Professional experts only', 'textdomain' ); ?>
		</label>
	</p>
	<?php
}

/**
 * Saves the custom meta box data when the influencer post is saved.
 * 
 * Validates nonces and permissions, updates the post meta, and triggers
 * the synchronization function to update the global option.
 *
 * @param int $post_id The ID of the post being saved.
 * @return void
 */
function dd_influencer_save_meta_box_data( $post_id ) {
	// Security checks.
	if ( ! isset( $_POST['dd_influencer_attributes_nonce'] ) || ! wp_verify_nonce( $_POST['dd_influencer_attributes_nonce'], 'dd_influencer_attributes_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Save Featured Status
	$featured_status = isset( $_POST['dd_is_featured_influencer'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, '_is_featured_influencer', $featured_status );

	// Save Expert Status
	$expert_status = isset( $_POST['is_expert'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, 'is_expert', $expert_status );

	// Sync with global settings
	dd_sync_global_featured_influencers();
}
add_action( 'save_post_influencer', 'dd_influencer_save_meta_box_data' );

/**
 * Synchronizes the global featured influencers option with post meta.
 * 
 * Queries all influencers that have the `_is_featured_influencer` meta set to 'yes'
 * and saves their IDs into a single global array (`wp_options`). This allows for
 * O(1) performance when fetching featured influencers on the frontend.
 *
 * @return void
 */
function dd_sync_global_featured_influencers() {
	$featured_query = new WP_Query( array(
		'post_type'      => 'influencer',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => '_is_featured_influencer',
				'value' => 'yes',
			),
		),
	) );

	update_option( 'global_featured_influencers', $featured_query->posts );
}

/**
 * Adds custom columns to the 'influencer' post type admin list.
 * * Injects the 'Featured' column immediately after the title. 
 * Utilizes priority 99 to prevent overwrites by third-party plugins.
 *
 * @param array $columns An array of existing column names.
 * @return array Modified array of column names.
 */
function dd_influencer_add_custom_columns( $columns ) {
	$new_columns = array();
	$inserted    = false;

	foreach ( $columns as $key => $title ) {
		$new_columns[ $key ] = $title;
		// Inject immediately after the title column
		if ( 'title' === $key ) {
			$new_columns['featured'] = '<span class="dashicons dashicons-star-filled" title="' . esc_attr__( 'Featured', 'textdomain' ) . '"></span>';
			$inserted = true;
		}
	}

	// Fallback: If the 'title' column was missing/renamed by another plugin, append to the end.
	if ( ! $inserted ) {
		$new_columns['featured'] = '<span class="dashicons dashicons-star-filled" title="' . esc_attr__( 'Featured', 'textdomain' ) . '"></span>';
	}

	return $new_columns;
}
// Using the screen-specific hook is generally more robust for custom post types.
add_filter( 'manage_edit-influencer_columns', 'dd_influencer_add_custom_columns', 99 );


/**
 * Renders the content for the custom 'featured' column.
 * * Outputs an anchor tag with data attributes necessary for the AJAX toggle.
 *
 * @param string $column  The name of the column to display.
 * @param int    $post_id The ID of the current post.
 * @return void
 */
function dd_influencer_render_custom_columns( $column, $post_id ) {
	if ( 'featured' === $column ) {
		$is_featured = get_post_meta( $post_id, '_is_featured_influencer', true );
		$icon_class  = ( 'yes' === $is_featured ) ? 'dashicons-star-filled' : 'dashicons-star-empty';
		
		printf(
			'<a href="#" class="dd-toggle-featured" data-post-id="%d" data-nonce="%s"><span class="dashicons %s"></span></a>',
			esc_attr( $post_id ),
			esc_attr( wp_create_nonce( 'dd-toggle-featured-' . $post_id ) ),
			esc_attr( $icon_class )
		);
	}
}
// Hook for non-hierarchical post types (default)
add_action( 'manage_influencer_posts_custom_column', 'dd_influencer_render_custom_columns', 99, 2 );
// Hook for hierarchical post types (failsafe)
add_action( 'manage_influencer_pages_custom_column', 'dd_influencer_render_custom_columns', 99, 2 );

/**
 * Handles the AJAX request to toggle the featured status of an influencer.
 * 
 * Validates the nonce, toggles the `_is_featured_influencer` meta value,
 * synchronizes the global settings, and returns a JSON success response.
 *
 * @return void
 */
function dd_influencer_ajax_toggle_featured() {
	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$nonce   = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';

	if ( ! wp_verify_nonce( $nonce, 'dd-toggle-featured-' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$current_status = get_post_meta( $post_id, '_is_featured_influencer', true );
	$new_status     = ( 'yes' === $current_status ) ? 'no' : 'yes';

	update_post_meta( $post_id, '_is_featured_influencer', $new_status );
	dd_sync_global_featured_influencers();

	wp_send_json_success( array( 'new_status' => $new_status ) );
}
add_action( 'wp_ajax_dd_toggle_featured', 'dd_influencer_ajax_toggle_featured' );

/**
 * Injects inline JavaScript and CSS to handle the WooCommerce-style AJAX toggle.
 * 
 * Ensures the script only loads on the 'influencer' edit screen.
 *
 * @return void
 */
function dd_influencer_admin_footer_scripts() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-influencer' !== $screen->id ) {
		return;
	}
	?>
	<style>
		.column-featured { width: 60px; text-align: center !important; }
		.dd-toggle-featured { text-decoration: none; color: #b3b3b3; transition: color 0.2s; }
		.dd-toggle-featured .dashicons-star-filled { color: #f5da24; }
		.dd-toggle-featured:focus { box-shadow: none; }
	</style>
	<script>
	jQuery(document).ready(function($) {
		$('.dd-toggle-featured').on('click', function(e) {
			e.preventDefault();
			var $btn    = $(this);
			var $icon   = $btn.find('.dashicons');
			var post_id = $btn.data('post-id');
			var nonce   = $btn.data('nonce');

			// Optimistic UI update
			var is_filled = $icon.hasClass('dashicons-star-filled');
			$icon.removeClass('dashicons-star-filled dashicons-star-empty');
			$icon.addClass(is_filled ? 'dashicons-star-empty' : 'dashicons-star-filled');

			$.post(ajaxurl, {
				action: 'dd_toggle_featured',
				post_id: post_id,
				nonce: nonce
			}, function(response) {
				if (!response.success) {
					// Revert on failure
					$icon.removeClass('dashicons-star-filled dashicons-star-empty');
					$icon.addClass(is_filled ? 'dashicons-star-filled' : 'dashicons-star-empty');
					alert('Error toggling featured status.');
				}
			});
		});
	});
	</script>
	<?php
}
add_action( 'admin_footer', 'dd_influencer_admin_footer_scripts' );

/**
 * Registers a submenu page under 'Influencers' for the Global Settings.
 * 
 * Allows batch management of featured influencers from a single synchronized dashboard.
 *
 * @return void
 */
function dd_influencer_register_settings_page() {
	add_submenu_page(
		'edit.php?post_type=influencer',
		__( 'Featured Settings', 'textdomain' ),
		__( 'Featured Settings', 'textdomain' ),
		'manage_options',
		'influencer-featured-settings',
		'dd_influencer_settings_page_html'
	);
}
add_action( 'admin_menu', 'dd_influencer_register_settings_page' );

/**
 * Renders the HTML and handles the form submission for the Global Settings page.
 * 
 * Iterates through all influencers to render checkboxes. Upon form submission,
 * it updates the `_is_featured_influencer` meta for all posts to ensure bidirectional
 * sync between the global settings interface and individual post metadata.
 *
 * @return void
 */
function dd_influencer_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle form save
	if ( isset( $_POST['dd_settings_nonce'] ) && wp_verify_nonce( $_POST['dd_settings_nonce'], 'dd_save_settings' ) ) {
		$selected_featured = isset( $_POST['featured_influencers'] ) ? array_map( 'intval', $_POST['featured_influencers'] ) : array();
		
		// We must update the post meta for ALL influencers to ensure bidirectional synchronization.
		$all_influencers = get_posts( array( 'post_type' => 'influencer', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		
		foreach ( $all_influencers as $influencer_id ) {
			$status = in_array( $influencer_id, $selected_featured ) ? 'yes' : 'no';
			update_post_meta( $influencer_id, '_is_featured_influencer', $status );
		}
		
		// Sync the optimized global option array
		dd_sync_global_featured_influencers();
		
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Featured influencers synchronized and updated globally.', 'textdomain' ) . '</p></div>';
	}

	// Fetch data for the form
	$all_influencers = get_posts( array( 'post_type' => 'influencer', 'posts_per_page' => -1 ) );
	$global_featured = get_option( 'global_featured_influencers', array() );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Global Featured Influencers Settings', 'textdomain' ); ?></h1>
		<p><?php esc_html_e( 'Select the influencers you want to feature. Changes made here will automatically synchronize with the individual influencer post settings.', 'textdomain' ); ?></p>
		
		<form method="post" action="">
			<?php wp_nonce_field( 'dd_save_settings', 'dd_settings_nonce' ); ?>
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Featured Roster', 'textdomain' ); ?></th>
						<td>
							<fieldset>
								<?php if ( ! empty( $all_influencers ) ) : ?>
									<?php foreach ( $all_influencers as $influencer ) : ?>
										<label style="display:block; margin-bottom: 5px;">
											<input type="checkbox" name="featured_influencers[]" value="<?php echo esc_attr( $influencer->ID ); ?>" <?php checked( in_array( $influencer->ID, $global_featured ) ); ?> />
											<?php echo esc_html( get_the_title( $influencer->ID ) ); ?>
										</label>
									<?php endforeach; ?>
								<?php else : ?>
									<p><?php esc_html_e( 'No influencers found.', 'textdomain' ); ?></p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
			
			<?php submit_button( __( 'Sync and Save Changes', 'textdomain' ) ); ?>
		</form>
	</div>
	<?php
}