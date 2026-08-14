<?php

/**
 * Plugin Name: Custom myCRED Frontend Log
 * Description: An object-oriented, AJAX-powered myCRED points log — summary strip, typed/dated/searchable ledger with a running balance column, and filtered CSV export.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 3.0.0
 */

// Prevent direct file access for security
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Custom_MyCred_Frontend_Log
 *
 * Renders a member's own myCred transaction history: a summary strip (balance,
 * this month's spend/earn, unlocks-vs-messages split), a filterable/searchable
 * ledger table with a running balance column, and a CSV export — all scoped to
 * the current user server-side (see handle_ajax_request()/handle_export_request()).
 *
 * Transaction type labels/grouping come from dd_credit_log_refs() (includes/core/helpers.php)
 * rather than a hardcoded list here, so the ledger can never disagree with the
 * credits ticker ([credits_remaining]) about what a given ref means.
 */
class Custom_MyCred_Frontend_Log
{
    /**
     * Initializes the class, hooking the shortcode and AJAX handlers into WordPress.
     *
     * @return void
     */
    public function __construct()
    {
        add_action('init', array($this, 'register_shortcode'));

        // Member-only: no wp_ajax_nopriv_ registration. Both handlers derive the
        // user from the current session (see resolve_request_user_id()) rather
        // than trusting a posted user_id, so one member can never page through
        // another member's transaction history.
        add_action('wp_ajax_mycred_load_log_page', array($this, 'handle_ajax_request'));
        add_action('wp_ajax_dd_export_credit_history', array($this, 'handle_export_request'));
    }

    /**
     * Registers the custom shortcode with the WordPress API.
     *
     * @return void
     */
    public function register_shortcode()
    {
        add_shortcode('custom_mycred_log', array($this, 'render_shortcode'));
    }

    /**
     * Resolves which user a request is allowed to act on. An explicit user_id
     * in the request is honoured only for an admin (manage_options) — every
     * other caller, regardless of what it posts, is scoped to itself. This is
     * the single choke point both AJAX handlers and the shortcode route
     * through, so "view someone else's credit history" has exactly one place
     * to be wrong.
     *
     * @return int 0 if no user is authenticated for this request.
     */
    private function resolve_request_user_id()
    {
        $current = get_current_user_id();
        if (! $current) {
            return 0;
        }

        if (isset($_POST['user_id']) && current_user_can('manage_options')) {
            $requested = absint($_POST['user_id']);
            if ($requested) {
                return $requested;
            }
        }

        return $current;
    }

    /**
     * Sanitizes the shared set of filter/paging params from $_POST, used by
     * both the paginate/filter AJAX handler and the CSV export handler so the
     * two can never interpret the same filter bar differently.
     *
     * @return array{ref:string, direction:string, date_from:string, date_to:string, search:string, limit:int, page:int, ctype:string}
     */
    private function get_filters_from_request()
    {
        $ref       = isset($_POST['ref']) ? sanitize_key($_POST['ref']) : '';
        $direction = isset($_POST['direction']) ? sanitize_key($_POST['direction']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to   = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $search    = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit     = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
        $page      = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $ctype     = isset($_POST['ctype']) ? sanitize_key($_POST['ctype']) : 'mycred_default';

        if (! in_array($direction, array('earn', 'spend'), true)) {
            $direction = '';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $date_from = '';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $date_to = '';
        }
        if (! in_array($limit, array(20, 50, 100), true)) {
            $limit = 20;
        }

        return array(
            'ref'       => $ref,
            'direction' => $direction,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'search'    => $search,
            'limit'     => $limit,
            'page'      => max(1, $page),
            'ctype'     => $ctype ?: 'mycred_default',
        );
    }

    /**
     * Translates sanitized filters into myCRED_Query_Log constructor args.
     * Kept separate from get_log() so the export handler can build the same
     * args with number=-1 (all matching rows) instead of a page size.
     *
     * @param int   $user_id
     * @param array $filters Shape of get_filters_from_request().
     * @return array
     */
    private function build_query_args($user_id, $filters)
    {
        $args = array(
            'user_id' => $user_id,
            'ctype'   => $filters['ctype'],
            'orderby' => 'time',
            'order'   => 'DESC',
        );

        $refs = dd_credit_log_refs();

        if (! empty($filters['ref']) && isset($refs[$filters['ref']])) {
            $args['ref'] = $filters['ref'];
        }

        // Earn/spend is decided by the sign of the amount actually logged, not
        // by ref membership — so a transaction using a ref this theme doesn't
        // recognise (e.g. a manual admin adjustment) still lands in the right
        // pill instead of silently vanishing from both.
        if ($filters['direction'] === 'earn') {
            $args['amount'] = array('num' => 0, 'compare' => '>');
        } elseif ($filters['direction'] === 'spend') {
            $args['amount'] = array('num' => 0, 'compare' => '<');
        }

        if ($filters['date_from'] && $filters['date_to']) {
            $args['time'] = array('dates' => array($filters['date_from'], $filters['date_to'] . ' 23:59:59'), 'compare' => 'BETWEEN');
        } elseif ($filters['date_from']) {
            $args['time'] = array('dates' => $filters['date_from'], 'compare' => '>=');
        } elseif ($filters['date_to']) {
            $args['time'] = array('dates' => $filters['date_to'] . ' 23:59:59', 'compare' => '<=');
        }

        if ($filters['search'] !== '') {
            $args['s'] = $filters['search'];
        }

        return $args;
    }

    /**
     * Runs a myCRED_Query_Log for one page of a user's filtered history.
     *
     * @param int   $user_id
     * @param array $filters
     * @return myCRED_Query_Log
     */
    private function get_log($user_id, $filters)
    {
        $args             = $this->build_query_args($user_id, $filters);
        $args['number']   = $filters['limit'];
        $args['paged']    = $filters['page'];

        return new myCRED_Query_Log($args);
    }

    /**
     * Sum of `creds` for every row that precedes the current page under the
     * exact same filters/ordering — i.e. every row newer than this page, so
     * the running balance column can be derived without a second, drifting
     * copy of the filter logic. Reuses $log->where/$log->sortby verbatim
     * (both built by myCRED_Query_Log itself) rather than re-deriving them,
     * so this can never disagree with what the visible rows were actually
     * filtered by.
     *
     * @param myCRED_Query_Log $log
     * @param string           $table
     * @param int              $offset Rows on pages before the current one.
     * @return float
     */
    private function get_prior_rows_sum($log, $table, $offset)
    {
        if ($offset <= 0) {
            return 0.0;
        }

        global $wpdb;
        $sql = "SELECT SUM(creds) FROM (SELECT creds FROM {$table} {$log->where} {$log->sortby} LIMIT " . (int) $offset . ') AS dd_prior_rows';
        $sum = $wpdb->get_var($sql);

        return $sum !== null ? (float) $sum : 0.0;
    }

    /**
     * Resolves the myCred log table name the same way the plugin itself does
     * (mycred()->log_table), falling back to the real table name — the
     * previous fallback guessed `mycred_log`, which is wrong: myCred's own
     * table is `myCRED_log` (case matters wherever MySQL is case-sensitive).
     *
     * @param string $ctype
     * @return string
     */
    private function get_log_table($ctype)
    {
        global $wpdb;
        $mycred = mycred($ctype);

        return ! empty($mycred->log_table) ? $mycred->log_table : $wpdb->prefix . 'myCRED_log';
    }

    /**
     * Processes the incoming AJAX request for a filtered/paged slice of the
     * ledger table. Validates the nonce and scopes strictly to the requesting
     * user (see resolve_request_user_id()).
     *
     * @return void Outputs JSON and terminates execution.
     */
    public function handle_ajax_request()
    {
        check_ajax_referer('mycred_log_ajax_nonce', 'security');

        $user_id = $this->resolve_request_user_id();
        if (! $user_id) {
            wp_send_json_error('Authentication required.');
        }

        $filters = $this->get_filters_from_request();
        $log     = $this->get_log($user_id, $filters);

        wp_send_json_success(array(
            'rows'       => $this->get_rows_html($log, $filters, $user_id),
            'pagination' => $this->get_pagination_html($log, $filters),
        ));
    }

    /**
     * Streams the current user's filtered history as a CSV download. Filters
     * are read through the same get_filters_from_request()/build_query_args()
     * path as the table itself, with no row limit (number=-1), so "export"
     * always matches whatever the table is currently showing.
     *
     * @return void Outputs a file and terminates execution.
     */
    public function handle_export_request()
    {
        check_ajax_referer('mycred_export_ajax_nonce', 'security');

        $user_id = $this->resolve_request_user_id();
        if (! $user_id) {
            wp_die(esc_html__('Authentication required.', 'hello-elementor-child'));
        }

        $filters           = $this->get_filters_from_request();
        $args              = $this->build_query_args($user_id, $filters);
        $args['number']    = -1;
        $args['fields']    = 'all';
        $log               = new myCRED_Query_Log($args);
        $refs              = dd_credit_log_refs();
        $mycred            = mycred($filters['ctype']);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . sanitize_file_name('credit-history-' . gmdate('Y-m-d') . '.csv'));
        while (ob_get_level()) {
            ob_end_clean();
        }

        $out = fopen('php://output', 'w');
        fputcsv($out, array(
            dd_get_message('dd_msg_credit_log_col_date'),
            dd_get_message('dd_msg_credit_log_col_type'),
            dd_get_message('dd_msg_credit_log_col_details'),
            dd_get_message('dd_msg_credit_log_col_amount'),
        ));

        foreach ((array) $log->results as $entry) {
            $type_label = isset($refs[$entry->ref]) ? $refs[$entry->ref]['label'] : ucwords(str_replace('_', ' ', $entry->ref));
            $details    = wp_strip_all_tags($mycred->parse_template_tags($entry->entry, $entry));
            $amount     = ($entry->creds > 0 ? '+' : '') . $mycred->format_creds($entry->creds);

            fputcsv($out, array(
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry->time),
                $this->csv_safe($type_label),
                $this->csv_safe($details),
                $this->csv_safe($amount),
            ));
        }

        fclose($out);
        exit;
    }

    /**
     * Neutralizes CSV formula injection: a cell whose first character is one
     * Excel/Sheets treats as a formula trigger is prefixed with a leading
     * apostrophe, per the standard OWASP mitigation.
     *
     * @param string $value
     * @return string
     */
    private function csv_safe($value)
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], array('=', '+', '-', '@', "\t", "\r"), true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Callback function for the shortcode to process attributes and trigger the initial renderer.
     *
     * @param array $atts User-defined shortcode attributes.
     * @return string     The buffered HTML output of the points log wrapper and initial layout.
     */
    public function render_shortcode($atts)
    {
        $args = shortcode_atts(array(
            'limit'          => 20,
            'ctype'          => 'mycred_default',
            'show_summary'   => 'yes',
            'show_export'    => 'yes',
        ), $atts, 'custom_mycred_log');

        return $this->get_layout_html($args);
    }

    /**
     * Resolves a creator (influencer) reference into a small linked chip —
     * avatar + name — for a ledger row whose ref both links_creator and
     * carries a ref_id. Degrades gracefully: a deleted post renders nothing
     * (caller falls back to the raw entry text) and an unpublished one still
     * shows a name, just without a dead link.
     *
     * @param int $ref_id
     * @return string
     */
    private function render_creator_chip($ref_id)
    {
        $ref_id = absint($ref_id);
        if (! $ref_id) {
            return '';
        }

        $post = get_post($ref_id);
        if (! $post || $post->post_type !== 'influencer') {
            return '';
        }

        $name  = get_the_title($post);
        $thumb = get_the_post_thumbnail($post->ID, 'thumbnail', array('class' => 'mycred-log-creator-avatar'));

        if ($post->post_status !== 'publish') {
            return '<span class="mycred-log-creator mycred-log-creator--gone">' . $thumb
                . '<span class="mycred-log-creator-name">' . esc_html($name) . ' <em>(' . esc_html__('removed', 'hello-elementor-child') . ')</em></span></span>';
        }

        return '<a href="' . esc_url(get_permalink($post->ID)) . '" class="mycred-log-creator">' . $thumb
            . '<span class="mycred-log-creator-name">' . esc_html($name) . '</span></a>';
    }

    /**
     * Generates the tabular data rows for one page of results, including the
     * Type badge, creator-linked Details cell, and running Balance column.
     *
     * @param myCRED_Query_Log $log
     * @param array            $filters
     * @param int              $user_id
     * @return string HTML markup containing the `<tr>` elements.
     */
    private function get_rows_html($log, $filters, $user_id)
    {
        if (! $log->have_entries()) {
            return '<tr><td colspan="5" class="mycred-empty-log">' . $this->empty_state_html() . '</td></tr>';
        }

        $mycred  = mycred($filters['ctype']);
        $refs    = dd_credit_log_refs();
        $table   = $this->get_log_table($filters['ctype']);
        $offset  = ($filters['page'] - 1) * $filters['limit'];
        $balance = (float) (function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user_id, $filters['ctype']) : 0);
        $running = $balance - $this->get_prior_rows_sum($log, $table, $offset);

        ob_start();
        foreach ($log->results as $entry) :
            $ref_meta    = isset($refs[$entry->ref]) ? $refs[$entry->ref] : null;
            $type_label  = $ref_meta ? $ref_meta['label'] : ucwords(str_replace('_', ' ', $entry->ref));
            $direction   = $ref_meta ? $ref_meta['direction'] : ($entry->creds >= 0 ? 'earn' : 'spend');
            $creator_html = ($ref_meta && ! empty($ref_meta['links_creator'])) ? $this->render_creator_chip($entry->ref_id) : '';
            $balance_after = $running;
            $running      -= (float) $entry->creds;
        ?>
            <tr>
                <td data-label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_col_date')); ?>">
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry->time)); ?>
                </td>
                <td data-label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_col_type')); ?>">
                    <span class="mycred-type-badge mycred-type-badge--<?php echo esc_attr($direction); ?>"><?php echo esc_html($type_label); ?></span>
                </td>
                <td data-label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_col_details')); ?>">
                    <?php if ($creator_html) : ?>
                        <?php echo wp_kses_post($creator_html); ?>
                    <?php else : ?>
                        <?php echo wp_kses_post($mycred->parse_template_tags($entry->entry, $entry)); ?>
                    <?php endif; ?>
                </td>
                <td data-label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_col_amount')); ?>" class="<?php echo ($entry->creds > 0) ? 'positive-points' : 'negative-points'; ?>">
                    <?php
                    $prefix = ($entry->creds > 0) ? '+' : '';
                    echo esc_html($prefix . $mycred->format_creds($entry->creds));
                    ?>
                </td>
                <td data-label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_col_balance')); ?>">
                    <?php echo esc_html($mycred->format_creds($balance_after)); ?>
                </td>
            </tr>
        <?php
        endforeach;
        return ob_get_clean();
    }

    private function empty_state_html()
    {
        return '<span class="mycred-empty-log-text">' . esc_html(dd_get_message('dd_msg_credit_log_empty')) . '</span>'
            . ' <a class="mycred-empty-log-cta" href="' . esc_url(function_exists('dd_get_buy_credits_url') ? dd_get_buy_credits_url() : '#') . '">'
            . esc_html(dd_get_message('dd_msg_unlock_buy_credits_btn')) . '</a>';
    }

    /**
     * Generates numbered pagination (first/prev/window/next/last), driven by
     * $log->num_rows/$log->max_num_pages — both computed by myCRED_Query_Log
     * itself from the identical filtered query, so this can never disagree
     * with the rows actually returned the way a hand-rolled parallel COUNT
     * query risked doing.
     *
     * @param myCRED_Query_Log $log
     * @param array            $filters
     * @return string
     */
    private function get_pagination_html($log, $filters)
    {
        $total_pages = (int) $log->max_num_pages;
        $page        = $filters['page'];

        if ($total_pages <= 1) {
            return '';
        }

        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($total_pages, $page + $window);

        ob_start();
        ?>
        <div class="mycred-pagination-controls">
            <button class="mycred-btn-paginate prev" data-target-page="<?php echo esc_attr($page - 1); ?>" <?php disabled($page <= 1); ?>>
                &laquo; Previous
            </button>
            <div class="mycred-pagination-pages">
                <?php if ($start > 1) : ?>
                    <button class="mycred-btn-page" data-target-page="1">1</button>
                    <?php if ($start > 2) : ?><span class="mycred-pagination-ellipsis">&hellip;</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++) : ?>
                    <button class="mycred-btn-page<?php echo ($i === $page) ? ' is-current' : ''; ?>" data-target-page="<?php echo esc_attr($i); ?>" <?php echo ($i === $page) ? 'aria-current="page"' : ''; ?>><?php echo esc_html($i); ?></button>
                <?php endfor; ?>

                <?php if ($end < $total_pages) : ?>
                    <?php if ($end < $total_pages - 1) : ?><span class="mycred-pagination-ellipsis">&hellip;</span><?php endif; ?>
                    <button class="mycred-btn-page" data-target-page="<?php echo esc_attr($total_pages); ?>"><?php echo esc_html($total_pages); ?></button>
                <?php endif; ?>
            </div>
            <button class="mycred-btn-paginate next" data-target-page="<?php echo esc_attr($page + 1); ?>" <?php disabled($page >= $total_pages); ?>>
                Next &raquo;
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Stat tiles answering "where did my credits go?" at a glance: current
     * balance, this month's spend/earn, and (conditionally) a spend split by
     * action. Balance reuses dd_credit_capacity() so this strip and the
     * banner ticker can never disagree about the current balance.
     *
     * The split tile only compares actions that currently cost credits. A
     * free action (dd_outreach_credit_cost() === 0, or unlocks made free via
     * the dd_unlock_credit_cost filter) is never logged as a transaction at
     * all — DD_Outreach_Manager::process_elementor_form_response() only
     * calls mycred_subtract() when the cost is above 0 — so its count would
     * always read 0 and wrongly read as "nobody's using this" rather than
     * "this is free right now". Renders a single-action tile when only one
     * side is costed, and omits the tile entirely when neither is.
     *
     * @param int    $user_id
     * @param string $ctype
     * @return string
     */
    private function get_summary_html($user_id, $ctype)
    {
        global $wpdb;
        $table = $this->get_log_table($ctype);
        $mycred = mycred($ctype);

        $month_start = strtotime(date_i18n('Y-m-01 00:00:00'));

        $capacity = function_exists('dd_credit_capacity') ? dd_credit_capacity($user_id) : null;
        $balance  = $capacity ? $capacity['balance'] : (function_exists('mycred_get_users_balance') ? mycred_get_users_balance($user_id, $ctype) : 0);

        $spent = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(creds) FROM {$table} WHERE user_id = %d AND ctype = %s AND time >= %d AND creds < 0",
            $user_id,
            $ctype,
            $month_start
        ));
        $earned = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(creds) FROM {$table} WHERE user_id = %d AND ctype = %s AND time >= %d AND creds > 0",
            $user_id,
            $ctype,
            $month_start
        ));

        $split_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ref, COUNT(id) as cnt FROM {$table} WHERE user_id = %d AND ctype = %s AND time >= %d AND ref IN ('unlock_influencer', 'buy_content', 'outreach_submission') GROUP BY ref",
            $user_id,
            $ctype,
            $month_start
        ));
        $unlocks_count  = 0;
        $messages_count = 0;
        foreach ($split_rows as $row) {
            if ($row->ref === 'outreach_submission') {
                $messages_count += (int) $row->cnt;
            } else {
                $unlocks_count += (int) $row->cnt;
            }
        }

        $actions        = function_exists('dd_credit_actions') ? dd_credit_actions($user_id) : [];
        $unlock_costed  = ! empty($actions['unlock']) && $actions['unlock']['cost'] > 0;
        $message_costed = ! empty($actions['message']) && $actions['message']['cost'] > 0;

        ob_start();
        ?>
        <div class="mycred-summary-strip">
            <div class="mycred-summary-tile">
                <span class="mycred-summary-label"><?php echo esc_html(dd_get_message('dd_msg_credit_log_summary_balance')); ?></span>
                <span class="mycred-summary-value"><?php echo esc_html($mycred->format_creds($balance)); ?></span>
            </div>
            <div class="mycred-summary-tile">
                <span class="mycred-summary-label"><?php echo esc_html(dd_get_message('dd_msg_credit_log_summary_spent')); ?></span>
                <span class="mycred-summary-value mycred-summary-value--spend"><?php echo esc_html($mycred->format_creds(abs($spent))); ?></span>
            </div>
            <div class="mycred-summary-tile">
                <span class="mycred-summary-label"><?php echo esc_html(dd_get_message('dd_msg_credit_log_summary_earned')); ?></span>
                <span class="mycred-summary-value mycred-summary-value--earn"><?php echo esc_html($mycred->format_creds($earned)); ?></span>
            </div>
            <?php if ($unlock_costed && $message_costed) : ?>
                <div class="mycred-summary-tile">
                    <span class="mycred-summary-label"><?php echo esc_html(dd_get_message('dd_msg_credit_log_summary_split')); ?></span>
                    <span class="mycred-summary-value mycred-summary-value--split"><?php echo esc_html($unlocks_count); ?> / <?php echo esc_html($messages_count); ?></span>
                </div>
            <?php elseif ($unlock_costed) : ?>
                <div class="mycred-summary-tile">
                    <span class="mycred-summary-label"><?php echo esc_html(dd_get_message('dd_msg_credit_log_summary_unlocks_only')); ?></span>
                    <span class="mycred-summary-value"><?php echo esc_html($unlocks_count); ?></span>
                </div>
            <?php elseif ($message_costed) : ?>
                <div class="mycred-summary-tile">
                    <span class="mycred-summary-label"><?php echo esc_html(dd_get_message('dd_msg_credit_log_summary_messages_only')); ?></span>
                    <span class="mycred-summary-value"><?php echo esc_html($messages_count); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Segmented All/Spent/Earned pills, the type dropdown (grouped by
     * direction via dd_credit_log_refs()), date range, free-text search,
     * per-page selector, and the CSV export form. $filters seeds every
     * control's initial state from the request that produced this render —
     * either the shortcode defaults or, on first page load, the ?ct_* query
     * args read in get_layout_html() — so a bookmarked/shared filtered URL
     * renders pre-filtered without a JS round trip.
     *
     * @param array $filters
     * @param bool  $show_export
     * @return string
     */
    private function get_filter_bar_html($filters, $show_export)
    {
        $refs   = dd_credit_log_refs();
        $earned = array_filter($refs, function ($r) {
            return $r['direction'] === 'earn';
        });
        $spent = array_filter($refs, function ($r) {
            return $r['direction'] === 'spend';
        });

        ob_start();
        ?>
        <div class="mycred-filter-bar">
            <div class="mycred-filter-pills" role="group" aria-label="<?php esc_attr_e('Filter by direction', 'hello-elementor-child'); ?>">
                <button type="button" class="mycred-pill<?php echo $filters['direction'] === '' ? ' is-active' : ''; ?>" data-direction=""><?php echo esc_html(dd_get_message('dd_msg_credit_log_filter_all')); ?></button>
                <button type="button" class="mycred-pill<?php echo $filters['direction'] === 'spend' ? ' is-active' : ''; ?>" data-direction="spend"><?php echo esc_html(dd_get_message('dd_msg_credit_log_filter_spent')); ?></button>
                <button type="button" class="mycred-pill<?php echo $filters['direction'] === 'earn' ? ' is-active' : ''; ?>" data-direction="earn"><?php echo esc_html(dd_get_message('dd_msg_credit_log_filter_earned')); ?></button>
            </div>

            <div class="mycred-filter-container">
                <label for="mycred-log-filter" class="screen-reader-text">Filter by Transaction Type</label>
                <select id="mycred-log-filter" class="mycred-log-filter">
                    <option value=""><?php echo esc_html(dd_get_message('dd_msg_credit_log_filter_all')); ?></option>
                    <?php if ($spent) : ?>
                        <optgroup label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_filter_spent')); ?>">
                            <?php foreach ($spent as $ref_key => $meta) : ?>
                                <option value="<?php echo esc_attr($ref_key); ?>" <?php selected($filters['ref'], $ref_key); ?>><?php echo esc_html($meta['label']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ($earned) : ?>
                        <optgroup label="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_filter_earned')); ?>">
                            <?php foreach ($earned as $ref_key => $meta) : ?>
                                <option value="<?php echo esc_attr($ref_key); ?>" <?php selected($filters['ref'], $ref_key); ?>><?php echo esc_html($meta['label']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>

                <label for="mycred-log-date-from" class="screen-reader-text">From date</label>
                <input type="date" id="mycred-log-date-from" class="mycred-log-date" value="<?php echo esc_attr($filters['date_from']); ?>" placeholder="From">

                <label for="mycred-log-date-to" class="screen-reader-text">To date</label>
                <input type="date" id="mycred-log-date-to" class="mycred-log-date" value="<?php echo esc_attr($filters['date_to']); ?>" placeholder="To">

                <label for="mycred-log-search" class="screen-reader-text">Search transactions</label>
                <input type="search" id="mycred-log-search" class="mycred-log-search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php echo esc_attr(dd_get_message('dd_msg_credit_log_search_placeholder')); ?>">

                <label for="mycred-log-limit" class="screen-reader-text">Rows per page</label>
                <select id="mycred-log-limit" class="mycred-log-limit">
                    <?php foreach (array(20, 50, 100) as $opt) : ?>
                        <option value="<?php echo esc_attr($opt); ?>" <?php selected($filters['limit'], $opt); ?>><?php echo esc_html($opt); ?> / page</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($show_export) : ?>
                <form class="mycred-export-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank">
                    <input type="hidden" name="action" value="dd_export_credit_history">
                    <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('mycred_export_ajax_nonce')); ?>">
                    <input type="hidden" name="ctype" value="<?php echo esc_attr($filters['ctype']); ?>">
                    <input type="hidden" name="ref" class="mycred-export-field" data-field="ref" value="<?php echo esc_attr($filters['ref']); ?>">
                    <input type="hidden" name="direction" class="mycred-export-field" data-field="direction" value="<?php echo esc_attr($filters['direction']); ?>">
                    <input type="hidden" name="date_from" class="mycred-export-field" data-field="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
                    <input type="hidden" name="date_to" class="mycred-export-field" data-field="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
                    <input type="hidden" name="search" class="mycred-export-field" data-field="search" value="<?php echo esc_attr($filters['search']); ?>">
                    <button type="submit" class="mycred-btn-export"><?php echo esc_html(dd_get_message('dd_msg_credit_log_export_btn')); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Reads the ?ct_* query args a shared/bookmarked filtered link carries,
     * for the initial (non-AJAX) render only — once the page is interactive,
     * dd-credit-history.js owns filter state and syncs the URL itself via
     * history.replaceState().
     *
     * @param array $defaults
     * @return array
     */
    private function get_initial_filters_from_url($defaults)
    {
        $filters = $defaults;

        if (isset($_GET['ct_ref'])) {
            $filters['ref'] = sanitize_key(wp_unslash($_GET['ct_ref']));
        }
        if (isset($_GET['ct_dir']) && in_array($_GET['ct_dir'], array('earn', 'spend'), true)) {
            $filters['direction'] = sanitize_key($_GET['ct_dir']);
        }
        if (isset($_GET['ct_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ct_from'])) {
            $filters['date_from'] = sanitize_text_field($_GET['ct_from']);
        }
        if (isset($_GET['ct_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ct_to'])) {
            $filters['date_to'] = sanitize_text_field($_GET['ct_to']);
        }
        if (isset($_GET['ct_q'])) {
            $filters['search'] = sanitize_text_field(wp_unslash($_GET['ct_q']));
        }
        if (isset($_GET['ct_page'])) {
            $filters['page'] = max(1, absint($_GET['ct_page']));
        }
        if (isset($_GET['ct_limit']) && in_array((int) $_GET['ct_limit'], array(20, 50, 100), true)) {
            $filters['limit'] = (int) $_GET['ct_limit'];
        }

        return $filters;
    }

    /**
     * Assembles the root layout HTML: summary strip, filter bar, table, and
     * pagination, plus the data-* attributes dd-credit-history.js reads to
     * drive AJAX paging/filtering and the export form.
     *
     * @param array $args Shape of render_shortcode()'s shortcode_atts().
     * @return string HTML markup of the entire frontend component.
     */
    private function get_layout_html($args)
    {
        if (! function_exists('mycred')) {
            return '<p class="error">myCRED core functions are not available.</p>';
        }

        $user_id = get_current_user_id();
        if (! $user_id) {
            return '<p class="auth-required">' . esc_html(dd_get_message('dd_msg_credit_log_auth_required')) . '</p>';
        }

        $ctype = sanitize_key($args['ctype']);
        $limit = absint($args['limit']);
        if (! in_array($limit, array(20, 50, 100), true)) {
            $limit = 20;
        }

        $filters = $this->get_initial_filters_from_url(array(
            'ref'       => '',
            'direction' => '',
            'date_from' => '',
            'date_to'   => '',
            'search'    => '',
            'limit'     => $limit,
            'page'      => 1,
            'ctype'     => $ctype ?: 'mycred_default',
        ));

        $log          = $this->get_log($user_id, $filters);
        $show_summary = $args['show_summary'] !== 'no';
        $show_export  = $args['show_export'] !== 'no';

        ob_start();
        ?>
        <div class="mycred-ajax-wrapper"
             data-user-id="<?php echo esc_attr($user_id); ?>"
             data-limit="<?php echo esc_attr($filters['limit']); ?>"
             data-ctype="<?php echo esc_attr($filters['ctype']); ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('mycred_log_ajax_nonce')); ?>">

            <?php if ($show_summary) : ?>
                <div class="mycred-summary-container"><?php echo $this->get_summary_html($user_id, $filters['ctype']); // phpcs:ignore -- self-built, already-escaped markup
                ?></div>
            <?php endif; ?>

            <?php echo $this->get_filter_bar_html($filters, $show_export); // phpcs:ignore -- self-built, already-escaped markup
            ?>

            <div class="mycred-table-responsive-wrapper">
                <table class="mycred-custom-log-table">
                    <caption class="screen-reader-text"><?php esc_html_e('Your credit transaction history', 'hello-elementor-child'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html(dd_get_message('dd_msg_credit_log_col_date')); ?></th>
                            <th scope="col"><?php echo esc_html(dd_get_message('dd_msg_credit_log_col_type')); ?></th>
                            <th scope="col"><?php echo esc_html(dd_get_message('dd_msg_credit_log_col_details')); ?></th>
                            <th scope="col"><?php echo esc_html(dd_get_message('dd_msg_credit_log_col_amount')); ?></th>
                            <th scope="col"><?php echo esc_html(dd_get_message('dd_msg_credit_log_col_balance')); ?></th>
                        </tr>
                    </thead>
                    <tbody aria-live="polite" aria-busy="false">
                        <?php echo $this->get_rows_html($log, $filters, $user_id); // phpcs:ignore -- self-built, already-escaped markup
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="mycred-pagination-container">
                <?php echo $this->get_pagination_html($log, $filters); // phpcs:ignore -- self-built, already-escaped markup
                ?>
            </div>
        </div>
<?php
        return ob_get_clean();
    }
}

// Instantiate the environment
new Custom_MyCred_Frontend_Log();
