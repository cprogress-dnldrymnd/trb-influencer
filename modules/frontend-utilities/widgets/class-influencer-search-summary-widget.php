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
        // Leverage your existing OOP method by calling the shortcode
        global $search_results_page_id;
        if ((int) get_queried_object_id() !== $search_results_page_id) return '';

        $brief = isset($_GET['search-brief']) ? trim(sanitize_textarea_field(wp_unslash($_GET['search-brief']))) : '';
        $niche = isset($_GET['niche']) ? (array) $_GET['niche'] : [];
        $country = isset($_GET['country']) ? (array) $_GET['country'] : [];
        $followers = isset($_GET['followers']) ? (array) $_GET['followers'] : [];
        $filter = isset($_GET['filter']) ? (array) $_GET['filter'] : [];
        $gender = isset($_GET['gender']) ? (array) $_GET['gender'] : [];
        $content_tag = isset($_GET['content_tag']) ? (array) $_GET['content_tag'] : [];

        if (empty($brief) && empty($niche) && empty($country) && empty($followers) && empty($gender) && empty($content_tag)) return '';
        $fields = is_array(get_query_var('influencer_search_fields')) ? get_query_var('influencer_search_fields') : [];

        $parts = [];
        if (!empty($niche)) {
            $niche_names = [];
            foreach ($niche as $slug) $niche_names[] = $fields['niche'][$slug] ?? ucfirst($slug);
            $parts[] = implode(', ', $niche_names);
        }
        if (!empty($country)) {
            $country_names = [];
            foreach ($country as $code) $country_names[] = $fields['country'][$code] ?? strtoupper($code);
            $parts[] = implode(', ', $country_names);
        }
        if (!empty($followers) && !empty($followers[0])) {
            $f_opts = $fields['followers'] ?? [];
            $parts[] = $f_opts[$followers[0]] ?? $followers[0];
        }
        if (!empty($gender)) {
            $gender_names = [];
            foreach ($gender as $g) $gender_names[] = $fields['gender'][$g] ?? ucfirst($g);
            $parts[] = implode(', ', $gender_names);
        }
        if (!empty($content_tag)) {
            $tag_names = [];
            foreach ($content_tag as $slug) $tag_names[] = $fields['content_tag'][$slug] ?? ucfirst(str_replace('-', ' ', $slug));
            $parts[] = implode(', ', $tag_names);
        }

        $prioritise_engagement = in_array('Prioritise engagement over reach', $filter, true);
        $engagement_boost_soft = false;
        if (!empty($brief) && function_exists('creatordb_parse_search_brief_structured')) {
            $structured_summary = creatordb_parse_search_brief_structured($brief);
            if (!empty($structured_summary['soft_intents']['engagement_boost'])) {
                $engagement_boost_soft = true;
            }
        }
        $verified_only = in_array('Include only verified influencers', $filter, true);
        $expert_only = in_array('Professional experts only', $filter, true);

?>
        <div class="influencer-search-summary">
            <?php if (!empty($brief)): ?>
                <div class="search-summary-brief search-summary-item">
                    <input type="hidden" name="search-brief" id="search-brief" value="<?= esc_attr($brief) ?>">
                    <div class="summary-brief-label">Your brief:</div>
                    <div class="summary-brief">
                        <div class="summary-brief-inner"><?= wpautop(esc_html(wp_trim_words($brief, 25))) ?></div>
                    </div>
                    <a class="edit-summary-brieft" href="<?= get_the_permalink(2149) ?>?search-brief=<?= urlencode($brief) ?>">EDIT BRIEF</a>
                </div>
            <?php endif; ?>
            <?php if (!empty($parts) && empty($brief)): ?>
                <div class="search-summary-item search-summary-filters"><strong>Filters:</strong> <?= esc_html(implode(' • ', $parts)) ?></div>
            <?php endif; ?>
            <?php if ($prioritise_engagement || $engagement_boost_soft || $verified_only || $expert_only): ?>
                <div class="search-summary-item search-summary-notes">
                    <?php
                    $notes = [];
                    $summary_copy = function_exists('creatordb_brief_summary_note_labels')
                        ? creatordb_brief_summary_note_labels()
                        : [];
                    if ($prioritise_engagement) {
                        $notes[] = '<span>' . esc_html($summary_copy['engagement_hard'] ?? 'Prioritising engagement over reach') . '</span>';
                    } elseif ($engagement_boost_soft) {
                        $notes[] = '<span>' . esc_html($summary_copy['engagement_soft'] ?? 'Engagement preference (sort boost — not a hard filter)') . '</span>';
                    }
                    if ($verified_only) {
                        $notes[] = '<span>' . esc_html($summary_copy['verified'] ?? 'Include only verified influencers') . '</span>';
                    }
                    if ($expert_only) {
                        $notes[] = '<span>' . esc_html($summary_copy['expert'] ?? 'Professional experts only') . '</span>';
                    }
                    echo implode(' • ', $notes);
                    ?>
                </div>
            <?php endif; ?>
            <?php if (function_exists('creatordb_brief_search_debug_enabled') && creatordb_brief_search_debug_enabled()) : ?>
                <div id="ic-brief-search-debug" class="ic-brief-search-debug" aria-live="polite">
                    <details open>
                        <summary>Brief search debug (dev)</summary>
                        <p class="ic-brief-search-debug-hint">Runs after each search. Requires <code>WP_DEBUG</code> or <code>IC_BRIEF_SEARCH_DEBUG</code> in wp-config.</p>
                        <pre class="ic-brief-search-debug-body">Waiting for search AJAX…</pre>
                    </details>
                </div>
                <style>
                    .ic-brief-search-debug {
                        margin: 1rem 0;
                        padding: 0.75rem 1rem;
                        background: #1e1e2e;
                        color: #cdd6f4;
                        border-radius: 8px;
                        font-size: 12px;
                    }

                    .ic-brief-search-debug summary {
                        cursor: pointer;
                        font-weight: 600;
                        color: #89b4fa;
                    }

                    .ic-brief-search-debug-hint {
                        opacity: 0.85;
                        margin: 0.5rem 0;
                    }

                    .ic-brief-search-debug-body {
                        max-height: 420px;
                        overflow: auto;
                        white-space: pre-wrap;
                        word-break: break-word;
                        margin: 0;
                    }
                </style>
            <?php endif; ?>
        </div>
<?php
    }
}
