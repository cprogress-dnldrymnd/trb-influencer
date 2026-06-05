(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Renders "chip" tags in the selected-option container whenever a filter
     * checkbox is checked, and removes them when unchecked or when the chip's
     * ✕ button is clicked.
     */
    InfluencerApp.initActiveFilterChips = function () {
        var formSelector    = '.influencer-search-main';
        var resultContainer = '.selected-option';
        var resetButton     = '.reset-filters-btn';

        $(formSelector).on('change', 'input[type="checkbox"]', function () {
            if (this.id === 'my-toggle') return;
            updateChips(this);
        });

        function updateChips(checkbox) {
            var $checkbox = $(checkbox);
            var val       = $checkbox.val();
            if (!val) return;

            var label  = $checkbox.attr('data-label') || $checkbox.next('label').text() || val;
            var chipId = 'chip-' + val.replace(/\s+/g, '-').toLowerCase();

            if ($checkbox.is(':checked')) {
                if ($(resultContainer + ' #' + chipId).length === 0) {
                    var chipHtml =
                        '<div class="active-filter-chip" id="' + chipId + '" data-target-value="' + val + '">' +
                            label +
                            '<span class="remove-chip">✕</span>' +
                        '</div>';

                    if ($(resultContainer).find(resetButton).length > 0) {
                        $(chipHtml).insertBefore($(resultContainer).find(resetButton));
                    } else {
                        $(resultContainer).append(chipHtml);
                    }
                    $(resultContainer).show();
                }
            } else {
                $('#' + chipId).remove();
            }
        }

        $(document).on('click', '.remove-chip', function () {
            var parentChip  = $(this).closest('.active-filter-chip');
            var targetVal   = parentChip.data('target-value');
            var $target     = $(formSelector + ' input[value="' + targetVal + '"]');

            if ($target.length) {
                $target.prop('checked', false).trigger('change');
            }
            parentChip.remove();
        });
    };

})(jQuery);
