<?php

/**
 * Plugin Name: CreatorDB Instagram Feed
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: An object-oriented WordPress plugin to render CreatorDB-style Instagram arrays via shortcode using native embed iframes with Load More functionality.
 * Version: 1.2.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: creatordb-ig-feed
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class CreatorDB_Instagram_Feed
 * Handles the initialization, data retrieval, AJAX pagination, and modular rendering 
 * of the Instagram feed using Meta's native iframe embeds.
 */
class CreatorDB_Instagram_Feed
{

    /**
     * Constructor.
     * Initializes the plugin by hooking into WordPress core actions for shortcodes, scripts, and AJAX.
     */
    public function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts_and_styles']);
        
        // Register AJAX endpoints for logged-in and guest users
        add_action('wp_ajax_load_more_creatordb_ig', [$this, 'ajax_load_more']);
        add_action('wp_ajax_nopriv_load_more_creatordb_ig', [$this, 'ajax_load_more']);
    }

    /**
     * Registers the shortcodes for the plugin.
     * @return void
     */
    public function register_shortcodes(): void
    {
        add_shortcode('creatordb_feed', [$this, 'render_feed_shortcode']);
    }

    /**
     * Enqueues the necessary CSS and JavaScript for the Instagram feed layout and Load More functionality.
     * Utilizes wp_add_inline_style and wp_add_inline_script to keep the plugin self-contained.
     * @return void
     */
    public function enqueue_scripts_and_styles(): void
    {
        // --- CSS Injection ---
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

            /* Load More Button Wrapper and Styling */
            .cdb-ig-load-more-wrapper {
                text-align: center;
                margin-top: 20px;
                margin-bottom: 40px;
            }

            .cdb-ig-btn {
                background-color: #ff8a65; /* Matching the peach button from your screenshot */
                color: #ffffff;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                font-size: 14px;
                font-weight: 600;
                padding: 10px 24px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.2s, opacity 0.2s;
            }

            .cdb-ig-btn:hover {
                background-color: #ff7043;
            }

            .cdb-ig-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
        ";

        wp_register_style('creatordb-ig-feed-style', false);
        wp_enqueue_style('creatordb-ig-feed-style');
        wp_add_inline_style('creatordb-ig-feed-style', $custom_css);

        // --- JavaScript Injection ---
        $custom_js = "
            document.addEventListener('DOMContentLoaded', function() {
                const loadMoreBtn = document.getElementById('cdb-ig-load-more');
                if (!loadMoreBtn) return;

                loadMoreBtn.addEventListener('click', function() {
                    const btn = this;
                    const postId = btn.getAttribute('data-post-id');
                    const offset = parseInt(btn.getAttribute('data-offset'), 10);
                    const nonce = btn.getAttribute('data-nonce');
                    
                    // Set loading state
                    const originalText = btn.innerText;
                    btn.innerText = 'Loading...';
                    btn.disabled = true;

                    // Prepare FormData for the WP AJAX endpoint
                    const formData = new FormData();
                    formData.append('action', 'load_more_creatordb_ig');
                    formData.append('post_id', postId);
                    formData.append('offset', offset);
                    formData.append('security', nonce);

                    fetch(cdbIgParams.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Inject new iframes into the grid
                            const grid = document.querySelector('.cdb-ig-grid');
                            grid.insertAdjacentHTML('beforeend', data.data.html);
                            
                            // Update pagination state or remove button
                            if (data.data.has_more) {
                                btn.setAttribute('data-offset', data.data.next_offset);
                                btn.innerText = originalText;
                                btn.disabled = false;
                            } else {
                                btn.parentElement.remove();
                            }
                        } else {
                            btn.innerText = 'Error loading posts';
                            console.error(data.data);
                        }
                    })
                    .catch(error => {
                        console.error('AJAX Error:', error);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    });
                });
            });
        ";

        wp_register_script('creatordb-ig-feed-script', false, [], false, true);
        wp_enqueue_script('creatordb-ig-feed-script');
        
        // Pass the AJAX URL to our frontend script securely
        wp_localize_script('creatordb-ig-feed-script', 'cdbIgParams', [
            'ajaxUrl' => admin_url('admin-ajax.php')
        ]);
        
        wp_add_inline_script('creatordb-ig-feed-script', $custom_js);
    }

    /**
     * Shortcode callback to initialize the Instagram feed rendering.
     * @param array|string $atts Shortcode attributes.
     * @return string The generated HTML grid containing the iframes.
     */
    public function render_feed_shortcode($atts): string
    {
        $post_id = get_the_ID();
        $instagram_data = $this->get_instagram_data($post_id);
        
        return $this->generate_feed_html($instagram_data, $post_id);
    }

    /**
     * Generates the complete HTML wrapper, iterates through initial post data, 
     * and outputs the Load More button if applicable.
     * * @param array $instagram_data Array of Instagram post arrays.
     * @param int|false $post_id The ID of the current post (to fetch meta during AJAX).
     * @return string The compiled HTML string.
     */
    private function generate_feed_html(array $instagram_data, $post_id): string
    {
        if (empty($instagram_data)) {
            return '<p class="cdb-ig-empty">' . esc_html__('No recent content available.', 'creatordb-ig-feed') . '</p>';
        }

        $items_per_page = 4;
        $total_items    = count($instagram_data);
        $initial_items  = array_slice($instagram_data, 0, $items_per_page);
        $has_more       = $total_items > $items_per_page;

        ob_start();
        ?>
        <div class="cdb-ig-container">
            <div class="cdb-ig-grid">
                <?php foreach ($initial_items as $post) : ?>
                    <?php echo $this->render_single_card($post); ?>
                <?php endforeach; ?>
            </div>
            
            <?php if ($has_more) : ?>
                <div class="cdb-ig-load-more-wrapper">
                    <button 
                        id="cdb-ig-load-more" 
                        class="cdb-ig-btn" 
                        data-post-id="<?php echo esc_attr($post_id); ?>" 
                        data-offset="<?php echo esc_attr($items_per_page); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('cdb_ig_load_more_nonce')); ?>">
                        <?php esc_html_e('Load More', 'creatordb-ig-feed'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Handles the AJAX request to load more Instagram iframes.
     * Validates the nonce, slices the stored array based on the requested offset, 
     * and returns a JSON payload containing the HTML payload and pagination state.
     * * @return void
     */
    public function ajax_load_more(): void
    {
        check_ajax_referer('cdb_ig_load_more_nonce', 'security');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $offset  = isset($_POST['offset']) ? intval($_POST['offset']) : 4;
        $limit   = 4;

        if (!$post_id) {
            wp_send_json_error('Invalid Post ID');
        }

        $instagram_data = $this->get_instagram_data($post_id);
        $total_items    = count($instagram_data);
        $sliced_items   = array_slice($instagram_data, $offset, $limit);

        $html = '';
        foreach ($sliced_items as $post) {
            $html .= $this->render_single_card($post);
        }

        $next_offset = $offset + $limit;
        $has_more    = $total_items > $next_offset;

        wp_send_json_success([
            'html'        => $html,
            'next_offset' => $next_offset,
            'has_more'    => $has_more
        ]);
    }

    /**
     * Renders the HTML markup for a single Instagram card using the native embed iframe.
     * Guarantees media delivery by offloading CDN token management to Meta.
     *
     * @param array $post A single associative array containing post data.
     * @return string The HTML markup for the card.
     */
    private function render_single_card(array $post): string
    {
        $shortcode = sanitize_text_field($post['shortcode'] ?? '');

        if (empty($shortcode)) {
            return '<div class="cdb-ig-card"><p class="cdb-ig-empty">' . esc_html__('Invalid post data.', 'creatordb-ig-feed') . '</p></div>';
        }

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
     * * @param int $post_id The ID of the post where the meta is stored.
     * @return array The array of Instagram post data, or an empty array if not found.
     */
    private function get_instagram_data(int $post_id): array
    {
        $recentposts = get_post_meta($post_id, 'recentposts', true);
        return is_array($recentposts) ? $recentposts : [];
    }
}

// Initialize the plugin class instance.
new CreatorDB_Instagram_Feed();