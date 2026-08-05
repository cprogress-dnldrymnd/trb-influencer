<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Plugin Name: Feature Comparison Pricing Table
 * Description: Provides an editable Mailchimp-style feature-comparison pricing table. Content
 * (columns — auto-seeded from PMPro plans or fully custom — and feature rows with tick/cross/text
 * cells) is authored once from the "Pricing Tables" tab on the Influencer Theme settings hub.
 * render_table() serves BOTH front-end tables from that one authored dataset: the
 * [dd_feature_comparison] shortcode (every column, plain static CTA buttons) and, in "pricing
 * mode", the dd_pricing_table shortcode owned by DD_PMPro_Frontend_Pricing (same grid, but each
 * PMPro column's button becomes membership-aware and a chosen set of columns — typically the free
 * Trial tier — is excluded). Sharing one renderer keeps the grid CSS, the desktop sticky-header
 * measurement pass and the mobile tab script from drifting between the two tables.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: dd-feature-comparison-table
 */

class DD_Feature_Comparison_Table
{
	const OPTION_KEY = 'dd_feature_comparison_table';

	/**
	 * Scopes the recommended-banner height measurement (see render_shortcode()) to a specific
	 * rendered instance, in the unlikely event the shortcode/widget appears more than once on a
	 * page.
	 * @var int
	 */
	private static $instance_counter = 0;

	/**
	 * The single live instance, so other modules (notably DD_PMPro_Frontend_Pricing, whose
	 * dd_pricing_table shortcode renders this same table in "pricing mode") can reach
	 * render_table() without constructing a second object and double-registering every hook.
	 * @var DD_Feature_Comparison_Table|null
	 */
	private static $instance;

	public function __construct()
	{
		self::$instance = $this;

		add_filter('dd_theme_settings_tabs', [$this, 'register_settings_tab']);
		add_action('admin_init', [$this, 'register_setting']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_action('admin_footer', [$this, 'render_admin_assets']);
		add_action('init', [$this, 'register_shortcode']);
	}

	/**
	 * @return DD_Feature_Comparison_Table|null
	 */
	public static function instance()
	{
		return self::$instance;
	}

	/**
	 * Appends the settings tab to the Influencer Theme settings hub. The tab id stays
	 * 'comparison-table' (the hub renders it into #dd-panel-comparison-table, which this module's
	 * own admin JS/CSS scopes itself to) even though the label now covers both tables — the
	 * authored columns/rows feed the Pricing Table widget as well.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function register_settings_tab($tabs)
	{
		$tabs[] = [
			'id'     => 'comparison-table',
			'label'  => 'Pricing Tables',
			'render' => [$this, 'render_tab_panel'],
		];
		return $tabs;
	}

	/**
	 * Registers the single JSON-carrying option. All validation happens in sanitize().
	 *
	 * @param string $raw
	 * @return string JSON-encoded ['columns' => [...], 'rows' => [...]]
	 */
	public function register_setting()
	{
		register_setting('dd_feature_comparison_group', self::OPTION_KEY, [
			'type'              => 'string',
			'sanitize_callback' => [$this, 'sanitize'],
			'default'           => wp_json_encode(['columns' => [], 'rows' => []]),
		]);
	}

	/**
	 * wp.media and jQuery UI Sortable are only needed on the settings screen; wp.media is also
	 * enqueued independently by the Platform Icons tab, but this guards itself since module load
	 * order isn't guaranteed. Sortable powers drag-to-reorder on both the Columns and Rows lists.
	 * 
	 * @param string $hook
	 * @return void
	 */
	public function enqueue_admin_assets($hook)
	{
		if ($hook !== 'toplevel_page_dd-theme-settings') {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script('jquery-ui-sortable');
	}

	/**
	 * Validates and re-encodes the posted JSON carrier. Unknown/malformed input collapses to an
	 * empty table rather than persisting garbage. Cell types are whitelisted; cells referencing a
	 * column key that didn't survive column sanitation are dropped.
	 *
	 * @param string $raw
	 * @return string JSON-encoded ['columns' => [...], 'rows' => [...]]
	 */
	public function sanitize($raw)
	{
		$decoded = json_decode(is_string($raw) ? stripslashes($raw) : '', true);
		if (! is_array($decoded)) {
			return wp_json_encode(['columns' => [], 'rows' => []]);
		}

		$columns_in = (isset($decoded['columns']) && is_array($decoded['columns'])) ? $decoded['columns'] : [];
		$rows_in    = (isset($decoded['rows']) && is_array($decoded['rows'])) ? $decoded['rows'] : [];

		$columns    = [];
		$valid_keys = [];

		foreach ($columns_in as $col) {
			if (! is_array($col) || empty($col['key'])) {
				continue;
			}
			$key = sanitize_key($col['key']);
			if ($key === '' || isset($valid_keys[$key])) {
				continue;
			}

			$type = (isset($col['type']) && $col['type'] === 'pmpro') ? 'pmpro' : 'custom';

			$columns[] = [
				'key'              => $key,
				'type'             => $type,
				'level_id'         => $type === 'pmpro' ? (int) ($col['level_id'] ?? 0) : 0,
				'name'             => sanitize_text_field($col['name'] ?? ''),
				'price'            => sanitize_text_field($col['price'] ?? ''),
				'period'           => sanitize_text_field($col['period'] ?? ''),
				'cta_text'         => sanitize_text_field($col['cta_text'] ?? ''),
				'cta_url'          => ! empty($col['cta_url']) ? esc_url_raw($col['cta_url']) : '',
				'price_annual'     => sanitize_text_field($col['price_annual'] ?? ''),
				'period_annual'    => sanitize_text_field($col['period_annual'] ?? ''),
				'cta_url_annual'   => ! empty($col['cta_url_annual']) ? esc_url_raw($col['cta_url_annual']) : '',
				'recommended'      => ! empty($col['recommended']),
				'recommended_text' => sanitize_text_field($col['recommended_text'] ?? ''),
				'highlight'        => ! empty($col['highlight']),
			];
			$valid_keys[$key] = true;
		}

		$rows = [];
		foreach ($rows_in as $row) {
			if (! is_array($row)) {
				continue;
			}

			$cells_in = (isset($row['cells']) && is_array($row['cells'])) ? $row['cells'] : [];
			$cells    = [];
			foreach ($cells_in as $cell_key => $cell) {
				$cell_key = sanitize_key($cell_key);
				if (! isset($valid_keys[$cell_key]) || ! is_array($cell)) {
					continue;
				}
				$cell_type = in_array(($cell['type'] ?? ''), ['tick', 'cross', 'text'], true) ? $cell['type'] : 'text';
				$cells[$cell_key] = [
					'type' => $cell_type,
					'text' => $cell_type === 'text' ? sanitize_text_field($cell['text'] ?? '') : '',
				];
			}

			$rows[] = [
				'label' => sanitize_text_field($row['label'] ?? ''),
				'cells' => $cells,
			];
		}

		return wp_json_encode(['columns' => $columns, 'rows' => $rows]);
	}

	/**
	 * Reads and decodes the current table data, defaulting to an empty shape.
	 * 
	 * @return array{columns: array, rows: array}
	 */
	public function get_data()
	{
		$raw     = get_option(self::OPTION_KEY, '');
		$decoded = json_decode(is_string($raw) ? $raw : '', true);
		if (! is_array($decoded) || ! isset($decoded['columns']) || ! isset($decoded['rows'])) {
			return ['columns' => [], 'rows' => []];
		}
		return $decoded;
	}

	/**
	 * Every paid, signup-enabled PMPro plan available to seed as a column — id + name.
	 * Queries pmpro_getAllLevels directly to ensure Free/Trial tiers are included, circumventing
	 * DD_PMPro_Frontend_Pricing::get_orderable_plans() which intentionally strips £0 plans.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function get_pmpro_plans()
	{
		if (! function_exists('pmpro_getAllLevels')) {
			return [];
		}
		
		$all_levels = pmpro_getAllLevels(true, true);
		$plans      = [];
		
		foreach ($all_levels as $level) {
			if (! empty($level->allow_signups)) {
				$plans[] = [
					'id'   => (int) $level->id,
					'name' => trim($level->name),
				];
			}
		}
		
		return $plans;
	}

	/**
	 * Self-contained "Comparison Table" tab panel body (the hub provides the surrounding
	 * <div class="dd-panel">). Columns/rows are built entirely client-side against the
	 * #dd-fc-data-input hidden field, which is the only field this form actually submits.
	 * 
	 * @return void
	 */
	public function render_tab_panel()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$data = $this->get_data();
	?>
		<p class="dd-tab-desc">Build the plan columns and feature rows used by <strong>both</strong> pricing tables. Columns can be pulled from your PMPro plans or added as fully custom columns with their own price. Each feature row's cell can be a tick, a cross, or free text. Drag either list to reorder it — that order is what both tables render.</p>
		<p class="dd-tab-desc"><strong>Comparison Pricing Table</strong> widget (<code>[dd_feature_comparison]</code>) shows every column below with plain "Buy Now" buttons. <strong>Pricing Table</strong> widget shows the same columns, but each button becomes membership-aware (Upgrade / Downgrade / Switch / Current Plan) and the visitor's active plan gets a "Current Plan" badge — use that widget's <em>Hide Plans</em> setting in Elementor to keep the free Trial column off it.</p>

		<form action="options.php" method="post" id="dd-fc-form">
			<?php settings_fields('dd_feature_comparison_group'); ?>
			<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>" id="dd-fc-data-input" value="<?php echo esc_attr(wp_json_encode($data)); ?>" />

			<div id="dd-fc-builder">
				<!-- Builder Tabs Navigation -->
				<h2 class="nav-tab-wrapper dd-fc-admin-tabs" style="margin-bottom: 20px;">
					<a href="#" class="nav-tab nav-tab-active" data-target="dd-fc-tab-columns">Plan Columns</a>
					<a href="#" class="nav-tab" data-target="dd-fc-tab-rows">Feature Rows</a>
				</h2>

				<!-- Plan Columns Tab -->
				<div id="dd-fc-tab-columns" class="dd-fc-tab-pane">
					<div class="dd-fc-add-row" style="margin-bottom: 20px;">
						<select id="dd-fc-add-pmpro-select"></select>
						<button type="button" class="button button-secondary" id="dd-fc-add-pmpro-btn">Add PMPro Plan Column</button>
						<button type="button" class="button button-primary" id="dd-fc-add-custom-btn">Add Custom Column</button>
					</div>
					<div id="dd-fc-columns-list" class="dd-fc-columns-list"></div>
				</div>

				<!-- Feature Rows Tab -->
				<div id="dd-fc-tab-rows" class="dd-fc-tab-pane" style="display: none;">
					<div id="dd-fc-rows-list" class="dd-fc-rows-list"></div>
					<div class="dd-fc-add-row-bottom" style="display: flex; justify-content: flex-end; margin-top: 20px;">
						<button type="button" class="button button-primary" id="dd-fc-add-row-btn">Add Feature Row</button>
					</div>
				</div>
			</div>

			<?php submit_button('Save Comparison Table'); ?>
		</form>
	<?php
	}

	/**
	 * Prints the builder's CSS/JS, scoped to #dd-panel-comparison-table, on the settings screen
	 * only. Follows the same admin_footer + screen-id guard every other module tab uses so several
	 * modules' inline assets can coexist on one admin page without colliding.
	 * 
	 * @return void
	 */
	public function render_admin_assets()
	{
		$screen = get_current_screen();
		if (! $screen || $screen->id !== 'toplevel_page_dd-theme-settings') {
			return;
		}

		$plans = $this->get_pmpro_plans();
		$data  = $this->get_data();
	?>
		<style>
			#dd-panel-comparison-table .dd-fc-add-row {
				display: flex;
				align-items: center;
				gap: 8px;
				flex-wrap: wrap;
			}

			#dd-panel-comparison-table .dd-fc-columns-list,
			#dd-panel-comparison-table .dd-fc-rows-list {
				display: flex;
				flex-direction: column;
				gap: 12px;
			}

			#dd-panel-comparison-table .dd-fc-drag {
				cursor: move;
				color: #8c8f94;
				user-select: none;
				font-size: 20px;
				line-height: 1;
				margin-right: 8px;
				flex-shrink: 0;
			}

			#dd-panel-comparison-table .ui-sortable-helper {
				box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
			}

			#dd-panel-comparison-table .ui-sortable-placeholder {
				border: 1px dashed #8c8f94;
				border-radius: 6px;
				background: #f6f7f7;
				visibility: visible !important;
			}

			/* Collapsible Card Styling */
			#dd-panel-comparison-table .dd-fc-card {
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				background: #fff;
				box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			}

			#dd-panel-comparison-table .dd-fc-card-head {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 12px 16px;
				background: #f6f7f7;
				border-bottom: 1px solid #c3c4c7;
				border-radius: 6px 6px 0 0;
				cursor: pointer;
				transition: background 0.2s;
			}

			#dd-panel-comparison-table .dd-fc-card-head:hover {
				background: #f0f0f1;
			}

			#dd-panel-comparison-table .dd-fc-card.collapsed .dd-fc-card-head {
				border-bottom: none;
				border-radius: 6px;
			}

			#dd-panel-comparison-table .dd-fc-card-body {
				padding: 16px;
			}

			#dd-panel-comparison-table .dd-fc-card.collapsed .dd-fc-card-body {
				display: none;
			}

			#dd-panel-comparison-table .dd-fc-card-title {
				font-weight: 600;
				flex: 1;
				font-size: 14px;
			}

			/* Input sizing corrections */
			#dd-panel-comparison-table .dd-fc-row-label-input {
				flex: 1;
				max-width: 500px;
				padding: 4px 8px;
			}

			#dd-panel-comparison-table .dd-fc-badge {
				display: inline-block;
				font-size: 11px;
				padding: 2px 8px;
				border-radius: 10px;
				background: #e2e4e7;
				color: #50575e;
				margin-left: 8px;
			}

			/* Card Action Buttons (Duplicate / Remove / Toggle) */
			#dd-panel-comparison-table .dd-fc-actions {
				display: flex;
				align-items: center;
				gap: 12px;
				margin-left: auto; /* Pushes the actions group to the far right */
			}

			#dd-panel-comparison-table .dd-fc-btn-icon {
				background: none;
				border: none;
				cursor: pointer;
				font-size: 13px;
				color: #2271b1;
				text-decoration: none;
				padding: 0;
			}

			#dd-panel-comparison-table .dd-fc-btn-icon:hover {
				color: #135e96;
				text-decoration: underline;
			}

			#dd-panel-comparison-table .dd-fc-btn-icon.dd-fc-remove {
				color: #d63638;
			}

			#dd-panel-comparison-table .dd-fc-btn-icon.dd-fc-remove:hover {
				color: #d63638;
			}

			#dd-panel-comparison-table .dd-fc-toggle-icon {
				display: inline-block;
				width: 20px;
				height: 20px;
				position: relative;
			}

			#dd-panel-comparison-table .dd-fc-toggle-icon::after {
				content: '';
				position: absolute;
				top: 8px;
				left: 5px;
				border: 5px solid transparent;
				border-top-color: #50575e;
				transform-origin: 50% 25%;
				transition: transform 0.2s ease;
			}

			#dd-panel-comparison-table .dd-fc-card.collapsed .dd-fc-toggle-icon::after {
				transform: rotate(-90deg);
				top: 5px;
				left: 7px;
			}

			#dd-panel-comparison-table .dd-fc-field-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 16px;
			}

			#dd-panel-comparison-table .dd-fc-field label {
				display: block;
				font-size: 13px;
				font-weight: 600;
				color: #50575e;
				margin-bottom: 6px;
			}

			#dd-panel-comparison-table .dd-fc-field input[type="text"] {
				width: 100%;
				box-sizing: border-box;
			}

			#dd-panel-comparison-table .dd-fc-inline-check {
				display: flex;
				align-items: center;
				gap: 6px;
				font-size: 13px;
				font-weight: 600;
			}

			/* Feature Cell Grid Alignment */
			#dd-panel-comparison-table .dd-fc-row-card .dd-fc-cells {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
				gap: 16px;
			}

			#dd-panel-comparison-table .dd-fc-cell-field {
				background: #f9f9f9;
				padding: 12px;
				border-radius: 6px;
				border: 1px solid #e2e4e7;
				display: flex;
				flex-direction: column;
				gap: 8px;
			}

			#dd-panel-comparison-table .dd-fc-cell-field .dd-fc-cell-col-label {
				font-size: 12px;
				color: #2c3338;
				font-weight: 600;
			}

			#dd-panel-comparison-table .dd-fc-cell-field select,
			#dd-panel-comparison-table .dd-fc-cell-field input[type="text"] {
				width: 100%;
				box-sizing: border-box;
			}

			#dd-panel-comparison-table .dd-fc-empty-note {
				color: #757575;
				font-style: italic;
			}

			#dd-panel-comparison-table .dd-fc-field-note {
				color: #757575;
				font-size: 12px;
				margin: 16px 0 10px;
			}
		</style>
		<script>
			jQuery(function($) {
				if (!$('#dd-fc-form').length) {
					return;
				}

				var pmproPlans = <?php echo wp_json_encode($plans); ?>;
				var state = <?php echo wp_json_encode($data); ?>;
				if (!state || typeof state !== 'object') {
					state = {
						columns: [],
						rows: []
					};
				}
				state.columns = state.columns || [];
				state.rows = state.rows || [];

				function uid() {
					return 'custom_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
				}

				function esc(str) {
					return $('<div>').text(str == null ? '' : str).html();
				}

				function columnLabel(col) {
					return col.name || (col.type === 'pmpro' ? ('PMPro Plan #' + col.level_id) : 'Untitled Column');
				}

				function usedPmproLevelIds() {
					var ids = {};
					state.columns.forEach(function(c) {
						if (c.type === 'pmpro') ids[c.level_id] = true;
					});
					return ids;
				}

				function refreshPmproSelect() {
					var used = usedPmproLevelIds();
					var $select = $('#dd-fc-add-pmpro-select');
					$select.empty();
					var available = pmproPlans.filter(function(p) {
						return !used[p.id];
					});
					if (!available.length) {
						$select.append($('<option>').val('').text('All plans already added'));
						$('#dd-fc-add-pmpro-btn').prop('disabled', true);
						return;
					}
					$('#dd-fc-add-pmpro-btn').prop('disabled', false);
					available.forEach(function(p) {
						$select.append($('<option>').val(p.id).text(p.name));
					});
				}

				function renderColumns() {
					var $list = $('#dd-fc-columns-list');
					$list.empty();

					if (!state.columns.length) {
						$list.append('<p class="dd-fc-empty-note">No columns yet — add a PMPro plan or a custom column above.</p>');
					}

					state.columns.forEach(function(col, idx) {
						var isCollapsed = !!col._collapsed;
						var $card = $('<div class="dd-fc-card' + (isCollapsed ? ' collapsed' : '') + '" data-key="' + col.key + '" data-index="' + idx + '"></div>');

						var $head = $('<div class="dd-fc-card-head"></div>');
						$head.append('<span class="dd-fc-drag" title="Drag to reorder">&#8801;</span>');
						$head.append('<span class="dd-fc-card-title">' + esc(columnLabel(col)) + '<span class="dd-fc-badge">' + (col.type === 'pmpro' ? 'PMPro Plan' : 'Custom') + '</span></span>');
						
						var $actions = $('<div class="dd-fc-actions"></div>');
						$actions.append('<button type="button" class="dd-fc-btn-icon dd-fc-duplicate" data-action="duplicate-column" data-index="' + idx + '" title="Duplicate">Copy</button>');
						$actions.append('<button type="button" class="dd-fc-btn-icon dd-fc-remove" data-action="remove-column" data-index="' + idx + '" title="Remove">Remove</button>');
						$actions.append('<span class="dd-fc-toggle-icon"></span>');
						$head.append($actions);
						$card.append($head);

						var $body = $('<div class="dd-fc-card-body"></div>');
						var $grid = $('<div class="dd-fc-field-grid"></div>');

						function field(labelText, key, value, placeholder) {
							var $f = $('<div class="dd-fc-field"></div>');
							$f.append('<label>' + esc(labelText) + '</label>');
							var $input = $('<input type="text" data-field="' + key + '" data-index="' + idx + '" />').val(value || '');
							if (placeholder) $input.attr('placeholder', placeholder);
							$f.append($input);
							return $f;
						}

						$grid.append(field('Column Name', 'name', col.name, col.type === 'pmpro' ? '(auto: plan name)' : ''));
						$grid.append(field('Price', 'price', col.price, col.type === 'pmpro' ? '(auto: plan price)' : 'e.g. 20'));
						$grid.append(field('Price Period', 'period', col.period, 'e.g. /month'));
						$grid.append(field('CTA Button Text', 'cta_text', col.cta_text, 'Buy Now'));
						$grid.append(field('CTA URL', 'cta_url', col.cta_url, col.type === 'pmpro' ? '(auto: checkout link)' : 'https://'));
						$grid.append(field('Recommended Banner Text', 'recommended_text', col.recommended_text, 'e.g. Recommended'));

						$body.append($grid);
						$body.append('<p class="dd-fc-field-note">Fill in an Annual Price to show a Yearly toggle on this column (leave blank to hide it). PMPro columns auto-detect an "Annual" Payment Plan if one is configured.</p>');
						
						var $annualGrid = $('<div class="dd-fc-field-grid"></div>');
						$annualGrid.append(field('Annual Price', 'price_annual', col.price_annual, col.type === 'pmpro' ? '(auto: annual plan price)' : 'e.g. 190'));
						$annualGrid.append(field('Annual Price Period', 'period_annual', col.period_annual, 'e.g. /year'));
						$annualGrid.append(field('Annual CTA URL', 'cta_url_annual', col.cta_url_annual, col.type === 'pmpro' ? '(auto: annual checkout link)' : '(defaults to CTA URL)'));
						$body.append($annualGrid);

						var $checks = $('<div class="dd-fc-field-grid" style="margin-top:20px;"></div>');
						var $recCheck = $('<label class="dd-fc-inline-check"></label>');
						$recCheck.append($('<input type="checkbox" data-field="recommended" data-index="' + idx + '" />').prop('checked', !!col.recommended));
						$recCheck.append('Show recommended banner');
						$checks.append($recCheck);

						var $hlCheck = $('<label class="dd-fc-inline-check"></label>');
						$hlCheck.append($('<input type="checkbox" data-field="highlight" data-index="' + idx + '" />').prop('checked', !!col.highlight));
						$hlCheck.append('Highlight this column');
						$checks.append($hlCheck);

						$body.append($checks);
						$card.append($body);
						
						$list.append($card);
					});

					refreshPmproSelect();
				}

				function renderRows() {
					var $list = $('#dd-fc-rows-list');
					$list.empty();

					if (!state.rows.length) {
						$list.append('<p class="dd-fc-empty-note">No feature rows yet — add one above.</p>');
					}

					state.rows.forEach(function(row, rIdx) {
						var isCollapsed = !!row._collapsed;
						var $card = $('<div class="dd-fc-card dd-fc-row-card' + (isCollapsed ? ' collapsed' : '') + '" data-row-index="' + rIdx + '"></div>');

						var $head = $('<div class="dd-fc-card-head"></div>');
						$head.append('<span class="dd-fc-drag" title="Drag to reorder">&#8801;</span>');
						
						var $labelInput = $('<input type="text" class="dd-fc-row-label-input" data-row-field="label" data-row-index="' + rIdx + '" />').val(row.label || '').attr('placeholder', 'Feature label, e.g. Monthly Email Sends');
						$head.append($labelInput);
						
						var $actions = $('<div class="dd-fc-actions"></div>');
						$actions.append('<button type="button" class="dd-fc-btn-icon dd-fc-duplicate" data-action="duplicate-row" data-row-index="' + rIdx + '" title="Duplicate">Copy</button>');
						$actions.append('<button type="button" class="dd-fc-btn-icon dd-fc-remove" data-action="remove-row" data-row-index="' + rIdx + '" title="Remove">Remove</button>');
						$actions.append('<span class="dd-fc-toggle-icon"></span>');
						
						$head.append($actions);
						$card.append($head);

						var $body = $('<div class="dd-fc-card-body"></div>');
						var $cells = $('<div class="dd-fc-cells"></div>');
						
						state.columns.forEach(function(col) {
							var cell = (row.cells && row.cells[col.key]) || {
								type: 'text',
								text: ''
							};
							var $cf = $('<div class="dd-fc-cell-field"></div>');
							$cf.append('<span class="dd-fc-cell-col-label">' + esc(columnLabel(col)) + '</span>');
							var $select = $('<select data-cell-field="type" data-row-index="' + rIdx + '" data-col-key="' + col.key + '"></select>');
							['tick', 'cross', 'text'].forEach(function(t) {
								$select.append($('<option>').val(t).text(t === 'tick' ? 'Tick' : (t === 'cross' ? 'Cross' : 'Text')));
							});
							$select.val(cell.type);
							$cf.append($select);
							var $text = $('<input type="text" data-cell-field="text" data-row-index="' + rIdx + '" data-col-key="' + col.key + '" />').val(cell.text || '');
							$text.toggle(cell.type === 'text');
							$cf.append($text);
							$cells.append($cf);
						});
						
						$body.append($cells);
						$card.append($body);
						
						$list.append($card);
					});
				}

				function syncToInput() {
					$('#dd-fc-data-input').val(JSON.stringify(state));
				}

				function renderAll() {
					renderColumns();
					renderRows();
					syncToInput();
				}
				
				// Admin Tabs Logic
				$('.dd-fc-admin-tabs .nav-tab').on('click', function(e) {
					e.preventDefault();
					$('.dd-fc-admin-tabs .nav-tab').removeClass('nav-tab-active');
					$(this).addClass('nav-tab-active');
					$('.dd-fc-tab-pane').hide();
					$('#' + $(this).data('target')).show();
				});
				
				// Accordion Collapse/Expand Logic
				$('#dd-fc-columns-list, #dd-fc-rows-list').on('click', '.dd-fc-card-head', function(e) {
					// Prevent collapsing if user clicks an input, button, or drag handle
					if ($(e.target).closest('button, input, select, .dd-fc-drag').length) return;
					
					var $card = $(this).closest('.dd-fc-card');
					var isRow = $card.hasClass('dd-fc-row-card');
					var idx = parseInt(isRow ? $card.data('row-index') : $card.data('index'), 10);
					
					if (isRow) {
						state.rows[idx]._collapsed = !state.rows[idx]._collapsed;
						$card.toggleClass('collapsed', state.rows[idx]._collapsed);
					} else {
						state.columns[idx]._collapsed = !state.columns[idx]._collapsed;
						$card.toggleClass('collapsed', state.columns[idx]._collapsed);
					}
				});

				$('#dd-fc-add-pmpro-btn').on('click', function() {
					var id = parseInt($('#dd-fc-add-pmpro-select').val(), 10);
					if (!id) return;
					var plan = pmproPlans.filter(function(p) {
						return p.id === id;
					})[0];
					if (!plan) return;
					state.columns.push({
						key: 'pmpro_' + id,
						type: 'pmpro',
						level_id: id,
						name: plan.name,
						price: '',
						period: '/month',
						cta_text: 'Buy Now',
						cta_url: '',
						price_annual: '',
						period_annual: '/year',
						cta_url_annual: '',
						recommended: false,
						recommended_text: '',
						highlight: false,
						_collapsed: false
					});
					renderAll();
				});

				$('#dd-fc-add-custom-btn').on('click', function() {
					state.columns.push({
						key: uid(),
						type: 'custom',
						level_id: 0,
						name: 'New Plan',
						price: '',
						period: '',
						cta_text: 'Buy Now',
						cta_url: '',
						price_annual: '',
						period_annual: '',
						cta_url_annual: '',
						recommended: false,
						recommended_text: '',
						highlight: false,
						_collapsed: false
					});
					renderAll();
				});

				$('#dd-fc-add-row-btn').on('click', function() {
					var cells = {};
					state.columns.forEach(function(col) {
						cells[col.key] = {
							type: 'text',
							text: ''
						};
					});
					state.rows.push({
						label: '',
						cells: cells,
						_collapsed: false
					});
					renderAll();
				});

				// Column Duplication
				$('#dd-fc-columns-list').on('click', 'button[data-action="duplicate-column"]', function(e) {
					e.stopPropagation();
					var idx = parseInt($(this).data('index'), 10);
					var col = state.columns[idx];
					var newCol = JSON.parse(JSON.stringify(col));
					newCol.key = uid();
					newCol.name = newCol.name + ' (Copy)';
					newCol._collapsed = false;
					
					state.columns.splice(idx + 1, 0, newCol);
					
					// Replicate row values for the new column
					state.rows.forEach(function(row) {
						if (row.cells && row.cells[col.key]) {
							row.cells[newCol.key] = JSON.parse(JSON.stringify(row.cells[col.key]));
						} else {
							row.cells[newCol.key] = { type: 'text', text: '' };
						}
					});
					renderAll();
				});

				// Row Duplication
				$('#dd-fc-rows-list').on('click', 'button[data-action="duplicate-row"]', function(e) {
					e.stopPropagation();
					var rIdx = parseInt($(this).data('row-index'), 10);
					var row = state.rows[rIdx];
					var newRow = JSON.parse(JSON.stringify(row));
					newRow.label = newRow.label + ' (Copy)';
					newRow._collapsed = false;
					
					state.rows.splice(rIdx + 1, 0, newRow);
					renderAll();
				});

				$('#dd-fc-columns-list').on('click', 'button[data-action="remove-column"]', function(e) {
					e.stopPropagation();
					var idx = parseInt($(this).data('index'), 10);
					var removed = state.columns[idx];
					state.columns.splice(idx, 1);
					if (removed) {
						state.rows.forEach(function(row) {
							if (row.cells && row.cells[removed.key]) {
								delete row.cells[removed.key];
							}
						});
					}
					renderAll();
				});

				$('#dd-fc-columns-list').on('input change', 'input[data-field]', function() {
					var idx = parseInt($(this).data('index'), 10);
					var field = $(this).data('field');
					var col = state.columns[idx];
					if (!col) return;
					col[field] = $(this).is(':checkbox') ? $(this).is(':checked') : $(this).val();
					if (field === 'name' || field === 'recommended') {
						// Title/badge text depends on these — refresh headers without losing focus elsewhere.
						$(this).closest('.dd-fc-card').find('.dd-fc-card-title').contents().first().replaceWith(esc(columnLabel(col)));
						renderRows();
					}
					syncToInput();
				});

				$('#dd-fc-rows-list').on('click', 'button[data-action="remove-row"]', function(e) {
					e.stopPropagation();
					var rIdx = parseInt($(this).data('row-index'), 10);
					state.rows.splice(rIdx, 1);
					renderAll();
				});

				$('#dd-fc-rows-list').on('input', 'input[data-row-field="label"]', function() {
					var rIdx = parseInt($(this).data('row-index'), 10);
					if (state.rows[rIdx]) {
						state.rows[rIdx].label = $(this).val();
					}
					syncToInput();
				});

				$('#dd-fc-rows-list').on('change', 'select[data-cell-field="type"]', function() {
					var rIdx = parseInt($(this).data('row-index'), 10);
					var colKey = $(this).data('col-key');
					var row = state.rows[rIdx];
					if (!row) return;
					row.cells = row.cells || {};
					row.cells[colKey] = row.cells[colKey] || {
						type: 'text',
						text: ''
					};
					row.cells[colKey].type = $(this).val();
					$(this).siblings('input[data-cell-field="text"]').toggle(row.cells[colKey].type === 'text');
					syncToInput();
				});

				$('#dd-fc-rows-list').on('input', 'input[data-cell-field="text"]', function() {
					var rIdx = parseInt($(this).data('row-index'), 10);
					var colKey = $(this).data('col-key');
					var row = state.rows[rIdx];
					if (!row) return;
					row.cells = row.cells || {};
					row.cells[colKey] = row.cells[colKey] || {
						type: 'text',
						text: ''
					};
					row.cells[colKey].text = $(this).val();
					syncToInput();
				});

				$('#dd-fc-form').on('submit', syncToInput);

				// Drag-to-reorder — the container is bound once; jQuery UI re-scans its `items`
				// on each drag start, so it keeps working across the empty()+rebuild renderAll()
				// does on every change. The lists are state-authoritative (not DOM-authoritative
				// like this theme's other repeaters), so `update` reads the new DOM order back
				// into `state` and re-renders from it, rather than rewriting input names in place.
				$('#dd-fc-columns-list').sortable({
					handle: '.dd-fc-drag',
					axis: 'y',
					items: '> .dd-fc-card',
					update: function() {
						var keys = $('#dd-fc-columns-list').children('.dd-fc-card').map(function() {
							return $(this).data('key');
						}).get();
						state.columns = keys.map(function(k) {
							return state.columns.filter(function(c) {
								return c.key === k;
							})[0];
						}).filter(Boolean);
						renderAll();
					}
				});

				$('#dd-fc-rows-list').sortable({
					handle: '.dd-fc-drag',
					axis: 'y',
					items: '> .dd-fc-card',
					update: function() {
						var order = $('#dd-fc-rows-list').children('.dd-fc-card').map(function() {
							return parseInt($(this).data('row-index'), 10);
						}).get();
						state.rows = order.map(function(i) {
							return state.rows[i];
						}).filter(Boolean);
						renderAll();
					}
				});

				renderAll();
			});
		</script>
	<?php
	}

	/**
	 * @return void
	 */
	public function register_shortcode()
	{
		add_shortcode('dd_feature_comparison', [$this, 'render_shortcode']);
	}

	/**
	 * Resolves a column's display name/price/CTA URL (and yearly-toggle annual price/CTA URL when
	 * present), live-deriving from its PMPro level when the admin left a field blank so a plan's
	 * price/name/checkout link never drifts stale.
	 *
	 * A PMPro column with no manually-entered annual price auto-detects the level's "Annual" Payment
	 * Plan extension via DD_PMPro_Frontend_Pricing::get_annual_payment_plan(). A custom column (or a
	 * PMPro column with no Annual plan configured) only shows an annual price if the admin entered
	 * one — leaving both blank simply omits the toggle for that column.
	 *
	 * annual_plan_id is returned separately from the price because pricing mode needs the Payment
	 * Plan identifier itself to detect whether the visitor owns the annual term of a level, so it is
	 * looked up even when the admin overrode the displayed annual price by hand.
	 *
	 * @param array $col
	 * @return array{name:string, price:string, cta_url:string, price_annual:string, cta_url_annual:string, annual_plan_id:string}
	 */
	private static function resolve_column($col)
	{
		$name           = $col['name'];
		$price          = $col['price'];
		$cta_url        = $col['cta_url'];
		$price_annual   = $col['price_annual'] ?? '';
		$cta_url_annual = $col['cta_url_annual'] ?? '';
		$annual_plan_id = '';

		if ($col['type'] === 'pmpro' && $col['level_id'] && function_exists('pmpro_getLevel')) {
			$level = pmpro_getLevel($col['level_id']);
			if (! empty($level)) {
				if ($name === '') {
					$name = trim($level->name);
				}
				if ($price === '') {
					$raw_price = (float) $level->initial_payment > 0 ? (float) $level->initial_payment : (float) $level->billing_amount;
					$price = function_exists('pmpro_formatPrice') ? pmpro_formatPrice($raw_price) : number_format($raw_price, 2);
				}
				if ($cta_url === '' && function_exists('pmpro_url')) {
					$cta_url = pmpro_url('checkout', '?level=' . (int) $col['level_id']);
				}

				// Looked up even when the admin typed their own annual price, because pricing mode
				// needs the Payment Plan id itself (not just its price) to tell whether the visitor
				// owns the annual term of this level — see DD_PMPro_Frontend_Pricing.
				if (class_exists('DD_PMPro_Frontend_Pricing')) {
					$annual_plan = DD_PMPro_Frontend_Pricing::get_annual_payment_plan($col['level_id']);
					if (! empty($annual_plan)) {
						$annual_plan_id = $annual_plan['id'];
						if ($price_annual === '') {
							$price_annual = $annual_plan['price'];
							if ($cta_url_annual === '' && function_exists('pmpro_url')) {
								$cta_url_annual = pmpro_url('checkout', '?level=' . (int) $col['level_id'] . '&pmpropp_chosen_plan=' . $annual_plan['id']);
							}
						}
					}
				}
			}
		}

		// A manually-entered annual price with no explicit annual link reuses the monthly CTA
		// rather than leaving the yearly view with a dead button.
		if ($price_annual !== '' && $cta_url_annual === '') {
			$cta_url_annual = $cta_url;
		}

		return [
			'name'           => $name,
			'price'          => $price,
			'cta_url'        => $cta_url,
			'price_annual'   => $price_annual,
			'cta_url_annual' => $cta_url_annual,
			'annual_plan_id' => $annual_plan_id,
		];
	}

	/**
	 * The configured columns as an Elementor-friendly choices map, resolving blank names from the
	 * live PMPro level the same way the front end does. Static so a widget can call it without an
	 * instance.
	 *
	 * @return array<string,string> column key => display name
	 */
	public static function get_column_choices()
	{
		$raw     = get_option(self::OPTION_KEY, '');
		$decoded = json_decode(is_string($raw) ? $raw : '', true);
		if (! is_array($decoded) || empty($decoded['columns']) || ! is_array($decoded['columns'])) {
			return [];
		}

		$choices = [];
		foreach ($decoded['columns'] as $col) {
			if (empty($col['key'])) {
				continue;
			}
			$resolved = self::resolve_column($col);
			$choices[$col['key']] = $resolved['name'] !== '' ? $resolved['name'] : $col['key'];
		}

		return $choices;
	}

	/**
	 * Renders the [dd_feature_comparison] shortcode — every configured column, with plain static
	 * CTA buttons.
	 *
	 * @return string
	 */
	public function render_shortcode()
	{
		return $this->render_table();
	}

	/**
	 * Renders the CSS-grid table — one column per configured plan, one row per configured feature.
	 * Empty state (no columns) renders nothing rather than an empty shell.
	 *
	 * Two modes share this one renderer so the grid CSS, the desktop sticky-header measurement pass
	 * and the mobile tab script can't drift between them:
	 *   - default          — the comparison table. Static CTA buttons, every column shown.
	 *   - pricing_mode      — the dd_pricing_table shortcode. Each PMPro column's button becomes
	 *                         membership-aware (upgrade/downgrade/switch/current/trial-lockdown) via
	 *                         DD_PMPro_Frontend_Pricing, the visitor's active plan gets a "Current
	 *                         Plan" badge, and 'exclude' drops columns (this is how the free Trial
	 *                         column is kept off the pricing table).
	 *
	 * @param array $args {
	 *     @type string[] $exclude      Column keys to omit entirely.
	 *     @type bool     $pricing_mode Resolve membership state per column.
	 * }
	 * @return string
	 */
	public function render_table($args = [])
	{
		$args = wp_parse_args($args, [
			'exclude'      => [],
			'pricing_mode' => false,
		]);

		$pricing_mode = ! empty($args['pricing_mode']);
		$exclude      = is_array($args['exclude']) ? $args['exclude'] : [];

		$data = $this->get_data();
		if (empty($data['columns'])) {
			return '';
		}

		$columns = $data['columns'];
		$rows    = $data['rows'];

		// Reindexed so data-col-index / --dd-fc-c stay contiguous — the mobile tab script and the
		// desktop explicit grid placement both key off those being 0..n with no gaps.
		if (! empty($exclude)) {
			$columns = array_values(array_filter($columns, function ($col) use ($exclude) {
				return empty($col['key']) || ! in_array($col['key'], $exclude, true);
			}));
		}

		if (empty($columns)) {
			return '';
		}

		$col_count = count($columns);
		$row_count = count($rows);

		// grid-row: 1 / -1 (used below to span the sticky plan header across every
		// row) resolves -1 against the grid's EXPLICIT tracks — every row must be
		// listed here rather than left to grid-auto-rows, or the span collapses back
		// down to row 1 alone and the header can only stay pinned for one row's height.
		$grid_template_rows = 'var(--dd-fc-head-h, auto)';
		if ($row_count > 0) {
			$grid_template_rows .= ' repeat(' . (int) $row_count . ', auto)';
		}

		// Resolved once per column (each PMPro column resolution can hit the DB) and reused both
		// for these page-level flags and inside the render loop below.
		$resolved_columns = [];
		$plan_states      = [];
		$has_recommended  = false;
		$has_annual       = false;
		$has_badge        = false;
		$owned_index      = null;
		$initial_active   = 0; // Default active tab index for mobile

		$pricing = ($pricing_mode && class_exists('DD_PMPro_Frontend_Pricing')) ? DD_PMPro_Frontend_Pricing::instance() : null;

		foreach ($columns as $index => $col) {
			$resolved_columns[$col['key']] = self::resolve_column($col);
			if (! empty($col['recommended'])) {
				$has_recommended = true;
				// If a recommended column exists, default to it on mobile initially
				$initial_active = $index;
			}
			if ($resolved_columns[$col['key']]['price'] !== '' && $resolved_columns[$col['key']]['price_annual'] !== '') {
				$has_annual = true;
			}

			// Only a real PMPro column can carry membership state; a custom column keeps the static
			// price/CTA the admin authored, with no badge and no upgrade/downgrade logic.
			if ($pricing && $col['type'] === 'pmpro' && ! empty($col['level_id'])) {
				$state = $pricing->get_plan_button_state($col['level_id'], $resolved_columns[$col['key']]['annual_plan_id']);
				$plan_states[$col['key']] = $state;
				if (! empty($state['has_any_plan'])) {
					$has_badge   = true;
					$owned_index = $index;
				}
			}
		}

		// If no recommended column, fallback to the highlighted one (if any)
		if (!$has_recommended) {
			foreach ($columns as $index => $col) {
				if (! empty($col['highlight'])) {
					$initial_active = $index;
					break;
				}
			}
		}

		// The visitor's own plan is the most useful mobile tab to open on, so it outranks both.
		if ($owned_index !== null) {
			$initial_active = $owned_index;
		}

		// The "Current Plan" badge occupies the same absolute slot as the recommended banner, so it
		// needs the same reserved head padding even on a table where nothing is marked recommended.
		if ($has_badge) {
			$has_recommended = true;
		}

		$wrap_id = 'dd-fc-' . (++self::$instance_counter);

		ob_start();
	?>
		<style>
			/* --------------------------------------------------------------------------
			 * Structural protection: 
			 * Using flex-direction column completely disables margin collapsing between 
			 * siblings (e.g. between the sticky tabs and the table). This physically 
			 * prevents the "jump" bug in Chrome when elements stick/unstick.
			 * -------------------------------------------------------------------------- */
			.dd-fc-wrap {
				display: flex;
				flex-direction: column;
				width: 100%;
				overflow: visible !important;
			}
		
			.dd-fc-wrap .dd-fc-mobile-tabs {
				display: none; /* Hidden on desktop */
			}
		
			.dd-fc-wrap .dd-fc-table {
				display: grid;
				grid-template-columns: minmax(160px, 1.4fr) repeat(<?php echo (int) $col_count; ?>, minmax(120px, 1fr));
				border: 1px solid var(--dd-fc-border-color, #e2e2e2);
				border-radius: 8px;
				background: #fff;
			}

			/* Corners are rounded per-cell rather than via .dd-fc-table{overflow:hidden} —
			   overflow:hidden on an ancestor silently disables position:sticky on descendants,
			   which the desktop sticky plan header below relies on. */
			.dd-fc-wrap .dd-fc-head-row .dd-fc-feature-col     { border-top-left-radius: var(--dd-fc-radius-tl, 8px); }
			.dd-fc-wrap .dd-fc-head-row .dd-fc-head:last-child { border-top-right-radius: var(--dd-fc-radius-tr, 8px); }
			.dd-fc-wrap .dd-fc-row:last-child .dd-fc-feature   { border-bottom-left-radius: var(--dd-fc-radius-bl, 8px); }
			.dd-fc-wrap .dd-fc-row:last-child .dd-fc-cell:last-child { border-bottom-right-radius: var(--dd-fc-radius-br, 8px); }

			.dd-fc-wrap .dd-fc-row {
				display: contents;
			}

			.dd-fc-wrap .dd-fc-cell {
				padding: 14px 16px;
				border-bottom: 1px solid var(--dd-fc-border-color, #ececec);
				display: flex;
				align-items: center;
				justify-content: space-between;
				text-align: center;
			}

			.dd-fc-wrap .dd-fc-cell:not(:last-child) {
				border-right: 1px solid var(--dd-fc-border-color, #ececec);
			}
			.dd-fc-wrap  .dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell:not(.dd-fc-feature) {
				justify-content: center;
			}

			.dd-fc-wrap .dd-fc-feature-col,
			.dd-fc-wrap .dd-fc-feature {
				justify-content: flex-start;
				text-align: left;
				font-weight: 500;
			}
			.dd-fc-wrap .dd-fc-feature span{
				border-bottom: 1px dashed;
			}

			.dd-fc-wrap .dd-fc-head {
				flex-direction: column;
				gap: 8px;
				padding-top: var(--dd-fc-rec-pad, 14px);
				background: #fafafa;
				font-weight: 600;
				position: relative;
				text-align: left;
			}

			.dd-fc-wrap .dd-fc-head > * {
				width: 100%;
			}

			.dd-fc-wrap .dd-fc-recommended {
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				background: var(--e-global-color-primary, #034146);
				color: #fff;
				font-size: 11px;
				line-height: 1.4;
				padding: 4px 6px;
				text-align: center;
			}

			.dd-fc-wrap .dd-fc-price {
				font-size: 22px;
				font-weight: 700;
			}

			.dd-fc-wrap .dd-fc-period {
				font-size: 12px;
				font-weight: 400;
				opacity: .7;
				margin-left: 2px;
			}

			/* Yearly-toggle chrome, scoped under .dd-fc-wrap so it can't collide with anything
			   else on the page.
			   NOTE: never write a bracketed shortcode-tag anywhere in this file's actual output
			   (echoed HTML/CSS/JS, not PHP docblocks) — do_shortcode() blindly regex-replaces any
			   registered-tag text wherever it appears, even inside a <script>/<style> comment,
			   which previously injected another shortcode's full HTML mid-tag and broke this block. */
			.dd-fc-wrap .dd-toggle-wrapper {
				display: flex;
				align-items: center;
				gap: 10px;
				margin-top: 6px;
			}

			.dd-fc-wrap .dd-switch {
				position: relative;
				display: inline-block;
				width: 44px;
				height: 24px;
				flex-shrink: 0;
			}

			.dd-fc-wrap .dd-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}

			.dd-fc-wrap .dd-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #ccc;
				transition: .4s;
			}

			.dd-fc-wrap .dd-slider:before {
				position: absolute;
				content: "";
				height: 18px;
				width: 18px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .4s;
			}

			.dd-fc-wrap .dd-switch input:checked + .dd-slider {
				background-color: var(--e-global-color-accent, #034146);
			}

			.dd-fc-wrap .dd-switch input:checked + .dd-slider:before {
				transform: translateX(20px);
			}

			.dd-fc-wrap .dd-slider.round {
				border-radius: 34px;
			}

			.dd-fc-wrap .dd-slider.round:before {
				border-radius: 50%;
			}

			.dd-fc-wrap .dd-toggle-label {
				font-size: 12px;
				font-weight: 500;
			}

			.dd-fc-wrap .dd-discount {
				background-color: #ABFFB6;
				padding: 0 10px;
				border-radius: 50px;
				font-size: 11px;
				font-weight: 600;
				color: var(--e-global-color-primary, #034146);
			}

			.dd-fc-wrap .dd-fc-cta {
				margin-top: 6px;
				display: inline-block;
				padding: 8px 20px;
				border-radius: 4px;
				background: var(--e-global-color-primary, #034146);
				color: #fff;
				text-decoration: none;
				font-weight: 600;
				font-size: 13px;
				text-align: center;
				line-height: 1 !important;
			}

			.dd-fc-wrap .dd-fc-cta:hover {
				opacity: .9;
				color: #fff;
			}

			/* --------------------------------------------------------------------------
			 * Pricing mode only (.dd-fc-pricing) — the membership-aware chrome carried
			 * over from the old card layout: the "Current Plan" badge, the locked-button
			 * state, the active-plan column emphasis and the free-trial notice.
			 * -------------------------------------------------------------------------- */
			.dd-fc-wrap .dd-fc-badge {
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				background: #ffe270;
				color: #241c15;
				font-size: 11px;
				font-weight: 700;
				line-height: 1.4;
				letter-spacing: .3px;
				padding: 4px 6px;
				text-align: center;
			}

			.dd-fc-wrap .dd-fc-cta.dd-fc-cta-disabled {
				background: #ffbbae;
				color: #241c15;
				pointer-events: none;
				cursor: not-allowed;
			}

			.dd-fc-wrap .dd-fc-head.dd-fc-current {
				box-shadow: inset 0 0 0 2px var(--e-global-color-secondary, #ff6b4a);
			}

			/* Side-only emphasis (inset shadow, not a background) so the owned column reads as one
			   connected column without fighting the .dd-fc-highlight background or the paint-order
			   guard that keeps body cells opaque above the sticky header. */
			.dd-fc-wrap .dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell.dd-fc-current {
				box-shadow: inset 2px 0 0 var(--e-global-color-secondary, #ff6b4a), inset -2px 0 0 var(--e-global-color-secondary, #ff6b4a);
			}

			.dd-fc-wrap .dd-fc-row:last-child .dd-fc-cell.dd-fc-current {
				box-shadow: inset 2px 0 0 var(--e-global-color-secondary, #ff6b4a), inset -2px 0 0 var(--e-global-color-secondary, #ff6b4a), inset 0 -2px 0 var(--e-global-color-secondary, #ff6b4a);
			}

			.dd-fc-wrap .dd-fc-trial-text {
				font-size: 12px;
				font-weight: 500;
			}

			.dd-fc-wrap .dd-fc-trial-text span {
				background-color: var(--e-global-color-secondary, #ff6b4a);
				color: #fef6f3;
				padding: 6px 8px;
				display: inline-block;
				border-radius: 5px;
				font-weight: 600;
				letter-spacing: .2px;
			}

			.dd-fc-wrap .dd-fc-trial-text i {
				font-style: italic;
			}

			.dd-fc-wrap .dd-fc-tick {
				color: #1e9e63;
				font-size: 18px;
				line-height: 1;
			}

			.dd-fc-wrap .dd-fc-cross {
				color: #c0392b;
				font-size: 18px;
				line-height: 1;
			}

			.dd-fc-wrap .dd-fc-highlight {
				background: #f4faf8;
			}

			.dd-fc-wrap .dd-fc-row:last-child .dd-fc-cell {
				border-bottom: none;
			}

			/* --------------------------------------------------------------------------
			 * Mobile Layout: 
			 * Top Tabs switch the active 'Plan Details' card.
			 * The Feature Table matches the Tabs grid and shows ALL columns.
			 * -------------------------------------------------------------------------- */
			@media (max-width: 768px) {
				
				/* 1. Mobile Tabs (Sticky Header) 
				   Using flexbox directly avoids CSS Grid rendering recalculation bugs 
				   when browsers apply position: sticky. */
				.dd-fc-wrap .dd-fc-mobile-tabs {
					display: flex;
					flex-wrap: nowrap;
					position: -webkit-sticky;
					position: sticky;
					top: var(--dd-fc-sticky-offset, 0px);
					z-index: 100;
					background: #fff;
					border-bottom: 1px solid var(--dd-fc-border-color, #ececec);
					box-shadow: 0 4px 6px -4px rgba(0,0,0,0.05);
					box-sizing: border-box;
				}

				/* Admin bar height fallback on mobile if user is logged in */
				.admin-bar .dd-fc-wrap .dd-fc-mobile-tabs {
					top: var(--dd-fc-sticky-offset, 46px);
				}

				.dd-fc-wrap .dd-fc-mobile-tab {
					flex: 1 1 0;
					width: 0;
					padding: 12px 4px;
					background: #fafafa;
					border: 1px solid var(--dd-fc-border-color, #ececec);
					border-bottom: none;
					font-weight: 500;
					color: #50575e;
					cursor: pointer;
					text-align: center;
					font-size: 13px;
					outline: none;
					word-break: break-word;
					box-sizing: border-box;
				}
				.dd-fc-wrap .dd-fc-mobile-tab.dd-fc-mobile-active {
					background: #fff;
					color: #241c15;
					font-weight: 700;
					border-bottom: 2px solid var(--e-global-color-primary, #034146);
					margin-bottom: -1px; /* Overlap bottom border safely */
				}

				/* 2. Feature Table perfectly aligns with Tabs Grid */
				.dd-fc-wrap .dd-fc-table {
					display: grid;
					grid-template-columns: repeat(<?php echo (int) $col_count; ?>, minmax(0, 1fr));
					border-left: none;
					border-right: none;
					border-radius: 0;
					background: transparent;
					/* Padding-top added here to replace the removed margin-top 
					   on the cards, entirely preventing layout margin collapses */
					padding-top: 16px;
				}
				
				.dd-fc-wrap .dd-fc-feature-col {
					display: none;
				}

				/* 3. Plan Details Card (Head) spans full width, only active is shown */
				.dd-fc-wrap .dd-fc-head {
					display: none;
					grid-column: 1 / -1;
					margin: 0 0 16px 0; /* Margin-top removed to avoid collapsing with sticky tabs */
					padding: 24px 16px !important;
					border: 1px solid var(--dd-fc-border-color, #e2e2e2);
					border-radius: 8px;
					background: #fafafa;
					position: relative;
					top: auto;
				}
				.dd-fc-wrap .dd-fc-head.dd-fc-mobile-active {
					display: flex;
				}
				
				/* Recommended banner / current-plan badge flow cleanly inside the active plan details card */
				.dd-fc-wrap .dd-fc-head .dd-fc-recommended,
				.dd-fc-wrap .dd-fc-head .dd-fc-badge {
					position: relative;
					display: inline-block;
					margin: -24px -16px 16px -16px;
					width: calc(100% + 32px);
					padding: 10px 0;
					border-radius: 8px 8px 0 0;
					font-size: 13px;
					font-weight: 600;
					background: #c5ebd3;
					color: #034146;
					border-bottom: 1px solid var(--dd-fc-border-color, #e2e2e2);
				}

				.dd-fc-wrap .dd-fc-head .dd-fc-badge {
					background: #ffe270;
					color: #241c15;
				}

				/* 4. Feature Names break to their own full-width row */
				.dd-fc-wrap .dd-fc-feature {
					grid-column: 1 / -1;
					background: #f4f4f4; 
					padding: 12px 16px;
					font-weight: 600;
					color: #241c15; 
					border-bottom: 1px solid var(--dd-fc-border-color, #ececec);
					justify-content: flex-start;
				}
				
				.dd-fc-wrap .dd-fc-feature span {
					border-bottom: none;
				}

				/* 5. Feature Values sit under the name in the matched columns */
				.dd-fc-wrap .dd-fc-cell:not(.dd-fc-head):not(.dd-fc-feature) {
					padding: 14px 4px;
					font-size: 13px;
					word-break: break-word;
					justify-content: center;
				}
				
				.dd-fc-wrap .dd-fc-cell:not(:last-child) {
					border-right: 1px solid var(--dd-fc-border-color, #ececec);
				}
				
				/* Light active highlight connecting the table column visually to the active Tab */
				.dd-fc-wrap .dd-fc-cell[data-col-index]:not(.dd-fc-head).dd-fc-mobile-active {
					background-color: rgba(3, 65, 70, 0.04);
				}
			}

			/* --------------------------------------------------------------------------
			 * Desktop: Plan Details header row sticks to the top of the viewport while
			 * feature rows scroll underneath, then condenses once detached from its
			 * natural position (.dd-fc-stuck, toggled by the IntersectionObserver script
			 * below) so it doesn't eat a third of the viewport for the whole scroll.
			 * -------------------------------------------------------------------------- */
			@media (min-width: 769px) {

				.dd-fc-sticky-sentinel {
					height: 0;
				}

				/* Row 1 is locked to the tallest head's natural (unstuck) height so
				   condensing the sticky cell's padding can't shrink the row and jump the
				   feature rows below. Every other row is listed explicitly too (PHP
				   $grid_template_rows) since grid-row: 1 / -1 below resolves -1 against
				   the EXPLICIT grid only — an implicit-only row 2+ would collapse the
				   header's span back down to row 1 alone. */
				.dd-fc-wrap .dd-fc-table {
					grid-template-rows: <?php echo $grid_template_rows; ?>;
				}

				/* Explicit placement from the --dd-fc-c/--dd-fc-r vars every cell carries
				   inline (server-rendered column/row index) — inert on mobile, where
				   auto-placement plus grid-column: 1 / -1 stays in charge. */
				.dd-fc-wrap .dd-fc-cell {
					grid-column: var(--dd-fc-c, auto);
					grid-row: var(--dd-fc-r, auto);
				}

				/* The plan header spans every row so it can stay pinned for the whole
				   table's scroll, not just row 1 — a sticky item's containing block is
				   its own grid area, so without this span it could travel at most
				   row 1's height before scrolling away with it. */
				.dd-fc-wrap .dd-fc-head,
				.dd-fc-wrap .dd-fc-head-row .dd-fc-feature-col {
					grid-row: 1 / -1;
					position: -webkit-sticky;
					position: sticky;
					top: var(--dd-fc-sticky-offset, 0px);
					align-self: start;
					z-index: 0;
				}

				/* Paint-order guard: every body cell is opaque and, at rest, stacks ABOVE
				   the header (z-index 0) — so if a stale height measurement ever lets the
				   header overflow into the row below again, that row's own background
				   simply covers the overflow instead of the header clipping its text.
				   Only once actually stuck does the header retake the top spot, which it
				   needs in order to visually cover rows scrolling underneath it. */
				.dd-fc-wrap .dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell:not(.dd-fc-highlight) {
					background: #fff;
				}
				.dd-fc-wrap .dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell {
					z-index: 1;
				}
				.dd-fc-wrap.dd-fc-stuck .dd-fc-head,
				.dd-fc-wrap.dd-fc-stuck .dd-fc-head-row .dd-fc-feature-col {
					z-index: 10;
				}

				.admin-bar .dd-fc-wrap .dd-fc-head,
				.admin-bar .dd-fc-wrap .dd-fc-head-row .dd-fc-feature-col {
					top: var(--dd-fc-sticky-offset, 32px);
				}

				/* min-height (not height) equalises every column to the tallest head at
				   rest, without clipping a column whose own content happens to run
				   taller. The CTA anchor's margin-top: auto (below) then rides that extra
				   space down to a shared bottom edge so all the buttons line up. */
				.dd-fc-wrap .dd-fc-head {
					min-height: var(--dd-fc-head-h, auto);
					transition: padding .18s ease, min-height .18s ease;
				}

				.dd-fc-wrap .dd-fc-cta {
					margin-top: auto;
				}

				.dd-fc-wrap .dd-fc-head-row .dd-fc-feature-col {
					background: #fff;
					height: var(--dd-fc-head-h, auto);
					transition: height .18s ease;
				}

				.dd-fc-wrap.dd-fc-stuck .dd-fc-head {
					min-height: var(--dd-fc-stuck-h, auto);
					padding-top: 10px;
					padding-bottom: 10px;
					gap: 4px;
					box-shadow: 0 4px 6px -4px rgba(0, 0, 0, .08);
				}

				.dd-fc-wrap.dd-fc-stuck .dd-fc-head-row .dd-fc-feature-col {
					height: var(--dd-fc-stuck-h, auto);
				}

				.dd-fc-wrap.dd-fc-stuck .dd-fc-head .dd-toggle-wrapper,
				.dd-fc-wrap.dd-fc-stuck .dd-fc-head .dd-fc-recommended,
				.dd-fc-wrap.dd-fc-stuck .dd-fc-head .dd-fc-badge,
				.dd-fc-wrap.dd-fc-stuck .dd-fc-head .dd-fc-trial-text {
					display: none;
				}

				.dd-fc-wrap.dd-fc-stuck .dd-fc-price {
					font-size: 16px;
				}

				.dd-fc-wrap.dd-fc-stuck .dd-fc-cta {
					padding: 8px 12px;
				}

				/* Neutralised only during the JS measurement pass below, so it can read
				   each head's true natural/condensed height before any of the above
				   (equal min-height, spanned grid-row, sticky positioning) can influence
				   it — otherwise a measurement would just read back its own prior output. */
				.dd-fc-wrap.dd-fc-measuring .dd-fc-table {
					grid-template-rows: auto;
				}
				.dd-fc-wrap.dd-fc-measuring .dd-fc-head,
				.dd-fc-wrap.dd-fc-measuring .dd-fc-head-row .dd-fc-feature-col {
					grid-row: 1;
					position: static;
					min-height: 0;
					height: auto;
					transition: none;
				}
			}
		</style>
		<?php if ($has_recommended): ?>
			<style>
				@media (min-width: 769px) {
					#<?php echo esc_attr($wrap_id); ?> { --dd-fc-rec-pad: 30px; }
				}
			</style>
		<?php endif; ?>
		<div class="dd-fc-wrap<?php echo $pricing_mode ? ' dd-fc-pricing' : ''; ?>" id="<?php echo esc_attr($wrap_id); ?>">

			<div class="dd-fc-sticky-sentinel" aria-hidden="true"></div>

			<!-- Mobile Sticky Tab Bar -->
			<div class="dd-fc-mobile-tabs">
				<?php foreach ($columns as $index => $col): 
					$resolved = $resolved_columns[$col['key']];
					$active_class = ($index === $initial_active) ? ' dd-fc-mobile-active' : '';
				?>
					<button type="button" class="dd-fc-mobile-tab<?php echo $active_class; ?>" data-col-index="<?php echo (int) $index; ?>">
						<?php echo esc_html($resolved['name']); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="dd-fc-table">
				<div class="dd-fc-row dd-fc-head-row">
					<div class="dd-fc-cell dd-fc-feature-col" style="--dd-fc-c:1"></div>
					<?php foreach ($columns as $index => $col):
						$resolved = $resolved_columns[$col['key']];
						$col_has_annual = $resolved['price'] !== '' && $resolved['price_annual'] !== '';
						$active_class = ($index === $initial_active) ? ' dd-fc-mobile-active' : '';
						$state = isset($plan_states[$col['key']]) ? $plan_states[$col['key']] : null;

						// Pricing mode opens on the term the visitor actually owns, so someone on the
						// annual plan sees their own price rather than the monthly one they'd have to
						// switch the toggle to find.
						$show_annual = ($state && $col_has_annual && ! empty($state['default_annual']));
						$shown_price  = $show_annual ? $resolved['price_annual'] : $resolved['price'];
						$shown_period = $show_annual ? ($col['period_annual'] ?? '') : $col['period'];

						$is_current   = ($state && ! empty($state['has_any_plan']));
						$trial_notice = ($pricing && $col['type'] === 'pmpro' && ! empty($col['level_id'])) ? $pricing->get_trial_notice($col['level_id']) : '';
					?>
						<div class="dd-fc-cell dd-fc-head<?php echo ! empty($col['highlight']) ? ' dd-fc-highlight' : ''; ?><?php echo $is_current ? ' dd-fc-current' : ''; ?><?php echo $active_class; ?>"
							data-col-index="<?php echo (int) $index; ?>"
							style="--dd-fc-c:<?php echo (int) $index + 2; ?>"
							<?php if ($col_has_annual): ?>
							data-price-monthly="<?php echo esc_attr($resolved['price']); ?>"
							data-price-annual="<?php echo esc_attr($resolved['price_annual']); ?>"
							data-period-monthly="<?php echo esc_attr($col['period']); ?>"
							data-period-annual="<?php echo esc_attr($col['period_annual'] ?? ''); ?>"
							data-url-monthly="<?php echo esc_url($resolved['cta_url']); ?>"
							data-url-annual="<?php echo esc_url($resolved['cta_url_annual']); ?>"
							<?php endif; ?>
							<?php if ($state): ?>
							data-owns-monthly="<?php echo ! empty($state['owns_monthly']) ? 'true' : 'false'; ?>"
							data-owns-annual="<?php echo ! empty($state['owns_annual']) ? 'true' : 'false'; ?>"
							data-action-verb="<?php echo esc_attr($state['action_verb']); ?>"
							data-is-on-trial="<?php echo ! empty($state['is_on_trial']) ? 'true' : 'false'; ?>"
							data-is-pending-downgrade="<?php echo ! empty($state['is_pending_downgrade']) ? 'true' : 'false'; ?>"
							data-is-leaving-plan="<?php echo ! empty($state['is_leaving_plan']) ? 'true' : 'false'; ?>"
							<?php endif; ?>>
							<?php if ($is_current): ?>
								<div class="dd-fc-badge">CURRENT PLAN</div>
							<?php elseif (! empty($col['recommended'])): ?>
								<div class="dd-fc-recommended"><?php echo esc_html($col['recommended_text'] !== '' ? $col['recommended_text'] : 'Recommended'); ?></div>
							<?php endif; ?>
							<div class="dd-fc-name"><?php echo esc_html($resolved['name']); ?></div>
							<?php if ($resolved['price'] !== ''): ?>
								<div class="dd-fc-price"><span class="dd-fc-price-amount"><?php echo esc_html($shown_price); ?></span><?php if (! empty($col['period'])): ?><span class="dd-fc-period"><?php echo esc_html($shown_period); ?></span><?php endif; ?></div>
							<?php endif; ?>
							<?php if ($col_has_annual): ?>
								<div class="dd-toggle-wrapper">
									<label class="dd-switch">
										<input type="checkbox" class="dd-fc-plan-toggle" <?php checked($show_annual); ?>>
										<span class="dd-slider round"></span>
									</label>
									<span class="dd-toggle-label">Yearly</span>
									<span class="dd-discount">Save 20%</span>
								</div>
							<?php endif; ?>
							<?php echo wp_kses_post($trial_notice); ?>
							<?php if ($state): ?>
								<a class="dd-fc-cta<?php echo ! empty($state['btn_disabled']) ? ' dd-fc-cta-disabled' : ''; ?>"<?php echo ! empty($state['btn_url']) ? ' href="' . esc_url($state['btn_url']) . '"' : ''; ?>><?php echo esc_html($state['btn_text']); ?></a>
							<?php elseif ($resolved['cta_url'] !== '' || ! empty($col['cta_text'])): ?>
								<a class="dd-fc-cta" href="<?php echo esc_url($resolved['cta_url']); ?>"><?php echo esc_html($col['cta_text'] !== '' ? $col['cta_text'] : 'Buy Now'); ?></a>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php foreach ($rows as $row_index => $row): ?>
					<div class="dd-fc-row">
						<div class="dd-fc-cell dd-fc-feature" style="--dd-fc-c:1;--dd-fc-r:<?php echo (int) $row_index + 2; ?>"><span><?php echo esc_html($row['label']); ?></span></div>
						<?php foreach ($columns as $index => $col):
							$cell = (isset($row['cells'][$col['key']])) ? $row['cells'][$col['key']] : ['type' => 'text', 'text' => ''];
							$active_class = ($index === $initial_active) ? ' dd-fc-mobile-active' : '';
							$current_class = (isset($plan_states[$col['key']]) && ! empty($plan_states[$col['key']]['has_any_plan'])) ? ' dd-fc-current' : '';
						?>
							<div class="dd-fc-cell<?php echo ! empty($col['highlight']) ? ' dd-fc-highlight' : ''; ?><?php echo $current_class; ?><?php echo $active_class; ?>" data-col-index="<?php echo (int) $index; ?>" style="--dd-fc-c:<?php echo (int) $index + 2; ?>;--dd-fc-r:<?php echo (int) $row_index + 2; ?>">
								<?php if ($cell['type'] === 'tick'): ?>
									<span class="dd-fc-tick" aria-label="Included">&#10003;</span>
								<?php elseif ($cell['type'] === 'cross'): ?>
									<span class="dd-fc-cross" aria-label="Not included">&#10005;</span>
								<?php else: ?>
									<?php echo esc_html($cell['text']); ?>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		
		<script>
			(function() {
				var wrap = document.getElementById('<?php echo esc_js($wrap_id); ?>');
				if (!wrap) return;
				
				var tabs = wrap.querySelectorAll('.dd-fc-mobile-tab');
				var headCards = wrap.querySelectorAll('.dd-fc-head');
				var featureCells = wrap.querySelectorAll('.dd-fc-row:not(.dd-fc-head-row) .dd-fc-cell[data-col-index]');
				
				tabs.forEach(function(tab) {
					tab.addEventListener('click', function() {
						var targetIndex = this.getAttribute('data-col-index');
						
						// Update Top Tabs
						tabs.forEach(function(t) { t.classList.remove('dd-fc-mobile-active'); });
						this.classList.add('dd-fc-mobile-active');
						
						// Update Plan Detail Cards
						headCards.forEach(function(card) {
							if (card.getAttribute('data-col-index') === targetIndex) {
								card.classList.add('dd-fc-mobile-active');
							} else {
								card.classList.remove('dd-fc-mobile-active');
							}
						});

						// Keep active column connected visually within the table rows
						featureCells.forEach(function(cell) {
							if (cell.getAttribute('data-col-index') === targetIndex) {
								cell.classList.add('dd-fc-mobile-active');
							} else {
								cell.classList.remove('dd-fc-mobile-active');
							}
						});
					});
				});
			})();
		</script>

		<script>
			(function() {
				var wrap = document.getElementById('<?php echo esc_js($wrap_id); ?>');
				if (!wrap) return;

				var sentinel = wrap.querySelector('.dd-fc-sticky-sentinel');
				var heads = wrap.querySelectorAll('.dd-fc-head-row .dd-fc-head');
				// The "Current Plan" badge (pricing mode) occupies the same absolute slot as the
				// recommended banner and therefore reserves the same head padding — both must be
				// measured or a badge-only table under-reserves --dd-fc-rec-pad.
				var banners = wrap.querySelectorAll('.dd-fc-head-row .dd-fc-recommended, .dd-fc-head-row .dd-fc-badge');
				var mq = window.matchMedia('(min-width: 769px)');
				var observer = null;
				var resizeTimer = null;

				// The banner overlays the top of the head cell (position:absolute) and
				// reserves the head's own padding-top (--dd-fc-rec-pad) to make room for
				// itself, so it MUST be measured before the head — measuring the head
				// first is what previously let a stale --dd-fc-head-h clip into the
				// feature row below it.
				function measureBannerPad() {
					if (!banners.length) return;
					var max = 0;
					banners.forEach(function(el) {
						if (el.offsetHeight > max) max = el.offsetHeight;
					});
					if (max > 0) {
						wrap.style.setProperty('--dd-fc-rec-pad', (max + 8) + 'px');
					}
				}

				// Measures the head row's natural (unstuck) and condensed (stuck) heights
				// so every column can be locked to a shared height at each state.
				// .dd-fc-measuring strips the sticky span/min-height/position that would
				// otherwise make a head's offsetHeight reflect a previous measurement
				// instead of its own true content height.
				function measureHeadHeights() {
					var wasStuck = wrap.classList.contains('dd-fc-stuck');
					wrap.classList.add('dd-fc-measuring');
					wrap.classList.remove('dd-fc-stuck');

					measureBannerPad();

					var naturalMax = 0;
					heads.forEach(function(el) {
						if (el.offsetHeight > naturalMax) naturalMax = el.offsetHeight;
					});
					if (naturalMax > 0) {
						wrap.style.setProperty('--dd-fc-head-h', naturalMax + 'px');
					}

					wrap.classList.add('dd-fc-stuck');
					var stuckMax = 0;
					heads.forEach(function(el) {
						if (el.offsetHeight > stuckMax) stuckMax = el.offsetHeight;
					});
					if (stuckMax > 0) {
						wrap.style.setProperty('--dd-fc-stuck-h', stuckMax + 'px');
					}

					wrap.classList.remove('dd-fc-measuring');
					wrap.classList.toggle('dd-fc-stuck', wasStuck);
				}

				function getOffset() {
					var raw = getComputedStyle(wrap).getPropertyValue('--dd-fc-sticky-offset').trim();
					var px = parseFloat(raw);
					return isNaN(px) ? 0 : px;
				}

				function teardown() {
					if (observer) {
						observer.disconnect();
						observer = null;
					}
					wrap.classList.remove('dd-fc-stuck', 'dd-fc-measuring');
					wrap.style.removeProperty('--dd-fc-head-h');
					wrap.style.removeProperty('--dd-fc-stuck-h');
				}

				function setup() {
					if (!sentinel || !window.IntersectionObserver) return;
					measureHeadHeights();
					observer = new IntersectionObserver(function(entries) {
						entries.forEach(function(entry) {
							wrap.classList.toggle('dd-fc-stuck', !entry.isIntersecting);
						});
					}, { rootMargin: '-' + (getOffset() + 1) + 'px 0px 0px 0px', threshold: 0 });
					observer.observe(sentinel);
				}

				function sync() {
					if (mq.matches) {
						teardown();
						setup();
					} else {
						teardown();
					}
				}

				// Re-measures in place without tearing down the IntersectionObserver —
				// used when only sizes changed (fonts finishing, a banner resizing), not
				// the breakpoint itself.
				function remeasure() {
					if (!mq.matches) return;
					measureHeadHeights();
				}

				sync();

				if (document.fonts && document.fonts.ready) {
					document.fonts.ready.then(remeasure);
				}
				window.addEventListener('load', remeasure);

				window.addEventListener('resize', function() {
					clearTimeout(resizeTimer);
					resizeTimer = setTimeout(sync, 150);
				});

				if (mq.addEventListener) {
					mq.addEventListener('change', sync);
				} else if (mq.addListener) {
					mq.addListener(sync);
				}

				if (banners.length && window.ResizeObserver) {
					var bannerRO = new ResizeObserver(function() {
						remeasure();
					});
					banners.forEach(function(el) {
						bannerRO.observe(el);
					});
				}
			})();
		</script>
		<?php if ($has_annual): ?>
			<script>
				(function() {
					var wrap = document.getElementById('<?php echo esc_js($wrap_id); ?>');
					if (!wrap) return;

					// Scoped to this instance's wrapper, so a comparison table and a pricing table
					// rendered on the same page each drive only their own toggles.
					var toggles = wrap.querySelectorAll('.dd-fc-plan-toggle');
					toggles.forEach(function(toggle) {
						toggle.addEventListener('change', function() {
							var head = this.closest('.dd-fc-head');
							if (!head) return;
							var isYearly = this.checked;

							var priceEl = head.querySelector('.dd-fc-price-amount');
							if (priceEl) {
								priceEl.textContent = isYearly ? head.getAttribute('data-price-annual') : head.getAttribute('data-price-monthly');
							}

							var periodEl = head.querySelector('.dd-fc-period');
							if (periodEl) {
								periodEl.textContent = isYearly ? head.getAttribute('data-period-annual') : head.getAttribute('data-period-monthly');
							}

							var ctaEl = head.querySelector('.dd-fc-cta');
							var url = isYearly ? head.getAttribute('data-url-annual') : head.getAttribute('data-url-monthly');

							// Comparison mode: the CTA is a plain static link, so only its target changes.
							if (!head.hasAttribute('data-action-verb')) {
								if (ctaEl && url) {
									ctaEl.setAttribute('href', url);
								}
								return;
							}

							// Pricing mode: mirror of the server-side cascade in
							// DD_PMPro_Frontend_Pricing::get_plan_button_state(). The precedence order
							// and every button string below must stay identical to that method — they
							// are the same states rendered twice, once per term.
							if (!ctaEl) return;

							var ownsMonthly       = head.getAttribute('data-owns-monthly') === 'true';
							var ownsAnnual        = head.getAttribute('data-owns-annual') === 'true';
							var actionVerb        = head.getAttribute('data-action-verb') || 'SELECT PLAN';
							var isOnTrial         = head.getAttribute('data-is-on-trial') === 'true';
							var isTargetDowngrade = head.getAttribute('data-is-pending-downgrade') === 'true';
							var isLeavingPlan     = head.getAttribute('data-is-leaving-plan') === 'true';

							var ownsSelected = isYearly ? ownsAnnual : ownsMonthly;
							var ownsOther    = isYearly ? ownsMonthly : ownsAnnual;

							var text     = '';
							var disabled = true;

							if (isOnTrial) {
								text = ownsSelected ? 'CURRENT PLAN (TRIAL)' : 'LOCKED DURING TRIAL';
							} else if (isTargetDowngrade) {
								text = 'PENDING DOWNGRADE';
							} else if (isLeavingPlan) {
								text = ownsSelected ? 'CURRENT PLAN' : 'CHANGES LOCKED';
							} else if (ownsSelected) {
								text = 'CURRENT PLAN';
							} else {
								text = ownsOther ? 'SWITCH PLAN' : actionVerb;
								disabled = false;
							}

							ctaEl.textContent = text;
							if (disabled) {
								ctaEl.classList.add('dd-fc-cta-disabled');
								ctaEl.removeAttribute('href');
							} else {
								ctaEl.classList.remove('dd-fc-cta-disabled');
								if (url) {
									ctaEl.setAttribute('href', url);
								}
							}
						});
					});
				})();
			</script>
		<?php endif; ?>
<?php
		return ob_get_clean();
	}
}

new DD_Feature_Comparison_Table();