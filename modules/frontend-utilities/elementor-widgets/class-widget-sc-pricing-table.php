<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_Widget_SC_Pricing_Table extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_dd_pricing_table'; }
    public function get_title()      { return esc_html__( 'Pricing Table', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-price-table'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [dd_pricing_table]. Displays the dynamic membership pricing table.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[dd_pricing_table]' );
    }
}
