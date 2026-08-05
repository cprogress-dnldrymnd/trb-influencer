<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Plugin Name: PMPro Dynamic Pricing Toggle Shortcode
 * Description: Owns the membership-state layer behind the dd_pricing_table shortcode — which plan a
 * visitor holds, whether that makes another plan an upgrade or a downgrade, and the trial /
 * pending-downgrade lockdowns that disable checkout. The table's markup itself is rendered by
 * DD_Feature_Comparison_Table::render_table() in "pricing mode" (both tables share one grid so their
 * CSS/JS can't drift); this class supplies the per-plan button state it stamps into each column.
 * Also detects each level's "Annual" Payment Plan extension, adds dynamic trial notices via the
 * Subscription Delays Add On, enforces the same lockdowns at the URL level, and rewrites the native
 * PMPro checkout DOM into the influencer look.
 * Version: 1.1.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-pricing
 */

/**
 * Class DD_PMPro_Frontend_Pricing
 * Handles dynamic Payment Plan extraction, per-plan membership/button state, the dd_pricing_table
 * shortcode, and resilient checkout page DOM manipulation.
 */
class DD_PMPro_Frontend_Pricing
{

	/**
	 * The single live instance, so DD_Feature_Comparison_Table can resolve per-plan button state
	 * while rendering the pricing table without constructing a second object.
	 * @var DD_PMPro_Frontend_Pricing|null
	 */
	private static $instance;

	/**
	 * Per-request memo for get_membership_context(). The context is identical for every column of
	 * every table on the page, and resolving it costs several PMPro queries plus a user-meta scan.
	 * @var array|null
	 */
	private $membership_context;

	/**
	 * Constructor.
	 * Initializes the shortcode registration, frontend footer scripts, and page redirects during the WordPress lifecycle.
	 */
	public function __construct()
	{
		self::$instance = $this;

		add_action('init', [$this, 'register_pricing_shortcode']);
		add_action('wp_footer', [$this, 'modify_checkout_plans_dom']);
		add_action('template_redirect', [$this, 'prevent_checkout_during_trial']); // URL security layer
		add_action('template_redirect', [$this, 'prevent_checkout_for_pending_downgrade']); // Block checkout on pending-downgrade target level
		add_action('wp_footer', [$this, 'influencer_style_pmpro_checkout'], 50); // Influencer UI Override
		// Recompute the "Starting" date when a discount code is applied via AJAX (post page-load)
		add_action('wp_ajax_dd_get_trial_start_date', [$this, 'ajax_get_trial_start_date']);
		add_action('wp_ajax_nopriv_dd_get_trial_start_date', [$this, 'ajax_get_trial_start_date']);
	}

	/**
	 * @return DD_PMPro_Frontend_Pricing|null
	 */
	public static function instance()
	{
		return self::$instance;
	}

	/**
	 * Calculates the date full-price recurring billing begins for a given PMPro level object.
	 *
	 * A Custom Trial (applied natively or via a discount code) defers the first real charge by
	 * `trial_limit` billing cycles, so the start date is now + (trial_limit * cycle). Falls back to
	 * the Subscription Delays Add On's `profile_start_date`, then "Now".
	 *
	 * @param object $level A PMPro level object (ideally post-discount, e.g. from pmpro_getLevelAtCheckout).
	 * @return string Localized date string, or "Now".
	 */
	private function calculate_billing_start_date($level)
	{
		if (empty($level)) {
			return 'Now';
		}

		$trial_limit  = isset($level->trial_limit) ? (int) $level->trial_limit : 0;
		$cycle_number = isset($level->cycle_number) ? (int) $level->cycle_number : 0;
		$cycle_period = isset($level->cycle_period) ? trim($level->cycle_period) : '';

		if ($trial_limit > 0 && $cycle_number > 0 && !empty($cycle_period)) {
			// First full-price charge lands after all trial cycles complete (e.g. 2 × 1 Month).
			$total_cycles = $trial_limit * $cycle_number;
			$start_ts = strtotime('+' . $total_cycles . ' ' . $cycle_period, current_time('timestamp'));
			if ($start_ts) {
				return date_i18n(get_option('date_format'), $start_ts);
			}
		} elseif (!empty($level->profile_start_date)) {
			return date_i18n(get_option('date_format'), strtotime($level->profile_start_date));
		}

		return 'Now';
	}

	/**
	 * AJAX: returns the recurring-billing start date for a level with an (optional) discount code
	 * applied. Discount codes are applied client-side via AJAX after page render, so the server's
	 * initial timeline can't see the resulting trial — this lets the front-end re-fetch it using
	 * PMPro's own discount-application logic.
	 * @return void
	 */
	public function ajax_get_trial_start_date()
	{
		$level_id      = isset($_REQUEST['level']) ? (int) $_REQUEST['level'] : 0;
		$discount_code = isset($_REQUEST['discount_code']) ? sanitize_text_field(wp_unslash($_REQUEST['discount_code'])) : '';

		if (!$level_id) {
			wp_send_json_error(['message' => 'invalid_request']);
		}

		$level = false;

		// 1. Prefer the discount-code pricing pulled straight from the DB. pmpro_getLevelAtCheckout()
		//    can silently drop the trial depending on validation context (use limits, login state),
		//    whereas the discount_codes_levels row is exactly how the code was configured.
		if (!empty($discount_code)) {
			$level = $this->get_discounted_level_pricing($level_id, $discount_code);
		}

		// 2. Fallback: PMPro's own level-at-checkout (URL-applied codes / native trials).
		if (empty($level) && function_exists('pmpro_getLevelAtCheckout')) {
			$level = pmpro_getLevelAtCheckout($level_id, $discount_code);
		}

		// 3. Final fallback: the plain base level.
		if (empty($level) && function_exists('pmpro_getLevel')) {
			$level = pmpro_getLevel($level_id);
		}

		wp_send_json_success(['start_date' => $this->calculate_billing_start_date($level)]);
	}

	/**
	 * Reads the pricing/trial a discount code applies to a level directly from PMPro's tables.
	 * Bypasses pmpro_getLevelAtCheckout()'s validation so the trial is reflected regardless of
	 * the AJAX request's user/use-limit context.
	 *
	 * @param int    $level_id      The PMPro level ID.
	 * @param string $discount_code The discount code string.
	 * @return object|false Level-like object with trial fields, or false if the code/row is absent.
	 */
	private function get_discounted_level_pricing($level_id, $discount_code)
	{
		global $wpdb;

		$code_id = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}pmpro_discount_codes WHERE code = %s LIMIT 1",
			$discount_code
		));

		if (!$code_id) {
			return false;
		}

		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT initial_payment, billing_amount, cycle_number, cycle_period, trial_amount, trial_limit
			 FROM {$wpdb->prefix}pmpro_discount_codes_levels
			 WHERE code_id = %d AND level_id = %d
			 LIMIT 1",
			$code_id,
			$level_id
		));

		if (!$row) {
			return false;
		}

		// Normalize into a level-like object that calculate_billing_start_date() understands.
		return (object) [
			'initial_payment' => $row->initial_payment,
			'billing_amount'  => $row->billing_amount,
			'cycle_number'    => $row->cycle_number,
			'cycle_period'    => $row->cycle_period,
			'trial_amount'    => $row->trial_amount,
			'trial_limit'     => $row->trial_limit,
		];
	}

	/**
	 * Registers the shortcode with WordPress.
	 * Binds the '[dd_pricing_table]' shortcode to the rendering method of this class.
	 * @return void
	 */
	public function register_pricing_shortcode()
	{
		add_shortcode('dd_pricing_table', [$this, 'render_pricing_table']);
	}

	/**
	 * Retrieves every paid, signup-enabled PMPro level (id + name), in PMPro Membership Plans
	 * settings-screen order (Level Groups displayorder — see get_level_group_order()). Public/static
	 * so other modules can list orderable plans without needing an instance of this class.
	 *
	 * Note this deliberately strips free/£0 levels. The pricing table no longer sources its columns
	 * from here — they come from the authored Pricing Tables settings via DD_Feature_Comparison_Table,
	 * where the Trial tier is excluded per-widget instead.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	public static function get_orderable_plans()
	{
		if (! function_exists('pmpro_getAllLevels')) {
			return [];
		}

		$all_levels = pmpro_getAllLevels(true, true);
		$plans = [];

		foreach ($all_levels as $level) {
			if (! $level->allow_signups) {
				continue;
			}

			// Exclude free/£0 levels (e.g. the Trial tier) — only paid plans appear here.
			$base_price = (float) $level->initial_payment > 0 ? (float) $level->initial_payment : (float) $level->billing_amount;
			if ($base_price <= 0) {
				continue;
			}

			$plans[] = [
				'id'   => (int) $level->id,
				'name' => trim($level->name),
			];
		}

		$order = self::get_level_group_order();
		if (! empty($order)) {
			$position = array_flip($order);
			usort($plans, function ($a, $b) use ($position) {
				$pa = $position[$a['id']] ?? PHP_INT_MAX;
				$pb = $position[$b['id']] ?? PHP_INT_MAX;
				return $pa <=> $pb;
			});
		}

		return $plans;
	}

	/**
	 * Reads the level display order from PMPro's Level Groups tables — the same order shown
	 * (and drag-and-drop reordered) on the Membership Plans settings screen: group
	 * `displayorder` first, then each level's `displayorder` within its group.
	 *
	 * @return int[] Level IDs in settings-screen order, or [] if the Level Groups tables/feature
	 *               are unavailable (PMPro < 3.0, or groups not in use).
	 */
	private static function get_level_group_order()
	{
		global $wpdb;

		$lg_table = $wpdb->prefix . 'pmpro_membership_levels_groups';
		$g_table  = $wpdb->prefix . 'pmpro_groups';

		if ($wpdb->get_var("SHOW TABLES LIKE '{$lg_table}'") !== $lg_table) {
			return [];
		}

		return array_map('intval', (array) $wpdb->get_col("
			SELECT lg.level
			FROM {$lg_table} lg
			LEFT JOIN {$g_table} g ON g.id = lg.`group`
			ORDER BY g.displayorder ASC, lg.displayorder ASC, lg.level ASC
		"));
	}

	/**
	 * Retrieves the 'Annual' payment plan data for a given PMPro level.
	 * Scans all level meta to guarantee extraction regardless of the specific meta key used by the add-on.
	 * Public/static so the Feature Comparison Table (pmpro-comparison-table.php) can reuse the same
	 * detection for its own yearly-toggle columns, same as it reuses get_orderable_plans().
	 * @param int $level_id The PMPro Level ID.
	 * @return array|false Returns an array containing the 'id' (formatted for checkout), 'price' (formatted), and 'raw_price' (float) or false if undetected.
	 */
	public static function get_annual_payment_plan($level_id)
	{
		global $wpdb;

		// The PMPro Level Meta table where the Payment Plans Add On serializes data
		$meta_table = $wpdb->prefix . 'pmpro_membership_levelmeta';

		if ($wpdb->get_var("SHOW TABLES LIKE '{$meta_table}'") === $meta_table) {

			// Extract all meta for this level to bypass guessing the exact meta_key
			$all_meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$meta_table} WHERE pmpro_membership_level_id = %d", $level_id));

			foreach ($all_meta as $meta) {
				$val = maybe_unserialize($meta->meta_value);

				// The Payment Plans add-on stores plans as an array
				if (is_array($val)) {
					foreach ($val as $plan_id => $plan) {
						// Support both object and associative array formats
						$p_name   = is_object($plan) ? ($plan->name ?? '') : ($plan['name'] ?? '');
						$p_status = is_object($plan) ? ($plan->status ?? 'active') : ($plan['status'] ?? 'active');

						// Search for the "Annual" plan identifier
						if (!empty($p_name) && stripos($p_name, 'annual') !== false && strtolower($p_status) === 'active') {

							// Prioritize initial_payment; fallback to billing_amount
							$initial = is_object($plan) ? ($plan->initial_payment ?? 0) : ($plan['initial_payment'] ?? 0);
							$billing = is_object($plan) ? ($plan->billing_amount ?? 0) : ($plan['billing_amount'] ?? 0);
							$p_price = (float)$initial > 0 ? $initial : $billing;

							// Extract internal Plan ID (Usually the array key which may already contain the prefix)
							$inner_id = is_object($plan) ? ($plan->id ?? $plan_id) : ($plan['id'] ?? $plan_id);

							// Prevent prefix duplication: If the ID already starts with 'L-', use it directly.
							$plan_identifier = (strpos((string)$inner_id, 'L-') === 0) ? $inner_id : 'L-' . $level_id . '-P-' . $inner_id;

							return [
								'id'        => $plan_identifier,
								'price'     => pmpro_formatPrice((float)$p_price),
								'raw_price' => (float)$p_price // Passed for mathematical comparison during state detection
							];
						}
					}
				}
			}
		}

		return false; // Return false if no active Annual plan configuration is discovered.
	}

	/**
	 * Determines the specific payment plan value a user currently owns for a given level.
	 * Executes mathematical isolation to distinguish between standard billing and the embedded Payment Plan Add On.
	 * @param int $user_id The WordPress User ID.
	 * @param int $level_id The PMPro Level ID.
	 * @return string|false Returns the exact string value of the owned plan (e.g., '8' or 'L-8-P-0'), or false if unowned.
	 */
	private function get_user_active_plan_value($user_id, $level_id)
	{
		if (!function_exists('pmpro_getMembershipLevelsForUser') || !$user_id) {
			return false;
		}

		$user_levels = pmpro_getMembershipLevelsForUser($user_id);

		if (!empty($user_levels)) {
			foreach ($user_levels as $l) {
				if ($l->id == $level_id) {
					// Retrieve the Annual plan details to compare the raw price
					$annual_plan = $this->get_annual_payment_plan($level_id);

					if (!$annual_plan) {
						return (string) $level_id; // If no annual plan exists, they own the default base level.
					}

					// Extract user's active billing rate
					$user_billing = (float)$l->billing_amount > 0 ? (float)$l->billing_amount : (float)$l->initial_payment;
					$annual_raw   = (float)$annual_plan['raw_price'];

					// If billing rate matches the annual rate within a tight tolerance, they own Annual.
					if (abs($user_billing - $annual_raw) < 0.01) {
						return $annual_plan['id'];
					} else {
						return (string) $level_id;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Checks if the user is currently on a free trial.
	 * Evaluates if the latest order is exactly $0.00 AND ensures the user has no recent paid history.
	 * This prevents false positives caused by Prorated Upgrades or mid-cycle Plan Changes, 
	 * which structurally generate $0.00 setup orders in PMPro to adjust billing dates.
	 * * @param int $user_id The WordPress User ID.
	 * @return bool True if active on a true free trial, false if it's an upgrade artifact or paid plan.
	 */
	private function is_user_on_free_trial($user_id)
	{
		if (!$user_id || !function_exists('pmpro_getMembershipLevelsForUser')) {
			return false;
		}

		$user_levels = pmpro_getMembershipLevelsForUser($user_id);
		if (empty($user_levels)) {
			return false;
		}

		global $wpdb;

		foreach ($user_levels as $level) {
			// Ensure the plan is meant to be paid (has a billing amount or initial payment > 0)
			if ((float)$level->billing_amount > 0 || (float)$level->initial_payment > 0) {

				// 1. Retrieve the total value of the most recent order for this specific active level.
				$latest_order_total = $wpdb->get_var($wpdb->prepare("
					SELECT total FROM {$wpdb->prefix}pmpro_membership_orders 
					WHERE user_id = %d 
					AND membership_id = %d 
					AND status IN ('success', 'pending') 
					ORDER BY timestamp DESC
					LIMIT 1
				", $user_id, $level->id));

				// 2. If the latest order is exactly $0.00, evaluate for a true trial vs an upgrade.
				if ($latest_order_total !== null && (float)$latest_order_total == 0) {

					// Upgrade/Proration Safeguard:
					// Query the user's global order history to see if they have ANY successful payment > $0 
					// within the last 365 days, regardless of the membership level.
					$has_recent_paid_order = $wpdb->get_var($wpdb->prepare("
						SELECT id FROM {$wpdb->prefix}pmpro_membership_orders 
						WHERE user_id = %d 
						AND total > 0 
						AND status IN ('success', 'pending') 
						AND timestamp >= DATE_SUB(NOW(), INTERVAL 365 DAY)
						LIMIT 1
					", $user_id));

					// If no recent paid history exists, the $0.00 order is a legitimate new free trial.
					if (!$has_recent_paid_order) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Detects if the user has a scheduled (delayed) downgrade pending and returns the target level ID.
	 *
	 * Uses multiple robust detection strategies to maximise compatibility across PMPro
	 * Proration, Delayed Downgrade, and Payment Plans Add-Ons regardless of addon version.
	 *
	 * @param int $user_id The WordPress User ID.
	 * @return int|false The pending downgrade level ID, or false if none detected.
	 */
	private function get_pending_downgrade_level_id($user_id)
	{
		if (!$user_id) {
			return false;
		}

		global $wpdb;

		// ── Method 1: PMPro Proration Addon Native Function (Most Reliable) ────────────
		if (function_exists('pmprorate_get_downgrades')) {
			$downgrades = pmprorate_get_downgrades($user_id, 'pending');
			if (!empty($downgrades)) {
				// Use reset to grab the first pending downgrade safely
				$downgrade = reset($downgrades);
				if (!empty($downgrade->new_level_id)) {
					return (int) $downgrade->new_level_id;
				}
			}
		}

		// ── Method 2: Direct DB query on pmprorate_downgrades (Fallback) ───────────────
		$prorate_table = $wpdb->prefix . 'pmprorate_downgrades';
		if ($wpdb->get_var("SHOW TABLES LIKE '{$prorate_table}'") === $prorate_table) {
			// Extract the target level without enforcing strict ownership of original plan, 
			// bypassing string-to-int mismatches caused by Payment Plans Addon tracking.
			$new_level_id = $wpdb->get_var($wpdb->prepare(
				"SELECT new_level_id
				 FROM {$prorate_table}
				 WHERE user_id = %d
				 AND status = 'pending'
				 ORDER BY id DESC
				 LIMIT 1",
				$user_id
			));

			if ($new_level_id) {
				return (int) $new_level_id;
			}
		}

		// ── Method 3: Broad user-meta scan for scheduled level changes ──────────────────
		$meta_keys = ['pmpro_scheduled_level_changes', 'pmpro_delayed_downgrade', 'pmpro_next_level'];
		foreach ($meta_keys as $key) {
			$val = get_user_meta($user_id, $key, true);
			if (!empty($val)) {
				if (is_numeric($val) && (int)$val > 0) return (int) $val;
				if (is_array($val)) {
					$found_id = $this->_recursive_find_level_id($val);
					if ($found_id) return (int) $found_id;
				}
			}
		}

		// ── Method 4: pmpro_memberships_users future-startdate row ─────────────────────
		$future_level_id = $wpdb->get_var($wpdb->prepare(
			"SELECT membership_id
			 FROM {$wpdb->prefix}pmpro_memberships_users
			 WHERE user_id   = %d
			   AND status    = 'active'
			   AND startdate > %s
			 ORDER BY startdate ASC
			 LIMIT 1",
			$user_id,
			current_time('mysql', true)
		));

		if ($future_level_id) {
			return (int) $future_level_id;
		}

		return false;
	}

	/**
	 * Helper function to recursively hunt for a level ID inside complex meta arrays.
	 * Required when add-ons nest scheduled tracking deep inside multidimensional arrays.
	 */
	private function _recursive_find_level_id($arr)
	{
		if (!is_array($arr)) return false;
		if (isset($arr['new_level_id'])) return $arr['new_level_id'];
		if (isset($arr['level_id'])) return $arr['level_id'];
		if (isset($arr['id'])) return $arr['id'];

		foreach ($arr as $v) {
			if (is_array($v)) {
				$res = $this->_recursive_find_level_id($v);
				if ($res) return $res;
			}
		}
		return false;
	}

	/**
	 * Intercepts page requests to the Checkout page.
	 * If the user is on a free trial, they are forcefully redirected to their account page with an error message.
	 * @return void
	 */
	public function prevent_checkout_during_trial()
	{
		global $pmpro_pages;

		// Ensure PMPro is active and we are physically on the checkout page
		if (empty($pmpro_pages['checkout']) || !is_page($pmpro_pages['checkout'])) {
			return;
		}

		$user_id = get_current_user_id();

		// If they aren't logged in, let PMPro handle standard guest workflows
		if (!$user_id) {
			return;
		}

		// Perform server-side trial check
		if ($this->is_user_on_free_trial($user_id)) {

			// Display a WordPress/PMPro notice explaining the redirect
			if (function_exists('pmpro_setMessage')) {
				pmpro_setMessage('Plan changes are disabled during your free trial period. Please wait until your first payment is processed.', 'pmpro_error');
			}

			// Bounce them back to the member account dashboard (or homepage fallback)
			$redirect_url = function_exists('pmpro_url') ? pmpro_url('account') : home_url();
			wp_redirect($redirect_url);
			exit;
		}
	}

	/**
	 * Intercepts page requests to the Checkout page.
	 * If the requested level is the target of the user's currently scheduled delayed downgrade,
	 * they are redirected to their account page — preventing a duplicate or conflicting checkout.
	 * @return void
	 */
	public function prevent_checkout_for_pending_downgrade()
	{
		global $pmpro_pages;

		// Only run on the true checkout page
		if (empty($pmpro_pages['checkout']) || !is_page($pmpro_pages['checkout'])) {
			return;
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			return;
		}

		$level_id = isset($_REQUEST['level']) ? (int) $_REQUEST['level'] : 0;
		if (!$level_id) {
			return;
		}

		$pending_downgrade_level_id = $this->get_pending_downgrade_level_id($user_id);

		// Block checkout if this level is already the scheduled downgrade target
		if ($pending_downgrade_level_id && $pending_downgrade_level_id === $level_id) {
			if (function_exists('pmpro_setMessage')) {
				pmpro_setMessage('You already have a downgrade to this plan scheduled. It will take effect at the end of your current billing period.', 'pmpro_error');
			}
			$redirect_url = function_exists('pmpro_url') ? pmpro_url('account') : home_url();
			wp_redirect($redirect_url);
			exit;
		}
	}

	/**
	 * Injects a robust MutationObserver script on the PMPro Checkout page.
	 * Disables the current plan and trial-locked plans without interfering with payment gateway labels.
	 * @return void
	 */
	public function modify_checkout_plans_dom()
	{
		global $pmpro_pages;

		// 1. NON-CHECKOUT PAGE CLEANUP
		if (empty($pmpro_pages['checkout']) || !is_page($pmpro_pages['checkout'])) {
?>
			<script>
				document.addEventListener('DOMContentLoaded', function() {
					const ppContainer = document.getElementById('pmpropp_payment_plans');
					if (ppContainer) {
						ppContainer.style.display = 'none';
						let prev = ppContainer.previousElementSibling;
						if (prev && prev.textContent.toLowerCase().includes('payment plan')) {
							prev.style.display = 'none';
						}
					}
					const headings = document.querySelectorAll('h2, h3, h4, label, legend, p');
					headings.forEach(h => {
						if (h.textContent.trim() === 'Select a Payment Plan') {
							h.style.display = 'none';
						}
					});
				});
			</script>
		<?php
			return;
		}

		// 2. CHECKOUT PAGE MUTATION LOGIC
		$level_id = isset($_REQUEST['level']) ? (int) $_REQUEST['level'] : 0;
		$user_id  = get_current_user_id();

		if (!$level_id) {
			return;
		}

		$owned_plan_value = $this->get_user_active_plan_value($user_id, $level_id);
		$is_on_free_trial = $this->is_user_on_free_trial($user_id);

		?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {

				const ownedValue = "<?php echo esc_js($owned_plan_value); ?>";
				const isOnTrial = <?php echo $is_on_free_trial ? 'true' : 'false'; ?>;

				const processCheckoutDOM = function() {
					// FEATURE: Lock down plans if user is on a trial
					if (isOnTrial) {
						// Strictly target only payment plan radios
						const planRadios = document.querySelectorAll('input[name="pmpropp_chosen_plan"]');
						planRadios.forEach(radio => {
							radio.disabled = true;
							const label = document.querySelector('label[for="' + radio.id + '"]');
							if (label && !label.classList.contains('dd-plan-disabled')) {
								label.classList.add('dd-plan-disabled');
								label.style.opacity = '0.5';
								label.style.cursor = 'not-allowed';
								if (ownedValue && radio.value === ownedValue && !label.innerHTML.includes('(Trial Active)')) {
									label.innerHTML += ' <span style="color:red; font-size:0.9em; margin-left:5px;">(Trial Active)</span>';
								}
							}
						});

						const submitBtn = document.getElementById('pmpro_btn-submit');
						if (submitBtn && !submitBtn.disabled) {
							submitBtn.disabled = true;
							submitBtn.value = 'Plan Changes Disabled During Free Trial';
							submitBtn.style.opacity = '0.5';
							submitBtn.style.cursor = 'not-allowed';
						}
					}
					// FEATURE: Disable the radio button for the plan the user already owns
					else if (ownedValue) {
						const radioBtn = document.querySelector('input[name="pmpropp_chosen_plan"][value="' + ownedValue + '"]');
						if (radioBtn && !radioBtn.disabled) {
							radioBtn.disabled = true;
							const label = document.querySelector('label[for="' + radioBtn.id + '"]');
							if (label && !label.classList.contains('dd-plan-disabled')) {
								label.classList.add('dd-plan-disabled');
								label.style.opacity = '0.5';
								label.style.cursor = 'not-allowed';
								if (!label.innerHTML.includes('(Current Plan)')) {
									label.innerHTML += ' <span style="color:red; font-size:0.9em; margin-left:5px;">(Current Plan)</span>';
								}
							}
						}
					}
				};

				processCheckoutDOM();

				const targetNode = document.body;
				const config = {
					childList: true,
					subtree: true
				};
				const observer = new MutationObserver(function(mutationsList) {
					observer.disconnect();
					processCheckoutDOM();
					observer.observe(targetNode, config);
				});
				observer.observe(targetNode, config);
			});
		</script>
	<?php
	}
	/**
	 * Transforms the PMPro Checkout into a cleaner, influencer-style layout.
	 * Reorders DOM elements, securely hides the payment plan selector, builds an influencer-style 
	 * Summary block ABOVE the payment info, injects the user avatar, and populates uniform bullet points.
	 *
	 * @return void
	 */
	public function influencer_style_pmpro_checkout()
	{
		global $pmpro_pages, $pmpro_level; // Added $pmpro_level to pull live checkout math

		// Abort if we are not on the explicit PMPro checkout page
		if (empty($pmpro_pages['checkout']) || ! is_page($pmpro_pages['checkout'])) {
			return;
		}

		// 1. EXTRACT REAL PLAN NAME & DESCRIPTION FROM PMPRO DATABASE
		$level_id = isset($_REQUEST['level']) ? intval($_REQUEST['level']) : 0;
		$real_plan_name = 'Membership Plan'; // Fallback
		$plan_description = '';

		if ($level_id > 0 && function_exists('pmpro_getLevel')) {
			$level = pmpro_getLevel($level_id);
			if (! empty($level)) {
				$real_plan_name = $level->name;
				if (! empty($level->description)) {
					$plan_description = wp_strip_all_tags($level->description);
				}
			}
		}

		// 2. EXTRACT DYNAMIC PRICING MATH FOR TIMELINE
		$paying_now = isset($pmpro_level->initial_payment) ? (float) $pmpro_level->initial_payment : 0;
		$paying_now_formatted = pmpro_formatPrice($paying_now);

		// Determine the context of the user to output accurate timeline reasoning
		$current_user_id = get_current_user_id();
		$has_existing_level = pmpro_hasMembershipLevel(0, $current_user_id);

		// Upgrade/Proration Safeguard: Query the user's global order history to see if they have ANY 
		// successful payment > $0 within the last 365 days.
		global $wpdb;
		$has_recent_paid_order = false;
		if ($current_user_id) {
			$has_recent_paid_order = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}pmpro_membership_orders 
                WHERE user_id = %d 
                AND total > 0 
                AND status IN ('success', 'pending') 
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 365 DAY)
                LIMIT 1
            ", $current_user_id));
		}

		$payment_reason = 'Standard initial payment';

		if ($paying_now == 0) {
			// Evaluate if a trial is actively configured via Core or the Subscription Delays Add On
			$has_native_trial = isset($pmpro_level->trial_limit) && $pmpro_level->trial_limit > 0;
			$delay_days = get_option('pmpro_subscription_delay_' . $level_id, '');
			$has_delay_addon = !empty($delay_days) && is_numeric($delay_days);

			if (!$has_recent_paid_order && ($has_native_trial || $has_delay_addon)) {
				// Context: User is utilizing a configured free trial and has no recent paid history
				$payment_reason = 'Free trial period';
			} elseif ($has_existing_level && $has_recent_paid_order) {
				// Context: User is downgrading; the $0 is a structural result of time proration
				$payment_reason = 'Adjusted for banked time';
			} else {
				// Context: Standard free level or fully discounted checkout for new/guest users
				$payment_reason = 'Free trial period';
			}
		} elseif (isset($pmpro_level->billing_amount) && $paying_now > 0 && $paying_now < (float)$pmpro_level->billing_amount) {
			// Context: User is upgrading; the cost is a monetary proration
			$payment_reason = 'Prorated upgrade cost';
		}

		// Determine when full-price billing begins. NOTE: a discount-code trial is applied
		// client-side via AJAX after this renders, so $pmpro_level here only reflects a trial that
		// came in via the URL. The front-end re-fetches this through ajax_get_trial_start_date()
		// once a code is applied (see the dd-start-date script below).
		$start_date_str = $this->calculate_billing_start_date($pmpro_level);

		// Safely get the dynamic Membership Levels page URL for the "Change plan" link
		$levels_url = function_exists('pmpro_url') ? pmpro_url('levels') : '/membership-levels/';

		// 3. DEFINE YOUR GLOBAL PLAN DETAILS HERE
		$dynamic_plan_details = [
			'default' => [
				'account_type' => '1 Account',
				'bullets' => [
					'Enjoy unlimited access to your selected plan features.',
					'From the starting date shown, you\'ll be charged for your updated subscription.',
					'<strong>Cancel anytime online. <a href="/terms-of-use/">Terms apply</a></strong>.'
				]
			]
		];

		// Execute your custom avatar shortcode safely
		$avatar_html = do_shortcode('[user_avatar]');
	?>
		<style>
			<?php if ($level_id === 9) : ?>

			/* Level 9 (Growth): hide the checkout sidebar */
			.checkout-sidebar {
				display: none !important;
			}

			<?php endif; ?>

			/* influencer-style CSS Overrides for PMPro */
			#pmpro_form {
				max-width: 600px;
				margin: 0 auto;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
				color: #000;
			}

			.dd-influencer-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding-bottom: 15px;
			}

			.dd-checkout-title-row {
				display: flex;
				justify-content: space-between;
				align-items: baseline;
				margin-bottom: 20px;
				border-bottom: 1px solid #e5e5e5;
				padding-bottom: 20px;
			}

			.dd-checkout-title-row h2 {
				font-size: clamp(20px, 1.5vw, 32px) !important;
				font-weight: 700 !important;
				margin: 0 !important;
				color: #000;
				letter-spacing: -0.5px;
			}

			.dd-checkout-title-row a {
				color: var(--e-global-color-accent);
				text-decoration: underline;
				font-weight: 500;
				font-size: 14px;
				transition: color 0.2s ease;
			}

			.dd-checkout-title-row a:hover {
				color: #000;
			}

			.dd-avatar-wrapper {
				width: 60px;
				height: 60px;
				border-radius: 50%;
				overflow: hidden;
				background: #eee;
				border: 2px solid #ddd;
			}

			.dd-avatar-wrapper img {
				width: 100%;
				height: 100%;
				object-fit: cover;
			}

			.pmpro_checkout-section {
				background: transparent !important;
				border: none !important;
				box-shadow: none !important;
				padding: 0 !important;
				margin-bottom: 30px !important;
			}

			.pmpro_checkout-section h3,
			.pmpro_checkout-section h2 {
				font-size: 20px !important;
				font-weight: 700 !important;
				border: none !important;
				margin-bottom: 15px !important;
				padding-bottom: 0 !important;
				color: #000;
			}

			#pmpro_level_cost,
			#pmpropp_payment_plans,
			#pmpro_pricing_fields,
			#pmpro_user_fields,
			#pmpropp_select_payment_plan {
				display: none !important;
			}

			.pmpro_form_field-radio-items.pmpro_form_field-radio-items.pmpro_form_field-radio-items {
				flex-direction: column;
				gap: 0;
			}

			.pmpro_form_field-radio-item.pmpro_form_field-radio-item.pmpro_form_field-radio-item {
				width: 100%;
			}

			.pmpro_form_field-radio-item.pmpro_form_field-radio-item.pmpro_form_field-radio-item .pmpro_form_label {
				font-size: 1rem;
				color: #000;
			}

			.pmpro_form_field-radio-item.pmpro_form_field-radio-item.pmpro_form_field-radio-item input {
				width: auto;
			}

			.pmpro_check_instructions.pmpro_check_instructions {
				box-shadow: none;
				border: none;
				border-radius: 0;
				margin-top: 0;
			}

			.pmpro_check_instructions.pmpro_check_instructions .pmpro_card_title {
				padding: 0;
			}

			.pmpro_check_instructions.pmpro_check_instructions .pmpro_card_content {
				padding: 0;
			}

			#pmpro_payment_information_fields .pmpro_card,
			#pmpro_payment_method .pmpro_card {
				border-radius: 0;
				margin: 0;
				border: none;
				box-shadow: none;
			}

			#pmpro_payment_method .pmpro_card {
				margin-bottom: 30px;
			}

			#pmpro_payment_information_fields .pmpro_card .pmpro_card_content,
			#pmpro_payment_method .pmpro_card .pmpro_card_content {
				padding: 0;
			}

			/* influencer Card Styles */
			#dd-influencer-summary {
				margin-top: 20px !important;
				margin-bottom: 20px !important;
				border-bottom: 1px solid #e5e5e5 !important;
				padding-bottom: 20px !important;
			}

			.infl-summary-card {
				background: transparent;
			}

			.infl-header-row {
				display: flex;
				align-items: center;
				margin-bottom: 25px;
			}

			.infl-icon {
				width: 50px;
				height: 50px;
				background: var(--e-global-color-secondary);
				border-radius: 6px;
				display: flex;
				align-items: center;
				justify-content: flex-end;
				margin-right: 15px;
				color: #fff;
			}

			.infl-icon img {
				width: 30px;
				height: 30px;
			}

			.infl-plan-info {
				flex-grow: 1;
			}

			.infl-plan-info h4 {
				margin: 0 0 2px 0 !important;
				font-size: 16px !important;
				font-weight: 700 !important;
			}

			.infl-plan-info span {
				font-size: 14px;
				color: #b3b3b3;
			}

			.infl-price-info {
				text-align: right;
			}

			.infl-price-info h4 {
				margin: 0 0 2px 0 !important;
				font-size: 16px !important;
				font-weight: 700 !important;
			}

			.infl-price-info span {
				font-size: 14px;
				color: #b3b3b3;
			}

			body:not(.page-id-4144) span#pmpro_submit_span {
				width: 100%;
			}

			.pmpro_form_submit {
				flex-direction: column;
			}

			/* Timeline Styles */
			.infl-timeline {
				position: relative;
				padding-left: 15px;
				margin-bottom: 20px;
			}

			.infl-timeline::before {
				content: '';
				position: absolute;
				left: 19px;
				top: 10px;
				bottom: 25px;
				width: 1px;
				background: #000;
			}

			.infl-timeline-item {
				position: relative;
				padding-left: 20px;
				margin-bottom: 20px;
			}

			.infl-dot {
				position: absolute;
				left: 0;
				top: 6px;
				width: 9px;
				height: 9px;
				border-radius: 50%;
				background: #000;
				z-index: 2;
			}

			.infl-dot.hollow {
				background: #fff;
				border: 2px solid #000;
				left: 0px;
				width: 9px;
				height: 9px;
			}

			.infl-content p {
				margin: 0 0 2px 0 !important;
				font-size: 15px;
				font-weight: 500;
			}

			.infl-content p+span {
				font-size: 14px;
				color: #b3b3b3;
				font-weight: 500;
			}

			.dd-start-date.dd-start-date.dd-start-date {
				color: var(--e-global-color-primary);
			}

			/* Bullet Points */
			.infl-bullets.infl-bullets {
				list-style: none;
				padding: 0;
				margin: 0;
				font-size: 13px !important;
				color: #6a6a6a;
				line-height: 1.5;
			}

			.infl-bullets li {
				position: relative;
				padding-left: 15px;
				margin-bottom: 6px;
			}

			.infl-bullets li::before {
				content: '•';
				position: absolute;
				left: 0;
				top: 0;
				color: #6a6a6a;
			}

			.infl-bullets a {
				color: #6a6a6a;
				text-decoration: underline;
			}

			/* Submit Button */
			#pmpro_btn-submit {
				background-color: #1ed760 !important;
				color: #000 !important;
				border-radius: 500px !important;
				padding: 16px 30px !important;
				font-size: 16px !important;
				font-weight: 700 !important;
				border: none !important;
				width: 100% !important;
				text-transform: none !important;
				transition: transform 0.2s ease, background-color 0.2s ease;
				margin-top: 20px;
			}

			#pmpro_btn-submit:hover {
				background-color: #1fdf64 !important;
				transform: scale(1.02);
			}

			/* Clean up residual Account Info if logged out */
			.dd-clean-account-info h2,
			.dd-clean-account-info h3,
			.dd-clean-account-info hr,
			.dd-clean-account-info p.pmpro_logged_in_text {
				display: none !important;
			}
		</style>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				if (typeof jQuery === 'undefined') return;
				var $ = jQuery;

				var avatarHtml = <?php echo wp_json_encode($avatar_html); ?>;
				var dynamicPlanMeta = <?php echo wp_json_encode($dynamic_plan_details); ?>;
				var realPlanName = <?php echo wp_json_encode($real_plan_name); ?>;
				var planDescription = <?php echo wp_json_encode($plan_description); ?>;
				var levelsUrl = <?php echo wp_json_encode($levels_url); ?>;

				// Dynamic Pricing injected directly from PMPro Live Logic
				var dynamicPayingNow = <?php echo wp_json_encode($paying_now_formatted); ?>;
				var paymentReason = <?php echo wp_json_encode($payment_reason); ?>;
				var dynamicStartDate = <?php echo wp_json_encode($start_date_str); ?>;

				// Endpoint + nonce used to re-derive the "Starting" date after a discount code
				// is applied via AJAX (the trial only exists once PMPro applies the code).
				var ddAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
				var ddStartDateNonce = <?php echo wp_json_encode(wp_create_nonce('dd_trial_start')); ?>;

				// 1. Inject Header and Title Row immediately
				$('.dd-influencer-header, .dd-checkout-title-row').remove();
				var headerHtml = '<div class="dd-influencer-header">' +
					'<div class="dd-influencer-logo"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="134.712" height="68.251" viewBox="0 0 134.712 68.251"><defs><clipPath id="clip-path"><rect id="Rectangle_9" data-name="Rectangle 9" width="134.712" height="68.251" fill="currentColor"/></clipPath></defs><g id="Group_9" data-name="Group 9" transform="translate(0 0)"><g id="Group_8" data-name="Group 8" transform="translate(0 0)" clip-path="url(#clip-path)"><path id="Path_6" data-name="Path 6" d="M7.342,45.71H6.154V54.9H2.659V33.234H7.2c4.893,0,8.108,2.306,8.108,6.116a5.3,5.3,0,0,1-3.7,5.067c2.866,1.083,4.753,7.758,8.807,7.758l-.7,2.936c-6.92,0-7.164-9.4-12.372-9.4m.21-9.75h-1.4v7.059h1.5c2.481,0,4.194-1.294,4.194-3.6,0-2.2-1.782-3.459-4.3-3.459" transform="translate(-1.191 -14.885)" fill="currentColor"/><path id="Path_7" data-name="Path 7" d="M76.659,54.929H71.522V33.3h4.264c5,0,8.387,1.572,8.387,5.452A3.966,3.966,0,0,1,81.1,42.8c3.075.489,4.962,2.271,4.962,5.731,0,4.683-3.6,6.4-9.4,6.4M76.17,35.988H75.017v5.7h1.4c3.285,0,4.229-1.083,4.229-2.761.035-2.062-1.328-2.936-4.473-2.936m1.4,8.422H75.017v7.653h2.551c3.984,0,4.823-1.573,4.928-3.7,0-1.887-.874-3.949-4.928-3.949" transform="translate(-32.034 -14.914)" fill="currentColor"/><path id="Path_8" data-name="Path 8" d="M118.811,54.929h-5.137V33.3h4.264c5,0,8.387,1.572,8.387,5.452A3.966,3.966,0,0,1,123.25,42.8c3.075.489,4.963,2.271,4.963,5.731,0,4.683-3.6,6.4-9.4,6.4m-.489-18.941h-1.153v5.7h1.4c3.285,0,4.229-1.083,4.229-2.761.035-2.062-1.328-2.936-4.473-2.936m1.4,8.422h-2.551v7.653h2.551c3.984,0,4.823-1.573,4.928-3.7,0-1.887-.874-3.949-4.928-3.949" transform="translate(-50.914 -14.914)" fill="currentColor"/><path id="Path_9" data-name="Path 9" d="M165.111,54.926c-6.221,0-11.182-4.055-11.182-11.149,0-7.059,4.961-11.113,11.182-11.113S176.3,36.718,176.3,43.812c0,7.059-4.963,11.114-11.184,11.114m0-19.361c-4.158,0-7.583,3.04-7.583,8.213,0,5.278,3.425,8.213,7.583,8.213s7.584-2.936,7.584-8.178c0-5.207-3.425-8.247-7.584-8.247" transform="translate(-68.944 -14.63)" fill="currentColor"/><path id="Path_10" data-name="Path 10" d="M213.754,39.84V54.448h-3.5V32.222h.489l14.643,15.132V32.781h3.494V54.9H228.4Z" transform="translate(-94.174 -14.432)" fill="currentColor"/><path id="Path_11" data-name="Path 11" d="M7.8,105.564H2.659V83.932H6.923c5,0,8.387,1.572,8.387,5.452a3.966,3.966,0,0,1-3.075,4.054c3.075.489,4.962,2.271,4.962,5.731,0,4.683-3.6,6.4-9.4,6.4M7.307,86.623H6.154v5.7h1.4c3.285,0,4.229-1.083,4.229-2.761.035-2.062-1.328-2.936-4.473-2.936m1.4,8.422H6.154V102.7H8.705c3.984,0,4.823-1.573,4.928-3.7,0-1.887-.874-3.949-4.928-3.949" transform="translate(-1.191 -37.593)" fill="currentColor"/><path id="Path_12" data-name="Path 12" d="M54.1,105.56c-6.221,0-11.183-4.054-11.183-11.148,0-7.059,4.962-11.113,11.183-11.113s11.183,4.054,11.183,11.148c0,7.059-4.962,11.113-11.183,11.113m0-19.361c-4.158,0-7.583,3.04-7.583,8.213,0,5.278,3.425,8.213,7.583,8.213s7.583-2.936,7.583-8.178c0-5.207-3.424-8.247-7.583-8.247" transform="translate(-19.22 -37.309)" fill="currentColor"/><path id="Path_13" data-name="Path 13" d="M97.3,105.536H93.421l7.933-12.686-5.7-8.982h4.019l3.53,6.326,3.53-6.326h4.019l-5.661,8.982,7.9,12.686h-3.88l-5.905-10.169Z" transform="translate(-41.843 -37.564)" fill="currentColor"/><path id="Path_14" data-name="Path 14" d="M141.863,120.176a2.048,2.048,0,0,1-2.237-2.062,2,2,0,0,1,2.237-2.027,2.051,2.051,0,0,1,2.306,2.062,2.082,2.082,0,0,1-2.306,2.027" transform="translate(-62.538 -51.995)" fill="currentColor"/><path id="Path_15" data-name="Path 15" d="M4.717,1.362,2.708,10.83H.978L2.97,1.362H0L.612,0h7.2L7.529,1.362Z" transform="translate(0 0)" fill="currentColor"/><path id="Path_16" data-name="Path 16" d="M24.528,10.83l1.118-5.275h-5.66L18.868,10.83H17.121L19.41,0h1.747l-.891,4.192h5.66L26.816,0h1.765L26.275,10.83Z" transform="translate(-7.669 0)" fill="currentColor"/><path id="Path_17" data-name="Path 17" d="M46.163,1.362l-.611,2.9h3.371l-.279,1.362H45.255l-.8,3.826H50.3l-.612,1.38H42.408L44.7,0h6.166l-.3,1.362Z" transform="translate(-18.994 0)" fill="currentColor"/><path id="Path_18" data-name="Path 18" d="M47.471,37.22V54.977h3.495V33.4Z" transform="translate(-21.262 -14.961)" fill="currentColor"/></g></g></svg></div>' +
					'<div class="dd-avatar-wrapper">' + (avatarHtml ? avatarHtml : '') + '</div>' +
					'</div>' +
					'<div class="dd-checkout-title-row">' +
					'<h2>Checkout</h2>' +
					'<a href="' + levelsUrl + '">Change plan</a>' +
					'</div>';
				$('#pmpro_form').prepend(headerHtml);

				// Wait for the Payment Plans DOM to be fully injected via AJAX
				var pollDOM = setInterval(function() {
					var $radios = $('input[name="pmpropp_chosen_plan"]');
					var $levelCost = $('#pmpro_level_cost');

					// Only proceed if PMPro has successfully rendered the pricing elements
					if ($radios.length > 0 || $levelCost.length > 0) {
						clearInterval(pollDOM);

						// 2. Safely parse URL to enforce accurate plan selection
						var urlParams = new URLSearchParams(window.location.search);
						var currentLevelId = urlParams.get('level') || '';
						var chosenPlanUrlValue = urlParams.get('pmpropp_chosen_plan');
						var planDetails = dynamicPlanMeta[currentLevelId] ? dynamicPlanMeta[currentLevelId] : dynamicPlanMeta['default'];

						// Force PMPro to check the correct radio button based on the URL
						if ($radios.length > 0) {
							if (chosenPlanUrlValue) {
								$radios.filter('[value="' + chosenPlanUrlValue + '"]').prop('checked', true);
							} else if (currentLevelId) {
								$radios.filter('[value="' + currentLevelId + '"]').prop('checked', true);
							}
						}

						// 3. Hide Native Elements
						var $paymentPlanWrapper = $('#pmpropp_payment_plans').closest('.pmpro_checkout-section');
						if ($paymentPlanWrapper.length === 0) $paymentPlanWrapper = $('#pmpropp_payment_plans');
						$paymentPlanWrapper.hide();
						$paymentPlanWrapper.prev('h2, h3, hr').hide();

						$('.pmpro_checkout-section h2, .pmpro_checkout-section h3').each(function() {
							var txt = $(this).text().trim();
							if (txt.indexOf('Payment Plan') !== -1 || txt.indexOf('Membership Information') !== -1) {
								$(this).hide();
								if ($(this).siblings().length === 0) $(this).closest('.pmpro_checkout-section').hide();
							}
						});

						// 4. Extract Pricing Data explicitly from the checked radio
						var labelText = $('input[name="pmpropp_chosen_plan"]:checked').siblings('label').text().trim() || $levelCost.text().trim();

						var planName = realPlanName;
						var isAnnual = labelText.toLowerCase().includes('annual') || labelText.toLowerCase().includes('year');
						if (isAnnual) planName = planName + " (Annual)";

						var recurringPrice = "";
						var cycle = "month";

						var recMatch = labelText.match(/(\$[0-9,.]+)\s+per\s+([a-zA-Z]+)/i);
						if (recMatch) {
							recurringPrice = recMatch[1];
							cycle = recMatch[2].toLowerCase();
						}

						// Build Dynamic Bullets from Array
						var bulletsHtml = '';

						// Inject the PMPro database description as the very first bullet
						if (planDescription) {
							bulletsHtml += '<li>' + planDescription + '</li>';
						}

						planDetails.bullets.forEach(function(bullet) {
							bulletsHtml += '<li>' + bullet + '</li>';
						});

						// 5. Build Summary HTML Using New Live Math Logic
						var influencerHtml = `
                        <div class="infl-summary-card">
                            <div class="infl-header-row">
                                <div class="infl-icon">
                                   <img src="/wp-content/uploads/2026/02/cropped-cropped-cropped-favicon-192x192-1.png" alt="Plan Icon">
                                </div>
                                <div class="infl-plan-info">
                                    <h4>${planName}</h4>
                                    <span>${planDetails.account_type}</span>
                                </div>
                                <div class="infl-price-info">
                                    <h4 class="dd-due-today-val">${dynamicPayingNow} </h4>
                                    <span>due today</span>
                                    <span class="membership-amount" style="display:none;">${recurringPrice}/${cycle}</span>
                                </div>
                            </div>
                            <div class="infl-timeline">
                                <div class="infl-timeline-item" id="dd-timeline-now">
                                    <div class="infl-dot filled"></div>
                                    <div class="infl-content">
                                        <p><strong>Paying Now:</strong> <span class="dd-paying-now-val">${dynamicPayingNow}</span></p>
                                        <span class="dd-paying-now-reason">${paymentReason}</span>
                                    </div>
                                </div>
                                <div class="infl-timeline-item" id="dd-timeline-later">
                                    <div class="infl-dot hollow"></div>
                                    <div class="infl-content">
                                        <p><strong>Starting <span class="dd-start-date">${dynamicStartDate}</span>:</strong> ${recurringPrice} /${cycle}</p>
                                        <span>${planName}</span>
                                    </div>
                                </div>
                            </div>
                            <ul class="infl-bullets">
                                ${bulletsHtml}
                            </ul>
                        </div>`;

						// 6. Inject Summary Block
						$('#dd-influencer-summary').remove(); // <-- ADD THIS FIX
						var $summarySection = $('<div id="dd-influencer-summary" class="pmpro_checkout-section"></div>');
						$summarySection.append(influencerHtml);

						// Map potential insertion anchors
						var $paymentPlanSelector = $('#pmpropp_select_payment_plan').closest('.pmpro_checkout-section');
						if (!$paymentPlanSelector.length) $paymentPlanSelector = $('#pmpropp_select_payment_plan');

						var $paymentMethodSelector = $('#pmpro_payment_method').closest('.pmpro_checkout-section');
						if (!$paymentMethodSelector.length) $paymentMethodSelector = $('#pmpro_payment_method');

						var $paymentFields = $('#pmpro_payment_information_fields').closest('.pmpro_checkout-section');
						if (!$paymentFields.length) $paymentFields = $('#pmpro_payment_information_fields');

						// Prioritize layout injection hierarchy 
						if ($paymentPlanSelector.length) {
							$paymentPlanSelector.before($summarySection);
						} else if ($paymentMethodSelector.length) {
							$paymentMethodSelector.before($summarySection);
						} else if ($paymentFields.length) {
							$paymentFields.before($summarySection);
						} else {
							$('#pmpro_form').prepend($summarySection);
						}

						// 7. Clean Up Account Information
						var $accInfo = $('#pmpro_account').closest('.pmpro_checkout-section');
						if (!$accInfo.length) $accInfo = $('#pmpro_account');
						if (!$accInfo.length) $accInfo = $('.pmpro_checkout-section:contains("Account Information")');

						if ($accInfo.length) {
							$accInfo.addClass('dd-clean-account-info');
							if ($accInfo.find('input[type="text"]').length > 0 || $accInfo.find('input[type="password"]').length > 0) {
								$summarySection.append($accInfo);
								$accInfo.show();
							} else {
								$accInfo.hide();
							}
						}

						if ($levelCost.length) $levelCost.hide();

						// --- GATEWAY LISTENER FOR TIMELINE & PMPro INSTRUCTIONS ---
						function ddHandleGatewaySwitch() {
							var gateway = $('input[name=gateway]:checked').val() || $('#gateway').val();

							// A) Update Timeline UI
							if (gateway === 'check') {
								$('.dd-paying-now-val').html(recurringPrice); // <-- Changed to .html
								$('.dd-due-today-val').html(recurringPrice);
								$('.dd-paying-now-reason').text('Standard initial payment (Trial disabled)');
								$('#dd-timeline-later').hide();
							} else {
								$('.dd-paying-now-val').html(dynamicPayingNow); // <-- Changed to .html
								$('.dd-due-today-val').html(dynamicPayingNow);
								$('.dd-paying-now-reason').text(paymentReason);
								$('#dd-timeline-later').show();
							}

							// B) Scrub native Check Instructions
							$('.pmpro_checkout-instructions-check, .pmpro_check_instructions').each(function() {
								var $el = $(this);
								if (typeof $el.data('dd-original-html') === 'undefined') {
									$el.data('dd-original-html', $el.html());
								}
							});
						}

						ddHandleGatewaySwitch(); // Initial run
						$(document).on('change', 'input[name=gateway], #gateway', ddHandleGatewaySwitch);

						// --- DISCOUNT-CODE START DATE SYNC ---
						// A discount code's trial only exists AFTER the code is applied (PMPro does
						// this via AJAX/REST post-render), so re-derive the recurring start date
						// whenever the applied code changes. We watch the applied-code value directly
						// (poll + ajaxComplete) to stay independent of how the checkout applies it.
						function ddGetAppliedDiscountCode() {
							// 1. Any discount-named *value* input that currently holds a value. The
							//    block checkout may use a different field name than classic PMPro, so
							//    match on "discount" appearing anywhere in the name/id rather than fixed
							//    ids. Skip buttons/submits — their value is the label ("Apply"), not a code.
							var code = '';
							$('input').each(function() {
								var type = (this.type || 'text').toLowerCase();
								if (type === 'submit' || type === 'button' || type === 'reset' ||
									type === 'checkbox' || type === 'radio' || type === 'image') {
									return; // continue
								}
								var key = (((this.name || '') + ' ' + (this.id || '')).toLowerCase());
								if (key.indexOf('discount') !== -1) {
									var v = $.trim($(this).val() || '');
									if (v) {
										code = v;
										return false; // break
									}
								}
							});
							if (code) return code;

							// 2. Fallback: some checkouts clear the field after applying and only show
							//    a "<CODE> code has been applied" message — pull the code out of that.
							var $msg = $('[id*="discount"], [class*="discount"]').filter(function() {
								return /applied/i.test($(this).text());
							}).first();
							if ($msg.length) {
								var m = $msg.text().match(/\b([A-Z0-9]{4,})\b/);
								if (m) return m[1];
							}
							return '';
						}

						// Network-safety state: never run more than one request at a time, fail fast,
						// and give up after repeated failures so a slow/blocked network can't make us
						// pile up hung requests and exhaust the browser's connection pool to the host.
						var ddInFlight = false;
						var ddSyncedCode = null; // last code we successfully reflected in the UI
						var ddFailCount = 0;
						var ddSyncEnabled = true;
						var ddPollTimer = null;

						function ddStopSync() {
							ddSyncEnabled = false;
							if (ddPollTimer) {
								clearInterval(ddPollTimer);
								ddPollTimer = null;
							}
						}

						function ddRefreshStartDate(code) {
							if (!ddSyncEnabled || !currentLevelId || ddInFlight) return;
							ddInFlight = true;
							$.ajax({
								url: ddAjaxUrl,
								method: 'POST',
								timeout: 8000, // fail fast instead of hanging ~30s and holding a connection
								data: {
									action: 'dd_get_trial_start_date',
									level: currentLevelId,
									discount_code: code || '',
									nonce: ddStartDateNonce
								}
							}).done(function(resp) {
								ddFailCount = 0;
								if (resp && resp.success && resp.data && resp.data.start_date) {
									ddSyncedCode = code;
									dynamicStartDate = resp.data.start_date;
									$('#dd-influencer-summary .dd-start-date').text(resp.data.start_date);
								}
							}).fail(function() {
								ddFailCount++;
								if (ddFailCount >= 3) {
									ddStopSync();
								}
							}).always(function() {
								ddInFlight = false;
							});
						}

						function ddMaybeRefresh() {
							if (!ddSyncEnabled) return;
							var code = ddGetAppliedDiscountCode();
							if (code === ddSyncedCode) return; // UI already shows the right date
							ddRefreshStartDate(code);
						}

						// Snappy: react to discount-apply AJAX. Safety net: poll the applied-code value.
						$(document).ajaxComplete(function(event, xhr, settings) {
							var probe = (((settings && settings.url) || '') + ((settings && settings.data) || '')).toLowerCase();
							if (probe.indexOf('dd_get_trial_start_date') !== -1) return; // ignore our own call
							if (probe.indexOf('pmpro') !== -1 || probe.indexOf('discount') !== -1) {
								setTimeout(ddMaybeRefresh, 50);
							}
						});
						ddPollTimer = setInterval(ddMaybeRefresh, 1000);
						ddMaybeRefresh(); // initial (covers a code already applied at load)

						// MutationObserver to catch PMPro's AJAX injection of check instructions
						var instrObserver = new MutationObserver(function() {
							// BUG FIX: Temporarily pause the observer to prevent infinite loop
							instrObserver.disconnect();

							var gateway = $('input[name=gateway]:checked').val() || $('#gateway').val();
							if (gateway === 'check') {
								$('.pmpro_checkout-instructions-check, .pmpro_check_instructions').each(function() {
									var txt = $(this).html();
									if (txt.toLowerCase().includes('trial')) {
										if (typeof $(this).data('dd-original-html') === 'undefined') {
											$(this).data('dd-original-html', txt);
										}
									}
								});
							}

							// Resume observer
							instrObserver.observe(document.body, {
								childList: true,
								subtree: true
							});
						});
						instrObserver.observe(document.body, {
							childList: true,
							subtree: true
						});
					}
				}, 100); // Poll every 100ms until DOM is ready
			});
		</script>
	<?php
	}

	/**
	 * The level's base (Monthly) price, formatted and raw, plus its plain checkout URL.
	 * Pivots to billing_amount when initial_payment is 0 (supports structural deferred billing).
	 *
	 * @param int $level_id
	 * @return array{id:int,price:string,raw_price:float,url:string}|false
	 */
	private function get_level_data($level_id)
	{
		if (! function_exists('pmpro_getLevel')) {
			return false;
		}
		$level = pmpro_getLevel($level_id);
		if (empty($level)) {
			return false;
		}

		$price = (float)$level->initial_payment > 0 ? $level->initial_payment : $level->billing_amount;

		return [
			'id'        => $level->id,
			'price'     => pmpro_formatPrice((float)$price),
			'raw_price' => (float)$price,
			'url'       => pmpro_url('checkout', '?level=' . $level->id)
		];
	}

	/**
	 * Determines the user's highest active tier based on the base (Monthly) price.
	 * Used to establish hierarchy and dynamically output "Upgrade" or "Downgrade" buttons.
	 *
	 * @param int $user_id The WordPress User ID.
	 * @return float The highest raw base price among the user's active levels.
	 */
	private function get_user_max_tier_base_price($user_id)
	{
		if (!function_exists('pmpro_getMembershipLevelsForUser') || !$user_id) {
			return 0.00;
		}

		$user_levels = pmpro_getMembershipLevelsForUser($user_id);
		$max_base_price = 0.00;

		if (!empty($user_levels)) {
			foreach ($user_levels as $l) {
				$base_level = pmpro_getLevel($l->id);
				if ($base_level) {
					$price = (float)$base_level->initial_payment > 0 ? (float)$base_level->initial_payment : (float)$base_level->billing_amount;
					if ($price > $max_base_price) {
						$max_base_price = $price;
					}
				}
			}
		}

		return $max_base_price;
	}

	/**
	 * The membership facts that are identical for every column of every pricing table on the page:
	 * who the visitor is, whether they're mid free-trial, the highest tier they hold (which decides
	 * upgrade vs downgrade), and any scheduled downgrade target.
	 *
	 * Memoized per request — get_pending_downgrade_level_id() alone runs up to three lookups, and
	 * the renderer asks for this once per plan column.
	 *
	 * @return array{user_id:int,is_on_free_trial:bool,user_max_base_price:float,pending_downgrade_level_id:int|false}
	 */
	public function get_membership_context()
	{
		if ($this->membership_context !== null) {
			return $this->membership_context;
		}

		$user_id = get_current_user_id();

		$this->membership_context = [
			'user_id'                    => $user_id,
			'is_on_free_trial'           => $user_id ? $this->is_user_on_free_trial($user_id) : false,
			'user_max_base_price'        => $user_id ? $this->get_user_max_tier_base_price($user_id) : 0.00,
			'pending_downgrade_level_id' => $user_id ? $this->get_pending_downgrade_level_id($user_id) : false,
		];

		return $this->membership_context;
	}

	/**
	 * Resolves one plan column's button state for the current visitor — ownership of each term,
	 * whether this plan is an upgrade or a downgrade from what they hold, and which of the lockdown
	 * states (free trial, scheduled downgrade) applies.
	 *
	 * This is the single source of truth for that cascade. The pricing table's yearly-toggle script
	 * (in pmpro-comparison-table.php) reproduces it client-side from the data-* attributes built
	 * here, so any change to the precedence order or to a button string must be made in both places.
	 *
	 * Precedence, highest first:
	 *   1. free trial          — every plan locked; the held one reads CURRENT PLAN (TRIAL)
	 *   2. pending downgrade   — this plan is the scheduled target, so checkout is blocked outright
	 *   3. leaving current plan— the visitor is downgrading away, so their terms are frozen
	 *   4. owns the shown term — CURRENT PLAN, disabled
	 *   5. owns the other term — SWITCH PLAN (monthly <-> annual on the same level)
	 *   6. otherwise           — UPGRADE / DOWNGRADE / SELECT PLAN
	 *
	 * @param int    $level_id       The primary PMPro Level ID (Default/Monthly).
	 * @param string $annual_plan_id The level's "Annual" Payment Plan identifier (e.g. 'L-8-P-0'),
	 *                               or '' when the level has no Annual plan configured.
	 * @return array|null Null when the level can't be resolved.
	 */
	public function get_plan_button_state($level_id, $annual_plan_id = '')
	{
		$monthly_data = $this->get_level_data($level_id);
		if (! $monthly_data) {
			return null;
		}

		$context     = $this->get_membership_context();
		$annual_plan_id = (string) $annual_plan_id;

		$owns_monthly = false;
		$owns_annual  = false;

		// Resolve which exact plan value the user holds on this level, so a monthly holder and an
		// annual holder of the SAME level are told apart (that's what makes SWITCH PLAN possible).
		$owned_plan_value = $this->get_user_active_plan_value($context['user_id'], $level_id);
		if ($owned_plan_value) {
			if ($annual_plan_id !== '' && $owned_plan_value === $annual_plan_id) {
				$owns_annual = true;
			} else {
				$owns_monthly = true;
			}
		}

		$has_any_plan = $owns_monthly || $owns_annual;

		// Default the shown term to the one the visitor actually holds.
		$show_annual       = $owns_annual;
		$owns_current_view = $show_annual ? $owns_annual : $owns_monthly;
		$owns_other_view   = $show_annual ? $owns_monthly : $owns_annual;

		// Upgrade vs downgrade is decided on tier hierarchy — the base (Monthly) price of this level
		// against the highest base price the visitor currently holds.
		if ($context['user_max_base_price'] > 0) {
			$action_verb = ($monthly_data['raw_price'] < $context['user_max_base_price']) ? 'DOWNGRADE PLAN' : 'UPGRADE PLAN';
		} else {
			$action_verb = 'SELECT PLAN';
		}

		$pending_downgrade_level_id = $context['pending_downgrade_level_id'];
		$is_target_downgrade        = ($pending_downgrade_level_id && (int) $pending_downgrade_level_id === (int) $level_id);
		$is_leaving_current_plan    = ($pending_downgrade_level_id && $has_any_plan);

		$annual_url = ($annual_plan_id !== '')
			? pmpro_url('checkout', '?level=' . (int) $level_id . '&pmpropp_chosen_plan=' . $annual_plan_id)
			: '';

		if ($context['is_on_free_trial']) {
			$btn_text     = $owns_current_view ? 'CURRENT PLAN (TRIAL)' : 'LOCKED DURING TRIAL';
			$btn_disabled = true;
		} elseif ($is_target_downgrade) {
			// This plan is the target of a scheduled delayed downgrade — block checkout entirely
			$btn_text     = 'PENDING DOWNGRADE';
			$btn_disabled = true;
		} elseif ($is_leaving_current_plan) {
			// The visitor is actively leaving their current plan via a downgrade; lock the other
			// payment options too, so they can't stack a term change on top of it.
			$btn_text     = $owns_current_view ? 'CURRENT PLAN' : 'CHANGES LOCKED';
			$btn_disabled = true;
		} elseif ($owns_current_view) {
			$btn_text     = 'CURRENT PLAN';
			$btn_disabled = true;
		} else {
			$btn_text     = $owns_other_view ? 'SWITCH PLAN' : $action_verb;
			$btn_disabled = false;
		}

		// Checkout destinations for each term. These deliberately ignore any CTA URL the admin
		// authored against the column in the Pricing Tables settings — a plan button has to land on
		// that level's own checkout carrying the level (and, for annual, the chosen Payment Plan),
		// exactly as the previous card layout did. A generic authored signup link would drop both.
		$url_monthly = $monthly_data['url'];
		$url_annual  = $annual_url !== '' ? $annual_url : $monthly_data['url'];

		return [
			'owns_monthly'         => $owns_monthly,
			'owns_annual'          => $owns_annual,
			'has_any_plan'         => $has_any_plan,
			'default_annual'       => $show_annual,
			'action_verb'          => $action_verb,
			'btn_text'             => $btn_text,
			'btn_disabled'         => $btn_disabled,
			'btn_url'              => $btn_disabled ? '' : ($show_annual ? $url_annual : $url_monthly),
			'url_monthly'          => $url_monthly,
			'url_annual'           => $url_annual,
			'is_on_trial'          => (bool) $context['is_on_free_trial'],
			'is_pending_downgrade' => (bool) $is_target_downgrade,
			'is_leaving_plan'      => (bool) $is_leaving_current_plan,
		];
	}

	/**
	 * The "N day free trial" notice for a level, from the PMPro Subscription Delays Add On.
	 *
	 * Shown strictly to visitors who hold no paid plan — to an existing member the delay is not on
	 * offer, so advertising it there would be a lie.
	 *
	 * @param int $level_id
	 * @return string HTML, or '' when no numeric delay is configured (or the visitor already pays).
	 */
	public function get_trial_notice($level_id)
	{
		$context = $this->get_membership_context();

		if ($context['user_max_base_price'] != 0) {
			return '';
		}

		$trial_days = get_option('pmpro_subscription_delay_' . (int) $level_id, '');
		if (empty($trial_days) || ! is_numeric($trial_days)) {
			return '';
		}

		return '<div class="dd-fc-trial-text"><span>' . esc_html($trial_days) . ' day <i>free</i> trial</span></div>';
	}

	/**
	 * Renders the shortcode output.
	 *
	 * The markup is the shared grid owned by DD_Feature_Comparison_Table — same columns, feature
	 * rows, sticky header and mobile tabs as the comparison table, authored on the one "Pricing
	 * Tables" settings tab — rendered in "pricing mode" so each PMPro column picks up the
	 * membership-aware button state and Current Plan badge this class resolves above.
	 *
	 * The dependency between the two modules is bidirectional (the comparison table already calls
	 * this class's get_annual_payment_plan()), but both directions resolve at render time behind
	 * class_exists(), so the functions.php require order stays irrelevant.
	 *
	 * @param array $atts Shortcode attributes. 'exclude' is a comma-separated list of column keys to
	 *                    omit — the Pricing Table Elementor widget's "Hide Plans" control writes the
	 *                    free Trial column here.
	 * @return string
	 */
	public function render_pricing_table($atts)
	{
		if (! defined('PMPRO_VERSION')) {
			return '<p>Paid Memberships Pro is required for the pricing table to function.</p>';
		}

		if (! class_exists('DD_Feature_Comparison_Table') || ! DD_Feature_Comparison_Table::instance()) {
			return '';
		}

		$atts    = shortcode_atts(['exclude' => ''], $atts, 'dd_pricing_table');
		$exclude = array_values(array_filter(array_map('sanitize_key', explode(',', (string) $atts['exclude']))));

		return DD_Feature_Comparison_Table::instance()->render_table([
			'exclude'      => $exclude,
			'pricing_mode' => true,
		]);
	}
}

new DD_PMPro_Frontend_Pricing();
