"""Real-browser checks for the shared resident picker; no live accounts or DB."""
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
chrome = Path('C:/Program Files/Google/Chrome/Application/chrome.exe')
with tempfile.TemporaryDirectory(prefix='resident-search-test-') as directory:
    folder = Path(directory)
    shutil.copy(root / 'assets/js/resident-select-search.js', folder / 'assets/js/resident-select-search.js')
    fields = ['resident_id', 'mother_id', 'patient_id', 'child_id', 'primary_resident_id', 'duplicate_resident_id']
    forms = ''.join(f'<select name="{name}" id="picker-{index}"><option value="">Select resident</option><option value="1" data-barangay="Bucana">Ana Cruz (Patient #1)</option><option value="2">Ben Santos (Patient #2)</option></select>' for index, name in enumerate(fields))
    forms += '<select name="consultation_id" id="lab-picker" data-resident-search><option value="">Select consultation</option><option value="0" data-resident-id="11" data-appointment-date="2026-11-01">Ana Cruz (Patient #11)</option><option value="0" data-resident-id="12" data-appointment-date="2026-11-02">Ben Santos (Patient #12)</option></select>'
    (folder / 'test.html').write_text('''<!doctype html><html><body>''' + forms + '''
<script src="assets/js/resident-select-search.js" defer></script><script>
function check(condition,message){if(!condition)throw Error(message);}
function searchFor(select){return Array.from(document.querySelectorAll('[data-resident-select-search]')).find(input=>input.dataset.residentSelectSearch===select.id);}
function search(select,term){const input=searchFor(select);check(!!input,'Search missing for '+select.name);input.value=term;input.dispatchEvent(new Event('input'));return document.getElementById(input.id+'-results');}
window.addEventListener('load',async()=>{try{
 for(const select of document.querySelectorAll('select:not([data-resident-search])')){
  let changed=0;select.addEventListener('change',()=>changed++);
  let results=search(select,'ana');check(results.style.display==='block'&&results.querySelectorAll('button').length===1,'Live list incorrect');
  results.querySelector('button').click();check(select.value==='1'&&changed===1,'Selection/autofill change event failed');
  results=search(select,'Ben');check(select.value==='1'&&results.textContent.includes('Ben Santos'),'Current selection lost');
  results=search(select,'missing');check(results.textContent.includes('No matching residents'),'Empty state missing');
  results=search(select,'bucana');check(results.textContent.includes('Ana Cruz'),'Barangay search failed');
 }
 const lab=document.getElementById('lab-picker');
 search(lab,'Ben').querySelector('button').click();
 check(lab.selectedOptions[0].dataset.residentId==='12'&&lab.selectedOptions[0].dataset.appointmentDate==='2026-11-02','Legacy lab booking changed');
 search(lab,'Ana');check(lab.selectedOptions[0].dataset.residentId==='12','Searching replaced selected lab visit');
 const dynamic=document.createElement('select');dynamic.name='resident_id';dynamic.id='dynamic-resident';dynamic.innerHTML='<option value="">Select resident</option><option value="7">Dynamic Resident (Patient #7)</option>';document.body.appendChild(dynamic);
 await new Promise(resolve=>setTimeout(resolve,20));
 check(search(dynamic,'dynamic').textContent.includes('Dynamic Resident'),'Dynamically opened modal not searchable');
 document.body.dataset.test='passed';
}catch(error){document.body.dataset.test=error.message;}});
</script></body></html>''', encoding='utf-8')
    browser = subprocess.run([str(chrome), '--headless', '--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--no-first-run', f'--user-data-dir={folder / "profile"}', '--virtual-time-budget=1500', '--dump-dom', (folder / 'test.html').as_uri()], capture_output=True, timeout=30)
    dom = browser.stdout.decode('utf-8', errors='replace')
    assert 'data-test="passed"' in dom, dom
    print('Staff resident search checks passed: all picker types, live results, click selection, change/autofill events, barangay search, exact legacy lab visits and dynamic modals.')
