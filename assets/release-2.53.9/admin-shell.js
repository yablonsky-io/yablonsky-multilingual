(function () {
  'use strict';

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function statusBadge(state, label) {
    return '<span class="ypm-status-badge ypm-status-badge--' + esc(state) + '">' + esc(label) + '</span>';
  }

  function resultRow(item) {
    var labels = window.ypmAdminShell || {};
    var state = item.state || 'neutral';
    var label = state === 'pass' ? labels.pass : (state === 'fail' ? labels.fail : labels.skip);
    return '<div class="ypm-mini-result"><span class="ypm-status-dot ypm-status-dot--' + esc(state) + '"></span><div><strong>' + esc(item.label) + '</strong><small>' + esc(item.value) + '</small></div>' + statusBadge(state, label || state.toUpperCase()) + '</div>';
  }

  function createClientPager(items, pager, afterRender) {
    if (!pager || !items || !items.length) {
      return { update: function (filtered) {
        (items || []).forEach(function (item) { item.hidden = filtered && filtered.indexOf(item) === -1; });
        if (typeof afterRender === 'function') afterRender(filtered || items || []);
      } };
    }

    var current = 1;
    var pageSize = parseInt(pager.getAttribute('data-page-size') || '25', 10) || 25;
    var filteredItems = items.slice();
    var summary = pager.querySelector('[data-ypm-pager-summary]');
    var pagesNode = pager.querySelector('[data-ypm-pager-pages]');
    var first = pager.querySelector('[data-ypm-pager-first]');
    var prev = pager.querySelector('[data-ypm-pager-prev]');
    var next = pager.querySelector('[data-ypm-pager-next]');
    var last = pager.querySelector('[data-ypm-pager-last]');
    var size = pager.querySelector('[data-ypm-pager-size]');
    var labels = window.ypmAdminShell || {};

    function totalPages() { return Math.max(1, Math.ceil(filteredItems.length / pageSize)); }

    function pageButton(page, label, currentPage) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'button ypm-client-pager__page' + (page === currentPage ? ' is-current' : '');
      button.textContent = label;
      button.setAttribute('data-ypm-page', String(page));
      if (page === currentPage) {
        button.setAttribute('aria-current', 'page');
        button.disabled = true;
      }
      return button;
    }

    function renderPageButtons(pageCount) {
      if (!pagesNode) return;
      pagesNode.innerHTML = '';
      var start = Math.max(1, current - 2);
      var end = Math.min(pageCount, start + 4);
      start = Math.max(1, end - 4);
      if (start > 1) {
        pagesNode.appendChild(pageButton(1, '1', current));
        if (start > 2) { var lead = document.createElement('span'); lead.className = 'ypm-client-pager__ellipsis'; lead.textContent = '…'; pagesNode.appendChild(lead); }
      }
      for (var page = start; page <= end; page += 1) pagesNode.appendChild(pageButton(page, String(page), current));
      if (end < pageCount) {
        if (end < pageCount - 1) { var tail = document.createElement('span'); tail.className = 'ypm-client-pager__ellipsis'; tail.textContent = '…'; pagesNode.appendChild(tail); }
        pagesNode.appendChild(pageButton(pageCount, String(pageCount), current));
      }
    }

    function render() {
      var pages = totalPages();
      if (current > pages) current = pages;
      if (current < 1) current = 1;
      items.forEach(function (item) { item.hidden = true; });
      var startIndex = (current - 1) * pageSize;
      var endIndex = Math.min(filteredItems.length, startIndex + pageSize);
      filteredItems.slice(startIndex, endIndex).forEach(function (item) { item.hidden = false; });
      if (summary) {
        if (!filteredItems.length) summary.textContent = '0 ' + (labels.pagerOf || 'of') + ' 0';
        else summary.textContent = String(startIndex + 1) + '–' + String(endIndex) + ' ' + (labels.pagerOf || 'of') + ' ' + String(filteredItems.length);
      }
      if (first) first.disabled = current <= 1;
      if (prev) prev.disabled = current <= 1;
      if (next) next.disabled = current >= pages;
      if (last) last.disabled = current >= pages;
      renderPageButtons(pages);
      var sizeOptions = size ? Array.prototype.slice.call(size.options || []).map(function (option) { return parseInt(option.value || '0', 10) || 0; }).filter(Boolean) : [pageSize];
      var smallestPage = sizeOptions.length ? Math.min.apply(Math, sizeOptions) : pageSize;
      pager.hidden = items.length <= smallestPage && filteredItems.length <= smallestPage;
      if (typeof afterRender === 'function') afterRender(filteredItems.slice(startIndex, endIndex));
    }

    function go(page) { current = Math.max(1, Math.min(totalPages(), page)); render(); }
    if (first) first.addEventListener('click', function () { go(1); });
    if (prev) prev.addEventListener('click', function () { go(current - 1); });
    if (next) next.addEventListener('click', function () { go(current + 1); });
    if (last) last.addEventListener('click', function () { go(totalPages()); });
    if (pagesNode) pagesNode.addEventListener('click', function (event) {
      var button = event.target.closest('[data-ypm-page]');
      if (!button) return;
      go(parseInt(button.getAttribute('data-ypm-page') || '1', 10) || 1);
    });
    if (size) size.addEventListener('change', function () {
      pageSize = parseInt(size.value || String(pageSize), 10) || pageSize;
      current = 1;
      render();
    });

    render();
    return {
      update: function (filtered, reset) {
        filteredItems = (filtered || []).slice();
        if (reset !== false) current = 1;
        render();
      },
      render: render
    };
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-ypm-open-details]');
    if (!button) return;
    var id = button.getAttribute('data-ypm-open-details');
    var details = id ? document.getElementById(id) : null;
    if (!details) return;
    details.open = true;
    details.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  var languageSearch = document.getElementById('ypm-language-search');
  var languageTable = document.getElementById('ypm-language-table');
  var languageEmpty = document.getElementById('ypm-language-search-empty');
  if (languageSearch && languageTable) {
    var languageRows = Array.prototype.slice.call(languageTable.querySelectorAll('tbody tr[data-ypm-language-search]'));
    var languagePager = createClientPager(languageRows, document.getElementById('ypm-language-pager'));
    var applyLanguageFilter = function () {
      var query = String(languageSearch.value || '').toLowerCase().trim();
      var filtered = languageRows.filter(function (row) {
        var hay = String(row.getAttribute('data-ypm-language-search') || '');
        return query === '' || hay.indexOf(query) !== -1;
      });
      languagePager.update(filtered);
      if (languageEmpty) languageEmpty.style.display = filtered.length === 0 ? 'block' : 'none';
    };
    languageSearch.addEventListener('input', applyLanguageFilter);
    applyLanguageFilter();
  }


  var customLocaleList = document.getElementById('ypm-custom-locale-list');
  if (customLocaleList) {
    createClientPager(Array.prototype.slice.call(customLocaleList.querySelectorAll('[data-ypm-custom-locale]')), document.getElementById('ypm-custom-locale-pager'));
  }

  var activeLocaleForm = document.getElementById('ypm-active-locales-form');
  var activeLocaleFrame = document.getElementById('ypm-active-locales-frame');
  var activeLocaleList = document.getElementById('ypm-enabled-locales');
  var activeLocaleToggle = document.getElementById('ypm-active-locales-toggle');
  var activeLocaleSort = document.getElementById('ypm-sort-locales-az');
  var activeLocaleState = document.getElementById('ypm-active-locales-state');
  var activeLocaleSave = document.getElementById('ypm-save-active-locales');
  var draggedLocale = null;

  function activeLocaleChips() {
    if (!activeLocaleList) return [];
    return Array.prototype.slice.call(activeLocaleList.querySelectorAll('.ypm-locale-chip[data-locale-code]'));
  }

  function setActiveLocaleDirty() {
    if (!activeLocaleForm) return;
    activeLocaleForm.classList.add('is-dirty');
    if (activeLocaleState) activeLocaleState.textContent = activeLocaleForm.getAttribute('data-dirty-label') || '';
    if (activeLocaleSave) activeLocaleSave.disabled = false;
  }

  function syncCatalogCheckbox(code, checked) {
    if (!languageTable) return;
    var boxes = languageTable.querySelectorAll('input[type="checkbox"][name="active_codes[]"]');
    Array.prototype.forEach.call(boxes, function (box) {
      if (String(box.value) === String(code)) box.checked = checked;
    });
  }

  function refreshActiveLocaleCollapse() {
    if (!activeLocaleFrame || !activeLocaleList || !activeLocaleToggle) return;
    var expanded = activeLocaleFrame.classList.contains('is-expanded');
    activeLocaleFrame.classList.remove('is-collapsible');
    activeLocaleToggle.hidden = true;

    window.requestAnimationFrame(function () {
      var naturalHeight = activeLocaleList.scrollHeight;
      var needsCollapse = naturalHeight > 232;
      if (expanded && needsCollapse) {
        activeLocaleToggle.hidden = false;
        activeLocaleToggle.textContent = activeLocaleToggle.getAttribute('data-hide-label') || '';
        return;
      }
      activeLocaleFrame.classList.toggle('is-collapsible', needsCollapse);
      activeLocaleToggle.hidden = !needsCollapse;
      activeLocaleToggle.textContent = activeLocaleToggle.getAttribute('data-show-label') || '';
    });
  }

  function localeInsertBeforeTarget(event, dragging) {
    var target = event.target.closest('.ypm-locale-chip[data-locale-code]');
    if (!target || target === dragging || !activeLocaleList) return;
    var rect = target.getBoundingClientRect();
    var before = event.clientY < rect.top + (rect.height / 2);
    if (Math.abs(event.clientY - (rect.top + rect.height / 2)) < rect.height / 3) {
      before = event.clientX < rect.left + (rect.width / 2);
    }
    activeLocaleList.insertBefore(dragging, before ? target : target.nextSibling);
  }

  if (activeLocaleForm && activeLocaleList) {
    if (activeLocaleSave) activeLocaleSave.disabled = true;

    activeLocaleForm.addEventListener('change', function (event) {
      if (event.target && event.target.matches('input[name="public_codes[]"]')) setActiveLocaleDirty();
    });

    activeLocaleList.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-ypm-remove-locale]');
      if (!remove) return;
      var chip = remove.closest('.ypm-locale-chip[data-locale-code]');
      if (!chip) return;
      var code = chip.getAttribute('data-locale-code') || '';
      chip.remove();
      syncCatalogCheckbox(code, false);
      setActiveLocaleDirty();
      refreshActiveLocaleCollapse();
    });

    activeLocaleList.addEventListener('keydown', function (event) {
      var handle = event.target.closest('.ypm-locale-drag');
      if (!handle) return;
      var chip = handle.closest('.ypm-locale-chip[data-locale-code]');
      if (!chip) return;
      var previous = chip.previousElementSibling;
      var next = chip.nextElementSibling;
      if ((event.key === 'ArrowLeft' || event.key === 'ArrowUp') && previous) {
        event.preventDefault();
        activeLocaleList.insertBefore(chip, previous);
        handle.focus();
        setActiveLocaleDirty();
        refreshActiveLocaleCollapse();
      } else if ((event.key === 'ArrowRight' || event.key === 'ArrowDown') && next) {
        event.preventDefault();
        activeLocaleList.insertBefore(next, chip);
        handle.focus();
        setActiveLocaleDirty();
        refreshActiveLocaleCollapse();
      }
    });

    activeLocaleList.addEventListener('dragstart', function (event) {
      var chip = event.target.closest('.ypm-locale-chip[data-locale-code]');
      if (!chip) return;
      draggedLocale = chip;
      chip.classList.add('is-dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', chip.getAttribute('data-locale-code') || '');
      }
    });

    activeLocaleList.addEventListener('dragover', function (event) {
      if (!draggedLocale) return;
      event.preventDefault();
      localeInsertBeforeTarget(event, draggedLocale);
    });

    activeLocaleList.addEventListener('drop', function (event) {
      if (!draggedLocale) return;
      event.preventDefault();
      setActiveLocaleDirty();
    });

    activeLocaleList.addEventListener('dragend', function () {
      if (draggedLocale) draggedLocale.classList.remove('is-dragging');
      draggedLocale = null;
      setActiveLocaleDirty();
      refreshActiveLocaleCollapse();
    });

    if (activeLocaleSort) {
      activeLocaleSort.addEventListener('click', function () {
        var chips = activeLocaleChips();
        chips.sort(function (a, b) {
          var aName = String((a.querySelector(':scope > span') || {}).textContent || '').trim();
          var bName = String((b.querySelector(':scope > span') || {}).textContent || '').trim();
          return aName.localeCompare(bName, undefined, { sensitivity: 'base' });
        });
        chips.forEach(function (chip) { activeLocaleList.appendChild(chip); });
        setActiveLocaleDirty();
        refreshActiveLocaleCollapse();
      });
    }

    if (activeLocaleToggle && activeLocaleFrame) {
      activeLocaleToggle.addEventListener('click', function () {
        var expanded = activeLocaleFrame.classList.toggle('is-expanded');
        activeLocaleToggle.textContent = expanded
          ? (activeLocaleToggle.getAttribute('data-hide-label') || '')
          : (activeLocaleToggle.getAttribute('data-show-label') || '');
        activeLocaleFrame.classList.toggle('is-collapsible', !expanded);
      });
    }

    activeLocaleForm.addEventListener('submit', function () {
      if (activeLocaleSave) {
        activeLocaleSave.disabled = true;
        activeLocaleSave.textContent = cfg.saving || '';
      }
    });

    window.addEventListener('resize', refreshActiveLocaleCollapse);
    refreshActiveLocaleCollapse();
  }

  function bindVisibleTableSearch(inputId, tableId, attributeName, emptyId) {
    var input = document.getElementById(inputId);
    var table = document.getElementById(tableId);
    var empty = document.getElementById(emptyId);
    if (!input || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[' + attributeName + ']'));
    input.addEventListener('input', function () {
      var query = String(input.value || '').toLowerCase().trim();
      var visible = 0;
      rows.forEach(function (row) {
        var hay = String(row.getAttribute(attributeName) || '').toLowerCase();
        var show = query === '' || hay.indexOf(query) !== -1;
        row.hidden = !show;
        if (show) visible += 1;
      });
      Array.prototype.forEach.call(table.querySelectorAll('.ypm-string-edit-row'), function (row) {
        row.hidden = true;
        var rowOpener = document.querySelector('[data-ypm-edit-row="' + row.id + '"]');
        if (rowOpener) rowOpener.setAttribute('aria-expanded', 'false');
      });
      if (empty) empty.hidden = visible !== 0;
    });
  }

  function bindWorkspaceFilters() {
    var input = document.getElementById('ypm-workspace-search');
    var state = document.getElementById('ypm-workspace-state');
    var table = document.getElementById('ypm-workspace-table');
    var empty = document.getElementById('ypm-workspace-search-empty');
    if (!table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-ypm-workspace-search]'));
    function apply() {
      var query = input ? String(input.value || '').toLowerCase().trim() : '';
      var wanted = state ? String(state.value || 'all') : 'all';
      var visible = 0;
      rows.forEach(function (row) {
        var hay = String(row.getAttribute('data-ypm-workspace-search') || '').toLowerCase();
        var rowState = String(row.getAttribute('data-ypm-workspace-state') || 'open');
        var show = (query === '' || hay.indexOf(query) !== -1) && (wanted === 'all' || rowState === wanted);
        row.hidden = !show;
        if (show) visible += 1;
      });
      if (empty) empty.hidden = visible !== 0;
    }
    if (input) input.addEventListener('input', apply);
    if (state) state.addEventListener('change', apply);
  }

  function bindStringFilters() {
    var input = document.getElementById('ypm-string-search');
    var domain = document.getElementById('ypm-string-domain');
    var table = document.getElementById('ypm-string-table');
    var empty = document.getElementById('ypm-string-search-empty');
    if (!table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-ypm-string-search]'));
    function apply() {
      var query = input ? String(input.value || '').toLowerCase().trim() : '';
      var wanted = domain ? String(domain.value || 'all') : 'all';
      var visible = 0;
      rows.forEach(function (row) {
        var hay = String(row.getAttribute('data-ypm-string-search') || '').toLowerCase();
        var rowDomain = String(row.getAttribute('data-ypm-string-domain') || '');
        var show = (query === '' || hay.indexOf(query) !== -1) && (wanted === 'all' || rowDomain === wanted);
        row.hidden = !show;
        if (show) visible += 1;
      });
      Array.prototype.forEach.call(table.querySelectorAll('.ypm-string-edit-row'), function (row) {
        row.hidden = true;
        var opener = document.querySelector('[data-ypm-edit-row="' + row.id + '"]');
        if (opener) opener.setAttribute('aria-expanded', 'false');
      });
      if (empty) empty.hidden = visible !== 0;
    }
    if (input) input.addEventListener('input', apply);
    if (domain) domain.addEventListener('change', apply);
  }

  function bindTaxonomyFilters() {
    var state = document.getElementById('ypm-taxonomy-state');
    var table = document.querySelector('.ypm-taxonomy-table');
    var empty = document.getElementById('ypm-taxonomy-filter-empty');
    if (!state || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-ypm-taxonomy-state]'));
    function apply() {
      var wanted = String(state.value || 'all');
      var visible = 0;
      rows.forEach(function (row) {
        var rowState = String(row.getAttribute('data-ypm-taxonomy-state') || 'open');
        var show = wanted === 'all' || rowState === wanted;
        row.hidden = !show;
        if (show) visible += 1;
      });
      if (empty) empty.hidden = visible !== 0;
    }
    state.addEventListener('change', apply);
  }

  bindWorkspaceFilters();
  bindStringFilters();
  bindTaxonomyFilters();

  var emailSearch = document.getElementById('ypm-email-search');
  var emailSource = document.getElementById('ypm-email-source-filter');
  var emailEdited = document.getElementById('ypm-email-edited-only');
  var emailEmpty = document.getElementById('ypm-email-search-empty');
  if (emailSearch || emailSource || emailEdited) {
    var emailItems = Array.prototype.slice.call(document.querySelectorAll('[data-ypm-email-search]'));
    var emailGroups = Array.prototype.slice.call(document.querySelectorAll('[data-ypm-email-source-group]'));
    var emailPager = createClientPager(emailItems, document.getElementById('ypm-email-pager'), function () {
      emailGroups.forEach(function (group) {
        group.hidden = !group.querySelector('[data-ypm-email-search]:not([hidden])');
      });
    });
    var applyEmailFilters = function () {
      var query = emailSearch ? String(emailSearch.value || '').toLowerCase().trim() : '';
      var wantedSource = emailSource ? String(emailSource.value || 'all') : 'all';
      var editedOnly = !!(emailEdited && emailEdited.checked);
      var filtered = emailItems.filter(function (item) {
        var group = item.closest('[data-ypm-email-source-group]');
        var source = group ? String(group.getAttribute('data-ypm-email-source') || '') : '';
        var hay = String(item.getAttribute('data-ypm-email-search') || '').toLowerCase();
        var edited = item.getAttribute('data-ypm-email-edited') === '1';
        return (query === '' || hay.indexOf(query) !== -1) && (wantedSource === 'all' || source === wantedSource) && (!editedOnly || edited);
      });
      emailPager.update(filtered);
      if (emailEmpty) emailEmpty.hidden = filtered.length !== 0;
    };
    if (emailSearch) emailSearch.addEventListener('input', applyEmailFilters);
    if (emailSource) emailSource.addEventListener('change', applyEmailFilters);
    if (emailEdited) emailEdited.addEventListener('change', applyEmailFilters);
    applyEmailFilters();
  }

  var wooSearch = document.getElementById('ypm-woo-string-search');
  var wooList = document.getElementById('ypm-woo-string-list');
  var wooEmpty = document.getElementById('ypm-woo-string-empty');
  if (wooSearch && wooList) {
    var wooRows = Array.prototype.slice.call(wooList.querySelectorAll('[data-ypm-string-search]'));
    var wooPager = createClientPager(wooRows, document.getElementById('ypm-woo-string-pager'));
    var applyWooStringFilter = function () {
      var query = String(wooSearch.value || '').toLowerCase().trim();
      var filtered = wooRows.filter(function (row) {
        var hay = String(row.getAttribute('data-ypm-string-search') || '');
        return query === '' || hay.indexOf(query) !== -1;
      });
      wooPager.update(filtered);
      if (wooEmpty) wooEmpty.style.display = filtered.length === 0 ? 'block' : 'none';
    };
    wooSearch.addEventListener('input', applyWooStringFilter);
    applyWooStringFilter();
  }

  var wooEntitySearch = document.getElementById('ypm-woo-entity-search');
  var wooEntityList = document.getElementById('ypm-woo-entity-list');
  var wooEntityEmpty = document.getElementById('ypm-woo-entity-empty');
  if (wooEntitySearch && wooEntityList) {
    var wooEntityRows = Array.prototype.slice.call(wooEntityList.querySelectorAll('[data-ypm-entity-search]'));
    var wooEntityPager = createClientPager(wooEntityRows, document.getElementById('ypm-woo-entity-pager'));
    var applyWooEntityFilter = function () {
      var query = String(wooEntitySearch.value || '').toLowerCase().trim();
      var filtered = wooEntityRows.filter(function (row) {
        var hay = String(row.getAttribute('data-ypm-entity-search') || '');
        return query === '' || hay.indexOf(query) !== -1;
      });
      wooEntityPager.update(filtered);
      if (wooEntityEmpty) wooEntityEmpty.hidden = filtered.length !== 0;
    };
    wooEntitySearch.addEventListener('input', applyWooEntityFilter);
    applyWooEntityFilter();
  }

  var form = document.getElementById('ypm-rest-test-form');
  var button = document.getElementById('ypm-rest-test-button');
  var progress = document.getElementById('ypm-rest-progress');
  var message = document.getElementById('ypm-rest-message');
  var results = document.getElementById('ypm-rest-results');
  var badge = document.getElementById('ypm-rest-summary-badge');
  var totals = document.getElementById('ypm-rest-totals');
  var meaning = document.getElementById('ypm-rest-meaning');
  var raw = document.getElementById('ypm-rest-raw');
  var nonce = document.getElementById('ypm-rest-ajax-nonce');
  var copyButton = document.getElementById('ypm-rest-copy');
  var saveButton = document.getElementById('ypm-rest-save');
  var rawFeedback = document.getElementById('ypm-rest-raw-feedback');
  var cfg = window.ypmAdminShell || {};

  function rawJsonText() {
    return raw ? String(raw.textContent || '').trim() : '';
  }

  function reportFileName(text) {
    var stamp = '';
    try {
      var parsed = JSON.parse(text);
      stamp = parsed && parsed.time ? String(parsed.time) : '';
    } catch (error) {
      stamp = '';
    }
    if (!stamp) stamp = new Date().toISOString();
    stamp = stamp.replace(/[:.]/g, '-').replace(/\+.*$/, 'Z');
    return 'ypm-rest-selftest-' + stamp + '.json';
  }

  function setRawActionsEnabled(enabled) {
    if (copyButton) copyButton.disabled = !enabled;
    if (saveButton) saveButton.disabled = !enabled;
  }

  function showRawFeedback(text) {
    if (!rawFeedback) return;
    rawFeedback.textContent = text || '';
    if (text) window.setTimeout(function () { rawFeedback.textContent = ''; }, 2600);
  }

  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', 'readonly');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (error) { ok = false; }
    document.body.removeChild(area);
    return ok;
  }

  if (copyButton && raw) {
    copyButton.addEventListener('click', function () {
      var text = rawJsonText();
      if (!text || text === (cfg.notRun || '')) return;
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () {
          showRawFeedback(cfg.copied || '');
        }).catch(function () {
          showRawFeedback(fallbackCopy(text) ? (cfg.copied || '') : (cfg.copyFailed || ''));
        });
      } else {
        showRawFeedback(fallbackCopy(text) ? (cfg.copied || '') : (cfg.copyFailed || ''));
      }
    });
  }

  if (saveButton && raw && window.Blob && window.URL && window.URL.createObjectURL) {
    saveButton.addEventListener('click', function () {
      var text = rawJsonText();
      if (!text || text === (cfg.notRun || '')) return;
      var blob = new Blob([text + '\n'], { type: 'application/json;charset=utf-8' });
      var url = window.URL.createObjectURL(blob);
      var link = document.createElement('a');
      link.href = url;
      link.download = reportFileName(text);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
      showRawFeedback(cfg.saved || '');
    });
  }

  if (form && button && progress && message && results && badge && raw && nonce && cfg.ajaxUrl && window.fetch && window.FormData) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (button.disabled) return;

      button.disabled = true;
      button.textContent = cfg.running || '';
      setRawActionsEnabled(false);
      showRawFeedback('');
      progress.classList.remove('is-complete', 'is-fail');
      progress.classList.add('is-running');
      progress.setAttribute('aria-hidden', 'false');
      message.textContent = cfg.checking || '';
      results.innerHTML = '<div class="ypm-empty-state">' + esc(cfg.checking || '') + '</div>';
      badge.innerHTML = statusBadge('running', cfg.running || '');

      var data = new FormData();
      data.append('action', 'ypm_rest_selftest');
      data.append('nonce', nonce.value);

      fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      }).then(function (response) {
        return response.json();
      }).then(function (payload) {
        if (!payload || !payload.success || !payload.data) throw new Error('Invalid self-test response');
        var summary = payload.data.summary || {};
        var items = Array.isArray(summary.items) ? summary.items : [];
        var failCount = Number(summary.fail || 0);
        progress.classList.remove('is-running');
        progress.classList.add(failCount > 0 ? 'is-fail' : 'is-complete');
        message.textContent = cfg.complete || '';
        badge.innerHTML = statusBadge(failCount > 0 ? 'fail' : 'pass', failCount > 0 ? (cfg.review || '') : (cfg.healthy || ''));
        if (totals) {
          totals.hidden = false;
          totals.innerHTML = '<span class="is-pass">' + esc(cfg.pass || '') + ' ' + esc(Number(summary.pass || 0)) + '</span><span class="is-fail">' + esc(cfg.fail || '') + ' ' + esc(failCount) + '</span><span class="is-skip">' + esc(cfg.skip || '') + ' ' + esc(Number(summary.skip || 0)) + '</span>';
        }
        if (meaning) {
          meaning.innerHTML = failCount > 0
            ? '<div class="ypm-result-meaning ypm-result-meaning--fail"><strong>' + esc(cfg.meaningFailTitle || '') + '</strong><span>' + esc(cfg.meaningFail || '') + '</span></div>'
            : '<div class="ypm-result-meaning ypm-result-meaning--pass"><strong>' + esc(cfg.meaningPassTitle || '') + '</strong><span>' + esc(cfg.meaningPass || '') + '</span></div>';
        }
        results.innerHTML = items.length ? items.map(resultRow).join('') : '<div class="ypm-empty-state">' + esc(cfg.result || '') + ': 0.</div>';
        raw.textContent = JSON.stringify(payload.data.result || {}, null, 2);
        setRawActionsEnabled(true);
        showRawFeedback('');
      }).catch(function () {
        progress.classList.remove('is-running');
        progress.classList.add('is-fail');
        message.textContent = cfg.failed || '';
        badge.innerHTML = statusBadge('fail', cfg.review || '');
        results.innerHTML = '<div class="ypm-empty-state">' + esc(cfg.failed || '') + '</div>';
      }).finally(function () {
        button.disabled = false;
        button.textContent = cfg.runTest || '';
      });
    });
  }


  // Confirm actions that change local overrides or start approved writes.
  document.addEventListener('click', function (event) {
    var target = event.target.closest('[data-ypm-confirm]');
    if (!target) return;
    var type = target.getAttribute('data-ypm-confirm');
    var text = type === 'import'
      ? (cfg.confirmImport || '')
      : (cfg.confirmDelete || '');
    if (!window.confirm(text)) event.preventDefault();
  });

  // Inline string override editing keeps identity fixed and edits only local copy.
  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-ypm-edit-row]');
    if (opener) {
      var openId = opener.getAttribute('data-ypm-edit-row');
      var openRow = openId ? document.getElementById(openId) : null;
      if (!openRow) return;
      Array.prototype.forEach.call(document.querySelectorAll('.ypm-string-edit-row'), function (row) {
        if (row !== openRow) {
          row.hidden = true;
          var otherOpener = document.querySelector('[data-ypm-edit-row="' + row.id + '"]');
          if (otherOpener) otherOpener.setAttribute('aria-expanded', 'false');
        }
      });
      openRow.hidden = !openRow.hidden;
      opener.setAttribute('aria-expanded', openRow.hidden ? 'false' : 'true');
      if (!openRow.hidden) {
        var textarea = openRow.querySelector('textarea');
        if (textarea) textarea.focus();
      }
      return;
    }

    var closer = event.target.closest('[data-ypm-close-row]');
    if (!closer) return;
    var closeId = closer.getAttribute('data-ypm-close-row');
    var closeRow = closeId ? document.getElementById(closeId) : null;
    if (closeRow) closeRow.hidden = true;
    var closeOpener = closeId ? document.querySelector('[data-ypm-edit-row="' + closeId + '"]') : null;
    if (closeOpener) {
      closeOpener.setAttribute('aria-expanded', 'false');
      closeOpener.focus();
    }
  });

  // While Migration is open, advance one bounded batch at a time. The PHP
  // runner has a short lock so WP-Cron and the visible-page runner cannot
  // process the same cursor concurrently.
  var migrationJob = document.getElementById('ypm-migration-job');
  var migrationState = document.getElementById('ypm-migration-live-state');
  var migrationBusy = false;

  function updateMigrationStats(job) {
    var stats = job && job.stats ? job.stats : {};
    ['processed', 'ready', 'imported', 'conflict', 'skipped'].forEach(function (key) {
      var node = document.querySelector('[data-ypm-job-stat="' + key + '"]');
      if (node) node.textContent = String(Number(stats[key] || 0));
    });
  }

  function migrationTick() {
    if (!migrationJob || migrationBusy || migrationJob.getAttribute('data-status') !== 'running') return;
    if (!cfg.ajaxUrl || !cfg.migrationNonce || !window.fetch || !window.FormData) return;
    migrationBusy = true;
    if (migrationState) migrationState.textContent = cfg.migrationWorking || '';

    var data = new FormData();
    data.append('action', 'ypm_migration_tick');
    data.append('nonce', cfg.migrationNonce);

    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.success || !payload.data) throw new Error('Invalid migration response');
        var job = payload.data.job || {};
        updateMigrationStats(job);
        var status = String(job.status || '');
        migrationJob.setAttribute('data-status', status);
        if (status === 'complete' || status === 'failed') {
          if (migrationState) migrationState.textContent = status === 'complete'
            ? (cfg.migrationComplete || '')
            : (cfg.failed || '');
          window.setTimeout(function () { window.location.reload(); }, 700);
          return;
        }
        window.setTimeout(migrationTick, 1100);
      })
      .catch(function () {
        if (migrationState) migrationState.textContent = cfg.migrationPaused || '';
      })
      .finally(function () { migrationBusy = false; });
  }

  if (migrationJob && migrationJob.getAttribute('data-status') === 'running') {
    window.setTimeout(migrationTick, 650);
  }

  function closeModelPicker(picker) {
    if (!picker) return;
    var popover = picker.querySelector('[data-ypm-model-popover]');
    var toggle = picker.querySelector('[data-ypm-model-toggle]');
    if (popover) popover.hidden = true;
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  }

  function filterModelPicker(picker, query) {
    var options = picker.querySelectorAll('[data-ypm-model-option]');
    var visible = 0;
    var tokens = String(query || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
    Array.prototype.forEach.call(options, function (option) {
      var hay = String(option.getAttribute('data-model-search') || ((option.textContent || '') + ' ' + (option.getAttribute('data-model-id') || ''))).toLowerCase();
      var show = tokens.length === 0 || tokens.every(function (token) { return hay.indexOf(token) !== -1; });
      option.hidden = !show;
      option.classList.toggle('is-filtered-out', !show);
      option.setAttribute('aria-hidden', show ? 'false' : 'true');
      // WordPress/admin theme CSS can give model options an explicit display value.
      // Inline display:none makes filtering deterministic even when the native hidden rule is overridden.
      option.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    var empty = picker.querySelector('[data-ypm-model-empty]');
    if (empty) {
      var noMatches = visible === 0;
      empty.hidden = !noMatches;
      empty.style.display = noMatches ? 'block' : 'none';
    }
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-ypm-model-picker]'), function (picker) {
    var toggle = picker.querySelector('[data-ypm-model-toggle]');
    var popover = picker.querySelector('[data-ypm-model-popover]');
    var search = picker.querySelector('[data-ypm-model-search]');
    var value = picker.querySelector('[data-ypm-model-value]');
    var current = picker.querySelector('[data-ypm-model-current]');
    if (!toggle || !popover || !value || !current) return;

    toggle.addEventListener('click', function () {
      var opening = popover.hidden;
      Array.prototype.forEach.call(document.querySelectorAll('[data-ypm-model-picker]'), function (other) {
        if (other !== picker) closeModelPicker(other);
      });
      popover.hidden = !opening;
      toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening && search) {
        search.value = '';
        filterModelPicker(picker, '');
        window.setTimeout(function () { search.focus(); }, 0);
      }
    });

    if (search) search.addEventListener('input', function () { filterModelPicker(picker, search.value); });

    picker.addEventListener('click', function (event) {
      var option = event.target.closest('[data-ypm-model-option]');
      if (!option || !picker.contains(option)) return;
      var id = option.getAttribute('data-model-id') || '';
      var label = option.getAttribute('data-model-label') || id;
      value.value = id;
      current.textContent = label;
      Array.prototype.forEach.call(picker.querySelectorAll('[data-ypm-model-option]'), function (candidate) {
        var selected = candidate === option;
        candidate.classList.toggle('is-selected', selected);
        candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
      closeModelPicker(picker);
    });

    picker.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeModelPicker(picker);
        toggle.focus();
      }
    });
  });

  document.addEventListener('click', function (event) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ypm-model-picker]'), function (picker) {
      if (!picker.contains(event.target)) closeModelPicker(picker);
    });
  });

  var modelRefresh = document.querySelector('[data-ypm-model-refresh]');
  if (modelRefresh) modelRefresh.addEventListener('click', function () {
    if (!cfg.ajaxUrl || !cfg.aiModelCatalogNonce || !window.fetch || !window.FormData) return;
    var original = modelRefresh.textContent;
    var body = new FormData();
    body.append('action', 'ypm_ai_model_catalog_refresh');
    body.append('nonce', cfg.aiModelCatalogNonce);
    modelRefresh.disabled = true;
    modelRefresh.textContent = cfg.aiModelRefreshing || '';
    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.success) {
          var message = payload && payload.data && payload.data.message ? payload.data.message : (cfg.aiModelRefreshFailed || '');
          throw new Error(message);
        }
        modelRefresh.textContent = cfg.aiModelRefreshed || '';
        window.setTimeout(function () { window.location.reload(); }, 250);
      })
      .catch(function (error) {
        modelRefresh.disabled = false;
        modelRefresh.textContent = original;
        window.alert(error && error.message ? error.message : (cfg.aiModelRefreshFailed || ''));
      });
  });

  function aiFeedback(button, text, isError) {
    var scope = button ? button.closest('form, .ypm-ai-suggest-bar') : null;
    var feedback = scope ? scope.querySelector('.ypm-ai-feedback') : null;
    if (!feedback && button && button.parentNode) feedback = button.parentNode.querySelector('.ypm-ai-feedback');
    if (!feedback) return;
    feedback.textContent = text || '';
    feedback.classList.toggle('is-error', !!isError);
  }

  function aiPost(data) {
    if (!cfg.ajaxUrl || !cfg.aiSuggestNonce || !window.fetch || !window.FormData) {
      return Promise.reject(new Error(cfg.aiSuggestFailed || ''));
    }
    data.append('action', 'ypm_ai_suggest');
    data.append('nonce', cfg.aiSuggestNonce);
    return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.success || !payload.data) {
          var message = payload && payload.data && payload.data.message ? payload.data.message : (cfg.aiSuggestFailed || '');
          throw new Error(message);
        }
        return payload.data;
      });
  }

  document.addEventListener('click', function (event) {
    var disconnect = event.target.closest('[data-ypm-ai-disconnect]');
    if (disconnect && !window.confirm(cfg.confirmAiDisconnect || '')) {
      event.preventDefault();
      return;
    }

    var termButton = event.target.closest('[data-ypm-ai-term]');
    if (termButton) {
      event.preventDefault();
      if (termButton.disabled) return;
      termButton.disabled = true;
      var originalTermLabel = termButton.textContent;
      termButton.textContent = cfg.aiSuggesting || '';
      aiFeedback(termButton, '', false);
      var termData = new FormData();
      termData.append('kind', 'term');
      termData.append('source_id', termButton.getAttribute('data-source-id') || '');
      termData.append('taxonomy', termButton.getAttribute('data-taxonomy') || '');
      termData.append('target_locale', termButton.getAttribute('data-target-locale') || '');
      aiPost(termData).then(function (data) {
        var name = document.getElementById('ypm-term-name');
        var slug = document.getElementById('ypm-term-slug');
        var description = document.getElementById('ypm-term-description');
        if (name) name.value = data.name || '';
        if (slug) slug.value = data.slug || '';
        if (description) description.value = data.description || '';
        aiFeedback(termButton, cfg.aiSuggestionReady || '', false);
        if (name) name.focus();
      }).catch(function (error) {
        aiFeedback(termButton, error && error.message ? error.message : (cfg.aiSuggestFailed || ''), true);
      }).finally(function () {
        termButton.disabled = false;
        termButton.textContent = originalTermLabel || (cfg.aiSuggest || '');
      });
      return;
    }

    var stringButton = event.target.closest('[data-ypm-ai-string]');
    if (stringButton) {
      event.preventDefault();
      if (stringButton.disabled) return;
      var stringForm = stringButton.closest('form');
      if (!stringForm) return;
      var sourceField = stringForm.querySelector('[name="source_text"]');
      var translationField = stringForm.querySelector('[name="translated_text"]');
      var domainField = stringForm.querySelector('[name="text_domain"]');
      var contextField = stringForm.querySelector('[name="context_name"]');
      var source = stringButton.getAttribute('data-source') || (sourceField ? sourceField.value : '');
      var domain = stringButton.getAttribute('data-domain') || (domainField ? domainField.value : '');
      var context = stringButton.getAttribute('data-context') || (contextField ? contextField.value : '');
      var targetLocale = stringButton.getAttribute('data-target-locale') || '';
      if (!source || !translationField) {
        aiFeedback(stringButton, cfg.aiSourceRequired || '', true);
        if (sourceField) sourceField.focus();
        return;
      }
      stringButton.disabled = true;
      var originalStringLabel = stringButton.textContent;
      stringButton.textContent = cfg.aiSuggesting || '';
      aiFeedback(stringButton, '', false);
      var stringData = new FormData();
      stringData.append('kind', 'string');
      stringData.append('source', source);
      stringData.append('target_locale', targetLocale);
      stringData.append('domain', domain);
      stringData.append('context', context);
      aiPost(stringData).then(function (data) {
        translationField.value = data.translation || '';
        translationField.focus();
        aiFeedback(stringButton, cfg.aiStringSuggestionReady || '', false);
      }).catch(function (error) {
        aiFeedback(stringButton, error && error.message ? error.message : (cfg.aiSuggestFailed || ''), true);
      }).finally(function () {
        stringButton.disabled = false;
        stringButton.textContent = originalStringLabel || (cfg.aiSuggest || '');
      });
    }
  });



  // YMulti 1.54.0 — one control expands/collapses Text, Vision and Image evidence together.
  document.querySelectorAll('[data-ymulti-model-catalog]').forEach(function (catalog) {
    var toggle = catalog.querySelector('[data-ymulti-model-catalog-toggle]');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
      var expanded = catalog.classList.toggle('is-expanded');
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.textContent = expanded ? (toggle.getAttribute('data-collapse-label') || '') : (toggle.getAttribute('data-expand-label') || '');
    });
  });


  // 1.90.0 — Autopilot safe-start presets. These controls only edit the
  // settings form; they never enqueue work or invoke remote compute.
  var autopilotForm = document.getElementById('ymulti-autopilot-settings');
  if (autopilotForm) {
    var safePreset = document.getElementById('ymulti-autopilot-safe-preset');
    var allTargets = document.getElementById('ymulti-autopilot-all-targets');
    var clearTargets = document.getElementById('ymulti-autopilot-clear-targets');
    var targetBoxes = function () { return Array.prototype.slice.call(autopilotForm.querySelectorAll('input[name="target_locales[]"]')); };
    var postTypeBoxes = function () { return Array.prototype.slice.call(autopilotForm.querySelectorAll('input[name="post_types[]"]')); };
    var behaviorNames = ['content_missing', 'content_stale', 'terms', 'media'];
    var markChanged = function () { autopilotForm.dispatchEvent(new Event('change', { bubbles: true })); };

    if (safePreset) safePreset.addEventListener('click', function () {
      targetBoxes().forEach(function (box, index) { box.checked = index === 0; });
      var types = postTypeBoxes();
      var hasPage = types.some(function (box) { return String(box.value) === 'page'; });
      types.forEach(function (box) { box.checked = hasPage ? String(box.value) === 'page' : String(box.value) === 'post'; });
      behaviorNames.forEach(function (name) {
        var box = autopilotForm.querySelector('input[name="' + name + '"]');
        if (box) box.checked = name === 'content_missing';
      });
      Array.prototype.forEach.call(autopilotForm.querySelectorAll('input[name="taxonomies[]"]'), function (box) { box.checked = false; });
      var cap = autopilotForm.querySelector('select[name="daily_cap"]');
      if (cap) cap.value = '25';
      markChanged();
    });
    if (allTargets) allTargets.addEventListener('click', function () { targetBoxes().forEach(function (box) { box.checked = box.getAttribute('data-ymulti-public') !== '0'; }); markChanged(); });
    if (clearTargets) clearTargets.addEventListener('click', function () { targetBoxes().forEach(function (box) { box.checked = false; }); markChanged(); });

    var taxonomyDisclosure = autopilotForm.querySelector('[data-ymulti-taxonomy-scope]');
    var taxonomyCountNode = autopilotForm.querySelector('[data-ymulti-taxonomy-count]');
    var taxonomyBoxes = function () { return Array.prototype.slice.call(autopilotForm.querySelectorAll('input[name="taxonomies[]"]')); };
    var updateTaxonomyDisclosure = function () {
      var count = taxonomyBoxes().filter(function (box) { return box.checked; }).length;
      if (taxonomyCountNode) taxonomyCountNode.textContent = count + ' ' + (cfg.autopilotTaxonomiesSelected || '');
      var termsBehavior = autopilotForm.querySelector('input[name="terms"]');
      if (taxonomyDisclosure && termsBehavior && termsBehavior.checked) taxonomyDisclosure.open = true;
    };
    var scopeSummary = document.getElementById('ymulti-autopilot-scope-summary');
    var updateScopeSummary = function () {
      if (!scopeSummary) return;
      var targetCount = targetBoxes().filter(function (box) { return box.checked; }).length;
      var typeCount = postTypeBoxes().filter(function (box) { return box.checked; }).length;
      var behaviorCount = behaviorNames.filter(function (name) { var box = autopilotForm.querySelector('input[name="' + name + '"]'); return box && box.checked; }).length;
      var cap = autopilotForm.querySelector('select[name="daily_cap"]');
      var capValue = cap ? String(cap.value || '') : '';
      var targetNode = scopeSummary.querySelector('[data-ymulti-scope-targets]');
      var typeNode = scopeSummary.querySelector('[data-ymulti-scope-types]');
      var behaviorNode = scopeSummary.querySelector('[data-ymulti-scope-behaviors]');
      var capNode = scopeSummary.querySelector('[data-ymulti-scope-cap]');
      if (targetNode) targetNode.textContent = targetCount + ' ' + (targetCount === 1 ? (cfg.autopilotTargetOne || '') : (cfg.autopilotTargetMany || ''));
      if (typeNode) typeNode.textContent = typeCount + ' ' + (typeCount === 1 ? (cfg.autopilotTypeOne || '') : (cfg.autopilotTypeMany || ''));
      if (behaviorNode) behaviorNode.textContent = behaviorCount + ' ' + (behaviorCount === 1 ? (cfg.autopilotBehaviorOne || '') : (cfg.autopilotBehaviorMany || ''));
      if (capNode) capNode.textContent = (cfg.autopilotDailyLimit || cfg.autopilotAiCap || '') + ': ' + (capValue === '0' ? (cfg.autopilotUnlimited || '') : (capValue || '—'));
      var launchReady = targetCount > 0 && typeCount > 0 && behaviorCount > 0;
      scopeSummary.classList.toggle('is-incomplete', !launchReady);
      var launchButton = document.getElementById('ymulti-autopilot-launch-button');
      var launchHint = document.getElementById('ymulti-autopilot-launch-hint');
      if (launchButton) launchButton.disabled = !launchReady;
      if (launchHint) launchHint.textContent = launchReady ? cfg.autopilotLaunchReady || '' : cfg.autopilotLaunchIncomplete || '';
    };
    var savedScopeForms = Array.prototype.slice.call(document.querySelectorAll('.ymulti-autopilot-manual-controls form[data-ymulti-autopilot-saved-scope="1"]'));
    var savedSettingsWarning = document.getElementById('ymulti-autopilot-saved-settings-warning');
    var setAutopilotDirty = function () {
      autopilotForm.classList.add('is-dirty');
      savedScopeForms.forEach(function (form) { var button = form.querySelector('button'); if (button) button.disabled = true; });
      if (savedSettingsWarning) savedSettingsWarning.hidden = false;
    };
    autopilotForm.addEventListener('change', function () { updateScopeSummary(); updateTaxonomyDisclosure(); setAutopilotDirty(); });
    autopilotForm.addEventListener('input', function (event) { if (event.target && event.target.matches('select,input')) setAutopilotDirty(); });
    updateScopeSummary();
    updateTaxonomyDisclosure();

    // Launch Center may open Autopilot with a form-only preset. Never save or
    // enqueue from a GET parameter; this only prepares visible controls.
    try {
      var presetName = new URLSearchParams(window.location.search || '').get('preset');
      if (presetName === 'safe_smoke' && safePreset) safePreset.click();
      if (presetName === 'all_public' && allTargets) allTargets.click();
    } catch (e) { /* URLSearchParams unavailable: presets remain optional. */ }
  }
}());

  // YMulti 1.98.0 — shared row selection for translation-capable workspaces.
  function ymultiSyncBulk(scope) {
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-ymulti-bulk-row="' + scope + '"]'));
    var checked = rows.filter(function (row) { return row.checked; });
    rows.forEach(function (row) {
      var tr = row.closest ? row.closest('tr') : null;
      if (tr) tr.classList.toggle('is-ymulti-selected', !!row.checked);
      row.setAttribute('aria-checked', row.checked ? 'true' : 'false');
    });
    var all = document.querySelector('[data-ymulti-bulk-all="' + scope + '"]');
    if (all) {
      all.checked = rows.length > 0 && checked.length === rows.length;
      all.indeterminate = checked.length > 0 && checked.length < rows.length;
    }
    document.querySelectorAll('[data-ymulti-bulk-form="' + scope + '"]').forEach(function (form) {
      var available = form.getAttribute('data-ymulti-bulk-available') !== '0';
      var host = form.querySelector('[data-ymulti-selected-inputs]');
      if (host) {
        host.innerHTML = '';
        checked.forEach(function (row) {
          var input = document.createElement('input'); input.type = 'hidden'; input.name = 'source_ids[]'; input.value = row.value; host.appendChild(input);
        });
      }
      var button = form.querySelector('[data-ymulti-bulk-selected]');
      if (button) button.disabled = !available || checked.length === 0;
      var count = form.querySelector('[data-ymulti-selected-count]');
      if (count) count.textContent = '(' + checked.length + ')';
      if (form.getAttribute('data-ymulti-bulk-status') === '1') {
        var targetField = form.querySelector('[name="target_locale"]');
        var target = targetField ? (targetField.value || '') : '';
        var selectionText = checked.length === 1 ? (cfg.bulkRowSelectedOne || '') : (checked.length > 0 && cfg.bulkRowsSelected ? checked.length + ' ' + cfg.bulkRowsSelected : '');
        var reason = !available ? (form.getAttribute('data-ymulti-bulk-unavailable-message') || (cfg.bulkAiReadyAction || '')) : '';
        var hasStateContent = !!selectionText || !!target || !!reason;
        var state = form.querySelector('[data-ymulti-selection-state]');
        if (!hasStateContent) {
          if (state) state.remove();
        } else {
          if (!state) {
            state = document.createElement('div');
            state.className = 'ymulti-bulk-selection-state';
            state.setAttribute('data-ymulti-selection-state', '1');
            state.setAttribute('aria-live', 'polite');
            form.insertBefore(state, form.firstChild);
          }
          state.innerHTML = '';
          if (selectionText) { var selection = document.createElement('strong'); selection.textContent = selectionText; state.appendChild(selection); }
          if (target) { var targetNode = document.createElement('span'); targetNode.textContent = (cfg.bulkTarget || '') + (cfg.bulkTarget ? ': ' : '') + target.toUpperCase(); state.appendChild(targetNode); }
          if (reason) {
            var locked = document.createElement('span'); locked.className = 'is-locked'; locked.textContent = (cfg.bulkAiAttention ? cfg.bulkAiAttention + ': ' : '') + reason; state.appendChild(locked);
            form.querySelectorAll('button:disabled').forEach(function (disabledButton) { if (!disabledButton.title) disabledButton.title = reason; });
          }
        }
      }
      var confirm = form.querySelector('[data-ymulti-bulk-confirm-all]');
      var allAction = form.querySelector('[data-ymulti-bulk-all-action]');
      if (confirm && allAction) allAction.disabled = !available || !confirm.checked;
    });
  }
  document.addEventListener('change', function (event) {
    var selectAll = event.target.closest('[data-ymulti-bulk-all]');
    if (selectAll) {
      var scope = selectAll.getAttribute('data-ymulti-bulk-all') || '';
      document.querySelectorAll('[data-ymulti-bulk-row="' + scope + '"]').forEach(function (row) { if (!row.disabled) row.checked = selectAll.checked; });
      ymultiSyncBulk(scope); return;
    }
    var row = event.target.closest('[data-ymulti-bulk-row]');
    if (row) { ymultiSyncBulk(row.getAttribute('data-ymulti-bulk-row') || ''); return; }
    var target = event.target.closest('[data-ymulti-bulk-form] [name="target_locale"]');
    if (target) {
      var targetForm = target.closest('[data-ymulti-bulk-form]');
      if (targetForm) ymultiSyncBulk(targetForm.getAttribute('data-ymulti-bulk-form') || '');
      return;
    }
    var confirm = event.target.closest('[data-ymulti-bulk-confirm-all]');
    if (confirm) {
      var form = confirm.closest('[data-ymulti-bulk-form]');
      if (form) ymultiSyncBulk(form.getAttribute('data-ymulti-bulk-form') || '');
    }
  });
  document.querySelectorAll('[data-ymulti-bulk-form]').forEach(function (form) { ymultiSyncBulk(form.getAttribute('data-ymulti-bulk-form') || ''); });
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('[data-ymulti-confirm-message]');
    if (!form) return;
    var message = form.getAttribute('data-ymulti-confirm-message') || '';
    if (message && !window.confirm(message)) event.preventDefault();
  });

  // YMulti 2.1.0 — apply a stored menu AI suggestion only to the review form.
  document.addEventListener('click', function (event) {
    var use = event.target.closest('[data-ymulti-menu-use-ai]');
    if (!use) return;
    event.preventDefault();
    var rowId = use.getAttribute('data-ymulti-menu-use-ai') || '';
    var row = rowId ? document.getElementById(rowId) : null;
    if (!row) return;
    var label = use.getAttribute('data-ymulti-menu-ai-label') || '';
    var input = row.querySelector('input[name="label"]');
    if (input) { input.value = label; input.focus(); input.dispatchEvent(new Event('input', { bubbles: true })); }
    row.hidden = false;
    var opener = document.querySelector('[data-ypm-edit-row="' + rowId + '"]');
    if (opener) opener.setAttribute('aria-expanded', 'true');
  });

// YMulti 2.31.0 — live staged progress for durable content-translation batches.
(function () {
  'use strict';
  function pollYMultiAIJob() {
    var panel = document.querySelector('[data-ymulti-ai-job-panel]');
    if (!panel || panel.getAttribute('data-job-status') !== 'running' || !window.fetch) return;
    var nonce = panel.getAttribute('data-job-nonce') || '';
    if (!nonce) return;
    var body = new URLSearchParams();
    body.set('action', 'ypm_ai_batch_status');
    body.set('nonce', nonce);
    fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      credentials: 'same-origin',
      body: body.toString()
    }).then(function (response) { return response.json(); }).then(function (payload) {
      if (!payload || !payload.success) throw new Error('AI batch status needs a refresh. Reload this page and try again');
      var data = payload.data || {};
      var percent = Math.max(0, Math.min(100, Number(data.percent || 0)));
      var fill = panel.querySelector('[data-ymulti-ai-job-progress-fill]');
      var percentNode = panel.querySelector('[data-ymulti-ai-job-percent]');
      var copy = panel.querySelector('[data-ymulti-ai-job-progress-copy]');
      var stage = panel.querySelector('[data-ymulti-ai-job-stage]');
      var items = panel.querySelector('[data-ymulti-ai-job-items]');
      var requests = panel.querySelector('[data-ymulti-ai-job-requests]');
      var model = panel.querySelector('[data-ymulti-ai-job-model]');
      if (fill) fill.style.width = String(percent) + '%';
      if (percentNode) percentNode.textContent = String(percent) + '%';
      if (copy) copy.textContent = data.message || '';
      if (stage) stage.textContent = Number(data.stage_total || 0) > 1
        ? (cfg.aiStage || '') + ' ' + String(data.stage_current || 0) + ' ' + (cfg.aiStageOf || '') + ' ' + String(data.stage_total || 0)
        : cfg.aiAdaptiveStaging || '';
      if (items) items.textContent = String(data.cursor || 0) + '/' + String(data.total || 0);
      if (requests) requests.textContent = String(data.remote_actions || 0) + ' ' + (cfg.aiRequests || '');
      if (model && data.model) {
        model.innerHTML = '<code></code>';
        model.querySelector('code').textContent = String(data.model);
        model.hidden = false;
      }
      panel.setAttribute('data-job-status', data.status || '');
      if (data.status === 'running') window.setTimeout(pollYMultiAIJob, 3000);
      else window.setTimeout(function () { window.location.reload(); }, 900);
    }).catch(function () {
      window.setTimeout(pollYMultiAIJob, 5000);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', pollYMultiAIJob);
  else pollYMultiAIJob();
}());

// YMulti 2.50.6 — explicit target-language activation for row actions.
(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest ? event.target.closest('[data-ymulti-focus-target]') : null;
    if (!trigger) return;
    event.preventDefault();
    var id = trigger.getAttribute('data-ymulti-focus-target') || '';
    var select = id ? document.getElementById(id) : null;
    if (!select) return;
    var picker = select.nextElementSibling && select.nextElementSibling.matches && select.nextElementSibling.matches('[data-ymulti-language-picker]') ? select.nextElementSibling : null;
    var button = picker ? picker.querySelector('[data-ymulti-language-toggle]') : null;
    if (button) {
      button.focus();
      if (button.getAttribute('aria-expanded') !== 'true') button.click();
    } else {
      select.focus();
    }
    if (select.scrollIntoView) select.scrollIntoView({behavior: 'smooth', block: 'center'});
  });
}());
