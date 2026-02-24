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
     * Renders the HTML markup for a single Instagram card using the native embed iframe.
     * This guarantees media delivery by offloading CDN token management to Meta.
     *
     * @param array $post A single associative array containing post data.
     * @return string The HTML markup for the card.
     */
    private function render_single_card(array $post): string
    {
        // Extract and sanitize the shortcode
        $shortcode = sanitize_text_field($post['shortcode'] ?? '');

        // Fallback if no shortcode exists in the array
        if (empty($shortcode)) {
            return '<div class="cdb-ig-card cdb-ig-broken-link"><p>' . esc_html__('Invalid post data.', 'creatordb-ig-feed') . '</p></div>';
        }

        // Construct the native Instagram embed URL
        // Appending /embed/ automatically loads the responsive widget
        $iframe_src = 'https://www.instagram.com/p/' . $shortcode . '/embed/';

        ob_start();
        ?>
        <div class="cdb-ig-card">
            <iframe 
                src="<?php echo esc_url($iframe_src); ?>" 
                class="cdb-ig-iframe"
                width="100%" 
                height="480" 
                frameborder="0" 
                scrolling="no" 
                allowtransparency="true"
                allow="encrypted-media"
                style="background: white; border: none; border-radius: 8px; overflow: hidden; width: 100%; max-width: 100%;">
            </iframe>
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