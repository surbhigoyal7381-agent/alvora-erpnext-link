import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import call from '../utils/api';
import formatDate from '../utils/format-date';
import { TODO_COUNT, errorKey } from '../utils/steps';

/**
 * Read-only ERPNext status on the Overview tab: one status call when the page opens, no polling.
 */
export default class ErpnextOverviewPanelComponent extends Component {
    @service fetch;
    @service intl;
    @service hostRouter;

    @tracked status = null;
    @tracked failed = false;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get state() {
        return this.status?.status ?? 'disconnected';
    }

    get isConnecting() {
        return this.state === 'connecting';
    }

    get isConnected() {
        return this.state === 'connected';
    }

    get isAttention() {
        return this.state === 'attention';
    }

    get isDisconnected() {
        return this.state === 'disconnected';
    }

    get badgeClass() {
        return this.isConnected
            ? 'border-green-600 text-green-700 dark:text-green-400'
            : this.isAttention
              ? 'border-amber-600 text-amber-700 dark:text-amber-400'
              : 'border-gray-400 text-gray-700 dark:text-gray-300';
    }

    get badgeGlyph() {
        return this.isConnected ? '✓' : this.isAttention ? '⚠' : this.isConnecting ? '…' : '–';
    }

    get linkKey() {
        return this.isDisconnected ? 'alvora-erpnext-link.panel.link-connect' : 'alvora-erpnext-link.panel.link';
    }

    get host() {
        return this.status?.host ?? '';
    }

    get lastAttempt() {
        return formatDate(this.status?.last_attempt_at);
    }

    get lastTest() {
        const status = this.status;
        if (!status?.last_test_at) {
            return this.intl.t('alvora-erpnext-link.panel.last-test-none');
        }
        const when = formatDate(status.last_test_at);

        return this.intl.t(status.last_test_ok ? 'alvora-erpnext-link.panel.last-test-ok' : 'alvora-erpnext-link.panel.last-test-failed', { when });
    }

    get progress() {
        const step = this.status?.step ?? 1;

        return this.intl.t('alvora-erpnext-link.panel.progress', { step, title: this.intl.t(['alvora-erpnext-link', 'step', step, 'title'].join('.')) });
    }

    get happened() {
        const code = TODO_COUNT[this.status?.error_code] ? this.status.error_code : 'site_error';

        return this.intl.t(errorKey(code, 'title'), { host: this.host });
    }

    get tabRoute() {
        return ['console.admin.organizations.details.extensions-tab', this.args.organization.public_id, 'erpnext'];
    }

    get tabUrl() {
        return this.hostRouter.urlFor(...this.tabRoute);
    }

    @action openTab(event) {
        event.preventDefault();
        this.hostRouter.transitionTo(...this.tabRoute);
    }

    @task *load() {
        const response = yield call(this.fetch, 'GET', `organizations/${this.args.organization.public_id}/erpnext`);
        this.failed = !response.ok;
        if (response.ok) {
            this.status = response.json;
        }
    }

    @action retry() {
        this.load.perform();
    }
}
