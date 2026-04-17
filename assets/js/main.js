(function ($) {
    jQuery(document).ready(function () {
        // --- FIX: Sync URL Parameters to Checkboxes before anything else ---
        sync_url_params_to_dom();

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

        $(window).on('resize', function () {
            dashboardLogoHeightVar();
        });
    });

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
            $('input[name="' + key + '"]').each(function() {
                if ($(this).attr('type') === 'checkbox' || $(this).attr('type') === 'radio') {
                    if ($(this).val() === value) {
                        $(this).prop('checked', true);
                    }
                }
            });
        });
    }

    /**
     * NEW: Reorders the niche tags on creator cards so active filters appear first.
     * It dynamically handles unhiding matched tags and hiding the overflowing ones.
     */
    function prioritize_active_tags() {
        // 1. Gather all active tags from the sidebar
        let activeTags = [];
        $('.tags-container .tag span:first-child').each(function() {
            activeTags.push($(this).text().trim().toLowerCase());
        });

        // If no active tags, exit early
        if (activeTags.length === 0) return;

        // 2. Loop through every creator card's tag container
        $('.influencer-niche-container').each(function() {
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
            $terms.each(function() {
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
            $.each(sortedTerms, function(index, $term) {
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
        var filter_followers = get_filter_values('followers');
        var filter_filter = get_filter_values('filter[]');
        var search_brief = ($('#search-brief').length) ? $('#search-brief').val() : '';

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
                followers: filter_followers,
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

                    // --- NEW: Trigger the tag sorting function after HTML loads ---
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
            const checkboxes = widget.querySelectorAll('.dropdown-item input');
            const tagsContainer = widget.querySelector('.tags-container');
            const resetBtn = widget.querySelector('.reset-btn');
            const searchInput = widget.querySelector('.dropdown-search-input');
            const listItems = widget.querySelectorAll('.dropdown-item');

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
                    const filter = e.target.value.toLowerCase();
                    listItems.forEach(item => {
                        const text = item.textContent || item.innerText;
                        if (text.toLowerCase().indexOf(filter) > -1) {
                            item.style.display = ""; 
                        } else {
                            item.style.display = "none"; 
                        }
                    });
                });
                searchInput.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateTags();
                });
            });

            resetBtn.addEventListener('click', () => {
                checkboxes.forEach(box => box.checked = false);
                if (searchInput) {
                    searchInput.value = '';
                    listItems.forEach(item => item.style.display = "");
                }
                updateTags();
            });

            function updateTags() {
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
                    // Also trigger search auto-update on tag removal if needed
                    // fetch_influencers(false); 
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
    }
})(jQuery);