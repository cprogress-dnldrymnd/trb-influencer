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

        $this->add_control( 'refine_search_heading', [
            'label'     => esc_html__( 'Mobile Sideout Trigger', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'condition' => [ 'form_layout' => 'sidebar' ],
        ] );
        $this->add_control( 'refine_icon', [
            'label'       => esc_html__( 'Icon', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::MEDIA,
            'default'     => [ 'url' => '' ],
            'condition'   => [ 'form_layout' => 'sidebar' ],
            'description' => esc_html__( 'Upload an icon from the media library. Leave empty to use the default target icon. Shown beside the title — the whole heading also acts as the mobile sideout trigger.', 'trb-influencer' ),
        ] );
        $this->add_control( 'refine_title', [
            'label'     => esc_html__( 'Title', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'REFINE YOUR SEARCH',
            'condition' => [ 'form_layout' => 'sidebar' ],
        ] );
        $this->add_control( 'refine_subtext', [
            'label'     => esc_html__( 'Subtext', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Filter your existing matches – no credits required.',
            'condition' => [ 'form_layout' => 'sidebar' ],
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        $atts = [
            'layout'   => $s['form_layout'],
            'btn_text' => $s['btn_text'],
        ];

        if ( 'sidebar' === $s['form_layout'] ) {
            $atts['refine_icon']    = $s['refine_icon']['url'] ?? '';
            $atts['refine_title']   = $s['refine_title'];
            $atts['refine_subtext'] = $s['refine_subtext'];
        }

        $shortcode = '[influencer_search_form';
        foreach ( $atts as $key => $value ) {
            $shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
        }
        $shortcode .= ']';

        echo do_shortcode( $shortcode );
    }
}
