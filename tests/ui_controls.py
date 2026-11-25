"""Run the shipped JS and CSS in Chromium against a small deterministic DOM fixture."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from urllib.parse import urlparse, parse_qs
import os
from playwright.sync_api import sync_playwright, expect

ROOT=Path(__file__).resolve().parents[1]
FIXTURE="""<!doctype html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">
<meta name=csrf-token content=demo><link rel=stylesheet href=/assets/app.css><script defer src=/assets/app.js></script></head><body>
<aside><a href="/admin">后台</a></aside><main><div data-grid><form id=filters>
<input type=number name=page value=2><select name=per data-per><option>5</option><option>10</option></select>
<input name="q[name]" value="茶"><button data-clear type=button>×</button></form>
<label><input data-col=name type=checkbox checked>名称</label><label><input data-col=sku type=checkbox checked>货号</label>
<button data-grid-action=columns>字段</button><button data-grid-action=only>只看</button><button data-grid-action=hide>隐藏</button>
<button data-grid-action=reset>重置</button><button data-grid-action=csv>导出</button>
<div class=scroll><table><thead><tr><th><input type=checkbox data-all></th><th draggable=true data-field=name>名称</th>
<th draggable=true data-field=sku>货号</th></tr></thead><tbody><tr><td><input type=checkbox data-row=1></td><td>测试</td><td>JE-1</td></tr>
<tr><td><input type=checkbox data-row=2></td><td>测试</td><td>JE-2</td></tr></tbody></table></div></div>
<fieldset><legend><input type=checkbox data-group-all></legend><input type=checkbox name="pages[]" value=a><input type=checkbox name="pages[]" value=b></fieldset>
<button data-meal=7 draggable=true>菜一</button><button data-meal=9 draggable=true>菜二</button>
<form id=swap><input name=from><input name=to></form><form data-confirm="请确认"><button id=remove>删除</button></form>
<button id=speed>测速</button><output id=speed-result></output><a class=mobile-cart>购物车</a></main></body></html>"""

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path=urlparse(self.path).path
        if path in ("/assets/app.js","/assets/app.css"):
            name=path.split("/")[-1]
            payload=(ROOT/"resources/assets"/name).read_bytes()
            mime="application/javascript" if name.endswith("js") else "text/css"
        elif path=="/admin/dev/speed": payload,mime=b"x"*1048576,"application/octet-stream"
        else: payload,mime=FIXTURE.encode(),"text/html; charset=utf-8"
        self.send_response(200)
        self.send_header("Content-Type",mime)
        self.send_header("Content-Length",str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)
    def log_message(self,*args): pass

def exercise():
    server=ThreadingHTTPServer(("127.0.0.1",0),Handler)
    Thread(target=server.serve_forever,daemon=True).start()
    base=f"http://127.0.0.1:{server.server_port}/"
    try:
        with sync_playwright() as p:
            options={"executable_path":os.environ["CHROMIUM"]} if os.getenv("CHROMIUM") else {}
            browser=p.chromium.launch(**options)
            page=browser.new_page(viewport={"width":390,"height":844})
            page.goto(base)
            errors=[]
            page.on("pageerror",lambda e: errors.append(str(e)))
            def query(key):
                return parse_qs(urlparse(page.url).query).get(key,[])
            expect(page.locator(".mobile-cart")).to_be_visible()
            assert page.locator(".mobile-cart").evaluate("e=>getComputedStyle(e).position")=="fixed"
            page.locator(".mobile-nav").click()
            expect(page.locator("aside a")).to_be_visible()
            page.set_viewport_size({"width":1024,"height":768})
            expect(page.locator(".mobile-cart")).to_be_hidden()
            page.locator("[data-all]").check()
            expect(page.locator("[data-row='2']")).to_be_checked()
            page.locator("[data-row='2']").uncheck()
            assert page.locator("[data-all]").evaluate("e=>e.indeterminate")
            page.locator("[data-grid-action=only]").click()
            assert query("only")==["1"]
            page.locator("[data-grid-action=csv]").click()
            assert query("csv")==["1"] and query("only")==["1"]
            page.locator("[data-grid-action=reset]").click()
            assert not query("only") and not query("csv")
            page.locator("[data-row='2']").check()
            page.locator("[data-grid-action=hide]").click()
            assert query("hide")==["2"]
            page.locator("[data-col=sku]").uncheck()
            page.locator("[data-grid-action=columns]").click()
            assert query("columns[]")==["name"]
            page.locator("[data-field=sku]").drag_to(page.locator("[data-field=name]"))
            page.wait_for_function("new URL(location.href).searchParams.getAll('columns[]').join(',') === 'sku,name'")
            assert query("columns[]")==["sku","name"]
            page.locator("[data-clear]").click()
            assert not query("q[name]") and query("page")==["1"]
            page.locator("[data-per]").select_option("10")
            page.wait_for_function("new URL(location.href).searchParams.get('per') === '10'")
            assert query("per")==["10"] and query("page")==["1"]
            page.locator("[data-group-all]").check()
            expect(page.locator('input[value=b]')).to_be_checked()
            page.locator('input[value=a]').uncheck()
            assert page.locator("[data-group-all]").evaluate("e=>e.indeterminate")
            page.locator("[data-meal='7']").click()
            page.locator("[data-meal='9']").click()
            assert query("from")==["7"] and query("to")==["9"]
            previous=page.url
            page.once("dialog",lambda dialog:dialog.dismiss())
            page.locator("#remove").click()
            assert page.url==previous
            page.locator("#speed").click()
            expect(page.locator("#speed-result")).to_contain_text("Mbps")
            assert not errors,errors
            browser.close()
            print("PASS Chromium: mobile cart/navigation, responsive layout, row selection/filter/CSV intent, column visibility/drag, clear/page size, role group, meal exchange, cancelled deletion, network meter.")
    finally:
        server.shutdown()
if __name__=="__main__": exercise()
