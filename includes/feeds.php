<?php

/**
 * Plugin Name: CreatorDB Instagram Feed
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: An object-oriented WordPress plugin to render CreatorDB-style Instagram arrays via shortcode.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: creatordb-ig-feed
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class CreatorDB_Instagram_Feed
 * * Handles the initialization, data processing, and rendering of the CreatorDB Instagram feed.
 */
class CreatorDB_Instagram_Feed
{

    /**
     * Constructor.
     * * Initializes the plugin by hooking into WordPress core actions.
     */
    public function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
    }

    /**
     * Registers the shortcodes for the plugin.
     * * @return void
     */
    public function register_shortcodes(): void
    {
        add_shortcode('creatordb_feed', [$this, 'render_feed_shortcode']);
    }

    /**
     * Shortcode callback to initialize the Instagram feed rendering.
     * * @param array|string $atts Shortcode attributes (unused in this specific instance but required by WP signature).
     * @return string The generated HTML grid.
     */
    public function render_feed_shortcode($atts): string
    {
        // Retrieve your saved array here. 
        // e.g., $instagram_data = get_option( 'my_saved_creatordb_array', [] );
        $instagram_data = $this->get_instagram_data();

        return $this->generate_feed_html($instagram_data);
    }

    /**
     * Generates the complete HTML wrapper and iterates through post data.
     * * @param array $instagram_data Array of Instagram posts.
     * @return string The compiled HTML string.
     */
    private function generate_feed_html(array $instagram_data): string
    {
        if (empty($instagram_data)) {
            return '<p class="cdb-ig-empty">' . esc_html__('No recent content available.', 'creatordb-ig-feed') . '</p>';
        }

        ob_start();
?>
        <div class="cdb-ig-grid">
            <?php foreach ($instagram_data as $post) : ?>
                <?php echo $this->render_single_card($post); ?>
            <?php endforeach; ?>
        </div>
    <?php

        return ob_get_clean();
    }

    /**
     * Renders the HTML markup for a single Instagram card.
     * * @param array $post A single associative array containing post data.
     * @return string The HTML markup for the card.
     */
    private function render_single_card(array $post): string
    {
        // Extract and sanitize data directly from the passed array
        $shortcode     = sanitize_text_field($post['shortcode'] ?? '');
        $title         = wp_kses_post($post['title'] ?? '');
        $photo_url     = esc_url($post['photoURL'] ?? '');
        $carousel_urls = array_map('esc_url', $post['carouselUrls'] ?? []);

        // Data mapping (adjust these keys based on your full array structure)
        $date          = esc_html($post['date'] ?? '2025-05-19');
        $likes         = absint($post['likes'] ?? 3);
        $comments      = absint($post['comments'] ?? 7);
        $er            = esc_html($post['er'] ?? '0.05%');
        $username      = sanitize_text_field($post['username'] ?? 'cam24fps');
        $followers     = esc_html($post['followers'] ?? '18.6K');
        $profile_pic   = esc_url($post['profilePic'] ?? 'https://via.placeholder.com/40');

        $display_image = ! empty($carousel_urls) ? $carousel_urls[0] : $photo_url;
        $post_url      = 'https://www.instagram.com/p/' . $shortcode . '/';
        $trimmed_title = wp_trim_words($title, 20, '&hellip;');

        ob_start();
    ?>
        <div class="cdb-ig-card">

            <div class="cdb-ig-header">
                <img src="<?php echo $profile_pic; ?>" alt="<?php esc_attr_e('Profile', 'creatordb-ig-feed'); ?>" class="cdb-ig-avatar">
                <div class="cdb-ig-user-info">
                    <div class="cdb-ig-username"><?php echo $username; ?></div>
                    <div class="cdb-ig-followers"><?php echo $followers; ?> <?php esc_html_e('followers', 'creatordb-ig-feed'); ?></div>
                </div>
                <a href="https://instagram.com/<?php echo esc_attr($username); ?>" target="_blank" rel="noopener noreferrer" class="cdb-ig-btn-view">
                    <?php esc_html_e('View profile', 'creatordb-ig-feed'); ?>
                </a>
            </div>

            <div class="cdb-ig-media">
                <?php if ($display_image) : ?>
                    <a href="<?php echo esc_url($post_url); ?>" target="_blank" rel="noopener noreferrer">
                        <img src="<?php echo $display_image; ?>" alt="<?php esc_attr_e('Instagram Post', 'creatordb-ig-feed'); ?>" loading="lazy">
                        <?php if (count($carousel_urls) > 1) : ?>
                            <div class="cdb-ig-indicator">
                                <svg width="16" height="16" fill="white" viewBox="0 0 24 24">
                                    <path d="M22 4h-2V2h2v2zm0 4h-2V6h2v2zm0 4h-2v-2h2v2zm0 4h-2v-2h2v2zm0 4h-2v-2h2v2zm-4 0h-2v-2h2v2zm-4 0h-2v-2h2v2zm-4 0H8v-2h2v2zm-4 0H4v-2h2v2zM2 20h2v2H2v-2zm0-4h2v2H2v-2zm0-4h2v2H2v-2zm0-4h2v2H2V8zm0-4h2v2H2V4zm4 0h2v2H6V4zm4 0h2v2h-2V4zm4 0h2v2h-2V4zm4 0h2v2h-2V4z" />
                                </svg>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php else : ?>
                    <div class="cdb-ig-broken-link">
                        <div class="cdb-ig-broken-icon">📸</div>
                        <p><?php esc_html_e('The link to this photo or video may be broken, or the post may have been removed.', 'creatordb-ig-feed'); ?></p>
                        <a href="<?php echo esc_url($post_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Visit Instagram', 'creatordb-ig-feed'); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cdb-ig-content">
                <a href="<?php echo esc_url($post_url); ?>" target="_blank" rel="noopener noreferrer" class="cdb-ig-view-more">
                    <?php esc_html_e('View more on Instagram', 'creatordb-ig-feed'); ?>
                </a>
                <div class="cdb-ig-actions">
                    <span class="cdb-action-left">♡ 💬 ↗</span>
                    <span class="cdb-action-right">⚑</span>
                </div>
                <p class="cdb-ig-caption"><strong><?php echo $username; ?></strong> <?php echo $trimmed_title; ?></p>
            </div>

            <div class="cdb-ig-footer">
                <span class="cdb-ig-date"><?php echo $date; ?></span>
                <div class="cdb-ig-metrics">
                    <span>♡ <?php echo $likes; ?></span>
                    <span>💬 <?php echo $comments; ?></span>
                    <span>📈 <?php echo $er; ?></span>
                </div>
            </div>

        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Mock function to retrieve the Instagram data array.
     * Replace the logic inside this method with your actual data source.
     * * @return array The array of Instagram post data.
     */
    private function get_instagram_data(): array
    {
        $recentposts = get_post_meta(get_the_ID(), 'recentposts', true);


        return $recentposts ?: [];
    }
}

// Initialize the class.
new CreatorDB_Instagram_Feed();
