<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Locked_Feature extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_locked_feature'; }
    public function get_title()      { return esc_html__( 'Locked Feature Preview', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-lock-user'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [locked_feature]. Shows the chosen template as-is for any visitor whose plan includes this feature (and always inside the Elementor editor); everyone else sees the same real UI blurred/dimmed behind a padlock and an "Upgrade your plan" button. Use this to preview a premium feature on the dashboard for free/trial members.', 'trb-influencer' ),
        ] );

        $features = function_exists( 'dd_plan_lockable_features' ) ? dd_plan_lockable_features() : [];
        $options  = [ '' => esc_html__( '— Select a feature —', 'trb-influencer' ) ];
        foreach ( $features as $key => $def ) {
            $options[ $key ] = $def['label'];
        }
        $this->add_control( 'feature', [
            'label'   => esc_html__( 'Feature', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => $options,
            'default' => '',
        ] );

        $templates = [ '' => esc_html__( '— Select a template —', 'trb-influencer' ) ];
        $tpl_posts = get_posts( [
            'post_type'      => 'elementor_library',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
        foreach ( $tpl_posts as $tpl_post ) {
            $templates[ $tpl_post->ID ] = $tpl_post->post_title;
        }
        $this->add_control( 'template_id', [
            'label'       => esc_html__( 'Preview Template', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => $templates,
            'default'     => '',
            'description' => esc_html__( 'The real feature UI to show behind the lock overlay.', 'trb-influencer' ),
        ] );

        $this->add_control( 'title_override', [
            'label'       => esc_html__( 'Title Override', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'description' => esc_html__( 'Leave blank to use the feature\'s default label.', 'trb-influencer' ),
        ] );
        $this->add_control( 'blurb_override', [
            'label'       => esc_html__( 'Blurb Override', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'description' => esc_html__( 'Leave blank to use the message configured under Influencer Theme → Messages.', 'trb-influencer' ),
        ] );
        $this->add_control( 'cta_label_override', [
            'label'       => esc_html__( 'Button Label Override', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_responsive_control( 'blur_amount', [
            'label'      => esc_html__( 'Blur Amount', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 12 ] ],
            'default'    => [ 'unit' => 'px', 'size' => 2 ],
            'selectors'  => [ '{{WRAPPER}} .dd-locked' => '--dd-lock-blur: {{SIZE}}{{UNIT}};' ],
        ] );
        $this->add_control( 'dim_opacity', [
            'label'      => esc_html__( 'Dim Opacity', 'trb-influencer' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 1, 'step' => 0.05 ] ],
            'default'    => [ 'size' => 0.45 ],
            'selectors'  => [ '{{WRAPPER}} .dd-locked' => '--dd-lock-opacity: {{SIZE}};' ],
        ] );
        $this->add_control( 'overlay_bg_color', [
            'label'     => esc_html__( 'Overlay Background', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-locked' => '--dd-lock-overlay-bg: {{VALUE}};' ],
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'title_typography',
                'label'    => esc_html__( 'Title Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-locked__title',
            ]
        );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'blurb_typography',
                'label'    => esc_html__( 'Blurb Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-locked__blurb',
            ]
        );
        $this->end_controls_section();

        $this->start_controls_section( 'button_style_section', [
            'label' => esc_html__( 'Button', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'button_typography',
                'label'    => esc_html__( 'Button Typography', 'trb-influencer' ),
                'selector' => '{{WRAPPER}} .dd-locked__cta',
            ]
        );
        $this->add_control( 'button_text_color', [
            'label'     => esc_html__( 'Text Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-locked__cta' => 'color: {{VALUE}};' ],
        ] );
        $this->add_control( 'button_bg_color', [
            'label'     => esc_html__( 'Background Color', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .dd-locked__cta' => 'background-color: {{VALUE}};' ],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        if ( empty( $settings['feature'] ) || empty( $settings['template_id'] ) ) {
            if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                echo '<p>' . esc_html__( 'Select a feature and a preview template.', 'trb-influencer' ) . '</p>';
            }
            return;
        }

        $atts = [
            'feature="' . esc_attr( $settings['feature'] ) . '"',
            'template_id="' . (int) $settings['template_id'] . '"',
        ];
        if ( ! empty( $settings['title_override'] ) ) {
            $atts[] = 'title="' . esc_attr( $settings['title_override'] ) . '"';
        }
        if ( ! empty( $settings['blurb_override'] ) ) {
            $atts[] = 'blurb="' . esc_attr( $settings['blurb_override'] ) . '"';
        }
        if ( ! empty( $settings['cta_label_override'] ) ) {
            $atts[] = 'cta_label="' . esc_attr( $settings['cta_label_override'] ) . '"';
        }

        echo do_shortcode( '[locked_feature ' . implode( ' ', $atts ) . ']' );
    }
}
