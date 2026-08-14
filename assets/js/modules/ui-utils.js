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
        var triggers = document.querySelectorAll('.mobile-nav-trigger');
        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                document.body.classList.toggle('mobile-menu-active');
            });
        });
    };

    /**
     * Refreshes every credits ticker on the page ([credits_remaining]
     * shortcode output — banner + unlock modal) after an AJAX action
     * changes the balance, without a full reload. Reads the per-action
     * costs off each ticker's own data-* attributes and the noun templates
     * off the localized dd_messages global, so the recomputed detail text
     * ("24 creator unlocks or 24 messages") can never disagree with what
     * the server just charged — it uses the exact same costs the server
     * rendered the ticker with.
     *
     * @param {number|string} balance New credit balance to display.
     */
    InfluencerApp.updateCreditsRemaining = function (balance) {
        balance = parseInt(balance, 10);
        if (isNaN(balance)) {
            return;
        }

        var $tickers = $('.dd-credits-remaining');
        if (!$tickers.length) {
            return;
        }

        var msgs = (typeof dd_messages !== 'undefined') ? dd_messages : {};
        var join = msgs.dd_msg_credits_detail_join || 'or';

        $tickers.each(function () {
            var $ticker      = $(this);
            var unlockCost   = parseInt($ticker.attr('data-unlock-cost'), 10) || 0;
            var messageCost  = parseInt($ticker.attr('data-message-cost'), 10) || 0;
            var parts        = [];

            $ticker.find('.dd-credits-remaining-value').text(balance);

            if (unlockCost > 0) {
                var unlockTpl = msgs.dd_msg_credits_detail_unlock || '%s creator unlocks';
                parts.push(unlockTpl.replace('%s', Math.max(0, Math.floor(balance / unlockCost))));
            }
            if (messageCost > 0) {
                var messageTpl = msgs.dd_msg_credits_detail_message || '%s messages';
                parts.push(messageTpl.replace('%s', Math.max(0, Math.floor(balance / messageCost))));
            }

            var $detail = $ticker.find('.dd-credits-remaining-detail');
            if ($detail.length) {
                $detail.text(parts.length ? parts.join(' ' + join + ' ') : '');
            }
        });
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
