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
        $post_id  = get_query_var('current_influencer_id') ?: get_the_ID();
        $criteria = get_query_var('search_criteria');
        $criteria = is_array($criteria) ? $criteria : [];
        $score    = self::calculate_match_score($post_id, $criteria);

        if ($score < 0) {
            if (function_exists('creatordb_brief_match_score_badge_html')) {
                return creatordb_brief_match_score_badge_html(-1);
            }
            return '<span class="influencer-match-score-wrap">— Match Score</span>';
        }

        $badge_label = function_exists('creatordb_brief_match_score_badge_html')
            ? creatordb_brief_match_score_badge_html((int) $score)
            : ('✨ ' . (int) $score . '% Match Score');

        $tooltip = function_exists('creatordb_get_match_evidence_tooltip_html')
            ? creatordb_get_match_evidence_tooltip_html($post_id, $criteria)
            : implode("\n", self::get_matched_criteria_labels($post_id, $criteria));

        $html = '<div class="influencer-match-score-wrap tooltip-wrapper"><span class="influencer-match-score-trigger tooltip-trigger">' . esc_html($badge_label) . '</span>';
        if ($tooltip !== '') {
            $html .= '<div class="influencer-match-score-tooltip"><span class="influencer-match-score-checklist">' . $tooltip . '</span></div>';
        }
        $html .= '</div>';
        return $html;
    }
}
