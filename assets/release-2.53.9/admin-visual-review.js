/* Visual Review: frame status is observed, not inferred from object existence. */
(() => {
  'use strict';
  const states = document.querySelectorAll('[data-ymulti-review-frame]');
  states.forEach((frame) => {
    const pane = frame.closest('.ymulti-visual-pane');
    const status = pane && pane.querySelector('[data-ymulti-review-status]');
    if (!status) return;
    let completed = false;
    const setStatus = (type) => {
      status.dataset.state = type;
      status.textContent = type === 'ready' ? status.dataset.ready
        : type === 'missing' ? status.dataset.missing : status.dataset.review;
    };
    const loaded = () => {
      completed = true;
      try {
        const doc = frame.contentDocument;
        if (!doc || !doc.documentElement || !doc.body) {
          setStatus('review');
          return;
        }
        // WordPress and most themes mark their 404 template on <body>.
        if (doc.body.classList.contains('error404') || doc.body.classList.contains('is-404')) {
          setStatus('missing');
          return;
        }
        const found = (doc.documentElement.getAttribute('lang') || '').toLowerCase().split(/[-_]/)[0];
        const expected = (frame.dataset.expectedLang || '').toLowerCase().split(/[-_]/)[0];
        setStatus(found && expected && found === expected ? 'ready' : 'review');
      } catch (_) {
        // Cross-origin language domains or restrictive frame policies are not
        // proof of a successful render. Leave the native Open link available.
        setStatus('review');
      }
    };
    frame.addEventListener('load', loaded);
    frame.addEventListener('error', () => { completed = true; setStatus('review'); });
    const startWatch = () => {
      window.setTimeout(() => {
        if (!completed) setStatus('review');
      }, 15000);
    };
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          observer.disconnect();
          startWatch();
        }
      });
      observer.observe(frame);
    } else {
      startWatch();
    }
    // A cached frame may have completed before listeners were attached.
    try {
      if (frame.contentDocument && frame.contentDocument.readyState === 'complete') loaded();
    } catch (_) { /* Cross-origin frames may reject direct inspection. */ }
  });
})();
