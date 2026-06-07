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

        function hasActiveFilters() {
            return $('.influencer-search-main input[type="checkbox"]:not(#my-toggle):checked').length > 0;
        }

        function syncResetBtn() {
            if (toggleInput.is(':checked')) {
                resetAllBtn.css('display', 'none');
            } else {
                resetAllBtn.css('display', hasActiveFilters() ? 'inline-block' : 'none');
            }
        }

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

                syncResetBtn();
            }
        }

        updateSearchVisibility(true);

        toggleInput.on('change', function () {
            updateSearchVisibility(false);
        });

        // Show/hide reset button as filters are toggled
        $('.influencer-search-main').on('change', 'input[type="checkbox"]', function () {
            if (this.id === 'my-toggle') return;
            syncResetBtn();
        });

        resetAllBtn.on('click', function (e) {
            e.preventDefault();
            $('.filter-widget .reset-btn').each(function () { this.click(); });
            $('.active-filter-chip').remove();
            syncResetBtn();
        });
    };

    /**
     * Shows/hides the "Reset All" button on the sidebar search form based on
     * whether any filters are currently selected, and clears every filter
     * widget in the form when clicked.
     */
    InfluencerApp.init_sidebar_reset_all = function () {
        var $form = $('.influencer-search-sidebar');
        if (!$form.length) return;

        var $resetBtn = $form.find('.reset-all-btn');
        if (!$resetBtn.length) return;

        function hasActiveFilters() {
            return $form.find('input[type="checkbox"]:checked, input[type="radio"]:checked').length > 0;
        }

        function syncVisibility() {
            $resetBtn.css('display', hasActiveFilters() ? '' : 'none');
        }

        $form.on('change', 'input[type="checkbox"], input[type="radio"]', syncVisibility);

        $resetBtn.on('click', function (e) {
            e.preventDefault();
            $form.find('.filter-widget .reset-btn').each(function () { this.click(); });
            $form.find('.checkbox-filter input[type="checkbox"]').prop('checked', false);
            syncVisibility();
        });

        syncVisibility();
    };

    /**
     * Toggles the sidebar search form as a sideout modal on small screens (<=767px).
     * Shared `.refine-search-trigger` class is used by the open button, the close
     * button, and the backdrop so any of them can open or close the panel.
     */
    InfluencerApp.initRefineSearchToggle = function () {
        var triggers = document.querySelectorAll('.refine-search-trigger');
        if (!triggers.length) return;

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                document.body.classList.toggle('refine-search-active');
            });
        });

        // Auto-close the sideout panel when a search is run from it so the
        // loading animation on the results page becomes visible.
        $('.influencer-search-sidebar .influencer-search-trigger').on('click', function () {
            document.body.classList.remove('refine-search-active');
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
