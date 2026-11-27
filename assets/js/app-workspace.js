(() => {
  'use strict';
  // The same session may also be open in a normal MTT browser tab.
  if (window.parent === window && !window.LGWorkspace) return;
  document.documentElement.classList.add('lg-workspace');
  // Keep wide semantic tables inside the tool viewport, including dynamic rows.
  const tableRegions = new Map();
  const syncTableRegions = () => {
    tableRegions.forEach((label, region) => {
      const overflowing = region.clientWidth > 0 && region.scrollWidth > region.clientWidth + 1;
      if (overflowing) {
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', label + ' (scroll horizontally)');
        region.setAttribute('tabindex', '0');
      } else {
        ['role', 'aria-label', 'tabindex'].forEach(name => region.removeAttribute(name));
      }
    });
  };
  const tableResize = typeof ResizeObserver === 'function' ? new ResizeObserver(syncTableRegions) : null;
  const wrapTables = () => {
    document.querySelectorAll('table').forEach((table, index) => {
      if (table.closest('.lg-table-scroll')) return;
      // Respect a module's existing table scroll container.
      const parent = table.parentElement;
      if (!parent || ['auto', 'scroll'].includes(getComputedStyle(parent).overflowX)) return;
      const region = document.createElement('div');
      region.className = 'lg-table-scroll';
      const labelledBy = (table.getAttribute('aria-labelledby') || '').split(/\s+/)
        .map(id => document.getElementById(id)?.textContent?.trim() || '').join(' ').trim();
      const label = table.caption?.textContent?.trim() || table.getAttribute('aria-label')?.trim()
        || labelledBy || document.title + ' table ' + (index + 1);
      parent.insertBefore(region, table);
      region.appendChild(table);
      tableRegions.set(region, label);
      tableResize?.observe(region);
      tableResize?.observe(table);
    });
    syncTableRegions();
  };
  wrapTables();
  window.addEventListener('resize', syncTableRegions);
  if (typeof MutationObserver === 'function') {
    new MutationObserver(wrapTables).observe(document.body, {childList: true, subtree: true});
  }

  const userId = Number(document.currentScript.dataset.user);
  const send = (event, data = {}) => {
    const message = {type: 'lg-workspace', event, userId, ...data};
    if (window.parent !== window) window.parent.postMessage(message, 'https://app.thelittlegraduates.in');
    if (window.LGWorkspace) window.LGWorkspace.postMessage(JSON.stringify(message));
  };
  // Track the position, rather than history.length (which includes forward entries).
  const prefix = 'lg-workspace-index:';
  const saved = window.name.startsWith(prefix) ? Number(window.name.slice(prefix.length)) : -1;
  const previous = Number.isSafeInteger(saved) && saved >= 0 ? saved : -1;
  let index = Number.isSafeInteger(history.state?.lgWorkspaceIndex) && history.state.lgWorkspaceIndex >= 0
    ? history.state.lgWorkspaceIndex : previous + 1;
  const remember = () => {
    history.replaceState({...history.state, lgWorkspaceIndex: index, lgWorkspaceUrl: location.href}, '');
    window.name = prefix + index;
  };
  const ready = () => {
    // A cached page's script is not re-run when Back restores it.
    window.name = prefix + index;
    send('ready', {title: document.title, canGoBack: index > 0});
  };
  const changed = () => {
    const state = history.state;
    if (Number.isSafeInteger(state?.lgWorkspaceIndex) && state.lgWorkspaceIndex >= 0 && state.lgWorkspaceUrl === location.href) {
      index = state.lgWorkspaceIndex;
    } else {
      // A new fragment entry has no marker (or inherits the previous URL).
      index++;
      remember();
    }
    ready();
  };
  remember();
  window.addEventListener('pageshow', ready);
  window.addEventListener('popstate', changed);
  window.addEventListener('hashchange', changed);
  window.addEventListener('pagehide', () => send('loading'));
  document.addEventListener('click', event => {
    const anchor = event.target?.closest?.('a[href]');
    if (!anchor || event.defaultPrevented) return;
    const url = new URL(anchor.href, location.href);
    if (url.origin === location.origin) {
      // Public standalone pages have no identity bridge: keep this tool open.
      if (['/fees_calculator.php', '/receipt.php'].includes(url.pathname)) {
        if (window.LGWorkspace) {
          event.preventDefault();
          send('external', {url: url.href});
        } else {
          // A direct user-clicked link retains browser activation for a new tab.
          anchor.setAttribute('target', '_blank');
          anchor.setAttribute('rel', 'noopener noreferrer');
        }
        return;
      }
      // Only school attachments may use the browser's download/PDF facilities.
      if (anchor.hasAttribute('download') || /\/(?:download|media|file)\.php$/.test(url.pathname)) return;
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
    if (event.data?.type === 'lg-workspace-back' && index > 0) history.back();
  });
})();
