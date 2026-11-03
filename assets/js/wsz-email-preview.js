/* Keep WooCommerce's own preview URL and nonce, but render its response in a
 * sandbox when embedded navigation is blocked by a browser environment. */
(function () {
    'use strict';

    var frames = new WeakMap();
    var types = ['WSZ_Manual_Renewal_Email', 'WSZ_Manual_Renewal_Reminder_Email'];

    function isRenewalPreview(source) {
        try {
            var url = new URL(source, window.location.href);
            return url.origin === window.location.origin && /\/wp-admin\/$/.test(url.pathname)
                && url.searchParams.get('preview_woocommerce_mail') === 'true'
                && url.searchParams.has('_wpnonce') && types.indexOf(url.searchParams.get('type')) !== -1;
        } catch (error) {
            return false;
        }
    }

    function restore(frame, state) {
        if (!state || !state.rendered) return;
        frame.removeAttribute('srcdoc');
        if (state.sandbox === null) frame.removeAttribute('sandbox');
        else frame.setAttribute('sandbox', state.sandbox);
        state.rendered = false;
    }

    function update(frame) {
        var source = frame.getAttribute('src');
        var state = frames.get(frame);
        if (state) {
            state.generation++;
            restore(frame, state);
        }
        if (!source || !isRenewalPreview(source)) return;
        if (!state) {
            state = { generation: 0, sandbox: frame.getAttribute('sandbox'), rendered: false };
            frames.set(frame, state);
        }
        var generation = state.generation;
        fetch(source, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok || response.redirected || !/^text\/html\b/i.test(response.headers.get('content-type') || '')) return null;
            return response.text();
        }).then(function (html) {
            if (html === null || !frame.isConnected || state.generation !== generation || frame.getAttribute('src') !== source) return;
            frame.setAttribute('sandbox', '');
            frame.setAttribute('srcdoc', html);
            state.rendered = true;
        }).catch(function () {
            // Leave the native WooCommerce preview intact on a network error.
        });
    }

    function scan() {
        document.querySelectorAll('iframe[src]').forEach(function (frame) {
            if (!frames.has(frame)) update(frame);
        });
    }

    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.type === 'attributes' && mutation.target.tagName === 'IFRAME') update(mutation.target);
        });
        if (mutations.some(function (mutation) { return mutation.type === 'childList'; })) scan();
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    scan();
}());
