'use strict';
const all = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const aside = document.querySelector('aside');
if (aside) {
    const toggle = document.createElement('button');
    toggle.className = 'mobile-nav alt'; toggle.textContent = '导航菜单';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.onclick = () => toggle.setAttribute('aria-expanded', String(aside.classList.toggle('open')));
    aside.prepend(toggle);
}
all('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));
all('[data-group-all]').forEach(box => {
    const children = all('input[name="pages[]"]', box.closest('fieldset'));
    function refresh() {
        box.checked = children.every(el => el.checked);
        box.indeterminate = !box.checked && children.some(el => el.checked);
    }
    box.onchange = () => { children.forEach(el => el.checked = box.checked); refresh(); };
    children.forEach(el => el.onchange = refresh); refresh();
});
const grid = document.querySelector('[data-grid]');
if (grid) {
    const here = new URL(location.href);
    const storeKey = 'selection:' + location.pathname + ':' + (here.searchParams.get('table') || '');
    let selected;
    try { selected = new Set(JSON.parse(sessionStorage.getItem(storeKey) || '[]')); } catch { selected = new Set(); }
    const boxes = all('[data-row]', grid);
    const mark = grid.querySelector('[data-all]');
    function sync() {
        boxes.forEach(box => box.checked = selected.has(box.dataset.row));
        mark.checked = boxes.length > 0 && boxes.every(box => box.checked);
        mark.indeterminate = !mark.checked && boxes.some(box => box.checked);
        sessionStorage.setItem(storeKey, JSON.stringify([...selected]));
    }
    function change(box, checked) { if (checked) selected.add(box.dataset.row); else selected.delete(box.dataset.row); }
    boxes.forEach(box => box.onchange = () => { change(box, box.checked); sync(); });
    mark.onchange = () => { boxes.forEach(box => change(box, mark.checked)); sync(); };
    sync();
    function setColumns(url, keys) {
        for (const key of [...url.searchParams.keys()]) if (/^columns(?:\[|$)/.test(key)) url.searchParams.delete(key);
        keys.forEach(key => url.searchParams.append('columns[]', key));
    }
    all('[data-grid-action]', grid).forEach(button => button.onclick = () => {
        const url = new URL(location.href); const mode = button.dataset.gridAction;
        url.searchParams.delete('csv'); url.searchParams.set('page', '1');
        if (['only','hide'].includes(mode) && !selected.size) return alert('请先勾选记录。');
        if (mode === 'columns') {
            const keys = all('[data-col]:checked', grid).map(el => el.dataset.col);
            if (!keys.length) return alert('请至少显示一个字段。');
            setColumns(url, keys);
        }
        if (mode === 'only') url.searchParams.set('only', [...selected].join(','));
        if (mode === 'hide') {
            const old = (url.searchParams.get('hide') || '').split(',').filter(Boolean);
            url.searchParams.set('hide', [...new Set([...old,...selected])].join(',')); selected.clear(); sync();
        }
        if (mode === 'reset') { url.searchParams.delete('only'); url.searchParams.delete('hide'); selected.clear(); sync(); }
        if (mode === 'csv') {
            url.searchParams.set('csv','1');
            if (selected.size) {
                const only = url.searchParams.get('only')?.split(',');
                const rows = [...selected].filter(id => !only || only.includes(id));
                url.searchParams.set('only',rows.length ? rows.join(',') : '0');
            }
        }
        location.assign(url.toString());
    });
    const form = document.querySelector('#filters');
    all('[data-clear]',grid).forEach(button => button.onclick = () => {
        button.previousElementSibling.value = ''; form.elements.page.value = 1; form.requestSubmit();
    });
    grid.querySelector('[data-per]').onchange = () => { form.elements.page.value = 1; form.requestSubmit(); };
    let source;
    const headers = all('[data-field]',grid);
    headers.forEach(th => {
        th.ondragstart = () => { source = th.dataset.field; };
        th.ondragover = e => e.preventDefault();
        th.ondrop = e => {
            e.preventDefault(); if (!source || source === th.dataset.field) return;
            const keys = headers.map(el => el.dataset.field).filter(key => key !== source);
            keys.splice(keys.indexOf(th.dataset.field),0,source);
            const url = new URL(location.href); setColumns(url,keys); location.assign(url.toString());
        };
    });
    const remove = grid.querySelector('[data-audit]');
    if (remove) remove.onclick = () => {
        if (!selected.size || !confirm('确认删除所选审计日志？')) return;
        const form = document.createElement('form'); form.method = 'post'; form.action = '/admin/dev/audit';
        for (const [name,value] of [['_method','DELETE'],['_token',document.querySelector('meta[name=csrf-token]').content],['table',remove.dataset.audit],...[...selected].map(id => ['ids[]',id])]) {
            const input = document.createElement('input'); input.type='hidden'; input.name=name; input.value=value; form.append(input);
        }
        document.body.append(form); selected.clear(); sync(); form.submit();
    };
}
let sourceMeal;
const meals = all('[data-meal]');
function exchange(target) {
    if (!sourceMeal || sourceMeal === target) { sourceMeal = target; meals.forEach(m => m.setAttribute('aria-pressed', String(m === target))); return; }
    const form = document.querySelector('#swap'); form.elements.from.value = sourceMeal.dataset.meal;
    form.elements.to.value = target.dataset.meal; form.requestSubmit();
}
meals.forEach(m => {
    m.onclick = () => exchange(m); m.ondragstart = () => { sourceMeal = m; };
    m.ondragover = e => e.preventDefault(); m.ondrop = e => { e.preventDefault(); exchange(m); };
});
const speed = document.querySelector('#speed');
if (speed) speed.onclick = async () => {
    const out = document.querySelector('#speed-result'); out.textContent = '测量中…'; speed.disabled=true;
    try {
        const start=performance.now(); const response=await fetch('/admin/dev/speed',{cache:'no-store'});
        if (!response.ok) throw Error(); const data=await response.arrayBuffer();
        out.textContent=(data.byteLength*8/(performance.now()-start)/1000).toFixed(2)+' Mbps';
    } catch { out.textContent='测量失败，请重试。'; } finally { speed.disabled=false; }
};