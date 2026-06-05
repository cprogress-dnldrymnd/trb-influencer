<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Influencer_Hashtags extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_influencer_hashtags'; }
    public function get_title()      { return esc_html__( 'Influencer Hashtags', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-hash-tag'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'limit', [
            'label'   => esc_html__( 'Limit', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 10,
            'min'     => 1,
            'max'     => 120,
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $limit = (int) $this->get_settings_for_display( 'limit' );
        echo do_shortcode( '[influencer_hashtags limit="' . $limit . '"]' );
    }
}
