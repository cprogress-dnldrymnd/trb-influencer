<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Pricing_Table extends \Elementor\Widget_Base {

    // Shared with Widget_Feature_Comparison_Table — both render the same .dd-fc-* grid.
    use DD_Comparison_Table_Style_Controls;

    public function get_name()       { return 'sc_dd_pricing_table'; }
    public function get_title()      { return esc_html__( 'Pricing Table', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-price-table'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    /**
     * The plan columns authored on the Pricing Tables settings tab, as key => name. This is the same
     * dataset the Comparison Pricing Table renders — this widget only chooses which of those columns
     * to leave out.
     *
     * @return array<string,string>
     */
    private function get_available_columns() {
        if ( ! class_exists( 'DD_Feature_Comparison_Table' ) ) {
            return [];
        }
        return DD_Feature_Comparison_Table::get_column_choices();
    }

    protected function register_controls() {
        $columns = $this->get_available_columns();

        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders the membership pricing table. Plan columns and feature rows are authored on the Influencer Theme → Pricing Tables settings tab (the same content the Comparison Pricing Table uses), and drag-reordering them there is what sets the column order here. Each plan button is resolved per visitor — Upgrade, Downgrade, Switch, Current Plan, or locked during a free trial or a scheduled downgrade — and the visitor\'s active plan gets a "Current Plan" badge.', 'trb-influencer' ),
        ] );

        if ( empty( $columns ) ) {
            $this->add_control( 'no_columns_notice', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => esc_html__( 'No plan columns are configured yet. Add them under Influencer Theme → Pricing Tables, then reload this panel.', 'trb-influencer' ),
                'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
            ] );
        } else {
            $this->add_control( 'hide_columns', [
                'label'       => esc_html__( 'Hide Plans', 'trb-influencer' ),
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => $columns,
                'label_block' => true,
                'description' => esc_html__( 'Columns to leave off this table. Use this to keep the free Trial plan off the pricing table while the Comparison Pricing Table still shows it.', 'trb-influencer' ),
            ] );
        }

        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->register_comparison_style_controls();

        // ----------------------------------------------------------------------
        // Pricing-only chrome (no equivalent on the comparison table)
        // ----------------------------------------------------------------------
        $this->add_control( 'badge_heading', [
            'label'     => esc_html__( 'Current Plan Badge', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'badge_bg_color', [
            'label'     => esc_html__( 'Badge Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-badge' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'badge_text_color', [
            'label'     => esc_html__( 'Badge Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-badge' => 'color: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'badge_typography',
                'label'    => esc_html__( 'Badge Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-fc-wrap .dd-fc-badge',
            ]
        );
        $this->add_responsive_control( 'badge_padding', [
            'label'      => esc_html__( 'Badge Padding', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', 'em', '%', 'custom' ],
            'selectors'  => [
                '{{WRAPPER}} .dd-fc-wrap .dd-fc-badge' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ] );

        $this->add_control( 'current_column_heading', [
            'label'     => esc_html__( 'Current Plan Column', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'current_outline_color', [
            'label'       => esc_html__( 'Column Outline Color', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::COLOR,
            'description' => esc_html__( 'Outlines the column belonging to the plan the visitor currently holds.', 'trb-influencer' ),
            'selectors'   => [
                '{{WRAPPER}} .dd-fc-wrap .dd-fc-head.dd-fc-current' => 'box-shadow: inset 0 0 0 2px {{VALUE}};',
                '{{WRAPPER}} .dd-fc-wrap .dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell.dd-fc-current' => 'box-shadow: inset 2px 0 0 {{VALUE}}, inset -2px 0 0 {{VALUE}};',
                '{{WRAPPER}} .dd-fc-wrap .dd-fc-row:last-child .dd-fc-cell.dd-fc-current' => 'box-shadow: inset 2px 0 0 {{VALUE}}, inset -2px 0 0 {{VALUE}}, inset 0 -2px 0 {{VALUE}};',
            ],
        ] );

        $this->add_control( 'disabled_btn_heading', [
            'label'     => esc_html__( 'Locked / Current Button', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'disabled_btn_bg_color', [
            'label'       => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::COLOR,
            'description' => esc_html__( 'Used whenever a button is not clickable — Current Plan, Pending Downgrade, Changes Locked, or locked during a free trial.', 'trb-influencer' ),
            'selectors'   => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-cta.dd-fc-cta-disabled' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'disabled_btn_text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-cta.dd-fc-cta-disabled' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'trial_notice_heading', [
            'label'     => esc_html__( 'Free Trial Notice', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'trial_notice_bg_color', [
            'label'       => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::COLOR,
            'description' => esc_html__( 'The "N day free trial" pill, shown only to visitors who hold no paid plan and only on levels with a Subscription Delay configured.', 'trb-influencer' ),
            'selectors'   => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-trial-text span' => 'background-color: {{VALUE}};' ],
        ] );
        $this->add_control( 'trial_notice_text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-fc-wrap .dd-fc-trial-text span' => 'color: {{VALUE}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $hidden = [];
        if ( ! empty( $settings['hide_columns'] ) && is_array( $settings['hide_columns'] ) ) {
            foreach ( $settings['hide_columns'] as $key ) {
                $key = sanitize_key( $key );
                if ( $key !== '' ) {
                    $hidden[] = $key;
                }
            }
        }

        $attrs = '';
        if ( ! empty( $hidden ) ) {
            $attrs .= ' exclude="' . esc_attr( implode( ',', $hidden ) ) . '"';
        }

        echo do_shortcode( '[dd_pricing_table' . $attrs . ']' );
    }
}
