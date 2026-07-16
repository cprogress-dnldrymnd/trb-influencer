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
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'label_typography',
                'label'    => esc_html__( 'Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-platform-btn .dd-platform-label',
            ]
        );

        $this->add_control( 'colors_heading', [
            'label'     => esc_html__( 'Colors & Border', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_responsive_control( 'border_radius', [
            'label'      => esc_html__( 'Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            // Extra .dd-platform-switcher ancestor selector outweighs the shortcode's own
            // hardcoded <style> block (same 2-class specificity) regardless of source order.
            'selectors'  => [
                '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->start_controls_tabs( 'colors_tabs' );

        $this->start_controls_tab( 'colors_tab_normal', [
            'label' => esc_html__( 'Normal', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'border',
                'selector' => '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn',
            ]
        );
        $this->end_controls_tab();

        $this->start_controls_tab( 'colors_tab_hover', [
            'label' => esc_html__( 'Hover', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_color_hover', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'bg_color_hover', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'border_hover',
                'selector' => '{{WRAPPER}} .dd-platform-switcher .dd-platform-btn:hover',
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->end_controls_section();
    }

    protected function render() {
        $settings  = $this->get_settings_for_display();
        $icon_size = isset( $settings['icon_size']['size'] ) ? (int) $settings['icon_size']['size'] : 0;

        $attrs = '';
        if ( $icon_size > 0 ) {
            $attrs .= ' icon_size="' . $icon_size . '"';
        }

        echo do_shortcode( '[platform_switcher' . $attrs . ']' );
    }
}
