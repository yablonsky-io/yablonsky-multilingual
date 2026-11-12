(function () {
  'use strict';

  var cfg = window.ypmLayoutQA || {};
  var labels = cfg.labels || {};
  var issues = [];
  var checked = 0;
  var currentIssue = -1;
  var panel = null;
  var issueList = null;
  var summary = null;
  var status = null;
  var copyButton = null;

  function visible(el) {
    if (!el || !el.getBoundingClientRect) return false;
    var style = window.getComputedStyle(el);
    if (!style || style.display === 'none' || style.visibility === 'hidden') return false;
    var rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function directText(el) {
    var text = '';
    Array.prototype.forEach.call(el.childNodes || [], function (node) {
      if (node.nodeType === 3) text += ' ' + String(node.nodeValue || '');
    });
    return text.replace(/\s+/g, ' ').trim();
  }

  function semanticText(el) {
    var tag = String(el.tagName || '').toLowerCase();
    if (tag === 'input') return String(el.value || el.placeholder || '').trim();
    if (tag === 'textarea') return String(el.value || el.placeholder || '').trim();
    if (tag === 'select') return String(el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex].text : '').trim();
    var direct = directText(el);
    if (direct) return direct;
    if (/^(button|a|label|summary|legend|h[1-6]|p|li|td|th|figcaption)$/.test(tag)) {
      return String(el.innerText || '').replace(/\s+/g, ' ').trim();
    }
    return '';
  }

  function skip(el) {
    var tag = String(el.tagName || '').toLowerCase();
    if (!tag || /^(html|body|script|style|noscript|svg|path|canvas|iframe|video|audio|source|meta|link)$/.test(tag)) return true;
    if (el.closest && el.closest('#ypm-layout-qa-panel')) return true;
    if (el.getAttribute && el.getAttribute('aria-hidden') === 'true') return true;
    return false;
  }

  function descriptor(el) {
    var tag = String(el.tagName || 'element').toLowerCase();
    var out = tag;
    if (el.id) out += '#' + String(el.id).slice(0, 80);
    var classes = Array.prototype.slice.call(el.classList || []).filter(function (name) {
      return name.indexOf('ypm-layout-qa') !== 0;
    }).slice(0, 3);
    if (classes.length) out += '.' + classes.join('.');
    return out;
  }

  function addIssue(el, type, severity, text, rect) {
    issues.push({
      element: el,
      type: type,
      severity: severity,
      selector: descriptor(el),
      text: String(text || '').slice(0, 180),
      rect: {
        left: Math.round(rect.left), top: Math.round(rect.top),
        width: Math.round(rect.width), height: Math.round(rect.height)
      },
      clientWidth: el.clientWidth || 0,
      scrollWidth: el.scrollWidth || 0,
      clientHeight: el.clientHeight || 0,
      scrollHeight: el.scrollHeight || 0
    });
  }

  function scanElement(el) {
    if (skip(el) || !visible(el)) return;
    var text = semanticText(el);
    if (!text) return;
    checked += 1;
    var rect = el.getBoundingClientRect();
    var style = window.getComputedStyle(el);
    var overflowX = String(style.overflowX || style.overflow || 'visible');
    var overflowY = String(style.overflowY || style.overflow || 'visible');
    var whiteSpace = String(style.whiteSpace || 'normal');
    var scrollX = el.scrollWidth > el.clientWidth + 2;
    var scrollY = el.scrollHeight > el.clientHeight + 2;
    var clipX = overflowX === 'hidden' || overflowX === 'clip';
    var clipY = overflowY === 'hidden' || overflowY === 'clip';
    var noWrap = /nowrap/.test(whiteSpace);
    var viewportWidth = document.documentElement.clientWidth || window.innerWidth || 0;
    var viewportEscape = rect.left < -2 || rect.right > viewportWidth + 2;

    if (scrollX && noWrap) addIssue(el, 'nowrap', clipX ? 'high' : 'review', text, rect);
    else if (scrollX && clipX) addIssue(el, 'horizontal', 'high', text, rect);
    if (scrollY && clipY) addIssue(el, 'vertical', 'high', text, rect);
    if (viewportEscape) addIssue(el, 'viewport', 'review', text, rect);
  }

  function report() {
    return {
      schema: 'yablonsky.multilingual.layout-qa/v1',
      time: new Date().toISOString(),
      post_id: cfg.postId || 0,
      title: cfg.title || document.title,
      url: window.location.href,
      viewport: {
        width: document.documentElement.clientWidth || window.innerWidth || 0,
        height: document.documentElement.clientHeight || window.innerHeight || 0,
        device_pixel_ratio: window.devicePixelRatio || 1
      },
      checked: checked,
      issue_count: issues.length,
      issues: issues.map(function (item) {
        return {
          type: item.type,
          severity: item.severity,
          selector: item.selector,
          text: item.text,
          rect: item.rect,
          client_width: item.clientWidth,
          scroll_width: item.scrollWidth,
          client_height: item.clientHeight,
          scroll_height: item.scrollHeight
        };
      })
    };
  }

  function issueLabel(type) {
    if (type === 'horizontal') return labels.horizontal || '';
    if (type === 'vertical') return labels.vertical || '';
    if (type === 'viewport') return labels.viewport || '';
    return labels.nowrap || '';
  }

  function focusIssue(index) {
    if (!issues.length) return;
    currentIssue = Math.max(0, Math.min(issues.length - 1, index));
    Array.prototype.forEach.call(document.querySelectorAll('.ypm-layout-qa-target'), function (el) {
      el.classList.remove('ypm-layout-qa-target');
    });
    var item = issues[currentIssue];
    if (!item || !item.element) return;
    item.element.classList.add('ypm-layout-qa-target');
    item.element.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
  }

  function render() {
    if (!panel) return;
    var pass = issues.length === 0;
    panel.classList.toggle('is-pass', pass);
    panel.classList.toggle('is-review', !pass);
    if (status) status.textContent = pass ? (labels.pass || '') : (labels.review || '');
    if (summary) summary.textContent = pass
      ? String(checked) + ' ' + (labels.checked || '')
      : String(issues.length) + ' ' + (labels.issues || '') + ' · ' + String(checked) + ' ' + (labels.checked || '');
    if (!issueList) return;
    issueList.innerHTML = '';
    if (!issues.length) {
      var empty = document.createElement('p');
      empty.className = 'ypm-layout-qa-empty';
      empty.textContent = (labels.none || '') + ' ' + (labels.resize || '');
      issueList.appendChild(empty);
      return;
    }
    issues.forEach(function (item, index) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'ypm-layout-qa-issue ypm-layout-qa-issue--' + item.severity;
      button.innerHTML = '<strong>' + escapeHtml(issueLabel(item.type)) + '</strong><code>' + escapeHtml(item.selector) + '</code><span>' + escapeHtml(item.text) + '</span>';
      button.addEventListener('click', function () { focusIssue(index); });
      issueList.appendChild(button);
    });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function saveSummary() {
    if (!cfg.ajaxUrl || !cfg.saveNonce || !cfg.postId || !window.fetch) return;
    var highCount = issues.filter(function (item) { return item.severity === 'high'; }).length;
    var data = new URLSearchParams();
    data.set('action', 'ypm_ai_layout_qa_save');
    data.set('nonce', String(cfg.saveNonce));
    data.set('post_id', String(cfg.postId));
    data.set('status', issues.length ? 'review' : 'pass');
    data.set('issue_count', String(issues.length));
    data.set('high_count', String(highCount));
    data.set('checked', String(checked));
    data.set('viewport_width', String(document.documentElement.clientWidth || window.innerWidth || 0));
    data.set('viewport_height', String(document.documentElement.clientHeight || window.innerHeight || 0));
    window.fetch(String(cfg.ajaxUrl), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: data.toString()
    }).catch(function () {});
  }

  function scan() {
    issues = [];
    checked = 0;
    currentIssue = -1;
    Array.prototype.forEach.call(document.querySelectorAll('.ypm-layout-qa-target'), function (el) {
      el.classList.remove('ypm-layout-qa-target');
    });
    Array.prototype.forEach.call(document.body.querySelectorAll('*'), scanElement);
    render();
    saveSummary();
  }

  function copyReport() {
    var text = JSON.stringify(report(), null, 2);
    function done() {
      if (!copyButton) return;
      var original = copyButton.textContent;
      copyButton.textContent = labels.copied || '';
      window.setTimeout(function () { copyButton.textContent = original; }, 1500);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(function () {});
      return;
    }
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', 'readonly');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); done(); } catch (error) {}
    document.body.removeChild(area);
  }

  function buildPanel() {
    panel = document.createElement('aside');
    panel.id = 'ypm-layout-qa-panel';
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', labels.title || '');
    panel.innerHTML = '<div class="ypm-layout-qa-head"><div><small>Yablonsky Multilingual</small><strong>' + escapeHtml(labels.title || '') + '</strong></div><span data-ypm-qa-status></span></div>' +
      '<div class="ypm-layout-qa-summary" data-ypm-qa-summary></div>' +
      '<div class="ypm-layout-qa-actions"><button type="button" data-ypm-qa-rescan>' + escapeHtml(labels.rescan || '') + '</button><button type="button" data-ypm-qa-next>' + escapeHtml(labels.next || '') + '</button><button type="button" data-ypm-qa-copy>' + escapeHtml(labels.copy || '') + '</button><button type="button" data-ypm-qa-close aria-label="' + escapeHtml(labels.close || '') + '">×</button></div>' +
      '<div class="ypm-layout-qa-list" data-ypm-qa-list></div>';
    document.body.appendChild(panel);
    issueList = panel.querySelector('[data-ypm-qa-list]');
    summary = panel.querySelector('[data-ypm-qa-summary]');
    status = panel.querySelector('[data-ypm-qa-status]');
    copyButton = panel.querySelector('[data-ypm-qa-copy]');
    panel.querySelector('[data-ypm-qa-rescan]').addEventListener('click', scan);
    panel.querySelector('[data-ypm-qa-next]').addEventListener('click', function () {
      if (!issues.length) return;
      focusIssue((currentIssue + 1) % issues.length);
    });
    copyButton.addEventListener('click', copyReport);
    panel.querySelector('[data-ypm-qa-close]').addEventListener('click', function () {
      panel.hidden = true;
      Array.prototype.forEach.call(document.querySelectorAll('.ypm-layout-qa-target'), function (el) {
        el.classList.remove('ypm-layout-qa-target');
      });
    });
  }

  function start() {
    buildPanel();
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(scan);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
