<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Plugin Name: PMPro Dynamic Pricing Toggle Shortcode
 * Description: Provides a shortcode [dd_pricing_table] to dynamically display PMPro levels in a toggleable Monthly/Yearly card format. Automatically detects the default (Monthly) level and pairs it with its "Annual" Payment Plan extension. Allows switching between plans, disables owned plans on checkout, and syncs trial text across plans.
 * Version: 1.0.13
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
	 * Initializes the shortcode registration, admin menu hooks, and frontend footer scripts during the WordPress lifecycle.
	 */
	public function __construct()
	{
		add_action('init', [$this, 'register_pricing_shortcode']);
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'register_plugin_settings']);
		add_action('wp_footer', [$this, 'modify_checkout_plans_dom']);
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
	 * Injects a robust MutationObserver script on the PMPro Checkout page.
	 * Handles DOM parsing securely to prevent duplicate trial-text appending and ensures the current active plan remains locked out during plan transitions.
	 * @return void
	 */
	public function modify_checkout_plans_dom()
	{
		global $pmpro_pages;

		// Ensure we are strictly on the PMPro checkout page
		if (empty($pmpro_pages['checkout']) || !is_page($pmpro_pages['checkout'])) {
			return;
		}

		$level_id = isset($_REQUEST['level']) ? (int) $_REQUEST['level'] : 0;
		$user_id  = get_current_user_id();

		if (!$level_id) {
			return;
		}

		// Retrieve the precise radio button value the user currently owns (returns false if guest/unowned)
		$owned_plan_value = $this->get_user_active_plan_value($user_id, $level_id);

		?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				
				const ownedValue = "<?php echo esc_js($owned_plan_value); ?>";
				
				const processCheckoutDOM = function() {
					// Feature 1: Disable Current Plan Logic resilient against plan switches
					if (ownedValue) {
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
					if (labels.length > 1) {
						// Extract trial text from the base monthly plan (which inherently respects the Subscription Delays Add On)
						const baseLabel = labels[0];
						const trialMatch = baseLabel.innerHTML.match(/(after your .*? trial\.?)/i);
						
						if (trialMatch && trialMatch[1]) {
							let trialText = trialMatch[1].trim();
							// Ensure the cloned text ends gracefully
							if (!trialText.endsWith('.')) { trialText += '.'; }

							// Iterate through remaining dynamically generated plans (e.g., Annual)
							for (let i = 1; i < labels.length; i++) {
								// Prevent infinite string looping by strictly checking lowercase representation
								if (!labels[i].innerHTML.toLowerCase().includes('trial')) {
									// Strip the trailing period from the original string and append the trial text
									labels[i].innerHTML = labels[i].innerHTML.replace(/\.$/, '').trim() + ' ' + trialText;
								}
							}
						}
					}
				};

				// Execute immediately in case elements are already parsed
				processCheckoutDOM();

				// Attach a MutationObserver to instantly intercept and mutate nodes injected by the PMPro Payment Plans Addon
				const targetNode = document.body; 
				const config = { childList: true, subtree: true };

				const observer = new MutationObserver(function(mutationsList) {
					for (const mutation of mutationsList) {
						if (mutation.type === 'childList') {
							processCheckoutDOM();
						}
					}
				});

				observer.observe(targetNode, config);
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
			'id'    => $level->id, 
			'price' => pmpro_formatPrice((float)$price), 
			'url'   => pmpro_url('checkout', '?level=' . $level->id)
		];
	}

	/**
	 * Constructs the HTML for individual pricing cards.
	 * Compiles pricing data, dynamic URLs, and current ownership status into the interactive card layout.
	 * Identifies exact plan ownership mathematically to allow cross-plan switching on the same level.
	 * @param string $name The level name.
	 * @param string $description The custom description text.
	 * @param int $level_id The primary PMPro Level ID (Default/Monthly).
	 * @param array $annual_plan Array containing the Payment Plan ID, formatted price, and raw price.
	 * @return string The generated HTML markup.
	 */
	private function build_pricing_card($name, $description, $level_id, $annual_plan)
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
		
		// If they own the current view, disable it. If they own the inverse view, offer a switch. Otherwise, standard upgrade.
		$btn_text          = $owns_current_view ? 'CURRENT PLAN' : ($owns_other_view ? 'SWITCH PLAN' : 'UPGRADE PLAN');
		$btn_class         = $owns_current_view ? 'dd-btn dd-checkout-btn dd-btn-disabled' : 'dd-btn dd-checkout-btn';
		$current_url       = $owns_current_view ? '' : ($show_annual_default ? $annual_data['url'] : $monthly_data['url']);

		ob_start();
	?>
		<div class="<?php echo esc_attr($card_class); ?>"
			data-price-monthly="<?php echo esc_attr($monthly_data['price']); ?>"
			data-url-monthly="<?php echo esc_url($monthly_data['url']); ?>"
			data-owns-monthly="<?php echo $owns_monthly ? 'true' : 'false'; ?>"
			data-price-annual="<?php echo esc_attr($annual_data['price']); ?>"
			data-url-annual="<?php echo esc_url($annual_data['url']); ?>"
			data-owns-annual="<?php echo $owns_annual ? 'true' : 'false'; ?>">
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
				margin-bottom: 2rem;
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
					echo $this->build_pricing_card($pair['name'], $description, $pair['monthly_id'], $pair['annual_plan']);
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

							// Update visual price based on toggle state
							priceEl.innerHTML = isYearly ? card.getAttribute('data-price-annual') : card.getAttribute('data-price-monthly');
							
							const userOwnsSelectedView = isYearly ? ownsAnnual : ownsMonthly;
							const userOwnsOtherView    = isYearly ? ownsMonthly : ownsAnnual;

							// Evaluate button state based on explicit ownership
							if (userOwnsSelectedView) {
								btnEl.textContent = 'CURRENT PLAN';
								btnEl.classList.add('dd-btn-disabled');
								btnEl.removeAttribute('href');
							} else {
								// Trigger 'SWITCH PLAN' if moving within same level but different term, else 'UPGRADE PLAN'
								btnEl.textContent = userOwnsOtherView ? 'SWITCH PLAN' : 'UPGRADE PLAN';
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