<?php

/**
 * Plugin Name: Recent Media Feed
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Renders an influencer's recent content per platform (Instagram / YouTube / TikTok)
 *              via the [platform_recent_media] shortcode, reacting to [platform_switcher].
 * Version: 2.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: creatordb-ig-feed
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class DD_Recent_Media_Feed
 *
 * Renders the "Recent Content" feed for any platform the influencer has media for, reading through
 * trb_platform_recent_media() (never raw meta / icdh_* directly).
 *
 * Reactivity piggybacks on the existing ddPlatformSwitcher controller in charts.php rather than
 * adding a second one: with no platform= attr the shortcode emits one .dd-platform-panel per
 * available platform, and set() already toggles those. That keeps card rendering in PHP — there is
 * no JS copy of the markup to drift out of sync.
 *
 * Each platform gets the embed its data can actually support:
 *   - Instagram: native blockquote + embed.js (unchanged).
 *   - TikTok:    native blockquote + embed.js — the normalized rows carry no thumbnail, and the raw
 *                IC media URLs are expiring CDN links, so the embed is the only reliable render.
 *   - YouTube:   a thumbnail card off i.ytimg.com (an iframe per card would be far too heavy).
 */
class DD_Recent_Media_Feed
{
    /** Cards rendered per page, initially and per Load More batch. */
    private const ITEMS_PER_PAGE = 4;

    /** Memoized no-data fallback markup — up to three panels can embed the same block per request. */
    private $no_data_fallback_html = null;

    /**
     * Constructor.
     * Initializes the plugin by hooking into WordPress core actions for shortcodes, scripts, and AJAX.
     */
    public function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts_and_styles']);

        // Register AJAX endpoints for logged-in and guest users
        add_action('wp_ajax_load_more_platform_media', [$this, 'ajax_load_more']);
        add_action('wp_ajax_nopriv_load_more_platform_media', [$this, 'ajax_load_more']);
    }

    /**
     * Registers the shortcodes for the plugin.
     * @return void
     */
    public function register_shortcodes(): void
    {
        add_shortcode('platform_recent_media', [$this, 'render_feed_shortcode']);
    }

    /**
     * Resolves the influencer post ID. Elementor may not set global $post when rendering a widget
     * outside the main query, so mirror the chart module's resolution order.
     */
    private function resolve_post_id(): int
    {
        $id = get_the_ID();
        if ($id) {
            return (int) $id;
        }

        global $post;
        if ($post instanceof WP_Post) {
            return (int) $post->ID;
        }

        return (int) get_queried_object_id();
    }

    private function render_no_data_fallback(): string
    {
        if ($this->no_data_fallback_html === null) {
            $this->no_data_fallback_html = do_shortcode(
                '[elementor-template id="' . dd_get_template_id('dd_tpl_no_data_fallback', 27230) . '"]'
            );
        }

        return $this->no_data_fallback_html;
    }

    /**
     * Enqueues the per-platform embed scripts, CSS, and inline AJAX logic.
     *
     * Only loads on influencer singles, and only the embed scripts the post's available platforms
     * actually need — Instagram's embed.js used to load on every page of the site.
     *
     * @return void
     */
    public function enqueue_scripts_and_styles(): void
    {
        $post_id = $this->resolve_post_id();
        if ($post_id <= 0 || get_post_type($post_id) !== 'influencer') {
            return;
        }

        $available = function_exists('trb_platforms_available') ? trb_platforms_available($post_id) : [];
        if ($available === []) {
            return;
        }

        if (in_array('instagram', $available, true)) {
            // Meta's embed script converts our blockquotes into correctly sized iframes.
            wp_enqueue_script('instagram-embed', 'https://www.instagram.com/embed.js', [], null, true);
        }
        if (in_array('tiktok', $available, true)) {
            wp_enqueue_script('tiktok-embed', 'https://www.tiktok.com/embed.js', [], null, true);
        }

        // --- CSS Injection ---
        $custom_css = "
            /* Recent media grid container */
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
                position: relative;
            }

            /* Invisible Overlay Anchor to make the entire card clickable */
            .cdb-ig-card-link {
                position: absolute;
                inset: 0; /* Shorthand for top, right, bottom, left: 0 */
                z-index: 10;
                width: 100%;
                height: 100%;
                display: block;
            }

            .cdb-ig-card * {
                font-family: Inter;
            }

            /* Overriding the embed scripts' inline blockquote margins to fit our grid perfectly */
            .cdb-ig-card .instagram-media,
            .cdb-ig-card .tiktok-embed {
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: unset !important;
                border: none !important;
            }

            /* YouTube thumbnail (no iframe — one per card would be far too heavy) */
            .cdb-yt-thumb {
                position: relative;
                width: 100%;
                aspect-ratio: 16 / 9;
                background-color: #000;
                overflow: hidden;
            }

            .cdb-yt-thumb img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }

            .cdb-play-badge {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                display: flex;
                align-items: center;
                justify-content: center;
                width: 56px;
                height: 40px;
                border-radius: 8px;
                background-color: rgba(0, 0, 0, 0.7);
                color: #fff;
                pointer-events: none;
                transition: background-color 0.2s ease;
            }

            .cdb-ig-card:hover .cdb-play-badge {
                background-color: #FF0000;
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
                margin-top: 30px;
            }


            .cdb-ig-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .post-stats {
                display: flex;
                justify-content: space-between;
                gap: 15px;
                width: 100%;
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

            .feed-footer {
                padding: 15px;
                display: flex;
                flex-direction: column;
                gap: 15px;
                position: absolute;
                bottom:0 ;
                left: 0;
                right: 0;
                background-color: #fff;
            }

            /* Instagram's embed is a tall fixed-height iframe, so its footer overlays the bottom of
               the card. YouTube/TikTok cards size to their media, so theirs sits in normal flow. */
            .cdb-ig-card--youtube .feed-footer,
            .cdb-ig-card--tiktok .feed-footer {
                position: static;
                margin-top: auto;
            }

            .feed-footer .date-post-stats {
                padding-top: 15px;
                display: flex;
                justify-content: space-between;
                border-top: 1px solid #BCBCBC;
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .feed-footer .date {
                font-size: 10px;
            }

            .feed-footer .title {
                -webkit-line-clamp: 3;
                -webkit-box-orient: vertical;
                display: -webkit-box;
                overflow: hidden;
                font-size: 12px;
                min-height: 45px;
            }
        ";

        wp_register_style('creatordb-ig-feed-style', false);
        wp_enqueue_style('creatordb-ig-feed-style');
        wp_add_inline_style('creatordb-ig-feed-style', $custom_css);

        // --- JavaScript Injection ---
        // Scoped per .cdb-ig-container: several feeds (one panel per platform) coexist on the page,
        // so nothing here may resolve the button or the grid by a page-unique id.
        $custom_js = "
            document.addEventListener('DOMContentLoaded', function() {
                function ddReprocessEmbeds(platform) {
                    if (platform === 'instagram') {
                        if (typeof window.instgrm !== 'undefined') {
                            window.instgrm.Embeds.process();
                        }
                        return;
                    }
                    if (platform === 'tiktok') {
                        // TikTok's embed.js exposes no public re-scan API (unlike instgrm.Embeds.process);
                        // it only scans on load, so re-inject it to pick up appended blockquotes.
                        var old = document.getElementById('tiktok-embed-js');
                        if (old) { old.parentNode.removeChild(old); }
                        var s = document.createElement('script');
                        s.id = 'tiktok-embed-js';
                        s.src = 'https://www.tiktok.com/embed.js';
                        s.async = true;
                        document.body.appendChild(s);
                    }
                }

                document.querySelectorAll('.cdb-ig-load-more').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var container = btn.closest('.cdb-ig-container');
                        var grid = container ? container.querySelector('.cdb-ig-grid') : null;
                        if (!grid) return;

                        var platform = btn.getAttribute('data-platform');
                        var originalText = btn.innerText;
                        btn.innerText = 'Loading...';
                        btn.disabled = true;

                        var formData = new FormData();
                        formData.append('action', 'load_more_platform_media');
                        formData.append('post_id', btn.getAttribute('data-post-id'));
                        formData.append('platform', platform);
                        formData.append('offset', btn.getAttribute('data-offset'));
                        formData.append('security', btn.getAttribute('data-nonce'));

                        fetch(cdbIgParams.ajaxUrl, {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.success) {
                                grid.insertAdjacentHTML('beforeend', data.data.html);
                                ddReprocessEmbeds(platform);

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
                        .catch(function(error) {
                            console.error('AJAX Error:', error);
                            btn.innerText = originalText;
                            btn.disabled = false;
                        });
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
     * Shortcode callback. With no platform= attr, renders one switcher-reactive panel per available
     * platform; with an explicit platform=, renders just that one (non-reactive).
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The generated HTML.
     */
    public function render_feed_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'platform' => '',
            'id'       => 0,
        ], (array) $atts, 'platform_recent_media');

        $post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : $this->resolve_post_id();
        if ($post_id <= 0) {
            return $this->render_no_data_fallback();
        }

        $requested = is_string($atts['platform']) ? trim($atts['platform']) : '';

        // Explicit platform: a single, non-reactive feed.
        if ($requested !== '' && in_array($requested, ['instagram', 'youtube', 'tiktok'], true)) {
            return $this->generate_feed_html($post_id, $requested);
        }

        $available = function_exists('trb_platforms_available') ? trb_platforms_available($post_id) : [];
        if ($available === []) {
            return $this->render_no_data_fallback();
        }

        $default = function_exists('trb_platform_default') ? trb_platform_default($post_id) : $available[0];

        $out = '';
        foreach ($available as $platform) {
            // Same markup contract as charts.php's [platform_panel]: ddPlatformSwitcher.set() finds
            // these by .dd-platform-panel[data-platform] and toggles display for us.
            $out .= sprintf(
                '<div class="dd-platform-panel" data-platform="%s"%s>%s</div>',
                esc_attr($platform),
                $platform === $default ? '' : ' style="display:none;"',
                $this->generate_feed_html($post_id, $platform)
            );
        }

        return $out;
    }

    /**
     * Generates one platform's feed (grid + Load More), or the no-data fallback when that platform
     * has no renderable media.
     *
     * An available-but-empty platform is routine, not an error: trb_platform_has_data() counts a
     * bare channel/username id as "has the platform", and CreatorDB influencers have no TikTok
     * recent media at all.
     */
    private function generate_feed_html(int $post_id, string $platform): string
    {
        $rows = $this->get_recent_media($post_id, $platform);

        if (empty($rows)) {
            return $this->render_no_data_fallback();
        }

        $total_items   = count($rows);
        $initial_items = array_slice($rows, 0, self::ITEMS_PER_PAGE);
        $has_more      = $total_items > self::ITEMS_PER_PAGE;

        ob_start();
?>
        <div class="cdb-ig-container">
            <div class="cdb-ig-grid">
                <?php foreach ($initial_items as $row) : ?>
                    <?php echo $this->render_single_card($row, $platform); ?>
                <?php endforeach; ?>
            </div>

            <?php if ($has_more) : ?>
                <div class="cdb-ig-load-more-wrapper">
                    <button
                        type="button"
                        class="cdb-ig-btn cdb-ig-load-more load--more-button"
                        data-post-id="<?php echo esc_attr($post_id); ?>"
                        data-platform="<?php echo esc_attr($platform); ?>"
                        data-offset="<?php echo esc_attr(self::ITEMS_PER_PAGE); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('platform_recent_media_nonce')); ?>">
                        <?php esc_html_e('Load More', 'creatordb-ig-feed'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php

        return ob_get_clean();
    }

    /**
     * Handles the AJAX request to load more media for one platform.
     * @return void
     */
    public function ajax_load_more(): void
    {
        check_ajax_referer('platform_recent_media_nonce', 'security');

        $post_id  = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $offset   = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : self::ITEMS_PER_PAGE;
        $platform = isset($_POST['platform']) ? sanitize_key(wp_unslash($_POST['platform'])) : '';

        if (!$post_id) {
            wp_send_json_error('Invalid Post ID');
        }
        if (!in_array($platform, ['instagram', 'youtube', 'tiktok'], true)) {
            wp_send_json_error('Invalid platform');
        }

        $rows         = $this->get_recent_media($post_id, $platform);
        $total_items  = count($rows);
        $sliced_items = array_slice($rows, $offset, self::ITEMS_PER_PAGE);

        $html = '';
        foreach ($sliced_items as $row) {
            $html .= $this->render_single_card($row, $platform);
        }

        $next_offset = $offset + self::ITEMS_PER_PAGE;
        $has_more    = $total_items > $next_offset;

        wp_send_json_success([
            'html'        => $html,
            'next_offset' => $next_offset,
            'has_more'    => $has_more
        ]);
    }

    /**
     * Renders one card, dispatching to the embed strategy that platform's data supports.
     *
     * @param array $row A canonical recent-media row (see trb_normalize_media_row()).
     */
    private function render_single_card(array $row, string $platform): string
    {
        if ($platform === 'youtube') {
            return $this->render_youtube_card($row);
        }
        if ($platform === 'tiktok') {
            return $this->render_tiktok_card($row);
        }

        return $this->render_instagram_card($row);
    }

    /**
     * Instagram card — Meta's native blockquote embed, converted to a sized iframe by embed.js.
     */
    private function render_instagram_card(array $row): string
    {
        $permalink = $row['url'];

        ob_start();
    ?>
        <div class="cdb-ig-card cdb-ig-card--instagram">
            <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener noreferrer" class="cdb-ig-card-link" aria-label="<?php esc_attr_e('View Instagram Post', 'creatordb-ig-feed'); ?>"></a>

            <blockquote class="instagram-media" scrolling="no" data-instgrm-permalink="<?php echo esc_url($permalink); ?>?utm_source=ig_embed&amp;utm_campaign=loading" data-instgrm-version="14" style="background:#FFF; ">
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
            <?php echo $this->render_card_footer($row, 'instagram'); ?>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * TikTok card — TikTok's native blockquote embed. The only viable render: the normalized rows
     * carry no thumbnail field, and the raw IC media URLs are expiring CDN links.
     */
    private function render_tiktok_card(array $row): string
    {
        ob_start();
    ?>
        <div class="cdb-ig-card cdb-ig-card--tiktok">
            <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer" class="cdb-ig-card-link" aria-label="<?php esc_attr_e('View TikTok Post', 'creatordb-ig-feed'); ?>"></a>

            <blockquote class="tiktok-embed" cite="<?php echo esc_url($row['url']); ?>" data-video-id="<?php echo esc_attr($row['id']); ?>" style="max-width: 100%; min-width: 100%;">
                <section></section>
            </blockquote>
            <?php echo $this->render_card_footer($row, 'tiktok'); ?>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * YouTube card — static thumbnail linking out. Deliberately not an iframe: four YouTube iframes
     * per page (plus Load More) would be far too heavy.
     */
    private function render_youtube_card(array $row): string
    {
        $video_id = trb_youtube_video_id_from_row($row);

        ob_start();
    ?>
        <div class="cdb-ig-card cdb-ig-card--youtube">
            <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer" class="cdb-ig-card-link" aria-label="<?php esc_attr_e('View YouTube Video', 'creatordb-ig-feed'); ?>"></a>

            <div class="cdb-yt-thumb">
                <img src="<?php echo esc_url('https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg'); ?>" alt="" loading="lazy" />
                <span class="cdb-play-badge">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M8 5v14l11-7L8 5Z" />
                    </svg>
                </span>
            </div>
            <?php echo $this->render_card_footer($row, 'youtube'); ?>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Shared card footer: title, date, and three stats.
     *
     * The third stat is engagement rate on Instagram but view count on YouTube/TikTok — IC-sourced
     * rows carry engageRate 0, so an ER column there would read "0.00%" on every card. The date is
     * dropped entirely when the row has none (IC's YouTube rows have a null updateDate) rather than
     * formatting epoch 0 into "1970 Jan 1st".
     */
    private function render_card_footer(array $row, string $platform): string
    {
        $has_date = $row['updateDate'] > 0;

        ob_start();
    ?>
        <div class="feed-footer">
            <div class="title">
                <?php echo esc_html($row['title']); ?>
            </div>
            <div class="date-post-stats">
                <?php if ($has_date) : ?>
                    <div class="date">
                        <?php echo esc_html(function_exists('formatNormalizedTimestamp') ? formatNormalizedTimestamp($row['updateDate']) : (string) $row['updateDate']); ?>
                    </div>
                <?php endif; ?>
                <div class="post-stats">
                    <div class="post-stat-item">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                            <path d="M12 8.19444C10 3.5 3 4 3 10C3 16.0001 12 21 12 21C12 21 21 16.0001 21 10C21 4 14 3.5 12 8.19444Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                        <span class="text-sm text-gray-900"><?php echo esc_html(function_exists('wp_custom_number_format_short') ? wp_custom_number_format_short($row['likes']) : $row['likes']); ?></span>
                    </div>
                    <div class="post-stat-item">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                            <path d="M21 14.8V7.19995V7.19666C21 6.07875 21 5.51945 20.7822 5.09204C20.5905 4.71572 20.2841 4.40973 19.9078 4.21799C19.48 4 18.9203 4 17.8002 4H6.2002C5.08009 4 4.51962 4 4.0918 4.21799C3.71547 4.40973 3.40973 4.71572 3.21799 5.09204C3 5.51986 3 6.07985 3 7.19995V18.671C3 19.7367 3 20.2696 3.21846 20.5432C3.40845 20.7813 3.69644 20.9197 4.00098 20.9194C4.35115 20.919 4.76744 20.5861 5.59961 19.9203L7.12357 18.7012C7.44844 18.4413 7.61084 18.3114 7.79172 18.219C7.95219 18.137 8.12279 18.0771 8.29932 18.0408C8.49829 18 8.70652 18 9.12256 18H17.8001C18.9202 18 19.48 18 19.9078 17.782C20.2841 17.5902 20.5905 17.2844 20.7822 16.908C21 16.4806 21 15.9212 21 14.8032V14.8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                        <span class="text-sm text-gray-900"><?php echo esc_html(function_exists('wp_custom_number_format_short') ? wp_custom_number_format_short($row['comments']) : $row['comments']); ?></span>
                    </div>
                    <?php if ($platform === 'instagram') : ?>
                        <div class="post-stat-item">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                                <path d="M21.0002 20H6.2002C5.08009 20 4.51962 20 4.0918 19.782C3.71547 19.5902 3.40973 19.2844 3.21799 18.908C3 18.4802 3 17.9201 3 16.8V5M21 7L15.1543 12.115C14.4542 12.7275 14.1041 13.0339 13.7207 13.161C13.2685 13.311 12.7775 13.2946 12.3363 13.1149C11.9623 12.9625 11.6336 12.6337 10.9758 11.9759C10.3323 11.3324 10.0105 11.0106 9.64355 10.8584C9.21071 10.6788 8.72875 10.6569 8.28142 10.7965C7.90221 10.9149 7.55252 11.2062 6.8534 11.7888L3 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                            <span class="text-sm text-gray-900"><?php echo esc_html(function_exists('convertDecimalToPercentage') ? convertDecimalToPercentage($row['engageRate']) : $row['engageRate']); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="post-stat-item">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="text-gray-300">
                                <path d="M2.036 12.322a1 1 0 0 1 0-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178a1 1 0 0 1 0 .644C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></circle>
                            </svg>
                            <span class="text-sm text-gray-900"><?php echo esc_html(function_exists('wp_custom_number_format_short') ? wp_custom_number_format_short($row['views']) : $row['views']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Retrieves the platform's recent media, dropping rows we cannot render (no permalink, or — on
     * YouTube — no parseable video ID). Filtering here rather than at render time keeps the Load
     * More paging counts honest.
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_recent_media(int $post_id, string $platform): array
    {
        if (!function_exists('trb_platform_recent_media')) {
            return [];
        }

        $rows = trb_platform_recent_media($post_id, $platform);
        if (!is_array($rows)) {
            return [];
        }

        $renderable = [];
        foreach ($rows as $row) {
            if (empty($row['url'])) {
                continue;
            }
            if ($platform === 'tiktok' && empty($row['id'])) {
                continue;
            }
            if ($platform === 'youtube' && trb_youtube_video_id_from_row($row) === '') {
                continue;
            }
            $renderable[] = $row;
        }

        return $renderable;
    }
}

// Initialize the plugin class instance.
new DD_Recent_Media_Feed();
