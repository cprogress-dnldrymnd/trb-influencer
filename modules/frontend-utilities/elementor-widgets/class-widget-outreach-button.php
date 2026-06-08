<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Outreach_Button extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_outreach_button'; }
    public function get_title()      { return esc_html__( 'Outreach Button', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-button'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [outreach_button]. Shows an "Unlock Full Profile" button (myCred unlock) when locked, plus a contact button. Once unlocked, only the enabled contact button shows.', 'trb-influencer' ),
        ] );

        $this->add_control( 'unlock_heading', [
            'label'     => esc_html__( 'Unlock Full Profile Button', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'unlock_text', [
            'label'   => esc_html__( 'Unlock Button Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'UNLOCK FULL PROFILE', 'trb-influencer' ),
        ] );
        $this->add_control( 'unlock_icon', [
            'label' => esc_html__( 'Unlock Button Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );

        $this->add_control( 'contact_heading', [
            'label'     => esc_html__( 'Contact Button', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'contact_text', [
            'label'   => esc_html__( 'Contact Button Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'CONTACT THIS CREATOR', 'trb-influencer' ),
        ] );
        $this->add_control( 'contact_icon', [
            'label' => esc_html__( 'Contact Button Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        $unlock_text  = isset( $s['unlock_text'] ) ? $this->sanitize_attr( $s['unlock_text'] ) : 'UNLOCK FULL PROFILE';
        $contact_text = isset( $s['contact_text'] ) ? $this->sanitize_attr( $s['contact_text'] ) : 'CONTACT THIS CREATOR';
        $unlock_icon  = ! empty( $s['unlock_icon']['url'] ) ? esc_url( $s['unlock_icon']['url'] ) : '';
        $contact_icon = ! empty( $s['contact_icon']['url'] ) ? esc_url( $s['contact_icon']['url'] ) : '';

        echo do_shortcode( sprintf(
            '[outreach_button unlock_text="%s" unlock_icon="%s" contact_text="%s" contact_icon="%s"]',
            $unlock_text,
            $unlock_icon,
            $contact_text,
            $contact_icon
        ) );
    }

    /**
     * Strips double quotes/brackets so user text cannot break out of the shortcode attribute.
     */
    private function sanitize_attr( $value ) {
        return str_replace( [ '"', '[', ']' ], '', $value );
    }
}
