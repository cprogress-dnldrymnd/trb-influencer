<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Custom Elementor category ─────────────────────────────────────────────────

add_action( 'elementor/elements/categories_registered', function ( \Elementor\Elements_Manager $em ) {
    $em->add_category( 'influencer-collective', [
        'title' => esc_html__( 'Influencer Collective', 'trb-influencer' ),
        'icon'  => 'eicon-person',
    ] );
} );

// ── Load and register all widgets ─────────────────────────────────────────────

add_action( 'elementor/widgets/register', function ( \Elementor\Widgets_Manager $wm ) {
    if ( ! did_action( 'elementor/loaded' ) ) {
        return;
    }

    $dir = get_stylesheet_directory() . '/modules/frontend-utilities/elementor-widgets/';

    // Core search widgets (shortcodes are registered by Influencer_Search)
    require_once $dir . 'class-widget-influencer-match-score.php';
    require_once $dir . 'class-widget-influencer-search-form.php';
    require_once $dir . 'class-widget-influencer-search-results.php';
    require_once $dir . 'class-widget-influencer-search-summary.php';

    // Module shortcode wrapper widgets
    require_once $dir . 'class-widget-custom-mycred-log.php';
    require_once $dir . 'class-widget-follower-growth-chart.php';
    require_once $dir . 'class-widget-follower-timeline-chart.php';
    require_once $dir . 'class-widget-follower-growth-rate-chart.php';
    require_once $dir . 'class-widget-follower-like-range-chart.php';
    require_once $dir . 'class-widget-platform-switcher.php';
    require_once $dir . 'class-widget-creatordb-feed.php';
    require_once $dir . 'class-widget-outreach-list.php';
    require_once $dir . 'class-widget-outreach-view.php';
    require_once $dir . 'class-widget-outreach-credit-cost.php';
    require_once $dir . 'class-widget-outreach-message.php';
    require_once $dir . 'class-widget-outreach-button.php';
    require_once $dir . 'class-widget-my-saved-groups.php';
    require_once $dir . 'class-widget-add-to-groups.php';
    require_once $dir . 'class-widget-remove-from-group.php';
    require_once $dir . 'class-widget-my-saved-searches.php';
    require_once $dir . 'class-widget-pricing-table.php';
    require_once $dir . 'class-widget-influencer-niche.php';
    require_once $dir . 'class-widget-influencer-niches.php';
    require_once $dir . 'class-widget-influencer-topics.php';
    require_once $dir . 'class-widget-influencer-hashtags.php';
    require_once $dir . 'class-widget-influencer-unlocked-badge.php';

    // ── Register core widgets ─────────────────────────────────────────────────
    $wm->register( new Influencer_Match_Score_Widget() );
    $wm->register( new Influencer_Search_Form_Widget() );
    $wm->register( new Influencer_Search_Results_Widget() );
    $wm->register( new Influencer_Search_Summary_Widget() );

    // ── Register module shortcode wrapper widgets ─────────────────────────────
    $wm->register( new Widget_Custom_Mycred_Log() );
    $wm->register( new Widget_Follower_Growth_Chart() );
    $wm->register( new Widget_Follower_Timeline_Chart() );
    $wm->register( new Widget_Follower_Growth_Rate_Chart() );
    $wm->register( new Widget_Follower_Like_Range_Chart() );
    $wm->register( new Widget_Platform_Switcher() );
    $wm->register( new Widget_Creatordb_Feed() );
    $wm->register( new Widget_Outreach_List() );
    $wm->register( new Widget_Outreach_View() );
    $wm->register( new Widget_Outreach_Credit_Cost() );
    $wm->register( new Widget_Outreach_Message() );
    $wm->register( new Widget_Outreach_Button() );
    $wm->register( new Widget_My_Saved_Groups() );
    $wm->register( new Widget_Add_To_Groups() );
    $wm->register( new Widget_Remove_From_Group() );
    $wm->register( new Widget_My_Saved_Searches() );
    $wm->register( new Widget_Pricing_Table() );
    $wm->register( new Widget_Influencer_Niche() );
    $wm->register( new Widget_Influencer_Niches() );
    $wm->register( new Widget_Influencer_Topics() );
    $wm->register( new Widget_Influencer_Hashtags() );
    $wm->register( new Widget_Influencer_Unlocked_Badge() );
} );
