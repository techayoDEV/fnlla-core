/* FNLLA Navigation. Copyright (c) 2026 TechAyo LTD. MIT License. */
(() => {
  "use strict";
  if (window.FNLLANavigation) return;
  const source = document.currentScript.src;
  const library = document.currentScript.dataset.fnllaNavigationLibrary || new URL("./turbo.js", source).href;
  const nonce = document.querySelector('meta[name="csp-nonce"]')?.content;
  let scope = new AbortController();
  let rendering = false;
  let regionPromotion;
  let historyOnlyVisit = false;
  let loadedBody;
  let visitAction;
  let announcementGeneration = 0;
  const regionFocus = new WeakMap();
  const focusTargets = new WeakSet();
  const routeStatus = document.createElement('div');
  routeStatus.id = 'fnlla-navigation-status';
  routeStatus.className = 'fnlla-navigation-status';
  routeStatus.setAttribute('role', 'status');
  routeStatus.setAttribute('aria-live', 'polite');
  routeStatus.setAttribute('aria-atomic', 'true');
  const visible = (element) => element instanceof HTMLElement && element.getClientRects().length > 0 && !element.closest('[hidden], [inert]') && getComputedStyle(element).visibility !== 'hidden';
  const focusContent = (element) => {
    if (!visible(element)) return;
    if (!focusTargets.has(element)) {
      focusTargets.add(element);
      const temporary = !element.hasAttribute('tabindex') && element.tabIndex < 0;
      if (temporary) element.setAttribute('tabindex', '-1');
      element.setAttribute('data-fnlla-focus-target', '');
      element.addEventListener('blur', () => {
        if (temporary) element.removeAttribute('tabindex');
        element.removeAttribute('data-fnlla-focus-target');
        focusTargets.delete(element);
      }, { once: true });
    }
    element.focus({ preventScroll: true });
  };
  const announce = (message) => {
    if (!routeStatus.isConnected) document.body.append(routeStatus);
    const generation = ++announcementGeneration;
    routeStatus.textContent = '';
    requestAnimationFrame(() => { if (generation === announcementGeneration) routeStatus.textContent = message; });
  };
  const focusPage = () => {
    const main = document.querySelector('main');
    if (!main) return;
    // Preserve autofocus, explicit application focus and an open modal.
    if (document.activeElement !== document.body && main.contains(document.activeElement)) return;
    if (document.querySelector('dialog[open], [data-fnlla-modal].is-open')) return;
    let anchor;
    try { anchor = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch (_) {}
    const heading = [...main.querySelectorAll('[data-fnlla-focus]')].find(visible) || [...main.querySelectorAll('h1, h2')].find(visible);
    focusContent(visitAction === 'restore' ? main : (visible(anchor) ? anchor : (heading || main)));
  };
  const readyCallbacks = new Set();
  const cleanups = new Set();
  const pageKeys = new Set();
  const regionKeys = new Set();
  const regionCallbacks = new Set();
  const regionScopes = new Map();
  const regionSelector = 'turbo-frame[data-fnlla-region]';
  const handledRequestErrors = new WeakSet();
  const lifetime = (signal) => ({
    signal,
    listen(target, type, callback, options = {}) {
      if (!target) return;
      const settings = typeof options === 'boolean' ? { capture: options } : options;
      target.addEventListener(type, callback, { ...settings, signal });
    },
    onBeforeLeave(callback) {
      if (signal.aborted) callback();
      else signal.addEventListener('abort', callback, { once: true });
    }
  });
  const runRegionCallback = (frame, entry, callback) => {
    if (entry.callbacks.has(callback)) return;
    entry.callbacks.add(callback);
    callback(frame, entry.resources);
  };
  const closeComponents = (root) => {
    root.querySelectorAll('[data-fnlla-dropdown].is-open').forEach((node) => window.FNLLARUNTIME?.closeDropdown?.(node));
    root.querySelectorAll('[data-fnlla-popover].is-open').forEach((node) => window.FNLLARUNTIME?.closePopover?.(node));
    root.querySelectorAll('[data-fnlla-offcanvas].is-open').forEach((node) => window.FNLLARUNTIME?.closeOffcanvas?.(node));
    root.querySelectorAll('[data-fnlla-modal].is-open').forEach((modal) => window.FNLLARUNTIME?.closeModal(modal));
    root.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
  };
  const enhanceMarkers = (root) => {
    root.querySelectorAll('[data-fnlla-reload]').forEach((node) => node.setAttribute('data-turbo', 'false'));
    root.querySelectorAll('[data-fnlla-region-top]').forEach((node) => node.setAttribute('data-turbo-frame', '_top'));
    root.querySelectorAll('form[data-fnlla-region-form]').forEach((form) => {
      // Regions are read-only. POST/upload/auth retain native transport.
      form.setAttribute('data-turbo', form.method.toLowerCase() === 'get' && form.closest(regionSelector) && !form.closest('[data-fnlla-reload]') ? 'true' : 'false');
    });
  };
  const mountRegions = (root) => {
    enhanceMarkers(root);
    const frames = [...root.querySelectorAll(regionSelector)];
    if (root.matches?.(regionSelector)) frames.unshift(root);
    frames.forEach((frame) => {
      if (regionScopes.has(frame)) return;
      if (!frame.id || document.querySelectorAll('#' + CSS.escape(frame.id)).length !== 1) {
        frame.setAttribute('disabled', '');
        return;
      }
      const action = frame.dataset.fnllaRegionHistory;
      if (action === 'advance' || action === 'replace') frame.setAttribute('data-turbo-action', action);
      const controller = new AbortController();
      const resources = lifetime(controller.signal);
      const entry = { controller, resources, callbacks: new Set() };
      regionScopes.set(frame, entry);
      regionCallbacks.forEach((callback) => runRegionCallback(frame, entry, callback));
      document.dispatchEvent(new CustomEvent('fnlla:region:load', { detail: { region: frame } }));
    });
  };
  const disposeRegion = (frame) => {
    regionScopes.get(frame)?.controller.abort();
    closeComponents(frame);
    regionScopes.delete(frame);
  };
  const shellMarkup = new WeakMap();
  const shellNodes = new WeakMap();
  const stateSelector = 'a[href], summary, [data-fnlla-navigation-state], input[type="hidden"]';
  const shellSelector = '[data-fnlla-shell][id][data-turbo-permanent]';
  const normalizeShell = (element) => {
    const clone = element.cloneNode(true);
    clone.querySelectorAll(stateSelector).forEach((node) => {
      node.removeAttribute('aria-current');
      node.classList.remove('is-active');
      if (!node.className) node.removeAttribute('class');
    });
    clone.querySelectorAll('[data-fnlla-navigation-disclosure]').forEach((node) => node.removeAttribute('hidden'));
    clone.querySelectorAll('[data-fnlla-navigation-toggle]').forEach((node) => node.removeAttribute('aria-expanded'));
    clone.querySelectorAll('input[type="hidden"]').forEach((node) => {
      if (['_token', 'csrf_token', 'return_to', 'return_path', 'return'].includes(node.name)) node.removeAttribute('value');
    });
    return clone.outerHTML.replace(/\s+class="\s*([^"\n]*?)\s*"/g, (_, value) => ' class="' + value.split(/\s+/).filter(Boolean).join(' ') + '"');
  };
  const rememberShells = () => document.querySelectorAll(shellSelector).forEach((element) => {
    if (!shellMarkup.has(element)) {
      shellMarkup.set(element, normalizeShell(element));
      shellNodes.set(element, [...element.querySelectorAll(stateSelector)]);
    }
  });
  document.addEventListener('DOMContentLoaded', rememberShells, { once: true });
  const syncShells = (nextBody) => {
    nextBody.querySelectorAll(shellSelector).forEach((fresh) => {
      const current = document.getElementById(fresh.id);
      const markup = normalizeShell(fresh);
      if (!current?.matches(shellSelector) || shellMarkup.get(current) !== markup) {
        // Server changes to identity, permissions, counts or controls are authoritative.
        // Do not preserve a shell whose non-navigation content has changed.
        fresh.removeAttribute('data-turbo-permanent');
        return;
      }
      const oldNodes = shellNodes.get(current);
      const newNodes = [...fresh.querySelectorAll(stateSelector)];
      oldNodes.forEach((node, index) => {
        const incoming = newNodes[index];
        if (!incoming) return;
        if (incoming.hasAttribute('aria-current')) node.setAttribute('aria-current', incoming.getAttribute('aria-current'));
        else node.removeAttribute('aria-current');
        node.classList.toggle('is-active', incoming.classList.contains('is-active'));
        if (node.matches('input[type="hidden"]')) node.value = incoming.value;
      });
      shellMarkup.set(current, markup);
    });
  };
  const api = {
    onLoad(callback, key) {
      if (key && pageKeys.has(key)) return;
      if (key) pageKeys.add(key);
      let initializedBody;
      const run = () => {
        if (initializedBody === document.body) return;
        initializedBody = document.body;
        callback();
      };
      document.addEventListener("fnlla:navigation:load", run);
      if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", run, { once: true });
      else if (!rendering) run();
    },
    onRegionLoad(callback, key) {
      if (key && regionKeys.has(key)) return;
      if (key) regionKeys.add(key);
      regionCallbacks.add(callback);
      api.ready(() => regionScopes.forEach((entry, frame) => runRegionCallback(frame, entry, callback)));
    },
    beforeContentReplace(root) {
      regionScopes.forEach((_resources, frame) => { if (root === frame || root.contains(frame)) disposeRegion(frame); });
      closeComponents(root);
    },
    contentLoaded(root) {
      window.FNLLARUNTIME?.init(root);
      mountRegions(root);
      const error = root.querySelector('[role="alert"]');
      if (error) focusContent(error);
      else focusPage();
      announce(error?.textContent || document.title);
    },
    // SSR inline scripts must bind after permanent nodes have been restored.
    ready(callback) {
      if (rendering) readyCallbacks.add(callback);
      else if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", callback, { once: true });
      else callback();
    },
    listen(target, type, listener, options = {}) {
      const settings = typeof options === "boolean" ? { capture: options } : options;
      target.addEventListener(type, listener, { ...settings, signal: scope.signal });
    },
    onBeforeLeave(callback) { cleanups.add(callback); },
    get signal() { return scope.signal; }
  };
  window.FNLLANavigation = api;
  document.addEventListener("turbo:before-fetch-response", (event) => {
    const frame = event.target.closest?.(regionSelector);
    if (!nonce && !frame) return;
    const response = event.detail.fetchResponse;
    const html = response.responseHTML;
    // The browser keeps the initial document's CSP. Turbo's script activation
    // must use that nonce, not the nonce of a fetched SSR response.
    Object.defineProperty(response, "responseHTML", { value: html.then((markup) => {
      if (!markup) {
        if (frame) window.location.assign(response.location.href);
        return markup;
      }
      const parsed = new DOMParser().parseFromString(markup, "text/html");
      if (frame && (!parsed.getElementById(frame.id)?.matches(regionSelector) ||
          parsed.querySelector('meta[name="turbo-visit-control"][content="reload"], body[data-fnlla-reload]'))) {
        // Login, missing regions and non-region errors need an authoritative full document.
        window.location.assign(response.location.href);
        return undefined;
      }
      const meta = parsed.querySelector('meta[name="csp-nonce"]');
      if (meta && nonce) meta.content = nonce;
      if (nonce) parsed.querySelectorAll("script[nonce], style[nonce]").forEach((element) => element.setAttribute("nonce", nonce));
      return "<!DOCTYPE html>" + parsed.documentElement.outerHTML;
    }) });
  });
  const leave = () => {
    document.dispatchEvent(new CustomEvent("fnlla:navigation:before-leave"));
    scope.abort();
    regionScopes.forEach((_resources, frame) => disposeRegion(frame));
    closeComponents(document);
    cleanups.forEach((callback) => callback());
    cleanups.clear();
    scope = new AbortController();
  };
  // Scripts initialize against the new body; forms retain browser submission.
  document.addEventListener("turbo:before-render", (event) => {
    const next = event.detail.newBody;
    if (next.hasAttribute("data-fnlla-reload")) {
      event.preventDefault();
      window.location.reload();
      return;
    }
    // Turbo 8 promotes Frame history through a visit with willRender=false.
    // Its before-render event must not dispose a body that will remain in place.
    if (historyOnlyVisit) return;
    leave();
    rendering = true;
    syncShells(next);
    const nextHtml = next.ownerDocument.documentElement;
    // Turbo replaces the body, not application metadata on <html>.
    [...document.documentElement.attributes].filter((a) => a.name.startsWith("data-fnlla-")).forEach((a) => document.documentElement.removeAttribute(a.name));
    [...nextHtml.attributes].filter((a) => a.name.startsWith("data-fnlla-") || a.name === "lang").forEach((a) => document.documentElement.setAttribute(a.name, a.value));
  });
  document.addEventListener("turbo:load", () => {
    const changedBody = loadedBody && loadedBody !== document.body;
    loadedBody = document.body;
    rendering = false;
    historyOnlyVisit = false;
    // Elements rejected for one transition can become permanent on later visits.
    document.querySelectorAll('[data-fnlla-shell][id]').forEach((element) => element.setAttribute('data-turbo-permanent', ''));
    rememberShells();
    mountRegions(document);
    window.FNLLARUNTIME?.init(document);
    document.dispatchEvent(new CustomEvent("fnlla:navigation:load"));
    readyCallbacks.forEach((callback) => callback());
    readyCallbacks.clear();
    if (changedBody) {
      focusPage();
      announce(document.title);
    }
  });
  document.addEventListener("turbo:click", (event) => {
    if (event.target.closest("[data-fnlla-reload]")) event.preventDefault();
  });
  api.ready(() => {
    loadedBody = document.body;
    document.body.append(routeStatus);
    mountRegions(document);
  });
  document.addEventListener('turbo:visit', (event) => {
    visitAction = event.detail.action;
    historyOnlyVisit = regionPromotion?.url === event.detail.url && regionPromotion?.body === document.body;
    regionPromotion = undefined;
  });
  document.addEventListener('turbo:before-frame-render', (event) => {
    if (!event.target.matches(regionSelector)) return;
    const active = document.activeElement;
    if (event.target.contains(active)) regionFocus.set(event.target, {
      id: active.id, name: active.getAttribute('name'), tag: active.tagName,
      form: active.form?.getAttribute('action')
    });
    api.beforeContentReplace(event.target);
  });
  document.addEventListener('turbo:frame-load', (event) => {
    if (!event.target.matches(regionSelector)) return;
    window.FNLLARUNTIME?.init(event.target);
    mountRegions(event.target);
    const previous = regionFocus.get(event.target);
    regionFocus.delete(event.target);
    if (previous && (document.activeElement === document.body || !event.target.contains(document.activeElement))) {
      const controls = [...event.target.querySelectorAll('input, select, textarea, button')];
      const replacement = previous.id ? event.target.querySelector('#' + CSS.escape(previous.id)) :
        controls.find((node) => previous.name && node.tagName === previous.tag && node.getAttribute('name') === previous.name && node.form?.getAttribute('action') === previous.form);
      focusContent(replacement || event.target);
    }
    announce(event.target.getAttribute('aria-label') || event.target.querySelector('h1, h2, h3')?.textContent || document.title);
    if (event.target.hasAttribute('data-turbo-action') && event.target.src) regionPromotion = { url: event.target.src, body: document.body };
  });
  document.addEventListener('turbo:frame-missing', (event) => {
    if (!event.target.matches(regionSelector)) return;
    event.preventDefault();
    window.location.assign(event.detail.response.url);
  });
  document.addEventListener('turbo:fetch-request-error', (event) => {
    // Never retry a mutation. These Regions only opt GET forms/links into transport.
    if (!event.detail.request.isSafe) return;
    event.preventDefault();
    handledRequestErrors.add(event.detail.error);
    window.location.assign(event.detail.request.url.href);
  });
  window.addEventListener('unhandledrejection', (event) => {
    // Turbo 8 rethrows fetch failures even after its cancellable error event.
    // Suppress only the exact error already handled by the native GET fallback.
    if (event.reason && handledRequestErrors.has(event.reason)) event.preventDefault();
  });
  document.addEventListener('change', (event) => {
    if (event.target.matches('[data-fnlla-region-submit]') && event.target.closest(regionSelector)) event.target.form?.requestSubmit();
  });
  document.addEventListener('click', (event) => {
    event.target.closest?.('[data-fnlla-reload]')?.setAttribute('data-turbo', 'false');
  }, true);
  document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[data-fnlla-skip]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    try { focusContent(document.getElementById(decodeURIComponent(link.hash.slice(1)))); } catch (_) {}
  });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    const method = event.submitter?.getAttribute('formmethod') || form.method;
    if (form.closest('[data-fnlla-reload]') || (form.closest(regionSelector) && method.toLowerCase() !== 'get')) form.setAttribute('data-turbo', 'false');
  }, true);
  // No speculative requests: GET endpoints may still have legacy side effects.
  document.addEventListener("turbo:before-prefetch", (event) => event.preventDefault());
  import(library).then((Turbo) => {
    Turbo.config.forms.mode = "optin";
    Turbo.config.drive.progressBarDelay = 500;
    Turbo.cache.exemptPageFromCache();
    // Cached snapshots retain initialized DOM but not its listeners. Keep SSR
    // authoritative until snapshot cleanup is addressed in a later step.
    document.addEventListener("turbo:load", () => Turbo.cache.exemptPageFromCache());
    if (document.body?.hasAttribute("data-fnlla-reload")) Turbo.session.drive = false;
  }).catch(() => {
    // Asset failure leaves normal anchors and forms fully usable.
    document.dispatchEvent(new CustomEvent("fnlla:navigation:unavailable"));
  });
})();
