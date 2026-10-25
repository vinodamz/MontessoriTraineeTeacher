(() => {
  'use strict';
  const userId = Number(document.currentScript.dataset.user);
  const send = (event, data = {}) => {
    const message = {type: 'lg-workspace', event, userId, ...data};
    if (window.parent !== window) window.parent.postMessage(message, 'https://app.thelittlegraduates.in');
    if (window.LGWorkspace) window.LGWorkspace.postMessage(JSON.stringify(message));
  };
  // Track the position, rather than history.length (which includes forward entries).
  const prefix = 'lg-workspace-index:';
  const previous = window.name.startsWith(prefix) ? Number(window.name.slice(prefix.length)) : -1;
  const index = Number.isInteger(history.state?.lgWorkspaceIndex)
    ? history.state.lgWorkspaceIndex : previous + 1;
  history.replaceState({...history.state, lgWorkspaceIndex: index}, '');
  window.name = prefix + index;
  window.addEventListener('pageshow', () => send('ready', {title: document.title, canGoBack: index > 0}));
  window.addEventListener('pagehide', () => send('loading'));
  document.addEventListener('click', event => {
    const anchor = event.target.closest('a[href]');
    if (!anchor || event.defaultPrevented || anchor.hasAttribute('download')) return;
    // Attachments use the browser's download/PDF facilities.
    if (/\/(?:download|media|file)\.php$/.test(new URL(anchor.href, location.href).pathname)) return;
    const url = new URL(anchor.href, location.href);
    if (url.origin === location.origin) {
      if (url.pathname === '/logout.php' || url.pathname === '/login.php') {
        event.preventDefault();
        send('error', {message: 'Use Sign out in the app’s School tab.'});
      } else {
        anchor.removeAttribute('target');
      }
    } else if (['https:', 'http:', 'tel:', 'mailto:'].includes(url.protocol)) {
      event.preventDefault();
      send('external', {url: url.href});
    }
  });
  window.addEventListener('message', event => {
    if (event.origin !== 'https://app.thelittlegraduates.in' || event.source !== parent) return;
    if (event.data?.type === 'lg-workspace-back') history.back();
  });
})();
