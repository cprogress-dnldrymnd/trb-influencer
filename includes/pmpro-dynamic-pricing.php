<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Plugin Name: PMPro Dynamic Pricing Toggle Shortcode
 * Description: Provides a shortcode [dd_pricing_table] to dynamically display PMPro levels in a toggleable Monthly/Yearly card format. Automatically detects and pairs levels, and supports a custom CTA card at the end.
 * Version: 1.0.5
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-pricing
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Class DD_PMPro_Frontend_Pricing
 * * Handles the registration of the admin settings interface, dynamic level pairing, data retrieval, and rendering of the pricing table shortcode.
 */
class DD_PMPro_Frontend_Pricing
{

	/**
	 * Constructor.
	 * * Initializes the shortcode registration and admin menu hooks during the WordPress lifecycle.
	 */
	public function __construct()
	{
		add_action('init', [$this, 'register_pricing_shortcode']);
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'register_plugin_settings']);
	}

	/**
	 * Registers the shortcode with WordPress.
	 * * Binds the '[dd_pricing_table]' shortcode to the rendering method of this class.
	 * * @return void
	 */
	public function register_pricing_shortcode()
	{
		add_shortcode('dd_pricing_table', [$this, 'render_pricing_table']);
	}

	/**
	 * Retrieves and pairs PMPro levels dynamically.
	 * * Identifies active levels with identical names and assigns them as Monthly/Annual variants
	 * based on their Group ID (2 for Monthly, 3 for Annual) or falls back to price comparison.
	 * * @return array Array of dynamically paired level data.
	 */
	private function get_dynamic_plan_pairs()
	{
		if (! function_exists('pmpro_getAllLevels')) {
			return [];
		}

		$all_levels = pmpro_getAllLevels(true, true);
		$grouped_by_name = [];

		// Group active, signup-enabled levels by their explicit name
		foreach ($all_levels as $level) {
			if (! $level->allow_signups) {
				continue;
			}
			$grouped_by_name[trim($level->name)][] = $level;
		}

		$pairs = [];

		foreach ($grouped_by_name as $name => $levels) {
			// We only process levels that have both a Monthly and Annual variant (2 or more)
			if (count($levels) >= 2) {
				$monthly_id = 0;
				$annual_id  = 0;

				foreach ($levels as $l) {
					// Extract Group ID if available
					$group_id = isset($l->group_id) ? (int) $l->group_id : 0;

					if ($group_id === 2) {
						$monthly_id = $l->id;
					} elseif ($group_id === 3) {
						$annual_id = $l->id;
					}
				}

				// Fallback mechanism
				if (! $monthly_id || ! $annual_id) {
					usort($levels, function ($a, $b) {
						return (float) $a->initial_payment <=> (float) $b->initial_payment;
					});
					$monthly_id = $levels[0]->id;
					$annual_id  = $levels[1]->id;
				}

				if ($monthly_id && $annual_id) {
					$pairs[] = [
						'name'       => $name,
						'monthly_id' => $monthly_id,
						'annual_id'  => $annual_id,
						'option_key' => 'dd_desc_' . sanitize_key($name),
					];
				}
			}
		}

		return $pairs;
	}

	/**
	 * Registers the backend submenu page under the PMPro Dashboard.
	 * * @return void
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
	 * * Secures the data in the wp_options table for dynamic plans and the custom CTA card.
	 * * @return void
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
	 * * @return void
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
		return ['id' => $level->id, 'price' => pmpro_formatPrice($level->initial_payment), 'url' => pmpro_url('checkout', '?level=' . $level->id)];
	}

	private function build_pricing_card($name, $description, $monthly_id, $annual_id)
	{
		$monthly_data = $this->get_level_data($monthly_id);
		$annual_data  = $this->get_level_data($annual_id);

		if (! $monthly_data || ! $annual_data) {
			return '';
		}

		$current_user_id = get_current_user_id();
		$is_current_monthly = false;
		$is_current_annual  = false;

		if (function_exists('pmpro_getMembershipLevelsForUser') && $current_user_id) {
			$user_levels = pmpro_getMembershipLevelsForUser($current_user_id);
			if (! empty($user_levels)) {
				foreach ($user_levels as $l) {
					if ($l->id == $monthly_id) {
						$is_current_monthly = true;
					}
					if ($l->id == $annual_id) {
						$is_current_annual = true;
					}
				}
			}
		}

		$has_any_plan = $is_current_monthly || $is_current_annual;
		$card_class = $has_any_plan ? 'dd-card dd-card-active' : 'dd-card';
		$badge_html = $has_any_plan ? '<div class="dd-badge">CURRENT PLAN</div>' : '';

		$show_annual_default = $is_current_annual;
		$toggle_checked      = $show_annual_default ? 'checked' : '';
		$current_price     = $show_annual_default ? $annual_data['price'] : $monthly_data['price'];
		$owns_current_view = $show_annual_default ? $is_current_annual : $is_current_monthly;
		$btn_text          = $owns_current_view ? 'CURRENT PLAN' : 'JOIN NOW';
		$btn_class         = $owns_current_view ? 'dd-btn dd-checkout-btn dd-btn-disabled' : 'dd-btn dd-checkout-btn';
		$current_url       = $owns_current_view ? '' : ($show_annual_default ? $annual_data['url'] : $monthly_data['url']);

		ob_start();
	?>
		<div class="<?php echo esc_attr($card_class); ?>"
			data-price-monthly="<?php echo esc_attr($monthly_data['price']); ?>"
			data-url-monthly="<?php echo esc_url($monthly_data['url']); ?>"
			data-owns-monthly="<?php echo $is_current_monthly ? 'true' : 'false'; ?>"
			data-price-annual="<?php echo esc_attr($annual_data['price']); ?>"
			data-url-annual="<?php echo esc_url($annual_data['url']); ?>"
			data-owns-annual="<?php echo $is_current_annual ? 'true' : 'false'; ?>">
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
				padding: 55px 40px 40px;
				position: relative;
				display: flex;
				flex-direction: column;
				border: 2px solid var(--e-global-color-2ba2932);

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
				font-weight: bold;
				max-width: 300px;
				width: 100%;
				text-align: center;
			}

			.dd-plan-name {
				font-size: 2rem;
				margin: 0 0 1rem 0;
				color: var(--e-global-color-secondary);


			}

			.dd-plan-desc {
				margin-bottom: 1.5rem;
				flex-grow: 1;
				font-size: 16px;
			}

			.dd-price-wrapper {
				font-size: 37px;
				font-weight: bold;
				color: var(--e-global-color-secondary);
				margin-bottom: 1rem;
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

			.dd-toggle-label {
				font-size: 0.9rem;
			}

			.dd-btn {
				background-color: var(--e-global-color-accent);
				font-size: var(--e-global-typography-accent-font-size);
				font-weight: var(--e-global-typography-accent-font-weight);
				letter-spacing: var(--e-global-typography-accent-letter-spacing);
				padding: 23px 30px;
				color: var(--e-global-color-2ba2932);
				;
				text-align: center;
				border-radius: 5px;
				transition: 400ms;
			}

			.dd-btn:hover {
				background: var(--e-global-color-secondary);
				;
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
				echo '<p>No matching Monthly and Annual plan pairs detected.</p>';
			} else {
				foreach ($pairs as $pair) {
					$default_desc = 'Discover features included in the ' . esc_html($pair['name']) . ' plan.';
					$description  = get_option($pair['option_key'], $default_desc);
					echo $this->build_pricing_card($pair['name'], $description, $pair['monthly_id'], $pair['annual_id']);
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

							priceEl.innerHTML = isYearly ? card.getAttribute('data-price-annual') : card.getAttribute('data-price-monthly');
							const userOwnsSelectedView = isYearly ? ownsAnnual : ownsMonthly;

							if (userOwnsSelectedView) {
								btnEl.textContent = 'CURRENT PLAN';
								btnEl.classList.add('dd-btn-disabled');
								btnEl.removeAttribute('href');
							} else {
								btnEl.textContent = 'JOIN NOW';
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
