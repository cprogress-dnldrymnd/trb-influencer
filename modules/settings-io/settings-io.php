<?php
if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/class-dd-settings-refs.php';
require_once __DIR__ . '/class-dd-template-transfer.php';
require_once __DIR__ . '/class-dd-settings-exporter.php';
require_once __DIR__ . '/class-dd-settings-importer.php';

/**
 * Registers the "Export / Import" tab on the Influencer Theme settings hub (see
 * includes/core/admin-settings.php for the tab mechanism this hooks into) and handles the
 * export download, the two-step import (analyze/preview then commit), and snapshot restore.
 */
class DD_Settings_IO
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('dd_theme_settings_tabs', [$this, 'register_tab']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_dd_settings_io_export', [$this, 'handle_export']);
        add_action('wp_ajax_dd_settings_io_analyze', [$this, 'ajax_analyze']);
        add_action('wp_ajax_dd_settings_io_commit', [$this, 'ajax_commit']);
        add_action('wp_ajax_dd_settings_io_restore', [$this, 'ajax_restore']);
    }

    public function register_tab($tabs)
    {
        $tabs[] = [
            'id'     => 'transfer',
            'label'  => 'Export / Import',
            'render' => [$this, 'render_panel'],
        ];
        return $tabs;
    }

    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_dd-theme-settings') {
            return;
        }

        wp_enqueue_script(
            'dd-settings-io-admin',
            get_stylesheet_directory_uri() . '/assets/js/modules/settings-io-admin.js',
            ['jquery', 'dd-modal'],
            defined('HELLO_ELEMENTOR_CHILD_VERSION') ? HELLO_ELEMENTOR_CHILD_VERSION : false,
            true
        );

        wp_localize_script('dd-settings-io-admin', 'ddSettingsIO', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('dd_settings_io_nonce'),
            'hasSnapshot' => (new DD_Settings_Importer())->has_snapshot(),
            'snapshotAt'  => (new DD_Settings_Importer())->snapshot_created_at(),
        ]);
    }

    // -----------------------------------------------------------------
    // Tab panel
    // -----------------------------------------------------------------

    public function render_panel()
    {
        $importer = new DD_Settings_Importer();
    ?>
        <div class="dd-tab-desc">
            <p>Export the Influencer Theme's settings — page assignments, Elementor template content, membership-level feature gates, messages, and more — as a bundle you can import on another environment. Membership level IDs, page IDs, template IDs and attachment IDs all differ between environments; import resolves each one by identity (name/slug/UID) rather than copying raw numbers.</p>
        </div>

        <h2>Export</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dd_settings_io_export">
            <?php wp_nonce_field('dd_settings_io_export'); ?>
            <p class="description">Exporting stamps a stable identifier onto every referenced page, level, template and attachment on <strong>this</strong> site (harmless, but it does write to the database).</p>
            <?php submit_button('Download Settings Bundle', 'secondary', 'dd-io-export'); ?>
        </form>

        <hr>

        <h2>Import</h2>
        <p class="description">PMPro membership levels are only ever <strong>matched</strong> to existing levels on this site — this feature never creates or edits levels, discount codes, orders, or memberships. Pages are matched by slug/title and are never created. Elementor templates are updated in place when matched, otherwise created.</p>
        <div id="dd-io-upload">
            <input type="file" id="dd-io-file" accept=".zip">
            <button type="button" class="button button-secondary" id="dd-io-analyze">Upload &amp; Preview</button>
            <span class="spinner" id="dd-io-spinner" style="float:none;"></span>
        </div>
        <div id="dd-io-preview"></div>

        <hr>

        <h2>Restore</h2>
        <?php if ($importer->has_snapshot()): ?>
            <p class="description">Pre-import snapshot from <?php echo esc_html($importer->snapshot_created_at()); ?> (UTC). Restoring rewrites the settings options captured at that point — it does not remove or revert any Elementor templates that were created or updated during that import.</p>
            <button type="button" class="button" id="dd-io-restore">Restore Pre-Import Snapshot</button>
        <?php else: ?>
            <p class="description">No import has been run on this site yet.</p>
        <?php endif; ?>
    <?php
    }

    // -----------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------

    public function handle_export()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
        check_admin_referer('dd_settings_io_export');

        $exporter = new DD_Settings_Exporter();
        $bundle = $exporter->build();
        $zip_path = $exporter->write_zip($bundle);

        if (is_wp_error($zip_path)) {
            wp_die(esc_html($zip_path->get_error_message()));
        }

        $host = parse_url(get_site_url(), PHP_URL_HOST);
        $filename = sanitize_file_name('trb-influencer-settings-' . $host . '-' . gmdate('Y-m-d-His') . '.zip');

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Length: ' . filesize($zip_path));
        while (ob_get_level()) {
            ob_end_clean();
        }
        flush();
        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }

    // -----------------------------------------------------------------
    // Import — analyze / preview
    // -----------------------------------------------------------------

    public function ajax_analyze()
    {
        $this->guard_ajax();

        if (empty($_FILES['bundle']['tmp_name']) || ! is_uploaded_file($_FILES['bundle']['tmp_name'])) {
            wp_send_json_error(['message' => 'No file was uploaded.']);
        }

        $filetype = wp_check_filetype($_FILES['bundle']['name'], ['zip' => 'application/zip']);
        if (empty($filetype['ext'])) {
            wp_send_json_error(['message' => 'Please upload the .zip file produced by Export.']);
        }

        $importer = new DD_Settings_Importer();
        $bundle = $importer->extract($_FILES['bundle']['tmp_name']);
        if (is_wp_error($bundle)) {
            wp_send_json_error(['message' => $bundle->get_error_message()]);
        }

        $plan = $importer->plan($bundle);

        $stage_key = $this->stage_key();
        set_transient($stage_key, $bundle, HOUR_IN_SECONDS);

        wp_send_json_success([
            'manifest' => $bundle['manifest'],
            'plan'     => $plan,
        ]);
    }

    // -----------------------------------------------------------------
    // Import — commit
    // -----------------------------------------------------------------

    public function ajax_commit()
    {
        $this->guard_ajax();

        $bundle = get_transient($this->stage_key());
        if (empty($bundle)) {
            wp_send_json_error(['message' => 'Your upload has expired — please re-upload the bundle and preview it again.']);
        }

        $overrides_raw = isset($_POST['overrides']) ? json_decode(wp_unslash($_POST['overrides']), true) : [];
        $overrides = $this->sanitize_overrides($overrides_raw, $bundle);

        $importer = new DD_Settings_Importer();
        $report = $importer->apply($bundle, $overrides);

        delete_transient($this->stage_key());
        $this->cleanup_dir($bundle['dir']);

        wp_send_json_success(['report' => $report]);
    }

    private function sanitize_overrides($raw, $bundle)
    {
        $clean = ['pages' => [], 'templates' => [], 'levels' => [], 'attachments' => [], 'accept_partial' => []];
        if (! is_array($raw)) {
            return $clean;
        }

        foreach (['pages', 'templates', 'levels', 'attachments'] as $group) {
            if (empty($raw[$group]) || ! is_array($raw[$group])) {
                continue;
            }
            foreach ($raw[$group] as $uid => $target_id) {
                $uid = sanitize_text_field($uid);
                if (isset($bundle['refs'][$group][$uid])) {
                    $clean[$group][$uid] = absint($target_id);
                }
            }
        }

        if (! empty($raw['accept_partial']) && is_array($raw['accept_partial'])) {
            $schema_keys = array_keys(dd_settings_io_schema());
            foreach ($raw['accept_partial'] as $key => $accepted) {
                $key = sanitize_key($key);
                if ($accepted && in_array($key, $schema_keys, true)) {
                    $clean['accept_partial'][$key] = true;
                }
            }
        }

        return $clean;
    }

    // -----------------------------------------------------------------
    // Restore
    // -----------------------------------------------------------------

    public function ajax_restore()
    {
        $this->guard_ajax();

        $importer = new DD_Settings_Importer();
        $result = $importer->restore_snapshot();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function guard_ajax()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied.'], 403);
        }
        check_ajax_referer('dd_settings_io_nonce', 'nonce');
    }

    private function stage_key()
    {
        return 'dd_settings_io_stage_' . get_current_user_id();
    }

    private function cleanup_dir($dir)
    {
        if (! $dir || ! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

DD_Settings_IO::instance();
