<?php
<?php
/**
 * Plugin Name: PMPro Dynamic Pricing Toggle Shortcode
 * Description: Provides a shortcode [dd_pricing_table] to dynamically display PMPro levels in a toggleable Monthly/Yearly card format.
 * Version: 1.0.1
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-pmpro-pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class DD_PMPro_Frontend_Pricing
 * * Handles the registration, data retrieval, and rendering of the dynamic pricing table shortcode.
 */
class DD_PMPro_Frontend_Pricing {

	/**
	 * Constructor.
	 * * Initializes the shortcode registration hook during the WordPress 'init' action.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_pricing_shortcode' ] );
	}

	/**
	 * Registers the shortcode with WordPress.
	 * * Binds the '[dd_pricing_table]' shortcode to the rendering method of this class.
	 * * @return void
	 */
	public function register_pricing_shortcode() {
		add_shortcode( 'dd_pricing_table', [ $this, 'render_pricing_table' ] );
	}

	/**
	 * Retrieves formatted pricing data for a specific PMPro level.
	 * * Queries the PMPro database for level details and formats the price using
	 * PMPro's native currency formatting function.
	 * * @param int $level_id The ID of the PMPro level.
	 * @return array|false Array of level data (price, url) or false if not found.
	 */
	private function get_level_data( $level_id ) {
		if ( ! function_exists( 'pmpro_getLevel' ) ) {
			return false;
		}

		$level = pmpro_getLevel( $level_id );
		if ( empty( $level ) ) {
			return false;
		}

		return [
			'id'    => $level->id,
			'price' => pmpro_formatPrice( $level->initial_payment ),
			'url'   => pmpro_url( 'checkout', '?level=' . $level->id ),
		];
	}

	/**
	 * Generates the HTML string for a single pricing card.
	 * * Constructs the DOM structure for a plan, injecting dynamic data attributes
	 * required by the vanilla JavaScript toggle logic. Evaluates current user membership
	 * to independently alter button states based on the active toggle view.
	 * * @param string $name         The display name of the plan (e.g., 'Essential').
	 * @param string $description  The feature description text.
	 * @param int    $monthly_id   The PMPro Level ID for the monthly variant.
	 * @param int    $annual_id    The PMPro Level ID for the annual variant.
	 * @return string              The constructed HTML block for the card.
	 */
	private function build_pricing_card( $name, $description, $monthly_id, $annual_id ) {
		$monthly_data = $this->get_level_data( $monthly_id );
		$annual_data  = $this->get_level_data( $annual_id );

		if ( ! $monthly_data || ! $annual_data ) {
			return '';
		}

		// Independently verify ownership of specific tiers
		$is_current_monthly = function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( $monthly_id );
		$is_current_annual  = function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( $annual_id );
		$has_any_plan       = $is_current_monthly || $is_current_annual;
		
		$card_class = $has_any_plan ? 'dd-card dd-card-active' : 'dd-card';
		$badge_html = $has_any_plan ? '<div class="dd-badge">CURRENT PLAN</div>' : '';
		
		// Set initial toggle state based on active tier (defaults to monthly if none)
		$show_annual_default = $is_current_annual;
		$toggle_checked      = $show_annual_default ? 'checked' : '';

		// Initialize default view variables
		$current_price     = $show_annual_default ? $annual_data['price'] : $monthly_data['price'];
		$owns_current_view = $show_annual_default ? $is_current_annual : $is_current_monthly;
		$btn_text          = $owns_current_view ? 'CURRENT PLAN' : 'JOIN NOW';
		$btn_class         = $owns_current_view ? 'dd-btn dd-checkout-btn dd-btn-disabled' : 'dd-btn dd-checkout-btn';
		$current_url       = $owns_current_view ? '#' : ( $show_annual_default ? $annual_data['url'] : $monthly_data['url'] );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $card_class ); ?>" 
			 data-price-monthly="<?php echo esc_attr( $monthly_data['price'] ); ?>" 
			 data-url-monthly="<?php echo esc_url( $monthly_data['url'] ); ?>"
			 data-owns-monthly="<?php echo $is_current_monthly ? 'true' : 'false'; ?>"
			 data-price-annual="<?php echo esc_attr( $annual_data['price'] ); ?>"
			 data-url-annual="<?php echo esc_url( $annual_data['url'] ); ?>"
			 data-owns-annual="<?php echo $is_current_annual ? 'true' : 'false'; ?>">
			
			<?php echo wp_kses_post( $badge_html ); ?>
			
			<h3 class="dd-plan-name"><?php echo esc_html( $name ); ?></h3>
			<p class="dd-plan-desc"><?php echo esc_html( $description ); ?></p>
			
			<div class="dd-price-wrapper">
				<span class="dd-price-amount"><?php echo wp_kses_post( $current_price ); ?></span>
			</div>
			
			<div class="dd-toggle-wrapper">
				<label class="dd-switch">
					<input type="checkbox" class="dd-plan-toggle" <?php echo esc_attr( $toggle_checked ); ?>>
					<span class="dd-slider round"></span>
				</label>
				<span class="dd-toggle-label">Yearly</span>
			</div>
			
			<a href="<?php echo esc_url( $current_url ); ?>" class="<?php echo esc_attr( $btn_class ); ?>">
				<?php echo esc_html( $btn_text ); ?>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the shortcode output including styling, HTML structure, and JS logic.
	 * * Constructs the full grid layout, processing the predefined level mappings 
	 * based on your provided database structure (Essential: 8/10, Growth: 9/12).
	 * * @param array $atts Shortcode attributes (optional overrides).
	 * @return string     The complete HTML, CSS, and JS to output to the frontend.
	 */
	public function render_pricing_table( $atts ) {
		// Enforce PMPro dependency
		if ( ! defined( 'PMPRO_VERSION' ) ) {
			return '<p>Paid Memberships Pro is required for the pricing table to function.</p>';
		}

		ob_start();
		?>
		<style>
			.dd-pricing-container { display: flex; gap: 2rem; justify-content: center; font-family: sans-serif; flex-wrap: wrap; }
			.dd-card { background: #f5f3f0; border-radius: 12px; padding: 2rem; width: 300px; position: relative; display: flex; flex-direction: column; }
			.dd-card-active { border: 2px solid #3c2a2a; background: #eae6e1; }
			.dd-badge { position: absolute; top: -15px; left: 50%; transform: translateX(-50%); background: #ffe270; padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; color: #333; }
			.dd-plan-name { font-size: 2rem; margin: 0 0 1rem 0; color: #5a3c3c; font-family: serif; }
			.dd-plan-desc { font-size: 0.95rem; color: #555; margin-bottom: 1.5rem; flex-grow: 1; }
			.dd-price-wrapper { font-size: 2.2rem; font-weight: bold; color: #4a3434; margin-bottom: 1rem; }
			
			/* Toggle Switch CSS */
			.dd-toggle-wrapper { display: flex; align-items: center; gap: 10px; margin-bottom: 2rem; }
			.dd-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
			.dd-switch input { opacity: 0; width: 0; height: 0; }
			.dd-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; }
			.dd-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; }
			input:checked + .dd-slider { background-color: #ff8c75; }
			input:checked + .dd-slider:before { transform: translateX(20px); }
			.dd-slider.round { border-radius: 34px; }
			.dd-slider.round:before { border-radius: 50%; }
			.dd-toggle-label { font-size: 0.9rem; color: #666; }
			
			/* Button CSS */
			.dd-btn { background: #ff8c75; color: white; text-align: center; padding: 12px; border-radius: 6px; text-decoration: none; font-weight: bold; text-transform: uppercase; transition: background 0.3s; }
			.dd-btn:hover { background: #fa7b63; color: white; }
			.dd-btn-disabled { background: #ffbbae; pointer-events: none; }
		</style>

		<div class="dd-pricing-container">
			<?php
			// Render 'Essential' Card (Monthly ID 8, Annual ID 10)
			echo $this->build_pricing_card( 
				'Essential', 
				'Discover creators across 2000+ industries & niches.', 
				8, 
				10 
			);

			// Render 'Growth' Card (Monthly ID 9, Annual ID 12)
			echo $this->build_pricing_card( 
				'Growth', 
				'Analyze creators & manage your creator partnerships.', 
				9, 
				12 
			);
			?>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const toggles = document.querySelectorAll('.dd-plan-toggle');
				
				toggles.forEach(toggle => {
					toggle.addEventListener('change', function() {
						const card = this.closest('.dd-card');
						const isYearly = this.checked;
						const priceEl = card.querySelector('.dd-price-amount');
						const btnEl = card.querySelector('.dd-checkout-btn');
						
						// Parse discrete ownership states
						const ownsMonthly = card.getAttribute('data-owns-monthly') === 'true';
						const ownsAnnual = card.getAttribute('data-owns-annual') === 'true';

						// Update Price Text
						priceEl.innerHTML = isYearly ? card.getAttribute('data-price-annual') : card.getAttribute('data-price-monthly');

						// Determine discrete button state
						const userOwnsSelectedView = isYearly ? ownsAnnual : ownsMonthly;

						if (userOwnsSelectedView) {
							btnEl.textContent = 'CURRENT PLAN';
							btnEl.classList.add('dd-btn-disabled');
							btnEl.href = '#';
						} else {
							btnEl.textContent = 'JOIN NOW';
							btnEl.classList.remove('dd-btn-disabled');
							btnEl.href = isYearly ? card.getAttribute('data-url-annual') : card.getAttribute('data-url-monthly');
						}
					});
				});
			});
		</script>
		<?php
		return ob_get_clean();
	}
}

// Instantiate the class to boot the shortcode handler.
new DD_PMPro_Frontend_Pricing();