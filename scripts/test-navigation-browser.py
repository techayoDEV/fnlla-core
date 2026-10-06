"""Exercise Step 1 against a local SSR application (requires Playwright + Edge).

Usage: python scripts/test-navigation-browser.py http://127.0.0.1:8080 /about /contact /terms
Routes must return complete HTML documents using a Navigation-enabled shell.
"""
import sys
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

origin = sys.argv[1].rstrip('/')
routes = sys.argv[2:] or ['/home', '/projects', '/contact']
if len(routes) < 3 or urlparse(origin).hostname not in ('127.0.0.1', 'localhost'):
    raise SystemExit('Provide a local base URL and at least three existing SSR routes.')

def anchor(page, path, reload=False):
    # Ordinary markup is the public API. Test anchors use actual server routes.
    page.evaluate('''({path, reload}) => {
      const a = document.createElement('a'); a.id = 'navigation-test-link';
      a.href = path; a.textContent = 'Navigation test';
      if (reload) a.setAttribute('data-fnlla-reload', '');
      document.body.prepend(a);
    }''', {'path': path, 'reload': reload})
    page.locator('#navigation-test-link').click()
    page.wait_for_url(origin + path)
    page.wait_for_load_state('networkidle')

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(channel='msedge', headless=True)
    enhanced = browser.new_context()
    page = enhanced.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('console', lambda message: errors.append(message.text) if message.type == 'error' else None)
    for path in routes:
        response = page.goto(origin + path, wait_until='networkidle')
        assert response.status == 200, (path, response.status)
        assert urlparse(page.url).path == path, (path, 'Redirected to', page.url)
        assert '<html' in response.text().lower() and '<body' in response.text().lower()
        assert page.locator('main').count() > 0
    page.goto(origin + routes[0], wait_until='networkidle')
    assert not page.locator('body[data-fnlla-reload]').count(), 'Select an enabled shell for this probe.'
    page.evaluate('window.fnllaNavigationProbe = 42')
    for path in routes[1:3]:
        anchor(page, path)
        assert page.evaluate('window.fnllaNavigationProbe') == 42, 'Unexpected browser reload'
    page.go_back(wait_until='networkidle')
    assert page.url == origin + routes[1]
    assert page.evaluate('window.fnllaNavigationProbe') == 42
    page.go_forward(wait_until='networkidle')
    assert page.url == origin + routes[2]
    assert page.evaluate('window.fnllaNavigationProbe') == 42
    page.reload(wait_until='networkidle')
    assert page.url == origin + routes[2]
    assert page.evaluate('window.fnllaNavigationProbe === undefined')
    page.evaluate('window.fnllaNavigationProbe = 42')
    anchor(page, routes[0], reload=True)
    assert page.evaluate('window.fnllaNavigationProbe === undefined'), 'Opt-out must reload'
    page.evaluate('''path => {
      window.fnllaNavigationProbe = 42;
      const form = document.createElement('form'); form.method = 'GET'; form.action = path;
      const button = document.createElement('button'); button.id = 'navigation-test-submit';
      button.textContent = 'Submit'; form.append(button); document.body.prepend(form);
    }''', routes[1])
    page.locator('#navigation-test-submit').click()
    page.wait_for_load_state('networkidle')
    assert urlparse(page.url).path == routes[1]
    assert page.evaluate('window.fnllaNavigationProbe === undefined'), 'Forms must remain native'
    page.evaluate('''() => {
      const a = document.createElement('a'); a.id = 'navigation-test-download';
      a.href = 'data:text/plain,FNLLA'; a.download = 'navigation.txt';
      a.textContent = 'Download'; document.body.prepend(a);
    }''')
    with page.expect_download() as download:
        page.locator('#navigation-test-download').click()
    assert download.value.suggested_filename == 'navigation.txt'
    # An external target must open through the browser, not a Drive request.
    enhanced.route('https://navigation.invalid/**', lambda route: route.fulfill(status=200, body='External fixture'))
    page.evaluate('''() => {
      const a = document.createElement('a'); a.id = 'navigation-test-external';
      a.href = 'https://navigation.invalid/'; a.target = '_blank';
      a.textContent = 'External'; document.body.prepend(a);
    }''')
    with page.expect_popup() as popup:
        page.locator('#navigation-test-external').click()
    popup.value.close()
    assert not errors, errors
    context = browser.new_context(java_script_enabled=False)
    plain = context.new_page()
    for path in routes:
        response = plain.goto(origin + path, wait_until='networkidle')
        assert response.status == 200 and plain.locator('main').count() > 0
    # Follow a real SSR anchor when present; otherwise use plain test markup.
    plain.set_content(f'<a href="{origin + routes[0]}">Open SSR route</a>')
    plain.locator('a').click()
    plain.wait_for_url(origin + routes[0])
    plain.reload(wait_until='networkidle')
    assert plain.locator('main').count() > 0
    browser.close()
print('PASS: complete SSR, enhanced links/URLs, Back/Forward, refresh, opt-out, native form, external target, download, no-JS; no console errors.')
