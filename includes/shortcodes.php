<?php
function influencer_avatar_shortcode()
{
    // Get the current post ID
    $post_id = get_the_ID();

    // Try to get the URL from the 'avatar' meta key
    $url = get_post_meta($post_id, 'avatar', true);

    // If meta is empty, get the URL of the fallback image (Media ID 1843)
    if (empty($url)) {
        $url = wp_get_attachment_url(1843);
    }

    // If we found a URL (either from meta or fallback), return the image tag
    if ($url) {
        return '<img src="' . esc_url($url) . '" class="influencer-avatar" alt="Influencer Avatar" />';
    }

    return ''; // Return nothing if URL is invalid
}
add_shortcode('influencer_avatar', 'influencer_avatar_shortcode');
/**
 * Shortcode to display Flag and Country Code (Supports 2 or 3 letter codes)
 * Usage: [country_with_flag]
 */
function get_country_flag_from_meta()
{
    $post_id = get_the_ID();

    // 1. Get the code from meta
    $raw_code = get_post_meta($post_id, 'country', true);

    if (empty($raw_code)) {
        return '';
    }

    // 2. Prepare Display Text (Keep original 3-letter or 2-letter format)
    $display_text = strtoupper(esc_html($raw_code));

    // 3. Prepare Image Source Code (Must be 2-letter lowercase)
    $code_clean = strtolower(esc_attr($raw_code));
    $flag_code  = $code_clean; // Default assumption

    // If it is a 3-letter code, convert it
    if (strlen($code_clean) === 3) {
        $flag_code = iso_alpha3_to_alpha2($code_clean);
    }

    // If no valid flag code found after conversion, just return text
    if (! $flag_code) {
        return $display_text;
    }

    // 4. Generate HTML
    $output  = '<span class="meta-country-wrapper" style="display: inline-flex; align-items: center; gap: 8px;">';

    // Flag Image
    $output .= sprintf(
        '<img src="https://flagcdn.com/%s.svg" alt="%s Flag" style="width: 24px; height: auto; box-shadow: 1px 1px 3px rgba(0,0,0,0.1); border-radius: 2px;">',
        $flag_code,
        $display_text
    );

    // Text (Right side)
    $output .= sprintf('<span class="country-code-text">%s</span>', $display_text);

    $output .= '</span>';

    return $output;
}
add_shortcode('influencer_country_with_flag', 'get_country_flag_from_meta');

/**
 * Helper function: Map 3-letter codes to 2-letter codes
 */
function iso_alpha3_to_alpha2($alpha3)
{
    $mapping = array(
        'afg' => 'af',
        'alb' => 'al',
        'dza' => 'dz',
        'asm' => 'as',
        'and' => 'ad',
        'ago' => 'ao',
        'aia' => 'ai',
        'ata' => 'aq',
        'atg' => 'ag',
        'arg' => 'ar',
        'arm' => 'am',
        'abw' => 'aw',
        'aus' => 'au',
        'aut' => 'at',
        'aze' => 'az',
        'bhs' => 'bs',
        'bhr' => 'bh',
        'bgd' => 'bd',
        'brb' => 'bb',
        'blr' => 'by',
        'bel' => 'be',
        'blz' => 'bz',
        'ben' => 'bj',
        'bmu' => 'bm',
        'btn' => 'bt',
        'bol' => 'bo',
        'bes' => 'bq',
        'bih' => 'ba',
        'bwa' => 'bw',
        'bvt' => 'bv',
        'bra' => 'br',
        'iot' => 'io',
        'brn' => 'bn',
        'bgr' => 'bg',
        'bfa' => 'bf',
        'bdi' => 'bi',
        'cpv' => 'cv',
        'khm' => 'kh',
        'cmr' => 'cm',
        'can' => 'ca',
        'cym' => 'ky',
        'caf' => 'cf',
        'tcd' => 'td',
        'chl' => 'cl',
        'chn' => 'cn',
        'cxr' => 'cx',
        'cck' => 'cc',
        'col' => 'co',
        'com' => 'km',
        'cod' => 'cd',
        'cog' => 'cg',
        'cok' => 'ck',
        'cri' => 'cr',
        'hrv' => 'hr',
        'cub' => 'cu',
        'cuw' => 'cw',
        'cyp' => 'cy',
        'cze' => 'cz',
        'dnk' => 'dk',
        'dji' => 'dj',
        'DMA' => 'dm',
        'dom' => 'do',
        'ecu' => 'ec',
        'egy' => 'eg',
        'slv' => 'sv',
        'gnq' => 'gq',
        'eri' => 'er',
        'est' => 'ee',
        'eth' => 'et',
        'flk' => 'fk',
        'fro' => 'fo',
        'fji' => 'fj',
        'fin' => 'fi',
        'fra' => 'fr',
        'guf' => 'gf',
        'pyf' => 'pf',
        'atf' => 'tf',
        'gab' => 'ga',
        'gmb' => 'gm',
        'geo' => 'ge',
        'deu' => 'de',
        'gha' => 'gh',
        'gib' => 'gi',
        'grc' => 'gr',
        'grl' => 'gl',
        'grd' => 'gd',
        'glp' => 'gp',
        'gum' => 'gu',
        'gtm' => 'gt',
        'ggy' => 'gg',
        'gin' => 'gn',
        'gnb' => 'gw',
        'guy' => 'gy',
        'hti' => 'ht',
        'hmd' => 'hm',
        'vat' => 'va',
        'hnd' => 'hn',
        'hkg' => 'hk',
        'hun' => 'hu',
        'isl' => 'is',
        'ind' => 'in',
        'idn' => 'id',
        'irn' => 'ir',
        'irq' => 'iq',
        'irl' => 'ie',
        'imn' => 'im',
        'isr' => 'il',
        'ita' => 'it',
        'jam' => 'jm',
        'jpn' => 'jp',
        'jey' => 'je',
        'jor' => 'jo',
        'kaz' => 'kz',
        'ken' => 'ke',
        'kir' => 'ki',
        'prk' => 'kp',
        'kor' => 'kr',
        'kwt' => 'kw',
        'kgz' => 'kg',
        'lao' => 'la',
        'lva' => 'lv',
        'lbn' => 'lb',
        'lso' => 'ls',
        'lbr' => 'lr',
        'lby' => 'ly',
        'lie' => 'li',
        'ltu' => 'lt',
        'lux' => 'lu',
        'mac' => 'mo',
        'mkd' => 'mk',
        'mdg' => 'mg',
        'mwi' => 'mw',
        'mys' => 'my',
        'mdv' => 'mv',
        'mli' => 'ml',
        'mlt' => 'mt',
        'mhl' => 'mh',
        'mtq' => 'mq',
        'mrt' => 'mr',
        'mus' => 'mu',
        'myt' => 'yt',
        'mex' => 'mx',
        'fsm' => 'fm',
        'mda' => 'md',
        'mco' => 'mc',
        'mng' => 'mn',
        'mne' => 'me',
        'msr' => 'ms',
        'mar' => 'ma',
        'moz' => 'mz',
        'mmr' => 'mm',
        'nam' => 'na',
        'nru' => 'nr',
        'npl' => 'np',
        'nld' => 'nl',
        'ncl' => 'nc',
        'nzl' => 'nz',
        'nic' => 'ni',
        'ner' => 'ne',
        'nga' => 'ng',
        'niu' => 'nu',
        'nfk' => 'nf',
        'mnp' => 'mp',
        'nor' => 'no',
        'omn' => 'om',
        'pak' => 'pk',
        'plw' => 'pw',
        'pse' => 'ps',
        'pan' => 'pa',
        'png' => 'pg',
        'pry' => 'py',
        'per' => 'pe',
        'phl' => 'ph',
        'pcn' => 'pn',
        'pol' => 'pl',
        'prt' => 'pt',
        'pri' => 'pr',
        'qat' => 'qa',
        'rou' => 'ro',
        'rus' => 'ru',
        'rwa' => 'rw',
        'reu' => 're',
        'blm' => 'bl',
        'shn' => 'sh',
        'kna' => 'kn',
        'lca' => 'lc',
        'maf' => 'mf',
        'spm' => 'pm',
        'vct' => 'vc',
        'wsm' => 'ws',
        'smr' => 'sm',
        'stp' => 'st',
        'sau' => 'sa',
        'sen' => 'sn',
        'srb' => 'rs',
        'syc' => 'sc',
        'sle' => 'sl',
        'sgp' => 'sg',
        'sxm' => 'sx',
        'svk' => 'sk',
        'svn' => 'si',
        'slb' => 'sb',
        'som' => 'so',
        'zaf' => 'za',
        'dgs' => 'gs',
        'ssd' => 'ss',
        'esp' => 'es',
        'lka' => 'lk',
        'sdn' => 'sd',
        'sur' => 'sr',
        'sjm' => 'sj',
        'swz' => 'sz',
        'swe' => 'se',
        'che' => 'ch',
        'syr' => 'sy',
        'twn' => 'tw',
        'tjk' => 'tj',
        'tza' => 'tz',
        'tha' => 'th',
        'tls' => 'tl',
        'tgo' => 'tg',
        'tkl' => 'tk',
        'ton' => 'to',
        'tto' => 'tt',
        'tun' => 'tn',
        'tur' => 'tr',
        'tkm' => 'tm',
        'tca' => 'tc',
        'tuv' => 'tv',
        'uga' => 'ug',
        'ukr' => 'ua',
        'are' => 'ae',
        'gbr' => 'gb',
        'usa' => 'us',
        'umi' => 'um',
        'ury' => 'uy',
        'uzb' => 'uz',
        'vut' => 'vu',
        'ven' => 've',
        'vnm' => 'vn',
        'vgb' => 'vg',
        'vir' => 'vi',
        'wlf' => 'wf',
        'esh' => 'eh',
        'yem' => 'ye',
        'zmb' => 'zm',
        'zwe' => 'zw'
    );

    return isset($mapping[$alpha3]) ? $mapping[$alpha3] : false;
}

/**
 * Get language name from 'lang' meta key using PHP Intl
 */
function get_lang_name_from_meta($post_id = null)
{
    // Get current post ID if none is provided
    if (! $post_id) {
        $post_id = get_the_ID();
    }

    // Get the language code from the 'lang' meta key
    $lang_code = get_post_meta($post_id, 'lang', true);

    // If meta is empty, return nothing
    if (empty($lang_code)) {
        return '';
    }

    // Check if the Intl extension is loaded (standard on most hosts)
    if (class_exists('Locale')) {
        // locale_get_display_language converts 'eng' -> 'English', 'de' -> 'German'
        // 'en_US' is the locale for the output language (so the result is in English)
        $display_name = Locale::getDisplayLanguage($lang_code, 'en_US');

        // Ensure we capitalize the first letter
        return ucfirst($display_name);
    }

    // Fallback if Intl is not enabled on server: Return code as uppercase
    return strtoupper($lang_code);
}

/**
 * Shortcode usage: [post_language]
 */
add_shortcode('influencer_language', 'get_lang_name_from_meta');


function shortcode_influencer_niche()
{
    // 1. Get terms from the current post for taxonomy 'niche'
    $terms = get_the_terms(get_the_ID(), 'niche');

    // 2. Check if terms exist and are not errors
    if (empty($terms) || is_wp_error($terms)) {
        return '';
    }

    // 3. Settings
    $display_limit = 3;
    $count = count($terms);
    $unique_id = uniqid('niche_'); // Create unique ID for JS targeting

    // 4. Start Output Buffer
    ob_start();
?>

    <div class="influencer-niche-container">
        <?php
        $i = 0;
        foreach ($terms as $term) {
            $i++;

            // Determine if this term should be hidden initially
            $is_hidden = $i > $display_limit;
            $style = $is_hidden ? 'display:none;' : '';
            $class = $is_hidden ? 'niche-term term-hidden' : 'niche-term';

            // Output the term (You can change <span> to <a> if you want links)
            echo sprintf(
                '<span class="%s" style="%s">%s</span>',
                esc_attr($class),
                esc_attr($style),
                esc_html($term->name)
            );
        }

        // 5. Add the Plus Sign if needed
        if ($count > $display_limit) : ?>
            <span class="niche-toggle">
                + <?php echo ($count - $display_limit); ?>
            </span>
        <?php endif; ?>

    </div>

<?php
    return ob_get_clean();
}
add_shortcode('influencer_niche', 'shortcode_influencer_niche');

function shortcode_influencer_followers()
{
    return wp_custom_number_format_short(get_post_meta(get_the_ID(), 'followers', true));
}

add_shortcode('influencer_followers', 'shortcode_influencer_followers');

function shortcode_influence_isverified()
{
    $is_verified = get_post_meta(get_the_ID(), 'isverified', true);
    if ($is_verified) {
        return 'is-verified';
    }
}
add_shortcode('influencer_isverified', 'shortcode_influence_isverified');

function shortcode_influencer_search_filter()
{
    ob_start();
?>
<div class="influencer-search-filter-holder">
    <div class="influencer-search-item">
        <?= select_filter('influencer_tags', array('Instagram' => 'Instagram', 'Female' => 'Female')) ?>
    </div>
</div>
<?php
    return ob_get_clean();
}

add_shortcode('influencer_search_filter', 'shortcode_influencer_search_filter');
