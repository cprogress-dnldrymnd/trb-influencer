(function ($) {
    'use strict';

    // Lightweight non-blocking modal helpers
    function ddAlert(msg) {
        var overlay = $('<div>').css({position:'fixed',top:'0',left:'0',right:'0',bottom:'0',background:'rgba(0,0,0,0.5)',zIndex:99999,display:'flex',alignItems:'center',justifyContent:'center'});
        var box = $('<div>').css({background:'#fff',borderRadius:'8px',padding:'28px 32px',maxWidth:'420px',width:'90%',boxShadow:'0 4px 24px rgba(0,0,0,0.18)',fontFamily:'inherit'});
        var p = $('<p>').css({margin:'0 0 20px',fontSize:'15px',lineHeight:'1.5'}).text(msg);
        var btn = $('<button>').attr('type','button').css({background:'#1a1a1a',color:'#fff',border:'none',borderRadius:'6px',padding:'10px 24px',fontSize:'14px',cursor:'pointer'}).text('OK');
        btn.on('click', function() { overlay.remove(); });
        box.append(p, btn); overlay.append(box); $('body').append(overlay); btn.focus();
    }

    function ddConfirm(msg, onOk) {
        var overlay = $('<div>').css({position:'fixed',top:'0',left:'0',right:'0',bottom:'0',background:'rgba(0,0,0,0.5)',zIndex:99999,display:'flex',alignItems:'center',justifyContent:'center'});
        var box = $('<div>').css({background:'#fff',borderRadius:'8px',padding:'28px 32px',maxWidth:'420px',width:'90%',boxShadow:'0 4px 24px rgba(0,0,0,0.18)',fontFamily:'inherit'});
        var p = $('<p>').css({margin:'0 0 20px',fontSize:'15px',lineHeight:'1.5'}).text(msg);
        var row = $('<div>').css({display:'flex',gap:'12px',justifyContent:'flex-end'});
        var cancelBtn = $('<button>').attr('type','button').css({background:'#e5e7eb',color:'#333',border:'none',borderRadius:'6px',padding:'10px 20px',fontSize:'14px',cursor:'pointer'}).text('Cancel');
        var okBtn = $('<button>').attr('type','button').css({background:'#1a1a1a',color:'#fff',border:'none',borderRadius:'6px',padding:'10px 24px',fontSize:'14px',cursor:'pointer'}).text('Confirm');
        cancelBtn.on('click', function() { overlay.remove(); });
        okBtn.on('click', function() { overlay.remove(); onOk(); });
        row.append(cancelBtn, okBtn); box.append(p, row); overlay.append(box); $('body').append(overlay); okBtn.focus();
    }

    jQuery(document).ready(function ($) {

        // Core UI State Variables
        var currentStatusFilter = 'all';

        // --- 1. Master-Detail List Click Loader ---
        function bindListItemClicks() {
            $('.dd-outreach-item').off('click').on('click', function (e) {

                if ($(e.target).closest('.dd-item-dots').length) {
                    return;
                }

                var isHumanClick = e.originalEvent !== undefined;
                var postId = $(this).data('post-id');
                var container = $('#dd-outreach-view-container');

                if ($(window).width() < 1025 && !isHumanClick) {
                    return;
                }

                $('.dd-outreach-item').removeClass('active-item');
                $(this).addClass('active-item');

                if ($(window).width() < 1025) {
                    container.addClass('dd-modal-active');
                    $('body').css('overflow', 'hidden');
                }

                container.html('<div class="dd-modal-content-wrapper"><span class="dd-view-placeholder">Loading...</span></div>');

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_get_outreach_details',
                        security: ddOutreach.nonce,
                        post_id: postId
                    },
                    success: function (response) {
                        if (response.success) {
                            container.html(response.data);
                        } else {
                            container.html('<div class="dd-modal-content-wrapper">' + (response.data || '<span class="dd-view-error">Error loading details.</span>') + '</div>');
                        }
                    },
                    error: function () {
                        container.html('<div class="dd-modal-content-wrapper"><span class="dd-view-error">Failed to load details. Please try again.</span></div>');
                    }
                });
            });
        }

        bindListItemClicks();
        var firstItem = $('.dd-outreach-item').first();
        if (firstItem.length) {
            firstItem.trigger('click');
        } else {
            $('#dd-outreach-view-container').html('<span class="dd-view-placeholder">No outreach found.</span>');
            $('#no-outreach-found').removeClass('hide-element');
            $('#outreach-found').addClass('hide-element');
        }

        // --- Modal Destruction Handlers (< 1025px) ---
        $(document).on('click', '#dd-close-modal', function (e) {
            e.preventDefault();
            $('#dd-outreach-view-container').removeClass('dd-modal-active');
            $('body').css('overflow', '');
        });

        $(document).on('click', '#dd-outreach-view-container', function (e) {
            if ($(window).width() < 1025 && e.target === this) {
                $(this).removeClass('dd-modal-active');
                $('body').css('overflow', '');
            }
        });

        // --- 2. Filtering Logic ---
        var filterTimer;

        function triggerFilter() {
            var searchQuery = $('#dd-outreach-search').val();

            var selectedTypes = [];
            $('input[name="project_type[]"]:checked').each(function () {
                selectedTypes.push($(this).attr('data-label') || $(this).val());
            });
            if (selectedTypes.length === 0 && $('select[name="project_type"]').length > 0 && $('select[name="project_type"]').val() !== '') {
                selectedTypes.push($('select[name="project_type"]').val());
            }

            var selectedLengths = [];
            $('input[name="project_length[]"]:checked').each(function () {
                selectedLengths.push($(this).attr('data-label') || $(this).val());
            });
            if (selectedLengths.length === 0 && $('select[name="project_length"]').length > 0 && $('select[name="project_length"]').val() !== '') {
                selectedLengths.push($('select[name="project_length"]').val());
            }

            $('#dd-outreach-list-container').html('<p style="padding: 20px; text-align:center;">Loading...</p>');

            $.ajax({
                url: ddOutreach.ajax_url,
                type: 'POST',
                data: {
                    action: 'dd_filter_outreach_list',
                    security: ddOutreach.nonce,
                    search: searchQuery,
                    project_type: selectedTypes,
                    project_length: selectedLengths,
                    status_filter: currentStatusFilter
                },
                success: function (response) {
                    if (response.success) {
                        $('#dd-outreach-list-container').html(response.data);
                        bindListItemClicks();

                        var newFirstItem = $('.dd-outreach-item').first();
                        if (newFirstItem.length) {
                            newFirstItem.trigger('click');
                        } else {
                            $('#dd-outreach-view-container').html('<span class="dd-view-placeholder">No outreach found matching your criteria.</span>');
                        }
                    }
                },
                error: function () {
                    $('#dd-outreach-list-container').html('<p style="padding:20px;text-align:center;color:#c00;">Failed to load results. Please try again.</p>');
                }
            });
        }

        $('#dd-outreach-search').on('keyup', function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(triggerFilter, 500);
        });

        $(document).on('change', 'input[name="project_type[]"], select[name="project_type"], input[name="project_length[]"], select[name="project_length"]', function () {
            triggerFilter();
        });

        // Status Navigation Handlers (All, Favourites, Archived)
        $(document).on('click', '.dd-status-pill, .dd-archive-link', function (e) {
            e.preventDefault();
            $('.dd-status-pill, .dd-archive-link').removeClass('active');
            $(this).addClass('active');

            if ($(this).hasClass('dd-status-pill')) {
                $('.dd-archive-link').css('font-weight', '500');
            } else {
                $(this).css('font-weight', 'bold');
            }

            currentStatusFilter = $(this).data('status');
            triggerFilter();
        });

        $(document).on('click', '.reset-btn, .tag-close', function (e) {
            e.preventDefault();
            triggerFilter();
        });

        // --- 3. 3-Dot Action Menu ---
        $(document).on('click', '.dd-action-toggle', function (e) {
            e.preventDefault();
            $('.dd-action-menu').not($(this).next('.dd-action-menu')).hide();
            $(this).next('.dd-action-menu').toggle();
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.dd-item-dots').length) {
                $('.dd-action-menu').hide();
            }
        });

        $(document).on('click', '.dd-action-btn', function (e) {
            e.preventDefault();
            var btn = $(this);
            var postId = btn.data('id');
            var action = btn.data('action');

            $.ajax({
                url: ddOutreach.ajax_url,
                type: 'POST',
                data: {
                    action: 'dd_toggle_outreach_status',
                    security: ddOutreach.nonce,
                    post_id: postId,
                    toggle_action: action
                },
                success: function (res) {
                    if (res.success) {
                        if (res.data && res.data.archived_count !== undefined) {
                            $('#dd-archive-count-badge').text(res.data.archived_count);
                        }
                        $('.dd-action-menu').hide();
                        triggerFilter();
                    }
                },
                error: function () {
                    $('.dd-action-menu').hide();
                    btn.closest('.dd-outreach-item').after('<p style="padding:4px 12px;color:#c00;font-size:13px;">Action failed. Please try again.</p>');
                }
            });
        });

        // --- 4. Note CRUD Event Delegation ---
        var viewContainer = $('#dd-outreach-view-container');

        viewContainer.on('click', '#dd-save-note', function (e) {
            e.preventDefault();
            var btn = $(this);
            var postId = btn.data('post-id');
            var noteId = $('#dd-note-input-id').val();
            var title = $('#dd-note-input-title').val();
            var content = $('#dd-note-input-content').val();

            if (!content.trim()) {
                ddAlert('Please enter note content before saving.');
                return;
            }

            btn.text('SAVING...').prop('disabled', true);

            $.ajax({
                url: ddOutreach.ajax_url,
                type: 'POST',
                data: {
                    action: 'dd_save_outreach_note',
                    security: ddOutreach.nonce,
                    post_id: postId,
                    note_id: noteId,
                    note_title: title,
                    note_content: content
                },
                success: function (res) {
                    btn.html('<svg xmlns="http://www.w3.org/2000/svg" width="12.832" height="16.332" viewBox="0 0 12.832 16.332"> <path id="saved" fill="currentColor" d="M26.125,10.333V22a.583.583,0,0,1-.583.583h-.083a.584.584,0,0,1-.416-.174l-4.167-4.243-4.167,4.243a.583.583,0,0,1-.416.174h-.083A.583.583,0,0,1,15.625,22V10.333a1.752,1.752,0,0,1,1.75-1.75h7a1.752,1.752,0,0,1,1.75,1.75ZM25.541,6.25h-7a.583.583,0,0,0,0,1.167h7a1.752,1.752,0,0,1,1.75,1.75V18.5a.583.583,0,1,0,1.167,0V9.166A2.92,2.92,0,0,0,25.541,6.25Z" transform="translate(-15.625 -6.25)" /> </svg> SAVE NOTE').prop('disabled', false);
                    if (res.success) {
                        $('#dd-notes-list-wrapper').html(res.data);
                        $('#dd-cancel-edit-note').trigger('click');
                    } else {
                        ddAlert('An error occurred while saving the note.');
                    }
                },
                error: function () {
                    btn.html('<svg xmlns="http://www.w3.org/2000/svg" width="12.832" height="16.332" viewBox="0 0 12.832 16.332"> <path id="saved" fill="currentColor" d="M26.125,10.333V22a.583.583,0,0,1-.583.583h-.083a.584.584,0,0,1-.416-.174l-4.167-4.243-4.167,4.243a.583.583,0,0,1-.416.174h-.083A.583.583,0,0,1,15.625,22V10.333a1.752,1.752,0,0,1,1.75-1.75h7a1.752,1.752,0,0,1,1.75,1.75ZM25.541,6.25h-7a.583.583,0,0,0,0,1.167h7a1.752,1.752,0,0,1,1.75,1.75V18.5a.583.583,0,1,0,1.167,0V9.166A2.92,2.92,0,0,0,25.541,6.25Z" transform="translate(-15.625 -6.25)" /> </svg> SAVE NOTE').prop('disabled', false);
                    viewContainer.find('#dd-notes-list-wrapper').before('<p style="color:#c00;font-size:13px;padding:4px 0;">Failed to save note. Please try again.</p>');
                }
            });
        });

        viewContainer.on('click', '.dd-edit-note', function (e) {
            e.preventDefault();
            var card = $(this).closest('.dd-steps-card');
            var noteId = $(this).data('note-id');
            var currentTitle = card.find('.dd-display-note-title').text();
            var currentContent = card.find('.dd-raw-note-content').val();

            $('#dd-note-input-id').val(noteId);
            $('#dd-note-input-title').val(currentTitle);
            $('#dd-note-input-content').val(currentContent);

            $('#dd-note-form-heading').text('✏️ Edit Note');
            $('#dd-cancel-edit-note').show();
            $('#dd-note-input-content').focus();
        });

        viewContainer.on('click', '#dd-cancel-edit-note', function (e) {
            e.preventDefault();
            $('#dd-note-input-id').val('');
            $('#dd-note-input-title').val('');
            $('#dd-note-input-content').val('');
            $('#dd-note-form-heading').text('🗒️ Create a note for this project');
            $(this).hide();
        });

        viewContainer.on('click', '.dd-delete-note', function (e) {
            e.preventDefault();
            var btn = $(this);
            var postId = btn.data('post-id');
            var noteId = btn.data('note-id');

            ddConfirm('Are you sure you want to permanently delete this note?', function () {
                btn.text('DELETING...').prop('disabled', true);

                $.ajax({
                    url: ddOutreach.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dd_delete_outreach_note',
                        security: ddOutreach.nonce,
                        post_id: postId,
                        note_id: noteId
                    },
                    success: function (res) {
                        if (res.success) {
                            $('#dd-notes-list-wrapper').html(res.data);
                            if ($('#dd-note-input-id').val() === noteId) {
                                $('#dd-cancel-edit-note').trigger('click');
                            }
                        }
                    },
                    error: function () {
                        btn.text('DELETE').prop('disabled', false);
                        viewContainer.find('#dd-notes-list-wrapper').before('<p style="color:#c00;font-size:13px;padding:4px 0;">Failed to delete note. Please try again.</p>');
                    }
                });
            });
        });

        // --- 5. Dynamic Message Preview Logic (Elementor Popup Safe) ---
        function updateMessagePreview() {
            var previewDiv = $('#dd-outreach-message-preview');
            if (!previewDiv.length) return;

            var rawTemplateData = previewDiv.attr('data-template');
            if (!rawTemplateData) return;

            var rawTemplate = '';
            try {
                rawTemplate = JSON.parse(rawTemplateData);
            } catch (e) {
                return;
            }

            var projectType   = $('[name="form_fields[project_type]"]').val() || 'N/A';
            var projectLength = $('[name="form_fields[project_length]"]').val() || 'N/A';
            var projectDates  = $('[name="form_fields[project_dates]"]').val() || 'Flexible';
            var budgetRange   = $('[name="form_fields[budget_range]"]').val() || $('[name="form_fields[budget]"]').val() || 'To be discussed';

            var tagStyle = 'background-color:#d1fae5;border:1px solid #0f766e;color:#034146;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:500;display:inline-block !important;margin:2px;';

            var tagsHtml = '<div class="tags-container">' +
                '<div class="tag" style="' + tagStyle + '"><strong>Project type :</strong> ' + projectType + '</div>' +
                '<div class="tag" style="' + tagStyle + '"><strong>Project length :</strong> ' + projectLength + '</div>' +
                '<div class="tag" style="' + tagStyle + '"><strong>Project Dates :</strong> ' + projectDates + '</div>' +
                '<div class="tag" style="' + tagStyle + '"><strong>Budget : </strong> ' + budgetRange + '</div>' +
                '</div>';

            var compiled = rawTemplate.replace(/[\r\n]*\{\{fields\}\}[\r\n]*/g, '<br><br>' + tagsHtml + '<br><br>');
            compiled = compiled.replace(/\{project_type\}/g, projectType);
            compiled = compiled.replace(/(?:\r\n|\r|\n)/g, '<br>');

            previewDiv.html(compiled);
        }

        $(document).on('change input', 'form.elementor-form select, form.elementor-form input', function () {
            updateMessagePreview();
        });

        $(document).on('elementor/popup/show', function () {
            setTimeout(updateMessagePreview, 100);
        });

        setTimeout(updateMessagePreview, 300);

    });

})(jQuery);
