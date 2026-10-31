const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const script = path.join(__dirname, '../../assets/js/wsz-email-preview.js');
const url = type => `http://localhost/wp-admin/?preview_woocommerce_mail=true&_wpnonce=test&type=${type}`;
const manual = 'WSZ_Manual_Renewal_Email';
const reminder = 'WSZ_Manual_Renewal_Reminder_Email';

function setup(source = url(manual)) {
    const attributes = { src: source };
    const frame = {
        tagName: 'IFRAME',
        isConnected: true,
        getAttribute: name => attributes[name] ?? null,
        setAttribute: (name, value) => { attributes[name] = String(value); },
        removeAttribute: name => { delete attributes[name]; },
    };
    let observer;
    const requests = [];
    const context = {
        URL,
        window: { location: { href: 'http://localhost/wp-admin/admin.php?page=wc-settings&tab=email', origin: 'http://localhost' } },
        document: { documentElement: {}, querySelectorAll: () => [frame] },
        MutationObserver: class { constructor(callback) { observer = callback; } observe() {} },
        fetch: (input, options) => new Promise(resolve => requests.push({ input, options, resolve })),
    };
    if (fs.existsSync(script)) vm.runInNewContext(fs.readFileSync(script, 'utf8'), context);
    return { frame, attributes, requests, change: value => { frame.setAttribute('src', value); observer([{ type: 'attributes', target: frame }]); } };
}
async function respond(request, body = '<html>Renew Now</html>', overrides = {}) {
    request.resolve({ ok: true, redirected: false, headers: { get: () => 'text/html; charset=UTF-8' }, text: async () => body, ...overrides });
    await new Promise(resolve => setImmediate(resolve));
}

test('renders the renewal preview with a sandboxed same-origin response', async () => {
    const state = setup();
    assert.equal(state.requests.length, 1, 'the custom preview should be requested');
    assert.equal(state.requests[0].options.credentials, 'same-origin');
    await respond(state.requests[0]);
    assert.equal(state.attributes.srcdoc, '<html>Renew Now</html>');
    assert.equal(state.attributes.sandbox, '');
    assert.equal(state.attributes.src, url(manual));
});

test('ignores other email types and foreign preview URLs', () => {
    for (const source of [url('WC_Email_Customer_Processing_Order'), url(manual).replace('localhost', 'elsewhere'), 'http://localhost/other/?preview_woocommerce_mail=true&type=' + manual]) {
        assert.equal(setup(source).requests.length, 0);
    }
});

test('does not show an older response after switching previews', async () => {
    const state = setup();
    assert.equal(state.requests.length, 1);
    state.change(url(reminder));
    assert.equal(state.requests.length, 2);
    await respond(state.requests[1], 'new preview');
    await respond(state.requests[0], 'old preview');
    assert.equal(state.attributes.srcdoc, 'new preview');
});

test('restores WooCommerce iframe when switching to another email', async () => {
    const state = setup();
    assert.equal(state.requests.length, 1);
    await respond(state.requests[0]);
    state.change(url('WC_Email_Customer_Processing_Order'));
    assert.equal(state.attributes.srcdoc, undefined);
    assert.equal(state.attributes.sandbox, undefined);
    assert.equal(state.requests.length, 1);
});

test('does not render a failed or redirected response', async () => {
    const state = setup();
    assert.equal(state.requests.length, 1);
    await respond(state.requests[0], 'login page', { redirected: true });
    assert.equal(state.attributes.srcdoc, undefined);
});
