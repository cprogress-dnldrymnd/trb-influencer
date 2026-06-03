(function ($) {
    jQuery(document).ready(function () {
        // --- FIX: Sync URL Parameters to Checkboxes before anything else ---
        sync_url_params_to_dom();

        // --- NEW: Sync the disabled states of follower min/max fields ---
        sync_follower_min_max_states();

        if (ajax_vars.search_results_page_id == ajax_vars.page_id) {
            fetch_influencers(false);
        } else {
            // If we are on a pre-rendered page, sort the tags right away
            prioritize_active_tags();
        }

        nicheToggle();
        influencer_select_filters();
        influencer_search_trigger();
        mobile_nav();
        share_profile();
        dashboardLogoHeightVar();

        // --- NEW: Initialize required search filter validation ---
        validate_required_search_filters();

        $(window).on('resize', function () {
            dashboardLogoHeightVar();
        });
    });

    /**
     * NEW: Validates the main search form before submission.
     * Iterates over all elements with the 'required-on-search' class to ensure 
     * the '.tags-container' is populated with at least one '.tag'.
     */
    function validate_required_search_filters() {
        $('.influencer-search-main').on('submit', function (e) {
            let isValid = true;

            if ($('.filtered-search.active').length > 1) {
                // Iterate over all required filter blocks
                $(this).find('.required-on-search').each(function () {
                    const $container = $(this);

                    // Verify if tags exist in the tags-container
                    const hasTags = $container.find('.tags-container .tag').length > 0;

                    // Fallback check against actual checkbox states for data integrity
                    const hasCheckedInputs = $container.find('input[type="checkbox"]:checked').length > 0;

                    if (!hasTags && !hasCheckedInputs) {
                        isValid = false;

                        // Apply visual error cue
                        $container.css({
                            'border': '1px solid #ff4d4d',
                            'padding': '10px',
                            'border-radius': '8px',
                            'transition': 'border 0.3s ease'
                        });
                    } else {
                        // Clear visual error cue
                        $container.css({
                            'border': '',
                            'padding': '',
                            'border-radius': ''
                        });
                    }
                });
            }

            // Prevent form submission if validation fails
            if (!isValid) {
                e.preventDefault();
                alert('Please populate all required filters (e.g., Niche) before generating matches.');
            }
        });

        // Event listener to dynamically remove the error styling once a user makes a valid selection
        $('.required-on-search').on('change', 'input[type="checkbox"]', function () {
            const $container = $(this).closest('.required-on-search');
            if ($container.find('input[type="checkbox"]:checked').length > 0) {
                $container.css({
                    'border': '',
                    'padding': '',
                    'border-radius': ''
                });
            }
        });
    }

    /**
     * Reads URL parameters on page load and physically checks the corresponding
     * form inputs so the DOM matches the requested search before AJAX fires.
     */
    function sync_url_params_to_dom() {
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.has('search-brief')) {
            $('#search-brief').val(urlParams.get('search-brief'));
        }

        urlParams.forEach((value, key) => {
            $('input[name="' + key + '"]').each(function () {
                if ($(this).attr('type') === 'checkbox' || $(this).attr('type') === 'radio') {
                    if ($(this).val() === value) {
                        $(this).prop('checked', true);
                    }
                }
            });
        });
    }

    /**
     * NEW: Disables invalid Minimum and Maximum follower options.
     * Prevents selecting a Max that is lower than the Min, and vice versa.
     */
    function sync_follower_min_max_states() {
        const minRadios = document.querySelectorAll('input[name="min_followers[]"], input[name="min_followers"]');
        const maxRadios = document.querySelectorAll('input[name="max_followers[]"], input[name="max_followers"]');

        if (!minRadios.length || !maxRadios.length) return;

        // Safely parse numeric values (handles string ranges like "1000-10000")
        const getNumericValue = (val, isMax) => {
            if (!val) return null;
            const parts = val.split('-');
            if (isMax && parts.length > 1) {
                return parseInt(parts[1], 10);
            }
            return parseInt(parts[0], 10);
        };

        let currentMin = null;
        let currentMax = null;

        minRadios.forEach(radio => { if (radio.checked) currentMin = getNumericValue(radio.value, false); });
        maxRadios.forEach(radio => { if (radio.checked) currentMax = getNumericValue(radio.value, true); });

        // Update Max dropdown options
        maxRadios.forEach(radio => {
            const val = getNumericValue(radio.value, true);
            const label = radio.closest('.dropdown-item');

            if (currentMin !== null && val <= currentMin) {
                radio.disabled = true;
                if (label) {
                    label.style.opacity = '0.4';
                    label.style.pointerEvents = 'none';
                }
            } else {
                radio.disabled = false;
                if (label) {
                    label.style.opacity = '1';
                    label.style.pointerEvents = 'auto';
                }
            }
        });

        // Update Min dropdown options
        minRadios.forEach(radio => {
            const val = getNumericValue(radio.value, false);
            const label = radio.closest('.dropdown-item');

            if (currentMax !== null && val >= currentMax) {
                radio.disabled = true;
                if (label) {
                    label.style.opacity = '0.4';
                    label.style.pointerEvents = 'none';
                }
            } else {
                radio.disabled = false;
                if (label) {
                    label.style.opacity = '1';
                    label.style.pointerEvents = 'auto';
                }
            }
        });
    }

    /**
     * Reorders the niche tags on creator cards so active filters appear first.
     * It dynamically handles unhiding matched tags and hiding the overflowing ones.
     */
    function prioritize_active_tags() {
        // 1. Gather all active tags from the sidebar
        let activeTags = [];
        $('.tags-container .tag span:first-child').each(function () {
            activeTags.push($(this).text().trim().toLowerCase());
        });

        // If no active tags, exit early
        if (activeTags.length === 0) return;

        // 2. Loop through every creator card's tag container
        $('.influencer-niche-container').each(function () {
            let $container = $(this);
            let $terms = $container.find('.niche-term');
            let $toggle = $container.find('.niche-toggle');

            if ($terms.length === 0) return;

            // Determine how many tags are allowed to be visible before the "+ X" button
            let visibleLimit = $terms.not('.term-hidden').length;
            if (visibleLimit === 0) visibleLimit = 3; // safe fallback

            let matched = [];
            let unmatched = [];

            // 3. Separate the tags into matched and unmatched arrays
            $terms.each(function () {
                let termText = $(this).text().trim().toLowerCase();
                if (activeTags.includes(termText)) {
                    matched.push($(this));
                } else {
                    unmatched.push($(this));
                }
            });

            // If this card doesn't have any matching tags to pull forward, skip it
            if (matched.length === 0) return;

            // Combine them with matched tags first
            let sortedTerms = matched.concat(unmatched);

            // Detach everything so we can rebuild it cleanly
            $terms.detach();
            if ($toggle.length) $toggle.detach();

            // 4. Re-append the tags and recalculate visibility
            $.each(sortedTerms, function (index, $term) {
                if (index < visibleLimit) {
                    // Make visible
                    $term.removeClass('term-hidden').css('display', '');
                } else {
                    // Hide
                    $term.addClass('term-hidden').css('display', 'none');
                }
                $container.append($term);
            });

            // 5. Re-append the toggle button and update its number
            if ($toggle.length) {
                let hiddenCount = sortedTerms.length - visibleLimit;
                if (hiddenCount > 0) {
                    // Make sure it displays the correct remaining amount
                    $toggle.text('+ ' + hiddenCount).show();
                    $container.append($toggle);
                } else {
                    // If everything fits, hide the toggle
                    $toggle.hide();
                }
            }
        });
    }

    function dashboardLogoHeightVar() {
        var $dashboardLogo = $('#dashboard-sidebar-logo');
        if ($dashboardLogo.length) {
            var dashboardLogoHeight = $dashboardLogo.outerHeight();
            $('body').css('--dashboard-sidebar-logo-height', dashboardLogoHeight + 'px');
        }
    }

    function share_profile() {
        const shareButton = document.querySelector('.share-profile a');
        if (!shareButton) return;

        shareButton.addEventListener('click', async (event) => {
            event.preventDefault();
            const currentUrl = window.location.href;
            try {
                await navigator.clipboard.writeText(currentUrl);
                alert('URL copied to clipboard successfully.');
            } catch (error) {
                console.error('Clipboard write failed:', error);
            }
        });

        $('.share-profile-trigger').click(function (e) {
            jQuery('#social-sharing').toggleClass('hide-element');
            e.preventDefault();
        });
    }

    function mobile_nav() {
        $('.mobile-nav-trigger').on('click', function (e) {
            e.preventDefault();
            $('body').toggleClass('mobile-menu-active');
        });
    }

    function nicheToggle() {
        jQuery(document).on('click', '.niche-toggle', function (e) {
            e.preventDefault();
            jQuery(this).parent().find('.niche-term').show();
            jQuery(this).hide();
        });
    }

    function influencer_search_trigger() {
        jQuery('.influencer-search-trigger').on('click', function (e) {
            e.preventDefault();
            fetch_influencers(false);
        });
    }

    // Global variables to track pagination
    var current_page = 1;
    var max_pages = 1;

    function fetch_influencers(is_load_more = false) {
        var container = $('#my-loop-grid-container');
        var button = $('#load-more-influencers');
        $('.loading-animation').show();

        if (!is_load_more) {
            current_page = 1;
        }

        function get_filter_values(name) {
            return $('[name="' + name + '"]:checked').map(function () {
                return $(this).val();
            }).get();
        }

        var filter_niche = get_filter_values('niche[]');
        var filter_country = get_filter_values('country[]');
        var filter_lang = get_filter_values('lang[]');
        var filter_filter = get_filter_values('filter[]');
        var search_brief = ($('#search-brief').length) ? $('#search-brief').val() : '';
        var min_f_arr = get_filter_values('min_followers[]');
        var max_f_arr = get_filter_values('max_followers[]');
        var filter_min_followers = min_f_arr.length > 0 ? min_f_arr[0] : '';
        var filter_max_followers = max_f_arr.length > 0 ? max_f_arr[0] : '';

        // --- NEW: Update the URL query parameters so the search is reloadable/shareable ---
        if (!is_load_more) {
            var urlParams = new URLSearchParams();
            
            // Append brief
            if (search_brief) urlParams.set('search-brief', search_brief);
            
            // Append array-based filters
            filter_niche.forEach(function(val) { urlParams.append('niche[]', val); });
            filter_country.forEach(function(val) { urlParams.append('country[]', val); });
            filter_lang.forEach(function(val) { urlParams.append('lang[]', val); });
            filter_filter.forEach(function(val) { urlParams.append('filter[]', val); });
            min_f_arr.forEach(function(val) { urlParams.append('min_followers[]', val); });
            max_f_arr.forEach(function(val) { urlParams.append('max_followers[]', val); });
            
            // Set the search active flag
            urlParams.set('search_active', 'true');
            
            // Push the new URL to the browser without reloading
            var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?' + urlParams.toString();
            window.history.pushState({path: newUrl}, '', newUrl);
        }
        // ----------------------------------------------------------------------------------

        container.css('opacity', '0.5');
        button.text('Loading...');

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'my_custom_loop_filter',
                niche: filter_niche,
                country: filter_country,
                lang: filter_lang,
                min_followers: filter_min_followers,
                max_followers: filter_max_followers,
                filter: filter_filter,
                search_brief: search_brief,
                paged: current_page,
                search_active: 'true'
            },
            success: function (response) {
                if (response.success) {
                    $('.loading-animation').hide();
                    max_pages = response.data.max_pages;

                    if (is_load_more) {
                        container.append(response.data.html);
                    } else {
                        container.html(response.data.html);
                    }

                    // Trigger the tag sorting function after HTML loads
                    prioritize_active_tags();

                    jQuery('.total-found-influencer').text(response.data.found_posts);
                    var count = jQuery('#my-loop-grid-container .e-loop-item').length;
                    jQuery('.current-found-influencer').text(count);

                    if (current_page < max_pages) {
                        button.show();
                        button.text('Load More');
                    } else {
                        button.hide();
                    }

                } else {
                    if (!is_load_more) {
                        container.html('<p>No influencers found matching your criteria.</p>');
                    }
                    button.hide();
                }
                container.css('opacity', '1');
            },
            error: function () {
                container.html('<p>An error occurred. Please try again.</p>');
                container.css('opacity', '1');
                button.text('Try Again');
            }
        });
    }

    jQuery(document).on('click', '#load-more-influencers', function (e) {
        e.preventDefault();
        current_page++;
        fetch_influencers(true);
    });

    function influencer_select_filters() {
        document.querySelectorAll('.select-filter').forEach(widget => {
            const dropdownBtn = widget.querySelector('.dropdown-button');
            const dropdownMenu = widget.querySelector('.dropdown-menu');
            const tagsContainer = widget.querySelector('.tags-container');
            const resetBtn = widget.querySelector('.reset-btn');
            const searchInput = widget.querySelector('.dropdown-search-input');
            const optionsList = widget.querySelector('.options-list');
            const ajaxSearchType = searchInput ? searchInput.getAttribute('data-ajax-search') : '';
            const isNicheAjaxSearch = ajaxSearchType === 'niche';
            const minChars = searchInput ? parseInt(searchInput.getAttribute('data-min-chars') || '3', 10) : 3;
            const maxResults = searchInput ? parseInt(searchInput.getAttribute('data-limit') || '20', 10) : 20;
            let searchTimer = null;
            let requestSeq = 0;

            dropdownBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                closeAllOtherDropdowns(dropdownMenu, dropdownBtn);
                dropdownMenu.classList.toggle('show');
                dropdownBtn.classList.toggle('open');
                if (dropdownMenu.classList.contains('show') && searchInput) {
                    setTimeout(() => searchInput.focus(), 100);
                }
            });

            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const raw = (e.target.value || '');
                    const filter = raw.toLowerCase();

                    if (!isNicheAjaxSearch) {
                        const listItems = widget.querySelectorAll('.dropdown-item');
                        listItems.forEach(item => {
                            const text = item.textContent || item.innerText;
                            if (text.toLowerCase().indexOf(filter) > -1) {
                                item.style.display = "";
                            } else {
                                item.style.display = "none";
                            }
                        });
                        return;
                    }

                    if (searchTimer) clearTimeout(searchTimer);
                    searchTimer = setTimeout(() => {
                        const term = raw.trim();
                        if (term.length < minChars) {
                            optionsList.innerHTML = '';
                            return;
                        }

                        /**
                         * INJECT LOADING STATE
                         * Utilizes an inline SVG with <animateTransform> to render a spinning 
                         * loader dynamically. This guarantees rendering without external CSS keyframes,
                         * preventing flash-of-unstyled-content (FOUC) during AJAX resolution.
                         */
                        optionsList.innerHTML = `
                            <div class="ajax-loading-state" style="padding: 15px; text-align: center; color: #666; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <svg width="18" height="18" viewBox="0 0 50 50">
                                    <circle cx="25" cy="25" r="20" fill="none" stroke="#e0e0e0" stroke-width="4"></circle>
                                    <circle cx="25" cy="25" r="20" fill="none" stroke="#333" stroke-width="4" stroke-dasharray="30 150" stroke-linecap="round">
                                        <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="0.8s" values="0 25 25;360 25 25"></animateTransform>
                                    </circle>
                                </svg>
                                Searching...
                            </div>
                        `;

                        const mySeq = ++requestSeq;
                        const selectedInputs = Array.from(widget.querySelectorAll('.dropdown-item input:checked'));
                        const selected = selectedInputs.map(i => i.value);
                        const selectedMap = {};
                        selectedInputs.forEach(i => {
                            selectedMap[i.value] = i.getAttribute('data-label') || i.value;
                        });

                        $.ajax({
                            url: ajax_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'dd_search_niche_options',
                                q: term,
                                selected: selected,
                                limit: maxResults
                            },
                            success: function (response) {
                                if (mySeq !== requestSeq) return;

                                // Handle edge case: empty data payload
                                if (!response || !response.success || !response.data || !Array.isArray(response.data.items)) {
                                    optionsList.innerHTML = '<div style="padding: 15px; text-align: center; color: #999; font-size: 14px;">No matches found.</div>';
                                    return;
                                }

                                const items = response.data.items.slice(0, maxResults);
                                const mergedItems = [];
                                const seen = {};

                                // Keep previously selected options visible so multi-select persists across searches.
                                Object.keys(selectedMap).forEach(value => {
                                    if (seen[value]) return;
                                    seen[value] = true;
                                    mergedItems.push({
                                        value: value,
                                        label: selectedMap[value],
                                        selected: true
                                    });
                                });

                                items.forEach(item => {
                                    if (!item || !item.value) return;
                                    if (seen[item.value]) return;
                                    seen[item.value] = true;
                                    mergedItems.push(item);
                                });

                                // Replace loading state if no final options exist
                                if (!mergedItems.length) {
                                    optionsList.innerHTML = '<div style="padding: 15px; text-align: center; color: #999; font-size: 14px;">No matches found.</div>';
                                    return;
                                }

                                // Populate valid items
                                optionsList.innerHTML = mergedItems.map(item => {
                                    const checked = item.selected ? 'checked="checked"' : '';
                                    return '<label class="dropdown-item checkbox-list-item">' +
                                        '<input class="pseudo-checkbox-input" type="checkbox" value="' + escapeHtml(item.value) + '" data-label="' + escapeHtml(item.label) + '" name="niche[]" ' + checked + '> ' +
                                        '<span class="pseudo-checkbox"></span> ' + escapeHtml(item.label) +
                                        '</label>';
                                }).join('');
                                updateTags();
                            },
                            error: function () {
                                if (mySeq !== requestSeq) return;
                                optionsList.innerHTML = '<div style="padding: 15px; text-align: center; color: #ff4d4d; font-size: 14px;">An error occurred. Please try again.</div>';
                            }
                        });
                    }, 220); // 220ms debounce execution
                });

                searchInput.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            }

            widget.addEventListener('change', (e) => {
                const target = e.target;
                if (target && target.matches('.dropdown-item input')) {
                    updateTags();

                    // --- NEW: Re-run state locking if a follower field was changed
                    if (target.name.includes('followers')) {
                        sync_follower_min_max_states();
                    }
                }
            });

            resetBtn.addEventListener('click', () => {
                const checkboxes = widget.querySelectorAll('.dropdown-item input');
                checkboxes.forEach(box => box.checked = false);
                if (searchInput) {
                    searchInput.value = '';
                    if (isNicheAjaxSearch) {
                        optionsList.innerHTML = '';
                    } else {
                        const listItems = widget.querySelectorAll('.dropdown-item');
                        listItems.forEach(item => item.style.display = "");
                    }
                }
                updateTags();

                // --- NEW: Free up the disabled locks when resetting
                sync_follower_min_max_states();
            });

            function updateTags() {
                const checkboxes = widget.querySelectorAll('.dropdown-item input');
                tagsContainer.innerHTML = '';
                let hasSelection = false;
                checkboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        createTag(checkbox.dataset.label, checkbox);
                        hasSelection = true;
                    }
                });
                if (hasSelection) {
                    tagsContainer.style.display = '';
                    resetBtn.style.display = '';
                } else {
                    tagsContainer.style.display = 'none';
                    resetBtn.style.display = 'none';
                }
            }

            function createTag(label, linkedCheckbox) {
                const tag = document.createElement('div');
                tag.classList.add('tag');
                const text = document.createElement('span');
                text.innerText = label;
                const closeBtn = document.createElement('span');
                closeBtn.classList.add('tag-close');
                closeBtn.innerHTML = '&times;';

                closeBtn.addEventListener('click', () => {
                    linkedCheckbox.checked = false;
                    updateTags();

                    // --- NEW: Re-evaluate locks if a tag is removed
                    if (linkedCheckbox.name.includes('followers')) {
                        sync_follower_min_max_states();
                    }
                });

                tag.appendChild(text);
                tag.appendChild(closeBtn);
                tagsContainer.appendChild(tag);
            }

            updateTags();
        });

        document.addEventListener('click', (e) => {
            document.querySelectorAll('.select-filter').forEach(widget => {
                const dropdownBtn = widget.querySelector('.dropdown-button');
                const dropdownMenu = widget.querySelector('.dropdown-menu');

                if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                    dropdownMenu.classList.remove('show');
                    dropdownBtn.classList.remove('open');
                }
            });
        });

        function closeAllOtherDropdowns(currentMenu, currentBtn) {
            document.querySelectorAll('.select-filter').forEach(widget => {
                const menu = widget.querySelector('.dropdown-menu');
                const btn = widget.querySelector('.dropdown-button');

                if (menu !== currentMenu && btn !== currentBtn) {
                    menu.classList.remove('show');
                    btn.classList.remove('open');
                }
            });
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    }
})(jQuery);