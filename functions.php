<?php

/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('HELLO_ELEMENTOR_CHILD_VERSION', '2.0.2');

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles()
{
    global $search_results_page_id;

    $page_id = get_the_ID();
    $js_dir  = get_stylesheet_directory_uri() . '/assets/js';
    $version = HELLO_ELEMENTOR_CHILD_VERSION;

    wp_enqueue_style('influencer-style', get_stylesheet_directory_uri() . '/style.css');
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);

    // ------------------------------------------------------------------
    // 1. Module files — each attaches helpers to window.InfluencerApp.
    //    Order matters: dependencies must be registered before their consumers.
    // ------------------------------------------------------------------
    $modules = [
        'inf-tag-prioritizer'  => 'modules/tag-prioritizer.js',   // no deps on other modules
        'inf-ui-utils'         => 'modules/ui-utils.js',           // no deps on other modules
        'inf-search-toggle'    => 'modules/search-toggle.js',      // no deps on other modules
        'inf-filter-chips'     => 'modules/filter-chips.js',       // no deps on other modules
        'inf-filter-validation' => 'modules/filter-validation.js',  // no deps on other modules
        'inf-filter-dropdowns' => 'modules/filter-dropdowns.js',   // uses sync_follower_min_max_states (self-contained)
        'inf-search-fetch'     => 'modules/search-fetch.js',       // uses prioritize_active_tags → needs tag-prioritizer
    ];

    $prev_handle = 'jquery'; // first handle in the dependency chain

    foreach ($modules as $handle => $path) {
        wp_enqueue_script(
            $handle,
            $js_dir . '/' . $path,
            [$prev_handle],
            $version,
            true
        );
        $prev_handle = $handle; // each module depends on the previous one to guarantee load order
    }

    // ------------------------------------------------------------------
    // 2. Main orchestrator — must load last.
    // ------------------------------------------------------------------
    wp_enqueue_script(
        'influencer-js',
        $js_dir . '/main.js',
        [$prev_handle],   // depends on the last module (inf-search-fetch)
        $version,
        true
    );

    // ------------------------------------------------------------------
    // 3. Localise ajax_vars onto the orchestrator handle so every module
    //    and main.js can reference it via the global ajax_vars object.
    // ------------------------------------------------------------------
    wp_localize_script('influencer-js', 'ajax_vars', [
        'ajax_url'              => admin_url('admin-ajax.php'),
        'page_id'               => $page_id,
        'search_results_page_id' => $search_results_page_id,
        'save_search_nonce'     => wp_create_nonce('save_search_nonce'),
        'save_influencer_nonce' => wp_create_nonce('save_influencer_nonce'),
        'search_filter_nonce'   => wp_create_nonce('search_filter_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20);




// Resolve and cache the directory path once.
// NOTE: Change to get_stylesheet_directory() if this is a child theme.
$dir = get_stylesheet_directory();

// Direct, unrolled require statements. 
// This is the fastest execution path in PHP for procedural files.

// 1. Core Includes (Load foundational dependencies first)
require $dir . '/includes/core/helpers.php';
require $dir . '/includes/core/custom-functions.php';
require $dir . '/includes/core/admin-settings.php';
require $dir . '/includes/core/hooks.php';
require $dir . '/includes/core/shortcodes.php';
// 2. Third-Party Integrations (Base handshakes and bridges)
require $dir . '/includes/integrations/acf.php';
require $dir . '/includes/integrations/dompdf.php';
require $dir . '/includes/integrations/elementor.php';
require $dir . '/modules/frontend-utilities/elementor-widgets/register.php';
require $dir . '/includes/integrations/mycred.php';
require $dir . '/includes/integrations/pmpro.php';

// 3. Domain Modules (Self-contained features)
require $dir . '/modules/email-manager/email-template-manager.php';

require $dir . '/modules/frontend-utilities/charts.php';
require $dir . '/modules/frontend-utilities/feeds.php';
require $dir . '/modules/frontend-utilities/search.php';

require $dir . '/modules/outreach/outreach.php';

require $dir . '/modules/membership-extensions/pmpro-sign-up.php';
require $dir . '/modules/membership-extensions/pmpro-dynamic-pricing.php';
require $dir . '/modules/membership-extensions/pmpro-mycred-rewards-manager.php';
require $dir . '/modules/membership-extensions/pmpro-trial-protection.php';

require $dir . '/modules/mycred-components/mycred-frontend-log.php';
require $dir . '/modules/saves/saves-manager.php';


function influencers_meta()
{
    ob_start();
?>
    <pre>
    <?php var_dump(get_post_meta(get_the_ID())); ?>
</pre>
<?php
    return ob_get_clean();
}
add_shortcode('influencers_meta', 'influencers_meta');



/**
 * Remove the default WordPress shutdown buffer flush action.
 *
 * This snippet unhooks 'wp_ob_end_flush_all' from the 'shutdown' action.
 * It is primarily used to suppress "Failed to send buffer" errors in 
 * specific server configurations or when custom output buffering is required.
 *
 * @return void
 */
add_action('init', function () {
    remove_action('shutdown', 'wp_ob_end_flush_all', 1);
});
