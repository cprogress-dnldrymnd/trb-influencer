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


/**
 * Converts a number to a short metric format (e.g., 1.1K, 1.5M).
 *
 * @param int|float $number The number to format.
 * @param int       $precision Optional. The number of decimal places. Default 1.
 * @return string The formatted number with suffix.
 */
function wp_custom_number_format_short($number, $precision = 1)
{
    // Return 0 immediately if input is empty or zero
    if (empty($number)) {
        return '0';
    }

    // Define suffixes
    $suffixes = array(
        12 => 'T', // Trillion
        9  => 'B', // Billion
        6  => 'M', // Million
        3  => 'K', // Thousand
        0  => '',  // None
    );

    // Loop through suffixes to find the correct range
    foreach ($suffixes as $exponent => $suffix) {
        if (abs($number) >= pow(10, $exponent)) {
            // Divide number by the exponent value
            $display = $number / pow(10, $exponent);

            // Format number to specified precision
            $formatted = number_format($display, $precision);

            // Remove ".0" or ".00" if the decimal is zero (cleaner look)
            // e.g., turns "10.0K" into "10K"
            $formatted = str_replace('.0', '', $formatted);
            $formatted = str_replace('.00', '', $formatted); // Just in case precision is 2

            return $formatted . $suffix;
        }
    }

    // Fallback for numbers smaller than 1000
    return number_format($number);
}


/**
 * Converts a decimal value to a formatted percentage string.
 *
 * This function multiplies the provided decimal by 100 and formats the output
 * using number_format() to guarantee consistent decimal places. 
 * Strict typing is enforced for both parameters and the return value.
 *
 * @param float $decimal   The raw decimal value to convert (e.g., 0.1234).
 * @param int   $precision The number of decimal places for the output. Defaults to 2.
 * @return string          The formatted percentage string appended with the '%' symbol.
 */
function convertDecimalToPercentage(float $decimal, int $precision = 2): string
{
    $percentage = $decimal * 100;

    // number_format handles rounding and trailing zeros automatically
    return number_format($percentage, $precision) . '%';
}




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
 * Helper function: Resolve a full country name (e.g. "United States") to its
 * 2-letter ISO code, for data sources that store names instead of codes.
 * Builds a name => alpha-2 lookup from PHP's Locale class and caches it.
 */
function country_name_to_alpha2($name)
{
    static $map = null;

    if ($map === null) {
        $map = get_transient('dd_country_name_to_alpha2_map');
        if ($map === false) {
            $map = array();
            if (class_exists('Locale')) {
                $alpha2_codes = explode(',', 'ad,ae,af,ag,ai,al,am,ao,aq,ar,as,at,au,aw,ax,az,ba,bb,bd,be,bf,bg,bh,bi,bj,bl,bm,bn,bo,bq,br,bs,bt,bv,bw,by,bz,ca,cc,cd,cf,cg,ch,ci,ck,cl,cm,cn,co,cr,cu,cv,cw,cx,cy,cz,de,dj,dk,dm,do,dz,ec,ee,eg,eh,er,es,et,fi,fj,fk,fm,fo,fr,ga,gb,gd,ge,gf,gg,gh,gi,gl,gm,gn,gp,gq,gr,gs,gt,gu,gw,gy,hk,hm,hn,hr,ht,hu,id,ie,il,im,in,io,iq,ir,is,it,je,jm,jo,jp,ke,kg,kh,ki,km,kn,kp,kr,kw,ky,kz,la,lb,lc,li,lk,lr,ls,lt,lu,lv,ly,ma,mc,md,me,mf,mg,mh,mk,ml,mm,mn,mo,mp,mq,mr,ms,mt,mu,mv,mw,mx,my,mz,na,nc,ne,nf,ng,ni,nl,no,np,nr,nu,nz,om,pa,pe,pf,pg,ph,pk,pl,pm,pn,pr,ps,pt,pw,py,qa,re,ro,rs,ru,rw,sa,sb,sc,sd,se,sg,sh,si,sj,sk,sl,sm,sn,so,sr,ss,st,sv,sx,sy,sz,tc,td,tf,tg,th,tj,tk,tl,tm,tn,to,tr,tt,tv,tw,tz,ua,ug,um,us,uy,uz,va,vc,ve,vg,vi,vn,vu,wf,ws,ye,yt,za,zm,zw');

                foreach ($alpha2_codes as $alpha2) {
                    $display_name = Locale::getDisplayRegion('-' . strtoupper($alpha2), 'en');
                    if ($display_name) {
                        $map[strtolower($display_name)] = $alpha2;
                    }
                }
            }
            set_transient('dd_country_name_to_alpha2_map', $map, 7 * DAY_IN_SECONDS);
        }
    }

    $key = strtolower(trim($name));
    return isset($map[$key]) ? $map[$key] : false;
}


/**
 * Add --admin-bar-height CSS variable to body based on #wpadminbar height.
 * Recalculates on window resize.
 */
function set_admin_bar_height_variable()
{
    // Only run this if the admin bar is actually showing
    if (is_admin_bar_showing()) {
    ?>
        <script type="text/javascript">
            (function() {
                function updateAdminBarHeight() {
                    var adminBar = document.getElementById('wpadminbar');
                    var height = adminBar ? adminBar.offsetHeight : 0;

                    // Set the CSS variable on the body
                    document.body.style.setProperty('--admin-bar-height', height + 'px');
                }

                // Run on initial load
                window.addEventListener('DOMContentLoaded', updateAdminBarHeight);
                window.addEventListener('load', updateAdminBarHeight);

                // Run whenever the window is resized (as admin bar height changes on mobile)
                window.addEventListener('resize', updateAdminBarHeight);
            })();
        </script>
    <?php
    } else {
        // Optional: Set variable to 0px if admin bar is not present to prevent CSS errors
    ?>
        <script type="text/javascript">
            document.body.style.setProperty('--admin-bar-height', '0px');
        </script>
<?php
    }
}
add_action('wp_footer', 'set_admin_bar_height_variable');

/**
 * Core reusable function to fetch specific meta values for a user's posts.
 * Includes dynamic month-filtering for analytics scopes.
 *
 * @param string $post_type       The custom post type to query.
 * @param bool   $this_month_only If true, restricts query to posts created in the current month and year.
 * @param string $meta_key        The meta key to retrieve. Defaults to 'influencer_id'.
 * @return array                  Array of retrieved integer IDs.
 */
function get_user_post_meta_ids($post_type, $this_month_only = false, $meta_key = 'influencer_id')
{
    if (! is_user_logged_in()) {
        return [];
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // 2. Direct SQL Query
    // This fetches the 'influencer_id' meta directly from the database 
    // for all designated posts authored by the current user.
    // We skip 'get_posts' entirely to avoid conflicts/loops.
    $sql = "
        SELECT pm.meta_value 
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = %s
        AND p.post_status = 'publish'
        AND p.post_author = %d
        AND pm.meta_key = %s
    ";

    $args = [$post_type, $user_id, $meta_key];

    // Dynamically append SQL conditions for the current month constraint
    if ($this_month_only) {
        $sql .= " AND MONTH(p.post_date) = MONTH(CURRENT_DATE()) AND YEAR(p.post_date) = YEAR(CURRENT_DATE())";
    }

    $meta_ids = $wpdb->get_col($wpdb->prepare($sql, ...$args));

    return array_map('intval', $meta_ids);
}

/**
 * Evaluates shortcode attributes to determine the boolean state of the 'this_month_only' flag.
 *
 * @param array|string $atts Shortcode attributes passed by the user.
 * @return bool              True if 'this_month_only' is 'true', false otherwise.
 */
function parse_month_only_attribute($atts)
{
    $atts = shortcode_atts(['this_month_only' => 'false'], $atts);
    return filter_var($atts['this_month_only'], FILTER_VALIDATE_BOOLEAN);
}

/**
 * Retrieves saved influencer IDs for the current user.
 *
 * @param bool $this_month_only Filters results to the current month if true.
 * @return array                Array of IDs.
 */
function get_saved_influencer($this_month_only = false)
{
    return get_user_post_meta_ids('saved-influencer', $this_month_only);
}


/**
 * Retrieves viewed influencer IDs for the current user.
 *
 * @param bool $this_month_only Filters results to the current month if true.
 * @return array                Array of IDs.
 */
function get_viewed_influencer($this_month_only = false)
{
    return get_user_post_meta_ids('viewed-influencer', $this_month_only);
}


/**
 * Retrieves outreach IDs for the current user.
 *
 * @param bool $this_month_only Filters results to the current month if true.
 * @return array                Array of IDs.
 */
function get_outreach($this_month_only = false)
{
    return get_user_post_meta_ids('outreach', $this_month_only);
}


/**
 * Retrieves the total count of saved searches for the current user.
 * Bypasses the postmeta table since searches do not rely on 'influencer_id'.
 *
 * @param bool $this_month_only Filters results to the current month if true.
 * @return int                  Total count of saved search posts.
 */
function get_saved_search_count_direct($this_month_only = false)
{
    if (! is_user_logged_in()) {
        return 0;
    }

    global $wpdb;
    $user_id = get_current_user_id();

    $sql = "
        SELECT COUNT(ID) 
        FROM {$wpdb->posts}
        WHERE post_type = 'saved-search'
        AND post_status = 'publish'
        AND post_author = %d
    ";

    $args = [$user_id];

    // Dynamically append SQL conditions for the current month constraint
    if ($this_month_only) {
        $sql .= " AND MONTH(post_date) = MONTH(CURRENT_DATE()) AND YEAR(post_date) = YEAR(CURRENT_DATE())";
    }

    return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
}




/**
 * Retrieve a list of Post IDs purchased by the current user via myCred.
 *
 * This function queries the myCred log to find all 'buy_content' entries
 * for the current user and returns the IDs of the purchased posts.
 * It allows filtering by specific post types and optionally restricting
 * the results to the current month only.
 *
 * @param string $post_type          Optional. The post type to filter by (e.g., 'influencer', 'post'). Default 'influencer'.
 * @param bool   $current_month_only Optional. Whether to return only purchases made in the current month. Default false.
 *
 * @return array An array of purchased Post IDs (integers). Returns an empty array if none found.
 */
function get_user_purchased_post_ids($post_type = 'influencer', $current_month_only = false)
{
    global $wpdb;

    $user_id = get_current_user_id();

    // Define table names
    $mycred_log_table = $wpdb->prefix . 'myCRED_log';
    $posts_table      = $wpdb->prefix . 'posts';

    // Base SQL Query
    $query = "
        SELECT DISTINCT p.ID 
        FROM {$mycred_log_table} l
        INNER JOIN {$posts_table} p ON l.ref_id = p.ID
        WHERE l.user_id = %d 
        AND l.ref = 'buy_content' 
        AND p.post_type = %s
    ";

    // Prepare arguments array
    $args = array($user_id, $post_type);

    // Filter by Current Month if requested
    if ($current_month_only) {
        // Calculate start and end of the current month based on WP Timezone
        $start_of_month = strtotime('first day of this month 00:00:00', current_time('timestamp'));
        $end_of_month   = strtotime('last day of this month 23:59:59', current_time('timestamp'));

        $query .= " AND l.time BETWEEN %d AND %d";

        $args[] = $start_of_month;
        $args[] = $end_of_month;
    }

    // Execute Query
    $post_ids = $wpdb->get_col($wpdb->prepare($query, $args));

    return $post_ids;
}


/**
 * specific function to get the ranking of niches based on user history.
 *
 * @param int $user_id The ID of the current user.
 * @return array Sorted array of niches with counts and percentages.
 */
function get_user_niche_ranking($user_id, $limit = false)
{

    // 1. RETRIEVE VIEWED IDS
    // Replace this line with however you are currently saving the data. 
    // Assuming you store it as an array of Post IDs in user meta:
    $viewed_influencers_ids = get_viewed_influencer();
    $saved_influencers_ids = get_saved_influencer();
    $purchased_influencers_ids = get_user_purchased_post_ids();

    $engage_influencers_ids = array_merge($viewed_influencers_ids, $saved_influencers_ids, $purchased_influencers_ids);

    // Safety check: if no history, return empty
    if (empty($engage_influencers_ids) || ! is_array($engage_influencers_ids)) {
        return [];
    }

    $niche_counts = [];

    // 2. AGGREGATE TERMS
    // Prime the term cache for all posts at once to avoid one query per post.
    update_object_term_cache(array_unique($engage_influencers_ids), 'post');

    foreach ($engage_influencers_ids as $post_id) {
        $terms = get_the_terms($post_id, 'niche');

        if (! empty($terms) && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_id = $term->term_id;

                if (! isset($niche_counts[$term_id])) {
                    $niche_counts[$term_id] = [
                        'term_id' => $term_id,
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                        'count'   => 0,
                    ];
                }
                $niche_counts[$term_id]['count']++;
            }
        }
    }

    // 3. SORT (Desc by count)
    usort($niche_counts, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    // 4. LIMIT RESULTS (Slice the array to the top X)
    if ($limit > 0) {
        $niche_counts = array_slice($niche_counts, 0, $limit);
    }

    // 5. RE-CALCULATE TOTAL (Based ONLY on the sliced top items)
    // We sum the counts of just these top 3 to ensure percentages = 100% relative to this group.
    $subset_total = 0;
    foreach ($niche_counts as $niche) {
        $subset_total += $niche['count'];
    }

    // 6. CALCULATE PERCENTAGE
    foreach ($niche_counts as &$niche) {
        if ($subset_total > 0) {
            $niche['percentage'] = round(($niche['count'] / $subset_total) * 100, 1);
        } else {
            $niche['percentage'] = 0;
        }
    }

    return $niche_counts;
}

function get_niche_terms_sql($post_id = null)
{
    global $wpdb;

    // Use global post ID if none is provided
    if (! $post_id) {
        $post_id = get_the_ID();
    }

    // Safety check
    if (! $post_id) {
        return '';
    }

    // Prepare the SQL query
    // We join 3 tables: terms (names), term_taxonomy (tax type), and term_relationships (post connection)
    $query = $wpdb->prepare(
        "
        SELECT t.name 
        FROM {$wpdb->terms} AS t
        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
        INNER JOIN {$wpdb->term_relationships} AS tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE tt.taxonomy = %s
        AND tr.object_id = %d
        ",
        'niche', // Taxonomy key
        $post_id
    );

    // Get the column of names directly
    $term_names = $wpdb->get_col($query);

    if (! empty($term_names)) {
        // Implode array into comma-separated string
        return implode(', ', $term_names);
    }

    return '';
}


/**
 * Retrieves an array of unique meta values for a specific meta key and post type.
 *
 * This function performs a direct database query joining the posts and postmeta tables.
 * It strictly returns values associated with 'publish' status posts to ensure 
 * data from drafts, auto-drafts, or revisions is excluded.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string $meta_key  The meta key to search for.
 * @param string $post_type The slug of the post type (e.g., 'outreach').
 * * @return array An array of unique meta values. Returns an empty array if none found or on failure.
 */
function get_unique_meta_values_by_post_type(string $meta_key, string $post_type = 'outreach'): array
{
    global $wpdb;

    // Ensure inputs are not empty before hitting the database
    if (empty($meta_key) || empty($post_type)) {
        return [];
    }

    $query = $wpdb->prepare(
        "
		SELECT DISTINCT pm.meta_value 
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		WHERE p.post_type = %s 
		  AND p.post_status = 'publish' 
		  AND pm.meta_key = %s
		",
        $post_type,
        $meta_key
    );

    // get_col returns a 1D array of the selected column
    $results = $wpdb->get_col($query);

    // Return the results, ensuring it falls back to an empty array if null
    return is_array($results) ? $results : [];
}


/**
 * Extracts up to two initials from a provided string.
 *
 * Utilizes regular expressions to isolate the first letter of the first 
 * two words. Includes a fallback mechanism for single-word strings.
 *
 * @param string $string The source string (e.g., post title).
 * @return string The extracted initials, capitalized.
 */
function dd_get_initials_from_string($string)
{
    $string = trim($string);
    if (empty($string)) {
        return '';
    }

    // Match the first letter of each word boundaries, supporting Unicode
    preg_match_all('/\b\w/u', $string, $matches);

    $initials = '';

    // Extract the first and (if available) second initial
    if (! empty($matches[0])) {
        $initials = mb_substr($matches[0][0], 0, 1);
        if (isset($matches[0][1])) {
            $initials .= mb_substr($matches[0][1], 0, 1);
        }
    } else {
        // Fallback for strings without clear word boundaries
        $initials = mb_substr($string, 0, 2);
    }

    return mb_strtoupper($initials);
}


/**
 * Converts a millisecond or second-based Unix timestamp into a 'Y M jS' format.
 * Automatically detects 13-digit millisecond timestamps and normalizes them to seconds.
 *
 * @param int|string $timestamp The Unix timestamp to convert (seconds or milliseconds).
 * @param string     $timezone  The timezone identifier (e.g., 'UTC', 'Asia/Manila').
 * @return string               The formatted date string (e.g., '2025 May 20th').
 * @throws Exception            If the timezone or DateTime instantiation fails.
 */
function formatNormalizedTimestamp(int|string $timestamp, string $timezone = 'UTC'): string
{
    // Cast to integer to ensure strict typing during mathematical operations
    $timestamp = (int) $timestamp;

    // Check if the timestamp is in milliseconds (typically 13 digits long)
    // 10000000000 corresponds to '2286-11-20', assuming typical present-day data bounds
    if ($timestamp > 10000000000) {
        $timestamp = (int) floor($timestamp / 1000);
    }

    // Prefix with '@' to force DateTime to interpret the value as a UTC Unix timestamp
    $date = new DateTimeImmutable('@' . $timestamp);

    // Apply the desired timezone
    $date = $date->setTimezone(new DateTimeZone($timezone));

    return $date->format('Y M jS');
}


/**
 * HTML hashtag cloud from a provided array.
 * When $preserve_order is true, keeps caller order (e.g. dictionary-prioritized list).
 *
 * @param array $hashtags       Array of hashtag strings (e.g., ['#blender', '#3d']).
 * @param int   $limit          Maximum number of hashtags to display.
 * @param bool  $preserve_order If true, do not shuffle tags (only colors are randomized).
 * @return string               Buffered HTML output.
 */
function render_hashtag_cloud(array $hashtags, int $limit = 10, bool $preserve_order = false)
{
    ob_start();

    if (empty($hashtags)) {
        return ob_get_clean();
    }

    if (!$preserve_order) {
        shuffle($hashtags);
    }

    $display_tags = array_slice($hashtags, 0, $limit);

    $palette = [
        '#034146',
        '#F77D67',
        '#8F8F8F',
        '#3B1527',
        '#E4A800',
        '#F77D67D6',
        '#612b00',
        '#034146B8',
        '#000',
    ];

    // Shuffle the palette to ensure random assignment without immediate repetition
    shuffle($palette);
    $palette_count = count($palette);
    $color_index = 0;

    // 3. Render the container using inline flexbox to guarantee clean wrapping without collision
    echo '<div class="hashtag-cloud-container" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding-top: 10px;">';

    // 4. Iterate and render each tag with standardized properties
    foreach ($display_tags as $tag) {
        $color = $palette[$color_index % $palette_count];
        $color_index++;

        $style = sprintf(
            'color: %1$s;',
            $color
        );

        $label = trim((string) $tag);
        if ($label !== '' && $label[0] !== '#') {
            $label = '#' . $label;
        }

        printf(
            '<span class="hashtag-item" style="%1$s">%2$s</span>',
            esc_attr($style),
            esc_html($label)
        );
    }

    echo '</div>';

    return ob_get_clean();
}

/**
 * Converts an absolute server path to a web-accessible URL.
 * * This is specifically designed to handle PMPro Register Helper fields
 * that store the full /home/user/path instead of the URL.
 *
 * @param string $path_or_url The raw value retrieved from get_user_meta.
 * @return string The converted URL, or the original string if no conversion was needed.
 */
function convert_pmpro_path_to_url($path_or_url)
{
    // 1. If the input is an array (sometimes returned by PMPro), extract the fullpath.
    if (is_array($path_or_url) && isset($path_or_url['fullpath'])) {
        $path_or_url = $path_or_url['fullpath'];
    }

    // 2. If it's empty or not a string, return early.
    if (empty($path_or_url) || ! is_string($path_or_url)) {
        return '';
    }

    // 3. Check if the string contains the local server path (ABSPATH).
    // ABSPATH is a WP constant, e.g., /home/influencerdd2/public_html/
    if (strpos($path_or_url, ABSPATH) !== false) {
        // Replace the server path with the site URL.
        // We use site_url('/') to ensure we get the root web address.
        $url = str_replace(ABSPATH, site_url('/'), $path_or_url);

        // Fix any potential double slashes that might occur during replacement
        // (excluding the http:// or https:// protocol slashes).
        $url = str_replace('://', '___PROTOCOL___', $url);
        $url = str_replace('//', '/', $url);
        $url = str_replace('___PROTOCOL___', '://', $url);

        return $url;
    }

    // 4. Fallback: If ABSPATH didn't match, try matching against the Uploads Directory specifically.
    // This is useful if the server structure varies slightly (e.g., symlinks).
    $upload_dir = wp_upload_dir();
    if (strpos($path_or_url, $upload_dir['basedir']) !== false) {
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $path_or_url);
    }

    // Return original if no match found (it might already be a URL).
    return $path_or_url;
}

/**
 * Retrieve the file URL for a specific user and PMPro field.
 *
 * @param int    $user_id   The ID of the user.
 * @param string $field_key The meta key used when registering the field (e.g., 'resume_upload').
 * @return string|false     The URL of the file or false if not found.
 */
function get_pmpro_file_field_url(int $user_id, string $field_key)
{
    // Retrieve the raw meta value.
    $meta_value = get_user_meta($user_id, $field_key, true);

    // Case A: The field stored a direct string URL.
    if (is_string($meta_value) && ! empty($meta_value)) {
        return $meta_value;
    }

    // Case B: The field stored an array (common in newer Register Helper versions).
    // The array typically looks like: ['original_filename' => '...', 'fullpath' => '...']
    if (is_array($meta_value) && ! empty($meta_value['fullpath'])) {
        return $meta_value['fullpath'];
    }

    // Case C: Sometimes only the Attachment ID is stored (rare, but possible with custom implementations).
    if (is_numeric($meta_value)) {
        return wp_get_attachment_url($meta_value);
    }

    return false;
}