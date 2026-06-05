(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

    /**
     * Disables invalid min/max follower options so users cannot select a
     * maximum that is lower than the current minimum and vice versa.
     */
    InfluencerApp.sync_follower_min_max_states = function () {
        var minRadios = document.querySelectorAll('input[name="min_followers[]"], input[name="min_followers"]');
        var maxRadios = document.querySelectorAll('input[name="max_followers[]"], input[name="max_followers"]');

        if (!minRadios.length || !maxRadios.length) return;

        function getNumericValue(val, isMax) {
            if (!val) return null;
            var parts = val.split('-');
            if (isMax && parts.length > 1) return parseInt(parts[1], 10);
            return parseInt(parts[0], 10);
        }

        var currentMin = null;
        var currentMax = null;

        minRadios.forEach(function (r) { if (r.checked) currentMin = getNumericValue(r.value, false); });
        maxRadios.forEach(function (r) { if (r.checked) currentMax = getNumericValue(r.value, true); });

        function applyState(radio, isDisabled) {
            radio.disabled = isDisabled;
            var label = radio.closest('.dropdown-item');
            if (label) {
                label.style.opacity       = isDisabled ? '0.4' : '1';
                label.style.pointerEvents = isDisabled ? 'none' : 'auto';
            }
        }

        maxRadios.forEach(function (r) {
            applyState(r, currentMin !== null && getNumericValue(r.value, true) <= currentMin);
        });

        minRadios.forEach(function (r) {
            applyState(r, currentMax !== null && getNumericValue(r.value, false) >= currentMax);
        });
    };

    /**
     * Initialises all .select-filter dropdown widgets:
     *   - open/close behaviour
     *   - live text search (sync and async/AJAX)
     *   - tag display for selected values
     *   - reset buttons
     *   - follower min/max re-sync on change
     */
    InfluencerApp.influencer_select_filters = function () {

        document.querySelectorAll('.select-filter').forEach(function (widget) {
            var dropdownBtn    = widget.querySelector('.dropdown-button');
            var dropdownMenu   = widget.querySelector('.dropdown-menu');
            var tagsContainer  = widget.querySelector('.tags-container');
            var resetBtn       = widget.querySelector('.reset-btn');
            var searchInput    = widget.querySelector('.dropdown-search-input');
            var optionsList    = widget.querySelector('.options-list');
            var ajaxSearchType = searchInput ? searchInput.getAttribute('data-ajax-search') : '';
            var isAsyncSearch  = (ajaxSearchType === 'niche' || ajaxSearchType === 'content_tag');
            var minChars       = searchInput ? parseInt(searchInput.getAttribute('data-min-chars') || '3', 10) : 3;
            var maxResults     = searchInput ? parseInt(searchInput.getAttribute('data-limit') || '20', 10) : 20;
            var searchTimer    = null;
            var requestSeq     = 0;

            // --- Open / close ---
            dropdownBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closeAllOtherDropdowns(dropdownMenu, dropdownBtn);
                dropdownMenu.classList.toggle('show');
                dropdownBtn.classList.toggle('open');
                if (dropdownMenu.classList.contains('show') && searchInput) {
                    setTimeout(function () { searchInput.focus(); }, 100);
                }
            });

            // --- Search input ---
            if (searchInput) {
                searchInput.addEventListener('input', function (e) {
                    var raw    = e.target.value || '';
                    var filter = raw.toLowerCase();

                    if (!isAsyncSearch) {
                        widget.querySelectorAll('.dropdown-item').forEach(function (item) {
                            var text = item.textContent || item.innerText;
                            item.style.display = text.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
                        });
                        return;
                    }

                    if (searchTimer) clearTimeout(searchTimer);

                    searchTimer = setTimeout(function () {
                        var term = raw.trim();
                        if (term.length < minChars) { optionsList.innerHTML = ''; return; }

                        optionsList.innerHTML =
                            '<div class="ajax-loading-state" style="padding:15px;text-align:center;color:#666;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">' +
                                '<svg width="18" height="18" viewBox="0 0 50 50">' +
                                    '<circle cx="25" cy="25" r="20" fill="none" stroke="#e0e0e0" stroke-width="4"></circle>' +
                                    '<circle cx="25" cy="25" r="20" fill="none" stroke="#333" stroke-width="4" stroke-dasharray="30 150" stroke-linecap="round">' +
                                        '<animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="0.8s" values="0 25 25;360 25 25"></animateTransform>' +
                                    '</circle>' +
                                '</svg>Searching...' +
                            '</div>';

                        var mySeq         = ++requestSeq;
                        var selectedInputs = Array.from(widget.querySelectorAll('.dropdown-item input:checked'));
                        var selected       = selectedInputs.map(function (i) { return i.value; });
                        var selectedMap    = {};
                        selectedInputs.forEach(function (i) {
                            selectedMap[i.value] = i.getAttribute('data-label') || i.value;
                        });

                        var actionName = ajaxSearchType === 'content_tag'
                            ? 'dd_search_content_tag_options'
                            : 'dd_search_niche_options';
                        var inputName = ajaxSearchType === 'content_tag' ? 'content_tag[]' : 'niche[]';

                        $.ajax({
                            url:  ajax_vars.ajax_url,
                            type: 'POST',
                            data: { action: actionName, q: term, selected: selected, limit: maxResults },
                            success: function (response) {
                                if (mySeq !== requestSeq) return;

                                if (!response || !response.success || !Array.isArray(response.data.items)) {
                                    optionsList.innerHTML = '<div style="padding:15px;text-align:center;color:#999;font-size:14px;">No matches found.</div>';
                                    return;
                                }

                                var items       = response.data.items.slice(0, maxResults);
                                var mergedItems = [];
                                var seen        = {};

                                Object.keys(selectedMap).forEach(function (value) {
                                    if (seen[value]) return;
                                    seen[value] = true;
                                    mergedItems.push({ value: value, label: selectedMap[value], selected: true });
                                });

                                items.forEach(function (item) {
                                    if (!item || !item.value || seen[item.value]) return;
                                    seen[item.value] = true;
                                    mergedItems.push(item);
                                });

                                if (!mergedItems.length) {
                                    optionsList.innerHTML = '<div style="padding:15px;text-align:center;color:#999;font-size:14px;">No matches found.</div>';
                                    return;
                                }

                                optionsList.innerHTML = mergedItems.map(function (item) {
                                    var checked = item.selected ? 'checked="checked"' : '';
                                    return '<label class="dropdown-item checkbox-list-item">' +
                                        '<input class="pseudo-checkbox-input" type="checkbox" value="' + escapeHtml(item.value) + '" data-label="' + escapeHtml(item.label) + '" name="' + inputName + '" ' + checked + '> ' +
                                        '<span class="pseudo-checkbox"></span> ' + escapeHtml(item.label) +
                                        '</label>';
                                }).join('');

                                updateTags();
                            },
                            error: function () {
                                if (mySeq !== requestSeq) return;
                                optionsList.innerHTML = '<div style="padding:15px;text-align:center;color:#ff4d4d;font-size:14px;">An error occurred. Please try again.</div>';
                            }
                        });
                    }, 220);
                });

                searchInput.addEventListener('click', function (e) { e.stopPropagation(); });
            }

            // --- Checkbox change ---
            widget.addEventListener('change', function (e) {
                var target = e.target;
                if (target && target.matches('.dropdown-item input')) {
                    updateTags();
                    if (target.name.includes('followers')) {
                        InfluencerApp.sync_follower_min_max_states();
                    }
                }
            });

            // --- Reset ---
            resetBtn.addEventListener('click', function () {
                widget.querySelectorAll('.dropdown-item input').forEach(function (box) {
                    box.checked = false;
                });
                if (searchInput) {
                    searchInput.value = '';
                    if (isAsyncSearch) {
                        optionsList.innerHTML = '';
                    } else {
                        widget.querySelectorAll('.dropdown-item').forEach(function (item) {
                            item.style.display = '';
                        });
                    }
                }
                updateTags();
                InfluencerApp.sync_follower_min_max_states();
            });

            // --- Tag helpers ---
            function updateTags() {
                var checkboxes   = widget.querySelectorAll('.dropdown-item input');
                tagsContainer.innerHTML = '';
                var hasSelection = false;

                checkboxes.forEach(function (checkbox) {
                    if (checkbox.checked) {
                        createTag(checkbox.dataset.label, checkbox);
                        hasSelection = true;
                    }
                });

                tagsContainer.style.display = hasSelection ? '' : 'none';
                resetBtn.style.display      = hasSelection ? '' : 'none';
            }

            function createTag(label, linkedCheckbox) {
                var tag      = document.createElement('div');
                tag.classList.add('tag');

                var text     = document.createElement('span');
                text.innerText = label;

                var closeBtn = document.createElement('span');
                closeBtn.classList.add('tag-close');
                closeBtn.innerHTML = '&times;';

                closeBtn.addEventListener('click', function () {
                    linkedCheckbox.checked = false;
                    updateTags();
                    if (linkedCheckbox.name.includes('followers')) {
                        InfluencerApp.sync_follower_min_max_states();
                    }
                });

                tag.appendChild(text);
                tag.appendChild(closeBtn);
                tagsContainer.appendChild(tag);
            }

            updateTags();
        });

        // --- Close dropdowns on outside click ---
        document.addEventListener('click', function (e) {
            document.querySelectorAll('.select-filter').forEach(function (widget) {
                var btn  = widget.querySelector('.dropdown-button');
                var menu = widget.querySelector('.dropdown-menu');
                if (!btn.contains(e.target) && !menu.contains(e.target)) {
                    menu.classList.remove('show');
                    btn.classList.remove('open');
                }
            });
        });

        function closeAllOtherDropdowns(currentMenu, currentBtn) {
            document.querySelectorAll('.select-filter').forEach(function (widget) {
                var menu = widget.querySelector('.dropdown-menu');
                var btn  = widget.querySelector('.dropdown-button');
                if (menu !== currentMenu && btn !== currentBtn) {
                    menu.classList.remove('show');
                    btn.classList.remove('open');
                }
            });
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

})(jQuery);
