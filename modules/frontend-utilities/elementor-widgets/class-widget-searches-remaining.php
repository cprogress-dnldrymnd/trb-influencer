<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Searches_Remaining extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_searches_remaining'; }
    public function get_title()      { return esc_html__( 'Searches Remaining', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-counter'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [searches_remaining]. Shows the current user\'s remaining creator-search quota. Renders nothing for unlimited plans or logged-out visitors.', 'trb-influencer' ),
        ] );

        $templates = [ '' => esc_html__( '— None (plain text only) —', 'trb-influencer' ) ];
        $tpl_posts = get_posts( [
            'post_type'      => 'elementor_library',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
        foreach ( $tpl_posts as $tpl_post ) {
            $templates[ $tpl_post->ID ] = $tpl_post->post_title;
        }

        $this->add_control( 'at_limit_template', [
            'label'       => esc_html__( 'At-Limit Template', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => $templates,
            'default'     => '',
            'description' => esc_html__( 'Elementor template to show instead of the plain text once the user has 0 searches remaining.', 'trb-influencer' ),
        ] );

        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'value_typography',
                'label'    => esc_html__( 'Value Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-searches-remaining-value',
            ]
        );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'label_typography',
                'label'    => esc_html__( 'Label Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-searches-remaining-label',
            ]
        );
        $this->end_controls_section();
    }

    protected function render() {
        $settings    = $this->get_settings_for_display();
        $template_id = ! empty( $settings['at_limit_template'] ) ? (int) $settings['at_limit_template'] : 0;
        $attr        = $template_id > 0 ? ' template_id="' . $template_id . '"' : '';
        echo do_shortcode( '[searches_remaining' . $attr . ']' );
    }
}
