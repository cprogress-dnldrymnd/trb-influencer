/* Tour-step repeater builder for the Onboarding settings tab. All selectors are scoped to
 * #dd-panel-onboarding — several module tabs now share one admin page DOM (see CLAUDE.md's
 * settings-tab-indirection section), so this must never query .dd-tab-content/.nav-tab
 * page-wide. */
jQuery(function ($) {
    'use strict';

    var $panel = $('#dd-panel-onboarding');
    if (!$panel.length) {
        return;
    }

    var $list  = $panel.find('#dd-ob-steps-list');
    var $input = $panel.find('#dd-ob-steps-input');

    var state = (window.dd_onboarding_admin && Array.isArray(dd_onboarding_admin.steps))
        ? dd_onboarding_admin.steps.slice()
        : [];

    function uid() {
        return 'step_' + Math.random().toString(36).slice(2, 9);
    }

    function escapeHtml(str) {
        return $('<div>').text(str || '').html();
    }

    function escapeAttr(str) {
        return String(str || '').replace(/"/g, '&quot;');
    }

    function textField(label, key, value, placeholder) {
        return '<label class="dd-ob-field"><span>' + label + '</span>' +
            '<input type="text" data-key="' + key + '" value="' + escapeAttr(value) + '" placeholder="' + escapeAttr(placeholder || '') + '"></label>';
    }

    function textareaField(label, key, value) {
        return '<label class="dd-ob-field dd-ob-field--wide"><span>' + label + '</span>' +
            '<textarea data-key="' + key + '" rows="2">' + escapeHtml(value) + '</textarea></label>';
    }

    function selectField(label, key, value, options) {
        var html = '<label class="dd-ob-field"><span>' + label + '</span><select data-key="' + key + '">';
        options.forEach(function (opt) {
            html += '<option value="' + opt + '"' + (opt === value ? ' selected' : '') + '>' + opt + '</option>';
        });
        html += '</select></label>';
        return html;
    }

    function cardHtml(step, index) {
        var title = step.title ? ': ' + escapeHtml(step.title) : '';
        return (
            '<div class="dd-ob-card" data-index="' + index + '">' +
                '<div class="dd-ob-card__header">' +
                    '<span class="dd-ob-drag" title="Drag to reorder">☰</span>' +
                    '<strong class="dd-ob-card__title">Step ' + (index + 1) + title + '</strong>' +
                    '<span class="dd-ob-card__actions">' +
                        '<button type="button" class="button-link dd-ob-remove">Remove</button>' +
                    '</span>' +
                '</div>' +
                '<div class="dd-ob-card__body">' +
                    textField('Title', 'title', step.title) +
                    selectField('Show On', 'page', step.page || 'any', ['any', 'dashboard', 'search', 'results']) +
                    textareaField('Body', 'body', step.body) +
                    textField('Target CSS Selector', 'target', step.target, '#search-header') +
                    selectField('Placement', 'placement', step.placement || 'bottom', ['top', 'bottom', 'left', 'right']) +
                    textField('Button Label (optional)', 'cta_label', step.cta_label) +
                    textField('Button URL (optional)', 'cta_url', step.cta_url) +
                '</div>' +
            '</div>'
        );
    }

    function syncInput() {
        $input.val(JSON.stringify(state));
    }

    function initSortable() {
        if (!$.fn.sortable) {
            return;
        }
        $list.sortable({
            handle: '.dd-ob-drag',
            axis: 'y',
            update: function () {
                var reordered = [];
                $list.find('.dd-ob-card').each(function () {
                    reordered.push(state[$(this).data('index')]);
                });
                state = reordered;
                renderAll();
            }
        });
    }

    function renderAll() {
        $list.empty();
        if (!state.length) {
            $list.append('<p class="description">No steps yet — click "Add Step" to create the first one.</p>');
        } else {
            state.forEach(function (step, index) {
                $list.append(cardHtml(step, index));
            });
        }
        syncInput();
        initSortable();
    }

    $panel.on('click', '#dd-ob-add-step', function () {
        state.push({ id: uid(), title: '', body: '', target: '', placement: 'bottom', page: 'any', cta_label: '', cta_url: '' });
        renderAll();
    });

    $panel.on('click', '#dd-ob-restore-steps', function () {
        var defaults = (window.dd_onboarding_admin && Array.isArray(dd_onboarding_admin.defaults))
            ? dd_onboarding_admin.defaults
            : [];

        if (!defaults.length) {
            return;
        }

        var restore = function () {
            // Deep copy — a shallow slice() would share step objects with the localized
            // payload, so editing a restored step then restoring again would hand back the
            // edited object instead of the default.
            state = defaults.map(function (step) {
                return $.extend({}, step);
            });
            renderAll();
        };

        if (!state.length) {
            restore();
            return;
        }

        var msg = 'Replace the current ' + state.length + ' step(s) with the ' + defaults.length +
            ' default steps? This takes effect when you save.';

        if (window.ddConfirm) {
            ddConfirm(msg, restore);
        } else if (window.confirm(msg)) {
            restore();
        }
    });

    $panel.on('click', '.dd-ob-remove', function () {
        var index = $(this).closest('.dd-ob-card').data('index');
        state.splice(index, 1);
        renderAll();
    });

    $panel.on('input change', '.dd-ob-card [data-key]', function () {
        var index = $(this).closest('.dd-ob-card').data('index');
        var key   = $(this).data('key');
        if (!state[index]) {
            return;
        }
        state[index][key] = $(this).val();
        syncInput();

        if (key === 'title') {
            var title = state[index].title ? ': ' + state[index].title : '';
            $(this).closest('.dd-ob-card').find('.dd-ob-card__title').text('Step ' + (index + 1) + title);
        }
    });

    $panel.find('#dd-onboarding-form').on('submit', syncInput);

    renderAll();
});
