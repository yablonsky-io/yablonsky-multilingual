(function () {
  'use strict';
  document.addEventListener('change', function (event) {
    var select = event.target && event.target.closest ? event.target.closest('select[data-ymulti-language-switcher]') : null;
    if (!select || !select.value) return;
    window.location.assign(select.value);
  });
})();
