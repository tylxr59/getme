const test = require('node:test');
const assert = require('node:assert/strict');
const Client = require('../web/api-client');
const ListState = require('../web/list-state');

test('ambiguous network failure retries identical envelope', async () => {
    const bodies = [];
    global.fetch = async (url, options) => {
        bodies.push(options.body);
        if (bodies.length === 1) throw new Error('Disconnected after save');
        return { ok: true, json: async () => ({ version: 1, success: true, revision: 2,
            results: [{ id: 'stable', item_id: 7 }], items: [{ id: 7, name: 'Milk' }] }) };
    };
    const client = new Client('', { revision: 1, items: [] });
    const result = await client.action('add', { name: 'Milk', request_id: 'stable' });
    assert.equal(bodies[0], bodies[1]);
    assert.equal(result.item.id, 7);
});

test('conflict refreshes baseline without replaying rejected change', async () => {
    const bodies = [];
    global.fetch = async (url, options) => {
        const body = JSON.parse(options.body); bodies.push(body);
        if (body.action === 'apply') return { ok: false, status: 409, json: async () => ({ success: false, code: 'conflict', error: 'Changed' }) };
        return { ok: true, json: async () => ({ success: true, version: 1, revision: 4, items: [] }) };
    };
    const client = new Client('', { revision: 1, items: [] });
    await assert.rejects(client.action('delete', { id: 7 }), /Changed/);
    assert.deepEqual(bodies.map(body => body.action), ['apply', 'read']);
    assert.equal(client.snapshot.revision, 4);
});

test('clear captures specific checked items from confirmed snapshot', async () => {
    let sent;
    global.fetch = async (url, options) => {
        sent = JSON.parse(options.body);
        return { ok: true, json: async () => ({ success: true, version: 1, revision: 2, results: [], items: [] }) };
    };
    const client = new Client('', { revision: 1, items: [{ id: 1, checked: 1 }, { id: 2, checked: 0 }] });
    await client.action('clear_checked');
    assert.deepEqual(sent.operations[0].item_ids, [1]);
});

test('writes remain ordered after a failure and refresh guard stays active', async () => {
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    const seen = [];
    const state = new ListState(() => seen.push('settled'));
    const one = state.enqueue(async () => { await gate; seen.push('first'); throw new Error('No'); });
    const two = state.enqueue(async () => { seen.push('second'); });
    assert.equal(state.pendingWrites, 2);
    release();
    await assert.rejects(one);
    await two;
    assert.equal(state.pendingWrites, 0);
    assert.ok(seen.indexOf('second') > seen.indexOf('first'));
});
