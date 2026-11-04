(() => {
  const style = document.createElement('style');
  style.textContent = '.resident-record-group{margin:0 0 12px;border:1px solid #dce7e2;border-radius:14px;background:white;overflow:hidden}.resident-record-group>summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;cursor:pointer;list-style:none;color:#193c33;font:700 14px system-ui}.resident-record-group>summary::-webkit-details-marker{display:none}.resident-record-group>summary:focus-visible{outline:2px solid #0f766e;outline-offset:-3px}.resident-record-group-count{font-size:11px;font-weight:600;color:#0f766e;background:#e9f7f0;padding:6px 10px;border-radius:20px;white-space:nowrap}.resident-record-group-body{padding:12px;border-top:1px solid #e4eee8;background:#f6faf8;max-height:min(55vh,480px);overflow:auto;overscroll-behavior:contain}.resident-record-group-body>table{width:100%}.resident-record-group-body>*+*{margin-top:12px}.resident-record-group[open]>summary{background:#eff8f3}.resident-record-group>summary:after{content:"+";font-size:20px;color:#0f766e}.resident-record-group[open]>summary:after{content:"−"}.resident-record-group-name{flex:1}@media print{.resident-record-group-body{max-height:none;overflow:visible}}';
  document.head.appendChild(style);
  const identity = row => {
    for (const element of row.querySelectorAll('h2,h3,h4,p,strong,td,.resident-filter-name')) {
      const text = element.textContent.replace(/\s+/g, ' ').trim();
      // A birth card identifies the mother in its subtitle, not the child's ID.
      if (/\(Mother:/i.test(text)) continue;
      const match = text.match(/^(.+?)\s*\(Patient\s*#\s*(\d+)\)/i);
      if (match) return {id:match[2],name:match[1].trim()};
    }
    return null;
  };
  function groupRows(collection, rows, table = null) {
    if (collection.dataset.residentRecordsGrouped || rows.length < 2) return;
    const groups = new Map();
    rows.forEach(row => {
      const person = identity(row);
      if (!person) return;
      if (!groups.has(person.id)) groups.set(person.id, {person,rows:[]});
      groups.get(person.id).rows.push(row);
    });
    if (!Array.from(groups.values()).some(group => group.rows.length > 1)) return;
    collection.dataset.residentRecordsGrouped = 'true';
    const columns = table ? Math.max(1, table.tHead?.rows[0]?.cells.length || rows[0].cells.length) : 0;
    groups.forEach(({person,rows}) => {
      const details = document.createElement('details');
      details.className = 'resident-record-group';
      details.dataset.residentGroupId = person.id;
      const summary = document.createElement('summary');
      const name = document.createElement('span');
      name.className = 'resident-record-group-name';
      name.textContent = `${person.name} (Patient #${person.id})`;
      const count = document.createElement('span');
      count.className = 'resident-record-group-count';
      count.textContent = `View ${rows.length} record${rows.length === 1 ? '' : 's'}`;
      summary.append(name,count);
      const body = document.createElement('div');
      body.className = 'resident-record-group-body';
      body.setAttribute('aria-label', `${person.name} records`);
      body.tabIndex = 0;
      details.append(summary,body);
      let groupRoot = details;
      let destination = body;
      if (table) {
        groupRoot = document.createElement('tr');
        const cell = document.createElement('td'); cell.colSpan = columns;
        cell.appendChild(details); groupRoot.appendChild(cell);
        const nested = document.createElement('table');
        nested.className = table.className;
        nested.dataset.residentGroupDetail = 'true';
        if (table.tHead) nested.appendChild(table.tHead.cloneNode(true));
        destination = document.createElement('tbody');
        nested.appendChild(destination); body.appendChild(nested);
      }
      groupRoot.setAttribute('data-record-search-row','');
      groupRoot.dataset.searchText = rows.map(row=>row.textContent).join(' ');
      rows[0].before(groupRoot);
      rows.forEach(row => {row.removeAttribute('data-record-search-row');row.classList.remove('staff-record-search-excluded');destination.appendChild(row);});
    });
    collection.dispatchEvent(new Event('staff-record-search-refresh'));
    table?.dispatchEvent(new Event('staff-record-search-refresh'));
  }
  function initialize() {
    const collections = new Set();
    document.querySelectorAll('[data-staff-record-scroll], .staff-record-scroll-list').forEach(list=>collections.add(list));
    document.querySelectorAll('[data-record-search-row]').forEach(row=>collections.add(row.parentElement));
    collections.forEach(list => {
      // The triage workbench owns tab visibility and panel placement.
      // Moving its panels into details breaks the selected patient's view.
      if (!list || list.closest('.resident-record-group, table, [data-triage-workbench]') || list.matches('main,body')) return;
      const rows = Array.from(list.children).filter(row => !row.matches('h1,h2,h3,h4,.staff-record-search,.staff-record-search-empty,.staff-record-scroll-hint') && identity(row));
      groupRows(list,rows);
    });
    document.querySelectorAll('main table').forEach(table => {
      if (table.closest('.resident-record-group, .history-entry, .dental-exam-sheet, .dental-prescription-sheet, [data-triage-workbench]') || table.dataset.consultationPaginationBound || table.querySelector('[data-consultation-detail-row], .assigned-record-detail-row')) return;
      for (const body of Array.from(table.tBodies)) {
        const rows = Array.from(body.rows);
        if (rows.some(row=>row.querySelector('td[colspan]'))) continue;
        groupRows(body,rows,table);
      }
    });
  }
  let pending = false;
  const refresh = () => {if(pending)return;pending=true;requestAnimationFrame(()=>{pending=false;initialize();});};
  function start() {
    initialize();
    new MutationObserver(refresh).observe(document.body,{childList:true,subtree:true});
    // Deep links still open the exact visit inside a collapsed resident group.
    const id = new URL(location.href).searchParams.get('qr_appointment');
    if (/^[1-9]\d*$/.test(id || '')) {
      const input = Array.from(document.querySelectorAll('input[name="consultation_id"]')).find(input=>input.value===id);
      const record = document.querySelector(`[data-qr-consultation-id="${id}"]`) || input;
      if (record) {for(let node=record.parentElement;node;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;}
    }
    let closedForPrint = [];
    window.addEventListener('beforeprint', () => {
      closedForPrint = Array.from(document.querySelectorAll('.resident-record-group:not([open])'));
      closedForPrint.forEach(group=>{group.open=true;});
    });
    window.addEventListener('afterprint', () => {closedForPrint.forEach(group=>{group.open=false;});closedForPrint=[];});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
