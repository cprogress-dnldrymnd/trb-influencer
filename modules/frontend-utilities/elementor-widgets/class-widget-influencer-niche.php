<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Influencer_Niche extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_influencer_niche'; }
    public function get_title()      { return esc_html__( 'Influencer Niche (Condensed)', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-bullet-list'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [influencer_niche]. Displays the first 3 niche terms for the current influencer with a "+X more" toggle for additional terms.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[influencer_niche]' );
    }
}
