<?php
/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders follower analytics interfaces utilizing ApexCharts via independent shortcodes.
 * Version: 1.7.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-follower-chart
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly to ensure security.
}

/**
 * Core class responsible for handling the Follower Growth Chart logic,
 * data transformations, and frontend rendering via distinct shortcodes.
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
        
        // Monthly Growth Bar Chart Shortcode
        add_shortcode('follower_growth_chart', [$this, 'render_monthly_shortcode']);
        
        // Total Followers Timeline Line Chart Shortcode
        add_shortcode('follower_timeline_chart', [$this, 'render_timeline_shortcode']);
        
        // Follower Growth Rate Area Chart Shortcode
        add_shortcode('follower_growth_rate_chart', [$this, 'render_growth_rate_shortcode']);
    }

    /**
     * Transforms raw timeline statistics into a structured dataset for the continuous datetime line chart.
     */
    private function prepare_timeline_chart_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['series_data' => []];
        }

        // Sort chronologically (Oldest to Newest) to ensure linear timeline plotting
        usort($raw_data, function ($a, $b) {
            $ts_a = isset($a['timestamp_ms']) ? (int)$a['timestamp_ms'] : strtotime($a['date'] ?? 'now') * 1000;
            $ts_b = isset($b['timestamp_ms']) ? (int)$b['timestamp_ms'] : strtotime($b['date'] ?? 'now') * 1000;
            return $ts_a <=> $ts_b;
        });

        $series_data = [];

        // Map data directly to [timestamp, value] pairs for ApexCharts Datetime X-axis
        foreach ($raw_data as $entry) {
            $ts_ms = isset($entry['timestamp_ms']) ? (int)$entry['timestamp_ms'] : strtotime($entry['date']) * 1000;
            $series_data[] = [ $ts_ms, (int)$entry['followers'] ];
        }

        return [
            'series_data' => $series_data
        ];
    }

    /**
     * Transforms raw timeline statistics into a calculated percentage growth rate dataset.
     */
    private function prepare_growth_rate_chart_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['series_data' => []];
        }

        // Sort strictly chronologically to accurately calculate sequential delta
        usort($raw_data, function ($a, $b) {
            $ts_a = isset($a['timestamp_ms']) ? (int)$a['timestamp_ms'] : strtotime($a['date'] ?? 'now') * 1000;
            $ts_b = isset($b['timestamp_ms']) ? (int)$b['timestamp_ms'] : strtotime($b['date'] ?? 'now') * 1000;
            return $ts_a <=> $ts_b;
        });

        $series_data = [];
        $previous_followers = null;

        foreach ($raw_data as $entry) {
            $ts_ms = isset($entry['timestamp_ms']) ? (int)$entry['timestamp_ms'] : strtotime($entry['date']) * 1000;
            $current_followers = (int)$entry['followers'];

            if ($previous_followers !== null && $previous_followers > 0) {
                // Calculate percentage growth rate
                $growth_rate = (($current_followers - $previous_followers) / $previous_followers) * 100;
            } else {
                // Anchor the baseline to 0 on the first node
                $growth_rate = 0;
            }

            // Cap floating precision to 3 decimal places for optimized frontend processing
            $series_data[] = [ $ts_ms, round($growth_rate, 3) ];
            
            $previous_followers = $current_followers;
        }

        return [
            'series_data' => $series_data
        ];
    }

    /**
     * Transforms raw daily/weekly follower statistics into a structured, contiguous 12-month dataset.
     */
    private function prepare_monthly_chart_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['labels' => [], 'totals' => [], 'gains' => [], 'last_updated' => 'N/A'];
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
        $months = [];
        $latest_month_start = strtotime(date('Y-m-01', $latest_ts));
        for ($i = 11; $i >= 0; $i--) {
            $time = strtotime("-$i months", $latest_month_start);
            $key = date('Y-m', $time);
            $months[$key] = [
                'label' => date('M Y', $time), 
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
                $month_data['total'] = $last_known_total;
                $month_data['gain']  = 0;
            }
        }

        // 6. Build the final charting payload
        $chart_payload = [
            'labels'       => [], 
            'totals'       => [], 
            'gains'        => [],
            'last_updated' => date('M d, Y', $latest_ts)
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
     * Registers and enqueues the necessary frontend scripts and dynamically injects the combined payload.
     */
    public function enqueue_scripts(): void
    {
        global $post;

        if (is_single() && get_post_type() == 'influencer') {
            wp_enqueue_script('apexcharts', 'https://cdn.jsdelivr.net/npm/apexcharts', [], '3.40.0', true);
            wp_enqueue_script('dd-chart-init', plugin_dir_url(__FILE__) . 'assets/js/dummy.js', ['apexcharts'], '1.0.0', true);

            $raw_data = $this->get_raw_follower_data($post->ID);
            
            // Process all three required datasets
            $monthly_data     = $this->prepare_monthly_chart_data($raw_data);
            $timeline_data    = $this->prepare_timeline_chart_data($raw_data);
            $growth_rate_data = $this->prepare_growth_rate_chart_data($raw_data);

            // Compute summary variables for the monthly view
            $total_gain = !empty($monthly_data['gains']) ? array_sum($monthly_data['gains']) : 0;
            $monthly_data['summary_action'] = $total_gain < 0 ? 'Loss' : 'Gained';
            $monthly_data['summary_gain'] = number_format(abs($total_gain));

            // Bundle the payloads into a unified localization object
            $unified_payload = [
                'monthly'     => $monthly_data,
                'timeline'    => $timeline_data,
                'growth_rate' => $growth_rate_data
            ];

            wp_localize_script('dd-chart-init', 'ddChartPayload', $unified_payload);
        }
    }

    /**
     * Handles the output of the [follower_growth_chart] shortcode (Monthly Bar Graph).
     */
    public function render_monthly_shortcode(): string
    {
        ob_start();
?>
        <style>
            .dd-chart-card {
                background-color: #FFFFFF;
                border: 1px solid #E0E0E0;
                border-radius: 8px;
                width: 100%;
                padding: 24px 16px;
                font-family: Inter, sans-serif !important;
                box-sizing: border-box;
            }
            .dd-chart-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 10px;
                font-size: 13px;
                color: #888;
                font-family: Inter, sans-serif !important;
                padding: 0 10px;
            }

            /* --- STRICT APEXCHARTS SVG OVERRIDES FOR MONTHLY VIEW --- */
            #ddMonthlyChart * {
                font-family: Inter, sans-serif !important;
            }
            #ddMonthlyChart .apexcharts-datalabels rect {
                fill: #F0FFF4 !important; 
                stroke: #034146 !important; 
                stroke-width: 1.5px !important;
                rx: 5px !important; 
                ry: 5px !important;
            }
            #ddMonthlyChart .apexcharts-datalabels text {
                fill: #034146 !important; 
                font-weight: 600 !important;
            }
        </style>

        <div class="dd-chart-card">
            <div id="ddMonthlyChart"></div>
            <div class="dd-chart-footer">
                <div>
                    In the last 12 months, <?= esc_html(get_the_title()) ?> <span class="chip" id="ddSummaryBadge">Loading...</span>
                </div>
                <div id="ddMonthlyLastUpdated">
                    Last updated: Loading...
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined' || typeof ApexCharts === 'undefined') return;

                const payloadMonthly = ddChartPayload.monthly;

                if (payloadMonthly.labels.length === 0) {
                    document.getElementById('ddMonthlyChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No follower data available for this creator.</p>';
                    document.getElementById('ddSummaryBadge').innerText = 'No Data';
                    document.getElementById('ddMonthlyLastUpdated').innerText = 'Last updated: N/A';
                    return;
                }

                document.getElementById('ddSummaryBadge').classList.add(payloadMonthly.summary_action);
                document.getElementById('ddSummaryBadge').innerText = payloadMonthly.summary_action + ' ' + payloadMonthly.summary_gain + ' followers';
                document.getElementById('ddMonthlyLastUpdated').innerText = 'Last updated: ' + payloadMonthly.last_updated;

                const formatToK = (value) => {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const sign = num < 0 ? '-' : '';
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return sign + (absValue / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    return num.toString();
                };

                const monthlyOptions = {
                    series: [{
                        name: 'Follower Gain',
                        data: payloadMonthly.gains 
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
                            dataLabels: { position: 'top' }
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: function (val) {
                            return formatToK(val); 
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
                        categories: payloadMonthly.labels,
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
                    },
                    tooltip: {
                        enabled: true,
                        theme: 'light',
                        y: {
                            formatter: function (val) {
                                const prefix = val >= 0 ? '+' : '';
                                return prefix + val.toLocaleString() + " followers";
                            }
                        }
                    }
                };

                const monthlyChart = new ApexCharts(document.querySelector("#ddMonthlyChart"), monthlyOptions);
                monthlyChart.render();
            });
        </script>
<?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [follower_timeline_chart] shortcode (Timeline Line Graph).
     */
    public function render_timeline_shortcode(): string
    {
        ob_start();
?>
        <style>
            .dd-chart-card {
                background-color: #FFFFFF;
                border: 1px solid #E0E0E0;
                border-radius: 8px;
                width: 100%;
                padding: 24px 16px;
                font-family: Inter, sans-serif !important;
                box-sizing: border-box;
            }
            .dd-timeline-footer {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                margin-top: 10px;
                font-size: 13px;
                color: #888;
                font-family: Inter, sans-serif !important;
                padding: 0 10px;
            }
            #ddTimelineChart * {
                font-family: Inter, sans-serif !important;
            }
            #ddTimelineChart .apexcharts-tooltip-marker {
                background-color: #FF7347 !important;
            }
        </style>

        <div class="dd-chart-card">
            <div id="ddTimelineChart"></div>
            <div class="dd-timeline-footer">
                <div id="ddTimelineLastUpdated">Last updated: Loading...</div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined' || typeof ApexCharts === 'undefined') return;

                const payloadTimeline = ddChartPayload.timeline;
                const payloadMonthly = ddChartPayload.monthly;

                const formatToK = (value) => {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const sign = num < 0 ? '-' : '';
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return sign + (absValue / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    return num.toString();
                };

                if (payloadTimeline.series_data.length === 0) {
                    document.getElementById('ddTimelineChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No timeline data available.</p>';
                    document.getElementById('ddTimelineLastUpdated').innerText = 'Last updated: N/A';
                    return;
                }

                document.getElementById('ddTimelineLastUpdated').innerText = 'Last updated: ' + payloadMonthly.last_updated;

                const timelineOptions = {
                    series: [{
                        name: 'Followers',
                        data: payloadTimeline.series_data
                    }],
                    chart: {
                        type: 'line',
                        height: 350,
                        toolbar: { show: false },
                        zoom: { enabled: false }
                    },
                    colors: ['#FF7347'],
                    stroke: { curve: 'straight', width: 2 },
                    dataLabels: { enabled: false },
                    xaxis: {
                        type: 'datetime',
                        labels: {
                            format: 'MM-dd',
                            style: { colors: '#888', fontSize: '12px' }
                        },
                        axisBorder: { show: true, color: '#E0E0E0' },
                        axisTicks: { show: true, color: '#E0E0E0' },
                        tooltip: { enabled: false }
                    },
                    yaxis: {
                        labels: {
                            formatter: formatToK,
                            style: { colors: '#888', fontSize: '11px' }
                        }
                    },
                    grid: {
                        borderColor: '#E0E0E0',
                        xaxis: { lines: { show: true } },
                        yaxis: { lines: { show: true } }
                    },
                    tooltip: {
                        theme: 'dark',
                        x: { format: 'yyyy-MM-dd' },
                        y: {
                            formatter: function (val) {
                                return val.toLocaleString();
                            }
                        }
                    }
                };

                const timelineChart = new ApexCharts(document.querySelector("#ddTimelineChart"), timelineOptions);
                timelineChart.render();
            });
        </script>
<?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [follower_growth_rate_chart] shortcode with integrated Time Filters.
     */
    public function render_growth_rate_shortcode(): string
    {
        ob_start();
?>
        <style>
            .dd-growth-rate-card {
                border: 1px solid #E0E0E0;
                border-radius: 8px;
                width: 100%;
                padding: 24px 16px;
                font-family: Inter, sans-serif !important;
                box-sizing: border-box;
            }
            .dd-growth-rate-header {
                display: flex;
                justify-content: flex-end;
                margin-bottom: 10px;
                padding: 0 10px;
            }

            /* --- TIME FILTERS STYLING --- */
            .dd-time-filters.dd-time-filters.dd-time-filters {
                display: inline-flex;
                background: #ffff;
                border: 1px solid #f77d67;
                border-radius: 6px;
                overflow: hidden;
            }
            .dd-time-btn.dd-time-btn.dd-time-btn {
                background: transparent;
                border: none;
                padding: 11px 18px;
                font-size: 12px;
                font-family: Inter, sans-serif;
                cursor: pointer;
                font-weight: 500;
                border-right: 1px solid #E5E5E5;
                transition: color 0.2s ease;
                letter-spacing: 0.6px;
                border-radius: 0;
            }
            .dd-time-btn.dd-time-btn.dd-time-btn:last-child {
                border-right: none;
            }
            .dd-time-btn.dd-time-btn.dd-time-btn:hover {
                background: #f77d67;
                color: #fff; 
            }
            .dd-time-btn.dd-time-btn.dd-time-btn.active {
                background: #f77d67;
                color: #fff; 
                background: #f77d67;
            }

            .dd-growth-rate-footer {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                margin-top: 10px;
                font-size: 13px;
                color: #888;
                font-family: Inter, sans-serif !important;
                padding: 0 10px;
            }
            #ddGrowthRateChart * {
                font-family: Inter, sans-serif !important;
            }
            #ddGrowthRateChart .apexcharts-tooltip-marker {
                background-color: #00BFFF !important; 
            }
        </style>

        <div class="dd-growth-rate-card">
            
            <div class="dd-growth-rate-header">
                <div class="dd-time-filters">
                    <button class="dd-time-btn active" data-days="30">Last 30 days</button>
                    <button class="dd-time-btn" data-days="90">Last 90 days</button>
                    <button class="dd-time-btn" data-days="365">Last 12 months</button>
                </div>
            </div>

            <div id="ddGrowthRateChart"></div>
            
            <div class="dd-growth-rate-footer">
                <div id="ddGrowthRateLastUpdated">Last updated: Loading...</div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined' || typeof ApexCharts === 'undefined') return;

                const payloadGrowthRate = ddChartPayload.growth_rate;
                const payloadMonthly = ddChartPayload.monthly; 

                if (payloadGrowthRate.series_data.length === 0) {
                    document.getElementById('ddGrowthRateChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No growth rate data available.</p>';
                    document.getElementById('ddGrowthRateLastUpdated').innerText = 'Last updated: N/A';
                    return;
                }

                document.getElementById('ddGrowthRateLastUpdated').innerText = 'Last updated: ' + payloadMonthly.last_updated;

                const growthRateOptions = {
                    series: [{
                        name: 'Growth Rate',
                        data: payloadGrowthRate.series_data 
                    }],
                    chart: {
                        type: 'area', 
                        height: 350,
                        toolbar: { show: false },
                        zoom: { enabled: false },
                        background: 'transparent' 
                    },
                    colors: ['#00BFFF'], 
                    fill: {
                        type: 'solid',
                        opacity: 0.15 
                    },
                    stroke: { 
                        curve: 'smooth',  
                        width: 2 
                    },
                    dataLabels: { enabled: false },
                    annotations: {
                        yaxis: [
                            {
                                y: 0,
                                borderColor: '#FF4560', 
                                borderWidth: 1.5,
                                strokeDashArray: 0
                            }
                        ]
                    },
                    xaxis: {
                        type: 'datetime',
                        labels: {
                            format: 'MM-dd',
                            style: { colors: '#888', fontSize: '12px' }
                        },
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                        tooltip: { enabled: false }
                    },
                    yaxis: {
                        title: {
                            text: 'Growth rate (%)',
                            style: {
                                color: '#888',
                                fontSize: '12px',
                                fontWeight: 400,
                                fontFamily: 'Inter, sans-serif'
                            }
                        },
                        labels: {
                            formatter: function (val) {
                                return val.toFixed(1); 
                            },
                            style: { colors: '#888', fontSize: '11px' }
                        }
                    },
                    grid: {
                        borderColor: '#E0E0E0',
                        xaxis: { lines: { show: false } },
                        yaxis: { lines: { show: true } }
                    },
                    tooltip: {
                        theme: 'dark',
                        x: { format: 'yyyy-MM-dd' },
                        y: {
                            formatter: function (val) {
                                return val + "%"; 
                            }
                        }
                    }
                };

                const growthRateChart = new ApexCharts(document.querySelector("#ddGrowthRateChart"), growthRateOptions);
                growthRateChart.render();

                // --- TAB FILTER LOGIC ---
                const timeButtons = document.querySelectorAll('.dd-time-btn');
                
                timeButtons.forEach(btn => {
                    btn.addEventListener('click', function() {
                        // Handle UI Active state
                        timeButtons.forEach(b => b.classList.remove('active'));
                        this.classList.add('active');

                        // Determine timeframe
                        const daysToFilter = parseInt(this.getAttribute('data-days'));
                        const allData = payloadGrowthRate.series_data;
                        
                        if (allData.length > 0) {
                            // Find the latest timestamp in the dataset to act as "Today"
                            const latestTs = allData[allData.length - 1][0];
                            
                            // Calculate the cutoff date by subtracting (days * milliseconds in a day)
                            const cutoffTs = latestTs - (daysToFilter * 24 * 60 * 60 * 1000);
                            
                            // Filter dataset
                            const filteredData = allData.filter(point => point[0] >= cutoffTs);
                            
                            // Dynamically update the ApexChart instance
                            growthRateChart.updateSeries([{
                                data: filteredData
                            }]);
                        }
                    });
                });

                // Trigger a default click on 'Last 30 days' to initially filter the view 
                document.querySelector('.dd-time-btn[data-days="30"]').click();
            });
        </script>
<?php
        return ob_get_clean();
    }
}

// Instantiate the plugin class.
new DD_Follower_Growth_Chart();