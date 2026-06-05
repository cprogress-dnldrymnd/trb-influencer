<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Outreach_View extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_dd_outreach_view'; }
    public function get_title()      { return esc_html__( 'Outreach Detail View', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-post-content'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [dd_outreach_view]. Displays the outreach detail/single view for a selected outreach record.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[dd_outreach_view]' );
    }
}
