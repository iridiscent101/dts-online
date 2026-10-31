import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/dts.js', import.meta.url), 'utf8').replaceAll('\r\n', '\n');
const marker = "document.addEventListener('click', async (event) => {\n    const button = event.target.closest('[data-records-bulk-receive]');";
const start = source.indexOf(marker);
assert.ok(start >= 0, 'Bulk Receive handler must exist');
const code = source.slice(start, source.indexOf('\n});', start) + 4);

async function receive(fetchResponse, confirmed = true) {
    const result = { calls: 0, visits: 0, alert: '', title: '', toast: '' };
    const rows = ['A', 'B'].map(id => ({ checked: true, dataset: { receiveOffice: 'SDO-Records Unit', receiveUrl: id, trackingNumber: id } }));
    const queue = { inert: false, querySelectorAll: () => rows };
    const button = { disabled: false, closest: () => queue };
    let handler;
    runInNewContext(code, {
        FormData,
        document: { addEventListener: (_, fn) => { handler = fn; }, querySelector: () => ({ content: 'csrf' }) },
        dtsDialog: {
            confirm: async () => confirmed,
            alert: async (text, options) => { result.alert = text; result.title = options.title; },
        },
        pageLoading: { show() {}, hide() {} },
        dtsToastAfterReload: text => { result.toast = text; },
        window: { location: { href: 'http://localhost/admin' }, Turbo: { visit() { result.visits++; } } },
        fetch: async (url, options) => {
            result.calls++;
            assert.equal(options.body.get('workflow[action]'), 'received');
            assert.equal(options.body.get('workflow[source_office]'), 'SDO-Records Unit');
            if (url === 'A') return { ok: true, json: async () => ({ message: 'Received' }) };
            return fetchResponse();
        },
    });
    const event = { target: { closest: () => button } };
    const first = handler(event);
    await handler(event); // A second click during confirmation must not start another batch.
    await first;
    assert.equal(button.disabled, false);
    assert.equal(queue.inert, false);
    return { ...result, rows };
}

test('bulk receipt handles a stale row without retrying successful receipts', async () => {
    const result = await receive(async () => ({ ok: false, json: async () => ({ message: 'Already received' }) }));
    assert.equal(result.calls, 2);
    assert.equal(result.visits, 1);
    assert.equal(result.rows[0].checked, false);
    assert.equal(result.rows[1].checked, true);
    assert.equal(result.alert, 'B: Already received');
    assert.equal(result.toast, '1 document(s) received.');
});

test('cancelled confirmation sends no requests', async () => {
    const result = await receive(() => assert.fail('No request expected'), false);
    assert.equal(result.calls, 0);
    assert.equal(result.visits, 0);
});

test('lost or invalid responses report an unconfirmed receipt, not a failed save', async () => {
    for (const fetchResponse of [
        async () => { throw new Error('NetworkError'); },
        async () => ({ ok: true, json: async () => { throw new SyntaxError('Invalid JSON'); } }),
    ]) {
        const result = await receive(fetchResponse);
        assert.equal(result.calls, 2);
        assert.equal(result.title, 'Receipt results need checking');
        assert.match(result.alert, /Receipt could not be confirmed/);
        assert.match(result.alert, /Refresh.*before trying again/);
    }
});
