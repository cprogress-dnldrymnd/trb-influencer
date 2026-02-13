<?php

/**
 * Filter: mycred_run_this
 * * Intercepts the point awarding process.
 * * UPDATE v1.1.0: Added default values to $mycred and $request to prevent 
 * "Too few arguments" errors if the hook is fired with insufficient parameters.
 *
 * @param bool         $run       Whether to run the point award (true/false).
 * @param object|null  $mycred    The myCred settings object (Optional).
 * @param array        $request   The request arguments (Optional).
 * @return bool                   Returns false to stop execution if duplicate is detected.
 */
add_filter('mycred_run_this', 'dd_prevent_duplicate_registration_points', 10, 3);

function dd_prevent_duplicate_registration_points($run, $mycred = null, $request = array())
{

    // 1. Safety Guard: Check if required arguments are present.
    // If $mycred or $request are missing (due to the argument count error), 
    // we cannot perform the check, so we return the default $run value to avoid breaking the site.
    if (! is_object($mycred) || empty($request)) {
        return $run;
    }

    // 2. Check if the current process is a 'registration' event.
    // We strictly look for the 'registration' reference.
    if (isset($request['ref']) && $request['ref'] === 'registration') {

        // 3. Validate that we have a valid User ID.
        $user_id = isset($request['user_id']) ? absint($request['user_id']) : 0;

        if ($user_id) {

            // 4. Query the log to see if this user already has a 'registration' entry.
            global $wpdb;

            // Safely retrieve the log table name from the myCred object
            if (method_exists($mycred, 'get_log_table')) {
                $log_table = $mycred->get_log_table();
            } else {
                // Fallback if method doesn't exist (rare, but possible in older versions)
                $log_table = $wpdb->prefix . 'myCRED_log';
            }

            // Query: Count how many times this user has been awarded points for 'registration'.
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$log_table} WHERE ref = %s AND user_id = %d",
                'registration',
                $user_id
            ));

            // 5. Decision Logic:
            // If the count is greater than 0, the user already has points.
            if ($count > 0) {
                return false;
            }
        }
    }

    // 6. If no duplicate is found, proceed as normal.
    return $run;
}

/**
 * Plugin Name: PMPro myCred Rewards Manager
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Assigns myCred points for PMPro registration and recurring monthly membership loyalty via a custom admin dashboard.
 * Version:     1.1.0
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-rewards
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DD_PMPro_Rewards_Manager
{

    /**
     * Option key for storing settings
     * @var string
     */
    private $option_name = 'dd_pmpro_rewards_settings';

    /**
     * Constructor: Initialize hooks and cron
     */
    public function __construct()
    {
        // Admin Menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'register_settings'));

        // Logic Hooks
        add_action('pmpro_after_checkout', array($this, 'award_registration_points'), 10, 2);
        add_action('dd_pmpro_daily_rewards_check', array($this, 'process_monthly_points'));

        // Schedule Cron on Activation
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
    }

    /**
     * Activation: Schedule Daily Cron
     */
    public function activate_plugin()
    {
        if (! wp_next_scheduled('dd_pmpro_daily_rewards_check')) {
            wp_schedule_event(time(), 'daily', 'dd_pmpro_daily_rewards_check');
        }
    }

    /**
     * Deactivation: Clear Cron
     */
    public function deactivate_plugin()
    {
        $timestamp = wp_next_scheduled('dd_pmpro_daily_rewards_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'dd_pmpro_daily_rewards_check');
        }
    }

    /**
     * Register Admin Menu under 'Settings'
     */
    public function add_admin_menu()
    {
        add_options_page(
            'PMPro Rewards',
            'PMPro Rewards',
            'manage_options',
            'dd-pmpro-rewards',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register Settings
     */
    public function register_settings()
    {
        register_setting('dd_pmpro_rewards_group', $this->option_name);
    }

    /**
     * Enqueue Admin Scripts (jQuery UI for Sortable)
     */
    public function enqueue_admin_scripts($hook)
    {
        if ('settings_page_dd-pmpro-rewards' !== $hook) {
            return;
        }
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('dd-pmpro-admin-css', false); // Inline styles used below
    }

    /**
     * Helper: Get Configured Rewards
     * Returns array of settings or empty array.
     */
    private function get_rewards_config()
    {
        $options = get_option($this->option_name);
        return ! empty($options) && is_array($options) ? $options : array();
    }

    /**
     * LOGIC: Award Registration Points
     * * @param int $user_id
     * @param object $level
     */
    public function award_registration_points($user_id, $level)
    {
        if (! function_exists('mycred_add')) return;

        $config = $this->get_rewards_config();
        $level_id = isset($level->id) ? $level->id : 0;

        // Find config for this level
        foreach ($config as $row) {
            if (isset($row['level_id']) && $row['level_id'] == $level_id) {

                // Award One-Time Points
                $reg_points = intval($row['reg_points']);
                if ($reg_points > 0) {
                    mycred_add(
                        'pmpro_registration',
                        $user_id,
                        $reg_points,
                        sprintf('Bonus for joining Membership Level %d', $level_id),
                        $level_id
                    );
                }

                // Initialize Monthly Timer (Set to NOW so first award is in 30 days)
                update_user_meta($user_id, '_dd_last_monthly_point_date', current_time('timestamp'));
                break;
            }
        }
    }

    /**
     * LOGIC: Process Monthly Recurring Points (CRON)
     */
    public function process_monthly_points()
    {
        if (! function_exists('mycred_add') || ! function_exists('pmpro_getMembershipUsers')) return;

        $config = $this->get_rewards_config();
        $now    = current_time('timestamp');
        $month_seconds = 2592000; // 30 days

        foreach ($config as $row) {
            $level_id = intval($row['level_id']);
            $monthly_points = intval($row['monthly_points']);

            if ($level_id > 0 && $monthly_points > 0) {

                $active_users = pmpro_getMembershipUsers($level_id); // Returns array of user IDs

                if (! empty($active_users)) {
                    foreach ($active_users as $user_id) {
                        $last_awarded = get_user_meta($user_id, '_dd_last_monthly_point_date', true);

                        // Legacy handling: if no meta, set to NOW and wait 30 days
                        if (empty($last_awarded)) {
                            update_user_meta($user_id, '_dd_last_monthly_point_date', $now);
                            continue;
                        }

                        if (($now - $last_awarded) >= $month_seconds) {
                            mycred_add(
                                'pmpro_monthly_recurring',
                                $user_id,
                                $monthly_points,
                                sprintf('Monthly Loyalty: Membership Level %d', $level_id),
                                $level_id
                            );
                            update_user_meta($user_id, '_dd_last_monthly_point_date', $now);
                        }
                    }
                }
            }
        }
    }

    /**
     * UI: Render Admin Page
     */
    public function render_admin_page()
    {
        $rewards = $this->get_rewards_config();

        // Get PMPro Levels for Dropdown
        $pmpro_levels = function_exists('pmpro_getAllLevels') ? pmpro_getAllLevels(true, true) : array();
?>
        <div class="wrap">
            <h1>PMPro myCred Rewards Manager</h1>

            <h2 class="nav-tab-wrapper">
                <a href="#tab-builder" class="nav-tab nav-tab-active">Reward Builder</a>
                <a href="#tab-settings" class="nav-tab">Settings & Logs</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('dd_pmpro_rewards_group'); ?>

                <div id="tab-builder" class="dd-tab-content active">
                    <p class="description">Define points for specific membership levels. Drag to reorder.</p>

                    <div id="dd-repeater-container">
                        <?php
                        if (! empty($rewards)) {
                            foreach ($rewards as $index => $row) {
                                $this->render_repeater_row($index, $row, $pmpro_levels);
                            }
                        }
                        ?>
                    </div>

                    <div class="dd-actions">
                        <button type="button" class="button button-secondary" id="dd-add-row">Add New Level Reward</button>
                    </div>

                    <div id="dd-row-template" style="display:none;">
                        <?php $this->render_repeater_row(9999, array(), $pmpro_levels, true); ?>
                    </div>
                </div>

                <div id="tab-settings" class="dd-tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th>Cron Status</th>
                            <td>
                                <?php
                                $next = wp_next_scheduled('dd_pmpro_daily_rewards_check');
                                echo $next ? 'Active. Next run: ' . get_date_from_gmt(date('Y-m-d H:i:s', $next), 'F j, Y g:i a') : 'Inactive';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Force Run</th>
                            <td>
                                <p class="description">To test monthly points immediately, use a plugin like "WP Crontrol" to run <code>dd_pmpro_daily_rewards_check</code>.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
            .dd-repeater-row {
                background: #fff;
                border: 1px solid #ccd0d4;
                margin-bottom: 10px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            }

            .dd-row-header {
                padding: 10px 15px;
                background: #f8f9fa;
                border-bottom: 1px solid #ccd0d4;
                cursor: move;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .dd-row-header h3 {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
            }

            .dd-row-body {
                padding: 15px;
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 20px;
            }

            .dd-row-actions {
                display: flex;
                gap: 10px;
            }

            .dd-remove-row {
                color: #b32d2e;
                text-decoration: none;
                font-size: 12px;
            }

            .dd-toggle-row {
                cursor: pointer;
            }

            .dd-actions {
                margin-top: 15px;
            }

            .dd-tab-content {
                margin-top: 20px;
            }

            .dd-collapsed .dd-row-body {
                display: none;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // TABS
                $('.nav-tab').click(function(e) {
                    e.preventDefault();
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.dd-tab-content').hide();
                    $($(this).attr('href')).show();
                });

                // REPEATER: SORTABLE
                $('#dd-repeater-container').sortable({
                    handle: '.dd-row-header',
                    update: function() {
                        reindex_rows();
                    }
                });

                // REPEATER: ADD ROW (FIXED)
                $('#dd-add-row').click(function() {
                    var template = $('#dd-row-template').html();
                    var $newRow = $(template); // Convert string to jQuery object

                    // CRITICAL FIX: Remove 'disabled' attribute from the cloned inputs
                    $newRow.find('select, input').prop('disabled', false);

                    $('#dd-repeater-container').append($newRow);
                    reindex_rows();
                });

                // REPEATER: REMOVE ROW
                $(document).on('click', '.dd-remove-row', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to remove this reward rule?')) {
                        $(this).closest('.dd-repeater-row').remove();
                        reindex_rows();
                    }
                });

                // REPEATER: COLLAPSE
                $(document).on('click', '.dd-toggle-row', function() {
                    $(this).closest('.dd-repeater-row').toggleClass('dd-collapsed');
                    var icon = $(this).closest('.dd-repeater-row').hasClass('dd-collapsed') ? 'dashicons-arrow-down' : 'dashicons-arrow-up';
                    $(this).find('.dashicons').attr('class', 'dashicons ' + icon);
                });

                // REPEATER: DUPLICATE
                $(document).on('click', '.dd-duplicate-row', function(e) {
                    e.preventDefault();
                    var $row = $(this).closest('.dd-repeater-row');
                    var $clone = $row.clone();

                    // Ensure cloned inputs are enabled (just in case)
                    $clone.find('select, input').prop('disabled', false);

                    $row.after($clone);
                    reindex_rows();
                });

                // REINDEX FUNCTION
                function reindex_rows() {
                    $('#dd-repeater-container .dd-repeater-row').each(function(index) {
                        // Update the Visible Number (e.g. #1, #2)
                        $(this).find('.row-index').text(index + 1);

                        // Update the name attributes for PHP saving
                        $(this).find('select, input').each(function() {
                            var name = $(this).attr('name');
                            if (name) {
                                // Replace existing index [x] with new index [index]
                                name = name.replace(/\[\d+\]/, '[' + index + ']');
                                $(this).attr('name', name);
                            }
                        });
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * Helper: Render Single Repeater Row
     */
    private function render_repeater_row($index, $data, $levels, $is_template = false)
    {
        $level_id       = isset($data['level_id']) ? $data['level_id'] : '';
        $reg_points     = isset($data['reg_points']) ? $data['reg_points'] : '';
        $monthly_points = isset($data['monthly_points']) ? $data['monthly_points'] : '';

        // If template, disable inputs to prevent form submission of template data
        $disabled = $is_template ? 'disabled' : '';
    ?>
        <div class="dd-repeater-row">
            <div class="dd-row-header dd-toggle-row">
                <h3>Membership Rule #<span class="row-index"><?php echo $index + 1; ?></span></h3>
                <div class="dd-row-actions">
                    <span class="dashicons dashicons-arrow-up"></span>
                </div>
            </div>
            <div class="dd-row-body">
                <div>
                    <label><strong>PMPro Level</strong></label><br>
                    <select name="<?php echo $this->option_name; ?>[<?php echo $index; ?>][level_id]" class="widefat" <?php echo $disabled; ?>>
                        <option value="">-- Select Level --</option>
                        <?php foreach ($levels as $level) : ?>
                            <option value="<?php echo esc_attr($level->id); ?>" <?php selected($level_id, $level->id); ?>>
                                <?php echo esc_html($level->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><strong>Registration Points</strong></label><br>
                    <input type="number" name="<?php echo $this->option_name; ?>[<?php echo $index; ?>][reg_points]" value="<?php echo esc_attr($reg_points); ?>" class="widefat" placeholder="e.g., 100" <?php echo $disabled; ?>>
                    <p class="description">One-time award on signup.</p>
                </div>
                <div>
                    <label><strong>Monthly Recurring Points</strong></label><br>
                    <input type="number" name="<?php echo $this->option_name; ?>[<?php echo $index; ?>][monthly_points]" value="<?php echo esc_attr($monthly_points); ?>" class="widefat" placeholder="e.g., 50" <?php echo $disabled; ?>>
                    <p class="description">Awarded every 30 days.</p>
                </div>
            </div>
            <div style="padding: 10px 15px; border-top: 1px solid #eee; background: #fcfcfc; text-align: right;">
                <a href="#" class="dd-duplicate-row button button-small">Duplicate</a>
                <a href="#" class="dd-remove-row button button-small button-link-delete">Delete</a>
            </div>
        </div>
<?php
    }
}

// Initialize
new DD_PMPro_Rewards_Manager();
