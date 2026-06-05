<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Shortcode ─────────────────────────────────────────────────────────────────

function dd_shortcode_influencer_search_results( $atts = [] ) {
    ob_start();
    ?>
    <div class="influencer-grid-box">
        <div id="my-loop-grid-container" class="influencer-loop-grid"></div>
        <div class="loading-animation" style="display: none;">
            <span class="loading-icon">
                <img src="https://influencer.theprogressteam.com/wp-content/uploads/2026/01/Spin@1x-1.0s-200px-200px.svg" alt="Loading...">
            </span>
        </div>
    </div>
    <div class="load-more-wrapper">
        <button id="load-more-influencers" class="elementor-button" style="display: none;">
            Load More
        </button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'influencer_search_results', 'dd_shortcode_influencer_search_results' );

// ── Elementor widget ──────────────────────────────────────────────────────────

class Influencer_Search_Results_Widget extends \Elementor\Widget_Base {

    public function get_name()       { return 'influencer_search_results'; }
    public function get_title()      { return esc_html__( 'Influencer Search Results Grid', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-gallery-grid'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [influencer_search_results]. Outputs the AJAX-powered influencer grid and load-more button.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[influencer_search_results]' );
    }
}
