<?php
/**
 * Brief Parser: Extracts structured filters from natural-language campaign briefs.
 * Rule-based keyword extraction — no AI. Maps brief phrases to internal filter values.
 * Merged with Influencer Collective keyword dictionaries.
 *
 * @package HelloElementorChild
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get keyword mappings for brief parsing.
 * Merged from: Niche_category, Geography, Audience dictionaries + wellbeing/wellness synonym.
 *
 * @return array
 */
function get_brief_keyword_mappings()
{
    return [
        // Niche: Brief keyword => niche slug(s). Wellbeing & wellness are synonyms (both map to both).
        'niche' => [
            // Wellbeing / wellness (synonyms — both map to both slugs)
            'wellbeing'           => ['wellbeing', 'wellness'],
            'wellness'            => ['wellbeing', 'wellness'],
            'self-care'           => ['wellbeing', 'wellness'],
            'self care'           => ['wellbeing', 'wellness'],
            'holistic health'     => ['wellbeing', 'wellness'],
            'mindfulness'         => ['wellbeing', 'wellness'],
            'mental health'       => ['wellbeing', 'wellness'],
            'emotional wellbeing' => ['wellbeing', 'wellness'],
            'stress relief'       => ['wellbeing', 'wellness'],
            'relaxation'          => ['wellbeing', 'wellness'],
            'healthy living'      => ['wellbeing', 'wellness'],
            'lifestyle wellness'  => ['wellbeing', 'wellness'],
            'self-improvement'    => ['wellbeing', 'wellness'],
            'beauty wellness'     => ['wellbeing', 'wellness'],
            'wellness routines'   => ['wellbeing', 'wellness'],
            'women\'s wellbeing'  => ['wellbeing', 'wellness'],
            'men\'s wellbeing'    => ['wellbeing', 'wellness'],
            // Fertility
            'fertility'           => 'fertility-doctor',
            'ivf'                 => 'fertility-doctor',
            'ttc'                 => 'fertility-doctor',
            'trying to conceive'  => 'fertility-doctor',
            'infertility'         => 'fertility-doctor',
            // Pregnancy
            'pregnancy'           => 'pregnancy',
            'pregnant'            => 'pregnancy',
            'expecting'           => 'pregnancy',
            'mum-to-be'           => 'pregnancy',
            'mom-to-be'           => 'pregnancy',
            'dad-to-be'           => 'pregnancy',
            'postpartum'          => 'pregnancy',
            'maternity'           => 'pregnancy',
            // Parenting
            'parenting'           => ['parenting', 'motherhood'],
            'motherhood'          => ['parenting', 'motherhood'],
            'fatherhood'          => 'parenting',
            'mum life'            => ['parenting', 'motherhood'],
            'mom life'            => ['parenting', 'motherhood'],
            'family life'         => 'parenting',
            'newborn'             => 'parenting',
            'babies'              => 'parenting',
            'toddlers'            => 'parenting',
            'children'            => 'parenting',
            // General
            'skincare'            => 'skincare',
            'beauty'              => 'beauty',
            'fitness'             => 'fitness',
            'nutrition'           => 'nutrition',
            'diet'                => 'nutrition',
            'healthy eating'      => 'nutrition',
            'exercise'            => 'fitness',
            'movement'            => 'fitness',
            'yoga'                => 'fitness',
            'pilates'             => 'fitness',
            'running'             => 'fitness',
            'fashion'             => 'fashion',
            'travel'              => 'travel',
            'vegan'               => 'vegan',
            'food'                => 'food',
            'lifestyle'           => 'lifestyle',
            'health'              => 'health',
            'haircare'            => 'haircare',
        ],

        // Geography: Brief keyword => country alpha3 (UPPERCASE). From Geography dictionary.
        'country' => [
            'worldwide'        => 'GLOBAL',
            'global'           => 'GLOBAL',
            'international'    => 'GLOBAL',
            'uk'               => 'GBR',
            'u.k.'             => 'GBR',
            'united kingdom'   => 'GBR',
            'england'          => 'GBR',
            'scotland'         => 'GBR',
            'wales'            => 'GBR',
            'northern ireland' => 'GBR',
            'britain'          => 'GBR',
            'london'           => 'GBR',
            'manchester'       => 'GBR',
            'birmingham'       => 'GBR',
            'usa'              => 'USA',
            'united states'    => 'USA',
            'america'          => 'USA',
            'us'               => 'USA',
            'los angeles'      => 'USA',
            'new york'         => 'USA',
            'chicago'          => 'USA',
            'miami'            => 'USA',
            'europe'           => 'EUROPE',
            'eu'               => 'EUROPE',
            'germany'          => 'DEU',
            'france'           => 'FRA',
            'spain'            => 'ESP',
            'italy'            => 'ITA',
            'netherlands'      => 'NLD',
            'australia'        => 'AUS',
            'aus'              => 'AUS',
            'sydney'           => 'AUS',
            'melbourne'        => 'AUS',
            'canada'           => 'CAN',
            'ca'               => 'CAN',
            'toronto'          => 'CAN',
            'vancouver'        => 'CAN',
        ],

        // Platform
        'platform' => [
            'instagram' => 'instagram',
            'ig'        => 'instagram',
            'insta'     => 'instagram',
            'youtube'   => 'youtube',
            'yt'        => 'youtube',
            'tiktok'    => 'tiktok',
            'tik tok'   => 'tiktok',
            'facebook'  => 'facebook',
        ],

        // Budget => follower range
        'budget_to_followers' => [
            ['max' => 500, 'range' => '1000-10000'],
            ['max' => 2000, 'range' => '10000-50000'],
            ['max' => 5000, 'range' => '50000-250000'],
            ['max' => 15000, 'range' => '250000-1000000'],
            ['max' => 999999, 'range' => '1000000-10000000'],
        ],

        // Filter options
        'filter' => [
            'verified'     => 'Include only verified influencers',
            'verification' => 'Include only verified influencers',
            'engagement'   => 'Prioritise engagement over reach',
            'engage'       => 'Prioritise engagement over reach',
        ],

        // Audience (for future gender/age filters) — from Audience dictionary
        'gender' => [
            'female'   => 'Female',
            'women'    => 'Female',
            'woman'    => 'Female',
            'mums'     => 'Female',
            'moms'     => 'Female',
            'ladies'   => 'Female',
            'girls'    => 'Female',
            'male'     => 'Male',
            'men'      => 'Male',
            'man'      => 'Male',
            'dads'     => 'Male',
            'fathers'  => 'Male',
            'guys'     => 'Male',
            'boys'     => 'Male',
            'nonbinary' => 'Non-Binary',
            'non-binary' => 'Non-Binary',
            'nb'       => 'Non-Binary',
            'lgbtq'    => 'lgbtq_plus',
            'lgbt'     => 'lgbtq_plus',
            'queer'    => 'lgbtq_plus',
        ],
        'age' => [
            'gen z'           => '18-24',
            '18-24'           => '18-24',
            'young audience'  => '18-24',
            'students'        => '18-24',
            'teens'           => '18-24',
            'millennial'      => '25-34',
            'millennials'     => '25-34',
            '25-34'           => '25-34',
            'young adults'    => '25-34',
            'gen x'           => '35-44',
            '35-44'           => '35-44',
            'middle aged'     => '35-44',
            '45+'             => '45-54',
            '50+'             => '45-54',
            'older audience'  => '45-54',
            'midlife'         => '45-54',
        ],
    ];
}

/**
 * Normalize niche mapping value (can be string or array).
 *
 * @param mixed $val
 * @return array
 */
function _brief_normalize_slugs($val)
{
    if (is_array($val)) {
        return $val;
    }
    return $val ? [$val] : [];
}

/**
 * Resolve extracted niche keywords to actual taxonomy slugs.
 * Exact match only. Wellbeing/wellness synonyms both resolve.
 */
function resolve_brief_niches($keywords)
{
    if (empty($keywords)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'niche',
        'hide_empty' => false,
        'fields'     => 'id=>slug',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $slugs = array_values($terms);
    $found = [];

    foreach ($keywords as $kw) {
        $kw_lower = strtolower(trim($kw));
        foreach ($slugs as $slug) {
            if ($slug === $kw_lower) {
                $found[$slug] = true;
            }
        }
    }

    return array_keys($found);
}

/**
 * Resolve platform keywords to taxonomy slugs.
 */
function resolve_brief_platforms($keywords)
{
    if (empty($keywords)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'platform',
        'hide_empty' => false,
        'fields'     => 'id=>slug',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $slugs = array_values($terms);
    $found = [];

    foreach ($keywords as $kw) {
        $kw_lower = strtolower(trim($kw));
        foreach ($slugs as $slug) {
            if ($slug === $kw_lower) {
                $found[$slug] = true;
            }
        }
    }

    return array_keys($found);
}

/**
 * Resolve topic keywords to taxonomy slugs.
 */
function resolve_brief_topics($keywords)
{
    if (empty($keywords)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'topic',
        'hide_empty' => false,
        'fields'     => 'id=>slug',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $slugs = array_values($terms);
    $found = [];

    foreach ($keywords as $kw) {
        $kw_lower = strtolower(trim($kw));
        foreach ($slugs as $slug) {
            if ($slug === $kw_lower) {
                $found[$slug] = true;
            }
        }
    }

    return array_keys($found);
}

/**
 * Resolve content_tag keywords to taxonomy slugs.
 */
function resolve_brief_content_tags($keywords)
{
    if (empty($keywords)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'content_tag',
        'hide_empty' => false,
        'fields'     => 'id=>slug',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $slugs = array_values($terms);
    $found = [];

    foreach ($keywords as $kw) {
        $kw_lower = strtolower(trim($kw));
        foreach ($slugs as $slug) {
            if ($slug === $kw_lower) {
                $found[$slug] = true;
            }
        }
    }

    return array_keys($found);
}

/**
 * Parse a campaign brief and extract structured filters.
 *
 * @param string $text Raw brief text.
 * @return array niche, country, platform, followers, filter, topic, content_tag
 */
function parse_search_brief($text)
{
    $result = [
        'niche'        => [],
        'country'      => [],
        'platform'     => [],
        'followers'    => [],
        'filter'       => [],
        'topic'        => [],
        'content_tag'  => [],
    ];

    if (empty($text) || !is_string($text)) {
        return $result;
    }

    $text_lower = strtolower(trim($text));
    $mappings   = get_brief_keyword_mappings();

    // Budget
    if (preg_match('/[£$]\s*([\d,]+)(?:\s*(?:per|per creator|each))?/i', $text, $m)) {
        $amount = (int) preg_replace('/[^\d]/', '', $m[1]);
        foreach ($mappings['budget_to_followers'] as $tier) {
            if ($amount <= $tier['max']) {
                $result['followers'] = [$tier['range']];
                break;
            }
        }
        if (empty($result['followers']) && $amount > 15000) {
            $result['followers'] = ['1000000-10000000'];
        }
    }

    // Niches (with wellbeing/wellness synonym — keywords can map to multiple slugs)
    $niche_keywords = [];
    foreach ($mappings['niche'] as $phrase => $slug_or_slugs) {
        if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
            $niche_keywords = array_merge($niche_keywords, _brief_normalize_slugs($slug_or_slugs));
        }
    }
    $result['niche'] = resolve_brief_niches(array_unique($niche_keywords));

    // Countries
    $country_found = [];
    $europe_codes = ['DEU', 'FRA', 'ESP', 'ITA', 'NLD']; // From Geography dict: europe = germany, france, spain, italy, netherlands
    foreach ($mappings['country'] as $phrase => $code) {
        if ($code === 'GLOBAL') {
            continue; // Skip — no single alpha3
        }
        if (in_array($code, ['EU', 'EUROPE'], true)) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
                foreach ($europe_codes as $c) {
                    $country_found[$c] = true;
                }
            }
            continue;
        }
        if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
            $country_found[$code] = true;
        }
    }
    $result['country'] = array_keys($country_found);

    // Platforms
    $platform_keywords = [];
    foreach ($mappings['platform'] as $phrase => $slug) {
        if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
            $platform_keywords[] = $slug;
        }
    }
    $result['platform'] = resolve_brief_platforms(array_unique($platform_keywords));

    // Filters
    foreach ($mappings['filter'] as $phrase => $filter_key) {
        if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text_lower)) {
            $result['filter'][] = $filter_key;
        }
    }
    $result['filter'] = array_unique($result['filter']);

    // Topics & Content Tags — use niche keywords as hints (brief phrases often overlap)
    // For now, resolve the same extracted niche-related terms against topic/content_tag
    $topic_keywords = array_unique($niche_keywords);
    $result['topic'] = resolve_brief_topics($topic_keywords);

    $content_tag_keywords = array_unique($niche_keywords);
    $result['content_tag'] = resolve_brief_content_tags($content_tag_keywords);

    return $result;
}

/**
 * Merge parsed brief filters with explicitly selected filters.
 */
function merge_brief_with_explicit_filters($parsed, $explicit)
{
    $keys = ['niche', 'country', 'platform', 'followers', 'filter', 'topic', 'content_tag'];

    foreach ($keys as $key) {
        if (!isset($parsed[$key])) {
            $parsed[$key] = [];
        }
    }

    $merged = [];

    foreach ($keys as $key) {
        $explicit_val = isset($explicit[$key]) ? $explicit[$key] : [];
        if (!is_array($explicit_val)) {
            $explicit_val = $explicit_val ? [$explicit_val] : [];
        }
        $parsed_val = isset($parsed[$key]) ? $parsed[$key] : [];

        if (!empty($explicit_val)) {
            $merged[$key] = $explicit_val;
        } elseif (!empty($parsed_val)) {
            $merged[$key] = $parsed_val;
        } else {
            $merged[$key] = [];
        }
    }

    return $merged;
}
