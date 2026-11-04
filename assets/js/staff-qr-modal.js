(() => {
  if (document.getElementById('staff-qr-dialog')) return;
  const dialog = document.createElement('dialog');
  dialog.id = 'staff-qr-dialog';
  dialog.setAttribute('aria-labelledby', 'staff-qr-dialog-title');
  dialog.innerHTML = '<div class="staff-qr-dialog-bar"><h2 id="staff-qr-dialog-title">Resident ID scanner</h2><button type="button" aria-label="Close resident ID scanner">&times;</button></div><iframe title="Scan resident ID and view pending appointments" allow="camera \'self\'"></iframe>';
  const style = document.createElement('style');
  style.textContent = '#staff-qr-dialog{padding:0;border:1px solid #d5e5dd;border-radius:22px;width:min(740px,calc(100vw - 32px));max-width:none;height:min(760px,calc(100dvh - 40px));max-height:calc(100dvh - 40px);background:#edf5f2;box-shadow:0 28px 100px #002d2940;overflow:hidden}#staff-qr-dialog::backdrop{background:#062c2866;backdrop-filter:blur(5px)}#staff-qr-dialog[open]{display:flex;flex-direction:column}.staff-qr-dialog-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 22px;background:white;border-bottom:1px solid #dce7e2}.staff-qr-dialog-bar h2{margin:0;font:700 16px system-ui;color:#164c40}.staff-qr-dialog-bar button{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #dce7e2;border-radius:10px;background:#f5faf7;color:#234b40;font:26px system-ui;cursor:pointer}.staff-qr-dialog-bar button:focus-visible{outline:2px solid #0f766e;outline-offset:3px}#staff-qr-dialog iframe{display:block;width:100%;flex:1;min-height:0;border:0}@media(max-width:540px){#staff-qr-dialog{width:calc(100vw - 16px);height:calc(100dvh - 20px);max-height:calc(100dvh - 20px);border-radius:16px}}';
  document.head.appendChild(style);
  document.body.appendChild(dialog);
  const frame = dialog.querySelector('iframe');
  let opener = null, previousOverflow = '';
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-staff-qr-modal]');
    if (!trigger) return;
    event.preventDefault();
    if (dialog.open) return;
    opener = trigger;
    const url = new URL('StaffResidentAppointments.php', location.href);
    url.searchParams.set('embed', '1');
    frame.src = url.href;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    dialog.showModal();
  });
  dialog.querySelector('button').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
  });
  dialog.addEventListener('close', () => {
    // Unload the scanner so closing the dialog releases the camera immediately.
    frame.src = 'about:blank';
    document.body.style.overflow = previousOverflow;
    opener?.focus();
  });
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== frame.contentWindow || !dialog.open) return;
    if (event.data === 'resident-qr-close') { dialog.close(); return; }
    if (event.data?.type !== 'resident-qr-open') return;
    try {
      const url = new URL(event.data.url, location.href);
      const pages = ['NurseDashboard.php','DentalDashboard.php','MidwifeDashboard.php','MedTechDashboard.php','SanitaryDashboard.php','RHUAdminDashboard.php'];
      const allowedPaths = pages.map(page => new URL(page, location.href).pathname);
      if (url.origin !== location.origin || !allowedPaths.includes(url.pathname)
          || !/^[1-9]\d*$/.test(url.searchParams.get('qr_appointment') || '')) return;
      dialog.close();
      location.assign(url.href);
    } catch { /* Ignore malformed navigation requests. */ }
  });
})();
