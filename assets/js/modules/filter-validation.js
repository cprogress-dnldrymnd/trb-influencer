(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Validates required filter groups (those with the .required-on-search class)
     * before the search form submits. Injects inline error messages and prevents
     * submission when no selection has been made.
     */
    InfluencerApp.validate_required_search_filters = function () {
        // Typing into a required filter's option-search box and pressing Enter
        // would otherwise submit the form before the user finishes selecting.
        $('.influencer-search .required-on-search .dropdown-search-input').on('keydown', function (e) {
            if (e.key === 'Enter' || e.which === 13) {
                e.preventDefault();
            }
        });

         $('.influencer-search').on('submit', function (e) {
            let isValid = true;
            const $form = $(this);

            // The filtered/brief toggle only exists on the main search form;
            // when it's present, only enforce required filters in filtered mode.
            // The sidebar form has no such toggle, so always enforce there.
            const hasFilteredToggle = $form.find('.filtered-search').length > 0;

            if (!hasFilteredToggle || $form.find('.filtered-search.active').length > 1) {
                // Iterate over all required filter blocks
                $form.find('.required-on-search').each(function () {
                    const $container = $(this);

                    // Verify if tags exist in the tags-container
                    const hasTags = $container.find('.tags-container .tag').length > 0;

                    // Fallback check against actual checkbox states for data integrity
                    const hasCheckedInputs = $container.find('input[type="checkbox"]:checked').length > 0;

                    if (!hasTags && !hasCheckedInputs) {
                        isValid = false;

                        // Apply visual error cue
                        $container.find('.influencer-search-item-title').css('color', '#ff4d4d');
                    } else {
                        // Clear visual error cue
                        $container.find('.influencer-search-item-title').css('color', '');
                    }
                });
            }

            // Prevent form submission if validation fails
            if (!isValid) {
                e.preventDefault();
                window.ddAlert('Please populate all required filters (e.g., Location) before generating matches.');
            }
        });

        // Event listener to dynamically remove the error styling once a user makes a valid selection
        $('.required-on-search').on('change', 'input[type="checkbox"]', function () {
            const $container = $(this).closest('.required-on-search');
            if ($container.find('input[type="checkbox"]:checked').length > 0) {
                $container.find('.influencer-search-item-title').css('color', '');
            }
        });
    };

})(jQuery);
