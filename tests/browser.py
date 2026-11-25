"""Full-stack Chromium acceptance checks. Run only against the disposable Compose network."""
import os
from playwright.sync_api import sync_playwright, expect
from pathlib import Path
from datetime import date
password=os.environ["HEJI_BROWSER_PASSWORD"]
out=Path("/results")
with sync_playwright() as p:
    browser=p.chromium.launch()
    for host in ("shop","stock","recipe"):
        log=out/(host+".laravel.demo.log")
        try:
            context=browser.new_context(ignore_https_errors=True,viewport={"width":390,"height":844},is_mobile=True,has_touch=True)
            page=context.new_page()
            errors=[]
            page.on("pageerror",lambda exc: errors.append(str(exc)))
            base="https://"+host+".laravel.demo"
            page.goto(base+"/login")
            page.get_by_label("邮箱").fill("browser@example.test")
            page.get_by_label("密码",exact=True).fill(password)
            page.get_by_role("button",name="登录",exact=True).click()
            expect(page).to_have_url(base+"/admin")
            if host=="shop":
                page.goto(base+"/")
                expect(page.locator(".mobile-cart")).to_be_visible()
                page.get_by_role("button",name="加入购物车").first.click()
                expect(page.locator("#basket")).to_contain_text("合计")
                page.get_by_role("button",name="确认下单").click()
                expect(page.locator("h1")).to_contain_text("订单")
            if host=="stock":
                page.goto(base+"/")
                page.get_by_label("采购数量").fill("3")
                page.get_by_role("button",name="提交采购单").click()
                expect(page.locator("tbody")).to_contain_text("待收货")
            if host=="recipe":
                page.goto(base+"/admin/recipes")
                expect(page.locator("[data-row]")).to_have_count(5)
                assert page.locator(".scroll").evaluate("el => el.scrollWidth > el.clientWidth")
                page.locator("[data-row]").first.check()
                page.locator("[data-grid-action=only]").click()
                expect(page.locator("[data-row]")).to_have_count(1)
                with page.expect_download() as download:
                    page.locator("[data-grid-action=csv]").click()
                assert download.value.suggested_filename.endswith(".csv")
                page.locator("[data-grid-action=reset]").click()
                page.goto(base+"/admin/calendar")
                month=page.locator(".calendar-month")
                start=date.fromisoformat(month.get_attribute("data-start"))
                end=date.fromisoformat(month.get_attribute("data-end"))
                expect(month.locator(".day")).to_have_count((end-start).days+1)
                expect(page.locator(".cal-index")).to_be_visible()
                tomorrow=page.locator('input[type="date"][name="day"]').get_attribute("min")
                page.get_by_role("link",name="周视图",exact=True).click()
                expect(page.locator(".calendar-week .day")).to_have_count(7)
                page.get_by_role("link",name="月视图",exact=True).click()
                expect(page.locator(".calendar-month")).to_be_visible()
                page.set_viewport_size({"width":1024,"height":768})
                page.goto(base+"/admin/calendar?view=week&date="+tomorrow)
                expect(page.locator("[data-meal]").first).to_be_visible()
                page.set_viewport_size({"width":1440,"height":900})
                page.get_by_role("link",name="月视图",exact=True).click()
                expect(page.locator(".cal-index")).to_be_hidden()
                expect(page.locator(".cal-weekday").first).to_be_visible()
                expect(page.get_by_role("heading",name="饮食日历")).to_be_visible()
            if errors: raise RuntimeError(errors)
            with log.open("a") as f: print("PASS Chromium 页面、布局与业务交互",file=f)
            context.close()
        except Exception as error:
            with log.open("a") as f: print("FAIL Chromium",type(error).__name__,file=f)
            raise
    browser.close()