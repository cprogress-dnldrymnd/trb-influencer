<?php

/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders a 12-month follower growth chart using ApexCharts, pulling dynamic data from post meta.
 * Version: 1.0.3
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
     * Transforms raw daily/weekly follower statistics into a structured, 12-month dataset.
     *
     * Extracts the final recorded follower count per month, sorts them chronologically
     * (oldest to latest), and isolates the totals for the Y-axis and the calculated deltas (gains) 
     * for the data labels.
     *
     * @param array $raw_data The raw multidimensional array of timeline statistics.
     * @return array Associative array containing 'labels' (x-axis), 'totals' (y-axis), and 'gains' (top labels).
     */
    private function prepare_monthly_chart_data(array $raw_data): array
    {
        // Return empty payload if no valid array is passed
        if (empty($raw_data)) {
            return ['labels' => [], 'totals' => [], 'gains' => []];
        }

        // 1. Sort the raw array strictly chronologically (Oldest first to Latest last).
        usort($raw_data, function ($a, $b) {
            $time_a = isset($a['timestamp_ms']) ? (int)$a['timestamp_ms'] : strtotime($a['date'] ?? 'now');
            $time_b = isset($b['timestamp_ms']) ? (int)$b['timestamp_ms'] : strtotime($b['date'] ?? 'now');
            return $time_a <=> $time_b;
        });

        $monthly_snapshots = [];

        // 2. Group data by year-month.
        // Because data is sorted chronologically, the loop naturally overwrites 
        // earlier dates with later dates for the same month, capturing the final month-end total.
        foreach ($raw_data as $entry) {
            $date = isset($entry['date']) ? new DateTime($entry['date']) : (new DateTime())->setTimestamp($entry['timestamp_ms'] / 1000);
            $month_key = $date->format('Y-m');

            $monthly_snapshots[$month_key] = [
                'label'     => $date->format('M'),
                'followers' => (int)$entry['followers']
            ];
        }

        // Ensure array keys are strictly sorted in chronological order
        sort($monthly_snapshots);

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

        // 4. Extract strictly the latest 12 months.
        $last_12_months = array_slice($processed_months, -12);

        $chart_payload = [
            'labels' => [],
            'totals' => [], // Mapped to Requirement 4: Left Label / Bar height
            'gains'  => []  // Mapped to Requirement 3: Top Label
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
        // Requirement 1: Data fetched directly from post meta
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

            // Compute delta for the summary badge safely
            $total_gain = !empty($processed_data['gains']) ? array_sum($processed_data['gains']) : 0;
            $processed_data['summary_gain'] = number_format($total_gain);

            wp_localize_script('dd-chart-init', 'ddChartPayload', $processed_data);
        }
    }

    /**
     * Handles the output of the [follower_growth_chart] shortcode.
     *
     * @return string The compiled HTML rendering the chart interface.
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

                // Data mapping based on your explicit requirements
                const chartTotals = ddChartPayload.totals; // Maps to Left Label and Bar Height
                const chartGains = ddChartPayload.gains;   // Maps to Top Label
                const chartLabels = ddChartPayload.labels; // Maps to Bottom Label

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
                        data: chartTotals // Requirement 4: Render bars against the total follower count
                    }],
                    chart: {
                        type: 'bar',
                        height: 350,
                        toolbar: {
                            show: false
                        }
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
                            // Requirement 3: Top label overrides the default value to show the GAIN
                            const gain = chartGains[opts.dataPointIndex];
                            const formattedGain = formatToK(gain);
                            // Add a '+' prefix for positive gains for better UI clarity
                            return gain > 0 ? '+' + formattedGain : formattedGain;
                        },
                        offsetY: -25,
                        style: {
                            fontSize: '11px',
                            colors: ['#1F4541']
                        },
                        background: {
                            enabled: true,
                            padding: 6,
                            borderRadius: 12,
                            borderWidth: 1,
                            borderColor: '#649E94',
                            opacity: 0,
                            dropShadow: {
                                enabled: false
                            }
                        }
                    },
                    xaxis: {
                        categories: chartLabels,
                        axisBorder: {
                            show: false
                        },
                        axisTicks: {
                            show: false
                        },
                        labels: {
                            style: {
                                colors: '#555',
                                fontSize: '12px'
                            }
                        }
                    },
                    yaxis: {
                        labels: {
                            // Requirement 4: Left label formatting represents Total Followers
                            formatter: formatToK,
                            style: {
                                colors: '#555',
                                fontSize: '11px'
                            }
                        }
                    },
                    grid: {
                        borderColor: '#E0E0E0',
                        xaxis: {
                            lines: {
                                show: false
                            }
                        },
                        yaxis: {
                            lines: {
                                show: true
                            }
                        }
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