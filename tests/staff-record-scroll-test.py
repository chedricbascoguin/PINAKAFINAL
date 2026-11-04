"""Check staff record scrolling in Chrome without touching live patient data."""
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='staff-record-scroll-') as directory:
    folder = Path(directory)
    for name in ['assets/js/staff-record-scroll.js', 'styles/staff-record-scroll.css', 'assets/js/staff-record-search.js', 'styles/staff-record-search.css']:
        shutil.copy(root / name, folder / name)
    rows = ''.join(f'<tr><td>Patient {i}</td><td>2026-10-23</td></tr>' for i in range(80))
    cards = ''.join(f'<article data-record-search-row style="padding:20px">Visit {i}</article>' for i in range(50))
    (folder / 'test.html').write_text('''<!doctype html><html><head><link rel="stylesheet" href="styles/staff-record-scroll.css"><link rel="stylesheet" href="styles/staff-record-search.css"></head><body><main>
<h1>Staff records</h1><div class="overflow-x-auto"><table id="records"><thead><tr><th>Patient</th><th>Date</th></tr></thead><tbody>''' + rows + '''</tbody></table></div>
<section><h2>Resident history</h2><div id="history" data-staff-record-scroll>''' + cards + '''</div></section>
<div id="short-list"><article data-record-search-row>One short record</article></div>
</main><script src="assets/js/staff-record-search.js" defer></script><script src="assets/js/staff-record-scroll.js" defer></script><script>
function check(condition,message){if(!condition)throw Error(message);}
window.addEventListener('load',async()=>{try{
 await new Promise(resolve=>setTimeout(resolve,100));
 const table=document.getElementById('records');const wrapper=table.parentElement;
 check(wrapper.classList.contains('staff-record-scroll')&&wrapper.scrollHeight>wrapper.clientHeight,'Long table does not scroll');
 check(table.tBodies[0].rows.length===80,'Table records lost');
 check(getComputedStyle(table.tHead).position==='sticky','Table header does not stay visible');
 const history=document.getElementById('history');
 check(history.scrollHeight>history.clientHeight&&history.getAttribute('tabindex')==='0','History not keyboard scrollable');
 check(history.querySelectorAll('[data-record-search-row]').length===50,'History records lost');
 check(!history.contains(history.parentElement.querySelector('h2')),'Heading moved into scroll area');
 const short=document.getElementById('short-list');check(short.clientHeight<80,'Short list forced to full height');
 const search=history.previousElementSibling?.classList.contains('staff-record-search-hint')?history.previousElementSibling.previousElementSibling:history.previousElementSibling;
 const input=history.parentElement.querySelector('input[type=search]');
 check(!!input&&!history.contains(input),'Search trapped inside history scroll');
 input.value='Visit 49';input.dispatchEvent(new Event('input'));
 check(history.querySelectorAll('[data-record-search-row]:not(.staff-record-search-excluded)').length===1,'History search broken');
 const dynamic=document.createElement('div');dynamic.innerHTML='<article data-record-search-row>Newly loaded record</article>';document.querySelector('main').appendChild(dynamic);
 await new Promise(resolve=>setTimeout(resolve,100));
 check(dynamic.classList.contains('staff-record-scroll'),'Dynamic list not initialized');
 document.body.dataset.test='passed';
}catch(error){document.body.dataset.test=error.message;}});
</script></body></html>''', encoding='utf-8')
    result = subprocess.run(['C:/Program Files/Google/Chrome/Application/chrome.exe', '--headless', '--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--no-first-run', f'--user-data-dir={folder / "profile"}', '--window-size=1100,850', '--virtual-time-budget=2000', '--dump-dom', (folder / 'test.html').as_uri()], capture_output=True, timeout=30)
    dom = result.stdout.decode('utf-8', errors='replace')
    assert 'data-test="passed"' in dom, __import__('re').search(r'data-test="([^"]*)',dom).group(1) if 'data-test=' in dom else dom[-1000:]
    print('Staff record scrolling checks passed: long tables/history, sticky headers, short lists, preserved rows, search and dynamic views.')
