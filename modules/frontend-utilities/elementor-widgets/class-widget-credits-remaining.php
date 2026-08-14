<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Credits_Remaining extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_credits_remaining'; }
    public function get_title()      { return esc_html__( 'Credits Remaining', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-counter'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [credits_remaining]. Shows the current user\'s myCred balance plus a plain-English conversion (e.g. "24 creator unlocks or 24 messages") derived live from the unlock and outreach credit costs. Renders nothing for logged-out visitors or when myCred is unavailable.', 'trb-influencer' ),
        ] );

        $this->add_control( 'show_detail', [
            'label'        => esc_html__( 'Show Conversion Detail', 'trb-influencer' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Show', 'trb-influencer' ),
            'label_off'    => esc_html__( 'Hide', 'trb-influencer' ),
            'return_value' => 'yes',
            'default'      => 'yes',
            'description'  => esc_html__( 'Show the "X creator unlocks or X messages" line beneath the credit count.', 'trb-influencer' ),
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

        $this->add_control( 'at_zero_template', [
            'label'       => esc_html__( 'At-Zero Template', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => $templates,
            'default'     => '',
            'description' => esc_html__( 'Elementor template to show instead of the plain text once the user has 0 credits.', 'trb-influencer' ),
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
                'selector' => '{{WRAPPER}} .dd-credits-remaining-value',
            ]
        );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'label_typography',
                'label'    => esc_html__( 'Label Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-credits-remaining-label',
            ]
        );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'detail_typography',
                'label'    => esc_html__( 'Detail Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-credits-remaining-detail',
            ]
        );
        $this->end_controls_section();
    }

    protected function render() {
        $settings    = $this->get_settings_for_display();
        $template_id = ! empty( $settings['at_zero_template'] ) ? (int) $settings['at_zero_template'] : 0;
        $show_detail = ! empty( $settings['show_detail'] ) ? 'yes' : 'no';

        $attr  = $template_id > 0 ? ' template_id="' . $template_id . '"' : '';
        $attr .= ' show_detail="' . $show_detail . '"';

        echo do_shortcode( '[credits_remaining' . $attr . ']' );
    }
}
