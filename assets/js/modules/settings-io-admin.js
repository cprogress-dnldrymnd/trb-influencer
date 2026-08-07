/**
 * Admin UI for the Influencer Theme "Export / Import" settings tab (#dd-panel-transfer).
 * Two-step import: analyze (upload + auto-resolve refs, writes nothing) then commit (apply, with
 * any manual overrides the admin entered). Scoped entirely to #dd-panel-transfer and the
 * toplevel_page_dd-theme-settings screen, per the module convention documented in CLAUDE.md.
 */
(function ($) {
    'use strict';

    if (typeof ddSettingsIO === 'undefined') {
        return;
    }

    var GROUP_LABELS = {
        levels: 'Membership Levels',
        pages: 'Pages',
        templates: 'Elementor Templates',
        attachments: 'Platform Icon Images'
    };

    var CONFIDENCE_LABELS = {
        uid: ['matched (linked)', '#2a9d5c'],
        name: ['matched by name', '#b7860b'],
        slug: ['matched by slug', '#b7860b'],
        title: ['matched by title', '#b7860b'],
        filename: ['matched by filename', '#b7860b'],
        fuzzy: ['fuzzy match — verify', '#c0392b']
    };

    function describe(kind, descriptor) {
        if (kind === 'level') {
            var money = (descriptor.billing_amount > 0)
                ? (' — ' + descriptor.initial_payment + '/' + descriptor.billing_amount + ' per ' + descriptor.cycle_number + ' ' + descriptor.cycle_period)
                : ' — free';
            return descriptor.name + money;
        }
        if (kind === 'page') {
            var label = descriptor.title + ' (/' + descriptor.slug + ')';
            if (descriptor.from_default) {
                label += ' [theme default — never explicitly assigned]';
            }
            return label;
        }
        if (kind === 'template') {
            return descriptor.title + ' [' + descriptor.tpl_type + ']';
        }
        return descriptor.title || descriptor.filename || descriptor.uid;
    }

    function badge(confidence) {
        if (!confidence) {
            return '<span style="color:#c0392b;font-weight:600;">unresolved</span>';
        }
        var info = CONFIDENCE_LABELS[confidence] || [confidence, '#666'];
        return '<span style="color:' + info[1] + ';">' + info[0] + '</span>';
    }

    function renderGroup(groupKey, kind, entries) {
        var uids = Object.keys(entries || {});
        if (!uids.length) {
            return '';
        }

        var rows = uids.map(function (uid) {
            var entry = entries[uid];
            var d = entry.descriptor || {};
            var targetId = entry.target_id || '';

            var actionCell = '';
            if (kind === 'page') {
                var placeholder = targetId ? 'target ID (matched)' : 'target ID, or use action ->';
                actionCell = '<input type="number" min="0" class="small-text dd-io-override" ' +
                        'data-group="pages" data-uid="' + uid + '" value="' + targetId + '" placeholder="' + placeholder + '"> ' +
                    '<select class="dd-io-page-action" data-uid="' + uid + '">' +
                        '<option value="">(default)</option>' +
                        '<option value="create">Force create new</option>' +
                        '<option value="skip">Skip entirely</option>' +
                    '</select>';
            } else {
                var ph = (kind === 'template' && !targetId) ? 'will create' : 'target ID';
                actionCell = '<input type="number" min="0" class="small-text dd-io-override" ' +
                    'data-group="' + groupKey + '" data-uid="' + uid + '" ' +
                    'value="' + targetId + '" placeholder="' + ph + '">';
            }

            return '<tr>' +
                '<td>' + $('<div>').text(describe(kind, d)).html() + '</td>' +
                '<td>' + badge(entry.confidence) + '</td>' +
                '<td>' + actionCell + '</td>' +
                '</tr>';
        }).join('');

        var note = (kind === 'level')
            ? '<p class="description">Levels are only matched, never created — enter an existing level ID to override, or leave blank to leave it unresolved.</p>'
            : (kind === 'page')
                ? '<p class="description">Pages are matched by default. "Force create new" ignores any match and always creates a fresh (draft) page; "Skip entirely" leaves this page — and any page-content changes to it — untouched even if "Import page content" is checked below.</p>'
                : (kind === 'template')
                    ? '<p class="description">Blank = create a new template. A matched template is updated in place.</p>'
                    : '';

        return '<h4>' + GROUP_LABELS[groupKey] + '</h4>' + note +
            '<table class="widefat striped" style="max-width:900px;"><thead><tr>' +
            '<th>Source</th><th>Match</th><th>Target / Action</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function renderOptionImpact(optionImpact) {
        var blockedKeys = Object.keys(optionImpact).filter(function (k) {
            return optionImpact[k].blocked;
        });
        if (!blockedKeys.length) {
            return '';
        }

        var rows = blockedKeys.map(function (key) {
            var imp = optionImpact[key];
            return '<tr>' +
                '<td><code>' + key + '</code></td>' +
                '<td>' + imp.unresolved + ' of ' + imp.total + ' references unresolved</td>' +
                '<td><label><input type="checkbox" class="dd-io-accept-partial" data-key="' + key + '"> ' +
                    'Write anyway, dropping unresolved entries</label></td>' +
                '</tr>';
        }).join('');

        return '<h4 style="color:#c0392b;">Blocked options</h4>' +
            '<p class="description">These options will <strong>not</strong> be written unless you tick "write anyway" — writing a shrunken or empty allowed-levels list can silently lock members out of a feature, so this feature refuses to guess. Note: a page reference here may resolve automatically once page content import creates the missing page — this list is computed before that happens, so it can be more pessimistic than the actual result.</p>' +
            '<table class="widefat striped" style="max-width:900px;"><thead><tr>' +
            '<th>Option</th><th>Impact</th><th>Override</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function renderPreview(data) {
        var plan = data.plan;
        var manifest = data.manifest;

        var html = '<div id="dd-io-plan">';
        html += '<p>Bundle from <strong>' + $('<div>').text(manifest.source.site_url).html() + '</strong>, exported ' + manifest.generated + '.</p>';
        html += renderGroup('levels', 'level', plan.refs.levels);
        html += renderGroup('pages', 'page', plan.refs.pages);
        html += renderGroup('templates', 'template', plan.refs.templates);
        html += renderGroup('attachments', 'attachment', plan.refs.attachments);
        html += renderOptionImpact(plan.options);
        html += '<p><button type="button" class="button button-primary" id="dd-io-commit">Apply Import</button> ' +
            '<span class="spinner" id="dd-io-commit-spinner" style="float:none;"></span></p>';
        html += '<div id="dd-io-report"></div>';
        html += '</div>';

        $('#dd-io-preview').html(html);
    }

    function collectOverrides() {
        var overrides = {
            pages: {}, templates: {}, levels: {}, attachments: {}, accept_partial: {},
            import_pages: $('#dd-io-opt-import-pages').is(':checked'),
            create_missing_pages: $('#dd-io-opt-create-pages').is(':checked'),
            publish_created_pages: $('#dd-io-opt-publish-pages').is(':checked'),
            update_hierarchy: $('#dd-io-opt-hierarchy').is(':checked')
        };

        $('.dd-io-override').each(function () {
            var $el = $(this);
            var val = $el.val();
            if (val === '' || val === null) {
                return;
            }
            overrides[$el.data('group')][$el.data('uid')] = parseInt(val, 10);
        });

        // A 'create'/'skip' action directive takes priority over a numeric override for the same page.
        $('.dd-io-page-action').each(function () {
            var $el = $(this);
            var val = $el.val();
            if (val === 'create' || val === 'skip') {
                overrides.pages[$el.data('uid')] = val;
            }
        });

        $('.dd-io-accept-partial:checked').each(function () {
            overrides.accept_partial[$(this).data('key')] = true;
        });

        return overrides;
    }

    function renderReport(report) {
        var html = '<h4>Import complete</h4>';
        html += '<p>Templates: ' + report.templates.created + ' created, ' + report.templates.updated + ' updated' +
            (report.templates.failed ? (', ' + report.templates.failed + ' failed') : '') + '.</p>';

        if (report.pages) {
            html += '<p>Pages: ' + report.pages.created + ' created, ' + report.pages.updated + ' updated, ' +
                report.pages.skipped + ' skipped' +
                (report.pages.failed ? (', ' + report.pages.failed + ' failed') : '') + '.</p>';
            if (report.pages.warnings && report.pages.warnings.length) {
                html += '<p style="color:#b7860b;"><strong>Page warnings:</strong></p><ul style="margin-left:20px;">' +
                    report.pages.warnings.map(function (w) { return '<li>' + $('<div>').text(w).html() + '</li>'; }).join('') +
                    '</ul>';
            }
        }

        if (report.written.length) {
            html += '<p><strong>Written:</strong> ' + report.written.join(', ') + '</p>';
        }
        if (report.warned.length) {
            html += '<p style="color:#b7860b;"><strong>Written with dropped/unresolved entries:</strong> ' + report.warned.join(', ') + '</p>';
        }
        if (report.blocked.length) {
            html += '<p style="color:#c0392b;"><strong>Blocked (not written):</strong> ' + report.blocked.join(', ') + '</p>';
        }
        if (report.snapshot_overwritten) {
            html += '<p style="color:#c0392b;">Note: a prior, unrestored snapshot from an earlier import was overwritten by this one.</p>';
        }
        html += '<p>Reload this page to see the "Restore" section reflect this import’s snapshot.</p>';

        $('#dd-io-report').html(html);
    }

    $(function () {
        var $panel = $('#dd-panel-transfer');
        if (!$panel.length) {
            return;
        }

        $panel.on('click', '#dd-io-analyze', function () {
            var fileInput = document.getElementById('dd-io-file');
            if (!fileInput || !fileInput.files.length) {
                window.ddAlert ? ddAlert('Choose a .zip settings bundle first.') : alert('Choose a .zip settings bundle first.');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'dd_settings_io_analyze');
            formData.append('nonce', ddSettingsIO.nonce);
            formData.append('bundle', fileInput.files[0]);

            $('#dd-io-spinner').addClass('is-active');
            $('#dd-io-preview').empty();

            $.ajax({
                url: ddSettingsIO.ajaxUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false
            }).done(function (res) {
                if (res && res.success) {
                    renderPreview(res.data);
                } else {
                    var msg = (res && res.data && res.data.message) || 'Could not analyze that file.';
                    window.ddAlert ? ddAlert(msg) : alert(msg);
                }
            }).fail(function () {
                var msg = 'Upload failed.';
                window.ddAlert ? ddAlert(msg) : alert(msg);
            }).always(function () {
                $('#dd-io-spinner').removeClass('is-active');
            });
        });

        $panel.on('click', '#dd-io-commit', function () {
            var overrides = collectOverrides();

            var proceed = function () {
                $('#dd-io-commit-spinner').addClass('is-active');
                $('#dd-io-commit').prop('disabled', true);

                $.post(ddSettingsIO.ajaxUrl, {
                    action: 'dd_settings_io_commit',
                    nonce: ddSettingsIO.nonce,
                    overrides: JSON.stringify(overrides)
                }).done(function (res) {
                    if (res && res.success) {
                        renderReport(res.data.report);
                    } else {
                        var msg = (res && res.data && res.data.message) || 'Import failed.';
                        window.ddAlert ? ddAlert(msg) : alert(msg);
                    }
                }).fail(function () {
                    var msg = 'Import failed.';
                    window.ddAlert ? ddAlert(msg) : alert(msg);
                }).always(function () {
                    $('#dd-io-commit-spinner').removeClass('is-active');
                    $('#dd-io-commit').prop('disabled', false);
                });
            };

            if (overrides.create_missing_pages || overrides.publish_created_pages) {
                var msg = overrides.publish_created_pages
                    ? 'This import can create new PUBLISHED pages on this site. Continue?'
                    : 'This import can create new (draft) pages on this site. Continue?';
                if (window.ddConfirm) {
                    ddConfirm(msg, proceed);
                } else if (confirm(msg)) {
                    proceed();
                }
            } else {
                proceed();
            }
        });

        $panel.on('click', '#dd-io-restore', function () {
            var proceed = function () {
                $.post(ddSettingsIO.ajaxUrl, {
                    action: 'dd_settings_io_restore',
                    nonce: ddSettingsIO.nonce
                }).done(function (res) {
                    if (res && res.success) {
                        window.location.reload();
                    } else {
                        var msg = (res && res.data && res.data.message) || 'Restore failed.';
                        window.ddAlert ? ddAlert(msg) : alert(msg);
                    }
                });
            };

            if (window.ddConfirm) {
                ddConfirm('Restore the settings to their pre-import state? This does not undo Elementor template or page changes.', proceed);
            } else if (confirm('Restore the settings to their pre-import state?')) {
                proceed();
            }
        });

        $panel.on('click', '#dd-io-restore-pages', function () {
            var proceed = function () {
                $.post(ddSettingsIO.ajaxUrl, {
                    action: 'dd_settings_io_restore_pages',
                    nonce: ddSettingsIO.nonce
                }).done(function (res) {
                    if (res && res.success) {
                        var r = res.data.result;
                        var msg = 'Restored ' + r.restored + ' page(s), trashed ' + r.trashed + ' created page(s).';
                        window.ddAlert ? ddAlert(msg) : alert(msg);
                    } else {
                        var errMsg = (res && res.data && res.data.message) || 'Restore failed.';
                        window.ddAlert ? ddAlert(errMsg) : alert(errMsg);
                    }
                });
            };

            if (window.ddConfirm) {
                ddConfirm('Restore all pages touched by the most recent page-content import? Pages that import created will be trashed; pages it updated will revert via their revision.', proceed);
            } else if (confirm('Restore all pages touched by the most recent page-content import?')) {
                proceed();
            }
        });
    });
})(jQuery);
