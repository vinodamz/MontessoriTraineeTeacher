(() => {
  'use strict';
  document.querySelectorAll('form[data-workspace-external]').forEach(form => {
    const button = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-external-status]');
    let pending = false;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (pending) return;
      pending = true;
      button.disabled = true;
      status.textContent = 'Opening…';
      let popup = null;
      const native = typeof window.LGWorkspace?.postMessage === 'function';
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        if (!native) {
          // Reserve the tab during the user gesture, before the asynchronous POST.
          popup = window.open('about:blank', '_blank');
          if (!popup) throw new Error('Allow pop-ups for this school tool, then try again.');
          popup.opener = null;
          const meta = popup.document.createElement('meta');
          meta.name = 'referrer';
          meta.content = 'no-referrer';
          popup.document.head.appendChild(meta);
          popup.document.title = 'Opening school integration';
          popup.document.body.textContent = 'Opening…';
        }
        const response = await fetch(form.action, {
          method: 'POST', credentials: 'same-origin', redirect: 'error',
          headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams(new FormData(form)), signal: controller.signal,
        });
        const data = await response.json();
        if (!response.ok || data.ok !== true) {
          throw new Error(typeof data.error === 'string' ? data.error : 'Could not open this integration. Try again.');
        }
        if (typeof data.url !== 'string') throw new Error('This integration returned an unsupported address.');
        const url = new URL(data.url);
        if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) {
          throw new Error('This integration returned an unsupported address.');
        }
        if (native) {
          window.LGWorkspace.postMessage(JSON.stringify({
            type: 'lg-workspace', event: 'external', userId: Number(form.dataset.user), url: url.href,
          }));
        } else {
          if (popup.closed) throw new Error('The new tab was closed. Try again to open the integration.');
          popup.location.replace(url.href);
        }
        status.textContent = native
          ? 'Opening in your browser. Your school tool will stay here.'
          : 'Opened in your browser. You can return to this school tool at any time.';
      } catch (error) {
        if (popup && !popup.closed) popup.close();
        status.textContent = error.name === 'AbortError'
          ? 'Opening took too long. Check your connection and try again.'
          : (error instanceof Error ? error.message : 'Could not open this integration. Try again.');
      } finally {
        clearTimeout(timeout);
        button.disabled = false;
        pending = false;
      }
    });
  });
})();
