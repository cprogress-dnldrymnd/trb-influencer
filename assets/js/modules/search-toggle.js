(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Controls the toggle switch between Filtered Search and Full Brief Search modes.
     * Handles showing/hiding the advanced search trigger, clearing inputs on mode switch,
     * and the Reset All button.
     *
     * @param {boolean} isInit  Pass true on page load to skip input clearing.
     */
    InfluencerApp.initSearchToggle = function () {
        var toggleInput     = $('#my-toggle');
        var resetAllBtn     = $('.reset-filters-btn');
        var advancedTrigger = $('.advanced-search-trigger');
        var advancedFilters = $('.advanced-search-filters');

        function updateSearchVisibility(isInit) {
            var isChecked = toggleInput.is(':checked');

            if (isChecked) {
                // Full Brief mode
                $('.filtered-search').removeClass('active');
                $('.full-brief-search').addClass('active');
                $('#search-brief').attr('required', true);

                advancedTrigger.hide().removeClass('open');
                advancedFilters.slideUp(300);

                if (!isInit) {
                    $('.filtered-search, .advanced-search-filters')
                        .find('.filter-widget .reset-btn')
                        .trigger('click');

                    $('.filtered-search, .advanced-search-filters')
                        .find('input[type="checkbox"], input[type="radio"]')
                        .prop('checked', false)
                        .trigger('change');
                }

                $('.influencer-search-main').find('.custom-group-error').remove();
                resetAllBtn.css('display', 'none');

            } else {
                // Filtered Search mode (default)
                $('.filtered-search').addClass('active');
                $('.full-brief-search').removeClass('active');
                $('#search-brief').attr('required', false);

                advancedTrigger.css('display', 'inline-flex');

                if (!isInit) {
                    $('#search-brief').val('');
                }

                resetAllBtn.css('display', 'inline-block');
            }
        }

        updateSearchVisibility(true);

        toggleInput.on('change', function () {
            updateSearchVisibility(false);
        });

        resetAllBtn.on('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.filter-widget .reset-btn').forEach(function (btn) {
                if (btn) btn.click();
            });
            $('.active-filter-chip').remove();
        });
    };

    /**
     * Toggles the advanced search filter panel open/closed when its trigger is clicked.
     */
    InfluencerApp.initAdvancedSearchToggle = function () {
        $('.advanced-search-trigger').on('click', function () {
            var $form       = $(this).closest('.influencer-search-main');
            var filtersWrap = $form.find('.advanced-search-filters');

            filtersWrap.slideToggle(300);
            $(this).toggleClass('open');
        });
    };

})(jQuery);
