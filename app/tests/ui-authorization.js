const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const fixtures = JSON.parse(execFileSync('php', [__dirname + '/ui-authorization.php', '--fixtures'], { encoding: 'utf8' }));

function page(name) {
    const calls = [], timers = new Map(), replies = [];
    let timerId = 0;
    const context = vm.createContext({
        console, URL, Headers, Request, crypto: webcrypto, tailwind: {},
        location: { href: 'http://localhost/banks/1', origin: 'http://localhost', reload() {} },
        document: { querySelector() { return { content: 'test-csrf-token' }; } },
        confirm: () => true, alert() {},
        setInterval(fn, delay) { timers.set(++timerId, { fn, delay }); return timerId; },
        clearInterval(id) { timers.delete(id); },
        setTimeout() {},
        fetch: async (url, options = {}) => {
            calls.push({ url, ...options });
            const reply = replies.shift();
            if (!reply) throw new Error('Unexpected request: ' + url);
            if (reply instanceof Error) throw reply;
            return { ok: reply.httpStatus ? reply.httpStatus < 400 : true, json: async () => reply };
        }
    });
    context.window = context;
    for (const [, source] of fixtures[name].matchAll(/<script\b[^>]*>([\s\S]*?)<\/script[^>]*>/gi)) {
        new vm.Script(source).runInContext(context);
    }
    return { context, calls, timers, replies };
}

async function run() {
    const dashboard = page('home');
    dashboard.replies.push(
        { success: false, authorization: { status: 'required' }, needs_tan: true, tan_request: { is_decoupled: true } },
        { success: false, authorization: { status: 'error' } },
        { success: false, authorization: { status: 'unknown' } }
    );
    const dashboardState = dashboard.context.dashboardSync();
    await dashboardState.syncAllBanks();
    assert.equal(dashboardState.results.length, 3);
    assert.equal(dashboard.timers.size, 0, 'Dashboard must not poll TAN');
    assert(dashboard.calls.every(call => call.url.endsWith('/sync-all')));
    assert.equal(dashboard.calls[0].headers.get('X-CSRF-Token'), 'test-csrf-token');
    assert.equal(dashboard.context.utcDate('2026-02-01 10:00:00').toISOString(), '2026-02-01T10:00:00.000Z');
    assert.equal(dashboard.context.utcDate('2026-02-01T11:00:00+01:00').toISOString(), '2026-02-01T10:00:00.000Z');
    assert.equal(dashboard.context.utcDate('invalid'), null);
    assert.match(dashboard.context.safeSyncWarning({ blocked: true, reason: 'background_sync_disabled' }), /ausgeschlossen/);

    for (const [name, factory, method] of [['account', 'accountDetails', 'syncTransactions'], ['depot', 'depotDetails', 'syncHoldings']]) {
        const test = page(name);
        test.replies.push({ needs_tan: true, tan_request: { is_decoupled: true } });
        const state = test.context[factory](10, 1);
        await state[method]();
        assert.equal(test.calls.length, 1);
        assert.equal(test.timers.size, 0);
        assert.equal(state.message.type, 'error');
        assert.match(state.message.text, /Bankfreigabe erforderlich/);
        assert.equal(state.submitTan, undefined);
    }

    const bank = page('bank');
    const state = bank.context.bankDetails(1);
    for (const method of ['syncAll', 'syncBalances', 'fetchAccounts']) {
        bank.replies.push({ needs_tan: true, tan_request: { is_decoupled: true } },
            { authorization: { status: 'required' } });
        await state[method]();
        assert.equal(state.showTanModal, false);
        assert.equal(bank.timers.size, 0);
    }
    assert(!bank.calls.some(call => call.url.endsWith('/authorize')));
    const pending = { success: false, needs_tan: true, operation_id: 'operation-1',
        operation_expires_at: '2099-01-01T00:00:00Z', authorization: { status: 'pending' },
        tan_request: { is_decoupled: true, poll_interval: 5, challenge: 'Confirm app' } };
    bank.replies.push(pending, pending);
    await state.authorize();
    assert.equal(state.showTanModal, true);
    assert.equal(state.operationId, 'operation-1');
    assert.equal(bank.timers.size, 1);
    assert.equal([...bank.timers.values()][0].delay, 5000);
    const authorize = bank.calls.find(call => call.url.endsWith('/authorize'));
    assert.match(JSON.parse(authorize.body).request_id, /^[a-f0-9]{48}$/);
    const count = bank.calls.length;
    await state.authorize();
    assert.equal(bank.calls.length, count, 'Duplicate authorize click should be ignored');
    bank.replies.push({ ...pending, tan_request: { is_decoupled: false, challenge: 'Enter TAN' } });
    await state.checkDecoupledStatus();
    assert.equal(bank.timers.size, 0, 'Changing TAN method must stop push polling');
    assert.equal(state.isDecoupled, false);
    assert.equal(JSON.parse(bank.calls.at(-1).body).operation_id, 'operation-1');
    state.tanInput = '123456';
    bank.replies.push({ success: true, authorization: { status: 'authorized' } },
        { success: true, authorization: { status: 'authorized' } });
    await state.submitTan();
    assert.equal(state.showTanModal, false);
    assert.equal(state.manualAuthorization, false);
    assert.equal(state.tanInput, '');
    const submission = bank.calls.find(call => call.url.endsWith('/tan'));
    assert.equal(JSON.parse(submission.body).operation_id, 'operation-1');

    // Reloading/restoring state never starts a new bank dialog; resume is explicit.
    const restored = bank.context.bankDetails(1);
    assert.equal(restored.manualAuthorization, false);
    bank.replies.push(pending);
    await restored.resumeAuthorization();
    assert.equal(restored.showTanModal, true);
    assert.equal(bank.timers.size, 1);
    bank.replies.push({ success: true, authorization: { status: 'required' } },
        { success: true, authorization: { status: 'required' } });
    await restored.closeModal();
    assert.equal(bank.timers.size, 0);
    assert.equal(restored.showTanModal, false);
    const cancellation = bank.calls.find(call => call.url.endsWith('/authorization/cancel'));
    assert.equal(JSON.parse(cancellation.body).operation_id, 'operation-1');

    const toggle = bank.context.backgroundSyncToggle(10, 0);
    bank.replies.push({ success: true });
    await toggle.toggle();
    assert.equal(toggle.enabled, true);
    assert.equal(bank.calls.at(-1).url, '/api/accounts/10/background-sync');
    assert.equal(JSON.parse(bank.calls.at(-1).body).enabled, true);
    bank.replies.push({ success: false });
    await toggle.toggle();
    assert.equal(toggle.enabled, true, 'Failed save must preserve displayed setting');
    assert.equal(toggle.saving, false);
    const expired = bank.context.bankDetails(1);
    expired.manualAuthorization = true;
    expired.operationExpiresAt = '2000-01-01T00:00:00Z';
    const beforeExpiredPoll = bank.calls.length;
    await expired.checkDecoupledStatus();
    assert.equal(bank.calls.length, beforeExpiredPoll, 'Expired continuation must not be sent');
    assert.match(expired.pollingStatus, /abgelaufen/);

    const network = bank.context.bankDetails(1);
    network.manualAuthorization = true;
    network.operationId = 'operation-1';
    network.tanRequest = { is_decoupled: true };
    network.startDecoupledPolling();
    bank.replies.push(new Error('offline'));
    await network.checkDecoupledStatus();
    assert.equal(bank.timers.size, 0, 'Network failure must stop automatic polling');
    assert.match(network.pollingStatus, /Netzwerkfehler/);
    bank.replies.push({ success: false, httpStatus: 409, reason: 'authorization_operation_mismatch' });
    network.showTanModal = true;
    await network.closeModal();
    assert.equal(network.showTanModal, true, 'Failed cancellation must not pretend success');
    assert.equal(network.message.type, 'error');
    console.log('PASS: rendered JavaScript syntax, safe sync isolation, explicit TAN/push/resume/cancel, UTC, CSRF and background toggle');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
