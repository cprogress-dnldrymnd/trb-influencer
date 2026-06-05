(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Validates required filter groups (those with the .required-on-search class)
     * before the search form submits. Injects inline error messages and prevents
     * submission when no selection has been made.
     */
    InfluencerApp.validate_required_search_filters = function () {
        const form = document.querySelector('.influencer-search-main');
        if (!form) return;

        form.addEventListener('submit', (e) => {
            const filteredSearch = form.querySelector('.filtered-search');

            if (filteredSearch && filteredSearch.classList.contains('active')) {
                const optionLists = filteredSearch.querySelectorAll('.required-on-search .options-list');
                let isFormValid = true;

                // Clear lingering errors
                form.querySelectorAll('.custom-group-error').forEach(err => err.remove());

                optionLists.forEach(listElement => {
                    const inputs = Array.from(listElement.querySelectorAll('input[type="checkbox"], input[type="radio"]'));
                    if (inputs.length === 0) return;

                    const hasSelection = inputs.some(input => input.checked);

                    if (!hasSelection) {
                        isFormValid = false;
                        const dropdownHeader = listElement.closest('.filter-widget').querySelector('.dropdown-button');
                        if (dropdownHeader) {
                            const errorSpan = document.createElement('span');
                            errorSpan.className = 'custom-group-error';
                            errorSpan.style.color = '#dc3545';
                            errorSpan.style.fontSize = '12px';
                            errorSpan.style.display = 'block';
                            errorSpan.style.marginTop = '4px';
                            errorSpan.style.fontWeight = 'normal';
                            errorSpan.style.textTransform = 'initial';
                            errorSpan.innerText = '* At least 1 selection required';
                            dropdownHeader.appendChild(errorSpan);
                        }
                    }
                });

                if (!isFormValid) e.preventDefault();
            }
        });

        // Real-time error clearing UX
        form.addEventListener('change', (e) => {
            if (e.target.matches('.options-list input')) {
                const widget = e.target.closest('.filter-widget');
                if (widget) {
                    const errorMsg = widget.querySelector('.custom-group-error');
                    if (errorMsg) errorMsg.remove();
                }
            }
        });
    };

})(jQuery);
