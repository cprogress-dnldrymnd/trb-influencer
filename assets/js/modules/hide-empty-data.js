(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    function isEmptyStatValue(text) {
        var trimmed = (text || '').trim();

        if (trimmed === '' || trimmed === '-' || trimmed.toLowerCase() === 'n/a') {
            return true;
        }

        var stripped = trimmed.replace(/^[+-]/, '').replace(/%$/, '').replace(/,/g, '');

        if (stripped !== '' && !isNaN(stripped)) {
            return parseFloat(stripped) === 0;
        }

        return false;
    }

    InfluencerApp.hideEmptyData = function () {
        document.querySelectorAll('.influencer-data-parent').forEach(function (parent) {
            var stats = parent.querySelectorAll('.platform-stat, .combined-stat');

            if (!stats.length) {
                return;
            }

            var allEmpty = true;
            stats.forEach(function (stat) {
                if (!isEmptyStatValue(stat.textContent)) {
                    allEmpty = false;
                }
            });

            parent.classList.toggle('dd-empty-hidden', allEmpty);
        });
    };

})(jQuery);
