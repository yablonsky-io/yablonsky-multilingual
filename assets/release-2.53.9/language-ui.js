(function () {
  'use strict';
  var i18n = window.YMultiLanguageUII18n || {};

  function text(value) { return String(value || ''); }
  function tokens(value) { return text(value).toLowerCase().trim().split(/\s+/).filter(Boolean); }
  function matches(haystack, query) {
    var hay = text(haystack).toLowerCase();
    var parts = tokens(query);
    return parts.length === 0 || parts.every(function (part) { return hay.indexOf(part) !== -1; });
  }

  function closePicker(picker) {
    if (!picker) return;
    var pop = picker.querySelector('[data-ymulti-language-popover]');
    var toggle = picker.querySelector('[data-ymulti-language-toggle]');
    if (pop) pop.hidden = true;
    picker.classList.remove('is-open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  }

  function positionPicker(picker) {
    if (!picker) return;
    var pop = picker.querySelector('[data-ymulti-language-popover]');
    var toggle = picker.querySelector('[data-ymulti-language-toggle]');
    if (!pop || !toggle || pop.hidden || window.innerWidth <= 782) {
      picker.classList.remove('is-align-right');
      return;
    }
    picker.classList.remove('is-align-right');
    var rect = toggle.getBoundingClientRect();
    var desired = Math.max(rect.width, 310);
    var gutter = 12;
    if (rect.left + desired > window.innerWidth - gutter) picker.classList.add('is-align-right');
  }

  function filterPicker(picker, query) {
    var visible = 0;
    picker.querySelectorAll('[data-ymulti-language-option]').forEach(function (option) {
      var show = matches(option.getAttribute('data-language-search') || option.textContent || '', query);
      option.hidden = !show;
      option.classList.toggle('is-filtered-out', !show);
      option.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    var empty = picker.querySelector('[data-ymulti-language-empty]');
    if (empty) empty.hidden = visible !== 0;
  }

  function upgradeSelect(select) {
    if (!select || select.dataset.ymultiLanguageEnhanced === '1') return;
    // Native WordPress list-table filters must keep native compact geometry.
    // Replacing ypm_lang with the searchable picker makes its block wrapper
    // consume the available row width and pushes third-party filters down.
    if (select.hasAttribute('data-ymulti-native-select')) return;
    var options = Array.prototype.slice.call(select.options || []);
    if (!options.length) return;
    var generic = select.hasAttribute('data-ymulti-searchable-select') || !/^(locale|target_locale|source_locale|ypm_lang|ypm_lang_code)$/.test(select.name || '');
    select.dataset.ymultiLanguageEnhanced = '1';
    var wasRequired = !!select.required;
    select.required = false;
    select.classList.add('ymulti-language-native');
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;

    var picker = document.createElement('div');
    picker.className = 'ymulti-language-picker' + (generic ? ' ymulti-language-picker--generic' : '');
    picker.dataset.ymultiLanguagePicker = '1';
    if (wasRequired) picker.dataset.ymultiLanguageRequired = '1';
    var isDisabled = !!select.disabled;
    picker.classList.toggle('is-disabled', isDisabled);

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'ymulti-language-picker__button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    button.dataset.ymultiLanguageToggle = '1';
    button.disabled = isDisabled;
    button.setAttribute('aria-disabled', isDisabled ? 'true' : 'false');
    var current = document.createElement('span');
    current.dataset.ymultiLanguageCurrent = '1';
    var caret = document.createElement('span');
    caret.className = 'ymulti-language-picker__caret';
    caret.setAttribute('aria-hidden', 'true');
    caret.textContent = '⌄';
    button.appendChild(current); button.appendChild(caret);

    var popover = document.createElement('div');
    popover.className = 'ymulti-language-picker__popover';
    popover.hidden = true;
    popover.dataset.ymultiLanguagePopover = '1';
    var searchWrap = document.createElement('div');
    searchWrap.className = 'ymulti-language-picker__search';
    var search = document.createElement('input');
    search.type = 'search'; search.autocomplete = 'off';
    search.placeholder = generic ? (i18n.searchGeneric || i18n.searchLabel || '') : (i18n.searchPlaceholder || '');
    search.setAttribute('aria-label', generic ? (i18n.searchGeneric || i18n.searchLabel || '') : (i18n.searchLabel || ''));
    search.dataset.ymultiLanguageSearch = '1';
    searchWrap.appendChild(search); popover.appendChild(searchWrap);
    var list = document.createElement('div');
    list.className = 'ymulti-language-picker__list';
    list.setAttribute('role', 'listbox');

    function refreshCurrent() {
      var selected = select.options[select.selectedIndex] || options[0];
      current.textContent = selected ? selected.textContent : (i18n.chooseLanguage || '');
      picker.classList.toggle('is-invalid', picker.dataset.ymultiValidationAttempted === '1' && wasRequired && !select.value);
      var disabledNow = !!select.disabled; picker.classList.toggle('is-disabled', disabledNow); button.disabled = disabledNow; button.setAttribute('aria-disabled', disabledNow ? 'true' : 'false');
    }

    options.forEach(function (sourceOption) {
      var option = document.createElement('button');
      option.type = 'button'; option.className = 'ymulti-language-picker__option';
      option.dataset.ymultiLanguageOption = '1';
      option.dataset.languageValue = sourceOption.value;
      option.dataset.languageSearch = (sourceOption.textContent || '') + ' ' + sourceOption.value;
      option.setAttribute('role', 'option');
      var strong = document.createElement('strong'); strong.textContent = sourceOption.textContent || sourceOption.value || (i18n.allLanguages || '');
      option.appendChild(strong);
      if (!generic) { var small = document.createElement('small'); small.textContent = sourceOption.value ? sourceOption.value : 'ALL'; option.appendChild(small); }
      if (sourceOption.selected) { option.classList.add('is-selected'); option.setAttribute('aria-selected','true'); }
      else option.setAttribute('aria-selected','false');
      list.appendChild(option);
    });
    var empty = document.createElement('div'); empty.className = 'ymulti-language-picker__empty'; empty.dataset.ymultiLanguageEmpty='1'; empty.hidden=true; empty.textContent=i18n.matchesZero || '';
    popover.appendChild(list); popover.appendChild(empty);
    picker.appendChild(button); picker.appendChild(popover);
    select.parentNode.insertBefore(picker, select.nextSibling);
    refreshCurrent();

    button.addEventListener('click', function () {
      if (select.disabled) return;
      var opening = popover.hidden;
      document.querySelectorAll('[data-ymulti-language-picker]').forEach(function (other) { if (other !== picker) closePicker(other); });
      popover.hidden = !opening; picker.classList.toggle('is-open', opening); button.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) {
        search.value=''; filterPicker(picker,''); positionPicker(picker);
        window.setTimeout(function(){ positionPicker(picker); search.focus(); },0);
      }
    });
    search.addEventListener('input', function () { filterPicker(picker, search.value); });
    picker.addEventListener('click', function (event) {
      var option = event.target.closest ? event.target.closest('[data-ymulti-language-option]') : null;
      if (!option || select.disabled) return;
      select.value = option.dataset.languageValue || '';
      Array.prototype.forEach.call(list.querySelectorAll('[data-ymulti-language-option]'), function (candidate) {
        var chosen = candidate === option; candidate.classList.toggle('is-selected', chosen); candidate.setAttribute('aria-selected', chosen ? 'true' : 'false');
      });
      refreshCurrent(); closePicker(picker);
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    picker.addEventListener('keydown', function(event){ if(event.key==='Escape'){ closePicker(picker); button.focus(); } });
    if (select.form && wasRequired) select.form.addEventListener('submit', function (event) { picker.dataset.ymultiValidationAttempted = '1'; if (!select.value) { event.preventDefault(); picker.classList.add('is-invalid','is-open'); popover.hidden=false; button.setAttribute('aria-expanded','true'); window.setTimeout(function(){search.focus();},0); } });
  }

  function upgradeWithin(root) {
    if (!root) return;
    root.querySelectorAll('select[name="locale"],select[name="target_locale"],select[name="source_locale"],select[name="ypm_lang"],select[name="ypm_lang_code"],select[data-ymulti-searchable-select]').forEach(upgradeSelect);
    root.querySelectorAll('.ypm-admin select:not([multiple])').forEach(function (select) {
      if (select.dataset.ymultiLanguageEnhanced === '1') return;
      if ((select.options || []).length > 8) upgradeSelect(select);
    });
  }

  // 2.49.6: language selectors and long admin option lists share one searchable control.
  upgradeWithin(document);


  var drawer = null, drawerBackdrop = null, drawerBody = null, lastDrawerTrigger = null;
  function ensureDrawer() {
    if (drawer) return;
    drawerBackdrop = document.createElement('div'); drawerBackdrop.className='ymulti-language-drawer-backdrop';
    drawer = document.createElement('aside'); drawer.className='ymulti-language-drawer'; drawer.setAttribute('role','dialog'); drawer.setAttribute('aria-modal','true'); drawer.setAttribute('aria-hidden','true');
    var bar=document.createElement('div'); bar.className='ymulti-language-drawer__bar';
    var title=document.createElement('strong'); title.textContent=i18n.drawerTitle || '';
    var close=document.createElement('button'); close.type='button'; close.className='button'; close.textContent=i18n.close || '';
    bar.appendChild(title); bar.appendChild(close); drawerBody=document.createElement('div'); drawerBody.className='ymulti-language-drawer__body'; drawer.appendChild(bar); drawer.appendChild(drawerBody); document.body.appendChild(drawerBackdrop); document.body.appendChild(drawer);
    close.addEventListener('click', closeDrawer); drawerBackdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape' && drawer.classList.contains('is-open')) closeDrawer(); });
  }
  function closeDrawer(){ if(!drawer)return; drawer.classList.remove('is-open'); drawerBackdrop.classList.remove('is-open'); drawer.setAttribute('aria-hidden','true'); document.body.classList.remove('ymulti-language-drawer-open'); if(lastDrawerTrigger && document.documentElement.contains(lastDrawerTrigger)) lastDrawerTrigger.focus(); }
  function openLocaleDrawer(trigger){ ensureDrawer(); var raw=trigger.getAttribute('data-ymulti-language-options')||'[]'; var rows=[]; try{rows=JSON.parse(raw);}catch(e){rows=[];} if(!Array.isArray(rows)||!rows.length)return; lastDrawerTrigger=trigger; drawerBody.innerHTML=''; var input=document.createElement('input'); input.type='search'; input.className='ymulti-language-drawer__search'; input.placeholder=i18n.searchPlaceholder || ''; input.setAttribute('aria-label',i18n.searchLabel || ''); input.dataset.ymultiDrawerSearch='1'; var list=document.createElement('div'); list.className='ymulti-language-drawer__list'; rows.forEach(function(data){ var row=document.createElement(data.url?'a':'div'); if(data.url)row.href=data.url; row.className='ymulti-language-drawer__row'; row.dataset.ymultiDrawerRow='1'; row.dataset.search=data.search||((data.name||'')+' '+(data.native||'')+' '+(data.code||'')); var name=document.createElement('span'); name.className='ymulti-language-drawer__name'; var strong=document.createElement('strong'); strong.textContent=data.name||data.code||''; name.appendChild(strong); if(data.native){var native=document.createElement('span');native.textContent=data.native;name.appendChild(native);} var code=document.createElement('code');code.className='ymulti-language-drawer__code';code.textContent=data.code||''; var state=document.createElement('span');state.className='ymulti-language-drawer__state'+(data.current?' is-current':'');state.textContent=data.state||''; row.appendChild(name);row.appendChild(code);row.appendChild(state);list.appendChild(row); }); drawerBody.appendChild(input);drawerBody.appendChild(list); drawerBackdrop.classList.add('is-open'); drawer.classList.add('is-open'); drawer.setAttribute('aria-hidden','false'); document.body.classList.add('ymulti-language-drawer-open'); input.addEventListener('input',function(){ var q=input.value; drawerBody.querySelectorAll('[data-ymulti-drawer-row]').forEach(function(row){row.hidden=!matches(row.getAttribute('data-search')||row.textContent||'',q);}); }); window.setTimeout(function(){input.focus();},0); }
  document.addEventListener('click', function(event){ var overflow=event.target.closest ? event.target.closest('[data-ymulti-language-overflow]') : null; if(overflow){event.preventDefault();openLocaleDrawer(overflow);} });

  document.addEventListener('click', function(event){ document.querySelectorAll('[data-ymulti-language-picker]').forEach(function(p){ if(!p.contains(event.target)) closePicker(p); }); });
  function repositionOpenPickers(){ document.querySelectorAll('[data-ymulti-language-picker].is-open').forEach(positionPicker); }
  window.addEventListener('resize', repositionOpenPickers);
  window.addEventListener('scroll', repositionOpenPickers, true);
  window.YMultiLanguageUI = { matches: matches, upgradeWithin: upgradeWithin, openLocaleDrawer: openLocaleDrawer };
}());
