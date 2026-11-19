import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { schedule } from '@ember/runloop';
import { task, timeout } from 'ember-concurrency';
import call from '../utils/api';
import formatDate from '../utils/format-date';
import { stepStates, TODO_COUNT, errorKey } from '../utils/steps';

/**
 * The ERPNext tab of an organization: Not set up (form), Connecting (steps), Connected, Needs attention.
 * The ERPNext key and secret live only in this component's memory until Connect is pressed; the secret is
 * emptied after every press and never written to storage.
 */
export default class ErpnextTabComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked status = null;
    @tracked loadFailed = false;
    @tracked formOpen = false;
    @tracked replacing = false;
    @tracked address = '';
    @tracked apiKey = '';
    @tracked apiSecret = '';
    @tracked errors = {};
    @tracked problem = null; // 'busy' | 'rate_limited' | 'failed' shown above the form
    @tracked confirmingDisconnect = false;
    @tracked paused = false;
    runKind = null;

    constructor() {
        super(...arguments);
        this.onVisible = () => {
            if (!document.hidden && this.paused && this.isConnecting) {
                this.paused = false;
                this.poll.perform();
            }
        };
        document.addEventListener('visibilitychange', this.onVisible);
        this.refresh.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    get path() {
        return `organizations/${this.args.organization.public_id}/erpnext`;
    }

    get headingId() {
        return `erpnext-heading-${this.args.organization.public_id}`;
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

    get headingKey() {
        if (this.showForm) {
            return this.replacing ? 'alvora-erpnext-link.form.heading-replace' : 'alvora-erpnext-link.form.heading';
        }
        if (this.isConnecting) {
            return this.isTesting ? 'alvora-erpnext-link.connected.heading' : 'alvora-erpnext-link.connecting.heading';
        }

        return this.isConnected ? 'alvora-erpnext-link.connected.heading' : 'alvora-erpnext-link.attention.heading';
    }

    get showSub() {
        return this.showForm && !this.replacing;
    }

    get badgeText() {
        return this.intl.t('alvora-erpnext-link.badge.tab', { status: this.intl.t(`alvora-erpnext-link.badge.${this.state}`) });
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

    get isTesting() {
        return this.isConnecting && this.status?.run_kind === 'test';
    }

    get showForm() {
        return this.state === 'disconnected' || this.formOpen;
    }

    get showSteps() {
        return this.isConnecting && !this.isTesting;
    }

    get steps() {
        return this.status ? stepStates(this.status) : [];
    }

    get host() {
        return this.status?.host ?? '';
    }

    get connectedAt() {
        return formatDate(this.status?.connected_at);
    }

    get lastAttempt() {
        return formatDate(this.status?.last_attempt_at);
    }

    get lastTest() {
        const status = this.status;
        if (!status?.last_test_at) {
            return this.intl.t('alvora-erpnext-link.connected.last-test-none');
        }
        const when = formatDate(status.last_test_at);

        return status.last_test_ok
            ? this.intl.t('alvora-erpnext-link.connected.last-test-ok', { when, seconds: ((status.last_test_ms ?? 0) / 1000).toFixed(1) })
            : this.intl.t('alvora-erpnext-link.connected.last-test-failed', { when });
    }

    get alertCode() {
        return this.state === 'attention' ? (this.status.error_code ?? 'site_error') : null;
    }

    get alert() {
        return this.alertFor(this.alertCode);
    }

    get problemAlert() {
        return this.problem ? this.alertFor(this.problem) : null;
    }

    get step5Failed() {
        return this.state === 'attention' && this.status.step === 5;
    }

    get keyInvalid() {
        return this.alertCode === 'key_invalid';
    }

    get previousKept() {
        return (
            this.state === 'attention' &&
            this.status.has_previous &&
            this.status.step < 5 &&
            this.alertCode !== 'key_invalid' &&
            !(this.alertCode === 'interrupted' && this.status.step === 4)
        );
    }

    alertFor(code) {
        const known = TODO_COUNT[code] ? code : 'site_error';
        const host = { host: this.host };
        const todo = Array.from({ length: TODO_COUNT[known] }, (_, i) => this.intl.t(errorKey(known, `todo-${i + 1}`), host));

        return { code: known, title: this.intl.t(errorKey(known, 'title'), host), body: this.intl.t(errorKey(known, 'body'), host), todo };
    }

    @task({ restartable: true }) *refresh() {
        const response = yield call(this.fetch, 'GET', this.path);
        if (!response.ok) {
            this.loadFailed = true;

            return;
        }
        this.loadFailed = false;
        const wasConnecting = this.isConnecting;
        this.setStatus(response.json);
        if (this.isConnecting) {
            this.poll.perform();
        } else if (wasConnecting) {
            this.finished();
        }
    }

    // While a run is going: ask every 2 seconds, stop when it ends, when the browser tab is hidden or when this tab is left.
    // After a failed call the wait doubles (2, 4, 8 ... capped at 30 seconds) and goes back to 2 once a call succeeds.
    @task({ restartable: true }) *poll() {
        const interval = this.args.pollInterval ?? 2000;
        let wait = interval;
        while (this.isConnecting) {
            yield timeout(wait);
            if (document.hidden) {
                this.paused = true;

                return;
            }
            const response = yield call(this.fetch, 'GET', this.path);
            if (!response.ok) {
                wait = Math.min(wait * 2, Math.max(interval, 30000));
                continue;
            }
            wait = interval;
            this.setStatus(response.json);
        }
        this.finished();
    }

    setStatus(status) {
        if (status.status === 'connecting') {
            this.runKind = status.run_kind;
        }
        this.status = status;
    }

    finished() {
        this.formOpen = false;
        this.replacing = false;
        this.apiSecret = '';
        if (this.state === 'connected') {
            this.notifications.success(this.intl.t(this.runKind === 'test' ? 'alvora-erpnext-link.toast.test-ok' : 'alvora-erpnext-link.toast.connected', { host: this.host }));
        }
        this.focusHeading();
    }

    focusHeading() {
        schedule('afterRender', this, () => document.getElementById(this.alert ? `${this.headingId}-alert` : this.headingId)?.focus());
    }

    @action update(field, event) {
        this[field] = event.target.value;
    }

    validate() {
        const errors = {};
        const address = this.address.trim();
        if (!address) {
            errors.address = this.intl.t('alvora-erpnext-link.form.error-address-required');
        } else if (!address.toLowerCase().startsWith('https://')) {
            errors.address = this.intl.t('alvora-erpnext-link.form.error-address-https');
        }
        if (!this.apiKey.trim()) {
            errors.apiKey = this.intl.t('alvora-erpnext-link.form.error-key-required');
        }
        if (!this.apiSecret) {
            errors.apiSecret = this.intl.t('alvora-erpnext-link.form.error-secret-required');
        }
        this.errors = errors;

        const first = ['address', 'apiKey', 'apiSecret'].find((field) => errors[field]);
        if (first) {
            schedule('afterRender', this, () => document.getElementById(`erpnext-${first}`)?.focus());
        }

        return !first;
    }

    @action async connect(event) {
        event?.preventDefault();
        this.problem = null;
        if (!this.validate()) {
            return;
        }

        const body = { address: this.address.trim(), api_key: this.apiKey.trim(), api_secret: this.apiSecret };
        this.apiSecret = ''; // never kept: emptied on every press, whatever the answer
        const response = await call(this.fetch, 'POST', `${this.path}/connect`, body);
        if (response.ok) {
            this.setStatus(response.json);
            this.formOpen = false;
            this.poll.perform();
            this.focusHeading();

            return;
        }
        this.problem = response.status === 409 ? 'busy' : response.status === 429 ? 'rate_limited' : 'failed';
    }

    @action async sendTest() {
        this.problem = null;
        const response = await call(this.fetch, 'POST', `${this.path}/test`);
        if (response.ok) {
            this.setStatus(response.json);
            this.poll.perform();

            return;
        }
        this.problem = response.status === 409 ? 'busy' : response.status === 429 ? 'rate_limited' : 'failed';
    }

    @action openForm(replacing) {
        this.problem = null;
        this.errors = {};
        this.apiSecret = '';
        this.address = this.host ? `https://${this.host}` : this.address;
        this.replacing = replacing;
        this.formOpen = true;
        this.focusHeading();
    }

    @action cancelForm() {
        this.formOpen = false;
        this.replacing = false;
        this.apiSecret = '';
        this.errors = {};
        this.focusHeading();
    }

    @action askDisconnect() {
        this.confirmingDisconnect = true;
        schedule('afterRender', this, () => document.getElementById('erpnext-disconnect-dialog')?.showModal());
    }

    @action closeDisconnect() {
        this.confirmingDisconnect = false;
    }

    @action async disconnect() {
        this.confirmingDisconnect = false;
        this.problem = null;
        const response = await call(this.fetch, 'DELETE', this.path);
        if (response.ok) {
            this.status = response.json;
            this.address = '';
            this.apiKey = '';
            this.notifications.success(this.intl.t('alvora-erpnext-link.toast.disconnected'));
            this.focusHeading();

            return;
        }
        this.problem = response.status === 409 ? 'busy' : 'failed';
    }

    @action retry() {
        this.refresh.perform();
    }
}
