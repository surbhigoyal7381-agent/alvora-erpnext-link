'use strict';
// Fails when the repository holds anything that must never be public: a client name, a key or secret
// shaped string, or a real ERPNext host. Run by `pnpm lint:privacy` and by CI.
// Client names are never written in this repository: they come from PRIVACY_FORBIDDEN_NAMES, a comma-separated
// list set from a CI secret (and in your own shell). In CI an empty list fails the run, so a missing secret cannot pass silently.
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const skipDirs = new Set(['.git', 'node_modules', 'server_vendor', 'dist', 'tmp', '.phpunit.cache']);
const names = (process.env.PRIVACY_FORBIDDEN_NAMES || '')
    .split(',')
    .map((name) => name.trim())
    .filter(Boolean);
if (process.env.CI && !names.length) {
    throw new Error('PRIVACY_FORBIDDEN_NAMES is not set: the client-name check cannot run.');
}
const forbidden = [
    ...names.map((name) => ({ name: 'client name', pattern: new RegExp(`\\b${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i') })),
    { name: 'Fleetbase live or test key', pattern: /flb_(live|test)_[A-Za-z0-9]{8,}/ },
    { name: 'webhook signing secret', pattern: /whsec_[0-9a-f]{16,}/ },
    { name: 'hosted ERPNext address', pattern: /[a-z0-9-]+\.(erpnext\.com|frappe\.cloud|frappecloud\.com)\b/i },
];

const hits = [];
(function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (!skipDirs.has(entry.name)) {
                walk(full);
            }
        } else if (full !== __filename && !/\.(png|jpg|ico|lock)$/.test(entry.name) && !/pnpm-lock/.test(entry.name)) {
            const text = fs.readFileSync(full, 'utf8');
            for (const { name, pattern } of forbidden) {
                if (pattern.test(text)) {
                    hits.push(`${path.relative(root, full)}: ${name}`);
                }
            }
        }
    }
})(root);

if (hits.length) {
    console.error('Forbidden content found:\n' + hits.join('\n'));
    process.exitCode = 1;
} else {
    console.log('No client names, keys, secrets or hosted ERPNext addresses found.');
}
