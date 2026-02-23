<?php

/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders a 12-month follower growth chart using ApexCharts, pulling dynamic chronological data from post meta.
 * Version: 1.0.7
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
     * Transforms raw daily/weekly follower statistics into a structured, contiguous 12-month dataset.
     *
     * This method anchors the timeline to the latest available data point, builds a strict
     * 12-month calendar (resolving cross-year sorting issues), maps the data into those specific
     * months, and handles carry-over calculations for any missing monthly snapshots.
     *
     * @param array $raw_data The raw multidimensional array of timeline statistics.
     * @return array Associative array containing 'labels' (x-axis), 'totals' (y-axis), and 'gains' (top labels).
     */
    private function prepare_monthly_chart_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['labels' => [], 'totals' => [], 'gains' => []];
        }

        // 1. Find the absolute latest timestamp in the dataset to anchor the right-side of our chart
        $latest_ts = 0;
        foreach ($raw_data as $entry) {
            $ts = isset($entry['timestamp_ms']) ? (int)($entry['timestamp_ms'] / 1000) : strtotime($entry['date']);
            if ($ts > $latest_ts) {
                $latest_ts = $ts;
            }
        }

        // 2. Build a rigid, continuous 12-month calendar backwards from the latest month.
        // This ensures the X-axis is always flawless and tracks year-over-year transitions (e.g., Nov, Dec, Jan, Feb)
        $months = [];
        $latest_month_start = strtotime(date('Y-m-01', $latest_ts));
        for ($i = 11; $i >= 0; $i--) {
            $time = strtotime("-$i months", $latest_month_start);
            $key = date('Y-m', $time);
            $months[$key] = [
                'label' => date('M', $time),
                'total' => 0,
                'gain'  => 0
            ];
        }

        // 3. Extract the final snapshot of each month from the raw data
        $monthly_snapshots = [];
        foreach ($raw_data as $entry) {
            $ts = isset($entry['timestamp_ms']) ? (int)($entry['timestamp_ms'] / 1000) : strtotime($entry['date']);
            $month_key = date('Y-m', $ts);

            // Keep only the latest entry per month
            if (!isset($monthly_snapshots[$month_key]) || $ts > $monthly_snapshots[$month_key]['ts']) {
                $monthly_snapshots[$month_key] = [
                    'ts'        => $ts,
                    'followers' => (int)$entry['followers']
                ];
            }
        }

        ksort($monthly_snapshots);

        // 4. Establish a starting baseline (Total followers before our 12-month window began)
        $twelve_months_keys = array_keys($months);
        $first_month_key = $twelve_months_keys[0];
        $last_known_total = null;

        foreach ($monthly_snapshots as $key => $data) {
            if ($key < $first_month_key) {
                $last_known_total = $data['followers'];
            }
        }

        // Fallback: If no history exists prior to the 12 months, use the earliest available snapshot
        if ($last_known_total === null) {
            $first_snapshot = reset($monthly_snapshots);
            $last_known_total = $first_snapshot ? $first_snapshot['followers'] : 0;
        }

        // 5. Populate the 12-month timeline and calculate precise month-over-month deltas
        foreach ($months as $key => &$month_data) {
            if (isset($monthly_snapshots[$key])) {
                $current_total = $monthly_snapshots[$key]['followers'];
                $month_data['total'] = $current_total;
                $month_data['gain']  = $current_total - $last_known_total;
                $last_known_total = $current_total;
            } else {
                // If the creator wasn't scraped this month, carry forward their last known total (Gain = 0)
                $month_data['total'] = $last_known_total;
                $month_data['gain']  = 0;
            }
        }

        // 6. Build the final charting payload
        $chart_payload = [
            'labels' => [], 
            'totals' => [], 
            'gains'  => []  
        ];

        foreach ($months as $key => $item) {
            $chart_payload['labels'][] = $item['label'];
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
            
            // Set dynamic action string and absolute value to prevent "Lost -500 followers" syntax
            $processed_data['summary_action'] = $total_gain < 0 ? 'Lost' : 'Gained';
            $processed_data['summary_gain'] = number_format(abs($total_gain));

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
            .dd-chart-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 10px;
                font-size: 14px;
                font-family: Inter !important;
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

            <div id="ddFollowerChart"></div>

            <div class="dd-chart-footer">
                <div>
                    In the last 12 months, <?= get_the_title() ?> <span class="chip" id="ddSummaryBadge">Loading...</span>
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
                    document.getElementById('ddFollowerChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#000;">No follower data available for this creator.</p>';
                    document.getElementById('ddSummaryBadge').innerText = 'No Data';
                    return;
                }

                const chartTotals = ddChartPayload.totals; 
                const chartGains = ddChartPayload.gains;   
                const chartLabels = ddChartPayload.labels; 

                document.getElementById('ddSummaryBadge').innerText = ddChartPayload.summary_action + ' ' + ddChartPayload.summary_gain + ' followers';

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
                            return formatToK(gain);
                        },
                        offsetY: -25,
                        style: {
                            fontSize: '11px',
                            colors: ['#034146'] 
                        },
                        background: {
                            enabled: true,
                            foreColor: '#034146',
                            padding: 6,
                            borderRadius: 12,
                            borderWidth: 1.5,
                            borderColor: '#034146',
                            opacity: 1, 
                            dropShadow: { enabled: false }
                        }
                    },
                    xaxis: {
                        categories: chartLabels,
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                        labels: {
                            style: { colors: '#000', fontSize: '12px' }
                        }
                    },
                    yaxis: {
                        labels: {
                            formatter: formatToK,
                            style: { colors: '#000', fontSize: '11px' }
                        }
                    },
                    grid: {
                        borderColor: '#BCBCBC',
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