"""Local SSR acceptance for two read-only FNLLA Regions (Playwright + Edge).
Usage: python scripts/test-navigation-regions-browser.py http://127.0.0.1:8095 /qa/git/code/1 /qa/git/commits/1
Choose complete SSR pages with same-route Region links and advance history.
No production routes, authentication or data are created by this probe.
"""
import sys
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
origin = sys.argv[1].rstrip('/')
paths = sys.argv[2:]
if urlparse(origin).hostname not in ('127.0.0.1', 'localhost') or len(paths) != 2:
    raise SystemExit('Provide a local URL and two existing Region routes.')

with sync_playwright() as p:
    browser = p.chromium.launch(channel='msedge', headless=True)
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type=='error' else None)
    requests = []
    page.on('request', lambda r: requests.append((r.url, r.method, r.headers.get('turbo-frame'))))
    for path in paths:
        response = page.goto(origin + path, wait_until='networkidle')
        assert response.status==200 and '<html' in response.text().lower()
        frame = page.locator('turbo-frame[data-fnlla-region]').first
        assert frame.count() and frame.get_attribute('id')
        initial = page.url
        page.evaluate("""() => {
          window.qaBody=document.body; window.qaShells=[...document.querySelectorAll('[data-fnlla-shell]')];
          window.qaRegionHits=0; window.qaRegionCleanups=0; window.qaRegionMounts=0;
          FNLLANavigation.onRegionLoad((region, resources)=>{
            window.qaRegionMounts++;
            resources.listen(region,'qa:region-probe',()=>window.qaRegionHits++);
            resources.onBeforeLeave(()=>window.qaRegionCleanups++);
          });
        }""")
        link = frame.locator('a[href]').evaluate_all("""links => links.filter(a => !a.hasAttribute('data-fnlla-region-top') && !a.hasAttribute('data-fnlla-reload')
          && new URL(a.href).pathname===location.pathname && a.href!==location.href && !a.hash).at(-1)?.href""")
        assert link, 'Select a Region with an existing query/directory/page link'
        before=len(requests)
        page.evaluate('window.scrollTo(0, Math.min(100, document.documentElement.scrollHeight-innerHeight))')
        scroll=page.evaluate('window.scrollY')
        frame.locator('a[href]').evaluate_all('(links, href)=>links.find(a=>a.href===href).click()',link)
        page.wait_for_url(link)
        page.wait_for_load_state('networkidle')
        assert page.evaluate('document.body===window.qaBody && window.qaShells.every(n=>document.getElementById(n.id)===n)'), 'Shell/body changed during Region update'
        assert any(method=='GET' and region for _,method,region in requests[before:]), 'Region did not use its partial transport'
        page.wait_for_function('()=>window.qaRegionMounts===2 && window.qaRegionCleanups===1')
        frame.evaluate("region=>region.dispatchEvent(new Event('qa:region-probe'))")
        assert page.evaluate('window.qaRegionHits')==1
        assert page.evaluate('window.qaRegionCleanups')==1
        assert abs(page.evaluate('window.scrollY')-scroll)<3, 'Region unexpectedly scrolled the page'
        # A Frame GET filter retains native validation/submission semantics and changes history.
        form=frame.locator('form[data-fnlla-region-form]')
        if form.count():
            select=form.locator('select').first
            if select.count() and select.locator('option').count()>1:
                previous=page.url
                select.focus()
                select.select_option(index=1)
                page.wait_for_function('previous=>location.href!==previous',arg=previous)
                page.wait_for_load_state('networkidle')
                assert page.evaluate('document.body===window.qaBody')
                page.wait_for_function("()=>document.activeElement.matches('turbo-frame[data-fnlla-region] select[name=ref]')")
                assert page.locator('#fnlla-navigation-status').count()==1
        target = page.url
        text = frame.inner_text()
        page.go_back();page.wait_for_load_state('networkidle')
        page.go_forward();page.wait_for_url(target);page.wait_for_load_state('networkidle')
        assert page.locator('turbo-frame[data-fnlla-region]').first.inner_text()==text
        page.reload(wait_until='networkidle')
        assert page.url==target and page.locator('turbo-frame[data-fnlla-region]').count()
        assert page.locator('main').count()==1
    # An initializer first registered while the new body renders runs only once.
    page.evaluate("""()=>{
      window.qaLateRegionInitializations=0;
      document.addEventListener('turbo:before-render',()=>{
        FNLLANavigation.onRegionLoad(()=>window.qaLateRegionInitializations++,'qa-late-region');
      },{once:true});
    }""")
    page.evaluate("path=>{const a=document.createElement('a');a.href=path;document.body.append(a);a.click()}",paths[0])
    page.wait_for_url(origin+paths[0]);page.wait_for_load_state('networkidle')
    page.wait_for_function('()=>window.qaLateRegionInitializations===1')
    assert not errors, errors
    # Missing/forbidden/expired/error responses must render a complete native document.
    for status in (403,404,422,500):
        page.goto(origin+paths[0],wait_until='networkidle')
        page.evaluate('window.qaNativeFallback=false')
        def fail(route, request, status=status):
            route.fulfill(status=status,content_type='text/html',body=f'<!doctype html><html><head><link rel="icon" href="data:,"><title>QA {status}</title></head><body><main id="qa-error">Synthetic {status} response</main></body></html>')
        page.route(origin+paths[0]+'?fault='+str(status),fail)
        page.locator('turbo-frame[data-fnlla-region]').first.evaluate("""(frame,status)=>{const a=document.createElement('a');a.href=location.pathname+'?fault='+status;frame.append(a);a.click();}""",status)
        page.wait_for_function("status=>document.title==='QA '+status",arg=status)
        assert page.locator('#qa-error').count()==1 and not page.locator('turbo-frame').count()
        page.unroute(origin+paths[0]+'?fault='+str(status),fail)
    # Only the enhanced fetch fails; the normal browser GET remains available.
    page.goto(origin+paths[0],wait_until='networkidle')
    target=origin+paths[0]+'?network=1'
    def network(route):
        if route.request.headers.get('turbo-frame'): route.abort('failed')
        else: route.continue_()
    page.route(target,network)
    page.evaluate('window.qaNativeFallbackProbe=1')
    page.locator('turbo-frame[data-fnlla-region]').first.evaluate("""(frame,target)=>{const a=document.createElement('a');a.href=target;frame.append(a);a.click();}""",target)
    page.wait_for_url(target);page.wait_for_load_state('networkidle')
    assert page.evaluate('window.qaNativeFallbackProbe===undefined'), 'Network failure did not fall back'
    page.unroute(target,network)
    # The same safe GET fallback handles a normal Drive visit outside a Region.
    page.goto(origin+paths[0],wait_until='networkidle')
    target=origin+paths[0]+'?navigation-network=1'
    def drive_network(route):
        if route.request.resource_type=='fetch': route.abort('failed')
        else: route.continue_()
    page.route(target,drive_network)
    page.evaluate('window.qaNativeFallbackProbe=1')
    page.evaluate("target=>{const a=document.createElement('a');a.href=target;document.body.append(a);a.click()}",target)
    page.wait_for_url(target);page.wait_for_load_state('networkidle')
    assert page.evaluate('window.qaNativeFallbackProbe===undefined')
    page.unroute(target,drive_network)
    assert all(message.startswith('Failed to load resource:') for message in errors), errors
    plain=browser.new_context(java_script_enabled=False).new_page()
    for path in paths:
        response=plain.goto(origin+path,wait_until='networkidle')
        assert response.status==200 and plain.locator('turbo-frame[data-fnlla-region]').count()
        assert plain.locator('main').count()==1
        form=plain.locator('form[data-fnlla-region-form]')
        if form.count() and form.locator('select option').count()>1:
            form.locator('select').select_option(index=1)
            form.locator('button[type=submit]').click()
            plain.wait_for_load_state('networkidle')
            assert 'ref=' in plain.url and plain.locator('main').count()==1
    browser.close()
print('PASS: SSR/no-JS, real Region links/GET filters, stable shell, scoped cleanup, history/refresh and HTTP/network fallback; no JavaScript errors.')
