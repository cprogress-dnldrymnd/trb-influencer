<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Onboarding_Checklist extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_onboarding_checklist'; }
    public function get_title()      { return esc_html__( 'Getting Started Checklist', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-checkbox'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [onboarding_checklist]. A persistent "what have I done / what\'s left" list for new members — running a search, saving a creator, unlocking a profile, and contacting a creator — each ticking off automatically as the user does it. Rows for features the viewer\'s plan doesn\'t include link to the upgrade page instead. Renders nothing for logged-out visitors.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'item_typography',
                'label'    => esc_html__( 'Item Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-onboarding-checklist__label',
            ]
        );
        $this->add_responsive_control( 'item_gap', [
            'label'      => esc_html__( 'Item Gap', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'selectors'  => [ '{{WRAPPER}} .dd-onboarding-checklist' => 'gap: {{SIZE}}{{UNIT}};' ],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[onboarding_checklist]' );
    }
}
