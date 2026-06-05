<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Influencer_Search_Form_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'influencer_search_form';
    }

    public function get_title() {
        return __( 'Influencer Search Form', 'elementor-child' );
    }

    public function get_icon() {
        return 'eicon-search';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    protected function register_controls() {
        $this->start_controls_section(
            'content_section',
            [
                'label' => __( 'Search Settings', 'elementor-child' ),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'form_layout',
            [
                'label' => __( 'Form Layout', 'elementor-child' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'main',
                'options' => [
                    'main'    => __( 'Main Search (With Toggle)', 'elementor-child' ),
                    'sidebar' => __( 'Sidebar Filter', 'elementor-child' ),
                ],
            ]
        );

        $this->add_control(
            'btn_text',
            [
                'label'   => __( 'Button Text', 'elementor-child' ),
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => __( 'GENERATE MATCHES', 'elementor-child' ),
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        echo dd_shortcode_influencer_search_form( [
            'layout'   => $settings['form_layout'],
            'btn_text' => $settings['btn_text'],
        ] );
    }
}
