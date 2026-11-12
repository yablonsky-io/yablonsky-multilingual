(function () {
  'use strict';

  var config = window.YPMAdminContent || {};
  var drawer = null;
  var backdrop = null;
  var body = null;
  var cache = {};
  var pending = {};
  var lastTrigger = null;

  function ensureDrawer() {
    if (drawer) return;
    backdrop = document.createElement('div');
    backdrop.className = 'ypm-family-drawer-backdrop';
    drawer = document.createElement('aside');
    drawer.className = 'ypm-family-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-hidden', 'true');

    var bar = document.createElement('div');
    bar.className = 'ypm-family-drawer__bar';
    var title = document.createElement('strong');
    title.textContent = 'YMulti';
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'button';
    close.textContent = config.close || '';
    close.addEventListener('click', closeDrawer);
    bar.appendChild(title);
    bar.appendChild(close);

    body = document.createElement('div');
    body.className = 'ypm-family-drawer__body';
    drawer.appendChild(bar);
    drawer.appendChild(body);
    document.body.appendChild(backdrop);
    document.body.appendChild(drawer);
    backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && drawer && drawer.classList.contains('is-open')) closeDrawer();
    });
  }

  function openDrawer(trigger) {
    ensureDrawer();
    if (trigger) lastTrigger = trigger;
    backdrop.classList.add('is-open');
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ypm-family-drawer-open');
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ypm-family-drawer-open');
    if (lastTrigger && document.documentElement.contains(lastTrigger)) lastTrigger.focus();
  }

  function bindSearch(root) {
    var input = root.querySelector('.ypm-family-search');
    if (!input) return;
    input.addEventListener('input', function () {
      var query = String(input.value || '').toLowerCase().trim();
      root.querySelectorAll('.ypm-family-row').forEach(function (row) {
        var hay = String(row.getAttribute('data-search') || '');
        row.hidden = query !== '' && hay.indexOf(query) === -1;
      });
    });
    input.focus();
  }

  function showHtml(html) {
    body.innerHTML = html;
    bindSearch(body);
  }

  function inlinePayload(link) {
    var raw = link.getAttribute('data-family-payload');
    if (!raw) return null;
    try {
      var payload = JSON.parse(raw);
      if (!payload || !Array.isArray(payload.rows) || !payload.rows.length) return null;
      return payload;
    } catch (e) {
      return null;
    }
  }

  function showPayload(payload) {
    body.innerHTML = '';

    var panel = document.createElement('div');
    panel.className = 'ypm-family-panel';

    var title = document.createElement('strong');
    title.className = 'ypm-family-panel__title';
    title.textContent = payload.title || '';
    panel.appendChild(title);

    if (payload.coverage) {
      var coverage = document.createElement('div');
      coverage.className = 'ypm-family-panel__coverage';
      coverage.textContent = payload.coverage;
      panel.appendChild(coverage);
    }

    var input = document.createElement('input');
    input.className = 'ypm-family-search';
    input.type = 'search';
    input.placeholder = config.searchPlaceholder || 'Search by language, native name, or code…';
    panel.appendChild(input);

    var list = document.createElement('div');
    list.className = 'ypm-family-list';

    payload.rows.forEach(function (data) {
      var row = document.createElement('div');
      row.className = 'ypm-family-row';
      row.setAttribute('data-search', String(data.search || ''));

      var name = document.createElement('div');
      name.className = 'ypm-family-row__name';
      var strong = document.createElement('strong');
      strong.textContent = data.name || data.code || '';
      name.appendChild(strong);
      if (data.native && data.native !== data.name) {
        var nativeName = document.createElement('span');
        nativeName.textContent = data.native;
        name.appendChild(nativeName);
      }

      var code = document.createElement('code');
      code.className = 'ypm-family-row__code';
      code.textContent = data.code || '';

      var action = document.createElement('div');
      action.className = 'ypm-family-row__action';
      if (data.current) {
        var current = document.createElement('span');
        current.className = 'ypm-family-status is-current';
        current.textContent = data.state || config.current || 'Current';
        action.appendChild(current);
      } else if (data.url) {
        var link = document.createElement('a');
        link.className = 'button button-small' + (data.primary ? ' button-primary' : '');
        link.href = data.url;
        link.textContent = data.state || '';
        action.appendChild(link);
      }

      row.appendChild(name);
      row.appendChild(code);
      row.appendChild(action);
      list.appendChild(row);
    });

    panel.appendChild(list);
    body.appendChild(panel);
    bindSearch(body);
  }

  function requestFor(link) {
    var postId = link.getAttribute('data-post-id');
    if (postId) return {
      key: 'post:' + postId,
      action: 'ypm_translation_family',
      nonce: config.postNonce || config.nonce || '',
      fields: { post_id: postId }
    };
    var termId = link.getAttribute('data-term-id');
    var taxonomy = link.getAttribute('data-taxonomy');
    if (termId && taxonomy) return {
      key: 'term:' + taxonomy + ':' + termId,
      action: 'ypm_term_translation_family',
      nonce: config.termNonce || '',
      fields: { term_id: termId, taxonomy: taxonomy }
    };
    var menuItemId = link.getAttribute('data-menu-item-id');
    if (menuItemId) {
      var menuSource = link.getAttribute('data-family-source') || '';
      return {
        key: 'menu:' + menuItemId + ':' + menuSource,
        action: 'ymulti_menu_translation_family',
        nonce: config.menuNonce || '',
        fields: { menu_item_id: menuItemId, source_locale: menuSource }
      };
    }
    var mediaId = link.getAttribute('data-media-id');
    if (mediaId) {
      var mediaSource = link.getAttribute('data-family-source') || '';
      return {
        key: 'media:' + mediaId + ':' + mediaSource,
        action: 'ymulti_media_translation_family',
        nonce: config.mediaNonce || '',
        fields: { attachment_id: mediaId, source_locale: mediaSource }
      };
    }
    return null;
  }

  function fetchFamily(request) {
    if (cache[request.key]) return Promise.resolve(cache[request.key]);
    if (pending[request.key]) return pending[request.key];
    var params = new URLSearchParams();
    params.set('action', request.action);
    Object.keys(request.fields).forEach(function (name) { params.set(name, request.fields[name]); });
    params.set('_ajax_nonce', request.nonce);
    pending[request.key] = window.fetch(config.ajaxUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.success || !payload.data || !payload.data.html) throw new Error('Invalid payload');
      cache[request.key] = payload.data.html;
      return cache[request.key];
    }).finally(function () { delete pending[request.key]; });
    return pending[request.key];
  }

  function loadFamily(link) {
    var payload = inlinePayload(link);
    if (payload) {
      openDrawer(link);
      showPayload(payload);
      return;
    }

    var request = requestFor(link);
    if (!request || !config.ajaxUrl || !request.nonce || !window.fetch) {
      return;
    }
    openDrawer(link);
    if (cache[request.key]) { showHtml(cache[request.key]); return; }
    body.textContent = config.loading || '';
    fetchFamily(request).then(showHtml).catch(function () {
      body.textContent = config.loadFailed || 'Translation family could not be loaded. Reload the page and try again.';
    });
  }

  // Family data is requested only after an explicit click. Passive pointer/focus
  // movement across long admin tables must not spawn background AJAX/SQL work.

  document.addEventListener('click', function (event) {
    var target = event.target && event.target.nodeType === 1 ? event.target : event.target && event.target.parentElement;
    var link = target && target.closest ? target.closest('.ypm-family-more[data-post-id], .ypm-family-more[data-term-id][data-taxonomy], .ypm-family-more[data-menu-item-id], .ypm-family-more[data-media-id]') : null;
    if (!link) return;
    event.preventDefault();
    event.stopPropagation();
    loadFamily(link);
  }, true);
}());


// YMulti 2.35.0 — Localized URL-base language tiles keep one editor open at a time.
document.addEventListener('toggle', function (event) {
  var active = event.target;
  if (!(active instanceof HTMLDetailsElement) || !active.matches('[data-ymulti-routing-language]') || !active.open) return;
  var matrix = active.closest('[data-ymulti-routing-matrix]');
  if (!matrix) return;
  matrix.querySelectorAll('[data-ymulti-routing-language][open]').forEach(function (panel) {
    if (panel !== active) panel.open = false;
  });
}, true);
