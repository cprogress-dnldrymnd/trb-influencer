<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Influencer_Search_Results_Widget extends \Elementor\Widget_Base {

    public function get_name()       { return 'influencer_search_results'; }
    public function get_title()      { return esc_html__( 'Influencer Search Results Grid', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-posts-grid'; }
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
