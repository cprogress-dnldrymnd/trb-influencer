(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    // Module-level pagination state
    var current_page = 1;
    var max_pages = 1;

    /**
     * Renders the brief-search debug payload into the debug panel (dev only).
     */
    InfluencerApp.render_brief_search_debug = function (debug) {
        if (!debug) return;
        var $panel = $('#ic-brief-search-debug .ic-brief-search-debug-body');
        if (!$panel.length) return;
        try {
            $panel.text(JSON.stringify(debug, null, 2));
        } catch (e) {
            $panel.text(String(debug));
        }
    };

    /**
     * Loading spinner: pinned to the centre of the viewport via `position: fixed`,
     * so it stays in the same spot on screen regardless of scrolling.
     */
    function show_loading_animation() {
        $('.loading-animation').show();
    }

    function hide_loading_animation() {
        $('.loading-animation').hide();
    }

    /**
     * Overlay that covers #dashboard-content-inner while a search is running,
     * blocking clicks on the dashboard underneath until results are ready.
     */
    function show_search_overlay() {
        var $parent = $('.dashboard-content-inner');
        if (!$parent.length) return;

        var $overlay = $parent.children('.search-loading-overlay');
        if (!$overlay.length) {
            $overlay = $('<div class="search-loading-overlay"></div>').appendTo($parent);
        }
        $overlay.show();
    }

    function hide_search_overlay() {
        $('.dashboard-content-inner').children('.search-loading-overlay').hide();
    }

    /**
     * Reads checked filter values for a given input name.
     */
    function get_filter_values(name) {
        return $('[name="' + name + '"]:checked').map(function () {
            return $(this).val();
        }).get();
    }

    /**
     * Pushes current filter state to the browser URL bar (no reload).
     */
    function push_url_state(filters, search_brief) {
        var urlParams = new URLSearchParams();

        if (search_brief) urlParams.set('search-brief', search_brief);

        ['niche[]', 'country[]', 'lang[]', 'gender[]', 'content_tag[]', 'filter[]'].forEach(function (name) {
            var values = $('[name="' + name + '"]:checked').map(function () { return $(this).val(); }).get();
            values.forEach(function (val) { urlParams.append(name, val); });
        });

        var minArr = get_filter_values('min_followers[]');
        var maxArr = get_filter_values('max_followers[]');
        minArr.forEach(function (v) { urlParams.append('min_followers[]', v); });
        maxArr.forEach(function (v) { urlParams.append('max_followers[]', v); });

        urlParams.set('search_active', 'true');

        var newUrl = window.location.protocol + '//' + window.location.host +
            window.location.pathname + '?' + urlParams.toString();
        window.history.pushState({ path: newUrl }, '', newUrl);
    }

    /**
     * Fires an AJAX call to retrieve influencer results.
     *
     * @param {boolean} is_load_more  True when appending results (Load More button).
     */
    InfluencerApp.fetch_influencers = function (is_load_more) {
        var container = $('#my-loop-grid-container');
        var button = $('#load-more-influencers');
        show_loading_animation();

        if (!is_load_more) current_page = 1;

        var filter_niche = get_filter_values('niche[]');
        var filter_country = get_filter_values('country[]');
        var filter_lang = get_filter_values('lang[]');
        var filter_gender = get_filter_values('gender[]');
        var filter_content_tag = get_filter_values('content_tag[]');
        var filter_filter = get_filter_values('filter[]');
        var search_brief = $('#search-brief').length ? $('#search-brief').val() : '';
        var min_f_arr = get_filter_values('min_followers[]');
        var max_f_arr = get_filter_values('max_followers[]');
        var filter_min = min_f_arr.length > 0 ? min_f_arr[0] : '';
        var filter_max = max_f_arr.length > 0 ? max_f_arr[0] : '';

        if (!is_load_more) {
            push_url_state(null, search_brief);
        }

        container.attr('aria-busy', 'true');
        show_search_overlay();
        button.text('Loading...');

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'my_custom_loop_filter',
                security: ajax_vars.search_filter_nonce,
                niche: filter_niche,
                country: filter_country,
                lang: filter_lang,
                gender: filter_gender,
                content_tag: filter_content_tag,
                min_followers: filter_min,
                max_followers: filter_max,
                filter: filter_filter,
                search_brief: search_brief,
                paged: current_page,
                search_active: 'true'
            },
            success: function (response) {
                var debug = (response.data && response.data.debug) ? response.data.debug : null;

                if (response.success) {
                    hide_loading_animation();
                    max_pages = response.data.max_pages;

                    if (is_load_more) {
                        container.append(response.data.html);
                    } else {
                        container.html(response.data.html);
                    }

                    InfluencerApp.prioritize_active_tags();

                    $('.total-found-influencer').text(response.data.found_posts);
                    $('.current-found-influencer').text($('#my-loop-grid-container .e-loop-item').length);

                    if (current_page < max_pages) {
                        button.show().text('Load More');
                    } else {
                        button.hide();
                    }

                    if (debug) InfluencerApp.render_brief_search_debug(debug);

                } else {
                    hide_loading_animation();
                    $('.total-found-influencer').text('0');
                    $('.current-found-influencer').text('0');

                    if (!is_load_more) {
                        container.html('<p class="no-influencers-found">No influencers found matching your criteria.</p>');
                    }
                    button.hide();

                    if (debug) InfluencerApp.render_brief_search_debug(debug);
                }

                container.attr('aria-busy', 'false');
                hide_search_overlay();
            },
            error: function () {
                hide_loading_animation();
                $('.total-found-influencer').text('0');
                $('.current-found-influencer').text('0');
                container.html('<p style="padding:20px 0;">An error occurred. Please try again.</p>');
                container.attr('aria-busy', 'false');
                hide_search_overlay();
                button.text('Try Again');
            }
        });
    };

    /**
     * Binds the manual search trigger button.
     */
    InfluencerApp.influencer_search_trigger = function () {
        $('.influencer-search-trigger').on('click', function (e) {
            e.preventDefault();
            InfluencerApp.fetch_influencers(false);
        });
    };

    // Load More button (delegated, works after DOM is ready)
    $(document).on('click', '#load-more-influencers', function (e) {
        e.preventDefault();
        current_page++;
        InfluencerApp.fetch_influencers(true);
    });

})(jQuery);
