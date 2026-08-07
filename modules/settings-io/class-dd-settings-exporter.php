<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds a portable settings bundle: every dd_settings_io_schema() option, with every environment-
 * local ID replaced by a {"$ref":kind,"uid":…} marker, plus the referenced Elementor templates' and
 * pages' full content and (when possible) their bundled media.
 */
class DD_Settings_Exporter
{
    const MISSING = "\0dd_settings_io_missing\0";

    /** Ancestor-chain depth cap while closing the page graph under post_parent (insurance, not today's data — every currently-assigned page is top-level). */
    const MAX_PAGE_DEPTH = 8;

    /** @var array uid => descriptor, keyed by kind: pages/templates/levels/attachments */
    private $refs = ['pages' => [], 'templates' => [], 'levels' => [], 'attachments' => []];

    /** @var string|null path to a media.zip built by Elementor's Media_Collector, if any */
    private $media_zip_path;

    public function build()
    {
        $this->assert_no_denylisted_keys();

        $options = [];
        foreach (dd_settings_io_schema() as $key => $spec) {
            $raw = get_option($key, self::MISSING);
            $exists = ($raw !== self::MISSING);
            $from_default = false;

            if ($exists) {
                $value = $raw;
            } elseif (in_array($spec['class'], ['page_ref', 'template_ref'], true)) {
                // Never explicitly saved on this site — fall back to whatever the theme's own
                // accessor would resolve to (dd_get_page_id()/dd_get_template_id()'s hardcoded
                // fallback, surfaced here via register_setting()'s registered default), so a page
                // assignment that's only ever been the hardcoded default still travels rather than
                // the bundle silently having nothing for it.
                $value = get_option($key); // triggers default_option_{$key} if one is registered
                $from_default = true;
                if (empty($value)) {
                    continue; // genuinely nothing to export — not even a usable fallback id
                }
            } else {
                continue; // never saved, and no fallback concept for this class (levels/attachments/plain)
            }

            $options[$key] = $this->export_value($value, $spec, $from_default);
        }

        $manifest = [
            'format'         => 'dd-theme-settings',
            'format_version' => 2,
            'generated'      => gmdate('c'),
            'source'         => [
                'site_url' => get_site_url(),
                'home_url' => get_home_url(),
            ],
        ];

        $documents = $this->export_documents();

        return [
            'manifest'  => $manifest,
            'refs'      => $this->refs,
            'options'   => $options,
            'templates' => $documents['templates'],
            'pages'     => $documents['pages'],
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

    private function export_value($value, $spec, $from_default = false)
    {
        switch ($spec['class']) {
            case 'page_ref':
            case 'template_ref':
            case 'attachment_ref':
            case 'level_ref':
                return $this->export_ref_shape($value, $spec, $from_default);

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

    private function export_ref_shape($value, $spec, $from_default = false)
    {
        $kind = $this->kind_for_class($spec['class']);

        switch ($spec['shape']) {
            case 'scalar':
                return $this->id_to_ref((int) $value, $kind, $from_default);

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

    private function id_to_ref($id, $kind, $from_default = false, $depth = 0)
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

        // An explicit (non-default) reference always wins over a later default-derived one for the
        // same object — evidence of deliberate configuration outranks a hardcoded fallback.
        $existing = $this->refs[$group][$descriptor['uid']] ?? null;
        $descriptor['from_default'] = ($existing && empty($existing['from_default'])) ? false : $from_default;

        // Close the page graph under post_parent so a path can still be reconstructed on import —
        // insurance for a future hierarchy, not something today's flat page set exercises.
        if ($kind === 'page' && ! empty($descriptor['parent_source_id']) && $depth < self::MAX_PAGE_DEPTH) {
            $parent_ref = $this->id_to_ref($descriptor['parent_source_id'], 'page', false, $depth + 1);
            $descriptor['parent_ref'] = $parent_ref;
        } else {
            $descriptor['parent_ref'] = null;
        }
        unset($descriptor['parent_source_id']);

        $this->refs[$group][$descriptor['uid']] = $descriptor;

        return ['$ref' => $kind, 'uid' => $descriptor['uid']];
    }

    /**
     * DD_Page_Transfer::export() returns raw ids for 'special' (level/attachment meta) and
     * 'pmpro_gating' (the pmpro_memberships_pages rows) — it has no access to this exporter's refs
     * table. Convert those raw ids into $ref markers here (registering them into $this->refs the
     * same way the options walk does), so a level referenced ONLY via page gating — never through
     * any dd_*_allowed_levels option — still gets a descriptor and can resolve on import.
     */
    private function convert_page_refs($payload)
    {
        $classes = dd_page_meta_classes();
        foreach ($payload['special'] as $key => $raw_id) {
            $kind = ($classes[$key]['class'] ?? '') === 'level_ref' ? 'level' : 'attachment';
            $ref = $this->id_to_ref((int) $raw_id, $kind);
            $payload['special'][$key] = $ref; // null if it somehow no longer resolves to a real object
        }

        // Deliberately NOT array_filter()'d: a pmpro_memberships_pages row can reference a level
        // that no longer exists (verified on this site — rows pointing at deleted levels 10/12).
        // Dropping that entry here would silently shrink the exported gating set with no signal
        // that anything was omitted, defeating apply_membership_pages()'s fail-closed guard (which
        // needs to see EVERY original entry, resolvable or not, to correctly refuse a partial sync
        // rather than silently importing a smaller-than-intended gating set). A null entry is that
        // signal.
        $payload['pmpro_gating'] = array_map(function ($level_id) {
            return $this->id_to_ref((int) $level_id, 'level');
        }, $payload['pmpro_gating']);

        return $payload;
    }

    /**
     * Exports every referenced template's and page's Elementor content inside a single
     * Media_Collector window — starting/ending it once, not once per document type, so media
     * referenced by page content is bundled exactly like media referenced by template content.
     */
    private function export_documents()
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

        $pages = [];
        foreach ($this->refs['pages'] as $uid => $descriptor) {
            $data = DD_Page_Transfer::export($descriptor['source_id']);
            if (! is_wp_error($data)) {
                $pages[$uid] = $this->convert_page_refs($data);
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

        return ['templates' => $templates, 'pages' => $pages];
    }

    /**
     * Zips manifest.json + settings.json (refs+options) + templates/{uid}.json + pages/{uid}.json +
     * media.zip (if any document referenced local/remote media) into the uploads dir, and returns
     * the zip path. Caller is responsible for streaming and deleting it.
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

        foreach ($bundle['pages'] as $uid => $data) {
            $zip->addFromString('pages/' . $uid . '.json', wp_json_encode($data));
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
