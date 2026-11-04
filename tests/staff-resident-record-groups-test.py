"""Real-browser checks for resident grouping without any live patient data."""
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='resident-groups-') as directory:
    folder = Path(directory)
    for name in ['assets/js/staff-resident-record-groups.js','assets/js/staff-record-search.js','styles/staff-record-search.css','assets/js/staff-record-scroll.js','styles/staff-record-scroll.css']:
        shutil.copy(root / name, folder / name)
    cards = ''.join(f'<article data-record-search-row><p>Test Resident (Patient #{person})</p><form><input name="consultation_id" value="{visit}"><button type="button" onclick="this.dataset.clicked=\'yes\'">Update {visit}</button></form><p>Visit {visit} {text}</p></article>' for person,visit,text in [(1,10,'first'),(1,11,'followup'),(2,20,'same name different patient')])
    (folder / 'test.html').write_text('''<!doctype html><html><head><link rel="stylesheet" href="styles/staff-record-search.css"><link rel="stylesheet" href="styles/staff-record-scroll.css"></head><body><main><section><h2>Prenatal visits</h2><div id="cards" data-staff-record-scroll>''' + cards + '''</div></section>
<table id="history"><thead><tr><th>Resident</th><th>Visit</th></tr></thead><tbody><tr><td>Table Resident (Patient #3)</td><td>First</td></tr><tr><td>Table Resident (Patient #3)</td><td>Second</td></tr><tr><td>Other Resident (Patient #4)</td><td>Only</td></tr></tbody></table>
<div data-triage-workbench><aside><div class="triage-patient-list"><button>Choose resident</button></div></aside><section class="triage-view-pane"><div class="triage-detail-panel is-active"><p>Triage Resident (Patient #5)</p></div><div class="triage-detail-panel"><p>Triage Resident (Patient #5)</p></div></section></div>
</main><script src="assets/js/staff-record-search.js" defer></script><script src="assets/js/staff-record-scroll.js" defer></script><script src="assets/js/staff-resident-record-groups.js" defer></script><script>
function check(condition,message){if(!condition)throw Error(message);}
window.addEventListener('load',async()=>{try{
 await new Promise(resolve=>setTimeout(resolve,150));
 const list=document.getElementById('cards');const groups=list.querySelectorAll(':scope>.resident-record-group');
 const workbench=document.querySelector('[data-triage-workbench]');
 check(!workbench.querySelector('.resident-record-group, .staff-record-scroll'),'Shared scripts changed triage layout');
 check(workbench.querySelectorAll('.triage-view-pane > .triage-detail-panel').length===2,'Triage panels moved out of their pane');
 check(groups.length===2,'Residents not grouped by patient ID');
 check(groups[0].querySelector('summary').textContent.includes('2 records'),'Wrong visit count');
 check(groups[0].open&&!groups[1].open,'Exact appointment deep link not opened');
 check(list.querySelectorAll('form').length===3,'Record form was lost');
 const action=list.querySelector('button');action.click();check(action.dataset.clicked==='yes','Original action handler lost');
 const input=list.closest('section').querySelector('input[type=search]');input.value='followup';input.dispatchEvent(new Event('input'));
 check(!groups[0].classList.contains('staff-record-search-excluded')&&groups[1].classList.contains('staff-record-search-excluded'),'Search did not find hidden record');
 const table=document.getElementById('history');check(table.tBodies[0].rows.length===2,'Table residents not grouped');
 check(table.querySelectorAll('[data-resident-group-detail] tbody tr').length===3,'Original table visits lost');
 check(!table.querySelector('.staff-record-search'),'Search inserted into table markup');
 window.dispatchEvent(new Event('beforeprint'));check(groups[1].open,'Printing omitted collapsed records');
 window.dispatchEvent(new Event('afterprint'));check(!groups[1].open,'Print changed group state permanently');
 document.body.dataset.test='passed';
}catch(error){document.body.dataset.test=error.message;}});
</script></body></html>''',encoding='utf-8')
    browser = subprocess.run(['C:/Program Files/Google/Chrome/Application/chrome.exe','--headless','--no-sandbox','--disable-gpu','--disable-software-rasterizer','--no-first-run',f'--user-data-dir={folder / "profile"}','--virtual-time-budget=2000','--dump-dom',(folder / 'test.html').as_uri()+'?qr_appointment=11'],capture_output=True,timeout=30)
    dom = browser.stdout.decode('utf-8',errors='replace')
    assert 'data-test="passed"' in dom, dom[-4000:]
    print('Resident grouping checks passed: one row per patient, same-name isolation, all records/forms preserved, hidden-record search, exact appointment links, tables and printing.')
