<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent direct file execution.
}
/**
 * Plugin Name: Feature Comparison Pricing Table
 * Description: Provides an editable Mailchimp-style feature-comparison pricing table. Content
 * (columns — auto-seeded from PMPro plans or fully custom — and feature rows with tick/cross/text
 * cells) is authored once from a "Comparison Table" tab on the Influencer Theme settings hub, then
 * rendered via the [dd_feature_comparison] shortcode and its Elementor widget wrapper. Distinct
 * from [dd_pricing_table] (DD_PMPro_Frontend_Pricing), which is a dynamic monthly/yearly toggle
 * card layout, not an editable comparison grid.
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

	public function __construct()
	{
		add_filter('dd_theme_settings_tabs', [$this, 'register_settings_tab']);
		add_action('admin_init', [$this, 'register_setting']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_action('admin_footer', [$this, 'render_admin_assets']);
		add_action('init', [$this, 'register_shortcode']);
	}

	/**
	 * Appends the "Comparison Table" tab to the Influencer Theme settings hub.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function register_settings_tab($tabs)
	{
		$tabs[] = [
			'id'     => 'comparison-table',
			'label'  => 'Comparison Table',
			'render' => [$this, 'render_tab_panel'],
		];
		return $tabs;
	}

	/**
	 * Registers the single JSON-carrying option. All validation happens in sanitize().
	 * @return void
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
	 * @return void
	 */
	public function render_tab_panel()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$data = $this->get_data();
	?>
		<p class="dd-tab-desc">Build the feature-comparison pricing table shown by the <code>[dd_feature_comparison]</code> shortcode and the "Comparison Pricing Table" Elementor widget. Columns can be pulled from your PMPro plans or added as fully custom columns with their own price. Each feature row's cell can be a tick, a cross, or free text.</p>

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
					<div class="dd-fc-add-row-bottom" style="justify-content: flex-start; margin-bottom: 20px;">
						<button type="button" class="button button-primary" id="dd-fc-add-row-btn">Add Feature Row</button>
					</div>
					<div id="dd-fc-rows-list" class="dd-fc-rows-list"></div>
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
				margin-bottom: 24px;
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
				justify-content: space-between;
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
				max-width: 400px;
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
				$('.dd-fc-admin-tab').on('click', function(e) {
					e.preventDefault();
					$('.dd-fc-admin-tab').removeClass('nav-tab-active');
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
	 * The annual side mirrors [dd_pricing_table]'s own monthly/yearly toggle
	 * (DD_PMPro_Frontend_Pricing::build_pricing_card()): a PMPro column with no manually-entered
	 * annual price auto-detects the level's "Annual" Payment Plan extension via the same
	 * get_annual_payment_plan() lookup that table uses. A custom column (or a PMPro column with no
	 * Annual plan configured) only shows an annual price if the admin entered one — leaving both
	 * blank simply omits the toggle for that column.
	 *
	 * @param array $col
	 * @return array{name:string, price:string, cta_url:string, price_annual:string, cta_url_annual:string}
	 */
	private function resolve_column($col)
	{
		$name           = $col['name'];
		$price          = $col['price'];
		$cta_url        = $col['cta_url'];
		$price_annual   = $col['price_annual'] ?? '';
		$cta_url_annual = $col['cta_url_annual'] ?? '';

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

				if ($price_annual === '' && class_exists('DD_PMPro_Frontend_Pricing')) {
					$annual_plan = DD_PMPro_Frontend_Pricing::get_annual_payment_plan($col['level_id']);
					if (! empty($annual_plan)) {
						$price_annual = $annual_plan['price'];
						if ($cta_url_annual === '' && function_exists('pmpro_url')) {
							$cta_url_annual = pmpro_url('checkout', '?level=' . (int) $col['level_id'] . '&pmpropp_chosen_plan=' . $annual_plan['id']);
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
		];
	}

	/**
	 * Renders the [dd_feature_comparison] shortcode — a CSS-grid comparison table with one column
	 * per configured plan and one row per configured feature. Empty state (no columns) renders
	 * nothing rather than an empty shell.
	 *
	 * @return string
	 */
	public function render_shortcode()
	{
		$data = $this->get_data();
		if (empty($data['columns'])) {
			return '';
		}

		$columns = $data['columns'];
		$rows    = $data['rows'];
		$col_count = count($columns);

		// Resolved once per column (each PMPro column resolution can hit the DB) and reused both
		// for these page-level flags and inside the render loop below.
		$resolved_columns = [];
		$has_recommended  = false;
		$has_annual       = false;
		$initial_active   = 0; // Default active tab index for mobile

		foreach ($columns as $index => $col) {
			$resolved_columns[$col['key']] = $this->resolve_column($col);
			if (! empty($col['recommended'])) {
				$has_recommended = true;
				// If a recommended column exists, default to it on mobile initially
				$initial_active = $index;
			}
			if ($resolved_columns[$col['key']]['price'] !== '' && $resolved_columns[$col['key']]['price_annual'] !== '') {
				$has_annual = true;
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
				overflow: hidden;
				background: #fff;
			}

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

			/* Yearly-toggle chrome — same classes/markup as the dd_pricing_table shortcode's own
			   toggle (DD_PMPro_Frontend_Pricing::build_pricing_card()) for a visually identical
			   switch, scoped under .dd-fc-wrap so it can't collide if both render on one page.
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
				
				/* Recommended banner flows cleanly inside the active plan details card */
				.dd-fc-wrap .dd-fc-head .dd-fc-recommended {
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
		</style>
		<?php if ($has_recommended): ?>
			<style>
				@media (min-width: 769px) {
					#<?php echo esc_attr($wrap_id); ?> { --dd-fc-rec-pad: 30px; }
				}
			</style>
		<?php endif; ?>
		<div class="dd-fc-wrap" id="<?php echo esc_attr($wrap_id); ?>">
			
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
					<div class="dd-fc-cell dd-fc-feature-col"></div>
					<?php foreach ($columns as $index => $col):
						$resolved = $resolved_columns[$col['key']];
						$col_has_annual = $resolved['price'] !== '' && $resolved['price_annual'] !== '';
						$active_class = ($index === $initial_active) ? ' dd-fc-mobile-active' : '';
					?>
						<div class="dd-fc-cell dd-fc-head<?php echo ! empty($col['highlight']) ? ' dd-fc-highlight' : ''; ?><?php echo $active_class; ?>"
							data-col-index="<?php echo (int) $index; ?>"
							<?php if ($col_has_annual): ?>
							data-price-monthly="<?php echo esc_attr($resolved['price']); ?>"
							data-price-annual="<?php echo esc_attr($resolved['price_annual']); ?>"
							data-period-monthly="<?php echo esc_attr($col['period']); ?>"
							data-period-annual="<?php echo esc_attr($col['period_annual'] ?? ''); ?>"
							data-url-monthly="<?php echo esc_url($resolved['cta_url']); ?>"
							data-url-annual="<?php echo esc_url($resolved['cta_url_annual']); ?>"
							<?php endif; ?>>
							<?php if (! empty($col['recommended'])): ?>
								<div class="dd-fc-recommended"><?php echo esc_html($col['recommended_text'] !== '' ? $col['recommended_text'] : 'Recommended'); ?></div>
							<?php endif; ?>
							<div class="dd-fc-name"><?php echo esc_html($resolved['name']); ?></div>
							<?php if ($resolved['price'] !== ''): ?>
								<div class="dd-fc-price"><span class="dd-fc-price-amount"><?php echo esc_html($resolved['price']); ?></span><?php if (! empty($col['period'])): ?><span class="dd-fc-period"><?php echo esc_html($col['period']); ?></span><?php endif; ?></div>
							<?php endif; ?>
							<?php if ($col_has_annual): ?>
								<div class="dd-toggle-wrapper">
									<label class="dd-switch">
										<input type="checkbox" class="dd-fc-plan-toggle">
										<span class="dd-slider round"></span>
									</label>
									<span class="dd-toggle-label">Yearly</span>
									<span class="dd-discount">Save 20%</span>
								</div>
							<?php endif; ?>
							<?php if ($resolved['cta_url'] !== '' || ! empty($col['cta_text'])): ?>
								<a class="dd-fc-cta" href="<?php echo esc_url($resolved['cta_url']); ?>"><?php echo esc_html($col['cta_text'] !== '' ? $col['cta_text'] : 'Buy Now'); ?></a>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php foreach ($rows as $row): ?>
					<div class="dd-fc-row">
						<div class="dd-fc-cell dd-fc-feature"><span><?php echo esc_html($row['label']); ?></span></div>
						<?php foreach ($columns as $index => $col):
							$cell = (isset($row['cells'][$col['key']])) ? $row['cells'][$col['key']] : ['type' => 'text', 'text' => ''];
							$active_class = ($index === $initial_active) ? ' dd-fc-mobile-active' : '';
						?>
							<div class="dd-fc-cell<?php echo ! empty($col['highlight']) ? ' dd-fc-highlight' : ''; ?><?php echo $active_class; ?>" data-col-index="<?php echo (int) $index; ?>">
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

		<?php if ($has_recommended): ?>
			<script>
				(function() {
					var wrap = document.getElementById('<?php echo esc_js($wrap_id); ?>');
					if (!wrap) return;
					var banners = wrap.querySelectorAll('.dd-fc-head-row .dd-fc-recommended');
					if (!banners.length) return;

					// The banner overlays the top of the head cell (position:absolute), so the
					// reserved top padding must be at least its rendered height (plus a small gap)
					// or a wrapped two-line banner overlaps the plan name below it. Measured live
					// since the actual height depends on the admin's text length and column width.
					function sync() {
						if (window.innerWidth <= 768) return; // Only needed on Desktop grid layout
						var max = 0;
						banners.forEach(function(el) {
							if (el.offsetHeight > max) max = el.offsetHeight;
						});
						wrap.style.setProperty('--dd-fc-rec-pad', (max + 8) + 'px');
					}

					sync();

					if (window.ResizeObserver) {
						var ro = new ResizeObserver(sync);
						banners.forEach(function(el) {
							ro.observe(el);
						});
					} else {
						window.addEventListener('resize', sync);
					}
				})();
			</script>
		<?php endif; ?>
		<?php if ($has_annual): ?>
			<script>
				(function() {
					var wrap = document.getElementById('<?php echo esc_js($wrap_id); ?>');
					if (!wrap) return;

					// Deliberately a distinct class from the dd_pricing_table shortcode's own
					// `.dd-plan-toggle` (see pmpro-dynamic-pricing.php) — that shortcode's global
					// script queries the whole document for that class and assumes a
					// `.dd-card`/`.dd-checkout-btn` ownership/trial structure this table doesn't
					// have, so sharing the class would throw if both tables ever render on one page.
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
							if (ctaEl && url) {
								ctaEl.setAttribute('href', url);
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