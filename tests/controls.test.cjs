'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');
const script = readFileSync(join(__dirname,'../resources/assets/app.js'),'utf8');

function element(fields={}) {
    return Object.assign({
        dataset:{}, checked:false, attributes:{},
        setAttribute(k,v){ this.attributes[k]=v; },
        querySelectorAll(){ return []; },
        addEventListener(type,fn){ this['on'+type]=fn; }
    },fields);
}
function run(first={},many={},extra={}) {
    const doc = {
        querySelector:q=>first[q]||null, querySelectorAll:q=>many[q]||[],
        createElement:()=>element()
    };
    vm.runInNewContext(script,{document:doc,URL,performance,window:{confirm:()=>false},...extra});
}
function makeGrid(href='https://recipe.laravel.demo/admin/recipes') {
    const mark=element(), boxes=['3','5'].map(row=>element({dataset:{row}}));
    const choices=['name','sku'].map(col=>element({dataset:{col},checked:true}));
    const buttons=['only','hide','reset','columns','csv'].map(mode=>element({dataset:{gridAction:mode}}));
    const headers=['name','sku'].map(field=>element({dataset:{field}}));
    const filter=element({previousElementSibling:{value:'鱼'}}), per=element();
    const form={elements:{page:{value:2}},requestSubmit(){ this.submitted=true; }};
    const storage=new Map(); const location={href,pathname:'/admin/recipes',assign(url){ this.href=url; }};
    const grid=element({
        querySelector:q=>({'[data-all]':mark,'[data-per]':per})[q]||null,
        querySelectorAll:q=>({'[data-row]':boxes,'[data-grid-action]':buttons,'[data-col]:checked':choices.filter(e=>e.checked),
            '[data-field]':headers,'[data-clear]':[filter]})[q]||[]
    });
    run({'[data-grid]':grid,'#filters':form},{},{location,sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},alert(){}});
    return {mark,boxes,choices,headers,form,filter,per,location,press:mode=>buttons.find(x=>x.dataset.gridAction===mode).onclick(),params:()=>new URL(location.href).searchParams};
}
test('勾选、跨页筛选、导出与恢复',()=>{
    const x=makeGrid(); x.mark.checked=true; x.mark.onchange();
    assert.ok(x.boxes.every(b=>b.checked)); x.boxes[1].checked=false; x.boxes[1].onchange();
    assert.equal(x.mark.indeterminate,true); x.press('only'); assert.equal(x.params().get('only'),'3');
    x.press('csv'); assert.equal(x.params().get('csv'),'1'); assert.equal(x.params().get('only'),'3');
    x.press('reset'); assert.equal(x.params().has('only'),false); assert.ok(x.boxes.every(b=>!b.checked));
});
test('隐藏行、列与拖动字段顺序',()=>{
    const x=makeGrid(); x.boxes[1].checked=true; x.boxes[1].onchange(); x.press('hide');
    assert.equal(x.params().get('hide'),'5'); assert.equal(x.boxes[1].checked,false);
    x.choices[1].checked=false; x.press('columns'); assert.deepEqual(x.params().getAll('columns[]'),['name']);
    x.headers[1].ondragstart(); x.headers[0].ondrop({preventDefault(){}});
    assert.deepEqual(x.params().getAll('columns[]'),['sku','name']);
});
test('搜索清除与翻页尺寸',()=>{
    const x=makeGrid(); x.filter.onclick(); assert.equal(x.filter.previousElementSibling.value,'');
    assert.equal(x.form.elements.page.value,1); assert.ok(x.form.submitted);
    x.form.submitted=false; x.per.onchange(); assert.ok(x.form.submitted);
});
test('角色全选与部分选择',()=>{
    const xs=[element(),element()]; const group=element({closest:()=>({querySelectorAll:()=>xs})});
    run({},{'[data-group-all]':[group]}); group.checked=true; group.onchange();
    assert.ok(xs.every(e=>e.checked)); xs[0].checked=false; xs[0].onchange(); assert.equal(group.indeterminate,true);
});
test('触屏点选与拖动交换食谱',()=>{
    const a=element({dataset:{meal:'7'}}),b=element({dataset:{meal:'8'}});
    const form={elements:{from:{},to:{}},requestSubmit(){ this.count=(this.count||0)+1; }};
    run({'#swap':form},{'[data-meal]':[a,b]}); a.onclick(); b.onclick();
    assert.equal(form.elements.from.value,'7'); assert.equal(form.elements.to.value,'8');
    b.ondragstart(); a.ondrop({preventDefault(){}});
    assert.equal(form.elements.from.value,'8'); assert.equal(form.count,2);
});
test('删除确认可取消',()=>{
    const form=element({dataset:{confirm:'确认删除？'}}); run({},{'[data-confirm]':[form]});
    let stopped=false; form.onsubmit({preventDefault(){stopped=true;}}); assert.ok(stopped);
});
test('网速以下载字节和实际耗时计算',async()=>{
    const button=element(),out=element(); let clock=0;
    run({'#speed':button,'#speed-result':out},{},{performance:{now:()=>{ clock+=1000; return clock; }},
        fetch:async()=>({ok:true,arrayBuffer:async()=>new ArrayBuffer(1048576)})});
    await button.onclick(); assert.equal(out.textContent,'8.39 Mbps'); assert.equal(button.disabled,false);
});
test('窄屏菜单展开状态',()=>{
    let opened=false,toggle;
    const side=element({classList:{toggle(){ opened=!opened; return opened; }},prepend(el){toggle=el;}});
    run({aside:side}); assert.equal(toggle.attributes['aria-expanded'],'false');
    toggle.onclick(); assert.equal(toggle.attributes['aria-expanded'],'true');
});