/**
 * Credit History — [custom_mycred_log] (mycred-frontend-log.php).
 *
 * Vanilla JS/fetch, mirroring this component's own prior implementation
 * (previously inlined per-instance in mycred-frontend-log.php) rather than
 * jQuery, extracted here so styles/behaviour are registered once regardless
 * of how many [custom_mycred_log] instances are on a page.
 *
 * Each .mycred-ajax-wrapper owns its own filter state (ref/direction/date
 * range/search/limit/page) independently, so two instances on one page
 * paginate/filter without interfering with each other. The first wrapper
 * found also syncs the browser URL (?ct_ref=&ct_dir=&ct_from=&ct_to=&ct_q=
 * &ct_page=&ct_limit=) via history.replaceState() so a filtered view can be
 * bookmarked/shared and survives a refresh (read back server-side in
 * mycred-frontend-log.php's get_initial_filters_from_url()).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var wrappers = document.querySelectorAll('.mycred-ajax-wrapper');
        var ajaxUrl = (typeof ajax_vars !== 'undefined' && ajax_vars.ajax_url) ? ajax_vars.ajax_url : '/wp-admin/admin-ajax.php';

        wrappers.forEach(function (wrapper, wrapperIndex) {
            var ownsUrl = wrapperIndex === 0; // only the first instance on a page reflects state into the URL
            var state = {
                ref: '',
                direction: '',
                date_from: '',
                date_to: '',
                search: '',
                limit: parseInt(wrapper.getAttribute('data-limit'), 10) || 20,
                page: 1
            };

            var refSelect   = wrapper.querySelector('.mycred-log-filter');
            var dateFrom    = wrapper.querySelector('#mycred-log-date-from');
            var dateTo      = wrapper.querySelector('#mycred-log-date-to');
            var searchInput = wrapper.querySelector('.mycred-log-search');
            var limitSelect = wrapper.querySelector('.mycred-log-limit');
            var pills       = wrapper.querySelectorAll('.mycred-pill');
            var tbody       = wrapper.querySelector('tbody');

            // Seed state from whatever the server already rendered into the
            // controls (either shortcode defaults or a ?ct_* bookmarked URL).
            if (refSelect) state.ref = refSelect.value;
            if (dateFrom) state.date_from = dateFrom.value;
            if (dateTo) state.date_to = dateTo.value;
            if (searchInput) state.search = searchInput.value;
            if (limitSelect) state.limit = parseInt(limitSelect.value, 10) || state.limit;
            var activePill = wrapper.querySelector('.mycred-pill.is-active');
            if (activePill) state.direction = activePill.getAttribute('data-direction') || '';

            function syncExportFields() {
                wrapper.querySelectorAll('.mycred-export-field').forEach(function (field) {
                    var key = field.getAttribute('data-field');
                    if (state.hasOwnProperty(key)) {
                        field.value = state[key];
                    }
                });
            }

            function syncUrl() {
                if (!ownsUrl || !window.history || !window.history.replaceState) {
                    return;
                }
                var params = new URLSearchParams(window.location.search);
                var map = {
                    ct_ref: state.ref,
                    ct_dir: state.direction,
                    ct_from: state.date_from,
                    ct_to: state.date_to,
                    ct_q: state.search,
                    ct_page: state.page > 1 ? state.page : '',
                    ct_limit: state.limit !== 20 ? state.limit : ''
                };
                Object.keys(map).forEach(function (key) {
                    if (map[key]) {
                        params.set(key, map[key]);
                    } else {
                        params.delete(key);
                    }
                });
                var query = params.toString();
                var newUrl = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
                window.history.replaceState(null, '', newUrl);
            }

            function fetchLogData(targetPage) {
                state.page = parseInt(targetPage, 10) || 1;

                var userId = wrapper.getAttribute('data-user-id');
                var ctype  = wrapper.getAttribute('data-ctype');
                var nonce  = wrapper.getAttribute('data-nonce');

                wrapper.classList.add('is-loading');
                if (tbody) tbody.setAttribute('aria-busy', 'true');

                var payload = new URLSearchParams();
                payload.append('action', 'mycred_load_log_page');
                payload.append('security', nonce);
                payload.append('page', state.page);
                payload.append('user_id', userId);
                payload.append('limit', state.limit);
                payload.append('ctype', ctype);
                payload.append('ref', state.ref);
                payload.append('direction', state.direction);
                payload.append('date_from', state.date_from);
                payload.append('date_to', state.date_to);
                payload.append('search', state.search);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: payload,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                })
                    .then(function (response) { return response.json(); })
                    .then(function (res) {
                        if (res.success) {
                            if (tbody) tbody.innerHTML = res.data.rows;
                            var paginationContainer = wrapper.querySelector('.mycred-pagination-container');
                            if (paginationContainer) paginationContainer.innerHTML = res.data.pagination;
                            syncExportFields();
                            syncUrl();
                        } else {
                            console.error('myCRED AJAX Error:', res.data);
                        }
                    })
                    .catch(function (error) { console.error('Fetch Error:', error); })
                    .finally(function () {
                        wrapper.classList.remove('is-loading');
                        if (tbody) tbody.setAttribute('aria-busy', 'false');
                    });
            }

            // Pagination — both the prev/next buttons and the numbered page
            // buttons share the data-target-page attribute, so one delegated
            // listener (re-attached after every innerHTML swap via delegation
            // on the wrapper) handles all of them.
            wrapper.addEventListener('click', function (e) {
                var btn = e.target.closest('.mycred-btn-paginate, .mycred-btn-page');
                if (!btn || btn.hasAttribute('disabled') || btn.classList.contains('is-current')) {
                    return;
                }
                e.preventDefault();
                fetchLogData(btn.getAttribute('data-target-page'));
            });

            // Segmented direction pills
            pills.forEach(function (pill) {
                pill.addEventListener('click', function () {
                    state.direction = pill.getAttribute('data-direction') || '';
                    pills.forEach(function (p) { p.classList.toggle('is-active', p === pill); });
                    fetchLogData(1);
                });
            });

            if (refSelect) {
                refSelect.addEventListener('change', function () {
                    state.ref = refSelect.value;
                    fetchLogData(1);
                });
            }

            [dateFrom, dateTo].forEach(function (input) {
                if (!input) return;
                input.addEventListener('change', function () {
                    state.date_from = dateFrom ? dateFrom.value : '';
                    state.date_to = dateTo ? dateTo.value : '';
                    fetchLogData(1);
                });
            });

            if (limitSelect) {
                limitSelect.addEventListener('change', function () {
                    state.limit = parseInt(limitSelect.value, 10) || 20;
                    fetchLogData(1);
                });
            }

            if (searchInput) {
                var searchTimer = null;
                searchInput.addEventListener('input', function () {
                    state.search = searchInput.value;
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () {
                        fetchLogData(1);
                    }, 400);
                });
            }

            // Keep the (independently submittable) export form's hidden
            // fields in sync with whatever the table is showing right now,
            // even before any filter has been touched.
            syncExportFields();
        });
    });
})();
