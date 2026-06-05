<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Influencer_Topics extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_influencer_topics'; }
    public function get_title()      { return esc_html__( 'Influencer Topics (Chips)', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-tags'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [influencer_topics]. Displays all topic taxonomy terms for the current influencer as styled chip elements.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[influencer_topics]' );
    }
}
