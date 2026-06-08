<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Influencer_Unlocked_Badge extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_influencer_unlocked_badge'; }
    public function get_title()      { return esc_html__( 'Influencer Unlocked Badge', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-lock-user'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [influencer_unlocked_badge]. Displays an "Unlocked" badge when the current user has unlocked this influencer; renders nothing when locked.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[influencer_unlocked_badge]' );
    }
}
