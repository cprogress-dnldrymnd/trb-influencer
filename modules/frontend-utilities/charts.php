<?php
/**
 * Plugin Name: DD Follower Growth Chart
 * Description: Renders follower analytics interfaces utilizing ApexCharts via independent shortcodes.
 * Version: 1.9.0
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
 *
 * Multi-platform: charts and the platform switcher read Instagram, YouTube, and
 * TikTok history via trb_platform_history_rows(). All platforms available for the
 * current influencer are localized into a single ddChartPayload object (keyed by
 * platform); the ddPlatformSwitcher JS controller swaps every chart + platform
 * panel in place when a [platform_switcher] button is clicked.
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

        // Platform selector (Instagram / YouTube / TikTok) that drives every chart + panel on the page
        add_shortcode('platform_switcher', [$this, 'render_platform_switcher_shortcode']);

        // Wraps arbitrary content so the platform switcher can show/hide it per platform
        add_shortcode('platform_panel', [$this, 'render_platform_panel_shortcode']);
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
            return [
                'series_data'  => [],
                'point_count'  => 0,
                'default_days' => 30,
            ];
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

        $point_count = count($series_data);

        return [
            'series_data'  => $series_data,
            'point_count'  => $point_count,
            // IC import_seed is ~1 month ago; default wider window when the series is sparse.
            'default_days' => $point_count > 0 && $point_count <= 3 ? 365 : 30,
        ];
    }

    /**
     * Resolve influencer post ID for chart reads (Elementor may not set global $post).
     */
    private function resolve_chart_post_id(): int
    {
        $id = (int) get_the_ID();
        if ($id > 0 && get_post_type($id) === 'influencer') {
            return $id;
        }

        global $post;
        if ($post instanceof \WP_Post && $post->post_type === 'influencer') {
            return (int) $post->ID;
        }

        $queried = (int) get_queried_object_id();
        if ($queried > 0 && get_post_type($queried) === 'influencer') {
            return $queried;
        }

        return 0;
    }

    /**
     * Retrieves the raw history rows for a given post + platform.
     */
    private function get_raw_follower_data(int $post_id, string $platform = 'instagram'): array
    {
        if (function_exists('trb_platform_history_rows')) {
            $history = trb_platform_history_rows($post_id, $platform);
            if (is_array($history) && $history !== []) {
                return $history;
            }
        }

        return [];
    }

    /**
     * Platforms (instagram/youtube/tiktok) this influencer has data for, checked
     * across both providers via trb_platform_has_data(). Order: instagram, youtube, tiktok.
     */
    private function get_available_platforms(int $post_id): array
    {
        $platforms = [];

        foreach (['instagram', 'youtube', 'tiktok'] as $platform) {
            $has_data = function_exists('trb_platform_has_data')
                ? trb_platform_has_data($post_id, $platform)
                : ($platform === 'instagram');

            if ($has_data) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * Builds the full chart payload (all four chart datasets + label metadata) for one platform.
     */
    private function build_platform_chart_payload(int $post_id, string $platform): array
    {
        $raw_data = $this->get_raw_follower_data($post_id, $platform);

        $monthly_data     = $this->prepare_monthly_chart_data($raw_data);
        $timeline_data    = $this->prepare_timeline_chart_data($raw_data);
        $growth_rate_data = $this->prepare_growth_rate_chart_data($raw_data);
        $like_range_data  = $this->prepare_like_range_data($raw_data);

        $total_gain = !empty($monthly_data['gains']) ? array_sum($monthly_data['gains']) : 0;
        $monthly_data['summary_action'] = $total_gain < 0 ? 'Lost' : 'Gained';
        $monthly_data['summary_gain'] = number_format(abs($total_gain));

        [$noun, $noun_cap] = function_exists('trb_platform_metric_noun')
            ? trb_platform_metric_noun($platform)
            : ['followers', 'Followers'];

        return [
            'monthly'     => $monthly_data,
            'timeline'    => $timeline_data,
            'growth_rate' => $growth_rate_data,
            'like_range'  => $like_range_data,
            'meta'        => [
                'noun'         => $noun,
                'noun_cap'     => $noun_cap,
                'last_updated' => $monthly_data['last_updated'],
            ],
        ];
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
     * Registers and enqueues the necessary frontend scripts and dynamically injects the combined
     * multi-platform payload, plus the ddPlatformSwitcher controller that drives every chart and
     * platform panel on the page.
     */
    public function enqueue_scripts(): void
    {
        $post_id = $this->resolve_chart_post_id();
        if ($post_id <= 0 && is_singular('influencer')) {
            $post_id = (int) get_queried_object_id();
        }

        if ($post_id <= 0 || get_post_type($post_id) !== 'influencer') {
            return;
        }

        wp_enqueue_script('apexcharts', 'https://cdn.jsdelivr.net/npm/apexcharts', [], '3.40.0', true);

        $available_platforms = $this->get_available_platforms($post_id);
        if (empty($available_platforms)) {
            return;
        }

        $unified_payload = [];
        foreach ($available_platforms as $platform) {
            $unified_payload[$platform] = $this->build_platform_chart_payload($post_id, $platform);
        }

        wp_localize_script('apexcharts', 'ddChartPayload', $unified_payload);

        $default_platform = in_array('instagram', $available_platforms, true)
            ? 'instagram'
            : $available_platforms[0];

        // Single global controller: every chart registers a re-render callback via
        // ddPlatformSwitcher.register(); [platform_switcher] buttons call .set(platform)
        // to fan that out and toggle .dd-platform-panel blocks in one shot.
        $controller_js = "window.ddPlatformSwitcher = (function () {\n"
            . "    var listeners = [];\n"
            . "    var active = null;\n"
            . "    function set(platform) {\n"
            . "        if (typeof ddChartPayload === 'undefined' || !platform || !ddChartPayload[platform]) return;\n"
            . "        active = platform;\n"
            . "        listeners.forEach(function (fn) { fn(platform); });\n"
            . "        document.querySelectorAll('.dd-platform-panel[data-platform]').forEach(function (panel) {\n"
            . "            panel.style.display = (panel.getAttribute('data-platform') === platform) ? '' : 'none';\n"
            . "        });\n"
            . "        document.querySelectorAll('.dd-platform-btn[data-platform]').forEach(function (btn) {\n"
            . "            btn.classList.toggle('active', btn.getAttribute('data-platform') === platform);\n"
            . "        });\n"
            . "    }\n"
            . "    function register(fn) {\n"
            . "        listeners.push(fn);\n"
            . "        if (active) fn(active);\n"
            . "    }\n"
            . "    function get() { return active; }\n"
            . "    return { set: set, register: register, get: get };\n"
            . "})();\n"
            . "document.addEventListener('DOMContentLoaded', function () {\n"
            . "    if (typeof ddChartPayload === 'undefined') return;\n"
            . "    ddPlatformSwitcher.set(" . wp_json_encode($default_platform) . ");\n"
            . "});\n";

        wp_add_inline_script('apexcharts', $controller_js, 'after');
    }

    /**
     * Handles the output of the [follower_growth_chart] shortcode (Monthly Bar Graph).
     */
    public function render_monthly_shortcode($atts = []): string
    {
        $atts = shortcode_atts(['platform' => 'instagram', 'id' => 0], (array) $atts, 'follower_growth_chart');
        $post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : $this->resolve_chart_post_id();

        if ($post_id <= 0 || empty($this->get_available_platforms($post_id))) {
            return $this->render_no_data_fallback();
        }

        $default_platform = in_array($atts['platform'], ['instagram', 'youtube', 'tiktok'], true) ? $atts['platform'] : 'instagram';

        ob_start();
?>
        <div class="dd-chart-card">
            <div id="ddMonthlyChart"></div>
            <div class="dd-chart-footer">
                <div>
                    In the last 12 months, <?php echo esc_html(get_the_title($post_id)); ?> <span class="chip" id="ddSummaryBadge">Loading...</span>
                </div>
                <div id="ddMonthlyLastUpdated">
                    Last updated: Loading...
                </div>
            </div>
        </div>

        <script>
            (function() {
                var ddMonthlyChartInstance = null;

                function ddFormatToK(value) {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const sign = num < 0 ? '-' : '';
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return sign + (absValue / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    return num.toString();
                }

                function ddRenderMonthlyChart(platform) {
                    if (typeof ddChartPayload === 'undefined') return;

                    const container = document.getElementById('ddMonthlyChart');
                    if (!container) return;

                    if (ddMonthlyChartInstance) {
                        ddMonthlyChartInstance.destroy();
                        ddMonthlyChartInstance = null;
                    }

                    const payload = ddChartPayload[platform];
                    const payloadMonthly = payload ? payload.monthly : null;
                    const noun = (payload && payload.meta && payload.meta.noun) ? payload.meta.noun : 'followers';
                    const nounCap = (payload && payload.meta && payload.meta.noun_cap) ? payload.meta.noun_cap : 'Followers';

                    if (!payloadMonthly || !payloadMonthly.labels || payloadMonthly.labels.length === 0) {
                        container.innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No ' + noun + ' data available for this creator.</p>';
                        const badge = document.getElementById('ddSummaryBadge');
                        if (badge) badge.innerText = 'No Data';
                        document.querySelectorAll('.dd-chart-footer').forEach(el => el.style.display = 'none');
                        const updated = document.getElementById('ddMonthlyLastUpdated');
                        if (updated) updated.innerText = 'Last updated: N/A';
                        return;
                    }

                    container.innerHTML = '';
                    document.querySelectorAll('.dd-chart-footer').forEach(el => el.style.display = '');

                    const badge = document.getElementById('ddSummaryBadge');
                    badge.classList.remove('Lost', 'Gained');
                    badge.classList.add(payloadMonthly.summary_action);
                    badge.innerText = payloadMonthly.summary_action + ' ' + payloadMonthly.summary_gain + ' ' + noun;
                    document.getElementById('ddMonthlyLastUpdated').innerText = 'Last updated: ' + payloadMonthly.last_updated;

                    const monthlyOptions = {
                        series: [{
                            name: nounCap + ' Gain',
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
                                return ddFormatToK(val);
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
                                formatter: ddFormatToK,
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
                                    return prefix + val.toLocaleString() + " " + noun;
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

                    ddMonthlyChartInstance = new ApexCharts(container, monthlyOptions);
                    ddMonthlyChartInstance.render();
                }

                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof ApexCharts === 'undefined') return;

                    if (typeof ddPlatformSwitcher !== 'undefined') {
                        ddPlatformSwitcher.register(ddRenderMonthlyChart);
                    } else if (typeof ddChartPayload !== 'undefined') {
                        ddRenderMonthlyChart(<?php echo wp_json_encode($default_platform); ?>);
                    }
                });
            })();
        </script>
    <?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [follower_timeline_chart] shortcode (Timeline Line Graph).
     */
    public function render_timeline_shortcode($atts = []): string
    {
        $atts = shortcode_atts(['platform' => 'instagram', 'id' => 0], (array) $atts, 'follower_timeline_chart');
        $post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : $this->resolve_chart_post_id();

        if ($post_id <= 0 || empty($this->get_available_platforms($post_id))) {
            return $this->render_no_data_fallback();
        }

        $default_platform = in_array($atts['platform'], ['instagram', 'youtube', 'tiktok'], true) ? $atts['platform'] : 'instagram';

        ob_start();
?>
        <div class="dd-chart-card">
            <div id="ddTimelineChart"></div>
            <div class="dd-timeline-footer">
                <div id="ddTimelineLastUpdated">Last updated: Loading...</div>
            </div>
        </div>

        <script>
            (function() {
                var ddTimelineChartInstance = null;

                function ddFormatToK(value) {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const sign = num < 0 ? '-' : '';
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return sign + (absValue / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    return num.toString();
                }

                function ddRenderTimelineChart(platform) {
                    if (typeof ddChartPayload === 'undefined') return;

                    const container = document.getElementById('ddTimelineChart');
                    if (!container) return;

                    if (ddTimelineChartInstance) {
                        ddTimelineChartInstance.destroy();
                        ddTimelineChartInstance = null;
                    }

                    const payload = ddChartPayload[platform];
                    const payloadTimeline = payload ? payload.timeline : null;
                    const payloadMonthly = payload ? payload.monthly : null;
                    const nounCap = (payload && payload.meta && payload.meta.noun_cap) ? payload.meta.noun_cap : 'Followers';

                    if (!payloadTimeline || !payloadTimeline.series_data || payloadTimeline.series_data.length === 0) {
                        container.innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No timeline data available.</p>';
                        const updated = document.getElementById('ddTimelineLastUpdated');
                        if (updated) updated.innerText = 'Last updated: N/A';
                        return;
                    }

                    container.innerHTML = '';
                    document.getElementById('ddTimelineLastUpdated').innerText = 'Last updated: ' + (payloadMonthly ? payloadMonthly.last_updated : 'N/A');

                    const timelineOptions = {
                        series: [{
                            name: nounCap,
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
                                formatter: ddFormatToK,
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

                    ddTimelineChartInstance = new ApexCharts(container, timelineOptions);
                    ddTimelineChartInstance.render();
                }

                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof ApexCharts === 'undefined') return;

                    if (typeof ddPlatformSwitcher !== 'undefined') {
                        ddPlatformSwitcher.register(ddRenderTimelineChart);
                    } else if (typeof ddChartPayload !== 'undefined') {
                        ddRenderTimelineChart(<?php echo wp_json_encode($default_platform); ?>);
                    }
                });
            })();
        </script>
    <?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [follower_growth_rate_chart] shortcode with integrated Time Filters.
     */
    public function render_growth_rate_shortcode($atts = []): string
    {
        $atts = shortcode_atts(['platform' => 'instagram', 'id' => 0], (array) $atts, 'follower_growth_rate_chart');
        $post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : $this->resolve_chart_post_id();

        if ($post_id <= 0 || empty($this->get_available_platforms($post_id))) {
            return $this->render_no_data_fallback();
        }

        $default_platform = in_array($atts['platform'], ['instagram', 'youtube', 'tiktok'], true) ? $atts['platform'] : 'instagram';

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
            (function() {
                var ddGrowthRateChartInstance = null;
                var ddGrowthRateSeries = [];

                function ddApplyGrowthRateFilter(days) {
                    if (!ddGrowthRateChartInstance || ddGrowthRateSeries.length === 0) return;

                    const latestTs = ddGrowthRateSeries[ddGrowthRateSeries.length - 1][0];
                    const cutoffTs = latestTs - (days * 24 * 60 * 60 * 1000);
                    const filteredData = ddGrowthRateSeries.filter(point => point[0] >= cutoffTs);

                    ddGrowthRateChartInstance.updateSeries([{
                        name: 'Growth Rate',
                        data: filteredData
                    }]);
                }

                function ddRenderGrowthRateChart(platform) {
                    if (typeof ddChartPayload === 'undefined') return;

                    const container = document.getElementById('ddGrowthRateChart');
                    if (!container) return;

                    if (ddGrowthRateChartInstance) {
                        ddGrowthRateChartInstance.destroy();
                        ddGrowthRateChartInstance = null;
                    }
                    ddGrowthRateSeries = [];

                    const payload = ddChartPayload[platform];
                    const payloadGrowthRate = payload ? payload.growth_rate : null;
                    const payloadMonthly = payload ? payload.monthly : null;

                    if (!payloadGrowthRate || !payloadGrowthRate.series_data || payloadGrowthRate.series_data.length === 0) {
                        container.innerHTML = '<p style="text-align:center; padding: 20px; color:#555;">No growth rate data available.</p>';
                        const updated = document.getElementById('ddGrowthRateLastUpdated');
                        if (updated) updated.innerText = 'Last updated: N/A';
                        return;
                    }

                    container.innerHTML = '';
                    document.getElementById('ddGrowthRateLastUpdated').innerText = 'Last updated: ' + (payloadMonthly ? payloadMonthly.last_updated : 'N/A');
                    ddGrowthRateSeries = payloadGrowthRate.series_data;

                    const growthRateOptions = {
                        series: [{
                            name: 'Growth Rate',
                            data: ddGrowthRateSeries
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

                    ddGrowthRateChartInstance = new ApexCharts(container, growthRateOptions);
                    ddGrowthRateChartInstance.render();

                    const activeBtn = document.querySelector('.dd-growth-rate-header .dd-time-btn.active');
                    ddApplyGrowthRateFilter(activeBtn ? parseInt(activeBtn.getAttribute('data-days')) : 30);
                }

                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof ApexCharts === 'undefined') return;

                    // --- TAB FILTER LOGIC --- attached once; re-applies to whichever platform is active.
                    const timeButtons = document.querySelectorAll('.dd-growth-rate-header .dd-time-btn');
                    timeButtons.forEach(btn => {
                        btn.addEventListener('click', function() {
                            timeButtons.forEach(b => b.classList.remove('active'));
                            this.classList.add('active');
                            ddApplyGrowthRateFilter(parseInt(this.getAttribute('data-days')));
                        });
                    });

                    if (typeof ddPlatformSwitcher !== 'undefined') {
                        ddPlatformSwitcher.register(ddRenderGrowthRateChart);
                    } else if (typeof ddChartPayload !== 'undefined') {
                        ddRenderGrowthRateChart(<?php echo wp_json_encode($default_platform); ?>);
                    }
                });
            })();
        </script>
    <?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [follower_like_range_chart] shortcode (Like Range Gradient Component).
     */
    public function render_like_range_shortcode($atts = []): string
    {
        $atts = shortcode_atts(['platform' => 'instagram', 'id' => 0], (array) $atts, 'follower_like_range_chart');
        $post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : $this->resolve_chart_post_id();

        if ($post_id <= 0 || empty($this->get_available_platforms($post_id))) {
            return $this->render_no_data_fallback();
        }

        $default_platform = in_array($atts['platform'], ['instagram', 'youtube', 'tiktok'], true) ? $atts['platform'] : 'instagram';

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
            (function() {
                var ddLikeRangeData = [];

                function ddFormatToK(value) {
                    const num = Number(value);
                    if (isNaN(num)) return value;
                    const absValue = Math.abs(num);
                    if (absValue >= 1000) {
                        return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
                    }
                    // For smaller floats like average likes (e.g. 566.79), round to a clean integer.
                    return Math.round(num).toLocaleString();
                }

                function ddUpdateLikeRangeUI(container, days) {
                    if (!ddLikeRangeData.length) return;

                    const contentDiv = container.querySelector('#ddLikeRangeContent');
                    const emptyDiv = container.querySelector('#ddLikeRangeEmpty');

                    const latestTs = ddLikeRangeData[ddLikeRangeData.length - 1].ts;
                    const cutoffTs = latestTs - (days * 24 * 60 * 60 * 1000);

                    let filteredLikes = ddLikeRangeData.filter(d => d.ts >= cutoffTs).map(d => d.likes);

                    // Sparse IC history (import_seed ~1 month ago): widen to all points when the window is too narrow.
                    if (filteredLikes.length < 2 && ddLikeRangeData.length >= 2) {
                        filteredLikes = ddLikeRangeData.map(d => d.likes);
                    }

                    if (filteredLikes.length < 2) {
                        contentDiv.style.display = 'none';
                        emptyDiv.style.display = 'block';
                        return;
                    }

                    contentDiv.style.display = 'block';
                    emptyDiv.style.display = 'none';

                    const min = Math.min(...filteredLikes);
                    const max = Math.max(...filteredLikes);
                    const avg = filteredLikes.reduce((a, b) => a + b, 0) / filteredLikes.length;
                    const formattedAvg = ddFormatToK(avg);

                    container.querySelector('.val-min').innerText = ddFormatToK(min);
                    container.querySelector('.val-max').innerText = ddFormatToK(max);

                    const markerPercent = min === max ? 50 : ((avg - min) / (max - min)) * 100;
                    const markerEl = container.querySelector('.range-marker');
                    markerEl.style.left = `${markerPercent}%`;
                    markerEl.setAttribute('data-value', 'Average: ' + formattedAvg);
                }

                function ddRenderLikeRangeChart(platform) {
                    if (typeof ddChartPayload === 'undefined') return;

                    const container = document.getElementById('ddLikeRangeWrapper');
                    if (!container) return;

                    const contentDiv = container.querySelector('#ddLikeRangeContent');
                    const emptyDiv = container.querySelector('#ddLikeRangeEmpty');
                    const payload = ddChartPayload[platform];
                    const payloadLikeRange = payload ? payload.like_range : null;

                    if (!payloadLikeRange || !payloadLikeRange.series_data || payloadLikeRange.series_data.length === 0) {
                        ddLikeRangeData = [];
                        contentDiv.style.display = 'none';
                        emptyDiv.style.display = 'block';
                        return;
                    }

                    ddLikeRangeData = payloadLikeRange.series_data;

                    const tabs = container.querySelectorAll('.dd-time-btn');
                    const defaultDays = payloadLikeRange.default_days || 30;
                    const defaultBtn = container.querySelector('.dd-time-btn[data-days="' + defaultDays + '"]')
                        || container.querySelector('.dd-time-btn[data-days="365"]')
                        || container.querySelector('.dd-time-btn');

                    tabs.forEach(b => b.classList.remove('active'));
                    if (defaultBtn) {
                        defaultBtn.classList.add('active');
                        ddUpdateLikeRangeUI(container, parseInt(defaultBtn.getAttribute('data-days')));
                    }
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const container = document.getElementById('ddLikeRangeWrapper');
                    if (!container) return;

                    const tabs = container.querySelectorAll('.dd-time-btn');
                    tabs.forEach(btn => {
                        btn.addEventListener('click', function() {
                            tabs.forEach(b => b.classList.remove('active'));
                            this.classList.add('active');
                            ddUpdateLikeRangeUI(container, parseInt(this.getAttribute('data-days')));
                        });
                    });

                    if (typeof ddPlatformSwitcher !== 'undefined') {
                        ddPlatformSwitcher.register(ddRenderLikeRangeChart);
                    } else if (typeof ddChartPayload !== 'undefined') {
                        ddRenderLikeRangeChart(<?php echo wp_json_encode($default_platform); ?>);
                    }
                });
            })();
        </script>
<?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [platform_switcher] shortcode — the clickable Instagram/YouTube/TikTok
     * selector that drives every chart + [platform_panel] on the page via ddPlatformSwitcher.set().
     * Buttons only render for platforms trb_platform_has_data() confirms (checked across both
     * CreatorDB and Influencers.Club signals) — a platform the influencer has no data for is omitted.
     */
    public function render_platform_switcher_shortcode($atts = []): string
    {
        $atts = shortcode_atts(['id' => 0, 'platforms' => ''], (array) $atts, 'platform_switcher');
        $post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : $this->resolve_chart_post_id();

        if ($post_id <= 0) {
            return '';
        }

        $requested = array_filter(array_map('trim', explode(',', (string) $atts['platforms'])));
        $candidates = $requested !== [] ? $requested : ['instagram', 'youtube', 'tiktok'];

        $labels = [
            'instagram' => 'Instagram',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
        ];
        $icons = [
            'instagram' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 2.2c3.2 0 3.6 0 4.9.07 1.2.06 2.2.28 3 .6.8.32 1.4.75 2 1.35.6.6 1 1.2 1.35 2 .3.8.53 1.8.6 3C23.9 10.4 24 10.8 24 14s-.07 3.6-.13 4.9c-.06 1.2-.28 2.2-.6 3-.32.8-.75 1.4-1.35 2-.6.6-1.2 1-2 1.35-.8.3-1.8.53-3 .6-1.27.06-1.67.07-4.9.07s-3.6 0-4.9-.07c-1.2-.06-2.2-.28-3-.6-.8-.32-1.4-.75-2-1.35-.6-.6-1-1.2-1.35-2-.3-.8-.53-1.8-.6-3C.07 17.6 0 17.2 0 14s.07-3.6.13-4.9c.06-1.2.28-2.2.6-3 .32-.8.75-1.4 1.35-2 .6-.6 1.2-1 2-1.35.8-.3 1.8-.53 3-.6C8.4 2.2 8.8 2.2 12 2.2Z" transform="translate(0 -2)"/><circle cx="12" cy="12" r="3.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="17.4" cy="6.6" r="1.1"/></svg>',
            'youtube'   => '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M23 7.2s-.23-1.6-.94-2.32c-.9-.94-1.9-.94-2.36-1C16.4 3.6 12 3.6 12 3.6h-.01s-4.4 0-7.7.28c-.46.06-1.46.06-2.36 1C1.23 5.6 1 7.2 1 7.2S.77 9.06.77 10.9v1.9c0 1.85.23 3.7.23 3.7s.23 1.6.94 2.32c.9.94 2.08.9 2.6 1 1.9.18 8.06.28 8.06.28s4.4-.01 7.7-.28c.46-.06 1.46-.06 2.36-1 .71-.72.94-2.32.94-2.32s.23-1.85.23-3.7v-1.9c0-1.85-.23-3.7-.23-3.7ZM9.7 14.9V8.9l6.2 3-6.2 3Z"/></svg>',
            'tiktok'    => '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M16.6 2h-3.2v13.9c0 1.5-1.2 2.7-2.7 2.7-1.5 0-2.7-1.2-2.7-2.7 0-1.5 1.2-2.7 2.7-2.7.3 0 .6.05.9.14V9.9c-.3-.04-.6-.06-.9-.06-3.2 0-5.8 2.6-5.8 5.8S7.5 21.4 10.7 21.4s5.8-2.6 5.8-5.8V8.6c1.2.9 2.7 1.4 4.3 1.4V6.8c-2.3 0-4.2-1.9-4.2-4.2V2Z"/></svg>',
        ];

        $available = [];
        foreach ($candidates as $platform) {
            if (!isset($labels[$platform])) {
                continue;
            }
            if (!function_exists('trb_platform_has_data') || !trb_platform_has_data($post_id, $platform)) {
                continue;
            }
            $available[] = $platform;
        }

        if (empty($available)) {
            return '';
        }

        // Instagram is the default when present; otherwise the first available platform.
        $default_platform = in_array('instagram', $available, true) ? 'instagram' : $available[0];

        $buttons = '';
        foreach ($available as $platform) {
            $is_active = $platform === $default_platform ? ' active' : '';
            $buttons .= sprintf(
                '<button type="button" class="dd-platform-btn%s" data-platform="%s" onclick="if(window.ddPlatformSwitcher){ddPlatformSwitcher.set(this.dataset.platform);}">%s<span>%s</span></button>',
                esc_attr($is_active),
                esc_attr($platform),
                $icons[$platform],
                esc_html($labels[$platform])
            );
        }

        ob_start();
?>
        <div class="dd-platform-switcher"><?php echo $buttons; ?></div>
        <style>
            .dd-platform-switcher {
                display: inline-flex;
                gap: 6px;
                flex-wrap: wrap;
            }

            .dd-platform-switcher .dd-platform-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 14px;
                border: 1px solid #E0E0E0;
                border-radius: 8px;
                background: #F5F5F5;
                color: #555;
                font-size: 13px;
                font-family: inherit;
                cursor: pointer;
                transition: background-color .15s ease, color .15s ease, border-color .15s ease;
            }

            .dd-platform-switcher .dd-platform-btn:hover {
                background: #EDEDED;
            }

            .dd-platform-switcher .dd-platform-btn.active {
                background: #FF7347;
                border-color: #FF7347;
                color: #fff;
            }

            .dd-platform-switcher .dd-platform-btn svg {
                flex: 0 0 auto;
            }
        </style>
<?php
        return ob_get_clean();
    }

    /**
     * Handles the output of the [platform_panel platform="youtube"]...[/platform_panel] shortcode —
     * a plain wrapper for non-Elementor usage that the switcher shows/hides. In Elementor, wrap the
     * platform's block in a container with class "dd-platform-panel" and attribute data-platform="…".
     */
    public function render_platform_panel_shortcode($atts = [], $content = ''): string
    {
        $atts = shortcode_atts(['platform' => 'instagram'], (array) $atts, 'platform_panel');
        $platform = in_array($atts['platform'], ['instagram', 'youtube', 'tiktok'], true) ? $atts['platform'] : 'instagram';
        $style = $platform === 'instagram' ? '' : ' style="display:none;"';

        return sprintf(
            '<div class="dd-platform-panel" data-platform="%s"%s>%s</div>',
            esc_attr($platform),
            $style,
            do_shortcode((string) $content)
        );
    }
}

// Instantiate the plugin class.
new DD_Follower_Growth_Chart();
