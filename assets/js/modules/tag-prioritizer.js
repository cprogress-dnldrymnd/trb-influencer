(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Reorders niche tags on creator cards so active filter tags appear first.
     * Dynamically handles unhiding matched tags and hiding overflowing ones.
     */
    InfluencerApp.prioritize_active_tags = function () {
        var activeTags = [];
        $('.tags-container .tag span:first-child').each(function () {
            activeTags.push($(this).text().trim().toLowerCase());
        });

        if (activeTags.length === 0) return;

        $('.influencer-niche-container').each(function () {
            var $container = $(this);
            var $terms     = $container.find('.niche-term');
            var $toggle    = $container.find('.niche-toggle');

            if ($terms.length === 0) return;

            var visibleLimit = $terms.not('.term-hidden').length;
            if (visibleLimit === 0) visibleLimit = 3;

            var matched   = [];
            var unmatched = [];

            $terms.each(function () {
                var termText = $(this).text().trim().toLowerCase();
                if (activeTags.includes(termText)) {
                    matched.push($(this));
                } else {
                    unmatched.push($(this));
                }
            });

            if (matched.length === 0) return;

            var sortedTerms = matched.concat(unmatched);

            $terms.detach();
            if ($toggle.length) $toggle.detach();

            $.each(sortedTerms, function (index, $term) {
                if (index < visibleLimit) {
                    $term.removeClass('term-hidden').css('display', '');
                } else {
                    $term.addClass('term-hidden').css('display', 'none');
                }
                $container.append($term);
            });

            if ($toggle.length) {
                var hiddenCount = sortedTerms.length - visibleLimit;
                if (hiddenCount > 0) {
                    $toggle.text('+ ' + hiddenCount).show();
                    $container.append($toggle);
                } else {
                    $toggle.hide();
                }
            }
        });
    };

    /**
     * Expands all niche tags when the "+N" toggle is clicked.
     */
    InfluencerApp.nicheToggle = function () {
        $(document).on('click', '.niche-toggle', function (e) {
            e.preventDefault();
            $(this).parent().find('.niche-term').show();
            $(this).hide();
        });
    };

})(jQuery);
