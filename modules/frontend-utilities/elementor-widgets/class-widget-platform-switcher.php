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

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_control( 'icon_size', [
            'label'      => esc_html__( 'Icon Size', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 8, 'max' => 60 ] ],
            'description' => esc_html__( 'Leave empty to use the default size.', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_size', [
            'label'      => esc_html__( 'Text Size', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 8, 'max' => 40 ] ],
            'description' => esc_html__( 'Leave empty to use the default size.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings  = $this->get_settings_for_display();
        $icon_size = isset( $settings['icon_size']['size'] ) ? (int) $settings['icon_size']['size'] : 0;
        $text_size = isset( $settings['text_size']['size'] ) ? (int) $settings['text_size']['size'] : 0;

        $attrs = '';
        if ( $icon_size > 0 ) {
            $attrs .= ' icon_size="' . $icon_size . '"';
        }
        if ( $text_size > 0 ) {
            $attrs .= ' text_size="' . $text_size . '"';
        }

        echo do_shortcode( '[platform_switcher' . $attrs . ']' );
    }
}
