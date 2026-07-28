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
	 * Every paid, signup-enabled PMPro plan available to seed as a column — id + name. Reuses the
	 * same public accessor the dynamic pricing table widget uses for its plan picker.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function get_pmpro_plans()
	{
		if (! class_exists('DD_PMPro_Frontend_Pricing') || ! function_exists('pmpro_getAllLevels')) {
			return [];
		}
		return DD_PMPro_Frontend_Pricing::get_orderable_plans();
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
				<h2>Columns</h2>
				<div class="dd-fc-add-row">
					<select id="dd-fc-add-pmpro-select"></select>
					<button type="button" class="button" id="dd-fc-add-pmpro-btn">Add PMPro Plan Column</button>
					<button type="button" class="button" id="dd-fc-add-custom-btn">Add Custom Column</button>
				</div>
				<div id="dd-fc-columns-list" class="dd-fc-columns-list"></div>

				<h2>Feature Rows</h2>
				<div id="dd-fc-rows-list" class="dd-fc-rows-list"></div>
				<div class="dd-fc-add-row-bottom">
					<button type="button" class="button" id="dd-fc-add-row-btn">Add Row</button>
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
				margin: 10px 0 18px;
				flex-wrap: wrap;
			}

			#dd-panel-comparison-table .dd-fc-columns-list {
				display: flex;
				flex-direction: column;
				gap: 12px;
				margin-bottom: 24px;
			}

			#dd-panel-comparison-table .dd-fc-rows-list {
				display: flex;
				flex-direction: column;
				gap: 12px;
				margin-bottom: 12px;
			}

			#dd-panel-comparison-table .dd-fc-add-row-bottom {
				display: flex;
				justify-content: flex-end;
				margin-bottom: 24px;
			}

			#dd-panel-comparison-table .dd-fc-drag {
				cursor: move;
				color: #8c8f94;
				user-select: none;
				font-size: 16px;
				line-height: 1;
				margin-right: 8px;
				flex-shrink: 0;
			}

			#dd-panel-comparison-table .ui-sortable-helper {
				box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
			}

			#dd-panel-comparison-table .ui-sortable-placeholder {
				border: 1px dashed #8c8f94;
				border-radius: 4px;
				background: #f6f7f7;
				visibility: visible !important;
			}

			#dd-panel-comparison-table .dd-fc-card {
				border: 1px solid #dcdcde;
				border-radius: 4px;
				background: #fff;
				padding: 14px 16px;
			}

			#dd-panel-comparison-table .dd-fc-card-head {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 10px;
				margin-bottom: 10px;
			}

			#dd-panel-comparison-table .dd-fc-card-title {
				font-weight: 600;
				flex: 1;
			}

			#dd-panel-comparison-table .dd-fc-badge {
				display: inline-block;
				font-size: 11px;
				padding: 2px 8px;
				border-radius: 10px;
				background: #f0f0f1;
				color: #50575e;
				margin-left: 8px;
			}

			#dd-panel-comparison-table .dd-fc-field-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 10px;
			}

			#dd-panel-comparison-table .dd-fc-field label {
				display: block;
				font-size: 12px;
				color: #50575e;
				margin-bottom: 3px;
			}

			#dd-panel-comparison-table .dd-fc-field input[type="text"] {
				width: 100%;
			}

			#dd-panel-comparison-table .dd-fc-inline-check {
				display: flex;
				align-items: center;
				gap: 6px;
				font-size: 13px;
			}

			#dd-panel-comparison-table .dd-fc-row-card .dd-fc-cells {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				margin-top: 10px;
			}

			#dd-panel-comparison-table .dd-fc-cell-field {
				display: flex;
				flex-direction: column;
				gap: 4px;
				min-width: 150px;
			}

			#dd-panel-comparison-table .dd-fc-cell-field .dd-fc-cell-col-label {
				font-size: 11px;
				color: #50575e;
				font-weight: 600;
			}

			#dd-panel-comparison-table .dd-fc-remove {
				color: #b32d2e;
				cursor: pointer;
				background: none;
				border: none;
				font-size: 12px;
				text-decoration: underline;
				padding: 0;
			}

			#dd-panel-comparison-table .dd-fc-empty-note {
				color: #757575;
				font-style: italic;
			}

			#dd-panel-comparison-table .dd-fc-field-note {
				color: #757575;
				font-size: 12px;
				margin: 12px 0 8px;
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
						var $card = $('<div class="dd-fc-card" data-key="' + col.key + '"></div>');

						var $head = $('<div class="dd-fc-card-head"></div>');
						$head.append('<span class="dd-fc-drag" title="Drag to reorder">&#9783;</span>');
						$head.append('<span class="dd-fc-card-title">' + esc(columnLabel(col)) + '<span class="dd-fc-badge">' + (col.type === 'pmpro' ? 'PMPro Plan' : 'Custom') + '</span></span>');
						$head.append('<button type="button" class="dd-fc-remove" data-action="remove-column" data-index="' + idx + '">Remove column</button>');
						$card.append($head);

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

						$card.append($grid);

						$card.append('<p class="dd-fc-field-note">Fill in an Annual Price to show a Yearly toggle on this column (leave blank to hide it). PMPro columns auto-detect an "Annual" Payment Plan if one is configured.</p>');
						var $annualGrid = $('<div class="dd-fc-field-grid"></div>');
						$annualGrid.append(field('Annual Price', 'price_annual', col.price_annual, col.type === 'pmpro' ? '(auto: annual plan price)' : 'e.g. 190'));
						$annualGrid.append(field('Annual Price Period', 'period_annual', col.period_annual, 'e.g. /year'));
						$annualGrid.append(field('Annual CTA URL', 'cta_url_annual', col.cta_url_annual, col.type === 'pmpro' ? '(auto: annual checkout link)' : '(defaults to CTA URL)'));
						$card.append($annualGrid);

						var $checks = $('<div class="dd-fc-field-grid" style="margin-top:10px;"></div>');
						var $recCheck = $('<label class="dd-fc-inline-check"></label>');
						$recCheck.append($('<input type="checkbox" data-field="recommended" data-index="' + idx + '" />').prop('checked', !!col.recommended));
						$recCheck.append('Show recommended banner');
						$checks.append($recCheck);

						var $hlCheck = $('<label class="dd-fc-inline-check"></label>');
						$hlCheck.append($('<input type="checkbox" data-field="highlight" data-index="' + idx + '" />').prop('checked', !!col.highlight));
						$hlCheck.append('Highlight this column');
						$checks.append($hlCheck);

						$card.append($checks);
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
						var $card = $('<div class="dd-fc-card dd-fc-row-card" data-row-index="' + rIdx + '"></div>');

						var $head = $('<div class="dd-fc-card-head"></div>');
						$head.append('<span class="dd-fc-drag" title="Drag to reorder">&#9783;</span>');
						var $labelInput = $('<input type="text" data-row-field="label" data-row-index="' + rIdx + '" style="flex:1;margin-right:10px;" />').val(row.label || '').attr('placeholder', 'Feature label, e.g. Monthly Email Sends');
						$head.append($labelInput);
						$head.append('<button type="button" class="dd-fc-remove" data-action="remove-row" data-row-index="' + rIdx + '">Remove row</button>');
						$card.append($head);

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
						$card.append($cells);
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
						highlight: false
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
						highlight: false
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
						cells: cells
					});
					renderAll();
				});

				$('#dd-fc-columns-list').on('click', 'button[data-action="remove-column"]', function() {
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

				$('#dd-fc-rows-list').on('click', 'button[data-action="remove-row"]', function() {
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
		foreach ($columns as $col) {
			$resolved_columns[$col['key']] = $this->resolve_column($col);
			if (! empty($col['recommended'])) {
				$has_recommended = true;
			}
			if ($resolved_columns[$col['key']]['price'] !== '' && $resolved_columns[$col['key']]['price_annual'] !== '') {
				$has_annual = true;
			}
		}
		$wrap_id = 'dd-fc-' . (++self::$instance_counter);

		ob_start();
	?>
		<style>
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
			 * Mobile Layout: Mailchimp-style stacked feature grid
			 * Instead of horizontal scroll, CSS Grid is recalculated to span feature labels 
			 * across all plan columns. Headers remain sticky but compact. 
			 * -------------------------------------------------------------------------- */
			@media (max-width: 768px) {
				.dd-fc-wrap .dd-fc-table {
					/* Render exactly X columns for X plans, stripping the dedicated label column */
					grid-template-columns: repeat(<?php echo (int) $col_count; ?>, minmax(0, 1fr));
					border-left: none;
					border-right: none;
					border-radius: 0;
					background: transparent;
				}

				.dd-fc-wrap .dd-fc-feature-col {
					display: none;
				}

				.dd-fc-wrap .dd-fc-feature {
					/* Feature name breaks into its own row spanning all plan columns */
					grid-column: 1 / -1; 
					background: #f4f4f4; /* Section divider gray matching Mailchimp */
					padding: 12px 16px;
					font-weight: 600;
					color: #241c15; /* Match typical dark header contrast */
					border-bottom: 1px solid var(--dd-fc-border-color, #ececec);
					justify-content: flex-start;
				}
				
				.dd-fc-wrap .dd-fc-feature span {
					border-bottom: none;
				}

				.dd-fc-wrap .dd-fc-head {
					position: sticky;
					top: var(--dd-fc-sticky-offset, 0px);
					z-index: 10;
					background: #fff;
					padding: 12px 4px !important; /* Override JS --dd-fc-rec-pad computation */
					border-bottom: 2px solid #241c15; /* Distinct lower border to visually ground sticky header */
					box-shadow: 0 4px 10px -4px rgba(0,0,0,0.1);
				}

				/* Heaviest UI pieces are hidden from the sticky nav on mobile, keeping it strictly to Text Labels */
				.dd-fc-wrap .dd-fc-price,
				.dd-fc-wrap .dd-toggle-wrapper,
				.dd-fc-wrap .dd-fc-cta {
					display: none !important;
				}

				.dd-fc-wrap .dd-fc-name {
					font-size: 13px;
					text-align: center;
				}

				.dd-fc-wrap .dd-fc-recommended {
					position: static; 
					display: table;
					margin: 0 auto 6px auto;
					font-size: 9px;
					padding: 2px 6px;
				}

				.dd-fc-wrap .dd-fc-cell {
					padding: 14px 4px;
					font-size: 13px;
					word-break: break-word;
				}

				.dd-fc-wrap .dd-fc-cell:not(:last-child) {
					border-right: 1px solid var(--dd-fc-border-color, #ececec);
				}
			}
		</style>
		<?php if ($has_recommended): ?>
			<style>
				#<?php echo esc_attr($wrap_id); ?> { --dd-fc-rec-pad: 30px; }
			</style>
		<?php endif; ?>
		<div class="dd-fc-wrap" id="<?php echo esc_attr($wrap_id); ?>">
			<div class="dd-fc-table">
				<div class="dd-fc-row dd-fc-head-row">
					<div class="dd-fc-cell dd-fc-feature-col"></div>
					<?php foreach ($columns as $col):
						$resolved = $resolved_columns[$col['key']];
						$col_has_annual = $resolved['price'] !== '' && $resolved['price_annual'] !== '';
					?>
						<div class="dd-fc-cell dd-fc-head<?php echo ! empty($col['highlight']) ? ' dd-fc-highlight' : ''; ?>"
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
						<?php foreach ($columns as $col):
							$cell = (isset($row['cells'][$col['key']])) ? $row['cells'][$col['key']] : ['type' => 'text', 'text' => ''];
						?>
							<div class="dd-fc-cell<?php echo ! empty($col['highlight']) ? ' dd-fc-highlight' : ''; ?>">
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