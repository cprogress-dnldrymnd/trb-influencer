jQuery(document).ready(function($) {

    function display_mycred_notice(html) {
        var $n = $('<div class="notice-wrap"><div class="notice-item-wrapper"><div class="notice-item succes">' + html + '</div></div></div>');
        $('body').append($n);
        $n.fadeIn(300);
        setTimeout(function() {
            $n.fadeOut(300, function() {
                $(this).remove();
            });
        }, 4000);
    }

    // Global State
    let state = {
        influencerId: null,
        triggerBtn: null,
        groups: [],
        activeIds: [],
        entryPoint: '',
        viewingGroupId: null,
        viewingGroupName: ''
    };

    function switchModalView(viewId) {
        $('.inf-modal-content').removeClass('active-view');
        $('#' + viewId).addClass('active-view');
        $('#inf-modal-overlay').css('display', 'flex');
    }
    $('.inf-close-modal').on('click', function() {
        $('#inf-modal-overlay').hide();
    });
    $('#inf-modal-overlay').on('click', function(e) {
        if (e.target === this) $(this).hide();
    });

    function renderGroupsList() {
        let html = '';
        if (state.groups.length === 0) {
            html = '<p style="font-size:13px; color:#666;">No groups found. Create one below.</p>';
        } else {
            state.groups.forEach(function(g) {
                let checked = state.activeIds.includes(g.id) ? 'checked' : '';
                html += `
                    <div class="inf-list-item">
                        <div class="inf-list-item-left">
                            <input type="checkbox" id="chk_${g.id}" value="${g.id}" class="inf-list-checkbox" ${checked}>
                            <label for="chk_${g.id}">${g.name}</label>
                        </div>
                        <button type="button" class="inf-btn-icon inf-trigger-edit-group" data-id="${g.id}" data-name="${g.name}" data-desc="${g.desc}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                        </button>
                    </div>
                `;
            });
        }
        $('#inf-lists-wrapper').html(html);
    }

    // 1. Influencer Saving Flow (Opens the Group selection modal)
    $(document).on('click', '.save-influencer-trigger', function(e) {
        e.preventDefault();
        state.triggerBtn = $(this);
        state.influencerId = state.triggerBtn.attr('influencer-id');
        state.entryPoint = 'influencer';

        $('#inf-lists-wrapper').html('<div class="inf-modal-loading">Fetching your saved lists…</div>');
        switchModalView('inf-view-manage');

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'get_influencer_modal_data',
                security: ajax_vars.save_influencer_nonce,
                influencer_id: state.influencerId
            },
            success: function(res) {
                if (res.success) {
                    state.groups = res.data.all_groups;
                    state.activeIds = res.data.active_lists;
                    renderGroupsList();
                    switchModalView('inf-view-manage');
                } else {
                    window.ddAlert('Error: ' + res.data.message);
                }
            }
        });
    });

    // Save Influencer Selection & Display Updated Count dynamically
    $('#inf-modal-save-influencer').on('click', function() {
        let selected = [];
        $('.inf-list-checkbox:checked').each(function() {
            selected.push($(this).val());
        });

        let $btn = $(this);
        let ogBtnText = $btn.text();
        $btn.text('Processing...').prop('disabled', true);

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'save_influencer_to_lists',
                security: ajax_vars.save_influencer_nonce,
                influencer_id: state.influencerId,
                lists: selected
            },
            success: function(res) {
                if (res.success) {

                    $('#inf-modal-overlay').hide();

                    // Show custom notice for standard saves or non-single page unlocks
                    if (res.data.notice_html) display_mycred_notice(res.data.notice_html);

                    let $text = state.triggerBtn.find('.elementor-button-text');
                    let $icon = state.triggerBtn.find('.elementor-button-icon');

                    $icon.html('<svg aria-hidden="true" class="e-font-icon-svg e-fas-bookmark" viewBox="0 0 384 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 512V48C0 21.49 21.49 0 48 0h288c26.51 0 48 21.49 48 48v464L192 400 0 512z"></path></svg>');
                    state.triggerBtn.removeAttr('data-locked');

                    if (res.data.status === 'saved') {
                        $text.text('SAVED(' + res.data.count + ')');
                        state.triggerBtn.addClass('delete-save');
                    } else {
                        $text.text('SAVE');
                        state.triggerBtn.removeClass('delete-save');
                    }
                } else {
                    // Graceful handling for insufficient credits redirect
                    if (res.data.action === 'redirect') {
                        $btn.text('Redirecting...');
                        window.location.href = res.data.url;
                        return;
                    } else {
                        window.ddAlert(res.data.message);
                    }
                }
                $btn.text(ogBtnText).prop('disabled', false);
            },
            error: function() {
                window.ddAlert('A server error occurred. Please try again.');
                $btn.text(ogBtnText).prop('disabled', false);
            }
        });
    });

    // 2. Group Edit / Create Flow
    $('#inf-btn-go-create').on('click', function() {
        $('#inf-edit-id, #inf-edit-name, #inf-edit-desc').val('');
        $('#inf-btn-back-manage').css('display', 'flex');
        $('#inf-edit-modal-title').text('Create New Group');
        switchModalView('inf-view-edit');
    });

    $(document).on('click', '.inf-shortcode-add-group', function() {
        state.entryPoint = 'shortcode';
        $('#inf-edit-id, #inf-edit-name, #inf-edit-desc').val('');
        $('#inf-btn-back-manage').hide();
        $('#inf-edit-modal-title').text('Create New Group');
        switchModalView('inf-view-edit');
    });

    $(document).on('click', '.inf-trigger-edit-group', function(e) {
        e.stopPropagation();
        $('#inf-edit-id').val($(this).attr('data-id'));
        $('#inf-edit-name').val($(this).attr('data-name'));
        $('#inf-edit-desc').val($(this).attr('data-desc'));
        $('#inf-edit-modal-title').text('Edit Group');

        if (state.entryPoint === 'influencer') {
            $('#inf-btn-back-manage').css('display', 'flex');
        } else {
            $('#inf-btn-back-manage').css('display', 'none');
        }

        switchModalView('inf-view-edit');
    });

    $('#inf-btn-back-manage').on('click', function() {
        switchModalView('inf-view-manage');
    });

    $('#inf-modal-save-group').on('click', function() {
        let id = $('#inf-edit-id').val();
        let name = $('#inf-edit-name').val().trim();
        let desc = $('#inf-edit-desc').val().trim();
        if (!name) {
            window.ddAlert("Group name is required.");
            return;
        }
        let $btn = $(this);
        $btn.text('Saving...').prop('disabled', true);

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'upsert_influencer_group',
                security: ajax_vars.save_influencer_nonce,
                group_id: id,
                name: name,
                desc: desc
            },
            success: function(res) {
                if (res.success) {
                    let newGrp = res.data.group;
                    if (state.entryPoint === 'influencer') {
                        let idx = state.groups.findIndex(g => g.id === newGrp.id);
                        if (idx > -1) state.groups[idx] = newGrp;
                        else {
                            state.groups.push(newGrp);
                            state.activeIds.push(newGrp.id);
                        }
                        renderGroupsList();
                        switchModalView('inf-view-manage');
                    } else {
                        let $card = $('#card-' + newGrp.id);
                        if ($card.length) {
                            $card.find('.inf-group-title').text(newGrp.name);

                            // Update the Description DOM element properly
                            let $desc = $card.find('.inf-group-desc');
                            if (newGrp.desc) {
                                $desc.text(newGrp.desc).show();
                            } else {
                                $desc.hide();
                            }

                            $card.find('.inf-trigger-edit-group').attr('data-name', newGrp.name).attr('data-desc', newGrp.desc);
                            $card.find('.view-group-influencers-trigger').attr('data-group-name', newGrp.name);
                        } else {
                            location.reload();
                        }
                        $('#inf-modal-overlay').hide();
                    }
                } else {
                    window.ddAlert(res.data.message);
                }
                $btn.text('Save').prop('disabled', false);
            }
        });
    });

    // 3. Shortcode Interactions
    $('.inf-groups-grid').on('click', '.inf-trigger-edit-group', function() {
        state.entryPoint = 'shortcode';
    });

    $(document).on('click', '.inf-trigger-dropdown', function(e) {
        e.stopPropagation();
        $('.inf-dropdown-wrapper').removeClass('active');
        $(this).closest('.inf-dropdown-wrapper').toggleClass('active');
    });
    $(document).click(function() {
        $('.inf-dropdown-wrapper').removeClass('active');
    });

    // Show/hide the Export PDF button depending on whether the group has any saved influencers
    function updateExportPdfVisibility() {
        var hasInfluencers = $('#inf-view-group-body .inf-loop-item-row').length > 0;
        $('#inf-export-group-pdf').toggle(hasInfluencers);
    }

    $(document).on('click', '.view-group-influencers-trigger', function() {
        let id = $(this).attr('data-group-id');
        let name = $(this).attr('data-group-name');
        state.viewingGroupId = id;
        state.viewingGroupName = name;
        $('#inf-view-group-title').text(name);
        $('#inf-view-group-body').html('<div style="text-align:center; padding:20px;">LOADING INFLUENCERS...</div>');
        switchModalView('inf-view-influencers');

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'get_group_influencers',
                security: ajax_vars.save_influencer_nonce,
                group_id: id
            },
            success: function(res) {
                if (res.success) {
                    $('#inf-view-group-body').html(res.data.html);
                    injectBulkCheckboxes();
                } else {
                    $('#inf-view-group-body').html('<div class="inf-alert">' + res.data.message + '</div>');
                }
                updateExportPdfVisibility();
            }
        });
    });

    // Inject checkboxes into each influencer row for bulk selection
    function injectBulkCheckboxes() {
        var $body = $('#inf-view-group-body');

        // Remove any leftover bulk bar from previous view
        $('#inf-bulk-action-bar').remove();

        // Prepend bulk action bar (hidden until a selection is made)
        $body.before(
            '<div id="inf-bulk-action-bar" style="display:none;align-items:center;gap:12px;padding:10px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:12px;">' +
                '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">' +
                    '<input type="checkbox" id="inf-bulk-select-all"> Select all' +
                '</label>' +
                '<button type="button" id="inf-bulk-remove-btn" class="inf-btn" style="margin-left:auto;">Remove selected (<span id="inf-bulk-count">0</span>)</button>' +
            '</div>'
        );

        // Inject checkbox into each row
        $body.find('.inf-loop-item-row').each(function() {
            var influencerId = $(this).find('.inf-remove-from-group-trigger').attr('data-influencer-id') ||
                               $(this).find('[data-influencer-id]').first().attr('data-influencer-id');
            if (influencerId && !$(this).find('.inf-bulk-check').length) {
                $(this).prepend(
                    '<label class="inf-bulk-check-label" style="display:flex;align-items:center;padding:8px;cursor:pointer;">' +
                        '<input type="checkbox" class="inf-bulk-check" data-influencer-id="' + influencerId + '">' +
                    '</label>'
                );
            }
        });

        // Show the bar if there are any rows
        if ($body.find('.inf-loop-item-row').length > 0) {
            $('#inf-bulk-action-bar').css('display', 'flex');
        }
    }

    // Update "Remove selected (N)" count on checkbox change
    $(document).on('change', '.inf-bulk-check', function() {
        var count = $('.inf-bulk-check:checked').length;
        $('#inf-bulk-count').text(count);
        var allChecked = count > 0 && count === $('.inf-bulk-check').length;
        $('#inf-bulk-select-all').prop('checked', allChecked).prop('indeterminate', count > 0 && !allChecked);
    });

    // Select all toggle
    $(document).on('change', '#inf-bulk-select-all', function() {
        var checked = $(this).is(':checked');
        $('.inf-bulk-check').prop('checked', checked);
        $('#inf-bulk-count').text(checked ? $('.inf-bulk-check').length : 0);
    });

    // Bulk remove submit
    $(document).on('click', '#inf-bulk-remove-btn', function() {
        var ids = [];
        $('.inf-bulk-check:checked').each(function() {
            ids.push($(this).attr('data-influencer-id'));
        });

        if (ids.length === 0) return;

        var $btn = $(this);
        $btn.text('Removing...').prop('disabled', true);

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'bulk_remove_from_group',
                security: ajax_vars.save_influencer_nonce,
                group_id: state.viewingGroupId,
                influencer_ids: ids
            },
            success: function(res) {
                $btn.html('Remove selected (<span id="inf-bulk-count">0</span>)').prop('disabled', false);
                if (res.success) {
                    $.each(res.data.removed, function(i, influencerId) {
                        var $row = $('.inf-bulk-check[data-influencer-id="' + influencerId + '"]').closest('.inf-loop-item-row');
                        $row.fadeOut(300, function() { $(this).remove(); });
                    });
                    setTimeout(function() {
                        if ($('#inf-view-group-body .inf-loop-item-row').length === 0) {
                            $('#inf-bulk-action-bar').remove();
                            $('#inf-view-group-body').html('<div class="inf-alert" style="margin:20px;">No creators remain in this group.</div>');
                            updateExportPdfVisibility();
                        } else {
                            $('#inf-bulk-select-all').prop('checked', false).prop('indeterminate', false);
                            $('#inf-bulk-count').text('0');
                        }
                    }, 350);
                }
            },
            error: function() {
                $btn.html('Remove selected (<span id="inf-bulk-count">0</span>)').prop('disabled', false);
                $('#inf-view-group-body').prepend('<div class="inf-alert" style="color:#c00;margin-bottom:12px;">Bulk remove failed. Please try again.</div>');
            }
        });
    });

    $(document).on('click', '#inf-export-group-pdf', function() {
        if (!state.viewingGroupId) {
            window.ddAlert('No group selected.');
            return;
        }

        const $btn = $(this);
        if ($btn.attr('data-growth-plan') !== '1') {
            const upgradeUrl = $btn.attr('data-upgrade-url');
            window.ddConfirm('Exporting saved lists to PDF is available on the Growth plan. Upgrade your plan to unlock this feature.', function() {
                if (upgradeUrl) window.location.href = upgradeUrl;
            });
            return;
        }

        const form = $('<form>', {
            method: 'POST',
            action: ajax_vars.ajax_url
        });

        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'creatordb_export_saved_list_pdf'
        }));
        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: ajax_vars.export_pdf_nonce || ''
        }));
        form.append($('<input>', {
            type: 'hidden',
            name: 'security',
            value: ajax_vars.save_influencer_nonce || ''
        }));
        form.append($('<input>', {
            type: 'hidden',
            name: 'group_id',
            value: state.viewingGroupId
        }));
        form.append($('<input>', {
            type: 'hidden',
            name: 'list_name',
            value: state.viewingGroupName || ''
        }));

        $('body').append(form);
        form.trigger('submit');
        form.remove();
    });

    $(document).on('click', '.inf-trigger-delete-group', function(e) {
        e.stopPropagation();
        $('.inf-dropdown-wrapper').removeClass('active');
        var id = $(this).attr('data-id');
        var $card = $('#card-' + id);
        window.ddConfirm("Are you sure you want to delete this group? This will remove the group from all saved creators.", function() {
            $card.css('opacity', '0.5');
            $.ajax({
                url: ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_influencer_group',
                    security: ajax_vars.save_influencer_nonce,
                    group_id: id
                },
                success: function(res) {
                    if (res.success) $card.fadeOut(300, function() {
                        $(this).remove();
                    });
                    else {
                        window.ddAlert(res.data.message);
                        $card.css('opacity', '1');
                    }
                }
            });
        });
    });

    // 4. Remove Single Influencer from Currently Opened Group List
    $(document).on('click', '.inf-remove-from-group-trigger', function(e) {
        e.preventDefault();
        var $btnWrapper = $(this);
        var influencerId = $btnWrapper.attr('data-influencer-id');
        var groupId = state.viewingGroupId;

        if (!groupId) {
            window.ddAlert('Error: Unable to identify the current group context.');
            return;
        }

        window.ddConfirm("Are you sure you want to remove this creator from the current group?", function() {
            var $btnText = $btnWrapper.find('.elementor-button-text');
            var ogText = $btnText.text();
            $btnText.text('Removing...');
            $btnWrapper.css('pointer-events', 'none');

            $.ajax({
                url: ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'remove_influencer_from_group',
                    security: ajax_vars.save_influencer_nonce,
                    influencer_id: influencerId,
                    group_id: groupId
                },
                success: function(res) {
                    if (res.success) {
                        $btnWrapper.closest('.inf-loop-item-row').fadeOut(300, function() {
                            $(this).remove();
                            if ($('#inf-view-group-body .inf-loop-item-row').length === 0) {
                                $('#inf-bulk-action-bar').remove();
                                $('#inf-view-group-body').html('<div class="inf-alert" style="margin:20px;">No creators remain in this group.</div>');
                                updateExportPdfVisibility();
                            } else {
                                $('#inf-bulk-count').text($('.inf-bulk-check:checked').length);
                            }
                        });
                    } else {
                        window.ddAlert(res.data.message);
                        $btnText.text(ogText);
                        $btnWrapper.css('pointer-events', 'auto');
                    }
                },
                error: function() {
                    window.ddAlert('A server error occurred. Please try again.');
                    $btnText.text(ogText);
                    $btnWrapper.css('pointer-events', 'auto');
                }
            });
        });
    });

    // --- Unlock & Save Flow ---
    $(document).on('click', '.unlock-and-save-trigger', function(e) {
        e.preventDefault();
        state.triggerBtn = $(this);
        state.influencerId = state.triggerBtn.attr('data-influencer-id');
        switchModalView('inf-view-unlock-confirm');
    });

    $('#inf-confirm-unlock-btn').on('click', function() {
        let $btn = $(this);
        let ogText = $btn.text();
        $btn.text('Processing...').prop('disabled', true);

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'unlock_and_save_influencer',
                security: ajax_vars.save_influencer_nonce,
                influencer_id: state.influencerId
            },
            success: function(res) {
                if (res.success) {
                    $('#inf-modal-overlay').hide();

                    // 1. Display the custom credit usage notice instantly
                    display_mycred_notice(res.data.notice_html);

                    // 2. Dynamically update the myCred balance text on the screen
                    if ($('.mycred-balance').length) {
                        $('.mycred-balance').text(res.data.new_balance);
                    }

                    // 3. Instantly swap the button to a normal "SAVE" button without reloading
                    state.triggerBtn
                        .removeClass('unlock-and-save-trigger')
                        .addClass('save-influencer-trigger delete-save')
                        .removeAttr('data-influencer-id')
                        .attr('influencer-id', state.influencerId);

                    // Update Icon to Bookmark
                    state.triggerBtn.find('.elementor-button-icon').html('<svg aria-hidden="true" class="e-font-icon-svg e-fas-bookmark" viewBox="0 0 384 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 512V48C0 21.49 21.49 0 48 0h288c26.51 0 48 21.49 48 48v464L192 400 0 512z"></path></svg>');

                    // Update Text to SAVED(X)
                    state.triggerBtn.find('.elementor-button-text').text('SAVED(' + res.data.count + ')');

                } else {
                    // Graceful handling for insufficient credits redirect
                    if (res.data.action === 'redirect') {
                        $btn.text('Redirecting...');
                        window.location.href = res.data.url;
                        return;
                    } else {
                        window.ddAlert(res.data.message);
                    }
                }
                $btn.text(ogText).prop('disabled', false);
            },
            error: function() {
                window.ddAlert('A server error occurred. Please try again.');
                $btn.text(ogText).prop('disabled', false);
            }
        });
    });

    // 5. Search Form Saving Flow
    // Triggers the naming modal instead of immediately saving
    $('.save-search-trigger').on('click', function(e) {
        e.preventDefault();
        $('#inf-save-search-name').val('');
        switchModalView('inf-view-save-search');
    });

    // Confirms the search save action from inside the modal
    $('#inf-modal-confirm-save-search').on('click', function() {
        let searchName = $('#inf-save-search-name').val().trim();
        if (!searchName) {
            window.ddAlert("Please enter a name for your search.");
            return;
        }

        let $btn = $(this);
        let ogText = $btn.text();
        $btn.text('Saving...').prop('disabled', true);

        let getChecked = (name) => {
            let v = [];
            $('input[name^="' + name + '"]:checked').each(function() {
                v.push($(this).val());
            });
            return v;
        };

        let searchData = {
            'niche': getChecked('niche'),
            'platform': getChecked('platform'),
            'followers': getChecked('followers'),
            'country': getChecked('country'),
            'lang': getChecked('lang'),
            'gender': getChecked('gender'),
            'score': $('input[name="score"]').val(),
            'filter': getChecked('filter')
        };

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'save_user_search',
                security: ajax_vars.save_search_nonce,
                search_data: searchData,
                search_name: searchName
            },
            success: function(res) {
                if (res.success) {
                    $('#inf-modal-overlay').hide();
                    display_mycred_notice('<div class="my-cred-notice-text"><h4>Search Saved</h4><p>Your custom search has been successfully saved.</p></div>');

                    let $trigger = $('.save-search-trigger');
                    let origTriggerText = $trigger.text();
                    $trigger.text('Saved!');
                    setTimeout(() => $trigger.text(origTriggerText), 2000);
                } else {
                    window.ddAlert(res.data.message);
                }
                $btn.text(ogText).prop('disabled', false);
            }
        });
    });

    // 6. Saved Searches Load More & Deletion
    let isFetchingSearches = false;
    $('.inf-load-more-searches').on('click', function() {
        if (isFetchingSearches) return;

        let $btn = $(this);
        let nextPage = parseInt($btn.attr('data-paged')) + 1;
        isFetchingSearches = true;

        let ogText = $btn.text();
        $btn.text('Loading...');

        $.ajax({
            url: ajax_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'load_more_saved_searches',
                security: ajax_vars.save_search_nonce,
                paged: nextPage
            },
            success: function(res) {
                if (res.success) {
                    $('#inf-searches-shortcode-grid').append(res.data.html);
                    $btn.attr('data-paged', nextPage);

                    // Remove button if no more pages exist
                    if (!res.data.has_more) {
                        $btn.parent().fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        $btn.text(ogText);
                    }
                } else {
                    window.ddAlert('Error loading more searches.');
                    $btn.text(ogText);
                }
                isFetchingSearches = false;
            },
            error: function() {
                window.ddAlert('A server error occurred while loading searches.');
                isFetchingSearches = false;
                $btn.text(ogText);
            }
        });
    });

    $(document).on('click', '.inf-trigger-delete-search', function(e) {
        e.stopPropagation();
        $('.inf-dropdown-wrapper').removeClass('active');
        var $trigger = $(this);
        window.ddConfirm("Are you sure you want to permanently delete this saved search?", function() {
            var id = $trigger.attr('data-id');
            var $card = $('#search-card-' + id);
            $card.css('opacity', '0.5');
            $.ajax({
                url: ajax_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_saved_search',
                    security: ajax_vars.save_search_nonce,
                    post_id: id
                },
                success: function(res) {
                    if (res.success) {
                        $card.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        window.ddAlert(res.data.message);
                        $card.css('opacity', '1');
                    }
                }
            });
        });
    });

});
