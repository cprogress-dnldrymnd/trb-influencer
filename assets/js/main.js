(function ($) {
    'use strict';

    var LAST_SEARCH_URL_KEY = 'dd_last_search_url';
    // Same-tab flag: set on the results page, cleared on any non-profile page.
    // Lets a profile refresh keep the search trail without re-showing it after
    // the visitor leaves search and opens a profile from elsewhere.
    var SEARCH_NAV_KEY = 'dd_search_nav';

    // -------------------------------------------------------------------------
    // Sync URL parameters → checkboxes BEFORE any module runs so that filter
    // state is correct when modules inspect the DOM on initialisation.
    // -------------------------------------------------------------------------
    function sync_url_params_to_dom() {
        var urlParams = new URLSearchParams(window.location.search);

        if (urlParams.has('search-brief') && urlParams.get('search-brief').trim() !== '') {
            $('#search-brief').val(urlParams.get('search-brief'));
            $('#my-toggle').prop('checked', true);
        } else if (urlParams.has('search_active')) {
            $('#my-toggle').prop('checked', false);
        }

        urlParams.forEach(function (value, key) {
            $('input[name="' + key + '"]').each(function () {
                var type = $(this).attr('type');
                if ((type === 'checkbox' || type === 'radio') && $(this).val() === value) {
                    $(this).prop('checked', true);
                }
            });
        });
    }

    /**
     * Remember the current results URL so the profile breadcrumb can return
     * with filters intact (Search → Profile → Search Results).
     */
    function remember_last_search_url(url) {
        try {
            sessionStorage.setItem(LAST_SEARCH_URL_KEY, url);
            sessionStorage.setItem(SEARCH_NAV_KEY, '1');
        } catch (e) { /* private mode / quota */ }
    }

    function clear_search_nav_flag() {
        try {
            sessionStorage.removeItem(SEARCH_NAV_KEY);
        } catch (e) { /* private mode */ }
    }

    function has_search_nav_flag() {
        try {
            return sessionStorage.getItem(SEARCH_NAV_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function get_stored_search_url() {
        try {
            return sessionStorage.getItem(LAST_SEARCH_URL_KEY);
        } catch (e) {
            return null;
        }
    }

    /**
     * True when document.referrer is the search-results page (path match).
     * $resultsPathHint is an optional pathname from a crumb/button href.
     */
    function referrer_is_search_results(resultsPathHint) {
        if (!document.referrer) {
            return false;
        }
        try {
            var ref = new URL(document.referrer, window.location.origin);
            if (resultsPathHint) {
                return ref.pathname === resultsPathHint;
            }
            // Fall back to comparing against a crumb/button already in the DOM.
            var $probe = $('.dd-crumb-search-results, .dd-back-to-search').first();
            if (!$probe.length) {
                return false;
            }
            var probeUrl = new URL($probe.attr('href'), window.location.origin);
            return ref.pathname === probeUrl.pathname;
        } catch (e) {
            return false;
        }
    }

    /**
     * Visitor is in the search→profile flow: live referer is results, or the
     * same-tab session flag is still set (profile refresh / profile→profile).
     */
    function arrived_from_search_results() {
        return referrer_is_search_results() || has_search_nav_flag();
    }

    /**
     * Track whether this tab is still in the search→profile journey.
     */
    function sync_search_nav_flag() {
        var onResults = typeof ajax_vars !== 'undefined'
            && String(ajax_vars.search_results_page_id) === String(ajax_vars.page_id);
        var onProfile = $('body').hasClass('single-influencer');

        if (onResults) {
            if (window.location.search) {
                remember_last_search_url(window.location.href);
            } else {
                try { sessionStorage.setItem(SEARCH_NAV_KEY, '1'); } catch (e) { /* */ }
            }
            return;
        }

        if (onProfile) {
            // Arriving from results: keep/set the flag. Arriving from elsewhere: clear.
            if (referrer_is_search_results()) {
                try { sessionStorage.setItem(SEARCH_NAV_KEY, '1'); } catch (e) { /* */ }
            } else if (!has_search_nav_flag()) {
                // Already cleared — nothing to do.
            } else if (document.referrer) {
                // Referer is some other page (dashboard, saved lists, etc.) — leave the flow.
                try {
                    var ref = new URL(document.referrer, window.location.origin);
                    if (ref.pathname !== window.location.pathname) {
                        clear_search_nav_flag();
                    }
                } catch (e) { /* keep flag on malformed referer */ }
            }
            return;
        }

        // Any other page ends the search→profile journey.
        clear_search_nav_flag();
    }

    /**
     * On influencer singles, point the Search Results crumb at the last
     * filtered results URL when its path matches the results page.
     */
    function restore_search_results_crumb() {
        if (!$('body').hasClass('single-influencer')) {
            return;
        }

        var $crumb = $('.dd-crumb-search-results');
        if (!$crumb.length) {
            return;
        }

        var stored = get_stored_search_url();
        if (!stored) {
            return;
        }

        try {
            var storedUrl = new URL(stored, window.location.origin);
            var crumbUrl = new URL($crumb.attr('href'), window.location.origin);
            if (storedUrl.pathname === crumbUrl.pathname && storedUrl.search) {
                $crumb.attr('href', storedUrl.href);
            }
        } catch (e) { /* malformed stored URL */ }
    }

    /**
     * Point the Discovery (search form) crumb at the search page with the same
     * filter query as the last results URL, so the form reopens pre-filled.
     */
    function restore_search_discovery_crumb() {
        var $crumb = $('.dd-crumb-search-discovery');
        if (!$crumb.length) {
            return;
        }

        var query = '';

        try {
            var stored = get_stored_search_url();
            if (stored) {
                var storedUrl = new URL(stored, window.location.origin);
                if (storedUrl.search) {
                    query = storedUrl.search;
                }
            }
        } catch (e) { /* private mode / malformed */ }

        // On the results page, prefer the live URL when sessionStorage is empty/stale.
        if (!query && window.location.search
            && typeof ajax_vars !== 'undefined'
            && String(ajax_vars.search_results_page_id) === String(ajax_vars.page_id)) {
            query = window.location.search;
        }

        if (!query) {
            return;
        }

        try {
            var base = (typeof ajax_vars !== 'undefined' && ajax_vars.search_page_url)
                ? ajax_vars.search_page_url
                : $crumb.attr('href');
            var dest = new URL(base, window.location.origin);
            dest.search = query.charAt(0) === '?' ? query.slice(1) : query;
            $crumb.attr('href', dest.href);
        } catch (e) { /* malformed URL */ }
    }

    /**
     * Show profile search-trail crumbs only when the visitor is in the
     * search→profile flow (not when opening a profile from elsewhere).
     */
    function restore_profile_search_crumbs() {
        if (!$('body').hasClass('single-influencer')) {
            return;
        }

        var $crumbs = $('.dd-crumb-from-search');
        if (!$crumbs.length) {
            return;
        }

        if (arrived_from_search_results()) {
            $crumbs.removeAttr('hidden');
        } else {
            $crumbs.attr('hidden', 'hidden');
        }
    }

    /**
     * Show .dd-back-to-search buttons only when the visitor arrived via the
     * search→profile flow. sessionStorage alone (stale from an earlier search)
     * is not enough — that was re-showing the button from saved lists / dashboard.
     */
    function restore_back_to_search_buttons() {
        var $btns = $('.dd-back-to-search');
        if (!$btns.length) {
            return;
        }

        var show = arrived_from_search_results();
        var stored = show ? get_stored_search_url() : null;

        $btns.each(function () {
            var $btn = $(this);
            var targetHref = null;

            try {
                var btnUrl = new URL($btn.attr('href'), window.location.origin);

                if (stored) {
                    var storedUrl = new URL(stored, window.location.origin);
                    if (storedUrl.pathname === btnUrl.pathname && storedUrl.search) {
                        targetHref = storedUrl.href;
                    }
                }

                // PHP may already have seeded a filtered referer into href.
                if (!targetHref && btnUrl.search) {
                    targetHref = btnUrl.href;
                }

                if (!targetHref && show) {
                    targetHref = btnUrl.href;
                }
            } catch (e) { /* malformed URL */ }

            if (show && targetHref) {
                $btn.attr('href', targetHref)
                    .removeAttr('hidden')
                    .removeAttr('aria-hidden');
            } else {
                $btn.attr('hidden', 'hidden')
                    .attr('aria-hidden', 'true');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    $(document).ready(function () {

        sync_url_params_to_dom();
        sync_search_nav_flag();

        InfluencerApp.sync_follower_min_max_states();

        InfluencerApp.initSearchToggle();
        InfluencerApp.initAdvancedSearchToggle();
        InfluencerApp.init_sidebar_reset_all();
        InfluencerApp.initRefineSearchToggle();

        InfluencerApp.initActiveFilterChips();
        InfluencerApp.validate_required_search_filters();
        InfluencerApp.initBriefQuality();

        InfluencerApp.influencer_select_filters();
        InfluencerApp.influencer_search_trigger();

        InfluencerApp.nicheToggle();

        InfluencerApp.mobile_nav();
        InfluencerApp.share_profile();
        InfluencerApp.dashboardLogoHeightVar();
        InfluencerApp.hideEmptyData();

        // Fire initial search only on the designated results page
        if (ajax_vars.search_results_page_id == ajax_vars.page_id) {
            if (window.location.search) {
                remember_last_search_url(window.location.href);
            }
            InfluencerApp.fetch_influencers(false);
        } else {
            InfluencerApp.prioritize_active_tags();
        }

        restore_search_results_crumb();
        restore_search_discovery_crumb();
        restore_profile_search_crumbs();
        restore_back_to_search_buttons();

        var resizeTimer = null;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                InfluencerApp.dashboardLogoHeightVar();
            }, 100);
        });
    });

})(jQuery);
