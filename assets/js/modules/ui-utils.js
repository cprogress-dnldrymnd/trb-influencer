(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Sets a CSS variable on <body> equal to the dashboard sidebar logo height.
     * Re-runs on window resize via main.js.
     */
    InfluencerApp.dashboardLogoHeightVar = function () {
        var $logo = $('#dashboard-sidebar-logo');
        if ($logo.length) {
            $('body').css('--dashboard-sidebar-logo-height', $logo.outerHeight() + 'px');
        }
    };

    /**
     * Toggles the mobile nav open/closed state on the body element.
     */
    InfluencerApp.mobile_nav = function () {
        $('.mobile-nav-trigger').on('click', function (e) {
            e.preventDefault();
            $('body').toggleClass('mobile-menu-active');
        });
    };

    /**
     * Copies the current page URL to the clipboard and toggles the social
     * sharing panel.
     */
    InfluencerApp.share_profile = function () {
        var shareButton = document.querySelector('.share-profile a');

        if (shareButton) {
            shareButton.addEventListener('click', async function (e) {
                e.preventDefault();
                try {
                    await navigator.clipboard.writeText(window.location.href);
                    alert('URL copied to clipboard successfully.');
                } catch (err) {
                    console.error('Clipboard write failed:', err);
                }
            });
        }

        $('.share-profile-trigger').on('click', function (e) {
            e.preventDefault();
            $('#social-sharing').toggleClass('hide-element');
        });
    };

})(jQuery);
