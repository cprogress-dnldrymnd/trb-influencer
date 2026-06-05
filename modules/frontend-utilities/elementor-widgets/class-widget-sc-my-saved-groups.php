<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Widget_SC_My_Saved_Groups extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_my_saved_groups'; }
    public function get_title()      { return esc_html__( 'My Saved Groups', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-folder'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [my_saved_groups]. Displays the current user\'s saved influencer groups.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[my_saved_groups]' );
    }
}
