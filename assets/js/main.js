(function ($) {
    'use strict';

    var LAST_SEARCH_URL_KEY = 'dd_last_search_url';

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
        } catch (e) { /* private mode / quota */ }
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

        var stored;
        try {
            stored = sessionStorage.getItem(LAST_SEARCH_URL_KEY);
        } catch (e) {
            return;
        }
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

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    $(document).ready(function () {

        sync_url_params_to_dom();

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

        var resizeTimer = null;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                InfluencerApp.dashboardLogoHeightVar();
            }, 100);
        });
    });

})(jQuery);