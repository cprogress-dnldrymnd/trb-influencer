<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Match Score ─────────────────────────────────────────────────────────────

function dd_shortcode_influencer_match_score( $atts = [] ) {
    $post_id  = get_query_var( 'current_influencer_id' ) ?: get_the_ID();
    $criteria = get_query_var( 'search_criteria' );
    $criteria = is_array( $criteria ) ? $criteria : [];

    $score = calculate_match_score( $post_id, $criteria );

    if ( $score < 0 ) {
        if ( function_exists( 'creatordb_brief_match_score_badge_html' ) ) {
            return creatordb_brief_match_score_badge_html( -1 );
        }
        return '<span class="influencer-match-score-wrap">— Match Score</span>';
    }

    $badge_label = function_exists( 'creatordb_brief_match_score_badge_html' )
        ? creatordb_brief_match_score_badge_html( (int) $score )
        : ( '✨ ' . (int) $score . '% Match Score' );

    $tooltip = function_exists( 'creatordb_get_match_evidence_tooltip_html' )
        ? creatordb_get_match_evidence_tooltip_html( $post_id, $criteria )
        : implode( "\n", get_matched_criteria_labels( $post_id, $criteria ) );

    $html  = '<div class="influencer-match-score-wrap tooltip-wrapper">';
    $html .= '<span class="influencer-match-score-trigger tooltip-trigger">' . esc_html( $badge_label ) . '</span>';
    if ( ! empty( trim( $tooltip ) ) ) {
        $html .= '<div class="influencer-match-score-tooltip tooltip-content">' . wp_kses_post( $tooltip ) . '</div>';
    }
    $html .= '</div>';

    return $html;
}
add_shortcode( 'influencer_match_score', 'dd_shortcode_influencer_match_score' );

// ─── Search Summary ──────────────────────────────────────────────────────────

function dd_shortcode_influencer_search_summary( $atts = [] ) {
    global $search_results_page_id;
    if ( (int) get_queried_object_id() !== $search_results_page_id ) {
        return '';
    }

    $brief       = isset( $_GET['search-brief'] ) ? trim( sanitize_textarea_field( wp_unslash( $_GET['search-brief'] ) ) ) : '';
    $niche       = isset( $_GET['niche'] )        ? (array) $_GET['niche']       : [];
    $country     = isset( $_GET['country'] )      ? (array) $_GET['country']     : [];
    $followers   = isset( $_GET['followers'] )    ? (array) $_GET['followers']   : [];
    $filter      = isset( $_GET['filter'] )       ? (array) $_GET['filter']      : [];
    $gender      = isset( $_GET['gender'] )       ? (array) $_GET['gender']      : [];
    $content_tag = isset( $_GET['content_tag'] )  ? (array) $_GET['content_tag'] : [];

    if ( empty( $brief ) && empty( $niche ) && empty( $country ) && empty( $followers ) && empty( $gender ) && empty( $content_tag ) ) {
        return '';
    }

    $fields = is_array( get_query_var( 'influencer_search_fields' ) ) ? get_query_var( 'influencer_search_fields' ) : [];

    $parts = [];
    if ( ! empty( $niche ) ) {
        $niche_names = [];
        foreach ( $niche as $slug ) {
            $niche_names[] = $fields['niche'][ $slug ] ?? ucfirst( $slug );
        }
        $parts[] = implode( ', ', $niche_names );
    }
    if ( ! empty( $country ) ) {
        $country_names = [];
        foreach ( $country as $code ) {
            $country_names[] = $fields['country'][ $code ] ?? strtoupper( $code );
        }
        $parts[] = implode( ', ', $country_names );
    }
    if ( ! empty( $followers ) && ! empty( $followers[0] ) ) {
        $f_opts  = $fields['followers'] ?? [];
        $parts[] = $f_opts[ $followers[0] ] ?? $followers[0];
    }
    if ( ! empty( $gender ) ) {
        $gender_names = [];
        foreach ( $gender as $g ) {
            $gender_names[] = $fields['gender'][ $g ] ?? ucfirst( $g );
        }
        $parts[] = implode( ', ', $gender_names );
    }
    if ( ! empty( $content_tag ) ) {
        $tag_names = [];
        foreach ( $content_tag as $slug ) {
            $tag_names[] = $fields['content_tag'][ $slug ] ?? ucfirst( str_replace( '-', ' ', $slug ) );
        }
        $parts[] = implode( ', ', $tag_names );
    }

    $prioritise_engagement = in_array( 'Prioritise engagement over reach', $filter, true );
    $engagement_boost_soft = false;
    if ( ! empty( $brief ) && function_exists( 'creatordb_parse_search_brief_structured' ) ) {
        $structured_summary = creatordb_parse_search_brief_structured( $brief );
        if ( ! empty( $structured_summary['soft_intents']['engagement_boost'] ) ) {
            $engagement_boost_soft = true;
        }
    }
    $verified_only = in_array( 'Include only verified influencers', $filter, true );
    $expert_only   = in_array( 'Professional experts only', $filter, true );

    ob_start();
    ?>
    <div class="influencer-search-summary">
        <?php if ( ! empty( $brief ) ) : ?>
            <div class="search-summary-brief search-summary-item">
                <input type="hidden" name="search-brief" id="search-brief" value="<?= esc_attr( $brief ) ?>">
                <div class="summary-brief-label">Your brief:</div>
                <div class="summary-brief">
                    <div class="summary-brief-inner"><?= wpautop( esc_html( wp_trim_words( $brief, 25 ) ) ) ?></div>
                </div>
                <a class="edit-summary-brieft" href="<?= get_the_permalink( 2149 ) ?>?search-brief=<?= urlencode( $brief ) ?>">EDIT BRIEF</a>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $parts ) && empty( $brief ) ) : ?>
            <div class="search-summary-item search-summary-filters"><strong>Filters:</strong> <?= esc_html( implode( ' • ', $parts ) ) ?></div>
        <?php endif; ?>
        <?php if ( $prioritise_engagement || $engagement_boost_soft || $verified_only || $expert_only ) : ?>
            <div class="search-summary-item search-summary-notes">
                <?php
                $notes        = [];
                $summary_copy = function_exists( 'creatordb_brief_summary_note_labels' )
                    ? creatordb_brief_summary_note_labels()
                    : [];
                if ( $prioritise_engagement ) {
                    $notes[] = '<span>' . esc_html( $summary_copy['engagement_hard'] ?? 'Prioritising engagement over reach' ) . '</span>';
                } elseif ( $engagement_boost_soft ) {
                    $notes[] = '<span>' . esc_html( $summary_copy['engagement_soft'] ?? 'Engagement preference (sort boost — not a hard filter)' ) . '</span>';
                }
                if ( $verified_only ) {
                    $notes[] = '<span>' . esc_html( $summary_copy['verified'] ?? 'Include only verified influencers' ) . '</span>';
                }
                if ( $expert_only ) {
                    $notes[] = '<span>' . esc_html( $summary_copy['expert'] ?? 'Professional experts only' ) . '</span>';
                }
                echo implode( ' • ', $notes );
                ?>
            </div>
        <?php endif; ?>
        <?php if ( function_exists( 'creatordb_brief_search_debug_enabled' ) && creatordb_brief_search_debug_enabled() ) : ?>
            <div id="ic-brief-search-debug" class="ic-brief-search-debug" aria-live="polite">
                <details open>
                    <summary>Brief search debug (dev)</summary>
                    <p class="ic-brief-search-debug-hint">Runs after each search. Requires <code>WP_DEBUG</code> or <code>IC_BRIEF_SEARCH_DEBUG</code> in wp-config.</p>
                    <pre class="ic-brief-search-debug-body">Waiting for search AJAX…</pre>
                </details>
            </div>
            <style>
                .ic-brief-search-debug { margin: 1rem 0; padding: .75rem 1rem; background: #1e1e2e; color: #cdd6f4; border-radius: 8px; font-size: 12px; }
                .ic-brief-search-debug summary { cursor: pointer; font-weight: 600; color: #89b4fa; }
                .ic-brief-search-debug-hint { opacity: .85; margin: .5rem 0; }
                .ic-brief-search-debug-body { max-height: 420px; overflow: auto; white-space: pre-wrap; word-break: break-word; margin: 0; }
            </style>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'influencer_search_summary', 'dd_shortcode_influencer_search_summary' );

// ─── Search Results Grid ─────────────────────────────────────────────────────

function dd_shortcode_influencer_search_results( $atts = [] ) {
    ob_start();
    ?>
    <div class="influencer-grid-box">
        <div id="my-loop-grid-container" class="influencer-loop-grid"></div>
        <div class="loading-animation" style="display: none;">
            <span class="loading-icon">
                <img src="https://influencer.theprogressteam.com/wp-content/uploads/2026/01/Spin@1x-1.0s-200px-200px.svg" alt="Loading...">
            </span>
        </div>
    </div>
    <div class="load-more-wrapper">
        <button id="load-more-influencers" class="elementor-button" style="display: none;">
            Load More
        </button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'influencer_search_results', 'dd_shortcode_influencer_search_results' );

// ─── Search Form ─────────────────────────────────────────────────────────────

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
