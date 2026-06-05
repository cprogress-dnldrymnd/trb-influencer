<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Influencer_Match_Score_Widget extends \Elementor\Widget_Base
{

    public function get_name()
    {
        return 'influencer_match_score';
    }

    public function get_title()
    {
        return esc_html__('Match Score (Influencer)', 'trb-influencer');
    }

    public function get_icon()
    {
        return 'eicon-star-o';
    }

    public function get_categories()
    {
        return ['general'];
    }

    protected function register_controls()
    {
        $this->start_controls_section(
            'content_section',
            [
                'label' => esc_html__('Settings', 'trb-influencer'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'info',
            [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                'raw' => esc_html__('This widget dynamically displays the match score badge for the influencer in the current loop/profile.', 'trb-influencer'),
            ]
        );

        $this->end_controls_section();
    }

    protected function render()
    {
        echo dd_shortcode_influencer_match_score();
    }
}
