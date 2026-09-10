const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
class Element {
    constructor(tag='div') { this.tag=tag; this.children=[]; this.attrs={}; this.events={}; this.hidden=false; this.value=''; this.dataset={}; this.classList={ toggle() {} }; }
    append(...items) { items.forEach(item => { this.children.push(item); item.parent=this; }); }
    before(item) { this.wrapper=item; }
    replaceChildren(...items) { this.children=[]; this.append(...items); }
    setAttribute(name,value) { this.attrs[name]=value; }
    removeAttribute(name) { delete this.attrs[name]; }
    addEventListener(name,callback) { (this.events[name] ||= []).push(callback); }
    emit(name, extra={}) { const event={ target:this, preventDefault() { this.prevented=true; }, ...extra }; (this.events[name] || []).forEach(cb => cb(event)); return event; }
    closest() { return this.form; }
    contains(item) { return item === this || this.children.some(child => child.contains(item)); }
    scrollIntoView() {}
}
async function main() {
    const input = new Element('input');
    input.dataset.recordSearchUrl='/system/personal_note_record_search.php';
    const form = new Element('form');
    form.status='Received'; form.submissions=0;
    form.requestSubmit=() => { form.submissions++; form.emit('submit'); };
    input.form=form;
    const document = new Element('document');
    document.querySelectorAll=() => [input];
    document.createElement=tag => new Element(tag);
    let timer;
    const calls=[];
    const pending=[];
    const context={ document, window:{location:{origin:'https://example.test'}}, URL, AbortController,
        setTimeout: cb => { timer=cb; return cb; }, clearTimeout: id => { if(timer===id) timer=null; },
        fetch: (url, options) => { calls.push([url,options]); return new Promise(resolve=>pending.push(resolve)); }
    };
    vm.runInNewContext(fs.readFileSync('public/assets/record-search-suggestions.js','utf8'),context);
    const tick=async()=>{if(timer){const cb=timer;timer=null;await Promise.resolve(cb());}};
    const flush=async()=>{for(let i=0;i<5;i++) await Promise.resolve();};
    const respond=async(records,ok=true)=>{pending.shift()({ok,json:async()=>({records})});await flush();};
    const list=input.wrapper.children[1];
    input.value='a';input.emit('input');await tick();assert.equal(calls.length,0);
    input.value='road';input.emit('input');const first=tick();await flush();
    assert.equal(calls[0][0].pathname,'/system/personal_note_record_search.php');
    assert.equal(calls[0][0].searchParams.get('q'),'road');
    await respond([{control_number:'C-00001-2026',title:'<b>Road</b>',status:'Received'}]);await first;
    assert.equal(list.hidden,false);assert.equal(list.children[0].children[1].textContent,'<b>Road</b>');
    assert.equal(input.attrs['aria-expanded'],'true');
    input.emit('keydown',{key:'ArrowDown'});const selected=input.emit('keydown',{key:'Enter'});
    assert.equal(selected.prevented,true);assert.equal(input.value,'C-00001-2026');assert.equal(form.submissions,1);assert.equal(form.status,'Received');assert.equal(list.hidden,true);
    input.value='old';input.emit('input');const old=tick();await flush();
    input.value='new';input.emit('input');const current=tick();await flush();
    await respond([{control_number:'OLD',title:'Old result'}]);await old;
    assert.equal(list.children[0].textContent,'Searching records…');
    await respond([{control_number:'NEW',title:'Current result'}]);await current;
    assert.equal(list.children[0].children[0].textContent,'NEW');
    input.emit('keydown',{key:'Escape'});assert.equal(list.hidden,true);
    input.value='fail';input.emit('input');const failure=tick();await flush();await respond([],false);await failure;
    assert.match(list.children[0].textContent,/Press Enter/);
    const enter=input.emit('keydown',{key:'Enter'});assert.equal(enter.prevented,undefined);
    input.value='none';input.emit('input');const empty=tick();await flush();await respond([]);await empty;
    assert.match(list.children[0].textContent,/No matching records/);
    input.emit('input');input.wrapper.emit('focusout',{relatedTarget:null});await tick();assert.equal(list.hidden,true);
    console.log('PASS: minimum query, subfolder URL, safe text rendering, keyboard selection, filter retention, stale responses, Escape, failure fallback, no results, and focus dismissal.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
