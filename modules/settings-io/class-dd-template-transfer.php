<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Export/import of Elementor template *content* (not just the dd_tpl_* option pointing at it).
 * Mirrors what Elementor's own (private) Source_Local::prepare_template_export()/import_single_template()
 * do internally, using only their public entry points, so a bundle produced here is also importable
 * through Elementor's own Templates → Saved Templates → Import as a manual fallback.
 */
class DD_Template_Transfer
{
    /** Widget setting name => ref kind, for the deep-rewrite pass over imported template content. */
    public static function id_bearing_settings()
    {
        return apply_filters('dd_settings_io_template_id_keys', [
            'at_limit_template' => 'template',
            'template_id'       => 'template',
        ]);
    }

    public static function export($template_id)
    {
        if (! class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_missing', 'Elementor is not active.');
        }

        $document = \Elementor\Plugin::$instance->documents->get($template_id);
        if (! $document) {
            return new WP_Error('template_missing', 'Template #' . $template_id . ' no longer exists.');
        }

        $export = $document->get_export_data();
        if (empty($export['content'])) {
            return new WP_Error('template_empty', 'Template #' . $template_id . ' has no content.');
        }

        $content = apply_filters('elementor/template_library/sources/local/export/elements', $export['content']);

        $data = [
            'content'       => $content,
            'page_settings' => $export['settings'],
            'version'       => class_exists('\Elementor\DB') ? \Elementor\DB::DB_VERSION : '',
            'title'         => get_the_title($template_id),
            'type'          => class_exists('\Elementor\TemplateLibrary\Source_Local')
                ? \Elementor\TemplateLibrary\Source_Local::get_template_type($template_id)
                : 'page',
        ];

        $snapshots = apply_filters('elementor/template_library/export/build_snapshots', [], $content, $template_id, $data);
        if (! empty($snapshots['global_classes'])) {
            $data['global_classes'] = $snapshots['global_classes'];
        }
        if (! empty($snapshots['global_variables'])) {
            $data['global_variables'] = $snapshots['global_variables'];
        }

        return $data;
    }

    /**
     * @param array    $payload     The exported template data (content/page_settings/title/type/…).
     * @param int|null $existing_id Matched live template to update in place, or null to create.
     * @return int|WP_Error New/updated template post ID.
     */
    public static function import($payload, $existing_id = null)
    {
        if (! class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_missing', 'Elementor is not active.');
        }

        $tmp_dir = \Elementor\Plugin::$instance->uploads_manager->create_unique_dir();
        $tmp_path = trailingslashit($tmp_dir) . 'template.json';
        file_put_contents($tmp_path, wp_json_encode($payload));

        $source = \Elementor\Plugin::$instance->templates_manager->get_source('local');
        $prepared = $source->prepare_import_template_data($tmp_path, 'match_site');

        \Elementor\Plugin::$instance->uploads_manager->remove_file_or_dir($tmp_dir);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        if ($existing_id && get_post($existing_id)) {
            $existing_type = class_exists('\Elementor\TemplateLibrary\Source_Local')
                ? \Elementor\TemplateLibrary\Source_Local::get_template_type($existing_id)
                : null;

            if ($existing_type === $prepared['type']) {
                $document = \Elementor\Plugin::$instance->documents->get($existing_id);
                if ($document) {
                    $document->save([
                        'elements' => $prepared['content'],
                        'settings' => $prepared['page_settings'],
                    ]);
                    wp_update_post(['ID' => $existing_id, 'post_title' => $prepared['title']]);
                    return $existing_id;
                }
            }
            // Type changed since export — fall through to create-new rather than corrupting the document.
        }

        $new_id = $source->save_item([
            'content'       => $prepared['content'],
            'title'         => $prepared['title'],
            'type'          => $prepared['type'],
            'page_settings' => $prepared['page_settings'],
        ]);

        return $new_id;
    }

    /**
     * Rewrites ID-bearing widget settings (see id_bearing_settings()) inside an imported template's
     * content so a widget pointing at another *template* (not media, which Elementor already handles)
     * lands on the correct local object. Must run only after every template in the batch has been
     * imported, so $template_map is complete.
     *
     * @return int Number of settings values rewritten.
     */
    public static function deep_rewrite($template_post_id, array $template_map)
    {
        if (! class_exists('\Elementor\Plugin')) {
            return 0;
        }

        $raw = get_post_meta($template_post_id, '_elementor_data', true);
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
            $document = \Elementor\Plugin::$instance->documents->get($template_post_id);
            if ($document) {
                $document->save(['elements' => $rewritten_data]);
            } else {
                update_post_meta($template_post_id, '_elementor_data', wp_slash(wp_json_encode($rewritten_data)));
            }
        }

        return $rewritten;
    }
}
