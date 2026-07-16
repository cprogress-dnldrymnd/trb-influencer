<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Platform_Icon extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_platform_icon'; }
    public function get_title()      { return esc_html__( 'Platform Icon', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-image'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [platform_icon]. Shows the current platform logo for the influencer and swaps it live when the page platform switcher changes. Color inherits from the surrounding text color.', 'trb-influencer' ),
        ] );
        $this->add_control( 'size', [
            'label'       => esc_html__( 'Icon Size (px)', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'min'         => 8,
            'max'         => 200,
            'description' => esc_html__( 'Leave empty to inherit the surrounding font size.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $size     = isset( $settings['size'] ) ? (int) $settings['size'] : 0;
        $attr     = $size > 0 ? ' size="' . $size . '"' : '';
        echo do_shortcode( '[platform_icon' . $attr . ']' );
    }
}
