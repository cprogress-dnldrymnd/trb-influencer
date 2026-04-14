<?php
/**
 * Plugin Name: PMPro myCred Rewards Manager
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Assigns myCred points for PMPro registration and recurring monthly membership loyalty via a custom admin dashboard. Implements a strict "Top-Up" allowance architecture to prevent infinite point stacking while protecting purchased points. Includes a targeted Test Mode with AJAX user search.
 * Version:     1.6.0
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-rewards
 */

if (! defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Class DD_PMPro_Rewards_Manager
 * Handles the registration of the admin interface, processing of myCred points, transaction tracking, dynamic CRON scheduling, and AJAX endpoints.
 */
class DD_PMPro_Rewards_Manager
{
    /**
     * @var string The option key for storing rewards configuration.
     */
    private $option_name = 'dd_pmpro_rewards_settings';

    /**
     * @var string The option key for general settings (including test mode).
     */
    private $general_option = 'dd_pmpro_rewards_general';

    /**
     * @var string The target myCred Point Type key.
     */
    private $point_type  = 'mycred_default';

    /**
     * Constructor.
     * Initializes WP hooks, admin menus, background CRON jobs, transaction tracking, and AJAX routes.
     */
    public function __construct()
    {
        // Admin setup
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'register_settings'));

        // Point allocation logic
        add_action('pmpro_after_checkout', array($this, 'award_registration_points'), 10, 2);
        add_action('dd_pmpro_daily_rewards_check', array($this, 'process_monthly_points'));

        // Point consumption tracking (Prioritizes spending allowance before permanent points)
        add_action('mycred_update_user_balance', array($this, 'track_allowance_spending'), 10, 4);

        // Theme-compatible CRON scheduling hooks
        add_filter('cron_schedules', array($this, 'register_custom_cron_intervals'));
        add_action('init', array($this, 'ensure_cron_is_scheduled'));
        add_action('switch_theme', array($this, 'unschedule_cron'));
        
        // Reschedule immediately when options are saved to apply test mode instantly
        add_action('update_option_' . $this->general_option, array($this, 'handle_settings_update'), 10, 2);

        // AJAX Handler for User Search
        add_action('wp_ajax_dd_search_pmpro_users', array($this, 'ajax_search_users'));
    }

    /**
     * Registers custom cron intervals for Test Mode.
     * @param array $schedules Existing WP cron schedules.
     * @return array Modified WP cron schedules.
     */
    public function register_custom_cron_intervals($schedules)
    {
        $schedules['dd_one_minute'] = array(
            'interval' => 60,
            'display'  => esc_html__('Every Minute (DD Testing)', 'dd-pmpro-rewards'),
        );
        return $schedules;
    }

    /**
     * Ensures the CRON event is dynamically scheduled based on Test Mode status.
     * Transitions between 'daily' and 'dd_one_minute' schedules safely.
     * @return void
     */
    public function ensure_cron_is_scheduled()
    {
        $general_settings = get_option($this->general_option, array());
        $is_test_mode     = !empty($general_settings['test_mode']) ? true : false;
        $target_schedule  = $is_test_mode ? 'dd_one_minute' : 'daily';

        $current_schedule = wp_get_schedule('dd_pmpro_daily_rewards_check');

        // If the schedule doesn't match the required state, clear and recreate it
        if ($current_schedule !== $target_schedule) {
            wp_clear_scheduled_hook('dd_pmpro_daily_rewards_check');
            wp_schedule_event(time(), $target_schedule, 'dd_pmpro_daily_rewards_check');
        }
    }

    /**
     * Hook listener for when settings are saved.
     * Forces a re-evaluation of the CRON schedule so test mode activates immediately.
     * @param mixed $old_value The old option value.
     * @param mixed $value The new option value.
     * @return void
     */
    public function handle_settings_update($old_value, $value)
    {
        $this->ensure_cron_is_scheduled();
    }

    /**
     * Unschedules the daily CRON event cleanly to prevent orphan tasks.
     * Hooked to 'switch_theme' to remove the event if the active theme changes.
     * @return void
     */
    public function unschedule_cron()
    {
        $timestamp = wp_next_scheduled('dd_pmpro_daily_rewards_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'dd_pmpro_daily_rewards_check');
        }
    }

    /**
     * Registers the backend options page.
     * @return void
     */
    public function add_admin_menu()
    {
        add_options_page('PMPro Rewards', 'PMPro Rewards', 'manage_options', 'dd-pmpro-rewards', array($this, 'render_admin_page'));
    }

    /**
     * Registers the plugin settings arrays with the WP Options API.
     * @return void
     */
    public function register_settings()
    {
        register_setting('dd_pmpro_rewards_group', $this->option_name);
        register_setting('dd_pmpro_rewards_group', $this->general_option);
    }

    /**
     * Enqueues necessary admin scripts, styles, and localizes AJAX parameters for the UI.
     * Loads jQuery UI Autocomplete for the user search functionality.
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_admin_scripts($hook)
    {
        if ('settings_page_dd-pmpro-rewards' !== $hook) return;
        
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('jquery-ui-autocomplete');
        
        // Pass AJAX URL and Security Nonce to JS
        wp_localize_script('jquery-ui-autocomplete', 'dd_pmpro_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dd_user_search_nonce')
        ));

        wp_enqueue_style('dd-pmpro-admin-css', false);
    }

    /**
     * Retrieves the saved rewards configuration array from the database.
     * @return array The configuration array.
     */
    private function get_rewards_config()
    {
        $options = get_option($this->option_name);
        return ! empty($options) && is_array($options) ? $options : array();
    }

    /**
     * Handles the AJAX request to search WordPress users by login, email, or display name.
     * Returns a JSON payload compatible with jQuery UI Autocomplete.
     * @return void
     */
    public function ajax_search_users()
    {
        check_ajax_referer('dd_user_search_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
        
        $users_query = new WP_User_Query(array(
            'search'         => '*' . esc_attr($term) . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number'         => 15,
            'fields'         => array('ID', 'display_name', 'user_email')
        ));

        $results = array();
        foreach ($users_query->get_results() as $user) {
            $results[] = array(
                'id'    => $user->ID,
                'label' => esc_html($user->display_name . ' (' . $user->user_email . ')'),
                'value' => esc_html($user->display_name . ' (' . $user->user_email . ')')
            );
        }

        wp_send_json($results);
    }

    /**
     * LOGIC: Track Allowance Spending
     * Intercepts myCred balance updates. If points are deducted (spent), it subtracts them 
     * from the user's active monthly allowance tracker first. This ensures expiring 
     * allowance points are consumed before permanent 'buycred' points.
     *
     * @param int    $user_id         The ID of the user.
     * @param mixed  $current_balance The balance before the update.
     * @param float  $amount          The amount being added or subtracted.
     * @param string $type            The point type key.
     * @return void
     */
    public function track_allowance_spending($user_id, $current_balance, $amount, $type)
    {
        if ($type !== $this->point_type) return;

        // We only care about point deductions (spending)
        if ($amount < 0) {
            $allowance_balance = get_user_meta($user_id, '_dd_current_allowance_balance', true);
            $allowance_balance = ! empty($allowance_balance) ? floatval($allowance_balance) : 0;

            if ($allowance_balance > 0) {
                // Deduct from the allowance tracker first, clamping at 0
                $new_allowance = max(0, $allowance_balance - abs($amount));
                update_user_meta($user_id, '_dd_current_allowance_balance', $new_allowance);
            }
        }
    }

    /**
     * LOGIC: Award Registration Points & Anti-Farming Protocol
     * Triggers post-checkout to allocate initial points. Utilizes a strict user meta flag 
     * to prevent duplicate points if a user hops between plans. It also resets the monthly 
     * timer to prevent monthly double-dipping during the switch.
     *
     * @param int    $user_id The ID of the user checking out.
     * @param object $morder  The membership order object.
     * @return void
     */
    public function award_registration_points($user_id, $morder)
    {
        if (! function_exists('mycred_add')) return;

        // Reset the monthly timer immediately on any plan switch/checkout to prevent monthly double-dipping
        update_user_meta($user_id, '_dd_last_monthly_point_date', current_time('timestamp'));

        // Anti-Farming Protocol: Check if this user has EVER received registration points
        $already_awarded = get_user_meta($user_id, '_dd_registration_points_awarded', true);
        if ($already_awarded) {
            error_log("PMPro Rewards: Blocked duplicate registration points for User ID {$user_id} (Anti-Farming active).");
            return;
        }

        // 1. Get Level ID and initialize Level Name
        $level_id = 0;
        $level_name = '';

        if (! empty($morder) && isset($morder->membership_id)) {
            $level_id = intval($morder->membership_id);
        }

        // Fallback if order object is missing
        if (! $level_id && function_exists('pmpro_getMembershipLevelForUser')) {
            $level_obj = pmpro_getMembershipLevelForUser($user_id);
            if ($level_obj) {
                $level_id = intval($level_obj->id);
                $level_name = $level_obj->name;
            }
        }

        if (! $level_id) return;

        // 2. Fetch Level Name if not already captured
        if (empty($level_name) && function_exists('pmpro_getLevel')) {
            $level = pmpro_getLevel($level_id);
            if ($level) {
                $level_name = $level->name;
            }
        }

        if (empty($level_name)) {
            $level_name = 'Membership Level ' . $level_id;
        }

        $config = $this->get_rewards_config();

        foreach ($config as $row) {
            if (isset($row['level_id']) && intval($row['level_id']) == $level_id) {

                $reg_points = intval($row['reg_points']);

                if ($reg_points > 0) {
                    error_log("PMPro Rewards: Awarding {$reg_points} ({$this->point_type}) to User ID {$user_id} for joining {$level_name}");

                    mycred_add(
                        'pmpro_registration',
                        $user_id,
                        $reg_points,
                        sprintf('Credits gained by joining %s Membership', $level_name),
                        $level_id,
                        '',
                        $this->point_type
                    );

                    // Lock the account from receiving future registration points across any plan
                    update_user_meta($user_id, '_dd_registration_points_awarded', 1);
                }
                break;
            }
        }
    }

    /**
     * LOGIC: Process Monthly Recurring Points (Allowance Top-Up)
     * Executed via WP-Cron. Evaluates the user's unspent allowance and ONLY tops up 
     * the exact difference required to reach their monthly cap. Integrates dynamic Test Mode 
     * threshold bypassing for isolated user debugging.
     * @return void
     */
    public function process_monthly_points()
    {
        if (! function_exists('mycred_add') || ! function_exists('pmpro_getMembershipUsers')) return;

        $config           = $this->get_rewards_config();
        $general_settings = get_option($this->general_option, array());
        $is_test_mode     = !empty($general_settings['test_mode']) ? true : false;
        $test_user_id     = !empty($general_settings['test_user_id']) ? intval($general_settings['test_user_id']) : 0;
        
        $now              = current_time('timestamp');
        $base_seconds     = 2592000; // 30 days mathematical equivalent

        foreach ($config as $row) {
            $level_id = intval($row['level_id']);
            $monthly_points = intval($row['monthly_points']);

            if ($level_id > 0 && $monthly_points > 0) {

                $active_users = pmpro_getMembershipUsers($level_id);

                if (! empty($active_users)) {
                    foreach ($active_users as $user_id) {
                        $last_awarded = get_user_meta($user_id, '_dd_last_monthly_point_date', true);

                        // If user has no history, set their timer and grant them their first full allowance
                        if (empty($last_awarded)) {
                            update_user_meta($user_id, '_dd_last_monthly_point_date', $now);
                            continue;
                        }

                        // Determine strict threshold: Default to 30 days, unless Test Mode is active FOR THIS USER (60 seconds)
                        $required_threshold = $base_seconds;
                        if ($is_test_mode && $user_id === $test_user_id) {
                            $required_threshold = 60;
                        }

                        if (($now - $last_awarded) >= $required_threshold) {
                            
                            $current_allowance = get_user_meta($user_id, '_dd_current_allowance_balance', true);
                            $current_allowance = ! empty($current_allowance) ? floatval($current_allowance) : 0;

                            // Calculate the required top-up to reach the monthly allowance cap
                            $points_to_add = max(0, $monthly_points - $current_allowance);

                            if ($points_to_add > 0) {
                                $log_context = ($is_test_mode && $user_id === $test_user_id) ? '[TEST MODE]' : '';
                                error_log("PMPro Rewards {$log_context}: Topping up {$points_to_add} ({$this->point_type}) to User ID {$user_id} to reach allowance cap of {$monthly_points}.");

                                mycred_add(
                                    'pmpro_monthly_recurring',
                                    $user_id,
                                    $points_to_add,
                                    sprintf('Monthly Allowance Top-up: Membership Level %d', $level_id),
                                    $level_id,
                                    '',
                                    $this->point_type
                                );
                            } else {
                                error_log("PMPro Rewards: User ID {$user_id} is already at or above their allowance cap. Skipping top-up.");
                            }

                            // Always reset the allowance tracker to the full limit for the new cycle
                            update_user_meta($user_id, '_dd_current_allowance_balance', $monthly_points);
                            update_user_meta($user_id, '_dd_last_monthly_point_date', $now);
                        }
                    }
                }
            }
        }
    }

    /**
     * UI: Render Admin Page
     * Compiles the HTML for the backend settings page utilizing a tabbed interface.
     * Includes dedicated fields for enabling Test Mode and AJAX User searching.
     * @return void
     */
    public function render_admin_page()
    {
        $rewards          = $this->get_rewards_config();
        $general_settings = get_option($this->general_option, array());
        $pmpro_levels     = function_exists('pmpro_getAllLevels') ? pmpro_getAllLevels(true, true) : array();
        
        $is_test_mode = !empty($general_settings['test_mode']) ? 1 : 0;
        $test_user_id = !empty($general_settings['test_user_id']) ? intval($general_settings['test_user_id']) : '';
        $current_cron = wp_get_schedule('dd_pmpro_daily_rewards_check');

        // Pre-fetch the display name of the currently saved test user for the UI
        $test_user_display = '';
        if ($test_user_id) {
            $u_data = get_userdata($test_user_id);
            if ($u_data) {
                $test_user_display = esc_attr($u_data->display_name . ' (' . $u_data->user_email . ')');
            }
        }
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
                    <p class="description">Define points for specific membership levels. Points are awarded to: <strong><?php echo esc_html($this->point_type); ?></strong></p>

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
                    
                    <h3>Environment Configuration</h3>
                    <table class="form-table">
                        <tr>
                            <th>Active Cron Interval</th>
                            <td>
                                <code><?php echo $current_cron ? esc_html($current_cron) : 'Not Scheduled'; ?></code>
                                <?php if ($current_cron === 'dd_one_minute') echo '<span style="color: #b32d2e; font-weight: bold;">(Test Mode Active)</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Target Point Type</th>
                            <td><code><?php echo esc_html($this->point_type); ?></code></td>
                        </tr>
                    </table>

                    <hr style="margin: 30px 0;">

                    <h3>Sandbox / Test Mode</h3>
                    <p class="description">Activate test mode to accelerate the CRON task to run every minute and bypass the 30-day requirement (reducing it to 60 seconds) for a specific user. This allows you to test point spending and allowance top-ups rapidly without affecting live users.</p>
                    <table class="form-table">
                        <tr>
                            <th>Enable Test Mode</th>
                            <td>
                                <input type="checkbox" name="<?php echo $this->general_option; ?>[test_mode]" value="1" <?php checked($is_test_mode, 1); ?>>
                            </td>
                        </tr>
                        <tr>
                            <th>Target Test User</th>
                            <td>
                                <input type="text" id="dd_user_search" class="regular-text" placeholder="Search by name, login, or email..." value="<?php echo $test_user_display; ?>">
                                <input type="hidden" name="<?php echo $this->general_option; ?>[test_user_id]" id="dd_test_user_id" value="<?php echo esc_attr($test_user_id); ?>">
                                <p class="description">Start typing to search. The actual User ID will be saved securely in the background.</p>
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
            .dd-row-actions { display: flex; gap: 10px; }
            .dd-remove-row { color: #b32d2e; text-decoration: none; font-size: 12px; }
            .dd-toggle-row { cursor: pointer; }
            .dd-actions { margin-top: 15px; }
            .dd-tab-content { margin-top: 20px; }
            .dd-collapsed .dd-row-body { display: none; }
            
            /* Autocomplete Styling Fixes for WP Admin */
            ul.ui-autocomplete {
                background: #fff;
                border: 1px solid #8c8f94;
                box-shadow: 0 3px 6px rgba(0,0,0,0.1);
                max-width: 400px;
                max-height: 250px;
                overflow-y: auto;
                z-index: 99999 !important;
            }
            ul.ui-autocomplete .ui-menu-item {
                padding: 8px 12px;
                cursor: pointer;
                font-size: 13px;
                border-bottom: 1px solid #f0f0f1;
            }
            ul.ui-autocomplete .ui-menu-item:hover,
            ul.ui-autocomplete .ui-state-active {
                background: #f0f6fc;
                color: #2271b1;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Tab Navigation
                $('.nav-tab').click(function(e) {
                    e.preventDefault();
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.dd-tab-content').hide();
                    $($(this).attr('href')).show();
                });

                // Repeater Functionality
                $('#dd-repeater-container').sortable({
                    handle: '.dd-row-header',
                    update: function() { reindex_rows(); }
                });

                $('#dd-add-row').click(function() {
                    var template = $('#dd-row-template').html();
                    var $newRow = $(template);
                    $newRow.find('select, input').removeAttr('disabled').prop('disabled', false);
                    $('#dd-repeater-container').append($newRow);
                    reindex_rows();
                });

                $(document).on('click', '.dd-remove-row', function(e) {
                    e.preventDefault();
                    if (confirm('Remove this rule?')) {
                        $(this).closest('.dd-repeater-row').remove();
                        reindex_rows();
                    }
                });

                $(document).on('click', '.dd-toggle-row', function() {
                    $(this).closest('.dd-repeater-row').toggleClass('dd-collapsed');
                    var icon = $(this).closest('.dd-repeater-row').hasClass('dd-collapsed') ? 'dashicons-arrow-down' : 'dashicons-arrow-up';
                    $(this).find('.dashicons').attr('class', 'dashicons ' + icon);
                });

                $(document).on('click', '.dd-duplicate-row', function(e) {
                    e.preventDefault();
                    var $row = $(this).closest('.dd-repeater-row');
                    var $clone = $row.clone();
                    $clone.find('select, input').removeAttr('disabled').prop('disabled', false);
                    $row.after($clone);
                    reindex_rows();
                });

                function reindex_rows() {
                    $('#dd-repeater-container .dd-repeater-row').each(function(index) {
                        $(this).find('.row-index').text(index + 1);
                        $(this).find('select, input').each(function() {
                            var name = $(this).attr('name');
                            if (name) {
                                name = name.replace(/\[\d+\]/, '[' + index + ']');
                                $(this).attr('name', name);
                            }
                        });
                    });
                }

                // User Search Autocomplete Logic
                if (typeof dd_pmpro_ajax !== 'undefined') {
                    $('#dd_user_search').autocomplete({
                        source: function(request, response) {
                            $.ajax({
                                url: dd_pmpro_ajax.ajax_url,
                                dataType: 'json',
                                data: {
                                    action: 'dd_search_pmpro_users',
                                    security: dd_pmpro_ajax.nonce,
                                    term: request.term
                                },
                                success: function(data) {
                                    response(data);
                                }
                            });
                        },
                        minLength: 3,
                        select: function(event, ui) {
                            // When user is selected, display name in text field, put ID in hidden field
                            $('#dd_user_search').val(ui.item.label);
                            $('#dd_test_user_id').val(ui.item.id);
                            return false; // Prevent default jQuery UI value setting
                        }
                    }).on('input', function() {
                        // If they clear the field, wipe the hidden ID to prevent accidental saves
                        if ($(this).val() === '') {
                            $('#dd_test_user_id').val('');
                        }
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * UI Helper: Renders an individual repeater row structure.
     * @param int $index Current row iteration index.
     * @param array $data Stored configuration data for the row.
     * @param array $levels Available PMPro Levels array.
     * @param bool $is_template Flag determining if it's the hidden initialization template.
     * @return void
     */
    private function render_repeater_row($index, $data, $levels, $is_template = false)
    {
        $level_id       = isset($data['level_id']) ? $data['level_id'] : '';
        $reg_points     = isset($data['reg_points']) ? $data['reg_points'] : '';
        $monthly_points = isset($data['monthly_points']) ? $data['monthly_points'] : '';
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
                </div>
                <div>
                    <label><strong>Monthly Recurring Cap</strong></label><br>
                    <input type="number" name="<?php echo $this->option_name; ?>[<?php echo $index; ?>][monthly_points]" value="<?php echo esc_attr($monthly_points); ?>" class="widefat" placeholder="e.g., 50" <?php echo $disabled; ?>>
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

new DD_PMPro_Rewards_Manager();