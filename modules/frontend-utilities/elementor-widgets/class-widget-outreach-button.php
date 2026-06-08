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
            'raw'  => esc_html__( 'Renders [outreach_button]. Locked: "Unlock Full Profile" (myCred unlock) + a disabled contact button. Unlocked: a "Profile Unlocked" status button + the enabled contact button.', 'trb-influencer' ),
        ] );

        // --- Locked state ---
        $this->add_control( 'unlock_heading', [
            'label'     => esc_html__( 'Locked: Unlock Full Profile Button', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'unlock_text', [
            'label'   => esc_html__( 'Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'UNLOCK FULL PROFILE', 'trb-influencer' ),
        ] );
        $this->add_control( 'unlock_icon', [
            'label' => esc_html__( 'Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );

        $this->add_control( 'contact_locked_heading', [
            'label'     => esc_html__( 'Locked: Contact Button (disabled)', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'contact_locked_text', [
            'label'   => esc_html__( 'Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'UNLOCK TO CONTACT', 'trb-influencer' ),
        ] );
        $this->add_control( 'contact_locked_icon', [
            'label' => esc_html__( 'Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );

        // --- Unlocked state ---
        $this->add_control( 'unlocked_heading', [
            'label'     => esc_html__( 'Unlocked: Profile Unlocked Button', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'unlocked_text', [
            'label'   => esc_html__( 'Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'PROFILE UNLOCKED', 'trb-influencer' ),
        ] );
        $this->add_control( 'unlocked_icon', [
            'label' => esc_html__( 'Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );

        $this->add_control( 'contact_heading', [
            'label'     => esc_html__( 'Unlocked: Contact Button (enabled)', 'trb-influencer' ),
            'type'      => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ] );
        $this->add_control( 'contact_text', [
            'label'   => esc_html__( 'Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'CONTACT THIS CREATOR', 'trb-influencer' ),
        ] );
        $this->add_control( 'contact_icon', [
            'label' => esc_html__( 'Icon', 'trb-influencer' ),
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        $fields = [
            'unlock_text'         => 'UNLOCK FULL PROFILE',
            'contact_locked_text' => 'UNLOCK TO CONTACT',
            'unlocked_text'       => 'PROFILE UNLOCKED',
            'contact_text'        => 'CONTACT THIS CREATOR',
        ];
        $icons = [ 'unlock_icon', 'contact_locked_icon', 'unlocked_icon', 'contact_icon' ];

        $shortcode = '[outreach_button';
        foreach ( $fields as $key => $default ) {
            $value = isset( $s[ $key ] ) && $s[ $key ] !== '' ? $this->sanitize_attr( $s[ $key ] ) : $default;
            $shortcode .= sprintf( ' %s="%s"', $key, $value );
        }
        foreach ( $icons as $key ) {
            $url = ! empty( $s[ $key ]['url'] ) ? esc_url( $s[ $key ]['url'] ) : '';
            $shortcode .= sprintf( ' %s="%s"', $key, $url );
        }
        $shortcode .= ']';

        echo do_shortcode( $shortcode );
    }

    /**
     * Strips double quotes/brackets so user text cannot break out of the shortcode attribute.
     */
    private function sanitize_attr( $value ) {
        return str_replace( [ '"', '[', ']' ], '', $value );
    }
}
