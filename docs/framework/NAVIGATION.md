# FNLLA Navigation

Local implementation candidate covering Steps 1–4. PHP routes, controllers,
authorization and complete SSR HTML remain authoritative. Direct URLs, refreshes
and forms work without JavaScript. Navigation adds optional browser enhancement;
it creates neither a JavaScript router nor an SPA.

## Normal navigation

```html
<a href="/projects">Projects</a>
```

Internal HTML links are enhanced automatically. External links, downloads, named
targets and modifier clicks keep browser semantics. The current content remains
visible while the next response and its styles are prepared. Slow visits show
only a small top progress indicator after 500 ms.

Regular GET/POST, uploads, authentication and PRG remain native. Existing panel
AJAX retains its transport, CSRF and server validation. No POST is automatically
replayed after a failure. Matching SSR 422 errors render in the panel; other errors
retain entries and restore controls. Validation alerts receive focus when the
panel content is replaced.

## Traditional reload

```html
<a href="/settings" data-fnlla-reload>Settings</a>
<form method="post" action="/account" data-fnlla-reload><!-- existing controls --></form>
```

`data-fnlla-reload` also applies to a containing element and bypasses existing panel
AJAX. Shared layouts use it on native-only pages. Advanced layout integrations may
pair a body marker with the engine's reload meta; ordinary links need no engine
attributes. Setup, sign-in, vault, project PWA integration, client previews, staff
workspaces and application commerce keep their explicit reload boundaries.

## FNLLA Regions

A Region is a named, server-rendered area that can update independently. Use it
for real read-only directory browsing, filtering or pagination. The original URL
must return a complete document containing the same unique Region ID.

Do not wrap the application shell, every component, unrelated destinations,
payment/authentication flows or mutations merely to make them partial. Core has
no fake panel or feed. Full uses Regions only for repository directory/branch
browsing with its README, and commit pagination.

The thin convention currently uses the engine's underlying element; no custom
`<fnlla-region>` element or component framework is registered:

```html
<turbo-frame id="repository-files" data-fnlla-region
             data-fnlla-region-history="advance" role="region" aria-label="Repository files">
  <form method="get" action="/repository" data-fnlla-region-form>
    <label>Branch <select name="ref" data-fnlla-region-submit><!-- real options --></select></label>
    <button type="submit">Browse</button>
  </form>
  <a href="/repository?path=src">src</a>
  <a href="/repository/file?path=src/main.php" data-fnlla-region-top>Open file</a>
</turbo-frame>
```

Region links and explicitly marked GET forms update only that area. The select
helper uses `requestSubmit`; the button works without JavaScript. Use history
`advance` or `replace` for meaningful deep-link URLs; omit the history marker for
updates that should leave the address unchanged. `data-fnlla-region-top` opens a
complete enhanced page. `data-fnlla-reload` requests a traditional visit.

A Region update retains whole-page scroll. Focused replacement form controls are
matched by ID or name/form; otherwise focus moves to the Region. Updates announce
the Region label politely. Missing content, authentication/native-only redirects,
non-HTML responses and failed navigation GETs fall back to a full browser GET.
Server authorization is never bypassed.

## JavaScript lifecycle

Shared scripts initialize once for each new body. Give scripts a stable optional
registration key to prevent duplicate initialization when a body script repeats:

```js
FNLLANavigation.onLoad(() => {
  FNLLANavigation.listen(document, "click", handlePageClick);
  const controllerSignal = FNLLANavigation.signal;
  const timer = setInterval(refreshLocalState, 60000);
  FNLLANavigation.onBeforeLeave(() => clearInterval(timer));
}, "page-controls");

FNLLANavigation.onRegionLoad((region, resources) => {
  resources.listen(region, "click", handleRegionClick);
  // Use resources.signal for Region-owned fetches.
}, "repository-controls");
```

Listeners use AbortSignals; requests, timers and observers belong to their page or
Region lifetime. Region resources end before replacement. A Region history update
does not dispose its still-present page. Inline SSR layout initialization uses
`FNLLANavigation.ready(callback)` after permanent shell nodes return.

Existing partial renderers can call `beforeContentReplace(root)` and
`contentLoaded(root)` to close transient controls, release nested Regions and
initialize new widgets. Optional events are `fnlla:navigation:load`,
`fnlla:navigation:before-leave`, `fnlla:region:load` (with `detail.region`) and
`fnlla:navigation:unavailable`. Engine events stay in the Navigation adapter.

## Stable application shell

Existing headers, sidebars and footers can persist with a unique ID and
`data-fnlla-shell`; advanced shared layouts also apply the engine permanent marker.
Navigation compares fresh SSR markup with the original shell. Compatible nodes
keep their DOM identity. Active classes, `aria-current` and CSRF/return fields are
updated from SSR. Changed identity, permissions, badges or controls replace the
shell. Persistence must never override authoritative account state.

Public Full keeps its header/footer. The panel keeps its header/sidebar and the
sidebar credits; it has no separate footer to invent. Core remains a minimal
`main` starter. Different product/protected/public shells may legitimately replace
or reload. Body classes, title, meta/canonical tags and FNLLA HTML metadata update
with the route. Navigation disclosure markers let application-specific scripts
update active groups without putting panel selectors into Core.

## Accessibility and motion

Shared layouts provide a keyboard skip link and a focusable main landmark, also
without JavaScript. Normal enhanced visits focus a visible main heading, optional
`data-fnlla-focus` target or main. Explicit application focus, autofocus and open
modals are respected. Back/Forward focuses main with `preventScroll`, preserving
the saved scroll position. Native anchor handling and reading controls remain
responsible for their target. A single polite, atomic status announces route
changes and Region updates; the initial native load is not announced twice.

Focus indicators add no layout space. Destructive confirmation traps Tab, supports
Escape and restores focus to its trigger. Transient menus close before content
replacement; persistent search results close on departure and pending requests
are cancelled.

Navigation animations and View Transitions remain disabled. The installed engine
supports document transitions, but content-only transitions are not validated
across the different shells, error and authentication boundaries. Instant content
replacement is the chosen stable behavior. `prefers-reduced-motion: reduce`
disables smooth document scrolling and progress-width transitions.

## Assets and ownership

The provider-neutral adapter and accessibility CSS are maintained in the Core
starter under `resources/project-templates/core/public/assets/fnlla/`. Full/site
mirror them under `public/assets/fnlla/`. The web runtime kernel and installed
packages are untouched. Local Turbo 8.0.23 (MIT) is the engine; no new dependency
is introduced in Step 4. It stays isolated behind FNLLA conventions and lifecycle.

Layouts load `navigation.js` and `navigation.css` once. Shared asset URLs remain
stable; changed tracked assets cause a native reload. Route styles use dynamic
head tracking. Existing local font preloads and browser caching remain intact.
Fetched script/style nonces use the initial document CSP. Core and Full use local
assets; fnlla.com uses its existing fingerprinted build. Rebuild with `npm ci` and
`npm run build`; a deployed PHP server does not require npm or a CDN.

Snapshot caching and speculative prefetch remain disabled. Back/Forward fetches
fresh SSR rather than retaining sensitive DOM snapshots. Write-enabled Regions,
realtime and WebSockets are not part of this implementation.

## Verification

Run browser probes only against local SSR pages:

- `scripts/test-navigation-browser.py`: foundation, native forms, opt-out,
  downloads, external targets, history and no-JS.
- `scripts/test-navigation-shell-browser.py`: persistent nodes, fresh account
  state, metadata, active navigation, assets, anchors, scroll and slow responses.
- `scripts/test-navigation-regions-browser.py`: two existing Regions, scoped
  cleanup, GET filters/focus, history, missing/error responses and network fallback.
- `scripts/test-navigation-accessibility-browser.py`: desktop, 390 px mobile,
  reduced motion, keyboard skip/focus, route announcements, shell geometry,
  shared asset/request reuse, style nonces, queries, refresh and no-JS.

Full additionally maintains `tests/fixtures/navigation/` and
`scripts/test-navigation-interactions-browser.py` for isolated synthetic
session/form/widget/search/timer/confirmation tests. Never install those fixtures
in production. They complement backend authorization/session tests, not a real
account/provider acceptance. Playwright and local Edge are QA tools, not runtime
dependencies. Automated semantics do not replace manual screen-reader and
additional browser verification.
