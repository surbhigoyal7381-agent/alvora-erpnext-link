export const STEP_NUMBERS = [1, 2, 3, 4, 5];

/**
 * The state word of each of the five steps, from the status the server returned:
 * done before the current step, working or failed at it, waiting after it.
 */
export function stepStates(status) {
    return STEP_NUMBERS.map((number) => {
        let state = 'waiting';
        if (status.status === 'connected') {
            state = 'done';
        } else if (status.step && number < status.step) {
            state = 'done';
        } else if (status.step === number) {
            state = status.status === 'attention' ? 'failed' : 'working';
        }

        return { number, state, working: state === 'working' };
    });
}

/**
 * How many "what to do" bullets each failure has (the words live in the translations).
 */
export const TODO_COUNT = {
    address_blocked: 2,
    key_refused: 3,
    site_timeout: 3,
    test_failed: 2,
    host_in_use: 3,
    site_error: 2,
    connector_missing: 3,
    owner_not_client: 2,
    key_create_failed: 2,
    handover_failed: 2,
    secret_expired: 2,
    interrupted: 3,
    key_invalid: 2,
    rate_limited: 2,
    busy: 2,
    failed: 1,
};

/**
 * Translation key of one part (title, body, todo-1...) of a failure. Built here, not inline, because the
 * failure code is data.
 */
export function errorKey(code, part) {
    return ['alvora-erpnext-link', 'error', code, part].join('.');
}
