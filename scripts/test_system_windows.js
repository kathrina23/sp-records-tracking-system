const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/system-windows.js', 'utf8');

class Target {
    constructor() { this.listeners = {}; }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
    dispatchEvent(event) {
        event.preventDefault ||= () => { event.defaultPrevented = true; };
        event.stopImmediatePropagation ||= () => { event.stopped = true; };
        for (const listener of this.listeners[event.type] || []) {
            listener(event);
            if (event.stopped) break;
        }
    }
}
class Element extends Target {
    constructor(tag) { super(); this.tag = tag; this.children = []; this.target = ''; this.textContent = ''; }
    setAttribute() {}
    hasAttribute() { return false; }
    append(...children) { this.children.push(...children); }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent({type: 'close'}); }
    remove() { this.removed = true; }
    focus() { this.focused = true; }
    closest() { return this; }
    matches(selector) { return selector.split(', ').includes(this.selector); }
}
const origin = 'http://localhost/sp-records-tracking-system/public/';
function setup() {
    const document = new Target();
    const fields = [
        {name: 'title', type: 'text', value: 'Original'},
        {name: 'remarks', type: 'textarea', value: ''},
        {name: 'enabled', type: 'checkbox', value: '1', checked: false},
        {name: 'attachments[]', type: 'file', value: '', files: []},
    ];
    document.forms = [{elements: fields}];
    document.body = new Element('body');
    document.createElement = tag => new Element(tag);
    let reloads = 0;
    vm.runInNewContext(source, {document, URL, Event, window: {location: {
        href: origin + 'record_form.php?id=1', reload() { reloads++; },
    }}});
    function click(path, options = {}) {
        const link = new Element('a');
        link.href = new URL(path, origin).href;
        link.textContent = 'Open';
        if (options.target) link.target = options.target;
        const event = {type: 'click', target: link, button: 0, ...options};
        // target above is the DOM node; link.target is the browsing context.
        event.target = link;
        document.dispatchEvent(event);
        return {event, link, dialog: document.body.children.at(-1)};
    }
    function load(dialog, path) {
        const frame = dialog.children[1];
        const child = new Target();
        child.URL = new URL(path || frame.src, origin).href;
        child.querySelector = () => null;
        frame.contentDocument = child;
        frame.dispatchEvent({type: 'load'});
        return child;
    }
    return {fields, click, load, reloads: () => reloads};
}

for (const page of ['record_attachments_view.php', 'record_attachments_manage.php',
    'record_view.php', 'record_form.php?id=2', 'record_update.php', 'record_update_edit.php',
    'plenary_number_form.php', 'committee_roster.php', 'log_history.php', 'record_recipients.php',
    'users.php?edit=1', 'officials.php?edit=1', 'committees.php?edit=1', 'terms.php?edit=1']) {
    const app = setup();
    app.fields[0].value = 'Draft title';
    app.fields[1].value = 'Unfinished remarks';
    app.fields[2].checked = true;
    const selectedFiles = [{name: 'draft.pdf', size: 32, lastModified: 123}];
    app.fields[3].files = selectedFiles;
    const {dialog, event, link} = app.click(page);
    assert.ok(event.defaultPrevented, page);
    const child = app.load(dialog);
    // Even a saved child must not cause the parent draft to reload.
    child.dispatchEvent({type: 'submit', target: {method: 'post'}});
    dialog.close();
    assert.equal(app.reloads(), 0, page);
    assert.equal(app.fields[0].value, 'Draft title');
    assert.equal(app.fields[1].value, 'Unfinished remarks');
    assert.equal(app.fields[2].checked, true);
    assert.equal(app.fields[3].files, selectedFiles);
    assert.ok(link.focused);
}
for (const path of ['dashboard.php', 'records.php', 'messengerial.php', 'reports.php']) {
    const app = setup();
    assert.ok(!app.click(path).event.defaultPrevented, 'Clean navigation is normal');
    app.fields[1].value = 'Keep this';
    assert.ok(app.click(path).event.defaultPrevented, 'Dirty navigation preserves the form');
}
for (const path of ['logout.php', 'record_delete.php', 'record_attachment.php?id=1', 'https://example.com/record_view.php']) {
    const app = setup();
    app.fields[0].value = 'Draft';
    assert.ok(!app.click(path).event.defaultPrevented, path);
}
for (const options of [{ctrlKey: true}, {metaKey: true}, {shiftKey: true}, {button: 1}, {target: '_blank'}]) {
    assert.ok(!setup().click('record_view.php', options).event.defaultPrevented);
}
{
    const app = setup();
    const {dialog} = app.click('users.php?edit=1');
    const child = app.load(dialog);
    child.dispatchEvent({type: 'submit', target: {method: 'post'}});
    app.load(dialog, 'users.php');
    assert.ok(dialog.removed, 'Save redirect closes the editor');
    assert.equal(app.reloads(), 1, 'Clean parent refreshes after saving');
}
{
    const app = setup();
    const {dialog} = app.click('record_update.php?id=1');
    const child = app.load(dialog);
    child.dispatchEvent({type: 'submit', target: {method: 'post'}, defaultPrevented: true});
    dialog.close();
    assert.equal(app.reloads(), 0, 'Rejected submissions do not refresh the parent');
}
for (const label of ['Cancel', 'Close', 'Back to Record']) {
    const app = setup();
    const {dialog} = app.click('record_update.php?id=1');
    const child = app.load(dialog);
    const link = new Element('a');
    link.textContent = label;
    const event = {type: 'click', target: link, button: 0};
    child.dispatchEvent(event);
    assert.ok(event.defaultPrevented && dialog.removed, label);
    assert.equal(app.reloads(), 0);
}
console.log('PASS: system windows, draft and file preservation, navigation, close controls, save redirects, and rejected submissions.');
