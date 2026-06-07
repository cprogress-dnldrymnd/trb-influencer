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
            'label'       => esc_html__( 'Icon (SVG markup)', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'default'     => '<svg xmlns="http://www.w3.org/2000/svg" width="23.66" height="20" viewBox="0 0 23.66 20"><path id="target" d="M24.044,20.152A10.187,10.187,0,0,1,24.1,21.2,10,10,0,1,1,19.973,13.1l-.745,2.778a7.375,7.375,0,1,0,2.037,3.527l2.777.744ZM13.436,21.579a.764.764,0,0,0,1.045.278l6.549-3.781,2.312.619,4.414-2.549-3.356-.9.9-3.356-4.414,2.549-.619,2.312-6.551,3.782a.764.764,0,0,0-.278,1.045Zm.661-3.032a2.671,2.671,0,0,1,.518.05L17.2,17.106a5.132,5.132,0,1,0,2.03,4.089,5.173,5.173,0,0,0-.04-.641l-2.582,1.491a2.649,2.649,0,1,1-2.51-3.5Z" transform="translate(-4.097 -11.195)" fill="#00a6ed" fill-rule="evenodd"></path></svg>',
            'condition'   => [ 'form_layout' => 'sidebar' ],
            'description' => esc_html__( 'Shown beside the title. The whole heading also acts as the mobile sideout trigger.', 'trb-influencer' ),
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
            $atts['refine_icon']    = $s['refine_icon'];
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
