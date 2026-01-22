(function ($) {
    jQuery(document).ready(function () {
        nicheToggle();
        fetch_influencers(false);
        influencer_select_filters();
        influencer_search_trigger();
        saved_search_trigger();
        saved_influencer_trigger();
    });
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
    var max_pages = 1; // Will be updated by the PHP response

    function fetch_influencers(is_load_more = false) {
        var container = $('#my-loop-grid-container');
        var button = $('#load-more-influencers');
        $('.loading-animation').show();

        // 1. If this is NOT a "load more" click (it's a filter change), reset page to 1
        if (!is_load_more) {
            current_page = 1;
        }

        // 2. Helper function to gather values
        function get_filter_values(name) {
            return $('[name="' + name + '"]:checked').map(function () {
                return $(this).val();
            }).get();
        }

        // 3. Gather values
        var filter_niche = get_filter_values('niche[]');
        var filter_platform = get_filter_values('platform[]');
        var filter_country = get_filter_values('country[]');
        var filter_lang = get_filter_values('lang[]');
        var filter_followers = get_filter_values('followers');

        // UI Feedback (Optional: Add spinner here)
        container.css('opacity', '0.5');
        button.text('Loading...'); // Change button text while loading

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'my_custom_loop_filter',
                niche: filter_niche,
                platform: filter_platform,
                country: filter_country,
                lang: filter_lang,
                followers: filter_followers,
                paged: current_page // <--- SEND CURRENT PAGE TO PHP
            },
            success: function (response) {
                if (response.success) {
                    $('.loading-animation').hide();

                    // Update Max Pages from PHP response
                    max_pages = response.data.max_pages;

                    // A. Render HTML
                    if (is_load_more) {
                        // If loading more, APPEND to existing content
                        container.append(response.data.html);
                    } else {
                        // If filtering, REPLACE existing content
                        container.html(response.data.html);
                    }

                    // B. Update Counters
                    jQuery('.total-found-influencer').text(response.data.found_posts);
                    var count = jQuery('#my-loop-grid-container .e-loop-item').length;
                    jQuery('.current-found-influencer').text(count);

                    // C. Handle Button Visibility
                    if (current_page < max_pages) {
                        button.show();
                        button.text('Load More');
                    } else {
                        button.hide();
                    }

                } else {
                    // No posts found
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
        current_page++; // Increment page
        fetch_influencers(true); // Pass true to indicate "Load More" mode
    });


    function influencer_select_filters() {

        // 1. Initialize all widgets independently
        document.querySelectorAll('.select-filter').forEach(widget => {

            // Scope elements to THIS specific widget instance
            const dropdownBtn = widget.querySelector('.dropdown-button');
            const dropdownMenu = widget.querySelector('.dropdown-menu');
            const checkboxes = widget.querySelectorAll('.dropdown-item input');
            const tagsContainer = widget.querySelector('.tags-container');
            const resetBtn = widget.querySelector('.reset-btn');

            // New Search Elements
            const searchInput = widget.querySelector('.dropdown-search-input');
            const listItems = widget.querySelectorAll('.dropdown-item');

            // Toggle Dropdown
            dropdownBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                closeAllOtherDropdowns(dropdownMenu, dropdownBtn);

                dropdownMenu.classList.toggle('show');
                dropdownBtn.classList.toggle('open');

                // Optional: Focus search input when opening
                if (dropdownMenu.classList.contains('show') && searchInput) {
                    setTimeout(() => searchInput.focus(), 100);
                }
            });

            // --- NEW SEARCH LOGIC ---
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const filter = e.target.value.toLowerCase();

                    listItems.forEach(item => {
                        const text = item.textContent || item.innerText;
                        if (text.toLowerCase().indexOf(filter) > -1) {
                            item.style.display = ""; // Show
                        } else {
                            item.style.display = "none"; // Hide
                        }
                    });
                });

                // Prevent clicking the search input from closing the dropdown (if event bubbling causes issues)
                searchInput.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            }
            // ------------------------

            // Handle Checkbox Selection
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateTags();
                });
            });

            // Reset functionality
            resetBtn.addEventListener('click', () => {
                checkboxes.forEach(box => box.checked = false);

                // Clear search on reset
                if (searchInput) {
                    searchInput.value = '';
                    // Show all items again
                    listItems.forEach(item => item.style.display = "");
                }

                updateTags();
            });

            // Function to Render Tags and Toggle Visibility
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

            // Create individual Tag
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
                });

                tag.appendChild(text);
                tag.appendChild(closeBtn);
                tagsContainer.appendChild(tag);
            }

            // Run once on load
            updateTags();
        });

        // 2. Global "Click Outside" Listener
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

        // Helper: Close all widgets except the one currently clicked
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

    function saved_influencer_trigger() {
        // Listen for click on .save-influencer-trigger
        $(document).on('click', '.save-influencer-trigger', function (e) {
            e.preventDefault();

            var $button = $(this);

            // Get the ID from the attribute
            var influencerId = $button.attr('influencer-id');
            var $buttonText = $(this).find('.elementor-button-text');
            // (Optional) Visual feedback: Change button text or disable it

            if ($button.hasClass('delete-save')) {
                type = 'delete';
                buttonupdated = 'SAVED';
                buttonupdating = 'UNSAVING...';
            } else {
                type = 'save';
                buttonupdated = 'UNSAVE';
                buttonupdating = 'SAVING...';
            }
            $buttonText.text(buttonupdating).prop('disabled', true);
            $button.prop('disabled', true);

            $.ajax({
                url: ajax_vars.ajax_url, // From wp_localize_script
                type: 'POST',
                data: {
                    action: 'save_influencer', // Must match the wp_ajax_ hook
                    security: ajax_vars.save_influencer_nonce,
                    influencer_id: influencerId,
                    type: type
                },
                success: function (response) {
                    if (response.success) {
                        alert('Success: ' + response.data.message);
                        $buttonText.text(buttonupdated);

                        if (type == 'delete') {
                            $button.removeClass('delete-save');
                        } else {
                            $button.addClass('delete-save');
                        }

                        $button.prop('disabled', false);

                    } else {
                        alert('Error: ' + response.data.message);
                        $buttonText.text('Save Influencer').prop('disabled', false);
                    }
                },
                error: function () {
                    alert('An unexpected error occurred.');
                    $buttonText.text('Save Influencer').prop('disabled', false);
                }
            });
        });
    }

    function saved_search_trigger() {
        /**
         * Helper Function: Get Checked Values
         * * Iterates through all checkboxes that share a specific "name" attribute
         * (e.g., name="niche" or name="niche[]") and returns an array of their values.
         * * @param {string} name - The name attribute of the input field.
         * @returns {Array} - An array of values from checked boxes.
         */
        function getCheckedValues(name) {
            var values = [];
            // Selector explanation:
            // input[name^="..."] selects inputs where the name STARTS with the string provided.
            // This handles cases where the name might be "niche" or "niche[]".
            jQuery('input[name^="' + name + '"]:checked').each(function () {
                values.push(jQuery(this).val());
            });
            return values;
        }

        /**
         * Event Listener: Save Button Click
         * * Listens for a click on any element with class '.save-search-trigger'.
         * Gathers data and sends it to the server.
         */
        jQuery('.save-search-trigger').on('click', function (e) {

            // Prevent the link from jumping to the top of the page or reloading.
            e.preventDefault();

            var $btn = jQuery(this);
            var originalText = $btn.text();

            // UX: Change button text to indicate processing.
            $btn.text('Saving...');

            // 1. Collect Data Object
            // We use our helper function for checkboxes and standard .val() for the range slider.
            var searchData = {
                'niche': getCheckedValues('niche'),
                'platform': getCheckedValues('platform'),
                'followers': getCheckedValues('followers'),
                'country': getCheckedValues('country'),
                'lang': getCheckedValues('lang'),
                'gender': getCheckedValues('gender'),
                'score': jQuery('input[name="score"]').val() // Range slider usually has a single value
            };

            // 2. AJAX Request
            // Sends the collected data to the PHP function 'handle_save_search_ajax'.
            jQuery.ajax({
                url: ajax_vars.ajax_url, // URL passed from PHP via wp_localize_script
                type: 'POST',
                data: {
                    action: 'save_user_search', // Must match the wp_ajax_{action} hook in PHP
                    security: ajax_vars.save_search_nonce,  // Security token passed from PHP
                    search_data: searchData          // The object containing our form values
                },

                // 3. Handle Success
                success: function (response) {
                    if (response.success) {
                        $btn.text('Saved!');
                        // Optional: Revert text back to original after 2 seconds
                        setTimeout(function () { $btn.text(originalText); }, 2000);
                    } else {
                        // If PHP sent wp_send_json_error()
                        alert(response.data.message);
                        $btn.text(originalText);
                    }
                },

                // 4. Handle Server/Network Errors
                error: function (response) {
                    alert('Server error. Please try again.');
                    console.log(response);
                    $btn.text(originalText);
                }
            });
        });
    }
})(jQuery);