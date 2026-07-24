<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Widget_Pricing_Table extends \Elementor\Widget_Base {

    public function get_name()       { return 'sc_dd_pricing_table'; }
    public function get_title()      { return esc_html__( 'Pricing Table', 'trb-influencer' ); }
    public function get_icon()       { return 'eicon-price-table'; }
    public function get_categories() { return [ 'influencer-collective' ]; }

    /**
     * Every paid, signup-enabled PMPro plan available to order — id + name, in PMPro
     * Membership Plans settings-screen order. Powers both the repeater's default rows and
     * its per-row plan SELECT options.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function get_available_plans() {
        if ( ! class_exists( 'DD_PMPro_Frontend_Pricing' ) || ! function_exists( 'pmpro_getAllLevels' ) ) {
            return [];
        }
        return DD_PMPro_Frontend_Pricing::get_orderable_plans();
    }

    protected function register_controls() {
        $plans = $this->get_available_plans();

        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Settings', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'info', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => esc_html__( 'Renders [dd_pricing_table]. Displays the dynamic membership pricing table.', 'trb-influencer' ),
        ] );

        if ( empty( $plans ) ) {
            $this->add_control( 'no_plans_notice', [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                'raw'  => esc_html__( 'No paid PMPro plans were found to order. Add/enable signups on a level, then reload this panel.', 'trb-influencer' ),
                'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
            ] );
        } else {
            $plan_options = [];
            foreach ( $plans as $plan ) {
                $plan_options[ $plan['id'] ] = $plan['name'];
            }

            $repeater = new \Elementor\Repeater();
            $repeater->add_control( 'plan_id', [
                'label'   => esc_html__( 'Plan', 'trb-influencer' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $plan_options,
                'default' => array_key_first( $plan_options ),
            ] );
            // Hidden — mirrors plan_id's default label so collapsed rows show the plan name
            // without extra JS wiring. Only accurate for the auto-seeded rows below; if a row's
            // Plan dropdown is manually changed to a different plan, the collapsed title keeps
            // showing the original name (cosmetic only — order/render still use plan_id).
            $repeater->add_control( 'plan_name', [
                'type'    => \Elementor\Controls_Manager::HIDDEN,
                'default' => '',
            ] );

            $default_rows = [];
            foreach ( $plans as $plan ) {
                $default_rows[] = [
                    'plan_id'   => $plan['id'],
                    'plan_name' => $plan['name'],
                ];
            }

            $this->add_control( 'plan_order', [
                'label'       => esc_html__( 'Plan Order', 'trb-influencer' ),
                'type'        => \Elementor\Controls_Manager::REPEATER,
                'fields'      => $repeater->get_controls(),
                'default'     => $default_rows,
                'title_field' => '{{{ plan_name }}}',
                'description' => esc_html__( 'Drag to reorder. Every available paid plan is listed automatically — newly added plans appear here (and on the front end) the next time this panel loads.', 'trb-influencer' ),
            ] );
        }

        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [
            'label' => esc_html__( 'Style', 'trb-influencer' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        $this->add_responsive_control( 'columns', [
            'label'           => esc_html__( 'Columns', 'trb-influencer' ),
            'type'            => \Elementor\Controls_Manager::SELECT,
            'options'         => [
                '1' => '1',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
            ],
            'default'         => '3',
            'tablet_default'  => '2',
            'mobile_default'  => '1',
            'selectors'       => [
                '{{WRAPPER}} .dd-pricing-container' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
            ],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $order_ids = [];
        if ( ! empty( $settings['plan_order'] ) && is_array( $settings['plan_order'] ) ) {
            foreach ( $settings['plan_order'] as $row ) {
                if ( ! empty( $row['plan_id'] ) ) {
                    $order_ids[] = (int) $row['plan_id'];
                }
            }
        }

        $attrs = '';
        if ( ! empty( $order_ids ) ) {
            $attrs .= ' order="' . esc_attr( implode( ',', $order_ids ) ) . '"';
        }

        echo do_shortcode( '[dd_pricing_table' . $attrs . ']' );
    }
}
