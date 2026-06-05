(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Validates required filter groups (those with the .required-on-search class)
     * before the search form submits. Injects inline error messages and prevents
     * submission when no selection has been made.
     */
    InfluencerApp.validate_required_search_filters = function () {
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
                window.ddAlert('Please populate all required filters (e.g., Niche) before generating matches.');
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
    };

})(jQuery);
