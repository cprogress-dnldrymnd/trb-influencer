<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Custom_Mycred_Log extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_custom_mycred_log'; }
    public function get_title()      { return esc_html__( 'Credit History', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-posts-group'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [custom_mycred_log]. Shows the current user\'s own myCred transaction history — summary tiles, a filterable/searchable ledger with a running balance, and a CSV export. Always scoped to the logged-in visitor; shows a login prompt when logged out.', 'trb-influencer' ),
        ] );

        $this->add_control( 'show_summary', [
            'label'        => esc_html__( 'Show Summary Strip', 'trb-influencer' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Show', 'trb-influencer' ),
            'label_off'    => esc_html__( 'Hide', 'trb-influencer' ),
            'return_value' => 'yes',
            'default'      => 'yes',
            'description'  => esc_html__( 'Balance / spent this month / earned this month / unlocks vs. messages tiles above the table.', 'trb-influencer' ),
        ] );

        $this->add_control( 'show_export', [
            'label'        => esc_html__( 'Show Export Button', 'trb-influencer' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Show', 'trb-influencer' ),
            'label_off'    => esc_html__( 'Hide', 'trb-influencer' ),
            'return_value' => 'yes',
            'default'      => 'yes',
            'description'  => esc_html__( '"Export CSV" button, downloading whatever the table\'s active filters currently show.', 'trb-influencer' ),
        ] );

        $this->add_control( 'limit', [
            'label'   => esc_html__( 'Rows Per Page', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                20  => '20',
                50  => '50',
                100 => '100',
            ],
            'default' => 20,
        ] );

        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'summary_value_typography',
                'label'    => esc_html__( 'Summary Value Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .mycred-summary-value',
            ]
        );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'summary_label_typography',
                'label'    => esc_html__( 'Summary Label Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .mycred-summary-label',
            ]
        );
        $this->add_control( 'accent_color', [
            'label'     => esc_html__( 'Accent Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .mycred-pill.is-active' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
                '{{WRAPPER}} .mycred-btn-page.is-current' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
            ],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $atts = [
            'show_summary' => ! empty( $settings['show_summary'] ) ? 'yes' : 'no',
            'show_export'  => ! empty( $settings['show_export'] ) ? 'yes' : 'no',
            'limit'        => ! empty( $settings['limit'] ) ? (int) $settings['limit'] : 20,
        ];

        $attr_string = '';
        foreach ( $atts as $key => $value ) {
            $attr_string .= ' ' . $key . '="' . esc_attr( $value ) . '"';
        }

        echo do_shortcode( '[custom_mycred_log' . $attr_string . ']' );
    }
}
