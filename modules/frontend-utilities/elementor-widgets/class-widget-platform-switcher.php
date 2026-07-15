<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Platform_Switcher extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_platform_switcher'; }
    public function get_title()      { return esc_html__( 'Platform Switcher', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-social-icons'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [platform_switcher]. Shows an Instagram/YouTube/TikTok button list for the current influencer — only for platforms with data — and drives every chart and [platform_panel] on the page when clicked. Place in the profile header.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[platform_switcher]' );
    }
}
