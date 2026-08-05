<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stable identity for the four kinds of environment-local ID this feature moves between sites:
 * pages, Elementor templates, PMPro levels, and attachments. Export stamps a UID onto the source
 * object (lazily, on first export) and describes it; import resolves that descriptor against the
 * target site (UID match first, then a same-site-appropriate fallback) and stamps the same UID
 * onto whatever it lands on, so re-imports become UID-exact instead of guessing again.
 */
class DD_Settings_Refs
{
    const META_KEY = '_dd_settings_io_uid';
    const LEVELMETA_KEY = 'dd_settings_io_uid';

    // -----------------------------------------------------------------
    // Export side — stamp + describe
    // -----------------------------------------------------------------

    public static function uid_for_post($post_id)
    {
        $uid = get_post_meta($post_id, self::META_KEY, true);
        if (! $uid) {
            $uid = wp_generate_uuid4();
            update_post_meta($post_id, self::META_KEY, $uid);
        }
        return $uid;
    }

    public static function uid_for_level($level_id)
    {
        if (! function_exists('get_pmpro_membership_level_meta')) {
            return '';
        }
        $uid = get_pmpro_membership_level_meta($level_id, self::LEVELMETA_KEY, true);
        if (! $uid) {
            $uid = wp_generate_uuid4();
            update_pmpro_membership_level_meta($level_id, self::LEVELMETA_KEY, $uid);
        }
        return $uid;
    }

    public static function page_descriptor($post_id)
    {
        $post = get_post($post_id);
        if (! $post) {
            return null;
        }
        return [
            'uid'       => self::uid_for_post($post_id),
            'slug'      => $post->post_name,
            'path'      => get_page_uri($post_id),
            'title'     => $post->post_title,
            'post_type' => $post->post_type,
            'source_id' => (int) $post_id,
        ];
    }

    public static function template_descriptor($post_id)
    {
        $post = get_post($post_id);
        if (! $post || ! class_exists('\Elementor\TemplateLibrary\Source_Local')) {
            return null;
        }
        return [
            'uid'       => self::uid_for_post($post_id),
            'title'     => $post->post_title,
            'tpl_type'  => \Elementor\TemplateLibrary\Source_Local::get_template_type($post_id),
            'source_id' => (int) $post_id,
        ];
    }

    public static function level_descriptor($level_id)
    {
        if (! function_exists('pmpro_getLevel')) {
            return null;
        }
        $level = pmpro_getLevel($level_id);
        if (empty($level)) {
            return null;
        }
        return [
            'uid'            => self::uid_for_level($level_id),
            'name'           => $level->name,
            'name_norm'      => self::normalize_name($level->name),
            'initial_payment' => (float) $level->initial_payment,
            'billing_amount' => (float) $level->billing_amount,
            'cycle_number'   => (int) $level->cycle_number,
            'cycle_period'   => (string) $level->cycle_period,
            'source_id'      => (int) $level_id,
        ];
    }

    public static function attachment_descriptor($post_id)
    {
        $post = get_post($post_id);
        if (! $post) {
            return null;
        }
        $file = get_post_meta($post_id, '_wp_attached_file', true);
        return [
            'uid'       => self::uid_for_post($post_id),
            'filename'  => $file ? basename($file) : '',
            'title'     => $post->post_title,
            'source_id' => (int) $post_id,
        ];
    }

    // -----------------------------------------------------------------
    // Import side — resolve descriptor against THIS site
    // -----------------------------------------------------------------

    /**
     * @return array{id:int, confidence:string}|null
     */
    public static function resolve_page($descriptor)
    {
        if (empty($descriptor)) {
            return null;
        }

        $post_type = ! empty($descriptor['post_type']) ? $descriptor['post_type'] : 'page';

        $by_uid = self::find_post_by_uid($descriptor['uid'], $post_type);
        if ($by_uid) {
            return ['id' => $by_uid, 'confidence' => 'uid'];
        }

        if (! empty($descriptor['path'])) {
            $post = get_page_by_path($descriptor['path'], OBJECT, $post_type);
            if ($post) {
                return ['id' => (int) $post->ID, 'confidence' => 'slug'];
            }
        }

        if (! empty($descriptor['title'])) {
            $found = get_posts([
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'title'          => $descriptor['title'],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if ($found) {
                return ['id' => (int) $found[0], 'confidence' => 'title'];
            }
        }

        return null;
    }

    public static function resolve_template($descriptor)
    {
        if (empty($descriptor)) {
            return null;
        }

        $by_uid = self::find_post_by_uid($descriptor['uid'], 'elementor_library');
        if ($by_uid) {
            return ['id' => $by_uid, 'confidence' => 'uid'];
        }

        if (! empty($descriptor['title'])) {
            $found = get_posts([
                'post_type'      => 'elementor_library',
                'post_status'    => ['publish', 'private'],
                'title'          => $descriptor['title'],
                'posts_per_page' => 5,
                'fields'         => 'ids',
            ]);
            foreach ($found as $candidate_id) {
                $type = class_exists('\Elementor\TemplateLibrary\Source_Local')
                    ? \Elementor\TemplateLibrary\Source_Local::get_template_type($candidate_id)
                    : '';
                if ($type === ($descriptor['tpl_type'] ?? '')) {
                    return ['id' => (int) $candidate_id, 'confidence' => 'title'];
                }
            }
        }

        return null;
    }

    public static function resolve_level($descriptor)
    {
        if (empty($descriptor) || ! function_exists('pmpro_getAllLevels')) {
            return null;
        }

        global $wpdb;
        $level_id = $wpdb->get_var($wpdb->prepare(
            "SELECT pmpro_membership_level_id FROM {$wpdb->prefix}pmpro_membership_levelmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::LEVELMETA_KEY,
            $descriptor['uid']
        ));
        if ($level_id) {
            return ['id' => (int) $level_id, 'confidence' => 'uid'];
        }

        $by_name = pmpro_getLevel($descriptor['name']);
        if (! empty($by_name)) {
            return ['id' => (int) $by_name->id, 'confidence' => 'name'];
        }

        $norm = $descriptor['name_norm'] ?? self::normalize_name($descriptor['name']);
        foreach (pmpro_getAllLevels(true, true) as $level) {
            if (self::normalize_name($level->name) === $norm) {
                return ['id' => (int) $level->id, 'confidence' => 'fuzzy'];
            }
        }

        return null;
    }

    public static function resolve_attachment($descriptor)
    {
        if (empty($descriptor)) {
            return null;
        }

        $by_uid = self::find_post_by_uid($descriptor['uid'], 'attachment');
        if ($by_uid) {
            return ['id' => $by_uid, 'confidence' => 'uid'];
        }

        if (! empty($descriptor['filename'])) {
            global $wpdb;
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($descriptor['filename'])
            ));
            if ($found) {
                return ['id' => (int) $found, 'confidence' => 'filename'];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Stamping the source UID onto a resolved/created target object
    // -----------------------------------------------------------------

    public static function stamp_post_uid($post_id, $uid)
    {
        if ($post_id && $uid) {
            update_post_meta($post_id, self::META_KEY, $uid);
        }
    }

    public static function stamp_level_uid($level_id, $uid)
    {
        if ($level_id && $uid && function_exists('update_pmpro_membership_level_meta')) {
            update_pmpro_membership_level_meta($level_id, self::LEVELMETA_KEY, $uid);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function find_post_by_uid($uid, $post_type)
    {
        if (! $uid) {
            return null;
        }
        $found = get_posts([
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::META_KEY,
            'meta_value'     => $uid,
        ]);
        return $found ? (int) $found[0] : null;
    }

    public static function normalize_name($name)
    {
        $name = strtolower((string) $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        return trim($name);
    }
}
