(function ($) {
    'use strict';

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

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    $(document).ready(function () {

        sync_url_params_to_dom();

        InfluencerApp.sync_follower_min_max_states();

        InfluencerApp.initSearchToggle();
        InfluencerApp.initAdvancedSearchToggle();

        InfluencerApp.initActiveFilterChips();
        InfluencerApp.validate_required_search_filters();

        InfluencerApp.influencer_select_filters();
        InfluencerApp.influencer_search_trigger();

        InfluencerApp.nicheToggle();

        InfluencerApp.mobile_nav();
        InfluencerApp.share_profile();
        InfluencerApp.dashboardLogoHeightVar();

        // Fire initial search only on the designated results page
        if (ajax_vars.search_results_page_id == ajax_vars.page_id) {
            InfluencerApp.fetch_influencers(false);
        } else {
            InfluencerApp.prioritize_active_tags();
        }

        var resizeTimer = null;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                InfluencerApp.dashboardLogoHeightVar();
            }, 100);
        });
    });

})(jQuery);