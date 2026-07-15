<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Follower_Growth_Rate_Chart extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_follower_growth_rate_chart'; }
    public function get_title()      { return esc_html__( 'Follower Growth Rate Chart', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-bar-chart'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [follower_growth_rate_chart]. Displays a chart of month-over-month follower growth rate for the current influencer. Switches with the page platform switcher; this control sets the initial platform.', 'trb-influencer' ),
        ] );
        $this->add_control( 'platform', [
            'label'   => esc_html__( 'Platform', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'instagram',
            'options' => [
                'instagram' => esc_html__( 'Instagram', 'trb-influencer' ),
                'youtube'   => esc_html__( 'YouTube', 'trb-influencer' ),
                'tiktok'    => esc_html__( 'TikTok', 'trb-influencer' ),
            ],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $platform = $settings['platform'] ?? 'instagram';
        echo do_shortcode( '[follower_growth_rate_chart platform="' . esc_attr( $platform ) . '"]' );
    }
}
