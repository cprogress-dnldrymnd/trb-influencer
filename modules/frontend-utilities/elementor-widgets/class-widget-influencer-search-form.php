<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Shortcode ─────────────────────────────────────────────────────────────────

function dd_shortcode_influencer_search_form( $atts = [] ) {
    $atts = shortcode_atts( [
        'layout'   => 'main',
        'btn_text' => 'GENERATE MATCHES',
    ], $atts );

    $layout   = $atts['layout'];
    $btn_text = $atts['btn_text'];

    $raw_fields               = get_query_var( 'influencer_search_fields' );
    $influencer_search_fields = is_array( $raw_fields ) ? $raw_fields : [];
    $influencer_search_page   = get_query_var( 'influencer_search_page' );
    $form_action              = $influencer_search_page ? get_the_permalink( $influencer_search_page ) : '';
    $brief                    = isset( $_GET['search-brief'] ) ? trim( sanitize_textarea_field( wp_unslash( $_GET['search-brief'] ) ) ) : '';

    ob_start();

    if ( $layout === 'sidebar' ) {
        ?>
        <form class="influencer-search" action="<?= esc_url( $form_action ) ?>" method="GET">
            <div class="influencer-search-filter-holder">
                <div class="influencer-search-item niche-filters">
                    <?= Influencer_Search::select_filter( 'niche', 'Tag Filter', 'Select your tag filters', $influencer_search_fields['niche'] ?? '', 'checkbox', true ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::select_filter( 'min_followers', 'Minimum Followers', 'Select Minimum Followers', $influencer_search_fields['followers'] ?? '', 'radio' ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::select_filter( 'max_followers', 'Maximum Followers', 'Select Maximum Followers', $influencer_search_fields['followers'] ?? '', 'radio' ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::select_filter( 'country', 'Location', 'Select a new location', $influencer_search_fields['country'] ?? '', 'checkbox', true ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::select_filter( 'lang', 'Language', 'Select a new language', $influencer_search_fields['lang'] ?? '', 'checkbox', true ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::select_filter( 'gender', 'Gender', 'Select Gender', $influencer_search_fields['gender'] ?? '', 'checkbox', true ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::select_filter( 'content_tag', 'Hashtags', 'Search hashtags...', $influencer_search_fields['content_tag'] ?? '', 'checkbox', true ) ?>
                </div>
                <div class="influencer-search-item">
                    <?= Influencer_Search::checkbox_filter( 'filter', false, $influencer_search_fields['filter'] ?? '' ) ?>
                </div>
                <div class="influencer-search-item">
                    <button type="submit" class="influencer-search-button influencer-search-trigger elementor-button elementor-button-link elementor-size-sm">
                        <span class="elementor-button-content-wrapper"><span class="elementor-button-text"><?= esc_html( $btn_text ) ?></span></span>
                    </button>
                </div>
            </div>
        </form>
        <?php
    } else {
        $is_brief_active = ! empty( $brief );
        $checked_attr    = $is_brief_active ? 'checked="checked"' : '';
        ?>
        <form class="influencer-search influencer-search-main" action="<?= esc_url( $form_action ) ?>" method="GET">
            <div id="search-header">
                <div class="toggle-holder">
                    <div class="filtered-search toggle-text <?= ! $is_brief_active ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="23.66" height="20" viewBox="0 0 23.66 20"><path id="target" d="M24.044,20.152A10.187,10.187,0,0,1,24.1,21.2,10,10,0,1,1,19.973,13.1l-.745,2.778a7.375,7.375,0,1,0,2.037,3.527l2.777.744ZM13.436,21.579a.764.764,0,0,0,1.045.278l6.549-3.781,2.312.619,4.414-2.549-3.356-.9.9-3.356-4.414,2.549-.619,2.312-6.551,3.782a.764.764,0,0,0-.278,1.045Zm.661-3.032a2.671,2.671,0,0,1,.518.05L17.2,17.106a5.132,5.132,0,1,0,2.03,4.089,5.173,5.173,0,0,0-.04-.641l-2.582,1.491a2.649,2.649,0,1,1-2.51-3.5Z" transform="translate(-4.097 -11.195)" fill="#00a6ed" fill-rule="evenodd"></path></svg>
                        <span>FILTERED SEARCH</span>
                    </div>
                    <div class="toggle-html">
                        <label class="toggle-switch">
                            <input type="checkbox" id="my-toggle" <?= $checked_attr ?>>
                            <span class="slider round"></span>
                        </label>
                    </div>
                    <div class="full-brief-search toggle-text <?= $is_brief_active ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 46.322 46.948"><path id="sparkers" d="M15.96,24.3a.809.809,0,0,0,.851-.751c.9-6.685,1.127-6.685,8.038-8.012a.847.847,0,0,0,.776-.851.864.864,0,0,0-.776-.851c-6.911-.951-7.161-1.177-8.038-7.987a.84.84,0,0,0-1.678.025c-.826,6.71-1.177,6.685-8.037,7.962a.884.884,0,0,0-.776.851c0,.5.326.776.876.851,6.811,1.1,7.111,1.277,7.937,7.962A.811.811,0,0,0,15.96,24.3ZM32.937,52.02a1.289,1.289,0,0,0,1.252-1.152c1.778-13.721,3.706-15.8,17.277-17.3a1.256,1.256,0,0,0,1.177-1.252,1.274,1.274,0,0,0-1.177-1.252c-13.571-1.5-15.5-3.581-17.277-17.3a1.266,1.266,0,0,0-1.252-1.127,1.225,1.225,0,0,0-1.227,1.127c-1.778,13.721-3.731,15.8-17.277,17.3a1.277,1.277,0,0,0-1.2,1.252,1.26,1.26,0,0,0,1.2,1.252c13.521,1.778,15.4,3.606,17.277,17.3A1.248,1.248,0,0,0,32.937,52.02Z" transform="translate(-6.32 -5.073)" fill="#ffe17b"></path></svg>
                        <span>FULL BRIEF SEARCH</span>
                    </div>
                </div>
                <div class="advanced-search-trigger">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                    <span>Advanced Search</span>
                </div>
            </div>

            <div class="influencer-search-filter-holder">
                <input type="hidden" value="true" name="search_active">

                <div class="influencer-search-item-row influencer-search-item-wrapper filtered-search <?= ! $is_brief_active ? 'active' : '' ?>">
                    <div class="influencer-search-item">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Location</div>
                        <?= Influencer_Search::select_filter( 'country', false, 'Location', $influencer_search_fields['country'] ?? '', 'checkbox', true ) ?>
                    </div>
                    <div class="influencer-search-item">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Language</div>
                        <?= Influencer_Search::select_filter( 'lang', false, 'Language', $influencer_search_fields['lang'] ?? '', 'checkbox', true ) ?>
                    </div>
                    <div class="influencer-search-item required-on-search">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Niche</div>
                        <?= Influencer_Search::select_filter( 'niche', false, 'Niche', $influencer_search_fields['niche'] ?? '', 'checkbox', true ) ?>
                    </div>
                    <div class="influencer-search-item">
                        <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Follower Count</div>
                        <div class="field-groups">
                            <?= Influencer_Search::select_filter( 'min_followers', false, 'Minimum', $influencer_search_fields['followers'] ?? '', 'radio' ) ?>
                            <?= Influencer_Search::select_filter( 'max_followers', false, 'Maximum', $influencer_search_fields['followers'] ?? '', 'radio' ) ?>
                        </div>
                    </div>
                </div>

                <div class="filtered-search <?= ! $is_brief_active ? 'active' : '' ?>">
                    <div class="advanced-search-filters" style="display: none;">
                        <div class="influencer-search-item-row influencer-search-item-wrapper">
                            <div class="influencer-search-item">
                                <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Gender</div>
                                <?= Influencer_Search::select_filter( 'gender', false, 'Select Gender', $influencer_search_fields['gender'] ?? '', 'checkbox', true ) ?>
                            </div>
                            <div class="influencer-search-item">
                                <div class="influencer-search-item-title" style="display: flex; align-items: center; gap: 7px">Hashtags</div>
                                <?= Influencer_Search::select_filter( 'content_tag', false, 'Search hashtags...', $influencer_search_fields['content_tag'] ?? '', 'checkbox', true ) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="influencer-search-item influencer-search-item-wrapper influencer-search-item-field full-brief-search <?= $is_brief_active ? 'active' : '' ?>">
                    <textarea rows="6" name="search-brief" id="search-brief" placeholder="Tell us what you're looking for..." <?= $is_brief_active ? 'required' : '' ?>><?= esc_html( $brief ) ?></textarea>
                </div>

                <div class="influencer-search-item checkbox-row">
                    <?= Influencer_Search::checkbox_filter( 'filter', false, $influencer_search_fields['filter'] ?? '' ) ?>
                </div>

                <div class="influencer-search-item" style="display: flex; justify-content: space-between">
                    <button type="button" class="reset-filters-btn elementor-button elementor-button-outline elementor-size-sm">
                        <span class="elementor-button-content-wrapper">
                            <span class="elementor-button-icon elementor-align-icon-left"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></span>
                            <span class="elementor-button-text">RESET ALL</span>
                        </span>
                    </button>
                    <button type="submit" class="influencer-search-button elementor-button elementor-button-link elementor-size-sm">
                        <span class="elementor-button-content-wrapper"><span class="elementor-button-text"><?= esc_html( $btn_text ) ?></span></span>
                    </button>
                </div>
            </div>
        </form>
        <?php
    }

    return ob_get_clean();
}
add_shortcode( 'influencer_search_form', 'dd_shortcode_influencer_search_form' );

// ── Elementor widget ──────────────────────────────────────────────────────────

class Influencer_Search_Form_Widget extends \Elementor\Widget_Base {

    public function get_name()       { return 'influencer_search_form'; }
    public function get_title()      { return esc_html__( 'Influencer Search Form', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-search'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Form Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'form_layout', [
            'label'   => esc_html__( 'Layout', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'main',
            'options' => [
                'main'    => esc_html__( 'Main (toggle + brief + advanced)', 'trb-influencer' ),
                'sidebar' => esc_html__( 'Sidebar (compact filters only)', 'trb-influencer' ),
            ],
        ] );
        $this->add_control( 'btn_text', [
            'label'   => esc_html__( 'Button Text', 'trb-influencer' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'GENERATE MATCHES',
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();
        echo do_shortcode(
            '[influencer_search_form layout="' . esc_attr( $s['form_layout'] ) . '" btn_text="' . esc_attr( $s['btn_text'] ) . '"]'
        );
    }
}
