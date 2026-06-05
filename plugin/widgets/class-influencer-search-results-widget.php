<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Influencer_Search_Results_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'influencer_search_results';
    }

    public function get_title() {
        return __( 'Influencer Search Results Grid', 'elementor-child' );
    }

    public function get_icon() {
        return 'eicon-gallery-grid';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    protected function register_controls() {
        // You can add empty state text or loading spinner URL overrides here if desired
    }

    protected function render() {
        ?>
        <div class="influencer-grid-box">
            <div id="my-loop-grid-container" class="influencer-loop-grid">
                </div>

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
    }
}