<?php

/**
 * Plugin Name: CreatorDB Instagram Feed
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: An object-oriented WordPress plugin to render CreatorDB-style Instagram arrays via shortcode using native embed iframes.
 * Version: 1.1.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: creatordb-ig-feed
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class CreatorDB_Instagram_Feed
 * * Handles the initialization, data retrieval, and modular rendering of the 
 * Instagram feed using Meta's native iframe embeds for guaranteed asset delivery.
 */
class CreatorDB_Instagram_Feed
{

    /**
     * Constructor.
     * * Initializes the plugin by hooking into WordPress core actions for shortcodes and scripts.
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
     * * Utilizes wp_add_inline_style to keep the plugin a self-contained single file.
     * Obsolete UI styles have been stripped out; only the structural grid and iframe 
     * container rules are retained for optimal performance.
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
                padding: 20px 0;
            }

            /* Individual Post Card Wrapper */
            .cdb-ig-card {
                display: flex;
                flex-direction: column;
                width: 100%;
            }

            /* Native Iframe Constraints */
            .cdb-ig-iframe {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                overflow: hidden;
                width: 100%;
                max-width: 100%;
                height: 100%;
                min-height: 480px; 
                margin: 0;
                padding: 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }

            /* Empty State Message */
            .cdb-ig-empty {
                padding: 20px;
                text-align: center;
                color: #6b7280;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            }
        ";

        // Register a dummy handle and attach the inline CSS to it
        wp_register_style('creatordb-ig-feed-style', false);
        wp_enqueue_style('creatordb-ig-feed-style');
        wp_add_inline_style('creatordb-ig-feed-style', $custom_css);
    }

    /**
     * Shortcode callback to initialize the Instagram feed rendering.
     * * @param array|string $atts Shortcode attributes.
     * @return string The generated HTML grid containing the iframes.
     */
    public function render_feed_shortcode($atts): string
    {
        $instagram_data = $this->get_instagram_data();
        return $this->generate_feed_html($instagram_data);
    }

    /**
     * Generates the complete HTML wrapper and iterates through post data.
     * * @param array $instagram_data Array of Instagram post arrays.
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
     * * This guarantees media delivery by offloading CDN token management, CORS, 
     * and UI rendering (likes, comments, captions) directly to Meta.
     *
     * @param array $post A single associative array containing post data.
     * @return string The HTML markup for the card.
     */
    private function render_single_card(array $post): string
    {
        // Extract and sanitize the shortcode
        $shortcode = sanitize_text_field($post['shortcode'] ?? '');

        // Fallback if no shortcode exists in the dataset
        if (empty($shortcode)) {
            return '<div class="cdb-ig-card"><p class="cdb-ig-empty">' . esc_html__('Invalid post data.', 'creatordb-ig-feed') . '</p></div>';
        }

        // Construct the native Instagram embed URL. 
        // Appending /embed/ automatically triggers Instagram's responsive iframe widget.
        $iframe_src = 'https://www.instagram.com/p/' . $shortcode . '/embed/';

        ob_start();
        ?>
        <div class="cdb-ig-card">
            <iframe 
                src="<?php echo esc_url($iframe_src); ?>" 
                class="cdb-ig-iframe"
                frameborder="0" 
                scrolling="no" 
                allowtransparency="true"
                allow="encrypted-media">
            </iframe>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Retrieves the stored Instagram data array from post meta.
     * * @return array The array of Instagram post data, or an empty array if not found.
     */
    private function get_instagram_data(): array
    {
        // Fetches the 'recentposts' meta from the current post context
        $recentposts = get_post_meta(get_the_ID(), 'recentposts', true);
        return is_array($recentposts) ? $recentposts : [];
    }
}

// Initialize the plugin class instance.
new CreatorDB_Instagram_Feed();