<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Feature_Comparison_Table extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_dd_comparison_table'; }
    public function get_title()      { return esc_html__( 'Comparison Pricing Table', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-price-table'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [dd_feature_comparison]. Columns, feature rows, and cell content (tick/cross/text) are authored on the Influencer Theme → Comparison Table settings tab — this widget only controls how the table looks.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'header_heading', [
            'label' => esc_html__( 'Header', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::HEADING,
        ] );
        $this->add_control( 'header_bg_color', [
            'label'     => esc_html__( 'Header Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-head' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'plan_name_typography',
                'label'    => esc_html__( 'Plan Name Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-name',
            ]
        );
        $this->add_control( 'header_name_color', [
            'label'     => esc_html__( 'Plan Name Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-name' => 'color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'price_typography',
                'label'    => esc_html__( 'Price Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-price',
            ]
        );
        $this->add_control( 'price_color', [
            'label'     => esc_html__( 'Price Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-price' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'recommended_heading', [
            'label'     => esc_html__( 'Recommended Banner', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'recommended_bg_color', [
            'label'     => esc_html__( 'Banner Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-recommended' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'recommended_text_color', [
            'label'     => esc_html__( 'Banner Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-recommended' => 'color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'recommended_banner_typography',
                'label'    => esc_html__( 'Banner Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-recommended',
            ]
        );
        $this->add_responsive_control( 'recommended_padding', [
            'label'      => esc_html__( 'Banner Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-table .dd-fc-recommended' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->add_control( 'cta_heading', [
            'label'     => esc_html__( 'CTA Button', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'cta_typography',
                'label'    => esc_html__( 'CTA Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-cta',
            ]
        );
        $this->add_responsive_control( 'cta_padding', [
            'label'      => esc_html__( 'CTA Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-table .dd-fc-cta' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'cta_border_radius', [
            'label'      => esc_html__( 'CTA Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-table .dd-fc-cta' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->start_controls_tabs( 'cta_colors_tabs' );

        $this->start_controls_tab( 'cta_colors_tab_normal', [
            'label' => esc_html__( 'Normal', 'trb-influencer' ),
        ] );
        $this->add_control( 'cta_text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-cta' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'cta_bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-cta' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'cta_border',
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-cta',
            ]
        );
        $this->end_controls_tab();

        $this->start_controls_tab( 'cta_colors_tab_hover', [
            'label' => esc_html__( 'Hover', 'trb-influencer' ),
        ] );
        $this->add_control( 'cta_text_color_hover', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-cta:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'cta_bg_color_hover', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-cta:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'cta_border_hover',
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-cta:hover',
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        // ----------------------------------------------------------------------
        // Mobile Tabs Style
        // ----------------------------------------------------------------------
        $this->add_control( 'mobile_tabs_heading', [
            'label'     => esc_html__( 'Mobile Tabs', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'mobile_tabs_typography',
                'label'    => esc_html__( 'Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab',
            ]
        );

        $this->start_controls_tabs( 'mobile_tabs_states' );

        // Normal State
        $this->start_controls_tab( 'mobile_tabs_normal', [
            'label' => esc_html__( 'Normal', 'trb-influencer' ),
        ] );
        $this->add_control( 'mobile_tabs_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'mobile_tabs_bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'mobile_tabs_border',
                'selector' => '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab',
            ]
        );
        $this->end_controls_tab();

        // Hover State
        $this->start_controls_tab( 'mobile_tabs_hover', [
            'label' => esc_html__( 'Hover', 'trb-influencer' ),
        ] );
        $this->add_control( 'mobile_tabs_color_hover', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab:hover' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'mobile_tabs_bg_color_hover', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab:hover' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'mobile_tabs_border_hover',
                'selector' => '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab:hover',
            ]
        );
        $this->end_controls_tab();

        // Active State
        $this->start_controls_tab( 'mobile_tabs_active', [
            'label' => esc_html__( 'Active', 'trb-influencer' ),
        ] );
        $this->add_control( 'mobile_tabs_color_active', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab.dd-fc-mobile-active' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'mobile_tabs_bg_color_active', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab.dd-fc-mobile-active' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'mobile_tabs_border_active',
                'selector' => '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab.dd-fc-mobile-active',
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control( 'mobile_tabs_padding', [
            'label'      => esc_html__( 'Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'separator'  => 'before',
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->add_responsive_control( 'mobile_tabs_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-wrap .dd-fc-mobile-tab' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->add_control( 'cells_heading', [
            'label'     => esc_html__( 'Feature Rows & Cells', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'feature_typography',
                'label'    => esc_html__( 'Feature Label Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-feature',
            ]
        );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'cell_value_typography',
                'label'    => esc_html__( 'Cell Value Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell:not(.dd-fc-feature)',
            ]
        );
        $this->add_control( 'tick_color', [
            'label'     => esc_html__( 'Tick Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-tick' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'cross_color', [
            'label'     => esc_html__( 'Cross Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-cross' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'cell_border_color', [
            'label'       => esc_html__( 'Cell Border Color', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::COLOR,
            'description' => esc_html__( 'Applies to every cell border in the table (outer edge, row dividers, and column dividers).', 'trb-influencer' ),
            'selectors'   => [ '{{WRAPPER}} .dd-fc-table' => '--dd-fc-border-color: {{VALUE}};' ],
        ] );
        $this->add_responsive_control( 'cell_padding', [
            'label'      => esc_html__( 'Cell Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-table .dd-fc-cell' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->add_control( 'highlight_heading', [
            'label'     => esc_html__( 'Highlighted Column', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'highlight_bg_color', [
            'label'     => esc_html__( 'Highlight Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-table .dd-fc-highlight' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'highlight_border',
                'selector' => '{{WRAPPER}} .dd-fc-table .dd-fc-highlight',
            ]
        );

        $this->add_control( 'table_border_radius', [
            'label'       => esc_html__( 'Table Border Radius', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units'  => [ 'px', 'custom' ],
            'separator'   => 'before',
            'description' => esc_html__( 'Rounds each corner cell individually rather than clipping the table, so the sticky plan header below keeps working (overflow:hidden on the table would silently disable position:sticky).', 'trb-influencer' ),
            'selectors'   => [
                '{{WRAPPER}} .dd-fc-table' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                '{{WRAPPER}} .dd-fc-wrap'  => '--dd-fc-radius-tl: {{TOP}}{{UNIT}}; --dd-fc-radius-tr: {{RIGHT}}{{UNIT}}; --dd-fc-radius-br: {{BOTTOM}}{{UNIT}}; --dd-fc-radius-bl: {{LEFT}}{{UNIT}};',
            ],
        ] );

        // ----------------------------------------------------------------------
        // Sticky Behaviour
        // ----------------------------------------------------------------------
        $this->add_control( 'sticky_heading', [
            'label'     => esc_html__( 'Sticky Behaviour', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_responsive_control( 'sticky_top_offset', [
            'label'       => esc_html__( 'Sticky Top Offset', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::SLIDER,
            'size_units'  => [ 'px' ],
            'range'       => [
                'px' => [ 'min' => 0, 'max' => 300, 'step' => 1 ],
            ],
            'description' => esc_html__( 'Distance from the top of the viewport (e.g. a fixed site header height) that the desktop plan header and the mobile tab bar stick below.', 'trb-influencer' ),
            'selectors'   => [
                '{{WRAPPER}} .dd-fc-wrap' => '--dd-fc-sticky-offset: {{SIZE}}{{UNIT}};',
            ],
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[dd_feature_comparison]' );
    }
}