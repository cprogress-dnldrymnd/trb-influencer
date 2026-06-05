<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Widget_SC_Custom_Mycred_Log extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_custom_mycred_log'; }
    public function get_title()      { return esc_html__( 'myCred Transaction Log', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-posts-group'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [custom_mycred_log]. Displays the current user\'s myCred transaction history.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[custom_mycred_log]' );
    }
}
