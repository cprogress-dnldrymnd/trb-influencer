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
            'label'       => esc_html__( 'Unlocked Button Text', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => esc_html__( 'SAVE', 'trb-influencer' ),
            'description' => esc_html__( 'Shown when the creator is unlocked and the button is active.', 'trb-influencer' ),
        ] );
        $this->add_control( 'locked_text', [
            'label'       => esc_html__( 'Locked Button Text', 'trb-influencer' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => esc_html__( 'SAVE', 'trb-influencer' ),
            'description' => esc_html__( 'Shown (disabled) when the creator is still locked.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $s           = $this->get_settings_for_display();
        $text        = isset( $s['text'] ) && $s['text'] !== '' ? str_replace( [ '"', '[', ']' ], '', $s['text'] ) : 'SAVE';
        $locked_text = isset( $s['locked_text'] ) && $s['locked_text'] !== '' ? str_replace( [ '"', '[', ']' ], '', $s['locked_text'] ) : 'SAVE';

        echo do_shortcode( sprintf(
            '[add_to_groups_btn text="%s" locked_text="%s"]',
            esc_attr( $text ),
            esc_attr( $locked_text )
        ) );
    }
}
