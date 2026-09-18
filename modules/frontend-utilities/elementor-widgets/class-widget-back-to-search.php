<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Back_To_Search extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_back_to_search'; }
    public function get_title()      { return esc_html__( 'Back to Search Results', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-arrow-left'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Returns to the filtered search-results URL (same behaviour as the profile Search Results breadcrumb). On the front end it only appears when the visitor arrived from the Search Results page; always visible in the Elementor editor so it can be styled. Pick a library icon or upload an SVG/image.', 'trb-influencer' ),
        ] );
        $this->add_control( 'text', [
            'label'   => esc_html__( 'Button Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'Back to Search Results', 'trb-influencer' ),
        ] );
        $this->add_control( 'selected_icon', [
            'label'   => esc_html__( 'Icon', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::ICONS,
            'default' => [
                'value'   => 'fas fa-arrow-left',
                'library' => 'fa-solid',
            ],
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
                '{{WRAPPER}} .dd-back-to-search' => 'display: inline-flex; width: 100%; justify-content: center;',
            ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'button_typography',
                'label'    => esc_html__( 'Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-back-to-search',
            ]
        );
        $this->add_responsive_control( 'button_padding', [
            'label'      => esc_html__( 'Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-back-to-search' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'button_border_radius', [
            'label'      => esc_html__( 'Border Radius', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-back-to-search' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
                '{{WRAPPER}} .dd-back-to-search__icon'     => 'font-size: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .dd-back-to-search__icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .dd-back-to-search__icon img' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
            ],
        ] );
        $this->add_responsive_control( 'icon_gap', [
            'label'     => esc_html__( 'Icon Gap', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'default'   => [ 'unit' => 'px', 'size' => 8 ],
            'selectors' => [
                '{{WRAPPER}} .dd-back-to-search' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ] );

        $this->start_controls_tabs( 'colors_tabs' );

        $this->start_controls_tab( 'colors_tab_normal', [
            'label' => esc_html__( 'Normal', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-back-to-search' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-back-to-search' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'button_border',
                'selector' => '{{WRAPPER}} .dd-back-to-search',
            ]
        );
        $this->end_controls_tab();

        $this->start_controls_tab( 'colors_tab_hover', [
            'label' => esc_html__( 'Hover', 'trb-influencer' ),
        ] );
        $this->add_control( 'text_color_hover', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-back-to-search:hover, {{WRAPPER}} .dd-back-to-search:focus' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'bg_color_hover', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-back-to-search:hover, {{WRAPPER}} .dd-back-to-search:focus' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'button_border_hover',
                'selector' => '{{WRAPPER}} .dd-back-to-search:hover, {{WRAPPER}} .dd-back-to-search:focus',
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'button_box_shadow',
                'selector' => '{{WRAPPER}} .dd-back-to-search',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        if ( ! function_exists( 'dd_render_back_to_search_button' ) ) {
            return;
        }

        $settings = $this->get_settings_for_display();
        $text     = ! empty( $settings['text'] ) ? $settings['text'] : 'Back to Search Results';

        $icon_html = '';
        if ( ! empty( $settings['selected_icon']['value'] ) ) {
            ob_start();
            \Elementor\Icons_Manager::render_icon(
                $settings['selected_icon'],
                [
                    'aria-hidden' => 'true',
                    'class'       => 'dd-back-to-search__icon',
                ]
            );
            $icon_html = ob_get_clean();
        }

        echo dd_render_back_to_search_button( [
            'text'      => $text,
            'icon_html' => $icon_html,
        ] );
    }
}
