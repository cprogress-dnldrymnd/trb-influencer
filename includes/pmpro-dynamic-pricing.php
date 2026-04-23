<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Plugin Name: PMPro Dynamic Pricing Toggle Shortcode
 * Description: Provides a shortcode [dd_pricing_table] to dynamically display PMPro levels in a toggleable Monthly/Yearly card format. Automatically detects the default (Monthly) level and pairs it with its "Annual" Payment Plan extension. Allows switching between plans, disables owned plans, locks plan changes during free trials (both UI and URL access), adds dynamic trial notices via the Subscription Delays Add On, and cleans up broken Payment Plan injections on non-checkout pages.
 * Version: 1.0.26
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-pricing
 */

/**
 * Class DD_PMPro_Frontend_Pricing
 * Handles the registration of the admin settings interface, dynamic Payment Plan extraction, data retrieval, pricing table rendering, and resilient checkout page DOM manipulation.
 */
class DD_PMPro_Frontend_Pricing
{

	/**
	 * Constructor.
	 * Initializes the shortcode registration, admin menu hooks, frontend footer scripts, and page redirects during the WordPress lifecycle.
	 */
	public function __construct()
	{
		add_action('init', [$this, 'register_pricing_shortcode']);
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'register_plugin_settings']);
		add_action('wp_footer', [$this, 'modify_checkout_plans_dom']);
		add_action('template_redirect', [$this, 'prevent_checkout_during_trial']); // URL security layer
		add_action('template_redirect', [$this, 'prevent_checkout_for_pending_downgrade']); // Block checkout on pending-downgrade target level
		add_action('wp_footer', [$this, 'influencer_style_pmpro_checkout'], 50); // Influencer UI Override
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
	 * Retrieves and pairs PMPro levels dynamically with their corresponding Payment Plans.
	 * Identifies active levels (Default/Monthly) and extracts the "Annual" payment plan attached to them.
	 * @return array Array of dynamically paired level and payment plan data.
	 */
	private function get_dynamic_plan_pairs()
	{
		if (! function_exists('pmpro_getAllLevels')) {
			return [];
		}

		$all_levels = pmpro_getAllLevels(true, true);
		$pairs = [];

		foreach ($all_levels as $level) {
			if (! $level->allow_signups) {
				continue;
			}

			$name = trim($level->name);

			// Extract the Annual payment plan extension for this base level
			$annual_plan = $this->get_annual_payment_plan($level->id);

			// Only render the toggle if an Annual Payment Plan is actively configured for this level
			if ($annual_plan) {
				$pairs[] = [
					'name'        => $name,
					'monthly_id'  => $level->id,
					'annual_plan' => $annual_plan,
					'option_key'  => 'dd_desc_' . sanitize_key($name),
				];
			}
		}

		return $pairs;
	}

	/**
	 * Retrieves the 'Annual' payment plan data for a given PMPro level.
	 * Scans all level meta to guarantee extraction regardless of the specific meta key used by the add-on.
	 * @param int $level_id The PMPro Level ID.
	 * @return array|false Returns an array containing the 'id' (formatted for checkout), 'price' (formatted), and 'raw_price' (float) or false if undetected.
	 */
	private function get_annual_payment_plan($level_id)
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
	 * Acts as a global DOM cleaner to remove broken Payment Plan injections on non-checkout pages (like the signup shortcode).
	 * Handles DOM parsing securely to prevent duplicate trial-text appending and ensures the current active plan remains locked out during plan transitions.
	 * @return void
	 */
	public function modify_checkout_plans_dom()
	{
		global $pmpro_pages;

		// 1. NON-CHECKOUT PAGE CLEANUP (Fixes the empty box on [pmpro_signup] pages)
		if (empty($pmpro_pages['checkout']) || !is_page($pmpro_pages['checkout'])) {
?>
			<script>
				document.addEventListener('DOMContentLoaded', function() {
					const ppContainer = document.getElementById('pmpropp_payment_plans');
					if (ppContainer) {
						ppContainer.style.display = 'none';

						// Remove the rogue "Select a Payment Plan" heading
						let prev = ppContainer.previousElementSibling;
						if (prev && prev.textContent.toLowerCase().includes('payment plan')) {
							prev.style.display = 'none';
						}
					}

					// Catch any stray headings injected by the Add On independently
					const headings = document.querySelectorAll('h2, h3, h4, label, legend, p');
					headings.forEach(h => {
						if (h.textContent.trim() === 'Select a Payment Plan') {
							h.style.display = 'none';
						}
					});
				});
			</script>
		<?php
			return; // Abort further execution as we are not on the true checkout page
		}

		// 2. CHECKOUT PAGE MUTATION LOGIC
		$level_id = isset($_REQUEST['level']) ? (int) $_REQUEST['level'] : 0;
		$user_id  = get_current_user_id();

		if (!$level_id) {
			return;
		}

		// Retrieve the precise radio button value the user currently owns (returns false if guest/unowned)
		$owned_plan_value = $this->get_user_active_plan_value($user_id, $level_id);
		$is_on_free_trial = $this->is_user_on_free_trial($user_id);

		?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {

				const ownedValue = "<?php echo esc_js($owned_plan_value); ?>";
				const isOnTrial = <?php echo $is_on_free_trial ? 'true' : 'false'; ?>;

				const processCheckoutDOM = function() {

					let gateway = document.querySelector('input[name=gateway]:checked');
					gateway = gateway ? gateway.value : (document.getElementById('gateway') ? document.getElementById('gateway').value : '');

					// Feature 0: If currently on a trial, entirely lock down the checkout logic
					if (isOnTrial) {
						const allRadios = document.querySelectorAll('input[name="pmpropp_chosen_plan"]');
						allRadios.forEach(radio => {
							radio.disabled = true;
							const label = document.querySelector('label[for="' + radio.id + '"]');
							if (label && !label.classList.contains('dd-plan-disabled')) {
								label.classList.add('dd-plan-disabled');
								label.style.opacity = '0.5';
								label.style.cursor = 'not-allowed';
								if (ownedValue && radio.value === ownedValue) {
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
					// Feature 1: Disable Current Plan Logic resilient against plan switches
					else if (ownedValue) {
						const radioBtn = document.querySelector('input[name="pmpropp_chosen_plan"][value="' + ownedValue + '"]');
						if (radioBtn && !radioBtn.disabled) {
							radioBtn.disabled = true;

							const label = document.querySelector('label[for="' + radioBtn.id + '"]');
							if (label && !label.classList.contains('dd-plan-disabled')) {
								label.classList.add('dd-plan-disabled');
								label.style.opacity = '0.5';
								label.style.cursor = 'not-allowed';
								label.innerHTML += ' <span style="color:red; font-size:0.9em; margin-left:5px;">(Current Plan)</span>';
							}
						}
					}

					// Feature 2: Sync Trial Text to Payment Plans securely
					const labels = document.querySelectorAll('.pmpro_form_field-radio-item label');
					if (labels.length > 0) {
						// Extract trial text from the base monthly plan (which inherently respects the Subscription Delays Add On)
						const baseLabel = labels[0];

						if (!baseLabel.hasAttribute('data-original-html')) {
							baseLabel.setAttribute('data-original-html', baseLabel.innerHTML);
						}

						const trialMatch = baseLabel.getAttribute('data-original-html').match(/(after your .*? trial\.?)/i);

						for (let i = 0; i < labels.length; i++) {
							if (!labels[i].hasAttribute('data-original-html')) {
								labels[i].setAttribute('data-original-html', labels[i].innerHTML);
							}

							if (gateway === 'check') {
								// Strip trial text entirely from ALL radio buttons if paying by check
								labels[i].innerHTML = labels[i].getAttribute('data-original-html').replace(/\s*after your .*? trial\.?/gi, '');
							} else {
								// Append/keep trial text normally for Stripe
								if (trialMatch && trialMatch[1]) {
									let trialText = trialMatch[1].trim();
									if (!trialText.endsWith('.')) {
										trialText += '.';
									}

									if (i > 0 && !labels[i].getAttribute('data-original-html').toLowerCase().includes('trial')) {
										labels[i].innerHTML = labels[i].getAttribute('data-original-html').replace(/\.$/, '').trim() + ' ' + trialText;
									} else {
										labels[i].innerHTML = labels[i].getAttribute('data-original-html');
									}
								} else {
									labels[i].innerHTML = labels[i].getAttribute('data-original-html');
								}
							}
						}
					}
				};

				// Execute immediately in case elements are already parsed
				processCheckoutDOM();

				// Ensure re-check on gateway changes
				document.body.addEventListener('change', function(e) {
					if (e.target.name === 'gateway' || e.target.id === 'gateway') {
						processCheckoutDOM();
					}
				});

				// Attach a MutationObserver to instantly intercept and mutate nodes injected by the PMPro Payment Plans Addon
				const targetNode = document.body;
				const config = {
					childList: true,
					subtree: true
				};

				const observer = new MutationObserver(function(mutationsList) {
					// BUG FIX: Temporarily pause the observer to prevent infinite loop
					observer.disconnect();
					let hasChildListMutation = false;
					for (const mutation of mutationsList) {
						if (mutation.type === 'childList') {
							hasChildListMutation = true;
							break;
						}
					}
					if (hasChildListMutation) {
						processCheckoutDOM();
					}
					// Resume observer
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
				$payment_reason = 'Free entry';
			}
		} elseif (isset($pmpro_level->billing_amount) && $paying_now > 0 && $paying_now < (float)$pmpro_level->billing_amount) {
			// Context: User is upgrading; the cost is a monetary proration
			$payment_reason = 'Prorated upgrade cost';
		}

		$start_date_str = "Now";
		if (!empty($pmpro_level->profile_start_date)) {
			$start_date_str = date_i18n(get_option('date_format'), strtotime($pmpro_level->profile_start_date));
		}

		// Safely get the dynamic Membership Levels page URL for the "Change plan" link
		$levels_url = function_exists('pmpro_url') ? pmpro_url('levels') : '/membership-levels/';

		// 3. DEFINE YOUR GLOBAL PLAN DETAILS HERE
		$dynamic_plan_details = [
			'default' => [
				'account_type' => '1 Account',
				'bullets' => [
					'Enjoy unlimited access to your selected plan features.',
					'From the starting date shown, you\'ll be charged for your updated subscription.',
					'Cancel anytime online. <a href="/terms-of-use/">Terms apply</a>.'
				]
			]
		];

		// Execute your custom avatar shortcode safely
		$avatar_html = do_shortcode('[user_avatar]');
	?>
		<style>
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

			.infl-content span {
				font-size: 14px;
				color: #b3b3b3;
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
                                    <h4>${recurringPrice} </h4>
                                    <span>/${cycle}</span>
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
                                        <p><strong>Starting ${dynamicStartDate}:</strong> ${recurringPrice} /${cycle}</p>
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
								$('.dd-paying-now-val').html(recurringPrice);
								$('.dd-paying-now-reason').text('Standard initial payment (Trial disabled)');
								$('#dd-timeline-later').hide();
							} else {
								$('.dd-paying-now-val').html(dynamicPayingNow);
								$('.dd-paying-now-reason').text(paymentReason);
								$('#dd-timeline-later').show();
							}

							// B) Scrub native Check Instructions
							$('.pmpro_checkout-instructions-check, .pmpro_check_instructions').each(function() {
								var $el = $(this);
								if (typeof $el.data('dd-original-html') === 'undefined') {
									$el.data('dd-original-html', $el.html());
								}
								if (gateway === 'check') {
									var cleanHtml = $el.data('dd-original-html').replace(/\s*after your .*? trial\.?/gi, '');
									$el.html(cleanHtml);
								} else {
									$el.html($el.data('dd-original-html'));
								}
							});
						}

						ddHandleGatewaySwitch(); // Initial run
						$(document).on('change', 'input[name=gateway], #gateway', ddHandleGatewaySwitch);

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
										$(this).html(txt.replace(/\s*after your .*? trial\.?/gi, ''));
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
	 * Registers the backend submenu page under the PMPro Dashboard.
	 * @return void
	 */
	public function register_admin_menu()
	{
		if (! defined('PMPRO_VERSION')) {
			return;
		}

		add_submenu_page(
			'pmpro-dashboard',
			'Pricing Table Settings',
			'Pricing Settings',
			'manage_options',
			'dd-pricing-settings',
			[$this, 'render_settings_page']
		);
	}

	/**
	 * Registers the settings, sections, and fields dynamically for the WordPress Settings API.
	 * Secures the data in the wp_options table for dynamic plans and the custom CTA card.
	 * @return void
	 */
	public function register_plugin_settings()
	{
		$pairs = $this->get_dynamic_plan_pairs();

		// Register dynamically generated options for PMPro plans
		foreach ($pairs as $pair) {
			register_setting('dd_pricing_settings_group', $pair['option_key']);
		}

		// Section 1: Dynamic Plan Descriptions
		add_settings_section(
			'dd_pricing_main_section',
			'Dynamic Plan Descriptions',
			function () {
				echo '<p>Update the descriptions displayed on the frontend pricing table shortcode.</p>';
			},
			'dd-pricing-settings'
		);

		foreach ($pairs as $pair) {
			add_settings_field(
				$pair['option_key'] . '_field',
				esc_html($pair['name']) . ' Plan Description',
				[$this, 'render_textarea_field'],
				'dd-pricing-settings',
				'dd_pricing_main_section',
				[
					'label_for' => $pair['option_key'],
					'name'      => $pair['option_key'],
					'default'   => 'Discover features included in the ' . esc_html($pair['name']) . ' plan.',
				]
			);
		}

		// Register static options for Custom CTA Card
		$cta_options = ['dd_cta_enable', 'dd_cta_heading', 'dd_cta_desc', 'dd_cta_btn_text', 'dd_cta_btn_link'];
		foreach ($cta_options as $opt) {
			register_setting('dd_pricing_settings_group', $opt);
		}

		// Section 2: Custom CTA Card
		add_settings_section(
			'dd_pricing_cta_section',
			'Custom CTA Card (e.g., Scale)',
			function () {
				echo '<p>Configure the static card that appears at the end of the pricing table.</p>';
			},
			'dd-pricing-settings'
		);

		add_settings_field('dd_cta_enable', 'Enable CTA Card', [$this, 'render_checkbox_field'], 'dd-pricing-settings', 'dd_pricing_cta_section', ['name' => 'dd_cta_enable']);
		add_settings_field('dd_cta_heading', 'Heading', [$this, 'render_text_field'], 'dd-pricing-settings', 'dd_pricing_cta_section', ['name' => 'dd_cta_heading', 'default' => 'Scale']);
		add_settings_field('dd_cta_desc', 'Description', [$this, 'render_textarea_field'], 'dd-pricing-settings', 'dd_pricing_cta_section', ['name' => 'dd_cta_desc', 'default' => 'Manage multiple campaigns enjoy limit-free usage.']);
		add_settings_field('dd_cta_btn_text', 'Button Text', [$this, 'render_text_field'], 'dd-pricing-settings', 'dd_pricing_cta_section', ['name' => 'dd_cta_btn_text', 'default' => 'ENQUIRE NOW']);
		add_settings_field('dd_cta_btn_link', 'Button Link', [$this, 'render_text_field'], 'dd-pricing-settings', 'dd_pricing_cta_section', ['name' => 'dd_cta_btn_link', 'default' => '/contact']);
	}

	/**
	 * Field Renderers
	 */
	public function render_textarea_field($args)
	{
		$option_value = get_option($args['name'], $args['default'] ?? '');
		echo '<textarea id="' . esc_attr($args['name']) . '" name="' . esc_attr($args['name']) . '" rows="4" class="regular-text">' . esc_textarea($option_value) . '</textarea>';
	}

	public function render_text_field($args)
	{
		$option_value = get_option($args['name'], $args['default'] ?? '');
		echo '<input type="text" id="' . esc_attr($args['name']) . '" name="' . esc_attr($args['name']) . '" value="' . esc_attr($option_value) . '" class="regular-text" />';
	}

	public function render_checkbox_field($args)
	{
		$option_value = get_option($args['name']);
		echo '<input type="checkbox" id="' . esc_attr($args['name']) . '" name="' . esc_attr($args['name']) . '" value="1" ' . checked(1, $option_value, false) . ' />';
	}

	/**
	 * Renders the HTML wrapper for the backend settings page.
	 * @return void
	 */
	public function render_settings_page()
	{
		if (! current_user_can('manage_options')) {
			return;
		}
	?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('dd_pricing_settings_group');
				do_settings_sections('dd-pricing-settings');
				submit_button('Save Pricing Table Settings');
				?>
			</form>
		</div>
	<?php
	}

	/**
	 * Data retrieval and standard card rendering logic
	 * Includes raw_price for tier hierarchy evaluation.
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

		// Pivot to billing_amount if initial_payment is 0 (supports structural deferred billing)
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
	 * Constructs the HTML for individual pricing cards.
	 * Compiles pricing data, dynamic URLs, and current ownership status into the interactive card layout.
	 * Identifies exact plan ownership mathematically to allow cross-plan switching on the same level.
	 * @param string $name The level name.
	 * @param string $description The custom description text.
	 * @param int $level_id The primary PMPro Level ID (Default/Monthly).
	 * @param array $annual_plan Array containing the Payment Plan ID, formatted price, and raw price.
	 * @param bool $is_on_free_trial Dictates if the user is locked out due to a trial state.
	 * @return string The generated HTML markup.
	 */
	private function build_pricing_card($name, $description, $level_id, $annual_plan, $is_on_free_trial = false, $pending_downgrade_level_id = false)
	{
		$monthly_data = $this->get_level_data($level_id);

		if (! $monthly_data || ! $annual_plan) {
			return '';
		}

		$annual_data = [
			'price' => $annual_plan['price'],
			// Use the correct `pmpropp_chosen_plan` parameter required by the Add On
			'url'   => pmpro_url('checkout', '?level=' . $level_id . '&pmpropp_chosen_plan=' . $annual_plan['id'])
		];

		$current_user_id = get_current_user_id();
		$owns_monthly    = false;
		$owns_annual     = false;

		// Fetch the active plan value mapped to this user and translate it to boolean states
		$owned_plan_value = $this->get_user_active_plan_value($current_user_id, $level_id);
		if ($owned_plan_value) {
			if ($owned_plan_value === $annual_plan['id']) {
				$owns_annual = true;
			} else {
				$owns_monthly = true;
			}
		}

		$has_any_plan = $owns_monthly || $owns_annual;
		$card_class = $has_any_plan ? 'dd-card dd-card-active' : 'dd-card';
		$badge_html = $has_any_plan ? '<div class="dd-badge">CURRENT PLAN</div>' : '';

		// Automatically default the toggle view to the user's active plan (if they own one)
		$show_annual_default = $owns_annual;
		$toggle_checked      = $show_annual_default ? 'checked' : '';
		$current_price       = $show_annual_default ? $annual_data['price'] : $monthly_data['price'];

		$owns_current_view = $show_annual_default ? $owns_annual : $owns_monthly;
		$owns_other_view   = $show_annual_default ? $owns_monthly : $owns_annual;

		// Determine Upgrade vs Downgrade based on tier hierarchy
		$user_max_base_price = $this->get_user_max_tier_base_price($current_user_id);
		$card_base_price     = $monthly_data['raw_price'];

		if ($user_max_base_price > 0) {
			$action_verb = ($card_base_price < $user_max_base_price) ? 'DOWNGRADE PLAN' : 'UPGRADE PLAN';
		} else {
			$action_verb = 'SELECT PLAN';
		}

		// Inject dynamic free trial text strictly for users with no plan
		$trial_text_html = '';
		if ($user_max_base_price == 0) {
			// Fetch dynamic trial days from PMPro Subscription Delays Add-on
			$trial_days = get_option('pmpro_subscription_delay_' . $level_id, '');

			// Only display if a numeric delay is explicitly set
			if (!empty($trial_days) && is_numeric($trial_days)) {
				$trial_text_html = '<div class="dd-trial-text"><span>' . esc_html($trial_days) . ' day <i>free</i> trial</span></div>';
			}
		}

		// Evaluate Structural Lockdown States
		$is_target_downgrade = ($pending_downgrade_level_id && (int)$pending_downgrade_level_id === (int)$level_id);
		$is_leaving_current_plan = ($pending_downgrade_level_id && $has_any_plan);

		// Implement robust lock out evaluation based on states
		if ($is_on_free_trial) {
			if ($owns_current_view) {
				$btn_text = 'CURRENT PLAN (TRIAL)';
			} else {
				$btn_text = 'LOCKED DURING TRIAL';
			}
			$btn_class   = 'dd-btn dd-checkout-btn dd-btn-disabled';
			$current_url = '';
		} elseif ($is_target_downgrade) {
			// This card is the target of a scheduled delayed downgrade — block checkout entirely
			$btn_text    = 'PENDING DOWNGRADE';
			$btn_class   = 'dd-btn dd-checkout-btn dd-btn-disabled';
			$current_url = '';
		} elseif ($is_leaving_current_plan) {
			// The user is actively leaving their current plan via a downgrade, lock other payment options to prevent errors
			$btn_text    = $owns_current_view ? 'CURRENT PLAN' : 'CHANGES LOCKED';
			$btn_class   = 'dd-btn dd-checkout-btn dd-btn-disabled';
			$current_url = '';
		} else {
			$btn_text    = $owns_current_view ? 'CURRENT PLAN' : ($owns_other_view ? 'SWITCH PLAN' : $action_verb);
			$btn_class   = $owns_current_view ? 'dd-btn dd-checkout-btn dd-btn-disabled' : 'dd-btn dd-checkout-btn';
			$current_url = $owns_current_view ? '' : ($show_annual_default ? $annual_data['url'] : $monthly_data['url']);
		}

		ob_start();
	?>
		<div class="<?php echo esc_attr($card_class); ?>"
			data-price-monthly="<?php echo esc_attr($monthly_data['price']); ?>"
			data-url-monthly="<?php echo esc_url($monthly_data['url']); ?>"
			data-owns-monthly="<?php echo $owns_monthly ? 'true' : 'false'; ?>"
			data-price-annual="<?php echo esc_attr($annual_data['price']); ?>"
			data-url-annual="<?php echo esc_url($annual_data['url']); ?>"
			data-owns-annual="<?php echo $owns_annual ? 'true' : 'false'; ?>"
			data-action-verb="<?php echo esc_attr($action_verb); ?>"
			data-is-on-trial="<?php echo $is_on_free_trial ? 'true' : 'false'; ?>"
			data-is-pending-downgrade="<?php echo $is_target_downgrade ? 'true' : 'false'; ?>"
			data-is-leaving-plan="<?php echo $is_leaving_current_plan ? 'true' : 'false'; ?>">
			<?php echo wp_kses_post($badge_html); ?>
			<h3 class="dd-plan-name"><?php echo esc_html($name); ?></h3>
			<div class="dd-plan-desc"><?php echo do_shortcode($description) ?></div>
			<div class="dd-price-wrapper"><span class="dd-price-amount"><?php echo wp_kses_post($current_price); ?></span></div>
			<div class="dd-toggle-wrapper">
				<label class="dd-switch">
					<input type="checkbox" class="dd-plan-toggle" <?php echo esc_attr($toggle_checked); ?>>
					<span class="dd-slider round"></span>
				</label>
				<span class="dd-toggle-label">Yearly</span>
				<span class="dd-discount">Save 20%</span>
			</div>
			<?php echo wp_kses_post($trial_text_html); ?>
			<a <?php echo $current_url ? 'href="' . esc_url($current_url) . '"' : ''; ?> class="<?php echo esc_attr($btn_class); ?>"><?php echo esc_html($btn_text); ?></a>
		</div>
	<?php
		return ob_get_clean();
	}

	/**
	 * Renders the shortcode output
	 */
	public function render_pricing_table($atts)
	{
		if (! defined('PMPRO_VERSION')) {
			return '<p>Paid Memberships Pro is required for the pricing table to function.</p>';
		}

		// Calculate global trial state for the active user once
		$current_user_id = get_current_user_id();
		$is_on_free_trial = $current_user_id ? $this->is_user_on_free_trial($current_user_id) : false;
		$user_max_base_price = $current_user_id ? $this->get_user_max_tier_base_price($current_user_id) : 0.00;
		$pending_downgrade_level_id = $current_user_id ? $this->get_pending_downgrade_level_id($current_user_id) : false;

		ob_start();
	?>
		<style>
			.dd-pricing-container {
				display: grid;
				gap: 2rem;
				grid-template-columns: repeat(3, 1fr);
				font-family: Inter;
			}

			.dd-card {
				background: var(--e-global-color-2ba2932);
				border-radius: 20px;
				padding: 55px clamp(20px, 2vw, 40px) clamp(20px, 2vw, 40px);
				position: relative;
				display: flex;
				flex-direction: column;
				border: 4px solid var(--e-global-color-2ba2932);
				font-family: Work Sans, sans-serif;
				font-size: clamp(12px, 0.938vw, 18px);

			}

			.dd-card:hover {
				border-color: var(--e-global-color-primary);
			}

			.dd-card-active {
				border-color: var(--e-global-color-secondary);
			}

			.dd-badge {
				position: absolute;
				top: -15px;
				left: 50%;
				transform: translateX(-50%);
				background: #ffe270;
				padding: 14px 22px;
				border-radius: 20px;
				font-size: 22px;
				font-weight: 600;
				max-width: 300px;
				width: 100%;
				text-align: center;
			}

			.dd-plan-name.dd-plan-name.dd-plan-name {
				font-size: clamp(22px, 1.927vw, 37px);
				color: var(--e-global-color-secondary);
				margin: 0 0 20px;
			}

			.dd-plan-desc {
				margin-bottom: 1.5rem;
				flex-grow: 1;
			}

			.dd-price-wrapper {
				font-size: clamp(22px, 1.927vw, 37px);
				font-weight: bold;
				color: var(--e-global-color-secondary);
				margin-bottom: 1rem;
			}

			.dd-discount {
				background-color: #ABFFB6;
				padding: 0px 10px 0px 10px;
				border-radius: 50px 50px 50px 50px;
				font-size: var(--e-global-typography-0ffc5c1-font-size);
				font-weight: var(--e-global-typography-0ffc5c1-font-weight);
				line-height: var(--e-global-typography-0ffc5c1-line-height);
				color: var(--e-global-color-primary);
			}

			.dd-toggle-wrapper {
				display: flex;
				align-items: center;
				gap: 10px;
				margin-bottom: 1.5rem;
				/* Adjusted for trial text */
			}

			.dd-switch {
				position: relative;
				display: inline-block;
				width: 60px;
				height: 34px;
			}

			.dd-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}


			.dd-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #ccc;
				transition: .4s;
			}

			.dd-slider:before {
				position: absolute;
				content: "";
				height: 26px;
				width: 26px;
				left: 4px;
				bottom: 4px;
				background-color: white;
				transition: .4s;
			}

			input:checked+.dd-slider {
				background-color: var(--e-global-color-accent);
				;
			}

			input:checked+.dd-slider:before {
				transform: translateX(26px);
			}

			.dd-slider.round {
				border-radius: 34px;
			}

			.dd-slider.round:before {
				border-radius: 50%;
			}

			.dd-trial-text {
				font-size: 14px;
				margin-bottom: 15px;
				font-weight: 500;
			}

			.dd-trial-text span {
				background-color: var(--e-global-color-secondary);
				padding: 10px;
				display: inline-block;
				border-radius: 5px;
				font-weight: 600;
				color: #fef6f3;
				letter-spacing: 0.2px;
			}

			.dd-trial-text i {
				font-style: italic;
			}

			.dd-btn {
				background-color: var(--e-global-color-accent);
				font-size: var(--e-global-typography-accent-font-size);
				font-weight: var(--e-global-typography-accent-font-weight);
				letter-spacing: var(--e-global-typography-accent-letter-spacing);
				padding: clamp(10px, 1.25vw, 24px) clamp(15px, 1.563vw, 30px);
				color: var(--e-global-color-2ba2932);
				text-align: center;
				border-radius: 5px;
				transition: 400ms;
			}

			.dd-btn:hover {
				background: var(--e-global-color-secondary);
			}

			.dd-btn-disabled {
				background: #ffbbae;
				pointer-events: none;
				cursor: not-allowed;
			}

			.dd-pricing-disclaimer {
				margin-top: 2.5rem;
				text-align: center;
				font-family: Inter;
				font-size: 14px;
				color: var(--e-global-color-secondary);
				margin-left: auto;
				margin-right: auto;
				line-height: 1.5;
			}

			@media(max-width: 1300px) {
				.dd-pricing-container {
					grid-template-columns: repeat(2, 1fr);
				}
			}

			@media(max-width: 767px) {
				.dd-pricing-container {
					grid-template-columns: repeat(1, 1fr);
				}
			}
		</style>

		<div class="dd-pricing-container">
			<?php
			$pairs = $this->get_dynamic_plan_pairs();

			if (empty($pairs)) {
				echo '<p>No configured Monthly levels with an active Annual payment plan were detected.</p>';
			} else {
				foreach ($pairs as $pair) {
					$default_desc = 'Discover features included in the ' . esc_html($pair['name']) . ' plan.';
					$description  = get_option($pair['option_key'], $default_desc);
					echo $this->build_pricing_card($pair['name'], $description, $pair['monthly_id'], $pair['annual_plan'], $is_on_free_trial, $pending_downgrade_level_id);
				}
			}

			// Render Custom CTA Card if enabled
			if (get_option('dd_cta_enable')) {
				$cta_heading = get_option('dd_cta_heading', 'Scale');
				$cta_desc    = get_option('dd_cta_desc', 'Manage multiple campaigns enjoy limit-free usage.');
				$cta_btn     = get_option('dd_cta_btn_text', 'ENQUIRE NOW');
				$cta_link    = get_option('dd_cta_btn_link', '/contact');
			?>
				<div class="dd-card">
					<h3 class="dd-plan-name"><?php echo esc_html($cta_heading); ?></h3>
					<div class="dd-plan-desc"><?php echo do_shortcode($cta_desc) ?></div>
					<a href="<?php echo esc_url($cta_link); ?>" class="dd-btn"><?php echo esc_html($cta_btn); ?></a>
				</div>
			<?php
			}
			?>
		</div>

		<?php
		// Render global disclaimer strictly for new members OR members actively on a trial lock.
		if ($user_max_base_price == 0 || $is_on_free_trial) {
		?>
			<div class="dd-pricing-disclaimer">
				<strong>Please note:</strong> During the free trial period, you are unable to change your plan. Plan adjustments can be made after your first paid month.
			</div>
		<?php
		}
		?>

		<script>
			(function() {
				function initDDPricingToggles() {
					const toggles = document.querySelectorAll('.dd-plan-toggle');
					toggles.forEach(toggle => {
						if (toggle.dataset.ddBound === 'true') return;
						toggle.dataset.ddBound = 'true';
						toggle.addEventListener('change', function() {
							const card = this.closest('.dd-card');
							const isYearly = this.checked;
							const priceEl = card.querySelector('.dd-price-amount');
							const btnEl = card.querySelector('.dd-checkout-btn');

							const ownsMonthly = card.getAttribute('data-owns-monthly') === 'true';
							const ownsAnnual = card.getAttribute('data-owns-annual') === 'true';
							const actionVerb = card.getAttribute('data-action-verb') || 'SELECT PLAN';

							const isOnTrial = card.getAttribute('data-is-on-trial') === 'true';
							const isTargetDowngrade = card.getAttribute('data-is-pending-downgrade') === 'true';
							const isLeavingPlan = card.getAttribute('data-is-leaving-plan') === 'true';

							// Update visual price based on toggle state
							priceEl.innerHTML = isYearly ? card.getAttribute('data-price-annual') : card.getAttribute('data-price-monthly');

							const userOwnsSelectedView = isYearly ? ownsAnnual : ownsMonthly;
							const userOwnsOtherView = isYearly ? ownsMonthly : ownsAnnual;

							// Feature A: Trial Lockdown
							if (isOnTrial) {
								btnEl.textContent = userOwnsSelectedView ? 'CURRENT PLAN (TRIAL)' : 'LOCKED DURING TRIAL';
								btnEl.classList.add('dd-btn-disabled');
								btnEl.removeAttribute('href');
							}
							// Feature B: Target Downgrade Block (Disable BOTH Monthly and Yearly views of the target plan)
							else if (isTargetDowngrade) {
								btnEl.textContent = 'PENDING DOWNGRADE';
								btnEl.classList.add('dd-btn-disabled');
								btnEl.removeAttribute('href');
							}
							// Feature C: Leaving Current Plan Block (Disable changing terms on a plan actively being left)
							else if (isLeavingPlan) {
								btnEl.textContent = userOwnsSelectedView ? 'CURRENT PLAN' : 'CHANGES LOCKED';
								btnEl.classList.add('dd-btn-disabled');
								btnEl.removeAttribute('href');
							}
							// Evaluate standard button state based on explicit ownership
							else if (userOwnsSelectedView) {
								btnEl.textContent = 'CURRENT PLAN';
								btnEl.classList.add('dd-btn-disabled');
								btnEl.removeAttribute('href');
							}
							// Evaluate valid plan switch
							else {
								// Trigger 'SWITCH PLAN' if moving within same level but different term, else upgrade/downgrade/select verb
								btnEl.textContent = userOwnsOtherView ? 'SWITCH PLAN' : actionVerb;
								btnEl.classList.remove('dd-btn-disabled');
								btnEl.setAttribute('href', isYearly ? card.getAttribute('data-url-annual') : card.getAttribute('data-url-monthly'));
							}
						});
					});
				}
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initDDPricingToggles);
				} else {
					initDDPricingToggles();
				}
			})();
		</script>
<?php
		return ob_get_clean();
	}
}

new DD_PMPro_Frontend_Pricing();
