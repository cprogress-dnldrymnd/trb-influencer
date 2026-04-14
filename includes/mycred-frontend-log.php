<?php

/**
 * Plugin Name: Custom myCRED Frontend Log
 * Description: An object-oriented, AJAX-powered myCRED points log featuring dynamic pagination and scoped styling.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 2.0.0
 */

// Prevent direct file access for security
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Custom_MyCred_Frontend_Log
 *
 * Handles the registration, secure AJAX endpoints, styling, and rendering of the 
 * custom myCRED history shortcode using an advanced object-oriented architecture.
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
        add_action('wp_ajax_mycred_load_log_page', array($this, 'handle_ajax_request'));
        add_action('wp_ajax_nopriv_mycred_load_log_page', array($this, 'handle_ajax_request'));
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
     * Processes the incoming AJAX request, validates security nonces, 
     * and returns the JSON payload containing the updated DOM nodes.
     *
     * @return void Outputs JSON and terminates execution.
     */
    public function handle_ajax_request()
    {
        // Validate the cryptographic nonce to prevent CSRF attacks
        check_ajax_referer('mycred_log_ajax_nonce', 'security');

        // Sanitize and strictly type cast incoming POST payload
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $limit   = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
        $page    = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $ctype   = isset($_POST['ctype']) ? sanitize_key($_POST['ctype']) : 'mycred_default';

        if (! $user_id) {
            wp_send_json_error('Authentication required.');
        }

        // Generate the modular HTML components
        $rows_html  = $this->get_rows_html($user_id, $limit, $page, $ctype);
        $pagination = $this->get_pagination_html($user_id, $limit, $page, $ctype);

        // Transmit successful JSON response back to the client browser
        wp_send_json_success(array(
            'rows'       => $rows_html,
            'pagination' => $pagination,
        ));
    }

    /**
     * Callback function for the shortcode to process attributes and trigger the initial renderer.
     *
     * @param array $atts User-defined shortcode attributes.
     * @return string     The buffered HTML output of the points log wrapper and initial layout.
     */
    public function render_shortcode($atts)
    {
        // Parse shortcode attributes with safe default fallbacks; limit explicitly defaults to 20
        $args = shortcode_atts(array(
            'user_id' => get_current_user_id(),
            'limit'   => 20,
            'ctype'   => 'mycred_default',
        ), $atts, 'custom_mycred_log');

        return $this->get_layout_html($args['user_id'], $args['limit'], $args['ctype']);
    }

    /**
     * Queries the database to determine the absolute total of log entries for pagination math.
     *
     * @param int    $user_id The ID of the user.
     * @param string $ctype   The point type key.
     * @return int            Total number of rows in the log for this user.
     */
    private function get_total_entries($user_id, $ctype)
    {
        global $wpdb;
        $mycred = mycred($ctype);

        // Dynamically fetch the table name based on myCRED settings, fallback to standard
        $table = isset($mycred->log_table) ? $mycred->log_table : $wpdb->prefix . 'mycred_log';

        // Prepare and execute a direct SQL count for performance
        $query = $wpdb->prepare(
            "SELECT COUNT(id) FROM {$table} WHERE user_id = %d AND ctype = %s",
            $user_id,
            $ctype
        );

        return (int) $wpdb->get_var($query);
    }

    /**
     * Generates the dynamic HTML for the pagination controls.
     *
     * @param int    $user_id The ID of the user.
     * @param int    $limit   The maximum items per page.
     * @param int    $page    The current page number.
     * @param string $ctype   The point type key.
     * @return string         HTML markup for the pagination footer.
     */
    private function get_pagination_html($user_id, $limit, $page, $ctype)
    {
        $total_entries = $this->get_total_entries($user_id, $ctype);
        $total_pages   = ceil($total_entries / $limit);

        if ($total_pages <= 1) {
            return '';
        }

        ob_start();
?>
        <div class="mycred-pagination-controls">
            <button class="mycred-btn-paginate prev" data-target-page="<?php echo esc_attr($page - 1); ?>" <?php disabled($page <= 1); ?>>
                &laquo; Previous
            </button>
            <span class="mycred-page-indicator">
                Page <?php echo esc_html($page); ?> of <?php echo esc_html($total_pages); ?>
            </span>
            <button class="mycred-btn-paginate next" data-target-page="<?php echo esc_attr($page + 1); ?>" <?php disabled($page >= $total_pages); ?>>
                Next &raquo;
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Generates the tabular data rows for a specific page of results.
     *
     * @param int    $user_id The ID of the user.
     * @param int    $limit   The maximum items per page.
     * @param int    $page    The specific offset page to query.
     * @param string $ctype   The point type key.
     * @return string         HTML markup containing the `<tr>` elements.
     */
    private function get_rows_html($user_id, $limit, $page, $ctype)
    {
        $mycred = mycred($ctype);

        $query_args = array(
            'user_id' => $user_id,
            'number'  => $limit,
            'paged'   => $page,
            'ctype'   => $ctype
        );

        $log = new myCRED_Query_Log($query_args);

        if (! $log->have_entries()) {
            return '<tr><td colspan="3" class="mycred-empty-log">No points history found for this account.</td></tr>';
        }

        ob_start();
        foreach ($log->results as $entry) :
        ?>
            <tr>
                <td>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry->time)); ?>
                </td>
                <td>
                    <?php echo wp_kses_post($mycred->parse_template_tags($entry->entry, $entry)); ?>
                </td>
                <td class="<?php echo ($entry->creds > 0) ? 'positive-points' : 'negative-points'; ?>">
                    <?php
                    $prefix = ($entry->creds > 0) ? '+' : '';
                    echo esc_html($prefix . $mycred->format_creds($entry->creds));
                    ?>
                </td>
            </tr>
        <?php
        endforeach;
        return ob_get_clean();
    }

    /**
     * Outputs scoped CSS specifically for the myCRED custom table and pagination.
     *
     * @return void
     */
    private function render_table_styles()
    {
        ?>
        <style>
            .mycred-ajax-wrapper {
                position: relative;
            }

            .mycred-table-responsive-wrapper {
                overflow-x: auto;
                margin: 20px 0;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                border-radius: 8px;
            }

            .mycred-custom-log-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                background-color: #ffffff;
            }

            .mycred-custom-log-table th,
            .mycred-custom-log-table td {
                padding: 16px 20px;
                border-bottom: 1px solid #e2e8f0;
            }

            .mycred-custom-log-table th {
                background-color: #f8fafc;
                font-weight: 600;
                color: #334155;
                text-transform: uppercase;
                font-size: 0.85rem;
                letter-spacing: 0.05em;
            }

            .mycred-custom-log-table tbody tr:hover {
                background-color: #f1f5f9;
            }

            .positive-points {
                color: #15803d;
                font-weight: 600;
            }

            .negative-points {
                color: #b91c1c;
                font-weight: 600;
            }

            .mycred-empty-log {
                text-align: center;
                color: #64748b;
                padding: 20px !important;
            }

            /* Pagination & States */
            .mycred-pagination-controls {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .mycred-btn-paginate.mycred-btn-paginate.mycred-btn-paginate {
                background: #f1f5f9;
                border: 1px solid #cbd5e1;
                color: #334155;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 500;
                transition: background 0.2s ease;
            }

            .mycred-btn-paginate.mycred-btn-paginate.mycred-btn-paginate:hover:not(:disabled) {
                background: #e2e8f0;
            }

            .mycred-btn-paginate.mycred-btn-paginate.mycred-btn-paginate:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .mycred-page-indicator {
                color: #64748b;
                font-size: 0.9rem;
            }

            /* Loading Overlay */
            .mycred-ajax-wrapper.is-loading::after {
                content: "";
                position: absolute;
                inset: 0;
                background: rgba(255, 255, 255, 0.6);
                backdrop-filter: blur(2px);
                z-index: 10;
                border-radius: 8px;
            }
        </style>
    <?php
    }

    /**
     * Outputs the vanilla JavaScript module required to process the AJAX fetch requests.
     *
     * @return void
     */
    private function render_ajax_script()
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce    = wp_create_nonce('mycred_log_ajax_nonce');
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const wrappers = document.querySelectorAll('.mycred-ajax-wrapper');

                wrappers.forEach(wrapper => {
                    wrapper.addEventListener('click', function(e) {
                        if (e.target.classList.contains('mycred-btn-paginate')) {
                            e.preventDefault();
                            const btn = e.target;

                            // Prevent execution if button is naturally disabled
                            if (btn.hasAttribute('disabled')) return;

                            const targetPage = btn.getAttribute('data-target-page');
                            const userId = wrapper.getAttribute('data-user-id');
                            const limit = wrapper.getAttribute('data-limit');
                            const ctype = wrapper.getAttribute('data-ctype');

                            // Trigger visual loading state
                            wrapper.classList.add('is-loading');

                            // Construct payload URLSearchParams for native fetch API
                            const payload = new URLSearchParams();
                            payload.append('action', 'mycred_load_log_page');
                            payload.append('security', '<?php echo esc_js($nonce); ?>');
                            payload.append('page', targetPage);
                            payload.append('user_id', userId);
                            payload.append('limit', limit);
                            payload.append('ctype', ctype);

                            // Execute asynchronous POST request
                            fetch('<?php echo esc_url($ajax_url); ?>', {
                                    method: 'POST',
                                    body: payload,
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded'
                                    }
                                })
                                .then(response => response.json())
                                .then(res => {
                                    if (res.success) {
                                        // Mutate DOM with fresh data
                                        wrapper.querySelector('tbody').innerHTML = res.data.rows;
                                        wrapper.querySelector('.mycred-pagination-container').innerHTML = res.data.pagination;
                                    } else {
                                        console.error('myCRED AJAX Error:', res.data);
                                    }
                                })
                                .catch(error => console.error('Fetch Error:', error))
                                .finally(() => {
                                    // Terminate loading state
                                    wrapper.classList.remove('is-loading');
                                });
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Assembles the root layout HTML, including styles, data attributes for JS, and the initial table rows.
     *
     * @param int    $user_id The ID of the user.
     * @param int    $limit   The limit of rows per page.
     * @param string $ctype   The point type key.
     * @return string         HTML markup of the entire frontend component.
     */
    private function get_layout_html($user_id, $limit, $ctype)
    {

        if (! function_exists('mycred')) {
            return '<p class="error">myCRED core functions are not available.</p>';
        }

        $user_id = absint($user_id);

        if (! $user_id) {
            return '<p class="auth-required">Authentication required to view points history.</p>';
        }

        $ctype = sanitize_key($ctype);
        $limit = absint($limit);

        ob_start();

        $this->render_table_styles();
    ?>

        <div class="mycred-ajax-wrapper" data-user-id="<?php echo esc_attr($user_id); ?>" data-limit="<?php echo esc_attr($limit); ?>" data-ctype="<?php echo esc_attr($ctype); ?>">
            <div class="mycred-table-responsive-wrapper">
                <table class="mycred-custom-log-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transaction Details</th>
                            <th>Points Impact</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $this->get_rows_html($user_id, $limit, 1, $ctype); // Load Page 1 initially 
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="mycred-pagination-container">
                <?php echo $this->get_pagination_html($user_id, $limit, 1, $ctype); // Load Pagination for Page 1 
                ?>
            </div>
        </div>

<?php
        $this->render_ajax_script();

        return ob_get_clean();
    }
}

// Instantiate the environment
new Custom_MyCred_Frontend_Log();
