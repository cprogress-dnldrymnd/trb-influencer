(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    // Module-level pagination state
    var current_page = 1;
    var max_pages = 1;

    // True once a "Load More" request has failed and the button now reads "Try Again" —
    // lets the click handler retry the same page instead of skipping ahead to the next one.
    var load_more_failed = false;

    // Auto-retry transient AJAX failures (timeouts / brief 5xx blips) before bothering the
    // user — a one-off server hiccup on page 2/3 then self-heals instead of erroring out.
    var MAX_AUTO_RETRIES = 2;
    var AUTO_RETRY_BASE_DELAY = 800; // ms, multiplied by the attempt number for light backoff

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
        $('.loading-animation').css('display', 'flex');
        $('body').addClass('search-loading-active');
    }

    function hide_loading_animation() {
        $('.loading-animation').hide();
        $('body').removeClass('search-loading-active');
    }

    /**
     * Inline spinner on the Load More button itself, used instead of the
     * full-page loading animation/overlay so the rest of the results stay
     * visible and clickable while more are appended.
     */
    function show_button_spinner(button) {
        button.addClass('is-loading').prop('disabled', true).text('Loading...');
    }

    function hide_button_spinner(button) {
        button.removeClass('is-loading').prop('disabled', false);
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
     * Height of whichever members-area header is currently visible, so the
     * scroll target isn't tucked underneath it (desktop vs. mobile header).
     */
    function get_members_area_header_height() {
        var $mobile  = $('#members-area-header-mobile');
        var $desktop = $('#members-area-header');

        if ($mobile.length && $mobile.is(':visible')) {
            return $mobile.outerHeight();
        }
        if ($desktop.length && $desktop.is(':visible')) {
            return $desktop.outerHeight();
        }
        return 0;
    }

    /**
     * Scrolls the results section into view once a (non load-more) search finishes,
     * offset by the height of the sticky members-area header and, for logged-in
     * admins, the WordPress admin bar.
     */
    function scroll_to_search_results() {
        var target = document.getElementById('influencer-search-result');
        if (!target) return;

        var $admin_bar    = $('#wpadminbar');
        var admin_bar_height = ($admin_bar.length && $admin_bar.is(':visible')) ? $admin_bar.outerHeight() : 0;

        var top = target.getBoundingClientRect().top + window.pageYOffset - get_members_area_header_height() - admin_bar_height;

        window.scrollTo({ top: top, behavior: 'smooth' });
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
     * Rebuilds the "Filters: ..." line in .influencer-search-summary to reflect
     * the sidebar form's current selections. The summary is rendered server-side
     * from $_GET on page load (see Influencer_Search::shortcode_search_summary),
     * but a "Find matches" search runs over AJAX with no page reload, so without
     * this it kept showing whatever filters were active when the page loaded.
     * Mirrors that PHP method's grouping/joining so the two stay in sync.
     */
    function update_search_summary_filters() {
        var $summary = $('.influencer-search-summary');
        if (!$summary.length || $summary.find('.search-summary-brief').length) return;

        var parts = [];
        ['niche[]', 'country[]', 'gender[]', 'content_tag[]'].forEach(function (name) {
            var labels = $('[name="' + name + '"]:checked').map(function () {
                return $(this).attr('data-label') || $(this).val();
            }).get();
            if (labels.length) parts.push(labels.join(', '));
        });

        var $filters = $summary.find('.search-summary-filters');

        if (!parts.length) {
            $filters.remove();
            return;
        }

        if (!$filters.length) {
            $filters = $('<div class="search-summary-item search-summary-filters"></div>').appendTo($summary);
        }

        $filters.empty()
            .append($('<strong>').text('Filters:'))
            .append(document.createTextNode(' ' + parts.join(' • ')));
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
     * @param {boolean} should_scroll True to scroll the results into view once finished —
     *                                used for the manual search trigger only, not the
     *                                initial page-load search or Load More.
     */
    InfluencerApp.fetch_influencers = function (is_load_more, should_scroll, attempt) {
        attempt = attempt || 0;
        var container = $('#my-loop-grid-container');
        var button = $('#load-more-influencers');

        if (is_load_more) {
            show_button_spinner(button);
        } else {
            show_loading_animation();
        }

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

        if (!is_load_more && attempt === 0) {
            push_url_state(null, search_brief);
        }

        container.attr('aria-busy', 'true');

        if (!is_load_more) {
            show_search_overlay();
            button.text('Loading...');
        }

        // A failed attempt auto-retries a couple of times (transient timeouts / brief 5xx
        // server blips) before surfacing anything to the user. Only after retries are
        // exhausted do we show the error UI — and a failed "Load More" keeps the results
        // already on the page instead of wiping them (which looked like "0 of 0 matches").
        function handle_failure(status_label) {
            if (attempt < MAX_AUTO_RETRIES) {
                setTimeout(function () {
                    InfluencerApp.fetch_influencers(is_load_more, should_scroll, attempt + 1);
                }, AUTO_RETRY_BASE_DELAY * (attempt + 1));
                return;
            }

            if (is_load_more) {
                // Keep already-loaded results; just let the user retry THIS page.
                hide_button_spinner(button);
                load_more_failed = true;
                button.show().text('Try Again');
            } else {
                hide_loading_animation();
                $('.total-found-influencer').text('0');
                $('.current-found-influencer').text('0');
                container.html('<p class="search-error" style="padding:20px 0;">An error occurred. Please try again.</p>');
                if (status_label) container.find('.search-error').attr('data-error-status', status_label);
                button.hide();
            }
            container.attr('aria-busy', 'false');

            if (!is_load_more) {
                hide_search_overlay();
                if (should_scroll) scroll_to_search_results();
            }
        }

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            timeout: 30000,
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

                // The server's fatal guard returns a clean 200 with recoverable:true when a
                // PHP fatal (e.g. memory exhaustion mid-render) was intercepted. Treat that
                // like a transient failure (auto-retry / Try Again), not a real "0 results".
                if (!response.success && response.data && response.data.recoverable) {
                    handle_failure('recoverable');
                    return;
                }

                if (is_load_more) load_more_failed = false;

                if (response.success) {
                    if (is_load_more) {
                        hide_button_spinner(button);
                    } else {
                        hide_loading_animation();
                    }
                    max_pages = response.data.max_pages;

                    if (is_load_more) {
                        container.append(response.data.html);
                    } else {
                        container.html(response.data.html);
                        update_search_summary_filters();
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
                    if (is_load_more) {
                        hide_button_spinner(button);
                    } else {
                        hide_loading_animation();
                    }
                    $('.total-found-influencer').text('0');
                    $('.current-found-influencer').text('0');

                    if (!is_load_more) {
                        container.html('<p class="no-influencers-found">No influencers found matching your criteria.</p>');
                    }
                    button.hide();

                    if (debug) InfluencerApp.render_brief_search_debug(debug);
                }

                container.attr('aria-busy', 'false');

                if (!is_load_more) {
                    hide_search_overlay();
                    if (should_scroll) scroll_to_search_results();
                }
            },
            error: function (jqXHR, textStatus) {
                // Capture the HTTP status so an intermittent failure is diagnosable next time
                // (403 = nonce/cache, 500 = PHP fatal, 0/timeout = network or slow response).
                var status_label = (jqXHR && jqXHR.status ? jqXHR.status : 0) + '/' + (textStatus || 'error');
                if (window.console && console.warn) {
                    console.warn('Influencer search failed [' + status_label + '] load_more=' + is_load_more +
                        ' page=' + current_page + ' attempt=' + attempt);
                }
                handle_failure(status_label);
            }
        });
    };

    /**
     * Binds the manual search trigger button.
     */
    InfluencerApp.influencer_search_trigger = function () {
        $('.influencer-search-trigger').on('click', function (e) {
            e.preventDefault();
            InfluencerApp.fetch_influencers(false, true);
        });
    };

    // Load More button (delegated, works after DOM is ready).
    // If the previous "Load More" request errored (button now reads "Try Again"),
    // retry the same page instead of advancing — advancing would skip the page
    // that failed and could walk straight past the end of the result set.
    $(document).on('click', '#load-more-influencers', function (e) {
        e.preventDefault();
        if (!load_more_failed) {
            current_page++;
        }
        load_more_failed = false;
        InfluencerApp.fetch_influencers(true);
    });

})(jQuery);
