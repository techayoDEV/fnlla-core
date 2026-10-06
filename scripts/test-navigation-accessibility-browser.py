"""Local FNLLA Navigation keyboard/mobile/reduced-motion and stability acceptance.
Usage: python scripts/test-navigation-accessibility-browser.py http://127.0.0.1:8095 /home /projects /git /settings /contact
Uses existing SSR routes. Does not create accounts or mutate server data.
Automated semantics checks complement, rather than replace, assistive-technology review.
"""
import sys
import re
from collections import Counter
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright, expect
origin=sys.argv[1].rstrip('/'); paths=sys.argv[2:]
if urlparse(origin).hostname not in ('localhost','127.0.0.1') or len(paths)<3:
    raise SystemExit('Supply a loopback URL and at least three existing SSR routes.')

with sync_playwright() as p:
    browser=p.chromium.launch(channel='msedge',headless=True)
    for width,motion in ((1280,'no-preference'),(390,'no-preference'),(390,'reduce')):
        context=browser.new_context(viewport={'width':width,'height':844},reduced_motion=motion)
        page=context.new_page();errors=[];requests=[]
        page.on('pageerror',lambda error:errors.append(str(error)))
        page.on('console',lambda message:errors.append(message.text) if message.type=='error' else None)
        page.on('request',lambda request:requests.append((request.url,request.method,request.resource_type)))
        page.goto(origin+paths[0],wait_until='networkidle')
        assert page.locator('main').count()==1
        status=page.locator('#fnlla-navigation-status')
        expect(status).to_have_attribute('aria-live','polite')
        expect(status).to_have_attribute('aria-atomic','true')
        assert status.count()==1
        page.keyboard.press('Tab')
        assert page.evaluate("document.activeElement.matches('a[data-fnlla-skip]')"), 'Keyboard skip link is not first'
        assert page.evaluate('document.activeElement.getBoundingClientRect().top>=0')
        page.keyboard.press('Enter')
        page.wait_for_function("()=>document.activeElement.matches('main')")
        assert page.evaluate("document.activeElement.matches(':focus-visible')")
        if motion=='reduce':
            assert page.evaluate("getComputedStyle(document.documentElement).scrollBehavior==='auto'")
        page.evaluate("""()=>{
          window.qaDocument=1;window.qaLoads=0;window.qaTransitions=0;
          window.qaShells=[...document.querySelectorAll('[data-fnlla-shell]')];
          window.qaShellRects=window.qaShells.map(n=>({id:n.id,tag:n.tagName,x:n.getBoundingClientRect().x,y:n.getBoundingClientRect().y,width:n.getBoundingClientRect().width,height:n.getBoundingClientRect().height}));
          document.addEventListener('fnlla:navigation:load',()=>window.qaLoads++);
          if(document.startViewTransition){const native=document.startViewTransition.bind(document);document.startViewTransition=(...args)=>{window.qaTransitions++;return native(...args)}}
        }""")
        asset_requests=Counter(url for url,method,kind in requests if kind in ('stylesheet','font','script'))
        shared=set(page.evaluate("[...document.head.querySelectorAll('script[src], link[rel=stylesheet][data-turbo-track=reload], link[as=font]')].map(node=>node.src||node.href)"))
        def visit(path):
            previous=page.evaluate('window.qaLoads')
            page.evaluate("path=>{const a=document.createElement('a');a.href=path;document.body.append(a);a.click();a.remove()}",path)
            page.wait_for_url(origin+path)
            page.wait_for_function('previous=>window.qaLoads>previous',arg=previous)
            page.wait_for_load_state('networkidle')
        for path in paths[1:]+[paths[0],paths[1],paths[0]]:
            before=len(requests);visit(path)
            assert page.evaluate('window.qaDocument===1'), 'Enhanced visit became a reload'
            assert page.locator('main').count()==1 and page.locator('#fnlla-navigation-status').count()==1
            assert page.evaluate("document.querySelector('main').contains(document.activeElement)"), 'New content did not receive focus'
            expect(status).to_have_text(page.title())
            assert page.evaluate("window.qaShells.every(node=>document.getElementById(node.id)===node)"), 'Shell lost identity'
            assert page.evaluate("window.qaShellRects.every(rect=>{const now=document.getElementById(rect.id).getBoundingClientRect();return Math.abs(now.x-rect.x)<2 && Math.abs(now.width-rect.width)<2})"), 'Shell geometry shifted'
            assert page.evaluate("window.qaShellRects.filter(rect=>rect.tag==='HEADER').every(rect=>{const now=document.getElementById(rect.id).getBoundingClientRect();return Math.abs(now.y-rect.y)<2 && Math.abs(now.height-rect.height)<2})"), 'Header jumped'
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), 'Mobile horizontal overflow'
            calls=[url for url,method,kind in requests[before:] if kind=='fetch' and method=='GET' and urlparse(url).path==urlparse(path).path]
            assert len(calls)==1, ('Duplicate navigation requests',calls)
        assert not any(count>asset_requests.get(url,0) for url,count in Counter(url for url,method,kind in requests if kind in ('stylesheet','font','script')).items() if url in shared), 'Shared assets were re-requested'
        assert page.evaluate('window.qaTransitions===0'), 'Unreviewed document animation enabled'
        # Nonce-bearing route styles retain the active document CSP and leave with their route.
        initial_nonce=page.locator('meta[name=csp-nonce]').get_attribute('content')
        def signed_style(route):
            response=route.fetch();body=response.text()
            nonce=re.search(r'<meta[^>]+name="csp-nonce"[^>]+content="([^"]*)"',body)
            style='<style id="qa-nonce-style" data-turbo-track="dynamic" nonce="'+(nonce.group(1) if nonce else '')+'">main{--fnlla-qa-style:valid}</style>'
            route.fulfill(response=response,body=body.replace('</head>',style+'</head>',1))
        page.route(origin+paths[1],signed_style)
        visit(paths[1])
        assert page.locator('#qa-nonce-style').evaluate('style=>style.nonce')==initial_nonce
        assert page.locator('main').evaluate("main=>getComputedStyle(main).getPropertyValue('--fnlla-qa-style').trim()")=='valid'
        page.unroute(origin+paths[1],signed_style)
        visit(paths[0]);assert not page.locator('#qa-nonce-style').count()
        # Restored focus must not undo the engine's saved scroll position.
        page.evaluate('window.scrollTo(0,Math.min(180,document.documentElement.scrollHeight-innerHeight))')
        saved=page.evaluate('scrollY');visit(paths[1])
        previous=page.evaluate('window.qaLoads');page.go_back()
        page.wait_for_function('previous=>window.qaLoads>previous',arg=previous)
        page.wait_for_function('saved=>Math.abs(scrollY-saved)<3',arg=saved)
        assert page.evaluate("document.activeElement.matches('main')")
        previous=page.evaluate('window.qaLoads');page.go_forward()
        page.wait_for_function('previous=>window.qaLoads>previous',arg=previous)
        assert page.url==origin+paths[1]
        # Query URLs, title/status, direct deep links and refresh remain complete SSR.
        visit(paths[2]+'?navigation-qa=1')
        page.reload(wait_until='networkidle');assert page.locator('main').count()==1
        # Deployment of a changed tracked entry starts a fresh document, not duplicate modules.
        target=origin+paths[2]+'?asset-version=1'
        def changed_entry(route):
            response=route.fetch();body=response.text()
            body=re.sub(r'(src="[^"]*navigation[^"]*)(")',lambda match:match.group(1)+('&amp;' if '?' in match.group(1) else '?')+'qa-asset=1'+match.group(2),body,count=1)
            route.fulfill(response=response,body=body)
        page.route(target,changed_entry);page.evaluate('window.qaAssetDocument=1')
        page.evaluate("target=>{const a=document.createElement('a');a.href=target;document.body.append(a);a.click()}",target)
        page.wait_for_url(target);page.wait_for_load_state('networkidle')
        page.wait_for_function('()=>window.qaAssetDocument===undefined')
        assert page.locator('main').count()==1 and page.locator('#fnlla-navigation-status').count()==1
        page.unroute(target,changed_entry)
        page.goto(origin+paths[-1],wait_until='networkidle');assert page.locator('main').count()==1
        assert not errors, errors
        context.close()
    plain=browser.new_context(java_script_enabled=False).new_page()
    for path in paths:
        response=plain.goto(origin+path,wait_until='networkidle')
        assert response.status==200 and plain.locator('main').count()==1
        assert plain.locator('a[data-fnlla-skip]').count()==1
    browser.close()
print('PASS: desktop/mobile/reduced motion, keyboard skip/focus, live route status, persistent shell geometry, asset/request reuse, repeated visits/history/queries/refresh/deep links and no-JS SSR; no console errors.')
