<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Recent_Media extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_platform_recent_media'; }
    public function get_title()      { return esc_html__( 'Influencer Recent Content', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-post-list'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [platform_recent_media]. Displays the influencer\'s recent posts/videos — Instagram and TikTok as native embeds, YouTube as thumbnails. Left on "All", it renders one panel per platform with data and swaps with the page platform switcher.', 'trb-influencer' ),
        ] );
        $this->add_control( 'platform', [
            'label'   => esc_html__( 'Platform', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '',
            'options' => [
                ''          => esc_html__( 'All (follows switcher)', 'trb-influencer' ),
                'instagram' => esc_html__( 'Instagram', 'trb-influencer' ),
                'youtube'   => esc_html__( 'YouTube', 'trb-influencer' ),
                'tiktok'    => esc_html__( 'TikTok', 'trb-influencer' ),
            ],
            'description' => esc_html__( 'Pinning a single platform makes this block ignore the switcher.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $platform = $settings['platform'] ?? '';
        echo do_shortcode( '[platform_recent_media platform="' . esc_attr( $platform ) . '"]' );
    }
}
