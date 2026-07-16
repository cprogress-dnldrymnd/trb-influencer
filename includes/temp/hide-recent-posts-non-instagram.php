<?php

/**
 * TEMPORARY — REMOVE ME.
 *
 * Hides #content-recent-posts whenever the active platform is anything other than
 * Instagram. The Recent Content feed is Instagram-only for now because the ICDH
 * content/posts endpoint is still gated for YouTube/TikTok; once that ships, this
 * file should be deleted along with its require in functions.php.
 *
 * Piggybacks on the ddPlatformSwitcher controller in
 * modules/frontend-utilities/charts.php (register() replays the current platform
 * immediately, so no separate first-paint pass is needed).
 *
 * @package HelloElementorChild
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', function () {
    if (!wp_script_is('apexcharts', 'enqueued') && !wp_script_is('apexcharts', 'done')) {
        return;
    }
    ?>
    <script id="dd-temp-hide-recent-posts">
        (function () {
            function ddTempToggleRecentPosts(platform) {
                var el = document.getElementById('content-recent-posts');
                if (!el) return;
                el.style.display = (platform === 'instagram') ? '' : 'none';
            }
            if (window.ddPlatformSwitcher) {
                ddPlatformSwitcher.register(ddTempToggleRecentPosts);
            }
        })();
    </script>
    <?php
}, 99);
