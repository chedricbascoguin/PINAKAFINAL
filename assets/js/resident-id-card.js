(() => {
  document.querySelectorAll('[data-resident-qr]').forEach(element => {
    if (element.dataset.qrReady) return;
    const url = new URL('StaffResidentAppointments.php', location.href);
    url.searchParams.set('resident_id', element.dataset.residentQr);
    try {
      new QRCode(element, {text:url.href,width:120,height:120,correctLevel:QRCode.CorrectLevel.M});
      element.dataset.qrReady = 'true';
    } catch {
      element.textContent = 'QR unavailable. Please reload before printing.';
      element.classList.add('resident-id__error');
      document.querySelectorAll('[data-print-resident-id]').forEach(button => {button.disabled = true;});
    }
  });
  document.querySelectorAll('[data-print-resident-id]').forEach(button => button.addEventListener('click', () => window.print()));
})();
