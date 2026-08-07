<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared plumbing for exporting/importing Elementor *documents* — both library templates
 * (DD_Template_Transfer) and ordinary pages (DD_Page_Transfer) are Elementor documents
 * (Source_Local::get_template_type() returns 'wp-page' for a normal page exactly like it returns
 * 'page'/'section' for a library template), so the export/prepare/save mechanics are identical;
 * only the create step differs (save_item() vs documents->create('wp-page', …)), which stays in
 * each caller. A plain static helper, matching this module's existing convention (DD_Settings_Refs)
 * rather than introducing the module's first abstract base class.
 */
class DD_Document_Transfer
{
    /**
     * Export a document's content, settings, and raw postmeta. Reads meta directly via
     * get_post_meta() rather than trusting Document::get_export_metadata() — that method flattens
     * multi-row meta to its first value and strips every "protected" (underscore-prefixed) key,
     * which is too lossy for a page-content transfer that wants specific protected keys
     * (_thumbnail_id, _wp_page_template via `settings`, etc.) intact.
     *
     * @return array|WP_Error
     */
    public static function export_document($post_id)
    {
        if (! class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_missing', 'Elementor is not active.');
        }

        $document = \Elementor\Plugin::$instance->documents->get($post_id);
        if (! $document) {
            return new WP_Error('document_missing', 'Post #' . $post_id . ' no longer exists.');
        }

        $export = $document->get_export_data();
        if (empty($export['content'])) {
            return new WP_Error('document_empty', 'Post #' . $post_id . ' has no Elementor content.');
        }

        $content = apply_filters('elementor/template_library/sources/local/export/elements', $export['content']);

        $data = [
            'content'       => $content,
            'page_settings' => $export['settings'],
            'version'       => class_exists('\Elementor\DB') ? \Elementor\DB::DB_VERSION : '',
            'title'         => get_the_title($post_id),
            'type'          => class_exists('\Elementor\TemplateLibrary\Source_Local')
                ? \Elementor\TemplateLibrary\Source_Local::get_template_type($post_id)
                : 'page',
            'metadata'      => get_post_meta($post_id),
        ];

        $snapshots = apply_filters('elementor/template_library/export/build_snapshots', [], $content, $post_id, $data);
        if (! empty($snapshots['global_classes'])) {
            $data['global_classes'] = $snapshots['global_classes'];
        }
        if (! empty($snapshots['global_variables'])) {
            $data['global_variables'] = $snapshots['global_variables'];
        }

        return $data;
    }

    /**
     * Run an exported payload through Elementor's own import processing (media sideload via
     * Media_Mapper, dynamic-tag/global-style restoration) without creating or touching any post.
     *
     * Note: the returned array does NOT include `metadata` — prepare_import_template_data() only
     * returns content/page_settings/title/type. Callers that need metadata must read it from the
     * original $payload they already have, not from this method's return value.
     *
     * @return array|WP_Error
     */
    public static function prepare($payload)
    {
        if (! class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_missing', 'Elementor is not active.');
        }

        $tmp_dir = \Elementor\Plugin::$instance->uploads_manager->create_unique_dir();
        $tmp_path = trailingslashit($tmp_dir) . 'document.json';
        file_put_contents($tmp_path, wp_json_encode($payload));

        $source = \Elementor\Plugin::$instance->templates_manager->get_source('local');
        $prepared = $source->prepare_import_template_data($tmp_path, 'match_site');

        \Elementor\Plugin::$instance->uploads_manager->remove_file_or_dir($tmp_dir);

        return $prepared;
    }

    /**
     * Save prepared content/settings into an existing document. Elementor's own save path can both
     * throw (Page\Manager::ajax_before_save_settings() throws a bare \Exception for a missing post
     * or missing capability) and silently return false (Document::save() when the current user
     * can't edit the post) — callers must not assume success just because nothing was thrown.
     *
     * @return true|WP_Error
     */
    public static function save_into($post_id, $prepared, $extra_settings = [])
    {
        $document = \Elementor\Plugin::$instance->documents->get($post_id);
        if (! $document) {
            return new WP_Error('document_missing', 'Post #' . $post_id . ' no longer exists.');
        }

        try {
            $result = $document->save([
                'elements' => $prepared['content'],
                'settings' => array_merge($prepared['page_settings'] ?? [], $extra_settings),
            ]);
        } catch (\Throwable $e) {
            return new WP_Error('save_failed', 'Saving post #' . $post_id . ' failed: ' . $e->getMessage());
        }

        if ($result === false) {
            return new WP_Error('save_rejected', 'Post #' . $post_id . ' could not be saved (not editable by the current user, or Elementor rejected the save).');
        }

        return true;
    }

    /** Widget setting name => ref kind, for the deep-rewrite pass over imported document content. */
    public static function id_bearing_settings()
    {
        return apply_filters('dd_settings_io_template_id_keys', [
            'at_limit_template' => 'template',
            'template_id'       => 'template',
        ]);
    }

    /**
     * Rewrites ID-bearing widget settings (see id_bearing_settings()) inside an imported document's
     * content so a widget pointing at another *template* (not media, which Elementor already
     * handles) lands on the correct local object. Must run only after every document in the batch
     * has been imported, so $template_map (source template id => new/matched id) is complete.
     *
     * @return int Number of settings values rewritten.
     */
    public static function deep_rewrite($post_id, array $template_map)
    {
        if (! class_exists('\Elementor\Plugin')) {
            return 0;
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($data)) {
            return 0;
        }

        $id_keys = self::id_bearing_settings();
        $rewritten = 0;

        $callback = function ($element) use ($id_keys, $template_map, &$rewritten) {
            if (empty($element['settings']) || ! is_array($element['settings'])) {
                return $element;
            }
            foreach ($id_keys as $setting_key => $kind) {
                if ($kind !== 'template' || empty($element['settings'][$setting_key])) {
                    continue;
                }
                $old_id = (int) $element['settings'][$setting_key];
                if ($old_id && isset($template_map[$old_id])) {
                    $element['settings'][$setting_key] = $template_map[$old_id];
                    $rewritten++;
                }
            }
            return $element;
        };

        $rewritten_data = \Elementor\Plugin::$instance->db->iterate_data($data, $callback);

        if ($rewritten > 0) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($document) {
                try {
                    $document->save(['elements' => $rewritten_data]);
                } catch (\Throwable $e) {
                    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($rewritten_data)));
                }
            } else {
                update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($rewritten_data)));
            }
        }

        return $rewritten;
    }
}
