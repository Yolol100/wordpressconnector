/* Pure Node contract for the WordPress React tab adapter, no browser package needed. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'plugin', 'wordpressconnector', 'assets', 'admin-ui.js'), 'utf8');
const names = ['overview', 'chatgpt', 'github', 'advanced'];
const panels = Object.fromEntries(names.map((name) => [
    'wpconnector-' + name,
    {
        hidden: false,
        attrs: {},
        setAttribute(k, v) { this.attrs[k] = v; }
    }
]));
const mount = { id: 'wpconnector-admin-tabs' };
const doc = { getElementById(id) { return id === mount.id ? mount : panels[id] || null; } };
const listeners = {};
let active;
let tree;
let focusLast = '';
let nav;
const win = {
    document: doc,
    location: { hash: '#wpconnector-chatgpt' },
    history: {
        replaceState(_state, _title, newHash) { win.location.hash = newHash; }
    },
    addEventListener(name, callback) { listeners[name] = callback; },
    removeEventListener(name) { delete listeners[name]; },
    WPConnectorAdminUI: {
        navLabel: 'Sections',
        tabs: names.map((name) => ({ name, title: name }))
    }
};
const Button = Symbol('WordPressButton');
const element = {
    createElement(type, props, ...children) {
        if (typeof type === 'function') { nav = type; return type(props); }
        return { type, props: props || {}, children };
    },
    useState(getInitial) {
        if (active === undefined) {
            active = getInitial();
        }
        return [active, (next) => {
            active = next;
            tree = nav();
            attachRefs(tree);
        }];
    },
    useRef() { return { current: {} }; },
    useEffect(callback) { callback(); },
    createRoot(node) {
        assert.equal(node, mount);
        return { render(output) { tree = output; attachRefs(tree); } };
    }
};
function attachRefs(output) {
    for (const node of output.children.flat(Infinity)) {
        node.props.ref({ focus() { focusLast = node.props.id; } });
    }
}
win.wp = { element, components: { Button } };
const context = { window: win, document: doc };
vm.runInNewContext(source, context, { filename: 'admin-ui.js' });

assert.ok(tree);
assert.equal(tree.props.role, 'tablist');
assert.equal(tree.children.flat(Infinity).length, 4);
assert.equal(panels['wpconnector-chatgpt'].hidden, false);
assert.equal(panels['wpconnector-overview'].hidden, true);
assert.equal(panels['wpconnector-chatgpt'].attrs.role, 'tabpanel');

const advancedTab = tree.children.flat(Infinity).find((node) => node.props.id === 'wpconnector-tab-advanced');
advancedTab.props.onClick();
assert.equal(active, 'advanced');
assert.equal(win.location.hash, '#wpconnector-advanced');
assert.equal(panels['wpconnector-advanced'].hidden, false);
assert.equal(panels['wpconnector-chatgpt'].hidden, true);

let prevented = false;
const lastTab = tree.children.flat(Infinity).find((node) => node.props.id === 'wpconnector-tab-advanced');
lastTab.props.onKeyDown({ key: 'ArrowRight', preventDefault() { prevented = true; } });
assert.equal(prevented, true);
assert.equal(active, 'overview');
assert.equal(focusLast, 'wpconnector-tab-overview');

win.location.hash = '#wpconnector-github';
assert.equal(typeof listeners.hashchange, 'function');
listeners.hashchange();
assert.equal(active, 'github');
assert.equal(panels['wpconnector-github'].hidden, false);
assert.equal(panels['wpconnector-overview'].hidden, true);

// Without React the server-side fallback must remain fully readable.
const fallbackPanels = Object.fromEntries(names.map((name) => [
    'wpconnector-' + name, { hidden: false }
]));
vm.runInNewContext(source, {
    window: { WPConnectorAdminUI: win.WPConnectorAdminUI },
    document: { getElementById(id) { return id === mount.id ? mount : fallbackPanels[id]; } }
}, { filename: 'admin-ui-fallback.js' });
assert.ok(Object.values(fallbackPanels).every((panel) => !panel.hidden));

console.log('WordPress React admin navigation PASS: tab selection, keyboard, hash links, and no-JS fallback.');
