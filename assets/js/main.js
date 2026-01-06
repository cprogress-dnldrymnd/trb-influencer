(function ($) {
    jQuery(document).ready(function () {
        nicheToggle();
        fetch_influencers();
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
            fetch_influencers();
        });
    }



    function fetch_influencers() {
        var container = $('#my-loop-grid-container');

        // 1. Helper function to gather values from CHECKED boxes only
        function get_filter_values(name) {
            return $('[name="' + name + '"]:checked').map(function () {
                return $(this).val();
            }).get();
        }

        // 2. Gather values using the helper function
        // This creates an array like ['fashion', 'travel'] for the ajax call
        var filter_niche = get_filter_values('niche[]');
        var filter_platform = get_filter_values('platform[]');
        var filter_country = get_filter_values('country[]');
        var filter_lang = get_filter_values('lang[]');
        var filter_followers = get_filter_values('followers');
        // UI Feedback
        container.css('opacity', '0.5');
        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'my_custom_loop_filter',
                // jQuery automatically converts these arrays into 
                // format niche[]=val1&niche[]=val2 for PHP
                niche: filter_niche,
                platform: filter_platform,
                country: filter_country,
                lang: filter_lang,
                followers: filter_followers
            },
            success: function (response) {
                if (response.success) {
                    container.html(response.data.html);
                    jQuery('.total-found-influencer').text(response.data.found_posts);
                    $count = jQuery('#my-loop-grid-container .e-loop-item').length;
                    jQuery('.current-found-influencer').text($count);
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
            const checkboxes = widget.querySelectorAll('.dropdown-item input');
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

            // Function to Render Tags and Toggle Visibility
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
                if (hasSelection) {
                    // Remove inline styles to revert to your CSS default (block/flex)
                    tagsContainer.style.display = '';
                    resetBtn.style.display = '';
                } else {
                    // Hide if empty
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
            // Run once on load to ensure container/reset button is hidden if empty
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
                buttonupdated = 'UNSAVED';
                buttonupdating = 'SAVING...';
            }
            $buttonText.text(buttonupdating).prop('disabled', true);

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
                        $button.prop('disabled', true);

                        if (type == 'delete') {
                            $button.removeClass('delete-save');
                        } else {
                            $button.addClass('delete-save');
                        }
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