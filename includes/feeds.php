<?php

/**
 * Plugin Name: CreatorDB Instagram Feed
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: An object-oriented WordPress plugin to render CreatorDB-style Instagram arrays via shortcode using native embed blockquotes with dynamic height and Load More functionality.
 * Version: 1.3.0
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
 * of the Instagram feed using Meta's official blockquote embeds for dynamic sizing.
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
     * Enqueues the necessary CSS, Instagram's embed.js, and inline AJAX logic.
     * @return void
     */
    public function enqueue_scripts_and_styles(): void
    {
        // Enqueue official Instagram Embed script for dynamic iframe resizing
        wp_enqueue_script('instagram-embed', 'https://www.instagram.com/embed.js', [], null, true);

        // --- CSS Injection ---
        $custom_css = "
            /* Instagram Feed Grid Container */
            .cdb-ig-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 20px;
                align-items: start; /* Prevents stretching cards vertically if heights differ */
            }

            /* Individual Post Card Wrapper */
            .cdb-ig-card {
                display: flex;
                flex-direction: column;
                width: 100%;
                background: transparent;
                height: 100%;
                background-color: #ffffff;
                border: 1px solid #BCBCBC;
                border-radius: 10px;
                overflow: hidden;
            }

            .cdb-ig-card * {
                font-family: Inter;
            }
            /* Overriding Instagram's inline blockquote margins to fit our grid perfectly */
            .cdb-ig-card .instagram-media {
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: unset !important;
                border: none !important;
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
                background-color: #ff8a65; 
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
            .post-stats {
                display: flex;
                justify-content: space-between;
                gap: 15px;
                padding: 10px 15px;
            }
            .post-stats .post-stat-item {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
            }
            .post-stats .post-stat-item svg{
                color: #BCBCBC;
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
                    
                    const originalText = btn.innerText;
                    btn.innerText = 'Loading...';
                    btn.disabled = true;

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
                            const grid = document.querySelector('.cdb-ig-grid');
                            grid.insertAdjacentHTML('beforeend', data.data.html);
                            
                            // Re-process the new DOM elements so Instagram script converts the new blockquotes to sized iframes
                            if (typeof window.instgrm !== 'undefined') {
                                window.instgrm.Embeds.process();
                            }

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

        wp_localize_script('creatordb-ig-feed-script', 'cdbIgParams', [
            'ajaxUrl' => admin_url('admin-ajax.php')
        ]);

        wp_add_inline_script('creatordb-ig-feed-script', $custom_js);
    }

    /**
     * Shortcode callback to initialize the Instagram feed rendering.
     * @param array|string $atts Shortcode attributes.
     * @return string The generated HTML.
     */
    public function render_feed_shortcode($atts): string
    {
        $post_id = get_the_ID();
        $instagram_data = $this->get_instagram_data($post_id);

        return $this->generate_feed_html($instagram_data, $post_id);
    }

    /**
     * Generates the complete HTML wrapper.
     * @param array $instagram_data Array of Instagram post arrays.
     * @param int|false $post_id The ID of the current post.
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
     * Handles the AJAX request to load more posts.
     * @return void
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
     * Renders the HTML markup for a single Instagram card using the native blockquote embed.
     * This structure allows Instagram's embed.js to calculate and apply the correct iframe height.
     *
     * @param array $post A single associative array containing post data.
     * @return string The HTML markup for the card.
     */
    private function render_single_card(array $post): string
    {
        $shortcode = sanitize_text_field($post['shortcode'] ?? '');
        $updateDate = sanitize_text_field($post['updateDate'] ?? '');
        if (empty($shortcode)) {
            return '<div class="cdb-ig-card"><p class="cdb-ig-empty">' . esc_html__('Invalid post data.', 'creatordb-ig-feed') . '</p></div>';
        }

        $permalink = 'https://www.instagram.com/p/' . $shortcode . '/';

        ob_start();
    ?>
        <div class="cdb-ig-card">
            <blockquote class="instagram-media" data-instgrm-permalink="<?php echo esc_url($permalink); ?>?utm_source=ig_embed&amp;utm_campaign=loading" data-instgrm-version="14" style="background:#FFF; ">
                <div style="padding:16px;">
                    <a href="<?php echo esc_url($permalink); ?>?utm_source=ig_embed&amp;utm_campaign=loading" style="background:#FFFFFF; line-height:0; padding:0 0; text-align:center; text-decoration:none; width:100%;" target="_blank">
                        <div style="display: flex; flex-direction: row; align-items: center;">
                            <div style="background-color: #F4F4F4; border-radius: 50%; flex-grow: 0; height: 40px; margin-right: 14px; width: 40px;"></div>
                            <div style="display: flex; flex-direction: column; flex-grow: 1; justify-content: center;">
                                <div style="background-color: #F4F4F4; border-radius: 4px; height: 14px; margin-bottom: 6px; width: 100px;"></div>
                                <div style="background-color: #F4F4F4; border-radius: 4px; height: 14px; width: 60px;"></div>
                            </div>
                        </div>
                        <div style="padding: 19% 0;"></div>
                        <div style="display:block; height:50px; margin:0 auto 12px; width:50px;">
                            <svg width="50px" height="50px" viewBox="0 0 60 60" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                    <g transform="translate(-511.000000, -20.000000)" fill="#000000">
                                        <g>
                                            <path d="M556.869,30.41 C554.814,30.41 553.148,32.076 553.148,34.131 C553.148,36.186 554.814,37.852 556.869,37.852 C558.924,37.852 560.59,36.186 560.59,34.131 C560.59,32.076 558.924,30.41 556.869,30.41 M541,60.657 C535.114,60.657 530.342,55.887 530.342,50 C530.342,44.114 535.114,39.342 541,39.342 C546.887,39.342 551.658,44.114 551.658,50 C551.658,55.887 546.887,60.657 541,60.657 M541,33.886 C532.1,33.886 524.886,41.1 524.886,50 C524.886,58.899 532.1,66.113 541,66.113 C549.9,66.113 557.115,58.899 557.115,50 C557.115,41.1 549.9,33.886 541,33.886 M565.378,62.101 C565.244,65.022 564.756,66.606 564.346,67.663 C563.803,69.06 563.154,70.057 562.106,71.106 C561.058,72.155 560.06,72.803 558.662,73.347 C557.607,73.757 556.021,74.244 553.102,74.378 C549.944,74.521 548.997,74.552 541,74.552 C533.003,74.552 532.056,74.521 528.898,74.378 C525.979,74.244 524.393,73.757 523.338,73.347 C521.94,72.803 520.942,72.155 519.894,71.106 C518.846,70.057 518.197,69.06 517.654,67.663 C517.244,66.606 516.755,65.022 516.623,62.101 C516.479,58.943 516.448,57.996 516.448,50 C516.448,42.003 516.479,41.056 516.623,37.899 C516.755,34.978 517.244,33.391 517.654,32.338 C518.197,30.938 518.846,29.942 519.894,28.894 C520.942,27.846 521.94,27.196 523.338,26.654 C524.393,26.244 525.979,25.756 528.898,25.623 C532.057,25.479 533.004,25.448 541,25.448 C548.997,25.448 549.943,25.479 553.102,25.623 C556.021,25.756 557.607,26.244 558.662,26.654 C560.06,27.196 561.058,27.846 562.106,28.894 C563.154,29.942 563.803,30.938 564.346,32.338 C564.756,33.391 565.244,34.978 565.378,37.899 C565.522,41.056 565.552,42.003 565.552,50 C565.552,57.996 565.522,58.943 565.378,62.101 M570.82,37.631 C570.674,34.438 570.167,32.258 569.425,30.349 C568.659,28.377 567.633,26.702 565.965,25.035 C564.297,23.368 562.623,22.342 560.652,21.575 C558.743,20.834 556.562,20.326 553.369,20.18 C550.169,20.033 549.148,20 541,20 C532.853,20 531.831,20.033 528.631,20.18 C525.438,20.326 523.257,20.834 521.349,21.575 C519.376,22.342 517.703,23.368 516.035,25.035 C514.368,26.702 513.342,28.377 512.574,30.349 C511.834,32.258 511.326,34.438 511.181,37.631 C511.035,40.831 511,41.851 511,50 C511,58.147 511.035,59.17 511.181,62.369 C511.326,65.562 511.834,67.743 512.574,69.651 C513.342,71.625 514.368,73.296 516.035,74.965 C517.703,76.634 519.376,77.658 521.349,78.425 C523.257,79.167 525.438,79.673 528.631,79.82 C531.831,79.965 532.853,80.001 541,80.001 C549.148,80.001 550.169,79.965 553.369,79.82 C556.562,79.673 558.743,79.167 560.652,78.425 C562.623,77.658 564.297,76.634 565.965,74.965 C567.633,73.296 568.659,71.625 569.425,69.651 C570.167,67.743 570.674,65.562 570.82,62.369 C570.966,59.17 571,58.147 571,50 C571,41.851 570.966,40.831 570.82,37.631"></path>
                                        </g>
                                    </g>
                                </g>
                            </svg>
                        </div>
                        <div style="padding-top: 8px;">
                            <div style="color:#3897f0; font-family:Arial,sans-serif; font-size:14px; font-style:normal; font-weight:550; line-height:18px;">View this post on Instagram</div>
                        </div>
                    </a>
                </div>
            </blockquote>
            <div class="feed-footer">
                <div class="date">
                    <?= formatTimestampToOrdinalDate($updateDate) ?>
                </div>
                <div class="post-stats">
                    <div class="post-stat-item">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                            <path d="M12 8.19444C10 3.5 3 4 3 10C3 16.0001 12 21 12 21C12 21 21 16.0001 21 10C21 4 14 3.5 12 8.19444Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                        <span class="text-sm text-gray-900">3</span>
                    </div>
                    <div class="post-stat-item">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                            <path d="M21 14.8V7.19995V7.19666C21 6.07875 21 5.51945 20.7822 5.09204C20.5905 4.71572 20.2841 4.40973 19.9078 4.21799C19.48 4 18.9203 4 17.8002 4H6.2002C5.08009 4 4.51962 4 4.0918 4.21799C3.71547 4.40973 3.40973 4.71572 3.21799 5.09204C3 5.51986 3 6.07985 3 7.19995V18.671C3 19.7367 3 20.2696 3.21846 20.5432C3.40845 20.7813 3.69644 20.9197 4.00098 20.9194C4.35115 20.919 4.76744 20.5861 5.59961 19.9203L7.12357 18.7012C7.44844 18.4413 7.61084 18.3114 7.79172 18.219C7.95219 18.137 8.12279 18.0771 8.29932 18.0408C8.49829 18 8.70652 18 9.12256 18H17.8001C18.9202 18 19.48 18 19.9078 17.782C20.2841 17.5902 20.5905 17.2844 20.7822 16.908C21 16.4806 21 15.9212 21 14.8032V14.8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                        <span class="text-sm text-gray-900">27</span>
                    </div>
                    <div class="post-stat-item">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                            <path d="M21.0002 20H6.2002C5.08009 20 4.51962 20 4.0918 19.782C3.71547 19.5902 3.40973 19.2844 3.21799 18.908C3 18.4802 3 17.9201 3 16.8V5M21 7L15.1543 12.115C14.4542 12.7275 14.1041 13.0339 13.7207 13.161C13.2685 13.311 12.7775 13.2946 12.3363 13.1149C11.9623 12.9625 11.6336 12.6337 10.9758 11.9759C10.3323 11.3324 10.0105 11.0106 9.64355 10.8584C9.21071 10.6788 8.72875 10.6569 8.28142 10.7965C7.90221 10.9149 7.55252 11.2062 6.8534 11.7888L3 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                        <span class="text-sm text-gray-900">0.16%</span>
                    </div>
                </div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Retrieves the stored Instagram data array from post meta.
     * @param int $post_id The ID of the post.
     * @return array The array of Instagram post data.
     */
    private function get_instagram_data(int $post_id): array
    {
        $recentposts = get_post_meta($post_id, 'recentposts', true);
        return is_array($recentposts) ? $recentposts : [];
    }
}

// Initialize the plugin class instance.
new CreatorDB_Instagram_Feed();
