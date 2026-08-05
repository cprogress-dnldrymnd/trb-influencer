<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parses a settings bundle, resolves its references against the current site (plan), and applies
 * it (commit). Never trusts anything from the plan step blindly — apply() re-resolves from the
 * bundle + explicit overrides every time it runs.
 */
class DD_Settings_Importer
{
    const SNAPSHOT_OPTION = 'dd_settings_io_snapshot';

    /**
     * Extract an uploaded .zip into a private uploads subfolder and parse manifest/refs/options.
     *
     * @return array|WP_Error ['dir'=>, 'manifest'=>, 'refs'=>, 'options'=>, 'template_files'=>[uid=>path]]
     */
    public function extract($uploaded_tmp_path)
    {
        if (! class_exists('ZipArchive')) {
            return new WP_Error('zip_unsupported', 'The ZipArchive PHP extension is required to import a settings bundle.');
        }

        $upload_dir = wp_upload_dir();
        $base = trailingslashit($upload_dir['basedir']) . 'dd-settings-io/';
        if (! file_exists($base)) {
            wp_mkdir_p($base);
            file_put_contents($base . 'index.php', "<?php\n// Silence is golden.\n");
        }
        $target_dir = $base . wp_generate_password(12, false, false) . '/';
        wp_mkdir_p($target_dir);

        $zip = new ZipArchive();
        if ($zip->open($uploaded_tmp_path) !== true) {
            return new WP_Error('zip_open_failed', 'Could not open the uploaded file as a zip archive.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Guard against zip-slip: refuse any entry that would extract outside $target_dir.
            if (strpos($name, '..') !== false || strpos($name, "\0") !== false) {
                $zip->close();
                return new WP_Error('zip_unsafe', 'The uploaded archive contains an unsafe file path.');
            }
        }
        $zip->extractTo($target_dir);
        $zip->close();

        $manifest_path = $target_dir . 'manifest.json';
        $settings_path = $target_dir . 'settings.json';
        if (! file_exists($manifest_path) || ! file_exists($settings_path)) {
            return new WP_Error('bundle_invalid', 'This does not look like an Influencer Theme settings bundle.');
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);
        $settings = json_decode(file_get_contents($settings_path), true);

        if (empty($manifest['format']) || $manifest['format'] !== 'dd-theme-settings') {
            return new WP_Error('bundle_invalid', 'This does not look like an Influencer Theme settings bundle.');
        }
        if (empty($manifest['format_version']) || (int) $manifest['format_version'] > 1) {
            return new WP_Error('bundle_newer', 'This bundle was exported by a newer version of this feature.');
        }

        $template_files = [];
        $tpl_dir = $target_dir . 'templates/';
        if (is_dir($tpl_dir)) {
            foreach (glob($tpl_dir . '*.json') as $file) {
                $template_files[basename($file, '.json')] = $file;
            }
        }

        $media_dir = null;
        $media_mapping = [];
        $media_zip_path = $target_dir . 'media.zip';
        if (file_exists($media_zip_path) && class_exists('ZipArchive')) {
            $media_zip = new ZipArchive();
            if ($media_zip->open($media_zip_path) === true) {
                $media_dir = $target_dir . 'media/';
                wp_mkdir_p($media_dir);
                $media_zip->extractTo($media_dir);
                $media_zip->close();
                $mapping_file = $media_dir . 'media-mapping.json';
                if (file_exists($mapping_file)) {
                    $media_mapping = json_decode(file_get_contents($mapping_file), true) ?: [];
                }
            }
        }

        return [
            'dir'            => $target_dir,
            'manifest'       => $manifest,
            'refs'           => is_array($settings['refs'] ?? null) ? $settings['refs'] : [],
            'options'        => is_array($settings['options'] ?? null) ? $settings['options'] : [],
            'template_files' => $template_files,
            'media_dir'      => $media_dir,
            'media_mapping'  => $media_mapping,
        ];
    }

    /**
     * Resolve every ref in the bundle against this site. Writes nothing.
     *
     * @return array ['refs' => [group => [uid => ['descriptor','target_id','confidence']]], 'options' => [key => impact]]
     */
    public function plan($bundle)
    {
        $resolved = ['pages' => [], 'templates' => [], 'levels' => [], 'attachments' => []];

        foreach ($bundle['refs']['pages'] ?? [] as $uid => $descriptor) {
            $match = DD_Settings_Refs::resolve_page($descriptor);
            $resolved['pages'][$uid] = [
                'descriptor' => $descriptor,
                'target_id'  => $match['id'] ?? null,
                'confidence' => $match['confidence'] ?? null,
            ];
        }

        foreach ($bundle['refs']['templates'] ?? [] as $uid => $descriptor) {
            $match = DD_Settings_Refs::resolve_template($descriptor);
            $resolved['templates'][$uid] = [
                'descriptor' => $descriptor,
                'target_id'  => $match['id'] ?? null, // null => will be created on apply()
                'confidence' => $match['confidence'] ?? null,
            ];
        }

        foreach ($bundle['refs']['levels'] ?? [] as $uid => $descriptor) {
            $match = DD_Settings_Refs::resolve_level($descriptor);
            $resolved['levels'][$uid] = [
                'descriptor' => $descriptor,
                'target_id'  => $match['id'] ?? null,
                'confidence' => $match['confidence'] ?? null,
            ];
        }

        foreach ($bundle['refs']['attachments'] ?? [] as $uid => $descriptor) {
            $match = DD_Settings_Refs::resolve_attachment($descriptor);
            $resolved['attachments'][$uid] = [
                'descriptor' => $descriptor,
                'target_id'  => $match['id'] ?? null,
                'confidence' => $match['confidence'] ?? null,
            ];
        }

        $option_impact = [];
        $schema = dd_settings_io_schema();
        foreach ($bundle['options'] as $key => $value) {
            if (! isset($schema[$key])) {
                continue;
            }
            $spec = $schema[$key];
            $refs_in_option = $this->collect_refs($value, $spec);
            $total = count($refs_in_option);
            $unresolved = 0;
            foreach ($refs_in_option as $r) {
                $group = $r['kind'] . 's';
                if (empty($resolved[$group][$r['uid']]['target_id']) && $r['kind'] !== 'template') {
                    $unresolved++;
                }
            }
            $option_impact[$key] = [
                'total'      => $total,
                'unresolved' => $unresolved,
                'fail'       => $spec['fail'] ?? null,
                'blocked'    => $unresolved > 0 && ($spec['fail'] ?? null) === 'closed',
            ];
        }

        return ['refs' => $resolved, 'options' => $option_impact];
    }

    /**
     * Find every {"$ref":kind,"uid":uid} marker inside an exported option value, regardless of shape.
     */
    private function collect_refs($value, $spec)
    {
        $found = [];
        $walk = function ($v) use (&$walk, &$found) {
            if (is_array($v)) {
                if (isset($v['$ref'], $v['uid'])) {
                    $found[] = ['kind' => $v['$ref'], 'uid' => $v['uid']];
                    return;
                }
                foreach ($v as $inner) {
                    $walk($inner);
                }
            }
        };
        $walk($value);
        return $found;
    }

    /**
     * Apply the bundle: snapshot current state, import templates, build final id maps (bundle
     * resolution + explicit overrides), remap and write every option, deep-rewrite imported
     * template content. Re-resolves from scratch rather than trusting a client-supplied plan.
     *
     * @param array $overrides ['pages'|'templates'|'levels'|'attachments' => [uid => target_id]],
     *                         'accept_partial' => [option_key => true]
     * @return array report
     */
    public function apply($bundle, $overrides = [])
    {
        $plan = $this->plan($bundle);
        $accept_partial = $overrides['accept_partial'] ?? [];

        $this->snapshot();

        // --- 1. Import Elementor templates, building source_id => new_id -------------------
        $media_mapped = false;
        if (! empty($bundle['media_mapping']) && $bundle['media_dir'] && class_exists('\Elementor\TemplateLibrary\Classes\Media_Mapper')) {
            \Elementor\TemplateLibrary\Classes\Media_Mapper::set_mapping($bundle['media_mapping'], rtrim($bundle['media_dir'], '/'));
            $media_mapped = true;
        }

        $template_map_by_uid = [];
        $template_map_by_source_id = [];
        $template_report = ['created' => 0, 'updated' => 0, 'failed' => 0];

        foreach ($bundle['refs']['templates'] ?? [] as $uid => $descriptor) {
            if (! isset($bundle['template_files'][$uid])) {
                continue;
            }
            $payload = json_decode(file_get_contents($bundle['template_files'][$uid]), true);
            if (! is_array($payload)) {
                $template_report['failed']++;
                continue;
            }

            $existing_id = $overrides['templates'][$uid] ?? $plan['refs']['templates'][$uid]['target_id'] ?? null;
            $existing_id = $existing_id ? (int) $existing_id : null;

            $result_id = DD_Template_Transfer::import($payload, $existing_id);
            if (is_wp_error($result_id)) {
                $template_report['failed']++;
                continue;
            }

            DD_Settings_Refs::stamp_post_uid($result_id, $uid);
            $template_map_by_uid[$uid] = $result_id;
            $template_map_by_source_id[(int) $descriptor['source_id']] = $result_id;
            $existing_id ? $template_report['updated']++ : $template_report['created']++;
        }

        if ($media_mapped) {
            \Elementor\TemplateLibrary\Classes\Media_Mapper::clear_mapping();
        }

        // --- 2. Build the remaining id maps from plan + overrides, stamping matched targets ---
        $page_map = $this->build_map($bundle, $plan, $overrides, 'pages');
        $level_map = $this->build_map($bundle, $plan, $overrides, 'levels');
        $attachment_map = $this->build_map($bundle, $plan, $overrides, 'attachments');

        foreach ($page_map as $uid => $id) {
            DD_Settings_Refs::stamp_post_uid($id, $uid);
        }
        foreach ($level_map as $uid => $id) {
            DD_Settings_Refs::stamp_level_uid($id, $uid);
        }
        foreach ($attachment_map as $uid => $id) {
            DD_Settings_Refs::stamp_post_uid($id, $uid);
        }

        $maps = [
            'page'       => $page_map,
            'template'   => $template_map_by_uid,
            'level'      => $level_map,
            'attachment' => $attachment_map,
        ];

        // --- 3. Deep-rewrite ID-bearing settings inside the imported template content ---------
        foreach ($template_map_by_uid as $new_id) {
            DD_Template_Transfer::deep_rewrite($new_id, $template_map_by_source_id);
        }

        // --- 4. Remap and write every option ---------------------------------------------------
        $schema = dd_settings_io_schema();
        $source_home = $bundle['manifest']['source']['home_url'] ?? '';
        $target_home = get_home_url();
        $report = ['written' => [], 'blocked' => [], 'warned' => []];

        foreach ($bundle['options'] as $key => $value) {
            if (! isset($schema[$key])) {
                continue;
            }
            $spec = $schema[$key];
            $had_unresolved = false;

            $new_value = $this->remap_value($value, $spec, $maps, $had_unresolved);

            if ($had_unresolved && ($spec['fail'] ?? null) === 'closed' && empty($accept_partial[$key])) {
                $report['blocked'][] = $key;
                continue;
            }

            if (! empty($spec['urls'])) {
                $new_value = $this->rewrite_urls($new_value, $source_home, $target_home);
            }

            $this->write_option($key, $new_value, $spec);

            if ($had_unresolved) {
                $report['warned'][] = $key;
            } else {
                $report['written'][] = $key;
            }
        }

        $report['templates'] = $template_report;
        return $report;
    }

    private function build_map($bundle, $plan, $overrides, $group)
    {
        $map = [];
        foreach ($bundle['refs'][$group] ?? [] as $uid => $descriptor) {
            $target = $overrides[$group][$uid] ?? $plan['refs'][$group][$uid]['target_id'] ?? null;
            if ($target) {
                $map[$uid] = (int) $target;
            }
        }
        return $map;
    }

    /**
     * Walk an exported option value, replacing every {"$ref":kind,"uid":uid} marker with the
     * resolved id from $maps (kind => [uid => id]). Sets $had_unresolved when a marker has no
     * entry in its map. Unresolved entries are dropped from list/rows/keys/json_columns shapes
     * (never left as a stale foreign id); an unresolved scalar becomes 0.
     */
    private function remap_value($value, $spec, $maps, &$had_unresolved)
    {
        $resolve = function ($marker) use ($maps, &$had_unresolved) {
            if (! is_array($marker) || ! isset($marker['$ref'], $marker['uid'])) {
                return null; // not a ref marker — leave shape handling to caller
            }
            $id = $maps[$marker['$ref']][$marker['uid']] ?? null;
            if (! $id) {
                $had_unresolved = true;
            }
            return $id;
        };

        switch ($spec['shape'] ?? null) {
            case 'scalar':
                if (is_array($value) && isset($value['$ref'])) {
                    return (int) ($resolve($value) ?? 0);
                }
                return (int) $value;

            case 'list':
                if (! is_array($value)) {
                    return [];
                }
                $out = [];
                foreach ($value as $marker) {
                    $id = $resolve($marker);
                    if ($id) {
                        $out[] = $id;
                    }
                }
                return array_values(array_unique($out));

            case 'keys':
                if (! is_array($value)) {
                    return [];
                }
                $out = [];
                foreach ($value as $entry) {
                    $id = $resolve($entry['ref'] ?? null);
                    if ($id) {
                        $out[$id] = $entry['value'] ?? null;
                    }
                }
                return $out;

            case 'rows':
                if (! is_array($value)) {
                    return [];
                }
                $out = [];
                foreach ($value as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (array_key_exists($spec['field'], $row) && is_array($row[$spec['field']])) {
                        $id = $resolve($row[$spec['field']]);
                        if (! $id) {
                            continue; // drop the whole row rather than leave a dangling level_id
                        }
                        $row[$spec['field']] = $id;
                    }
                    $out[] = $row;
                }
                return $out;

            case 'json_columns':
                $decoded = is_array($value) ? $value : (json_decode((string) $value, true) ?: ['columns' => [], 'rows' => []]);
                if (! empty($decoded['columns'])) {
                    foreach ($decoded['columns'] as &$col) {
                        if (($col['type'] ?? '') === 'pmpro' && is_array($col[$spec['field']] ?? null)) {
                            $id = $resolve($col[$spec['field']]);
                            if ($id) {
                                $col[$spec['field']] = $id;
                            } else {
                                // Level didn't resolve: keep the column but demote it to a plain
                                // custom column rather than dropping it — the admin already
                                // authored a name/price/CTA for it, so it degrades gracefully to
                                // render_table()'s documented static-CTA fallback.
                                $col['type'] = 'custom';
                                $col[$spec['field']] = 0;
                            }
                        }
                    }
                    unset($col);
                }
                return wp_json_encode($decoded);

            default:
                return $value;
        }
    }

    private function rewrite_urls($value, $from, $to)
    {
        if (! $from || ! $to || $from === $to) {
            return $value;
        }
        if (is_string($value)) {
            $value = str_replace($from, $to, $value);
            $value = str_replace(str_replace('/', '\/', $from), str_replace('/', '\/', $to), $value);
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->rewrite_urls($v, $from, $to);
            }
        }
        return $value;
    }

    private function write_option($key, $value, $spec)
    {
        if (! empty($spec['slash_on_write']) && is_string($value)) {
            $value = wp_slash($value);
        }
        update_option($key, $value);
    }

    // -----------------------------------------------------------------
    // Snapshot / restore
    // -----------------------------------------------------------------

    public function snapshot()
    {
        $sentinel = "\0dd_settings_io_missing\0";
        $captured = [];
        foreach (array_keys(dd_settings_io_schema()) as $key) {
            $value = get_option($key, $sentinel);
            $captured[$key] = ($value === $sentinel)
                ? ['exists' => false]
                : ['exists' => true, 'value' => $value];
        }

        update_option(self::SNAPSHOT_OPTION, [
            'created' => current_time('mysql'),
            'options' => $captured,
        ], false);
    }

    public function restore_snapshot()
    {
        $snapshot = get_option(self::SNAPSHOT_OPTION);
        if (empty($snapshot['options'])) {
            return new WP_Error('no_snapshot', 'No pre-import snapshot is available.');
        }

        foreach ($snapshot['options'] as $key => $captured) {
            if (! empty($captured['exists'])) {
                update_option($key, $captured['value']);
            } else {
                delete_option($key);
            }
        }

        return true;
    }

    public function has_snapshot()
    {
        $snapshot = get_option(self::SNAPSHOT_OPTION);
        return ! empty($snapshot['created']);
    }

    public function snapshot_created_at()
    {
        $snapshot = get_option(self::SNAPSHOT_OPTION);
        return $snapshot['created'] ?? null;
    }
}
