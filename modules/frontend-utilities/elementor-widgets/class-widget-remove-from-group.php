<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Remove_From_Group extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_remove_from_group_btn'; }
    public function get_title()      { return esc_html__( 'Remove from Group Button', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-trash'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [remove_from_group_btn]. Displays the button to remove the current influencer from a saved group.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[remove_from_group_btn]' );
    }
}
