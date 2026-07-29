<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Account_Notice extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_account_notice'; }
    public function get_title()      { return esc_html__( 'Account Notice', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-warning'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [account_notice]. Shows an upgrade notice when the current user has no creator searches left — either because their company already used its trial, or because their plan\'s search cap is exhausted. Renders nothing for unlimited plans, users who still have searches remaining, or logged-out visitors. Message text is edited under Influencer Theme → Messages → "Search Limit Reached" / "Company Trial Limit" / "Account Notice Upgrade Button".', 'trb-influencer' ),
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'message_typography',
                'label'    => esc_html__( 'Message Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-account-notice-text',
            ]
        );
        $this->add_control( 'message_color', [
            'label'     => esc_html__( 'Message Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-account-notice-text' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'box_bg_color', [
            'label'     => esc_html__( 'Box Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-account-notice' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'box_border',
                'selector' => '{{WRAPPER}} .dd-account-notice',
            ]
        );
        $this->add_responsive_control( 'box_border_radius', [
            'label'      => esc_html__( 'Box Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-account-notice' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'box_padding', [
            'label'      => esc_html__( 'Box Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-account-notice' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'button_style_section', [
            'label' => esc_html__( 'Button', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'button_typography',
                'label'    => esc_html__( 'Button Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-account-notice-cta',
            ]
        );
        $this->add_responsive_control( 'button_padding', [
            'label'      => esc_html__( 'Button Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-account-notice-cta' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'button_border_radius', [
            'label'      => esc_html__( 'Button Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-account-notice-cta' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->start_controls_tabs( 'button_colors_tabs' );

        $this->start_controls_tab( 'button_colors_tab_normal', [
            'label' => esc_html__( 'Normal', 'trb-influencer' ),
        ] );
        $this->add_control( 'button_text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-account-notice-cta' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'button_bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-account-notice-cta' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'button_border',
                'selector' => '{{WRAPPER}} .dd-account-notice-cta',
            ]
        );
        $this->end_controls_tab();

        $this->start_controls_tab( 'button_colors_tab_hover', [
            'label' => esc_html__( 'Hover', 'trb-influencer' ),
        ] );
        $this->add_control( 'button_text_color_hover', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-account-notice-cta:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'button_bg_color_hover', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-account-notice-cta:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'button_border_hover',
                'selector' => '{{WRAPPER}} .dd-account-notice-cta:hover',
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[account_notice]' );
    }
}
