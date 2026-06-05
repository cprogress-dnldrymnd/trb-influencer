<?php

/**
 * Helper: Check if the current user has unlocked (purchased) the influencer.
 */
function is_influencer_unlocked($influencer_id)
{
    $user_id = get_current_user_id();

    // 1. Check custom meta from our new unlock function
    $unlocked_meta = get_user_meta($user_id, 'dd_unlocked_influencers', true);
    if (is_array($unlocked_meta) && in_array($influencer_id, $unlocked_meta)) {
        return true;
    }

    // 2. Check existing ecosystem function
    if (function_exists('get_user_purchased_post_ids')) {
        $unlocked_ids = (array) get_user_purchased_post_ids('influencer', true);
        return in_array($influencer_id, $unlocked_ids);
    }

    return false;
}

/**
 * Calculate Influencer Match Score
 */
function calculate_match_score($post_id, $criteria)
{
    if (function_exists('creatordb_calculate_match_score')) {
        return creatordb_calculate_match_score($post_id, $criteria);
    }

    if (!$post_id || get_post_type($post_id) !== 'influencer') return -1;
    if (!is_array($criteria) || (empty($criteria['niche']) && empty($criteria['platform']) && empty($criteria['country']) && empty($criteria['followers']) && empty($criteria['topic']) && empty($criteria['content_tag']))) return -1;

    $earned = 0;
    $max_poss = 0;
    $content_terms = array_unique(array_filter(array_merge((array)($criteria['niche'] ?? []), (array)($criteria['topic'] ?? []), (array)($criteria['content_tag'] ?? []))));

    if (!empty($content_terms)) {
        $max_poss += 40;
        $influencer_slugs = [];
        foreach (['niche', 'topic', 'content_tag'] as $tax) {
            $terms = wp_get_post_terms($post_id, $tax);
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $t) {
                    $influencer_slugs[] = $t->slug;
                }
            }
        }
        $matched = count(array_intersect($content_terms, $influencer_slugs));
        $earned += $matched > 0 ? (int) round(($matched / count($content_terms)) * 40) : 0;
    }

    if (!empty($criteria['country'])) {
        $max_poss += 30;
        $incountry = strtoupper(trim((string) get_post_meta($post_id, 'country', true)));
        $req = array_map('strtoupper', array_map('trim', (array) $criteria['country']));
        if ($incountry && in_array($incountry, $req, true)) $earned += 30;
    }

    if (!empty($criteria['platform'])) {
        $max_poss += 15;
        $platforms = wp_get_post_terms($post_id, 'platform');
        if (!is_wp_error($platforms) && is_array($platforms)) {
            foreach ($platforms as $t) {
                if (in_array($t->slug, (array) $criteria['platform'], true)) {
                    $earned += 15;
                    break;
                }
            }
        }
    }

    if (!empty($criteria['followers']) && !empty($criteria['followers'][0])) {
        $max_poss += 10;
        $f = (int) get_post_meta($post_id, 'followers', true);
        $range = $criteria['followers'][0];
        if (strpos($range, '-') !== false) {
            $parts = explode('-', $range);
            $min = isset($parts[0]) ? (int) $parts[0] : 0;
            $max = isset($parts[1]) ? (int) str_replace('+', '', $parts[1]) : PHP_INT_MAX;
            if ($f >= $min && $f <= $max) $earned += 10;
        } else {
            if ($f >= (int)$range) $earned += 10;
        }
    }

    if (!empty($criteria['filter']) && in_array('Prioritise engagement over reach', (array) $criteria['filter'], true)) {
        $max_poss += 5;
        $pct = ((float) get_post_meta($post_id, 'engagerate', true)) * 100;
        if ($pct > 0) $earned += min(5, round(($pct / 20) * 5, 0));
    }

    if (!empty($criteria['filter']) && in_array('Include only verified influencers', (array) $criteria['filter'], true)) {
        $max_poss += 5;
        if (get_post_meta($post_id, 'isverified', true)) $earned += 5;
    }

    if ($max_poss <= 0) return -1;
    return max(50, min(100, (int) round(($earned / $max_poss) * 100)));
}

/**
 * Get Match Evidence Criteria Labels
 */
function get_matched_criteria_labels($post_id, $criteria)
{
    if (function_exists('creatordb_get_match_evidence_html')) {
        return creatordb_get_match_evidence_html($post_id, $criteria);
    }

    $phrases = [];
    if (!$post_id || !is_array($criteria)) return $phrases;

    $content_terms = array_unique(array_filter(array_merge((array)($criteria['niche'] ?? []), (array)($criteria['topic'] ?? []), (array)($criteria['content_tag'] ?? []))));
    if (!empty($content_terms)) {
        $influencer_slugs = [];
        foreach (['niche', 'topic', 'content_tag'] as $tax) {
            $terms = wp_get_post_terms($post_id, $tax);
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $t) {
                    $influencer_slugs[] = $t->slug;
                }
            }
        }
        if (count(array_intersect($content_terms, $influencer_slugs)) > 0) {
            $phrases[] = '<span class="checklist">Frequently posts about topics related to your brief</span>';
        }
    }

    if (!empty($criteria['country'])) {
        $incountry = strtoupper(trim((string) get_post_meta($post_id, 'country', true)));
        $req = array_map('strtoupper', array_map('trim', (array) $criteria['country']));
        if ($incountry && in_array($incountry, $req, true)) {
            $phrases[] = '<span class="checklist">Audience demographics align well with your target</span>';
        }
    }

    if (!empty($criteria['platform'])) {
        $platforms = wp_get_post_terms($post_id, 'platform');
        if (!is_wp_error($platforms) && is_array($platforms)) {
            foreach ($platforms as $t) {
                if (in_array($t->slug, (array) $criteria['platform'], true)) {
                    $phrases[] = '<span class="checklist">Content style fits your campaign goals</span>';
                    break;
                }
            }
        }
    }

    if (!empty($criteria['followers']) && !empty($criteria['followers'][0])) {
        $f = (int) get_post_meta($post_id, 'followers', true);
        $range = $criteria['followers'][0];
        $in_range = (strpos($range, '-') !== false)
            ? ($f >= (int)explode('-', $range)[0] && $f <= (int)str_replace('+', '', explode('-', $range)[1]))
            : ($f >= (int)$range);
        if ($in_range) $phrases[] = '<span class="checklist">Reach aligns with your campaign scope</span>';
    }

    if (!empty($criteria['filter']) && in_array('Prioritise engagement over reach', (array) $criteria['filter'], true)) {
        if ((float) get_post_meta($post_id, 'engagerate', true) > 0) $phrases[] = '<span class="checklist">Engagement levels suit this campaign type</span>';
    }

    if (!empty($criteria['filter']) && in_array('Include only verified influencers', (array) $criteria['filter'], true)) {
        if (get_post_meta($post_id, 'isverified', true)) $phrases[] = '<span class="checklist">Verified creator</span>';
    }

    return array_unique($phrases);
}
