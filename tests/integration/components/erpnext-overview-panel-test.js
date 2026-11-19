import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { setupIntl } from 'ember-intl/test-support';

class StubFetch extends Service {
    host = 'http://api.test';
    credentials = 'include';
    getHeaders() {
        return {};
    }
}

class StubRouter extends Service {
    transitions = [];
    urlFor() {
        return '/console/admin/organizations/company_abc/extensions/erpnext';
    }
    transitionTo(...args) {
        this.transitions.push(args);
    }
}

module('Integration | Component | erpnext-overview-panel', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    let calls;
    let reply;
    const realFetch = window.fetch;

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetch);
        this.owner.register('service:hostRouter', StubRouter);
        this.set('org', { public_id: 'company_abc' });
        calls = 0;
        window.fetch = async () => {
            calls += 1;

            return new Response(JSON.stringify(reply.json), { status: reply.status ?? 200 });
        };
    });

    hooks.afterEach(function () {
        window.fetch = realFetch;
    });

    test('not set up: one call, the sentence and the link', async function (assert) {
        reply = { json: { status: 'disconnected', host: null } };
        await render(hbs`<ErpnextOverviewPanel @organization={{this.org}} />`);

        assert.dom('h2').hasText('ERPNext');
        assert.dom('[data-test-panel-badge]').includesText('Not set up');
        assert.dom('[data-test-erpnext-panel]').includesText('ERPNext is not set up for this client yet.');
        assert.dom('[data-test-panel-link]').hasText('Open ERPNext tab to connect');
        assert.strictEqual(calls, 1);
        await click('[data-test-panel-link]');
        assert.deepEqual(this.owner.lookup('service:hostRouter').transitions[0], ['console.admin.organizations.details.extensions-tab', 'company_abc', 'erpnext']);
    });

    test('connecting shows the progress line', async function (assert) {
        reply = { json: { status: 'connecting', step: 2, host: 'erp.example.com' } };
        await render(hbs`<ErpnextOverviewPanel @organization={{this.org}} />`);
        assert.dom('[data-test-erpnext-panel]').includesText('Step 2 of 5: Reach the site and check the key');
    });

    test('connected shows the last test and never an internal id', async function (assert) {
        reply = { json: { status: 'connected', host: 'erp.example.com', last_test_at: '2026-11-19T09:05:00Z', last_test_ok: true } };
        await render(hbs`<ErpnextOverviewPanel @organization={{this.org}} />`);
        assert.dom('[data-test-erpnext-panel]').includesText('confirmed by ERPNext');
        assert.dom('[data-test-panel-badge]').includesText('Connected');
    });

    test('needs attention shows the failure title, not the bullets', async function (assert) {
        reply = { json: { status: 'attention', step: 2, error_code: 'key_refused', host: 'erp.example.com', last_attempt_at: '2026-11-19T09:00:00Z' } };
        await render(hbs`<ErpnextOverviewPanel @organization={{this.org}} />`);
        assert.dom('[data-test-erpnext-panel]').includesText('Step 2 failed: ERPNext did not accept the key or secret');
        assert.dom('[data-test-erpnext-panel]').doesNotIncludeText('What to do');
    });

    test('a failed call says so with a Retry (no polling)', async function (assert) {
        reply = { status: 500, json: { error: 'x' } };
        await render(hbs`<ErpnextOverviewPanel @organization={{this.org}} />`);
        assert.dom('[data-test-panel-failed]').hasText('ERPNext status could not be loaded.');
        assert.strictEqual(calls, 1);
    });
});
