const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/scroll-position.js', 'utf8');
const storage = new Map();
function page({frame = false, blocked = false, hash = ''} = {}) {
    const listeners = {};
    const sidebar = {scrollTop: 75};
    const table = {scrollLeft: 40};
    const window = {
        scrollX: 12, scrollY: 460,
        location: {href: 'http://localhost:8000/committees.php?term_id=3' + hash, origin: 'http://localhost:8000', pathname: '/committees.php', hash},
        addEventListener(type, callback) { listeners['window:' + type] = callback; },
        scrollTo(position) { this.scrollX = position.left; this.scrollY = position.top; },
    };
    window.top = frame ? {} : window;
    window.frameElement = frame ? {title: 'Membership'} : null;
    const document = {
        addEventListener(type, callback) { listeners[type] = callback; },
        querySelector() { return sidebar; },
        querySelectorAll() { return [table]; },
    };
    const sessionStorage = {
        getItem(key) { if (blocked) throw Error('disabled'); return storage.get(key); },
        setItem(key, value) { if (blocked) throw Error('disabled'); storage.set(key, value); },
        removeItem(key) { storage.delete(key); },
    };
    const history = {scrollRestoration: 'auto'};
    vm.runInNewContext(source, {window, document, history, sessionStorage, URL, requestAnimationFrame: callback => callback()});
    return {window, sidebar, table, listeners, history};
}
const first = page();
first.listeners.submit();
const next = page();
next.window.scrollY = next.window.scrollX = next.sidebar.scrollTop = next.table.scrollLeft = 0;
next.listeners.DOMContentLoaded();
assert.equal(next.window.scrollY, 460);
next.listeners['window:pagehide']();
const editor = page({hash: '#historical-legislation-form'});
editor.window.scrollY = 0;
editor.listeners.DOMContentLoaded();
editor.listeners['window:load']();
assert.equal(editor.window.scrollY, 0, 'Saved table scroll hid the explicit edit form target');
assert.equal(next.window.scrollX, 12);
assert.equal(next.sidebar.scrollTop, 75);
assert.equal(next.table.scrollLeft, 40);
assert.equal(next.history.scrollRestoration, 'manual');
next.listeners['window:load']();
assert.equal(next.window.scrollY, 460);
const child = page({frame: true});
child.window.scrollY = 900;
child.listeners.submit();
const parent = page();
parent.window.scrollY = 0;
parent.listeners.DOMContentLoaded();
assert.equal(parent.window.scrollY, 0, 'Child scroll leaked into parent');
assert.doesNotThrow(() => page({blocked: true}).listeners.submit());
assert.ok(!fs.readFileSync('public/assets/table-pagination.js', 'utf8').includes('scrollIntoView'));
console.log('PASS: Navigation and form scroll restoration, sidebar and horizontal positions, frame isolation, disabled storage, and pagination without scrolling.');
