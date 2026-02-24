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
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
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
     * Enqueues the necessary CSS for the Instagram feed layout.
     * Utilizes wp_add_inline_style to keep the plugin a self-contained single file.
     * * @return void
     */
    public function enqueue_styles(): void
    {
        $custom_css = "
            /* Instagram Feed Grid Container */
            .cdb-ig-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 20px;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                background-color: #f3f4f6;
                padding: 20px;
            }

            /* Individual Post Card */
            .cdb-ig-card {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            /* Card Header */
            .cdb-ig-header {
                display: flex;
                align-items: center;
                padding: 12px 16px;
                border-bottom: 1px solid #f3f4f6;
            }

            .cdb-ig-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                object-fit: cover;
                margin-right: 12px;
            }

            .cdb-ig-user-info {
                flex-grow: 1;
            }

            .cdb-ig-username {
                font-weight: 600;
                font-size: 14px;
                color: #262626;
            }

            .cdb-ig-followers {
                font-size: 12px;
                color: #8e8e8e;
            }

            .cdb-ig-btn-view {
                background-color: #0095f6;
                color: #ffffff;
                font-size: 14px;
                font-weight: 600;
                padding: 6px 16px;
                border-radius: 4px;
                text-decoration: none;
                transition: background-color 0.2s;
            }

            .cdb-ig-btn-view:hover {
                background-color: #007bb5;
                color: #ffffff;
            }

            /* Media Display */
            .cdb-ig-media {
                position: relative;
                width: 100%;
                aspect-ratio: 1 / 1;
                background-color: #fafafa;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
            }

            .cdb-ig-media img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .cdb-ig-indicator {
                position: absolute;
                top: 10px;
                right: 10px;
                background: rgba(0, 0, 0, 0.6);
                border-radius: 4px;
                padding: 4px;
                display: flex;
            }

            /* Broken Link Fallback UI */
            .cdb-ig-broken-link {
                text-align: center;
                padding: 20px;
            }

            .cdb-ig-broken-icon {
                font-size: 48px;
                margin-bottom: 10px;
            }

            .cdb-ig-broken-link p {
                font-size: 14px;
                color: #8e8e8e;
                margin-bottom: 16px;
            }

            .cdb-ig-broken-link a {
                color: #0095f6;
                font-weight: 600;
                text-decoration: none;
            }

            /* Card Content & Caption */
            .cdb-ig-content {
                padding: 12px 16px;
                flex-grow: 1;
            }

            .cdb-ig-view-more {
                display: block;
                color: #0095f6;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                margin-bottom: 12px;
            }

            .cdb-ig-actions {
                display: flex;
                justify-content: space-between;
                font-size: 20px;
                color: #262626;
                margin-bottom: 12px;
            }

            .cdb-action-left {
                letter-spacing: 10px;
            }

            .cdb-ig-caption {
                font-size: 14px;
                line-height: 1.5;
                color: #262626;
                margin: 0;
            }

            /* Card Footer */
            .cdb-ig-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                border-top: 1px solid #f3f4f6;
                font-size: 12px;
                color: #8e8e8e;
            }

            .cdb-ig-metrics {
                display: flex;
                gap: 12px;
            }
        ";

        // Register a dummy handle and attach the inline CSS to it
        wp_register_style('creatordb-ig-feed-style', false);
        wp_enqueue_style('creatordb-ig-feed-style');
        wp_add_inline_style('creatordb-ig-feed-style', $custom_css);
    }

    /**
     * Shortcode callback to initialize the Instagram feed rendering.
     * * @param array|string $atts Shortcode attributes (unused in this specific instance but required by WP signature).
     * @return string The generated HTML grid.
     */
    public function render_feed_shortcode($atts): string
    {
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
     * Includes referrer-policy bypass for Instagram CDN hotlink protection.
     *
     * @param array $post A single associative array containing post data.
     * @return string The HTML markup for the card.
     */
    private function render_single_card(array $post): string
    {
        // Extract data. Using esc_url_raw for URLs to prevent encoding complex CDN signature parameters (&) too early.
        $shortcode     = sanitize_text_field($post['shortcode'] ?? '');
        $title         = wp_kses_post($post['title'] ?? '');
        $photo_url     = esc_url_raw($post['photoURL'] ?? '');
        $carousel_urls = array_map('esc_url_raw', $post['carouselUrls'] ?? []);

        // Data mapping
        $date          = esc_html($post['date'] ?? '2025-05-19');
        $likes         = absint($post['likes'] ?? 3);
        $comments      = absint($post['comments'] ?? 7);
        $er            = esc_html($post['er'] ?? '0.05%');
        $username      = sanitize_text_field($post['username'] ?? 'cam24fps');
        $followers     = esc_html($post['followers'] ?? '18.6K');

        // Ensure profile pic falls back gracefully
        $profile_pic   = esc_url_raw($post['profilePic'] ?? 'https://via.placeholder.com/40');

        // Determine main image
        $display_image = ! empty($carousel_urls) ? $carousel_urls[0] : $photo_url;
        $post_url      = 'https://www.instagram.com/p/' . $shortcode . '/';
        $trimmed_title = wp_trim_words($title, 20, '&hellip;');

        ob_start();
    ?>
        <div class="cdb-ig-card">

            <div class="cdb-ig-header">
                <img src="<?php echo esc_url($profile_pic); ?>" alt="<?php esc_attr_e('Profile', 'creatordb-ig-feed'); ?>" class="cdb-ig-avatar" referrerpolicy="no-referrer">
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
                        <img src="<?php echo esc_url($display_image); ?>" alt="<?php esc_attr_e('Instagram Post', 'creatordb-ig-feed'); ?>" loading="lazy" referrerpolicy="no-referrer">
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
