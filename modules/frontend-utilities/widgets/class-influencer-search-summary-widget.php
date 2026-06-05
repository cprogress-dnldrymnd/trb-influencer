<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Influencer_Search_Summary_Widget extends \Elementor\Widget_Base
{

    public function get_name()
    {
        return 'influencer_search_summary';
    }

    public function get_title()
    {
        return esc_html__('Search Summary (Influencer)', 'trb-influencer');
    }

    public function get_icon()
    {
        return 'eicon-text-area';
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
                'raw' => esc_html__('This widget outputs the active search criteria (Niche, Country, Gender, etc.) dynamically. No additional setup is required.', 'trb-influencer'),
            ]
        );

        $this->end_controls_section();
    }

    protected function render()
    {
        echo dd_shortcode_influencer_search_summary();
    }
}
