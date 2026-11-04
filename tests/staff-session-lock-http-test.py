"""Local HTTP checks with temporary router/session files outside the web root."""
import http.cookiejar
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='rhu-lock-test-') as directory:
    router = Path(directory) / 'router.php'
    router.write_text('''<?php
session_start();
$_SERVER['SCRIPT_NAME'] = '/StaffLock.php';
require 'ROOT/db.php';
// A synthetic PDO keeps PIN creation and verification away from real accounts.
class PinTestStatement extends PDOStatement {
 private array $params = [];
 public function __construct(private string $sql) {}
 public function execute(?array $params = null): bool {
  $this->params = $params ?? [];
  if (str_contains($this->sql, 'INSERT INTO staff_screen_pins')) $_SESSION['test_pin_hash'] = $this->params['hash'];
  return true;
 }
 public function fetchColumn(int $column = 0): mixed {
  if (str_contains($this->sql, 'information_schema')) return 1;
  if (str_contains($this->sql, 'SELECT pin_hash')) return $_SESSION['test_pin_hash'] ?? false;
  if (str_contains($this->sql, 'SELECT id FROM health_workers')) return 2147483000;
  return false;
 }
}
class PinTestPDO extends PDO {
 public function __construct() {}
 public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new PinTestStatement($query); }
 public function prepare(string $query, array $options = []): PDOStatement|false { return new PinTestStatement($query); }
 public function exec(string $statement): int|false { return 0; }
}
$pdo = new PinTestPDO();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/seed') {
 $_SESSION = ['rhu_staff_login'=>['staff_id'=>2147483000,'name'=>'Lock Test']];
 if (!isset($_GET['nopin'])) $_SESSION['test_pin_hash'] = password_hash('0042', PASSWORD_DEFAULT);
 require_once 'ROOT/staff-session-lock.php';
 rhuStaffLockState(2147483000);
 $_SESSION['staff_screen_lock']['last_activity'] = time() - (int)($_GET['age'] ?? 0);
 echo 'seeded'; exit;
}
if ($path === '/StaffLock.php') { $_SERVER['SCRIPT_NAME'] = '/StaffLock.php'; require 'ROOT/StaffLock.php'; exit; }
if ($path === '/protected') {
 require_once 'ROOT/staff-session-lock.php';
 $_SERVER['SCRIPT_NAME'] = '/NurseDashboard.php';
 rhuEnforceStaffScreenLock(); echo 'PROTECTED_CONTENT'; exit;
}
http_response_code(404);
'''.replace('ROOT', root.as_posix()), encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    server = subprocess.Popen(['C:/xampp/php/php.exe', '-d', f'session.save_path={directory}', '-S', f'127.0.0.1:{port}', str(router)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            with opener.open(base + path, body, timeout=8) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode()
    try:
        for _ in range(40):
            try:
                request('/seed'); break
            except (OSError, urllib.error.URLError):
                time.sleep(.1)
        assert request('/protected')[1] == 'PROTECTED_CONTENT'
        state = json.loads(request('/StaffLock.php?api=1')[1])
        assert not state['locked']
        assert request('/StaffLock.php?api=1', {'action':'lock','csrf':'wrong'})[0] == 403
        assert not json.loads(request('/StaffLock.php?api=1')[1])['locked']
        assert json.loads(request('/StaffLock.php?api=1', {'action':'lock','csrf':state['csrf']})[1])['locked']
        assert request('/protected?api=records')[0] == 423
        request('/seed?age=301')
        state = json.loads(request('/StaffLock.php?api=1')[1])
        assert state['locked']
        assert json.loads(request('/StaffLock.php?api=1', {'action':'activity','csrf':state['csrf']})[1])['locked']
        status, body = request('/protected?api=records', {'action':'save'})
        assert status == 423 and 'PROTECTED_CONTENT' not in body
        payload = {'action':'unlock','csrf':state['csrf'],'pin':'9876'}
        assert 'Incorrect PIN' in request('/StaffLock.php', payload)[1]
        assert json.loads(request('/StaffLock.php?api=1')[1])['locked']
        for _ in range(4): request('/StaffLock.php', payload)
        assert 'Too many attempts' in request('/StaffLock.php', payload)[1]
        request('/seed?age=301')
        state = json.loads(request('/StaffLock.php?api=1')[1])
        request('/StaffLock.php', {'action':'unlock','csrf':state['csrf'],'pin':'0042'})
        assert not json.loads(request('/StaffLock.php?api=1')[1])['locked']
        request('/seed?nopin=1')
        state = json.loads(request('/StaffLock.php?api=1')[1])
        assert state['setup_required']
        assert request('/protected?api=records')[0] == 423
        for pin in ['123', '1234567', '12ab', ' 1234']:
            result = request('/StaffLock.php?settings=1', {'action':'set_pin','csrf':state['csrf'],'new_pin':pin,'confirm_pin':pin})[1]
            assert 'Use 4 to 6 digits' in result
        result = request('/StaffLock.php?settings=1', {'action':'set_pin','csrf':state['csrf'],'new_pin':'1234','confirm_pin':'1235'})[1]
        assert 'PINs do not match' in result
        request('/StaffLock.php?settings=1', {'action':'set_pin','csrf':state['csrf'],'new_pin':'000042','confirm_pin':'000042'})
        assert not json.loads(request('/StaffLock.php?api=1')[1])['setup_required']
        request('/StaffLock.php?api=1', {'action':'lock','csrf':state['csrf']})
        request('/StaffLock.php', {'action':'unlock','csrf':state['csrf'],'pin':'000042'})
        assert not json.loads(request('/StaffLock.php?api=1')[1])['locked']
        print('HTTP checks passed: PIN setup/validation, leading zeros, PIN unlock, manual/idle locks, protected GET/POST, CSRF and retry limit.')
    finally:
        server.terminate()
        server.wait(timeout=5)
