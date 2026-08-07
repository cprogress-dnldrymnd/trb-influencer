<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Export/import of ordinary page *content* — post fields, Elementor content/settings, meta
 * (classified via dd_page_meta_classes()/dd_page_meta_denylist()), and PMPro page-access gating
 * (the {$wpdb->prefix}pmpro_memberships_pages join table — NOT post meta; see CLAUDE.md).
 *
 * Mirrors DD_Template_Transfer's use of DD_Document_Transfer for the Elementor mechanics, but
 * forks at the create step: Source_Local::save_item() rejects the 'wp-page' document type in a
 * real web request (is_valid_template_type()), so pages are created via
 * documents->create('wp-page', …) instead, always as a draft.
 *
 * This class returns/consumes RAW ids (plain ints) for parent/level/attachment references — never
 * $ref markers. Building markers (and populating the exporter's refs table) is DD_Settings_Exporter's
 * job, mirroring how it already treats option values; this class stays a plain data reader/writer
 * with no dependency on the exporter's internals.
 */
class DD_Page_Transfer
{
    // -----------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------

    /**
     * @return array|WP_Error
     */
    public static function export($page_id)
    {
        $document = DD_Document_Transfer::export_document($page_id);
        if (is_wp_error($document)) {
            return $document;
        }

        $post = get_post($page_id);
        $meta_result = self::export_meta($page_id);
        $built_with_elementor = ($document['metadata']['_elementor_edit_mode'][0] ?? '') === 'builder';

        return [
            'post' => [
                'post_title'           => $post->post_title,
                'post_name'            => $post->post_name,
                'post_excerpt'         => $post->post_excerpt,
                'post_status'          => $post->post_status,
                'menu_order'           => (int) $post->menu_order,
                'built_with_elementor' => $built_with_elementor,
                // post_content is a phantom for a builder page — Document::save() overwrites it
                // with a plain-text extraction of the Elementor elements regardless of what's sent,
                // so only carry it for a page that ISN'T built with Elementor.
                'post_content'         => $built_with_elementor ? '' : $post->post_content,
                'parent_source_id'     => (int) $post->post_parent,
            ],
            'elementor'    => ['content' => $document['content'], 'settings' => $document['page_settings']],
            // Carried separately from 'meta' (rather than through the generic copy loop) because
            // it's applied via Document::save()'s settings['template'] channel, not a raw meta write.
            'page_template' => get_post_meta($page_id, '_wp_page_template', true),
            'meta'         => $meta_result['meta'],
            'special'      => $meta_result['special'],
            'unknown'      => $meta_result['unknown'],
            'pmpro_gating' => self::export_membership_pages($page_id),
            'source_id'    => (int) $page_id,
        ];
    }

    /**
     * Walks raw post meta through dd_page_meta_classes()/denylist(). Multi-row meta is preserved
     * as an array; single-row meta is flattened to a scalar. 'special' keys carry a raw id (int)
     * for the exporter to convert into a $ref marker — never written here.
     */
    public static function export_meta($page_id)
    {
        $raw = get_post_meta($page_id);
        $classes = dd_page_meta_classes();
        $deny_exact = dd_page_meta_denylist();
        $deny_prefixes = dd_page_meta_denylist_prefixes();

        $meta = [];
        $special = [];
        $unknown = [];

        foreach ($raw as $key => $values) {
            if (in_array($key, $deny_exact, true)) {
                continue;
            }
            $denied_by_prefix = false;
            foreach ($deny_prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    $denied_by_prefix = true;
                    break;
                }
            }
            if ($denied_by_prefix) {
                continue;
            }

            if (isset($classes[$key])) {
                $id = (int) ($values[0] ?? 0);
                if ($id > 0) {
                    $special[$key] = $id;
                }
                continue;
            }

            $unserialized = array_map('maybe_unserialize', $values);
            $meta[$key] = count($unserialized) > 1 ? $unserialized : ($unserialized[0] ?? '');

            // Flag ACF sibling keys (an underscore-prefixed key whose value is an ACF field key
            // string) and anything else not explicitly classified, so the preview can show exactly
            // what travelled under the "everything" rule rather than it being invisible.
            if (strpos($key, '_') === 0 && is_string($meta[$key]) && strpos($meta[$key], 'field_') === 0) {
                $unknown[$key] = ['type' => 'acf_sibling', 'value' => $meta[$key]];
            } elseif (strpos($key, '_') !== 0) {
                // Public (non-underscore) keys are the common case for legitimate plugin data
                // (header_text_colour, members_only, cmplz_hide_cookiebanner, footnotes, …) — not
                // flagged as "unknown", just carried, since that's the expected shape of page meta.
            } else {
                $unknown[$key] = ['type' => 'unclassified', 'length' => is_string($meta[$key]) ? strlen($meta[$key]) : null];
            }
        }

        return ['meta' => $meta, 'special' => $special, 'unknown' => $unknown];
    }

    /** Raw level IDs gating this page via {$wpdb->prefix}pmpro_memberships_pages (not post meta). */
    public static function export_membership_pages($page_id)
    {
        global $wpdb;
        if (! function_exists('pmpro_getAllLevels')) {
            return [];
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT membership_id FROM {$wpdb->prefix}pmpro_memberships_pages WHERE page_id = %d",
            $page_id
        ));
        return array_map('intval', $ids);
    }

    // -----------------------------------------------------------------
    // Import
    // -----------------------------------------------------------------

    /**
     * @param array    $payload    This page's export() payload.
     * @param int|null $existing_id Matched live page to update, or null to create.
     * @param array    $maps       ['level' => [source_id => target_id], 'attachment' => [...]]
     * @param array    $opts       ['publish_created' => bool]
     * @return array{id:int, action:string, warnings:array}|WP_Error
     */
    public static function import($payload, $existing_id, array $maps, array $opts = [])
    {
        if (! class_exists('\Elementor\Plugin')) {
            return new WP_Error('elementor_missing', 'Elementor is not active.');
        }

        $warnings = [];
        $prepared = DD_Document_Transfer::prepare([
            'content'       => $payload['elementor']['content'],
            'page_settings' => $payload['elementor']['settings'],
            'title'         => $payload['post']['post_title'],
            'type'          => 'wp-page',
        ]);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $action = 'updated';
        $page_id = $existing_id;

        if (! $page_id) {
            $page_id = self::create_page($payload, ! empty($opts['publish_created']));
            if (is_wp_error($page_id)) {
                return $page_id;
            }
            $action = 'created';
        }

        $extra_settings = [
            'post_title'   => $payload['post']['post_title'],
            'post_excerpt' => $payload['post']['post_excerpt'],
            'menu_order'   => $payload['post']['menu_order'],
            'template'     => $payload['page_template'] ?? '',
        ];
        // Never let a matched (existing) page's status be overwritten by the bundle's — only a
        // freshly-created page's status is under this import's control (and even then, forced to
        // draft unless publish-on-create is explicitly enabled — see create_page()).
        if ($action === 'created') {
            $extra_settings['post_status'] = get_post_status($page_id);
        }

        $save_result = DD_Document_Transfer::save_into($page_id, $prepared, $extra_settings);
        if (is_wp_error($save_result)) {
            return $save_result;
        }

        if (! empty($payload['post']['post_content']) && ! ($payload['post']['built_with_elementor'] ?? false)) {
            wp_update_post(['ID' => $page_id, 'post_content' => $payload['post']['post_content']]);
        }

        $meta_warnings = self::apply_meta($page_id, $payload, $maps);
        $warnings = array_merge($warnings, $meta_warnings);

        $gating_result = self::apply_membership_pages($page_id, $payload, $maps);
        if ($gating_result !== true) {
            $warnings[] = $gating_result; // string reason — gating left untouched
        }

        // Stamping _dd_settings_io_uid is the importer's job (DD_Settings_Importer::apply()) — it
        // already has the source uid from the refs table and stamps every kind uniformly there,
        // rather than this class needing its own copy of that logic.

        return ['id' => $page_id, 'action' => $action, 'warnings' => $warnings];
    }

    private static function create_page($payload, $publish)
    {
        $document = \Elementor\Plugin::$instance->documents->create('wp-page', [
            'post_title'  => $payload['post']['post_title'],
            'post_name'   => $payload['post']['post_name'],
            'post_status' => $publish ? ($payload['post']['post_status'] ?: 'publish') : 'draft',
        ]);

        if (is_wp_error($document)) {
            return $document;
        }

        return $document->get_main_id();
    }

    /**
     * Schema-scoped meta sync: writes plain + resolved special keys, deletes a schema-declared key
     * that's absent from the bundle, and leaves every other target-only key untouched (merge, not
     * sync) — see the plan's deletion-semantics rationale.
     *
     * @return string[] warnings (e.g. "pmpro_default_level unresolved, left unchanged")
     */
    public static function apply_meta($page_id, $payload, array $maps)
    {
        $warnings = [];

        // Plain meta, verbatim — includes ACF sibling keys (`_members_only` = `field_xxx`); ACF
        // resolves those by name too, so an occasional stale key on a target whose field group
        // wasn't cloned from the same source degrades rather than breaks.
        foreach ($payload['meta'] as $key => $value) {
            update_post_meta($page_id, $key, $value);
        }

        $classes = dd_page_meta_classes();
        foreach ($classes as $key => $spec) {
            $kind_map = $spec['class'] === 'level_ref' ? ($maps['level'] ?? []) : ($maps['attachment'] ?? []);

            if (empty($payload['special'][$key])) {
                // Bundle carried no (resolvable) value for this schema key — nothing to write or
                // delete; absence here just means the source page never had it set either.
                continue;
            }

            // Exporter has already converted this to a {"$ref":kind,"uid":…} marker (see
            // DD_Settings_Exporter::convert_page_refs()) — resolve by uid, same as every other ref.
            $marker = $payload['special'][$key];
            $target_id = is_array($marker) ? ($kind_map[$marker['uid'] ?? ''] ?? null) : null;

            if ($target_id) {
                update_post_meta($page_id, $key, $target_id);
            } else {
                $warnings[] = "{$key} unresolved — left unchanged on the target.";
            }
        }

        return $warnings;
    }

    /**
     * Replaces this page's pmpro_memberships_pages rows with the bundle's resolved set. Unlike
     * general meta, this is a real sync (not merge) — the whole point is that access restrictions
     * on the target should match the source — but ONLY once every referenced level has resolved;
     * a partially-applied gating set could be less restrictive than either environment intended.
     *
     * @return true|string true on success, or a warning string if left untouched.
     */
    public static function apply_membership_pages($page_id, $payload, array $maps)
    {
        global $wpdb;

        $source_levels = $payload['pmpro_gating'] ?? [];
        if (empty($source_levels)) {
            return true; // source page had no gating rows — nothing to sync
        }

        $level_map = $maps['level'] ?? [];
        $target_levels = [];
        foreach ($source_levels as $marker) {
            $uid = is_array($marker) ? ($marker['uid'] ?? '') : '';
            if (! $uid || ! isset($level_map[$uid])) {
                return "PMPro page-access levels unresolved for page — left the existing gating rows untouched.";
            }
            $target_levels[] = $level_map[$uid];
        }

        $wpdb->delete($wpdb->prefix . 'pmpro_memberships_pages', ['page_id' => $page_id]);
        foreach (array_unique($target_levels) as $level_id) {
            $wpdb->insert($wpdb->prefix . 'pmpro_memberships_pages', [
                'page_id'       => $page_id,
                'membership_id' => $level_id,
            ]);
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Rollback — revision-based (see plan: cheap delta, not a full-content dump)
    // -----------------------------------------------------------------

    /**
     * Captures a pre-touch delta: an Elementor/WP revision (which already carries
     * _elementor_data/_wp_page_template/_thumbnail_id — see safe_copy_elementor_meta()), post
     * fields, gating rows, and every meta key import's apply_meta() could write. That's NOT just
     * the schema-classified (level_ref/attachment_ref) keys — apply_meta() also plain-copies every
     * non-denylisted key (members_only, header_text_colour, …), and none of those are covered by
     * the revision (WP revisions only carry post_content; safe_copy_elementor_meta() only copies
     * the three Elementor-specific keys above) — so they must be captured here or restore silently
     * leaves them at whatever the import wrote. Still cheap: a handful of short values per page, not
     * the ~20KB _elementor_data the revision already handles. Call immediately before touching a
     * page, not batched up front, so restore only ever reverses what actually changed.
     */
    public static function snapshot_page($page_id)
    {
        $post = get_post($page_id);
        if (! $post) {
            return null;
        }

        $revision_id = wp_save_post_revision($page_id);

        // Same universe apply_meta() can write: every raw meta key minus the denylist, plus
        // _wp_page_template (denylisted from the generic loop because it's applied via
        // Document::save()'s settings channel, but still something import can change).
        $raw = get_post_meta($page_id);
        $deny_exact = dd_page_meta_denylist();
        $deny_prefixes = dd_page_meta_denylist_prefixes();
        $captured_meta = [];
        foreach ($raw as $key => $values) {
            if (in_array($key, $deny_exact, true)) {
                continue;
            }
            foreach ($deny_prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    continue 2;
                }
            }
            $captured_meta[$key] = count($values) > 1 ? array_map('maybe_unserialize', $values) : ($values[0] ?? '');
        }
        $captured_meta['_wp_page_template'] = get_post_meta($page_id, '_wp_page_template', true);

        return [
            'page_id'      => $page_id,
            'revision_id'  => $revision_id ?: null,
            'post_fields'  => [
                'post_title'   => $post->post_title,
                'post_name'    => $post->post_name,
                'post_excerpt' => $post->post_excerpt,
                'post_status'  => $post->post_status,
                'post_parent'  => $post->post_parent,
                'menu_order'   => $post->menu_order,
            ],
            'meta'         => $captured_meta,
            'pmpro_gating' => self::export_membership_pages($page_id),
        ];
    }

    public static function restore_page($entry)
    {
        if (empty($entry['page_id']) || ! get_post($entry['page_id'])) {
            return false;
        }

        if (! empty($entry['revision_id']) && get_post($entry['revision_id'])) {
            wp_restore_post_revision($entry['revision_id']);
        }

        wp_update_post(array_merge(['ID' => $entry['page_id']], $entry['post_fields']));

        foreach ($entry['meta'] as $key => $value) {
            if ($value === '' || $value === false) {
                delete_post_meta($entry['page_id'], $key);
            } else {
                update_post_meta($entry['page_id'], $key, $value);
            }
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'pmpro_memberships_pages', ['page_id' => $entry['page_id']]);
        foreach ($entry['pmpro_gating'] as $level_id) {
            $wpdb->insert($wpdb->prefix . 'pmpro_memberships_pages', [
                'page_id'       => $entry['page_id'],
                'membership_id' => $level_id,
            ]);
        }

        return true;
    }
}
