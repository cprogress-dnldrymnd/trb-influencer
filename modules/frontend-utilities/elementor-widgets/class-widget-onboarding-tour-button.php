<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Onboarding_Tour_Button extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_onboarding_tour_button'; }
    public function get_title()      { return esc_html__( 'Start Guided Tour', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-play'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [onboarding_tour_button]. On a page with guided-tour steps configured, clicking starts the tour in place. On a page with none — safe to drop this in a global header or sidebar — it links to the Dashboard and the tour starts automatically on arrival, so it never ends up doing nothing. Renders nothing for logged-out visitors or while Onboarding is switched off (Influencer Theme → Functionality); always visible in the Elementor editor so it can be styled.', 'trb-influencer' ),
        ] );
        $this->add_control( 'text', [
            'label'       => esc_html__( 'Button Text', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => esc_html__( 'Take a quick tour', 'trb-influencer' ),
            'description' => esc_html__( 'Leave blank to use the label configured under Influencer Theme → Messages → Onboarding.', 'trb-influencer' ),
        ] );
        $this->add_control( 'icon', [
            'label' => esc_html__( 'Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_responsive_control( 'alignment', [
            'label'     => esc_html__( 'Alignment', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::CHOOSE,
            'options'   => [
                'left'   => [ 'title' => esc_html__( 'Left', 'trb-influencer' ), 'icon' => 'eicon-text-align-left' ],
                'center' => [ 'title' => esc_html__( 'Center', 'trb-influencer' ), 'icon' => 'eicon-text-align-center' ],
                'right'  => [ 'title' => esc_html__( 'Right', 'trb-influencer' ), 'icon' => 'eicon-text-align-right' ],
            ],
            'default'   => 'left',
            'selectors' => [ '{{WRAPPER}}' => 'text-align: {{VALUE}};' ],
        ] );
        $this->add_control( 'full_width', [
            'label'        => esc_html__( 'Full Width', 'trb-influencer' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Yes', 'trb-influencer' ),
            'label_off'    => esc_html__( 'No', 'trb-influencer' ),
            'return_value' => 'yes',
            'selectors'    => [
                '{{WRAPPER}} .dd-onboarding-tour-btn' => 'display: flex; width: 100%; justify-content: center;',
            ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'button_typography',
                'label'    => esc_html__( 'Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-onboarding-tour-btn',
            ]
        );
        $this->add_responsive_control( 'button_padding', [
            'label'      => esc_html__( 'Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-onboarding-tour-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'button_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-onboarding-tour-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->add_control( 'icon_heading', [
            'label'     => esc_html__( 'Icon', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_responsive_control( 'icon_size', [
            'label'     => esc_html__( 'Icon Size', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => [ 'px' => [ 'min' => 8, 'max' => 60 ] ],
            'default'   => [ 'unit' => 'px', 'size' => 18 ],
            'selectors' => [
                '{{WRAPPER}} .dd-onboarding-tour-btn__icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'icon_gap', [
            'label'     => esc_html__( 'Icon Gap', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'default'   => [ 'unit' => 'px', 'size' => 8 ],
            'selectors' => [
                '{{WRAPPER}} .dd-onboarding-tour-btn' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ] );

        $this->start_controls_tabs( 'colors_tabs' );

        $this->start_controls_tab( 'colors_tab_normal', [
            'label' => esc_html__( 'Normal', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-onboarding-tour-btn' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-onboarding-tour-btn' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'button_border',
                'selector' => '{{WRAPPER}} .dd-onboarding-tour-btn',
            ]
        );
        $this->end_controls_tab();

        $this->start_controls_tab( 'colors_tab_hover', [
            'label' => esc_html__( 'Hover', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_color_hover', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-onboarding-tour-btn:hover, {{WRAPPER}} .dd-onboarding-tour-btn:focus' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'bg_color_hover', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-onboarding-tour-btn:hover, {{WRAPPER}} .dd-onboarding-tour-btn:focus' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'button_border_hover',
                'selector' => '{{WRAPPER}} .dd-onboarding-tour-btn:hover, {{WRAPPER}} .dd-onboarding-tour-btn:focus',
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'button_box_shadow',
                'selector' => '{{WRAPPER}} .dd-onboarding-tour-btn',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $atts = [];
        if ( ! empty( $settings['text'] ) ) {
            $atts[] = 'text="' . esc_attr( $this->sanitize_attr( $settings['text'] ) ) . '"';
        }
        if ( ! empty( $settings['icon']['url'] ) ) {
            $atts[] = 'icon="' . esc_url( $settings['icon']['url'] ) . '"';
        }

        echo do_shortcode( '[onboarding_tour_button' . ( $atts ? ' ' . implode( ' ', $atts ) : '' ) . ']' );
    }

    /**
     * Strips double quotes/brackets so user text cannot break out of the shortcode attribute
     * (same guard class-widget-outreach-button.php uses).
     */
    private function sanitize_attr( $value ) {
        return str_replace( [ '"', '[', ']' ], '', $value );
    }
}
