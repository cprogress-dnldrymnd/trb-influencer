(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    var MIN_LEN = 25;
    var ACK_DATA_KEY = 'briefQualityAckText';

    function getBriefTextarea() {
        return $('#search-brief');
    }

    function getNotice() {
        return $('#brief-quality-notice');
    }

    function getForm() {
        return $('.influencer-search-main');
    }

    function isFullBriefMode() {
        return $('#my-toggle').is(':checked');
    }

    function clearAck($form) {
        $form.removeData(ACK_DATA_KEY);
    }

    function setAck($form, text) {
        $form.data(ACK_DATA_KEY, text);
    }

    function hasAckFor($form, text) {
        return $form.data(ACK_DATA_KEY) === text;
    }

    function showNotice(html) {
        getNotice().html(html);
    }

    function hideNotice() {
        getNotice().empty();
    }

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    function getIconUrl(quality) {
        var vars = window.ajax_vars || {};
        if (quality === 'too_short') {
            return vars.brief_quality_icon_red_url || vars.brief_quality_icon_url || '';
        }
        return vars.brief_quality_icon_url || '';
    }

    function buildNoticeHtml(assessment, options) {
        options = options || {};
        var copy = assessment.copy || {};
        var quality = assessment.quality;
        var title = '';
        var body = '';

        if (quality === 'too_short') {
            title = copy.too_short_title || 'Add a little more detail';
            body = copy.too_short_body || 'Please add a little more detail so we can match creators properly. Try including location, audience or engagement goals — or switch to Filtered Search if you only need a simple topic search.';
        } else if (quality === 'low') {
            title = copy.low_title || 'Simple niche search';
            body = copy.low_pre_submit || 'This looks like a broad brief — a simple niche search. Adding location, audience size, or engagement preferences helps us rank creators more accurately.';
        }

        var iconUrl = getIconUrl(quality);
        var html = '<div class="brief-quality-notice brief-quality-notice--' + quality + '">';
        html += '<div class="brief-quality-notice__inner">';

        if (iconUrl) {
            html += '<span class="brief-quality-notice__icon" aria-hidden="true">';
            html += '<img src="' + escapeHtml(iconUrl) + '" alt="" width="24" height="24" decoding="async">';
            html += '</span>';
        }

        html += '<div class="brief-quality-notice__content">';
        if (title) {
            html += '<p class="brief-quality-notice__title">' + escapeHtml(title) + '</p>';
        }
        if (body) {
            html += '<p class="brief-quality-notice__body">' + escapeHtml(body) + '</p>';
        }

        if (options.showContinue || options.showSwitch) {
            html += '<div class="brief-quality-notice__actions">';
            if (options.showContinue) {
                html += '<button type="button" class="brief-quality-continue">' +
                    escapeHtml(copy.continue || 'Continue anyway') + '</button>';
            }
            if (options.showSwitch) {
                html += '<button type="button" class="brief-quality-switch">' +
                    escapeHtml(copy.switch_filtered || 'Try Filtered Search instead') + '</button>';
            }
            html += '</div>';
        }

        html += '</div></div></div>';
        return html;
    }

    function assessBrief(text) {
        var deferred = $.Deferred();
        var ajaxUrl = (window.ajax_vars && window.ajax_vars.ajax_url) ? window.ajax_vars.ajax_url : '/wp-admin/admin-ajax.php';
        var nonce = window.ajax_vars && window.ajax_vars.brief_quality_nonce;

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'creatordb_assess_brief_quality',
                brief: text,
                nonce: nonce
            }
        }).done(function (resp) {
            if (resp && resp.success && resp.data) {
                deferred.resolve(resp.data);
            } else {
                deferred.reject();
            }
        }).fail(function () {
            deferred.reject();
        });

        return deferred.promise();
    }

    function submitFormNative($form) {
        var el = $form[0];
        if (el) {
            el.submit();
        }
    }

    /**
     *
     * @returns {Promise<boolean>} Resolves true when submit should proceed.
     */
    InfluencerApp.checkBriefQuality = function () {
        var deferred = $.Deferred();
        var $form = getForm();

        if (!$form.length || !isFullBriefMode()) {
            deferred.resolve(true);
            return deferred.promise();
        }

        var $textarea = getBriefTextarea();
        if (!$textarea.length) {
            deferred.resolve(true);
            return deferred.promise();
        }

        var text = ($textarea.val() || '').trim();
        var minLen = (window.ajax_vars && window.ajax_vars.brief_quality_min_chars) || MIN_LEN;

        if (text.length < minLen) {
            var tooShort = {
                quality: 'too_short',
                copy: (window.ajax_vars && window.ajax_vars.brief_quality_copy) || {}
            };
            showNotice(buildNoticeHtml(tooShort, { showSwitch: true }));
            deferred.resolve(false);
            return deferred.promise();
        }

        if (hasAckFor($form, text)) {
            hideNotice();
            deferred.resolve(true);
            return deferred.promise();
        }

        assessBrief(text).done(function (assessment) {
            if (assessment.quality === 'low') {
                showNotice(buildNoticeHtml(assessment, { showContinue: true, showSwitch: true }));
                deferred.resolve(false);
            } else {
                hideNotice();
                deferred.resolve(true);
            }
        }).fail(function () {
            hideNotice();
            deferred.resolve(true);
        });

        return deferred.promise();
    };

    InfluencerApp.initBriefQuality = function () {
        var $form = getForm();
        if (!$form.length) {
            return;
        }

        var $textarea = getBriefTextarea();

        $textarea.on('input', function () {
            clearAck($form);
            hideNotice();
        });

        $form.on('submit', function (e) {
            if (!isFullBriefMode()) {
                return;
            }

            e.preventDefault();

            InfluencerApp.checkBriefQuality().done(function (canSubmit) {
                if (canSubmit) {
                    submitFormNative($form);
                }
            });
        });

        $(document).on('click', '.brief-quality-continue', function (e) {
            e.preventDefault();
            var text = ($textarea.val() || '').trim();
            setAck($form, text);
            hideNotice();
            submitFormNative($form);
        });

        $(document).on('click', '.brief-quality-switch', function (e) {
            e.preventDefault();
            var toggle = $('#my-toggle');
            if (toggle.is(':checked')) {
                toggle.prop('checked', false).trigger('change');
            }
            hideNotice();
            clearAck($form);
            $textarea.val('').trigger('input');
        });
    };

})(jQuery);
