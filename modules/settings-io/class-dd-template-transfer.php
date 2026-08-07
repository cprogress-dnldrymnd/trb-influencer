<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Export/import of Elementor template *content* (not just the dd_tpl_* option pointing at it).
 * Delegates the shared document mechanics to DD_Document_Transfer; owns only what's specific to
 * the library-template path — the save_item() create branch (rejected for pages, see
 * DD_Page_Transfer) and a narrow rewrite of _elementor_conditions.
 *
 * Note on _elementor_conditions: verified against pro-elements/modules/theme-builder/classes/
 * conditions-manager.php — Conditions_Manager::save_conditions() stores each condition as a flat
 * "type/sub_name/sub_id" string (e.g. "include/singular/page/1555"), the whole set serialized into
 * one `_elementor_conditions` meta row. Only the "singular/page/{id}" and "child_of/{id}" forms
 * carry a resolvable post reference; every other condition type (by_author/{user_id},
 * in_category/{term_id}, etc.) has no identity layer in this feature and is left untouched. On this
 * site every dd_tpl_* template's conditions are empty (`a:0:{}` — templates are injected by
 * shortcode/ID, not theme-builder location), so this path is exercised by nothing today; it exists
 * for whichever template a future admin adds that IS a theme-builder location.
 */
class DD_Template_Transfer
{
    public static function id_bearing_settings()
    {
        return DD_Document_Transfer::id_bearing_settings();
    }

    public static function export($template_id)
    {
        $data = DD_Document_Transfer::export_document($template_id);
        if (is_wp_error($data)) {
            return $data;
        }

        $raw_conditions = $data['metadata']['_elementor_conditions'][0] ?? null;
        unset($data['metadata']); // don't ship raw template postmeta — everything else is derived/cache noise

        if ($raw_conditions) {
            $conditions = maybe_unserialize($raw_conditions);
            if (is_array($conditions) && ! empty($conditions)) {
                $data['conditions'] = $conditions;
            }
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

        $prepared = DD_Document_Transfer::prepare($payload);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        if ($existing_id && get_post($existing_id)) {
            $existing_type = class_exists('\Elementor\TemplateLibrary\Source_Local')
                ? \Elementor\TemplateLibrary\Source_Local::get_template_type($existing_id)
                : null;

            if ($existing_type === $prepared['type']) {
                $result = DD_Document_Transfer::save_into($existing_id, $prepared);
                if (is_wp_error($result)) {
                    return $result;
                }
                wp_update_post(['ID' => $existing_id, 'post_title' => $prepared['title']]);
                return $existing_id;
            }
            // Type changed since export — fall through to create-new rather than corrupting the document.
        }

        $source = \Elementor\Plugin::$instance->templates_manager->get_source('local');
        $new_id = $source->save_item([
            'content'       => $prepared['content'],
            'title'         => $prepared['title'],
            'type'          => $prepared['type'],
            'page_settings' => $prepared['page_settings'],
        ]);

        return $new_id;
    }

    public static function deep_rewrite($template_post_id, array $template_map)
    {
        return DD_Document_Transfer::deep_rewrite($template_post_id, $template_map);
    }

    /**
     * Best-effort rewrite of post-ID-bearing conditions ("singular/page/{id}", "child_of/{id}")
     * inside an already-imported template's _elementor_conditions, using the completed page map
     * (source page id => target page id). Every other condition form is left byte-identical.
     *
     * @param array $payload_conditions The 'conditions' array captured at export(), if any.
     * @return array{rewritten:int, left:int} counts, for the import report.
     */
    public static function rewrite_conditions($template_post_id, $payload_conditions, array $page_map_by_source_id)
    {
        $counts = ['rewritten' => 0, 'left' => 0];

        if (empty($payload_conditions) || ! is_array($payload_conditions)) {
            return $counts;
        }

        $new_conditions = [];
        foreach ($payload_conditions as $condition_str) {
            $segments = explode('/', (string) $condition_str);
            $rewritten_this = false;

            if (count($segments) === 4 && $segments[1] === 'singular' && $segments[2] === 'page' && ctype_digit($segments[3])) {
                $source_page_id = (int) $segments[3];
                if (isset($page_map_by_source_id[$source_page_id])) {
                    $segments[3] = (string) $page_map_by_source_id[$source_page_id];
                    $rewritten_this = true;
                }
            } elseif (count($segments) === 2 && $segments[0] === 'child_of' && ctype_digit($segments[1])) {
                $source_page_id = (int) $segments[1];
                if (isset($page_map_by_source_id[$source_page_id])) {
                    $segments[1] = (string) $page_map_by_source_id[$source_page_id];
                    $rewritten_this = true;
                }
            }

            $rewritten_this ? $counts['rewritten']++ : $counts['left']++;
            $new_conditions[] = implode('/', $segments);
        }

        if ($counts['rewritten'] > 0) {
            self::save_conditions($template_post_id, $new_conditions);
        }

        return $counts;
    }

    private static function save_conditions($post_id, array $flat_conditions)
    {
        $parsed = array_map(function ($flat) {
            return explode('/', $flat);
        }, $flat_conditions);

        if (class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
            $manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
            if ($manager && method_exists($manager, 'save_conditions')) {
                $manager->save_conditions($post_id, $parsed);
                return; // save_conditions() also regenerates the theme-builder conditions cache
            }
        }

        // Pro theme-builder module unavailable — write the raw meta directly. The cached
        // elementor_pro_theme_builder_conditions option will be stale until something else
        // regenerates it (e.g. saving the document in the editor).
        update_post_meta($post_id, '_elementor_conditions', $flat_conditions);
    }
}
