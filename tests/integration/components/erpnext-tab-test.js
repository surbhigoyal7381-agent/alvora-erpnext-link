import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click, fillIn, waitUntil, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { setupIntl } from 'ember-intl/test-support';

const STATUS = {
    status: 'disconnected',
    step: null,
    run_kind: null,
    host: null,
    error_code: null,
    connected_at: null,
    last_test_at: null,
    last_test_ok: null,
    last_test_ms: null,
    last_attempt_at: null,
    has_previous: false,
};
const SENTINEL = 'ZZSENTINEL-tab';

class StubFetch extends Service {
    host = 'http://api.test';
    credentials = 'include';
    getHeaders() {
        return { 'Content-Type': 'application/json' };
    }
}

class StubNotifications extends Service {
    messages = [];
    success(message) {
        this.messages.push(message);
    }
}

module('Integration | Component | erpnext-tab', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    let calls;
    let responses;
    const realFetch = window.fetch;

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetch);
        this.owner.register('service:notifications', StubNotifications);
        this.set('org', { public_id: 'company_abc', name: 'Client Foods' });
        calls = [];
        responses = [];
        window.fetch = async (url, options) => {
            calls.push({ url, method: options.method, body: options.body });
            const next = responses.length > 1 ? responses.shift() : responses[0];
            if (next.wait) {
                await next.wait; // a held answer: the test decides when the server "replies"
            }

            return new Response(JSON.stringify(next.json), { status: next.status ?? 200, headers: { 'Content-Type': 'application/json' } });
        };
    });

    hooks.afterEach(function () {
        window.fetch = realFetch;
    });

    const respond = (...list) => (responses = list.map((entry) => (entry.json ? entry : { json: entry })));
    const render_ = () => render(hbs`<ErpnextTab @organization={{this.org}} @pollInterval={{5}} />`);

    test('not set up: the form, the note and the badge (AC-2)', async function (assert) {
        respond({ ...STATUS });
        await render_();

        assert.dom('h2').hasText('Connect to ERPNext');
        assert.dom('[data-test-before-you-start]').includesText('AlvoraVector connector');
        assert.dom('[data-test-badge]').includesText('Not set up');
        assert.dom('#erpnext-apiSecret').hasAttribute('type', 'password');
        assert.dom('#erpnext-apiSecret').hasAttribute('autocomplete', 'off');
        assert.dom('[data-test-cancel]').doesNotExist();
        assert.strictEqual(calls.length, 1, 'one status request and no polling (AC-7)');
        assert.strictEqual(calls[0].url, 'http://api.test/int/v1/alvora/organizations/company_abc/erpnext');
    });

    test('empty or non-https entries show the four messages and send nothing (AC-11)', async function (assert) {
        respond({ ...STATUS });
        await render_();

        await click('[data-test-connect]');
        assert.dom('[data-test-error="address"]').hasText('Enter the ERPNext site address.');
        assert.dom('[data-test-error="apiKey"]').hasText('Enter the API key.');
        assert.dom('[data-test-error="apiSecret"]').hasText('Enter the API secret.');
        assert.dom('#erpnext-address').isFocused();

        await fillIn('#erpnext-address', 'http://erp.example.com');
        await click('[data-test-connect]');
        assert.dom('[data-test-error="address"]').hasText('The address must start with https://');
        assert.strictEqual(calls.length, 1, 'no connect request');
    });

    test('Connect posts the three entries, empties the secret, polls every interval and stops when done (AC-6, AC-69)', async function (assert) {
        let release;
        const gate = new Promise((resolve) => (release = resolve));
        respond(
            { ...STATUS },
            { status: 'connecting', step: 1, run_kind: 'connect', host: 'erp.example.com' },
            { wait: gate, json: { status: 'connecting', step: 3, run_kind: 'connect', host: 'erp.example.com' } },
            {
                status: 'connected',
                step: null,
                run_kind: null,
                host: 'erp.example.com',
                connected_at: '2026-11-19T09:00:00Z',
                last_test_at: '2026-11-19T09:05:00Z',
                last_test_ok: true,
                last_test_ms: 1100,
                has_previous: true,
            }
        );
        await render_();
        await fillIn('#erpnext-address', 'https://erp.example.com');
        await fillIn('#erpnext-apiKey', 'key-1');
        await fillIn('#erpnext-apiSecret', SENTINEL);
        await click('[data-test-connect]');

        const post = calls.find((c) => c.method === 'POST');
        assert.deepEqual(JSON.parse(post.body), { address: 'https://erp.example.com', api_key: 'key-1', api_secret: SENTINEL });
        assert.dom('h2').hasText('Connecting to erp.example.com');
        assert.dom('[data-test-steps]').hasAttribute('aria-live', 'polite');
        assert.dom('[data-test-step="1"]').includesText('Check the address');
        assert.dom('[data-test-step="1"]').hasAttribute('data-test-step-state', 'working');
        assert.dom('#erpnext-apiSecret').doesNotExist('the form is gone while it runs');

        release();

        await waitUntil(() => this.element.querySelector('h2')?.textContent.includes('ERPNext is connected'));
        await settled();
        const requests = calls.length;
        assert.dom('[data-test-last-test]').includesText('ERPNext confirmed it in 1.1 s');
        assert.dom('[data-test-badge]').includesText('Connected');
        assert.dom('[data-test-disconnect]').exists();
        assert.strictEqual(this.owner.lookup('service:notifications').messages.at(-1), 'Connected to erp.example.com');

        await new Promise((resolve) => setTimeout(resolve, 40));
        assert.strictEqual(calls.length, requests, 'polling stopped once the run ended');
        assert.notOk(document.body.innerHTML.includes(SENTINEL), 'the secret is nowhere on the page');
        assert.notOk(JSON.stringify(window.localStorage).includes(SENTINEL), 'nor in storage');
    });

    test('a wrong key shows the exact alert, the failed step and the retry button with the secret empty (AC-4, AC-41, AC-42)', async function (assert) {
        respond({ status: 'attention', step: 2, run_kind: null, host: 'erp.example.com', error_code: 'key_refused', last_attempt_at: '2026-11-19T09:00:00Z', has_previous: false });
        await render_();

        assert.dom('[data-test-alert="key_refused"]').includesText('Step 2 failed: ERPNext did not accept the key or secret');
        assert.dom('[data-test-alert="key_refused"]').includesText('erp.example.com answered, but the key and secret were refused. Nothing was created or changed.');
        assert.dom('[data-test-alert="key_refused"]').hasAttribute('role', 'alert');
        assert.dom('[data-test-step="2"]').hasAttribute('data-test-step-state', 'failed');
        assert.dom('[data-test-step="3"]').hasAttribute('data-test-step-state', 'waiting');
        assert.dom('[data-test-alert="key_refused"]').doesNotIncludeText('The previous connection');

        await click('[data-test-change-details]');
        assert.dom('#erpnext-address').hasValue('https://erp.example.com');
        assert.dom('#erpnext-apiSecret').hasValue('');
    });

    test('a failed replace says the previous connection was left as it was (AC-47)', async function (assert) {
        respond({ status: 'attention', step: 4, host: 'erp.example.com', error_code: 'handover_failed', has_previous: true });
        await render_();
        assert.dom('[data-test-alert="handover_failed"]').includesText('The previous connection was left as it was.');
    });

    test('step 5 failed offers Send test event again and Connect again (AC-42)', async function (assert) {
        respond({ status: 'attention', step: 5, host: 'erp.example.com', error_code: 'test_failed', has_previous: true });
        await render_();
        assert.dom('[data-test-send-test]').hasText('Send test event again');
        assert.dom('[data-test-connect-again]').exists();
        assert.dom('[data-test-alert="test_failed"]').doesNotIncludeText('The previous connection');
    });

    test('Connect again opens the replace form with Cancel, and Cancel sends nothing (AC-52)', async function (assert) {
        respond({ status: 'connected', host: 'erp.example.com', connected_at: '2026-11-19T09:00:00Z', has_previous: true });
        await render_();
        await click('[data-test-connect-again]');

        assert.dom('h2').hasText('Connect again');
        assert.dom('[data-test-connect]').hasText('Replace connection');
        assert.dom('#erpnext-apiSecret').hasValue('');
        const before = calls.length;
        await click('[data-test-cancel]');
        assert.dom('h2').hasText('ERPNext is connected');
        assert.strictEqual(calls.length, before);
    });

    test('a busy connection (409) and a rate limit (429) show their alert and keep the badge', async function (assert) {
        respond({ ...STATUS });
        await render_();
        await fillIn('#erpnext-address', 'https://erp.example.com');
        await fillIn('#erpnext-apiKey', 'k');
        await fillIn('#erpnext-apiSecret', 's');

        responses = [{ status: 409, json: { error: 'A connection is already being set up.' } }];
        await click('[data-test-connect]');
        assert.dom('[data-test-problem="busy"]').includesText('A connection is already being set up.');
        assert.dom('[data-test-badge]').includesText('Not set up');
        assert.dom('#erpnext-apiSecret').hasValue('', 'secret emptied even when refused');

        await fillIn('#erpnext-apiSecret', 's');
        responses = [{ status: 429, json: { message: 'Too Many Attempts.' } }];
        await click('[data-test-connect]');
        assert.dom('[data-test-problem="rate_limited"]').includesText('Too many attempts');
    });

    test('Send test event disables the button while it runs (AC-48)', async function (assert) {
        respond({
            status: 'connected',
            host: 'erp.example.com',
            connected_at: '2026-11-19T09:00:00Z',
            last_test_at: '2026-11-19T09:00:00Z',
            last_test_ok: true,
            last_test_ms: 900,
            has_previous: true,
        });
        await render_();
        let release;
        const gate = new Promise((resolve) => (release = resolve));
        responses = [
            { status: 202, json: { status: 'connecting', step: 5, run_kind: 'test', host: 'erp.example.com', has_previous: true } },
            {
                wait: gate,
                json: {
                    status: 'connected',
                    host: 'erp.example.com',
                    connected_at: '2026-11-19T09:00:00Z',
                    last_test_at: '2026-11-19T09:10:00Z',
                    last_test_ok: true,
                    last_test_ms: 700,
                    has_previous: true,
                },
            },
        ];
        await click('[data-test-send-test]');
        assert.dom('[data-test-send-test]').hasText('Sending test event...').isDisabled();
        release();
        await waitUntil(() => !document.querySelector('[data-test-send-test]')?.disabled);
        assert.dom('[data-test-last-test]').includesText('0.7 s');
        assert.strictEqual(this.owner.lookup('service:notifications').messages.at(-1), 'Test event confirmed by ERPNext');
    });

    test('Disconnect asks first: Keep connected is focused, then DELETE and a toast (AC-61, AC-62)', async function (assert) {
        respond({ status: 'connected', host: 'erp.example.com', connected_at: '2026-11-19T09:00:00Z', has_previous: true });
        await render_();
        await click('[data-test-disconnect]');
        assert.dom('#erpnext-disconnect-title').hasText('Disconnect ERPNext from Client Foods?');
        assert.dom('[data-test-keep]').isFocused();
        await click('[data-test-keep]');
        assert.dom('#erpnext-disconnect-dialog').doesNotExist();
        assert.notOk(calls.some((c) => c.method === 'DELETE'));

        await click('[data-test-disconnect]');
        responses = [{ json: { ...STATUS, host: 'erp.example.com' } }];
        await click('[data-test-confirm-disconnect]');
        assert.ok(calls.some((c) => c.method === 'DELETE'));
        assert.dom('h2').hasText('Connect to ERPNext');
        assert.strictEqual(this.owner.lookup('service:notifications').messages.at(-1), 'Disconnected from ERPNext');
    });

    test('a status call that fails shows a retry, never a crash', async function (assert) {
        responses = [{ status: 500, json: { error: 'x' } }];
        await render_();
        assert.dom('[data-test-load-failed]').includesText('ERPNext status could not be loaded.');
    });
});
