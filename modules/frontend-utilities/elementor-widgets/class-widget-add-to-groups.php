<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Add_To_Groups extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_add_to_groups_btn'; }
    public function get_title()      { return esc_html__( 'Add to Groups Button', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-folder-o'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [add_to_groups_btn]. Displays the button to add the current influencer to the user\'s saved groups.', 'trb-influencer' ),
        ] );
        $this->add_control( 'text', [
            'label'   => esc_html__( 'Button Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'SAVE', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $s    = $this->get_settings_for_display();
        $text = isset( $s['text'] ) ? str_replace( [ '"', '[', ']' ], '', $s['text'] ) : 'SAVE';

        echo do_shortcode( '[add_to_groups_btn text="' . esc_attr( $text ) . '"]' );
    }
}
