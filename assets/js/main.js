(function ($) {
    jQuery(document).ready(function () {
        nicheToggle();
        fetch_influencers();
        influencer_select_filters();
        influencer_search_trigger();
        saved_search_trigger();

    });

    function nicheToggle() {
        jQuery('.niche-toggle').click(function (e) {
            e.preventDefault();
            jQuery(this).parent().find('.niche-term').show();
            jQuery(this).hide();
        });
    }


    function influencer_search_trigger() {
        jQuery('.influencer-search-trigger').on('click', function (e) {
            e.preventDefault();
            fetch_influencers();
        });
    }



    function fetch_influencers() {
        var container = $('#my-loop-grid-container');

        // Gather values from inputs
        // Adjust selectors if your inputs use IDs (e.g. #niche) instead of names
        var filter_niche = $('[name="niche"]').val();
        var filter_platform = $('[name="platform"]').val();
        var filter_country = $('[name="country"]').val();
        var filter_lang = $('[name="lang"]').val();
        var filter_followers = $('[name="followers"]').val();

        // UI Feedback
        container.css('opacity', '0.5');

        $.ajax({
            url: search_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'my_custom_loop_filter',
                niche: filter_niche,
                platform: filter_platform,
                country: filter_country,
                lang: filter_lang,
                followers: filter_followers
            },
            success: function (response) {
                if (response.success) {
                    container.html(response.data);
                } else {
                    container.html('<p>No influencers found matching your criteria.</p>');
                }
                container.css('opacity', '1');
            },
            error: function () {
                container.html('<p>An error occurred. Please try again.</p>');
                container.css('opacity', '1');
            }
        });
    }

    function influencer_select_filters() {

        // 1. Initialize all widgets independently
        document.querySelectorAll('.select-filter').forEach(widget => {

            // Scope elements to THIS specific widget instance
            const dropdownBtn = widget.querySelector('.dropdown-button');
            const dropdownMenu = widget.querySelector('.dropdown-menu');
            const checkboxes = widget.querySelectorAll('.dropdown-item input[type="checkbox"]');
            const tagsContainer = widget.querySelector('.tags-container');
            const resetBtn = widget.querySelector('.reset-btn');

            // Toggle Dropdown
            dropdownBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                // Close other open widgets (optional UX choice)
                closeAllOtherDropdowns(dropdownMenu, dropdownBtn);

                dropdownMenu.classList.toggle('show');
                dropdownBtn.classList.toggle('open');
            });

            // Handle Checkbox Selection
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateTags();
                });
            });

            // Reset functionality
            resetBtn.addEventListener('click', () => {
                checkboxes.forEach(box => box.checked = false);
                updateTags();
            });

            // Function to Render Tags
            function updateTags() {
                tagsContainer.innerHTML = ''; // Clear only this widget's container

                let hasSelection = false; // Track if we have active tags

                checkboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        createTag(checkbox.dataset.label, checkbox);
                        hasSelection = true;
                    }
                });

                // --- VISIBILITY TOGGLE ---
                // If selections exist, remove inline 'none' (reverting to CSS default like block or flex). 
                // If empty, set display to 'none'.
                if (hasSelection) {
                    tagsContainer.style.display = '';
                } else {
                    tagsContainer.style.display = 'none';
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

                // Remove tag logic (Uncheck specific box in this widget)
                closeBtn.addEventListener('click', () => {
                    linkedCheckbox.checked = false;
                    updateTags();
                });

                tag.appendChild(text);
                tag.appendChild(closeBtn);
                tagsContainer.appendChild(tag);
            }

            // --- INITIALIZATION ---
            // Run once on load to ensure container is hidden if empty
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
                url: search_vars.ajax_url, // URL passed from PHP via wp_localize_script
                type: 'POST',
                data: {
                    action: 'save_user_search', // Must match the wp_ajax_{action} hook in PHP
                    security: search_vars.nonce,  // Security token passed from PHP
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
                error: function () {
                    alert('Server error. Please try again.');
                    $btn.text(originalText);
                }
            });
        });
    }
})(jQuery);