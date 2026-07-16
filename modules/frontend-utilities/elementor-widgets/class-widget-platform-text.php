<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Platform_Text extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_platform_text'; }
    public function get_title()      { return esc_html__( 'Platform Text', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-t-letter'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [platform_text]. Shows the platform logo + name (e.g. "Instagram Overview") for the current influencer. The logo and name swap live when the page platform switcher changes; the prefix/suffix stay put.', 'trb-influencer' ),
        ] );
        $this->add_control( 'prefix', [
            'label'   => esc_html__( 'Prefix', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ] );
        $this->add_control( 'suffix', [
            'label'   => esc_html__( 'Suffix', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Overview',
        ] );
        $this->add_control( 'icon', [
            'label'   => esc_html__( 'Show Logo', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'yes',
            'options' => [
                'yes' => esc_html__( 'Yes', 'trb-influencer' ),
                'no'  => esc_html__( 'No', 'trb-influencer' ),
            ],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $prefix   = $settings['prefix'] ?? '';
        $suffix   = isset( $settings['suffix'] ) ? $settings['suffix'] : 'Overview';
        $icon     = $settings['icon'] ?? 'yes';
        echo do_shortcode(
            '[platform_text prefix="' . esc_attr( $prefix ) . '" suffix="' . esc_attr( $suffix ) . '" icon="' . esc_attr( $icon ) . '"]'
        );
    }
}
