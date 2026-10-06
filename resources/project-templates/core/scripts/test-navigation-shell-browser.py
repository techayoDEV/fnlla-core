"""Verify Step 2 against local SSR routes sharing a FNLLA shell.
Usage: python scripts/test-navigation-shell-browser.py http://127.0.0.1:8094 /home /projects /git /settings /contact
Requires Python Playwright and local Edge; not a runtime dependency.
"""
import sys
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

origin = sys.argv[1].rstrip('/')
routes = sys.argv[2:]
if len(routes) < 3 or urlparse(origin).hostname not in ('127.0.0.1', 'localhost'):
    raise SystemExit('Provide a local URL and at least three SSR routes sharing a shell.')

def state(page):
    return page.evaluate("""() => ({title: document.title, body: document.body.className,
      metadata: [...document.head.querySelectorAll('meta[name="description"], meta[name="robots"], link[rel="canonical"]')].map(n => n.outerHTML).sort(),
      html: [...document.documentElement.attributes].filter(a => a.name.startsWith('data-fnlla-')).map(a => [a.name,a.value]),
      active: [...document.querySelectorAll('[data-fnlla-shell] [aria-current="page"]')].map(n => n.getAttribute('href')),
      shells: [...document.querySelectorAll('[data-fnlla-shell]')].map(n => n.id),
      assets: [...document.head.querySelectorAll('link[rel="stylesheet"], script[src]')].map(n => n.href || n.src)
    })""")

def count_loads(page):
    return page.evaluate("""() => { if (!window.qaCountLoads) {
      window.qaCountLoads=true; window.qaLoads=0;
      document.addEventListener('fnlla:navigation:load',()=>window.qaLoads++);
    } return window.qaLoads; }""")

def wait_visit(page, previous):
    page.wait_for_function('n => window.qaLoads > n', arg=previous)
    page.wait_for_load_state('networkidle')

def visit(page, path):
    previous = count_loads(page)
    page.evaluate("""path => { const a=document.createElement('a'); a.href=path;
      document.body.append(a); a.click(); a.remove(); }""", path)
    page.wait_for_url(origin + path)
    wait_visit(page, previous)

with sync_playwright() as p:
    browser = p.chromium.launch(channel='msedge', headless=True)
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
    expected = {}
    for path in routes:
        response = page.goto(origin + path, wait_until='networkidle')
        assert response.status == 200 and urlparse(page.url).path == path, (path, response.status, page.url)
        assert '<html' in response.text().lower() and '<body' in response.text().lower()
        assert page.locator('main').count() == 1, 'One main landmark is required'
        expected[path] = state(page)
        assert len(expected[path]['shells']) >= 2, 'Header plus sidebar/footer must be marked'
    page.goto(origin + routes[0], wait_until='networkidle')
    page.evaluate("""() => { window.qaShells = [...document.querySelectorAll('[data-fnlla-shell]')];
      window.qaDocument = document; }""")
    shared = set.intersection(*(set(v['assets']) for v in expected.values()))
    page.evaluate('''() => {
      window.qaScopedEvents=0;
      FNLLANavigation.onLoad(() => FNLLANavigation.listen(document, 'qa:scoped', () => window.qaScopedEvents++));
    }''' )
    requests = []
    page.on('request', lambda request: requests.append(request.url))
    for path in routes[1:] + [routes[0]]:
        visit(page, path)
        actual = state(page)
        for key in ('title', 'body', 'metadata', 'html', 'active', 'shells'):
            assert actual[key] == expected[path][key], (path, key, actual[key], expected[path][key])
        assert page.evaluate('window.qaDocument === document'), 'Unexpected browser reload'
        assert page.evaluate('window.qaShells.every(el => document.getElementById(el.id) === el)'), 'Compatible shell lost identity'
        assert page.evaluate('Math.abs(scrollY) < 2'), 'New route must start at top'
        assert page.locator('[data-fnlla-shell]').count() == len(expected[path]['shells'])
        scoped = page.evaluate("() => { const previous=window.qaScopedEvents; document.dispatchEvent(new Event('qa:scoped')); return window.qaScopedEvents-previous; }")
        assert scoped == 1, 'Scoped global handlers were lost or duplicated'
        toggles = page.locator('[data-developer-sidebar-toggle]')
        if toggles.count():
            toggle = toggles.first
            expanded = toggle.get_attribute('aria-expanded')
            toggle.click()
            assert toggle.get_attribute('aria-expanded') != expanded, 'Sidebar control did not rebind'
            toggle.click()
        button = page.locator('#qa-global')
        if button.count():
            previous = page.evaluate('window.qaClicks || 0')
            button.click()
            assert page.evaluate('window.qaClicks') == previous + 1, 'Inline shell control did not rebind exactly once'

    assert not shared.intersection(requests), ('Shared assets requested again', shared.intersection(requests))
    # History restoration must work without retaining sensitive cached DOM snapshots.
    scroll = page.evaluate('() => { const y = Math.min(900, document.documentElement.scrollHeight - innerHeight); scrollTo(0, y); return y; }')
    page.wait_for_function('value => Math.abs(scrollY-value)<3', arg=scroll)
    assert scroll > 0, 'Select pages long enough to test scroll restoration'
    visit(page, routes[1])
    previous = count_loads(page)
    page.go_back(wait_until='networkidle')
    wait_visit(page, previous)
    assert page.url == origin + routes[0]
    page.wait_for_function('title => document.title === title', arg=expected[routes[0]]['title'])
    page.wait_for_function('value => Math.abs(scrollY-value)<3', arg=scroll)
    previous = count_loads(page)
    page.go_forward(wait_until='networkidle')
    wait_visit(page, previous)
    assert page.url == origin + routes[1]
    page.wait_for_function('title => document.title === title', arg=expected[routes[1]]['title'])
    page.reload(wait_until='networkidle')
    assert page.url == origin + routes[1]
    # Same-page anchors may be handled by native navigation or an audited reader.
    # A fragment change need not dispatch a full page-load lifecycle event.
    target = page.evaluate("""() => [...document.querySelectorAll('main [id]')].reverse()
      .find(n => n.getBoundingClientRect().top + scrollY > 200 && n.getClientRects().length)?.id""")
    assert target, 'Select a page with a server-rendered anchor below the fold'
    page.evaluate("target=>{const a=document.createElement('a');a.href='#'+target;document.body.append(a);a.click();a.remove()}",target)
    page.wait_for_url(origin+routes[1]+'#'+target)
    page.wait_for_function('() => scrollY > 0')
    # An authoritative server-side account/control change must invalidate persistence.
    page.goto(origin + routes[0], wait_until='networkidle')
    page.evaluate("window.qaHeader = document.querySelector('[data-fnlla-shell]')")
    def changed_shell(route):
        response = route.fetch()
        body = response.text()
        # Add a server-rendered control to the first shell (before its closing tag).
        from re import sub
        body = sub(r'(<(?:header|aside|footer)\b[^>]*data-fnlla-shell[^>]*>)',
                   r'\1<span id="qa-server-change">Changed account state</span>', body, count=1)
        route.fulfill(response=response, body=body)
    page.route(origin + routes[1], changed_shell)
    visit(page, routes[1])
    assert page.locator('#qa-server-change').count() == 1, 'Fresh server state was discarded'
    assert page.evaluate('window.qaHeader !== document.getElementById(window.qaHeader.id)'), 'Changed shell was kept stale'
    page.unroute(origin + routes[1], changed_shell)
    # A delayed response keeps the current shell and content painted; no blank overlay.
    page.goto(origin + routes[0], wait_until='networkidle')
    page.evaluate("""() => { const main=document.querySelector('main');
      window.qaVisibleDuringLoad=false;
      setTimeout(() => { window.qaVisibleDuringLoad=main.isConnected && main.getClientRects().length>0
        && getComputedStyle(main).visibility !== 'hidden' && getComputedStyle(main).opacity !== '0'; }, 600);
    }""")
    def slow_response(route):
        import time
        response = route.fetch()
        time.sleep(0.9)
        route.fulfill(response=response)
    page.route(origin + routes[1], slow_response)
    visit(page, routes[1])
    assert page.evaluate('window.qaVisibleDuringLoad'), 'Current content disappeared during loading'
    page.unroute(origin + routes[1], slow_response)
    assert not errors, errors
    plain = browser.new_context(java_script_enabled=False).new_page()
    for path in routes:
        response = plain.goto(origin + path, wait_until='networkidle')
        assert response.status == 200 and urlparse(plain.url).path == path
        assert plain.locator('main').count() == 1
    browser.close()
print('PASS: SSR/deep links/refresh/no-JS, stable compatible shell nodes, fresh account state, active navigation/title/metadata/body, asset reuse, anchors, Back/Forward and scroll; no console errors.')
