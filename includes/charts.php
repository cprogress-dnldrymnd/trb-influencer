<?php
/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders a 12-month follower growth chart using ApexCharts, pulling dynamic data from post meta.
 * Version: 1.0.1
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-follower-chart
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Core class responsible for handling the Follower Growth Chart logic,
 * data transformations, and frontend rendering via shortcode.
 */
class DD_Follower_Growth_Chart {

    /**
     * Initializes the plugin by hooking into WordPress core actions and registering shortcodes.
     *
     * @return void
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_shortcode( 'follower_growth_chart', [ $this, 'render_shortcode' ] );
    }

    /**
     * Transforms raw daily/weekly follower statistics into a structured, 12-month dataset for frontend charting.
     *
     * This function extracts the final recorded follower count for each month, calculates the 
     * month-over-month difference (gain/loss), and formats the output into arrays perfectly 
     * structured for ApexCharts consumption.
     *
     * @param array $raw_data The raw multidimensional array of timeline statistics.
     * @return array Associative array containing 'labels' (x-axis), 'gains' (y-axis deltas), and 'totals' (y-axis absolutes).
     */
    private function prepare_monthly_chart_data( array $raw_data ): array {
        // Return empty payload if no valid array is passed
        if ( empty( $raw_data ) ) {
            return [ 'labels' => [], 'gains' => [], 'totals' => [] ];
        }

        $monthly_snapshots = [];
        
        // Group data by year-month and isolate the latest entry per month.
        foreach ( $raw_data as $entry ) {
            // Fallback for timestamp processing if date string is malformed
            $date = isset( $entry['date'] ) ? new DateTime( $entry['date'] ) : ( new DateTime() )->setTimestamp( $entry['timestamp_ms'] / 1000 );
            $month_key = $date->format( 'Y-m' );
            
            $monthly_snapshots[$month_key] = [
                'label'     => $date->format( 'M' ),
                'followers' => $entry['followers']
            ];
        }
        
        ksort( $monthly_snapshots );
        
        $processed_months = [];
        $previous_followers = null;
        
        // Calculate the month-over-month delta.
        foreach ( $monthly_snapshots as $key => $data ) {
            $gain = ( $previous_followers !== null ) ? ( $data['followers'] - $previous_followers ) : 0;
            
            $processed_months[] = [
                'month' => $data['label'],
                'total' => $data['followers'],
                'gain'  => $gain
            ];
            
            $previous_followers = $data['followers'];
        }
        
        // Extract strictly the trailing 12 months.
        $last_12_months = array_slice( $processed_months, -12 );
        
        $chart_payload = [
            'labels' => [],
            'gains'  => [],
            'totals' => []
        ];
        
        foreach ( $last_12_months as $item ) {
            $chart_payload['labels'][] = $item['month'];
            $chart_payload['gains'][]  = $item['gain'];
            $chart_payload['totals'][] = $item['total'];
        }
        
        return $chart_payload;
    }

    /**
     * Retrieves the raw statistics array dynamically from the current post's meta.
     *
     * @param int $post_id The ID of the current post/page to fetch meta from.
     * @return array The raw timeline data, or an empty array if meta is missing/invalid.
     */
    private function get_raw_follower_data( int $post_id ): array {
        $history = get_post_meta( $post_id, 'creatordb_history', true );

        // Ensure we are strictly returning an array to prevent fatal foreach errors downstream
        if ( ! is_array( $history ) ) {
            return [];
        }

        return $history;
    }

    /**
     * Registers and enqueues the necessary frontend scripts and dynamically injects the payload.
     * * Evaluates the global post object to ensure scripts are only loaded when the specific
     * shortcode is present, optimizing page load speeds.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        global $post;
        
        // Only load assets if the shortcode is present on the current post/page.
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'follower_growth_chart' ) ) {
            wp_enqueue_script( 'apexcharts', 'https://cdn.jsdelivr.net/npm/apexcharts', [], '3.40.0', true );
            
            // Dummy handle used solely to attach inline localized script payloads.
            wp_enqueue_script( 'dd-chart-init', plugin_dir_url( __FILE__ ) . 'assets/js/dummy.js', ['apexcharts'], '1.0.0', true );

            // Dynamically pass the current post ID to fetch the specific meta
            $raw_data = $this->get_raw_follower_data( $post->ID );
            $processed_data = $this->prepare_monthly_chart_data( $raw_data );
            
            // Compute delta for the summary badge safely
            $total_gain = !empty($processed_data['gains']) ? array_sum( $processed_data['gains'] ) : 0;
            $processed_data['summary_gain'] = number_format( $total_gain );

            // Inject data securely into the DOM for ApexCharts to consume.
            wp_localize_script( 'dd-chart-init', 'ddChartPayload', $processed_data );
        }
    }

    /**
     * Handles the output of the [follower_growth_chart] shortcode.
     *
     * Constructs the HTML scaffolding, applies the scoped CSS, and initializes the
     * ApexCharts instance targeting the localized payload.
     *
     * @return string The compiled HTML rendering the chart interface.
     */
    public function render_shortcode(): string {
        ob_start();
        ?>
        <style>
            .dd-chart-card {
                background-color: #F3F1F0;
                border: 1px solid #4A3F3F;
                border-radius: 8px;
                width: 100%;
                padding: 24px 32px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                box-sizing: border-box;
            }
            .dd-chart-header {
                display: flex;
                align-items: center;
                margin-bottom: 20px;
                font-weight: 600;
                color: #2D2D2D;
                letter-spacing: 1px;
                font-size: 14px;
                text-transform: uppercase;
            }
            .dd-chart-header svg {
                margin-right: 10px;
                width: 18px;
                height: 18px;
            }
            .dd-chart-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 10px;
                font-size: 13px;
                color: #555;
            }
            .dd-pill-badge {
                border: 1px solid #649E94;
                color: #1F4541;
                padding: 4px 12px;
                border-radius: 12px;
                font-weight: 500;
                margin-left: 10px;
            }
        </style>

        <div class="dd-chart-card">
            <div class="dd-chart-header">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                Monthly Gain in Total Followers
            </div>
            
            <div id="ddFollowerChart"></div>

            <div class="dd-chart-footer">
                <div>
                    In the last 12 months, <span class="dd-pill-badge" id="ddSummaryBadge">Loading...</span>
                </div>
                <div>
                    Last updated: <?php echo date('M d, Y'); ?>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined' || typeof ApexCharts === 'undefined') return;

                // Stop execution if there is no data to chart (prevents JS errors)
                if (ddChartPayload.labels.length === 0) {
                    document.getElementById('ddFollowerChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No follower data available for this creator.</p>';
                    document.getElementById('ddSummaryBadge').innerText = 'No Data';
                    return;
                }

                // Note: If you want to chart absolute totals instead of delta, change `ddChartPayload.gains` to `ddChartPayload.totals`
                const chartData = ddChartPayload.gains; 
                const chartLabels = ddChartPayload.labels;
                
                document.getElementById('ddSummaryBadge').innerText = 'Gained ' + ddChartPayload.summary_gain + ' followers';

                const formatToK = (value) => {
                    if (Math.abs(value) >= 1000) {
                        return (value / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    return value.toString();
                };

                const options = {
                    series: [{ name: 'Followers', data: chartData }],
                    chart: { type: 'bar', height: 350, toolbar: { show: false } },
                    colors: ['#FF8A7A'],
                    plotOptions: {
                        bar: {
                            columnWidth: '22%',
                            borderRadius: 4,
                            dataLabels: { position: 'top' }
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: formatToK,
                        offsetY: -25,
                        style: { fontSize: '11px', colors: ['#1F4541'] },
                        background: {
                            enabled: true,
                            padding: 6,
                            borderRadius: 12,
                            borderWidth: 1,
                            borderColor: '#649E94',
                            opacity: 0,
                            dropShadow: { enabled: false }
                        }
                    },
                    xaxis: {
                        categories: chartLabels,
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                        labels: { style: { colors: '#555', fontSize: '12px' } }
                    },
                    yaxis: {
                        labels: {
                            formatter: formatToK,
                            style: { colors: '#555', fontSize: '11px' }
                        }
                    },
                    grid: {
                        borderColor: '#E0E0E0',
                        xaxis: { lines: { show: false } },
                        yaxis: { lines: { show: true } }
                    }
                };

                const chart = new ApexCharts(document.querySelector("#ddFollowerChart"), options);
                chart.render();
            });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Instantiate the plugin class.
new DD_Follower_Growth_Chart();