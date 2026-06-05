(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    InfluencerApp.ddAlert   = window.ddAlert;
    InfluencerApp.ddConfirm = window.ddConfirm;

    InfluencerApp.dashboardLogoHeightVar = function () {
        var $logo = $('#dashboard-sidebar-logo');
        if ($logo.length) {
            $('body').css('--dashboard-sidebar-logo-height', $logo.outerHeight() + 'px');
        }
    };

    InfluencerApp.mobile_nav = function () {
        var trigger = document.querySelector('.mobile-nav-trigger');
        if (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                document.body.classList.toggle('mobile-menu-active');
            });
        }
    };

    InfluencerApp.share_profile = function () {
        var shareButton = document.querySelector('.share-profile a');

        if (shareButton) {
            shareButton.addEventListener('click', async function (e) {
                e.preventDefault();
                var url = window.location.href;
                try {
                    await navigator.clipboard.writeText(url);
                    window.ddAlert('URL copied to clipboard successfully.');
                } catch (err) {
                    // Clipboard API unavailable (e.g. non-HTTPS context) — show the URL so user can copy manually.
                    window.prompt('Copy the link below:', url);
                }
            });
        }

        var shareTrigger = document.querySelector('.share-profile-trigger');
        if (shareTrigger) {
            shareTrigger.addEventListener('click', function (e) {
                e.preventDefault();
                var panel = document.getElementById('social-sharing');
                if (panel) panel.classList.toggle('hide-element');
            });
        }
    };

})(jQuery);
