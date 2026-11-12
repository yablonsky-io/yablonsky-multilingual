/* global wp, YPMWidgetsBatchCompat */
(function () {
  'use strict';

  if (!window.wp || !wp.apiFetch || typeof wp.apiFetch.use !== 'function') return;

  var config = window.YPMWidgetsBatchCompat || {};
  var maxItems = parseInt(config.maxItems, 10);
  if (!Number.isFinite(maxItems) || maxItems < 1) return;

  wp.apiFetch.use(function (options, next) {
    var method = String((options && options.method) || 'GET').toUpperCase();
    var path = String((options && options.path) || '');

    if (method === 'OPTIONS' && (path === '/batch/v1' || path === '/batch/v1/')) {
      return Promise.resolve({
        methods: ['POST'],
        endpoints: [
          {
            methods: ['POST'],
            args: {
              requests: {
                required: true,
                maxItems: maxItems
              }
            }
          }
        ]
      });
    }

    return next(options);
  });

  window.__YPM_WIDGETS_BATCH_COMPAT__ = {
    active: true,
    maxItems: maxItems
  };
}());
