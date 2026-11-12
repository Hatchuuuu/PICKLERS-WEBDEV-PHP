const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/owner.js', 'utf8');
const refresh = source.slice(source.indexOf('function refreshRequestsQueue('), source.indexOf('function acceptBooking('));

test('staff read-only request view does not poll the owner-only endpoint', async () => {
  for (const allowed of [false, true]) {
    let calls = 0;
    const context = { window: { PICKLERS_CAN_REVIEW_REQUESTS: allowed },
      document: { querySelectorAll: () => [] }, apiUrl: () => '/api?action=get_pending_requests',
      fetch: () => { calls++; return Promise.resolve({ json: () => ({ success: false }) }); } };
    vm.createContext(context);
    vm.runInContext('let requestsQueueRefreshInFlight = false;\n' + refresh, context);
    context.refreshRequestsQueue();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls, allowed ? 1 : 0);
  }
});
