<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Feature_Comparison_Table extends \Elementor\Widget_Base {

    // Every Style-tab control lives in the trait, shared with Widget_Pricing_Table — both widgets
    // render the same .dd-fc-* grid, so their styling must stay defined in one place.
    use DD_Comparison_Table_Style_Controls;

    public function get_name()       { return 'sc_dd_comparison_table'; }
    public function get_title()      { return esc_html__( 'Comparison Pricing Table', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-price-table'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [dd_feature_comparison]. Columns, feature rows, and cell content (tick/cross/text) are authored on the Influencer Theme → Pricing Tables settings tab — this widget only controls how the table looks. Every configured column is shown, with the plain CTA button each column defines; for membership-aware buttons use the "Pricing Table" widget instead.', 'trb-influencer' ),
        ] );
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->register_comparison_style_controls();

        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode( '[dd_feature_comparison]' );
    }
}
