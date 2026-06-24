<?php
/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders follower analytics interfaces utilizing ApexCharts via independent shortcodes.
 * Version: 1.8.4
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

        // Like Range Component Shortcode
        add_shortcode('follower_like_range_chart', [$this, 'render_like_range_shortcode']);
    }

    /**
     * Transforms raw timeline statistics into a structured dataset for the continuous datetime line chart.
     */
    private function prepare_timeline_chart_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['series_data' => []];
        }

        usort($raw_data, function ($a, $b) {
            $ts_a = isset($a['timestamp_ms']) ? (int)$a['timestamp_ms'] : strtotime($a['date'] ?? 'now') * 1000;
            $ts_b = isset($b['timestamp_ms']) ? (int)$b['timestamp_ms'] : strtotime($b['date'] ?? 'now') * 1000;
            return $ts_a <=> $ts_b;
        });

        $series_data = [];

        foreach ($raw_data as $entry) {
            $ts_ms = isset($entry['timestamp_ms']) ? (int)$entry['timestamp_ms'] : strtotime($entry['date']) * 1000;
            $series_data[] = [$ts_ms, (int)$entry['followers']];
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
                $growth_rate = (($current_followers - $previous_followers) / $previous_followers) * 100;
            } else {
                $growth_rate = 0;
            }

            $series_data[] = [$ts_ms, round($growth_rate, 3)];

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

        $latest_ts = 0;
        foreach ($raw_data as $entry) {
            $ts = isset($entry['timestamp_ms']) ? (int)($entry['timestamp_ms'] / 1000) : strtotime($entry['date']);
            if ($ts > $latest_ts) {
                $latest_ts = $ts;
            }
        }

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

        $monthly_snapshots = [];
        foreach ($raw_data as $entry) {
            $ts = isset($entry['timestamp_ms']) ? (int)($entry['timestamp_ms'] / 1000) : strtotime($entry['date']);
            $month_key = date('Y-m', $ts);

            if (!isset($monthly_snapshots[$month_key]) || $ts > $monthly_snapshots[$month_key]['ts']) {
                $monthly_snapshots[$month_key] = [
                    'ts'        => $ts,
                    'followers' => (int)$entry['followers']
                ];
            }
        }

        ksort($monthly_snapshots);

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
     * Transforms raw timeline statistics into a structured dataset for the Like Range component.
     */
    private function prepare_like_range_data(array $raw_data): array
    {
        if (empty($raw_data)) {
            return ['series_data' => []];
        }

        usort($raw_data, function ($a, $b) {
            $ts_a = isset($a['timestamp_ms']) ? (int)$a['timestamp_ms'] : strtotime($a['date'] ?? 'now') * 1000;
            $ts_b = isset($b['timestamp_ms']) ? (int)$b['timestamp_ms'] : strtotime($b['date'] ?? 'now') * 1000;
            return $ts_a <=> $ts_b;
        });

        $series_data = [];

        foreach ($raw_data as $entry) {
            $ts_ms = isset($entry['timestamp_ms']) ? (int)$entry['timestamp_ms'] : strtotime($entry['date']) * 1000;
            $series_data[] = [
                'ts'    => $ts_ms,
                'likes' => isset($entry['avglikes']) ? (float)$entry['avglikes'] : 0
            ];
        }

        return [
            'series_data' => $series_data
        ];
    }

    /**
     * Retrieves the raw statistics array dynamically from the current post's meta.
     */
    private function get_raw_follower_data(int $post_id): array
    {
        if (function_exists('trb_instagram_history_rows')) {
            $history = trb_instagram_history_rows($post_id);
            if (is_array($history) && $history !== []) {
                return $history;
            }
        }

        $history = get_post_meta($post_id, 'creatordb_history', true);

        if (! is_array($history)) {
            return [];
        }

        return $history;
    }

    /**
     * Determines whether the raw history holds at least one usable follower
     * snapshot. A creator whose history is empty — or contains only zero/blank
     * follower counts — has nothing meaningful to chart, so callers fall back
     * to the no-data template instead of rendering an empty chart card.
     */
    private function has_usable_data(array $raw_data): bool
    {
        foreach ($raw_data as $entry) {
            if (isset($entry['followers']) && (int) $entry['followers'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders the fallback Elementor template when no analytical data is present.
     * Utilizes WordPress's do_shortcode for robust fallback handling without direct Elementor class dependencies.
     *
     * @return string The rendered HTML of the designated Elementor template.
     */
    private function render_no_data_fallback(): string
    {
        // dd_get_template_id() returns the stored option, which may be 0 if the
        // admin "No Data Fallback" field was saved empty. id="0" renders nothing,
        // so guard against it and fall back to the known-good default template.
        $template_id = dd_get_template_id('dd_tpl_no_data_fallback', 27230);
        if ($template_id <= 0) {
            $template_id = 27230;
        }

        $html = do_shortcode('[elementor-template id="' . $template_id . '"]');

        // The Elementor template can resolve to an empty string when it is
        // missing, unpublished, or the configured ID is wrong. Never let that
        // leave a blank card — emit a guaranteed-visible placeholder instead.
        // The HTML comment also makes the active branch greppable in page source.
        if (trim($html) === '') {
            $html = '<!-- dd-no-data-fallback: elementor template ' . (int) $template_id . ' returned empty -->'
                . '<div class="dd-chart-no-data" style="text-align:center;padding:40px 20px;color:#888;'
                . 'font-family:Inter,sans-serif;font-size:14px;">'
                . esc_html__('No data available yet for this creator.', 'dd-follower-chart')
                . '</div>';
        }

        return $html;
    }

    /**
     * Registers and enqueues the necessary frontend scripts and dynamically injects the combined payload.
     */
    public function enqueue_scripts(): void
    {
        global $post;

        if (is_single() && get_post_type() == 'influencer') {
            wp_enqueue_script('apexcharts', 'https://cdn.jsdelivr.net/npm/apexcharts', [], '3.40.0', true);

            $raw_data = $this->get_raw_follower_data($post->ID);

            $monthly_data     = $this->prepare_monthly_chart_data($raw_data);
            $timeline_data    = $this->prepare_timeline_chart_data($raw_data);
            $growth_rate_data = $this->prepare_growth_rate_chart_data($raw_data);
            $like_range_data  = $this->prepare_like_range_data($raw_data);

            $total_gain = !empty($monthly_data['gains']) ? array_sum($monthly_data['gains']) : 0;
            $monthly_data['summary_action'] = $total_gain < 0 ? 'Lost' : 'Gained';
            $monthly_data['summary_gain'] = number_format(abs($total_gain));

            $unified_payload = [
                'monthly'     => $monthly_data,
                'timeline'    => $timeline_data,
                'growth_rate' => $growth_rate_data,
                'like_range'  => $like_range_data
            ];

            wp_localize_script('apexcharts', 'ddChartPayload', $unified_payload);
        }
    }

    /**
     * Handles the output of the [follower_growth_chart] shortcode (Monthly Bar Graph).
     */
    public function render_monthly_shortcode(): string
    {
        global $post;
        $raw_data = $post ? $this->get_raw_follower_data($post->ID) : [];

        if (! $this->has_usable_data($raw_data)) {
            return $this->render_no_data_fallback();
        }

        ob_start();
?>
        <div class="dd-chart-card">
            <div id="ddMonthlyChart"></div>
            <div class="dd-chart-footer">
                <div>
                    In the last 12 months, <?php echo esc_html(get_the_title()); ?> <span class="chip" id="ddSummaryBadge">Loading...</span>
                </div>
                <div id="ddMonthlyLastUpdated">
                    Last updated: Loading...
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined' || typeof ApexCharts === 'undefined') return;

                // DOM Existence Check: Required since PHP may replace this container with an Elementor template
                if (!document.getElementById('ddMonthlyChart')) return;

                const payloadMonthly = ddChartPayload.monthly;

                if (payloadMonthly.labels.length === 0) {
                    document.getElementById('ddMonthlyChart').innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No follower data available for this creator.</p>';
                    document.getElementById('ddSummaryBadge').innerText = 'No Data';
                    document.querySelectorAll('.dd-chart-footer').forEach(el => el.style.display = 'none');
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
                        formatter: function(val) {
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
                            dropShadow: {
                                enabled: false
                            }
                        }
                    },
                    xaxis: {
                        categories: payloadMonthly.labels,
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
                    },
                    tooltip: {
                        enabled: true,
                        theme: 'light',
                        y: {
                            formatter: function(val) {
                                const prefix = val >= 0 ? '+' : '';
                                return prefix + val.toLocaleString() + " followers";
                            }
                        }
                    },
                    responsive: [{
                        breakpoint: 600,
                        options: {
                            chart: {
                                height: 280
                            },
                            plotOptions: {
                                bar: {
                                    columnWidth: '55%'
                                }
                            },
                            // The floating value badges collide on narrow screens; values stay available via tap/tooltip.
                            dataLabels: {
                                enabled: false
                            },
                            xaxis: {
                                labels: {
                                    rotate: -45,
                                    rotateAlways: true,
                                    hideOverlappingLabels: true,
                                    style: {
                                        fontSize: '10px'
                                    }
                                }
                            }
                        }
                    }]
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
        global $post;
        $raw_data = $post ? $this->get_raw_follower_data($post->ID) : [];

        if (! $this->has_usable_data($raw_data)) {
            return $this->render_no_data_fallback();
        }

        ob_start();
    ?>
        <div class="dd-chart-card">
            <div id="ddTimelineChart"></div>
            <div class="dd-timeline-footer">
                <div id="ddTimelineLastUpdated">Last updated: Loading...</div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined' || typeof ApexCharts === 'undefined') return;

                // DOM Existence Check
                if (!document.getElementById('ddTimelineChart')) return;

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
                        toolbar: {
                            show: false
                        },
                        zoom: {
                            enabled: false
                        }
                    },
                    colors: ['#FF7347'],
                    stroke: {
                        curve: 'straight',
                        width: 2
                    },
                    dataLabels: {
                        enabled: false
                    },
                    xaxis: {
                        type: 'datetime',
                        labels: {
                            format: 'MM-dd',
                            style: {
                                colors: '#888',
                                fontSize: '12px'
                            }
                        },
                        axisBorder: {
                            show: true,
                            color: '#E0E0E0'
                        },
                        axisTicks: {
                            show: true,
                            color: '#E0E0E0'
                        },
                        tooltip: {
                            enabled: false
                        }
                    },
                    yaxis: {
                        labels: {
                            formatter: formatToK,
                            style: {
                                colors: '#888',
                                fontSize: '11px'
                            }
                        }
                    },
                    grid: {
                        borderColor: '#E0E0E0',
                        xaxis: {
                            lines: {
                                show: true
                            }
                        },
                        yaxis: {
                            lines: {
                                show: true
                            }
                        }
                    },
                    tooltip: {
                        theme: 'dark',
                        x: {
                            format: 'yyyy-MM-dd'
                        },
                        y: {
                            formatter: function(val) {
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
        global $post;
        $raw_data = $post ? $this->get_raw_follower_data($post->ID) : [];

        if (! $this->has_usable_data($raw_data)) {
            return $this->render_no_data_fallback();
        }

        ob_start();
    ?>
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

                // DOM Existence Check
                if (!document.getElementById('ddGrowthRateChart')) return;

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
                        toolbar: {
                            show: false
                        },
                        zoom: {
                            enabled: false
                        },
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
                    dataLabels: {
                        enabled: false
                    },
                    annotations: {
                        yaxis: [{
                            y: 0,
                            borderColor: '#FF4560',
                            borderWidth: 1.5,
                            strokeDashArray: 0
                        }]
                    },
                    xaxis: {
                        type: 'datetime',
                        labels: {
                            format: 'MM-dd',
                            style: {
                                colors: '#888',
                                fontSize: '12px'
                            }
                        },
                        axisBorder: {
                            show: false
                        },
                        axisTicks: {
                            show: false
                        },
                        tooltip: {
                            enabled: false
                        }
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
                            formatter: function(val) {
                                return val.toFixed(1);
                            },
                            style: {
                                colors: '#888',
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
                    },
                    tooltip: {
                        theme: 'dark',
                        x: {
                            format: 'yyyy-MM-dd'
                        },
                        y: {
                            formatter: function(val) {
                                return val + "%";
                            }
                        }
                    }
                };

                const growthRateChart = new ApexCharts(document.querySelector("#ddGrowthRateChart"), growthRateOptions);
                growthRateChart.render();

                // --- TAB FILTER LOGIC ---
                const timeButtons = document.querySelectorAll('.dd-growth-rate-header .dd-time-btn');

                timeButtons.forEach(btn => {
                    btn.addEventListener('click', function() {
                        timeButtons.forEach(b => b.classList.remove('active'));
                        this.classList.add('active');

                        const daysToFilter = parseInt(this.getAttribute('data-days'));
                        const allData = payloadGrowthRate.series_data;

                        if (allData.length > 0) {
                            const latestTs = allData[allData.length - 1][0];
                            const cutoffTs = latestTs - (daysToFilter * 24 * 60 * 60 * 1000);
                            const filteredData = allData.filter(point => point[0] >= cutoffTs);

                            growthRateChart.updateSeries([{
                                name: 'Growth Rate',
                                data: filteredData
                            }]);
                        }
                    });
                });

                document.querySelector('.dd-growth-rate-header .dd-time-btn[data-days="30"]').click();
            });
        </script>
    <?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [follower_like_range_chart] shortcode (Like Range Gradient Component).
     */
    public function render_like_range_shortcode(): string
    {
        global $post;
        $raw_data = $post ? $this->get_raw_follower_data($post->ID) : [];

        if (! $this->has_usable_data($raw_data)) {
            return $this->render_no_data_fallback();
        }

        ob_start();
    ?>
        <div class="dd-range-card" id="ddLikeRangeWrapper">
            <div class="dd-range-header">
                <div class="dd-time-filters">
                    <button class="dd-time-btn active" data-days="30">Last 30 days</button>
                    <button class="dd-time-btn" data-days="90">Last 90 days</button>
                    <button class="dd-time-btn" data-days="365">Last 12 months</button>
                </div>
            </div>

            <div id="ddLikeRangeContent">
                <div class="dd-range-stats">
                    <div class="dd-stat-block">
                        <div class="dd-stat-value val-min">0</div>
                        <div class="dd-stat-label">Minimum</div>
                    </div>
                    <div class="dd-stat-block" style="text-align:right; justify-content:flex-end;">
                        <div class="dd-stat-value val-max">0</div>
                        <div class="dd-stat-label">Maximum</div>
                    </div>
                </div>

                <div class="dd-gradient-track">
                    <div class="dd-gradient-marker range-marker" data-value="0" style="left: 0%;"></div>
                </div>
            </div>

            <div id="ddLikeRangeEmpty" style="display: none; text-align: center; padding: 40px 20px; color: #888; font-size: 14px;">
                No like range data available.
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof ddChartPayload === 'undefined') return;

                const container = document.getElementById('ddLikeRangeWrapper');
                
                // DOM Existence Check
                if (!container) return;

                const payloadLikeRange = ddChartPayload.like_range;
                const contentDiv = container.querySelector('#ddLikeRangeContent');
                const emptyDiv = container.querySelector('#ddLikeRangeEmpty');

                if (!payloadLikeRange || payloadLikeRange.series_data.length === 0) {
                    contentDiv.style.display = 'none';
                    emptyDiv.style.display = 'block';
                    return;
                }

                const rawLikeData = payloadLikeRange.series_data;

                const formatToK = (value) => {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    // For smaller floats like average likes (e.g. 566.79), round to a clean integer.
                    return Math.round(num).toLocaleString();
                };

                const updateRangeUI = (days) => {
                    const latestTs = rawLikeData[rawLikeData.length - 1].ts;
                    const cutoffTs = latestTs - (days * 24 * 60 * 60 * 1000);

                    const filteredLikes = rawLikeData.filter(d => d.ts >= cutoffTs).map(d => d.likes);

                    // Check if there are no items
                    if (filteredLikes.length === 0) {
                        contentDiv.style.display = 'none';
                        emptyDiv.style.display = 'block';
                        return;
                    }

                    const min = Math.min(...filteredLikes);
                    const max = Math.max(...filteredLikes);

                    // If there are less than 2 points, OR the data points are totally identical, 
                    // we cannot accurately display a comparative range line. 
                    if (filteredLikes.length < 2 || min === max) {
                        contentDiv.style.display = 'none';
                        emptyDiv.style.display = 'block';
                        return;
                    }

                    // Otherwise, render the UI wrapper and populate the data
                    contentDiv.style.display = 'block';
                    emptyDiv.style.display = 'none';

                    const avg = filteredLikes.reduce((a, b) => a + b, 0) / filteredLikes.length;
                    const formattedAvg = formatToK(avg);

                    container.querySelector('.val-min').innerText = formatToK(min);
                    container.querySelector('.val-max').innerText = formatToK(max);

                    const markerPercent = ((avg - min) / (max - min)) * 100;

                    const markerEl = container.querySelector('.range-marker');
                    markerEl.style.left = `${markerPercent}%`;
                    markerEl.setAttribute('data-value', 'Average: ' + formattedAvg);
                };

                const tabs = container.querySelectorAll('.dd-time-btn');
                tabs.forEach(btn => {
                    btn.addEventListener('click', function() {
                        tabs.forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        updateRangeUI(parseInt(this.getAttribute('data-days')));
                    });
                });

                // Trigger default UI state
                container.querySelector('.dd-time-btn[data-days="30"]').click();
            });
        </script>
<?php
        return ob_get_clean();
    }
}

// Instantiate the plugin class.
new DD_Follower_Growth_Chart();