(function ($) {
    'use strict';

    window.InfluencerApp = window.InfluencerApp || {};

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

    InfluencerApp.influencer_select_filters = function () {

        document.querySelectorAll('.select-filter').forEach(function (widget) {
            var dropdownBtn    = widget.querySelector('.dropdown-button');
            var dropdownMenu   = widget.querySelector('.dropdown-menu');
            var tagsContainer  = widget.querySelector('.tags-container');
            var placeholderEl  = widget.querySelector('.dropdown-placeholder');
            var resetBtn       = widget.querySelector('.reset-btn');
            var searchInput    = widget.querySelector('.dropdown-search-input');
            var optionsList    = widget.querySelector('.options-list');
            var ajaxSearchType = searchInput ? searchInput.getAttribute('data-ajax-search') : '';
            var isAsyncSearch  = (ajaxSearchType === 'niche' || ajaxSearchType === 'content_tag');
            var minChars       = searchInput ? parseInt(searchInput.getAttribute('data-min-chars') || '3', 10) : 3;
            var maxResults     = searchInput ? parseInt(searchInput.getAttribute('data-limit') || '20', 10) : 20;
            var searchTimer    = null;
            var requestSeq     = 0;
            var currentXhr     = null;
            var persistEl      = null;
            var maxVisibleTags = isAsyncSearch ? 2 : 0;

            if (isAsyncSearch) {
                persistEl = document.createElement('div');
                persistEl.className = 'selection-persist';
                persistEl.setAttribute('aria-hidden', 'true');
                widget.appendChild(persistEl);
            }

            function cssEscapeValue(value) {
                return (window.CSS && CSS.escape) ? CSS.escape(value) : String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            }

            function getInputName() {
                var filterName = dropdownMenu.getAttribute('data-filter-name');
                if (filterName) return filterName + '[]';
                return ajaxSearchType === 'content_tag' ? 'content_tag[]' : 'niche[]';
            }

            function getSelectedMap() {
                var map = {};

                if (isAsyncSearch && persistEl) {
                    persistEl.querySelectorAll('input').forEach(function (inp) {
                        map[inp.value] = inp.getAttribute('data-label') || inp.value;
                    });
                    return map;
                }

                widget.querySelectorAll('.options-list .dropdown-item input:checked').forEach(function (inp) {
                    map[inp.value] = inp.getAttribute('data-label') || inp.value;
                });
                return map;
            }

            function addToPersist(value, label) {
                if (!persistEl) return;
                if (persistEl.querySelector('input[value="' + cssEscapeValue(value) + '"]')) return;

                var inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.name = getInputName();
                inp.value = value;
                inp.setAttribute('data-label', label);
                inp.checked = true;
                inp.className = 'selection-persist-input';
                persistEl.appendChild(inp);
            }

            function removeFromPersist(value) {
                if (!persistEl) return;
                var inp = persistEl.querySelector('input[value="' + cssEscapeValue(value) + '"]');
                if (inp) inp.remove();
            }

            function clearPersist() {
                if (persistEl) persistEl.innerHTML = '';
            }

            function seedPersistFromDom() {
                if (!isAsyncSearch) return;

                widget.querySelectorAll('.options-list .dropdown-item input').forEach(function (cb) {
                    if (cb.checked) {
                        addToPersist(cb.value, cb.getAttribute('data-label') || cb.value);
                    }
                    cb.removeAttribute('name');
                });
            }

            function renderOptionsFromItems(items) {
                if (!items.length) {
                    optionsList.innerHTML = '<div class="options-list-empty" style="padding:12px;text-align:center;color:#999;font-size:14px;">No matches found.</div>';
                    return;
                }

                var selectedMap = getSelectedMap();
                var inputName   = getInputName();

                optionsList.innerHTML = items.map(function (item) {
                    var isSelected = !!selectedMap[item.value] || item.selected;
                    var checked    = isSelected ? 'checked="checked"' : '';
                    var nameAttr   = isAsyncSearch ? '' : 'name="' + escapeHtml(inputName) + '"';

                    return '<label class="dropdown-item checkbox-list-item">' +
                        '<input class="pseudo-checkbox-input" type="checkbox" value="' + escapeHtml(item.value) + '" data-label="' + escapeHtml(item.label) + '" ' + nameAttr + ' ' + checked + '> ' +
                        '<span class="pseudo-checkbox"></span> ' + escapeHtml(item.label) +
                        '</label>';
                }).join('');
            }

            function renderShortQueryState() {
                var map   = getSelectedMap();
                var items = Object.keys(map).map(function (value) {
                    return { value: value, label: map[value], selected: true };
                });

                if (!items.length) {
                    optionsList.innerHTML = '<div class="options-list-hint" style="padding:12px;color:#888;font-size:14px;">Type ' + minChars + '+ characters to search…</div>';
                    return;
                }

                renderOptionsFromItems(items);
            }

            function syncPlaceholder() {
                if (!placeholderEl) return;
                var hasTags = tagsContainer && tagsContainer.children.length > 0;
                placeholderEl.style.display = hasTags ? 'none' : '';
                if (dropdownBtn) {
                    dropdownBtn.classList.toggle('has-selection', hasTags);
                }
            }

            function findVisibleCheckbox(value) {
                return widget.querySelector('.options-list input[value="' + cssEscapeValue(value) + '"]');
            }

            dropdownBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closeAllOtherDropdowns(dropdownMenu, dropdownBtn);
                dropdownMenu.classList.toggle('show');
                dropdownBtn.classList.toggle('open');
                if (dropdownMenu.classList.contains('show') && searchInput) {
                    setTimeout(function () { searchInput.focus(); }, 100);
                }
            });

            if (tagsContainer) {
                tagsContainer.addEventListener('click', function (e) { e.stopPropagation(); });
            }

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
                        var term        = raw.trim();
                        var selectedMap = getSelectedMap();
                        var selected    = Object.keys(selectedMap);

                        if (term.length < minChars) {
                            renderShortQueryState();
                            return;
                        }

                        dropdownMenu.setAttribute('aria-busy', 'true');
                        optionsList.innerHTML =
                            '<div class="ajax-loading-state" style="padding:15px;text-align:center;color:#666;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">' +
                                '<svg width="18" height="18" viewBox="0 0 50 50">' +
                                    '<circle cx="25" cy="25" r="20" fill="none" stroke="#e0e0e0" stroke-width="4"></circle>' +
                                    '<circle cx="25" cy="25" r="20" fill="none" stroke="#333" stroke-width="4" stroke-dasharray="30 150" stroke-linecap="round">' +
                                        '<animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="0.8s" values="0 25 25;360 25 25"></animateTransform>' +
                                    '</circle>' +
                                '</svg>Searching...' +
                            '</div>';

                        if (currentXhr) { currentXhr.abort(); currentXhr = null; }
                        var mySeq = ++requestSeq;

                        var actionName = ajaxSearchType === 'content_tag'
                            ? 'dd_search_content_tag_options'
                            : 'dd_search_niche_options';

                        currentXhr = $.ajax({
                            url:  ajax_vars.ajax_url,
                            type: 'POST',
                            data: { action: actionName, q: term, selected: selected, limit: maxResults },
                            success: function (response) {
                                currentXhr = null;
                                dropdownMenu.setAttribute('aria-busy', 'false');
                                if (mySeq !== requestSeq) return;

                                if (!response || !response.success || !Array.isArray(response.data.items)) {
                                    renderShortQueryState();
                                    return;
                                }

                                var items       = response.data.items.slice(0, maxResults);
                                var mergedItems = [];
                                var seen        = {};
                                var freshMap    = getSelectedMap();

                                Object.keys(freshMap).forEach(function (value) {
                                    if (seen[value]) return;
                                    seen[value] = true;
                                    mergedItems.push({ value: value, label: freshMap[value], selected: true });
                                });

                                items.forEach(function (item) {
                                    if (!item || !item.value || seen[item.value]) return;
                                    seen[item.value] = true;
                                    mergedItems.push(item);
                                });

                                if (!mergedItems.length) {
                                    renderShortQueryState();
                                    return;
                                }

                                renderOptionsFromItems(mergedItems);
                                updateTags();
                            },
                            error: function (jqXHR) {
                                currentXhr = null;
                                dropdownMenu.setAttribute('aria-busy', 'false');
                                if (jqXHR.statusText === 'abort' || mySeq !== requestSeq) return;
                                optionsList.innerHTML = '<div style="padding:15px;text-align:center;color:#ff4d4d;font-size:14px;">An error occurred. Please try again.</div>';
                            }
                        });
                    }, 220);
                });

                searchInput.addEventListener('click', function (e) { e.stopPropagation(); });
            }

            widget.addEventListener('change', function (e) {
                var target = e.target;
                if (target && target.matches('.dropdown-item input')) {
                    if (isAsyncSearch) {
                        var label = target.getAttribute('data-label') || target.value;
                        if (target.checked) {
                            addToPersist(target.value, label);
                        } else {
                            removeFromPersist(target.value);
                        }
                    }
                    updateTags();
                    if (target.name && target.name.includes('followers')) {
                        InfluencerApp.sync_follower_min_max_states();
                    }
                }
            });

            resetBtn.addEventListener('click', function () {
                widget.querySelectorAll('.dropdown-item input').forEach(function (box) {
                    box.checked = false;
                });
                if (isAsyncSearch) {
                    clearPersist();
                }
                if (searchInput) {
                    searchInput.value = '';
                    if (isAsyncSearch) {
                        optionsList.innerHTML = '<div class="options-list-hint" style="padding:12px;color:#888;font-size:14px;">Type ' + minChars + '+ characters to search…</div>';
                    } else {
                        widget.querySelectorAll('.dropdown-item').forEach(function (item) {
                            item.style.display = '';
                        });
                    }
                }
                updateTags();
                InfluencerApp.sync_follower_min_max_states();
            });

            function updateTags() {
                if (!tagsContainer) return;

                tagsContainer.innerHTML = '';
                var map          = getSelectedMap();
                var keys         = Object.keys(map);
                var hasSelection = keys.length > 0;
                var visibleKeys  = maxVisibleTags > 0 ? keys.slice(0, maxVisibleTags) : keys;
                var hiddenCount  = maxVisibleTags > 0 ? Math.max(0, keys.length - visibleKeys.length) : 0;

                visibleKeys.forEach(function (value) {
                    createTag(map[value], value);
                });

                if (hiddenCount > 0) {
                    createMoreTag(hiddenCount);
                }

                tagsContainer.style.display = hasSelection ? '' : 'none';
                resetBtn.style.display      = hasSelection ? '' : 'none';
                syncPlaceholder();
            }

            function createMoreTag(count) {
                var tag  = document.createElement('div');
                tag.classList.add('tag', 'tag-more');
                tag.setAttribute('role', 'button');
                tag.setAttribute('tabindex', '0');
                tag.setAttribute('aria-label', count + ' more selected — open list');

                var text = document.createElement('span');
                text.innerText = '+' + count + ' more';
                tag.appendChild(text);

                function openDropdown(e) {
                    if (e) e.stopPropagation();
                    if (!dropdownMenu.classList.contains('show')) {
                        closeAllOtherDropdowns(dropdownMenu, dropdownBtn);
                        dropdownMenu.classList.add('show');
                        dropdownBtn.classList.add('open');
                        if (searchInput) {
                            setTimeout(function () { searchInput.focus(); }, 100);
                        }
                    }
                }

                tag.addEventListener('click', openDropdown);
                tag.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openDropdown(e);
                    }
                });

                tagsContainer.appendChild(tag);
            }

            function createTag(label, value) {
                var tag      = document.createElement('div');
                tag.classList.add('tag');

                var text     = document.createElement('span');
                text.innerText = label;

                var closeBtn = document.createElement('span');
                closeBtn.classList.add('tag-close');
                closeBtn.innerHTML = '&times;';

                closeBtn.addEventListener('click', function (e) {
                    e.stopPropagation();

                    if (isAsyncSearch) {
                        removeFromPersist(value);
                        var visible = findVisibleCheckbox(value);
                        if (visible) visible.checked = false;
                    } else {
                        var linked = findVisibleCheckbox(value);
                        if (linked) linked.checked = false;
                    }

                    updateTags();

                    var linkedForFollowers = findVisibleCheckbox(value);
                    if (linkedForFollowers && linkedForFollowers.name && linkedForFollowers.name.includes('followers')) {
                        InfluencerApp.sync_follower_min_max_states();
                    }
                });

                tag.appendChild(text);
                tag.appendChild(closeBtn);
                tagsContainer.appendChild(tag);
            }

            seedPersistFromDom();
            updateTags();
        });

        document.querySelectorAll('.influencer-search-followers-filter').forEach(function (group) {
            var groupResetBtn  = group.querySelector('.header .reset-btn');
            var innerResetBtns = group.querySelectorAll('.followers-filter .reset-btn');

            if (!groupResetBtn || !innerResetBtns.length) return;

            function syncGroupResetVisibility() {
                var anyVisible = Array.prototype.some.call(innerResetBtns, function (btn) {
                    return btn.style.display !== 'none';
                });
                groupResetBtn.style.display = anyVisible ? '' : 'none';
            }

            groupResetBtn.addEventListener('click', function () {
                innerResetBtns.forEach(function (btn) { btn.click(); });
            });

            var observer = new MutationObserver(syncGroupResetVisibility);
            innerResetBtns.forEach(function (btn) {
                observer.observe(btn, { attributes: true, attributeFilter: ['style'] });
            });

            syncGroupResetVisibility();
        });

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