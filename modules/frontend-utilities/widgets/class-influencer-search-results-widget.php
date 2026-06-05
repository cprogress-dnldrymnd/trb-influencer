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
        echo dd_shortcode_influencer_search_results();
    }
}