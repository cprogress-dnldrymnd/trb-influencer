/**
 * Welcome popup + step-by-step guided tour for new members. Localized as `dd_onboarding`
 * (see functions.php). Copy comes from the `dd_messages` global (dd_msg_ob_*,
 * includes/core/messages-settings.php) with hardcoded fallbacks, same defensive pattern every
 * other module uses: (typeof dd_messages !== 'undefined' && dd_messages.key) || 'fallback'.
 */
(function () {
    'use strict';

    if (typeof dd_onboarding === 'undefined' || !dd_onboarding.enabled) {
        return;
    }

    function msg(key, fallback) {
        return (typeof dd_messages !== 'undefined' && dd_messages[key]) || fallback;
    }

    function postEvent(event, extra) {
        if (typeof jQuery === 'undefined') {
            return;
        }
        var data = Object.assign({
            action: 'dd_onboarding_state',
            security: dd_onboarding.nonce,
            event: event
        }, extra || {});
        jQuery.post(dd_onboarding.ajax_url, data);
    }

    // ------------------------------------------------------------------
    // Welcome popup
    // ------------------------------------------------------------------

    function closeOverlay(overlay) {
        if (overlay && overlay.parentNode) {
            document.body.removeChild(overlay);
        }
    }

    function showWelcome() {
        var overlay = document.createElement('div');
        overlay.className = 'dd-ob-overlay';

        var box = document.createElement('div');
        box.className = 'dd-ob-box';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'dd-ob-close';
        closeBtn.setAttribute('aria-label', msg('dd_msg_gate_close', 'Close'));
        closeBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/></svg>';
        closeBtn.addEventListener('click', function () {
            closeOverlay(overlay);
            postEvent('dismiss');
        });

        var title = document.createElement('h2');
        title.className = 'dd-ob-title';
        title.textContent = msg('dd_msg_ob_welcome_title', "You're in! Let's find your first creators.");

        var body = document.createElement('p');
        body.className = 'dd-ob-body';
        body.textContent = msg('dd_msg_ob_welcome_body', "Describe your brand and what you're looking for, and we'll match you with creators in seconds. Ready to run your first search?");

        var actions = document.createElement('div');
        actions.className = 'dd-ob-actions';

        var primary = document.createElement('a');
        primary.className = 'dd-ob-btn dd-ob-btn-primary';
        primary.href = dd_onboarding.welcome_url;
        primary.textContent = msg('dd_msg_ob_welcome_cta', 'Start your first search');
        primary.addEventListener('click', function () {
            postEvent('welcome_seen');
        });
        actions.appendChild(primary);

        if (dd_onboarding.show_tour_button) {
            var tourBtn = document.createElement('button');
            tourBtn.type = 'button';
            tourBtn.className = 'dd-ob-btn dd-ob-btn-secondary';
            tourBtn.textContent = msg('dd_msg_ob_tour_cta', 'Show me around first');
            tourBtn.addEventListener('click', function () {
                closeOverlay(overlay);
                startTour();
            });
            actions.appendChild(tourBtn);
        }

        box.appendChild(closeBtn);
        box.appendChild(title);
        box.appendChild(body);
        box.appendChild(actions);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        // Deliberately no postEvent() here — merely displaying the popup must not count as
        // "seen". Only an actual interaction (close, primary CTA, or starting the tour) marks
        // it, so a visitor who closes the tab without touching it gets the popup again next visit.
    }

    // ------------------------------------------------------------------
    // Guided tour
    // ------------------------------------------------------------------

    var tourSteps    = [];
    var tourIndex    = 0;
    var spotlightEl  = null;
    var tooltipEl    = null;

    function visibleSteps() {
        return (dd_onboarding.steps || []).filter(function (step) {
            return step.target && document.querySelector(step.target);
        });
    }

    function formatStepCounter(current, total) {
        var tpl = msg('dd_msg_ob_step_counter', 'Step %s of %s');
        var parts = tpl.split('%s');
        if (parts.length < 3) {
            return 'Step ' + current + ' of ' + total;
        }
        return parts[0] + current + parts[1] + total + parts[2];
    }

    function teardownTour() {
        if (spotlightEl && spotlightEl.parentNode) {
            document.body.removeChild(spotlightEl);
        }
        if (tooltipEl && tooltipEl.parentNode) {
            document.body.removeChild(tooltipEl);
        }
        spotlightEl = null;
        tooltipEl = null;
        window.removeEventListener('resize', positionCurrentStep);
        window.removeEventListener('scroll', positionCurrentStep, true);
    }

    function startTour() {
        tourSteps = visibleSteps();
        if (!tourSteps.length) {
            return;
        }
        tourIndex = 0;
        window.addEventListener('resize', positionCurrentStep);
        window.addEventListener('scroll', positionCurrentStep, true);
        renderStep();
    }

    function finishTour(completed) {
        teardownTour();
        postEvent(completed ? 'tour_completed' : 'dismiss');
    }

    function positionCurrentStep() {
        var step = tourSteps[tourIndex];
        if (!step || !spotlightEl || !tooltipEl) {
            return;
        }
        var target = document.querySelector(step.target);
        if (!target) {
            renderStep();
            return;
        }

        var rect = target.getBoundingClientRect();
        var pad = 6;
        spotlightEl.style.top    = (rect.top - pad) + 'px';
        spotlightEl.style.left   = (rect.left - pad) + 'px';
        spotlightEl.style.width  = (rect.width + pad * 2) + 'px';
        spotlightEl.style.height = (rect.height + pad * 2) + 'px';

        var placement = step.placement || 'bottom';
        var ttWidth   = tooltipEl.offsetWidth || 300;
        var ttHeight  = tooltipEl.offsetHeight || 160;
        var gap       = 14;
        var top, left;

        switch (placement) {
            case 'top':
                top  = rect.top - ttHeight - gap;
                left = rect.left + (rect.width / 2) - (ttWidth / 2);
                break;
            case 'left':
                top  = rect.top + (rect.height / 2) - (ttHeight / 2);
                left = rect.left - ttWidth - gap;
                break;
            case 'right':
                top  = rect.top + (rect.height / 2) - (ttHeight / 2);
                left = rect.right + gap;
                break;
            default:
                top  = rect.bottom + gap;
                left = rect.left + (rect.width / 2) - (ttWidth / 2);
        }

        var margin = 12;
        top  = Math.min(Math.max(top, margin), window.innerHeight - ttHeight - margin);
        left = Math.min(Math.max(left, margin), window.innerWidth - ttWidth - margin);

        tooltipEl.style.top  = top + 'px';
        tooltipEl.style.left = left + 'px';
    }

    function renderTooltipContent(step) {
        tooltipEl.innerHTML = '';

        var counter = document.createElement('p');
        counter.className = 'dd-ob-tooltip__step';
        counter.textContent = formatStepCounter(tourIndex + 1, tourSteps.length);

        var title = document.createElement('p');
        title.className = 'dd-ob-tooltip__title';
        title.textContent = step.title || '';

        var actions = document.createElement('div');
        actions.className = 'dd-ob-tooltip__actions';

        var skipBtn = document.createElement('button');
        skipBtn.type = 'button';
        skipBtn.className = 'dd-ob-link-btn';
        skipBtn.textContent = msg('dd_msg_ob_skip', 'Skip');
        skipBtn.addEventListener('click', function () {
            finishTour(false);
        });

        var nav = document.createElement('div');
        nav.className = 'dd-ob-tooltip__nav';

        if (tourIndex > 0) {
            var backBtn = document.createElement('button');
            backBtn.type = 'button';
            backBtn.className = 'dd-ob-link-btn';
            backBtn.textContent = msg('dd_msg_ob_back', 'Back');
            backBtn.addEventListener('click', function () {
                tourIndex -= 1;
                renderStep();
            });
            nav.appendChild(backBtn);
        }

        var isLast = tourIndex === tourSteps.length - 1;
        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'dd-ob-btn dd-ob-btn-primary';
        nextBtn.textContent = isLast ? msg('dd_msg_ob_done', 'Done') : msg('dd_msg_ob_next', 'Next');
        nextBtn.addEventListener('click', function () {
            if (isLast) {
                finishTour(true);
            } else {
                tourIndex += 1;
                renderStep();
            }
        });
        nav.appendChild(nextBtn);

        actions.appendChild(skipBtn);
        actions.appendChild(nav);

        tooltipEl.appendChild(counter);
        tooltipEl.appendChild(title);

        if (step.body) {
            var body = document.createElement('p');
            body.className = 'dd-ob-tooltip__body';
            body.textContent = step.body;
            tooltipEl.appendChild(body);
        }

        if (step.cta_label && step.cta_url) {
            var ctaLink = document.createElement('a');
            ctaLink.className = 'dd-ob-tooltip__cta';
            ctaLink.href = step.cta_url;
            ctaLink.textContent = step.cta_label;
            ctaLink.style.cssText = 'display:block; margin-bottom:14px; font-size:13px;';
            tooltipEl.appendChild(ctaLink);
        }

        tooltipEl.appendChild(actions);
    }

    function renderStep() {
        var step = tourSteps[tourIndex];
        if (!step) {
            finishTour(true);
            return;
        }

        var target = document.querySelector(step.target);
        if (!target) {
            // The target vanished since the tour started (e.g. a template edit) — skip it
            // rather than stalling the tour on a step that can never render.
            tourSteps.splice(tourIndex, 1);
            renderStep();
            return;
        }

        if (!spotlightEl) {
            spotlightEl = document.createElement('div');
            spotlightEl.className = 'dd-ob-spotlight';
            document.body.appendChild(spotlightEl);
        }
        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'dd-ob-tooltip';
            document.body.appendChild(tooltipEl);
        }

        renderTooltipContent(step);

        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        positionCurrentStep();
        // Re-measure once scrollIntoView's smooth scroll has settled.
        setTimeout(positionCurrentStep, 300);

        postEvent('tour_progress', { step: tourIndex });
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-dd-tour], .dd-start-tour') : null;
        if (trigger) {
            e.preventDefault();
            startTour();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var url = new URL(window.location.href);
        var forceWelcome = url.searchParams.get('dd_welcome') === '1';

        if (forceWelcome) {
            url.searchParams.delete('dd_welcome');
            window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
        }

        if (forceWelcome || !dd_onboarding.state.welcome_seen) {
            showWelcome();
        }
    });
})();
