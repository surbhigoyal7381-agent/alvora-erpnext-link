import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { stepStates, TODO_COUNT, errorKey } from '@alvora/erpnext-link-engine/utils/steps';
import formatDate from '@alvora/erpnext-link-engine/utils/format-date';

module('Unit | Utility | steps and dates', function () {
    test('connecting at step 3: done, done, working, waiting, waiting', function (assert) {
        const states = stepStates({ status: 'connecting', step: 3 }).map((s) => s.state);
        assert.deepEqual(states, ['done', 'done', 'working', 'waiting', 'waiting']);
    });

    test('attention at step 2 marks only step 2 as failed', function (assert) {
        const states = stepStates({ status: 'attention', step: 2 }).map((s) => s.state);
        assert.deepEqual(states, ['done', 'failed', 'waiting', 'waiting', 'waiting']);
    });

    test('connected means all five are done', function (assert) {
        assert.true(stepStates({ status: 'connected', step: null }).every((s) => s.state === 'done'));
    });

    test('every failure code has a bullet count', function (assert) {
        [
            'address_blocked',
            'key_refused',
            'site_timeout',
            'test_failed',
            'host_in_use',
            'site_error',
            'connector_missing',
            'owner_not_client',
            'key_create_failed',
            'handover_failed',
            'secret_expired',
            'interrupted',
            'key_invalid',
            'rate_limited',
            'busy',
            'failed',
        ].forEach((code) => {
            assert.ok(TODO_COUNT[code] > 0, code);
        });
    });

    test('dates read "19 Nov 2026, 09:05" and nothing reads as null', function (assert) {
        assert.strictEqual(formatDate(null), null);
        assert.ok(/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{2}:\d{2}$/.test(formatDate('2026-11-19T09:05:00Z')), formatDate('2026-11-19T09:05:00Z'));
    });

    module('translations', function (hooks) {
        setupTest(hooks);
        setupIntl(hooks, 'en-us');

        test('every failure has a title, a sentence and as many bullets as it says', function (assert) {
            const intl = this.owner.lookup('service:intl');
            Object.entries(TODO_COUNT).forEach(([code, count]) => {
                assert.true(intl.exists(errorKey(code, 'title')), `${code} title`);
                assert.true(intl.exists(errorKey(code, 'body')), `${code} body`);
                for (let i = 1; i <= count; i++) {
                    assert.true(intl.exists(errorKey(code, `todo-${i}`)), `${code} todo-${i}`);
                }
            });
        });

        test('all five steps have a title and a reason', function (assert) {
            const intl = this.owner.lookup('service:intl');
            [1, 2, 3, 4, 5].forEach((n) => {
                assert.true(intl.exists(`alvora-erpnext-link.step.${n}.title`), `step ${n}`);
                assert.true(intl.exists(`alvora-erpnext-link.step.${n}.why`), `step ${n} why`);
            });
        });
    });
});
