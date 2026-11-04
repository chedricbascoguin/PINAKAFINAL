"""Exercise actual QR pages with isolated sessions and SQLite; no live DB access."""
from pathlib import Path
import http.cookiejar
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='rhu-qr-test-') as directory:
    target = Path(directory)
    for name in ['ResidentID.php', 'StaffResidentAppointments.php', 'resident-qr.php', 'record-access.php', 'staff-access.php', 'staff-record-departments.php', 'styles/resident-qr.css', 'assets/js/resident-qr-scanner.js', 'resident-id-card.php', 'styles/resident-id-card.css', 'assets/js/resident-id-card.js', 'assets/js/staff-qr-appointment.js', 'assets/js/staff-qr-modal.js']:
        shutil.copy(root / name, target / name)
    shutil.copytree(root / 'assets/vendor', target / 'assets/vendor')
    shutil.copy(root / 'assets/images/resihunity_logo.jpg', target / 'assets/images/resihunity_logo.jpg')
    (target / 'staff-record-sheets.php').write_text("<?php function staffRecordBackUrl(string $department): string { return 'DentalDashboard.php'; }")
    (target / 'db.php').write_text('''<?php
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE residents (id INTEGER, first_name TEXT, middle_name TEXT, last_name TEXT)");
$pdo->exec("INSERT INTO residents VALUES (1,'Test','','Resident'),(2,'Other','','Person')");
$pdo->exec("CREATE TABLE consultations (id INTEGER, resident_id INTEGER, chief_complaint TEXT, remarks TEXT, consultation_date TEXT, consultation_time TEXT, consultation_status TEXT, diagnosis TEXT)");
$pdo->exec("INSERT INTO consultations VALUES
 (1,1,'[Dental Consultation & Oral Health] DENTAL_PENDING','','2026-11-01','09:00','Scheduled','Pending OPD Triage'),
 (2,1,'[General Medical Consultation] MEDICAL_PENDING','','2026-11-02','10:00','Scheduled','Pending OPD Triage'),
 (3,1,'[Dental Consultation & Oral Health] CLOSED_DENTAL','','2026-11-03','11:00','Completed','Treated'),
 (4,2,'[Dental Consultation & Oral Health] OTHER_PATIENT','','2026-11-04','12:00','Scheduled','Pending OPD Triage')");
function rhuTableExists(PDO $pdo, string $table): bool { return $table === 'consultations'; }
function rhuColumnExists(PDO $pdo, string $table, string $column): bool { return false; }
''')
    (target / 'portal.php').write_text("<?php require_once __DIR__.'/db.php';")
    (target / 'router.php').write_text('''<?php
session_start();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/preview-id') {
 $_SESSION=['user'=>['resident_id'=>1]];
 header('Location: ResidentID.php'); return;
}
if ($path === '/preview-staff') {
 $_SESSION=['rhu_staff_login'=>['staff_id'=>7,'staff_type'=>'PHYSICIAN']];
 header('Location: outer.html'); return;
}
if ($path === '/seed') {
 $_SESSION=[];
 if (($_GET['role'] ?? '') === 'RESIDENT') $_SESSION=['user'=>['resident_id'=>1]];
 elseif (!empty($_GET['role'])) $_SESSION=['rhu_staff_login'=>['staff_id'=>7,'staff_type'=>$_GET['role']]];
 echo 'seeded'; return;
}
if ($path === '/session') { header('Content-Type: application/json'); echo json_encode($_SESSION); return; }
return false;
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args):
            return None
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path):
        try:
            response = opener.open(base + path, timeout=8)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.headers, response.read().decode()
    server = subprocess.Popen(['php', '-d', f'session.save_path={directory}', '-S', f'127.0.0.1:{port}', '-t', directory, str(target / 'router.php')], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                request('/seed')
                break
            except OSError:
                time.sleep(.1)
        code, headers, body = request('/StaffResidentAppointments.php?resident_id=1')
        assert code == 302 and headers['Location'] == 'RHULogin.php'
        assert 'StaffResidentAppointments.php?resident_id=1' in request('/session')[2]
        request('/seed?role=RESIDENT')
        assert request('/StaffResidentAppointments.php?resident_id=1')[0] == 302
        code, headers, body = request('/ResidentID.php?resident_id=2')
        assert code == 200 and 'Test Resident' in body and 'Other Person' not in body
        assert 'no-store' in headers['Cache-Control']
        for role, allowed, forbidden in [('DENTAL','DENTAL_PENDING','MEDICAL_PENDING'), ('PHYSICIAN','MEDICAL_PENDING','DENTAL_PENDING')]:
            request('/seed?role=' + role)
            code, headers, body = request('/StaffResidentAppointments.php?resident_id=1')
            assert code == 200 and allowed in body and forbidden not in body
            assert 'OTHER_PATIENT' not in body and 'CLOSED_DENTAL' not in body
            embedded_code, _, embedded_body = request('/StaffResidentAppointments.php?resident_id=1&embed=1')
            assert embedded_code == 200 and 'class="qr-embedded"' in embedded_body and allowed in embedded_body and forbidden not in embedded_body
            assert 'target="_parent"' in embedded_body and 'qr_appointment=' in embedded_body and 'Open appointment' in embedded_body
            assert 'no-store' in headers['Cache-Control']
        request('/seed?role=MEDTECH')
        assert 'No pending appointments for your department.' in request('/StaffResidentAppointments.php?resident_id=1')[2]
        assert request('/StaffResidentAppointments.php?resident_id=999')[0] == 404
        request('/seed?role=UNSUPPORTED')
        assert request('/StaffResidentAppointments.php?resident_id=1')[0] == 403
        chrome = Path('C:/Program Files/Google/Chrome/Application/chrome.exe')
        if chrome.exists():
            browser = subprocess.run([str(chrome), '--headless', '--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--no-first-run', '--no-default-browser-check', f'--user-data-dir={target / "browser-profile"}', '--virtual-time-budget=1500', '--dump-dom', base + '/preview-id'], capture_output=True, timeout=30)
            dom = browser.stdout.decode('utf-8', errors='replace')
            assert browser.returncode == 0 and 'data:image/png;base64,' in dom, f'Resident ID QR did not render in Chrome: {browser.returncode}, {browser.stderr.decode(errors="replace")[:1500]}, {dom[:1500]}'
            print('Chrome check passed: resident ID renders an actual QR image.')
            (target / 'focus.html').write_text('''<!doctype html><html><body>
<button data-triage-tab data-target="patient-12" onclick="document.getElementById('patient-12').classList.add('is-active')">Patient</button>
<section id="patient-12" class="triage-detail-panel">
<select data-consult-date-select onchange="document.querySelectorAll('[data-consult-date-record]').forEach(record=>record.classList.toggle('hidden',record.id!==this.value))"><option value="consult-record-99">Newest</option><option value="consult-record-71">Selected</option></select>
<form id="consult-record-99" data-consult-date-record><input name="consultation_id" value="99"></form>
<form id="consult-record-71" class="hidden" data-consult-date-record><input name="consultation_id" value="71"></form>
</section><script src="assets/js/staff-qr-appointment.js" defer></script></body></html>''')
            focus_browser = subprocess.run([str(chrome), '--headless', '--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--no-first-run', '--no-default-browser-check', f'--user-data-dir={target / "browser-profile"}', '--virtual-time-budget=1500', '--dump-dom', base + '/focus.html?qr_appointment=71'], capture_output=True, timeout=30)
            focus_dom = focus_browser.stdout.decode('utf-8', errors='replace')
            import re
            selected = re.search(r'<form id="consult-record-71"[^>]*>', focus_dom).group()
            assert 'hidden' not in selected and 'tabindex="-1"' in selected
            assert 'id="patient-12" class="triage-detail-panel is-active"' in focus_dom
            assert re.search(r'<form id="consult-record-99"[^>]*class="hidden"', focus_dom)
            print('Chrome check passed: OPD selects and focuses the clicked visit, leaving the other visit hidden.')
            (target / 'outer.html').write_text('''<!doctype html><html><body><h1>OUTER_APP_FRAME</h1><iframe src="modal-test.html"></iframe><p id="result">Waiting</p><script>
window.addEventListener('message',event=>{if(event.origin===location.origin && event.data?.type==='test-arrived') document.getElementById('result').textContent='ARRIVED: '+event.data.url;});
</script></body></html>''')
            (target / 'modal-test.html').write_text('''<!doctype html><html><body><a href="StaffResidentAppointments.php" data-staff-qr-modal>Scan</a><script src="assets/js/staff-qr-modal.js" defer></script><script>
window.addEventListener('load',()=>{
 const frame=document.querySelector('#staff-qr-dialog iframe');
 frame.addEventListener('load',()=>{
  const link=frame.contentDocument?.querySelector('.appointment-link');
  if(link) link.click();
  else if(frame.src.includes('StaffResidentAppointments.php') && !frame.src.includes('resident_id=')) frame.src='StaffResidentAppointments.php?resident_id=1&embed=1';
 });
 document.querySelector('[data-staff-qr-modal]').click();
});</script></body></html>''')
            (target / 'RHUAdminDashboard.php').write_text('''<!doctype html><html><body>DESTINATION<script>parent.postMessage({type:'test-arrived',url:location.href},location.origin);</script></body></html>''')
            nested = subprocess.run([str(chrome), '--headless', '--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--no-first-run', '--no-default-browser-check', f'--user-data-dir={target / "browser-profile"}', '--virtual-time-budget=4000', '--dump-dom', base + '/preview-staff'], capture_output=True, timeout=30)
            nested_dom = nested.stdout.decode('utf-8', errors='replace')
            assert 'OUTER_APP_FRAME' in nested_dom and 'ARRIVED: ' in nested_dom and 'qr_appointment=2' in nested_dom, nested_dom
            print('Chrome check passed: clicking a scanned appointment navigates the host dashboard inside an outer app frame.')
        print('QR HTTP checks passed: authentication, resident ID ownership, server-side role isolation, patient isolation, closed visits, empty results, missing residents and unsupported roles.')
    finally:
        server.terminate()
        server.wait(timeout=10)
