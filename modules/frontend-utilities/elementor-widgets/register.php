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

    // Core search widgets (each file also defines + registers its shortcode)
    require_once $dir . 'class-widget-influencer-match-score.php';
    require_once $dir . 'class-widget-influencer-search-form.php';
    require_once $dir . 'class-widget-influencer-search-results.php';
    require_once $dir . 'class-widget-influencer-search-summary.php';

    // Module shortcode wrapper widgets
    require_once $dir . 'class-widget-sc-custom-mycred-log.php';
    require_once $dir . 'class-widget-sc-follower-growth-chart.php';
    require_once $dir . 'class-widget-sc-follower-timeline-chart.php';
    require_once $dir . 'class-widget-sc-follower-growth-rate-chart.php';
    require_once $dir . 'class-widget-sc-follower-like-range-chart.php';
    require_once $dir . 'class-widget-sc-creatordb-feed.php';
    require_once $dir . 'class-widget-sc-outreach-list.php';
    require_once $dir . 'class-widget-sc-outreach-view.php';
    require_once $dir . 'class-widget-sc-outreach-credit-cost.php';
    require_once $dir . 'class-widget-sc-outreach-message.php';
    require_once $dir . 'class-widget-sc-outreach-button.php';
    require_once $dir . 'class-widget-sc-my-saved-groups.php';
    require_once $dir . 'class-widget-sc-add-to-groups.php';
    require_once $dir . 'class-widget-sc-remove-from-group.php';
    require_once $dir . 'class-widget-sc-my-saved-searches.php';
    require_once $dir . 'class-widget-sc-pricing-table.php';

    // ── Register core widgets ─────────────────────────────────────────────────
    $wm->register( new Influencer_Match_Score_Widget() );
    $wm->register( new Influencer_Search_Form_Widget() );
    $wm->register( new Influencer_Search_Results_Widget() );
    $wm->register( new Influencer_Search_Summary_Widget() );

    // ── Register module shortcode wrapper widgets ─────────────────────────────
    $wm->register( new DD_Widget_SC_Custom_Mycred_Log() );
    $wm->register( new DD_Widget_SC_Follower_Growth_Chart() );
    $wm->register( new DD_Widget_SC_Follower_Timeline_Chart() );
    $wm->register( new DD_Widget_SC_Follower_Growth_Rate_Chart() );
    $wm->register( new DD_Widget_SC_Follower_Like_Range_Chart() );
    $wm->register( new DD_Widget_SC_Creatordb_Feed() );
    $wm->register( new DD_Widget_SC_Outreach_List() );
    $wm->register( new DD_Widget_SC_Outreach_View() );
    $wm->register( new DD_Widget_SC_Outreach_Credit_Cost() );
    $wm->register( new DD_Widget_SC_Outreach_Message() );
    $wm->register( new DD_Widget_SC_Outreach_Button() );
    $wm->register( new DD_Widget_SC_My_Saved_Groups() );
    $wm->register( new DD_Widget_SC_Add_To_Groups() );
    $wm->register( new DD_Widget_SC_Remove_From_Group() );
    $wm->register( new DD_Widget_SC_My_Saved_Searches() );
    $wm->register( new DD_Widget_SC_Pricing_Table() );
} );
