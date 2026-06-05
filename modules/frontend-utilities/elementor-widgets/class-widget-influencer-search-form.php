<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Influencer_Search_Form_Widget extends \Elementor\Widget_Base {

    public function get_name()       { return 'influencer_search_form'; }
    public function get_title()      { return esc_html__( 'Influencer Search Form', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-search'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Form Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'form_layout', [
            'label'   => esc_html__( 'Layout', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'main',
            'options' => [
                'main'    => esc_html__( 'Main (toggle + brief + advanced)', 'trb-influencer' ),
                'sidebar' => esc_html__( 'Sidebar (compact filters only)', 'trb-influencer' ),
            ],
        ] );
        $this->add_control( 'btn_text', [
            'label'   => esc_html__( 'Button Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'GENERATE MATCHES',
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();
        echo do_shortcode(
            '[influencer_search_form layout="' . esc_attr( $s['form_layout'] ) . '" btn_text="' . esc_attr( $s['btn_text'] ) . '"]'
        );
    }
}
