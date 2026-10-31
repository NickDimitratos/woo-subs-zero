const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

function prepare(manual, cart = 'subscription', sepa = '', version = '10.7.0', cardTokens = 'yes') {
    const scripts = JSON.parse(execFileSync('php', [path.join(__dirname, '../fixtures/paynl-block-checkout.php'), manual, cart, sepa, version, cardTokens], { encoding: 'utf8' }));
    const original = ['pay_gateway_ideal', 'pay_gateway_incasso', 'pay_gateway_banktransfer', 'pay_gateway_visa', 'stripe', 'pay_gateway_custom'].map(paymentMethodId => ({ paymentMethodId, title: paymentMethodId, description: 'Initial payment', issuers: ['Bank A'] }));
    const context = vm.createContext({ window: { paynl_gateways: original } });
    function run() {
        scripts.forEach(({ handle, script, position }) => {
            assert.equal(handle, 'paynl-blocks-js');
            assert.equal(position, 'before');
            vm.runInContext(script, context);
        });
        return JSON.parse(JSON.stringify(context.window.paynl_gateways));
    }
    return { scripts, original, run, context };
}

test('PAY.nl Block hides unsupported methods while preserving cards and explicit adapters', () => {
    const { run } = prepare('no');
    assert.deepEqual(run().map(method => method.paymentMethodId), ['pay_gateway_visa', 'stripe', 'pay_gateway_custom']);
});

test('PAY.nl Block discloses manual payment for each renewal before purchase', () => {
    const { run } = prepare('yes');
    const methods = run();
    const ideal = methods.find(method => method.paymentMethodId === 'pay_gateway_ideal');
    assert.match(ideal.title, /manual subscription renewals/);
    assert.match(ideal.description, /not collected automatically/);
    assert.match(ideal.description, /pay each renewal manually/);
    assert.deepEqual(ideal.issuers, ['Bank A']);
    assert.match(methods.find(method => method.paymentMethodId === 'pay_gateway_incasso').title, /manual subscription renewals/);
    assert.equal(methods.find(method => method.paymentMethodId === 'stripe').description, 'Initial payment');
    assert.deepEqual(run(), methods);
});

test('ordinary product checkout remains unchanged', () => {
    const { run, original, scripts } = prepare('no', 'ordinary');
    assert.deepEqual(scripts, []);
    assert.deepEqual(run(), original);
});

test('missing PAY.nl Block data is safe', () => {
    const { run, context } = prepare('yes');
    context.window.paynl_gateways = undefined;
    assert.doesNotThrow(() => {
        // Execute the production scripts directly; there is no provider list to transform.
        prepare('yes').scripts.forEach(({ script }) => vm.runInContext(script, context));
    });
});


test('configured SEPA keeps iDEAL available without reclassifying bank transfer or altering cards', () => {
    const { run } = prepare('no', 'subscription', 'sepa');
    const methods = run();
    assert.deepEqual(methods.map(method => method.paymentMethodId), ['pay_gateway_ideal', 'pay_gateway_visa', 'stripe', 'pay_gateway_custom']);
    const ideal = methods.find(method => method.paymentMethodId === 'pay_gateway_ideal');
    assert.doesNotMatch(ideal.title, /manual/);
    assert.deepEqual(ideal.issuers, ['Bank A']);
});

test('older Checkout Blocks cannot offer SEPA without supported mandate fields', () => {
    const { run } = prepare('no', 'subscription', 'sepa', '8.8.0');
    assert.deepEqual(run().map(method => method.paymentMethodId), ['pay_gateway_visa', 'stripe', 'pay_gateway_custom']);
});

test('disabled card adapter is hidden when manual subscriptions are disabled', () => {
    const { run } = prepare('no', 'subscription', '', '10.7.0', 'no');
    assert.deepEqual(run().map(method => method.paymentMethodId), ['stripe', 'pay_gateway_custom']);
});

test('disabled card adapter is clearly labelled when manual subscriptions are allowed', () => {
    const { run } = prepare('yes', 'subscription', '', '10.7.0', 'no');
    assert.match(run().find(method => method.paymentMethodId === 'pay_gateway_visa').title, /manual subscription renewals/);
});
