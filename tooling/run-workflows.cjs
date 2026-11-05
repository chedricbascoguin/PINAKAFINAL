const { spawn, spawnSync } = require('node:child_process');
const path = require('node:path');
const net = require('node:net');
const fs = require('node:fs');
const { randomBytes } = require('node:crypto');

(async () => {
  const root = path.resolve(__dirname, '..');
  const env = { ...process.env, RHU_TEST_DB: `rhu_pw_${Date.now()}`, RHU_TEST_TOKEN: randomBytes(24).toString('hex') };
  const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
  const fixture = path.join(root, 'tests/workflow-support/database.php');
  const setup = spawnSync(php, [fixture, 'setup'], { cwd: root, env, encoding: 'utf8' });
  if (setup.status !== 0) throw new Error(setup.stderr || setup.stdout);
  console.log(setup.stdout.trim());
  const port = await new Promise(resolve => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => { const p = socket.address().port; socket.close(() => resolve(p)); });
  });
  env.RHU_TEST_URL = `http://127.0.0.1:${port}/`;
  const sessions = path.join(root, 'scratch', env.RHU_TEST_DB, 'sessions');
  fs.mkdirSync(sessions, { recursive: true });
  const server = spawn(php, ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${port}`, 'tests/workflow-support/router.php'], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  let logs = '';
  server.stderr.on('data', data => { logs = (logs + data).slice(-12000); });
  let code = 1;
  try {
    for (let attempt = 0; attempt < 50; attempt++) {
      try { const response = await fetch(env.RHU_TEST_URL + '__test/health', { headers: { 'X-Test-Token': env.RHU_TEST_TOKEN } }); if (response.ok) break; } catch {}
      await new Promise(resolve => setTimeout(resolve, 100));
      if (attempt === 49) throw new Error('Test server did not start: ' + logs);
    }
    const runner = spawn(process.execPath, ['node_modules/@playwright/test/cli.js', 'test', '--config=playwright.workflows.config.js', ...process.argv.slice(2)], { cwd: __dirname, env, stdio: 'inherit', windowsHide: true });
    code = await new Promise(resolve => runner.on('exit', resolve));
    if (code) console.error(logs);
  } finally {
    server.kill();
    const cleanup = spawnSync(php, [fixture, 'cleanup'], { cwd: root, env, encoding: 'utf8' });
    console.log(cleanup.stdout.trim());
    if (cleanup.status !== 0) { console.error(cleanup.stderr); code = 1; }
  }
  process.exitCode = code || 0;
})().catch(error => { console.error(error.message); process.exitCode = 1; });
