/**
 * One call to this extension's server routes. Returns { ok, status, json } and never throws on an
 * HTTP error, so the screen can tell a busy connection (409) and a rate limit (429) from a failure.
 * The ERPNext secret travels only in the POST body of a connect call.
 */
export default async function call(fetchService, method, path, body) {
    const url = [fetchService.host, 'int/v1/alvora', path].join('/');
    const response = await fetch(url, {
        method,
        mode: 'cors',
        credentials: fetchService.credentials,
        headers: fetchService.getHeaders(),
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    let json = null;
    try {
        json = await response.json();
    } catch (error) {
        json = null;
    }

    return { ok: response.ok, status: response.status, json };
}
