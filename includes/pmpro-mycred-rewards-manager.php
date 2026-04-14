<?php

/**
 * Plugin Name: PMPro myCred Rewards Manager
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Assigns myCred points for PMPro registration and recurring monthly membership loyalty via a custom admin dashboard. Implements a strict "Top-Up" allowance architecture to prevent infinite point stacking while protecting purchased points.
 * Version:     1.4.0
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-rewards
 */

if (! defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Class DD_PMPro_Rewards_Manager
 * Handles the registration of the admin interface, processing of myCred points, and CRON scheduling.
 */
class DD_PMPro_Rewards_Manager
{
    /**
     * @var string The option key for storing rewards configuration.
     */
    private $option_name = 'dd_pmpro_rewards_settings';

    /**
     * @var string The target myCred Point Type key.
     */
    private $point_type  = 'mycred_default';

    /**
     * Constructor.
     * Initializes WP hooks, admin menus, background CRON jobs, and transaction tracking.
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
        add_action('init', array($this, 'ensure_cron_is_scheduled'));
        add_action('switch_theme', array($this, 'unschedule_cron'));
    }

    /**
     * Ensures the daily CRON event is scheduled within a theme context.
     * Hooked to 'init' to verify the schedule exists on page load.
     * @return void
     */
    public function ensure_cron_is_scheduled()
    {
        if (! wp_next_scheduled('dd_pmpro_daily_rewards_check')) {
            wp_schedule_event(time(), 'daily', 'dd_pmpro_daily_rewards_check');
        }
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
     * Registers the plugin settings array with the WP Options API.
     * @return void
     */
    public function register_settings()
    {
        register_setting('dd_pmpro_rewards_group', $this->option_name);
    }

    /**
     * Enqueues necessary admin scripts and styles for the repeater UI.
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_admin_scripts($hook)
    {
        if ('settings_page_dd-pmpro-rewards' !== $hook) return;
        wp_enqueue_script('jquery-ui-sortable');
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
     * the exact difference required to reach their monthly cap. Prevents infinite 
     * accumulation while completely ignoring permanent bought points.
     * @return void
     */
    public function process_monthly_points()
    {
        if (! function_exists('mycred_add') || ! function_exists('pmpro_getMembershipUsers')) return;

        $config = $this->get_rewards_config();
        $now    = current_time('timestamp');
        $month_seconds = 2592000; // 30 days mathematical equivalent

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

                        if (($now - $last_awarded) >= $month_seconds) {

                            $current_allowance = get_user_meta($user_id, '_dd_current_allowance_balance', true);
                            $current_allowance = ! empty($current_allowance) ? floatval($current_allowance) : 0;

                            // Calculate the required top-up to reach the monthly allowance cap
                            $points_to_add = max(0, $monthly_points - $current_allowance);

                            if ($points_to_add > 0) {
                                error_log("PMPro Rewards: Topping up {$points_to_add} ({$this->point_type}) to User ID {$user_id} to reach allowance cap of {$monthly_points}.");

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

                            // Always reset the allowance tracker to the full monthly limit for the new cycle
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
     * @return void
     */
    public function render_admin_page()
    {
        $rewards = $this->get_rewards_config();
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
                    <table class="form-table">
                        <tr>
                            <th>Cron Status</th>
                            <td><?php echo wp_next_scheduled('dd_pmpro_daily_rewards_check') ? 'Active' : 'Inactive'; ?></td>
                        </tr>
                        <tr>
                            <th>Target Point Type</th>
                            <td><code><?php echo esc_html($this->point_type); ?></code></td>
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
                $('.nav-tab').click(function(e) {
                    e.preventDefault();
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.dd-tab-content').hide();
                    $($(this).attr('href')).show();
                });

                $('#dd-repeater-container').sortable({
                    handle: '.dd-row-header',
                    update: function() {
                        reindex_rows();
                    }
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