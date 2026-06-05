<?php
/**
 * Elementor widget wrappers for module shortcodes.
 * Each widget simply renders its corresponding shortcode tag.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Helper: shared base for all thin shortcode-wrapper widgets ───────────────

abstract class DD_Shortcode_Widget_Base extends \Elementor\Widget_Base {

    abstract protected function shortcode_tag(): string;

    public function get_categories() {
        return [ 'general' ];
    }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => sprintf(
                esc_html__( 'Renders the %s shortcode. No additional configuration needed.', 'trb-influencer' ),
                '<code>[' . $this->shortcode_tag() . ']</code>'
            ),
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[' . $this->shortcode_tag() . ']' );
    }
}

// ─── myCred Log ──────────────────────────────────────────────────────────────

class DD_Widget_SC_Custom_Mycred_Log extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_custom_mycred_log'; }
    public function get_title() { return esc_html__( 'myCred Log', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-posts-group'; }
    protected function shortcode_tag(): string { return 'custom_mycred_log'; }
}

// ─── Charts ───────────────────────────────────────────────────────────────────

class DD_Widget_SC_Follower_Growth_Chart extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_follower_growth_chart'; }
    public function get_title() { return esc_html__( 'Follower Growth Chart', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-bar-chart'; }
    protected function shortcode_tag(): string { return 'follower_growth_chart'; }
}

class DD_Widget_SC_Follower_Timeline_Chart extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_follower_timeline_chart'; }
    public function get_title() { return esc_html__( 'Follower Timeline Chart', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-line-chart'; }
    protected function shortcode_tag(): string { return 'follower_timeline_chart'; }
}

class DD_Widget_SC_Follower_Growth_Rate_Chart extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_follower_growth_rate_chart'; }
    public function get_title() { return esc_html__( 'Follower Growth Rate Chart', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-bar-chart'; }
    protected function shortcode_tag(): string { return 'follower_growth_rate_chart'; }
}

class DD_Widget_SC_Follower_Like_Range_Chart extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_follower_like_range_chart'; }
    public function get_title() { return esc_html__( 'Follower Like Range Chart', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-bar-chart'; }
    protected function shortcode_tag(): string { return 'follower_like_range_chart'; }
}

// ─── Feeds ────────────────────────────────────────────────────────────────────

class DD_Widget_SC_Creatordb_Feed extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_creatordb_feed'; }
    public function get_title() { return esc_html__( 'CreatorDB Feed', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-instagram-gallery'; }
    protected function shortcode_tag(): string { return 'creatordb_feed'; }
}

// ─── Outreach ─────────────────────────────────────────────────────────────────

class DD_Widget_SC_Outreach_List extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_dd_outreach_list'; }
    public function get_title() { return esc_html__( 'Outreach List', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-post-list'; }
    protected function shortcode_tag(): string { return 'dd_outreach_list'; }
}

class DD_Widget_SC_Outreach_View extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_dd_outreach_view'; }
    public function get_title() { return esc_html__( 'Outreach Detail View', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-post-content'; }
    protected function shortcode_tag(): string { return 'dd_outreach_view'; }
}

class DD_Widget_SC_Outreach_Credit_Cost extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_dd_outreach_credit_cost'; }
    public function get_title() { return esc_html__( 'Outreach Credit Cost', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-price-table'; }
    protected function shortcode_tag(): string { return 'dd_outreach_credit_cost'; }
}

class DD_Widget_SC_Outreach_Message extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_outreach_message'; }
    public function get_title() { return esc_html__( 'Outreach Message', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-mail'; }
    protected function shortcode_tag(): string { return 'outreach_message'; }
}

class DD_Widget_SC_Outreach_Button extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_outreach_button'; }
    public function get_title() { return esc_html__( 'Outreach Button', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-button'; }
    protected function shortcode_tag(): string { return 'outreach_button'; }
}

// ─── Saves ────────────────────────────────────────────────────────────────────

class DD_Widget_SC_My_Saved_Groups extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_my_saved_groups'; }
    public function get_title() { return esc_html__( 'My Saved Groups', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-folder'; }
    protected function shortcode_tag(): string { return 'my_saved_groups'; }
}

class DD_Widget_SC_Add_To_Groups extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_add_to_groups_btn'; }
    public function get_title() { return esc_html__( 'Add to Groups Button', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-folder-o'; }
    protected function shortcode_tag(): string { return 'add_to_groups_btn'; }
}

class DD_Widget_SC_Remove_From_Group extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_remove_from_group_btn'; }
    public function get_title() { return esc_html__( 'Remove from Group Button', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-trash'; }
    protected function shortcode_tag(): string { return 'remove_from_group_btn'; }
}

class DD_Widget_SC_My_Saved_Searches extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_my_saved_searches'; }
    public function get_title() { return esc_html__( 'My Saved Searches', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-search'; }
    protected function shortcode_tag(): string { return 'my_saved_searches'; }
}

// ─── Membership ───────────────────────────────────────────────────────────────

class DD_Widget_SC_Pricing_Table extends DD_Shortcode_Widget_Base {
    public function get_name()  { return 'sc_dd_pricing_table'; }
    public function get_title() { return esc_html__( 'Pricing Table', 'trb-influencer' ); }
    public function get_icon()  { return 'eicon-price-table'; }
    protected function shortcode_tag(): string { return 'dd_pricing_table'; }
}
