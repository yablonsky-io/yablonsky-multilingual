(function () {
  'use strict';


  Array.prototype.slice.call(document.querySelectorAll('[data-ymulti-language-preset-confirm]')).forEach(function (button) {
    button.addEventListener('click', function (event) {
      var message = String(button.getAttribute('data-ymulti-language-preset-confirm') || '');
      if (message !== '' && !window.confirm(message)) event.preventDefault();
    });
  });

  var input = document.getElementById('ypm-language-search');
  var table = document.getElementById('ypm-language-table');
  var empty = document.getElementById('ypm-language-search-empty');
  if (!input || !table) return;

  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-ypm-language-search]'));
  input.addEventListener('input', function () {
    var query = String(input.value || '').toLowerCase().trim();
    var visible = 0;
    rows.forEach(function (row) {
      var hay = String(row.getAttribute('data-ypm-language-search') || '');
      var show = query === '' || hay.indexOf(query) !== -1;
      row.hidden = !show;
      if (show) visible += 1;
    });
    if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
  });
}());
