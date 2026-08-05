<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds a portable settings bundle: every dd_settings_io_schema() option, with every environment-
 * local ID replaced by a {"$ref":kind,"uid":…} marker, plus the referenced Elementor templates'
 * full content and (when possible) their bundled media.
 */
class DD_Settings_Exporter
{
    /** @var array uid => descriptor, keyed by kind: pages/templates/levels/attachments */
    private $refs = ['pages' => [], 'templates' => [], 'levels' => [], 'attachments' => []];

    /** @var string|null path to a media.zip built by Elementor's Media_Collector, if any */
    private $media_zip_path;

    public function build()
    {
        $this->assert_no_denylisted_keys();

        $options = [];
        foreach (dd_settings_io_schema() as $key => $spec) {
            $value = get_option($key, null);
            if ($value === null) {
                continue; // never saved on this site — nothing to export
            }
            $options[$key] = $this->export_value($value, $spec);
        }

        $manifest = [
            'format'         => 'dd-theme-settings',
            'format_version' => 1,
            'generated'      => gmdate('c'),
            'source'         => [
                'site_url' => get_site_url(),
                'home_url' => get_home_url(),
            ],
        ];

        return [
            'manifest' => $manifest,
            'refs'     => $this->refs,
            'options'  => $options,
            'templates' => $this->export_templates(),
        ];
    }

    private function assert_no_denylisted_keys()
    {
        $schema_keys = array_keys(dd_settings_io_schema());
        $overlap = array_intersect($schema_keys, dd_settings_io_denylist());
        if (! empty($overlap)) {
            // A future schema edit accidentally reintroduced a denylisted (runtime-only) option.
            // Failing loudly here beats silently shipping environment state in a bundle.
            wp_die('dd_settings_io_schema() must not include denylisted keys: ' . implode(', ', $overlap));
        }
    }

    private function export_value($value, $spec)
    {
        switch ($spec['class']) {
            case 'page_ref':
            case 'template_ref':
            case 'attachment_ref':
                return $this->export_ref_shape($value, $spec);

            case 'level_ref':
                return $this->export_ref_shape($value, $spec);

            case 'rewards_general':
                $value = is_array($value) ? $value : [];
                unset($value['test_user_id']);
                return $value;

            case 'plain':
            default:
                return $value;
        }
    }

    private function kind_for_class($class)
    {
        return [
            'page_ref'       => 'page',
            'template_ref'   => 'template',
            'attachment_ref' => 'attachment',
            'level_ref'      => 'level',
        ][$class] ?? null;
    }

    private function export_ref_shape($value, $spec)
    {
        $kind = $this->kind_for_class($spec['class']);

        switch ($spec['shape']) {
            case 'scalar':
                return $this->id_to_ref((int) $value, $kind);

            case 'list':
                if (! is_array($value)) {
                    return [];
                }
                return array_values(array_filter(array_map(function ($id) use ($kind) {
                    return $this->id_to_ref((int) $id, $kind);
                }, $value)));

            case 'keys':
                if (! is_array($value)) {
                    return [];
                }
                $out = [];
                foreach ($value as $id => $inner) {
                    $ref = $this->id_to_ref((int) $id, $kind);
                    if ($ref !== null) {
                        $out[] = ['ref' => $ref, 'value' => $inner];
                    }
                }
                return $out;

            case 'rows':
                if (! is_array($value)) {
                    return [];
                }
                foreach ($value as &$row) {
                    if (is_array($row) && isset($row[$spec['field']])) {
                        $row[$spec['field']] = $this->id_to_ref((int) $row[$spec['field']], $kind);
                    }
                }
                unset($row);
                return $value;

            case 'json_columns':
                $decoded = json_decode(is_string($value) ? $value : '', true);
                if (! is_array($decoded) || empty($decoded['columns'])) {
                    return $value;
                }
                foreach ($decoded['columns'] as &$col) {
                    if (($col['type'] ?? '') === 'pmpro' && ! empty($col[$spec['field']])) {
                        $col[$spec['field']] = $this->id_to_ref((int) $col[$spec['field']], $kind);
                    }
                }
                unset($col);
                return $decoded;

            default:
                return $value;
        }
    }

    private function id_to_ref($id, $kind)
    {
        if ($id <= 0) {
            return null;
        }

        switch ($kind) {
            case 'page':
                $descriptor = DD_Settings_Refs::page_descriptor($id);
                $group = 'pages';
                break;
            case 'template':
                $descriptor = DD_Settings_Refs::template_descriptor($id);
                $group = 'templates';
                break;
            case 'attachment':
                $descriptor = DD_Settings_Refs::attachment_descriptor($id);
                $group = 'attachments';
                break;
            case 'level':
                $descriptor = DD_Settings_Refs::level_descriptor($id);
                $group = 'levels';
                break;
            default:
                return null;
        }

        if (! $descriptor) {
            return null;
        }

        $this->refs[$group][$descriptor['uid']] = $descriptor;

        return ['$ref' => $kind, 'uid' => $descriptor['uid']];
    }

    private function export_templates()
    {
        $collector = null;
        if (class_exists('\Elementor\TemplateLibrary\Classes\Media_Collector')) {
            $collector = new \Elementor\TemplateLibrary\Classes\Media_Collector();
            $collector->start_collection();
        }

        $templates = [];
        foreach ($this->refs['templates'] as $uid => $descriptor) {
            $data = DD_Template_Transfer::export($descriptor['source_id']);
            if (! is_wp_error($data)) {
                $templates[$uid] = $data;
            }
        }

        if ($collector) {
            $urls = $collector->get_collected_urls();
            if (! empty($urls)) {
                $this->media_zip_path = $collector->process_media_collection($urls);
            }
            // Note: cleanup() removes the temp dir process_media_collection() wrote into, which
            // would also delete the zip it just returned — write_zip() must copy media_zip_path
            // into the bundle BEFORE we get here on the caller's next line, so don't cleanup() yet.
        }

        return $templates;
    }

    /**
     * Zips manifest.json + settings.json (refs+options) + templates/{uid}.json + media.zip (if any
     * template referenced local/remote media) into the uploads dir, and returns the zip path.
     * Caller is responsible for streaming and deleting it.
     */
    public function write_zip($bundle)
    {
        if (! class_exists('ZipArchive')) {
            return new WP_Error('zip_unsupported', 'The ZipArchive PHP extension is required to export a settings bundle.');
        }

        $upload_dir = wp_upload_dir();
        $base = trailingslashit($upload_dir['basedir']) . 'dd-settings-io/';
        if (! file_exists($base)) {
            wp_mkdir_p($base);
            file_put_contents($base . 'index.php', "<?php\n// Silence is golden.\n");
        }

        $zip_path = $base . 'export-' . wp_generate_password(12, false, false) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            return new WP_Error('zip_create_failed', 'Could not create the export zip file.');
        }

        $zip->addFromString('manifest.json', wp_json_encode($bundle['manifest'], JSON_PRETTY_PRINT));
        $zip->addFromString('settings.json', wp_json_encode([
            'refs'    => $bundle['refs'],
            'options' => $bundle['options'],
        ], JSON_PRETTY_PRINT));

        foreach ($bundle['templates'] as $uid => $data) {
            $zip->addFromString('templates/' . $uid . '.json', wp_json_encode($data));
        }

        if ($this->media_zip_path && file_exists($this->media_zip_path)) {
            $zip->addFile($this->media_zip_path, 'media.zip');
        }

        $zip->close();

        if ($this->media_zip_path && file_exists($this->media_zip_path)) {
            @unlink($this->media_zip_path);
            @rmdir(dirname($this->media_zip_path));
        }

        return $zip_path;
    }
}
