<?php

/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders a 12-month follower growth chart using ApexCharts, pulling dynamic chronological data from post meta.
 * Version: 1.0.5
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-follower-chart
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Core class responsible for handling the Follower Growth Chart logic,
 * data transformations, and frontend rendering via shortcode.
 */
class DD_Follower_Growth_Chart
{

    /**
     * Initializes the plugin by hooking into WordPress core actions and registering shortcodes.
     *
     * @return void
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('follower_growth_chart', [$this, 'render_shortcode']);
    }

    /**
     * Transforms raw daily/weekly follower statistics into a structured, chronological 12-month dataset.
     *
     * This method securely parses timestamps, groups the data by month (retaining only the latest
     * snapshot per month), and calculates the month-over-month follower gain.
     *
     * @param array $raw_data The raw multidimensional array of timeline statistics.
     * @return array Associative array containing 'labels' (x-axis), 'totals' (y-axis), and 'gains' (top labels).
     */
    private function prepare_monthly_chart_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['labels' => [], 'totals' => [], 'gains' => []];
        }

        $monthly_snapshots = [];

        // 1. Group data by year-month and strictly retain the latest entry per month
        foreach ($raw_data as $entry) {
            $ts = isset($entry['timestamp_ms']) ? (int)($entry['timestamp_ms'] / 1000) : strtotime($entry['date']);
            $month_key = date('Y-m', $ts);

            if (!isset($monthly_snapshots[$month_key]) || $ts > $monthly_snapshots[$month_key]['ts']) {
                $monthly_snapshots[$month_key] = [
                    'ts'        => $ts,
                    'label'     => date('M', $ts),
                    'followers' => (int)$entry['followers']
                ];
            }
        }

        // 2. Sort the array keys (Y-m) chronologically (e.g., '2025-11', '2025-12', '2026-01', '2026-02')
        ksort($monthly_snapshots);

        $processed_months = [];
        $previous_followers = null;

        // 3. Calculate the month-over-month delta (Gain)
        foreach ($monthly_snapshots as $key => $data) {
            $gain = ($previous_followers !== null) ? ($data['followers'] - $previous_followers) : 0;

            $processed_months[] = [
                'month' => $data['label'],
                'total' => $data['followers'],
                'gain'  => $gain
            ];

            $previous_followers = $data['followers'];
        }

        // 4. Extract strictly the latest 12 months for the chart
        $last_12_months = array_slice($processed_months, -12);

        $chart_payload = [
            'labels' => [], // X-Axis (e.g., Nov, Dec, Jan, Feb)
            'totals' => [], // Y-Axis & Bar Height (Total followers)
            'gains'  => []  // Top Label (Followers gained this month)
        ];

        foreach ($last_12_months as $item) {
            $chart_payload['labels'][] = $item['month'];
            $chart_payload['totals'][] = $item['total'];
            $chart_payload['gains'][]  = $item['gain'];
        }

        return $chart_payload;
    }

    /**
     * Retrieves the raw statistics array dynamically from the current post's meta.
     *
     * @param int $post_id The ID of the current post/page to fetch meta from.
     * @return array The raw timeline data, or an empty array if meta is missing/invalid.
     */
    private function get_raw_follower_data(int $post_id): array
    {
        $history = get_post_meta($post_id, 'creatordb_history', true);

        if (! is_array($history)) {
            return [];
        }

        return $history;
    }

    /**
     * Registers and enqueues the necessary frontend scripts and dynamically injects the payload.
     *
     * @return void
     */
    public function enqueue_scripts(): void
    {
        global $post;

        if (is_single() && get_post_type() == 'influencer') {
            wp_enqueue_script('apexcharts', 'https://cdn.jsdelivr.net/npm/apexcharts', [], '3.40.0', true);
            wp_enqueue_script('dd-chart-init', plugin_dir_url(__FILE__) . 'assets/js/dummy.js', ['apexcharts'], '1.0.0', true);

            $raw_data = $this->get_raw_follower_data($post->ID);
            $processed_data = $this->prepare_monthly_chart_data($raw_data);

            // Calculate total gained across the rendered 12 months for the footer badge
            $total_gain = !empty($processed_data['gains']) ? array_sum($processed_data['gains']) : 0;
            $processed_data['summary_gain'] = number_format($total_gain);

            wp_localize_script('dd-chart-init', 'ddChartPayload', $processed_data);
        }
    }

    /**
     * Handles the output of the [follower_growth_chart] shortcode.
     *
     * @return string The compiled HTML and JS rendering the chart interface.
     */
    public function render_shortcode(): string
    {
        ob_start();
?>
        <style>
            .dd-chart-card {
                background-color: #F3F1F0;
                border: 1px solid #4A3F3F;
                border-radius: 8px;
                width: 100%;
                padding: 24px 32px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
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
                border: 1px solid #034146;
                background-color: #E2EBE8;
                color: #034146;
                padding: 4px 12px;
                border-radius: 12px;
                font-weight: 600;
                margin-left: 10px;
            }

            /* --- STRICT APEXCHARTS SVG OVERRIDES --- */
            /* These explicitly bypass the ApexCharts JS contrast-checker to force your exact UI colors */
            #ddFollowerChart * {
                font-family: Inter !important;
            }
            #ddFollowerChart .apexcharts-datalabels rect {
                fill: #F0FFF4 !important; /* Solid light green background from mockup */
                stroke: #034146 !important; /* Solid dark green border */
                stroke-width: 1.5px !important;
                rx: 5px !important; /* Perfectly rounded pill caps */
                ry: 5px !important;
            }

            #ddFollowerChart .apexcharts-datalabels text {
                fill: #034146 !important; /* Solid dark green text */
                font-weight: 600 !important;
            }
        </style>

        <div class="dd-chart-card">
            <div class="dd-chart-header">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                </svg>
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

                if (ddChartPayload.labels.length === 0) {
                    document.getElementById('ddFollowerChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No follower data available for this creator.</p>';
                    document.getElementById('ddSummaryBadge').innerText = 'No Data';
                    return;
                }

                const chartTotals = ddChartPayload.totals; 
                const chartGains = ddChartPayload.gains;   
                const chartLabels = ddChartPayload.labels; 

                document.getElementById('ddSummaryBadge').innerText = 'Gained ' + ddChartPayload.summary_gain + ' followers';

                const formatToK = (value) => {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    return num.toString();
                };

                const options = {
                    series: [{
                        name: 'Total Followers',
                        data: chartTotals
                    }],
                    chart: {
                        type: 'bar',
                        height: 350,
                        toolbar: { show: false }
                    },
                    colors: ['#FF8A7A'],
                    plotOptions: {
                        bar: {
                            columnWidth: '22%',
                            borderRadius: 4,
                            dataLabels: {
                                position: 'top'
                            }
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: function (val, opts) {
                            const gain = chartGains[opts.dataPointIndex];
                            return formatToK(gain); // Removed '+' prefix to match mockup exactly
                        },
                        offsetY: -25,
                        style: {
                            fontSize: '11px',
                            colors: ['#034146'] // Fallback if CSS fails
                        },
                        background: {
                            enabled: true,
                            foreColor: '#034146',
                            padding: 6,
                            borderRadius: 12,
                            borderWidth: 1.5,
                            borderColor: '#034146',
                            opacity: 1, // Required to tell ApexCharts to render the SVG rect element so our CSS can style it
                            dropShadow: { enabled: false }
                        }
                    },
                    xaxis: {
                        categories: chartLabels,
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                        labels: {
                            style: { colors: '#555', fontSize: '12px' }
                        }
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