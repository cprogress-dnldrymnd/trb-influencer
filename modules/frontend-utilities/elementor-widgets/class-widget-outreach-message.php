<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Outreach_Message extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_outreach_message'; }
    public function get_title()      { return esc_html__( 'Outreach Message', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-mail'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [outreach_message]. Displays the outreach message body on an influencer profile page.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[outreach_message]' );
    }
}
