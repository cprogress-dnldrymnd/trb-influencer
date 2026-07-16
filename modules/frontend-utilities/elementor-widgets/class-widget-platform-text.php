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

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_control( 'icon_size', [
            'label'       => esc_html__( 'Icon Size', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::SLIDER,
            'size_units'  => [ 'px' ],
            'range'       => [ 'px' => [ 'min' => 8, 'max' => 100 ] ],
            'description' => esc_html__( 'Leave empty to match the text size.', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_size', [
            'label'       => esc_html__( 'Text Size', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::SLIDER,
            'size_units'  => [ 'px' ],
            'range'       => [ 'px' => [ 'min' => 8, 'max' => 80 ] ],
            'description' => esc_html__( 'Leave empty to inherit the surrounding font size.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings  = $this->get_settings_for_display();
        $prefix    = $settings['prefix'] ?? '';
        $suffix    = isset( $settings['suffix'] ) ? $settings['suffix'] : 'Overview';
        $icon      = $settings['icon'] ?? 'yes';
        $icon_size = isset( $settings['icon_size']['size'] ) ? (int) $settings['icon_size']['size'] : 0;
        $text_size = isset( $settings['text_size']['size'] ) ? (int) $settings['text_size']['size'] : 0;

        $attrs = ' prefix="' . esc_attr( $prefix ) . '" suffix="' . esc_attr( $suffix ) . '" icon="' . esc_attr( $icon ) . '"';
        if ( $icon_size > 0 ) {
            $attrs .= ' icon_size="' . $icon_size . '"';
        }
        if ( $text_size > 0 ) {
            $attrs .= ' text_size="' . $text_size . '"';
        }

        echo do_shortcode( '[platform_text' . $attrs . ']' );
    }
}
