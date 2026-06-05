(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Validates required filter groups (those with the .required-on-search class)
     * before the search form submits. Injects inline error messages and prevents
     * submission when no selection has been made.
     */
    InfluencerApp.validate_required_search_filters = function () {
        var form = document.querySelector('.influencer-search-main');
        if (!form) return;

        // Native submit validation
        form.addEventListener('submit', function (e) {
            var filteredSearch = form.querySelector('.filtered-search');
            if (!filteredSearch || !filteredSearch.classList.contains('active')) return;

            var optionLists = filteredSearch.querySelectorAll('.required-on-search .options-list');
            var isFormValid = true;

            form.querySelectorAll('.custom-group-error').forEach(function (err) {
                err.remove();
            });

            optionLists.forEach(function (listElement) {
                var inputs     = Array.from(listElement.querySelectorAll('input[type="checkbox"], input[type="radio"]'));
                if (inputs.length === 0) return;

                var hasSelection = inputs.some(function (input) { return input.checked; });

                if (!hasSelection) {
                    isFormValid = false;
                    var dropdownHeader = listElement.closest('.filter-widget').querySelector('.dropdown-button');
                    if (dropdownHeader) {
                        var errorSpan           = document.createElement('span');
                        errorSpan.className     = 'custom-group-error';
                        errorSpan.style.cssText = 'color:#dc3545;font-size:12px;display:block;margin-top:4px;font-weight:normal;text-transform:initial;';
                        errorSpan.innerText     = '* At least 1 selection required';
                        dropdownHeader.appendChild(errorSpan);
                    }
                }
            });

            if (!isFormValid) e.preventDefault();
        });

        // Real-time error clearing
        form.addEventListener('change', function (e) {
            if (e.target.matches('.options-list input')) {
                var widget = e.target.closest('.filter-widget');
                if (widget) {
                    var errorMsg = widget.querySelector('.custom-group-error');
                    if (errorMsg) errorMsg.remove();
                }
            }
        });
    };

})(jQuery);
