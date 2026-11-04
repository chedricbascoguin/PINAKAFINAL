const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function scenario(state) {
  const classes = new Set();
  const events = {};
  const redirects = [];
  const document = {
    hidden: false,
    createElement: () => ({}),
    head: { appendChild() {} },
    documentElement: { classList: {
      add: name => classes.add(name),
      remove: name => classes.delete(name),
    } },
    addEventListener: (name, handler) => { events[name] = handler; },
  };
  const fetch = async () => ({ ok: true, status: 200, json: async () => state });
  const context = { document, location: {
    pathname: '/MidwifeDashboard.php', search: '?tab=prenatal',
    replace: url => redirects.push(url),
  }, window: { fetch, addEventListener: (name, handler) => { events[name] = handler; } },
  fetch, setInterval() {}, setTimeout, clearTimeout, URLSearchParams, Date };
  vm.runInNewContext(fs.readFileSync('assets/js/staff-session-lock.js', 'utf8'), context);
  assert(!classes.has('staff-lock-checking'), 'Fresh server-validated navigation must stay visible');
  await new Promise(resolve => setImmediate(resolve));
  return { classes, events, redirects };
}

(async () => {
  const unlocked = await scenario({ authenticated: true, locked: false,
    csrf: 'token', timeout: 300, server_time: 1000, last_activity: 1000 });
  unlocked.events.pageshow({ persisted: false });
  assert(!unlocked.classes.has('staff-lock-checking'));
  unlocked.events.pageshow({ persisted: true });
  assert(unlocked.classes.has('staff-lock-checking'), 'History restore must hide stale data until checked');
  await new Promise(resolve => setImmediate(resolve));
  assert(!unlocked.classes.has('staff-lock-checking'));
  const locked = await scenario({ authenticated: true, locked: true });
  assert(locked.classes.has('staff-lock-checking'));
  assert.equal(locked.redirects.length, 1);
  assert(locked.redirects[0].startsWith('StaffLock.php?return='));
  console.log('Navigation checks passed: no fresh-page flash, history restore rechecked, locked sessions redirected.');
})().catch(error => { console.error(error); process.exitCode = 1; });
